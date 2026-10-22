# monitor-vm 上既有的 Graylog 設定（FortiGate）

2026-10-20 從 monitor-vm（Graylog 7.1）唯讀取得。這些設定**由 Graylog 介面維護，不是本 repo 的 `system/graylog-pipelines.py` 管的**，
也不會被它套用或回滾。記在這裡有兩個目的：重建環境時有依據（規格 ARC-062、ARC-068），
以及說明 `IOC Normalize - Firewall` 為什麼要排在 stage 2、為什麼要另外連到 `FortiGate Syslog` stream。

> 和 CLAUDE.md「Graylog 進階整合」那一節不同：該節寫的是 Syslog Input 上有 6 個 Grok extractor；
> 這台實際是 CEF 訊息由下面的 pipeline 用 `key_value()` 解析。**以這台實機為準。**

## 訊息怎麼走

```
FortiGate ─ syslog UDP 514 ─► Input syslog_udp_514
                                   │ stream 規則：message 含 deviceExternalId=
                                   ▼
                          Stream「FortiGate Syslog」（移出 Default Stream）
                                   │
              stage 0  ──► fortigate_normalize         key_value() 解析 CEF，寫入 srcip、dstip、action…
              stage 1  ──► fortigate-extract-flow-fields   寫入 source_ip、destination_ip…（給 jt-gelflow）
              stage 2  ──► IOC Normalize - Firewall    寫入 log_type、src_ip、dst_ip、event_action…（本工具）
```

重點：

- 訊息**只在** `FortiGate Syslog`，不在 Default Stream。只連到 Default Stream 的 pipeline 看不到它們。
- 同一個 stream 上的 pipeline 是**依 stage 編號一起往下跑**（所有 pipeline 的 stage 0，再來 stage 1…）。
  `srcip`、`dstip`、`action` 是 `fortigate_normalize` 在 stage 0 才產生的，所以 IOC 的防火牆規則必須排在它之後。
- 這個 stream 目前約每秒 55 筆（2026-10-20 13:09 的 5 分鐘有 16,475 筆）。

## Stream 設定

| Stream | ID | 移出 Default Stream | 規則 |
|---|---|---|---|
| FortiGate Syslog | `6a33ef93a68ab16ef006f414` | 是 | `message` **含** `deviceExternalId=`（規則類型 6，contains） |
| Default Stream | `000000000000000000000001` | — | — |
| All events／All system events | `…0002`／`…0003` | 是 | Graylog 內建 |

stream ID 是各站點自己產生的，換環境就不同，所以本工具的 `GRAYLOG_FIREWALL_STREAMS` 用標題（`FortiGate Syslog`）即可。

## Pipeline `fortigate_normalize`

```
pipeline "fortigate_normalize"
stage 0 match all
rule "fortigate_normalize"
stage 1 match all
rule "fortigate-extract-flow-fields"
end
```

### 規則 `fortigate_normalize`（stage 0）

```
rule "fortigate_normalize"
when
  has_field("message") && contains(to_string($message.message), "CEF:0|Fortinet|Fortigate|")
then
  set_field("devid", regex_replace("^.*?deviceExternalId=([^ ]+).*$", to_string($message.message), "$1"));
  set_fields(key_value(to_string($message.message)));
  set_field("subtype", to_string($message.FTNTFGTsubtype, "", true));
  set_field("eventtype", to_string($message.FTNTFGTeventtype, "", true));
  set_field("eventsubtype", to_string($message.FTNTFGTeventsubtype, "", true));
  set_field("action", to_string($message.act, "", true));
  set_field("srcip", to_string($message.src, "", true));
  set_field("dstip", to_string($message.dst, "", true));
  set_field("srcport", to_long($message.spt, 0, true));
  set_field("dstport", to_long($message.dpt, 0, true));
  set_field("user", to_string($message.duser, "", true));
  set_field("profile", to_string($message.FTNTFGTprofile, "", true));
  set_field("srcintf", to_string($message.deviceInboundInterface, "", true));
  set_field("dstintf", to_string($message.deviceOutboundInterface, "", true));
  set_field("srcname", to_string($message.shost, "", true));
  set_field("dstname", to_string($message.dhost, "", true));
  set_field("srcmac", to_string($message.srcmac, "", true));
  set_field("srchwvendor", to_string($message.srchwvendor, "", true));
  set_field("srcfamily", to_string($message.srcfamily, "", true));
  set_field("osname", to_string($message.osname, "", true));
  set_field("devtype", to_string($message.devtype, "", true));
  set_field("sentbyte", to_long($message.sentbyte, 0, true));
  set_field("rcvdbyte", to_long($message.rcvdbyte, 0, true));
  set_field("sentpkt", to_long($message.sentpkt, 0, true));
  set_field("rcvdpkt", to_long($message.rcvdpkt, 0, true));
  set_field("policyid", to_long($message.FTNTFGTpolicyid, 0, true));
  set_field("policyname", to_string($message.FTNTFGTpolicyname, "", true));
  set_field("applist", to_string($message.FTNTFGTapplist, "", true));
  set_field("appcat", to_string($message.FTNTFGTappcat, "", true));
  set_field("hostname", to_string($message.FTNTFGThostname, "", true));
  set_field("reason", to_string($message.reason, "", true));
  set_field("logdesc", to_string($message.FTNTFGTlogdesc, "", true));
  set_field("catdesc", to_string($message.FTNTFGTcatdesc, "", true));
  set_field("qname", to_string($message.FTNTFGTqname, "", true));
  set_field("qclass", to_string($message.FTNTFGTqclass, "", true));
  set_field("qtype", to_string($message.FTNTFGTqtype, "", true));
  set_field("attackid", to_string($message.FTNTFGTattackid, "", true));
  set_field("attack", to_string($message.FTNTFGTattack, "", true));
  set_field("crlevel", to_string($message.FTNTFGTcrlevel, "", true));
  set_field("srccountry", to_string($message.FTNTFGTsrccountry, "", true));
  set_field("dstcountry", to_string($message.FTNTFGTdstcountry, "", true));
end
```

### 規則 `fortigate-extract-flow-fields`（stage 1）

```
rule "fortigate-extract-flow-fields"
when
  has_field("srcip") AND has_field("dstip") AND
  to_string($message.srcip) != "255.255.255.255" AND
  to_string($message.dstip) != "255.255.255.255" AND
  to_string($message.srcip) != "0.0.0.0" AND
  to_string($message.dstip) != "0.0.0.0"
then
  // 映射 IP / port 到 jt-gelflow 期望的欄位名
  set_field("source_ip",        to_string($message.srcip));
  set_field("destination_ip",   to_string($message.dstip));
  set_field("source_port",      to_long($message.srcport, 0));
  set_field("destination_port", to_long($message.dstport, 0));

  // protocol_name：優先用 appcat（FortiGate App Control 分類），空則用 applist
  let appcat   = to_string($message.appcat, "", true);
  let applist  = to_string($message.applist, "", true);
  let proto    = concat(appcat, applist);
  set_field("protocol_name", proto);

  // network_bytes = 送 + 收
  let sent = to_long($message.sentbyte, 0);
  let rcvd = to_long($message.rcvdbyte, 0);
  set_field("network_bytes", sent + rcvd);

  // 國碼（給 2D Map / 3D Globe / Sankey 用）
  set_field("source_ip_country_code",      to_string($message.srccountry, "", true));
  set_field("destination_ip_country_code", to_string($message.dstcountry, "", true));
end
```

## 實測的流量分布（2026-10-20，FortiGate Syslog，最近 1 小時，共 151,578 筆）

以 `POST /api/search/aggregate`（`group_by` 欄位、`count`）取得。

**分布會隨時間大幅變動，下表只是那一小時的快照**：這一小時含 13:06 前後的一次阻擋突發（Graylog 直方圖可見，每 10 秒約 2,000 筆）。
套用 IOC pipeline 後平穩的 3 分鐘（13:3x）是 `fw_allow` 3,982、`fw_deny` 2,206（阻擋 36%、允許 64%），
另有約 21% 的防火牆訊息（7,794 筆中的 1,606 筆）沒有 `event_action`。

`action`：

| 分類 | 值（筆數） | 合計 | 占比 | IOC 的 `event_action` |
|---|---|---|---|---|
| 阻擋類 | `deny` 92,287、`blocked` 12 | 92,299 | 60.9% | `fw_deny` |
| 允許類 | `pass` 19,753、`accept` 17,451、`passthrough` 8,127 | 45,331 | 29.9% | `fw_allow` |
| 其他 | `close` 7,229、`client-rst` 3,236、`server-rst` 2,065、`timeout` 719、`dns` 393、`ip-conn` 168、`info` 57、`tunnel-stats` 47、`perf-stats` 24、`negotiate` 10 | 13,948 | 9.2% | 無（刻意不歸類：連線結束與統計，非安全事件） |

`cat`（該期間的筆數）：`traffic:local` 97,564、`traffic:forward` 25,961、`utm:dns` 15,918、`utm:app-ctrl` 12,228、`utm:webfilter` 8,120、
`event:endpoint` 1,337、`event:vpn` 85、`utm:ssl` 57、`event:system` 30、`utm:waf` 10、`event:security-rating` 5、`event:user` 4。

給 Phase 1 的備註：

- **`fw_deny` 每小時約 9 萬筆**，而且 `traffic:local` 占大宗（多半是網際網路來的掃描）。入口不能逐筆列，必須彙總，並區分來源是內網還是外網，
  否則「最需要注意的來源」會被外部掃描洗版。
- **`logdesc` 被截斷成單一單字**（`FortiClient`、`IPsec`、`SSL`、`System`、`Authentication`…）。
  很可能是 `fortigate_normalize` 用的 `key_value()` 以空白切欄位，多個字的值（例如 `Admin login failed`）只剩第一個字。
  之後要偵測 FortiGate 管理者登入失敗等事件，要比對 `message` 全文，不要依賴 `logdesc`。
- 查詢請用 `_exists_:欄位`。Graylog 7.1 的搜尋框單打 `*` 會報 `'*' or '?' not allowed as first character in WildcardQuery`。
- **阻擋事件裡有不少廣播雜訊**：例如 `10.60.0.140 → 255.255.255.255`，是區網的 UDP 廣播被 `traffic:local` 擋下。
  既有的 `fortigate-extract-flow-fields` 為 jt-gelflow 特別排除了 `255.255.255.255` 與 `0.0.0.0`；
  入口在算「需注意來源」時也應排除廣播與多播，否則會被雜訊洗版。
- **這個 stream 裡不只一台設備**：`host_name` 有 `Land-Lab-FortiGate-71G`（約 82%）與 `LAND-SSLVPN`（約 18%，是否為另一台 FortiGate 待確認）。
  入口依設備分組時用 `host_name`。
- **搜尋彙整 API 一定要帶 `group_by`**，只有 `metrics` 會回 `400 groupings must not be empty`。要算總數，就 group 一個一定存在的欄位再加總。
- **阻擋事件會有突發**：單一尖峰每 10 秒約 2,000 筆。入口的「待處理事件」、「可疑登入」之類的 KPI 不能直接拿 `fw_deny` 筆數當數字。

## 值得注意

- 這兩條規則的 `to_string(…, "", true)`、`to_long(…, 0, true)` 帶了第三個參數 `true`，本工具產生的規則沒有帶。
  實測（2026-10-20）重播樣本後，四條 IOC pipeline 的 `Processing Errors` 仍是 0（total 0），所以**不需要**加。
- `fortigate_normalize` 把 `act` 原樣複製到 `action`。`act` 的值（`accept`、`deny`、`close`、`timeout`…）決定 IOC 的 `fw_allow`／`fw_deny`；
  其他值（例如連線結束的 `close`）目前沒有 `event_action`。
- `fortigate-extract-flow-fields` 寫的 `source_ip`、`destination_ip` 是給 jt-gelflow 的，和 IOC 的 `src_ip`、`dst_ip` 是**不同欄位**，不衝突。
  但 IOC 的 Windows 規則把 `source_ip` 當成 Winlogbeat 的候選欄位之一，因此 **Windows pipeline 不可連到 `FortiGate Syslog` stream**。
- **既有 pipeline 的位元組數欄位是壞的（monitor-vm 實測，2026-10-21）**：`fortigate_normalize` 讀 `sentbyte`／`rcvdbyte`，
  但這台 FortiGate（v7.4／v7.2）的 CEF 日誌沒有這兩個鍵，位元組數放在 CEF 標準的 `out=`（送出）與 `in=`（收到），
  封包數在 `FTNTFGTsentpkt`／`FTNTFGTrcvdpkt`。結果是 `sentbyte`、`rcvdbyte` 沒有值，`network_bytes` **每一筆都是 0**
  （最近 1 小時 10,942 筆 `traffic:forward` 的 `sum(network_bytes)`＝0、`card(network_bytes)`＝1）；`out`、`in` 則被 `key_value()` 寫成字串欄位，不能直接加總。
  真實日誌的樣子：`… FTNTFGTduration=74 out=4456 in=11139 FTNTFGTsentpkt=20 FTNTFGTrcvdpkt=18 …`（連線結束的 `act=close` 才有位元組數）。
  這條既有 pipeline 不歸本工具管，所以不去改它；IOC 的防火牆 pipeline 新增規則 `ioc-fw-bytes`，從原始訊息文字取 `out=`、`in=`，
  寫標準欄位 `net_bytes`（數字，送出加收到），內網可視性讀這個欄位。**只有套用之後進來的日誌才有 `net_bytes`**。
