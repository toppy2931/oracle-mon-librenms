<?php
/** 僅管理者可下載已驗證雜湊的私有快照。 */
$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

[$actor, $level] = ioc_begin(true);
try {
    \Ioc\Access::requireHandler($level, '只有管理者可以下載稽核證據');
    $raw = (string) file_get_contents('php://input', false, null, 0, 1025);
    if (strlen($raw) > 1024) { ioc_fail(413, '下載請求內容太大'); }
    $in = json_decode($raw, true);
    if (!is_array($in) || !is_int($in['evidence_id'] ?? null) || $in['evidence_id'] < 1) {
        ioc_fail(400, '請指定有效證據');
    }
    $svc = new \Ioc\AuditEvidenceService(\Illuminate\Support\Facades\DB::connection()->getPdo(),
        new \Ioc\AuditSnapshotStore(), static fn (int $id): array => [], static fn (int $device, int $version): array => []);
    $download = $svc->download($in['evidence_id'], $actor, $level);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $download['filename'] . '"');
    header('Content-Length: ' . strlen($download['content']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    echo $download['content'];
    exit;
} catch (\Throwable $e) { ioc_handle($e); }
