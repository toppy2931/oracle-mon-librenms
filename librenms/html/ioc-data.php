<?php
/**
 * ioc-data.php — 中文入口「維運總覽」的資料（JSON）。
 * GET /ioc-data.php[?demo=1]，需登入、角色為 admin 或 global-read，並帶 X-CSRF-Token 標頭。
 * 資料來源：Graylog（用專用唯讀帳號）、LibreNMS 設備、事件案。任何一塊取不到都明確標示，不顯示成 0 或正常。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

use Illuminate\Support\Facades\Cache;

[$actor, $level] = ioc_begin(false);
$demo = ($_GET['demo'] ?? '') === '1';

try {
    // 20 秒的快取：多人同時開著也只會打一次 Graylog；不分使用者，因為內容一樣
    $payload = Cache::remember(IOC_OVERVIEW_CACHE . ($demo ? 'demo' : 'live'), 20, static function () use ($demo, $level) {
        return ioc_overview($demo, $level);
    });
    ioc_json($payload);
} catch (\Throwable $e) {
    ioc_handle($e);
}
