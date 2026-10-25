#!/usr/bin/env bash
# Optional, administrator-invoked installation. Never enables timers itself.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
[ "$(id -u)" -eq 0 ] || { echo '請以 root 執行'; exit 1; }
if [ "$LIBRENMS_DIR" != /opt/librenms ]; then
    echo '此服務定義目前僅支援 /opt/librenms'; exit 1
fi
id oracle-backup >/dev/null 2>&1 || { echo '請先安裝組態備份工作器'; exit 1; }
if ! id ioc-audit >/dev/null 2>&1; then
    useradd --system --user-group --home-dir /var/lib/oracle-mon/ioc-audit --shell /usr/sbin/nologin ioc-audit
fi
install -d -m 700 -o ioc-audit -g ioc-audit /var/lib/oracle-mon/ioc-audit
install -d -m 750 -o oracle-backup -g librenms /var/lib/oracle-mon/ioc-audit-network-results
install -d -m 750 -o ioc-audit -g librenms /var/lib/oracle-mon/ioc-audit-linux-results
install -d -m 755 -o librenms -g librenms /var/lib/oracle-mon/ioc-audit-requests
install -d -m 755 /opt/oracle-mon/admin
for item in ioc-audit-network.py ioc-audit-linux.py ioc-audit-spool.py ioc-audit-tick.php ioc-audit-cases.php; do
    install -m 644 -o root -g root "$HERE/$item" "/opt/oracle-mon/admin/$item"
done
for item in ioc-audit.service ioc-audit.timer ioc-audit-network.service ioc-audit-network.timer ioc-audit-linux.service ioc-audit-linux.timer ioc-audit-cases.service ioc-audit-cases.timer; do
    install -m 644 -o root -g root "$HERE/$item" "/etc/systemd/system/$item"
done
systemctl daemon-reload
echo '稽核工作器已安裝，排程尚未啟用；完成 VM 驗收後再明確啟用四個 timer。'
