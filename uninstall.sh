#!/usr/bin/env bash
#
# uninstall.sh — 移除本套件部署的客製檔（保留 LibreNMS 本體與 RRD 歷史資料）
#
# 預設保留 /opt/oracle-mon/dbs/*.conf（含連線設定）與 RRD。
# 加 --purge 才一併刪除 /opt/oracle-mon 整個目錄。
#
set -euo pipefail
LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
ORACLE_MON_DIR="${ORACLE_MON_DIR:-/opt/oracle-mon}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"
SNMPD_CONF="${SNMPD_CONF:-/etc/snmp/snmpd.conf}"

say(){ echo -e "\033[1;36m[uninstall]\033[0m $*"; }
[ "$(id -u)" -eq 0 ] || { echo "請以 sudo 執行"; exit 1; }

say "移除 LibreNMS 客製檔"
rm -f "$LIBRENMS_DIR"/html/oracle-admin.php \
      "$LIBRENMS_DIR"/html/oracle-save.php \
      "$LIBRENMS_DIR"/html/oracle-test.php \
      "$LIBRENMS_DIR"/html/oracle-db-add.php \
      "$LIBRENMS_DIR"/html/oracle-db-remove.php \
      "$LIBRENMS_DIR"/html/oracle-ip-update.php \
      "$LIBRENMS_DIR"/html/oracle-dashboard.php \
      "$LIBRENMS_DIR"/html/oracle-dashboard-data.php
rm -f "$LIBRENMS_DIR"/includes/html/graphs/application/oracle-l1hweb_*.inc.php
rm -f "$LIBRENMS_DIR"/includes/polling/applications/oracle-l1hweb.inc.php
rm -f "$LIBRENMS_DIR"/includes/html/pages/device/apps/oracle-l1hweb.inc.php
rm -f "$LIBRENMS_DIR"/includes/html/pages/apps/oracle-l1hweb.inc.php

say "移除 sudoers"
rm -f /etc/sudoers.d/oracle-admin

say "移除 snmpd managed block"
if grep -q "BEGIN oracle-mon managed" "$SNMPD_CONF" 2>/dev/null; then
    sed -i '/# BEGIN oracle-mon managed/,/# END oracle-mon managed/d' "$SNMPD_CONF"
    systemctl reload snmpd 2>/dev/null || true
fi

say "移除齒輪選單區塊"
MENU="$LIBRENMS_DIR/resources/views/layouts/menu.blade.php"
if grep -q "BEGIN oracle-mon menu" "$MENU" 2>/dev/null; then
    sed -i '/BEGIN oracle-mon menu/,/END oracle-mon menu/d' "$MENU"
fi

sudo -u "$LIBRENMS_USER" bash -c "cd '$LIBRENMS_DIR' && php artisan view:clear" || true

if [ "${1:-}" = "--purge" ]; then
    say "--purge：刪除 $ORACLE_MON_DIR（含 dbs/*.conf）"
    rm -rf "$ORACLE_MON_DIR"
else
    say "保留 $ORACLE_MON_DIR（dbs/*.conf 與 jar）。要全刪請加 --purge"
fi

say "完成。注意：本腳本不會刪除 RRD 歷史資料與 LibreNMS 告警規則。"
