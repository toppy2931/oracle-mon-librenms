#!/bin/bash
# =============================================================================
# oracle-mv.sh — Oracle 9i Materialized View (Snapshot) SNMP Extend 監控腳本
# 部署位置：Oracle 主機 /etc/snmp/oracle-mv.sh
# 執行身份：snmpd 執行使用者（與 oracle_dg.sh 相同，需在 dba group）
#
# 輸出格式：
#   Line  1: can_connect      1=連線成功, 0=失敗
#   Line  2: mv_total         系統 MV (Snapshot) 總數
#   Line  3: mv_stale_count   非 FRESH 狀態的 MV 數（STALE/NEEDS_COMPILE/UNUSABLE）
#   Line  4: mv_failed_count  UNUSABLE 狀態（無法使用）的 MV 數
#   Lines 5+: 每個 MV 一行，pipe 分隔：
#             name|age_minutes|type|is_stale|staleness
#             例：SALES_SUMMARY|45|COMPLETE|0|FRESH
# =============================================================================

# ── 環境變數（與 oracle_dg.sh 保持一致）────────────────────────────────────
ORACLE_HOME=/u01/app/oracle/product/9.2.0/db_1
ORACLE_SID=ORCL
ORACLE_USER="/ as sysdba"

export ORACLE_HOME
export ORACLE_SID
export PATH="${ORACLE_HOME}/bin:${PATH}"
export LD_LIBRARY_PATH="${ORACLE_HOME}/lib:${LD_LIBRARY_PATH}"
export NLS_LANG="AMERICAN_AMERICA.AL32UTF8"

SQLPLUS="${ORACLE_HOME}/bin/sqlplus"

# ── 連線失敗輸出（4 行基本值，無 MV 明細）────────────────────────────────
fail_output() {
    printf "0\n0\n0\n0\n"
    exit 1
}

# ── 連線測試 ──────────────────────────────────────────────────────────────
if ! "${SQLPLUS}" -s "${ORACLE_USER}" </dev/null >/dev/null 2>&1; then
    fail_output
fi

# ── SQL 輔助函式 ───────────────────────────────────────────────────────────
run_sql() {
    "${SQLPLUS}" -s "${ORACLE_USER}" 2>/dev/null <<EOF
SET HEADING OFF
SET FEEDBACK OFF
SET PAGESIZE 0
SET TRIMOUT ON
SET TRIMSPOOL ON
SET LINESIZE 300
$1
EXIT;
EOF
}

# ── 取得 MV 彙總計數 ────────────────────────────────────────────────────
COUNTS=$(run_sql "
SELECT
    COUNT(*)                                                          || '|' ||
    SUM(DECODE(NVL(staleness,'UNKNOWN'), 'FRESH', 0, 'UNDEFINED', 0, 1)) || '|' ||
    SUM(DECODE(NVL(staleness,'UNKNOWN'), 'UNUSABLE', 1, 0))
FROM user_snapshots;
")

COUNTS=$(echo "${COUNTS}" | tr -d ' ')

if [ -z "${COUNTS}" ]; then
    fail_output
fi

MV_TOTAL=$(echo "${COUNTS}"        | cut -d'|' -f1)
MV_STALE_COUNT=$(echo "${COUNTS}"  | cut -d'|' -f2)
MV_FAILED_COUNT=$(echo "${COUNTS}" | cut -d'|' -f3)

[ -z "${MV_TOTAL}" ]        && MV_TOTAL=0
[ -z "${MV_STALE_COUNT}" ]  && MV_STALE_COUNT=0
[ -z "${MV_FAILED_COUNT}" ] && MV_FAILED_COUNT=0

CAN_CONNECT=1

# ── 輸出前 4 行（彙總）────────────────────────────────────────────────────
printf "%d\n%d\n%d\n%d\n" \
    "${CAN_CONNECT}" \
    "${MV_TOTAL}" \
    "${MV_STALE_COUNT}" \
    "${MV_FAILED_COUNT}"

# ── 若無 MV 則結束 ─────────────────────────────────────────────────────────
[ "${MV_TOTAL}" -eq 0 ] && exit 0

# ── 每個 MV 明細（Lines 5+）────────────────────────────────────────────────
# Oracle 9i USER_SNAPSHOTS 欄位：
#   name, last_refresh, type (COMPLETE/FAST/FORCE), staleness (FRESH/STALE/NEEDS_COMPILE/UNUSABLE)
run_sql "
SELECT
    TRIM(name) || '|' ||
    ROUND((SYSDATE - NVL(last_refresh, TO_DATE('1970-01-01','YYYY-MM-DD'))) * 24 * 60) || '|' ||
    TRIM(NVL(type, 'UNKNOWN')) || '|' ||
    DECODE(NVL(staleness,'UNKNOWN'), 'FRESH', 0, 'UNDEFINED', 0, 1) || '|' ||
    TRIM(NVL(staleness, 'UNKNOWN'))
FROM user_snapshots
ORDER BY name;
"

exit 0
