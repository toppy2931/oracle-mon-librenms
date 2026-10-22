# IOC 智慧維運中心 — Phase 1：中文入口與事件案

本批交付「骨架＋維運總覽＋事件案」（規格 OPS、EVT-001～004、EVT-007、IS-011～018、RPT-007 的一部分）。
**尚未做**：跨來源關聯規則與自動建案（EVT-005）、跨系統時間軸與 ARP/FDB 對交換器埠（EVT-003 的後半、LOG-004）、
中文日誌查詢頁（LOG-002、003、006）、報表與匯出（RPT）。這些是 Phase 1 後續的切片。

## 1. 已確認的設計決定

| 項目 | 決定 | 理由 |
|---|---|---|
| 誰能用 | `admin` 可處理，`global-read` 唯讀，其他拒絕 | 沿用 LibreNMS 內建角色，不另建帳號系統；每支後端 API 都檢查（IS-013、IS-015） |
| 事件案存哪 | LibreNMS 資料庫，`ioc_` 前綴的資料表 | 備份跟著 LibreNMS 走、有交易保護；LibreNMS 升級不會動它 |
| Graylog 帳密 | 專用唯讀帳號 `ioc-reader` 的 Token | 入口有漏洞時不等於 Graylog admin（IS-012） |
| 先做哪段 | 骨架＋總覽＋事件案一起 | 使用者決定 |

## 2. 架構

```
瀏覽器 ──► /ioc-portal.php（頁面：登入＋角色檢查）
   │         │ fetch（X-CSRF-Token）
   │         ├─► /ioc-data.php   總覽資料（GET，快取 20 秒）
   │         ├─► /ioc-cases.php  事件案 API（POST；含 evidence：事件詳情的證據時間軸，唯讀查 Graylog）
   │         ├─► /ioc-settings.php  設定 API（POST；風險等級門檻、內部網段、站點與網段）
   │         ├─► /ioc-network.php  內網可視性 API（POST；admin 與 global-read 都能看，唯讀：東西向流量、Top Talkers、異常連線、IDS，查 Graylog，快取 60 秒）
   │         └─► /ioc-identity.php  身分與稽核 API（POST；只有 admin：帳號統計、稽核軌跡、主機遠端登入、帳號盤點紀錄）
   │                │
   │      includes/ioc/endpoint.php   登入、角色、CSRF、錯誤不洩漏內部資訊
   │                │
   │      includes/ioc/*.php   函式庫（不依賴 LibreNMS，本機測得到）
   │         ├ CaseService   權限、狀態流程、驗證、歷程 ── PdoCaseRepo ──► MariaDB（ioc_cases、ioc_case_history）
   │         ├ SettingsService  設定的權限、驗證、預設值、異動紀錄 ── PdoSettingsRepo ──► MariaDB（ioc_settings、ioc_settings_history）
   │         ├ OverviewService  整理畫面要的資料 ── GraylogClient ──► Graylog（唯讀 Token）
   │         └ Labels／Time／CaseView  中文名稱、UTC→台北、顯示轉換
   └ 設備資料：endpoint.php 直接查 LibreNMS 的 devices 表
```

函式庫裝在 `/opt/librenms/includes/ioc/`（不在 `html/` 底下，瀏覽器無法直接要求）；頁面與 API 自動由 `install.sh`／`update.sh` 的 `html/*.php` 迴圈部署；
選單項目加在 `system/menu-patch.py` 的 `BLOCK`（齒輪選單，僅 admin 看得到；global-read 用網址 `/ioc-portal.php`）。

### 檔案

| 路徑 | 用途 |
|---|---|
| `librenms/html/ioc-portal.php` | 中文入口頁面（深色，版面照參考畫面）。所有資料用 `textContent` 放進畫面，不用 `innerHTML` |
| `librenms/html/ioc-data.php`、`ioc-cases.php`、`ioc-settings.php`、`ioc-identity.php`、`ioc-network.php` | 總覽資料、事件案 API、設定 API、身分與稽核 API、內網可視性 API |
| `librenms/ioc/endpoint.php` | 端點共用前置檢查；只能在 LibreNMS 環境載入 |
| `librenms/ioc/*.php` | 純函式庫，`bootstrap.php` 依序載入 |
| `system/ioc-db-setup.php` | 建立資料表（`CREATE TABLE IF NOT EXISTS`，冪等），`install.sh`／`update.sh` 自動執行 |
| `system/ioc-db-selftest.php` | 在真正的 MariaDB 上驗證事件案與設定的 SQL（本機的單元測試用記憶體版，驗證不到）；設定那幾項在一個永遠回復的交易裡跑，不會動到正式的門檻 |
| `system/graylog-reader.py` | 建立唯讀帳號 `ioc-reader` 與 Token |
| `tests/php/`、`tests/test_php.py` | PHP 單元測試、語法檢查、一致性檢查 |

## 3. 權限

| 動作 | admin | global-read | 其他 |
|---|---|---|---|
| 看總覽、事件案列表與詳情 | ✅ | ✅ | ❌ |
| 建立事件案、變更狀態、留言 | ✅ | ❌（API 回 403） | ❌ |
| 看設定頁（風險等級門檻、異動紀錄） | ✅ | ✅（唯讀） | ❌ |
| 修改風險等級門檻 | ✅ | ❌（API 回 403） | ❌ |
| 身分與稽核（帳號與角色統計、稽核軌跡、主機遠端登入、帳號盤點紀錄的讀與寫） | ✅ | ❌（導覽不顯示，API 一律回 403） | ❌ |
| 看內網可視性（東西向流量、Top Talkers、異常連線、IDS；全部唯讀）；看內部網段設定 | ✅ | ✅ | ❌ |
| 修改內部網段 | ✅ | ❌（API 回 403） | ❌ |

畫面會依角色隱藏按鈕，但那不是權限控管；後端 `CaseService`、`SettingsService` 每個方法都先檢查，並有測試驗證檢視者直接打 API 會被拒絕且不留任何痕跡。

### 設定：風險等級門檻

入口左側「設定」頁（`#settings`）。總覽的整體風險分數（0–100，越高越好）依兩個門檻分成低、中、高：分數 ≥ 低風險門檻是低風險、≥ 中等風險門檻是中等風險、其餘高風險。預設 85／65，管理者可自己調。

- **規則**：兩個值都是 1～100 的整數，且中等風險門檻要比低風險門檻小；伺服器驗證（`SettingsService`），畫面只做預覽提示。儲存前畫面會用「目前的整體風險分數」預覽改完後的等級。
- **存放**：`ioc_settings`（沒有資料列＝用預設值，所以升級不改變既有行為）；每個實際變動的值各寫一筆 `ioc_settings_history`（誰、何時、原值、新值，只增不改不刪）。值沒變就什麼都不寫。儲存後會清總覽快取，畫面立刻用新門檻。
- **壞掉的資料不悄悄補預設值**：資料表裡只剩一個值、或有人手動改成不合法的值，總覽會只顯示分數、標「無法判定等級」並說明原因（否則管理者設了 95 卻顯示成低風險）；設定頁會顯示警告並填入預設值，重新儲存即修復。資料表讀不到時同樣處理。
- 分項權重與事件扣分（`RiskScore::WEIGHTS`、`CASE_PENALTY`）、站點異常等級的百分比目前仍是程式裡的常數，沒有放進設定頁。

## 4. 事件案

狀態流程（`CaseFlow`）：

```
待確認 ─► 已確認 ─► 處理中 ─► 已結案
  └────────────────────────────►┘      誤報等可直接結案
已結案 ─► 處理中                        重新開啟（清掉處理結果與結案時間）
```

- 結案一定要有處理結果：已處理、誤報、接受風險
- 歷程只增不改不刪（程式裡沒有更新或刪除它的路徑）：建立、狀態變更、留言，各記操作者、時間、前後狀態、處理結果、備註
- 負責人：第一次確認或開始處理的人自動成為負責人，之後不會被換掉
- 重複建立：自動建案（之後的關聯規則）用 `dedup_key` 去重，同一把鍵只會有一件，競爭時由唯一索引擋下
- 時間：資料庫存 UTC，顯示才轉台北時間（INT-072）
- `evidence_query`：記下能在 Graylog 重現這件事的查詢條件，用來追溯原始日誌（EVT-006）。目前不複製日誌內容，原始 Evidence 留在 Graylog（ARC-043）
- **事件詳情**（2026-10-20 改版，設計與各項資料來源見 [gui-from-proposal-deck.md](gui-from-proposal-deck.md) 第 7 節）：事件案多了事件類型、目的主機、關聯主機、影響範圍、標籤五個欄位；詳情頁依類型顯示 MITRE ATT&CK 技術與內建的建議處置（`CaseCatalog`，不存進案件），並用 `evidence_query` 向 Graylog 取事件建立前 24 小時到建立後 1 小時內的最新 30 筆日誌當證據時間軸（`EvidenceService`，唯讀、取不到明講原因）。檢視者也看得到證據，建立與處理仍只有 admin

## 5. 總覽

> **2026-10-20 版面已改版**（參照提案簡報，見 [gui-from-proposal-deck.md](gui-from-proposal-deck.md) 第 6 節）：總覽頁的 6 張 KPI 換成站點數、監控資產數、待處理事件、高風險站點、整體可用率、核心業務服務；「可疑登入事件」「誰在什麼設備上做了什麼」「最需要注意的帳號與來源」移到「資安」頁。下表的「今日整體運作」「待處理事件」「設備運作狀況」三張舊 KPI **畫面已不再顯示**（資料還在 `ioc-data.php` 的 `kpi` 裡，離線設備台數改顯示在「監控資產數」卡片），其餘區塊的資料來源與取不到時的處理不變。

| 區塊 | 資料來源 | 取不到時 |
|---|---|---|
| 今日整體運作 | 設備正常數＋「有資料的」日誌來源類型的正常數；尚未接入的來源不算在分母 | 「無法取得」 |
| 待處理事件 | 事件案資料表 | 「無法取得」（例如資料表還沒建立） |
| 可疑登入事件 | Graylog：`SUSPICIOUS` 清單內的 event_action 在 24 小時的筆數與涉及帳號數。登入類來源（Windows、Linux、網路設備）**一個都沒接入**時顯示「無法判斷」並說明（沒有日誌的 0 不是「沒有可疑事件」）；只接入一部分時照常顯示並註明哪些沒接入 | 「無法取得」 |
| 資料來源連線 | Graylog：各 `log_type` 最後收到資料的時間 | 「無法取得」 |
| 設備運作狀況 | LibreNMS `devices`（排除停用與忽略） | 「無法取得」 |
| 誰在什麼設備上做了什麼 | Graylog：最近 15 筆非防火牆的標準化事件 | 紅色虛線框＋原因 |
| 各類系統資料是否都有進來 | 同「資料來源連線」；≤10 分鐘＝正常接收、≤1 小時＝延遲、更久＝中斷、24 小時內完全沒有＝待接入、算不出時間＝無法判斷 | 紅色虛線框＋原因 |
| 最需要注意的帳號與來源 | Graylog：可疑事件依「帳號＋來源 IP」統計（次數 ≥10 高、≥3 中）；防火牆阻擋依來源 IP 統計，**內部來源逐一列出，外部只彙總成一個數字**，廣播與多播排除 | 紅色虛線框＋原因 |
| 需要關注的設備 | LibreNMS 異常設備（最多 20 台） | 紅色虛線框＋原因 |

**鐵則：資料取不到就明確標示，絕不顯示成 0 或正常**（OPS-006、MON-006、EVT-007、RPT-007）。各區塊各自成敗：Graylog 掛了，設備與事件案那幾塊照常。
測試涵蓋：Graylog 連不上、尚未設定唯讀帳號、設備讀不到、事件案讀不到、有筆數但算不出時間（不能算正常）、分組碰到上限（標「至少」）。

- **範例資料**：重播工具送的資料帶 `ioc_demo=true`。一般模式的每個查詢都排除它，`?demo=1` 只看它並顯示黃色橫幅（IS-054～056）
- **稽核準備度**：計算方式尚未定義，所以不顯示，也不編造數字
- **參數是經驗值**：新鮮度門檻、次數分級、KPI 的顏色門檻都寫在 `OverviewService` 的常數，依現場調整

## 6. 本機驗證了什麼、沒驗證什麼

| 項目 | 狀態 |
|---|---|
| 規則與邏輯（權限、狀態流程、驗證、歷程、總覽的缺值處理、顯示轉換、風險等級門檻的設定） | ✅ 206 項 PHP 單元測試（用 WSL 的 PHP 8.3，不碰任何資料庫；含事件類型目錄、新欄位驗證、證據時間軸、Graylog 絕對時間範圍、身分與稽核、內部網段與內網可視性） |
| Graylog 用戶端 | ✅ 對 PHP 內建伺服器做的假 Graylog：請求內容、每種失敗、逾時、整次載入的總時間預算（25 秒，避免 5 個區塊各等 15 秒而 504）、不跟隨重導向、http 只能連本機、錯誤訊息不洩漏網址與 Token |
| 唯讀帳號工具 | ✅ 13 項測試（對假的 Graylog；清單變動沿用同一個 Token、群組設不起來會警告） |
| 畫面 | ✅ 用暫時的測試台（真的頁面＋真的函式庫＋假資料）在瀏覽器實際操作：建立、確認、結案被擋、檢視者唯讀、Graylog 連不上、手機寬度；惡意字串只當純文字顯示 |
| 靜態檢查 | ✅ PHP 語法、JS 語法（node）、端點都呼叫 `ioc_begin()`、頁面不用 `innerHTML`、設定 JSON 的跳脫旗標、函式庫不在 `html/` |
| **LibreNMS 的登入、角色、CSRF、資料庫** | ⏳ 要到 VM 驗證（`endpoint.php` 在本機載不起來） |
| **事件案與設定的 SQL 與交易** | ⏳ 在 VM 跑 `ioc-db-selftest.php`（設定的 `ON DUPLICATE KEY UPDATE`、`LIMIT` 綁定、`ioc-settings.php` 的登入／CSRF／403 要到 VM 才驗證得到） |
| **Graylog 唯讀帳號的實際權限** | ⏳ 在 VM 跑 `graylog-reader.py create`；`streams:read:<id>` 這種權限寫法與 Reader 角色的實際效果要實機確認 |
| **Graylog 搜尋 API 的細節** | ⏳ `latest:timestamp` 指標、`group_by` 的 `limit`、`/api/search/messages` 的請求格式，都是依文件寫的，要實機確認；內網可視性另外用了 `sum`（位元組數）、`card`（不同的目的主機數）兩個指標、絕對時間範圍的彙整查詢（`aggregateBetween`）、`src_ip:(10.* OR …)` 這種前綴萬用字元查詢，**也都還沒在 VM 驗證** |

## 7. 在 VM 上驗證

先取得新版，`update.sh` 會部署函式庫與頁面、建立資料表、修補選單：

```bash
cd /opt/oracle-mon-librenms && git pull && sudo ./update.sh
```

**1. 資料表與資料層**（在真的 MariaDB 上，結束時會清掉自己的測試資料）：

```bash
sudo -u librenms php /opt/oracle-mon-librenms/system/ioc-db-selftest.php
```

預期 20 項 PASS，最後印「全部通過（測試資料已清除）」。其中 1 項驗證事件案新欄位（`ALTER` 補的欄位）能寫入並讀回；**「設備與站點」1 項與最後 2 項（身分與稽核）是只讀**（設備那項第一次對真實 LibreNMS 讀 `devices.ip`、`devices.overwrite_ip`：`ip` 是二進位欄位，失敗時訊息會指出欄位）；站點那 1 項（在設定的交易裡）會實際 `INSERT`、更新、`DELETE` 一個站點，並第一次對真實 MariaDB 跑 `SELECT … FOR UPDATE` 與異動紀錄的前綴篩選（`LIKE … ESCAPE '!'`），**正式的站點在交易裡會被整份取代掉，但整個交易最後一定 rollBack，不會動到**；用跟網頁一樣的 PHP 身分（librenms）實際讀 `users`、角色、雙因子、`authlog`、`/var/log/oracle-admin.log`，其中管理操作紀錄檔如果 librenms 讀不到會在這裡失敗並指出檔案（修法：`sudo chmod 640 /var/log/oracle-admin.log && sudo chgrp librenms /var/log/oracle-admin.log`）；7 項是設定（門檻）、帳號盤點、內部網段與站點：全部在一個永遠 rollBack 的交易裡跑，正式的風險等級門檻與異動紀錄不會被改到（網頁看不到測試值，程式中途被砍也不會留下），最後一項確認回復後與測試前一樣。

**2. 唯讀帳號**（需要 `/etc/oracle-mon/graylog-api.conf` 有 admin 帳密與 `GRAYLOG_FIREWALL_STREAMS`）：

```bash
python3 /opt/oracle-mon-librenms/system/graylog-reader.py create
```

預期印出「帳號已建立」與「Token 已建立並驗證」，不會印出 Token。失敗時會說是權限問題還是回應格式問題，把訊息貼回來。
之後可用 `status` 檢視（唯讀）、`remove` 回滾：

```bash
python3 /opt/oracle-mon-librenms/system/graylog-reader.py status
```

**3. 畫面**：用 admin 登入 LibreNMS，齒輪選單 →「IOC 智慧維運中心」（或直接開 `/ioc-portal.php`）。
這台沒有真實的 Windows 事件，Linux 與網路設備也只有重播資料，所以要看到那幾塊有內容，用 `/ioc-portal.php?demo=1`（範例資料模式）；正式模式下防火牆那幾塊會有真實資料。

檢查：
- 總覽的 5 張 KPI 與各表格都有值；「資料來源連線」裡 Windows 是「待接入」
- 建立一件事件案（可從「需注意帳號」按「建立事件案」帶入），依序改成已確認、結案（不選處理結果會被擋）
- 事件詳情（第二批）：從「資安」頁的「可疑登入的帳號」按「建立事件案」（會預選事件類型「可疑登入」並帶入查詢條件），建立後點開，應看到摘要列、攻擊技術 T1110、**證據時間軸有 Graylog 的日誌**（舊到新）、關聯指標（各來源筆數，弱點是「尚未接入」）、建議處置；沒填查詢條件的案件，時間軸寫「沒有填 Graylog 查詢條件」；暫時把 `/etc/oracle-mon/graylog-reader.conf` 改名後重新點開，時間軸應寫「無法取得：尚未設定 Graylog 唯讀帳號」，其餘區塊照常；`global-read` 看得到證據，沒有任何處理按鈕
- 用 `global-read` 角色的帳號登入：看得到總覽與事件案，沒有任何處理按鈕；直接打 API 回 403
- 暫時把 `/etc/oracle-mon/graylog-reader.conf` 改名：日誌相關區塊顯示「尚未設定 Graylog 唯讀帳號」，設備與事件案照常（看完改回來）
- 左側「身分與稽核」（只有 admin 看得到）：上方 5 張卡（啟用中的帳號、管理者、雙因子、久未登入、帳號盤點）、角色分布甜甜圈圖、治理檢查；久未登入的名單下面有註明登入紀錄最早到哪天；填帳號盤點紀錄按儲存，卡片變成「今天」、稽核軌跡多一筆「修改設定：帳號盤點…」；稽核軌跡有你剛剛的登入與管理操作，可依類別與結果篩選；「主機的遠端登入」在最近 24 小時沒收到 Linux／Windows 日誌時寫「沒有收到日誌（還沒接入，或已經中斷了）」。`global-read` 登入後導覽沒有這一項，直接打 `/ioc-identity.php` 回 403
- 左側「設定」頁：admin 能改風險等級門檻（儲存前會預覽等級、中等風險不能大於低風險），儲存後到「總覽」確認風險等級與說明文字用新門檻、「異動紀錄」多一筆；`global-read` 看得到但輸入框是停用的，直接打 `/ioc-settings.php` 的 `save` 回 403。看完把門檻改回原值
- **內網可視性的位元組數來自新的標準欄位 `net_bytes`**，要先讓 IOC 的防火牆 pipeline 套用新規則：`sudo ./update.sh` 會自動 `apply`（先用 `python3 /opt/oracle-mon-librenms/system/graylog-pipelines.py plan` 看唯讀的計畫：預期只有「規則 ioc-fw-bytes：新增」與「Pipeline IOC Normalize - Firewall：更新」）。套用後約 1～2 分鐘，到 Graylog 搜尋 `_exists_:net_bytes` 應該有新日誌（連線結束的 `close` 等），`net_bytes` 是數字。**只有套用之後進來的日誌才有這個欄位**，所以內網可視性的流量那三塊一開始只有幾分鐘的資料，趨勢前面幾格寫「沒有位元組數資料」，24 小時後才完整
- 左側「內網可視性」（admin 與 `global-read` 都看得到，全部唯讀）：先看 4 張 KPI（東西向流量佔比、異常 SMB 來源、異常連線、IDS 告警）是不是有值或有明確的原因；東西向趨勢 6 格、Top Talkers 前 5 名的位元組數大小合理；異常連線清單的每一筆（來源 → 目的、行為、風險）對得上你知道的內網狀況（例如掃描器、備份主機會被標成橫向探測，這是預期的，確認後可以忽略）。**這一頁是第一次對真實的 FortiGate 日誌跑 `sum`、`card` 與前綴萬用字元查詢，任何區塊寫「無法取得：Graylog 回應 HTTP 400…」就是查詢語法與 Graylog 版本不合，把訊息貼回來**。IDS 告警在 monitor-vm 上有數字（2026-10-21 實測 26，較前一個 24 小時 +13%：這台 FortiGate 有送 IPS／異常偵測日誌）；沒有 IPS 日誌的站點才寫「尚未接入」。異常連線清單的「目的」欄現在直接列內部主機 IP（最多 3 個，其餘寫「等 N 個」）：**這是第一次對真實 Graylog 做三層 `group_by`（來源 × 埠 × 目的）**，若 Graylog 不接受，畫面會退回「N 個內部主機」，清單下方多一行「目的主機的 IP 沒有全部查到……：原因」，而不是壞掉（有幾列補查成功卻一個目的也對不上時，原因會寫「有 N 列沒有查到目的 IP」）；看到這行把原因貼回來。`global-read` 沒有「建立事件案」按鈕
- 左側「設定」頁的「內部網段」：預設是三個私有位址網段；admin 改成你站點實際的網段（例如只有 172.16.1.0/24）存檔後，回「內網可視性」確認「內部網段：…」那行與清單都變了；填 `10.0.0.0/4`、空白、`abc` 都會被擋下並說明；異動紀錄多一筆。`global-read` 看得到但輸入框是停用的，直接打 `/ioc-settings.php` 的 `save_network` 回 403。看完改回你要的網段
- 左側「設定」頁的「站點與網段」（2026-10-22 新增，**這一項還沒在 monitor-vm 驗證**）：先看總覽的「站點數」「站點分佈」現在是只有 LibreNMS 位置的舊樣子（1 個站點、13 台沒有歸屬）；admin 新增一個站點「測試站點」＝`172.16.1.0/24`（你的設備所在網段）存檔，總覽自動重載：站點數增加、那些設備歸進去（排行與站點分佈都有它，離線比例合理）、「另有 N 台設備沒有歸屬站點」的 N 變小；異動紀錄多一筆「（新增）→ 測試站點：172.16.1.0/24」；稽核軌跡（身分與稽核頁）多一筆「修改設定：站點與網段」。再試：填空白名稱、`Unknown`、`abc` 網段、同一個網段放進兩個站點，都會被擋下並說明（一次列出所有問題）；改名後再存、刪掉後再存，異動紀錄各多一筆；設定了一個沒有設備的網段（例如 `203.0.113.0/24`）→ 總覽的站點區塊提示那個站點沒有設備歸進去。`global-read` 看得到但輸入框是停用的，直接打 `/ioc-settings.php` 的 `save_sites` 回 403。**看完把測試站點刪掉（或改成你要的站點）**。若某台設備的 IP 欄位在 LibreNMS 是空的（IPv6、沒探索過），它不會歸進網段站點，只能靠 LibreNMS 的位置

**4. 失敗時看哪裡**：PHP 錯誤在 PHP-FPM 的錯誤 log（`/var/log/php8.3-fpm.log` 或 nginx 的 error log），訊息以 `[ioc]` 開頭；
瀏覽器看到的只會是通用錯誤訊息，不含內部細節。

## 8. 回滾（要退掉 Phase 1 時）

這一批的變更屬於高風險（認證授權＋新資料表），所以回滾步驟寫在這裡。依序做，每一步都可以單獨停下：

```bash
# 1. 移除頁面、函式庫與選單（事件案資料表——包含第二批 ALTER 補的 5 個欄位，它們是可空欄位，留著不影響舊程式——與 Graylog 帳號不會動）
cd /opt/oracle-mon-librenms && sudo ./uninstall.sh

# 2. 移除 Graylog 唯讀帳號與 Token（刪帳號 Token 就失效），同時移除 /etc/oracle-mon/graylog-reader.conf
sudo python3 /opt/oracle-mon-librenms/system/graylog-reader.py remove

# 3. 只有確定不要事件案與設定資料時才做：刪資料表（事件案歷程表有外鍵，要先刪）。這一步不可復原，先備份
sudo mysqldump librenms ioc_case_history ioc_cases ioc_settings_history ioc_settings > /root/ioc-backup.sql   # 資料庫名稱依 /opt/librenms/.env 的 DB_DATABASE；以 root 走 socket 驗證
sudo mysql librenms -e 'DROP TABLE ioc_case_history; DROP TABLE ioc_cases; DROP TABLE ioc_settings_history; DROP TABLE ioc_settings;'
```

只想讓風險等級回到預設（85／65）、不退整個入口：在設定頁按「填入預設值」再儲存（會留異動紀錄，這是正規做法）。
只想取消自訂站點（總覽回到只依 LibreNMS 位置）：在設定頁把站點全部刪除再儲存；緊急時 `sudo mysql librenms -e "DELETE FROM ioc_settings WHERE skey LIKE 'site.%'"`（同樣不留異動紀錄；`sites.next_id` 計數器不會被這條刪掉，留著無害，之後新增的站點 id 也不會重用）。
緊急時才用 SQL：`sudo mysql librenms -e 'DELETE FROM ioc_settings'`（沒有資料列就用預設值），**這樣改不會留異動紀錄**，稽核軌跡會有缺口；事後請自己記下是誰、何時、為什麼改。

只想先停用入口、不刪任何東西：執行第 1 步即可（頁面與 API 都會消失，資料保留，重新 `update.sh` 就回來）。
Phase 0 的 pipeline 規則是另一套回滾：`python3 system/graylog-pipelines.py remove`。

## 9. 已知限制與後續

- 選單項目在齒輪選單（僅 admin 可見）；唯讀使用者要用網址進入。要不要給 global-read 一個頂層選單項目，之後再決定
- 事件案目前只能手動建立；自動建案隨關聯規則的切片一起做
- 防火牆阻擋每小時可達數萬到十萬筆，且有突發；入口一律彙總，不逐筆列出。KPI 不直接用 `fw_deny` 筆數
- `ioc_case_history`、`ioc_settings_history` 沒有資料庫層的防竄改（沒有觸發器）：只是程式裡沒有更新或刪除的路徑。要更強的保證需要另外限制資料庫帳號權限
- 兩位管理者同一秒儲存設定時是後者覆蓋前者；異動紀錄的「原值」是各自儲存當下讀到的值，這種極端情況下兩筆的原值可能對不起來（沒有樂觀鎖，單一站點的管理者人數不值得）
- 既有的 `oracle-db-add.php:40` 有同樣的 PHP 陷阱（`"別名「$alias」…"`，全形括號被當成變數名的一部分，訊息裡的別名會是空的），本批沒有動它
