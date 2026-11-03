<?php
/**
 * ioc-data.php — 中文入口「維運總覽」的資料（JSON）。
 * GET /ioc-data.php[?demo=1&range_seconds=1800|3600|14400|28800|43200|86400&trend_seconds=86400|604800|2592000]，需登入、角色為 admin 或 global-read，並帶 X-CSRF-Token 標頭。
 * range_seconds＝Graylog 區塊的統計期間（最長 24 小時）；trend_seconds＝事件趨勢自己的視窗（只讀事件案，預設 30 日）。
 * 資料來源：Graylog（用專用唯讀帳號）、LibreNMS 設備、事件案。任何一塊取不到都明確標示，不顯示成 0 或正常。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

use Illuminate\Support\Facades\Cache;
use Ioc\LandscapeService;
use Ioc\NetworkVisibilityService;

[$actor, $level] = ioc_begin(false);
$demo = ($_GET['demo'] ?? '') === '1';
$rawRange = $_GET['range_seconds'] ?? (string) NetworkVisibilityService::RANGE;
if (!is_string($rawRange) || !ctype_digit($rawRange) || !isset(NetworkVisibilityService::RANGE_LABELS[(int) $rawRange])) {
    ioc_fail(400, '不支援的統計期間');
}
$range = (int) $rawRange;
// 事件趨勢自己的視窗（24 小時／7 日／30 日，預設 30 日）：只讀事件案資料表，不受 Graylog 區塊最長 24 小時的統計期間限制
$rawTrend = $_GET['trend_seconds'] ?? (string) LandscapeService::TREND_DEFAULT;
if (!is_string($rawTrend) || !ctype_digit($rawTrend) || !isset(LandscapeService::TREND_OPTIONS[(int) $rawTrend])) {
    ioc_fail(400, '不支援的趨勢期間');
}
$trend = (int) $rawTrend;

try {
    // 20 秒的快取：多人同時開著也只會打一次 Graylog；不分使用者，因為內容一樣
    $payload = Cache::remember(IOC_OVERVIEW_CACHE . ($demo ? 'demo' : 'live') . ':' . $range . ':' . $trend, 20, static function () use ($demo, $level, $range, $trend) {
        return ioc_overview($demo, $level, $range, $trend);
    });
    ioc_json($payload);
} catch (\Throwable $e) {
    ioc_handle($e);
}
