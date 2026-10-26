#!/usr/bin/env bash
# 資料庫層測試（tests/php/*_dbtest.php）：在「本機」的 MariaDB 建一個一次性的測試 schema 與只能碰它的使用者，
# 跑完（成功、失敗或被 Ctrl+C 中斷）一定刪掉。**不碰 LibreNMS 的資料庫**：
#   - schema 與使用者名稱都帶 ioc_test_ 前綴加隨機字尾，使用者只對這個 schema 有權限；
#   - PHP 端（tests/php/db_support.php）再檢查一次，資料庫名稱不是 ioc_test_* 就拒絕執行。
# 只給開發機（例如 WSL）用，不會被 install.sh／update.sh 部署到 monitor-vm，VM 上不要跑。
#
# 用法（需要能用 root 連本機 MariaDB 的 unix socket）：
#   sudo bash tests/run-db-tests.sh                       # 在 WSL／Linux 內
#   wsl.exe -u root -e bash tests/run-db-tests.sh         # 從 Windows
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOCKET="${IOC_TEST_SOCKET:-/run/mysqld/mysqld.sock}"

command -v mysql >/dev/null || { echo "找不到 mysql 用戶端" >&2; exit 2; }
mysql -e 'SELECT 1' >/dev/null 2>&1 || { echo "無法以目前身分連上本機 MariaDB（需要 root／sudo）" >&2; exit 2; }

SUFFIX="$(head -c 6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
DB="ioc_test_${SUFFIX}"
DBUSER="ioc_test_${SUFFIX}"
DBPASS="$(head -c 18 /dev/urandom | base64 | tr -d '/+=\n')"

cleanup() {
    # 名稱是這個腳本自己產生的，但還是檢查前綴，萬一被改壞也不會刪到別的東西
    case "$DB" in ioc_test_*) ;; *) echo "拒絕刪除不是 ioc_test_ 開頭的資料庫：$DB" >&2; return 1 ;; esac
    mysql -e "DROP DATABASE IF EXISTS \`${DB}\`; DROP USER IF EXISTS '${DBUSER}'@'localhost'; FLUSH PRIVILEGES;" || true
    left="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'ioc\\_test\\_%'")"
    echo "測試 schema 已刪除（殘留的 ioc_test_* 資料庫：${left}）"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

mysql -e "CREATE DATABASE \`${DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          CREATE USER '${DBUSER}'@'localhost' IDENTIFIED BY '${DBPASS}';
          GRANT ALL PRIVILEGES ON \`${DB}\`.* TO '${DBUSER}'@'localhost';
          FLUSH PRIVILEGES;"
echo "已建立一次性測試 schema：${DB}"

cd "$ROOT"
IOC_TEST_DSN="mysql:unix_socket=${SOCKET};dbname=${DB};charset=utf8mb4" \
IOC_TEST_USER="$DBUSER" IOC_TEST_PASS="$DBPASS" \
    php tests/php/run.php
