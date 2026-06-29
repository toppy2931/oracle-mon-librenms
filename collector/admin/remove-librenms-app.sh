#!/bin/bash
# remove-librenms-app.sh — 移除指定 Oracle DB 的 LibreNMS PHP 檔（conf 已由 remove-db-conf.sh 處理）
# Usage: remove-librenms-app.sh <alias>
set -euo pipefail

ALIAS="$1"
echo "$ALIAS" | grep -qE '^[a-z0-9-]+$' || { echo "ERROR: Invalid alias: $ALIAS" >&2; exit 1; }

# 安全鎖：l1hweb 是系統基底模板，禁止透過此腳本刪除
if [ "$ALIAS" = "l1hweb" ]; then
    echo "ERROR: l1hweb is the base template and cannot be removed via this script" >&2
    exit 1
fi

TARGET="oracle-${ALIAS}"
LIBRENMS="${LIBRENMS_DIR:-/opt/librenms}"
removed=0

for f in \
    "$LIBRENMS/includes/polling/applications/${TARGET}.inc.php" \
    "$LIBRENMS/includes/html/pages/apps/${TARGET}.inc.php" \
    "$LIBRENMS/includes/html/pages/device/apps/${TARGET}.inc.php"; do
    if [ -f "$f" ]; then
        rm -f "$f"
        echo "REMOVED: $f"
        removed=$((removed+1))
    fi
done

for f in "$LIBRENMS/includes/html/graphs/application/${TARGET}_"*.inc.php; do
    [ -f "$f" ] || continue
    rm -f "$f"
    echo "REMOVED: $f"
    removed=$((removed+1))
done

echo "Done: $removed PHP files removed for $TARGET"
