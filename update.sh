#!/usr/bin/env bash
#
# update.sh — 從 git 拉取最新版後，重新部署程式碼（不動 DB conf / 不重複注入 snmpd）
#
# 用法：
#   cd /opt/oracle-mon-librenms && git pull && sudo ./update.sh
#
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
ORACLE_MON_DIR="${ORACLE_MON_DIR:-/opt/oracle-mon}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"
SNMP_GROUP="${SNMP_GROUP:-Debian-snmp}"

say(){ echo -e "\033[1;36m[update]\033[0m $*"; }
[ "$(id -u)" -eq 0 ] || { echo "請以 sudo 執行"; exit 1; }

say "更新 collector 程式（保留 dbs/*.conf）"
install -m 644 -o root -g root "$HERE/collector/OracleStats.java" "$ORACLE_MON_DIR/OracleStats.java"
install -m 644 -o root -g root "$HERE/collector/lib/ojdbc14.jar"   "$ORACLE_MON_DIR/lib/ojdbc14.jar"
install -m 750 -o root -g "$SNMP_GROUP" "$HERE/collector/run.sh"   "$ORACLE_MON_DIR/run.sh"
for f in "$HERE"/collector/admin/*.sh; do
    install -m 750 -o root -g "$LIBRENMS_USER" "$f" "$ORACLE_MON_DIR/admin/$(basename "$f")"
done
for f in "$HERE"/collector/admin/*.py; do
    [ -e "$f" ] && install -m 644 -o root -g "$LIBRENMS_USER" "$f" "$ORACLE_MON_DIR/admin/$(basename "$f")"
done

say "重新編譯 OracleStats.java"
( cd "$ORACLE_MON_DIR" && javac -cp "$ORACLE_MON_DIR/lib/ojdbc14.jar" OracleStats.java )
chmod 644 "$ORACLE_MON_DIR/OracleStats.class"

say "更新 LibreNMS 客製檔"
GRAPH_DST="$LIBRENMS_DIR/includes/html/graphs/application"
POLL_DST="$LIBRENMS_DIR/includes/polling/applications"
PAGE_DEV="$LIBRENMS_DIR/includes/html/pages/device/apps"
PAGE_APP="$LIBRENMS_DIR/includes/html/pages/apps"
for f in "$HERE"/librenms/html/*.php; do
    install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$f" "$LIBRENMS_DIR/html/$(basename "$f")"
done
for f in "$HERE"/librenms/graphs/*.inc.php; do
    install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$f" "$GRAPH_DST/$(basename "$f")"
done
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/librenms/polling/oracle-l1hweb.inc.php" "$POLL_DST/oracle-l1hweb.inc.php"
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/librenms/pages/device_apps/oracle-l1hweb.inc.php" "$PAGE_DEV/oracle-l1hweb.inc.php"
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/librenms/pages/apps/oracle-l1hweb.inc.php" "$PAGE_APP/oracle-l1hweb.inc.php"

say "更新 sudoers（新增指令需重新套用）"
install -m 440 -o root -g root "$HERE/system/sudoers.oracle-admin" /etc/sudoers.d/oracle-admin

say "清除 view 快取"
sudo -u "$LIBRENMS_USER" bash -c "cd '$LIBRENMS_DIR' && php artisan view:clear" || true
say "完成。"
