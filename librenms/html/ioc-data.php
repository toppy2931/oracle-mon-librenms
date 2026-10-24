<?php
/**
 * ioc-data.php — 中文入口「維運總覽」的資料（JSON）。
 * GET /ioc-data.php[?demo=1&range_seconds=1800|3600|14400|28800|43200|86400]，需登入、角色為 admin 或 global-read，並帶 X-CSRF-Token 標頭。
 * 資料來源：Graylog（用專用唯讀帳號）、LibreNMS 設備、事件案。任何一塊取不到都明確標示，不顯示成 0 或正常。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

use Illuminate\Support\Facades\Cache;
use Ioc\NetworkVisibilityService;

[$actor, $level] = ioc_begin(false);
$demo = ($_GET['demo'] ?? '') === '1';
$rawRange = $_GET['range_seconds'] ?? (string) NetworkVisibilityService::RANGE;
if (!is_string($rawRange) || !ctype_digit($rawRange) || !isset(NetworkVisibilityService::RANGE_LABELS[(int) $rawRange])) {
    ioc_fail(400, '不支援的統計期間');
}
$range = (int) $rawRange;

try {
    // 20 秒的快取：多人同時開著也只會打一次 Graylog；不分使用者，因為內容一樣
    $payload = Cache::remember(IOC_OVERVIEW_CACHE . ($demo ? 'demo' : 'live') . ':' . $range, 20, static function () use ($demo, $level, $range) {
        return ioc_overview($demo, $level, $range);
    });
    ioc_json($payload);
} catch (\Throwable $e) {
    ioc_handle($e);
}
