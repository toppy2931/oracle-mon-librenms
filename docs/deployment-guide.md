# LibreNMS 部署指南

> 依據 2026-06-11 實際安裝驗證，適用 **Ubuntu 24.04 LTS**。
> LibreNMS 版本：26.6.x（master branch）

---

## 目錄

1. [系統需求](#1-系統需求)
2. [安裝前準備](#2-安裝前準備)
3. [安裝套件](#3-安裝套件)
4. [建立 LibreNMS 使用者](#4-建立-librenms-使用者)
5. [下載程式碼](#5-下載程式碼)
6. [設定目錄權限](#6-設定目錄權限)
7. [安裝 PHP 依賴（Composer）](#7-安裝-php-依賴composer)
8. [設定 PHP Timezone](#8-設定-php-timezone)
9. [設定 MariaDB](#9-設定-mariadb)
10. [設定 PHP-FPM](#10-設定-php-fpm)
11. [設定 Nginx](#11-設定-nginx)
12. [設定 SNMP](#12-設定-snmp)
13. [設定 Cron / Scheduler](#13-設定-cron--scheduler)
14. [設定 Logrotate](#14-設定-logrotate)
15. [建立管理員帳號](#15-建立管理員帳號)
16. [驗證安裝](#16-驗證安裝)
17. [加入第一台設備](#17-加入第一台設備)
18. [防火牆設定](#18-防火牆設定)
19. [設定繁體中文](#19-設定繁體中文)
20. [後續維護](#20-後續維護)
21. [疑難排解](#21-疑難排解)

---

## 1. 系統需求

| 項目 | 最低需求 |
|------|---------|
| OS | Ubuntu 24.04 LTS / 22.04 LTS / Debian 12 |
| CPU | 2 核心 |
| RAM | 2 GB（建議 4 GB+） |
| 磁碟 | 20 GB+（RRD 資料隨設備數增長） |
| PHP | 8.2+（Ubuntu 24.04 預設 8.3） |
| MariaDB | 10.6+（Ubuntu 24.04 預設 10.11） |
| Python | 3.x |

> ⚠️ **Linux 原生檔案系統必要條件**：安裝目錄必須在原生 Linux 檔案系統（ext4）上，不可在 NTFS/SMB 掛載點（如 WSL2 的 `/mnt/d`）。`composer install` 的 ZipArchive 操作會因 NTFS 權限限制失敗。

---

## 2. 安裝前準備

以 root 或具 sudo 權限的帳號操作。

```bash
# 確認 OS 版本
lsb_release -a

# 更新套件清單
sudo apt-get update
```

---

## 3. 安裝套件

```bash
sudo apt-get install -y \
  acl curl fping git \
  mariadb-client mariadb-server \
  mtr-tiny nginx-full nmap \
  php-cli php-curl php-fpm php-gd php-gmp php-json \
  php-mbstring php-mysql php-snmp php-xml php-zip \
  python3-command-runner python3-dotenv python3-pip \
  python3-psutil python3-pymysql python3-redis \
  python3-setuptools python3-systemd \
  rrdtool snmp snmpd \
  traceroute unzip whois
```

確認安裝的 PHP 版本（後續步驟需要）：

```bash
php --version
# 例：PHP 8.3.6
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "PHP Version: $PHP_VER"
```

---

## 4. 建立 LibreNMS 使用者

```bash
sudo useradd librenms -d /opt/librenms -M -r -s $(which bash)
```

---

## 5. 下載程式碼

```bash
cd /opt
sudo git clone https://github.com/librenms/librenms.git
```

若要用自訂分支（建議：避免 daily.sh 自動更新覆蓋修改）：

```bash
cd /opt/librenms
sudo -u librenms git checkout -b feature/my-custom
# 停用自動更新
sudo -u librenms php lnms config:set update false
```

---

## 6. 設定目錄權限

```bash
sudo chown -R librenms:librenms /opt/librenms
sudo chmod 771 /opt/librenms

# 設定 ACL（讓 web server 可讀寫特定目錄）
sudo setfacl -d -m g::rwx \
  /opt/librenms/rrd \
  /opt/librenms/logs \
  /opt/librenms/bootstrap/cache/ \
  /opt/librenms/storage/

sudo setfacl -R -m g::rwx \
  /opt/librenms/rrd \
  /opt/librenms/logs \
  /opt/librenms/bootstrap/cache/ \
  /opt/librenms/storage/
```

---

## 7. 安裝 PHP 依賴（Composer）

```bash
sudo -u librenms bash -c 'cd /opt/librenms && php scripts/composer_wrapper.php install --no-dev'
```

> ⚠️ 此步驟需要網路連線，耗時 2–5 分鐘。
> 若出現 `ZipArchive::extractTo ... Operation not permitted`，表示安裝目錄在非原生檔案系統（見[系統需求](#1-系統需求)）。

---

## 8. 設定 PHP Timezone

```bash
# Ubuntu 24.04 預設 PHP 8.3，依實際版本修改路徑
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

sudo sed -i 's/;date.timezone =/date.timezone = Asia\/Taipei/' /etc/php/${PHP_VER}/fpm/php.ini
sudo sed -i 's/;date.timezone =/date.timezone = Asia\/Taipei/' /etc/php/${PHP_VER}/cli/php.ini

# 設定系統時區
sudo timedatectl set-timezone Asia/Taipei

# 驗證
grep 'date.timezone' /etc/php/${PHP_VER}/cli/php.ini | grep -v '^;'
timedatectl | grep 'Time zone'
```

其他時區選項：`Asia/Taipei`、`UTC`、`Asia/Tokyo`、`America/New_York`

---

## 9. 設定 MariaDB

### 9.1 修改設定檔

```bash
# Ubuntu 24.04
sudo tee -a /etc/mysql/mariadb.conf.d/50-server.cnf > /dev/null << 'EOF'

[mysqld]
innodb_file_per_table=1
lower_case_table_names=0
EOF
```

> ⚠️ `lower_case_table_names=0` 必須在**首次初始化前**設定，事後修改會造成資料庫損毀。

### 9.2 啟動 MariaDB

```bash
sudo systemctl enable mariadb
sudo systemctl restart mariadb
```

### 9.3 建立資料庫與使用者

```bash
sudo mysql -u root << 'SQL'
CREATE DATABASE librenms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'librenms'@'localhost' IDENTIFIED BY '請替換為強密碼';
GRANT ALL PRIVILEGES ON librenms.* TO 'librenms'@'localhost';
FLUSH PRIVILEGES;
SQL
```

> ⚠️ 請將 `請替換為強密碼` 改為實際密碼，後續 `.env` 需填入相同密碼。

---

## 10. 設定 PHP-FPM

```bash
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

# 複製 pool 設定
sudo cp /etc/php/${PHP_VER}/fpm/pool.d/www.conf \
        /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf

# 修改 pool 名稱、使用者、socket 路徑
sudo sed -i 's/^\[www\]/[librenms]/'             /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sudo sed -i 's/^user = www-data/user = librenms/' /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sudo sed -i 's/^group = www-data/group = librenms/' /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sudo sed -i 's|^listen = .*|listen = /run/php-fpm-librenms.sock|' \
        /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sudo sed -i 's/^listen.owner = www-data/listen.owner = librenms/' \
        /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sudo sed -i 's/^listen.group = www-data/listen.group = librenms/' \
        /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf

# 將 nginx 加入 librenms group（允許存取 socket）
sudo usermod -aG librenms www-data

# 驗證設定
grep -E '^\[|^user|^group|^listen' /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf | head -8
```

---

## 11. 設定 Nginx

### 11.1 建立 LibreNMS 站台設定

```bash
sudo tee /etc/nginx/conf.d/librenms.conf > /dev/null << 'NGINX'
server {
    listen      80;
    server_name localhost;   # 改為實際的 FQDN 或 IP
    root        /opt/librenms/html;
    index       index.php;

    charset utf-8;
    gzip on;
    gzip_types text/css application/javascript text/javascript
               application/x-javascript image/svg+xml
               text/plain text/xsd text/xsl text/xml image/x-icon;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ [^/]\.php(/|$) {
        fastcgi_pass unix:/run/php-fpm-librenms.sock;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include fastcgi.conf;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
```

### 11.2 移除預設站台

```bash
sudo rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default 2>/dev/null || true
```

### 11.3 測試設定並啟動

```bash
sudo nginx -t
sudo systemctl enable nginx
sudo systemctl restart nginx

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo systemctl enable php${PHP_VER}-fpm
sudo systemctl restart php${PHP_VER}-fpm
```

---

## 12. 設定 SNMP

```bash
# 複製設定範本
sudo cp /opt/librenms/snmpd.conf.example /etc/snmp/snmpd.conf

# 修改 community string（預設 RANDOMSTRINGGOESHERE，改為自訂值）
sudo sed -i 's/RANDOMSTRINGGOESHERE/自訂community字串/' /etc/snmp/snmpd.conf

# 下載 OS 偵測腳本
sudo curl -o /usr/bin/distro \
  https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/distro
sudo chmod +x /usr/bin/distro

# 啟動
sudo systemctl enable snmpd
sudo systemctl restart snmpd
```

> `自訂community字串` 為 SNMP v2c 的 community string，加入設備時需填入相同值。

---

## 13. 設定 Cron / Scheduler

```bash
# Cron job（poller/discovery wrapper）
sudo cp /opt/librenms/dist/librenms.cron /etc/cron.d/librenms

# Systemd scheduler timer（Laravel schedule:run，每分鐘）
sudo cp /opt/librenms/dist/librenms-scheduler.service /etc/systemd/system/
sudo cp /opt/librenms/dist/librenms-scheduler.timer   /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now librenms-scheduler.timer

# 驗證 timer 狀態
systemctl status librenms-scheduler.timer
```

> **注意**：`librenms-scheduler.service` 是 `Type=oneshot`，執行完會顯示 `inactive (dead)`，這是正常的。確認 `.timer` 顯示 `active (waiting)` 即可。`validate.php` 顯示 `Scheduler FAIL` 為已知誤報，不影響功能。

---

## 14. 設定 Logrotate

```bash
sudo cp /opt/librenms/misc/librenms.logrotate /etc/logrotate.d/librenms
```

---

## 15. 建立管理員帳號

### 15.1 建立 .env 設定檔

```bash
sudo -u librenms bash -c '
cd /opt/librenms
cat > .env << ENVEOF
APP_KEY=
APP_URL=http://your-server-hostname-or-ip
DB_HOST=localhost
DB_DATABASE=librenms
DB_USERNAME=librenms
DB_PASSWORD=第9步設定的密碼
ENVEOF
'

# 產生 APP_KEY
sudo -u librenms bash -c 'cd /opt/librenms && php artisan key:generate --force'
```

### 15.2 執行資料庫 Migration

```bash
sudo -u librenms bash -c 'cd /opt/librenms && php artisan migrate --seed --force'
```

### 15.3 建立 admin 帳號

```bash
# 必須帶完整參數（-p），否則非互動環境會 Exception
# 密碼須符合強度要求（不可使用常見洩漏密碼）
sudo -u librenms bash -c '
cd /opt/librenms
php lnms user:add admin \
  -p "您的強密碼" \
  -r admin \
  -e admin@your-domain.com \
  -l "Administrator"
'
```

> ⚠️ 密碼會自動比對資料外洩庫，弱密碼（如 `Admin@2026`、`Password123!`）會被拒絕。建議使用 16 字元以上含特殊字元的密碼。

---

## 16. 驗證安裝

```bash
sudo -u librenms bash -c 'cd /opt/librenms && php validate.php'
```

正常輸出（主要項目）：

```
[OK]    Composer Version: 2.x.x
[OK]    Dependencies up-to-date.
[OK]    Database Connected
[OK]    Database Schema is current
[OK]    SQL Server meets minimum requirements
[OK]    lower_case_table_names is enabled
[OK]    MySQL engine is optimal
[OK]    Database and column collations are correct
[OK]    MySQL and PHP time match
[OK]    rrd_dir is writable
[OK]    rrdtool version ok
[WARN]  You have no devices.        ← 正常，尚未加設備
[FAIL]  Scheduler is not running    ← 已知誤報，可忽略（timer active 即正常）
```

### 測試 Web UI 是否正常

```bash
curl -s -L -o /dev/null -w '%{http_code} -> %{url_effective}' http://localhost/
# 預期：200 -> http://localhost/login
```

瀏覽器開啟 `http://<server-ip>/` → 應自動導向登入頁。

---

## 17. 加入第一台設備

### 方法一：CLI

```bash
sudo -u librenms bash -c '
cd /opt/librenms
php lnms device:add localhost --v2c --community=自訂community字串
'
```

### 方法二：Web UI

登入 → 左側選單 **Devices → Add Device** → 填入：
- **Hostname**: 設備 IP 或 FQDN
- **SNMP Version**: v2c
- **Community**: 第 12 步設定的 community string

---

## 18. 防火牆設定

```bash
# 若使用 ufw
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS（若有設 SSL）
sudo ufw allow 161/udp   # SNMP（若此機也需被監控）
sudo ufw allow 514/udp   # Syslog（選用）
sudo ufw allow 162/udp   # SNMP Trap（選用）
```

---

## 19. 設定繁體中文

LibreNMS 內建繁體中文（`zh-TW`），翻譯覆蓋所有主要模組。

### 全域預設

```bash
sudo -u librenms bash -c 'cd /opt/librenms && php lnms config:set locale zh-TW'
```

或登入 Web UI → **Settings → Global Settings → locale** → 選 `zh-TW`。

### 使用者個人設定

登入 → 右上角頭像 → **My Settings** → **Language** → 選 `zh-TW`。

---

## 20. 後續維護

### 啟用/停用自動更新

```bash
# 停用（建議有自訂修改時停用）
sudo -u librenms bash -c 'cd /opt/librenms && php lnms config:set update false'

# 啟用
sudo -u librenms bash -c 'cd /opt/librenms && php lnms config:set update true'
```

> `daily.sh` cron job（每日 00:19）會執行 `git pull` 更新到最新 master。若有本地修改，停用前務必先建立自己的 branch。

### 手動更新

```bash
sudo -u librenms bash -c 'cd /opt/librenms && php daily.php'
```

### 服務狀態確認

```bash
systemctl status nginx php8.3-fpm mariadb snmpd librenms-scheduler.timer
```

### 查看 Log

```bash
tail -f /opt/librenms/logs/librenms.log    # LibreNMS 應用 log
tail -f /var/log/nginx/error.log           # Nginx 錯誤
journalctl -u librenms-scheduler.service  # Scheduler 執行記錄
```

---

## 21. 疑難排解

### ERR_CONNECTION_REFUSED / 無法連線

1. 確認 nginx 與 php-fpm 正在執行：
   ```bash
   sudo systemctl status nginx php8.3-fpm
   ```
2. 確認 socket 存在：
   ```bash
   ls -la /run/php-fpm-librenms.sock
   ```
3. 若 socket 不存在，重啟 php-fpm：
   ```bash
   sudo systemctl restart php8.3-fpm
   ```

### 502 Bad Gateway

php-fpm socket 不存在或 nginx 無權限存取。

```bash
# 確認 www-data 在 librenms group
id www-data | grep librenms

# 若不在，重新加入
sudo usermod -aG librenms www-data
sudo systemctl restart nginx php8.3-fpm
```

### 資料庫連線失敗

```bash
# 確認 MariaDB 運行
sudo systemctl status mariadb

# 測試連線
mysql -u librenms -p librenms -e "SELECT 1;"
```

### Composer install 失敗（ZipArchive Operation not permitted）

安裝目錄在非原生 Linux 檔案系統（NTFS/SMB）。解法：將 `/opt/librenms` 移至原生 ext4 分割區。

### validate.php 顯示 Python wrapper / Scheduler FAIL

- **Scheduler FAIL**：已知誤報，確認 `systemctl status librenms-scheduler.timer` 為 `active (waiting)` 即正常
- **Python wrapper FAIL**：通常在加入設備並讓 poller 跑過一次後自動消失

### 時間不同步（MySQL and PHP time 不符）

```bash
sudo timedatectl set-timezone Asia/Taipei
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo sed -i 's/;date.timezone =/date.timezone = Asia\/Taipei/' /etc/php/${PHP_VER}/fpm/php.ini
sudo sed -i 's/;date.timezone =/date.timezone = Asia\/Taipei/' /etc/php/${PHP_VER}/cli/php.ini
sudo systemctl restart php${PHP_VER}-fpm
```

---

## 快速安裝腳本（一鍵執行，互動式填寫密碼）

> 僅適用 Ubuntu 24.04，建議先閱讀完整文件再使用。

```bash
#!/bin/bash
set -e

read -rsp "LibreNMS DB 密碼: " DB_PASS; echo
read -rsp "Admin 帳號密碼: "   ADMIN_PASS; echo
read -rp  "Server hostname/IP: " SERVER_HOST

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.3")

# 1. 套件
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  acl curl fping git mariadb-client mariadb-server mtr-tiny nginx-full nmap \
  php-cli php-curl php-fpm php-gd php-gmp php-json php-mbstring php-mysql \
  php-snmp php-xml php-zip python3-command-runner python3-dotenv python3-pip \
  python3-psutil python3-pymysql python3-redis python3-setuptools \
  python3-systemd rrdtool snmp snmpd traceroute unzip whois

# 2. 使用者
useradd librenms -d /opt/librenms -M -r -s "$(which bash)" 2>/dev/null || true

# 3. Clone
[ -d /opt/librenms/.git ] || git clone https://github.com/librenms/librenms.git /opt/librenms

# 4. 權限
chown -R librenms:librenms /opt/librenms
chmod 771 /opt/librenms
setfacl -d -m g::rwx /opt/librenms/rrd /opt/librenms/logs \
  /opt/librenms/bootstrap/cache/ /opt/librenms/storage/
setfacl -R -m g::rwx /opt/librenms/rrd /opt/librenms/logs \
  /opt/librenms/bootstrap/cache/ /opt/librenms/storage/

# 5. Composer
sudo -u librenms bash -c 'cd /opt/librenms && php scripts/composer_wrapper.php install --no-dev'

# 6. PHP timezone
sed -i 's/;date.timezone =/date.timezone = Asia\/Taipei/' /etc/php/${PHP_VER}/fpm/php.ini
sed -i 's/;date.timezone =/date.timezone = Asia\/Taipei/' /etc/php/${PHP_VER}/cli/php.ini
timedatectl set-timezone Asia/Taipei

# 7. MariaDB
tee -a /etc/mysql/mariadb.conf.d/50-server.cnf > /dev/null << 'EOF'

[mysqld]
innodb_file_per_table=1
lower_case_table_names=0
EOF
systemctl enable mariadb && systemctl restart mariadb
mysql -u root << SQL
CREATE DATABASE IF NOT EXISTS librenms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'librenms'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON librenms.* TO 'librenms'@'localhost';
FLUSH PRIVILEGES;
SQL

# 8. PHP-FPM
cp /etc/php/${PHP_VER}/fpm/pool.d/www.conf /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sed -i 's/^\[www\]/[librenms]/'                  /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sed -i 's/^user = www-data/user = librenms/'      /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sed -i 's/^group = www-data/group = librenms/'    /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sed -i 's|^listen = .*|listen = /run/php-fpm-librenms.sock|' \
  /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sed -i 's/^listen.owner = www-data/listen.owner = librenms/' \
  /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
sed -i 's/^listen.group = www-data/listen.group = librenms/' \
  /etc/php/${PHP_VER}/fpm/pool.d/librenms.conf
usermod -aG librenms www-data

# 9. Nginx
rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default 2>/dev/null || true
cat > /etc/nginx/conf.d/librenms.conf << NGINX
server {
    listen      80;
    server_name ${SERVER_HOST};
    root        /opt/librenms/html;
    index       index.php;
    charset utf-8;
    gzip on;
    gzip_types text/css application/javascript text/javascript application/x-javascript image/svg+xml text/plain text/xsd text/xsl text/xml image/x-icon;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ [^/]\.php(/|$) {
        fastcgi_pass unix:/run/php-fpm-librenms.sock;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include fastcgi.conf;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX

# 10. SNMP
cp /opt/librenms/snmpd.conf.example /etc/snmp/snmpd.conf
SNMP_COMMUNITY="librenms_$(openssl rand -hex 4)"
sed -i "s/RANDOMSTRINGGOESHERE/${SNMP_COMMUNITY}/" /etc/snmp/snmpd.conf
curl -s -o /usr/bin/distro \
  https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/distro
chmod +x /usr/bin/distro
systemctl enable snmpd && systemctl restart snmpd

# 11. Cron + Scheduler + Logrotate
cp /opt/librenms/dist/librenms.cron /etc/cron.d/librenms
cp /opt/librenms/dist/librenms-scheduler.service /etc/systemd/system/
cp /opt/librenms/dist/librenms-scheduler.timer   /etc/systemd/system/
cp /opt/librenms/misc/librenms.logrotate /etc/logrotate.d/librenms
ln -sf /opt/librenms/lnms /usr/local/bin/lnms
cp /opt/librenms/misc/lnms-completion.bash /etc/bash_completion.d/
systemctl daemon-reload
systemctl enable --now librenms-scheduler.timer

# 12. 啟動服務
systemctl enable nginx php${PHP_VER}-fpm
systemctl restart nginx php${PHP_VER}-fpm

# 13. .env + migration
sudo -u librenms bash -c "
cd /opt/librenms
cat > .env << ENVEOF
APP_KEY=
APP_URL=http://${SERVER_HOST}
DB_HOST=localhost
DB_DATABASE=librenms
DB_USERNAME=librenms
DB_PASSWORD=${DB_PASS}
ENVEOF
php artisan key:generate --force
php artisan migrate --seed --force
"

# 14. Admin 帳號
sudo -u librenms bash -c "
cd /opt/librenms
php lnms user:add admin -p '${ADMIN_PASS}' -r admin -e admin@localhost -l Administrator
php lnms config:set locale zh-TW
"

echo ""
echo "======================================"
echo "LibreNMS 安裝完成"
echo "Web UI:  http://${SERVER_HOST}/"
echo "帳號:    admin"
echo "SNMP community: ${SNMP_COMMUNITY}"
echo "======================================"
echo "執行驗證: sudo -u librenms bash -c 'cd /opt/librenms && php validate.php'"
```

---

*文件最後更新：2026-06-11 | 依 WSL2 Ubuntu 24.04 實際安裝驗證*
