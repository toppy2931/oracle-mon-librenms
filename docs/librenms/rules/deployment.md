# 部署環境（WSL2）

## 雙位置（⚠️ 最重要）

| 位置 | 用途 |
| ---- | ---- |
| `D:\claude-code\librenms` | Windows 側 clone，供瀏覽與編輯參考 |
| `/opt/librenms`（WSL2 Ubuntu-24.04） | **實際執行環境**，Windows 經 `\\wsl$\Ubuntu-24.04\opt\librenms` 存取 |

兩者是**獨立的 git clone，互不同步**。修改要生效必須改 `/opt/librenms`；重要異動需手動同步回 Windows 側。

## 存取資訊

- **Web UI**: `http://localhost/`（WSL2 自動轉發，勿用浮動 IP）
- **Admin**: `admin` / `Systex@LibreNMS2026!`
- **DB**: MariaDB 10.11，database `librenms`，user `librenms` / `librenms_pwd_2026`
- **SNMP community**: `librenms_snmp`
- **PHP-FPM socket**: `/run/php-fpm-librenms.sock`
- **工作分支**: `feature/systex-custom`（master 會被 daily.sh 自動更新，自訂修改勿放 master）
- **自動更新**: 已停用（`lnms config:set update false`）

## 服務管理

WSL2 閒置會自動關機；服務已設 enabled，喚醒 WSL（跑任意 `wsl` 指令）後 systemd 自動帶起。

手動重啟：
```bash
wsl -d Ubuntu-24.04 -e bash -c "sudo service mariadb start && sudo service nginx start && sudo service php8.3-fpm start && sudo service snmpd start && sudo service cron start"
```

排程器：`librenms-scheduler.timer`（systemd，每分鐘）+ `/etc/cron.d/librenms`（poller/discovery wrapper）。

## WSL2 內指令執行格式

一律以 `librenms` 使用者執行：
```bash
wsl -d Ubuntu-24.04 -e bash -c "sudo -u librenms bash -c 'cd /opt/librenms && <command>'"
```

## 系統架構

```
Windows 瀏覽器 http://localhost/
        │ WSL2 localhost 轉發
Nginx :80 ── /run/php-fpm-librenms.sock ── PHP-FPM 8.3
        │
/opt/librenms（Laravel 12 + LibreNMS/ 核心 + includes/ legacy）
        │
MariaDB 10.11 ── RRDtool（rrd/）── snmpd :161 ── cron + systemd timer
```
