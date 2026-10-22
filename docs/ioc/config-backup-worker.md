# IOC 設備組態備份：背景程序與維運指引

## 範圍與信任邊界

本模組在 IOC「組態備份」專屬管理頁集中管理設備、帳號、固定間隔排程、備份紀錄與版本比較；一般設定頁只保留入口。第一版使用獨立受管 Oxidized，不修改既有 LibreNMS、Oxidized 或 RANCID 設定。支援 ios、fortios、junos、routeros，僅 SSH 22；每份政策最多 50 台，間隔 1 至 168 小時。實際設備相容性必須在隔離測試設備驗收。

設定頁不直接連線設備。PHP 經 root 白名單 helper 降權至 `oracle-backup`，與 Oxidized、排程使用相同專用帳號。所有請求走標準輸入 JSON，不以命令列傳遞密碼。

**只適用於可信任的專用維運 VM。** Oxidized REST 固定綁定 `127.0.0.1:8879`，此介面包含原始組態，本機其他使用者也可能存取。不得對外開放、防火牆轉送或以 nginx 反向代理公開。回環綁定並非使用者認證。若主機上有不可信帳號或多租戶，需先隔離 REST 網路命名空間或加入可信代理認證，才可啟用。

狀態目錄 `/var/lib/oracle-mon/config-backup` 權限 700、帳號 `oracle-backup`；設定、憑證及 SQLite 檔案為 600。**這些是存取權限，沒有加密靜態資料。** 主機磁碟加密、主機備份與備份媒體權限由維護人員管理。SQLite 也必須納入主機備份；勿只備份 Oxidized 最新檔案而遺失歷史版本與稽核。

## 模組與資料

- `system/ioc-config-backup.py`：標準函式庫實作，無額外 Python 套件。
- `collector/admin/manage-config-backup.sh`：固定身分、固定路徑的受限入口。
- `system/ioc-config-backup-oxidized.service`：專用 Oxidized daemon。
- `system/ioc-config-backup.service`、`.timer`：每分鐘檢查工作及結果。
- 狀態目錄中的 `state.sqlite3`：政策、帳密、工作、不可覆寫的內容版本、差異及操作稽核。
- `oxidized/config`、`oxidized/router.db`：僅 IOC 管理的設定。JSON 是 YAML 子集，不需 Python YAML 相依套件。
- `oxidized/configs/`：Oxidized 最新輸出；歷史版本另存在 SQLite，不使用 Git，不清除過時設備檔案。

正式路徑固定。`IOC_BACKUP_STATE_DIR` 僅供本機測試覆寫；Web helper 用清空的環境執行，不接收瀏覽器提供的路徑或環境值。目錄與檔案的符號連結、錯誤擁有者及過寬 POSIX 權限會被拒絕。

## 狀態與排程

1. `save` 驗證政策，交易寫入資料庫並產生受管設定；回傳 `pending_apply`。**儲存不是生效。** 寫檔中斷時資料庫交易回復，下一次 tick 由資料庫重建檔案再套用。
2. `tick` 檢查本機 REST，重新載入設備來源並確認設備清單，再標示 `ready/applied`。未安裝、未啟動或回應不相容都顯示 `unavailable`，不宣稱已備份。
3. `test`、`run` 只建立 `queued` 工作，必須先有背景程序確認的已套用設定；不接受尚未儲存的帳密。
4. `tick` 將等待中的工作送入 Oxidized。送出前持久化 `dispatched_at` 與前次完成時間；即使 API 回應遺失，也不自動重送，避免同設備重複觸發。
5. 後續 tick 必須取得新的 `last.start/end/status`，且開始時間不早於 `dispatched_at`，才接受此次採集。`/node/next` 的 `ok` 只代表排隊。
6. `success` 結果還需取得非空且小於 2 MiB 的內容才能建立版本；失敗與空內容不覆蓋成功版本。相同內容為 `unchanged`，仍保留工作紀錄。
7. 工作等待超過 15 分鐘為 `timeout`；Oxidized 本身 `timelimit=300`、`retries=0`。未取得成功證據不視為成功。

Oxidized `interval=0` 停用其初始與週期採集，只有 IOC 排程可觸發。此版本是固定間隔，不是「每天幾點」或週曆排程；每台依最近要求時間計算間隔，停機恢復後每台補一筆，不重播所有漏跑時段。每個 tick 最多建立及發送 5 筆、讀取 5 份完成內容；HTTP 單次上限 1 秒、共用 4 秒時間預算，未完成留到下一分鐘。

`status/settings` 純讀已保存狀態，不執行 HTTP。工具狀態是最近一次 tick 的快照，含 `checked_at`；超過三分鐘未更新會顯示 unavailable 並拒絕新的手動工作；維護人員應確認 timer 運作。執行中的工作會禁止更改政策。停用全域排程會停止新增週期工作，已排隊工作繼續完成；手動備份仍可使用。

## 版本與機密

`remove_secret=false` 保存原始採集輸出，讓密碼等敏感設定的變更也會產生雜湊及版本紀錄。原始檔是否足以還原仍取決於設備與模型採集範圍；不得在未實機驗證前承諾完整還原。

Web 不接收原始內容，只收到差異預覽。已知設備密碼、密碼／金鑰／community／token 等敏感行及 PEM 私鑰區塊會遮罩；僅敏感內容變更也會提示。這是盡力遮罩，無法保證涵蓋所有廠牌的未知秘密格式，所以第一版組態內容僅限 admin，仍應以敏感資料處理。設備密碼不回傳，只回 `password_set`；操作紀錄只寫設備 ID、設備數與固定說明，不寫憑證或 API 原始錯誤。

差異以設備模型輸出做換行正規化，尚未增加跨廠牌的時間戳忽略規則；不同設備的雜訊需真實樣本後設定。每份預覽約 8 KiB，過長明確標示截斷。備份與稽核目前不自動清除，啟用前應規劃容量、監控剩餘空間與保存政策。

## 閘道契約

輸入 `{action,actor,settings,device_id}`。動作為 `settings/status/save/test/run/tick`，Web 不可直接暴露 tick。輸入 JSON 上限 256 KiB。排程另可執行 `--tick`，不接受其他命令列參數。

`settings`：`{enabled,interval_hours,devices:[{device_id,name,host,model,username,password,port,enabled}]}`。

`password` 空白或省略保留相同 device_id 已存密碼，首次必填。username 上限 128 字元、password 512 字元、actor 255 字元。來源為 Oxidized CSV，輸入不得含控制字元、`|`、Ruby 插值起始 `#{`。主機支援 IP 或安全 DNS 名稱；拒絕直接輸入 loopback、link-local、multicast、unspecified。DNS 解析及 SSH 連線仍屬可信管理端的設備納管權限，不是任意多租戶 SSRF 隔離措施。

成功 `{ok:true,data:{settings,engine,runs,changes,audit}}`，exit 0。失敗 `{ok:false,error:{code,message}}`，exit 1，不回 traceback 或工具原始錯誤。

- `engine`：`backend,endpoint,state,message,applied,checked_at`。
- `runs` 最近 50 筆：`id,device_id,device_name,kind,status,requested_at,completed_at,message,version_id`。kind 為 test/manual/scheduled，status 為 queued/success/unchanged/failed/timeout。
- `changes` 最近 50 筆：`id,device_id,device_name,detected_at,previous_version_id,version_id,added,removed,diff`。
- `audit` 最近 50 筆：`id,occurred_at,actor,action,detail`。
- 所有時間 UTC；畫面轉為台北時間。組態變更只能確定兩次採集間發生，不推測實際修改人與時間。

## 維護人員啟用前檢查

本次開發不安裝工具、不啟動服務、不變更 VM。後續授權部署時：

1. 建立無登入 shell 的 `oracle-backup` system user；安裝 worker 與 root 擁有的 helper；建立專用狀態目錄 700。一般 `librenms` 帳號不得寫 helper、worker 或狀態目錄。
2. 在專用 VM 安裝相容的 Oxidized 與 oxidized-web（本模組依官方目前 extensions 設定，oxidized-web 至少 0.16.0）；確認 `oxidized` 在 service PATH。此 repo 不執行 gem 安裝、不下載相依套件。
3. 以維護方式核對設備 SSH 主機金鑰指紋，提供 `oracle-backup` 的 `known_hosts`。設定保持 `input.ssh.secure=true`，不自動信任未知主機金鑰，不以關閉驗證解決連線問題。
4. 部署三個 systemd unit，先不啟用；在 IOC 儲存設備設定，產生受管 config 後，才另行授權啟動 daemon 與 timer。不得對既有 Oxidized 啟用相同設備週期排程。
5. 確認 REST 僅回環監聽，再驗證 pending → ready、手動 queued → success、設定變更、無變更、錯誤密碼、設備離線、逾時與重啟恢復。
6. 核對 admin/global-read HTTP 權限、CSRF 與密碼不回傳，確認沒有將 REST 公開。最後才安排正式設備啟用。

## 驗證與官方依據

本機測試：`python -m unittest discover -s tests -p test_config_backup.py`。測試使用假的 Oxidized REST 行為，不連線真實設備；另於 WSL 執行同組測試驗證 POSIX 權限與符號連結。真實工具版本、設備與 systemd 啟用仍待 VM 驗收。

官方參考（2026-10-22 查閱）：

- [Oxidized Configuration](https://github.com/ytti/oxidized/blob/master/docs/Configuration.md)：interval=0、next_adds_job、remove_secret、extensions、SSH 與時間限制。
- [Oxidized CSV source](https://github.com/ytti/oxidized/blob/master/lib/oxidized/source/csv.rb)：逐行分隔、map、vars_map。
- [Oxidized CLI](https://github.com/ytti/oxidized/blob/master/lib/oxidized/cli.rb)：home-dir，沒有 one-shot 參數。
- [Oxidized-web API](https://github.com/ytti/oxidized-web/blob/master/lib/oxidized/web/webapp.rb)：reload、next、nodes、fetch；String 以 lines 陣列輸出 JSON，worker 會串接回原內容。
- [Oxidized output](https://github.com/ytti/oxidized/blob/master/docs/Outputs.md)：file 儲存與 clean_obsolete_nodes 風險；此模組明確關閉清理。

## 專屬管理頁與歷史查詢

組態備份頁提供設備查詢、設備與排程設定、歷史紀錄及任選兩個版本比較。歷史查詢包含已移除備份政策但仍保有紀錄的設備，管理者可依設備分頁查閱，不受首頁最近 50 筆限制。每頁 20 筆；工作紀錄與內容版本各自顯示總筆數。備份內容未改變時沿用既有版本，因此執行次數不等於版本代數。

API 仍使用 POST、CSRF 與 admin 權限：

- `history`：`device_id` 正整數、`offset` 非負整數（預設 0）；`backup.history` 回傳執行紀錄、版本摘要、總筆數及分頁資訊。
- `compare`：`device_id`、`from_version_id`、`to_version_id` 皆為正整數；兩個版本必須屬於同一設備。`backup.comparison` 回傳來源與目標版本時間、遮罩差異、新增／刪除行數及截斷旗標。
- `backup.history_devices` 提供可查歷史的設備，`configured` 區分目前仍有設定的設備。

歷史查詢直接讀取本模組 SQLite，不需要備份引擎上線，也不會派送備份。版本列表不包含原始內容。比較從較舊或較新的指定來源往目標方向呈現，敏感內容保持遮罩；過長差異明確提示截斷。正式 VM 的頁面、權限與實際資料驗收仍須部署後進行。

為避免更換密碼或移除設備後，歷史差異重新顯示已知秘密，受保護資料庫另保留曾設定的備份密碼供遮罩使用；這些資料不回傳 Web，與原始備份採相同存取保護。升級前已遺失的舊密碼無法回溯補齊，仍使用敏感欄位模式遮罩。
