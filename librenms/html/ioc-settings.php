<?php
/**
 * ioc-settings.php — 入口設定 API（JSON）。POST /ioc-settings.php，內容 {"action": "...", ...}
 *
 *   get                                   目前的風險等級門檻與內部網段、預設值、最近的異動紀錄（檢視者可）
 *   save  {risk_low, risk_medium}         儲存風險等級門檻（僅 admin）
 *   save_network {cidrs}                  儲存內部網段（僅 admin；內網可視性用來分「內部對內部」）
 *
 * 權限、驗證、預設值與異動紀錄都在 Ioc\SettingsService；這裡只負責登入、CSRF、解析內容與回應格式。
 * 需登入、角色為 admin 或 global-read，並帶 X-CSRF-Token 標頭。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

[$actor, $level] = ioc_begin(true);

$raw = (string) file_get_contents('php://input', false, null, 0, 4097);
if (strlen($raw) > 4096) {
    ioc_fail(413, '內容太大');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    ioc_fail(400, '內容不是合法的 JSON');
}

try {
    $svc = ioc_settings();
    switch ((string) ($in['action'] ?? '')) {
        case 'get':
            ioc_json(['risk' => $svc->riskSettings($level), 'network' => $svc->networkSettings($level)]);
            // no break（ioc_json 會結束）
        case 'save':
            $r = $svc->saveRisk(ioc_pick($in, ['risk_low', 'risk_medium']), $actor, $level);
            ioc_overview_cache_forget();          // 總覽快取裡有算好的風險等級
            ioc_json(['risk' => $r]);
        case 'save_network':
            $r = $svc->saveNetwork(ioc_pick($in, ['cidrs']), $actor, $level);
            ioc_network_cache_forget();           // 內網可視性的快取是用舊網段算的
            ioc_overview_cache_forget();          // 總覽「防火牆阻擋的來源」也用同一份內部網段分內外
            ioc_json(['network' => $r]);
        default:
            ioc_fail(400, '不認得的動作');
    }
} catch (\Throwable $e) {
    ioc_handle($e);
}
