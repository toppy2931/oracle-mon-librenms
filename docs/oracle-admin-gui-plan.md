# 系統設定統一管理 GUI — 實作計畫

> 版本：v1.1 | 日期：2026-06-16 | 狀態：待審核

---

## 一、前提與範圍

### 1.1 環境現況（2026-06-16）

| 元件 | 狀態 |
|------|------|
| monitor-vm（172.16.1.94） | 已重裝，SSH 可用（systex/Systex7720@） |
| LibreNMS 26.5.1 | 待重部署 |
| Oracle 監控 stack（Stage 1–7） | 待重部署（重裝前已驗證可運作） |
| Web Admin GUI | 待新建（本計畫目標） |

**重要**：LibreNMS + Oracle 監控 stack 需先完成重部署，Phase 1 包含此步驟。

### 1.2 目標

建立 `http://172.16.1.94/oracle-admin` 統一管理介面，涵蓋三個區塊：

| 區塊 | 功能 |
|------|------|
| A | Oracle 資料庫連線設定（IP / Port / SID / 帳號 / 密碼） |
| B | monitor-vm IP 異動（一鍵更新所有相關設定） |
| C | 多台資料庫主機管理（新增 / 移除 / 測試 / 啟停） |

---

## 二、整體架構設計

### 2.1 關鍵技術確認

| 項目 | 確認值 |
|------|--------|
| PHP-FPM 執行 user | `librenms`（sudoers 規則需用此 user） |
| OracleStats.java 連線參數 | 讀 `System.getenv("ORA_URL/ORA_USER/ORA_PASS")`，無需改 Java 程式 |
| run.sh 目前機制 | `set -a; . /etc/oracle-mon.conf; set +a` 把 conf 匯出為環境變數 |
| snmpd extend 執行 user | `Debian-snmp` |
| LibreNMS html 根目錄 | `/opt/librenms/html/` |

### 2.2 目錄結構（最終狀態）

```
/opt/oracle-mon/
├── dbs/                              # 新建：每台 DB 一個 conf 檔
│   ├── l1hweb.conf                   # Oracle 9i L1HWEB（目前唯一）
│   └── <alias>.conf                  # 未來新增的 DB
├── admin/                            # 新建：特權 wrapper scripts（chmod 750 root:librenms）
│   ├── save-db-conf.sh               # 寫入 dbs/<alias>.conf
│   ├── remove-db-conf.sh             # 刪除 dbs/<alias>.conf
│   ├── update-snmpd-extends.sh       # 重建 snmpd.conf managed block + snmpd reload
│   ├── update-librenms-url.sh        # lnms config:set + sed .env + artisan config:clear
│   └── test-db.sh                    # 執行 run.sh <alias>，回傳 JSON
├── OracleStats.java                  # 現有（不改）
├── ojdbc14.jar                       # 現有
└── run.sh                            # 修改：支援 alias 參數

/opt/librenms/html/
├── oracle-admin.php                  # 新建：主 GUI 頁面（三個區塊）
└── ajax/
    ├── oracle_save.php               # 區塊A：儲存 DB conf
    ├── oracle_test.php               # 區塊A/C：測試連線
    ├── ip_update.php                 # 區塊B：更新 base_url / .env
    ├── db_add.php                    # 區塊C：新增 DB 完整流程
    └── db_remove.php                 # 區塊C：移除 DB 完整流程

/etc/sudoers.d/oracle-admin           # librenms user 可執行 admin/ 下的 scripts
/var/log/oracle-admin.log             # 操作稽核 log
```

### 2.3 DB conf 格式

每台 DB 獨立一個 conf 檔（`chmod 640 root:Debian-snmp`）：

```bash
# /opt/oracle-mon/dbs/l1hweb.conf
DB_HOST=172.16.1.101
DB_PORT=1521
DB_SID=L1HWEB
DB_USER=librenms
DB_PASS=librenms
DB_ALIAS=l1hweb
DB_LABEL=L1HWEB（Oracle 9i 主機）
DB_ENABLED=1
DB_ADDED=2026-06-16
```

### 2.4 run.sh 改版（多 DB，backward-compatible）

```bash
#!/bin/bash
ALIAS=${1:-l1hweb}
CONF="/opt/oracle-mon/dbs/${ALIAS}.conf"
[ -f "$CONF" ] || { echo '{"error":"conf not found"}'; exit 1; }
set -a; . "$CONF"; set +a
# 組裝 ORA_URL 供 OracleStats.java 讀取（System.getenv）
ORA_URL="jdbc:oracle:thin:@//${DB_HOST}:${DB_PORT}/${DB_SID}"
ORA_USER="$DB_USER"
ORA_PASS="$DB_PASS"
export ORA_URL ORA_USER ORA_PASS
exec java -cp /opt/oracle-mon:/opt/oracle-mon/lib/ojdbc14.jar OracleStats
```

### 2.5 snmpd.conf managed block（GUI 自動維護）

```conf
# BEGIN oracle-mon managed — do not edit manually
extend oracle_l1hweb /opt/oracle-mon/run.sh l1hweb
extend oracle_db2    /opt/oracle-mon/run.sh db2
# END oracle-mon managed
```

`update-snmpd-extends.sh` 讀取 `dbs/*.conf`（`DB_ENABLED=1`）→ 重建這段 → `service snmpd reload`

---

## 三、頁面規格

### 3.1 URL 與存取

| 項目 | 值 |
|------|-----|
| URL | `http://172.16.1.94/oracle-admin` |
| 實體路徑 | `/opt/librenms/html/oracle-admin.php` |
| 認證 | LibreNMS Session；`isAdmin()` 驗證；否則 302 → `/login` |
| 入口 | LibreNMS 上方選單 → Devices → monitor-vm → 右上齒輪 → 系統設定 |

### 3.2 區塊 A — Oracle 連線設定

**用途**：快速修改現有 DB 的連線參數，取代 SSH 手動編輯 conf 檔。

**UI 線框**：
```
┌─ Oracle 資料庫連線設定 ─────────────────────────────────────┐
│  選擇 DB    [l1hweb — L1HWEB（Oracle 9i 主機） ▼]           │
│  主機 IP    [172.16.1.101       ]                           │
│  Port       [1521               ]                           │
│  SID        [L1HWEB             ]                           │
│  帳號       [librenms           ]                           │
│  密碼       [                   ] [顯示/隱藏]               │
│             （空白 = 不變更現有密碼）                        │
│  [儲存設定]  [測試連線]                                      │
│                                                             │
│  測試結果：● 連線成功，instance_up=1，DB 狀態：OPEN         │
└─────────────────────────────────────────────────────────────┘
```

**AJAX 流程**：
```
[儲存設定] → POST ajax/oracle_save.php
           {alias, host, port, sid, user, pass}
           → sudo save-db-conf.sh（pass 空字串 → 不覆寫舊值）
           → 回 {ok:true}

[測試連線] → POST ajax/oracle_test.php {alias}
           → sudo test-db.sh <alias> → run.sh → JSON
           → 回 {connected, instance_up, db_status, error}
```

**密碼安全**：GET 時密碼欄一律空白；POST 時 pass 為空字串 → 保留 conf 原有密碼。

### 3.3 區塊 B — monitor-vm IP 異動

**用途**：monitor-vm 換網段時一次更新所有設定，避免遺漏導致服務中斷。

**UI 線框**：
```
┌─ 監控主機 IP 異動 ──────────────────────────────────────────┐
│  目前 IP   172.16.1.94  （偵測自 lnms config:get base_url） │
│  新 IP     [_______________]                                │
│                                                             │
│  ☑ LibreNMS base_url                                       │
│    指令：lnms config:set base_url http://<新IP>             │
│    說明：警報通知、圖表連結等所有 LibreNMS 生成的 URL       │
│                                                             │
│  ☑ Laravel APP_URL（.env）                                  │
│    檔案：/opt/librenms/.env → APP_URL=http://<新IP>         │
│    說明：Laravel 框架根 URL，影響 CSRF token 驗證           │
│                                                             │
│  ☑ 清除 Laravel 設定快取                                    │
│    指令：php artisan config:clear                           │
│    說明：.env 異動後必須執行，否則新設定不生效              │
│                                                             │
│  [套用]（需二次確認彈窗）                                   │
│  套用成功後 3 秒自動跳轉到 http://<新IP>/oracle-admin        │
└─────────────────────────────────────────────────────────────┘
```

**後端執行順序**（`ajax/ip_update.php`）：
1. `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)` 格式驗證
2. sudo `update-librenms-url.sh <new_ip>`：
   - `sudo -u librenms lnms config:set base_url "http://<new_ip>"`
   - `sed -i "s|^APP_URL=.*|APP_URL=http://<new_ip>|" /opt/librenms/.env`（或 append 若不存在）
   - `sudo -u librenms php /opt/librenms/artisan config:clear`
3. 記錄操作 log
4. 回 `{ok:true, new_ip:"..."}`

### 3.4 區塊 C — 多台 DB 主機管理

**用途**：新增/移除/啟停受監控的 Oracle DB 主機，無需 SSH。

**UI — 清單表格**：
```
┌─ 多台資料庫主機管理 ────────────────────────────────────────┐
│  別名    │ 主機           │ Port │ SID    │ 標籤      │ 狀態 │ 最後測試 │ 操作            │
│  l1hweb  │ 172.16.1.101   │ 1521 │ L1HWEB │ L1HWEB 9i │ ● 啟 │ ✓ 成功   │ [編][測][停][刪]│
│  db2     │ 10.0.0.5       │ 1521 │ PROD   │ 第二台    │ ● 啟 │ —        │ [編][測][停][刪]│
│                                                             │
│  [＋ 新增 DB]  [全部測試]                                   │
└─────────────────────────────────────────────────────────────┘
```

**新增 DB 完整流程**（`ajax/db_add.php`）：
1. 輸入驗證：
   - alias：`/^[a-z0-9_]+$/`，不得與現有重複
   - host：`FILTER_VALIDATE_IP`
   - port：1–65535
   - sid：`/^[A-Za-z0-9_]+$/`
2. sudo `save-db-conf.sh <alias> <host> <port> <sid> <user> <pass>`  
   → 建立 `dbs/<alias>.conf`，`chmod 640 root:Debian-snmp`
3. sudo `update-snmpd-extends.sh`  
   → 重建 snmpd.conf managed block（含新 DB）→ `service snmpd reload`
4. LibreNMS 加 application：  
   - 優先：`sudo -u librenms lnms device:add-application 1 oracle_<alias>`  
   - 備案：直接 SQL `INSERT INTO applications (app_type, app_instance, device_id, app_state) VALUES ('oracle_<alias>', 'oracle_<alias>', 1, 'NOTPOLLED')`
5. 回 `{ok:true}`

**移除 DB 流程**（`ajax/db_remove.php`）：
1. 第一步（軟刪）：`DB_ENABLED=0` → 更新 snmpd extends → LibreNMS app 停用  
   （RRD 歷史圖表資料完整保留）
2. 第二步（硬刪，需二次確認）：sudo `remove-db-conf.sh <alias>` → 刪 conf 檔

**停用/啟用**：toggle `DB_ENABLED`，sudo 更新 snmpd extends，無其他操作。

**全部測試**：並行呼叫 `oracle_test.php` 取得每台狀態，表格即時更新。

---

## 四、安全設計

| 考量 | 做法 |
|------|------|
| 頁面存取 | `isAdmin()` session 驗證；否則 302 → `/login` |
| CSRF | 所有 AJAX POST 帶 `X-CSRF-Token`（LibreNMS `csrf_token()` 產生） |
| 特權操作 | wrapper scripts 限 `/opt/oracle-mon/admin/`，sudoers 白名單指定完整路徑 |
| DB 密碼 | GET 不回傳明文；pass 空字串 → 不覆寫 conf 舊值 |
| Input 驗證 | IP: `FILTER_VALIDATE_IP`；Port: 1-65535；Alias: `/^[a-z0-9_]+$/`；SID: `/^[A-Za-z0-9_]+$/` |
| 操作 Log | 每次操作記錄到 `/var/log/oracle-admin.log`（時間、LibreNMS user、來源 IP、動作） |

sudoers（`/etc/sudoers.d/oracle-admin`）：
```
librenms ALL=(root) NOPASSWD: /opt/oracle-mon/admin/save-db-conf.sh
librenms ALL=(root) NOPASSWD: /opt/oracle-mon/admin/remove-db-conf.sh
librenms ALL=(root) NOPASSWD: /opt/oracle-mon/admin/update-snmpd-extends.sh
librenms ALL=(root) NOPASSWD: /opt/oracle-mon/admin/update-librenms-url.sh
librenms ALL=(root) NOPASSWD: /opt/oracle-mon/admin/test-db.sh
```

---

## 五、分期實作計畫

### Phase 1 — 環境重建 + conf 架構重構（0.5 天）

| 步驟 | 動作 | 驗收 |
|------|------|------|
| 1.1 | monitor-vm 重部署 LibreNMS（依 `docs/single-vm-deployment.md`） | LibreNMS Web UI 可存取 |
| 1.2 | 重部署 Oracle 監控 Stage 1–7（`docs/oracle9i-monitoring.md`） | oracle_l1hweb 9 張圖表出現 |
| 1.3 | 建立 `/opt/oracle-mon/dbs/l1hweb.conf`（從 `/etc/oracle-mon.conf` 遷移） | conf 檔格式正確，權限 640 |
| 1.4 | 修改 `run.sh` 支援 `<alias>` 參數 | `run.sh l1hweb` 輸出正確 JSON |
| 1.5 | 修改 snmpd.conf 改用 managed block | snmpwalk oracle_l1hweb 正常，圖表不中斷 |
| 1.6 | 建立 `admin/` wrapper scripts + sudoers | sudo test-db.sh l1hweb 回傳 JSON |

### Phase 2 — 區塊 A：Oracle 連線設定 GUI（0.5 天）

| 步驟 | 動作 |
|------|------|
| 2.1 | 建立 `oracle-admin.php`（LibreNMS session 驗證 + CSRF token + Bootstrap 5 UI） |
| 2.2 | 實作區塊 A UI（DB 選擇 select + 欄位表單 + 顯示/隱藏密碼） |
| 2.3 | `ajax/oracle_save.php`（sudo 呼叫 save-db-conf.sh，pass 空值保護） |
| 2.4 | `ajax/oracle_test.php`（sudo 呼叫 test-db.sh，解析 JSON） |

**驗收**：改密碼後測試連線成功；HTTP response 無明文密碼；空密碼不覆寫。

### Phase 3 — 區塊 C：多 DB 管理 GUI（1 天）

| 步驟 | 動作 |
|------|------|
| 3.1 | 區塊 C 清單表格 UI（從 dbs/*.conf 讀取） |
| 3.2 | 新增 DB 表單 + `ajax/db_add.php`（建 conf + snmpd + LibreNMS app） |
| 3.3 | 停用/啟用 DB（toggle DB_ENABLED + snmpd reload） |
| 3.4 | 刪除 DB + `ajax/db_remove.php`（二次確認 + 軟刪 → 硬刪） |
| 3.5 | 全部測試按鈕（並行 fetch oracle_test.php，更新表格狀態） |

**驗收**：新增第 2 台 DB → 5 分鐘內 LibreNMS 出現新 app 圖表；刪除 → extend 消失；停用 → 資料保留。

### Phase 4 — 區塊 B：IP 異動 GUI（0.5 天）

| 步驟 | 動作 |
|------|------|
| 4.1 | `admin/update-librenms-url.sh` wrapper |
| 4.2 | `ajax/ip_update.php`（IP 驗證 + sudo + 回傳跳轉 URL） |
| 4.3 | 區塊 B UI（目前 IP 自動帶入 + 勾選清單 + 二次確認彈窗 + 跳轉） |

**驗收**：套用後 base_url 更新；.env APP_URL 更新；config cache 清除；breadcrumb 連結正常。

---

## 六、驗收標準

| 項目 | 標準 |
|------|------|
| 區塊 A | ① 儲存後測試連線成功 ② HTTP response 無密碼明文 ③ 空密碼不覆寫舊值 |
| 區塊 B | ① 套用後 LibreNMS base_url 更新 ② .env APP_URL 更新 ③ 頁面自動跳轉 ④ breadcrumb 正常 |
| 區塊 C | ① 新增 DB → snmpd.conf 更新 → LibreNMS 圖表出現（5 分鐘內） ② 刪除 → extend 消失 ③ 停用 → 資料保留 |
| 安全 | ① 非 admin 無法存取 ② CSRF token 驗證有效 ③ 操作 log 正確記錄 ④ 密碼不出現在任何 HTTP response |

---

## 七、已知風險與備案

| 風險 | 說明 | 備案 |
|------|------|------|
| PHP-FPM user 確認 | 文件顯示為 librenms，實際部署後再驗證 `ps aux \| grep php-fpm` | 若為 www-data，sudoers 規則對應調整 |
| snmpd reload 中斷輪詢 | 新增/刪除 DB 時需 reload snmpd（約 1–2 秒中斷） | LibreNMS 輪詢間隔 5 分鐘，短暫中斷不影響圖表 |
| LibreNMS app CLI 指令 | `lnms device:add-application` 是否存在需現場確認 | 備案：直接 SQL INSERT |

---

*計畫終*
