#!/bin/bash
# =============================================================================
# oracle_dg.sh — Oracle 9i DataGuard SNMP Extend 監控腳本
# 部署位置：Oracle 主機 /etc/snmp/oracle_dg.sh
# 執行身份：snmpd 執行使用者（通常是 Debian-snmp 或 snmpd）
#
# 輸出格式（12 行，一值一行，LibreNMS oracle-dg.inc.php 依序讀取）：
#   Line  1: can_connect     1=sqlplus 連線成功, 0=失敗
#   Line  2: is_primary      1=PRIMARY, 0=PHYSICAL STANDBY
#   Line  3: db_open         1=OPEN, 0=MOUNTED 或其他
#   Line  4: mrp_running     1=MRP 程序執行中, 0=未執行, -1=Primary(不適用)
#   Line  5: rfs_connected   1=RFS 連線中, 0=未連線, -1=Primary(不適用)
#   Line  6: current_seq     Primary: 當前 redo 序號; Standby: 最後收到序號
#   Line  7: applied_seq     Primary: -1; Standby: 最後套用序號
#   Line  8: apply_lag_seqs  Primary: 0; Standby: 收到-套用的差距(序號數)
#   Line  9: lag_seconds     延遲秒數（由 archived_log 時間戳估算，0=無法計算）
#   Line 10: dest_ok         Primary: 1=VALID, 0=INVALID; Standby: -1(不適用)
#   Line 11: dest_has_error  Primary: 1=有錯誤, 0=無錯誤; Standby: -1(不適用)
#   Line 12: protection_mode 0=MAX_PROTECTION, 1=MAX_AVAILABILITY, 2=MAX_PERFORMANCE
# =============================================================================

# ── 環境變數（依實際 Oracle 9i 安裝路徑修改）──────────────────────────────
ORACLE_HOME=/u01/app/oracle/product/9.2.0/db_1
ORACLE_SID=ORCL            # 修改為實際 SID
ORACLE_USER="/ as sysdba"  # 使用 OS 認證（snmpd 需在 dba group）
# 若使用帳密認證，改為：ORACLE_USER="monitor_user/password"

export ORACLE_HOME
export ORACLE_SID
export PATH="${ORACLE_HOME}/bin:${PATH}"
export LD_LIBRARY_PATH="${ORACLE_HOME}/lib:${LD_LIBRARY_PATH}"
export NLS_LANG="AMERICAN_AMERICA.AL32UTF8"

SQLPLUS="${ORACLE_HOME}/bin/sqlplus"

# ── 連線測試 ──────────────────────────────────────────────────────────────
if ! "${SQLPLUS}" -s "${ORACLE_USER}" </dev/null >/dev/null 2>&1; then
    # sqlplus 無法連線，輸出全失敗值
    printf "0\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n"
    exit 1
fi

# ── SQL 執行輔助函式 ───────────────────────────────────────────────────────
run_sql() {
    "${SQLPLUS}" -s "${ORACLE_USER}" 2>/dev/null <<EOF
SET HEADING OFF
SET FEEDBACK OFF
SET PAGESIZE 0
SET TRIMOUT ON
SET TRIMSPOOL ON
SET LINESIZE 200
$1
EXIT;
EOF
}

# ── 取得基本資料庫資訊 ────────────────────────────────────────────────────
DB_INFO=$(run_sql "
SELECT
    TRIM(DATABASE_ROLE) || '|' ||
    TRIM(STATUS)        || '|' ||
    TRIM(PROTECTION_MODE)
FROM V\$DATABASE, V\$INSTANCE
WHERE ROWNUM = 1;
")

DB_ROLE=$(echo "${DB_INFO}"       | cut -d'|' -f1 | tr -d ' ')
DB_STATUS=$(echo "${DB_INFO}"     | cut -d'|' -f2 | tr -d ' ')
PROTECTION=$(echo "${DB_INFO}"    | cut -d'|' -f3 | tr -d ' ')

# 連線失敗檢查（sqlplus 回傳空值）
if [ -z "${DB_ROLE}" ]; then
    printf "0\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n-1\n"
    exit 1
fi

CAN_CONNECT=1

# is_primary
if [ "${DB_ROLE}" = "PRIMARY" ]; then
    IS_PRIMARY=1
else
    IS_PRIMARY=0
fi

# db_open
if [ "${DB_STATUS}" = "OPEN" ]; then
    DB_OPEN=1
else
    DB_OPEN=0
fi

# protection_mode
case "${PROTECTION}" in
    "MAXIMUM PROTECTION")   PROT_MODE=0 ;;
    "MAXIMUM AVAILABILITY") PROT_MODE=1 ;;
    "MAXIMUM PERFORMANCE")  PROT_MODE=2 ;;
    *)                      PROT_MODE=2 ;;
esac

# ── PRIMARY 端監控 ─────────────────────────────────────────────────────────
if [ "${IS_PRIMARY}" -eq 1 ]; then

    # 當前 redo log 序號
    CURRENT_SEQ=$(run_sql "
SELECT NVL(MAX(SEQUENCE#), 0)
FROM V\$LOG
WHERE STATUS = 'CURRENT';
" | tr -d ' \n')

    # 傳送至 Standby 的最新封存序號
    LAST_SENT=$(run_sql "
SELECT NVL(MAX(ARCHIVED_SEQ#), 0)
FROM V\$ARCHIVE_DEST
WHERE TARGET = 'STANDBY'
  AND STATUS != 'INACTIVE'
  AND ROWNUM = 1;
" | tr -d ' \n')

    # Archive dest 狀態（第一個 STANDBY 目的地）
    DEST_STATUS_STR=$(run_sql "
SELECT TRIM(NVL(STATUS, 'UNKNOWN'))
FROM V\$ARCHIVE_DEST
WHERE TARGET = 'STANDBY'
  AND STATUS != 'INACTIVE'
  AND ROWNUM = 1;
" | tr -d ' \n')

    # Dest 錯誤訊息（非空 = 有錯誤）
    DEST_ERROR_STR=$(run_sql "
SELECT TRIM(NVL(ERROR, ''))
FROM V\$ARCHIVE_DEST
WHERE TARGET = 'STANDBY'
  AND STATUS != 'INACTIVE'
  AND ROWNUM = 1;
" | tr -d '\n')

    if [ "${DEST_STATUS_STR}" = "VALID" ]; then
        DEST_OK=1
    else
        DEST_OK=0
    fi

    if [ -n "${DEST_ERROR_STR}" ] && [ "${DEST_ERROR_STR}" != "" ]; then
        DEST_HAS_ERROR=1
    else
        DEST_HAS_ERROR=0
    fi

    # Primary 不適用的欄位
    MRP_RUNNING=-1
    RFS_CONNECTED=-1
    APPLIED_SEQ=-1
    APPLY_LAG_SEQS=0
    LAG_SECONDS=0

    printf "%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n" \
        "${CAN_CONNECT}" \
        "${IS_PRIMARY}" \
        "${DB_OPEN}" \
        "${MRP_RUNNING}" \
        "${RFS_CONNECTED}" \
        "${CURRENT_SEQ}" \
        "${APPLIED_SEQ}" \
        "${APPLY_LAG_SEQS}" \
        "${LAG_SECONDS}" \
        "${DEST_OK}" \
        "${DEST_HAS_ERROR}" \
        "${PROT_MODE}"
    exit 0
fi

# ── STANDBY 端監控 ────────────────────────────────────────────────────────

# MRP (Media Recovery Process) 狀態
MRP_INFO=$(run_sql "
SELECT TRIM(NVL(STATUS, 'NOT_RUNNING')) || '|' || NVL(SEQUENCE#, 0)
FROM V\$MANAGED_STANDBY
WHERE PROCESS LIKE 'MRP%'
  AND ROWNUM = 1;
" | tr -d ' ')

MRP_STATUS=$(echo "${MRP_INFO}" | cut -d'|' -f1)
MRP_SEQ=$(echo "${MRP_INFO}"    | cut -d'|' -f2)

if [ -z "${MRP_STATUS}" ] || [ "${MRP_STATUS}" = "NOT_RUNNING" ]; then
    MRP_RUNNING=0
    MRP_SEQ=0
else
    MRP_RUNNING=1
fi

# RFS (Remote File Server) — 接收 Primary 傳送的 log
RFS_INFO=$(run_sql "
SELECT TRIM(NVL(STATUS, 'IDLE')) || '|' || NVL(MAX(SEQUENCE#), 0)
FROM V\$MANAGED_STANDBY
WHERE PROCESS LIKE 'RFS%'
GROUP BY STATUS
ORDER BY MAX(SEQUENCE#) DESC
FETCH FIRST 1 ROW ONLY;
" | tr -d ' ')

# Oracle 9i 沒有 FETCH FIRST，改用 subquery
RFS_INFO=$(run_sql "
SELECT TRIM(STATUS) || '|' || SEQUENCE#
FROM (
    SELECT STATUS, SEQUENCE#
    FROM V\$MANAGED_STANDBY
    WHERE PROCESS LIKE 'RFS%'
    ORDER BY SEQUENCE# DESC
)
WHERE ROWNUM = 1;
" | tr -d ' ')

RFS_STATUS=$(echo "${RFS_INFO}" | cut -d'|' -f1)
RECEIVED_SEQ=$(echo "${RFS_INFO}" | cut -d'|' -f2)

if [ -z "${RFS_STATUS}" ]; then
    RFS_CONNECTED=0
    RECEIVED_SEQ=0
else
    RFS_CONNECTED=1
fi
[ -z "${RECEIVED_SEQ}" ] && RECEIVED_SEQ=0

# 最後套用的封存 log 序號
APPLIED_SEQ=$(run_sql "
SELECT NVL(MAX(SEQUENCE#), 0)
FROM V\$ARCHIVED_LOG
WHERE APPLIED = 'YES';
" | tr -d ' \n')
[ -z "${APPLIED_SEQ}" ] && APPLIED_SEQ=0

# 計算 apply lag（序號差距）
if [ "${RECEIVED_SEQ}" -gt 0 ] && [ "${APPLIED_SEQ}" -gt 0 ]; then
    APPLY_LAG_SEQS=$((RECEIVED_SEQ - APPLIED_SEQ))
    [ "${APPLY_LAG_SEQS}" -lt 0 ] && APPLY_LAG_SEQS=0
else
    APPLY_LAG_SEQS=0
fi

# 估算延遲秒數（用最後套用的 archive log 的 completion_time 與 SYSDATE 差值）
LAG_SECONDS=$(run_sql "
SELECT ROUND((SYSDATE - COMPLETION_TIME) * 86400)
FROM (
    SELECT COMPLETION_TIME
    FROM V\$ARCHIVED_LOG
    WHERE APPLIED = 'YES'
    ORDER BY SEQUENCE# DESC
)
WHERE ROWNUM = 1;
" | tr -d ' \n')
[ -z "${LAG_SECONDS}" ] && LAG_SECONDS=0
# 避免負值（時鐘偏差）
[ "${LAG_SECONDS}" -lt 0 ] 2>/dev/null && LAG_SECONDS=0

# Standby 不適用的欄位
DEST_OK=-1
DEST_HAS_ERROR=-1
CURRENT_SEQ="${RECEIVED_SEQ}"

printf "%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n%d\n" \
    "${CAN_CONNECT}" \
    "${IS_PRIMARY}" \
    "${DB_OPEN}" \
    "${MRP_RUNNING}" \
    "${RFS_CONNECTED}" \
    "${CURRENT_SEQ}" \
    "${APPLIED_SEQ}" \
    "${APPLY_LAG_SEQS}" \
    "${LAG_SECONDS}" \
    "${DEST_OK}" \
    "${DEST_HAS_ERROR}" \
    "${PROT_MODE}"
exit 0
