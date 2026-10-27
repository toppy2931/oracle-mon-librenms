# 裝置行為分析：試行部署與驗收

此模組以 FortiGate 經 Graylog 正規化的防火牆日誌開始試行。資料只涵蓋經過防火牆的對外流量；同一網段內未經防火牆的連線不可見。`443` 目的埠僅代表埠號，沒有 DNS／TLS 訊號時不推斷網站或 HTTPS。此功能提供可解釋的統計異常，不會自動封鎖設備。

## 資料流程

`LibreNMS device_id → IP 使用歷程 → Graylog 五分鐘彙整 → 啟用監控時訓練基線 → 正式警示 → IOC 事件案 → 簽章 Webhook`。

- 管理員先在「設定」維護內部網段，並在「裝置行為分析」為設備、站點或「預設」設定時區與上班時段。套用順序：設備 → 站點 → 預設；都沒設時用內建預設（台北、週一到五 09:00–18:00），所以不必先設定時段也能啟用監控；工作台的資產詳情與「啟用監控」確認視窗會寫出這個資產實際用的時段與來源（設備、站點、自訂預設或內建預設），還在用內建預設時特別提醒。變更既有時段會讓「實際套用它而且已在監控中」的資產改回學習中，需要重新按「啟用監控」（監控暫停），舊基線及歷程保留；學習中的資產不受影響（啟用時才讀時段，沒有任何東西依賴舊時段）。儲存前工作台會先預覽影響台數，請管理員確認。
- 未知來源 IP 建立暫時資產（「待確認」）。同 IP 若超過 24 小時未觀測，重新出現時另建暫時身分。暫時資產只累積區間，不能啟用正式監控；管理員要先把它確認為受管主機（填寫 DHCP 租約、資產清冊或人工核對依據，見下方「已確認主機」），確認後它從「裝置與待確認」移到「已確認主機」分頁。LibreNMS 設備不需要確認，也不跟主機做人工關聯（2026-10-27 移除，見「人工關聯已移除」）。原暫時資產及活動紀錄仍可點查。
- 每個完整五分鐘區間彙整連線數、位元組、目的地數與目的埠數。來源中斷、必要欄位缺值、位址衝突或分組達上限時標為缺資料／不完整，受影響區間不進基線與偵測。
- 這裡的「連線數」以 FortiGate 轉送流量日誌筆數近似，並非即時並行連線數；須用實機日誌核對每筆代表的 session 範圍。
- IOC 會對同一時段重跑與同類警示去重，但 Graylog 若把同一筆設備日誌重複匯入，彙整數值仍可能放大。試行驗收須檢查日誌來源的重複率及延遲，確認可辨識的事件 ID 後才可設計來源層去重。
- **沒有強制的學習天數。** 管理員檢查資料品質與來源後，在資產詳情按「啟用監控」：先唯讀預覽資料量與涵蓋度（幾個完整區間、涵蓋幾天、幾個離峰時段、有幾格有連線），確認後伺服器**立即用現有資料訓練基線並啟用監控**；資料不夠就擋下並寫出缺多少。沒有觀測到該裝置的流量會標示為缺資料，不能只靠全域來源心跳判為零流量。「重新學習」重設學習起點並清除已知目的地，之後訓練只用重設之後的區間，監控中的會改回學習中，要重新按「啟用監控」；重新學習期間不使用舊基線判斷。詳見下一節「啟用監控與基線」。
- 正式警示依裝置、偵測類型與小時去重，建立 IOC 事件案。管理員可填理由確認或結案。事件案及警示是不同紀錄，處置時應分別檢查。
- Webhook 投遞失敗會自動退避重試（間隔 1、2、4、8 分鐘），**共 5 次嘗試**（首次加 4 次重試），第 5 次失敗就標 `failed`；管理員排除原因後可在警示詳情手動重試（次數歸零）。
- 模式、警示處置、時段、來源盤點與主機確認寫入操作稽核；不記錄密鑰及原始日誌。
- AI 擴充契約為 `BehaviorAnalyzer::aiFeatures()` 與 `BehaviorAiProvider`，只含統計特徵、品質與基線差異，不含 IP、網域、帳號、原始日誌或組態；目前沒有接入 AI 模型。AI 的建議不能改寫正式警示、事件案或設備設定。

## VM 更新

在專案根目錄執行既有的 `sudo bash update.sh`。更新程序建立資料表、安裝 `ioc-behavior.timer`，並部署 PHP API、前端與工作器。檢查：

```bash
systemctl status ioc-behavior.timer --no-pager
systemctl list-timers ioc-behavior.timer --no-pager
sudo -u librenms php tests/php/run.php
sudo journalctl -u ioc-behavior.service --since '30 minutes ago' --no-pager
```

`ioc-behavior.service` 以 `ProtectSystem=strict` 執行，整個檔案系統唯讀；它啟動 LibreNMS 時需要寫設定快取，所以單元檔只開放一個目錄：`ReadWritePaths=-/opt/librenms/storage/framework/cache`（前綴 `-`：目錄不存在時略過，服務照樣能啟動）。沒有這一行時，日誌每 5 分鐘（快取過期時）會出現 `file_put_contents(…/storage/framework/cache/…): Failed to open stream: Read-only file system … ConfigRepository.php:55`；那只是警告，採集本身仍會成功。更新後確認設定已生效（輸出應包含該路徑）：

```bash
systemctl show ioc-behavior.service -p ReadWritePaths
```

再等 10 分鐘以上，確認警告不再出現（預期輸出 `0`；`--since` 只看更新之後，更新前殘留的舊警告不算）：

```bash
sudo journalctl -u ioc-behavior.service --since '10 minutes ago' --no-pager | grep -c 'Read-only file system'
```

不要把可寫路徑放寬成 `/opt/librenms` 或整個 `storage`：採集程式一旦出問題，就能改動 LibreNMS 的程式與紀錄。

若要通知管理員，在 VM 以 root 執行 `/opt/oracle-mon/admin/configure-behavior-webhook.sh`，輸入 HTTPS URL，將顯示一次的 HMAC 密鑰安全交給接收端。密鑰儲存在 `/etc/oracle-mon/ioc-behavior-webhook.conf`，權限 `root:librenms 0640`；網頁與資料庫不保存密鑰。接收端驗證 `X-IOC-Timestamp` 與 `X-IOC-Signature`，簽名輸入是 `timestamp + '.' + 原始 JSON body`，演算法 HMAC-SHA256；並依 `X-IOC-Event-ID` 去重。接收端應拒絕過期時間戳。

## 試行驗收

1. 確認 Graylog 有 `log_type:firewall`、`cat:"traffic:forward"`、`src_ip`、`dst_ip`、`dstport` 及數字 `net_bytes`。IOC 的防火牆 pipeline 只對新進日誌寫入 `net_bytes`，舊資料不補寫。
2. 在工作台確認受監控設備、目前 IP、暫時身分、來源狀態與五分鐘區間品質。若來源未送資料，畫面須顯示缺資料，不能顯示正常或零流量。
3. 設定上班時段後，以經核對的資料回放正常維運、離峰流量暴增、新目的地、重複日誌、IP 更換與來源中斷。檢查模擬警示的指標及來源涵蓋說明。
4. 基線與來源就緒後，由管理員切換一台測試裝置到監控。檢查事件案去重、Webhook 簽章、接收端重送去重、投遞失敗重試及管理員處置歷程。
5. 記錄「日誌進入 IOC → 產生警示 → 首次投遞」的實測時間。五分鐘內是目標，須由真實流量回放與接收端可用性驗證；未量測前不得宣稱達標。

## 感測器擴充門檻

在工作台盤點 SPAN、TAP、Flow、DNS 的交換器／網段與可用性，盤點狀態不代表已接入。接入 Zeek 或 Flow 前，須先以實機驗證鏡像方向、封包遺失、時間同步、DNS／TLS 欄位、同網段流量涵蓋與和防火牆日誌的重疊去重。新來源要有獨立適配器與資料品質測試，再啟用為正式採集來源；不可只把盤點狀態改成「就緒」。

目前不自動刪除五分鐘彙整與歷史基線；維運時需監看資料庫容量與備份。原始 Graylog 日誌不複製到 IOC 資料庫；目的地 IP 只保存在受限的來源特徵表，不回傳檢視者摘要，也不送 Webhook。

## 已確認主機（M2a）

有對外流量、但不在 LibreNMS 的主機，由管理員確認後也能訓練基線與啟用監控：

1. 工作台「裝置與待確認」分頁列出「待確認」的暫時資產（伺服器端分頁與排序，每頁 10／25／50／100 筆，欄位標題可點排序）。可勾選目前這一頁的多台（換頁會清除勾選），填寫確認依據（DHCP 租約、資產清冊或人工核對結果）；也可在單一資產的詳情頁確認並自訂名稱（沒填就用「主機 <位址>」）。名稱與位址會出現在事件案，檢視者也看得到：請填用途或編號，**不要填人名**。整批在同一個交易裡（依識別碼由小到大處理，兩位管理者同時確認不會死結），任何一台不合格就全部不動。確認後該台從這個分頁消失，出現在「已確認主機」分頁。
2. 確認後既有的五分鐘區間與已知目的地都保留，學習起點不重設；之後同一個 IP 不受 24 小時限制（缺席再久回來都還是同一台）。確認寫入操作稽核與資產歷程。
3. 採集器依 IP 落在哪個「站點與網段」替觀測主機填站點（沒對到就是未歸屬）；站點之後變了才重新學習。
4. 已確認主機與 LibreNMS 設備一樣，資料量足夠就能在詳情頁按「啟用監控」（見「啟用監控與基線」）；身分條件是「有 LibreNMS 設備，或已確認」。待確認的暫時資產不能啟用監控。
5. 已確認主機的身分只靠 IP 維繫，沒有 MAC／DHCP 證據，兩個方向都要留意：主機換位址後會被當成新的暫時資產；**位址被 DHCP 租給別台機器時，新機器的流量會算進這台已確認主機**。所以隔 24 小時以上才又出現的已確認主機，歷史會留一筆 `reappeared`（寫出隔了幾天），請在資產詳情的歷程確認仍是同一台；不是的話請在「已確認主機」分頁按「移除」。
6. **移除。** 「已確認主機」分頁每列有「移除」：確認後把它設成已移除（`retired`），不再追蹤與監控，歷史與警示紀錄保留，兩個清單都不再列出；之後同一個位址的流量會成為新的待確認主機。已移除的主機不能啟用監控（兩個清單都不列它，畫面沒有入口；直接呼叫 API 會被擋下）。詳情頁沒有「停用追蹤」，只有「重新學習」與「啟用監控」。

部署後在 VM 確認（先唯讀）：

```bash
sudo mysql librenms -e "SHOW COLUMNS FROM ioc_behavior_assets LIKE 'confirm%';"
```

預期 3 列（`confirmed_at`、`confirmed_by`、`confirm_note`）；然後在工作台確認一台測試主機，再看：

```bash
sudo mysql librenms -e "SELECT id, name, mode, confirmed_by, confirmed_at FROM ioc_behavior_assets WHERE confirmed_at IS NOT NULL;"
```

## 啟用監控與基線

狀態只有 `learning`（學習中）、`monitoring`（監控中）與 `retired`（已移除），沒有 `ready`，採集器也不會自動訓練基線。

1. **啟用監控＝立即訓練。** 詳情頁按「啟用監控」，畫面先呼叫唯讀的 `preview_baseline`，顯示「用 N 個完整區間（X 天）訓練，涵蓋 K 個離峰時段；沒涵蓋的時段不判斷」與上班時段來源，管理員確認後 `set_mode` 才在同一個交易裡訓練新版基線、把資產設成監控中，並寫歷程 `baseline_ready`、`mode_changed` 與稽核。已在監控中不重做。
2. **只用相關的區間。** 品質完整（`complete`）、最近 28 天內（`BehaviorService::BASELINE_MAX_DAYS`）、在學習起點之後結束的區間；所以「重新學習」之後舊資料不會進新基線。
3. **最低資料量（提案值，可調）：** 完整區間 ≥ 144 格（約 12 小時），其中有連線的 ≥ 12 格（`BehaviorAnalyzer::BASELINE_MIN_WINDOWS`、`BASELINE_MIN_ACTIVE_WINDOWS`）；不達標就擋下並寫出現有幾格、至少需要幾格。偵測本來就要求每個離峰時段桶樣本 ≥ 6（`BASELINE_MIN_SAMPLES`），沒涵蓋的時段不判斷，所以資料少只是涵蓋少、不會誤報；一個離峰時段都沒涵蓋時，預覽會警告「啟用後不會判斷離峰異常」。
4. **其他條件：** 身分（LibreNMS 設備或已確認）、已移除的不能直接啟用，以及防火牆日誌來源狀態為 `ready` 或 `partial`、而且 15 分鐘內有更新；`error`、`missing`（沒收到日誌）、其他狀態或過期都擋下。
   **`partial` 可以啟用**（2026-10-27 起）：來源 `partial` 只代表「有些區間不完整」（缺 `net_bytes`／目的 IP／目的埠，或分組被截斷）；多主機的環境裡任何一個資產有一格不完整，整個來源就是 `partial`，這台 VM 幾乎一直如此。原本要求必須 `ready`，結果沒有任何資產啟用得了（VM 驗證時第一次按就被擋）。訓練只用完整區間、資料量由第 3 點的檢查把關，所以來源 `partial` 不需要擋。
5. **副作用：** 不再先經過「模擬警示」階段，風險靠啟用前的涵蓋度確認與每小時去重降低。舊資料裡的 `preview` 模擬警示照常顯示，不再產生新的。
6. **站點、時段變更：** 監控中的資產站點歸屬或套用的時段改變時改回學習中，需要重新按「啟用監控」。
7. **資料遷移：** `Schema::statements()` 末尾有一句冪等的 `UPDATE ioc_behavior_assets SET mode='learning',quality_status='learning' WHERE mode='ready'`，把舊版的「待啟用」資產遷回學習中（基線版本與區間保留）。

部署後在 VM 確認沒有 `ready` 殘留，再選一台資料足夠的已確認主機按「啟用監控」：

```bash
sudo mysql librenms -e "SELECT mode, COUNT(*) AS n FROM ioc_behavior_assets GROUP BY mode;"
```

```bash
sudo mysql librenms -e "SELECT id, name, mode, baseline_version, quality_status FROM ioc_behavior_assets WHERE mode='monitoring';"
```

預期沒有 `ready`；啟用成功的資產 `baseline_version` 加一、歷程有 `baseline_ready`。

## 人工關聯已移除（2026-10-27）

M2b 的「關聯 LibreNMS 設備」（預覽並關聯、建議關聯、解除位址、歷史合併、採集器依已確認位址對應流量、命名鎖）已整個移除：主機不需要跟 LibreNMS 設備做關聯，而且關聯用錯（例如把另一台主機的位址綁到防火牆設備）時沒有撤銷、還原代價高。

- 端點 `preview_link`、`link_asset`、`unlink_address` 與畫面上的對應功能都不存在了；採集器回到「每個位址各寫各的區間」（LibreNMS 的當前 IP → 觀測主機），不再有 `source_ip`、`waiting` 狀態與多位址合併。
- `ioc_behavior_links` 資料表保留（不做破壞性遷移），只是不再讀寫；位址表裡舊的 `admin_confirmed` 列也不再被採集器當作對應依據。
- 之前已經關聯並合併進設備的歷史不會收回。若有被污染的設備，請在 VM 先唯讀檢視再決定怎麼處理：

```bash
sudo mysql librenms -e "SELECT l.observed_asset_id, l.target_asset_id, l.confirmed_by, l.confirmed_at FROM ioc_behavior_links l ORDER BY l.confirmed_at DESC LIMIT 20;"
```

```bash
sudo mysql librenms -e "SELECT asset_id, address, evidence_kind, last_seen_at FROM ioc_behavior_addresses WHERE evidence_kind='admin_confirmed';"
```

## 已知限制（2026-10-26 讀程式碼確認；標「待 VM 查證」的尚未實測）

- **已確認主機只靠 IP 維繫**：沒有 MAC／DHCP 證據，位址被 DHCP 租給別台機器時，新機器的流量會算進原本那台（見「已確認主機」的 `reappeared` 與「移除」）。
- **未確認的暫時資產不能啟用監控**：只累積區間，確認為受管主機後才能啟用；確認不會重設學習起點，已累積的區間直接可用。
- **基線只在按「啟用監控」時訓練一次**：不會自動更新（沒有滾動重訓），要重訓就「重新學習」再「啟用監控」。
- **區間品質（VM 實測，2026-10-26，採集約 3.75 小時、每個資產 45 格）**：complete 2219／missing 559／partial 449（約 69%／17%／14%）。任何一筆日誌缺 `net_bytes`、目的 IP 或目的埠（例如 ICMP），該裝置整個五分鐘區間就是 `partial`，不進基線與偵測，「啟用監控」的品質檢查當下也過不了。分佈很不平均：partial 集中在少數幾台（有的幾乎每格都是 partial），多數主機只偶爾 partial；LibreNMS 已納管的設備則整段都是 `missing`（沒有經過防火牆的對外流量）。
- **實際影響（依 VM 的資料推得）**：這個站點有對外流量的主機大多不在 LibreNMS（以「待確認設備」暫時資產出現），已納管的設備又幾乎沒有流量。要由管理員把有流量的主機確認為受管主機，才能對它啟用監控（取消強制 28 天後，資料量達最低門檻就能啟用）。整段 `missing` 的 LibreNMS 設備仍然建不起基線（要等 `idle` 與缺資料語意的調整，M2c）。
- **`partial` 的原因會寫在 `quality_reason`**（2026-10-26 起；更早寫入的格子還是舊的籠統說明）：`N 筆日誌缺 net_bytes`、`N 筆日誌缺目的 IP`、`N 筆日誌缺目的埠`、`同一個 IP 對到多台設備`、`分組達查詢列數上限，這個區間的資料可能被截斷`，同時發生就用「；」串起來。資產詳情的區間表會顯示。要看整體分佈：

```bash
sudo mysql librenms -e "SELECT SUM(quality_reason LIKE '%缺 net_bytes%') AS no_bytes, SUM(quality_reason LIKE '%缺目的 IP%') AS no_dst, SUM(quality_reason LIKE '%缺目的埠%') AS no_port, SUM(quality_reason LIKE '%同一個 IP%') AS ambiguous, SUM(quality_reason LIKE '%截斷%') AS truncated, COUNT(*) AS partial_total FROM ioc_behavior_windows WHERE quality='partial' AND window_start >= UTC_TIMESTAMP() - INTERVAL 1 DAY;"
```
- **分組達查詢列數上限（500）時，這個區間所有有流量的資產都是 `partial`**（VM 實測 2026-10-27：上班時段的區間常被截斷，詳情頁的區間表寫「分組達查詢列數上限，這個區間的資料可能被截斷」）。偵測只看離峰時段、離峰流量小，通常不會截斷，所以不影響離峰偵測，但忙的時段不會進基線、截斷的那一格也不會判斷。要量化可查每小時被截斷的區間比例（`quality_reason LIKE '%截斷%'`）；調高上限前要先量 Graylog 的查詢成本，目前沒有改。
- **一個 tick 只處理最新一個完整區間，不回補**：採集器停擺期間的區間不會補算，啟用監控時可用的完整區間會因此變少。
- **每個區間只檢查與學習前 100 個目的地與目的埠**：超過的部分不會被判為「新」，也不會被學習。

判斷規則（區間品質、上班時段與小時分桶、警示去重鍵、基線訓練門檻）在 `BehaviorAnalyzer`，有單元測試（`tests/php/behavior_test.php`）；修改這些規則要同步改測試。
