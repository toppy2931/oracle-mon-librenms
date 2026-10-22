#!/usr/bin/env bash
# IOC 備份特權入口：固定程式與身分，請求只從標準輸入傳遞。
set -euo pipefail
if [ "$#" -ne 0 ]; then
    printf '%s\n' '{"ok":false,"error":{"code":"validation","message":"不支援命令列參數"}}'
    exit 1
fi
exec /usr/sbin/runuser -u oracle-backup -- /usr/bin/env -i PATH=/usr/bin:/bin /usr/bin/python3 /opt/oracle-mon/admin/ioc-config-backup.py
