# AIX SNMP 子代理（hostmibd）SMUX 故障 — 交接說明

> **對象**：AIX 系統管理者 / DBA
> **主機**：`PRD-ORA-P0WEB_TEST`（172.16.1.101），AIX 6.1 TL09（6100-09）
> **日期**：2026-06-16
> **影響**：LibreNMS 無法取得此機 CPU / 記憶體 / 檔案系統（HOST-RESOURCES-MIB），
> 但網路 / 協定 / Up-Down / Oracle DB 監控均正常。

---

## 零、AIX 6.1 SNMP 關鍵限制（必讀）

### SNMPv1 Only — 不支援 v2c / v3

此機 AIX 6.1 目前執行的是 **SNMPv1 daemon**（`/usr/sbin/snmpd`）。

原因：`dpid2`（DPI2 子代理 broker，1997 年編譯）在 SNMPv3 daemon（`snmpdv3`）下會出現 ASN.1 PDU
解碼失敗（`encode_SMUX_PDUs: pr_seq:missing mandatory parameter`），導致 SMUX 連線每 2–3 分鐘斷一次，
hostmibd / aixmibd 完全無法穩定運作。切換回 SNMPv1 daemon 後 SMUX 連線恢復穩定。

**切換指令（已執行，重開機後自動沿用）**：
```
/usr/sbin/snmpv3_ssw -1
```

### 設定檔：/etc/snmpd.conf（非 snmpdv3.conf）

SNMPv1 daemon 讀取 **`/etc/snmpd.conf`**，格式與 `snmpdv3.conf` 不同。

`/etc/snmpd.conf` 中 LibreNMS 監控 community 設定（已就緒，勿動）：
```
community librenms_ro 172.16.1.94 255.255.255.255 readOnly
```

格式說明：`community <name> <source-ip> <mask> <access>`，限定只允許來自 monitor-vm（172.16.1.94）讀取。

**參考：一源版設定（172.16.1.100，正常機）的 `/etc/snmpd.conf` 為同格式，可對照確認。**

### LibreNMS Add Device 設定

LibreNMS 加入此設備時必須選擇：
- **SNMP Version** = `v1`（不是 v2c）
- **Community** = `librenms_ro`

若已加入且設定為 v2c，請至 Devices → 172.16.1.101 → Edit 修正。

---

## 一、現象

LibreNMS（monitor-vm 172.16.1.94）以 SNMP v1 查詢 172.16.1.101：

- ✅ `sysDescr`（`.1.3.6.1.2.1.1.1.0`）、介面、TCP/UDP 等 → **正常回應**
- 🔴 Host Resources（`.1.3.6.1.2.1.25.*`：`hrProcessorLoad`、`hrStorage`、`hrSystemUptime`）→ **回 NULL**

## 二、根因

AIX 的 SNMP 是「主 snmpd ←(SMUX)← 子代理」架構。Host Resources 由 **`hostmibd`** 子代理提供。
雖然 `lssrc -s hostmibd` 顯示 `active`，但它與主 snmpd 的 SMUX 連線**無法維持**。

`/usr/tmp/snmpdv3.log` 持續出現（每 5 秒一次）：

```
Accepted new SMUX inet socket connection on fd=10 from ::ffff:127.0.0.1 port NNNNN.
Closing SMUX connection, fd=10.
```

即 snmpd 接受子代理的 SMUX 連線後**立即關閉**，導致 hostmibd 無法註冊 `.1.3.6.1.2.1.25` 子樹，
故所有 host resources 查詢回 NULL。

此現象在 log 中**最早可追溯到 2025-09**（早於本次 LibreNMS 監控設定），並非設定造成。

## 三、已排除（設定面確認無誤）

- SNMP community / VACM / view 正確：`sysDescr` 可正常查得、`.25` 已在 view（`1.3.6.1`）範圍內
- `hostmibd` / `aixmibd` / `snmpmibd` / `dpid2` 皆已用正確順序重啟（snmpd→dpid2→子代理）
- 對照組 **172.16.1.100（同為 AIX 6.1）host resources 完全正常**，採相同設定方向

→ 差異在 **172.16.1.101 這台機器的 SNMP 子系統執行狀態**，非 LibreNMS 或設定。

## 三之二、已嘗試的修復與進展（2026-06-16）

對照 .100（正常）= `bos.net.tcp.client 6.1.9.401` + `bos.net.tcp.server 6.1.9.400`、oslevel 6100-09-12，
**與 .101 完全相同**；.100 的 smux 僅 `gated` + `xmtopas`（無 dpid、無 mibd 專屬行）。
→ 排除 fileset 版本與 smux 設定差異。

已做且**有效改善**：
1. 在 `snmpdv3.conf` 補上 dpid 的 SMUX 授權行（原 v1 `snmpd.conf` 有、v3 缺）：
   `smux  1.3.6.1.4.1.2.3.1.2.2.1.1.2  dpid_password`
2. 重建 `/etc/snmpd.boots`（mv 舊檔，snmpd 自動產生新檔）
3. 完整 stack 依序重啟

**結果**：原本每 5 秒一次的 `Accepted→Closing` SMUX 迴圈**已消除**，dpid2 的 SMUX 連線可穩定保持。
**但 host resources（`.25`）仍 NULL** —— hostmibd 的註冊未完成，研判為**啟動時序死結**：
dpid2 的 SMUX 需先就緒，mibd 子代理才能透過它註冊；但手動 `startsrc` 難排出正確時序，
且重啟 mibd 會打斷 dpid2 既有連線（log 可見 EOF）。

## 四、建議修復方向（AIX 管理者）

**首選：在維護時段重開機 .101**（需與 DBA 協調，因跑著 Oracle）
- 開機時 AIX 透過 `/etc/rc.tcpip` 以原廠正確順序與時序啟動 snmpd→dpid2→各 mibd 子代理，
  正是 .100 進入正常狀態的方式；手動 `startsrc` 無法完美複製此時序
- 重開後驗證：`snmpwalk -v2c -c librenms_ro 172.16.1.101 .1.3.6.1.2.1.25.1.1.0` 應回 uptime 數值

**次選（若重開機仍無效）**：
- `installp` 強制重裝 `bos.net.tcp.server`（需安裝媒體）以還原 hostmibd binary/狀態
- 開 IBM case 查 AIX 6.1 TL9 hostmibd / DPI2 / SMUX 已知 APAR
- 檢查是否有第三方 SMUX peer 干擾（log 歷史曾見 `172.16.1.145` 反覆連線失敗）
5. **必要時**：向 IBM 查 AIX 6.1 hostmibd SMUX 已知 APAR（此 TL 偏舊，可能有對應修補）

## 五、修好後（LibreNMS 端零操作）

只要 AIX 端 hostmibd 的 SMUX 穩定（log 不再 accept→close 迴圈、
`snmpwalk -v2c -c librenms_ro 172.16.1.101 .1.3.6.1.2.1.25.1.1.0` 回到實際 uptime 值），
**LibreNMS 會在下次 poll 自動抓到 CPU / 記憶體 / 檔案系統並畫圖，無需任何額外設定**
（view 已開放 `1.3.6.1`、設備已加入、discovery 模組已就緒）。

## 六、目前運作中的監控（不受此問題影響）

- ✅ Oracle DB 完整監控（sessions / SGA hit / I/O / SQL / tablespace / Database Health 等，走 JDBC，與 SNMP 無關）
- ✅ AIX 網路介面流量、TCP/UDP/ICMP 協定統計、Up-Down、可用率（走 snmpd 核心 MIB，正常）

---

### 驗證指令（monitor-vm 172.16.1.94）

```bash
# 注意：必須用 -v1，不是 -v2c
# 應回 AIX 字串（正常）
snmpwalk -v1 -c librenms_ro 172.16.1.101 .1.3.6.1.2.1.1.1.0
# 修好後應回 uptime 數值（目前回 NULL）
snmpwalk -v1 -c librenms_ro 172.16.1.101 .1.3.6.1.2.1.25.1.1.0
```

### AIX 端唯讀監控設定（已就緒，勿動）

**使用 `/etc/snmpd.conf`**（SNMPv1 daemon 設定檔）：
```
community librenms_ro 172.16.1.94 255.255.255.255 readOnly
```
（限定來源 172.16.1.94，唯讀，讀不到 Oracle 資料；Oracle 監控另走 JDBC 不經 SNMP）

`/etc/snmpdv3.conf` 雖仍存在，但目前 SNMPv1 daemon 不讀取它，勿在此檔新增 community。

> ⚠️ **鐵則一**：此機只要重啟 `snmpd`，**必須接著依序重啟** `dpid2 → hostmibd → aixmibd → snmpmibd`，
> 否則 host resources 子代理連線會中斷。
>
> ⚠️ **鐵則二**：LibreNMS / snmpwalk 查詢此機**必須用 v1**，用 v2c 會全部 timeout（daemon 不認識）。
>
> ⚠️ **鐵則三**：SNMP 設定以 `/etc/snmpd.conf` 為準，**勿修改 `/etc/snmpdv3.conf`**（目前未生效）。
