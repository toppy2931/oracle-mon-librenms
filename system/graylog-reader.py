#!/usr/bin/env python3
"""
graylog-reader.py — 建立中文入口專用的 Graylog 唯讀帳號，並把 Token 存成入口讀得到的設定檔

為什麼要另外建帳號：入口（PHP-FPM，以 librenms 身分跑）只需要查詢日誌。若直接用 LibreNMS 設定裡的
Graylog admin 帳密，入口一旦有漏洞就等於 Graylog 的 admin 權限（規格 IS-012 最小權限）。

用法：
  graylog-reader.py create    建立（或確認）帳號 ioc-reader、取得 Token、驗證權限、寫設定檔
  graylog-reader.py status    唯讀：帳號與設定檔是否存在
  graylog-reader.py remove    刪除帳號並移除設定檔

用 graylog-api.conf 的 admin 帳密操作（設定方式見 graylog-pipelines.py）。產生的
/etc/oracle-mon/graylog-reader.conf（root:librenms，640）只含唯讀 Token 與要查的 stream；
GRAYLOG_READER_CONF 可指定其他路徑（測試用）。

可查的 stream：Default Stream，加上 graylog-api.conf 的 GRAYLOG_FIREWALL_STREAMS 所列的。
之後新增了要查的 stream，改完設定檔重跑 create 即可補權限，不會換 Token。

退出碼：0 成功；1 失敗（API 錯誤、驗證不過）；2 缺少 admin 設定。
Token 不會印在畫面上。
"""
import importlib.util
import os
import secrets
import stat
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
READER_USER = "ioc-reader"
READER_ROLE = "Reader"
READER_CONF = "/etc/oracle-mon/graylog-reader.conf"


def _load_pipelines():
    spec = importlib.util.spec_from_file_location("graylog_pipelines", os.path.join(HERE, "graylog-pipelines.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


gp = _load_pipelines()


def reader_conf_path(env=None):
    return (os.environ if env is None else env).get("GRAYLOG_READER_CONF", READER_CONF)


def stream_ids(gl, conf):
    """Default Stream ＋ 設定檔指定的防火牆 stream，轉成 ID（找不到就報錯）。"""
    names = [n.strip() for n in conf.get("GRAYLOG_FIREWALL_STREAMS", "").split(",") if n.strip()]
    ids = [conf["GRAYLOG_STREAM_ID"]]
    for i in (gp.resolve_streams(gl, names) if names else []):
        if i not in ids:
            ids.append(i)
    return ids


def wanted_permissions(streams):
    return ["streams:read:%s" % s for s in streams]


def ensure_user(gl, streams, say=print):
    """帳號不存在就建立；存在就只補缺的角色與權限（保留 Graylog 自己加的預設權限）。回傳是否有變更。"""
    perms = wanted_permissions(streams)
    try:
        user = gl.call("GET", "/api/users/%s" % READER_USER)
    except gp.ApiError as e:
        if e.status != 404:
            raise
        gl.call("POST", "/api/users", {
            "username": READER_USER, "email": "ioc-reader@localhost", "first_name": "IOC", "last_name": "Reader",
            "password": secrets.token_urlsafe(32),     # 只用 Token 登入，密碼沒人會用
            "roles": [READER_ROLE], "permissions": perms, "timezone": "Asia/Taipei",
        })
        say("[帳號] %s：已建立（角色 %s，可讀 %d 個 stream）" % (READER_USER, READER_ROLE, len(streams)))
        return True

    have_roles = list(user.get("roles", []))
    have_perms = list(user.get("permissions", []))
    missing_roles = [] if READER_ROLE in have_roles else [READER_ROLE]
    missing_perms = [p for p in perms if p not in have_perms]
    if not missing_roles and not missing_perms:
        say("[帳號] %s：無變更" % READER_USER)
        return False
    gl.call("PUT", "/api/users/%s" % READER_USER,
            {"roles": have_roles + missing_roles, "permissions": have_perms + missing_perms})
    say("[帳號] %s：已補權限（%d 個 stream）" % (READER_USER, len(missing_perms)))
    return True


def verify(url, token, streams, ca_file=None):
    """用唯讀 Token 真的查一次（對每個 stream），回傳 None 表示通過，否則回傳原因。"""
    client = gp.Graylog(url, token, "token", ca_file)      # Graylog 的 Token 驗證：帳號放 Token，密碼固定 token
    body = {"query": "_exists_:source", "streams": streams, "timerange": {"type": "relative", "range": 300},
            "group_by": [{"field": "source", "limit": 1}], "metrics": [{"function": "count"}]}
    try:
        data = client.call("POST", "/api/search/aggregate", body)
    except gp.ApiError as e:
        if e.status in (401, 403):
            return "唯讀帳號沒有查詢這些 stream 的權限（HTTP %s）；請到 Graylog 檢查 %s 的角色與 stream 權限" % (e.status, READER_USER)
        return str(e)
    if not isinstance(data, dict) or "schema" not in data or "datarows" not in data:
        return "查詢回應的格式不符，Graylog 版本是否不同？"
    return None


def read_conf(path):
    return gp.parse_conf(path)


def write_conf(path, url, token, streams, ca_file=None):
    """寫成 root:librenms 640（入口讀得到、其他人讀不到）。非 root 或沒有 librenms 群組時只設 640。"""
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    lines = ["# 由 system/graylog-reader.py 產生；只含唯讀 Token，請勿手動編輯",
             "GRAYLOG_URL=%s" % url, "GRAYLOG_TOKEN=%s" % token, "GRAYLOG_STREAMS=%s" % ",".join(streams)]
    if ca_file:
        lines.append("GRAYLOG_CA_FILE=%s" % ca_file)
    tmp = path + ".tmp"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o640)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")
    warning = None
    try:
        os.chmod(tmp, 0o640)
    except OSError:
        pass
    if hasattr(os, "geteuid") and os.geteuid() == 0:
        import grp
        try:
            os.chown(tmp, 0, grp.getgrnam("librenms").gr_gid)
        except KeyError:
            warning = "找不到群組 librenms，入口（PHP-FPM）可能讀不到設定檔，請自行 chgrp"
        except OSError as e:
            warning = "無法把設定檔的群組設成 librenms（%s），入口可能讀不到" % (e.strerror or e)
    os.replace(tmp, path)
    return warning


def create(gl, conf, say=print):
    streams = stream_ids(gl, conf)
    ensure_user(gl, streams, say)
    path = reader_conf_path()
    url = conf["GRAYLOG_URL"]
    ca = conf.get("GRAYLOG_CA_FILE")

    # 舊 Token 對「現在這份」stream 清單也查得通（ensure_user 剛補好權限）就沿用，只更新清單。
    # 不能每次清單變動就簽新 Token：舊的不會自動作廢，會留下沒人記得的有效憑證。
    existing = read_conf(path)
    old = existing.get("GRAYLOG_TOKEN")
    if old and verify(url, old, streams, ca) is None:
        if existing.get("GRAYLOG_STREAMS", "").split(",") == streams and existing.get("GRAYLOG_URL") == url:
            say("[Token] 設定檔裡的 Token 仍有效，無需變更（%s）" % path)
        else:
            warning = write_conf(path, url, old, streams, ca)
            say("[Token] 沿用原本的 Token，已更新設定檔的 stream 清單（%s）" % path)
            if warning:
                say("警告：" + warning)
        return 0

    name = "ioc-portal-%s" % time.strftime("%Y%m%d%H%M%S")
    created = gl.call("POST", "/api/users/%s/tokens/%s" % (READER_USER, name))
    token = created.get("token") if isinstance(created, dict) else None
    if not token:
        say("Graylog 沒有回傳 Token，無法繼續")
        return 1
    problem = verify(url, token, streams, ca)
    if problem:
        try:                                    # 驗證不過的 Token 不留著
            gl.call("DELETE", "/api/users/%s/tokens/%s" % (READER_USER, created.get("id", name)))
        except gp.ApiError:
            pass
        say("[Token] 驗證失敗，未寫設定檔：%s" % problem)
        return 1
    warning = write_conf(path, url, token, streams, ca)
    say("[Token] 已建立並驗證，寫入 %s" % path)
    if warning:
        say("警告：" + warning)
    return 0


def status(gl, say=print):
    path = reader_conf_path()
    try:
        user = gl.call("GET", "/api/users/%s" % READER_USER)
        say("[帳號] %s：存在（角色 %s）" % (READER_USER, "、".join(user.get("roles", [])) or "無"))
    except gp.ApiError as e:
        if e.status != 404:
            raise
        say("[帳號] %s：不存在" % READER_USER)
    if os.path.isfile(path):
        mode = stat.S_IMODE(os.stat(path).st_mode)
        say("[設定檔] %s：存在（權限 %o）" % (path, mode))
        if os.name == "posix" and mode & 0o007:
            say("  警告：其他人讀得到，建議 chmod 640 並確認群組是 librenms")
    else:
        say("[設定檔] %s：不存在" % path)
    return 0


def remove(gl, say=print):
    try:
        gl.call("DELETE", "/api/users/%s" % READER_USER)
        say("[帳號] %s：已刪除" % READER_USER)
    except gp.ApiError as e:
        if e.status != 404:
            raise
        say("[帳號] %s：本來就不存在" % READER_USER)
    path = reader_conf_path()
    if os.path.isfile(path):
        os.remove(path)
        say("[設定檔] %s：已移除" % path)
    return 0


def main(argv=None):
    argv = sys.argv[1:] if argv is None else argv
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")
        sys.stderr.reconfigure(encoding="utf-8")
    cmd = argv[0] if argv else ""
    if cmd not in ("create", "status", "remove"):
        print(__doc__.split("為什麼要另外建帳號")[0].strip(), file=sys.stderr)
        return 1
    conf = gp.load_config()
    if not conf.get("GRAYLOG_USER") or not conf.get("GRAYLOG_PASS"):
        print("缺少 Graylog admin 帳密：請設定 %s（GRAYLOG_USER / GRAYLOG_PASS）或同名環境變數" % conf["_path"],
              file=sys.stderr)
        return 2
    gl = gp.Graylog(conf["GRAYLOG_URL"], conf["GRAYLOG_USER"], conf["GRAYLOG_PASS"], conf.get("GRAYLOG_CA_FILE"))
    try:
        if cmd == "create":
            return create(gl, conf)
        if cmd == "status":
            return status(gl)
        return remove(gl)
    except gp.ApiError as e:
        print("Graylog API 錯誤：%s" % e, file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
