# 整合監控平台單機部署 Runbook
# LibreNMS + Graylog + jt-ipam on Ubuntu 24.04

> **適用場景**：小型至中型環境（≤ 100 台設備），資源有限、單台 VM 部署
> **較大規模請改用** [雙 VM 部署](integrated-deployment.md)（100–500 台）
> **OS**：Ubuntu 24.04 LTS
> **更新日期**：2026-06-14（v2：依 172.16.1.94 重裝實測，修正 7 處 LibreNMS 安裝盲點）
>
> **本次修正項目**：
> 1. §4.1 lv-backup 改 `-l 100%FREE`（避免 VG metadata 佔用導致 `-L 60G` 少 1 extent 失敗）
> 2. §4.1 mkfs 步驟加 `-F`/`-f` 並標註【必要】；末尾加 `blkid` 驗證
> 3. §4.3 fstab 改用 `/dev/vg-data/lv-xxx` device path，移除 `UUID=<placeholder>` 模板
> 4. §5.4 加密碼一致性警告 + DB 帳號連線驗證指令
> 5. §5.5 `.env` 寫入改 append（現代 LibreNMS 的 `.env.example` 不含 DB_* 行，sed 替換無效）
> 6. §5.12 新增 snmpd 安裝設定章節（之前完全缺失）
> 7. §5.13 SNMP Community 設定（移除已不存在的 `--json` flag）
>
> **歷史更新**：jt-ipam 章節改用官方腳本流程，新增 REPO_ROOT 致命陷阱與 pgvector/nginx 修正
>
> ⚠️ **部署 jt-ipam 前務必先讀 [§7.0 致命陷阱](#-70-致命陷阱絕不可把腳本單獨複製到-tmp-執行會摧毀整台系統)** —— 從錯誤路徑執行安裝腳本會 `chown -R jtipam /` 摧毀整台系統。

---

## 目錄

- [0. 前置說明](#0-前置說明)
- [1. VM 規格與磁碟規劃](#1-vm-規格與磁碟規劃)
- [2. 安裝順序與原因](#2-安裝順序與原因)
- [3. 基礎系統準備](#3-基礎系統準備)
- [4. LVM 磁碟分割](#4-lvm-磁碟分割)
- [5. 安裝 LibreNMS](#5-安裝-librenms)
- [6. 安裝 Graylog（含 OpenSearch + MongoDB）](#6-安裝-graylog含-opensearch--mongodb)
- [7. 安裝 jt-ipam（含 PostgreSQL + Redis）](#7-安裝-jt-ipam含-postgresql--redis)
- [8. Nginx 統一設定](#8-nginx-統一設定)
- [9. 三者整合](#9-三者整合)
- [10. 備份策略](#10-備份策略)
- [11. 驗證檢查清單](#11-驗證檢查清單)
- [12. 疑難排解](#12-疑難排解)

---

## 0. 前置說明

### 服務組合與職責

| 服務 | 職責 | Port |
|------|------|------|
| **LibreNMS** | SNMP 網路監控、設備圖表、告警 | :80 / :443 |
| **Graylog** | Log 集中收集、搜尋、告警 | :9000（Web）、:5044（Beats）、:12201（GELF）|
| **Graylog Datanode** | 嵌入式 OpenSearch 管理（graylog 7.x 取代獨立 OpenSearch）| :9200（內部，不對外）|
| **MongoDB** | Graylog 的設定資料庫 | :27017（內部）|
| **jt-ipam** | IP 位址管理、與 LibreNMS/Graylog 整合 | :8080 |
| **MariaDB** | LibreNMS 資料庫 | :3306（內部）|
| **PostgreSQL** | jt-ipam 資料庫 | :5432（內部）|
| **Redis** | jt-ipam 快取 | :6379（內部）|

### 資源需求（建議）

| 元件 | RAM | 備註 |
|------|-----|------|
| LibreNMS + MariaDB | 2–3 GB | |
| Graylog（Java heap） | 3–4 GB | 設 `-Xms3g -Xmx3g` |
| Graylog Datanode（OpenSearch heap） | 4–6 GB | **不可超過 31 GB** |
| MongoDB | 1 GB | |
| jt-ipam + PostgreSQL + Redis | 2–3 GB | |
| OS + Nginx + 緩衝 | 2 GB | |
| **總計（建議）** | **≥ 16 GB** | 24 GB 較穩定 |

> ⚠️ **低於 16 GB 的 VM 不建議同時跑三套**。若只有 8 GB，可將 OpenSearch heap 降至 2 GB，但效能會受影響。

---

## 1. VM 規格與磁碟規劃

### 1.1 建議 VM 規格

| 項目 | 最低 | 建議 |
|------|------|------|
| vCPU | 4 核心 | 8 核心 |
| RAM | 16 GB | 24 GB |
| 系統磁碟 | 100 GB | 150 GB |
| 資料磁碟 | 300 GB | 500 GB |
| OS | Ubuntu 24.04 LTS | Ubuntu 24.04 LTS |

> 建議**分兩顆虛擬磁碟**：`/dev/sda`（OS）+ `/dev/sdb`（資料），方便快照與擴容。

### 1.2 LVM 規劃

```
/dev/sda（150 GB，OS 磁碟）
├── /dev/sda1      1 GB     /boot/efi    vfat（UEFI）
├── /dev/sda2      2 GB     /boot        ext4
└── /dev/sda3      147 GB   PV → VG: vg-system
    ├── lv-root    30 GB    /            ext4
    ├── lv-opt     20 GB    /opt         ext4   （LibreNMS、jt-ipam 程式）
    ├── lv-var     15 GB    /var         ext4   （logs、cache 等雜項）
    ├── lv-tmp     5  GB    /tmp         ext4
    └── lv-swap    8  GB    [swap]

/dev/sdb（500 GB，資料磁碟）
└── /dev/sdb1      500 GB   PV → VG: vg-data
    ├── lv-mysql       30 GB    /var/lib/mysql         ext4
    ├── lv-rrd         60 GB    /opt/librenms/rrd      ext4, noatime
    ├── lv-opensearch  280 GB   /var/lib/opensearch    xfs,  noatime,nodiratime
    ├── lv-mongodb     20 GB    /var/lib/mongodb       ext4
    ├── lv-postgres    25 GB    /var/lib/postgresql    ext4
    ├── lv-redis       5  GB    /var/lib/redis         ext4
    ├── lv-graylog     20 GB    /var/log/graylog       ext4
    └── lv-backup      60 GB    /backup                ext4
```

> **為什麼 OpenSearch 用 xfs？**
> xfs 對大量小 I/O（Lucene index segment）的處理比 ext4 好，官方建議。
>
> **為什麼 RRD 要 noatime？**
> LibreNMS 每 5 分鐘輪詢一次並更新所有設備的 RRD 檔案，noatime 可減少大量無謂的 metadata 寫入。

---

## 2. 安裝順序與原因

```
[1] 基礎系統準備          → LVM、套件、使用者、時區、NTP
[2] MariaDB               → LibreNMS 依賴；先裝 DB 再裝應用
[3] LibreNMS              → 最先要能監控網路
[4] MongoDB               → Graylog 設定 DB，必須先於 Graylog
[5] graylog-datanode      → 內嵌 OpenSearch，不需再裝獨立 OpenSearch
    + graylog-server      → Graylog 7.x：datanode 先啟，等 90 秒再啟 server
[6] PostgreSQL            → jt-ipam 依賴
[7] Redis                 → jt-ipam 快取層依賴
[8] jt-ipam               → IPAM 平台（最後裝，官方 scripts/jt-ipam.sh 會調整 nginx）
[9] Nginx 衝突修正         → 移除 jt-ipam vhost 的 port 80 default_server（§7.4）
[10] 整合設定              → API token 串接、Graylog Input 設定
```

> **為什麼 jt-ipam 最後裝？**
> jt-ipam 官方安裝腳本 `scripts/jt-ipam.sh` 會自建 nginx vhost 並用 `listen 80 default_server`，
> 與 LibreNMS 的 port 80 衝突。最後裝可在 LibreNMS 就緒後再修正衝突（§7.4）。
>
> ⚠️ **執行腳本前先讀 [§7.0](#-70-致命陷阱絕不可把腳本單獨複製到-tmp-執行會摧毀整台系統)**：
> 必須從 `git clone` 出的 `/opt/jt-ipam/scripts/jt-ipam.sh` 完整路徑執行，
> **絕不可** `cp` 到 `/tmp` 單獨跑（`REPO_ROOT` 會變 `/`，`chown -R jtipam /` 摧毀系統）。

---

## 3. 基礎系統準備

```bash
# 更新系統
sudo apt-get update && sudo apt-get upgrade -y

# 安裝必要工具
sudo apt-get install -y \
    curl wget git vim htop \
    lvm2 xfsprogs \
    chrony ufw fail2ban \
    net-tools nmap \
    ca-certificates gnupg lsb-release \
    apt-transport-https software-properties-common

# 設定時區（台灣）
sudo timedatectl set-timezone Asia/Taipei
sudo systemctl enable --now chrony

# 確認時間同步
chronyc tracking

# 設定 hostname（依環境修改）
sudo hostnamectl set-hostname monitor-vm
echo "127.0.1.1 monitor-vm" | sudo tee -a /etc/hosts

# 停用 swap（OpenSearch 要求）
sudo swapoff -a
# 先不要永久停用，後面 LVM 設定後重新設定
```

### 3.1 核心參數（OpenSearch 必要）

```bash
# 設定 vm.max_map_count（OpenSearch 要求 ≥ 262144）
echo "vm.max_map_count=262144" | sudo tee -a /etc/sysctl.conf
echo "fs.file-max=65536" | sudo tee -a /etc/sysctl.conf
sudo sysctl -p

# 設定 ulimit（加入 /etc/security/limits.conf）
cat << 'EOF' | sudo tee -a /etc/security/limits.conf
# OpenSearch
opensearch soft nofile 65536
opensearch hard nofile 65536
opensearch soft nproc 4096
opensearch hard nproc 4096
# librenms
librenms soft nofile 65536
librenms hard nofile 65536
EOF
```

### 3.2 防火牆設定

> **可攜性原則（重要）**：管理 UI 埠（Graylog 9000、jt-glogarch 8990、jt-gelflow 8099、
> LibreNMS Web）**不要硬編碼網段**。佈署到不同客戶站點時 IP/網段都不同，硬編碼的
> `from 172.16.0.0/16` 之類規則無法重用。改用 `system/setup-mgmt-firewall.sh`
> **自動偵測本機網段**開放管理埠；日誌「接收」埠才用手動規則（因來源在外部）。

```bash
# 啟用 ufw
sudo ufw enable

# ── 管理 UI 埠：自動偵測本機網段並開放（可攜，免每站手調）──
sudo /opt/oracle-mon-librenms/system/setup-mgmt-firewall.sh
#   預設開放 22,80,443,9000,8990,8099 給「本機所在網段」
#   另含遠端管理網段：sudo EXTRA_CIDRS="10.20.0.0/16" .../setup-mgmt-firewall.sh
#   自訂埠：sudo MGMT_PORTS="80,443,9000,8990" .../setup-mgmt-firewall.sh

# ── 日誌「接收」埠：對「log 來源」開放（來源常不在本機網段，故維持手動）──
sudo ufw allow 5044/tcp    # Beats
sudo ufw allow 12201/udp   # GELF UDP
sudo ufw allow 12201/tcp   # GELF TCP
sudo ufw allow 514/udp     # Syslog UDP
sudo ufw allow 161/udp     # SNMP（LibreNMS polling 回應）

sudo ufw status verbose
```

> **為何不用 nginx 反向代理把全部收斂到 443？**
> 評估過（方案 C）：`jt-glogarch` 的 web server 無 `root_path` 選項、無法掛在子路徑下；
> 且本機無 DNS，subdomain vhosts 在無 DNS 控制權的客戶站點不可攜。故採「自動偵測網段
> + 各服務自身 HTTPS/帳密認證」，是最可攜且零 nginx 改動風險的方案。
> 各管理介面本身都有登入驗證，限制來源網段即足夠。

#### 額外管理網段（其他內網網段 / 遠端管理）

本機直連網段會自動偵測；**其他經路由轉送的網段**（如管理者在不同 VLAN）需另外加入。
兩種方式，**寫入同一個持久化設定檔** `/etc/oracle-mon/mgmt-cidrs.conf`，
`setup-mgmt-firewall.sh` 每次自動讀取（更新重跑不會漏）：

- **GUI（建議）**：登入 LibreNMS →「⚙ → Oracle 監控管理」→ **區塊 D 防火牆管理網段**，
  直接增/刪網段，即時套用。
- **CLI**：
  ```bash
  sudo /opt/oracle-mon/admin/manage-mgmt-cidrs.sh add 172.16.5.0/24
  sudo /opt/oracle-mon/admin/manage-mgmt-cidrs.sh remove 172.16.5.0/24
  sudo /opt/oracle-mon/admin/manage-mgmt-cidrs.sh list
  ```

---

## 4. LVM 磁碟分割

> 以下指令假設 `/dev/sda`=OS 磁碟、`/dev/sdb`=資料磁碟。
> 用 `lsblk` 確認實際磁碟代號。

```bash
# 確認磁碟
lsblk
sudo fdisk -l /dev/sdb
```

### 4.1 建立資料磁碟 LVM

```bash
# 初始化 PV
sudo pvcreate /dev/sdb

# 建立 VG
sudo vgcreate vg-data /dev/sdb

# 建立各 LV
# ⚠️ lv-backup 使用 -l 100%FREE 吃掉剩餘空間，避免因 VG metadata
#    佔用導致 `-L 60G` 比實際可用多 1 extent 而 lvcreate 失敗。
sudo lvcreate -n lv-mysql       -L 30G       vg-data
sudo lvcreate -n lv-rrd         -L 60G       vg-data
sudo lvcreate -n lv-opensearch  -L 280G      vg-data
sudo lvcreate -n lv-mongodb     -L 20G       vg-data
sudo lvcreate -n lv-postgres    -L 25G       vg-data
sudo lvcreate -n lv-redis       -L 5G        vg-data
sudo lvcreate -n lv-graylog     -L 20G       vg-data
sudo lvcreate -n lv-backup      -l 100%FREE  vg-data

# ⚠️ 格式化是【必要】步驟，不可跳過。未格式化的 LV 不可被 mount，
#    `blkid` 也不會顯示，後續 §4.3 `mount -a` 會全部失敗。
#    使用 -F / -f 強制（剛建立的 LV 為空，安全）。
# ext4（一般用途）
sudo mkfs.ext4 -F -L mysql      /dev/vg-data/lv-mysql
sudo mkfs.ext4 -F -L rrd        /dev/vg-data/lv-rrd
sudo mkfs.ext4 -F -L mongodb    /dev/vg-data/lv-mongodb
sudo mkfs.ext4 -F -L postgres   /dev/vg-data/lv-postgres
sudo mkfs.ext4 -F -L redis      /dev/vg-data/lv-redis
sudo mkfs.ext4 -F -L graylog    /dev/vg-data/lv-graylog
sudo mkfs.ext4 -F -L backup     /dev/vg-data/lv-backup

# xfs（OpenSearch — 效能較好）
sudo mkfs.xfs -f -L opensearch  /dev/vg-data/lv-opensearch

# 驗證：8 個 LV 都應該有檔案系統
sudo blkid | grep vg-data
# 預期：8 行輸出，每個 LV 都顯示 TYPE="ext4" 或 TYPE="xfs"
```

### 4.2 掛載點設定

> ⚠️ **注意**：`/opt/librenms/rrd` 掛載點必須在 `git clone` **之後**建立，否則 clone 會因目錄非空而失敗。
> 此節先跳過 `lv-rrd` 的掛載，Section 5.6 再處理。

```bash
# 建立掛載點（跳過 /opt/librenms/rrd，留到 Section 5.6）
sudo mkdir -p /var/lib/mysql
sudo mkdir -p /var/lib/opensearch
sudo mkdir -p /var/lib/mongodb
sudo mkdir -p /var/lib/postgresql
sudo mkdir -p /var/lib/redis
sudo mkdir -p /var/log/graylog
sudo mkdir -p /backup
```

> 💡 **掛載寫法改用 LVM device path（`/dev/vg-data/lv-xxx`），不再用 UUID**：
> - device path 在 LVM 環境下與 UUID 同樣穩定，不會因 PV 重建而改變
> - 不需要 `blkid` 抄 UUID 填入模板，避免使用者忘了替換而 mount 失敗
> - 易讀、與 `lvs` 輸出一致，故障排除直觀

### 4.3 加入 /etc/fstab

> ⚠️ **注意**：`/opt/librenms/rrd` 掛載點在 `git clone` **之後**才能建立（Section 5.6）。
> fstab 仍寫入此行，但暫時先不執行 `mount -a`，避免因目錄不存在而 mount 失敗。

```bash
# 用 LVM device path 加入 fstab（穩定、可直接複製貼上）
# lv-rrd 暫時註解，git clone 完成後在 §5.6 取消註解。
cat << 'EOF' | sudo tee -a /etc/fstab

# === 資料磁碟 LVM（device path，穩定不需 UUID）===
/dev/vg-data/lv-mysql       /var/lib/mysql       ext4  defaults,noatime            0 2
/dev/vg-data/lv-opensearch  /var/lib/opensearch  xfs   defaults,noatime,nodiratime 0 2
/dev/vg-data/lv-mongodb     /var/lib/mongodb     ext4  defaults,noatime            0 2
/dev/vg-data/lv-postgres    /var/lib/postgresql  ext4  defaults,noatime            0 2
/dev/vg-data/lv-redis       /var/lib/redis       ext4  defaults                    0 2
/dev/vg-data/lv-graylog     /var/log/graylog     ext4  defaults,noatime            0 2
/dev/vg-data/lv-backup      /backup              ext4  defaults                    0 2
# rrd 待 git clone 後再啟用（§5.6 會取消下行註解）：
# /dev/vg-data/lv-rrd       /opt/librenms/rrd    ext4  defaults,noatime            0 2
EOF

# 重載 systemd（讀新的 fstab）
sudo systemctl daemon-reload

# 一次掛載全部（rrd 已註解不會被掛）
sudo mount -a

# 確認 7 個都掛上了
df -hT | grep vg-data
# 預期：7 行（mysql、opensearch、mongodb、postgresql、redis、graylog、backup）
```

> ⚠️ **若 `mount -a` 報 `special device ... does not exist`**：
> 通常是 §4.1 的 `mkfs` 沒跑、或 LV 名稱拼錯。先檢查：
> ```bash
> sudo blkid | grep vg-data    # 應有 8 行
> sudo lvs                      # 應列出 8 個 LV
> ```

---

## 5. 安裝 LibreNMS

> 參考官方 [Installing LibreNMS](https://docs.librenms.org/Installation/Install-LibreNMS/)

### 5.1 安裝依賴套件

```bash
# PHP 8.3
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update
sudo apt-get install -y \
    php8.3 php8.3-cli php8.3-common php8.3-curl \
    php8.3-fpm php8.3-gd php8.3-gmp php8.3-mbstring \
    php8.3-mysql php8.3-snmp php8.3-xml php8.3-zip \
    php8.3-bcmath php8.3-intl php8.3-opcache

# 其他依賴
sudo apt-get install -y \
    nginx python3-pip python3-pymysql \
    fping rrdtool snmp snmpd whois mtr-tiny \
    libvirt-clients graphviz imagemagick \
    acl nmap composer

# Node.js 20（前端 build 用）
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
```

### 5.2 安裝 MariaDB

```bash
# 安裝 MariaDB 10.11
curl -LsS https://r.mariadb.com/downloads/mariadb_repo_setup | \
    sudo bash -s -- --mariadb-server-version="mariadb-10.11"
sudo apt-get install -y mariadb-server mariadb-client

# 啟動（此時資料目錄已在 /var/lib/mysql 掛載點上）
sudo systemctl enable --now mariadb

# 安全設定
sudo mysql_secure_installation
```

**`mysql_secure_installation` 互動提示對照表**：

| 提示 | 建議回答 | 說明 |
| ---- | ---- | ---- |
| Enter current password for root | 直接 Enter | 剛安裝無密碼 |
| Switch to unix_socket authentication | **Y** | 允許 OS root 免密登入 |
| Change root password | **Y** → `LibreNMS@2026!` | 後續 LibreNMS DB 建立需要此密碼 |
| Remove anonymous users | **Y** | 移除匿名帳號 |
| Disallow root login remotely | **Y** | root 僅允許本機登入 |
| Remove test database | **Y** | 移除測試 DB |
| Reload privilege tables | **Y** | 立即套用變更 |

### 5.3 MariaDB 設定（/etc/mysql/mariadb.conf.d/50-server.cnf）

```bash
sudo tee /etc/mysql/mariadb.conf.d/99-librenms.cnf << 'EOF'
[mysqld]
innodb_file_per_table = 1
lower_case_table_names = 0
# 依 RAM 調整（建議 RAM 的 25%）
innodb_buffer_pool_size = 2G
innodb_log_file_size = 512M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT
EOF

sudo systemctl restart mariadb
```

### 5.4 建立 LibreNMS 資料庫

> ⚠️ **密碼一致性**：以下 `librenms_pwd_2026` 必須與 §5.5 寫入 `.env` 的 `DB_PASSWORD` **完全相同**。
> 若日後改密碼，兩處都要改，並用 `ALTER USER 'librenms'@'localhost' IDENTIFIED BY '新密碼'; FLUSH PRIVILEGES;` 同步 DB。

```bash
# 用 sudo mysql（unix_socket auth）建立 DB 與使用者
sudo mysql << 'EOF'
CREATE DATABASE librenms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'librenms'@'localhost' IDENTIFIED BY 'librenms_pwd_2026';
GRANT ALL PRIVILEGES ON librenms.* TO 'librenms'@'localhost';
FLUSH PRIVILEGES;
EOF

# 驗證：librenms 帳號可登入並看到 DB
mysql -u librenms -p'librenms_pwd_2026' -e "USE librenms; SELECT 'OK' AS status;"
```

### 5.5 安裝 LibreNMS 程式

```bash
# 建立使用者
sudo useradd librenms -d /opt/librenms -M -r -s "$(which bash)"

# Clone
# ⚠️ 若 /opt/librenms 已存在（重新安裝），且 lv-rrd 已掛載，必須先卸載再刪除：
#   sudo umount /opt/librenms/rrd
#   sudo rm -rf /opt/librenms
cd /opt
sudo git clone https://github.com/librenms/librenms.git
sudo chown -R librenms:librenms /opt/librenms

# 安裝 PHP 依賴（必須在 /opt/librenms，不能在 /mnt 等 NTFS）
sudo -u librenms bash -c "
    cd /opt/librenms
    composer install --no-dev --no-interaction --no-ansi
"

# 安裝前端依賴
sudo -u librenms bash -c "
    cd /opt/librenms
    npm ci && npm run build
"

# 安裝 Python 模組（Ubuntu 24.04 的 externally-managed 環境需加 --break-system-packages）
sudo pip3 install --break-system-packages -r /opt/librenms/requirements.txt

# 設定 .env
sudo cp /opt/librenms/.env.example /opt/librenms/.env
sudo chown librenms:librenms /opt/librenms/.env
sudo -u librenms bash -c "cd /opt/librenms && php artisan key:generate"

# ⚠️ 寫入 DB 連線設定到 .env
# 注意：現代 LibreNMS 的 .env.example 不含 DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD 行，
# sed 替換會找不到目標 → grep "^DB_" 會空白。直接 append 即可，密碼必須與 §5.4 完全一致。
sudo -u librenms bash -c "
  # 先刪掉可能殘留的 DB_ 行（避免重複寫入）
  sed -i '/^DB_HOST=/d;/^DB_DATABASE=/d;/^DB_USERNAME=/d;/^DB_PASSWORD=/d;/^DB_TIMEZONE=/d' /opt/librenms/.env
  cat >> /opt/librenms/.env << 'INNEREOF'

DB_HOST=localhost
DB_DATABASE=librenms
DB_USERNAME=librenms
DB_PASSWORD=librenms_pwd_2026
DB_TIMEZONE=Asia/Taipei
INNEREOF
"

# 驗證 .env 含 5 行 DB_*
grep "^DB_" /opt/librenms/.env
# 預期輸出：DB_HOST、DB_DATABASE、DB_USERNAME、DB_PASSWORD、DB_TIMEZONE 各一行
```

> ⚠️ **若 migrate 時出現 `Access denied for user 'librenms'@'localhost' (using password: YES)`**：
> 代表 `.env` 的 `DB_PASSWORD` 與 DB 端不一致。用 root 強制重設密碼以校齊：
> ```bash
> sudo mysql -e "ALTER USER 'librenms'@'localhost' IDENTIFIED BY 'librenms_pwd_2026'; FLUSH PRIVILEGES;"
> ```
> 然後重跑 `php artisan migrate --force`。

### 5.6 RRD 目錄掛載（git clone 完成後）

```bash
# git clone 已建立 /opt/librenms，現在可以建立 rrd 子目錄並掛載 lv-rrd
sudo mkdir -p /opt/librenms/rrd
sudo mount /opt/librenms/rrd

# 設定正確權限
sudo chown librenms:librenms /opt/librenms/rrd
sudo chmod 775 /opt/librenms/rrd

# 確認掛載成功
df -hT /opt/librenms/rrd
```

### 5.7 PHP-FPM 設定

> ⚠️ LibreNMS 26.x 已不再提供 `misc/php-fpm-librenms.conf`，需手動建立。

```bash
sudo tee /etc/php/8.3/fpm/pool.d/librenms.conf << 'EOF'
[librenms]
user = librenms
group = librenms
listen = /run/php-fpm-librenms.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 500
chdir = /
EOF

sudo systemctl restart php8.3-fpm

# 確認 socket 已建立
ls -la /run/php-fpm-librenms.sock
```

### 5.8 Nginx 設定（LibreNMS）

> ⚠️ LibreNMS 26.x 已不再提供 `misc/nginx.conf`，需手動建立。
> 另外 Ubuntu 24.04 的 nginx 預設啟用 `/etc/nginx/sites-enabled/default`（Welcome 頁），
> 必須移除，否則 `/` 路由會被它攔截，顯示 nginx 預設頁而非 LibreNMS。

```bash
# 建立 LibreNMS nginx 設定（手動）
sudo tee /etc/nginx/conf.d/librenms.conf << 'EOF'
server {
    listen      80;
    server_name localhost;
    root        /opt/librenms/html;
    index       index.php;
    charset utf-8;
    gzip on;
    gzip_types text/css application/javascript text/javascript application/x-javascript image/svg+xml text/plain text/xsd text/xsl text/xml image/x-icon;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ [^/]\.php(/|$) {
        fastcgi_pass  unix:/run/php-fpm-librenms.sock;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include        fastcgi.conf;
    }
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

# 移除 nginx 預設頁（否則會覆蓋 LibreNMS 路由）
sudo rm -f /etc/nginx/sites-enabled/default

sudo nginx -t && sudo systemctl reload nginx
```

### 5.9 資料庫初始化

```bash
sudo -u librenms bash -c "cd /opt/librenms && php artisan migrate --force"

# 建立管理員帳號
sudo -u librenms bash -c "cd /opt/librenms && php lnms user:add \
    -r admin -p 'Systex@LibreNMS2026!' admin"
```

> ⚠️ **不要執行** `php artisan db:seed --force`，新版 LibreNMS 已移除此指令，執行會報錯。

### 5.10 排程器與 Cron

> ⚠️ LibreNMS 26.x 的 scheduler service/timer 在 `dist/`，不在 `misc/`。
> `misc/librenms.cron` 也已不存在，需改用 user crontab（個人 crontab 方式）。
>
> **為什麼不用 `/etc/cron.d/librenms`**：LibreNMS `validate.php` 的 Cron 驗證有已知 Bug，
> 其 regex 無 multiline flag，若 `/etc/cron.d/` 目錄下有其他按字母排在前面的 cron 檔
>（如 `e2scrub_all`），驗證會永遠失敗。改用 librenms user 的個人 crontab 可避開此問題。

```bash
# Scheduler service/timer（在 dist/，不在 misc/）
sudo cp /opt/librenms/dist/librenms-scheduler.service \
       /opt/librenms/dist/librenms-scheduler.timer \
       /etc/systemd/system/

sudo systemctl daemon-reload
sudo systemctl enable --now librenms-scheduler.timer

# Cron：改用 librenms user 個人 crontab（繞過 validate.php regex bug）
sudo crontab -u librenms - << 'EOF'
*/5  * * * *   /opt/librenms/poller-wrapper.py 16 >> /dev/null 2>&1
15   0 * * *   /opt/librenms/daily.php >> /dev/null 2>&1
*    * * * *   /opt/librenms/alerts.php >> /dev/null 2>&1
EOF

# 確認 crontab 已寫入
sudo crontab -u librenms -l

# SNMP Community 設定
sudo -u librenms bash -c "cd /opt/librenms && \
    php lnms config:set snmp.community '[\"librenms_snmp\"]'"
# 註：LibreNMS 26.x 已移除 --json flag，JSON 字串直接傳入即可。
```

### 5.11 收尾設定

```bash
# PHP 時區設定（系統用 Asia/Taipei，PHP 必須一致，否則 validate.php 警告）
sudo sed -i 's|^;*date.timezone.*|date.timezone = Asia/Taipei|' /etc/php/8.3/cli/php.ini
sudo sed -i 's|^;*date.timezone.*|date.timezone = Asia/Taipei|' /etc/php/8.3/fpm/php.ini
sudo systemctl restart php8.3-fpm

# lnms 全域 symlink（可從任何目錄執行 lnms 指令）
sudo ln -sf /opt/librenms/lnms /usr/local/bin/lnms

# Bash 補全（可選）
sudo cp /opt/librenms/misc/lnms-completion.bash /etc/bash_completion.d/

# 其他 ACL / log 目錄設定
sudo setfacl -d -m g::rwx /opt/librenms/rrd \
    /opt/librenms/logs \
    /opt/librenms/bootstrap/cache/ \
    /opt/librenms/storage/
sudo setfacl -R -m g::rwx /opt/librenms/rrd \
    /opt/librenms/logs \
    /opt/librenms/bootstrap/cache/ \
    /opt/librenms/storage/
```

### 5.12 設定 snmpd（讓 LibreNMS 可監控本機）

> ⚠️ **這一步不可省略**。若沒裝 snmpd 或 community 設定錯誤，
> Web UI Add Device `localhost` 會回 `SNMP v2c: No reply with community ...`。

```bash
# 1. snmpd 通常已隨 §5.1 一併裝好，確認狀態
systemctl status snmpd --no-pager | head -3

# 2. 用 LibreNMS 提供的範本（含 distro 偵測等）
sudo cp /opt/librenms/snmpd.conf.example /etc/snmp/snmpd.conf

# 3. 改 community（範本預設值是 RANDOMSTRINGGOESHERE）
sudo sed -i 's/RANDOMSTRINGGOESHERE/librenms_snmp/' /etc/snmp/snmpd.conf

# 4. 安裝 distro 偵測腳本（LibreNMS 範本會呼叫 /usr/bin/distro）
sudo curl -o /usr/bin/distro \
    https://raw.githubusercontent.com/librenms/librenms-agent/master/snmp/distro
sudo chmod +x /usr/bin/distro

# 5. 啟動並設為開機自啟
sudo systemctl enable --now snmpd
sudo systemctl restart snmpd

# 6. 本機驗證 SNMP 可讀（用 numeric OID 避免 client MIB 缺失）
snmpwalk -v2c -c librenms_snmp localhost .1.3.6.1.2.1.1.1.0
# 預期：iso.3.6.1.2.1.1.1.0 = STRING: "Linux monitor-vm ..."
```

> 💡 若 `snmpwalk` 用 `sysDescr.0` 報 `Unknown Object Identifier`，
> 是 snmpwalk client 端的 MIB 檔未安裝（與 snmpd server 無關）。可選補裝：
> ```bash
> sudo apt install -y snmp-mibs-downloader
> sudo sed -i 's/^mibs :/# mibs :/' /etc/snmp/snmp.conf
> ```

### 5.13 SNMP Community 設定（LibreNMS 端）

`snmp.community` 是 LibreNMS 嘗試的 community **清單**（依序測試）。新增設備或 `snmp-scan.py` 掃描網段時，會逐一試這些字串，第一個成功的就用。

```bash
# 自家設備統一用 librenms_snmp
sudo -u librenms bash -c "cd /opt/librenms && \
    php lnms config:set snmp.community '[\"librenms_snmp\"]'"
# 註：LibreNMS 26.x 已移除 --json flag，JSON 字串直接傳入即可。

# 驗證
sudo -u librenms /opt/librenms/lnms config:get snmp.community
```

> ⚠️ **掃描網段的特殊情境**：若要用 `snmp-scan.py` 掃 `/24` 找出尚未改過 community 的設備，
> 可暫時把 `public` 加入清單（多數設備預設值）：
> ```bash
> sudo -u librenms /opt/librenms/lnms config:set snmp.community '["librenms_snmp","public"]'
> ```
> 找到設備後，要求網管把該設備 community 改成 `librenms_snmp`，
> 最後從清單移除 `public`（`public` 是攻擊者第一個會試的字串，不應留在 production）：
> ```bash
> sudo -u librenms /opt/librenms/lnms config:set snmp.community '["librenms_snmp"]'
> ```

### 5.13a 自動掃描網段內 SNMP 設備（snmp-scan.py）

```bash
# 1. dry-run（只列出會嘗試的 IP，不真的加）
sudo -u librenms /opt/librenms/snmp-scan.py -l 172.16.1.0/24

# 2. 正式掃描（網段當位置參數，不需要先設 nets）
sudo -u librenms /opt/librenms/snmp-scan.py 172.16.1.0/24

# 3. 多 thread 加速（預設 8，/24 建議 32）
sudo -u librenms /opt/librenms/snmp-scan.py --threads 32 172.16.1.0/24

# 4. 列出已加入的設備
sudo -u librenms /opt/librenms/lnms device:list
```

> 💡 **`nets` 不是 LibreNMS config 白名單欄位**，不能用 `lnms config:set nets.0` 設。
> 直接把網段當 `snmp-scan.py` 的位置參數最簡單。

> 💡 **鄰居自動發現**（已有設備後，靠 CDP/LLDP/OSPF 擴展）：
> ```bash
> sudo -u librenms /opt/librenms/lnms config:set autodiscovery.xdp true       # CDP/LLDP
> sudo -u librenms /opt/librenms/lnms config:set autodiscovery.ospf true      # OSPF 鄰居
> sudo -u librenms /opt/librenms/lnms config:set autodiscovery.nets-exclude.0 '172.16.1.1'  # 排除特定 IP
> ```
> 注意：現代 LibreNMS **沒有** `autodiscovery.arp` 或 `autodiscovery.snmpscan` 設定鍵。

### 5.14 驗證 LibreNMS

```bash
sudo -u librenms bash -c "cd /opt/librenms && php validate.php"
# 開啟瀏覽器：http://<VM_IP>/
# 帳號：admin / Systex@LibreNMS2026!
```

> **驗證結果說明**：
> - `[OK]` — 正常
> - `[WARN] You have no devices` — 尚未加入設備，加入 localhost 後消失（Web UI `/addhost`，hostname `127.0.0.1`，community `librenms_snmp`）
> - `[WARN] git modified files` — npm build 產生的 `html/build/` 異動，無害。可選清理：`sudo -u librenms git -C /opt/librenms checkout -- html/build/`
> - `[OK] Redis is unavailable` — 文字看似不對，實際代表「沒裝 Redis 但不影響」，單機部署不需要
> - `[FAIL]` — 需要修正，參見 Section 12 疑難排解

---

## 6. 安裝 Graylog（含 Datanode + MongoDB）

> **Graylog 7.x 架構變更**：7.x 完全移除對外部獨立 OpenSearch 的支援，改用
> `graylog-datanode` 服務自行管理 OpenSearch 叢集。
> **不需要也不應該單獨安裝 OpenSearch。**
> `lv-opensearch`（`/var/lib/opensearch`）LVM 分割區沿用作為 Datanode 的 OpenSearch 資料目錄。

### 6.1 安裝 Java 21（Graylog Datanode 需要）

```bash
sudo apt-get install -y openjdk-21-jdk
java -version  # 確認 openjdk 21
```

### 6.2 安裝 MongoDB 7.0（Graylog 設定資料庫）

```bash
curl -fsSL https://www.mongodb.org/static/pgp/server-7.0.asc | \
    sudo gpg -o /etc/apt/trusted.gpg.d/mongodb.gpg --dearmor

echo "deb [ arch=amd64,arm64 ] https://repo.mongodb.org/apt/ubuntu jammy/mongodb-org/7.0 multiverse" | \
    sudo tee /etc/apt/sources.list.d/mongodb-org-7.0.list
# 注意：Ubuntu 24.04 用 jammy（22.04）的 repo，MongoDB 7.0 相容

sudo apt-get update
sudo apt-get install -y mongodb-org

sudo chown -R mongodb:mongodb /var/lib/mongodb
sudo systemctl enable --now mongod

# 確認 MongoDB 在 27017 監聽
ss -tlnp | grep 27017
```

### 6.3 安裝 graylog-server + graylog-datanode

> ⚠️ **URL 格式**：Graylog 7.x 的 repo package 需要完整小版本號（如 `7.1`），
> 不能用 `7.x`（會 404）。安裝時需同時安裝 `graylog-server` 和 `graylog-datanode`。

```bash
# 加入 Graylog 7.1 repo（確認 7.1 是當前最新，或至官網查最新小版本號）
wget https://packages.graylog2.org/repo/packages/graylog-7.1-repository_latest.deb
sudo dpkg -i graylog-7.1-repository_latest.deb
sudo apt-get update

# 同時安裝 graylog-server 和 graylog-datanode
sudo apt-get install -y graylog-server graylog-datanode
```

### 6.4 設定 graylog-datanode

> ⚠️ `/var/lib/opensearch` 目錄擁有者**必須**是 `graylog-datanode`（不是 `opensearch`）。
> 錯誤擁有者會導致 `Cannot read from directory /var/lib/opensearch` 啟動失敗。

```bash
# 設定目錄擁有者（關鍵步驟）
sudo chown -R graylog-datanode:graylog-datanode /var/lib/opensearch
sudo chmod 755 /var/lib/opensearch

# 產生共用密鑰（datanode.conf 與 server.conf 必須使用完全相同的值）
PASSWORD_SECRET=$(tr -dc A-Za-z0-9 < /dev/urandom | head -c 96)
ROOT_PASSWORD_SHA2=$(echo -n "Graylog@2026!" | sha256sum | awk '{print $1}')

# 儲存密鑰供後續 server.conf 使用
sudo tee /root/.graylog-secrets << EOF
PASSWORD_SECRET=${PASSWORD_SECRET}
ROOT_PASSWORD_SHA2=${ROOT_PASSWORD_SHA2}
EOF
sudo chmod 600 /root/.graylog-secrets

# 設定 datanode.conf
sudo tee /etc/graylog/datanode/datanode.conf << EOF
node_id_file = /etc/graylog/datanode/node-id
config_location = /etc/graylog/datanode

# 必須與 server.conf 的值完全一致
password_secret = ${PASSWORD_SECRET}
root_password_sha2 = ${ROOT_PASSWORD_SHA2}

mongodb_uri = mongodb://127.0.0.1/graylog
opensearch_location = /usr/share/graylog-datanode/dist
opensearch_config_location = /var/lib/graylog-datanode/opensearch/config
opensearch_data_location = /var/lib/opensearch
opensearch_logs_location = /var/log/graylog-datanode/opensearch
EOF

sudo systemctl enable graylog-datanode
```

### 6.5 設定 graylog-server

> ⚠️ **Graylog 7.x 三個重要變更**：
> - `data_dir` 為 7.x **新增必填**，缺少會立即啟動失敗（`Required parameter "data_dir" not found`）
> - `plugin_dir` 必須明確指定為 `/usr/share/graylog-server/plugin`；
>   預設值為 `/plugin`（不存在），導致儲存後端 plugin 無法載入 → Guice MissingImplementation
> - **不可設定** `elasticsearch_hosts`：7.x 透過 MongoDB 自動發現 Datanode；
>   若保留此參數會觸發額外的 Guice 錯誤

```bash
# 建立 data_dir（7.x 必填）
sudo mkdir -p /var/lib/graylog-server
sudo chown graylog:graylog /var/lib/graylog-server

# 讀取先前儲存的密鑰
source /root/.graylog-secrets

# 寫入 server.conf
sudo tee /etc/graylog/server/server.conf << EOF
password_secret = ${PASSWORD_SECRET}
root_username = admin
root_password_sha2 = ${ROOT_PASSWORD_SHA2}
root_timezone = Asia/Taipei

# Graylog 7.x 新增必填
data_dir = /var/lib/graylog-server

# 7.x 預設 plugin_dir=/plugin（不存在），必須明確指定正確路徑
# 否則 graylog-storage-opensearch2.jar 等不會載入 → Guice MissingImplementation
plugin_dir = /usr/share/graylog-server/plugin

# HTTP
http_bind_address = 0.0.0.0:9000
http_publish_uri = http://$(hostname -I | awk '{print $1}'):9000/

# MongoDB（7.x 透過 MongoDB 自動發現 Datanode，不需也不能設定 elasticsearch_hosts）
mongodb_uri = mongodb://127.0.0.1:27017/graylog
EOF

sudo systemctl enable graylog-server
```

### 6.6 啟動順序（Datanode 必須先啟）

> ⚠️ graylog-datanode 需要約 60–90 秒初始化內嵌的 OpenSearch 叢集，
> **graylog-server 必須等 Datanode 完全就緒後才能啟動**，否則無法找到 OpenSearch。

```bash
# 步驟 1：啟動 graylog-datanode
sudo systemctl start graylog-datanode

# 等待 90 秒（OpenSearch 冷啟動需要時間）
echo "等待 Datanode 初始化 OpenSearch（90 秒）..."
sleep 90

# 確認 Datanode 已就緒
systemctl status graylog-datanode --no-pager | grep "Active:"
sudo journalctl -u graylog-datanode --no-pager | grep -E "started|running|ERROR" | tail -10

# 步驟 2：啟動 graylog-server
sudo systemctl start graylog-server

# 等待 30 秒後查看啟動狀態
sleep 30
sudo tail -20 /var/log/graylog-server/server.log
```

### 6.7 首次 Setup Wizard（Datanode 模式）

Graylog 7.x 第一次啟動會進入 **preflight mode**，顯示 Setup Wizard。

> ⚠️ **Graylog 7.x Wizard 與舊版不同**：Wizard 的所有 API 呼叫都需要 OTP 認證；
> 未認證時 data nodes 列表為空、CA/renewal 回 401，**不要誤判為 Datanode 未啟動**。

```bash
# 取得一次性密碼（OTP）和認證 URL
sudo grep -A 2 "Initial configuration is accessible" /var/log/graylog-server/server.log | tail -5
# 輸出範例：
#   Initial configuration is accessible at 0.0.0.0:9000, with username 'admin' and password 'bDsAZZTeky'.
#   Try clicking on http://admin:bDsAZZTeky@0.0.0.0:9000

# 若找不到（重啟後仍在 MongoDB）
mongosh --quiet --eval 'db = db.getSiblingDB("graylog"); printjson(db.preflight.find({type:"preflight_password"}).toArray())'
```

**完成 Setup Wizard 步驟：**

1. **用 OTP 認證開啟 Wizard**（把 `0.0.0.0` 換成實際 IP）：
   ```
   http://admin:<OTP>@<VM_IP>:9000
   ```
   認證後 Wizard 會顯示 data nodes（`monitor-vm` 應出現），且 CA 相關選項可用。

2. **Step 1 — Configure Certificate Authority**：點 "Configure CA" 建立 CA

3. **Step 2 — Renewal Policy**：選預設（90 天自動更新）

4. **Step 3 — Provision certificates**：等 `monitor-vm` datanode 出現後點 "Provision" 簽發憑證

5. **Step 4 — Resume Startup**：點 "Resume startup"，server 切換至 full mode

6. Wizard 完成後，以 `admin` / `Graylog@2026!` 重新登入

### 6.8 驗證 Graylog + Datanode

```bash
# 兩個服務均 active (running)
systemctl status graylog-datanode graylog-server --no-pager | grep "Active:"

# 確認 9000 port 監聽
ss -tlnp | grep 9000

# 確認 API 可用（Setup Wizard 完成後）
curl -s -u admin:Graylog@2026! http://localhost:9000/api/system/lbstatus
# 應回傳 {"status":"alive"}
```

### 6.9 設定 Graylog Input（接收 Syslog）

在 Graylog Web UI 操作：
1. **System → Inputs**
2. 選 **Syslog UDP** → Launch new input
   - Title: `Syslog UDP 514`
   - Port: `514`
   - Bind address: `0.0.0.0`
3. 選 **GELF UDP** → Launch new input
   - Title: `GELF UDP`
   - Port: `12201`

```bash
# 讓 Graylog 監聽 <1024 port（514 需要特殊權限）
sudo setcap 'cap_net_bind_service=+ep' /usr/share/graylog-server/bin/graylog-server
```

### 6.10 清空 Graylog 歷史資料（重新佈署 / 搬遷至新端點時）

> 適用情境：舊 VM 繼續沿用但需清除歷史 log；或搬遷後想重置資料。
> **新裝 VM 不需要此步驟**（全新安裝本身就是空的）。

#### 方式 1：Web UI 刪除 Index（推薦）

1. 登入 Graylog Web UI → **System → Indices**
2. 每個 Index Set 右側點 **Maintenance** 下拉
3. 選 **`Delete all indices in this set`**
4. 操作完成後 Graylog 自動建立新的空 index，繼續正常接收資料

> Stream 規則、Dashboard、Alert 等設定**不受影響**，只清資料。

#### 方式 2：REST API（腳本化）

```bash
# 查詢所有 index set ID
curl -s -u admin:Graylog@2026! http://localhost:9000/api/system/indices/index_sets | python3 -m json.tool

# 刪除指定 index set 的所有 indices（替換 <ID>）
curl -u admin:Graylog@2026! -X DELETE \
  "http://localhost:9000/api/system/indices/index_sets/<ID>/indices"
```

#### 方式 3：完整重置（連設定一起清，慎用）

```bash
sudo systemctl stop graylog-server graylog-datanode

# 清 OpenSearch 資料（歷史 log）
curl -X DELETE http://localhost:9200/graylog*
curl -X DELETE http://localhost:9200/.graylog*

# 清 MongoDB（Stream / Dashboard / Alert 設定全刪）
mongosh graylog --eval "db.dropDatabase()"

sudo systemctl start graylog-datanode
# 等 90 秒讓 Datanode 就緒後再啟 server
sudo systemctl start graylog-server
```

---

## 7. 安裝 jt-ipam（含 PostgreSQL + Redis）

> jt-ipam 使用**官方一鍵安裝腳本** `scripts/jt-ipam.sh`，會自動安裝並設定
> PostgreSQL 16 + pgvector、Redis、Python venv、前端 build、systemd 服務、nginx vhost。
> **不要自行手動 pip install / 寫 systemd unit**，官方腳本已全部涵蓋。
> jt-ipam 後端是 **FastAPI**（非 Django），沒有 `manage.py`。

### 🚨 7.0 致命陷阱：絕不可把腳本單獨複製到 /tmp 執行（會摧毀整台系統）

`jt-ipam.sh` 內部用以下方式推導專案根目錄：

```bash
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
```

接著執行 `chown -R jtipam:jtipam "$REPO_ROOT"`（腳本約 line 287）。

若把腳本單獨下載到 `/tmp/jt-ipam.sh` 執行，`dirname=/tmp`，往上一層 `..` = **`/`**，
於是 `REPO_ROOT="/"`，腳本會執行 **`chown -R jtipam /`**：

- 整個檔案系統（`/etc /usr /var /home /bin /lib /boot /opt …`）擁有者全變 `jtipam`
- **所有系統 binary 的 setuid bit 被清除**（`sudo`、`su`、`passwd`、`mount` 全失效）
- PostgreSQL、SSL、cron、sshd 等對 owner 敏感的服務逐一崩潰
- **系統等同報廢，唯一可靠修復是重裝 OS**（2026-06-14 實機已踩此坑，整台 172.16.1.94 重裝）

✅ **唯一正確做法**：先 clone 完整 repo，從 repo 內的 `scripts/` 路徑執行，
這樣 `REPO_ROOT` 才會正確解析為 `/opt/jt-ipam`：

```bash
sudo git clone https://github.com/jasoncheng7115/jt-ipam.git /opt/jt-ipam
# 之後一律用完整路徑 /opt/jt-ipam/scripts/jt-ipam.sh，不要 cp 到別處
```

### 7.1 先手動安裝 pgvector（繞過腳本誤判）

Ubuntu 24.04（noble）的 **PGDG repo 沒有** `postgresql-16-pgvector`，
但 **Ubuntu 官方 repo 有**。必須在跑安裝腳本前先手動裝好：

```bash
sudo apt-get update
sudo apt-get install -y postgresql-16-pgvector
```

> jt-ipam.sh 用 `apt-cache madison`（檢查 repo 是否「提供」此套件）而非 `dpkg -l`（檢查是否「已安裝」）
> 來判斷 pgvector。因 PGDG repo 不提供，腳本會誤判失敗 ——
> 即使已從 Ubuntu repo 裝好仍然 `FATAL: pgvector ... not installable`。需 patch（見 7.2）。

### 7.2 Patch jt-ipam.sh 的 pgvector 檢查

```bash
# 確認 die 那行的行號（約 line 250）
grep -n "pgvector for PostgreSQL" /opt/jt-ipam/scripts/jt-ipam.sh

# 套用 patch：若 dpkg 顯示已安裝就跳過 die
python3 << 'PYEOF'
with open('/opt/jt-ipam/scripts/jt-ipam.sh', 'r') as f:
    content = f.read()
old = '            die "pgvector for PostgreSQL $PG_VER not installable (package postgresql-$PG_VER-pgvector). Install it manually, then re-run install."'
new = '''            dpkg -l "postgresql-${PG_VER}-pgvector" 2>/dev/null | grep -q '^ii' \\
              && { log "postgresql-${PG_VER}-pgvector already installed; skipping"; PG_PKGS+=("postgresql-${PG_VER}-pgvector"); } \\
              || die "pgvector for PostgreSQL $PG_VER not installable (package postgresql-$PG_VER-pgvector). Install it manually, then re-run install."'''
if old in content:
    content = content.replace(old, new)
    with open('/opt/jt-ipam/scripts/jt-ipam.sh', 'w') as f:
        f.write(content)
    print("✅ patch 成功")
else:
    print("❌ 找不到目標字串，請確認腳本版本（可能官方已修正）")
PYEOF
```

### 7.3 執行安裝（務必用完整 repo 路徑）

```bash
# self-signed TLS，以 VM IP（或域名）作為 FQDN
sudo bash /opt/jt-ipam/scripts/jt-ipam.sh install \
    --tls-mode self-signed \
    --public-fqdn 172.16.1.94 2>&1 | grep -v "chown: changing ownership of '/proc"

# 說明：
#  - 一律用 /opt/jt-ipam/scripts/jt-ipam.sh 完整路徑（見 7.0）
#  - --public-fqdn 換成實際 IP 或域名
#  - grep -v 過濾掉腳本遞迴 chown /opt/jt-ipam 時碰到 /proc 的無害錯誤噪音
#  - 參數是 --public-fqdn（不是 --fqdn）；--tls-mode 可選 self-signed / letsencrypt / none
```

腳本完成後會建立：

| 項目 | 值 |
|------|-----|
| 系統使用者 | `jtipam`（home `/var/lib/jt-ipam`）|
| PostgreSQL 資料庫 | `jt_ipam` |
| systemd 服務 | `jt-ipam-backend.service`、`jt-ipam-sync.timer`、`jt-ipam-backup.timer` |
| nginx vhost | self-signed TLS（見 7.4 衝突處理）|
| 管理員初始密碼 | `/etc/jt-ipam/.admin-initial-password` |

### 7.4 修復 nginx port 80 衝突（與 LibreNMS）

jt-ipam 的 nginx vhost 預設使用 `listen 80 default_server`，會與 LibreNMS 的 port 80
衝突（同一 port 出現兩個 `default_server` 會讓 `nginx -t` 失敗，或 jt-ipam 搶走 `/` 根路由）。

```bash
# 找出含 default_server 的 jt-ipam vhost 檔（位置依腳本版本可能在 sites-available 或 conf.d）
grep -rl "default_server" /etc/nginx/sites-available/ /etc/nginx/conf.d/ 2>/dev/null

# 移除 jt-ipam vhost 的 default_server（檔名依上一行輸出調整）
sudo sed -i 's/listen 80 default_server;/listen 80;/' /etc/nginx/sites-available/jt-ipam
sudo sed -i 's/listen \[::\]:80 default_server;/listen [::]:80;/' /etc/nginx/sites-available/jt-ipam

sudo nginx -t && sudo systemctl reload nginx
```

### 7.5 取得管理員初始密碼並驗證

```bash
sudo cat /etc/jt-ipam/.admin-initial-password
sudo systemctl status jt-ipam-backend --no-pager | grep "Active:"
sudo systemctl is-active jt-ipam-backend postgresql redis-server
```

---

## 8. Nginx 統一設定

### 8.1 服務反向代理架構

```
外部請求
  :80  /          → LibreNMS（PHP-FPM）
  :9000            → Graylog（直接 bind，無需 nginx 代理）
  :443 (+ :80)     → jt-ipam（官方腳本自建 vhost，self-signed TLS，反向代理後端）
```

### 8.2 jt-ipam Nginx 設定（由官方腳本自動建立）

> jt-ipam 的 nginx vhost 由 `scripts/jt-ipam.sh` 在 §7.3 安裝時**自動建立**，
> 監聽 443（self-signed TLS）與 80。**不需手動建立 conf**。
> 唯一需要的後處理是移除其 port 80 的 `default_server`，避免與 LibreNMS 衝突 ——
> 詳見 [§7.4](#74-修復-nginx-port-80-衝突與-librenms)。

```bash
# 確認 jt-ipam vhost 已存在並通過語法檢查
grep -rl "jt-ipam\|jtipam" /etc/nginx/sites-available/ /etc/nginx/conf.d/ 2>/dev/null
sudo nginx -t
```

> 若需要把 jt-ipam 改成獨立 port（例如 8080）而非搶 80/443，可在其 vhost 內調整 `listen`，
> 但預設 self-signed TLS 流程已足夠內網使用。

### 8.3 （可選）SSL/TLS 設定

```bash
# 安裝 certbot（若有公開域名）
sudo apt-get install -y certbot python3-certbot-nginx
# sudo certbot --nginx -d your-domain.com

# 若使用自簽憑證（內網）
sudo openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /etc/ssl/private/monitor.key \
    -out /etc/ssl/certs/monitor.crt \
    -subj "/CN=monitor-vm/O=Internal"
```

---

## 9. 三者整合

### 9.1 LibreNMS → 產生 API Token

```
LibreNMS Web UI → 右上角頭像 → API Access → Add API Token
→ 描述：jt-ipam-integration → 複製 Token
```

```bash
# 儲存 token（之後 jt-ipam .env 會用到）
LNMS_TOKEN="<貼上 token>"
```

### 9.2 在 jt-ipam 設定 LibreNMS 整合

jt-ipam 的整合設定建議在其 **Web UI → 設定 → 整合** 填入 LibreNMS URL 與 API Token
（官方腳本管理設定，不一定有可直接 sed 的 `.env`）。

```bash
# 若該版本確實使用 /etc/jt-ipam 下的設定檔，可先確認設定位置與鍵名
sudo ls -la /etc/jt-ipam/
sudo grep -ril "librenms" /etc/jt-ipam/ 2>/dev/null

# 修改設定後重啟後端
sudo systemctl restart jt-ipam-backend
```

> LibreNMS 整合需要的 token 即 §9.1 產生的 `LNMS_TOKEN`；URL 用 `http://127.0.0.1`。

### 9.3 LibreNMS 設定 Graylog 整合

```bash
# 在 LibreNMS 設定 Graylog 連線（讓設備頁顯示 Graylog log）
sudo -u librenms bash -c "cd /opt/librenms && \
    php lnms config:set graylog.server 'http://127.0.0.1' && \
    php lnms config:set graylog.port 9000 && \
    php lnms config:set graylog.username 'admin' && \
    php lnms config:set graylog.password 'Graylog@2026!' && \
    php lnms config:set graylog.version '2.1' && \
    php lnms config:set graylog.query.field 'source'"
```

> ⚠️ **`graylog.version` 不是 Graylog 產品版本**，而是 LibreNMS 內建 Graylog API
> 客戶端版本。`config_definitions.json` 只接受三個值：
> - `"2.0"` — Graylog **2.0 以下**（極舊版本，幾乎不用）
> - `"2.1"` — **Graylog 2.1 及以後**（包含 3.x / 4.x / 5.x / 6.x / 7.x），新部署都選這個
> - `"other"` — 自訂
>
> 寫成 `"7.0"`、`"1"` 等都會被拒絕並回 `X is not an allowed value`。

### 9.4 設定網路設備發送 Syslog 到本機

在每台被監控設備設定：
```
logging host <VM_IP>
logging trap informational     # Cisco IOS 語法
# 或
set system syslog host <VM_IP> port 514 any any  # Junos 語法
```

### 9.5 LibreNMS 加入本機監控

```bash
# 加入 localhost 設備
sudo -u librenms bash -c "cd /opt/librenms && \
    php lnms device:add \
    --v2c \
    --community librenms_snmp \
    --force \
    localhost"

# 執行首次 discovery
sudo -u librenms bash -c "cd /opt/librenms && \
    php discovery.php -h localhost"
```

---

## 10. 備份策略

### 10.1 每日備份腳本

```bash
sudo tee /usr/local/bin/backup-monitor.sh << 'SCRIPT'
#!/bin/bash
set -e
DATE=$(date +%Y%m%d)
BACKUP_DIR="/backup/${DATE}"
mkdir -p "${BACKUP_DIR}"

# LibreNMS MariaDB
mysqldump -u librenms -plibrenms_pwd_2026 \
    --single-transaction --quick \
    librenms | gzip > "${BACKUP_DIR}/librenms-db.sql.gz"

# LibreNMS 設定
tar czf "${BACKUP_DIR}/librenms-config.tar.gz" \
    /opt/librenms/.env \
    /opt/librenms/config.php 2>/dev/null || true

# jt-ipam PostgreSQL
sudo -u postgres pg_dump jtipam | \
    gzip > "${BACKUP_DIR}/jtipam-db.sql.gz"

# Graylog MongoDB
mongodump --db graylog \
    --out "${BACKUP_DIR}/graylog-mongo"
tar czf "${BACKUP_DIR}/graylog-mongo.tar.gz" \
    "${BACKUP_DIR}/graylog-mongo"
rm -rf "${BACKUP_DIR}/graylog-mongo"

# Graylog 設定
tar czf "${BACKUP_DIR}/graylog-config.tar.gz" \
    /etc/graylog/server/server.conf

# 清理 14 天前的備份
find /backup -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +

echo "[${DATE}] 備份完成：$(du -sh ${BACKUP_DIR})"
SCRIPT

sudo chmod +x /usr/local/bin/backup-monitor.sh

# Cron：每天凌晨 2:00
echo "0 2 * * * root /usr/local/bin/backup-monitor.sh >> /var/log/backup.log 2>&1" | \
    sudo tee /etc/cron.d/monitor-backup
```

> **注意**：RRD 資料（`/opt/librenms/rrd`）是每 5 分鐘輪詢產生的 Binary 格式，
> 大量設備時備份成本高，建議改用 VM 快照備份，或只備份近期 RRD。

---

## 11. 驗證檢查清單

```bash
# === 服務狀態 ===
systemctl status nginx mariadb php8.3-fpm \
    graylog-datanode mongod graylog-server \
    postgresql redis-server jt-ipam-backend \
    librenms-scheduler.timer

# === 埠號確認 ===
ss -tlnp | grep -E ':80|:443|:9000|:27017|:3306|:5432|:6379'

# === LibreNMS ===
sudo -u librenms bash -c "cd /opt/librenms && php validate.php"
curl -s http://localhost/ | grep -i librenms

# === Graylog ===
curl -s -u admin:Graylog@2026! http://localhost:9000/api/system/lbstatus
# 應回傳 {"status":"alive"}

# === Graylog Datanode（OpenSearch 健康狀態）===
curl -s -u admin:Graylog@2026! http://localhost:9000/api/datanode/nodes | python3 -m json.tool
# 應看到 Datanode node 資訊，status 為 AVAILABLE

# === jt-ipam ===
sudo systemctl is-active jt-ipam-backend
# self-signed TLS，用 -k 略過憑證驗證；路徑/port 以實際 nginx vhost 為準
curl -sk https://localhost/ -o /dev/null -w "jt-ipam HTTP %{http_code}\n"

# === LibreNMS Graylog 整合 ===
sudo -u librenms bash -c "cd /opt/librenms && \
    php artisan tinker --execute='echo app(App\ApiClients\GraylogApi::class)->isConfigured();'"
# 應輸出 1
```

---

## 12. 疑難排解

### FortiGate SNMP 故障排除順序（device 圖表無資料）

**徵兆**：LibreNMS device 詳情頁顯示「Last Discovered 剛剛 + Downtime 數天」+ ICMP availability 圖在跳但 CPU/RAM/Traffic 圖表全空。RRD 檔（如 `fortigate_cpu.rrd`）的 mtime 停在某個過去時間點。

**從 monitor-vm 端**：

```bash
# 必須先 cd 到 /opt/librenms，否則 librenms user 無法在 /home/<sudo-user> 下 spawn process
cd /opt/librenms

# 1. 確認 ICMP 通（區分 SNMP 問題 vs 設備掛掉）
ping -c 3 <FORTIGATE_IP>

# 2. SNMP 直接測試（用 numeric OID 避開 client MIB 缺失問題）
sudo -u librenms snmpget -v3 -l authPriv \
    -u librenms -a SHA-256 -A librenms \
    -x AES-256 -X librenms \
    -t 5 -r 1 \
    <FORTIGATE_IP> .1.3.6.1.2.1.1.3.0

# 3. UDP 161 是否可達（區分網路 vs FortiGate 內部）
sudo nmap -sU -p 161 -Pn <FORTIGATE_IP>
# open|filtered = FortiGate 收到但不回（典型 SNMPv3 認證/ACL 問題）
```

**從 FortiGate CLI 端**：

```
# 1. 確認 SNMP daemon 開著
get system snmp sysinfo
# 預期：status : enable

# 2. 確認 user 名稱與設定（注意大小寫敏感！）
show system snmp user

# 3. 抓封包確認 FortiGate 是否收到 / 回應
diagnose sniffer packet any 'host <MONITOR_VM_IP> and port 161' 4 0 30
# 預期成功時：in <monitor>.xxx -> 161 + out 161 -> <monitor>.xxx
# 若只有 in 沒有 out → FortiGate silently drop（往下查）
```

**故障排除順序（從外到內，命中率由高到低）**：

| 層級 | 檢查項 | 失效徵兆 | 修法 |
|------|--------|---------|------|
| **L1. Interface Admin Access**（最常見） | Web UI → Network → Interfaces → 該 vlan → **Administrative Access 勾 SNMP** | 封包進來但 FortiGate 不回（sniffer 只看到 in） | 勾 SNMP → OK → Apply |
| **L2. SNMP Agent enable** | System → SNMP → SNMP Agent toggle | 同上 | 開 toggle |
| **L3. SNMPv3 user 存在 + 大小寫一致** | `show system snmp user` 對照 LibreNMS device 設定 | 同上（SNMPv3 unknown user 靜默丟） | 改成完全一致（user name case-sensitive） |
| **L4. Auth/Priv 密碼明文一致** | Edit user → 重設兩個密碼 | 同上（auth failed 靜默丟） | 設成跟 LibreNMS `-A xxx -X xxx` 完全一致 |
| **L5. Hosts / notify-hosts ACL** | Edit user → Hosts 欄位 | 同上 | 加入 monitor-vm IP（如 172.16.1.94） |

⚠️ **L1 是最容易忽略也最關鍵**——SNMP daemon 設定全對、user/密碼/ACL 都對，但 interface 的 Administrative Access 沒勾 SNMP，FortiGate 就在入口層級 silently drop。沒勾 SNMP 上面 L2-L5 全做也沒用。

**修完後在 monitor-vm 強制 poll 一次寫入 RRD**：

```bash
sudo -u librenms /opt/librenms/lnms device:poll <FORTIGATE_IP>
ls -la /opt/librenms/rrd/<FORTIGATE_IP>/fortigate_cpu.rrd
# mtime 應變成「剛剛」，不再停留在故障當日
```

幾分鐘後 LibreNMS device 詳情頁 CPU/RAM/Traffic 開始有資料。

---

### git clone 失敗：destination path 'librenms' already exists

當重新安裝或 `/opt/librenms` 目錄已存在時：

```bash
# 若 lv-rrd 已掛載在 /opt/librenms/rrd，必須先卸載
sudo umount /opt/librenms/rrd

# 確認已卸載
mount | grep librenms  # 應無輸出

# 刪除後重新 clone
sudo rm -rf /opt/librenms
cd /opt
sudo git clone https://github.com/librenms/librenms.git
```

### php artisan migrate 失敗：Access denied (using password: NO)

表示 `.env` 的 `DB_PASSWORD` 未寫入（sed 靜默失敗）：

```bash
# 確認目前 .env 的 DB_ 設定
grep "^DB_" /opt/librenms/.env

# 若 DB_PASSWORD= 為空，手動編輯
sudo -u librenms nano /opt/librenms/.env
# 確認以下四行均已填入：
#   DB_HOST=127.0.0.1
#   DB_DATABASE=librenms
#   DB_USERNAME=librenms
#   DB_PASSWORD=librenms_pwd_2026

# 再重新執行 migrate
sudo -u librenms bash -c "cd /opt/librenms && php artisan migrate --force"
```

### validate.php FAIL：Python wrapper cron entry is not present

> **根本原因**：LibreNMS `CheckPythonWrapper.php` 的 regex 無 multiline flag，
> 若 `/etc/cron.d/` 下有按字母排在前面的其他 cron 檔（如 `e2scrub_all`），
> 驗證永遠失敗，即使 `/etc/cron.d/librenms` 內容完全正確也一樣。

```bash
# 解決方案：改用 librenms user 個人 crontab
sudo crontab -u librenms - << 'EOF'
*/5  * * * *   /opt/librenms/poller-wrapper.py 16 >> /dev/null 2>&1
15   0 * * *   /opt/librenms/daily.php >> /dev/null 2>&1
*    * * * *   /opt/librenms/alerts.php >> /dev/null 2>&1
EOF

# 驗證
sudo crontab -u librenms -l
sudo -u librenms bash -c "cd /opt/librenms && php validate.php 2>&1 | grep -E 'FAIL|WARN'"
```

### validate.php FAIL：Python3 module issue（command_runner not found）

Ubuntu 24.04 的 Python 環境是 "externally managed"，pip3 預設禁止系統層安裝：

```bash
sudo pip3 install --break-system-packages -r /opt/librenms/requirements.txt
```

### validate.php FAIL：Scheduler is not running

LibreNMS 26.x 的 scheduler 檔案位置從 `misc/` 改到 `dist/`：

```bash
sudo cp /opt/librenms/dist/librenms-scheduler.service \
       /opt/librenms/dist/librenms-scheduler.timer \
       /etc/systemd/system/

sudo systemctl daemon-reload
sudo systemctl enable --now librenms-scheduler.timer
systemctl status librenms-scheduler.timer
# 應顯示 active (waiting)
```

### 瀏覽器顯示 "Welcome to nginx!" 而非 LibreNMS

Nginx 預設頁 `/etc/nginx/sites-enabled/default` 優先於 `conf.d/librenms.conf`：

```bash
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### validate.php WARN：PHP timezone mismatch（system CST ≠ PHP UTC）

```bash
sudo sed -i 's|^;*date.timezone.*|date.timezone = Asia/Taipei|' /etc/php/8.3/cli/php.ini
sudo sed -i 's|^;*date.timezone.*|date.timezone = Asia/Taipei|' /etc/php/8.3/fpm/php.ini
sudo systemctl restart php8.3-fpm

# 確認
php -r "echo date_default_timezone_get();"  # 應回傳 Asia/Taipei
```

### Graylog 啟動失敗：Required parameter "data_dir" not found

Graylog 7.x 新增必填參數，6.x 文件沒有此項：

```bash
# 建立 data_dir 並補入 server.conf
sudo mkdir -p /var/lib/graylog-server
sudo chown graylog:graylog /var/lib/graylog-server

# 在 server.conf 加入（找到 http_bind_address 前插入）
sudo sed -i '/^http_bind_address/i data_dir = /var/lib/graylog-server' \
    /etc/graylog/server/server.conf

sudo systemctl restart graylog-server
```

### Graylog 啟動失敗：Guice MissingImplementation（SearchVersion adapter）

**症狀**：
```
ERROR [CmdLineTool] Guice error: No implementation for Map<SearchVersion, Provider<MoreSearchAdapter>> was bound.
WARN  [PluginLoader] Plugin directory /plugin does not exist, not loading plugins.
```

**根本原因**：`plugin_dir` 未設定，預設值 `/plugin` 不存在，導致 `/usr/share/graylog-server/plugin/` 下的儲存後端 JAR 無法載入（`graylog-storage-opensearch2-*.jar` 等），Guice MapBinder 無版本條目。

**修正**：

```bash
# 確認 plugin JAR 存在
ls /usr/share/graylog-server/plugin/

# 在 server.conf 加入 plugin_dir
echo 'plugin_dir = /usr/share/graylog-server/plugin' | \
  sudo tee -a /etc/graylog/server/server.conf

sudo systemctl restart graylog-server

# 確認 plugin 已載入（應看到 Elasticsearch 7 / OpenSearch 2 / OpenSearch 3 Support）
sudo grep "Loaded plugin" /var/log/graylog-server/server.log | tail -10
```

**副原因**：若 server.conf 殘留 `elasticsearch_hosts` 也會觸發類似 Guice 錯誤：

```bash
# 移除所有 elasticsearch_* 行（Datanode 模式不需要）
sudo sed -i '/^elasticsearch_/d' /etc/graylog/server/server.conf
sudo systemctl restart graylog-server
```

### graylog-datanode 啟動失敗：Cannot read from directory /var/lib/opensearch

目錄擁有者是 `opensearch`（若曾安裝過獨立 OpenSearch）而非 `graylog-datanode`：

```bash
# 確認目前擁有者
ls -la /var/lib/opensearch

# 修正擁有者
sudo chown -R graylog-datanode:graylog-datanode /var/lib/opensearch
sudo chmod 755 /var/lib/opensearch

sudo systemctl restart graylog-datanode
```

### graylog-datanode 無法初始化（已有舊 OpenSearch cluster 資料）

若 `/var/lib/opensearch` 含有舊獨立 OpenSearch 的 cluster 資料，Datanode 無法建立新 cluster：

```bash
# 停止服務
sudo systemctl stop graylog-server graylog-datanode

# 確認沒有重要 log 資料後，清空舊 cluster 資料
sudo rm -rf /var/lib/opensearch/*

# 重設擁有者
sudo chown -R graylog-datanode:graylog-datanode /var/lib/opensearch

# 重新啟動（datanode 先，等 90 秒再啟 server）
sudo systemctl start graylog-datanode
sleep 90
sudo systemctl start graylog-server
```

### Graylog Setup Wizard 一次性密碼消失（找不到密碼）

一次性密碼只在第一次啟動的 log 中出現一次：

```bash
# 從 server.log 搜尋（即使已滾動，通常仍在）
sudo grep -A 3 "Initial configuration is accessible" /var/log/graylog-server/server.log

# 若找不到，重設方式：停止 server，在 MongoDB 刪除 preflight 狀態，重啟即再次顯示
sudo systemctl stop graylog-server
mongosh graylog --eval 'db.preflight_checks.drop()'
sudo systemctl start graylog-server
sleep 30
sudo grep -A 3 "Initial configuration" /var/log/graylog-server/server.log
```

### vm.max_map_count 不足（Graylog Datanode / OpenSearch 需要）

```bash
# 檢查目前值
cat /proc/sys/vm/max_map_count

# 若小於 262144，立即設定並永久化
sudo sysctl -w vm.max_map_count=262144
echo "vm.max_map_count=262144" | sudo tee -a /etc/sysctl.conf

sudo systemctl restart graylog-datanode
```

### jt-ipam 無法同步 LibreNMS 設備

```bash
# 確認 API Token 有效
curl -H "X-Auth-Token: <token>" http://localhost/api/v0/devices | head -20
# 確認 jt-ipam .env 的 LIBRENMS_API_TOKEN
sudo cat /opt/jt-ipam/.env | grep LIBRENMS_API_TOKEN
sudo systemctl restart jt-ipam
```

### 磁碟空間警告

```bash
# 查看各 LV 使用量
df -hT | grep -E "vg-data|Filesystem"

# OpenSearch 索引清理（保留最近 N 天）
# 在 Graylog Web UI：System → Indices → 調整 Index Rotation 策略

# LibreNMS RRD 清理（移除已刪除設備的 RRD）
sudo -u librenms bash -c "cd /opt/librenms && php lnms db:purge"
```

### 記憶體不足（OOM）

```bash
free -h
# 若 Graylog Datanode（OpenSearch）heap 佔太多，在 Graylog Web UI 調整：
# System → Datanode → 選擇 node → 修改 JVM heap size
# 或直接修改 /etc/graylog/datanode/datanode.conf 加入 JVM 參數後重啟

# 查看記憶體占用前 10 名
ps aux --sort=-%mem | head -11
```

### 🚨 jt-ipam.sh 從錯誤路徑執行 → `chown -R jtipam /` 摧毀系統

**症狀**（任一出現即代表已誤觸，見 §7.0）：

```
sudo: /etc/sudo.conf is owned by uid 995, should be 0
psql: ... could not open file "global/pg_filenode.map": Permission denied
postgresql@16-main: Error: Config owner (jtipam:995) ... config owner is not root
```

**確認損害範圍**：

```bash
stat -c '%U:%G  %n' /etc /usr /var /home /bin   # 若全為 jtipam:jtipam = 全系統損壞
ls -l /usr/bin/sudo                              # 正常有 s (rws)；無 s (rwx) = setuid 已被清除
find /usr /bin /sbin -perm /6000 -type f | wc -l # 乾淨系統約 30+；數字過低 = setuid 大量遺失
```

**處置決策**：

- **owner 損壞 + setuid 已清除** → **直接重裝 OS**。手動修復需 `chown -R root:root` 全系統
  再逐一還原各服務 data 目錄 owner，且 setuid 只能靠 `apt-get install --reinstall` 全套件還原，
  可靠性低、殘留風險高（尤其安全敏感）。重裝最快最乾淨。
- **僅 owner 損壞、setuid 仍在**（極少數，視核心而定）→ 可嘗試手動修復，但仍建議重裝。

**修復完成前絕對不要重開機**（owner/setuid 損壞可能導致無法開機）；當前 root shell 是唯一生命線。

> **預防**：永遠從 `git clone` 出的 `/opt/jt-ipam/scripts/jt-ipam.sh` 完整路徑執行，
> 不要 `cp` 腳本到 `/tmp` 或其他單檔位置（詳見 §7.0）。

### jt-ipam 安裝：pgvector not installable / nginx default_server 衝突

見 §7.1–7.2（pgvector 先手動裝 + patch 腳本）與 §7.4（移除 nginx port 80 default_server）。

---

## 帳號密碼彙整

| 服務 | 帳號 | 密碼 |
|------|------|------|
| LibreNMS | admin | Systex@LibreNMS2026! |
| MariaDB (librenms) | librenms | librenms_pwd_2026 |
| Graylog | admin | Graylog@2026!（首次 Setup Wizard 設定）|
| Graylog Datanode | — | 由 graylog-datanode 管理，無獨立帳號 |
| jt-ipam | admin | 安裝時自動產生，見 `/etc/jt-ipam/.admin-initial-password` |
| PostgreSQL (jt_ipam) | jtipam | 由 `scripts/jt-ipam.sh` 自動產生並寫入 jt-ipam 設定 |

> ⚠️ 部署完成後請立即更改所有預設/初始密碼。
> jt-ipam 的資料庫名為 `jt_ipam`、系統使用者為 `jtipam`、後端服務為 `jt-ipam-backend.service`。
