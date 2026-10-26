#!/usr/bin/env bash
# 在 VM 終端互動設定 Webhook；密鑰不經過網頁、資料庫或 shell 參數。
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo '請以 root 執行'; exit 1; }
read -r -p 'Webhook HTTPS URL：' webhook_url
python3 - "$webhook_url" <<'PY'
import sys
from urllib.parse import urlsplit
u = urlsplit(sys.argv[1])
if u.scheme != 'https' or not u.hostname or u.username or u.password or u.fragment or len(sys.argv[1]) > 2048:
    raise SystemExit('只接受不含帳密或片段的 HTTPS URL')
PY
umask 077
install -d -m 755 /etc/oracle-mon
secret="$(openssl rand -hex 32)"
temp="$(mktemp /etc/oracle-mon/.ioc-behavior-webhook.XXXXXX)"
trap 'rm -f "$temp"' EXIT
printf 'WEBHOOK_URL=%s\nWEBHOOK_SECRET_HEX=%s\n' "$webhook_url" "$secret" > "$temp"
chown root:librenms "$temp"
chmod 640 "$temp"
mv -f "$temp" /etc/oracle-mon/ioc-behavior-webhook.conf
echo '已設定 Webhook。請將以下密鑰交給接收端驗證 HMAC-SHA256；此處只顯示一次：'
printf '%s\n' "$secret"
