# Oracle 9i 客製監控建置手冊

> **建置日期**：2026-06-15
> **適用環境**：monitor-vm（Ubuntu 24.04）+ LibreNMS 26.5.1
> **監控目標**：Oracle 9i R2（9.2.0.8.0）on AIX 6.1

---

## 目錄

1. [架構總覽](#1-架構總覽)
2. [前置條件](#2-前置條件)
3. [Oracle 端準備（AIX）](#3-oracle-端準備aix)
4. [Stage 1 — 安裝 JDK](#4-stage-1--安裝-jdk)
5. [Stage 2 — 放置 JDBC 驅動](#5-stage-2--放置-jdbc-驅動)
6. [Stage 3 — 憑證檔](#6-stage-3--憑證檔)
7. [Stage 4 — 採集程式 OracleStats.java](#7-stage-4--採集程式-oraclestatsjava)
8. [Stage 5 — Wrapper 腳本與手動測試](#8-stage-5--wrapper-腳本與手動測試)
9. [Stage 6 — net-snmp extend](#9-stage-6--net-snmp-extend)
10. [Stage 7 — LibreNMS 自訂 Application](#10-stage-7--librenms-自訂-application)
11. [驗收清單](#11-驗收清單)
12. [踩坑紀錄](#12-踩坑紀錄)
13. [維護與擴充](#13-維護與擴充)
14. [dbstat2.sh 健檢指標整合](#14-dbstat2sh-健檢指標整合)

---

## 1. 架構總覽

```
┌─────────────────────┐         JDBC thin (port 1521)        ┌──────────────────────┐
│   monitor-vm        │ ◄──────────────────────────────────► │  AIX PRD 主機        │
│   172.16.1.94       │                                      │  172.16.1.101        │
│   Ubuntu 24.04      │                                      │  Oracle 9.2.0.8.0    │
│                     │                                      │  SID: L1HWEB         │
│  ┌───────────────┐  │                                      │  (零變更)            │
│  │ OracleStats   │──┤  Java 程式透過 ojdbc14.jar           └──────────────────────┘
│  │ .java         │  │  每 5 分鐘撈 v$/dba_ 資料
│  └───────┬───────┘  │
│          │ stdout   │
│          ▼ JSON     │
│  ┌───────────────┐  │
│  │ net-snmp      │  │  snmpd extend 將 JSON 暴露為 OID
│  │ extend        │  │
│  └───────┬───────┘  │
│          │ SNMP     │
│          ▼          │
│  ┌───────────────┐  │
│  │ LibreNMS      │  │  poll → 解析 JSON → 存 RRD → 畫圖
│  │ Application   │  │
│  └───────────────┘  │
└─────────────────────┘
```

**為何採此架構（選項 C）**：

| 考量 | 說明 |
|------|------|
| AIX 零變更 | PRD 正式機不安裝任何額外軟體 |
| AIX snmpd 不支援 extend | 原生 snmpd，非 net-snmp |
| JDBC thin 純 Java | 不裝 Oracle native client，避開 Ubuntu 24.04 glibc/32-bit 問題 |
| ojdbc14.jar 10.2 | 唯一對 Oracle 9.2 有官方相容性的 JDBC 驅動 |

**否決的替代方案**：

- ~~完整 Oracle 9i client~~：Ubuntu 24.04 裝不起來（32-bit 依賴地獄）
- ~~Instant Client 10.2~~：2010 年 binary 在新 glibc 上風險高（列備案）
- ~~python-oracledb thin~~：最低支援 Oracle 12.1，連不了 9i

---

## 2. 前置條件

- [x] monitor-vm（172.16.1.94）已安裝 Ubuntu 24.04
- [x] LibreNMS 已部署並可正常運作
- [x] net-snmp（snmpd）已安裝（LibreNMS 部署時即帶）
- [x] monitor-vm 與 Oracle 主機（172.16.1.101）網路互通（同 172.16.1.0/24 網段）
- [x] Oracle 端已建立唯讀監控帳號

---

## 3. Oracle 端準備（AIX）

在 Oracle 9i 主機上以 DBA（SYS/SYSTEM）登入 sqlplus 執行：

```sql
-- 建立唯讀監控帳號
CREATE USER librenms IDENTIFIED BY librenms;
GRANT CREATE SESSION TO librenms;
GRANT SELECT_CATALOG_ROLE TO librenms;
```

**驗證**：

```sql
-- 以 librenms 帳號登入測試
CONNECT librenms/librenms

-- 確認可查詢
SELECT status FROM v$instance;           -- 預期: OPEN
SELECT count(*) FROM v$session;          -- 預期: 數百
SELECT tablespace_name FROM dba_tablespaces;  -- 預期: 多筆
```

> **安全建議**：正式環境建議改強密碼：
> ```sql
> ALTER USER librenms IDENTIFIED BY "YourStr0ngP@ss!";
> ```
> 改完後需同步更新 monitor-vm 的 `/etc/oracle-mon.conf`。

**權限說明**：

| 權限 | 用途 |
|------|------|
| `CREATE SESSION` | 允許連線 |
| `SELECT_CATALOG_ROLE` | 唯讀存取所有 `v$` 動態效能視圖和 `dba_` 資料字典（含 `v$instance`、`v$session`、`v$sysstat`、`dba_data_files`、`dba_free_space`、`dba_tablespaces`） |

---

## 4. Stage 1 — 安裝 JDK

```bash
sudo apt update
sudo apt install -y default-jdk
java -version    # 預期: openjdk 21.x
javac -version   # 預期: javac 21.x
```

> **備案**：若後續 Stage 5 測試時 ojdbc14.jar 在 Java 21 出現相容性問題，
> 改裝 Adoptium Temurin 8，用其 `java` 執行（編譯仍可用 21）。

---

## 5. Stage 2 — 放置 JDBC 驅動

```bash
sudo mkdir -p /opt/oracle-mon/lib
```

取得 **ojdbc14.jar**（Oracle Database 10.2.0.5 版）：

- 來源：[Oracle JDBC Driver Archive](https://www.oracle.com/database/technologies/appdev/jdbc-downloads.html)
  → Database 10.2.0.5 → `ojdbc14.jar`
- 這是 Oracle 9.2 相容性最穩的 JDBC 驅動（新版 ojdbc8/ojdbc11 不保證連 9.2）

```bash
# 將下載的 jar 放進去
sudo cp ojdbc14.jar /opt/oracle-mon/lib/
sudo chmod 644 /opt/oracle-mon/lib/ojdbc14.jar

# 驗證
ls -la /opt/oracle-mon/lib/ojdbc14.jar
```

---

## 6. Stage 3 — 憑證檔

將 Oracle 連線資訊與程式碼分離，並鎖定檔案權限：

```bash
sudo tee /etc/oracle-mon.conf > /dev/null << 'EOF'
ORA_USER=librenms
ORA_PASS=librenms
ORA_URL=jdbc:oracle:thin:@//172.16.1.101:1521/L1HWEB
EOF

sudo chown root:Debian-snmp /etc/oracle-mon.conf
sudo chmod 640 /etc/oracle-mon.conf
```

**參數說明**：

| 變數 | 值 | 說明 |
|------|------|------|
| `ORA_USER` | `librenms` | Stage 3 建立的唯讀帳號 |
| `ORA_PASS` | `librenms` | 帳號密碼（建議改強密碼） |
| `ORA_URL` | `jdbc:oracle:thin:@//172.16.1.101:1521/L1HWEB` | JDBC thin 連線字串 |

**權限說明**：

- `Debian-snmp` 是 Ubuntu 上 snmpd 的執行帳號（snmpd extend 以此身分跑）
- `chmod 640` = owner(root) 讀寫、group(Debian-snmp) 唯讀、others 無權限
- 確認 snmpd 執行帳號：`ps -ef | grep snmpd`

---

## 7. Stage 4 — 採集程式 OracleStats.java

```bash
sudo tee /opt/oracle-mon/OracleStats.java > /dev/null << 'JAVA'
import java.sql.*;
import java.util.*;

public class OracleStats {
  static String q1(Statement st, String sql) throws SQLException {
    try (ResultSet r = st.executeQuery(sql)) { return r.next() ? r.getString(1) : "0"; }
  }
  static String num(String v) {
    if (v == null || v.isEmpty()) return "0";
    if (v.startsWith(".")) return "0" + v;
    if (v.startsWith("-.")) return "-0" + v.substring(1);
    return v;
  }
  public static void main(String[] a) {
    String url  = System.getenv("ORA_URL");
    String user = System.getenv("ORA_USER");
    String pass = System.getenv("ORA_PASS");
    Map<String,String> m = new LinkedHashMap<>();
    StringBuilder ts = new StringBuilder("[");
    int error = 0;
    String errorString = "";
    try {
      Class.forName("oracle.jdbc.OracleDriver");
      try (Connection c = DriverManager.getConnection(url, user, pass);
           Statement st = c.createStatement()) {
        m.put("instance_up",
              "OPEN".equals(q1(st,"select status from v$instance")) ? "1" : "0");
        m.put("sessions_total",  num(q1(st,"select count(*) from v$session")));
        m.put("sessions_active",
              num(q1(st,"select count(*) from v$session where status='ACTIVE'")));
        m.put("logons_current",
              num(q1(st,"select value from v$sysstat where name='logons current'")));
        m.put("buffer_hit_pct", num(q1(st,
          "select round((1-(p.value/(d.value+co.value)))*100,2) from "+
          "(select value from v$sysstat where name='physical reads') p,"+
          "(select value from v$sysstat where name='db block gets') d,"+
          "(select value from v$sysstat where name='consistent gets') co")));
        try (ResultSet r = st.executeQuery(
          "select t.tablespace_name,"+
          " round((d.bytes-nvl(f.bytes,0))/d.bytes*100,1) pct from"+
          " (select tablespace_name,sum(bytes) bytes from dba_data_files group by tablespace_name) d,"+
          " (select tablespace_name,sum(bytes) bytes from dba_free_space group by tablespace_name) f,"+
          " dba_tablespaces t where t.tablespace_name=d.tablespace_name"+
          " and t.tablespace_name=f.tablespace_name(+)")) {
          boolean first=true;
          while (r.next()) {
            if(!first) ts.append(",");
            ts.append("{\"name\":\"").append(r.getString(1))
              .append("\",\"pct_used\":").append(num(r.getString(2))).append("}");
            first=false;
          }
        }
      }
    } catch (Exception e) {
      error = 1;
      errorString = e.getMessage().replace("\"","'");
      m.put("instance_up", "0");
    }
    ts.append("]");
    StringBuilder out = new StringBuilder("{\"version\":1,\"error\":");
    out.append(error).append(",\"errorString\":\"").append(errorString).append("\",\"data\":{");
    boolean first=true;
    for (Map.Entry<String,String> e : m.entrySet()) {
      if(!first) out.append(",");
      out.append("\"").append(e.getKey()).append("\":")
         .append(e.getValue()==null?"0":e.getValue());
      first=false;
    }
    out.append(",\"tablespaces\":").append(ts).append("}}");
    System.out.println(out);
  }
}
JAVA
```

**編譯**：

```bash
sudo javac -d /opt/oracle-mon /opt/oracle-mon/OracleStats.java
```

**採集的指標**：

| 指標 | SQL 來源 | 說明 |
|------|----------|------|
| `instance_up` | `v$instance.status` | 1=OPEN, 0=異常 |
| `sessions_total` | `count(*) from v$session` | 總連線數 |
| `sessions_active` | `v$session where status='ACTIVE'` | 活躍連線數 |
| `logons_current` | `v$sysstat` | 目前登入數 |
| `buffer_hit_pct` | `v$sysstat` (physical reads / block gets + consistent gets) | Buffer Cache 命中率（%）|
| `tablespaces[].pct_used` | `dba_data_files` + `dba_free_space` | 各表空間使用率（%）|

> **Oracle 9i 注意**：9i 沒有 `dba_tablespace_usage_metrics`（10g+ 才有），
> 必須用 `dba_data_files` + `dba_free_space` 手動計算使用率。

**`num()` 函式的用途**：Oracle 回傳的數值可能是 `.3`（缺少前導零），
這不是合法 JSON。`num()` 將 `.3` → `0.3`，避免 LibreNMS 的 `json_app_get` 解析失敗。

**輸出格式**（LibreNMS 標準 JSON app 格式）：

```json
{
  "version": 1,
  "error": 0,
  "errorString": "",
  "data": {
    "instance_up": 1,
    "sessions_total": 929,
    "sessions_active": 930,
    "logons_current": 930,
    "buffer_hit_pct": 99.92,
    "tablespaces": [
      {"name": "SYSTEM", "pct_used": 97.2},
      {"name": "L1PA_TAB", "pct_used": 83.1},
      ...
    ]
  }
}
```

---

## 8. Stage 5 — Wrapper 腳本與手動測試

```bash
sudo tee /opt/oracle-mon/run.sh > /dev/null << 'EOF'
#!/bin/bash
set -a
. /etc/oracle-mon.conf
set +a
exec java -cp /opt/oracle-mon:/opt/oracle-mon/lib/ojdbc14.jar OracleStats
EOF

sudo chown root:Debian-snmp /opt/oracle-mon/run.sh
sudo chmod 750 /opt/oracle-mon/run.sh
```

**手動測試**（模擬 snmpd 的執行身分）：

```bash
sudo -u Debian-snmp /opt/oracle-mon/run.sh
```

**預期輸出**：一行 JSON，含 `"instance_up":1` 和 26 個 tablespace。

**JSON 合法性驗證**：

```bash
sudo -u Debian-snmp /opt/oracle-mon/run.sh | python3 -m json.tool > /dev/null && echo "JSON valid" || echo "JSON invalid"
```

**排錯**：

| 症狀 | 原因 | 解法 |
|------|------|------|
| `Permission denied` | Debian-snmp 無權讀取 conf 或 run.sh | 確認 `chown root:Debian-snmp` + `chmod 640/750` |
| `ClassNotFoundException: oracle.jdbc` | ojdbc14.jar 路徑錯或不存在 | 確認 `/opt/oracle-mon/lib/ojdbc14.jar` 存在 |
| `IO Exception: The Network Adapter could not establish the connection` | 網路不通或 port 錯 | `telnet 172.16.1.101 1521` 測試 |
| `ORA-01017: invalid username/password` | 帳密錯誤 | 確認 `/etc/oracle-mon.conf` 內容 |

---

## 9. Stage 6 — net-snmp extend

編輯 `/etc/snmp/snmpd.conf`，加入一行：

```
extend oracle_l1hweb /opt/oracle-mon/run.sh
```

重啟 snmpd 並驗證：

```bash
sudo systemctl restart snmpd

# 用數字 OID 驗證（不依賴 MIB 檔）
snmpwalk -v2c -c librenms_snmp localhost .1.3.6.1.4.1.8072.1.3.2.3.1.2
```

> **注意**：community string 用你的實際值（如 `librenms_snmp`），不是 `public`。

**預期輸出**：看到 `oracle_l1hweb` 字樣和完整 JSON 字串。

**MIB 名稱解析**（選用）：

```bash
# 如果想用 MIB 名稱而非數字 OID
sudo apt install -y snmp-mibs-downloader
sudo download-mibs

# 之後可以用：
snmpwalk -v2c -c librenms_snmp localhost 'NET-SNMP-EXTEND-MIB::nsExtendOutputFull."oracle_l1hweb"'
```

---

## 10. Stage 7 — LibreNMS 自訂 Application

### 10.1 Polling 檔

建立 `/opt/librenms/includes/polling/applications/oracle_l1hweb.inc.php`：

```bash
sudo -u librenms tee /opt/librenms/includes/polling/applications/oracle_l1hweb.inc.php > /dev/null << 'PHP'
<?php

use LibreNMS\Exceptions\JsonAppException;
use LibreNMS\Exceptions\JsonAppMissingKeysException;
use LibreNMS\RRD\RrdDefinition;

$name = 'oracle_l1hweb';

try {
    $oracle_data = json_app_get($device, $name, 1)['data'];
} catch (JsonAppMissingKeysException $e) {
    $oracle_data = $e->getParsedJson()['data'] ?? [];
} catch (JsonAppException $e) {
    echo PHP_EOL . $name . ':' . $e->getCode() . ':' . $e->getMessage() . PHP_EOL;
    update_application($app, $e->getCode() . ':' . $e->getMessage(), []);
    return;
}

$metrics = [];

// ── Sessions ──
$category = 'sessions';
$fields = [
    'total'  => $oracle_data['sessions_total'] ?? 0,
    'active' => $oracle_data['sessions_active'] ?? 0,
    'logons' => $oracle_data['logons_current'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('total', 'GAUGE', 0)
    ->addDataset('active', 'GAUGE', 0)
    ->addDataset('logons', 'GAUGE', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Performance ──
$category = 'performance';
$fields = [
    'instance_up'    => $oracle_data['instance_up'] ?? 0,
    'buffer_hit_pct' => $oracle_data['buffer_hit_pct'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('instance_up', 'GAUGE', 0, 1)
    ->addDataset('buffer_hit_pct', 'GAUGE', 0, 100);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Per-Tablespace ──
$tablespaces = $oracle_data['tablespaces'] ?? [];
foreach ($tablespaces as $ts) {
    $ts_name = strtolower($ts['name'] ?? 'unknown');
    $category = 'ts_' . $ts_name;
    $fields = [
        'pct_used' => $ts['pct_used'] ?? 0,
    ];
    $rrd_def = RrdDefinition::make()
        ->addDataset('pct_used', 'GAUGE', 0, 100);
    $rrd_name = ['app', $name, $app->app_id, $category];
    $metrics[$category] = $fields;
    $tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
    app('Datastore')->put($device, 'app', $tags, $fields);
}

update_application($app, 'OK', $metrics);
PHP
```

**RRD 檔案產出**（每次 poll 自動建立）：

| RRD 檔案 | 內含 DS | 說明 |
|-----------|---------|------|
| `app-oracle_l1hweb-{id}-sessions.rrd` | total, active, logons | 連線數 |
| `app-oracle_l1hweb-{id}-performance.rrd` | instance_up, buffer_hit_pct | 效能 |
| `app-oracle_l1hweb-{id}-ts_{name}.rrd` | pct_used | 每個表空間一個 |

### 10.2 Graph 定義檔

**Sessions 圖**（`includes/html/graphs/application/oracle_l1hweb_sessions.inc.php`）：

```bash
sudo -u librenms tee /opt/librenms/includes/html/graphs/application/oracle_l1hweb_sessions.inc.php > /dev/null << 'PHP'
<?php

require 'includes/html/graphs/common.inc.php';

$scale_min = 0;
$nototal = 1;
$unit_text = 'Sessions';
$unitlen = 15;
$bigdescrlen = 20;
$smalldescrlen = 15;
$colours = 'mixed';

$rrd_filename = Rrd::name($device['hostname'], ['app', 'oracle_l1hweb', $app->app_id, 'sessions']);

$array = [
    'total'  => 'Total',
    'active' => 'Active',
    'logons' => 'Logons Current',
];

$rrd_list = [];
$i = 0;
foreach ($array as $ds => $descr) {
    $rrd_list[$i]['filename'] = $rrd_filename;
    $rrd_list[$i]['descr'] = $descr;
    $rrd_list[$i]['ds'] = $ds;
    $i++;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
PHP
```

**Buffer Cache Hit % 圖**（`includes/html/graphs/application/oracle_l1hweb_buffer.inc.php`）：

```bash
sudo -u librenms tee /opt/librenms/includes/html/graphs/application/oracle_l1hweb_buffer.inc.php > /dev/null << 'PHP'
<?php

require 'includes/html/graphs/common.inc.php';

$scale_min = 0;
$scale_max = 100;
$nototal = 1;
$unit_text = 'Percent';
$unitlen = 15;
$bigdescrlen = 20;
$smalldescrlen = 15;
$colours = 'mixed';

$rrd_filename = Rrd::name($device['hostname'], ['app', 'oracle_l1hweb', $app->app_id, 'performance']);

$array = [
    'buffer_hit_pct' => 'Buffer Cache Hit %',
];

$rrd_list = [];
$i = 0;
foreach ($array as $ds => $descr) {
    $rrd_list[$i]['filename'] = $rrd_filename;
    $rrd_list[$i]['descr'] = $descr;
    $rrd_list[$i]['ds'] = $ds;
    $i++;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
PHP
```

**Tablespace Usage % 圖**（`includes/html/graphs/application/oracle_l1hweb_tablespaces.inc.php`）：

```bash
sudo -u librenms tee /opt/librenms/includes/html/graphs/application/oracle_l1hweb_tablespaces.inc.php > /dev/null << 'PHP'
<?php

require 'includes/html/graphs/common.inc.php';

$scale_min = 0;
$scale_max = 100;
$nototal = 1;
$unit_text = 'Percent';
$unitlen = 15;
$bigdescrlen = 20;
$smalldescrlen = 15;
$colours = 'mixed';

$rrd_list = [];
$i = 0;

$rrd_dir = \LibreNMS\Config::get('rrd_dir', '/opt/librenms/rrd') . '/' . $device['hostname'];
$glob_pattern = $rrd_dir . '/app-oracle_l1hweb-' . $app->app_id . '-ts_*.rrd';

foreach (glob($glob_pattern) as $rrd_file) {
    if (preg_match('/ts_(.+)\.rrd$/', basename($rrd_file), $matches)) {
        $rrd_list[$i]['filename'] = $rrd_file;
        $rrd_list[$i]['descr'] = strtoupper($matches[1]);
        $rrd_list[$i]['ds'] = 'pct_used';
        $i++;
    }
}

usort($rrd_list, function ($a, $b) {
    return strcmp($a['descr'], $b['descr']);
});

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
PHP
```

### 10.3 Application 頁面定義

建立 `/opt/librenms/includes/html/pages/device/apps/oracle_l1hweb.inc.php`：

```bash
sudo -u librenms tee /opt/librenms/includes/html/pages/device/apps/oracle_l1hweb.inc.php > /dev/null << 'PHP'
<?php

$graphs = [
    'oracle_l1hweb_sessions' => 'Oracle Sessions',
    'oracle_l1hweb_buffer' => 'Buffer Cache Hit %',
    'oracle_l1hweb_tablespaces' => 'Tablespace Usage %',
];

foreach ($graphs as $key => $text) {
    $graph_type = $key;
    $graph_array['height'] = '100';
    $graph_array['width'] = '215';
    $graph_array['to'] = time();
    $graph_array['id'] = $app['app_id'];
    $graph_array['type'] = 'application_' . $key;

    echo '<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">' . $text . '</h3>
    </div>
    <div class="panel-body">
    <div class="row">';
    include 'includes/html/print-graphrow.inc.php';
    echo '</div>
    </div>
    </div>';
}
PHP
```

### 10.4 註冊 Application 到 DB

```bash
# 查 monitor-vm 的 device_id
mysql -u root librenms -e "SELECT device_id, hostname FROM devices;"

# 註冊 application（device_id 換成實際值）
mysql -u root librenms -e \
  "INSERT INTO applications (device_id, app_type, app_status, app_state) \
   VALUES (1, 'oracle_l1hweb', 'OK', 'UNKNOWN');"
```

> **注意**：MariaDB root 可免密碼登入，但 librenms 帳號密碼在 `/opt/librenms/.env` 的 `DB_PASSWORD`。

### 10.5 首次 Poll 驗證

```bash
cd /opt/librenms
sudo -u librenms php lnms device:poll 127.0.0.1 -m applications -vv 2>&1 | grep -i -A5 oracle
```

**成功輸出特徵**：

- 看到完整 JSON 字串
- 看到多行 `RRD[create ...]` 和 `RRD[update ...]`
- 結尾無 `Invalid JSON` 或 error code

**確認 RRD 檔案**：

```bash
ls -la /opt/librenms/rrd/127.0.0.1/app-oracle_l1hweb-*
# 預期：sessions.rrd + performance.rrd + 26 個 ts_*.rrd
```

### 10.6 （選用）修改設備顯示名稱

```bash
mysql -u root librenms -e \
  "UPDATE devices SET display = 'monitor-vm (Oracle L1HWEB 172.16.1.101 代理)' WHERE device_id = 1;"
```

---

## 11. 驗收清單

| Stage | 驗收指令 | 預期結果 |
|-------|----------|----------|
| 1 | `java -version` | openjdk 21.x |
| 2 | `ls /opt/oracle-mon/lib/ojdbc14.jar` | 檔案存在 |
| 3 | `sudo -u Debian-snmp cat /etc/oracle-mon.conf` | 三行環境變數 |
| 4 | `ls /opt/oracle-mon/OracleStats.class` | class 檔存在 |
| 5 | `sudo -u Debian-snmp /opt/oracle-mon/run.sh` | 一行 JSON（instance_up:1） |
| 5+ | `... \| python3 -m json.tool > /dev/null` | `JSON valid` |
| 6 | `snmpwalk -v2c -c librenms_snmp localhost .1.3.6.1.4.1.8072.1.3.2.3.1.2` | JSON 從 SNMP 吐出 |
| 7 | `lnms device:poll ... -m applications` | `RRD[update ...]` 無 error |
| 7+ | LibreNMS UI → Apps → Oracle L1hweb → 127.0.0.1 | 3 張圖表 |

---

## 12. 踩坑紀錄

### 12.1 Oracle 回傳 `.3` 非合法 JSON

**症狀**：poll 日誌顯示 `oracle_l1hweb:-3:Invalid JSON`

**原因**：Oracle `ROUND()` 回傳 `.3`（缺前導零），這不是 RFC 8259 合法 JSON。
PHP `json_decode` 會回傳 NULL。

**解法**：在 OracleStats.java 加 `num()` 函式：`.3` → `0.3`、`-.5` → `-0.5`。

### 12.2 MIB 找不到 NET-SNMP-EXTEND-MIB

**症狀**：`snmpwalk` 用 MIB 名稱查詢時顯示 `Unknown Object Identifier`

**原因**：Ubuntu 24.04 預設未安裝完整 MIB 檔。

**解法**：
```bash
sudo apt install -y snmp-mibs-downloader && sudo download-mibs
```
或直接用數字 OID（`.1.3.6.1.4.1.8072.1.3.2.3.1.2`）繞過。

### 12.3 SNMP community string 不匹配

**症狀**：`snmpwalk` 回傳 `Timeout: No Response from localhost`

**原因**：community string 用了 `public`，但 snmpd.conf 設定的是 `librenms_snmp`。

**解法**：確認 `/etc/snmp/snmpd.conf` 裡的 `rocommunity` 值，兩邊一致。

### 12.4 OracleStats.java 輸出缺少 error/errorString

**症狀**：LibreNMS `json_app_get` 報 JSON 格式不符

**原因**：初版 JSON 只有 `{"version":1,"data":{...}}`，缺少 LibreNMS 標準格式要求的 `error` 和 `errorString` 欄位。

**解法**：輸出改為 `{"version":1,"error":0,"errorString":"","data":{...}}`。

### 12.5 LibreNMS App 頁面沒圖表

**症狀**：Apps → Oracle L1hweb 頁面只有設備列表，點進去沒有圖。

**原因**：缺少 application 頁面定義檔 `includes/html/pages/device/apps/oracle_l1hweb.inc.php`。

**解法**：建立該檔（見 Stage 7.3），定義要顯示的 graph 類型。

---

## 13. 維護與擴充

### 13.1 新增監控指標

1. 在 `OracleStats.java` 的 `data` 區塊新增 SQL 查詢
2. 重新編譯：`javac -d /opt/oracle-mon /opt/oracle-mon/OracleStats.java`
3. 在 polling 檔新增對應的 RRD category
4. 新增 graph 定義檔
5. 在 app 頁面定義檔加入新 graph

### 13.2 新增第二台 Oracle 資料庫

多 DB 監控架構（未來擴充用）：

```
targets.conf（清單驅動）
├── l1hweb|jdbc:oracle:thin:@//172.16.1.101:1521/L1HWEB|librenms|librenms
├── newdb|jdbc:oracle:thin:@//172.16.1.xxx:1521/NEWDB|librenms|password
└── ...

常駐 daemon（cron 或 systemd timer）
├── 讀 targets.conf
├── 每 DB 獨立 Java 呼叫
├── JSON 快取至 /run/oracle-mon/{sid}.json
└── 超過 300 秒未更新 → instance_up:0（stale detection）

read.sh（per-DB wrapper）
├── 檢查 /run/oracle-mon/{sid}.json 是否 stale
└── 輸出 JSON（或 stale fallback）

snmpd.conf
├── extend oracle_l1hweb /opt/oracle-mon/read.sh l1hweb
├── extend oracle_newdb  /opt/oracle-mon/read.sh newdb
└── ...
```

每新增一台 DB：
1. 在 `targets.conf` 加一行
2. 在 `snmpd.conf` 加一行 extend
3. 複製 LibreNMS polling/graph/app 定義檔（改名）
4. DB 註冊 `INSERT INTO applications`

### 13.3 改密碼

```sql
-- Oracle 端
ALTER USER librenms IDENTIFIED BY "NewStr0ngP@ss!";
```

```bash
# monitor-vm 端同步
sudo vi /etc/oracle-mon.conf   # 改 ORA_PASS
sudo systemctl restart snmpd
```

### 13.4 已知限制

- Oracle 9i 不支援 AWR/ASH（10g+ 功能），無法取得 top SQL、等待事件明細
- hit ratio（buffer/dict/lib/latch）皆為**自啟動起算的累計值**，反映長期平均而非瞬時；
  瞬時波動需用 delta 計算（目前未實作）。physical reads/writes、parse、execute、table scans
  已用 RRD COUNTER 型解決此問題（自動算 rate/sec）
- TEMP tablespace 使用率已納入（`v$temp_space_header`，見 §14.2）
- 採集頻率受 LibreNMS poll 間隔限制（預設 5 分鐘）

---

## 檔案清單

| 主機 | 路徑 | 用途 |
|------|------|------|
| monitor-vm | `/etc/oracle-mon.conf` | Oracle 連線憑證 |
| monitor-vm | `/opt/oracle-mon/OracleStats.java` | 採集程式原始碼 |
| monitor-vm | `/opt/oracle-mon/OracleStats.class` | 編譯後 bytecode |
| monitor-vm | `/opt/oracle-mon/run.sh` | 執行 wrapper |
| monitor-vm | `/opt/oracle-mon/lib/ojdbc14.jar` | JDBC 驅動（10.2.0.5） |
| monitor-vm | `/etc/snmp/snmpd.conf` | 加入 extend 行 |
| monitor-vm | `/opt/librenms/includes/polling/applications/oracle_l1hweb.inc.php` | Polling 定義 |
| monitor-vm | `/opt/librenms/includes/html/graphs/application/oracle_l1hweb_sessions.inc.php` | Sessions 圖表 |
| monitor-vm | `/opt/librenms/includes/html/graphs/application/oracle_l1hweb_buffer.inc.php` | Buffer Hit 圖表 |
| monitor-vm | `/opt/librenms/includes/html/graphs/application/oracle_l1hweb_tablespaces.inc.php` | Tablespace 圖表 |
| monitor-vm | `/opt/librenms/includes/html/graphs/application/oracle_l1hweb_sga.inc.php` | SGA Hit Ratios 圖表（dbstat2.sh）|
| monitor-vm | `/opt/librenms/includes/html/graphs/application/oracle_l1hweb_io.inc.php` | Physical I/O 圖表（dbstat2.sh）|
| monitor-vm | `/opt/librenms/includes/html/graphs/application/oracle_l1hweb_sql.inc.php` | SQL Activity 圖表（dbstat2.sh）|
| monitor-vm | `/opt/librenms/includes/html/graphs/application/oracle_l1hweb_sga_memory.inc.php` | SGA Memory 圖表（dbstat2.sh）|
| monitor-vm | `/opt/librenms/includes/html/graphs/application/oracle_l1hweb_waits.inc.php` | Problem Indicators 圖表（dbstat2.sh）|
| monitor-vm | `/opt/librenms/includes/html/graphs/application/oracle_l1hweb_health.inc.php` | Database Health 圖表（dbreport SQL）|
| monitor-vm | `/opt/librenms/includes/html/pages/device/apps/oracle_l1hweb.inc.php` | App 頁面定義（含繁中說明）|
| monitor-vm | `/opt/librenms/app/Models/Application.php` | ⚠️ core 客製：`displayName()` 自訂 app 名稱（見 §17.2，升級會被覆蓋）|

---

## 14. dbstat2.sh 健檢指標整合

> **背景**：客戶維護用的 `dbstat2.sh`（Oracle Database Health Check Report Ver 2.0）是一次性的
> sqlplus 健檢報告腳本。從中萃取**適合長期趨勢監控**的指標，整合進 LibreNMS 持續採集 + 畫圖。
> 一次性報告型查詢（如 listener log、alert log、session 明細、SQL 全文）不適合 RRD，未納入。

### 14.1 整合的指標對照表

| LibreNMS 圖表 | 指標欄位 | dbstat2.sh 來源 | 健檢閾值 |
|---------------|----------|-----------------|----------|
| **SGA Hit Ratios** | `dict_cache_hit_pct` | `v$rowcache` | <90% → 加 SHARED_POOL_SIZE |
| | `lib_cache_hit_pct` | `v$librarycache` | <95% → 加 SHARED_POOL_SIZE |
| | `latch_hit_pct` | `v$latch` | <90% → latch contention |
| | `buffer_hit_pct` | `v$sysstat` | <90% → 加 DB_BLOCK_BUFFERS |
| **Physical I/O** | `physical_reads` | `v$sysstat` | COUNTER → rate/sec 趨勢 |
| | `physical_writes` | `v$sysstat` | COUNTER → rate/sec 趨勢 |
| | `redo_writes` | `v$sysstat` | COUNTER → redo 活動 |
| **SQL Activity** | `execute_count` | `v$sysstat` | COUNTER → 工作負載 |
| | `parse_total` | `v$sysstat` | COUNTER → 解析活動 |
| | `parse_hard` | `v$sysstat` | hard parse 高 → SQL 未共享 |
| | `sql_executing` | `v$sqlarea.users_executing` | 當下執行中語句數 |
| **SGA Memory** | `shared_pool_free` | `v$sgastat` (free memory) | 趨近 0 → shared pool 不足 |
| | `shared_pool_total` | `v$sgastat` | shared pool 總量 |
| **Problem Indicators** | `rollback_wait_pct` | `v$rollstat` | >5% → 加 rollback segments |
| | `disk_sort_pct` | `v$sysstat` (sorts disk/memory) | >5% → 加 SORT_AREA_SIZE |
| | `temp_pct_used` | `v$temp_space_header` | TEMP 表空間使用率 |
| **Table Scans** | `table_scans_long` | `v$sysstat` | COUNTER → full table scan 趨勢 |
| | `table_scans_short` | `v$sysstat` | COUNTER |

### 14.2 設計重點

- **批次撈 `v$sysstat`**：原本每個指標一次 query，改為一次 `WHERE name IN (...)` 撈 13 個累計計數器，
  減少往返。Buffer Hit %、Disk Sort % 改在 Java 端從批次資料計算，不再額外 query。
- **COUNTER vs GAUGE**：累計型計數器（physical reads、parse count、execute count、table scans）
  用 RRD `COUNTER` 型，RRDtool 自動算每秒速率，避免「自開機起算」的累計值失去趨勢意義。
  比率型（hit %、wait %）用 `GAUGE`。
- **首次 poll 後 COUNTER 圖無值屬正常**：COUNTER 需要兩個資料點才能算 rate，第二次 poll（或 5 分鐘後
  自動 poll）後才會出現曲線。
- **Temp tablespace 單獨處理**：一般 tablespace 用 `dba_data_files`+`dba_free_space`，
  但 TEMP 的 free space 計算方式不同，必須改用 `v$temp_space_header`（這是原版 tablespace 查詢
  抓不到 temp 的原因）。

### 14.3 健檢基準值（L1HWEB 實測 2026-06-15）

| 指標 | 實測值 | 狀態 |
|------|--------|------|
| Dictionary Cache Hit | 99.99% | ✅ |
| Library Cache Hit | 99.99% | ✅ |
| Latch Hit | 95.6% | ✅ |
| Buffer Cache Hit | 99.9% | ✅ |
| Disk Sort % | 0.0% | ✅ |
| Rollback Wait % | 0% | ✅ |
| Hard Parse / Total Parse | 22,924 / 16 億 | ✅ 極低 |
| Shared Pool Free | 44MB / 285MB（~15%）| ✅ |
| **Temp TBS Used** | **45.7%** | ⚠️ 觀察 |
| **SYSTEM TBS Used** | **97.2%** | 🔴 需 DBA 處理 |

### 14.4 未納入監控的 dbstat2.sh 區段（一次性報告，不適合 RRD）

- Listener log / Alert log tail（文字日誌 → 應由 Graylog/syslog 收）
- Session 連線明細、Session idle time（瞬時清單，非趨勢）
- Top 5 Disk I/O SQL、Low performance SQL（SQL 全文 → 適合 AWR，9i 無）
- Datafile 逐檔 I/O 明細（檔案數多，建議只看 tablespace 層級）
- Rollback segment 逐段明細、Lock/Latch 明細清單
- Initialization parameters、Database links、Object count（組態快照，變動低）
- OS 層 `df -vg` / `vmstat` / `lsps` / `lsvg`（→ 應由 LibreNMS 對 AIX 做 SNMP 主機監控）

---

## 15. dbreport SQL 分析與 Database Health 指標

> **背景**：客戶另一套維護腳本 `dbreport/SQL/*.sql`（36 個 sqlplus 報表查詢）。經分析，
> 絕大多數與 §14 的 dbstat2.sh 同源（cache hit、sort、tablespace、table scan、SGA free…），
> 已在監控中。從中再萃取 **4 個全新、適合長期告警的純量指標**，新增「Database Health」分類。

### 15.1 新增的 Database Health 指標

| 指標 | 來源 SQL | 查詢 | 健檢意義 |
|------|----------|------|----------|
| `archivelog_mode` | `dbstatus.sql` | `v$database.log_mode='ARCHIVELOG'?1:0` | **0 = 未開歸檔** → 無法熱備份/PITR，PRD 高風險 |
| `db_open` | `dbstatus.sql` | `v$database.open_mode like 'READ WRITE%'?1:0` | 0 = DB 非正常開啟 |
| `invalid_objects` | `object_iv.sql` | `count(*) dba_objects where status='INVALID'`（排除系統 schema） | 數量暴增 = 有東西壞了 |
| `invalid_indexes` | `in_stat_iv.sql` | `count(*) dba_indexes where status='INVALID'`（排除系統 schema） | INVALID 索引 = 查詢效能風險 |

RRD 分類 `health`（皆 GAUGE）：`invalid_obj` / `invalid_idx` / `archivelog` / `db_open`。
圖表 `oracle_l1hweb_health` 顯示 Invalid Objects/Indexes 趨勢；archivelog/db_open 為 0/1 狀態指標（可設告警）。

### 15.2 dbreport SQL 分類（36 個）

- **已監控（§14 同源）**：`cache_hit`、`sort_area`、`tablespace`、`temp_datafile`、`full_table_scan`、`free_memory`/`cree_memory`、`rollback_usage`/`rbks`
- **新增 health**：`dbstatus`、`object_iv`、`in_stat_iv`
- **一次性稽核/組態（不適合 RRD，未納入）**：`db_tgnts`(grants)、`db_user`、`role`、`dblink`、`init_parameter`、`datafile`/`io_status`(逐檔)、`disk_read`/`low_sql`/`utmodify`(top SQL)、`session_idle`/`user_hit`、`dbblock_extent`/`user_usage`、`obj_stat`/`object_v`/`in_stat`/`in_stat_v`(完整清單)、`title80`/`title132`(報表排版)、`db_rbks`(rbs 組態)、`redo`(redo 設定)
- **選配未做**：`fragment.sql`（tablespace FSFI 碎片指數，per-TS，可比照 ts_* 加）

### 15.3 L1HWEB 實測（2026-06-16）

| 指標 | 實測值 | 狀態 |
|------|--------|------|
| `archivelog_mode` | **0** | 🔴 **未開歸檔模式** → 知會 DBA 確認是否刻意；PRD 通常應開以支援熱備份/PITR |
| `db_open` | 1 (READ WRITE) | ✅ |
| `invalid_objects` | **281** | ⚠️ 建議 DBA 跑 `?/rdbms/admin/utlrp.sql` 重編譯後再觀察 |
| `invalid_indexes` | 0 | ✅ |

---

## 16. AIX 主機 OS 層監控（172.16.1.101）

> 目標：在同一 LibreNMS 加入 AIX 主機設備，補 CPU/記憶體/網路等 OS 層指標。

### 16.1 已完成

- AIX 加入 LibreNMS（device_id 12，OS 自動偵測為 `aix`）
- AIX 端 `/etc/snmpdv3.conf` 新增**限定來源（172.16.1.94）的唯讀 community `librenms_ro`**，
  view 開放 `1.3.6.1`（讀不到 Oracle 資料；Oracle 走 JDBC 與 SNMP 無關）
- ✅ 正常運作：網路介面流量、TCP/UDP/ICMP 協定統計、Up-Down、可用率

### 16.2 未解（CPU/記憶體/檔案系統）— AIX 子系統故障，已交接

- HOST-RESOURCES-MIB（`.1.3.6.1.2.1.25.*`）回 NULL：`hostmibd` 子代理的 SMUX 連線被 snmpd
  即時關閉（`Accepted→Closing` 每 5 秒迴圈），無法註冊子樹
- 現象早於本次設定（log 可追溯至 2025-09），且 **172.16.1.100 同款 AIX 正常** → 機器子系統問題
- 詳細交接（症狀／證據／檢查方向）見 **[oracle9i-aix-snmp-handoff.md](oracle9i-aix-snmp-handoff.md)**
- **修好後 LibreNMS 端零操作**：view 已開、設備已加、discovery 就緒，下次 poll 自動畫圖

### 16.3 AIX SNMP 操作鐵則

> 此機只要重啟 `snmpd`，**必須接著依序重啟** `dpid2 → hostmibd → aixmibd → snmpmibd`，
> 否則 host resources 子代理連線中斷、`.25` 全回 NULL。

---

## 17. UI 客製（名稱與頁面說明）

> ⚠️ 以下為 **core 檔案客製**（custom 分支、已停自動更新）。未來 `git pull` 可能覆蓋，需重補。

### 17.1 設備顯示名稱（DB 層，升級不影響）

存於 `devices.display` 欄位，非 core 檔案，升級安全：

```sql
UPDATE devices SET display='monitor-vm（監控主機）'   WHERE device_id=1;   -- LibreNMS 採集主機（127.0.0.1）
UPDATE devices SET display='L1HWEB（Oracle 9i 主機）' WHERE device_id=12;  -- AIX 172.16.1.101
```

### 17.2 App 顯示名稱（core model 客製）

App 麵包屑名稱由 `app/Models/Application.php` 的 `displayName()` 對 app_type 做 `niceCase()` 自動產生
（`oracle_l1hweb` → `Oracle L1hweb`）。**與裝置主機名無關**。改為固定中文名：

```php
// app/Models/Application.php → displayName()
public function displayName()
{
    if ($this->app_type === 'oracle_l1hweb') { return 'L1HWEB（Oracle 9i 主機）'; }
    return StringHelpers::niceCase($this->app_type);
}
```

套用後 `sudo -u librenms php artisan cache:clear`。麵包屑顯示 `Apps » L1HWEB（Oracle 9i 主機）`。
> **升級後若被覆蓋**：重新加回那行 `if` 即可。

### 17.3 App 頁面繁中說明（per-graph）

`includes/html/pages/device/apps/oracle_l1hweb.inc.php` 將 `$graphs` 改為 `key => [標題, 繁中說明]`，
每張圖標題下渲染一行說明（意義 + 注意門檻）。樣式：`font-size:16px;color:#64b5f6;font-weight:600`。
此檔本就是自訂 app 頁面（非內建），升級不影響。

### 17.4 全域 Apps 頁（監控入口 / 取代 dashboard）

`includes/html/pages/apps/oracle_l1hweb.inc.php`（自訂新檔，升級不影響）—— 全域 Apps 頁，
標題「L1HWEB（Oracle 9i 主機）」+ 右上「採集自 monitor-vm（監控主機）」，一頁顯示全部 9 張圖。

**監控入口網址（加書籤）**：`http://172.16.1.94/apps/app=oracle_l1hweb`

> **為何不用 dashboard 的 Graph widget**：dashboard widget 的 Application 選擇器（`graph.blade.php` JS）
> 用 `split('_')` 從圖名反推 app_type，只取第二個 token（`oracle`），無法處理含底線的 app_type
> `oracle_l1hweb` → 下拉永遠空白。LibreNMS 內建 app 的 app_type 都不含底線即為此因。
> 故改用此全域 Apps 頁作為單頁監控入口（功能更完整，且無此限制）。

### 17.5 base_url / APP_URL（全站連結正確性）

舊式 `generate_link()` 連結用 LibreNMS `base_url`、Laravel 絕對網址用 `.env` 的 `APP_URL`。
兩者未設時 fallback 到 `localhost` → 以 IP 存取時連結跳 localhost 失敗。已設定：

```bash
sudo -u librenms php lnms config:set base_url http://172.16.1.94   # 改成實際存取網址
# .env: APP_URL=http://172.16.1.94 ; 之後 php artisan config:clear
```
