# IOC 智慧維運中心 — Phase 0：準備與基礎

依《智慧維運中心資訊服務採購需求規格書》v1.1（紅字段落視為刪除）。可行性評估與工時見評估報告；
本文件記錄 Phase 0 **已做什麼、怎麼在實機驗證、還有什麼要你決定或要實機才能做**。

## 1. 狀態

| 項目 | 狀態 | 說明 |
|---|---|---|
| 欄位標準化 pipeline | ✅ 已在 monitor-vm 套用；重播資料與**真實 FortiGate 流量**都驗證過（這台沒有真實 Windows 事件，Windows 規則只驗證過重播） | `system/graylog-pipelines.py`（32 條規則 / 4 條 pipeline），驗證結果見 §4 |
| 套用與回滾工具 | ✅ | `apply`（冪等）／`plan`（唯讀）／`remove`（只刪本工具建立的）；防火牆可另連到指定 stream |
| 樣本日誌與重播 | ✅ | `tools/replay-sample-logs.py`、`tests/fixtures/sample-logs.jsonl`（15 筆，每筆標預期欄位） |
| 既有 Graylog 設定紀錄 | ✅ | [existing-graylog-config.md](existing-graylog-config.md)：monitor-vm 上 FortiGate 的 pipeline、規則、stream 設定（原本 repo 裡沒有） |
| 上線前自檢 | ✅ | `system/ioc-preflight.sh`（唯讀）：出廠密碼、HTTP/HTTPS、設定檔權限、pipeline 是否已套用 |
| 自動部署 | ✅ | `install.sh`／`update.sh` 在 `graylog-api.conf` 存在時自動 `apply`，不存在就略過 |
| 規格疑義、附表 A、清冊 | 📝 草稿（§6～§8） | 要機關回覆或填列 |
| 全面 HTTPS | ⏸ 待實機與決定 | §9 |
| Graylog 唯讀 Token | ⏸ 待實機驗證權限 | §9 |
| 出廠密碼更換 | ⏸ 偵測已做，更換由你執行 | §9 |
| Graylog 其他設定匯出（Input／GeoIP／Lookup Table／Output） | ⏸ 待實機（FortiGate 的 pipeline／stream 已記錄） | §9 |

跑測試（不需要 Graylog）：

```bash
python3 -m unittest discover -s tests
```

## 2. 標準欄位

pipeline 把來源日誌寫入下列欄位，**原始欄位不動**。中文入口只讀這些欄位。

| 欄位 | 值 | 說明 |
|---|---|---|
| `ioc_schema` | `1` | 規則版本。有這個欄位代表已被標準化，之後改欄位定義就升版（INT-034、INT-039） |
| `log_type` | `windows` `linux` `firewall` `network` | 來源系統類型，是「資料涵蓋／新鮮度」的分組依據 |
| `event_action` | 見下表 | 事件代碼，存英文，中文名稱在入口顯示層對照 |
| `severity` | `info` `low` `medium` `high` | 事件本身的等級，不是風險分數（風險分數在 Phase 1 由關聯規則算） |
| `user_name` | 帳號 | 登入類是被登入的帳號；異動類是操作者。Windows 電腦帳號以 `$` 結尾，入口顯示時應過濾 |
| `src_ip` | IP | 來源位址。Windows 本機登入的 `-`、`::1`、`127.0.0.1` 不寫入 |
| `dst_ip` | IP | 目的位址（防火牆） |
| `host_name` | 主機名 | 產生日誌的主機 |
| `target_name` | 名稱 | 被操作的對象：群組、帳號、服務、介面 |
| `ioc_demo` | `true` | 由重播工具加上，不是 pipeline 寫的。入口與報表必須排除，不得和正式資料混在一起（IS-054～056） |

`event_action` 與中文名稱（入口顯示用，Phase 1 會做成程式裡的對照表）：

| 代碼 | 中文 | 來源 | 代碼 | 中文 | 來源 |
|---|---|---|---|---|---|
| `login_success` | 登入成功 | Windows、Linux、網路設備 | `account_locked` | 帳號鎖定 | Windows |
| `login_failed` | 登入失敗 | Windows、Linux、網路設備 | `audit_policy_changed` | 稽核原則變更 | Windows |
| `logout` | 登出 | Windows | `audit_log_cleared` | 稽核日誌清除 | Windows |
| `explicit_logon` | 以明確憑證登入 | Windows | `service_installed` | 安裝服務 | Windows |
| `privileged_logon` | 使用高權限 | Windows | `privileged_command` | 執行提權指令 | Linux |
| `account_created` | 帳號建立 | Windows | `fw_allow` | 防火牆允許 | 防火牆 |
| `account_enabled` | 帳號啟用 | Windows | `fw_deny` | 防火牆阻擋 | 防火牆 |
| `account_disabled` | 帳號停用 | Windows | `link_up` | 連接埠上線 | 網路設備 |
| `account_deleted` | 帳號刪除 | Windows | `link_down` | 連接埠離線 | 網路設備 |
| `group_member_added` | 加入群組 | Windows | `group_member_removed` | 移出群組 | Windows |
| `invalid_user` | 無效帳號登入嘗試 | Linux | | | |

`severity` 是初始設定，改 `system/graylog-pipelines.py` 裡的 `WIN_EVENTS`、`LINUX_EVENTS`、`NET_EVENTS` 即可，重跑 `apply` 生效。

### 支援的來源與沒做的

| 來源 | 支援方式 | 備註 |
|---|---|---|
| Windows 事件 | Winlogbeat（`winlog_*`）與 NXLog（`EventID`、`TargetUserName`…）兩種欄位名 | 20 個事件 ID，清單在 `WIN_EVENTS` |
| Linux | sshd 登入失敗／成功、sudo | 以 rsyslog 進 Graylog 的 `message` 文字比對。帳號不存在時 sshd 會記兩行：`Invalid user` 那行是 `invalid_user`，`Failed password for invalid user` 那行是 `login_failed`，入口統計時要分開算，不然一次嘗試會被算成兩次 |
| 防火牆 | 讀既有 pipeline `fortigate_normalize` 整理過的 `srcip` `dstip` `action`，找不到再退回 CEF 原始的 `src` `dst` `act` | 防火牆 pipeline 排 **stage 2**（要在 `fortigate_normalize` 的 stage 0、1 之後）。FortiGate 訊息在自訂 stream，要設 `GRAYLOG_FIREWALL_STREAMS`（§3）。`act` 不是 deny／allow 類（例如 `close`）的訊息只有 `log_type`、`src_ip`、`dst_ip`、`host_name`，**沒有 `event_action`**。實測 1 小時 15 萬筆：阻擋類 61%、允許類 30%、其他 9%（連線結束與統計，刻意不歸類） |
| 網路設備 | Cisco IOS 風格的登入失敗／成功、連接埠上下線 | **其他廠牌（Extreme、Junos、…）要有真實日誌樣本才能寫** |
| PaloAlto、AIX、JBoss／Tomcat | 未做 | 同樣要先拿到樣本 |

## 3. 套用步驟（monitor-vm 上）

建立設定檔（帳密只放這裡，權限 600）：

```bash
sudo install -d -m 755 /etc/oracle-mon
```

```bash
sudo install -m 600 /dev/null /etc/oracle-mon/graylog-api.conf
```

編輯 `/etc/oracle-mon/graylog-api.conf`：

```
GRAYLOG_URL=http://127.0.0.1:9000
GRAYLOG_USER=admin
GRAYLOG_PASS=（admin 密碼）
# 防火牆日誌所在的 stream（標題或 ID，逗號分隔）。monitor-vm 的 FortiGate 訊息被路由到這個 stream
# 並移出 Default Stream，不設的話防火牆規則看不到真實流量。找不到這個 stream 時工具會報錯並列出現有的
GRAYLOG_FIREWALL_STREAMS=FortiGate Syslog
# HTTPS 用自簽或內部 CA 時指定 PEM；工具沒有「關閉憑證驗證」的選項（IS-043）
# GRAYLOG_CA_FILE=/etc/graylog/server/server.crt
```

先看會改什麼（唯讀），確認後套用：

```bash
python3 /opt/oracle-mon-librenms/system/graylog-pipelines.py plan
```

```bash
python3 /opt/oracle-mon-librenms/system/graylog-pipelines.py apply
```

回滾（只刪本工具建立的 `IOC Normalize - *` pipeline 與 `ioc-*` 規則，GeoIP 等既有的不動）：

```bash
python3 /opt/oracle-mon-librenms/system/graylog-pipelines.py remove
```

之後 `install.sh` 或 `update.sh` 會自動重新套用（冪等，內容相同就不動）。

## 4. 實機驗證清單（我在本機驗證不到的假設）

本機測的是工具邏輯（對一個假的 Graylog API），這些假設**只有在真實 Graylog 7.1 上才能確認**。
第一次套用請先在測試環境做，不要直接在正式 Graylog 上試。

最後一欄是 2026-10-20 在 monitor-vm（Graylog 7.1）的實測結果。

| # | 假設 | 怎麼確認 | 實測結果 |
|---|---|---|---|
| 1 | pipeline／規則／連線的 API 路徑與 body 格式 | `apply` 回傳 400／404 時工具會印出 Graylog 的錯誤訊息 | ✅ 建立規則、pipeline、連到 stream 都成功；列表 API 回傳格式正確。`GET /connections` 的格式也證實了（`plan`／`apply` 對既有連線顯示「無變更」）；Graylog 接受**只有 `stage 2`、沒有 stage 0 和 1** 的 pipeline |
| 2 | 規則語法（巢狀 `to_string`、`set_fields(grok(...))`、`!(...)`） | `apply` 建立規則時 Graylog 會解析；重播後看欄位 | ✅ 重播結果與預期相符（畫面可見的 12／14 筆全部正確，其餘 2 筆待確認） |
| 3 | Windows 欄位名稱 | 搜尋 `_exists_:winlog_event_id` 與 `_exists_:EventID` | ⏳ 這台**沒有**真實的 Windows 事件（兩者都是未知欄位）。Winlogbeat 與 NXLog 兩種格式都只用重播驗證過 |
| 4 | 防火牆規則需要的欄位在它執行時已經存在 | 查既有 pipeline 的 stage（[existing-graylog-config.md](existing-graylog-config.md)） | ⚠️ 這台的 FortiGate 欄位是 pipeline `fortigate_normalize`（stage 0、1）產生，**不是 extractor**。同一 stream 的 pipeline 依 stage 編號一起往下跑，所以防火牆 pipeline 排 stage 2。✅ 套用後真實 FortiGate 訊息已有標準欄位（2026-10-20 13:29 查詢，5 分鐘 5,458 筆有 `event_action`；`fw_deny`／`low`、`fw_allow`／`info`、`src_ip`、`dst_ip`、`host_name` 逐筆正確，UTM 的 `passthrough`、`pass` 也歸 `fw_allow`） |
| 5 | 流量都留在 Default Stream | 看訊息詳情的「Routed into streams」 | ❌ **不成立**：FortiGate 訊息只在 `FortiGate Syslog` stream，且該 stream 移出了 Default Stream。已加 `GRAYLOG_FIREWALL_STREAMS`。GELF、Linux、網路設備、Windows 仍走 Default Stream |
| 6 | 空字串欄位不會被儲存（取不到值時 `set_field` 等於沒設） | 缺帳號欄位的 Windows 事件 | ⏳ 樣本未涵蓋。本機登入 `-` 沒有 `src_ip` 已驗證，但那是 `when` 條件排除，不是這個行為 |
| 7 | 新欄位不與既有欄位型別衝突 | 搜尋 `_exists_:severity` 等 | ✅ `severity`、`user_name`、`src_ip`、`EventID`、`winlog_event_id` 都是未知欄位，沒有衝突。✅ 重播後四條 IOC pipeline 的 `Processing Errors` 仍是 0（total 0）：來源欄位不存在時 `to_string(欄位, "")` 不會產生處理錯誤 |
| 8 | grok 預設 pattern（`USERNAME`、`IP`、`WORD`、`DATA`）可用 | 重播後 Linux、網路設備的 `user_name`、`src_ip` 有值 | ✅ |
| 9 | `act` 為 `close`、`timeout` 等的訊息如何處理 | FortiGate Syslog stream 的 `action` 分布（`/api/search/aggregate`） | ✅ 1 小時 151,578 筆：阻擋類 60.9%（`deny`、`blocked`）、允許類 29.9%（`pass`、`accept`、`passthrough`）、其他 9.2%（`close`、`client-rst`、`server-rst`、`timeout`、`dns`、`ip-conn`、統計類）。其他是連線結束與統計，非安全事件，**刻意不歸類**。占比會隨時間大幅變動：那一小時含 13:06 前後的一次阻擋突發，套用後平穩的 3 分鐘是阻擋 36%、允許 64%（以有 `event_action` 者計），另有約 21% 的防火牆訊息沒有 `event_action`。詳見 [existing-graylog-config.md](existing-graylog-config.md) |
| 10 | Phase 1 要用的 Graylog 搜尋彙整 API（`POST /api/search/aggregate`） | 用相對時間、指定 stream 和 `_exists_` 查詢取欄位分布 | ✅ 回傳 `schema`＋`datarows`＋`metadata`。查詢用 `_exists_:欄位`；在 Graylog 介面搜尋框單打 `*` 會被 7.1 擋下（`'*' or '?' not allowed as first character`），程式裡不要用。**一定要帶 `group_by`**：只有 `metrics` 會回 400（`groupings must not be empty`），要算總數就隨便 group 一個一定存在的欄位再加總 |
| 11 | `remove`（回滾）能在真實 Graylog 上完整執行 | 在測試環境跑 `remove`，看 System → Pipelines 與 Manage rules 是否清乾淨，再 `apply` 回來 | ⏳ **尚未驗證**。解除連線用的 `to_pipeline` 搭配空的 stream 清單是否被接受不知道；工具已改成被拒絕時仍繼續刪除並印警告 |
| 12 | 防火牆規則不會誤標 Default Stream 裡的非防火牆日誌 | Search 選 Default Stream，查 `log_type:firewall AND NOT ioc_demo:true` | ⏳ 防火牆 pipeline 也連在 Default Stream，條件只要求同時有 `srcip`／`dstip`（或 `src`／`dst`）。預期 0 筆；若有，表示有別的日誌帶這些欄位，要收緊條件（例如再要求 `act`／`action`） |

驗證流程：

```bash
python3 tools/replay-sample-logs.py --target <測試用 Graylog>:12201 --proto udp --show-expect
```

到 Graylog 搜尋 `ioc_demo:true`，逐筆對照印出的預期欄位。**重播工具沒有預設目標**，避免誤打正式環境。
monitor-vm 的 GELF Input 是 **UDP 12201**（`ss -lntu | grep 12201` 只有 udp，且同一個埠會出現多行，是 Graylog 的多接收執行緒）。
若 Input 是 TCP 就加 `--proto tcp`。重播的 GELF 訊息會經過 Default Stream 的 pipeline，**不會**經過 `FortiGate Syslog` stream。

## 5. 上線前自檢

```bash
sudo /opt/oracle-mon-librenms/system/ioc-preflight.sh
```

唯讀。`FAIL` 會讓退出碼為 1；`WARN` 與 `SKIP` 不影響。檢查項目：

- Graylog 是否仍可用出廠密碼登入（IS-051、DEP-043）。只試一次
- Graylog API 與 LibreNMS 是否仍用 HTTP（IS-041、IS-042）
- `graylog-api.conf` 權限是否過寬
- 欄位標準化 pipeline 是否已套用且與程式一致

## 6. 規格疑義（草稿，投標前提出）

紅字刪掉了功能章節，但前後仍有引用。沒釐清的話，驗收時可能被要求補做已刪除的項目。

| # | 位置 | 問題 | 建議寫法 |
|---|---|---|---|
| 1 | 1.1、1.2 第 7 點、1.3、2.1 | 目的、效益、使用情境與建置範圍仍列資產、組態、弱點、Shadow AI、合規、自動化、AI 分析 | 確認本案範圍以第三章有效需求為準 |
| 2 | 2.5、DEP-001 | 清冊規定由廠商於導入計畫（DEP-001）提出，但 7.1 整節已刪；第 3 項「AI 支援資料來源清冊」已無對應功能 | 確認導入計畫與清冊的提出時點，並刪除 AI 清冊 |
| 3 | 名詞定義 | 仍定義 AI RCA、ChatOps、Profile／Override、Shadow AI | 確認是否刪除 |
| 4 | ARC-012、ARC-064 | 把組態、合規列為核心非 AI 功能，並要求備份自動化與合規基準 | 確認是否刪除該引用 |
| 5 | DEP-070～072、DEP-124～125 | 合規設備納管、自動化設備對應、AI 來源清冊，與自動化及 AI 的教育訓練 | 確認是否適用 |
| 6 | 9.2、9.3 | AI 功能驗收與 AI 治理驗收 | 確認是否適用 |
| 7 | 附表 A | F-09 合規工作量、F-11 地端 AI、F-31 AI 資料來源 | 確認填 N/A |
| 8 | 第四章開頭 | 4.4 機敏資料保護、4.7 弱點及更新管理、4.8 AI 治理刪除後，是否仍依「資通系統防護基準」（附表十）辦理？該章開頭寫「本章未列者依該基準辦理」 | 確認防護需求等級（F-28），並預期 Token 加密、遮罩仍會被要求 |

## 7. 附表 A：假設與容量估算

附表 A 全部是「由機關填列」，下表是**推估用的假設**，不是建議值。實測資料量後再校正。

| 參數 | 項目 | 假設值（待機關填列） | 用在 |
|---|---|---|---|
| F-01／F-02 | 納管設備數（初始／最大） | ＿＿ ／ ＿＿ | ARC-071，LibreNMS 輪詢負載 |
| F-03 | 每日事件／告警量 | ＿＿ | ARC-072，事件案資料表大小 |
| F-04 | 每日日誌量 | ＿＿ GB | ARC-073，磁碟與 OpenSearch heap |
| F-05 | 日誌保存期間 | ＿＿ 天 | ARC-073，磁碟 |
| F-06 | 每日 Flow 量 | ＿＿ 筆 | ARC-074，磁碟 |
| F-07 | NSM 平均／尖峰流量 | ＿＿ Mbps | ARC-075，感測主機規格 |
| F-08 | 主動檢測目標數 | ＿＿ | ARC-076，檢測主機排程 |
| F-10 | 同時使用者數 | ＿＿ | ARC-078，入口與 Graylog 查詢負載 |

估算公式：

```
日誌磁碟 ≈ 每日日誌量(GB) × 保存天數 × 索引膨脹係數 × (1 + 副本數) × 1.25（預留）
Flow 磁碟 ≈ 每日 Flow 筆數 × 每筆索引大小 × 保存天數 × (1 + 副本數) × 1.25
```

- 索引膨脹係數、每筆索引大小都要用**測試環境的實測值**，先用重播或真實來源跑一週，看 System → Indices 的索引大小與筆數反推
- 單節點副本數實際是 0：預設 index template 要求 1 個 replica 但單節點無處放置（CLAUDE.md），所以不佔磁碟；若 F-26 要求不得有單點故障，這個前提就變了
- OpenSearch heap 目前 `opensearch_heap = 8g`（CLAUDE.md），原則是實體 RAM 的 50%；Flow 與 NSM 加入後要重新評估，必要時把 Data Node 獨立成一台
- 網路安全監控與主動檢測各一台主機，規格由 F-07、F-08 決定

## 8. 清冊草稿

### F-29 納管設備與資料來源清冊（範本）

| 設備類型 | 廠牌 | 型號 | 數量 | 管理位址／網段 | 據點 | 收集方式（SNMP／Syslog／Flow／API） | 日誌格式 | 備註 |
|---|---|---|---|---|---|---|---|---|
| 防火牆 | FortiGate |  |  |  |  | SNMP、Syslog（CEF）、Flow |  |  |
| 交換器 | 待填 |  |  |  |  | SNMP、Syslog、Flow、SPAN |  |  |
| Windows 伺服器／AD |  |  |  |  |  | Winlogbeat 或 NXLog |  |  |
| Linux 主機 |  |  |  |  |  | rsyslog |  |  |

### F-30 系統整合與介接清冊（草稿，依現有架構整理，待現場確認）

方向以本系統為主體。「來源可寫」指本系統是否有權限修改來源系統的資料（INT-075）。

| 系統 | 用途 | 方向 | 來源可寫 | 協定／埠 | 認證 | 需健康監測 |
|---|---|---|---|---|---|---|
| LibreNMS | 設備監控、告警、ARP/FDB | 輸入 | 否 | 資料庫與 `/api/v0` | 本機帳號 | 是 |
| Graylog（查詢） | 日誌與事件查詢 | 輸入 | 否 | REST 9000 | 唯讀 Token（規劃中） | 是 |
| Graylog（收集） | 接收日誌 | 輸入 | — | Syslog 514／UDP、GELF 12201、Beats 5044 | — | 是 |
| Graylog → jt-gelflow | 流量視覺化 | 輸出 | — | GELF UDP 12301 | — | 否 |
| jt-glogarch | 日誌歸檔 | 雙向 | 是（寫歸檔檔案） | HTTPS 8990 | Graylog 帳號 | 是 |
| NAS | 歸檔備份 | 輸出 | 是 | NFS／CIFS、rsync | 帳密（600） | 是 |
| 被監控設備 | SNMP 輪詢 | 輸入 | 否 | SNMP 161／UDP | SNMPv2c／v3 | 是 |
| 被監控 Oracle | 資料庫指標 | 輸入 | 否 | JDBC 1521 | 唯讀帳號 | 是 |
| jt-ipam | IP 管理，ARP/FDB 同步 | 輸入 | 否 | HTTPS 443／8443 | 帳號 | 否 |

## 9. 待實機驗證或待你決定

| 項目 | 為什麼沒在本機做 | 需要你做或決定 |
|---|---|---|
| 全面 HTTPS（LibreNMS 80、Graylog 9000 → TLS） | 會改 nginx 與 Graylog 的連線方式，選單、jt-glogarch、LibreNMS 的 Graylog 整合都會受影響；憑證依機關政策（IS-042） | 決定用機關 CA 還是自簽；給一台測試 VM。`graylog-pipelines.py` 已支援 `GRAYLOG_CA_FILE`，切 HTTPS 時只要改設定檔 |
| Graylog 唯讀 Token（給 Phase 1 入口用） | Graylog 的「Reader」角色對 stream 與搜尋 API 的實際權限要實機確認；建錯會變成權限過大或查不到資料 | Phase 1 開始時在測試 VM 上建立並驗證 |
| 出廠密碼更換 | 改 `server.conf` 的 `root_password_sha2` 並重啟 Graylog，影響 jt-glogarch 與 LibreNMS 的整合帳密 | 由你執行；`ioc-preflight.sh` 會偵測是否還在用出廠密碼 |
| Graylog 其他設定匯出（Input、GeoIP、Lookup Table、Output） | 這些只存在實機，repo 裡沒有；FortiGate 的 pipeline、規則、stream 已記錄在 [existing-graylog-config.md](existing-graylog-config.md)，其餘要從 Graylog 介面（System → Content Packs → Create）匯出 | 匯出後放進 `graylog/content-packs/`，我再接進 `install.sh` |
| 其他廠牌網路設備與 PaloAlto 的日誌規則 | 沒有真實日誌樣本，格式猜不準 | 提供各廠牌的日誌樣本（遮掉敏感資訊） |
