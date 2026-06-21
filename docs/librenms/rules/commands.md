# 常用指令

> 所有指令在 WSL2 `/opt/librenms` 內以 `librenms` 使用者執行，格式見 [deployment.md](deployment.md)。

## 健康檢查

```bash
php validate.php          # 安裝/設定健康狀態（Scheduler FAIL 誤報見 gotchas.md）
```

## PR 前必跑

```bash
./lnms dev:check          # php-cs-fixer + phpstan level 5 + phpunit 一次執行
```

## 測試

```bash
vendor/bin/phpunit                          # 全部（排除 browser/mibs/external-dependencies groups）
vendor/bin/phpunit --filter SomeTest        # 單一測試
./lnms dev:check unit -o <osname>           # 特定 OS 的 snmpsim 測試
./lnms dev:simulate <os_variant>            # 啟動 snmpsim 模擬器（127.1.6.1:1161）
```

## 設備管理

```bash
./lnms device:add <hostname> --v2c --community=librenms_snmp
php discovery.php -h <device|all> [-m <module>]     # 手動 discovery
php poller.php -h <device|all> [-m <module>]        # 手動 polling
```

## 使用者管理

```bash
# 非互動環境必須帶完整參數（-p、-r），否則 prompt Exception
php lnms user:add <name> -p "<password>" -r admin -e <email> -l "<fullname>"
```

## 前端

```bash
npm run dev      # Vite watch
npm run build    # production build → html/
```

## 新 OS 支援測試資料

```bash
./scripts/collect-snmp-data.php -h <device_id>      # 擷取 snmprec
./scripts/save-test-data.php -o <os>                # 產生 JSON 基準
```

## Laravel

```bash
php artisan config:clear        # .env 修改後必跑
php artisan migrate --force     # DB migration
```
