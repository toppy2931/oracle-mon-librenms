"""測試共用：依路徑載入檔名含連字號的腳本，與用標準函式庫起一個假的 Graylog API。"""
import base64
import importlib.util
import json
import re
import threading
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def load_module(name, relpath):
    spec = importlib.util.spec_from_file_location(name, ROOT / relpath)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


class FakeGraylog:
    """模擬 pipeline 相關 API 的最小子集；行為依社群文件與原始碼推得，未對真實 Graylog 驗證。"""

    def __init__(self, user="admin", password="secret"):
        self.expect_auth = "Basic " + base64.b64encode(("%s:%s" % (user, password)).encode()).decode()
        self.rules, self.pipes, self.conns = {}, {}, {}   # id -> obj；conns: stream_id -> [pipeline_id]
        # 仿真實環境：另有一個把訊息移出 Default Stream 的 FortiGate 自訂 stream
        self.streams = {"000000000000000000000001": "Default Stream", "6a33ef93a68ab16ef006f414": "FortiGate Syslog"}
        self.requests = []                                 # (method, path, body)
        self.header_violations = []                        # 缺 X-Requested-By 的請求（Graylog 會拒絕）
        self.overrides = {}                                # (method, path) -> (code, body[, headers])
        self.users = {}                                    # username -> {roles, permissions, tokens{id: token}}
        self.token_auth = {}                               # Authorization 標頭 -> username（Token 登入）
        self.principal = None                              # 目前這個請求是誰（admin 或使用者名稱）
        fake = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def _send(self, code, obj=None, headers=None):
                if isinstance(obj, bytes):          # 測試用：回非 JSON 的內容
                    raw = obj
                else:
                    raw = b"" if obj is None else json.dumps(obj).encode()
                self.send_response(code)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                for k, v in (headers or {}).items():
                    self.send_header(k, v)
                self.end_headers()
                self.wfile.write(raw)

            def _handle(self, method):
                n = int(self.headers.get("Content-Length") or 0)
                body = json.loads(self.rfile.read(n)) if n else None
                fake.requests.append((method, self.path, body))
                fake.principal = fake.who(self.headers.get("Authorization"))
                if fake.principal is None:
                    return self._send(401, {"type": "ApiError", "message": "unauthorized"})
                if not self.headers.get("X-Requested-By"):
                    fake.header_violations.append((method, self.path))
                    return self._send(400, {"message": "missing X-Requested-By"})
                ov = fake.overrides.get((method, self.path))
                if ov:                               # (狀態碼, 內容, 標頭)：強制某個請求回特定結果
                    return self._send(ov[0], ov[1], ov[2] if len(ov) > 2 else None)
                code, out = fake.route(method, self.path, body)
                self._send(code, out)

            def do_GET(self): self._handle("GET")
            def do_POST(self): self._handle("POST")
            def do_PUT(self): self._handle("PUT")
            def do_DELETE(self): self._handle("DELETE")

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.url = "http://127.0.0.1:%d" % self.server.server_address[1]
        # 預設輪詢間隔 0.5 秒，shutdown() 會等到下一次輪詢，每個測試白白多 0.5 秒
        threading.Thread(target=self.server.serve_forever, kwargs={"poll_interval": 0.01}, daemon=True).start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()

    def mutations(self):
        return [(m, p) for m, p, _ in self.requests if m != "GET"]

    def who(self, header):
        """admin 的 Basic 帳密，或某個使用者的 Token（帳號放 Token、密碼固定 token）。"""
        if header == self.expect_auth:
            return "admin"
        return self.token_auth.get(header)

    def _users_route(self, method, path, body):
        if path == "/api/users" and method == "POST":
            missing = [k for k in ("username", "email", "first_name", "last_name", "password", "roles") if not body.get(k)]
            if missing:
                return 400, {"message": "missing %s" % missing}
            name = body["username"]
            self.users[name] = {"username": name, "roles": list(body["roles"]), "tokens": {},
                                "permissions": ["users:edit:" + name] + list(body.get("permissions", []))}  # Graylog 會自己加預設權限
            return 201, {"user_id": uuid.uuid4().hex}
        parts = path[len("/api/users/"):].split("/")
        name = parts[0]
        if name not in self.users:
            return 404, {"message": "no such user"}
        user = self.users[name]
        if len(parts) == 1:
            if method == "GET":
                return 200, {k: v for k, v in user.items() if k != "tokens"}
            if method == "PUT":
                user["roles"] = list(body.get("roles", user["roles"]))
                user["permissions"] = list(body.get("permissions", user["permissions"]))
                return 204, None
            if method == "DELETE":
                for token in user["tokens"].values():
                    self.token_auth.pop(self._token_header(token), None)
                del self.users[name]
                return 204, None
        elif len(parts) == 3 and parts[1] == "tokens":
            if method == "POST":
                token, tid = uuid.uuid4().hex, uuid.uuid4().hex[:12]
                user["tokens"][tid] = token
                self.token_auth[self._token_header(token)] = name
                return 200, {"id": tid, "name": parts[2], "token": token}
            if method == "DELETE":
                token = user["tokens"].pop(parts[2], None)
                if token:
                    self.token_auth.pop(self._token_header(token), None)
                return 204, None
        return 404, {"message": "no route %s %s" % (method, path)}

    @staticmethod
    def _token_header(token):
        return "Basic " + base64.b64encode(("%s:token" % token).encode()).decode()

    def route(self, method, path, body):
        if path == "/api/search/aggregate" and method == "POST":
            if not body.get("group_by"):                    # 真的 Graylog：搜尋彙整一定要有 group_by
                return 400, {"message": "must not be empty", "path": "ScriptingApiResource.executeQuery.arg0.groupings"}
            if self.principal != "admin":
                perms = self.users.get(self.principal, {}).get("permissions", [])
                for s in body.get("streams", []):
                    if "streams:read:%s" % s not in perms:
                        return 403, {"message": "Not authorized to access resource id <%s>" % s}
            return 200, {"schema": [{"column_type": "grouping", "type": "string", "field": "source", "name": "grouping: source"},
                                    {"column_type": "metric", "type": "numeric", "function": "count", "name": "metric: count()"}],
                         "datarows": [["web01", 3]], "metadata": {}}
        if path == "/api/users" or path.startswith("/api/users/"):
            if self.principal != "admin":
                return 403, {"message": "forbidden"}
            return self._users_route(method, path, body)
        if path == "/api/streams" and method == "GET":
            return 200, {"total": len(self.streams),
                         "streams": [{"id": i, "title": t, "rules": []} for i, t in self.streams.items()]}
        base = "/api/system/pipelines/"
        if path == base + "connections" and method == "GET":
            return 200, [{"stream_id": s, "pipeline_ids": ids} for s, ids in self.conns.items()]
        if path == base + "connections/to_pipeline" and method == "POST":
            pid = body["pipeline_id"]
            for ids in self.conns.values():
                if pid in ids:
                    ids.remove(pid)
            for s in body["stream_ids"]:
                self.conns.setdefault(s, []).append(pid)
            return 200, {}
        for kind, store, key in (("rule", self.rules, "rule"), ("pipeline", self.pipes, "pipeline")):
            if path == base + kind:
                if method == "GET":
                    return 200, list(store.values())
                if method == "POST":
                    return self._save(store, None, body, key)
            elif path.startswith(base + kind + "/"):
                oid = path.rsplit("/", 1)[1]
                if oid not in store:
                    return 404, {"message": "not found"}
                if method == "PUT":
                    return self._save(store, oid, body, key)
                if method == "DELETE":
                    del store[oid]
                    for ids in self.conns.values():
                        if oid in ids:
                            ids.remove(oid)
                    return 204, None
        return 404, {"message": "no route %s %s" % (method, path)}

    def _save(self, store, oid, body, key):
        src = body.get("source", "")
        if "SYNTAXERR" in src:
            return 400, {"message": "parse error"}
        m = re.match(r'\s*%s\s+"([^"]+)"' % key, src)
        if not m:
            return 400, {"message": "no %s name" % key}
        if key == "pipeline":  # 真的 Graylog 會檢查引用的規則存在
            known = {r["title"] for r in self.rules.values()}
            for ref in re.findall(r'^\s*rule\s+"([^"]+)"', src, re.M):
                if ref not in known:
                    return 400, {"message": "unknown rule " + ref}
        oid = oid or uuid.uuid4().hex[:24]
        store[oid] = {"id": oid, "title": m.group(1), "description": body.get("description", ""), "source": src}
        return 200, store[oid]

    def seed_foreign(self):
        """另一組不屬於本工具的規則與 pipeline（例如既有的 GeoIP），用來驗證不被動到。"""
        self.rules["f1"] = {"id": "f1", "title": "GeoIP enrich FortiGate src", "description": "", "source": 'rule "GeoIP enrich FortiGate src"\nwhen true\nthen\nend'}
        self.pipes["f2"] = {"id": "f2", "title": "GeoIP Enrichment", "description": "", "source": 'pipeline "GeoIP Enrichment"\nstage 0 match either\n  rule "GeoIP enrich FortiGate src"\nend'}
        self.conns["000000000000000000000001"] = ["f2"]
        # 別人自己的規則，剛好用了同樣的 ioc- 前綴；remove 不得誤刪
        self.rules["f3"] = {"id": "f3", "title": "ioc-custom-mine", "description": "我自己的規則", "source": 'rule "ioc-custom-mine"\nwhen true\nthen\nend'}
