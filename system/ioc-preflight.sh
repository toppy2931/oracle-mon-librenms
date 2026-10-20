#!/usr/bin/env bash
#
# ioc-preflight.sh — IOC 平台上線前自檢（唯讀，不修改任何設定）
#
# 對應規格：IS-051 / DEP-043（不得保留出廠密碼）、IS-041 / IS-042（管理通訊用安全傳輸）、
#          Graylog 欄位標準化 pipeline 是否已套用。
#
# 用法：
#   sudo /opt/oracle-mon-librenms/system/ioc-preflight.sh
#
# 退出碼：0 = 沒有 FAIL（WARN / SKIP 不影響）；1 = 至少一項 FAIL
# 設定來源：GRAYLOG_URL 等環境變數優先，其次 /etc/oracle-mon/graylog-api.conf（見 graylog-pipelines.py）
#
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF="${GRAYLOG_API_CONF:-/etc/oracle-mon/graylog-api.conf}"
LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
PYTHON="${PYTHON:-python3}"
# OVA 樣板出廠預設（CLAUDE.md「樣板機出廠預設帳密」）；只用來確認它「已經失效」，只試一次。
FACTORY_USER="admin"
FACTORY_PASS='Graylog@2026!'

FAILS=0
pass(){ printf '[PASS] %s\n' "$*"; }
warn(){ printf '[WARN] %s\n' "$*"; }
skip(){ printf '[SKIP] %s\n' "$*"; }
fail(){ printf '[FAIL] %s\n' "$*"; FAILS=$((FAILS+1)); }

conf_get(){
    [ -f "$CONF" ] || return 0
    # 與 graylog-pipelines.py 的 parse_conf 同一規則：只拿掉「成對」的外層引號
    grep -E "^$1=" "$CONF" | tail -1 | cut -d= -f2- | sed -E "s/^(['\"])(.+)\1\$/\2/"
}

URL="${GRAYLOG_URL:-$(conf_get GRAYLOG_URL)}"; URL="${URL:-http://127.0.0.1:9000}"
CA="${GRAYLOG_CA_FILE:-$(conf_get GRAYLOG_CA_FILE)}"
CURL=(curl -s -o /dev/null -w '%{http_code}' --max-time 10)
[ -n "$CA" ] && CURL+=(--cacert "$CA")      # 不使用 -k：不以關閉憑證驗證作為解法（IS-043）

# 1) Graylog 可連線
code="$("${CURL[@]}" "$URL/api/system/lbstatus" 2>/dev/null || true)"
if [ "$code" = "200" ]; then
    pass "Graylog 可連線（$URL）"
    REACHABLE=1
else
    skip "連不上 Graylog（$URL，HTTP ${code:-000}）；以下與 Graylog 有關的檢查略過"
    REACHABLE=0
fi

# 2) IS-051：出廠密碼
if [ "$REACHABLE" = 1 ]; then
    code="$("${CURL[@]}" -u "${FACTORY_USER}:${FACTORY_PASS}" "$URL/api/system" 2>/dev/null || true)"
    case "$code" in
        200) fail "IS-051 Graylog 仍可用出廠密碼登入（${FACTORY_USER}）。請更換：改 server.conf 的 root_password_sha2 後重啟 graylog-server" ;;
        401|403) pass "IS-051 Graylog 出廠密碼已失效" ;;
        *) skip "IS-051 無法判斷（HTTP ${code:-000}）" ;;
    esac
fi

# 3) IS-041：管理通訊
case "$URL" in
    https://*) pass "IS-041 Graylog API 使用 HTTPS" ;;
    *)         [ "$REACHABLE" = 1 ] && warn "IS-041 Graylog API 目前是 HTTP（$URL）；正式環境應改 HTTPS，憑證依機關政策" ;;
esac
if [ -f "$LIBRENMS_DIR/.env" ]; then
    app_url="$(grep -E '^APP_URL=' "$LIBRENMS_DIR/.env" | tail -1 | cut -d= -f2-)"
    case "$app_url" in
        https://*) pass "IS-041 LibreNMS APP_URL 使用 HTTPS" ;;
        http://*)  warn "IS-041 LibreNMS APP_URL 目前是 HTTP（$app_url）" ;;
        *)         skip "IS-041 讀不到 LibreNMS APP_URL" ;;
    esac
else
    skip "IS-041 找不到 $LIBRENMS_DIR/.env"
fi

# 4) Graylog API 設定檔權限（內含帳密）
if [ -f "$CONF" ]; then
    mode="$(stat -c '%a' "$CONF" 2>/dev/null || echo '')"
    if [ -z "$mode" ]; then
        skip "無法讀取 $CONF 權限"
    elif [ $(( 8#$mode & 8#077 )) -eq 0 ]; then
        pass "$CONF 權限 $mode"
    else
        warn "$CONF 權限 $mode 過寬，建議 chmod 600"
    fi
else
    skip "尚未建立 $CONF（Graylog pipeline 自動套用會略過）"
fi

# 5) 欄位標準化 pipeline 是否已套用
if [ "$REACHABLE" = 1 ] && command -v "$PYTHON" >/dev/null 2>&1; then
    out="$("$PYTHON" "$HERE/graylog-pipelines.py" plan 2>&1)"; rc=$?
    if [ $rc -eq 2 ]; then
        skip "欄位標準化 pipeline：缺 Graylog 帳密，無法比對"
    elif [ $rc -ne 0 ]; then
        warn "欄位標準化 pipeline：比對失敗：$(printf '%s' "$out" | tail -1)"
    elif printf '%s' "$out" | grep -q '^PLAN: changes=0$'; then
        pass "欄位標準化 pipeline 已套用且與程式一致"
    else
        warn "欄位標準化 pipeline 尚未套用或有差異：$(printf '%s' "$out" | tail -1)（執行 graylog-pipelines.py apply）"
    fi
fi

echo
if [ "$FAILS" -gt 0 ]; then
    echo "結果：$FAILS 項 FAIL"
    exit 1
fi
echo "結果：無 FAIL"
