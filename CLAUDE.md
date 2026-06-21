# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> 補充 README.md 沒寫的「不顯而易見的事」。專案總覽、安裝步驟、告警規則表參考 [README.md](README.md)。

---

## 三層架構與信任邊界（重要）

整個 GUI 與管理操作走一條**固定信任鏈**，新增功能務必照同樣模式走：

```
Browser  ─[POST + CSRF + X-CSRF-Token]──►  /opt/librenms/html/oracle-*.php
   (admin role check + Auth::check)
                                              │
                                              │ proc_open(['sudo', '<script>', ...args])
                                              ▼
                                          /opt/oracle-mon/admin/*.sh
                                          (root 身分執行；sudoers NOPASSWD 白名單)
                                              │
                                              ▼
                                          OracleStats Java（採集）
                                          /opt/librenms/lnms / artisan（LibreNMS CLI）
                                          其他系統設定檔
```

**新增一個 admin 動作 = 必須同步動 4 處**：
1. `collector/admin/<name>.sh` — 實際特權操作（**寫成 read-only 或冪等**；參數驗證；輸出 JSON）
2. `librenms/html/oracle-<name>.php` — Web 端點，clone 既有 `oracle-test.php` 樣板（auth + CSRF + proc_open + 結構化 JSON 回傳 + log 到 `/var/log/oracle-admin.log`）
3. `system/sudoers.oracle-admin` — 加 NOPASSWD 條目
4. `install.sh` / `update.sh` — 自動部署到 VM（檔案 install 規則照既有 pattern 抄）

⚠️ **sudoers user 是 `librenms`，不是 `www-data`**：LibreNMS 自家 PHP-FPM pool 是 librenms（見 `/etc/php/8.3/fpm/pool.d/librenms.conf`）。新 shell script `librenms ALL=(root) NOPASSWD: /opt/oracle-mon/admin/<name>.sh`。

---

## 重複跑安全（idempotency）

`install.sh` 與 `update.sh` 設計成**任何時候重跑都不破壞既有狀態**：

- `install -m ... -o ... -g ...` 會覆寫但保留權限
- `menu-patch.py` 用 `{{-- BEGIN oracle-mon menu --}}` / `{{-- END --}}` 標記檢測，已存在就 `ALREADY PRESENT - no change`
- `install_alert_rules.php` 依名稱比對，已存在就 `SKIP (exists)`
- snmpd extend block 用 `BEGIN oracle-mon managed` 字串守護
- Bootstrap CSS 用 `[ -f "$BS_CSS" ]` 守護避免重複下載
- `dbs/*.conf` **從不被 install.sh 動到**，使用者資料絕對保留

⚠️ **改 `menu.blade.php` 結構時務必保留 BEGIN/END 標記**，否則 `menu-patch.py` 重跑會 ANCHOR NOT FOUND 失敗，或誤判沒裝過而重複插入造成選單重複（曾發生過）。

⚠️ **改 `install.sh` 加新部署步驟時**，務必 `[ -f ... ] && say "已存在，略過"` 包起來，否則破壞冪等性。

---

## 兩種 Oracle DB 測試路徑（互不干擾）

`oracle-test.php` 同時支援兩種模式，依 request body 自動判斷：

| 模式 | 觸發條件 | 用途 | 後端腳本 |
|------|---------|------|---------|
| **alias** | body 只有 `alias` 欄位 | 區塊 C 多 DB 列表測試「已存檔」設定 | `test-db.sh <alias>` → 讀 `/opt/oracle-mon/dbs/<alias>.conf` |
| **adhoc** | body 含 `host` + `sid` | 區塊 A「測試連線」用**表單即時值**測試（未存檔也能測） | `test-db-adhoc.sh <host> <port> <sid> <user> <pass>` |

ad-hoc 模式有個重要 UX 巧思：**密碼欄空白 + alias 有對應 .conf → 從 .conf 撈現存密碼**。讓使用者「改 IP 不用重打密碼」的常見場景能跑通。

---

## 已知硬編碼與限制

- **`oracle-l1hweb` 寫死在 polling / graphs / pages 檔名**（README 已列為 TODO）：
  - `librenms/polling/oracle-l1hweb.inc.php`
  - `librenms/pages/device_apps/oracle-l1hweb.inc.php`
  - `librenms/pages/apps/oracle-l1hweb.inc.php`
  - 加新 DB alias 需手動複製這 3 個檔並改 app 名稱，並讓對應 device 啟用該 application。**多實例完整支援是 long-term refactor，現階段不要再硬編更多 alias**。
- **ojdbc14.jar 是 10.2 版**（為了 Oracle 9i 相容）。改動 `OracleStats.java` 用到的 API 時，**確認在 9i 上能跑**，不要用 ojdbc 11g+ 才有的 feature。
- **`dbs/*.conf`** 含明文密碼，被 `.gitignore` 排除。不要把 `*.conf`（非 `.example`）入庫。

---

## monitor-vm 整合架構（同台共處的其他服務）

monitor-vm 上同時跑 LibreNMS + Graylog + jt-ipam + jt-gelflow。本 repo 的 `oracle-admin.php`（區塊 B「監控主機 IP 異動」 + 「🔍 掃描舊 IP」）會處理跨服務的 IP 更新：

- IP 異動：`update-librenms-url.sh` 改 LibreNMS `base_url` + `.env APP_URL` + `artisan config:clear`
- 掃描舊 IP：`scan-old-ip.sh` 是**唯讀**腳本，掃描以下檔案中是否殘留舊 IP：
  - nginx: `/etc/nginx/conf.d/*.conf`, `/etc/nginx/sites-enabled/*`
  - LibreNMS: `menu.blade.php`, `.env`
  - jt-gelflow / jt-ipam: `config.json`, `.env`
  - Graylog: `server.conf`, `datanode.conf`（已實測會抓到 `http_publish_uri`）
  - SNMP: `snmpd.conf`, `snmp.conf`
  - Oracle: `/opt/oracle-mon/dbs/*.conf`
  
  **新增其他被監控元件時**（例如未來加 jt-glogarch），如果它的設定檔含 monitor-vm IP，**請把該檔加進 `TARGETS=(...)` 陣列**。

更廣的部署文件在 [docs/single-vm-deployment.md](docs/single-vm-deployment.md)（含 nginx 反向代理 jt-gelflow、Graylog Pipeline、GeoIP 等實測 runbook）。

---

## 常用指令

```bash
# 部署 / 更新（都冪等）
sudo /opt/oracle-mon-librenms/install.sh        # 完整安裝
sudo /opt/oracle-mon-librenms/update.sh         # 只更新 code（保留 dbs/、RRD）

# 手動測試 admin 腳本（驗證 sudoers + 腳本邏輯）
sudo -u librenms sudo -n /opt/oracle-mon/admin/test-db.sh l1hweb
sudo -u librenms sudo -n /opt/oracle-mon/admin/test-db-adhoc.sh 172.16.1.101 1521 L1HWEB librenms PASSWORD
sudo -u librenms sudo -n /opt/oracle-mon/admin/scan-old-ip.sh 172.16.1.94

# 重新編譯 Java collector
( cd /opt/oracle-mon && sudo javac -cp lib/ojdbc14.jar OracleStats.java )

# 清 LibreNMS view cache（改 .php / .blade.php 後務必清）
sudo -u librenms php /opt/librenms/artisan view:clear

# 修舊版告警規則的 metric 名稱
sudo -u librenms php /opt/oracle-mon-librenms/system/install_alert_rules.php --fix-legacy

# 從 LibreNMS 端跑一次 Oracle polling 驗證
sudo -u librenms php /opt/librenms/poller.php -h 127.0.0.1

# 直接看採集 JSON（不透過 LibreNMS）
sudo /opt/oracle-mon/run.sh l1hweb | python3 -m json.tool
```

---

## Web 端點清單（`/opt/librenms/html/`）

| 端點 | 用途 | sudo shell |
|------|------|-----------|
| `oracle-admin.php` | 主 GUI（區塊 A/B/C） | — |
| `oracle-dashboard.php` + `oracle-dashboard-data.php` | 戰情室即時面板 | — |
| `oracle-save.php` | 儲存 DB 設定 | `save-db-conf.sh` |
| `oracle-db-add.php` / `oracle-db-remove.php` | 新增 / 刪除 / 啟停 DB | `save-db-conf.sh` / `remove-db-conf.sh` + `update-snmpd-extends.sh` |
| `oracle-test.php` | 連線測試（alias 或 adhoc 雙模式） | `test-db.sh` 或 `test-db-adhoc.sh` |
| `oracle-ip-update.php` | monitor-vm IP 異動 + auto-scan 殘留 | `update-librenms-url.sh` + `scan-old-ip.sh` |
| `oracle-scan-old-ip.php` | 獨立掃描 endpoint（IP 殘留唯讀檢查） | `scan-old-ip.sh` |
