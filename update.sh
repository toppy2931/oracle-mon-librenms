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

say "更新 IOC 入口函式庫與事件案資料表（冪等）"
IOC_LIB_DST="$LIBRENMS_DIR/includes/ioc"
mkdir -p "$IOC_LIB_DST"
for f in "$HERE"/librenms/ioc/*.php; do
    [ -e "$f" ] && install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$f" "$IOC_LIB_DST/$(basename "$f")"
done
if command -v php >/dev/null; then
    IOC_TMP="$(mktemp --suffix=.php /tmp/oraclemon_ioc_db.XXXXXX)"
    cp "$HERE/system/ioc-db-setup.php" "$IOC_TMP"
    chown "$LIBRENMS_USER":"$LIBRENMS_USER" "$IOC_TMP"
    sudo -u "$LIBRENMS_USER" env LIBRENMS_DIR="$LIBRENMS_DIR" php "$IOC_TMP" || say "事件案資料表未建立（可稍後手動執行 system/ioc-db-setup.php）"
    rm -f "$IOC_TMP"
fi

if command -v python3 >/dev/null; then
    say "修補 LibreNMS 齒輪選單"
    python3 "$HERE/system/menu-patch.py" "$LIBRENMS_DIR/resources/views/layouts/menu.blade.php" || say "選單未自動修補，請見 README 手動加入"

    say "修補 Custom Map blade（讓 custom_map_refresh 可獨立設定）"
    python3 "$HERE/system/custom-map-patch.py" "$LIBRENMS_DIR/resources/views/map/custom-view.blade.php" || say "Custom Map blade 未修補，區塊 F GUI 將無效果"

    say "修補 Apps 概觀（為 oracle-* app 註冊概觀縮圖，避免 Graph Template Missing）"
    python3 "$HERE/system/apps-overview-patch.py" "$LIBRENMS_DIR/includes/html/pages/apps.inc.php" || say "Apps 概觀未修補（apps.inc.php 結構可能已變）"

    say "修補 Graph 自動對應（oracle 圖一律綁定 graph_type 指定 alias 的現役 app_id）"
    python3 "$HERE/system/graph-app-autocorrect-patch.py" "$LIBRENMS_DIR/includes/html/graphs/application/auth.inc.php" || say "Graph 自動對應未修補（auth.inc.php 結構可能已變）"
fi

GRAYLOG_API_CONF="${GRAYLOG_API_CONF:-/etc/oracle-mon/graylog-api.conf}"
if [ ! -f "$GRAYLOG_API_CONF" ]; then
    say "未設定 $GRAYLOG_API_CONF，略過 Graylog IOC pipeline"
elif ! command -v python3 >/dev/null; then
    say "已有 $GRAYLOG_API_CONF 但找不到 python3，略過 Graylog IOC pipeline"
else
    say "套用 Graylog IOC 欄位標準化 pipeline（冪等）"
    python3 "$HERE/system/graylog-pipelines.py" apply || say "Graylog pipeline 未套用（可稍後手動執行 system/graylog-pipelines.py apply）"
fi

say "更新 sudoers（新增指令需重新套用）"
install -m 440 -o root -g root "$HERE/system/sudoers.oracle-admin" /etc/sudoers.d/oracle-admin

say "清除 view 快取"
sudo -u "$LIBRENMS_USER" bash -c "cd '$LIBRENMS_DIR' && php artisan view:clear" || true
say "完成。"
