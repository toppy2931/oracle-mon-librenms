# Oracle 9i DataGuard — LibreNMS SNMP Extend 監控套件

## 架構說明

```
Oracle 主機（Primary 或 Standby）
  └── snmpd
        └── extend oracle-dg → /etc/snmp/oracle_dg.sh
              └── sqlplus / as sysdba → V$DATABASE, V$MANAGED_STANDBY ...
                    └── 12 行數值輸出

LibreNMS Poller（每 5 分鐘）
  └── snmpwalk OID .1.3.6.1.4.1.8072.1.3.2.3.1.2.9.111...
        └── oracle-dg.inc.php → RRD 圖表 + Eventlog
```

> **注意**：需在 Primary 與 Standby 各自安裝。Primary 監控 archive shipping；Standby 監控 MRP apply lag。

---

## 安裝步驟

### Step 1：Oracle 主機 — 安裝腳本

```bash
# 複製腳本
sudo cp oracle_dg.sh /etc/snmp/oracle_dg.sh
sudo chmod 750 /etc/snmp/oracle_dg.sh
sudo chown root:dba /etc/snmp/oracle_dg.sh

# 修改腳本內的環境變數（ORACLE_HOME、ORACLE_SID）
sudo vi /etc/snmp/oracle_dg.sh
```

**必填修改：**
```bash
ORACLE_HOME=/u01/app/oracle/product/9.2.0/db_1  # 改為實際路徑
ORACLE_SID=ORCL                                   # 改為實際 SID
```

### Step 2：Oracle 主機 — SNMP 使用者加入 dba group

```bash
# 查詢 snmpd 執行使用者
ps aux | grep snmpd | head -1
# 通常是 Debian-snmp（Ubuntu）或 snmpd（RHEL）

# 加入 dba group（以 Debian-snmp 為例）
sudo usermod -a -G dba Debian-snmp

# 重啟 snmpd
sudo systemctl restart snmpd
```

### Step 3：Oracle 主機 — 測試腳本

```bash
# 手動測試（以 dba group 使用者執行）
sudo -u Debian-snmp /etc/snmp/oracle_dg.sh
```

**PRIMARY 預期輸出：**
```
1      # can_connect=1（連線成功）
1      # is_primary=1
1      # db_open=1
-1     # mrp_running=-1（Primary 不適用）
-1     # rfs_connected=-1（Primary 不適用）
127    # current_seq（當前 redo 序號）
-1     # applied_seq=-1（Primary 不適用）
0      # apply_lag_seqs=0
0      # lag_seconds=0
1      # dest_ok=1（Standby dest VALID）
0      # dest_has_error=0（無錯誤）
2      # protection_mode=2（MAXIMUM PERFORMANCE）
```

**STANDBY 預期輸出：**
```
1      # can_connect=1
0      # is_primary=0
0      # db_open=0（Standby 通常是 MOUNTED）
1      # mrp_running=1（MRP0 執行中）
1      # rfs_connected=1
127    # current_seq（最後收到序號）
125    # applied_seq（最後套用序號）
2      # apply_lag_seqs（落後 2 個序號）
600    # lag_seconds（落後約 10 分鐘）
-1     # dest_ok=-1（Standby 不適用）
-1     # dest_has_error=-1
2      # protection_mode=2
```

### Step 4：Oracle 主機 — 設定 snmpd.conf

```bash
sudo vi /etc/snmp/snmpd.conf
# 加入 snmpd-oracle-dg.conf 的內容

sudo systemctl restart snmpd
```

**驗證 SNMP 輸出（從 Oracle 主機本機）：**
```bash
snmpwalk -v2c -c librenms_snmp localhost \
  .1.3.6.1.4.1.8072.1.3.2.3.1.2.9.111.114.97.99.108.101.45.100.103
```

應看到 12 行數值。

### Step 5：LibreNMS — 部署 Poller

```bash
# 在 LibreNMS 伺服器執行（WSL2 或實體 VM）
sudo cp oracle-dg.inc.php \
  /opt/librenms/includes/polling/applications/oracle-dg.inc.php
sudo chown librenms:librenms \
  /opt/librenms/includes/polling/applications/oracle-dg.inc.php
```

### Step 6：LibreNMS — 啟用 Application 監控

1. LibreNMS Web UI → 設備頁 → **Apps** tab
2. 點擊 **Manage Applications**
3. 找到 `oracle-dg` → 點 **Enable**

或用指令：
```bash
sudo -u librenms php /opt/librenms/lnms app:add <device_hostname> oracle-dg
```

---

## 告警規則設定

在 LibreNMS → **Alerts → Rules → +** 新增以下規則：

### 規則 1：Standby MRP 停止（Critical）

```
規則名稱：Oracle DG - Standby MRP Not Running
Severity：critical
條件：
  macros.app_state AND
  applications.app_name = "oracle-dg" AND
  applications.app_status LIKE "%mrp_running:0%"

或使用 RRD 告警（建議）：
  devices.device_id = <id> AND
  application_metrics.metric = "mrp_running" AND
  application_metrics.value = 0
```

### 規則 2：Apply Lag 過高（Warning / Critical）

```
規則名稱：Oracle DG - Apply Lag High
Severity：warning（>10 seq）/ critical（>50 seq）

條件範例（Custom OID Alert）：
  Metric: apply_lag_seqs
  Threshold Warning:  > 10
  Threshold Critical: > 50
```

### 規則 3：Primary Archive Dest 失敗（Critical）

```
規則名稱：Oracle DG - Archive Dest Error
條件：dest_ok = 0 OR dest_has_error = 1
Severity：critical
```

### 規則 4：無法連線 DB（Critical）

```
規則名稱：Oracle DG - Cannot Connect
條件：can_connect = 0
Severity：critical
```

---

## 監控指標說明

| 指標 | 說明 | 正常值 |
|------|------|--------|
| `can_connect` | sqlplus 連線成功 | 1 |
| `is_primary` | 1=Primary, 0=Standby | 任意 |
| `db_open` | DB 狀態 OPEN | Primary=1, Standby=0 |
| `mrp_running` | MRP recover 程序執行 | Standby=1 |
| `rfs_connected` | RFS 接收 Primary log | Standby=1 |
| `current_seq` | 當前/最新序號（趨勢圖） | 持續增加 |
| `applied_seq` | 最後套用序號（Standby） | 接近 current_seq |
| `apply_lag_seqs` | 落後序號數 | < 5（正常）|
| `lag_seconds` | 估算延遲秒數 | < 300（5分鐘）|
| `dest_ok` | Archive dest 狀態 | Primary=1 |
| `dest_has_error` | Archive dest 錯誤 | Primary=0 |
| `protection_mode` | 保護模式 0/1/2 | 依設計而定 |

---

## 疑難排解

### 腳本輸出全為 -1 或 0

```bash
# 檢查 ORACLE_HOME 路徑
ls -la $ORACLE_HOME/bin/sqlplus

# 檢查 ORACLE_SID
echo $ORACLE_SID

# 確認執行使用者在 dba group
id Debian-snmp | grep dba

# 手動以 snmpd 使用者測試
sudo -u Debian-snmp bash -c "
  export ORACLE_HOME=/u01/app/oracle/product/9.2.0/db_1
  export ORACLE_SID=ORCL
  export PATH=\$ORACLE_HOME/bin:\$PATH
  sqlplus -s '/ as sysdba' <<EOF
  SELECT DATABASE_ROLE FROM V\\\$DATABASE;
  EXIT;
EOF"
```

### V\$MANAGED_STANDBY 回傳空值（Primary 上執行 Standby SQL）

這是正常的。Primary 不會有 MRP/RFS 程序，腳本已依 `is_primary` 分支處理。

### lag_seconds 數值異常（負值或超大）

Oracle 主機與 LibreNMS 伺服器時鐘不同步。使用 NTP 校時：
```bash
sudo timedatectl set-ntp true
```

### Oracle 9i 的 V$ 視圖相容性

| 視圖 | 9.2.0 | 9.2.0.6+ |
|------|-------|----------|
| `V$DATABASE.DATABASE_ROLE` | ✓ | ✓ |
| `V$DATABASE.PROTECTION_MODE` | ✓ | ✓ |
| `V$MANAGED_STANDBY` | ✓ | ✓ |
| `V$ARCHIVE_DEST.TARGET='STANDBY'` | ✓ | ✓ |
| `V$ARCHIVED_LOG.APPLIED` | ✓ | ✓ |
| `V$DATAGUARD_STATS` | ✗（需 10g）| ✗ |
| `V$DATAGUARD_CONFIG` | ✗（需 10g）| ✗ |

---

## Part 2：Materialized View (Snapshot) 監控

### oracle-mv.sh 安裝（Oracle 主機）

```bash
# 複製腳本
sudo cp oracle-mv.sh /etc/snmp/oracle-mv.sh
sudo chmod 750 /etc/snmp/oracle-mv.sh
sudo chown root:dba /etc/snmp/oracle-mv.sh

# 修改 ORACLE_HOME / ORACLE_SID（與 oracle_dg.sh 保持一致）
sudo vi /etc/snmp/oracle-mv.sh
```

新增 SNMP extend（`/etc/snmp/snmpd.conf`）：
```
extend oracle-dg /etc/snmp/oracle_dg.sh
extend oracle-mv /etc/snmp/oracle-mv.sh
```

重啟 snmpd：
```bash
sudo systemctl restart snmpd
# 驗證
snmpwalk -v2c -c librenms_snmp localhost .1.3.6.1.4.1.8072.1.3.2.3.1.2.9.111.114.97.99.108.101.45.109.118
# 期望：4 行數值 + N 行 MV 明細（name|age|type|stale|status）
```

### LibreNMS 啟用 oracle-mv 應用程式

1. LibreNMS UI → **設備** → Oracle 主機 → **Applications** 頁籤
2. 點「+」→ 選 `oracle-mv` → 儲存
3. 等下次 poll（或手動）：`sudo -u librenms php /opt/librenms/poller.php -h <oracle-hostname> -m applications -d`

---

## Part 3：LibreNMS 監控畫面設定（戰情室）

### 建立專用 Dashboard

1. LibreNMS 首頁 → **Dashboards** 下拉 → **Create New Dashboard**
2. 命名（例：`Oracle 監控`）→ 建立

### 加入 Oracle DG + MV Widget

1. Dashboard 右上角 → **Edit Mode**（鉛筆圖示）
2. **Add Widget** → 選 **Oracle DG + MV Status**
3. 設定：Stale Threshold（MV 超過幾分鐘算警示）→ 儲存

### 建議搭配的其他 Widget

| Widget | 用途 |
|--------|------|
| Alerts | 即時告警（DG lag、MRP stopped、MV stale 等）|
| Alert Log | 歷史告警記錄 |
| Health Sensors | 設備 CPU/記憶體（Oracle 主機） |
| Graph | DataGuard lag_seconds 趨勢圖 |

### 全螢幕戰情室模式

Dashboard 頁面 URL 加上 `?hide_navbar=1` 可隱藏導覽列，適合投影至大螢幕：
```
http://localhost/dashboard/1?hide_navbar=1
```

### API 供外部系統讀取

```bash
# 取得 API Token（LibreNMS UI → Settings → API Access → Create token）
curl -H "X-Auth-Token: <token>" http://monitor-vm/api/v0/oracle-dg-mv-status
```

回應格式：
```json
{
  "status": "ok",
  "dataguard": [
    {"hostname": "oracle-primary", "is_primary": 1, "lag_seconds": 0, "dest_has_error": 0}
  ],
  "materialized_views": [
    {
      "hostname": "oracle-primary",
      "mv_total_count": 5, "mv_stale_count": 1,
      "snapshots": [
        {"name": "SALES_SUMMARY", "age_minutes": 45, "is_stale": 0},
        {"name": "INVENTORY_AGG", "age_minutes": 125, "is_stale": 1}
      ]
    }
  ]
}
```

---

## 新增檔案一覽

| 檔案 | 部署位置 | 用途 |
|------|----------|------|
| `oracle_dg.sh` | Oracle 主機 `/etc/snmp/` | DG 監控 SNMP extend |
| `oracle-mv.sh` | Oracle 主機 `/etc/snmp/` | MV 監控 SNMP extend |
| `oracle-dg.inc.php` | LibreNMS `/includes/polling/applications/` | DG poller |
| `oracle-mv.inc.php` | LibreNMS `/includes/polling/applications/` | MV poller |
| `OracleStatusController.php` | LibreNMS `app/Http/Controllers/Widgets/` | Dashboard widget |
| `oracle-status.blade.php` | LibreNMS `resources/views/widgets/` | Widget 畫面 |
| `OracleDgMvController.php` | LibreNMS `app/Api/Controllers/` | REST API |
