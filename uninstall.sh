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
rm -f "$LIBRENMS_DIR"/html/ioc-portal.php \
      "$LIBRENMS_DIR"/html/ioc-data.php \
      "$LIBRENMS_DIR"/html/ioc-cases.php \
      "$LIBRENMS_DIR"/html/ioc-settings.php \
      "$LIBRENMS_DIR"/html/ioc-identity.php \
      "$LIBRENMS_DIR"/html/ioc-network.php \
      "$LIBRENMS_DIR"/html/ioc-backup.php \
      "$LIBRENMS_DIR"/html/ioc-backup-download.php \
      "$LIBRENMS_DIR"/html/ioc-audit.php \
      "$LIBRENMS_DIR"/html/ioc-audit-download.php \
      "$LIBRENMS_DIR"/html/js/ioc-config-backup.js \
      "$LIBRENMS_DIR"/html/js/ioc-audit.js
rm -rf "$LIBRENMS_DIR"/includes/ioc      # IOC 入口函式庫（事件案與設定的資料表 ioc_* 與讀取用的 Graylog 帳號會保留，見文末）
rm -f "$LIBRENMS_DIR"/includes/html/graphs/application/oracle-l1hweb_*.inc.php
rm -f "$LIBRENMS_DIR"/includes/polling/applications/oracle-l1hweb.inc.php
rm -f "$LIBRENMS_DIR"/includes/html/pages/device/apps/oracle-l1hweb.inc.php
rm -f "$LIBRENMS_DIR"/includes/html/pages/apps/oracle-l1hweb.inc.php

say "移除 sudoers"
# 只停止本模組的背景服務，備份資料與專用帳號保留。
for unit in ioc-config-backup.timer ioc-config-backup.service ioc-config-backup-oxidized.service; do
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
done
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
say "IOC 入口的資料（事件案 ioc_cases／ioc_case_history、設定 ioc_settings／ioc_settings_history）與 Graylog 唯讀帳號 ioc-reader 也不會刪；要移除請見 docs/ioc/phase1.md「回滾」。"
