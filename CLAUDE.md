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

⚠️ **`daily.sh`（LibreNMS 每日自動更新）會被 patch 過的檔案卡住**：daily.sh 內部對 `/opt/librenms` 跑 `git pull`，若 `menu.blade.php`／`custom-view.blade.php` 等有我們的本地修改，git 會因「local changes would be overwritten」直接 abort，導致 GUI 右上角出現 `Error: Daily Update Failed`（已實際發生過）。修復流程：
```bash
sudo -u librenms git -C /opt/librenms diff resources/views/layouts/menu.blade.php   # 先確認只有我們的 patch，不是別人手動改的東西
sudo -u librenms git -C /opt/librenms checkout -- resources/views/layouts/menu.blade.php
sudo -u librenms /opt/librenms/daily.sh                                             # 這次會正常跑完
sudo python3 /opt/oracle-mon-librenms/system/menu-patch.py /opt/librenms/resources/views/layouts/menu.blade.php   # 冪等，重新注入
sudo -u librenms php /opt/librenms/artisan view:clear
```
`custom-view.blade.php` 若也被卡住，比照辦理，改跑 `system/custom-map-patch.py`。

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
- **自己的狀態資料庫是 `/opt/jt-glogarch/jt-glogarch.db`**（SQLite，WAL 模式，同目錄下 `.db-shm`/`.db-wal`）——作業歷程、歸檔清單、通知設定、排程作業、行為稽核**都存在這裡，不是存在 `/data/graylog-archives` 或 Graylog 的 Mongo/OpenSearch**。`prepare-for-clone.sh` step 4 會一併清除（連同 `.session_secret`，避免所有 clone 共用同一把 session 簽署金鑰）。
- ⚠️ **若只清了 Graylog 的 MongoDB、沒清 `jt-glogarch.db`**：舊 DB 裡快取的 Graylog 認證狀態會失效，造成 jt-glogarch 背景呼叫 `/api/streams`、`/api/system` 持續 401，被判定 session 失效而不斷跳回登入頁（外觀像是「點歸檔清單就被登出」）。修法是停服務、砍掉 `jt-glogarch.db*`、重啟即可自動重建全新空白 DB，不影響登入（登入本身是即時打 Graylog API 驗證，不依賴這個 DB）。

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

# 出 OVA 樣板前：清空站點資料（受監控 DB 密碼/歷史/機器身分等），預設 dry-run
sudo /opt/oracle-mon-librenms/system/prepare-for-clone.sh              # 預覽，不刪任何東西
sudo /opt/oracle-mon-librenms/system/prepare-for-clone.sh --yes        # 真正執行（互動仍需輸入 CONFIRM）

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

## 樣板機（OVA）出廠預設帳密

`prepare-for-clone.sh` 只清 MongoDB 的 `graylog` 資料庫與 OpenSearch 索引資料，**不會動 `/etc/graylog/server/server.conf`**——Graylog 的 `root_password_sha2`（admin 密碼雜湊）是設定檔層級的資料，不受清除影響，會原封不動跟著 OVA 一起匯出。

**出廠預設帳密（每個新站點部署後務必立即更改）**：
- Graylog Web UI（`:9000`）：`admin` / `Graylog@2026!`
- jt-glogarch（`:8990`）：與 Graylog 同一組帳密（經由 Graylog API 認證，本身不存密碼）

**新站點首次開機後的預期行為（勿誤判為異常）**：
1. 因 MongoDB 資料已清空，Graylog 會顯示「Fresh installation detected」並在 `server.log` 印出一組**隨機一次性密碼**——這組密碼只用於背景 `GraylogCertificateProvisioningPeriodical` 跟本機 Data Node 自動做憑證配對，配對完成（約數分鐘內，`server.log` 會出現第二次 `Started listener bound to [0.0.0.0:9000]`）後自動失效。**正式登入一律用上面的出廠預設帳密，不要用這組隨機密碼**
2. **樣板已內建 Syslog UDP 514 / GELF UDP 12201 兩個 Input**（清除流程後手動於 System → Inputs 重新設定並保留在樣板內；舊版這裡寫 GELF TCP，monitor-vm 實測 `ss -lntu | grep 12201` 只有 udp，以實機為準），新站點開機後這兩個 Input 會直接跟著存在、不用重建，**但防火牆不會自動跟著開**——`setup-mgmt-firewall.sh` 預設 `MGMT_PORTS` 故意不含 514/udp、12201/udp（log 來源可能跨網段，無法用「本機網段」規則概括），新站點須依實際 log 來源另外手動 `ufw allow`
3. OpenSearch cluster 單節點必為 `yellow`（1 unassigned replica shard），因預設 index template 要求 1 個 replica 但單節點無處放置，非錯誤
4. 若要讓新站點強制走全新 Preflight 設定（不共用出廠密碼），需另外清空 `server.conf` 的 `root_password_sha2` 欄位

---

## Graylog 進階整合：FortiGate 解析 / GeoIP / jt-gelflow

樣板內建了一套「FortiGate CEF log → 結構化欄位 → GeoIP 定位 → jt-gelflow 視覺化」的完整鏈路，供新站點防火牆（FortiGate/PaloAlto）接進來時參考或直接沿用。

**FortiGate 欄位解析**（`System → Inputs → Syslog UDP 514 → Manage extractors`）：
FortiGate CEF 格式的 `key=value` 內容用 **6 個獨立 Grok pattern Extractor**（不是單一大 pattern）解析：`src=%{IP:src}`、`dst=%{IP:dst}`、`spt=%{NUMBER:spt}`、`dpt=%{NUMBER:dpt}`、`proto=%{NUMBER:proto}`、`act=%{WORD:act}`。這些是 CEF **標準**欄位（非 `FTNTFGT` 前綴的 Fortinet 自訂欄位），理論上通用於其他有支援 CEF 輸出的設備。**拆成多個獨立 Extractor 是刻意設計**：某類 log（如 deny）缺少某欄位時，單一欄位的 Extractor 不匹配就單純跳過，不會像一條大 pattern 那樣整筆解析失敗。

⚠️ **monitor-vm 實測（2026-10-20）與上面這段不同**：FortiGate 的 CEF 實際由 pipeline `fortigate_normalize`（`key_value()` 解析，產生 `srcip`／`dstip`／`action`…）處理，訊息被 stream `FortiGate Syslog`（規則：`message` 含 `deviceExternalId=`）收走並**移出 Default Stream**；第二條規則 `fortigate-extract-flow-fields` 另外產生 `source_ip`／`destination_ip`（註解寫明是 jt-gelflow 期望的欄位名）。**以實機為準**，完整內容與 stage 順序見 [docs/ioc/existing-graylog-config.md](docs/ioc/existing-graylog-config.md)。

**PaloAlto 尚未處理**：預設是 CSV 位置對應格式（非 key=value），欄位順序依 PAN-OS 版本/log 類型不同，需要實際樣本才能正確建對應規則，延後到有真實資料或客戶現場時處理。

**GeoIP 查詢**（繞開 Graylog 內建 GeoIP Resolver 的 UI bug）：
- ⚠️ **`System → Configurations → Geo-Location Processor` 這個全域設定介面，取消勾選「Enforce default schema」會觸發後端 bug**（`Couldn't parse cluster configuration ... GeoIpResolverConfig` 反序列化錯誤，Graylog 7.1.3 已驗證）。**改用 Lookup Table + Pipeline Rule** 完全繞開這個介面：
  1. `System → Lookup Tables` 建立 Data Adapter（`geoip-city-adapter`，type: Geo IP - MaxMind/IPinfo，File path: `/etc/graylog/server/geoip/GeoLite2-City.mmdb`）+ Cache（`geoip-cache`，node-local in-memory）+ Lookup Table（`geoip-lookup`）
  2. `System → Pipelines → Manage rules` 建兩條**獨立**規則（`GeoIP enrich FortiGate src`／`dst`），各自用 `when has_field("src")` 判斷、`then set_field("src_geo", lookup_value("geoip-lookup", to_string($message.src)))`
  3. **重要語法坑**：Graylog Pipeline Rule 的 `then` 底下**沒有 `if/then/end` 巢狀條件語法**（會報 `Unknown function if`），條件判斷只能寫在最上層的 `when`，需要「欄位存在才處理」的邏輯必須拆成多條獨立規則，不能塞進單一規則的 action 區塊裡
  4. 兩條規則放進同一個新建 Pipeline（例如 `GeoIP Enrichment`）的 Stage 0，**另外新建 Pipeline，不要動 `Default Routing` 那條 `Managed by Application` 的系統管理 pipeline**
  5. `lookup_value()` 回傳的 `single_value` 已經是 `"lat,lng"` 字串格式，可直接 `set_field`，不需要額外轉換
- ⚠️ **mmdb 路徑常被 UI 預先填錯**：實際檔案在 `/etc/graylog/server/geoip/GeoLite2-City.mmdb`（含 `geoip` 子目錄），設定表單有時會預填少一層目錄的錯誤路徑，存檔前務必核對。

**jt-gelflow 串接**（獨立上游專案，`/opt/jt-gelflow`）：
- 監聽 port **依 `config.json` 實際設定為準，不要假設官方文件範例值**——這台是 `UDP 12301` / `TCP 12302`（刻意跟 Graylog 自己的 GELF Input `12201`錯開避免衝突）
- Graylog 端建 **`System → Outputs` → GELF Output**（Destination host `127.0.0.1`、Port 依上一點確認的實際值、Protocol UDP），**掛到 `Default Stream`**（內建），欄位對應（jt-gelflow 設定 → 欄位對應）改成實際解析出的欄位名：來源/目的 IP 用 `src`/`dst`，GeoIP 座標用 `src_geo`/`dst_geo`
- ⚠️ **Graylog Input 精靈的陷阱**：用 `System → Inputs → Launch new input` 精靈新增 Input 時，精靈會**自動建立一個同名自訂 stream（如 `default_stream`）+ 一條 `Managed by Application` 路由規則**，把該 Input 的訊息導去這個自訂 stream、順便讓它們**不會進到內建 Default Stream**。若要讓所有流量單純留在內建 Default Stream，需要到 `System/Pipelines → Manage rules` 找到 `Input setup wizard routing rule` 描述的那條規則刪掉，並到 `System/Pipelines → Manage pipelines` 把附屬的空殼 pipeline 跟 `Streams` 裡對應的自訂 stream 一併刪除
- jt-gelflow 自己的官方測試腳本 `scripts/test_data_generator.py` **內部寫死用 UDP 送到 `localhost:12201`**（沒有 `--host`/`--port` 參數可覆寫），跟這台的 `12301` 對不上，**不要用它測試**，改用一行 Python `socket.sendto()` 手動送符合格式的測試封包

**OpenSearch Data Node Heap 大小**：
- 樣板預設在 `/etc/graylog/datanode/datanode.conf` 加了 `opensearch_heap = 8g`（原本這個 key 不存在，用內建預設值極小，曾觸發 `Data Node Heap Size Warning`）
- `8g` 是抓「新站點實體 RAM 下限 16G 的 50%」訂出的保守值，避免部署到記憶體較小的站點時 OOM。**若確認新站點實體 RAM ≥ 24G，部署後建議手動調高到 `11g`~`12g`**（RAM 的 50%）以取得更好效能，改完要 `sudo systemctl restart graylog-datanode`
- 驗證方式：`ps aux | grep opensearch | grep -o "\-Xmx[0-9a-zA-Z]*"`

---

## IOC 欄位標準化 pipeline（智慧維運中心 Phase 0）

把 Windows／Linux／防火牆／網路設備日誌統一寫入 `log_type` `event_action` `severity` `user_name` `src_ip` `dst_ip` `host_name` `target_name`，供之後的中文戰情入口讀取。欄位定義、事件代碼中文對照、實機驗證清單見 [docs/ioc/phase0.md](docs/ioc/phase0.md)。

- **規則由 `system/graylog-pipelines.py` 產生，經 REST 冪等套用**（`print`／`plan`／`apply`／`remove`）。**不要在 Graylog 介面手改 `ioc-*` 規則或 `IOC Normalize - *` pipeline**，下次 `install.sh`／`update.sh` 會蓋掉；要改就改該檔再重跑 `apply`。`remove` 只刪自己建立的，不動 GeoIP 那組。
- 帳密在 `/etc/oracle-mon/graylog-api.conf`（600）。檔案不存在時 `install.sh`／`update.sh` 直接略過，所以現有部署不受影響。工具**沒有**「關閉憑證驗證」選項（規格 IS-043），HTTPS 自簽請用 `GRAYLOG_CA_FILE`。
- 設計取捨：每條 pipeline 只有一個 stage（stage 不滿足 match 條件時後面的 stage 不會跑，fallback 鏈會悄悄斷掉）；規則互相獨立、條件互斥，不依賴同一 stage 內的執行順序；條件全寫在 `when`（同上方 GeoIP 的語法坑）。來源欄位名稱因 shipper 而異，用 `coalesce()` 列候選欄位。
- **防火牆 pipeline 是 stage 2，且要連到 FortiGate 的自訂 stream**：同一 stream 上的 pipeline 依 stage 編號跨 pipeline 一起往下跑，`srcip`／`dstip`／`action` 由既有的 `fortigate_normalize` 在 stage 0 產生，所以排在它之後；FortiGate 訊息又不在 Default Stream，所以設定檔要有 `GRAYLOG_FIREWALL_STREAMS=FortiGate Syslog`（標題或 ID，找不到會報錯不會默默略過）。**Windows pipeline 不可連到那個 stream**（它把 `source_ip` 當候選欄位，而 `fortigate-extract-flow-fields` 會產生同名欄位）。連線只增不減，不會拿掉別人手動連的 stream。
- **新增 `event_action` 代碼要同步補 `docs/ioc/phase0.md` 的中文對照表**，測試會擋。
- **驗證狀態**（2026-10-20，monitor-vm）：API 與規則語法已驗證，Windows／Linux／網路設備／防火牆用重播資料驗證過；防火牆規則也已用真實 FortiGate 流量驗證（stage 2、讀 `srcip`／`dstip`／`action` 都正常）；**缺帳號欄位的 Windows 事件尚待驗證**。結果與待辦見 phase0.md §4。這台沒有真實 Windows 事件。
- 測試：`python3 -m unittest discover -s tests`。Windows 上 `subprocess` 找 `bash` 會優先挑到 System32 的 WSL 啟動器（環境變數傳不進去、輸出被轉碼），`tests/test_phase0_tools.py` 的 `find_bash()` 已排除。
- `tools/replay-sample-logs.py` 沒有預設目標，避免誤打正式環境；送出的每筆都帶 `ioc_demo=true`，入口與報表必須排除（規格 IS-054～056）。
- `system/ioc-preflight.sh` 是上線前唯讀自檢（出廠密碼、HTTP/HTTPS、設定檔權限、pipeline 是否已套用）。

---

## 中文入口：維運總覽與事件案（智慧維運中心 Phase 1）

`/ioc-portal.php`（`ioc-data.php`、`ioc-cases.php`、`ioc-settings.php`）。設計、權限矩陣、各區塊資料來源、VM 驗證步驟見 [docs/ioc/phase1.md](docs/ioc/phase1.md)；提案簡報要求的畫面項目（外框、總覽、事件詳情、弱點、組態、稽核）與各項的資料來源、整合順序見 [docs/ioc/gui-from-proposal-deck.md](docs/ioc/gui-from-proposal-deck.md)。**這一塊不走上面的「PHP → sudo shell」信任鏈**：它只讀（Graylog、LibreNMS 設備）與寫自己的資料表（事件案、設定），沒有特權操作。

- **邏輯與 LibreNMS 分開**：`librenms/ioc/*.php` 是不依賴 LibreNMS 的純函式庫（裝在 `includes/ioc/`，不在 `html/`，瀏覽器要不到）；只有 `endpoint.php` 與頁面接 LibreNMS（`Auth`、`csrf_token`、資料庫）。新的規則一律寫在函式庫並補 `tests/php/` 的測試；`endpoint.php` 只管登入、角色、CSRF、錯誤回應。
- **權限在後端**：`admin`＝處理者、`global-read`＝檢視者，`CaseService` 每個方法都先檢查（IS-013）。頁面隱藏按鈕不算權限控管。新增 API 動作時，`tests/test_php.py` 會檢查每支端點都先呼叫 `ioc_begin()`。
- **鐵則：資料取不到就顯示「無法取得」，絕不能變成 0 或正常**（OPS-006、EVT-007、RPT-007）。各區塊各自成敗；`OverviewService::guard()` 只攔 `GraylogUnavailable`，其他例外是 bug，要往上拋。
- **畫面一律用 DOM＋`textContent`，不用 `innerHTML`**：日誌內容來自外部，放進畫面就是 XSS。`tests/test_php.py` 會擋。內嵌在 `<script>` 的 JSON 要用 `JSON_HEX_TAG|AMP|APOS|QUOT`。
- **Graylog 用專用唯讀帳號**：`system/graylog-reader.py create` 建立 `ioc-reader` 並把 Token 寫到 `/etc/oracle-mon/graylog-reader.conf`（root:librenms 640）。入口不碰 admin 密碼。Graylog 的搜尋彙整 API **一定要有 `group_by`**（只有 `metrics` 會回 400）；要算總數就 group 一個一定存在的欄位再加總。重跑 `create` 若只是 stream 清單變了，會**沿用原 Token** 只更新設定檔（舊 Token 不會自動作廢，每次換新就會留下沒人記得的有效憑證）。`GRAYLOG_URL` 只有本機位址可以用 http，其他一定要 https（Token 在每個請求的標頭裡）。
- **Graylog 的使用者 API：GET／DELETE 用帳號名稱，建立 Token 與修改使用者的路徑吃 userId（24 碼）**——用帳號名稱去打 `POST /api/users/<名稱>/tokens/<name>` 會得到 HTTP 500 `state should be: hexString has 24 characters`（monitor-vm、Graylog 7.1 實測，2026-10-20）。`graylog-reader.py` 先 `GET /api/users/ioc-reader` 取 `id` 再用；修改權限與刪除帳號後都會再讀一次確認真的生效，不相信「回 204」。`tests/helpers.py` 的假 Graylog 已照這個行為實作，改這支工具時別放寬。
- **Graylog 分組查詢缺值時，欄位值是字串 `(Empty Value)`**（monitor-vm 畫面實測：「alice · (Empty Value)」）。`GraylogClient::rows()` 把分組欄位的這個字串轉成 `null`，入口顯示成空字串；不處理的話畫面會出現假的來源位址，從那一列「建立事件案」還會把它帶進 `subject_ip`（驗證不過）與查詢條件。
- **「沒有事件」不等於「沒事」**：Windows、Linux、網路設備都沒接入時（正式模式目前只有防火牆有資料），「可疑登入事件」是「無法判斷」而不是綠色的 0，「誰在什麼設備上做了什麼」與「可疑登入的帳號」的空白訊息也會說明哪些來源沒接入。`OverviewService::build()` 的 `login_sources`（`missing`、`all_missing`）就是給畫面用的；新增會產生登入事件的來源類型要同步加進 `LOGIN_SOURCES`。
- **Graylog 慢或卡住時不能讓頁面 504**：`GraylogClient` 一次畫面載入共用 25 秒的總預算（單次 15 秒），用完後的查詢直接回報「逾時」；沒有這個上限，5 個區塊各等 15 秒會超過 nginx 的 60 秒（同 8f0ce05 修過的 Oracle 探測）。新增區塊要走同一個 `LogSource`，不要另外開連線。
- **事件案與設定異動要清總覽快取**：總覽有 20 秒伺服器端快取（含事件案件數與算好的風險等級）；會改件數的動作（建立、狀態變更）與儲存設定都要呼叫 `ioc_overview_cache_forget()`，否則 KPI 會落後分頁角標、改了門檻的畫面還是舊等級（測試會擋）。
- **事件詳情**（`CaseCatalog`、`EvidenceService`、`WindowedLogSource`，設計見 [docs/ioc/gui-from-proposal-deck.md](docs/ioc/gui-from-proposal-deck.md) 第 7 節）：事件類型目錄 `CaseCatalog::CATEGORIES` 是 ATT&CK 技術與建議處置的**單一來源**（提案版，內容請使用者調）；案件只存類型代碼（`ioc_cases.category`），沒有類型、舊案、不認得的代碼一律當 `other`。新增的 5 個事件案欄位（`category`、`target_host`、`related_host`、`impact_scope`、`tags`）是用 `ALTER TABLE … ADD COLUMN IF NOT EXISTS` 補的，**加欄位要同步 `PdoCaseRepo::CASE_COLUMNS`、`ioc-cases.php` create 的 `ioc_pick` 白名單與建立表單**（測試會擋漏掉的）。證據時間軸要看「事件前後」，所以 Graylog 查詢有絕對時間範圍的介面 `WindowedLogSource`（`GraylogClient`、`UnavailableLogSource` 實作；`LogSource` 的相對時間查詢與其測試替身不動）；`messagesBetween`／`aggregateBetween` 只收嚴格的 UTC ISO 8601（用 `/D` 擋結尾換行）。證據查詢**一定**包 `AND NOT ioc_demo:true`（demo 模式才是 `ioc_demo:true`），所以 `evidence_query` 在建立時要檢查括號與雙引號成對（`CaseService::isBalancedQuery`），不然 `x) OR (y` 這種條件會跳出這層包裝、把範例資料混進證據；日誌沒有 `event_action` 時寫「尚未分類的日誌」、不帶出原始訊息；`EvidenceService` 只攔 `GraylogUnavailable`，取不到回 `ok:false`＋原因（`no_query` 是沒填查詢條件，不是無法取得）。`evidence` 動作先 `CaseService::get`（檢視權限）才查 Graylog，檢視者可用、不能建立或處理。
- **風險等級門檻是使用者可調的設定**（`SettingsService`＋`ioc-settings.php`，入口左側「設定」頁）：預設 85／65（`RiskScore::DEFAULT_THRESHOLDS`，唯一來源），存在 `ioc_settings`，每次變動記 `ioc_settings_history`。**畫面上的說明文字不能寫死門檻數字**，要用伺服器回的 `risk.data.thresholds`（測試會擋）；`RiskScore::compute()` 的門檻參數**沒有預設值**，`LandscapeService::build()` 的也是——門檻讀不到（資料表沒建、值被手動改壞）要傳 `null`，畫面只顯示分數、標「無法判定等級」，**不能套預設值冒充**（不然管理者設了 95 卻顯示低風險）。只有 admin 能存（`SettingsService::saveRisk` 先 `requireHandler`），檢視者看得到但唯讀。要加別的設定就在 `SettingsService` 加讀寫與驗證，同樣先檢查權限、儲存後清快取。
- **CSS 陷阱**：元素設了 `display:…` 就會蓋掉瀏覽器內建的 `[hidden]{display:none}`，`hidden` 屬性失效；頁面已加全域 `[hidden]{display:none!important}`。LibreNMS 資料表的時間（例如 `devices.last_polled`）是主機本地時間，不是 UTC，轉台北時間要用 `Time::toTaipei($v, 'Y-m-d H:i:s', date_default_timezone_get())`。
- 之後要加欄位：在 `Schema::statements()` 最後面另加 `ALTER TABLE … ADD COLUMN IF NOT EXISTS`（只改 `CREATE TABLE` 對已部署的主機沒有效果），並同步 `PdoCaseRepo` 的欄位白名單。
- **總覽的站點、資產、風險分數**（`Fleet`、`RiskScore`、`LandscapeService`，版面參照提案簡報）：「站點」＝LibreNMS 設備的「位置」（Location），沒有指定位置的設備另外計數、不屬於任何站點；**設備自己用 SNMP 回報的預設位置（`Unknown`、`End User Premise.`、`Rack, Room, Building, City, Country [Lat, Lon]`、`Unknown (edit …)`）LibreNMS 也會建成位置，但不是人設定的站點，`Fleet::realLocation()` 視為沒有指定**（monitor-vm 實測：14 台只有 1 個真的站點「機房」）；設備資料由 `endpoint.php` 的 `ioc_fleet()` 讀（`devices` 對 `locations`、`availability` 表），讀不到整個回 null，畫面標「無法取得」。風險分數是**提案版**（權重與扣分是 `RiskScore` 的常數，測試把數字釘住，要調就一起改測試；**等級門檻不是常數，是設定頁可調的設定**，見下一條）；某分項沒資料就不納入、權重重新分配，全都沒有是「無法計算」。簡報有、但沒有資料來源的項目（弱點、組態、稽核…）導覽顯示成停用「尚未提供」，**不要放能點進去的空白頁或假數字**。整體說明與還沒做的項目見 [docs/ioc/gui-from-proposal-deck.md](docs/ioc/gui-from-proposal-deck.md) 第 6 節。
- **新增 `librenms/ioc/` 的類別要同步加進 `bootstrap.php` 的載入清單**（測試會擋）；改了 `ioc-data.php` 回傳的形狀要升 `IOC_OVERVIEW_CACHE` 的版號，否則更新後 20 秒內舊快取會讓畫面缺欄位。畫面的 KPI 卡片沒有值時，大字用 `na`（例如「尚未定義」「尚未接入」）說明原因，沒有 `na` 才是「無法取得」（讀取失敗）。
- **PHP 陷阱：變數後面緊接全形標點（`"$x，"`、`"$x」"`）會讓全形字元被當成變數名稱的一部分**，變數變成未定義、訊息變空。要寫 `{$x}`。`tests/php/run.php` 把任何 PHP 警告當成失敗，就是為了抓這種問題。既有的 `oracle-db-add.php:40` 也有（尚未修）。
- **資料表**：`ioc_cases`、`ioc_case_history`、`ioc_settings`、`ioc_settings_history`（`librenms/ioc/Schema.php`，`CREATE TABLE IF NOT EXISTS`，`install.sh`／`update.sh` 自動建立；新增資料表要同步加進 `system/ioc-db-setup.php` 的 `$tables`，測試會擋）。時間存 UTC，顯示才轉台北。兩張歷程表只增不改不刪（測試會擋函式庫裡出現 UPDATE／DELETE）。`uninstall.sh` 不會刪它們。
- **本機驗證**：這台 Windows 沒有 PHP，用 WSL 的 PHP 做語法檢查與不碰資料庫的邏輯測試（`tests/test_php.py` 會自動找 `php` 或 WSL 的 `php`）；**不要在 WSL 裡的 LibreNMS 與資料庫做任何寫入**。LibreNMS 的登入／CSRF／資料庫與事件案、設定的 SQL 要到 VM 驗證（`system/ioc-db-selftest.php`，14 項；設定那幾項在永遠 rollBack 的交易裡跑，`PdoSettingsRepo::transaction()` 會加入外層交易，別改回直接寫正式資料）。
- 畫面可用暫時的測試台驗證（真的頁面＋真的函式庫＋假資料）：用 `php -S` 載入真的 `ioc-portal.php` 的 HTML，把最前面的登入與設定那段換成測試台的版本，再用瀏覽器操作；這種方式抓到過 `replaceChildren(null)` 會插入文字「null」的錯誤。測試台不進 repo。

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
| `ioc-portal.php` + `ioc-data.php` + `ioc-cases.php` + `ioc-settings.php` | — | 中文入口：維運總覽、事件案、設定（風險等級門檻）（admin 處理與修改、global-read 唯讀；不走 sudo） | — |
