# 防錯機制 / Edge Cases（必讀）

| # | 陷阱 | 防錯機制 |
|---|------|---------|
| 1 | **daily.sh 自動更新覆蓋本地修改**：cron 的 `daily.sh` 會 `git pull` 重置 master | 自訂修改一律在 `feature/systex-custom` branch；自動更新已停用（`lnms config:set update false`），勿重新啟用 |
| 2 | **NTFS Composer 失敗**：在 `/mnt/d` 跑 `composer install` 因 ZipArchive `Operation not permitted` 失敗 | Composer 操作一律在 `/opt/librenms`（ext4）執行，禁止在 Windows 掛載目錄跑 |
| 3 | **雙 clone 不同步**：改了 `D:\claude-code\librenms` 但執行環境沒變 | 修改一律以 `/opt/librenms` 為準；Windows 側僅供參考 |
| 4 | **WSL2 閒置自動關機**：使用者回報「無法連線」 | 第一步先跑任意 `wsl` 指令喚醒（systemd 自動帶起服務），勿急著重裝或改設定 |
| 5 | **WSL2 IP 浮動**：`172.25.x.x` 重啟後變動 | 一律用 `http://localhost/`，文件與設定不寫死 IP |
| 6 | **validate.php 誤報 Scheduler FAIL**：oneshot service 執行完即 inactive，驗證腳本誤判 | `systemctl status librenms-scheduler.timer` 顯示 active (waiting) 即正常，勿無限重試修復 |
| 7 | **php-fpm 重啟後 nginx 502** | 檢查 `/run/php-fpm-librenms.sock` 存在且權限正確（www-data 已加入 librenms group） |
| 8 | **lnms 互動式指令卡死**：`user:add` 等指令無參數會進 prompt，非互動環境直接 Exception | CLI 一律帶完整參數（`-p`、`-r`）；密碼會查資料外洩庫，常見密碼（如 `Admin@2026`）被拒 |
| 9 | **`.env` 修改不生效**：Laravel config cache | 改完跑 `php artisan config:clear` |
| 10 | **檔案權限**：root 建立的檔案 librenms user 讀不到 | `/opt/librenms` 內操作一律 `sudo -u librenms`；新檔案 `chown librenms:librenms` |
| 11 | **phpunit 預設排除 groups**：`browser`、`mibs`、`external-dependencies` 不會跑 | 「測試全過」≠ 全部測試；Dusk/MIB 測試需明確指定 group |
| 12 | **新碼放錯層**：在 `includes/` 新增功能 | 新 Web 功能一律 Laravel（`app/`）；`includes/*.inc.php` 只維護不新增，詳見 [architecture.md](architecture.md) |
| 13 | **lnms device:add 選項格式**：`-v2c -c xxx` 短選項合併會解析失敗 | 用 `--v2c --community=xxx` 完整寫法 |
