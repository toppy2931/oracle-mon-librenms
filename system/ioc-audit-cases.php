<?php
/** Captures only closed cases explicitly mapped by an administrator. */
declare(strict_types=1);
$librenms = getenv('LIBRENMS_DIR') ?: '/opt/librenms';
chdir($librenms);
require "$librenms/vendor/autoload.php";
$app = require "$librenms/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require "$librenms/includes/ioc/bootstrap.php";

$db = Illuminate\Support\Facades\DB::connection()->getPdo();
$sql = <<<'SQL'
SELECT x.id,x.closed_at,m.cycle_id,m.control_id
FROM ioc_cases x
JOIN ioc_audit_case_mappings m ON m.category=x.category AND m.active=1
JOIN ioc_audit_cycles y ON y.id=m.cycle_id AND y.is_current=1
JOIN ioc_audit_catalogs c ON c.id=y.catalog_id AND c.active=1
LEFT JOIN ioc_audit_case_attempts a ON a.case_id=x.id AND a.cycle_id=m.cycle_id
    AND a.control_id=m.control_id AND a.closed_at=x.closed_at
WHERE x.status='closed' AND x.closed_at IS NOT NULL
  AND (a.case_id IS NULL OR (a.status='error' AND a.attempted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)))
ORDER BY x.closed_at DESC,x.id DESC LIMIT 4
SQL;
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { exit(0); }

$caseSvc = new Ioc\CaseService(new Ioc\PdoCaseRepo($db));
$caseSource = static function (int $caseId) use ($caseSvc): array {
    $case = $caseSvc->get($caseId, Ioc\Access::HANDLER)['case'];
    $logs = Ioc\GraylogClient::fromConf('/etc/oracle-mon/graylog-reader.conf');
    return ['case' => $case, 'evidence' => (new Ioc\EvidenceService($logs))->build($case)];
};
$service = new Ioc\AuditEvidenceService($db, new Ioc\AuditSnapshotStore(), $caseSource,
    static fn (int $device, int $version): array => []);
$attempt = $db->prepare('INSERT INTO ioc_audit_case_attempts
    (case_id,cycle_id,control_id,closed_at,evidence_id,status,error_code,attempted_at)
    VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
    evidence_id=VALUES(evidence_id),status=VALUES(status),error_code=VALUES(error_code),attempted_at=VALUES(attempted_at)');
foreach ($rows as $row) {
    $caseId = (int) $row['id'];
    $evidenceId = null; $status = 'error'; $code = 'source_unavailable';
    try {
        $evidenceId = $service->captureCase((int) $row['cycle_id'], (int) $row['control_id'],
            $caseId, 'automation', Ioc\Access::HANDLER);
        $count = $db->prepare('SELECT item_count FROM ioc_audit_evidence WHERE id=?');
        $count->execute([$evidenceId]);
        $status = (int) $count->fetchColumn() > 0 ? 'captured' : 'empty';
        $code = '';
    } catch (Throwable $e) {
        error_log('IOC audit case source unavailable for case #' . $caseId);
    }
    $attempt->execute([$caseId, (int) $row['cycle_id'], (int) $row['control_id'],
        $row['closed_at'], $evidenceId, $status, $code]);
}
