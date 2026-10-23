<?php
/** 組態備份 API：只接受管理者；秘密只透過標準輸入交給受限背景工具。 */
$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

[$actor, $level] = ioc_begin(true);
try {
    \Ioc\Access::requireHandler($level, '只有管理者可以管理設備組態備份');
    $raw = (string) file_get_contents('php://input', false, null, 0, 262145);
    if (strlen($raw) > 262144) {
        ioc_fail(413, '內容太大，請減少本次設定的設備數量');
    }
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        ioc_fail(400, '內容不是合法的 JSON');
    }
    $action = $in['action'] ?? '';
    if (!is_string($action) || !in_array($action, ['get', 'save', 'test', 'run', 'diagnose', 'history', 'compare', 'host_key_scan', 'host_key_confirm'], true)) {
        ioc_fail(400, '不認得的動作');
    }
    $devices = ioc_backup_devices($level);
    $svc = new \Ioc\ConfigBackupService(new \Ioc\ConfigBackupGateway(), $devices);
    if ($action === 'get') {
        ioc_json(['backup' => $svc->status($level), 'devices' => $devices]);
    }
    if ($action === 'save') {
        if (!is_array($in['settings'] ?? null)) {
            ioc_fail(400, '請填寫設備與備份時間');
        }
        ioc_json(['backup' => $svc->save($in['settings'], $actor, $level), 'devices' => $devices]);
    }
    if (!is_int($in['device_id'] ?? null) || $in['device_id'] < 1) {
        ioc_fail(400, '請選擇有效的設備');
    }
    if ($action === 'history') {
        $offset = $in['offset'] ?? 0;
        if (!is_int($offset) || $offset < 0 || $offset > 2147483647) {
            ioc_fail(400, '分頁位置不正確');
        }
        ioc_json(['backup' => $svc->history($in['device_id'], $offset, $level), 'devices' => $devices]);
    }
    if ($action === 'compare') {
        foreach (['from_version_id', 'to_version_id'] as $field) {
            if (!is_int($in[$field] ?? null) || $in[$field] < 1 || $in[$field] > 2147483647) {
                ioc_fail(400, '請選擇有效的備份版本');
            }
        }
        ioc_json(['backup' => $svc->compare($in['device_id'], $in['from_version_id'], $in['to_version_id'], $level), 'devices' => $devices]);
    }
    if ($action === 'diagnose') {
        ioc_json(['backup' => $svc->diagnose($in['device_id'], $actor, $level), 'devices' => $devices]);
    }
    if ($action === 'host_key_scan') {
        ioc_json(['backup' => $svc->scanHostKey($in['device_id'], $actor, $level), 'devices' => $devices]);
    }
    if ($action === 'host_key_confirm') {
        if (!is_string($in['token'] ?? null) || !is_string($in['fingerprint'] ?? null)) {
            ioc_fail(400, '請確認 SSH 身分指紋');
        }
        ioc_json(['backup' => $svc->confirmHostKey($in['device_id'], $in['token'], $in['fingerprint'], $actor, $level), 'devices' => $devices]);
    }
    $data = $action === 'test' ? $svc->test($in['device_id'], $actor, $level) : $svc->run($in['device_id'], $actor, $level);
    ioc_json(['backup' => $data, 'devices' => $devices]);
} catch (\Ioc\IocError $e) {
    if ($e instanceof \Ioc\AccessDenied || $e instanceof \Ioc\ValidationError || $e instanceof \Ioc\NotFound) {
        ioc_handle($e);
    }
    // IocError 訊息由本地服務層產生，不帶外部工具回應或憑證。
    ioc_fail(503, $e->getMessage());
} catch (\Throwable $e) {
    // 此端點可能處理密碼，不將外部例外內容寫入紀錄。
    ioc_fail(500, '設備備份服務發生錯誤，請聯絡管理者');
}
