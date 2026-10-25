# IOC 稽核與證據模組維運

本模組保存控制項評估、事件案查詢快照、組態備份版本參照與改善歷程。它是內部工作紀錄；未匯入正式控制項目錄時不產生評估率，也不宣稱符合法規或具不可竄改保全能力。

## 更新與首次使用

在 VM 執行 `git pull --ff-only origin master` 與 `sudo ./update.sh`。更新程式會建立資料表及 `/var/lib/oracle-mon/ioc-evidence`，擁有者為 `librenms`、權限為 `0700`。若資料表建立失敗，先檢查 LibreNMS 資料庫，再以 `sudo -u librenms env LIBRENMS_DIR=/opt/librenms php system/ioc-db-setup.php` 重試。不能只看頁面已出現就認定資料庫可用。

管理者從「稽核與證據」下載 UTF-8 CSV 範本。標題固定為 `code,domain,title,requirement,description`，最多 2,000 項、1 MiB；先預覽新增、變更、移除的代碼，再啟用整版。啟用後建立有起迄日期的評估週期。舊目錄及舊週期僅供檢視。

事件案快照只保存既有證據時間軸的標準化欄位，最多 30 筆，記下實際查詢條件、固定 UTC 區間、查詢總筆數與截取標記。來源無法取得時不會建立快照；查詢成功但 0 筆則可保存明確標示為 0 筆的結果。組態備份證據只保存設備編號、版本編號、建立時間、大小及工作器回傳的雜湊，原始設定仍留在原有受限備份服務。外部封存目前只登錄未驗證參照，不算已保存快照，也不能支持「符合」判定或改善結案。

## 備份與還原驗證

VM 的既有備份工作必須**同時**包含 LibreNMS 資料庫的 `ioc_audit_*` 資料表，以及 `/var/lib/oracle-mon/ioc-evidence/` 整個目錄。兩者須來自同一次備份時間點；只還原資料庫會讓快照連結失效，只還原檔案也無法辨識來源。備份檔應限制存取、加密並存到 VM 以外的儲存位置。第一版不自動清除快照，需監控頁面顯示的剩餘空間。

還原後執行：

```bash
sudo -u librenms env LIBRENMS_DIR=/opt/librenms php /opt/oracle-mon-librenms/system/ioc-audit-verify.php
```

預期輸出為「失敗 0 筆」。再以管理者登入，擷取一件有 Graylog 查詢條件的真實事件案、下載並檢查雜湊；連結一個已成功且完整的設備備份版本。用檢視者登入時，只能看摘要；直接呼叫明細或下載 API 應回 403。最後確認來源逾時會顯示無法取得，且不產生證據列。這些真實來源驗收需在 VM 連線 Graylog 與備份工作器後進行。

`/var/lib/oracle-mon/ioc-evidence/` 中的檔案權限為 `0600`，資料夾為 `0700`；下載時會重新檢查大小與 SHA-256。資料庫與檔案若被具有 VM 管理權限的人共同更改，這些檢查仍無法提供法律上的不可竄改保證。正式保全需求需另建異地不可覆寫儲存、簽章及保留政策。

解除安裝會移除網頁與程式，不會自動刪除 `ioc_audit_*` 資料表或私有快照；清理資料須另訂保存期限並經資料擁有者確認。

## 自動化工作台（選用部署）

更新網頁與資料庫後，管理者可先在「稽核與證據」檢視 LibreNMS 資產涵蓋清單、預覽版本化 FortiOS／Linux 檢查包，再明確啟用檢查包並將技術規則對應到目前週期的控制項。技術結果僅產生「符合／缺口／待查」**建議**；正式判定仍由管理者逐筆或批次核定。停用對應後不再產生新建議，既有歷程保留。未建立控制項目錄時可檢視技術結果，但不顯示符合率。

自動化工作器須另外由 VM 管理者安裝；`install.sh`／`update.sh` 不會自動建立 SSH 帳號、安裝工作器或啟用排程：

```bash
cd /opt/oracle-mon-librenms
sudo bash system/install-audit-automation.sh
sudo systemctl start ioc-audit-network.service ioc-audit-linux.service ioc-audit.service ioc-audit-cases.service
sudo systemctl status ioc-audit-network.service ioc-audit-linux.service ioc-audit.service ioc-audit-cases.service --no-pager
```

此安裝僅放置受限程式、服務定義與私有目錄，**不啟用 timer**。確認單次執行、來源權限及證據雜湊後，VM 管理者才啟用四個排程：

```bash
sudo systemctl enable --now ioc-audit-network.timer ioc-audit-linux.timer ioc-audit.timer ioc-audit-cases.timer
sudo systemctl list-timers 'ioc-audit*'
```

日後更新程式碼後，重新執行 `sudo bash system/install-audit-automation.sh` 更新受管工作器檔案；此腳本不會改動既有 timer 啟用狀態。網路掃描以 `oracle-backup` 讀取既有完整備份；Linux SSH 掃描以 `ioc-audit` 專用帳號執行；中央匯入器僅讀取兩者寫出的去識別 JSON，不持有兩者機密。四個服務均維持 `NoNewPrivileges=yes`，不新增 sudoers 規則。

### Linux 主機逐台納管

LibreNMS 中已知 Linux 發行版的主機會自動列入；`type=server` 但作業系統不明的主機列為「待確認」，不能掃描。管理者先為每台主機建立專用唯讀 SSH 帳號及 VM 上的專用金鑰，確認 SSH 主機指紋後，將固定的公鑰與主機金鑰寫入私有檔。只允許遠端帳號執行清單中的唯讀指令；SSH 設定檔檢查需要白名單 `sudo -n /usr/bin/stat -c '%a %u %g' /etc/ssh/sshd_config`，不授予通用 shell 或任意 sudo 命令。

VM 的 `/var/lib/oracle-mon/ioc-audit/linux-hosts.json` 應由 `ioc-audit:ioc-audit` 持有，權限 `0600`，父目錄 `0700`。格式例如：

```json
{"hosts":{"123":{"address":"192.0.2.10","username":"ioc_audit","identity_file":"/var/lib/oracle-mon/ioc-audit/host-123.key","known_hosts_file":"/var/lib/oracle-mon/ioc-audit/known_hosts","port":22,"enabled":true}}}
```

`123` 必須是 LibreNMS 的 `device_id`，`address` 必須與頁面顯示的當前管理位址一致。金鑰與主機金鑰檔只能由 `ioc-audit` 讀取；設定中不保存密碼，也不接受自訂遠端指令。範例位址僅供說明，不要直接納管。未完成納管的主機保留在涵蓋清單分母，顯示待設定原因。

### 證據與驗收

FortiOS 檢查只對已驗證完整、SHA-256 正確的既有備份版本做離線解析，不複製原始組態。無法解析或未取得實機樣本的 Palo Alto、Extreme、HPE 不會回報通過。Linux 掃描輸出僅包含固定檢查代碼與狀態；任一來源故障、逾時、金鑰錯誤或缺資料皆為待查，不沿用先前通過結果。網路證據有效期為 8 日，Linux 為 2 日；逾期不再產生符合建議。重跑同一來源版本會去重。

網路檢查的備份設備 `host` 必須與 LibreNMS 資產的管理位址一致；中央匯入器會拒絕位址不一致的結果。若設備使用 DNS 名稱或不同的管理介面，先在兩邊確認設備對應，不能僅為讓結果通過而關閉來源比對。

管理者可在工作台設定要擷取的**已結案事件類型及控制項**。獨立事件案工作器使用現有 Graylog 唯讀帳號、固定 UTC 查詢區間與最多 30 筆標準化日誌；查無資料會保存明確標示的空快照，來源故障則記錄失敗並稍後重試。事件案快照只供人工判定，不自動視為符合。若最新技術檢查通過且改善事項仍未結案，工作台只顯示提醒；管理者仍須確認實際改善情形並手動結案。

核定技術建議時會建立只含來源版本、雜湊與關聯清單的可下載快照；原始網路設定仍在組態備份服務中。下載與還原驗證均重算 SHA-256。VM 驗收須用一份已確認完整的 FortiGate 備份、一台已納管 Linux 主機及一件真實已結案事件案，分別核對成功、無變更、來源故障、查無資料、逾期、檢視者 403、快照竄改與人工核定歷程。未在 VM 完成這些實機驗收前，不將自動化工作台視為正式稽核認證結果。
