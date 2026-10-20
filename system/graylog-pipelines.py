#!/usr/bin/env python3
"""
graylog-pipelines.py — IOC 欄位標準化 pipeline 的冪等套用工具（Graylog REST API）

把 Windows / Linux / 防火牆 / 網路設備的日誌，統一寫入標準欄位，原始欄位保持不動：
  ioc_schema  log_type  event_action  severity  user_name  src_ip  dst_ip  host_name  target_name
中文入口（IOC portal）只讀這些標準欄位；event_action 存英文代碼，中文名稱在入口顯示層對照。

用法：
  graylog-pipelines.py print    印出規則與 pipeline 原始碼（離線，不連 Graylog）
  graylog-pipelines.py plan     比對現況，列出將新增／更新什麼（唯讀）
  graylog-pipelines.py apply    冪等套用：規則 → pipeline → 連到 Default Stream
                                （防火牆另連到 GRAYLOG_FIREWALL_STREAMS 指定的 stream）
  graylog-pipelines.py remove   回滾：解除連線並刪除本工具建立的 pipeline 與規則

設定（環境變數優先於設定檔 /etc/oracle-mon/graylog-api.conf，格式 KEY=VALUE）：
  GRAYLOG_URL        預設 http://127.0.0.1:9000
  GRAYLOG_USER / GRAYLOG_PASS
  GRAYLOG_CA_FILE    HTTPS 自簽或內部 CA 的 PEM。沒有「關閉憑證驗證」選項（規格 IS-043）
  GRAYLOG_STREAM_ID  預設 Default Stream（000000000000000000000001）
  GRAYLOG_FIREWALL_STREAMS  防火牆日誌所在的 stream，標題或 ID，逗號分隔，例如 FortiGate Syslog。
                     該 stream 若把訊息移出了 Default Stream，不設的話防火牆規則看不到真實流量
  GRAYLOG_API_CONF   指定設定檔路徑（測試用）

退出碼：0 成功；1 失敗（API 錯誤、規則檢查未過、找不到指定的 stream）；2 缺少設定。

設計取捨（改規則前先看）：
  * 每條 pipeline 只有一個 stage。stage 不滿足 match 條件時，後面的 stage 不會執行，
    多 stage 的 fallback 鏈會悄悄斷掉。Windows／Linux／網路設備用 stage 0；防火牆用 stage 2：
    同一個 stream 上的 pipeline 是依 stage 編號跨 pipeline 一起往下跑，FortiGate 的欄位由既有的
    fortigate_normalize 在 stage 0、1 產生，排在它們之後才讀得到（見 docs/ioc/existing-graylog-config.md）。
  * 規則之間互不依賴、條件互斥：不假設同一 stage 內規則的執行順序。
  * 來源欄位名稱因收集方式而異（Winlogbeat 的 winlog_*、NXLog 的 EventID…），
    用 to_string(欄位, 預設值) 巢狀取第一個存在的。要支援新來源只要在 coalesce() 加欄位名。
  * 規則內字串不得含雙引號與反斜線（lint() 會擋）；grok 用 . 代替 \\[ 這類跳脫。
  * Graylog 不儲存空字串欄位，所以取不到值時 set_field 等於沒設。
"""
import base64
import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.request

SCHEMA_VERSION = "1"
DEFAULT_STREAM_ID = "000000000000000000000001"
CONF_PATH = "/etc/oracle-mon/graylog-api.conf"
RULE_PREFIX = "ioc-"
PIPELINE_PREFIX = "IOC Normalize - "
DESCRIPTION = "IOC 欄位標準化（由 system/graylog-pipelines.py 管理，請勿手改；要改規則請改程式後重跑 apply）"
STANDARD_FIELDS = frozenset([
    "ioc_schema", "log_type", "event_action", "severity",
    "user_name", "src_ip", "dst_ip", "host_name", "target_name",
])
# 預設 stage 0。防火牆規則要讀 fortigate_normalize（stage 0、1）產生的欄位，所以排 stage 2
STAGE = {PIPELINE_PREFIX + "Firewall": 2}
# 這些 pipeline 除了 Default Stream，還連到設定檔指定的 stream。這台的 FortiGate 訊息被路由到自訂
# stream 並移出 Default Stream，只連 Default 的 pipeline 看不到它們。
STREAM_KEYS = {PIPELINE_PREFIX + "Firewall": "GRAYLOG_FIREWALL_STREAMS"}


# ── 規則產生 ────────────────────────────────────────────────────────────

def lit(s):
    return '"%s"' % s


def coalesce(*fields):
    """依序取第一個存在的欄位，皆無則空字串。"""
    expr = lit("")
    for f in reversed(fields):
        expr = "to_string($message.%s, %s)" % (f, expr)
    return expr


def rule(name, when, sets, grok=None):
    """sets: [(標準欄位, 已渲染的表達式)]；grok: 命名擷取 pattern，擷取結果寫入同名欄位。"""
    title = RULE_PREFIX + name
    body = ["  set_field(%s, %s);" % (lit("ioc_schema"), lit(SCHEMA_VERSION))]
    body += ["  set_field(%s, %s);" % (lit(k), v) for k, v in sets]
    if grok:
        body.append(
            "  set_fields(grok(pattern: %s, value: %s, only_named_captures: true));"
            % (lit(grok), MSG)
        )
    return title, 'rule "%s"\nwhen\n  %s\nthen\n%s\nend' % (title, when, "\n".join(body))


MSG = coalesce("message")

# Windows 事件。(事件 ID, event_action, severity, 當事人欄位, 對象欄位)
# 當事人：登入類是被登入的帳號（TargetUserName），異動類是操作者（SubjectUserName）。
WIN_EVENTS = [
    ("4624", "login_success", "info", "TargetUserName", None),
    ("4625", "login_failed", "low", "TargetUserName", None),
    ("4634", "logout", "info", "TargetUserName", None),
    ("4647", "logout", "info", "SubjectUserName", None),
    ("4648", "explicit_logon", "medium", "SubjectUserName", "TargetUserName"),
    ("4672", "privileged_logon", "medium", "SubjectUserName", None),
    ("4720", "account_created", "medium", "SubjectUserName", "TargetUserName"),
    ("4722", "account_enabled", "medium", "SubjectUserName", "TargetUserName"),
    ("4725", "account_disabled", "medium", "SubjectUserName", "TargetUserName"),
    ("4726", "account_deleted", "medium", "SubjectUserName", "TargetUserName"),
    ("4728", "group_member_added", "high", "SubjectUserName", "TargetUserName"),
    ("4729", "group_member_removed", "medium", "SubjectUserName", "TargetUserName"),
    ("4732", "group_member_added", "high", "SubjectUserName", "TargetUserName"),
    ("4733", "group_member_removed", "medium", "SubjectUserName", "TargetUserName"),
    ("4756", "group_member_added", "high", "SubjectUserName", "TargetUserName"),
    ("4757", "group_member_removed", "medium", "SubjectUserName", "TargetUserName"),
    ("4740", "account_locked", "medium", "TargetUserName", None),
    ("4719", "audit_policy_changed", "high", "SubjectUserName", None),
    ("1102", "audit_log_cleared", "high", "SubjectUserName", None),
    ("7045", "service_installed", "medium", None, "ServiceName"),
]


def windows_rules():
    event_id = coalesce("winlog_event_id", "EventID")
    host = coalesce("winlog_computer_name", "Hostname", "source")

    def data(field):
        return coalesce("winlog_event_data_" + field, field)

    out = []
    for eid, action, sev, user, target in WIN_EVENTS:
        sets = [("log_type", lit("windows")), ("event_action", lit(action)),
                ("severity", lit(sev)), ("host_name", host)]
        if user:
            sets.append(("user_name", data(user)))
        if target:
            sets.append(("target_name", data(target)))
        out.append(rule("win-" + eid, "%s == %s" % (event_id, lit(eid)), sets))

    # 來源 IP 獨立一條：本機登入時 IpAddress 是 "-"，不能讓它拖垮上面的事件分類
    ip = coalesce("winlog_event_data_IpAddress", "IpAddress", "source_ip")
    when = " && ".join(['%s != ""' % event_id, '%s != ""' % ip, '%s != "-"' % ip,
                        '%s != "::1"' % ip, '%s != "127.0.0.1"' % ip])
    out.append(rule("win-src-ip", when, [("src_ip", ip)]))
    return out


# Linux（rsyslog 進 Graylog 後的 message 文字）。(名稱, 關鍵字條件, action, severity, grok)
LINUX_EVENTS = [
    ("linux-ssh-failed", 'contains(%s, "Failed password for")' % MSG, "login_failed", "low",
     "Failed password for (?:invalid user )?%{USERNAME:user_name} from %{IP:src_ip}"),
    # 帳號不存在時 sshd 會記兩行：「Invalid user X」與「Failed password for invalid user X」。
    # 用不同的 action，入口才不會把同一次嘗試算成兩次登入失敗
    ("linux-ssh-invalid-user", 'contains(%s, "Invalid user")' % MSG, "invalid_user", "low",
     "Invalid user %{USERNAME:user_name} from %{IP:src_ip}"),
    ("linux-ssh-accepted",
     'contains(%s, "Accepted password for") || contains(%s, "Accepted publickey for")' % (MSG, MSG),
     "login_success", "info",
     "Accepted %{WORD} for %{USERNAME:user_name} from %{IP:src_ip}"),
    ("linux-sudo-command", 'contains(%s, "TTY=") && contains(%s, "COMMAND=")' % (MSG, MSG),
     "privileged_command", "medium",
     "%{USERNAME:user_name} : TTY="),
]


def linux_rules():
    host = coalesce("source")
    return [
        rule(name, when,
             [("log_type", lit("linux")), ("event_action", lit(action)),
              ("severity", lit(sev)), ("host_name", host)],
             grok=grok)
        for name, when, action, sev, grok in LINUX_EVENTS
    ]


def firewall_rules():
    """FortiGate 的欄位由既有 pipeline fortigate_normalize 產生：srcip/dstip/action 是它整理過的，
    src/dst/act 是 CEF 原始欄位；其他 CEF 設備只有後者。兩種都認，先取整理過的。
    三條規則寫入的欄位互不重疊，條件互斥。"""
    src, dst = coalesce("srcip", "src"), coalesce("dstip", "dst")
    act = "lowercase(%s)" % coalesce("action", "act")
    deny = " || ".join('contains(%s, "%s")' % (act, w) for w in ("deny", "drop", "block", "reject"))
    allow = " || ".join('contains(%s, "%s")' % (act, w) for w in ("accept", "allow", "pass"))
    has = '%s != "" && %s != ""' % (src, dst)
    return [
        rule("fw-base", has,
             [("log_type", lit("firewall")), ("src_ip", src),
              ("dst_ip", dst), ("host_name", coalesce("source"))]),
        rule("fw-deny", "%s && (%s)" % (has, deny),
             [("event_action", lit("fw_deny")), ("severity", lit("low"))]),
        rule("fw-allow", "%s && (%s) && !(%s)" % (has, allow, deny),
             [("event_action", lit("fw_allow")), ("severity", lit("info"))]),
    ]


# 網路設備（Cisco IOS 風格助記碼）。其他廠牌要有真實日誌樣本才能寫（同 PaloAlto 的處理方式）。
# grok 裡的 . 對應原文的 [ ]，避免反斜線。
NET_EVENTS = [
    ("net-login-failed", '%SEC_LOGIN-4-LOGIN_FAILED', None, "login_failed", "low",
     "Login failed .user: %{USERNAME:user_name}. .Source: %{IP:src_ip}."),
    ("net-login-success", '%SEC_LOGIN-5-LOGIN_SUCCESS', None, "login_success", "info",
     "Login Success .user: %{USERNAME:user_name}. .Source: %{IP:src_ip}."),
    ("net-link-down", '%LINK-3-UPDOWN', "changed state to down", "link_down", "medium",
     "Interface %{DATA:target_name}, changed state to down"),
    ("net-link-up", '%LINK-3-UPDOWN', "changed state to up", "link_up", "info",
     "Interface %{DATA:target_name}, changed state to up"),
]


def network_rules():
    host = coalesce("source")
    out = []
    for name, mnemonic, extra, action, sev, grok in NET_EVENTS:
        when = 'contains(' + MSG + ', "' + mnemonic + '")'
        if extra:
            when += ' && contains(' + MSG + ', "' + extra + '")'
        out.append(rule(name, when,
                        [("log_type", lit("network")), ("event_action", lit(action)),
                         ("severity", lit(sev)), ("host_name", host)],
                        grok=grok))
    return out


def pipelines():
    """{pipeline 標題: [(rule 標題, rule 原始碼), ...]}"""
    return {
        PIPELINE_PREFIX + "Windows": windows_rules(),
        PIPELINE_PREFIX + "Linux": linux_rules(),
        PIPELINE_PREFIX + "Firewall": firewall_rules(),
        PIPELINE_PREFIX + "Network": network_rules(),
    }


def stage_of(title):
    return STAGE.get(title, 0)


def pipeline_source(title, rules):
    lines = ['pipeline "%s"' % title, "stage %d match either" % stage_of(title)]
    lines += ['  rule "%s"' % t for t, _ in rules]
    lines.append("end")
    return "\n".join(lines)


def lint(title, source):
    """送出前的離線檢查，只能擋低級錯誤；語法是否被 Graylog 接受以 apply 的回應為準。"""
    problems = []
    if "\\" in source:
        problems.append("含反斜線")
    if source.count('"') % 2:
        problems.append("雙引號不成對")
    if source.count("(") != source.count(")"):
        problems.append("括號不成對")
    if not source.endswith("\nend"):
        problems.append("缺少 end")
    for f in re.findall(r'set_field\("([^"]+)"', source):
        if f not in STANDARD_FIELDS:
            problems.append("寫入非標準欄位 " + f)
    for f in re.findall(r"%\{\w+:(\w+)\}", source):
        if f not in STANDARD_FIELDS:
            problems.append("grok 擷取非標準欄位 " + f)
    return ["%s：%s" % (title, p) for p in problems]


# ── Graylog REST ────────────────────────────────────────────────────────

class ApiError(Exception):
    def __init__(self, status, body):
        super().__init__("HTTP %s：%s" % (status, str(body)[:300]))
        self.status = status


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """不跟隨重導向。urllib 預設會連 Authorization 一起轉送（可能送到別台主機），
    而且會把 POST／PUT 改成不帶內容的 GET，讓寫入悄悄變成讀取。"""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Graylog:
    def __init__(self, url, user, password, ca_file=None):
        self.url = url.rstrip("/")
        self.auth = "Basic " + base64.b64encode(("%s:%s" % (user, password)).encode()).decode()
        handlers = [urllib.request.ProxyHandler({}), _NoRedirect()]  # 本機 API，不經 proxy、不跟隨重導向
        if self.url.startswith("https"):
            handlers.append(urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=ca_file)))
        self.opener = urllib.request.build_opener(*handlers)

    def call(self, method, path, body=None):
        headers = {"Authorization": self.auth, "Accept": "application/json",
                   "X-Requested-By": "ioc-pipelines"}
        data = None
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(self.url + path, data=data, headers=headers, method=method)
        try:
            with self.opener.open(req, timeout=30) as r:
                raw = r.read()
        except urllib.error.HTTPError as e:
            if 300 <= e.code < 400:
                raise ApiError(e.code, "Graylog 回應重新導向到 %s；請把 GRAYLOG_URL 改成最終網址（例如 https）"
                               % e.headers.get("Location", "?"))
            raise ApiError(e.code, e.read().decode("utf-8", "replace"))
        except OSError as e:  # 含 URLError、逾時
            raise ApiError(0, "連不上 %s（%s）" % (self.url, getattr(e, "reason", e)))
        try:
            return json.loads(raw) if raw else None
        except ValueError:
            raise ApiError(200, "回應不是 JSON（%s %s）：%s" % (method, path, raw[:120].decode("utf-8", "replace")))

    def list(self, path):
        data = self.call("GET", path)
        if not isinstance(data, list):  # 失敗即停，避免誤判成「不存在」而重複建立
            raise ApiError(200, "非預期的回應格式（%s）；Graylog API 版本是否不同？" % path)
        return data


P_RULE = "/api/system/pipelines/rule"
P_PIPE = "/api/system/pipelines/pipeline"
P_CONN = "/api/system/pipelines/connections"


def connected_map(gl):
    """{pipeline id: {已連的 stream id}}。查不到就失敗：to_pipeline 是整組取代，
    不知道現況就送出，會把別人手動連的 stream 拿掉。"""
    data = gl.call("GET", P_CONN)
    if not isinstance(data, list):
        raise ApiError(200, "非預期的回應格式（%s）；Graylog API 版本是否不同？" % P_CONN)
    out = {}
    for c in data:
        for pid in c.get("pipeline_ids", []):
            out.setdefault(pid, set()).add(c.get("stream_id"))
    return out


def resolve_streams(gl, names):
    """stream 標題或 ID → ID。找不到就報錯並列出現有的，不默默略過（否則防火牆規則會悄悄沒套到）。"""
    data = gl.call("GET", "/api/streams")
    if not isinstance(data, dict) or not isinstance(data.get("streams"), list):
        raise ApiError(200, "非預期的回應格式（/api/streams）；Graylog API 版本是否不同？")
    by_title = {s["title"]: s["id"] for s in data["streams"]}
    known_ids = set(by_title.values())
    ids = []
    for n in names:
        if n in by_title:
            ids.append(by_title[n])
        elif n in known_ids:
            ids.append(n)
        else:
            raise ApiError(0, "找不到 stream「%s」。現有：%s" % (n, "、".join(sorted(by_title)) or "（無）"))
    return ids


def stream_targets(gl, conf):
    """每條 pipeline 要連的 stream：一律有 Default Stream，部分另加設定檔指定的。"""
    default = conf["GRAYLOG_STREAM_ID"]
    names = {t: [n.strip() for n in conf.get(STREAM_KEYS.get(t, ""), "").split(",") if n.strip()]
             for t in pipelines()}
    all_names = sorted({n for ns in names.values() for n in ns})
    resolved = dict(zip(all_names, resolve_streams(gl, all_names))) if all_names else {}
    targets = {}
    for title, ns in names.items():
        targets[title] = [default]
        for n in ns:
            if resolved[n] not in targets[title]:
                targets[title].append(resolved[n])
    return targets


def sync(gl, targets, dry_run, say=print):
    """targets: {pipeline 標題: [要連的 stream ID]}"""
    changes = 0
    wanted = pipelines()
    connected = connected_map(gl)  # 先讀：任何一步讀不到現況，都要在改動任何東西之前就停下

    existing_rules = {r["title"]: r for r in gl.list(P_RULE)}
    for title, source in (r for rules in wanted.values() for r in rules):
        cur = existing_rules.get(title)
        body = {"title": title, "description": DESCRIPTION, "source": source}
        if cur is None:
            say("[規則] %s：新增" % title)
            changes += 1
            if not dry_run:
                gl.call("POST", P_RULE, body)
        elif cur.get("source", "").strip() != source.strip():
            say("[規則] %s：更新" % title)
            changes += 1
            if not dry_run:
                gl.call("PUT", "%s/%s" % (P_RULE, cur["id"]), body)
        else:
            say("[規則] %s：無變更" % title)

    existing_pipes = {p["title"]: p for p in gl.list(P_PIPE)}
    pipe_ids = {}
    for title, rules in wanted.items():
        source = pipeline_source(title, rules)
        cur = existing_pipes.get(title)
        body = {"title": title, "description": DESCRIPTION, "source": source}
        if cur is None:
            say("[Pipeline] %s：新增" % title)
            changes += 1
            if not dry_run:
                pipe_ids[title] = gl.call("POST", P_PIPE, body)["id"]
        else:
            pipe_ids[title] = cur["id"]
            if cur.get("source", "").strip() != source.strip():
                say("[Pipeline] %s：更新" % title)
                changes += 1
                if not dry_run:
                    gl.call("PUT", "%s/%s" % (P_PIPE, cur["id"]), body)
            else:
                say("[Pipeline] %s：無變更" % title)

    for title in wanted:
        pid = pipe_ids.get(title)
        have = connected.get(pid, set()) if pid is not None else set()
        missing = [s for s in targets[title] if s not in have]
        for s in targets[title]:
            say("[連線] %s → stream %s：%s" % (title, s, "新增" if s in missing else "無變更"))
        changes += len(missing)
        if missing and not dry_run and pid is not None:
            # to_pipeline 是整組取代，所以帶入既有的連線：只增不減
            gl.call("POST", P_CONN + "/to_pipeline",
                    {"pipeline_id": pid, "stream_ids": sorted(have | set(targets[title]))})
    return changes


def owned(obj, prefix):
    """只認「標題前綴＋描述標記」都符合的才算本工具建立的，避免誤刪別人剛好同前綴的規則。
    描述欄位若被 Graylog 吃掉，結果是什麼都不刪（安全的失敗方向）。"""
    return obj.get("title", "").startswith(prefix) and obj.get("description") == DESCRIPTION


def remove(gl, say=print):
    """只動本工具建立的 pipeline 與規則；GeoIP 等其他的不碰。"""
    n = 0
    for p in gl.list(P_PIPE):
        if owned(p, PIPELINE_PREFIX):
            say("[Pipeline] %s：解除連線並刪除" % p["title"])
            try:
                gl.call("POST", P_CONN + "/to_pipeline", {"pipeline_id": p["id"], "stream_ids": []})
            except ApiError as e:
                # 這個呼叫尚未在真實 Graylog 驗證過（空清單可能被拒）。不能因此卡住回滾：
                # 照樣刪 pipeline，連線由 Graylog 在刪除時處理；殘留的話到 System → Pipelines 看
                say("  警告：解除連線失敗（%s），仍繼續刪除" % e)
            gl.call("DELETE", "%s/%s" % (P_PIPE, p["id"]))
            n += 1
    for r in gl.list(P_RULE):
        if owned(r, RULE_PREFIX):
            say("[規則] %s：刪除" % r["title"])
            gl.call("DELETE", "%s/%s" % (P_RULE, r["id"]))
            n += 1
    return n


# ── 設定與入口 ──────────────────────────────────────────────────────────

def parse_conf(path):
    conf = {}
    try:
        with open(path, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, v = line.split("=", 1)
                    v = v.strip()
                    if len(v) >= 2 and v[0] == v[-1] and v[0] in "\"'":  # 只拿掉「成對」的外層引號
                        v = v[1:-1]
                    conf[k.strip()] = v
    except FileNotFoundError:
        pass
    return conf


def load_config(env=None):
    env = os.environ if env is None else env
    path = env.get("GRAYLOG_API_CONF", CONF_PATH)
    conf = parse_conf(path)
    for k in ("GRAYLOG_URL", "GRAYLOG_USER", "GRAYLOG_PASS", "GRAYLOG_CA_FILE", "GRAYLOG_STREAM_ID",
              "GRAYLOG_FIREWALL_STREAMS"):
        if env.get(k):
            conf[k] = env[k]
    conf.setdefault("GRAYLOG_URL", "http://127.0.0.1:9000")
    conf.setdefault("GRAYLOG_STREAM_ID", DEFAULT_STREAM_ID)
    conf["_path"] = path
    return conf


def lint_all():
    problems = []
    for title, rules in pipelines().items():
        for rtitle, source in rules:
            problems += lint(rtitle, source)
    return problems


def main(argv=None):
    argv = sys.argv[1:] if argv is None else argv
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")
        sys.stderr.reconfigure(encoding="utf-8")
    cmd = argv[0] if argv else ""
    if cmd not in ("print", "plan", "apply", "remove"):
        print(__doc__.split("設定（")[0].strip(), file=sys.stderr)
        return 1

    problems = lint_all()
    if problems:
        print("規則檢查未通過，未送出任何請求：\n  " + "\n  ".join(problems), file=sys.stderr)
        return 1

    if cmd == "print":
        for title, rules in pipelines().items():
            print("# ===== %s =====" % title)
            print(pipeline_source(title, rules))
            for _, source in rules:
                print()
                print(source)
            print()
        return 0

    conf = load_config()
    if not conf.get("GRAYLOG_USER") or not conf.get("GRAYLOG_PASS"):
        print("缺少 Graylog 帳密：請設定 %s（GRAYLOG_USER / GRAYLOG_PASS）或同名環境變數" % conf["_path"],
              file=sys.stderr)
        return 2
    if os.name == "posix" and os.path.isfile(conf["_path"]) and os.stat(conf["_path"]).st_mode & 0o077:
        print("警告：%s 權限過寬，建議 chmod 600" % conf["_path"], file=sys.stderr)

    gl = Graylog(conf["GRAYLOG_URL"], conf["GRAYLOG_USER"], conf["GRAYLOG_PASS"], conf.get("GRAYLOG_CA_FILE"))
    try:
        if cmd == "remove":
            print("REMOVE: deleted=%d" % remove(gl))
        else:
            n = sync(gl, stream_targets(gl, conf), dry_run=(cmd == "plan"))
            print("%s: changes=%d" % (cmd.upper(), n))
    except ApiError as e:
        print("Graylog API 錯誤：%s" % e, file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
