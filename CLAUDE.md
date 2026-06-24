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

## 修改 LibreNMS 內建檔的兩種標準模式

除了上述「PHP↔shell」三層信任鏈，本 repo 還會修補 LibreNMS 內建檔（Blade view、PHP class、config.php 等）。**標準做法是 `system/*-patch.py` 冪等 upsert 腳本**，由 `install.sh` 呼叫。**禁止手動 sed 一次性改完不留追蹤**。

| Patch 腳本 | 修改目標 | 標記方式 |
|-----------|---------|---------|
| `system/menu-patch.py` | `resources/views/layouts/menu.blade.php` 齒輪選單 | `{{-- BEGIN oracle-mon menu --}}` / `{{-- END --}}` 區塊整段替換 |
| `system/custom-map-patch.py` | `resources/views/map/custom-view.blade.php` 兩處 `{{$page_refresh}}` 改 fallback 寫法 | `{{-- oracle-mon: custom_map_refresh patched --}}` sentinel comment |

**Patch 腳本必須冪等**：偵測既有 marker，存在則 update / skip，不存在才 insert。

**LibreNMS 升級時這些 patch 會被覆蓋**——`update.sh` 不會處理升級後的 LibreNMS code，使用者升完 LibreNMS 需重跑 `install.sh` 讓 patch 重新生效。

⚠️ **`re.sub` 處理含反斜線的替換字串**（如 `\LibrenmsConfig::get(...)`）必須用 lambda 繞 backreference 解析，否則炸 `re.error: bad escape \L`（已踩過，見 `custom-map-patch.py` 註解）。

---

## 重複跑安全（idempotency）

`install.sh` 與 `update.sh` 設計成**任何時候重跑都不破壞既有狀態**：

- `install -m ... -o ... -g ...` 會覆寫但保留權限
- `menu-patch.py` 用 `{{-- BEGIN oracle-mon menu --}}` / `{{-- END --}}` 標記。**已改為 upsert**：區塊存在就整段替換為最新內容（可重跑更新選單項目），不存在才插入到 Settings「Validate Config」`@endcanany` 之後。改選單項目只要改 `menu-patch.py` 的 `BLOCK` 再重跑即可
- `install_alert_rules.php` 依名稱比對，已存在就 `SKIP (exists)`
- snmpd extend block 用 `BEGIN oracle-mon managed` 字串守護
- Bootstrap CSS 用 `[ -f "$BS_CSS" ]` 守護避免重複下載
- `dbs/*.conf` **從不被 install.sh 動到**，使用者資料絕對保留

⚠️ **menu-patch `BLOCK` 是選單的單一真實來源**：jt-gelflow 等項目都在這個受管理區塊內，**不要只在 `menu.blade.php` 手動加項目**——重跑 upsert 會整段覆蓋而洗掉（曾發生：手動加的 jt-gelflow 被洗掉）。新增選單項目一律加進 `menu-patch.py` 的 `BLOCK`。外部服務連結（jt-glogarch/jt-gelflow）用相對路徑或 `location.hostname` 組 URL，避免硬編 IP（IP 異動自動跟隨）。

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

## Oracle 9i 採集 SQL 相容性（踩過的坑）

`OracleStats.java` 的查詢**必須同時支援 9i 與 10g+**。`q1()` / 各 try-catch 出錯時靜默回 `0` 或 `""`，所以「在 9i 用了 10g 才有的視圖」會**靜默變成永遠為 0**（看起來像「沒設定」），不會 throw 給上層。新增指標前先對照下表：

| 10g+ 才有 | 9i 替代寫法 |
|---------|-----------|
| `v$dataguard_stats`（apply lag / transport lag） | 9i 沒對應視圖，保持 try-catch fallback 0 即可 |
| `v$dataguard_config` | 不存在；用 `v$archive_dest where target='STANDBY'` 判斷有無 DG |
| `v$diag_alert_ext`（alert log） | 11g+；9i 須走 UTL_FILE 或 SSH，**架構性異動，先不做** |
| `v$archive_dest_status.TARGET` 欄位 | 9i 此 view 沒 TARGET，要 JOIN `v$archive_dest`（兩邊都有 DEST_ID）撈 standby |
| `v$session.blocking_session` 欄位 | 9i 沒此欄位；用 `v$session.lockwait is not null` 或 JOIN `v$lock` |
| `v$archive_dest.VALID_ROLE` | 9i 沒此欄位，只能查 `status / destination / target / error` |

**Primary vs Standby 視角**：`v$managed_standby` 在 **Primary 上看不到 MRP/RFS**（那是備庫端 process）。判斷「備庫運作狀態」要從 Primary 端查 `v$archive_dest`（status / error）+ JOIN `v$archive_dest_status`（applied_seq#）。dashboard 不要顯示 Primary 端的 MRP 狀態（永遠 NONE，誤導 DBA）。

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
  
  **新增其他被監控元件時**，如果它的設定檔含 monitor-vm IP，**請把該檔加進 `TARGETS=(...)` 陣列**。

更廣的部署文件在 [docs/single-vm-deployment.md](docs/single-vm-deployment.md)（含 nginx 反向代理 jt-gelflow、Graylog Pipeline、GeoIP、§3.2 防火牆、§6.10 Graylog 清空 等實測 runbook）。

### jt-glogarch（Graylog 日誌歸檔，**獨立上游專案**，非本 repo 套件）

jt-glogarch（`jasoncheng7115/jt-glogarch`）裝在同台 monitor-vm，本 repo 透過區塊 E 管理「歸檔同步到 NAS」、選單放入口連結。重點：

- **必須走 HTTPS**：其 `web/app.py` SessionMiddleware 寫死 `https_only=True`（secure cookie），HTTP 下瀏覽器拒存 cookie → 登入「閃一下」跳回 login。config `web.ssl_certfile/ssl_keyfile` 指回 `server.crt/server.key` 即 HTTPS。Web UI `https://<host>:8990`，用 **Graylog 帳號**登入（驗證走 Graylog `/api/system` Basic Auth）。
- 安裝鐵則（與作者另一專案 jt-ipam 同源，曾 `chown -R /` 毀機）：原始碼放 `/opt/jt-glogarch` 本身（install.sh 跑 `pip install /opt/jt-glogarch`）；Ubuntu 24.04 需先 `pip install --break-system-packages --ignore-installed setuptools wheel`；systemd 服務那步是互動詢問，非互動跑會跳過需手動 `cp deploy/jt-glogarch.service`。
- 歸檔落地在獨立碟 `/data/graylog-archives`（VG `vg-archive`/`/dev/sdc`），API 模式（非 OpenSearch Direct，因 9200 有認證）。

---

## 防火牆可攜性（區塊 D）+ NAS 備份（區塊 E）

兩者都是為「**佈署到客戶端、各站點 IP/網段不同**」設計，避免硬編碼。

**防火牆（區塊 D / `setup-mgmt-firewall.sh` / `manage-mgmt-cidrs.sh`）**
- 管理 UI 埠（22/80/443/9000/8990/8099）**只開放給「本機所在網段」**（`ip route` 自動偵測），各站點免手調。
- 其他內網/遠端網段寫進持久化檔 `/etc/oracle-mon/mgmt-cidrs.conf`（一行一 CIDR），`setup-mgmt-firewall.sh` 每次自動讀取（更新重跑不漏）。GUI 區塊 D 增刪即寫此檔。
- 評估過 nginx 反代收斂到 443（方案 C）但**否決**：jt-glogarch 無 `root_path` 不能掛子路徑，且無 DNS 不能用 subdomain。故採「限制來源網段 + 各服務自身認證」。
- 區塊 D「🔍 查詢」(`rules` action) 列出 ufw **實際**允許規則（含舊手動規則與 Anywhere），是排查曝險的真實視圖。

**NAS 備份（區塊 E / `manage-nas-backup.sh`，策略 B）**
- jt-glogarch 歸檔仍寫本地獨立碟 `/data/graylog-archives`，再用 systemd timer `oracle-nas-sync`（hourly/6h/daily）rsync 同步到 NAS。**NAS 掉線只影響同步，不影響歸檔**。
- 支援 NFS / CIFS，自動裝 `nfs-common`/`cifs-utils`、寫 fstab（**`nofail` 不卡開機**）；設定 `/etc/oracle-mon/nas-backup.conf`、CIFS 帳密 `/etc/oracle-mon/nas-credentials`(600)。
- rsync flags：`-rt --update --stats -i --no-perms --no-owner --no-group --omit-dir-times`
  - 差異性備份（rsync 預設 mtime+size 比對只傳變動檔）
  - `--update` 安全網：目標較新時跳過
  - `--stats -i` 給 GUI 顯示「檔案總數 N ｜ 實際傳送 M ｜ 跳過 K ｜ 傳送 X MB」
  - `--no-perms/owner/group` 相容 CIFS
- 從 stdout 解析 `Number of files` / `transferred` / `Total bytes` 注入回傳 JSON 給 UI。

---

## 區塊 F：Custom Map 自動刷新秒數（寫 config.php 機制）

LibreNMS 全域 `page_refresh` 影響所有自動重整頁面，但使用者常只想單獨調整 Custom Map。本區塊提供 GUI 設定 `custom_map_refresh`，**單獨覆寫 Custom Map 刷新間隔**而不影響其他頁面。

關鍵設計：
- **不走 `lnms config:set`**：custom config key 會被 `resources/definitions/config_definitions.json` 白名單擋下（拒收未定義 key）。直接維護 `/opt/librenms/config.php` 的 `$config['custom_map_refresh']`。
- **Blade fallback**：`custom-map-patch.py` 把 `custom-view.blade.php` 兩處 `{{$page_refresh}}` 改成 `{{ \LibrenmsConfig::get('custom_map_refresh', $page_refresh) }}`，未設定時自然 fallback。
- `set-custom-map-refresh.sh` set 時**先 `grep -vF` 刪光所有舊行再 append**，保證 N 次 set 後 config.php 仍只有 1 行（曾因 sed regex 失敗造成累積 4 行 bug）。
- 設完跑 `artisan config:clear` 讓 Laravel 立即讀新值。

⚠️ **同 pattern 可推廣到任何「LibreNMS 不在白名單的自訂 config」**：寫 config.php + blade `\LibrenmsConfig::get('<key>', <fallback>)` + `artisan config:clear`。

---

## UI 慣例（oracle-admin.php / oracle-dashboard.php）

- 純 **standalone PHP**（非 Blade）；改完**不需** `artisan view:clear`，但瀏覽器會快取 CSS/JS，測試時 `Ctrl+F5`。
- 深色主題；`.result-box` 必須有明確文字色（曾因繼承色在深底上對比過低看不到）。狀態用 `.ok`/`.err`/`.info` class 上色，列表類輸出依語意上色（如 ufw Anywhere→橘警示、mgmt-auto→綠）。
- 新增區塊照 A–F 既有模式：HTML 卡片 + `api(url,{action,...})`（POST JSON + `X-CSRF-TOKEN`）+ 後端回結構化 JSON。
- **區塊由上到下嚴格按 A→B→C→D→E→F 順序排**（曾因多次 insert 變亂，重排過）。
- **跳轉 URL 用 `${location.protocol}//`** 不要硬編 `http://`（將來切 HTTPS 不會壞）。
- **Bootstrap CSS 本地化**：`html/css/oracle-admin/bootstrap-5.3.2.min.css`，由 `install.sh` 首次安裝時 `curl` 下載。**禁止從外部 CDN 引用**（內網環境會壞）。
- LibreNMS 齒輪選單 label 是「**監控管理客製化設定**」（由 `menu-patch.py` 維護的單一真實來源；歷史名稱「Oracle 監控管理」已棄用）。

---

## 戰情室 panel 配置（`oracle-dashboard.php`）

每張 DB 卡片用 `display:grid; grid-template-columns:1fr 1fr` 渲染，**panel 順序由 `card()` 函數內 `body = healthPanel(m) + opsPanel(m) + dgPanel(m) + mvPanel(m) + tsPanel(m)` 的字串拼接順序決定**。當前 layout：

```
Row 1: 資料庫健康  | 即時運作指標   ← 最常看
Row 2: DATA GUARD | MV (snapshot)
Row 3: 表空間使用率／效能（.panel.full 橫跨）
```

要改順序就改 card() 的串接。每個 panel 用 `data-block="<key>"`，對應 `.dbcard.hide-<key> .panel[data-block=<key>]{display:none}`，給使用者單卡顯隱（齒輪選單）。新增 panel 要同步：① 新 panel 函數、② `card()` 串接、③ `BLOCKS` array、④ `#card-pop` 的 `.blk-toggle` checkbox、⑤ CSS hide rule。

**UI 慣例**：
- **流量燈小點**（`.dot-sm.green/yellow/red`）：閾值在 `gapDot()` / `lagDot()` / `sessDot()` / `pctDot()` 集中。改閾值改這幾個函數即可。
- **可折疊指標說明** 用原生 `<details class="helpbox">` + `<summary>`；body 用 `<dl>` grid 排成「名稱／解釋」兩欄，最下方 `.note` 是藍邊提示框。無 JS，刷新時會收合（這是 `innerHTML` 重建的特性，目前可接受）。
- **Tooltip ⓘ** 用 `.tip[data-tip="..."]`，`::after` 顯示 `attr(data-tip)`，純 CSS hover。換行用 `&#10;`。
- **statusOf()** 是卡片號誌（紅/黃/綠/灰）邏輯入口。新增「會讓整張卡轉紅/黃」的指標要在這裡加判斷，**並同步在 `generateWarnings()` 加底部警示文字**（兩者用同一閾值）。

---

## 區塊 A 表單與 oracle-save.php 的欄位同步

`oracle-save.php` 後端接受 `alias/host/port/sid/user/pass/label/enabled` 八個欄位。新增任何 DB 設定欄位時，**三處要同步加**，缺一個會「能存但 UI 沒入口可改」：
1. `oracle-admin.php` 區塊 A HTML（`#aFoo` input）
2. `loadConf()` 把 `d.DB_FOO` 寫回 input
3. `saveConf()` 把 input 值塞進 POST body

新增 DB 流程的「+ 新增 DB」展開表單在 `#addForm` 內（`#nAlias/#nHost/...`），對應 `addDb()` 與 `oracle-db-add.php`，獨立於區塊 A 編輯流程。

---

## 常用指令

```bash
# 部署 / 更新（都冪等）
sudo /opt/oracle-mon-librenms/install.sh        # 完整安裝
sudo /opt/oracle-mon-librenms/update.sh         # 只更新 code（保留 dbs/、RRD）

# 手動測試 admin 腳本（驗證 sudoers + 腳本邏輯；以 librenms 身分模擬 PHP-FPM 呼叫）
sudo -u librenms sudo -n /opt/oracle-mon/admin/test-db.sh l1hweb
sudo -u librenms sudo -n /opt/oracle-mon/admin/test-db-adhoc.sh 172.16.1.101 1521 L1HWEB librenms PASSWORD
sudo -u librenms sudo -n /opt/oracle-mon/admin/scan-old-ip.sh 172.16.1.94
sudo -u librenms sudo -n /opt/oracle-mon/admin/manage-mgmt-cidrs.sh list   # rules / add <cidr> / remove <cidr>
sudo -u librenms sudo -n /opt/oracle-mon/admin/manage-nas-backup.sh status # save/test/sync/unmount
sudo -u librenms sudo -n /opt/oracle-mon/admin/set-custom-map-refresh.sh get   # set <sec> / clear

# 防火牆：自動偵測本機網段開放管理埠（可攜，每站直接跑）
sudo /opt/oracle-mon-librenms/system/setup-mgmt-firewall.sh
sudo EXTRA_CIDRS="10.20.0.0/16" /opt/oracle-mon-librenms/system/setup-mgmt-firewall.sh

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

主 GUI `oracle-admin.php` 由六個區塊組成（**順序固定 A→B→C→D→E→F**）：**A** Oracle 連線設定、**B** monitor-vm IP 異動、**C** 多 DB 管理、**D** 防火牆管理網段、**E** NAS 備份、**F** Custom Map 自動刷新秒數。每個區塊對應一支 AJAX endpoint 與一支 admin shell：

| 端點 | 區塊 | 用途 | sudo shell |
|------|------|------|-----------|
| `oracle-admin.php` | — | 主 GUI（區塊 A–F） | — |
| `oracle-dashboard.php` + `oracle-dashboard-data.php` | — | 戰情室即時面板 | — |
| `oracle-save.php` | A | 儲存 DB 設定 | `save-db-conf.sh` |
| `oracle-test.php` | A/C | 連線測試（adhoc 表單值 / alias 已存檔雙模式） | `test-db.sh` 或 `test-db-adhoc.sh` |
| `oracle-db-add.php` / `oracle-db-remove.php` | C | 新增 / 刪除 / 啟停 DB | `save-db-conf.sh` / `remove-db-conf.sh` + `update-snmpd-extends.sh` |
| `oracle-ip-update.php` | B | monitor-vm IP 異動 + auto-scan 殘留 | `update-librenms-url.sh` + `scan-old-ip.sh` |
| `oracle-scan-old-ip.php` | B | 獨立掃描 endpoint（IP 殘留唯讀檢查） | `scan-old-ip.sh` |
| `oracle-firewall.php` | D | 管理網段 list/add/remove + 列出 ufw 實際允許來源(rules) | `manage-mgmt-cidrs.sh` |
| `oracle-nasbackup.php` | E | NAS 掛載/同步 status/save/test/sync/unmount | `manage-nas-backup.sh` |
| `oracle-custom-map.php` | F | Custom Map 自動刷新秒數 get/set/clear（寫 `config.php`） | `set-custom-map-refresh.sh` |
