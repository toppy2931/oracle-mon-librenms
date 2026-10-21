<?php
/**
 * ioc-identity.php — 身分與稽核 API（JSON）。POST /ioc-identity.php，內容 {"action": "...", ...}
 *
 *   accounts                                帳號與角色統計、雙因子覆蓋、最小權限、久未登入的帳號、帳號盤點狀態
 *   trail   {category,result,limit}         稽核軌跡（LibreNMS 登入、管理操作、事件案處理、設定異動）
 *   hosts   {demo}                          主機的遠端登入（SSH 與 Windows 登入，來自 Graylog）
 *   review_save {last_date,owner,period_days}  儲存帳號盤點紀錄
 *
 * **只有 admin 能用**：稽核軌跡會列出所有人的登入位址與失敗嘗試。第一道檢查在這裡（沒有權限就不去讀任何資料），
 * 函式庫的每個方法也會再檢查一次（Access::requireHandler）。權限、驗證與錯誤原因在 Ioc\IdentityService、
 * Ioc\AuditTrail、Ioc\HostLoginService、Ioc\SettingsService；這裡只負責登入、CSRF、解析內容與回應格式。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

[$actor, $level] = ioc_begin(true);
if (!\Ioc\Access::canHandle($level)) {
    ioc_fail(403, '只有管理者可以看身分與稽核');
}

$raw = (string) file_get_contents('php://input', false, null, 0, 4097);
if (strlen($raw) > 4096) {
    ioc_fail(413, '內容太大');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    ioc_fail(400, '內容不是合法的 JSON');
}

try {
    switch ((string) ($in['action'] ?? '')) {
        case 'accounts':
            ioc_json(['accounts' => ioc_identity_accounts($level)]);
            // no break（ioc_json 會結束）
        case 'trail':
            ioc_json(['trail' => ioc_audit_trail($in, $level)]);
        case 'hosts':
            ioc_json(['hosts' => ioc_host_logins(($in['demo'] ?? false) === true, $level)]);
        case 'review_save':
            $r = ioc_settings()->saveAccountReview(ioc_pick($in, ['last_date', 'owner', 'period_days']), $actor, $level);
            ioc_json(['review' => $r]);
        default:
            ioc_fail(400, '不認得的動作');
    }
} catch (\Throwable $e) {
    ioc_handle($e);
}
