<?php
/** 裝置行為工作台 API。所有請求都驗證登入、角色與 CSRF。 */
declare(strict_types=1);

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

[$actor, $level] = ioc_begin(true);
$raw = (string) file_get_contents('php://input', false, null, 0, 16385);
if (strlen($raw) > 16384) { ioc_fail(413, '請求內容太大'); }
$in = json_decode($raw, true);
if (!is_array($in)) { ioc_fail(400, '內容不是合法的 JSON'); }

try {
    $svc = new \Ioc\BehaviorService(\Illuminate\Support\Facades\DB::connection()->getPdo());
    $str = static function (string $key) use ($in): string {
        if (!is_string($in[$key] ?? null)) { throw new \Ioc\ValidationError(['欄位格式不正確：' . $key]); }
        return $in[$key];
    };
    $id = static function (string $key) use ($in): int {
        if (!is_int($in[$key] ?? null) || $in[$key] < 1 || $in[$key] > 2147483647) {
            throw new \Ioc\ValidationError(['識別碼不正確：' . $key]);
        }
        return $in[$key];
    };
    switch ($in['action'] ?? null) {
        case 'get': ioc_json(['behavior' => $svc->state($level)]);
        case 'detail': ioc_json(['detail' => $svc->detail($id('asset_id'), $level)]);
        case 'set_mode':
            $svc->setMode($id('asset_id'), $str('mode'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'set_alert_status':
            $svc->setAlertStatus($id('alert_id'), $str('status'), $str('reason'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'retry_delivery':
            $svc->retryDelivery($id('alert_id'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'save_schedule':
            $svc->saveSchedule($str('scope'), $str('scope_key'), $str('timezone'), $str('workdays'),
                $str('start_time'), $str('end_time'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'confirm_hosts':
            // items：[{id, name?}]；形狀與長度由服務檢查（整批在同一個交易裡，任何一台不合格就全部不動）
            $confirmed = $svc->confirmHosts(is_array($in['items'] ?? null) ? $in['items'] : [], $str('note'), $actor, $level);
            ioc_json(['ok' => true, 'confirmed' => $confirmed]);
        case 'link_asset':
            $svc->linkAsset($id('asset_id'), $id('device_id'), $str('reason'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'save_source_inventory':
            $svc->saveSourceInventory($str('source_key'), $str('kind'), $str('scope_label'),
                $str('status'), $str('coverage'), $actor, $level);
            ioc_json(['ok' => true]);
        default: ioc_fail(400, '不認得的動作');
    }
} catch (\Throwable $e) { ioc_handle($e); }
