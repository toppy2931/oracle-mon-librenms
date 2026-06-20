#!/bin/bash
# test-db-adhoc.sh — ad-hoc Oracle 連線測試（不需 dbs/*.conf）
#
# 接表單即時輸入的值，直接組 JDBC URL 測試，方便「未存檔先測」場景。
# Usage: test-db-adhoc.sh <HOST> <PORT> <SID> <USER> <PASS>
# Output: 同 OracleStats Java（單一 JSON 結果，errorString / data 等欄位）
#
# 與既有 test-db.sh + run.sh 邏輯一致，差異只在「不讀檔，從參數取連線資訊」。

set -e
HOST="${1:?usage: $0 HOST PORT SID USER PASS}"
PORT="${2:?missing PORT}"
SID="${3:?missing SID}"
USER="${4:?missing USER}"
PASS="${5:?missing PASS}"

# 基本格式驗證（IPv4 或 hostname）
echo "$HOST" | grep -qE '^[A-Za-z0-9.-]+$' \
    || { printf '{"error":1,"errorString":"invalid host"}\n'; exit 1; }
echo "$PORT" | grep -qE '^[0-9]+$' \
    || { printf '{"error":1,"errorString":"invalid port"}\n'; exit 1; }
[ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] \
    || { printf '{"error":1,"errorString":"port out of range"}\n'; exit 1; }
echo "$SID" | grep -qE '^[A-Za-z0-9_$.-]+$' \
    || { printf '{"error":1,"errorString":"invalid sid"}\n'; exit 1; }

# 組 JDBC URL 與認證資訊（與 run.sh 等效）
ORA_URL="jdbc:oracle:thin:@//${HOST}:${PORT}/${SID}"
ORA_USER="$USER"
ORA_PASS="$PASS"
export ORA_URL ORA_USER ORA_PASS

# 呼叫 OracleStats Java（同 run.sh 用同一個 class）
exec java -cp /opt/oracle-mon:/opt/oracle-mon/lib/ojdbc14.jar OracleStats
