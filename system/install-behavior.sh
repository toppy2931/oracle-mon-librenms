#!/usr/bin/env bash
# 部署 IOC 行為工作器。不安裝感測器，也不修改交換器或防火牆。
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ORACLE_MON_DIR="${ORACLE_MON_DIR:-/opt/oracle-mon}"
LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"
[ "$(id -u)" -eq 0 ] || { echo '請以 root 執行'; exit 1; }
if [ "$ORACLE_MON_DIR" != /opt/oracle-mon ] || [ "$LIBRENMS_DIR" != /opt/librenms ]; then
    echo '行為工作器的 systemd 單元目前限 /opt/oracle-mon 與 /opt/librenms；自訂路徑需另行調整單元檔'
    exit 1
fi
install -d -m 755 "$ORACLE_MON_DIR/admin" "$LIBRENMS_DIR/html/js"
install -m 644 -o root -g root "$HERE/ioc-behavior-tick.php" "$ORACLE_MON_DIR/admin/ioc-behavior-tick.php"
install -m 700 -o root -g root "$HERE/configure-behavior-webhook.sh" "$ORACLE_MON_DIR/admin/configure-behavior-webhook.sh"
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/../librenms/html/js/ioc-behavior.js" "$LIBRENMS_DIR/html/js/ioc-behavior.js"
for unit in ioc-behavior.service ioc-behavior.timer; do
    install -m 644 -o root -g root "$HERE/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
systemctl enable --now ioc-behavior.timer >/dev/null
echo '裝置行為工作器已安裝；感測器需另依盤點結果部署。'
