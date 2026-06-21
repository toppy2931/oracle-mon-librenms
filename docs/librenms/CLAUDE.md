# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

LibreNMS — 開源網路監控平台（auto-discovering, SNMP-based）。PHP 8.2+ / Laravel 12，MariaDB，RRDtool，Vue 2 + Vite 前端。

**工作分支：`feature/systex-custom`**（master 受 daily.sh 自動更新管控，自訂修改勿放 master；自動更新已停用）

---

## ⚠️ 高危警告（必知）

1. **雙位置**：本目錄（`D:\claude-code\librenms`）只是參考 clone；**實際執行環境在 WSL2 `/opt/librenms`**（`\\wsl$\Ubuntu-24.04\opt\librenms`），兩者互不同步，修改要生效必須改 WSL2 那份
2. **Composer 禁止在 `/mnt/d` 執行**（NTFS ZipArchive 失敗），一律在 `/opt/librenms`
3. **新 Web 功能一律走 Laravel（`app/`）**；`includes/*.inc.php` 是 legacy 層，只維護不新增
4. 「無法連線」第一步：跑任意 `wsl` 指令喚醒 WSL2（閒置會自動關機，服務會自動帶起）

## 快速指令

```bash
# WSL2 內執行格式（一律 librenms 使用者）
wsl -d Ubuntu-24.04 -e bash -c "sudo -u librenms bash -c 'cd /opt/librenms && <command>'"

php validate.php                    # 健康檢查
./lnms dev:check                    # PR 前必跑：lint + phpstan + phpunit
vendor/bin/phpunit --filter X       # 單一測試
php discovery.php -h all            # 手動 discovery
php poller.php -h all               # 手動 polling
```

- **Web UI**: `http://localhost/`，帳號 `admin` / `Systex@LibreNMS2026!`

## 架構速覽

```
Windows 瀏覽器 → WSL2 Nginx :80 → PHP-FPM 8.3 → /opt/librenms
監控流：snmpd/設備 → discovery.php → poller.php(cron 5min) → RRD+MariaDB → alerts.php(1min)
程式分層：app/(Laravel,新碼) | LibreNMS/(核心類別庫) | includes/(legacy,勿新增)
```

## 自訂擴充（Systex 專屬）

> 以下頁面為本專案新增，不屬於 LibreNMS 上游。

| 檔案 | URL | 功能 |
| ---- | --- | ---- |
| `oracle-admin.php` | `/oracle-admin` | Oracle DB 連線設定 / monitor-vm IP 異動 / AIX SNMP 白名單更新 GUI |
| `oracle-save.php` | POST `/oracle-save` | 寫入 Oracle 連線設定（呼叫 `save-db-conf.sh`） |
| `oracle-test.php` | POST `/oracle-test` | 測試 Oracle 連線（呼叫 `test-db.sh`） |
| `oracle-ip-update.php` | POST `/oracle-ip-update` | 更新 monitor-vm IP 並同步所有設定檔 |

shell 腳本（sudo 權限配置在 `/etc/sudoers.d/oracle-admin-sudoers`）：
- `save-db-conf.sh` / `remove-db-conf.sh` — Oracle 連線設定檔寫入/移除
- `update-snmpd-extends.sh` / `.py` — AIX SNMP Extend 白名單更新
- `update-librenms-url.sh` — LibreNMS base URL 同步
- `test-db.sh` — Oracle 連線測試

## Email / SMTP 設定注意

LibreNMS Settings GUI（`/settings/alerting/email`）儲存的值進 DB，但 **`config.php` 定義的同名 key 永遠優先且以唯讀模式覆蓋 DB**。  
→ **SMTP 設定一律寫在 `/opt/librenms/config.php`**，GUI 僅供確認，勿在 GUI 修改後期望 config.php 自動更新。

目前已設定 Brevo SMTP relay（`config.php` 內），測試發信：
```bash
wsl -d Ubuntu-24.04 -e bash -c "sudo -u librenms bash -c 'cd /opt/librenms && php /tmp/test_mail.php 2>&1'"
```

---

## 規則目錄

> 需要細節時讀取對應檔案；發現規則需補充或調整時，**直接編輯 rules/ 對應檔案**，並在下方「近期規則異動」記錄。

| 檔案 | 內容 |
| ---- | ---- |
| [rules/deployment.md](rules/deployment.md) | WSL2 雙位置部署、帳密、服務管理、系統架構圖 |
| [rules/commands.md](rules/commands.md) | 完整指令：測試、設備管理、使用者、前端、Laravel |
| [rules/architecture.md](rules/architecture.md) | 雙層架構（Laravel/Legacy）、LibreNMS/ namespace、告警、前端、監控資料流 |
| [rules/os-support.md](rules/os-support.md) | 新增設備 OS 支援流程、YAML 定義、snmprec 測試體系 |
| [rules/gotchas.md](rules/gotchas.md) | 防錯機制 / Edge Cases（必讀） |
| [docs/deployment-guide.md](docs/deployment-guide.md) | Linux VM 單機部署文件（含一鍵腳本） |
| [docs/integrated-deployment.md](docs/integrated-deployment.md) | 中型生產雙 VM 整合部署（LibreNMS+jt-ipam / Graylog+OpenSearch） |
| [docs/single-vm-deployment.md](docs/single-vm-deployment.md) | 單機部署（三套合裝，≤100 台）；含 LVM 分割、安裝順序、Nginx 整合 |
| [docs/oracle-dataguard/](docs/oracle-dataguard/) | Oracle 9i DataGuard SNMP Extend 監控套件（腳本 + LibreNMS poller）|

---

## 近期規則異動

| 日期 | 異動 | 檔案 |
| ---- | ---- | ---- |
| 2026-06-11 | 初始部署完成（WSL2 Ubuntu-24.04）；建立 CLAUDE.md + rules/ 結構 | 全部 |
| 2026-06-11 | 建 `feature/systex-custom` 分支；停用 daily.sh 自動更新 | rules/deployment.md, rules/gotchas.md |
| 2026-06-11 | 新增 Linux VM 完整部署文件（21 章節 + 一鍵腳本） | docs/deployment-guide.md |
| 2026-06-12 | 新增中型生產雙 VM 整合部署 Runbook（LibreNMS+jt-ipam / Graylog+OpenSearch，含 LVM 切割、nginx 隔離、備份/災難復原）| docs/integrated-deployment.md |
| 2026-06-13 | 新增單機三合一部署 Runbook（Ubuntu 24.04，≤100 台）；LVM 磁碟規劃、安裝順序、Nginx 統一設定 | docs/single-vm-deployment.md |
| 2026-06-13 | 新增 Oracle 9i DataGuard SNMP Extend 監控套件 | docs/oracle-dataguard/ |
| 2026-06-17 | 新增 Oracle Admin GUI（oracle-admin.php 等 6 檔）；記錄 config.php vs GUI email 設定優先級 | oracle-admin.php, CLAUDE.md |
| 2026-06-17 | Email SMTP 設定改用 Brevo relay，固定寫在 config.php | /opt/librenms/config.php |
