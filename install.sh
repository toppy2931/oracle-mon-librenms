#!/usr/bin/env bash
#
# install.sh — 部署 Oracle 監控套件到 LibreNMS 主機（monitor-vm）
#
# 冪等：可重複執行；已存在的設定不會被覆寫破壞。
# 需以可 sudo 的帳號執行（會用 sudo 寫入 /opt、/etc 與 LibreNMS 目錄）。
#
# 用法：
#   sudo ./install.sh                  # 完整安裝（不含 DB 連線；之後用 GUI 新增）
#   sudo ./install.sh --with-l1hweb    # 同時用內附 example 建立 l1hweb DB conf（需先填密碼）
#
set -euo pipefail

LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
ORACLE_MON_DIR="${ORACLE_MON_DIR:-/opt/oracle-mon}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"
SNMP_GROUP="${SNMP_GROUP:-Debian-snmp}"        # net-snmp extend 執行身分（Debian/Ubuntu）
SNMPD_CONF="${SNMPD_CONF:-/etc/snmp/snmpd.conf}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say(){ echo -e "\033[1;36m[install]\033[0m $*"; }
warn(){ echo -e "\033[1;33m[warn]\033[0m $*"; }
die(){ echo -e "\033[1;31m[error]\033[0m $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "請以 root / sudo 執行：sudo ./install.sh"

# ── 0) 前置檢查 ───────────────────────────────────────────────
command -v java >/dev/null   || die "找不到 java，請先安裝 default-jdk（Java 11+）"
command -v javac >/dev/null  || die "找不到 javac，請先安裝 default-jdk（含 JDK）"
command -v python3 >/dev/null || warn "找不到 python3：snmpd / menu 自動修補將略過"
[ -d "$LIBRENMS_DIR" ]       || die "找不到 LibreNMS 目錄：$LIBRENMS_DIR（可用 LIBRENMS_DIR 覆寫）"
id "$LIBRENMS_USER" >/dev/null 2>&1 || die "找不到使用者 $LIBRENMS_USER"
getent group "$SNMP_GROUP" >/dev/null || warn "找不到群組 $SNMP_GROUP（snmpd extend 群組），請確認 net-snmp 已安裝"

# ── 1) 部署 collector → /opt/oracle-mon ──────────────────────
say "部署 collector 至 $ORACLE_MON_DIR"
mkdir -p "$ORACLE_MON_DIR/admin" "$ORACLE_MON_DIR/lib" "$ORACLE_MON_DIR/dbs"
install -m 644 -o root -g root "$HERE/collector/OracleStats.java" "$ORACLE_MON_DIR/OracleStats.java"
install -m 644 -o root -g root "$HERE/collector/lib/ojdbc14.jar"   "$ORACLE_MON_DIR/lib/ojdbc14.jar"
install -m 750 -o root -g "$SNMP_GROUP" "$HERE/collector/run.sh"   "$ORACLE_MON_DIR/run.sh"
for f in "$HERE"/collector/admin/*.sh; do
    install -m 750 -o root -g "$LIBRENMS_USER" "$f" "$ORACLE_MON_DIR/admin/$(basename "$f")"
done
for f in "$HERE"/collector/admin/*.py; do
    [ -e "$f" ] && install -m 644 -o root -g "$LIBRENMS_USER" "$f" "$ORACLE_MON_DIR/admin/$(basename "$f")"
done

say "編譯 OracleStats.java"
( cd "$ORACLE_MON_DIR" && javac -cp "$ORACLE_MON_DIR/lib/ojdbc14.jar" OracleStats.java )
chmod 644 "$ORACLE_MON_DIR/OracleStats.class"

# 選擇性：建立 l1hweb conf（從 example）
if [ "${1:-}" = "--with-l1hweb" ]; then
    if [ ! -f "$ORACLE_MON_DIR/dbs/l1hweb.conf" ]; then
        install -m 640 -o "$LIBRENMS_USER" -g "$SNMP_GROUP" \
            "$HERE/collector/dbs/l1hweb.conf.example" "$ORACLE_MON_DIR/dbs/l1hweb.conf"
        warn "已從 example 建立 dbs/l1hweb.conf — 請編輯填入正確 DB_PASS！"
    else
        say "dbs/l1hweb.conf 已存在，略過"
    fi
fi

# ── 2) 部署 LibreNMS 客製檔 ──────────────────────────────────
say "部署 LibreNMS 客製檔"
GRAPH_DST="$LIBRENMS_DIR/includes/html/graphs/application"
POLL_DST="$LIBRENMS_DIR/includes/polling/applications"
PAGE_DEV="$LIBRENMS_DIR/includes/html/pages/device/apps"
PAGE_APP="$LIBRENMS_DIR/includes/html/pages/apps"
mkdir -p "$GRAPH_DST" "$POLL_DST" "$PAGE_DEV" "$PAGE_APP"

for f in "$HERE"/librenms/html/*.php; do
    install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$f" "$LIBRENMS_DIR/html/$(basename "$f")"
done
for f in "$HERE"/librenms/graphs/*.inc.php; do
    install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$f" "$GRAPH_DST/$(basename "$f")"
done
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/librenms/polling/oracle-l1hweb.inc.php" "$POLL_DST/oracle-l1hweb.inc.php"
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/librenms/pages/device_apps/oracle-l1hweb.inc.php" "$PAGE_DEV/oracle-l1hweb.inc.php"
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/librenms/pages/apps/oracle-l1hweb.inc.php" "$PAGE_APP/oracle-l1hweb.inc.php"

# ── 3) sudoers（讓 LibreNMS PHP 可呼叫 admin 腳本）──────────────
say "安裝 sudoers"
install -m 440 -o root -g root "$HERE/system/sudoers.oracle-admin" /etc/sudoers.d/oracle-admin
visudo -cf /etc/sudoers.d/oracle-admin >/dev/null || die "sudoers 語法錯誤"

# ── 4) snmpd managed block（冪等）─────────────────────────────
if ! grep -q "BEGIN oracle-mon managed" "$SNMPD_CONF" 2>/dev/null; then
    say "注入 snmpd extend managed block"
    {
        echo ""
        cat "$HERE/system/snmpd-extend.conf.snippet"
    } >> "$SNMPD_CONF"
    systemctl reload snmpd 2>/dev/null || systemctl restart snmpd 2>/dev/null || warn "請手動重載 snmpd"
else
    say "snmpd managed block 已存在，略過"
fi

# ── 5) 齒輪選單（冪等）────────────────────────────────────────
if command -v python3 >/dev/null; then
    say "修補 LibreNMS 齒輪選單"
    python3 "$HERE/system/menu-patch.py" "$LIBRENMS_DIR/resources/views/layouts/menu.blade.php" || warn "選單未自動修補，請見 README 手動加入"
fi

# ── 6) 告警規則（add-only，需要 DB 已有指標後較有意義）─────────
say "安裝告警規則（add-only）"
cp "$HERE/system/install_alert_rules.php" /tmp/oraclemon_alert_rules.php
chown "$LIBRENMS_USER":"$LIBRENMS_USER" /tmp/oraclemon_alert_rules.php
sudo -u "$LIBRENMS_USER" php /tmp/oraclemon_alert_rules.php || warn "告警規則安裝失敗（可稍後手動執行）"
rm -f /tmp/oraclemon_alert_rules.php

# ── 7) 清快取 ────────────────────────────────────────────────
say "清除 LibreNMS view 快取"
sudo -u "$LIBRENMS_USER" bash -c "cd '$LIBRENMS_DIR' && php artisan view:clear" || true

say "完成。後續："
echo "  1) 用瀏覽器登入 LibreNMS → 齒輪選單應出現「Oracle 監控管理 / Oracle 戰情室」"
echo "  2) 至「Oracle 監控管理」新增/設定資料庫連線（或編輯 $ORACLE_MON_DIR/dbs/*.conf）"
echo "  3) 確認 device 已加入 application：sudo -u $LIBRENMS_USER php $LIBRENMS_DIR/poller.php -h 127.0.0.1"
echo "  4) 驗證：sudo $ORACLE_MON_DIR/run.sh <alias> | python3 -m json.tool"
