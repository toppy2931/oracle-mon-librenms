# 裝置行為分析：試行部署與驗收

此模組以 FortiGate 經 Graylog 正規化的防火牆日誌開始試行。資料只涵蓋經過防火牆的對外流量；同一網段內未經防火牆的連線不可見。`443` 目的埠僅代表埠號，沒有 DNS／TLS 訊號時不推斷網站或 HTTPS。此功能提供可解釋的統計異常，不會自動封鎖設備。

## 資料流程

`LibreNMS device_id → IP 使用歷程 → Graylog 五分鐘彙整 → 28 天基線 → 模擬或正式警示 → IOC 事件案 → 簽章 Webhook`。

- 管理員先在「設定」維護內部網段，並在「裝置行為分析」為站點或裝置設定時區與上班時段。裝置時段優先於站點時段。變更既有時段會重新學習 28 天，舊基線及歷程保留。
- 未知來源 IP 建立暫時資產。同 IP 若超過 24 小時未觀測，重新出現時另建暫時身分。只有管理員填寫 DHCP 租約、MAC／VLAN 或人工核對依據後才能關聯 LibreNMS 裝置；原暫時資產及活動紀錄仍可點查，暫時資產不得啟用正式監控。
- 每個完整五分鐘區間彙整連線數、位元組、目的地數與目的埠數。來源中斷、必要欄位缺值、位址衝突或分組達上限時標為缺資料／不完整，受影響區間不進基線與偵測。
- 這裡的「連線數」以 FortiGate 轉送流量日誌筆數近似，並非即時並行連線數；須用實機日誌核對每筆代表的 session 範圍。
- IOC 會對同一時段重跑與同類警示去重，但 Graylog 若把同一筆設備日誌重複匯入，彙整數值仍可能放大。試行驗收須檢查日誌來源的重複率及延遲，確認可辨識的事件 ID 後才可設計來源層去重。
- 學習至少 28 天、28 天內至少 6048 個完整五分鐘區間、20 個不同日期，且至少 7 天／100 格實際有連線，才產生版本化基線。沒有觀測到該裝置的流量會標示為缺資料，不能只靠全域來源心跳判為零流量或就緒。這個保守門檻可能使低活動設備無法建立基線；須先確認該設備的日誌涵蓋，再評估專用心跳。管理員檢查資料品質與來源後，明確切到監控模式。基線就緒但尚未啟用監控時，只產生模擬警示；重新學習期間不使用舊基線判斷。
- 正式警示依裝置、偵測類型與小時去重，建立 IOC 事件案。管理員可填理由確認或結案。事件案及警示是不同紀錄，處置時應分別檢查。
- Webhook 投遞失敗會自動退避重試（間隔 1、2、4、8 分鐘），**共 5 次嘗試**（首次加 4 次重試），第 5 次失敗就標 `failed`；管理員排除原因後可在警示詳情手動重試（次數歸零）。
- 模式、警示處置、時段、來源盤點與人工身分關聯寫入操作稽核；不記錄密鑰及原始日誌。
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

## 已知限制（2026-10-26 讀程式碼確認；標「待 VM 查證」的尚未實測）

- **人工關聯不會改變流量歸屬**：`linkAsset` 把暫時資產設為 `retired`，並把位址複製到 LibreNMS 裝置；但採集器只依 LibreNMS 當前 IP 對應流量，同一個 IP 的下一輪流量找不到未退役的暫時資產，會另建新的暫時資產。要讓流量算在該裝置上，必須先更新 LibreNMS 的裝置 IP。
- **暫時資產不會訓練基線**：訓練需要上班時段，時段只能依 LibreNMS 裝置或站點設定，暫時資產兩者皆無；它們只會累積區間。
- **基線只在學習模式訓練**：`ready`／`monitoring` 的基線不會自動更新，管理員改回學習（重新學習 28 天）才會重訓。
- **區間品質（VM 實測，2026-10-26，採集約 3.75 小時、每個資產 45 格）**：complete 2219／missing 559／partial 449（約 69%／17%／14%）。任何一筆日誌缺 `net_bytes`、目的 IP 或目的埠（例如 ICMP），該裝置整個五分鐘區間就是 `partial`，不進基線與偵測，「啟用監控」的品質檢查當下也過不了。分佈很不平均：partial 集中在少數幾台（有的幾乎每格都是 partial），多數主機只偶爾 partial；LibreNMS 已納管的設備則整段都是 `missing`（沒有經過防火牆的對外流量）。
- **實際影響（依 VM 的資料推得，需觀察滿 28 天確認）**：這個站點有對外流量的主機大多不在 LibreNMS（以「待確認設備」暫時資產出現），已納管的設備又幾乎沒有流量。暫時資產不訓練、不能啟用監控，所以照現行規則與現在的資料，沒有資產會走到 `ready`／`monitoring`。
- **`partial` 的原因會寫在 `quality_reason`**（2026-10-26 起；更早寫入的格子還是舊的籠統說明）：`N 筆日誌缺 net_bytes`、`N 筆日誌缺目的 IP`、`N 筆日誌缺目的埠`、`同一個 IP 對到多台設備`、`分組達查詢列數上限，這個區間的資料可能被截斷`，同時發生就用「；」串起來。資產詳情的區間表會顯示。要看整體分佈：

```bash
sudo mysql librenms -e "SELECT SUM(quality_reason LIKE '%缺 net_bytes%') AS no_bytes, SUM(quality_reason LIKE '%缺目的 IP%') AS no_dst, SUM(quality_reason LIKE '%缺目的埠%') AS no_port, SUM(quality_reason LIKE '%同一個 IP%') AS ambiguous, SUM(quality_reason LIKE '%截斷%') AS truncated, COUNT(*) AS partial_total FROM ioc_behavior_windows WHERE quality='partial' AND window_start >= UTC_TIMESTAMP() - INTERVAL 1 DAY;"
```
- **一個 tick 只處理最新一個完整區間，不回補**：採集器停擺期間的區間不會補算，基線要求的 6048 格（28 天的 75%）會因此更難達到。
- **每個區間只檢查與學習前 100 個目的地與目的埠**：超過的部分不會被判為「新」，也不會被學習。

判斷規則（區間品質、上班時段與小時分桶、警示去重鍵、基線訓練門檻）在 `BehaviorAnalyzer`，有單元測試（`tests/php/behavior_test.php`）；修改這些規則要同步改測試。
