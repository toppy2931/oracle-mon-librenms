<?php
/**
 * ioc-network.php — 內網可視性 API（JSON，全部唯讀）。POST /ioc-network.php，內容 {"action": "get", "demo": true|false, "range_seconds": 1800|3600|14400|28800|43200|86400}
 *
 *   get   東西向流量佔比與趨勢、Top Talkers、異常連線清單與異常 SMB、IDS 告警（防火牆日誌，來自 Graylog）
 *
 * admin 與 global-read 都能看（與總覽、資安頁一致）。規則與資料處理在 Ioc\NetworkVisibilityService、內部網段在
 * Ioc\IpNets 與 SettingsService；這裡只負責登入、CSRF、解析內容、快取與回應格式。
 * 查詢次數多（約 20 次 Graylog 查詢），所以有 60 秒的伺服器端快取；內部網段一改就清掉（ioc_network_cache_forget）。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

use Illuminate\Support\Facades\Cache;
use Ioc\NetworkVisibilityService;

[$actor, $level] = ioc_begin(true);

$raw = (string) file_get_contents('php://input', false, null, 0, 1025);
if (strlen($raw) > 1024) {
    ioc_fail(413, '內容太大');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    ioc_fail(400, '內容不是合法的 JSON');
}

try {
    switch ((string) ($in['action'] ?? '')) {
        case 'get':
            $demo = ($in['demo'] ?? false) === true;       // 只收真正的 true，不收字串
            $range = $in['range_seconds'] ?? NetworkVisibilityService::RANGE;
            if (!is_int($range) || !isset(NetworkVisibilityService::RANGE_LABELS[$range])) {
                ioc_fail(400, '不支援的統計期間');
            }
            // 快取不分使用者：admin 與檢視者看到的內容一樣；每個統計期間分開計算
            $payload = Cache::remember(IOC_NETWORK_CACHE . ($demo ? 'demo' : 'live') . ':' . $range, 60, static function () use ($demo, $level, $range) {
                return ioc_network($demo, $level, $range);
            });
            ioc_json(['network' => $payload]);
            // no break（ioc_json 會結束）
        default:
            ioc_fail(400, '不認得的動作');
    }
} catch (\Throwable $e) {
    ioc_handle($e);
}
