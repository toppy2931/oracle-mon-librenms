<?php
/** 受權限保護的組態版本下載端點；僅串流指定版本，不回傳 JSON 設定資料。 */
$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

[$actor, $level] = ioc_begin(true);
try {
    \Ioc\Access::requireHandler($level, '只有管理者可以下載設備組態備份');
    $raw = (string) file_get_contents('php://input', false, null, 0, 1025);
    if (strlen($raw) > 1024) {
        ioc_fail(413, '下載請求內容太大');
    }
    $in = json_decode($raw, true);
    if (!is_array($in) || !is_int($in['device_id'] ?? null) || !is_int($in['version_id'] ?? null)
        || $in['device_id'] < 1 || $in['version_id'] < 1) {
        ioc_fail(400, '請選擇有效的設備與備份版本');
    }
    $svc = new \Ioc\ConfigBackupService(new \Ioc\ConfigBackupGateway(), ioc_backup_devices($level));
    $download = $svc->downloadVersion($in['device_id'], $in['version_id'], $actor, $level);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $download['filename'] . '"');
    header('Content-Length: ' . strlen($download['content']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    echo $download['content'];
    exit;
} catch (\Ioc\IocError $e) {
    ioc_handle($e);
} catch (\Throwable $e) {
    ioc_fail(500, '備份版本下載失敗，請稍後再試');
}
