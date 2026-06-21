# 替代方案：Laravel-native widget / API 實作（已被取代，僅存檔參考）

> ⚠️ **此目錄為早期實驗，未部署、未使用。** 正式採用的是 standalone PHP 方案
> （`oracle-mon-librenms/librenms/html/oracle-dashboard.php` + `oracle-dashboard-data.php`），
> 已部署於 monitor-vm（172.16.1.94）。保留此目錄僅供日後若想改走 Laravel-native 路線時參考。

## 背景

2026-06-17 早上曾先嘗試用 LibreNMS 的 Laravel 框架原生方式做 Oracle 戰情室：
- 自訂 API endpoint（`/api/v0/oracle-dg-mv-status`）回傳 DG/MView 狀態
- Dashboard widget（可拖拉到 LibreNMS 既有儀表板）
- 戰情頁透過 server-side cURL 呼叫該 API 取資料

後來改為 **standalone PHP** 方案，原因：
- 不需動到 LibreNMS Laravel route / controller（升級時不會衝突）
- 不需處理 API token 簽發
- 部署只要丟 `.php` 到 `html/`，`install.sh` 冪等覆蓋即可

## 此目錄內容（對應 LibreNMS 部署路徑）

| 檔案 | 原始路徑 | 說明 |
| ---- | -------- | ---- |
| `oracle-dashboard.php` | `/opt/librenms/oracle-dashboard.php` | 早期戰情頁（呼叫 Laravel API） |
| `app/Api/Controllers/OracleDgMvController.php` | 同左 | DG/MView 狀態 API |
| `app/Http/Controllers/Widgets/OracleStatusController.php` | 同左 | 儀表板 widget 控制器 |
| `resources/views/widgets/oracle-status.blade.php` | 同左 | widget 顯示模板 |
| `resources/views/widgets/settings/oracle-status.blade.php` | 同左 | widget 設定模板 |
| `routes.patch` | `routes/web.php` + `routes/api.php` | 需注入的路由（diff 格式） |

## 若要啟用此方案

1. 套用 `routes.patch` 到 LibreNMS 的 `routes/web.php` 與 `routes/api.php`
2. 把 `app/`、`resources/` 下的檔案複製到 `/opt/librenms` 對應位置
3. `php artisan route:clear && php artisan view:clear`
4. 設定 API token（`config('app.oracle_dashboard_token')`）

> 注意：LibreNMS 每次升級都需重新確認 route 注入點，這正是改用 standalone PHP 的主因。
