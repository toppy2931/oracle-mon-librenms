<?php
/**
 * ioc-correlation.php — 跨來源關聯建議 API（JSON，唯讀）。POST /ioc-correlation.php，內容 {"action": "get", "demo": true|false}
 *
 *   get   規則式的關聯建議（同一來源既被防火牆阻擋又登入失敗、嘗試多個帳號後有成功登入、同一目的被多個內部來源擋下）
 *
 * admin 與 global-read 都能看建議；「建立事件案」只有 admin（畫面只給 admin 按鈕，建立仍走 ioc-cases.php 的 create 與權限檢查）。
 * 只給建議，不自動建案，沒有任何自動處置。規則與門檻在 Ioc\CorrelationService（提案版）。
 * 一次約 5 個 Graylog 彙整查詢，所以有 60 秒的伺服器端快取（正式／範例分開）；內部網段一改就清掉（ioc_network_cache_forget）。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

use Illuminate\Support\Facades\Cache;

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
            // 快取不分使用者：admin 與檢視者看到的建議一樣
            $payload = Cache::remember(IOC_CORRELATION_CACHE . ($demo ? 'demo' : 'live'), 60, static function () use ($demo, $level) {
                return ioc_correlation($demo, $level);
            });
            ioc_json(['correlation' => $payload]);
            // no break（ioc_json 會結束）
        default:
            ioc_fail(400, '不認得的動作');
    }
} catch (\Throwable $e) {
    ioc_handle($e);
}
