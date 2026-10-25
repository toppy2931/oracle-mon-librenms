<?php
/** IOC 稽核與證據 JSON API；檢視者只能呼叫 summary。 */
$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

[$actor, $level] = ioc_begin(true);
$raw = (string) file_get_contents('php://input', false, null, 0, 2097153);
if (strlen($raw) > 2097152) { ioc_fail(413, '請求內容超過 2 MiB'); }
$in = json_decode($raw, true);
if (!is_array($in)) { ioc_fail(400, '內容不是合法的 JSON'); }
$action = $in['action'] ?? null;
if (!is_string($action)) { ioc_fail(400, '不認得的動作'); }

try {
    if ($action !== 'summary') {
        \Ioc\Access::requireHandler($level, '只有管理者可以管理稽核與證據');
    }
    $caseSource = static function (int $caseId) use ($level): array {
        $case = ioc_service()->get($caseId, $level)['case'];
        return ['case' => $case, 'evidence' => ioc_evidence($case, false)];
    };
    $backupSource = static function (int $deviceId, int $versionId) use ($level): array {
        $svc = new \Ioc\ConfigBackupService(new \Ioc\ConfigBackupGateway(), ioc_backup_devices($level));
        return $svc->versionMetadata($deviceId, $versionId, $level);
    };
    $svc = new \Ioc\AuditEvidenceService(\Illuminate\Support\Facades\DB::connection()->getPdo(),
        new \Ioc\AuditSnapshotStore(), $caseSource, $backupSource);
    $automation = new \Ioc\AuditAutomationService(\Illuminate\Support\Facades\DB::connection()->getPdo(),
        new \Ioc\AuditSnapshotStore());
    $id = static function (string $key) use ($in): int {
        $v = $in[$key] ?? null;
        if (!is_int($v) || $v < 1 || $v > 2147483647) {
            throw new \Ioc\ValidationError(['識別碼不正確：' . $key]);
        }
        return $v;
    };
    $str = static function (string $key) use ($in): string {
        if (!is_string($in[$key] ?? null)) { throw new \Ioc\ValidationError(['欄位格式不正確：' . $key]); }
        return $in[$key];
    };
    switch ($action) {
        case 'summary': ioc_json(['summary' => $svc->summary($level)]);
        case 'automation_state':
            $rows = \Illuminate\Support\Facades\DB::table('devices')->where('disabled', 0)->where('ignore', 0)
                ->get(['device_id', 'hostname', 'sysName', 'display', 'type', 'os', 'overwrite_ip', 'ip']);
            $assets = [];
            foreach ($rows as $device) {
                $asset = \Ioc\AuditAssetDiscovery::fromLibreNms($device);
                if ($asset !== null) { $assets[] = $asset; }
            }
            $automation->syncAssets($assets);
            ioc_json(['automation' => $automation->state($level)]);
        case 'automation_activate':
            if (!is_bool($in['enabled'] ?? null)) { ioc_fail(400, '啟用狀態不正確'); }
            $automation->activate($str('pack_key'), $in['enabled'], $actor, $level);
            ioc_json(['ok' => true]);
        case 'automation_map':
            $automation->map($id('cycle_id'), $id('control_id'), $str('rule_id'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'automation_map_case':
            $automation->mapCase($id('cycle_id'), $id('control_id'), $str('category'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'automation_unmap':
            $automation->deactivateMapping($id('mapping_id'), $str('kind'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'automation_run':
            $automation->requestRun($id('asset_id'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'automation_review':
            $ids = $in['proposal_ids'] ?? null;
            if (!is_array($ids)) { ioc_fail(400, '建議編號不正確'); }
            $status = $in['status'] ?? null;
            if ($status !== null && !is_string($status)) { ioc_fail(400, '判定狀態不正確'); }
            $automation->review($ids, $str('decision'), $status, $str('reason'), $actor, $level);
            ioc_json(['ok' => true]);
        case 'detail':
            $cycleId = $in['cycle_id'] ?? 0;
            if (!is_int($cycleId) || $cycleId < 0) { ioc_fail(400, '週期識別碼不正確'); }
            ioc_json(['detail' => $svc->detail($cycleId, $level)]);
        case 'preview': ioc_json(['preview' => $svc->preview($str('csv'), $level)]);
        case 'import': ioc_json(['catalog_id' => $svc->import($str('csv'), $str('label'), $str('expected_digest'), $actor, $level)]);
        case 'activate_catalog': $svc->activateCatalog($id('catalog_id'), $actor, $level); ioc_json(['ok' => true]);
        case 'create_cycle': ioc_json(['cycle_id' => $svc->createCycle($str('title'), $str('starts_on'), $str('ends_on'), $actor, $level)]);
        case 'assess':
            $evidenceId = $in['evidence_id'] ?? null;
            if ($evidenceId !== null && (!is_int($evidenceId) || $evidenceId < 1)) { ioc_fail(400, '證據識別碼不正確'); }
            $svc->assess($id('cycle_id'), $id('control_id'), $str('status'), $str('reason'), $evidenceId, $actor, $level);
            ioc_json(['ok' => true]);
        case 'capture_case': ioc_json(['evidence_id' => $svc->captureCase($id('cycle_id'), $id('control_id'), $id('case_id'), $actor, $level)]);
        case 'link_backup': ioc_json(['evidence_id' => $svc->linkBackup($id('cycle_id'), $id('control_id'), $id('device_id'), $id('version_id'), $actor, $level)]);
        case 'link_external': ioc_json(['evidence_id' => $svc->linkExternal($id('cycle_id'), $id('control_id'), $str('reference'), $actor, $level)]);
        case 'history': ioc_json(['history' => $svc->history($id('cycle_id'), $id('control_id'), $level)]);
        case 'save_remediation':
            $remediationId = $in['remediation_id'] ?? 0;
            $closingEvidence = $in['closing_evidence_id'] ?? null;
            if (!is_int($remediationId) || $remediationId < 0 || ($closingEvidence !== null && (!is_int($closingEvidence) || $closingEvidence < 1))) {
                ioc_fail(400, '改善或結案證據識別碼不正確');
            }
            ioc_json(['remediation_id' => $svc->saveRemediation($remediationId, $id('cycle_id'), $id('control_id'), $str('owner'), $str('due_on'),
                $str('status'), $str('note'), $closingEvidence, $actor, $level)]);
        default: ioc_fail(400, '不認得的動作');
    }
} catch (\Ioc\IocError $e) {
    if ($e instanceof \Ioc\AccessDenied || $e instanceof \Ioc\ValidationError || $e instanceof \Ioc\NotFound || $e instanceof \Ioc\InvalidTransition) {
        ioc_handle($e);
    }
    // 此服務只產生不含查詢原文、憑證及設備組態的固定中文錯誤訊息。
    ioc_fail(503, $e->getMessage());
} catch (\Throwable $e) { ioc_handle($e); }
