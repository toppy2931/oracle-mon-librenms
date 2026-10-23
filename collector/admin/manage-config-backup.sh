#!/usr/bin/env bash
# IOC 備份特權入口：固定程式與身分，請求只從標準輸入傳遞。
set -euo pipefail
if [ "$#" -ne 0 ]; then
    printf '%s\n' '{"ok":false,"error":{"code":"validation","message":"不支援命令列參數"}}'
    exit 1
fi
worker_status=0
result=$(/usr/sbin/runuser -u oracle-backup -- /usr/bin/env -i PATH=/usr/bin:/bin /usr/bin/python3 /opt/oracle-mon/admin/ioc-config-backup.py) || worker_status=$?

# 原生 API 設備不需要 Oxidized；不要讓空 router.db 觸發服務重啟迴圈。
# 維持固定服務名稱與固定狀態檔路徑，不接受呼叫端提供 systemd 參數。
router_db=/var/lib/oracle-mon/config-backup/oxidized/router.db
if [ -s "$router_db" ]; then
    if ! systemctl is-active --quiet ioc-config-backup-oxidized.service; then
        systemctl reset-failed ioc-config-backup-oxidized.service >/dev/null 2>&1 || true
        systemctl start ioc-config-backup-oxidized.service >/dev/null 2>&1 || true
    fi
else
    systemctl stop ioc-config-backup-oxidized.service >/dev/null 2>&1 || true
    systemctl reset-failed ioc-config-backup-oxidized.service >/dev/null 2>&1 || true
fi

printf '%s\n' "$result"
exit "$worker_status"
