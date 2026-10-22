<?php
/**
 * ioc-settings.php — 入口設定 API（JSON）。POST /ioc-settings.php，內容 {"action": "...", ...}
 *
 *   get                                   目前的風險等級門檻、內部網段與站點、預設值、最近的異動紀錄（檢視者可）
 *   save  {risk_low, risk_medium}         儲存風險等級門檻（僅 admin）
 *   save_network {cidrs}                  儲存內部網段（僅 admin；內網可視性用來分「內部對內部」）
 *   save_sites {sites:[{id?,name,cidrs}]} 儲存整份站點清單（僅 admin；總覽依設備 IP 落在哪個站點的網段來歸站點）
 *
 * 權限、驗證、預設值與異動紀錄都在 Ioc\SettingsService；這裡只負責登入、CSRF、解析內容與回應格式。
 * 需登入、角色為 admin 或 global-read，並帶 X-CSRF-Token 標頭。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

[$actor, $level] = ioc_begin(true);

// 站點清單最多 50 個站點、每個最長約 260 位元組（含中文名稱），32 KB 綽綽有餘。只有 save_sites 需要這麼大；
// 其他動作的內容都很小，維持原本的 4 KB 上限（內容超過 4 KB 又不是 save_sites 就擋下）
$raw = (string) file_get_contents('php://input', false, null, 0, 32769);
if (strlen($raw) > 32768) {
    ioc_fail(413, '內容太大');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    ioc_fail(400, '內容不是合法的 JSON');
}
if (strlen($raw) > 4096 && ($in['action'] ?? '') !== 'save_sites') {
    ioc_fail(413, '內容太大');
}

try {
    $svc = ioc_settings();
    switch ((string) ($in['action'] ?? '')) {
        case 'get':
            ioc_json(['risk' => $svc->riskSettings($level), 'network' => $svc->networkSettings($level), 'sites' => $svc->siteSettings($level)]);
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
        case 'save_sites':
            // ioc_pick 只收純量；站點清單是陣列，由 SettingsService 嚴格檢查形狀、長度與內容
            $r = $svc->saveSites(['sites' => $in['sites'] ?? null], $actor, $level);
            ioc_overview_cache_forget();          // 總覽的站點數、站點分佈、排行都是快取裡算好的
            ioc_json(['sites' => $r]);
        default:
            ioc_fail(400, '不認得的動作');
    }
} catch (\Throwable $e) {
    ioc_handle($e);
}
