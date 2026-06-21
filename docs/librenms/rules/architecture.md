# 架構：雙層並存（Laravel + Legacy）

LibreNMS 正從 legacy 程序式程式碼遷移到 Laravel，兩層長期並存。

## 三層規則

| 層 | 位置 | 規則 |
| ---- | ---- | ---- |
| **Laravel（新碼用這層）** | `app/`（Models、Controllers、Jobs、Console）、`routes/`、`resources/views/` | 新 Web 功能一律走 Laravel 慣例：Eloquent、Controller、Blade |
| **核心類別庫** | `LibreNMS/` namespace | CLI 與 Web 共用的可攜邏輯：OS 類別、模組、工具 |
| **Legacy（勿新增）** | `includes/discovery/`、`includes/polling/`（.inc.php）、`includes/html/` | 只在維護既有 discovery/polling 模組時觸碰；CLI-only，與 Laravel 隔離 |

## LibreNMS/ namespace 重點

- `LibreNMS/OS/` — 每個設備 OS 一個類別（OS 特定行為）
- `LibreNMS/Modules/` — 現代化 discovery/polling 模組（Ports、Sensors、ArpTable…）
- `LibreNMS/Util/` — 工具類（IP、Dns、Graph、StringHelpers…，約 44 個類別）
- `LibreNMS/Data/` — 時序資料層：`Store/`（RRD、InfluxDB、Kafka）、`Graphing/`
- `LibreNMS/Alert/` — 告警引擎：`RunAlerts` 協調器、`Transport/` 各通知通道（Email、Slack、Webhook…）
- `LibreNMS/Discovery/` — YAML discovery 框架（`YamlDiscoveryDefinition`）

## 告警系統

- 規則/模板/排程存 DB：`app/Models/Alert*`（Alert、AlertRule、AlertTemplate、AlertTransport、AlertSchedule）
- 執行邏輯：`LibreNMS/Alert/`，模板式訊息產生，支援 per-device / per-group 排程

## 前端

- Vite（`vite.config.mjs`）：entry `resources/js/app.js` → 輸出 `html/`（web root）
- Vue 2.7 + Alpine.js + Tailwind 4 + Bootstrap 4 + jQuery 混用（漸進遷移中）
- Blade views：`resources/views/`；legacy 靜態資源與入口：`html/`

## 程式碼風格

- PHP-CS-Fixer（`.php-cs-fixer.php`，PSR-12）
- PHPStan level 5（`phpstan.neon`，涵蓋 app/、LibreNMS/、includes/、tests/）
- Rector（`rector.php`，PHP 現代化）
- 三者由 `./lnms dev:check` 一次執行
- 全域 helper：`includes/helpers.php`（composer autoload files）

## 監控資料流

```
snmpd/設備 → discovery.php（identify OS → 建 device 資料）
           → poller.php（cron 每 5 分鐘，更新 metrics）
           → RRDtool（rrd/ 時序檔案）+ MariaDB（結構化資料）
           → alerts.php（每分鐘，評估 AlertRule → Transport 派送）
```
