# 整合監控平台部署 Runbook（中型生產，100–500 台設備）

> **架構**：兩台 VM 分離部署
> - **VM-A**：LibreNMS + jt-ipam（監控 + IPAM）
> - **VM-B**：Graylog + OpenSearch（Log 集中化）
> **OS**：Ubuntu 24.04 LTS
> **更新日期**：2026-06-12

---

## 目錄

- [0. 部署前準備](#0-部署前準備)
- [1. VM 規格與磁碟規劃](#1-vm-規格與磁碟規劃)
- [2. 網路與防火牆規劃](#2-網路與防火牆規劃)
- [3. VM-B 部署：Graylog + OpenSearch](#3-vm-b-部署graylog--opensearch)
- [4. VM-A 部署：LibreNMS + jt-ipam](#4-vm-a-部署librenms--jt-ipam)
- [5. 三者整合](#5-三者整合)
- [6. 備份策略](#6-備份策略)
- [7. 維運監控](#7-維運監控)
- [8. 災難復原](#8-災難復原)
- [9. 疑難排解](#9-疑難排解)

---

## 0. 部署前準備

### 0.1 規模假設

| 項目 | 預估值 |
|------|--------|
| 監控設備數 | 100–500 台 |
| 每日 Log 量 | 5–20 GB（保留 30–90 天） |
| 並發使用者 | 5–20 人 |

### 0.2 IP 分配（範例，依實際環境調整）

| 主機 | IP | 角色 |
|------|----|----|
| VM-A | `10.0.10.10` | LibreNMS + jt-ipam |
| VM-B | `10.0.10.11` | Graylog + OpenSearch |
| Gateway | `10.0.10.1` | |
| DNS | `10.0.10.1` | |

> 本文檔以 `VM_A_IP=10.0.10.10` / `VM_B_IP=10.0.10.11` 標示，實際部署請替換。

---

## 1. VM 規格與磁碟規劃

### 1.1 VM-A 規格（LibreNMS + jt-ipam）

| 項目 | 配置 |
|------|------|
| vCPU | **8 核心** |
| RAM | **16 GB** |
| 磁碟 | **200 GB**（單一虛擬磁碟，LVM 切分） |
| 網卡 | 1 張，固定 IP |

#### 磁碟分割（VM-A）

```
裝置：/dev/sda（200 GB）

├── /dev/sda1          1 GB    ext4    /boot/efi (EFI)
├── /dev/sda2          1 GB    ext4    /boot
└── /dev/sda3        198 GB    LVM     PV (vg-data)
        │
        ├── lv-root           30 GB    ext4    /
        ├── lv-var-log         5 GB    ext4    /var/log
        ├── lv-swap            4 GB    swap
        ├── lv-mysql          25 GB    ext4    /var/lib/mysql
        ├── lv-postgres       25 GB    ext4    /var/lib/postgresql
        ├── lv-redis           2 GB    ext4    /var/lib/redis
        ├── lv-librenms-rrd   40 GB    ext4    /opt/librenms/rrd     ⚠️ I/O 密集
        ├── lv-opt            15 GB    ext4    /opt
        └── 未配置            51 GB             保留供日後 lvextend
```

#### LVM 建立指令（VM-A）

```bash
# 假設安裝時已建立 PV /dev/sda3 與 VG vg-data
sudo vgcreate vg-data /dev/sda3   # 若未建立

sudo lvcreate -L 30G  -n lv-root          vg-data
sudo lvcreate -L 5G   -n lv-var-log       vg-data
sudo lvcreate -L 4G   -n lv-swap          vg-data
sudo lvcreate -L 25G  -n lv-mysql         vg-data
sudo lvcreate -L 25G  -n lv-postgres      vg-data
sudo lvcreate -L 2G   -n lv-redis         vg-data
sudo lvcreate -L 40G  -n lv-librenms-rrd  vg-data
sudo lvcreate -L 15G  -n lv-opt           vg-data

# 格式化
sudo mkfs.ext4 -L root         /dev/vg-data/lv-root
sudo mkfs.ext4 -L var-log      /dev/vg-data/lv-var-log
sudo mkswap   -L swap          /dev/vg-data/lv-swap
sudo mkfs.ext4 -L mysql        /dev/vg-data/lv-mysql
sudo mkfs.ext4 -L postgres     /dev/vg-data/lv-postgres
sudo mkfs.ext4 -L redis        /dev/vg-data/lv-redis
sudo mkfs.ext4 -L librenms-rrd /dev/vg-data/lv-librenms-rrd
sudo mkfs.ext4 -L opt          /dev/vg-data/lv-opt
```

### 1.2 VM-B 規格（Graylog + OpenSearch）

| 項目 | 配置 |
|------|------|
| vCPU | **8 核心** |
| RAM | **24 GB**（OpenSearch 吃 RAM 兇） |
| 磁碟 | **500 GB**（Log 持續累積） |
| 網卡 | 1 張，固定 IP |

#### 磁碟分割（VM-B）

```
裝置：/dev/sda（500 GB）

├── /dev/sda1          1 GB    ext4    /boot/efi
├── /dev/sda2          1 GB    ext4    /boot
└── /dev/sda3        498 GB    LVM     PV (vg-data)
        │
        ├── lv-root            30 GB   ext4    /
        ├── lv-var-log          5 GB   ext4    /var/log
        ├── lv-swap            12 GB   swap    (= RAM 一半)
        ├── lv-mongodb         10 GB   ext4    /var/lib/mongodb
        ├── lv-graylog          15 GB  ext4    /var/lib/graylog-server
        ├── lv-opensearch     350 GB   xfs     /var/lib/opensearch   ⚠️ 主戰場
        └── 未配置             76 GB            保留供 lvextend
```

> **⚠️ 為何 OpenSearch 用 xfs**：xfs 對大量大檔案（segments、translog）順序寫入效能優於 ext4，且支援即時擴充（`xfs_growfs`）。

#### LVM 建立指令（VM-B）

```bash
sudo vgcreate vg-data /dev/sda3   # 若未建立

sudo lvcreate -L 30G   -n lv-root        vg-data
sudo lvcreate -L 5G    -n lv-var-log     vg-data
sudo lvcreate -L 12G   -n lv-swap        vg-data
sudo lvcreate -L 10G   -n lv-mongodb     vg-data
sudo lvcreate -L 15G   -n lv-graylog     vg-data
sudo lvcreate -L 350G  -n lv-opensearch  vg-data

sudo mkfs.ext4 -L root      /dev/vg-data/lv-root
sudo mkfs.ext4 -L var-log   /dev/vg-data/lv-var-log
sudo mkswap   -L swap       /dev/vg-data/lv-swap
sudo mkfs.ext4 -L mongodb   /dev/vg-data/lv-mongodb
sudo mkfs.ext4 -L graylog   /dev/vg-data/lv-graylog
sudo mkfs.xfs  -L opensearch /dev/vg-data/lv-opensearch   # ← xfs
```

### 1.3 `/etc/fstab` 範例（兩 VM 共通格式）

```bash
# VM-A 範例 (依 UUID 改寫)
LABEL=root              /                ext4  defaults                          0 1
LABEL=var-log           /var/log         ext4  defaults                          0 2
LABEL=mysql             /var/lib/mysql   ext4  defaults                          0 2
LABEL=postgres          /var/lib/postgresql ext4 defaults                        0 2
LABEL=redis             /var/lib/redis   ext4  defaults                          0 2
LABEL=librenms-rrd      /opt/librenms/rrd ext4 defaults,noatime                  0 2
LABEL=opt               /opt             ext4  defaults                          0 2
LABEL=swap              none             swap  sw                                0 0

# VM-B 範例
LABEL=root              /                ext4  defaults                          0 1
LABEL=var-log           /var/log         ext4  defaults                          0 2
LABEL=mongodb           /var/lib/mongodb ext4  defaults                          0 2
LABEL=graylog           /var/lib/graylog-server ext4 defaults                    0 2
LABEL=opensearch        /var/lib/opensearch     xfs  defaults,noatime,nodiratime 0 2
LABEL=swap              none             swap  sw                                0 0
```

> `noatime,nodiratime`：減少不必要的 metadata 寫入，對高 I/O 目錄（RRD、OpenSearch）有效。

### 1.4 掛載順序注意（兩 VM 都要）

部分目錄掛載前要先建立目錄，否則服務裝起來檔案會被掛載點蓋掉：

```bash
sudo mkdir -p /var/lib/mysql /var/lib/postgresql /var/lib/redis \
              /var/lib/mongodb /var/lib/graylog-server \
              /var/lib/opensearch /opt/librenms/rrd
sudo mount -a
df -h | grep -E 'mysql|postgres|redis|mongo|graylog|opensearch|rrd'
```

---

## 2. 網路與防火牆規劃

### 2.1 Port 矩陣

| 來源 | 目的 | Port | 協定 | 用途 |
|------|------|------|------|------|
| 使用者 | VM-A | 80 / 443 | TCP | LibreNMS / jt-ipam Web UI |
| 使用者 | VM-B | 443 | TCP | Graylog Web UI |
| 設備 | VM-A | 161 | UDP | SNMP poll（由 VM-A 主動）|
| 設備 | VM-A | 162 | UDP | SNMP Trap |
| 設備 | VM-B | 514 | UDP/TCP | Syslog |
| 設備 | VM-B | 12201 | UDP/TCP | GELF |
| VM-A | VM-B | 443 / 9000 | TCP | LibreNMS 查詢 Graylog API |
| VM-A | VM-B | 9200 | TCP | jt-ipam 查詢 OpenSearch（選用）|
| 管理員 | 兩 VM | 22 | TCP | SSH（建議僅內網/VPN）|

### 2.2 防火牆設定（VM-A，ufw 範例）

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing

sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 162/udp                     # SNMP Trap
sudo ufw allow from 10.0.10.0/24 to any port 22  # 限內網 SSH

sudo ufw enable
```

### 2.3 防火牆設定（VM-B）

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing

sudo ufw allow 22/tcp
sudo ufw allow 443/tcp                            # Web UI（nginx）
sudo ufw allow 514/udp                            # Syslog UDP
sudo ufw allow 514/tcp                            # Syslog TCP
sudo ufw allow 12201/udp                          # GELF UDP

# 僅允許 VM-A 存取 Graylog API 與 OpenSearch
sudo ufw allow from 10.0.10.10 to any port 9000  # Graylog API（可省略，走 443）
sudo ufw allow from 10.0.10.10 to any port 9200  # OpenSearch（選用）

sudo ufw enable
```

### 2.4 系統調校（兩 VM 共通）

```bash
# 時區
sudo timedatectl set-timezone Asia/Taipei

# 開啟 NTP
sudo timedatectl set-ntp true

# 提高檔案描述符上限（OpenSearch / nginx 必要）
sudo tee /etc/security/limits.d/99-monitoring.conf > /dev/null << 'EOF'
*               soft    nofile          65536
*               hard    nofile          65536
*               soft    nproc           4096
*               hard    nproc           4096
EOF

# sysctl 調校
sudo tee /etc/sysctl.d/99-monitoring.conf > /dev/null << 'EOF'
# 網路
net.core.somaxconn = 4096
net.ipv4.tcp_max_syn_backlog = 4096

# 記憶體
vm.swappiness = 10
vm.max_map_count = 262144   # OpenSearch 要求
EOF

sudo sysctl --system
```

---

## 3. VM-B 部署：Graylog + OpenSearch

> **為何先裝 VM-B**：LibreNMS 要連接 Graylog API，先讓 VM-B 就緒可避免重新設定。

### 3.1 系統準備

```bash
sudo apt-get update
sudo apt-get install -y curl gnupg apt-transport-https openjdk-21-jre-headless \
  uuid-runtime pwgen ca-certificates nginx
java -version
```

### 3.2 安裝 MongoDB 7

```bash
curl -fsSL https://www.mongodb.org/static/pgp/server-7.0.asc | \
  sudo gpg --dearmor -o /usr/share/keyrings/mongodb-server-7.0.gpg

echo "deb [ signed-by=/usr/share/keyrings/mongodb-server-7.0.gpg ] \
  https://repo.mongodb.org/apt/ubuntu jammy/mongodb-org/7.0 multiverse" | \
  sudo tee /etc/apt/sources.list.d/mongodb-org-7.0.list

sudo apt-get update
sudo apt-get install -y mongodb-org

# MongoDB 預設裝在 /var/lib/mongodb（剛好對應 lv-mongodb）
sudo systemctl enable --now mongod
sudo systemctl status mongod --no-pager | head -5
```

### 3.3 安裝 OpenSearch 2.x

```bash
curl -o- https://artifacts.opensearch.org/publickeys/opensearch.pgp | \
  sudo gpg --dearmor --batch --yes -o /usr/share/keyrings/opensearch-keyring

echo "deb [signed-by=/usr/share/keyrings/opensearch-keyring] \
  https://artifacts.opensearch.org/releases/bundle/opensearch/2.x/apt stable main" | \
  sudo tee /etc/apt/sources.list.d/opensearch-2.x.list

sudo apt-get update
sudo OPENSEARCH_INITIAL_ADMIN_PASSWORD='請改為強密碼' apt-get install -y opensearch

# 變更資料目錄到獨立 LV（如果預設路徑非 /var/lib/opensearch）
sudo systemctl stop opensearch
sudo rsync -aHX /var/lib/opensearch/ /var/lib/opensearch.bak/ 2>/dev/null || true
```

#### OpenSearch 設定檔 `/etc/opensearch/opensearch.yml`

```yaml
cluster.name: graylog
node.name: opensearch-vmb
path.data: /var/lib/opensearch
path.logs: /var/log/opensearch
network.host: 127.0.0.1                # 僅監聽 localhost（Graylog 同台）
http.port: 9200
discovery.type: single-node
action.auto_create_index: false
plugins.security.disabled: true        # 單機環境關閉 security（簡化整合）
```

#### OpenSearch JVM Heap（極重要）

```bash
sudo tee /etc/opensearch/jvm.options.d/heap.options > /dev/null << 'EOF'
-Xms8g
-Xmx8g
EOF
```

> **規則**：Heap = RAM 的 **1/3 到 1/2**，且**不可超過 31 GB**（壓縮指標 OOP 限制）。24 GB RAM 給 8 GB heap 適中。

```bash
sudo systemctl enable --now opensearch
sleep 30
curl -s http://127.0.0.1:9200/_cluster/health?pretty
```

### 3.4 安裝 Graylog 6.x

```bash
wget https://packages.graylog2.org/repo/packages/graylog-6.1-repository_latest.deb
sudo dpkg -i graylog-6.1-repository_latest.deb
sudo apt-get update
sudo apt-get install -y graylog-server

# 產生 password_secret
PASSWORD_SECRET=$(pwgen -N 1 -s 96)

# 產生 admin 密碼雜湊
read -rsp "Graylog admin 密碼: " ADMIN_PASS; echo
ROOT_PASSWORD_SHA2=$(echo -n "$ADMIN_PASS" | sha256sum | awk '{print $1}')

# 寫入設定檔
sudo sed -i "s|password_secret =.*|password_secret = ${PASSWORD_SECRET}|" /etc/graylog/server/server.conf
sudo sed -i "s|root_password_sha2 =.*|root_password_sha2 = ${ROOT_PASSWORD_SHA2}|" /etc/graylog/server/server.conf
sudo sed -i "s|#root_timezone = UTC|root_timezone = Asia/Taipei|" /etc/graylog/server/server.conf
sudo sed -i "s|#http_bind_address = 127.0.0.1:9000|http_bind_address = 127.0.0.1:9000|" /etc/graylog/server/server.conf
sudo sed -i "s|http_external_uri =.*|http_external_uri = https://10.0.10.11/|" /etc/graylog/server/server.conf
sudo sed -i "s|elasticsearch_hosts =.*|elasticsearch_hosts = http://127.0.0.1:9200|" /etc/graylog/server/server.conf
```

#### Graylog Heap 調校

```bash
sudo sed -i 's|-Xms.*|-Xms4g|' /etc/default/graylog-server
sudo sed -i 's|-Xmx.*|-Xmx4g|' /etc/default/graylog-server
```

```bash
sudo systemctl enable --now graylog-server
sudo journalctl -u graylog-server -f   # 觀察啟動，看到 "Graylog server up and running" 即成功
```

### 3.5 設定 nginx 前置（HTTPS）

```bash
# 自簽憑證（正式環境換成 Let's Encrypt 或內部 CA）
sudo mkdir -p /etc/nginx/ssl
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/nginx/ssl/graylog.key \
  -out /etc/nginx/ssl/graylog.crt \
  -subj "/CN=graylog.internal/O=Monitoring"

sudo tee /etc/nginx/conf.d/graylog.conf > /dev/null << 'NGINX'
server {
    listen 443 ssl;
    server_name 10.0.10.11 graylog.internal;

    ssl_certificate     /etc/nginx/ssl/graylog.crt;
    ssl_certificate_key /etc/nginx/ssl/graylog.key;
    ssl_protocols TLSv1.2 TLSv1.3;

    location / {
        proxy_set_header Host              $http_host;
        proxy_set_header X-Forwarded-Host  $http_host;
        proxy_set_header X-Forwarded-Server $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Graylog-Server-URL https://$server_name/;
        proxy_pass http://127.0.0.1:9000;
    }
}

server {
    listen 80;
    server_name 10.0.10.11 graylog.internal;
    return 301 https://$host$request_uri;
}
NGINX

sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 3.6 Graylog Input 建立

登入 `https://10.0.10.11/`（帳號 `admin` / 剛才設的密碼）後：

1. **System → Inputs → Select Input** → **Syslog UDP** → **Launch new input**
2. 設定：
   - **Title**：`syslog-udp-514`
   - **Bind address**：`0.0.0.0`
   - **Port**：`514`
   - **Allow override date**：勾選
3. **Save**

驗證：
```bash
# 從任一機器送測試訊息
logger -n 10.0.10.11 -P 514 -d "Graylog test message $(date)"
```

回 Graylog UI → **Search** → 應該看到訊息。

---

## 4. VM-A 部署：LibreNMS + jt-ipam

### 4.1 安裝 LibreNMS

> **完整流程**參考 [docs/deployment-guide.md](deployment-guide.md)，這裡只列關鍵差異。

```bash
# 套件
sudo apt-get update
sudo apt-get install -y acl curl fping git mariadb-client mariadb-server \
  mtr-tiny nginx-full nmap php-cli php-curl php-fpm php-gd php-gmp php-json \
  php-mbstring php-mysql php-snmp php-xml php-zip \
  python3-command-runner python3-dotenv python3-pip python3-psutil \
  python3-pymysql python3-redis python3-setuptools python3-systemd \
  rrdtool snmp snmpd traceroute unzip whois

# 建立使用者並 clone
sudo useradd librenms -d /opt/librenms -M -r -s "$(which bash)"
sudo git clone https://github.com/librenms/librenms.git /opt/librenms

# 注意：/opt/librenms/rrd 已是獨立掛載點，要先 chown
sudo chown -R librenms:librenms /opt/librenms
sudo chown librenms:librenms /opt/librenms/rrd
sudo chmod 771 /opt/librenms

# 後續步驟（ACL、Composer、MariaDB、PHP-FPM、Nginx、SNMP、Cron、.env、migration、admin user）
# 完全照 deployment-guide.md 第 6–15 章執行
```

#### MariaDB innodb buffer pool 調校（500 台規模）

`/etc/mysql/mariadb.conf.d/50-server.cnf` 加入：
```ini
[mysqld]
innodb_file_per_table=1
lower_case_table_names=0

# 調校（500 台設備）
innodb_buffer_pool_size=2G
innodb_log_file_size=512M
innodb_flush_log_at_trx_commit=2
innodb_io_capacity=2000
max_connections=200
```

#### RRD 路徑驗證（重要）

```bash
mount | grep rrd
# 應該看到 /opt/librenms/rrd 是 ext4 獨立掛載
# 若顯示空，表示 LV 沒掛上，service 跑起來會把 RRD 寫到 / 上撐爆 root partition
```

### 4.2 安裝 jt-ipam

#### 4.2.1 Clone repo 並審閱安裝腳本

> 🚨 **致命陷阱**：jt-ipam 官方安裝腳本是 `scripts/jt-ipam.sh`（**不是** `bootstrap.sh`，該檔已不存在）。
> 腳本內部以 `REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"` 推導根目錄，接著 `chown -R jtipam "$REPO_ROOT"`。
> **若把腳本單獨複製到 `/tmp` 執行，`REPO_ROOT` 會變成 `/`，導致 `chown -R jtipam /` 摧毀整台系統**
>（owner 全變 jtipam + setuid 被清，只能重裝 OS；2026-06-14 實機已踩此坑）。
> **唯一正確做法是 `git clone` 完整 repo，從 `repo/scripts/` 執行。**

```bash
# Clone 完整 repo（REPO_ROOT 才會正確解析為 /opt/jt-ipam）
sudo git clone https://github.com/jasoncheng7115/jt-ipam.git /opt/jt-ipam

# ⚠️ 必審：搜尋會不會動到 LibreNMS 的 nginx 設定
grep -nE 'sites-enabled|default_server|librenms|listen 80' /opt/jt-ipam/scripts/jt-ipam.sh
less /opt/jt-ipam/scripts/jt-ipam.sh

# pgvector 前置：Ubuntu 24.04 的 PGDG repo 無 postgresql-16-pgvector，須先從 Ubuntu repo 裝，
# 否則腳本的 apt-cache madison 檢查會誤判失敗。裝完後再 patch 腳本的 die 檢查。
sudo apt-get update && sudo apt-get install -y postgresql-16-pgvector
# patch 方式見 single-vm-deployment.md §7.2
```

#### 4.2.2 預先掛載 PostgreSQL data dir

> **重要**：bootstrap.sh 會自動裝 PostgreSQL，預設資料目錄是 `/var/lib/postgresql`。我們已切獨立 LV，但 PostgreSQL 安裝**會建立子目錄 `/var/lib/postgresql/16/main`**，要確認權限正確。

```bash
sudo mkdir -p /var/lib/postgresql
sudo chown postgres:postgres /var/lib/postgresql 2>/dev/null || true
mount | grep postgresql   # 確認獨立掛載
```

#### 4.2.3 執行安裝腳本（務必用完整 repo 路徑）

```bash
# 一律用 /opt/jt-ipam/scripts/jt-ipam.sh 完整路徑（見 4.2.1 致命陷阱）
sudo bash /opt/jt-ipam/scripts/jt-ipam.sh install \
    --tls-mode self-signed \
    --public-fqdn 10.0.10.10 2>&1 | grep -v "chown: changing ownership of '/proc"
# --public-fqdn 換成本機 IP 或域名；grep -v 過濾遞迴 chown /opt/jt-ipam 碰到 /proc 的無害噪音
```

腳本會：
1. 安裝 postgresql-16、python3.12、redis
2. 建立 `jtipam` 系統帳號（home `/var/lib/jt-ipam`）
3. 產生 jt-ipam 設定（`/etc/jt-ipam/`）
4. 跑 alembic migration（資料庫 `jt_ipam`）
5. build 前端
6. 啟動 systemd 服務 `jt-ipam-backend.service`（後端在 `127.0.0.1:8000`）+ sync/backup timer

**Admin 初始密碼**：
```bash
sudo cat /etc/jt-ipam/.admin-initial-password
```

#### 4.2.4 修改 nginx 設定，與 LibreNMS 共存

bootstrap.sh 可能建立的設定會搶 `:80` 或 `default_server`，需要調整。

**目標配置**：
- `:80` → LibreNMS（保持）
- `:443` → jt-ipam（HTTPS）
- 兩個 server_name 分開

```bash
# 1. 移除可能衝突的 jt-ipam 預設 conf（路徑視 bootstrap.sh 而定）
sudo ls /etc/nginx/sites-enabled/  /etc/nginx/conf.d/ | grep -i ipam

# 2. 建立 jt-ipam HTTPS server block
sudo mkdir -p /etc/nginx/ssl
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/nginx/ssl/jt-ipam.key \
  -out /etc/nginx/ssl/jt-ipam.crt \
  -subj "/CN=ipam.internal/O=IPAM"

sudo tee /etc/nginx/conf.d/jt-ipam.conf > /dev/null << 'NGINX'
server {
    listen 443 ssl;
    server_name 10.0.10.10 ipam.internal;

    ssl_certificate     /etc/nginx/ssl/jt-ipam.crt;
    ssl_certificate_key /etc/nginx/ssl/jt-ipam.key;
    ssl_protocols TLSv1.2 TLSv1.3;

    client_max_body_size 50M;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade           $http_upgrade;
        proxy_set_header Connection        "upgrade";
    }
}
NGINX

# 3. LibreNMS 的 conf（/etc/nginx/conf.d/librenms.conf）保持 :80，server_name 改具體值
sudo sed -i 's|server_name localhost;|server_name 10.0.10.10 librenms.internal;|' \
  /etc/nginx/conf.d/librenms.conf

# 4. 測試與重載
sudo nginx -t && sudo systemctl reload nginx
```

#### 4.2.5 驗證

```bash
curl -kI https://10.0.10.10/        # → jt-ipam (200/302)
curl -I  http://10.0.10.10/login    # → LibreNMS (200)
```

---

## 5. 三者整合

### 5.1 LibreNMS → Graylog 整合

```bash
# VM-A 上執行
sudo -u librenms bash -c '
cd /opt/librenms
php lnms config:set graylog.server "https://10.0.10.11"
php lnms config:set graylog.port 443
php lnms config:set graylog.username "admin"
php lnms config:set graylog.password "Graylog 的 admin 密碼"
php lnms config:set graylog.version "6.1"
php lnms config:set graylog.timezone "Asia/Taipei"
'
```

> ⚠️ 自簽憑證時，LibreNMS PHP 端可能拒絕憑證，可暫時：
> ```bash
> sudo -u librenms bash -c 'cd /opt/librenms && php lnms config:set graylog.api.verify-ssl false'
> ```
> 正式環境請改用內部 CA 簽發的憑證，不要永久關閉 verify-ssl。

驗證：登入 LibreNMS → 任一設備頁 → **Logs** tab → 出現 Graylog 分頁。

### 5.2 設備 syslog 設定（送 Graylog）

所有設備（路由器/交換器/伺服器）syslog 目標設為 `10.0.10.11:514`。

範例（Cisco IOS）：
```
logging trap informational
logging host 10.0.10.11 transport udp port 514
```

範例（Linux server）：
```bash
echo '*.* @10.0.10.11:514' | sudo tee /etc/rsyslog.d/50-graylog.conf
sudo systemctl restart rsyslog
```

### 5.3 LibreNMS ↔ jt-ipam 整合

#### 5.3.1 取得 LibreNMS API Token

LibreNMS Web UI → 右上角頭像 → **API** → **Create API Token**，複製 token。

#### 5.3.2 在 jt-ipam 設定 LibreNMS 來源

登入 jt-ipam（`https://10.0.10.10/`）：

1. **Settings → Integrations → LibreNMS**
2. 填入：
   - **URL**：`http://127.0.0.1`（同台用 localhost，免 TLS）
   - **API Token**：上一步複製的
   - **Sync ARP/FDB**：啟用
3. **Test Connection** → **Save**

#### 5.3.3 jt-ipam → Graylog 整合（選用）

如果要在 jt-ipam 查 IP 對應主機名歷史：

1. **Settings → Integrations → Graylog**
2. 填入：
   - **URL**：`https://10.0.10.11`
   - **Username** / **Password**：Graylog 帳密（建議建立專用唯讀帳號）
3. **Test → Save**

### 5.4 整合驗證清單

| 檢查項 | 預期結果 |
|--------|---------|
| LibreNMS 設備頁 Logs → Graylog tab | 顯示該設備近期 syslog |
| Graylog Web → Search → `source:10.0.10.x` | 看到設備 log |
| jt-ipam → Devices → Sync from LibreNMS | 設備清單匯入 |
| jt-ipam → IP Detail → 點選 IP | 查到 ARP/FDB 與 Graylog 主機歷史 |

---

## 6. 備份策略

### 6.1 VM-A 備份

| 資料 | 路徑 | 頻率 | 工具 |
|------|------|------|------|
| LibreNMS DB | `/var/lib/mysql` | 每日 | `mysqldump --single-transaction librenms` |
| LibreNMS RRD | `/opt/librenms/rrd` | 每週 | `rsync` 或 LV snapshot |
| LibreNMS config | `/opt/librenms/.env`、`/opt/librenms/config.php` | 每次修改後 | git |
| jt-ipam DB | `/var/lib/postgresql` | 每日 | `pg_dump jt_ipam` |
| jt-ipam config | `/etc/jt-ipam/` | 每次修改後 | rsync 異地 |
| nginx config | `/etc/nginx/conf.d/`、`/etc/nginx/ssl/` | 每次修改後 | git |

#### 範例：每日 DB 備份 cron

```bash
sudo tee /etc/cron.d/backup-databases > /dev/null << 'EOF'
# 每日 02:00 備份所有 DB
0 2 * * * root mysqldump --single-transaction --routines librenms | gzip > /var/backups/librenms-$(date +\%Y\%m\%d).sql.gz
5 2 * * * postgres pg_dump jt_ipam | gzip > /var/backups/jt_ipam-$(date +\%Y\%m\%d).sql.gz
# 保留 14 天
10 2 * * * root find /var/backups -name '*.sql.gz' -mtime +14 -delete
EOF
```

### 6.2 VM-B 備份

| 資料 | 路徑 | 頻率 | 工具 |
|------|------|------|------|
| MongoDB | `/var/lib/mongodb` | 每日 | `mongodump --out=/var/backups/mongo-$(date +%F)` |
| Graylog config | `/etc/graylog/` | 每次修改後 | rsync |
| OpenSearch indices | `/var/lib/opensearch` | **不建議檔案備份** | 改用 **OpenSearch Snapshot API**（指向 S3/NFS） |

#### OpenSearch Snapshot 範例

```bash
# 1. 在 opensearch.yml 加入 repo 路徑
echo 'path.repo: ["/mnt/nfs-backup"]' | sudo tee -a /etc/opensearch/opensearch.yml
sudo systemctl restart opensearch

# 2. 註冊 repo
curl -X PUT http://127.0.0.1:9200/_snapshot/daily \
  -H 'Content-Type: application/json' \
  -d '{"type":"fs","settings":{"location":"/mnt/nfs-backup/opensearch","compress":true}}'

# 3. 建立 snapshot（每日 cron）
curl -X PUT "http://127.0.0.1:9200/_snapshot/daily/snap-$(date +%Y%m%d)?wait_for_completion=false"
```

### 6.3 LVM Snapshot（停機備援）

```bash
# VM-A：對 MySQL LV 做 snapshot（要先 FLUSH TABLES WITH READ LOCK）
sudo lvcreate -L 5G -s -n lv-mysql-snap /dev/vg-data/lv-mysql
# 備份 snapshot 到外部，完成後刪除
sudo lvremove -y /dev/vg-data/lv-mysql-snap
```

---

## 7. 維運監控

### 7.1 監控自己（dogfooding）

兩台 VM 都當設備加入 LibreNMS：

```bash
# 在 VM-A 上
sudo -u librenms bash -c '
cd /opt/librenms
php lnms device:add 10.0.10.10 --v2c --community="librenms_snmp"  # 自己
php lnms device:add 10.0.10.11 --v2c --community="librenms_snmp"  # VM-B
'
```

### 7.2 健康檢查指令

```bash
# VM-A
sudo -u librenms bash -c 'cd /opt/librenms && php validate.php'

# VM-B
curl -s http://127.0.0.1:9200/_cluster/health?pretty
curl -s -u admin:密碼 http://127.0.0.1:9000/api/system/lbstatus
systemctl status mongod opensearch graylog-server nginx
```

### 7.3 關鍵告警（在 LibreNMS 設）

| 告警 | 規則 | 嚴重度 |
|------|------|--------|
| OpenSearch heap > 85% | `applications.metrics_value > 0.85` | Warning |
| `/var/lib/opensearch` 使用率 > 80% | `storage.percent > 80` | Warning |
| Graylog journal lag | journal_unread > 1M msgs | Critical |
| LibreNMS RRD 寫入失敗 | event 名稱含 "rrd_update" | Critical |
| 兩 VM 互 ping 失敗 | ICMP 超時 | Critical |

---

## 8. 災難復原

### 8.1 OpenSearch 損毀（最常見）

**症狀**：Graylog 啟動 30 秒後關掉、Search 一直 spinner、`/var/log/opensearch/graylog.log` 出現 `red status`

```bash
# 1. 檢查叢集狀態
curl http://127.0.0.1:9200/_cluster/health?pretty

# 2. 看哪個 index 紅
curl http://127.0.0.1:9200/_cat/indices?v | grep red

# 3. 嘗試 reroute
curl -X POST http://127.0.0.1:9200/_cluster/reroute?retry_failed=true

# 4. 不行就刪掉壞 index（Log 會丟失）
curl -X DELETE http://127.0.0.1:9200/graylog_NN
```

### 8.2 LibreNMS RRD 損壞

**症狀**：圖表空白、`/opt/librenms/rrd` 變大但讀不出資料

```bash
sudo systemctl stop cron
sudo -u librenms bash -c 'cd /opt/librenms && php scripts/rrdtune.php -h all'

# 嚴重損毀：從備份還原 LV
sudo umount /opt/librenms/rrd
sudo rsync -aHX /mnt/backup/rrd/ /opt/librenms/rrd/
sudo mount /opt/librenms/rrd
```

### 8.3 VM-B 整台失效

LibreNMS 設備頁 Logs tab 會顯示「Graylog connection failed」但**監控本身不受影響**（這是分離部署的好處）。

修復步驟：
1. 修復 VM-B 或從 snapshot 還原
2. OpenSearch 起來後 Graylog 自動重連
3. 設備 syslog 在 VM-B 停機期間**會遺失**（除非設備本地有 buffer）

### 8.4 磁碟爆滿緊急處理

```bash
# 找出大宗
sudo du -sh /var/lib/* /opt/* /var/log/* 2>/dev/null | sort -hr | head -10

# 立即 lvextend（前提：vg-data 還有空間）
sudo vgs
sudo lvextend -L +20G /dev/vg-data/lv-opensearch
sudo xfs_growfs /var/lib/opensearch     # xfs 線上擴充
# 或
sudo resize2fs /dev/vg-data/lv-mysql    # ext4 線上擴充
```

---

## 9. 疑難排解

| 症狀 | 可能原因 | 處置 |
|------|---------|------|
| nginx 啟動失敗，binding 80 | jt-ipam bootstrap 建了重複 server | 刪除 `/etc/nginx/sites-enabled/default` 與重複 conf |
| LibreNMS Logs tab 空白 | `graylog.api.verify-ssl` 拒絕自簽 | 設定 false 或裝內部 CA |
| OpenSearch 不啟動，`vm.max_map_count` | sysctl 沒設 | `sudo sysctl -w vm.max_map_count=262144` 並寫入 sysctl.d |
| Graylog journal lag 持續上升 | OpenSearch 處理不及 | 加 heap、或 process buffer 改大 |
| LibreNMS poller 跑不完 5 分鐘 | 設備過多單執行緒撐不住 | 改用 `poller-wrapper.py` 多執行緒（已在 cron 預設） |
| jt-ipam Web 404 | uvicorn 沒起 | `systemctl status jt-ipam-backend` 看錯誤 |
| 兩台 VM 時間不同步 | NTP 沒開 | `timedatectl set-ntp true` |
| `mount: special device ... does not exist` | LV 還沒建 | `vgs` / `lvs` 確認，必要時重建 |

---

## 附錄 A：完整部署時程估計

| 階段 | 預估時間 |
|------|---------|
| VM 建立 + 磁碟分割 + OS 安裝（兩台）| 2 小時 |
| VM-B（Graylog + OpenSearch）部署 | 2 小時 |
| VM-A（LibreNMS）部署 | 1.5 小時 |
| VM-A（jt-ipam）部署 | 1 小時 |
| 三者整合 + 驗證 | 1 小時 |
| 備份/監控設定 | 1 小時 |
| **合計** | **約 8.5 小時** |

## 附錄 B：版本對應表（2026-06）

| 元件 | 推薦版本 | 備註 |
|------|---------|------|
| Ubuntu | 24.04 LTS | 維護至 2029-04 |
| PHP | 8.3 | Ubuntu 24.04 原生 |
| MariaDB | 10.11 | LTS 版本 |
| PostgreSQL | 16 | jt-ipam 要求 |
| MongoDB | 7.0 | Graylog 6.x 相容 |
| OpenSearch | 2.x | Graylog 6.x 相容 |
| Graylog | 6.1 | 最新穩定 |
| LibreNMS | master | rolling release |
| jt-ipam | main | rolling release |

---

*依本文檔部署完成後，請將實際 IP / 密碼 / API Token 紀錄於密碼管理器，不要寫回本文件。*
