#!/usr/bin/env bash
# 安裝 IOC 組態備份介接檔；不下載套件、不啟用服務、不覆寫現有 Oxidized。
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ORACLE_MON_DIR="${ORACLE_MON_DIR:-/opt/oracle-mon}"
LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"
[ "$(id -u)" -eq 0 ] || { echo '請以 root 執行安裝'; exit 1; }
# systemd 與 sudoers 使用固定受管入口；自訂路徑需另行建立並驗證白名單。
if [ "$ORACLE_MON_DIR" != /opt/oracle-mon ]; then
    echo '組態備份介接目前限 /opt/oracle-mon，自訂路徑未安裝此選用模組'
    exit 0
fi
if ! id oracle-backup >/dev/null 2>&1; then
    useradd --system --user-group --home-dir /var/lib/oracle-mon/config-backup --shell /usr/sbin/nologin oracle-backup
else
    echo 'oracle-backup 帳號已存在，沿用'
fi
install -d -m 700 -o oracle-backup -g oracle-backup /var/lib/oracle-mon/config-backup
install -d -m 755 "$ORACLE_MON_DIR/admin" "$LIBRENMS_DIR/html/js"
install -m 644 -o root -g root "$HERE/ioc-config-backup.py" "$ORACLE_MON_DIR/admin/ioc-config-backup.py"
install -m 750 -o root -g "$LIBRENMS_USER" "$HERE/../collector/admin/manage-config-backup.sh" "$ORACLE_MON_DIR/admin/manage-config-backup.sh"
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/../librenms/html/js/ioc-config-backup.js" "$LIBRENMS_DIR/html/js/ioc-config-backup.js"
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/../librenms/html/js/ioc-audit.js" "$LIBRENMS_DIR/html/js/ioc-audit.js"
for unit in ioc-config-backup-oxidized.service ioc-config-backup.service ioc-config-backup.timer; do
    install -m 644 -o root -g root "$HERE/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
echo '組態備份介接已安裝；服務啟用狀態不變。初次使用須另完成 Oxidized 安裝、SSH 主機金鑰確認及服務啟用。'
