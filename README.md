# oracle-mon-librenms

LibreNMS 上的 **Oracle（含 9i）客製監控套件**：透過 JDBC thin 遠端採集 Oracle 指標，
不在被監控的 DB 主機安裝任何 agent，並提供：

- **多 DB 管理 GUI**（`oracle-admin.php`）：新增 / 編輯 / 測試 / 啟停資料庫連線。
- **戰情室即時畫面**（`oracle-dashboard.php`）：集中呈現所有 DB 的 Data Guard、
  Materialized View（snapshot）、健康、表空間、效能；每 60 秒自動刷新、紅黃綠號誌。
- **LibreNMS 原生整合**：application polling + 14 張 RRD 圖表 + 告警規則。

本 repo 的目的：把散落在 monitor-vm 上的客製程式**模組化**，新 VM 重裝後可一鍵重新部署。

---

## 架構

```
被監控 Oracle（如 9i / L1HWEB 172.16.1.101）
        ▲  JDBC thin（ojdbc14.jar，純 Java，DB 端零安裝）
        │
monitor-vm（LibreNMS 主機）
  OracleStats.java  →  run.sh <alias>  →  dbs/<alias>.conf
        │ JSON
  net-snmp  extend oracle-<alias>  →  LibreNMS application poller
        │
  RRD 圖表 + 告警 + oracle-dashboard.php 戰情室
```

採集指標涵蓋：sessions、SGA/library/dict/latch/buffer 命中率、physical I/O、redo、SQL/parse、
sorts、shared pool、表空間使用率、health（invalid objects/indexes、archivelog、open mode）、
**Data Guard**（role / switchover / standby / archive gap / apply lag / configured）、
**Materialized View**（total / stale / broken jobs / failed jobs / 最舊刷新時間）。

---

## 前置需求（monitor-vm）

- LibreNMS（已驗證 26.5.x）安裝於 `/opt/librenms`，PHP-FPM 以 `librenms` 身分執行。
- `default-jdk`（Java 11+，含 `javac`）。
- net-snmp（`snmpd` 支援 `extend`；Debian/Ubuntu extend 執行群組為 `Debian-snmp`）。
- 被監控 DB 上有唯讀帳號：`GRANT CREATE SESSION` + `GRANT SELECT_CATALOG_ROLE`。

> **ojdbc14.jar 授權**：本 repo 內含 Oracle 的 `ojdbc14.jar`（10.2，相容 9i）。
> 該驅動受 Oracle 授權條款規範，僅供已具備 Oracle 使用權者於內部使用。

---

## 安裝（新 VM）

```bash
sudo mkdir -p /opt && cd /opt
sudo git clone <YOUR_PRIVATE_REPO_URL> oracle-mon-librenms
cd /opt/oracle-mon-librenms
sudo ./install.sh                 # 完整安裝（不含 DB 連線）
# 之後用瀏覽器登入 LibreNMS →「⚙ → Oracle 監控管理」新增資料庫
```

install.sh 會：部署 collector 至 `/opt/oracle-mon`、編譯 `OracleStats.java`、
部署 LibreNMS 客製檔、安裝 sudoers、注入 snmpd managed block、修補齒輪選單、
安裝告警規則（add-only）、清 view 快取。**全程冪等**，可重跑。

可用環境變數覆寫路徑：`LIBRENMS_DIR`、`ORACLE_MON_DIR`、`LIBRENMS_USER`、`SNMP_GROUP`、`SNMPD_CONF`。

### 新增一台被監控 DB

建議用 GUI（`/oracle-admin.php`）。或手動建立 `dbs/<alias>.conf`（參考 `collector/dbs/l1hweb.conf.example`），
然後讓 snmpd 重新載入 extend：

```bash
sudo /opt/oracle-mon/admin/update-snmpd-extends.sh
```

並把對應 device 加入 LibreNMS、啟用 application `oracle-<alias>`。

> ⚠️ **目前限制**：LibreNMS 整合檔（polling / graphs / pages）以 app 名稱 `oracle-l1hweb`
> 命名。多台不同 app 名稱需另行產生對應檔（見「TODO / 後續優化」）。同一 `oracle-l1hweb`
> app 下可掛多個 device/instance。

---

## 更新

```bash
cd /opt/oracle-mon-librenms && sudo git pull && sudo ./update.sh
```

`update.sh` 只更新程式碼並重編譯、清快取，**保留 `dbs/*.conf` 與 RRD 歷史**。

## 解除安裝

```bash
sudo ./uninstall.sh            # 移除客製檔，保留 /opt/oracle-mon 與 RRD
sudo ./uninstall.sh --purge    # 連 /opt/oracle-mon（含連線設定）一併刪除
```

---

## 目錄結構

```
collector/        → 部署到 /opt/oracle-mon
  OracleStats.java        JDBC 採集器（輸出 LibreNMS JSON）
  run.sh                  依 alias 載入 dbs/<alias>.conf 後執行採集器
  lib/ojdbc14.jar         Oracle JDBC thin 驅動（相容 9i）
  dbs/l1hweb.conf.example DB 連線設定範本（密碼已遮蔽）
  admin/                  特權 wrapper（sudoers 白名單）：save/remove/test-db/
                          update-snmpd-extends(.sh/.py)/update-librenms-url
librenms/         → 部署到 /opt/librenms 對應子目錄
  html/                   oracle-admin.php、5 個 ajax、oracle-dashboard(.php/-data.php)
  graphs/                 14 個 application graph 定義
  polling/                application polling include
  pages/                  device/apps 與全域 apps 頁
system/
  snmpd-extend.conf.snippet  snmpd managed block 範本
  sudoers.oracle-admin       librenms 可呼叫 admin 腳本之 sudoers
  menu-patch.py              冪等注入齒輪選單
  install_alert_rules.php    add-only 告警規則安裝器（--fix-legacy 可修舊規則）
install.sh / update.sh / uninstall.sh
docs/
  single-vm-deployment.md    LibreNMS + Graylog + jt-ipam 單機部署 Runbook（主文件）
  deployment-guide.md        Linux VM 單機部署（舊版參考）
  integrated-deployment.md   雙 VM 中型生產部署參考
  oracle9i-monitoring.md     Oracle 9i JDBC thin 監控架構說明
  oracle9i-aix-snmp-handoff.md  AIX SNMP 交接文件
  oracle-dataguard/          DG/MView 早期參考實作
  librenms/
    CLAUDE.md                LibreNMS 專案 Claude Code 指示（WSL2 環境、Oracle GUI、SMTP）
    rules/
      deployment.md          WSL2 雙位置部署、帳密、服務管理
      architecture.md        雙層架構（Laravel/Legacy）、監控資料流
      commands.md            完整指令：測試、設備管理、前端、Laravel
      gotchas.md             防錯機制 / Edge Cases
      os-support.md          新增設備 OS 支援流程
```

---

## 安全

- `dbs/*.conf`（含密碼）由 `.gitignore` 排除，**永不入庫**；只提交 `*.example`。
- 特權操作集中於 `/opt/oracle-mon/admin/`，由 `/etc/sudoers.d/oracle-admin` 白名單授權給 `librenms`。
- Web 端：所有頁面 / 端點以 `Auth::user()->hasRole('admin')` 驗證；
  寫入 / 資料端點額外驗 CSRF（`X-CSRF-Token`）。

---

## 告警規則

`install.sh` 會新增（add-only，依名稱冪等）：

| 名稱 | 條件 | 嚴重度 |
|------|------|--------|
| Oracle MView 刷新 Job 中斷 | `mview_jobs_broken > 0` | warning |
| Oracle MView 刷新 Job 失敗 | `mview_jobs_failed > 0` | warning |
| Oracle MView 超過7天未刷新 | `mview_oldest_hours > 168` | warning |
| Oracle DataGuard Archive Gap | `dataguard_dg_gap > 0` | critical |
| Oracle DataGuard Apply Lag 過大 | `dataguard_dg_apply_lag > 15` | warning |

> 既有規則 #1/#2（Oracle DB Down / Archivelog Off）參照的 metric 名稱為舊式
> （`instance_up` / `archivelog_mode`），與目前 `application_metrics` 的
> `{category}_{field}` 命名不符，故不會觸發。若要修正：
> `sudo -u librenms php system/install_alert_rules.php --fix-legacy`

---

## TODO / 後續優化

- **去硬編碼 `oracle-l1hweb`**：將 polling / graphs / pages 改為樣板 + 產生器，
  讓每個 DB alias 自動產生對應 LibreNMS 整合檔，達成真正多實例。
- 戰情室加入歷史趨勢圖（嵌入 LibreNMS RRD graph）。
