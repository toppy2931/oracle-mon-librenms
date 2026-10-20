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
        self.requests = []                                 # (method, path, body)
        self.header_violations = []                        # 缺 X-Requested-By 的請求（Graylog 會拒絕）
        fake = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def _send(self, code, obj=None):
                raw = b"" if obj is None else json.dumps(obj).encode()
                self.send_response(code)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)

            def _handle(self, method):
                n = int(self.headers.get("Content-Length") or 0)
                body = json.loads(self.rfile.read(n)) if n else None
                fake.requests.append((method, self.path, body))
                if self.headers.get("Authorization") != fake.expect_auth:
                    return self._send(401, {"type": "ApiError", "message": "unauthorized"})
                if not self.headers.get("X-Requested-By"):
                    fake.header_violations.append((method, self.path))
                    return self._send(400, {"message": "missing X-Requested-By"})
                code, out = fake.route(method, self.path, body)
                self._send(code, out)

            def do_GET(self): self._handle("GET")
            def do_POST(self): self._handle("POST")
            def do_PUT(self): self._handle("PUT")
            def do_DELETE(self): self._handle("DELETE")

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.url = "http://127.0.0.1:%d" % self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()

    def mutations(self):
        return [(m, p) for m, p, _ in self.requests if m != "GET"]

    def route(self, method, path, body):
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
