<?php
declare(strict_types=1);

namespace Ioc;

/** Read-only technical checks and human-reviewed audit proposals. */
final class AuditAutomationService
{
    public const PACKS = [
        'fortios_network_baseline' => [
            'version' => 1, 'kind' => 'network',
            'rules' => [
                ['id' => 'fortios.interface_no_plaintext_management', 'title' => '管理介面未開放 HTTP／Telnet', 'description' => '僅在完整 FortiOS 組態中出現明確設定時判定。'],
                ['id' => 'fortios.admin_no_explicit_wildcard_trusthost', 'title' => '管理員未明確設定任意來源', 'description' => '未出現設定時為待查，不將省略的預設值判為符合。'],
            ],
        ],
        'linux_ssh_baseline' => [
            'version' => 1, 'kind' => 'linux',
            'rules' => [
                ['id' => 'non_root_login', 'title' => '稽核帳號不是 root', 'description' => '使用專用 SSH 身分確認。'],
                ['id' => 'auditd_active', 'title' => 'auditd 正在運行', 'description' => '以唯讀服務狀態檢查。'],
                ['id' => 'time_synchronized', 'title' => '系統時間已同步', 'description' => '以唯讀時間狀態檢查。'],
                ['id' => 'sshd_config_permissions', 'title' => 'SSH 設定檔權限受限', 'description' => '僅使用白名單 sudo stat。'],
            ],
        ],
    ];

    public function __construct(private \PDO $db, private AuditSnapshotStore $store)
    {
    }

    /** @param array<int,array{id:int,name:string,address:string,kind:string}> $devices */
    public function syncAssets(array $devices): void
    {
        foreach ($devices as $device) {
            $id = (int) ($device['id'] ?? 0);
            $kind = (string) ($device['kind'] ?? '');
            $address = trim((string) ($device['address'] ?? ''));
            if ($id < 1 || !in_array($kind, ['network', 'linux'], true) || $address === '' || strlen($address) > 255) {
                continue;
            }
            $name = mb_substr((string) ($device['name'] ?? (string) $id), 0, 200);
            $confirmed = (bool) ($device['os_confirmed'] ?? true);
            $sql = <<<'SQL'
INSERT INTO ioc_audit_assets (id,name,address,kind,os_confirmed,readiness,reason,last_seen_at)
VALUES (?,?,?,?,?,'pending',?,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
name=VALUES(name),
readiness=IF(address<>VALUES(address) OR kind<>VALUES(kind) OR VALUES(os_confirmed)=0,'pending',readiness),
reason=IF(VALUES(os_confirmed)=0,'作業系統尚未確認',IF(address<>VALUES(address) OR kind<>VALUES(kind),'設備位址或類型已變更，須重新確認',reason)),
address=VALUES(address),kind=VALUES(kind),os_confirmed=VALUES(os_confirmed),last_seen_at=UTC_TIMESTAMP()
SQL;
            $this->exec($sql, [$id, $name, $address, $kind, $confirmed ? 1 : 0,
                $confirmed ? ($kind === 'linux' ? '尚未納管專用 SSH 帳號或等待首次檢查' : '等待首次檢查') : '作業系統尚未確認']);
        }
    }

    /** @return array<string,mixed> */
    public function state(?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以檢視自動化明細');
        $settings = [];
        foreach ($this->all('SELECT pack_key,version,enabled FROM ioc_audit_packs') as $row) {
            $settings[$row['pack_key']] = $row;
        }
        $packs = [];
        foreach (self::PACKS as $key => $definition) {
            $packs[] = ['key' => $key, 'version' => $definition['version'],
                'enabled' => (int) ($settings[$key]['enabled'] ?? 0) === 1,
                'rules' => $definition['rules']];
        }
        $assets = $this->all('SELECT id,name,kind AS type,os_confirmed,readiness,reason FROM ioc_audit_assets WHERE last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) ORDER BY kind,name LIMIT 2000');
        $cycle = $this->currentCycle();
        $mappings = $cycle ? $this->all('SELECT m.id,m.rule_id,m.pack_version,m.control_id,c.code AS control_code FROM ioc_audit_mappings m JOIN ioc_audit_controls c ON c.id=m.control_id WHERE m.cycle_id=? AND m.active=1 ORDER BY m.id', [(int) $cycle['id']]) : [];
        $caseMappings = $cycle ? $this->all('SELECT m.id,m.category,m.control_id,c.code AS control_code FROM ioc_audit_case_mappings m JOIN ioc_audit_controls c ON c.id=m.control_id WHERE m.cycle_id=? AND m.active=1 ORDER BY m.id', [(int) $cycle['id']]) : [];
        $caseCategories = [];
        foreach (CaseCatalog::CATEGORIES as $key => $value) {
            $caseCategories[] = ['key' => $key, 'label' => (string) ($value['label'] ?? $key)];
        }
        $proposals = $cycle ? $this->all("SELECT p.id,p.control_id,c.code AS control_code,p.status,p.reason,p.evidence_id,p.created_at FROM ioc_audit_proposals p JOIN ioc_audit_controls c ON c.id=p.control_id WHERE p.cycle_id=? AND p.review_state='pending' ORDER BY p.id DESC LIMIT 100", [(int) $cycle['id']]) : [];
        $runs = $this->all('SELECT r.id,a.name AS asset_name,r.source,r.status,r.error_code,r.started_at,r.finished_at FROM ioc_audit_runs r LEFT JOIN ioc_audit_assets a ON a.id=r.asset_id ORDER BY r.id DESC LIMIT 50');
        foreach ($runs as &$run) {
            $run['summary'] = $run['error_code'] !== '' ? self::errorLabel((string) $run['error_code']) : self::statusLabel((string) $run['status']);
            unset($run['error_code']);
        }
        unset($run);
        $ready = count(array_filter($assets, static fn (array $a): bool => $a['readiness'] === 'ready'));
        $errors = count(array_filter($assets, static fn (array $a): bool => $a['readiness'] === 'error'));
        $caseErrors = $cycle ? (int) $this->value("SELECT COUNT(*) FROM ioc_audit_case_attempts WHERE cycle_id=? AND status='error'", [(int) $cycle['id']]) : 0;
        $overdue = $cycle ? (int) $this->value("SELECT COUNT(*) FROM ioc_audit_remediation WHERE cycle_id=? AND status<>'done' AND due_on<CURRENT_DATE()", [(int) $cycle['id']]) : 0;
        $stale = (int) $this->value("SELECT COUNT(*) FROM ioc_audit_auto_evidence e JOIN ioc_audit_runs r ON r.id=e.run_id WHERE e.captured_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL IF(r.source='linux',2,8) DAY)");
        $proposalCount = $cycle ? (int) $this->value("SELECT COUNT(*) FROM ioc_audit_proposals WHERE cycle_id=? AND review_state='pending'", [(int) $cycle['id']]) : 0;
        $remediationHints = $cycle ? $this->all("SELECT r.id,c.code AS control_code,r.due_on
            FROM ioc_audit_remediation r JOIN ioc_audit_controls c ON c.id=r.control_id
            JOIN ioc_audit_proposals p ON p.cycle_id=r.cycle_id AND p.control_id=r.control_id
            WHERE r.cycle_id=? AND r.status<>'done' AND p.status='compliant'
              AND p.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)
              AND p.id=(SELECT MAX(newer.id) FROM ioc_audit_proposals newer
                WHERE newer.cycle_id=r.cycle_id AND newer.control_id=r.control_id)
            ORDER BY r.due_on,r.id LIMIT 50", [(int) $cycle['id']]) : [];
        return ['counts' => ['total' => count($assets), 'ready' => $ready, 'pending' => count($assets) - $ready - $errors,
            'source_errors' => $errors + $caseErrors, 'pending_proposals' => $proposalCount, 'stale' => $stale, 'overdue' => $overdue],
            'assets' => $assets, 'packs' => $packs, 'mappings' => $mappings,
            'case_categories' => $caseCategories, 'case_mappings' => $caseMappings,
            'case_attempts' => $cycle ? $this->all('SELECT case_id,control_id,status,error_code,attempted_at FROM ioc_audit_case_attempts WHERE cycle_id=? ORDER BY attempted_at DESC LIMIT 50', [(int) $cycle['id']]) : [],
            'runs' => $runs, 'proposals' => $proposals, 'remediation_hints' => $remediationHints];
    }

    public function activate(string $packKey, bool $enabled, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以啟用檢查包');
        if (!isset(self::PACKS[$packKey])) { throw new ValidationError(['檢查包不正確']); }
        $this->exec('INSERT INTO ioc_audit_packs (pack_key,version,enabled,updated_by,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE version=VALUES(version),enabled=VALUES(enabled),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',
            [$packKey, self::PACKS[$packKey]['version'], $enabled ? 1 : 0, $actor]);
        $this->event($actor, $enabled ? 'pack_enable' : 'pack_disable', 'pack', 0);
    }

    public function map(int $cycleId, int $controlId, string $ruleId, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以設定控制項對應');
        $packKey = self::packForRule($ruleId);
        if ($packKey === null) { throw new ValidationError(['檢查項目不正確']); }
        $cycle = $this->currentCycle();
        if (!$cycle || (int) $cycle['id'] !== $cycleId) { throw new InvalidTransition('只能對應目前評估週期'); }
        if ((int) $this->value('SELECT COUNT(*) FROM ioc_audit_controls WHERE id=? AND catalog_id=?', [$controlId, (int) $cycle['catalog_id']]) !== 1) {
            throw new ValidationError(['控制項不屬於目前目錄']);
        }
        $prior = $this->one('SELECT id,active,pack_version FROM ioc_audit_mappings WHERE cycle_id=? AND control_id=? AND pack_key=? AND rule_id=?',
            [$cycleId, $controlId, $packKey, $ruleId]);
        if ($prior && (int) $prior['active'] === 1 && (int) $prior['pack_version'] === self::PACKS[$packKey]['version']) { return; }
        $this->exec('INSERT INTO ioc_audit_mappings (cycle_id,control_id,pack_key,pack_version,rule_id,active,created_by,created_at) VALUES (?,?,?,?,?,1,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE active=1,pack_version=VALUES(pack_version)',
            [$cycleId, $controlId, $packKey, self::PACKS[$packKey]['version'], $ruleId, $actor]);
        $mappingId = (int) ($prior['id'] ?? $this->db->lastInsertId());
        $this->mappingEvent('rule', $mappingId, $cycleId, $controlId, $ruleId, self::PACKS[$packKey]['version'], 'enable', $actor);
        $this->event($actor, 'mapping_save', 'control', $controlId);
        $this->propose($cycleId, $controlId);
    }

    public function mapCase(int $cycleId, int $controlId, string $category, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以設定事件案擷取');
        if (!isset(CaseCatalog::CATEGORIES[$category])) { throw new ValidationError(['事件類型不正確']); }
        $cycle = $this->currentCycle();
        if (!$cycle || (int) $cycle['id'] !== $cycleId) { throw new InvalidTransition('只能對應目前評估週期'); }
        if ((int) $this->value('SELECT COUNT(*) FROM ioc_audit_controls WHERE id=? AND catalog_id=?', [$controlId, (int) $cycle['catalog_id']]) !== 1) {
            throw new ValidationError(['控制項不屬於目前目錄']);
        }
        $prior = $this->one('SELECT id,active FROM ioc_audit_case_mappings WHERE cycle_id=? AND control_id=? AND category=?',
            [$cycleId, $controlId, $category]);
        if ($prior && (int) $prior['active'] === 1) { return; }
        $this->exec('INSERT INTO ioc_audit_case_mappings (cycle_id,control_id,category,active,created_by,created_at) VALUES (?,?,?,1,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE active=1',
            [$cycleId, $controlId, $category, $actor]);
        $mappingId = (int) ($prior['id'] ?? $this->db->lastInsertId());
        $this->mappingEvent('case', $mappingId, $cycleId, $controlId, $category, 1, 'enable', $actor);
        $this->event($actor, 'case_mapping_save', 'control', $controlId);
    }

    public function deactivateMapping(int $mappingId, string $kind, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以停用對應');
        if (!in_array($kind, ['rule', 'case'], true)) { throw new ValidationError(['對應種類不正確']); }
        $table = $kind === 'case' ? 'ioc_audit_case_mappings' : 'ioc_audit_mappings';
        $row = $this->one("SELECT * FROM $table WHERE id=? AND active=1", [$mappingId]);
        $cycle = $this->currentCycle();
        if (!$row || !$cycle || (int) $row['cycle_id'] !== (int) $cycle['id']) {
            throw new InvalidTransition('只能停用目前週期的有效對應');
        }
        $this->exec("UPDATE $table SET active=0 WHERE id=?", [$mappingId]);
        $sourceKey = $kind === 'case' ? $row['category'] : $row['rule_id'];
        $version = $kind === 'case' ? 1 : (int) $row['pack_version'];
        $this->mappingEvent($kind, $mappingId, (int) $cycle['id'], (int) $row['control_id'], $sourceKey, $version, 'disable', $actor);
        $this->event($actor, 'mapping_disable', 'control', (int) $row['control_id']);
        if ($kind === 'rule') { $this->propose((int) $cycle['id'], (int) $row['control_id']); }
    }

    public function requestRun(int $assetId, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以要求檢查');
        if ((int) $this->value('SELECT COUNT(*) FROM ioc_audit_assets WHERE id=? AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)', [$assetId]) !== 1) {
            throw new ValidationError(['資產不存在']);
        }
        if ((int) $this->value('SELECT os_confirmed FROM ioc_audit_assets WHERE id=?', [$assetId]) !== 1) {
            throw new ValidationError(['作業系統尚未確認']);
        }
        $root = '/var/lib/oracle-mon/ioc-audit-requests';
        if (!is_dir($root) || is_link($root) || !is_writable($root)) {
            throw new IocError('自動檢查排程尚未完成部署');
        }
        $marker = $root . '/' . $assetId . '.request';
        if (is_link($marker) || (file_exists($marker) && !is_file($marker))) {
            throw new IocError('檢查佇列不可用');
        }
        if (@file_put_contents($marker, '', LOCK_EX) === false || !@chmod($marker, 0644)) {
            throw new IocError('無法排入檢查');
        }
        touch($marker);
        $this->exec('UPDATE ioc_audit_assets SET requested_at=UTC_TIMESTAMP() WHERE id=?', [$assetId]);
        $this->event($actor, 'scan_request', 'asset', $assetId);
    }

    /** @param array<string,mixed> $result */
    public function recordScan(int $assetId, array $result, ?string $revision = null): ?int
    {
        $asset = $this->one('SELECT * FROM ioc_audit_assets WHERE id=?', [$assetId]);
        if (!$asset) { throw new ValidationError(['未知資產']); }
        if ($asset['kind'] === 'linux' && (int) $asset['os_confirmed'] !== 1) {
            throw new ValidationError(['作業系統尚未確認']);
        }
        $pack = (string) ($result['rule_pack'] ?? '');
        if (!isset(self::PACKS[$pack]) || self::PACKS[$pack]['kind'] !== $asset['kind']
            || (int) ($result['rule_pack_version'] ?? 0) !== self::PACKS[$pack]['version']
            || (int) ($result[$asset['kind'] === 'network' ? 'device_id' : 'host_id'] ?? 0) !== $assetId) {
            throw new ValidationError(['檢查結果與資產或規則版本不符']);
        }
        $allowed = array_column(self::PACKS[$pack]['rules'], 'id');
        $checks = $result['checks'] ?? null;
        if (!is_array($checks) || count($checks) > count($allowed)) { throw new ValidationError(['檢查結果格式不正確']); }
        $seen = [];
        foreach ($checks as $check) {
            if (!is_array($check) || !in_array($check['id'] ?? null, $allowed, true)
                || !in_array($check['status'] ?? null, ['pass', 'fail', 'unknown', 'error'], true)
                || isset($seen[$check['id']])) { throw new ValidationError(['檢查項目或狀態不正確']); }
            $seen[$check['id']] = true;
        }
        $status = (string) ($result['status'] ?? 'error');
        if (!in_array($status, ['pass', 'fail', 'unknown', 'unsupported', 'error'], true)) {
            throw new ValidationError(['檢查狀態不正確']);
        }
        if (($result['source'] ?? null) !== ($asset['kind'] === 'network' ? 'config_backup' : 'linux_ssh')
            || (in_array($status, ['pass', 'fail'], true) && count($checks) !== count($allowed))
            || ($status === 'unknown' && $checks !== [] && count($checks) !== count($allowed))
            || ($status === 'pass' && count(array_filter($checks, static fn (array $c): bool => $c['status'] === 'pass')) !== count($allowed))
            || ($status === 'fail' && !in_array('fail', array_column($checks, 'status'), true))) {
            throw new ValidationError(['檢查狀態與結果不一致']);
        }
        $code = (string) ($result['error_code'] ?? '');
        if (!preg_match('/\A[a-z_]{0,48}\z/D', $code)) { $code = 'scan_error'; }
        $digest = (string) ($result['digest'] ?? '');
        if ($asset['kind'] === 'network') {
            $version = (int) ($result['version_id'] ?? 0);
            if ($status !== 'error' && $status !== 'unsupported' && ($version < 1 || !preg_match('/\A[a-f0-9]{64}\z/D', $digest))) {
                throw new ValidationError(['備份版本雜湊不正確']);
            }
            $revision ??= in_array($status, ['error', 'unsupported'], true)
                ? gmdate('Y-m-d-H') . ':' . $version . ':' . $code
                : $version . ':' . $digest;
        } else {
            $revision ??= gmdate('Y-m-d') . ($status === 'error' ? ':' . $code : '');
        }
        if (strlen($revision) > 128) { throw new ValidationError(['來源版本過長']); }
        $dedupe = hash('sha256', implode('|', [$assetId, $pack, self::PACKS[$pack]['version'], $revision]));
        $existing = $this->one('SELECT id,status FROM ioc_audit_runs WHERE dedupe_key=?', [$dedupe]);
        if ($existing) {
            $latest = $this->one('SELECT id,status FROM ioc_audit_runs WHERE asset_id=? AND pack_key=? ORDER BY id DESC LIMIT 1', [$assetId, $pack]);
            if (!$latest || (int) $latest['id'] === (int) $existing['id'] || $latest['status'] === $status) {
                $this->exec('UPDATE ioc_audit_assets SET requested_at=NULL WHERE id=?', [$assetId]);
                return null;
            }
            // A source recovered after a later failure on the same version.
            // Keep the original idempotent run and record the recovery separately.
            $revision = substr($revision, 0, 105) . ':recovery:' . gmdate('YmdHis');
            $dedupe = hash('sha256', implode('|', [$assetId, $pack, self::PACKS[$pack]['version'], $revision]));
            if ($this->one('SELECT id FROM ioc_audit_runs WHERE dedupe_key=?', [$dedupe])) { return null; }
        }
        $this->db->beginTransaction();
        $snapshot = null;
        try {
            $this->exec('INSERT INTO ioc_audit_runs (asset_id,source,source_revision,pack_key,pack_version,dedupe_key,status,error_code,started_at,finished_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
                [$assetId, $asset['kind'] === 'network' ? 'network' : 'linux', $revision, $pack, self::PACKS[$pack]['version'], $dedupe, $status, $code]);
            $runId = (int) $this->db->lastInsertId();
            $readiness = $status === 'unsupported' ? 'unsupported' : (
                in_array($code, ['not_enrolled', 'not_enabled', 'missing_file', 'unsafe_permissions', 'host_key_error', 'authentication_failed', 'inventory_changed'], true)
                    ? 'pending' : ($status === 'error' ? 'error' : 'ready'));
            $this->exec('UPDATE ioc_audit_assets SET readiness=?,reason=?,requested_at=NULL WHERE id=?',
                [$readiness, $readiness === 'ready' ? '' : self::errorLabel($code !== '' ? $code : $status), $assetId]);
            if (!in_array($status, ['error', 'unsupported'], true) && $checks !== []) {
                if ($asset['kind'] === 'linux') {
                    $snapshot = $this->store->put(json_encode(['schema' => 1, 'asset_id' => $assetId, 'pack' => $pack,
                        'version' => self::PACKS[$pack]['version'], 'checks' => $checks], JSON_THROW_ON_ERROR));
                    $sha = $snapshot['sha256']; $bytes = $snapshot['bytes'];
                } else {
                    $sha = $digest; $bytes = max(0, min(2 * 1024 * 1024, (int) ($result['bytes'] ?? 0)));
                }
                $sourceRef = $asset['kind'] === 'network' ? 'device:' . $assetId . ':version:' . (int) $result['version_id'] : 'device:' . $assetId . ':scan:' . $revision;
                $this->exec('INSERT INTO ioc_audit_auto_evidence (run_id,source_ref,snapshot_name,sha256,bytes,item_count,captured_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',
                    [$runId, $sourceRef, $snapshot['name'] ?? null, $sha, $bytes, count($checks)]);
                $evidenceId = (int) $this->db->lastInsertId();
                foreach ($checks as $check) {
                    $this->exec('INSERT INTO ioc_audit_results (run_id,rule_id,status,evidence_id) VALUES (?,?,?,?)',
                        [$runId, $check['id'], $check['status'], $evidenceId]);
                }
            }
            $this->event('automation', 'scan_record', 'asset', $assetId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($snapshot) { $this->store->discard($snapshot['name']); }
            throw $e;
        }
        $cycle = $this->currentCycle();
        if ($cycle) {
            foreach ($this->all('SELECT DISTINCT control_id FROM ioc_audit_mappings WHERE cycle_id=? AND pack_key=? AND active=1', [(int) $cycle['id'], $pack]) as $row) {
                $this->propose((int) $cycle['id'], (int) $row['control_id']);
            }
        }
        return $runId;
    }

    /** @param int[] $ids */
    public function review(array $ids, string $decision, ?string $status, string $reason, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以核定建議');
        if (!$ids || count($ids) > 50 || count($ids) !== count(array_unique($ids)) || !in_array($decision, ['approve', 'reject', 'override'], true)) {
            throw new ValidationError(['審核要求不正確']);
        }
        $reason = trim($reason);
        if (strlen($reason) > 2000 || (($decision === 'reject' || $decision === 'override') && $reason === '')) {
            throw new ValidationError(['駁回或覆寫須填寫理由']);
        }
        $cycle = $this->currentCycle();
        if (!$cycle) { throw new InvalidTransition('尚未建立目前評估週期'); }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) { throw new ValidationError(['建議編號不正確']); }
            $candidate = $this->one('SELECT control_id FROM ioc_audit_proposals WHERE id=? AND cycle_id=?', [$id, (int) $cycle['id']]);
            if (!$candidate) { throw new InvalidTransition('建議不屬於目前週期'); }
            $this->propose((int) $cycle['id'], (int) $candidate['control_id']);
        }
        $this->db->beginTransaction();
        $createdSnapshots = [];
        try {
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1) { throw new ValidationError(['建議編號不正確']); }
                $p = $this->one("SELECT * FROM ioc_audit_proposals WHERE id=? AND cycle_id=? AND review_state='pending' FOR UPDATE", [$id, (int) $cycle['id']]);
                if (!$p) { throw new InvalidTransition('建議已處理或不屬於目前週期'); }
                if ((int) $this->value('SELECT MAX(id) FROM ioc_audit_proposals WHERE cycle_id=? AND control_id=?', [(int) $cycle['id'], (int) $p['control_id']]) !== $id) {
                    throw new InvalidTransition('建議已有較新的檢查結果，請重新整理');
                }
                $target = $decision === 'approve' ? (string) $p['status'] : (string) $status;
                if ($decision === 'approve' && !in_array($target, ['compliant', 'gap'], true)) {
                    throw new ValidationError(['待查建議不可直接核定']);
                }
                if ($decision === 'override' && !in_array($target, ['compliant', 'gap', 'not_applicable', 'unreviewed'], true)) {
                    throw new ValidationError(['覆寫判定不正確']);
                }
                if ($target === 'compliant' && (!$p['evidence_id'] || $p['status'] !== 'compliant'
                    || strtotime((string) $p['created_at'] . ' UTC') < time() - 172800)) {
                    throw new ValidationError(['符合判定需要完整且有效的證據']);
                }
                if ($target === 'not_applicable' && $reason === '') {
                    throw new ValidationError(['不適用須填寫理由']);
                }
                $this->exec('UPDATE ioc_audit_proposals SET review_state=?,reviewed_by=?,review_reason=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?',
                    [$decision === 'reject' ? 'rejected' : 'approved', $actor, $reason, $id]);
                if ($decision === 'reject') { $this->event($actor, 'proposal_reject', 'proposal', $id); continue; }
                $legacyEvidence = null;
                $items = $this->all('SELECT e.id,e.source_ref,e.sha256,e.bytes,e.captured_at,r.pack_key,r.pack_version,r.asset_id
                    FROM ioc_audit_proposal_evidence pe JOIN ioc_audit_auto_evidence e ON e.id=pe.evidence_id
                    JOIN ioc_audit_runs r ON r.id=e.run_id WHERE pe.proposal_id=? ORDER BY e.id', [$id]);
                if ($items) {
                    $manifest = json_encode(['schema' => 1, 'proposal_id' => $id, 'evidence' => $items], JSON_THROW_ON_ERROR);
                    $snapshot = $this->store->put($manifest);
                    $createdSnapshots[] = $snapshot['name'];
                    $this->exec('INSERT INTO ioc_audit_evidence (cycle_id,control_id,kind,source_ref,snapshot_name,sha256,bytes,item_count,captured_by,captured_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
                        [(int) $cycle['id'], (int) $p['control_id'], 'auto_snapshot', 'proposal:' . $id,
                            $snapshot['name'], $snapshot['sha256'], $snapshot['bytes'], count($items), 'automation']);
                    $legacyEvidence = (int) $this->db->lastInsertId();
                }
                $assessmentReason = $reason !== '' ? $reason : (string) $p['reason'];
                $this->exec('INSERT INTO ioc_audit_assessments (cycle_id,control_id,status,reason,evidence_id,assessed_by,assessed_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=VALUES(status),reason=VALUES(reason),evidence_id=VALUES(evidence_id),assessed_by=VALUES(assessed_by),assessed_at=VALUES(assessed_at)',
                    [(int) $cycle['id'], (int) $p['control_id'], $target, $assessmentReason, $legacyEvidence, $actor]);
                $this->exec('INSERT INTO ioc_audit_assessment_history (cycle_id,control_id,status,reason,evidence_id,actor,occurred_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',
                    [(int) $cycle['id'], (int) $p['control_id'], $target, $assessmentReason, $legacyEvidence, $actor]);
                $this->event($actor, 'proposal_review', 'proposal', $id);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            foreach ($createdSnapshots as $name) { $this->store->discard($name); }
            throw $e;
        }
    }

    public function refreshCurrentProposals(): void
    {
        $cycle = $this->currentCycle();
        if (!$cycle) { return; }
        foreach ($this->all('SELECT DISTINCT control_id FROM ioc_audit_mappings WHERE cycle_id=? AND active=1 LIMIT 2000', [(int) $cycle['id']]) as $row) {
            $this->propose((int) $cycle['id'], (int) $row['control_id']);
        }
    }

    private function propose(int $cycleId, int $controlId): void
    {
        $mappings = $this->all('SELECT pack_key,pack_version,rule_id FROM ioc_audit_mappings WHERE cycle_id=? AND control_id=? AND active=1', [$cycleId, $controlId]);
        if (!$mappings) {
            $this->exec("UPDATE ioc_audit_proposals SET review_state='superseded' WHERE cycle_id=? AND control_id=? AND review_state='pending'", [$cycleId, $controlId]);
            return;
        }
        $states = []; $evidence = null; $evidenceIds = []; $signatures = [];
        foreach ($mappings as $mapping) {
            $pack = self::PACKS[$mapping['pack_key']] ?? null;
            if (!$pack || (int) $mapping['pack_version'] !== $pack['version']
                || (int) $this->value('SELECT COALESCE(MAX(enabled),0) FROM ioc_audit_packs WHERE pack_key=?', [$mapping['pack_key']]) !== 1) {
                $states[] = 'unknown'; $signatures[] = $mapping['rule_id'] . ':disabled'; continue;
            }
            $assets = $this->all('SELECT id,readiness FROM ioc_audit_assets WHERE kind=? AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) ORDER BY id', [$pack['kind']]);
            if (!$assets) { $states[] = 'unknown'; $signatures[] = $mapping['rule_id'] . ':no-assets'; }
            foreach ($assets as $asset) {
                $prefix = $mapping['rule_id'] . ':' . $asset['id'] . ':';
                if ($asset['readiness'] !== 'ready') { $states[] = 'unknown'; $signatures[] = $prefix . $asset['readiness']; continue; }
                // 只看最新一次執行；來源錯誤不能沿用先前通過的結果。
                $latest = $this->one('SELECT id,status,finished_at FROM ioc_audit_runs WHERE asset_id=? AND pack_key=? ORDER BY id DESC LIMIT 1',
                    [(int) $asset['id'], $mapping['pack_key']]);
                $ttl = $pack['kind'] === 'linux' ? 172800 : 691200;
                if (!$latest || (int) $latest['id'] < 1 || strtotime($latest['finished_at'] . ' UTC') < time() - $ttl
                    || in_array($latest['status'], ['error', 'unsupported'], true)) {
                    $states[] = 'unknown'; $signatures[] = $prefix . ($latest['id'] ?? 'missing'); continue;
                }
                $row = $this->one('SELECT status,evidence_id FROM ioc_audit_results WHERE run_id=? AND rule_id=?',
                    [(int) $latest['id'], $mapping['rule_id']]);
                if (!$row) { $states[] = 'unknown'; $signatures[] = $prefix . $latest['id'] . ':missing-rule'; continue; }
                $states[] = $row['status'];
                $signatures[] = $prefix . $latest['id'] . ':' . $row['status'];
                $evidence = (int) $row['evidence_id'];
                $evidenceIds[$evidence] = true;
            }
        }
        $status = in_array('fail', $states, true) ? 'gap' : (in_array('unknown', $states, true) || !$states ? 'unknown' : 'compliant');
        $fingerprint = hash('sha256', $cycleId . '|' . $controlId . '|' . $status . '|' . implode(',', $signatures));
        if ($this->one('SELECT id FROM ioc_audit_proposals WHERE fingerprint=?', [$fingerprint])) { return; }
        $reason = $status === 'gap' ? '技術檢查發現缺口，請核對證據' : ($status === 'compliant' ? '適用資產的對應檢查均通過，待管理者核定' : '缺少新鮮或可用的檢查結果，請先處理來源');
        $this->db->beginTransaction();
        try {
            $this->exec('INSERT INTO ioc_audit_proposals (cycle_id,control_id,evidence_id,fingerprint,status,reason,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',
                [$cycleId, $controlId, $evidence, $fingerprint, $status, $reason]);
            $proposalId = (int) $this->db->lastInsertId();
            $this->exec("UPDATE ioc_audit_proposals SET review_state='superseded' WHERE cycle_id=? AND control_id=? AND id<>? AND review_state='pending'",
                [$cycleId, $controlId, $proposalId]);
            foreach (array_keys($evidenceIds) as $linkedId) {
                $this->exec('INSERT IGNORE INTO ioc_audit_auto_links (evidence_id,cycle_id,control_id) VALUES (?,?,?)', [$linkedId, $cycleId, $controlId]);
                $this->exec('INSERT INTO ioc_audit_proposal_evidence (proposal_id,evidence_id) VALUES (?,?)', [$proposalId, $linkedId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    private static function packForRule(string $ruleId): ?string
    {
        foreach (self::PACKS as $key => $pack) {
            if (in_array($ruleId, array_column($pack['rules'], 'id'), true)) { return $key; }
        }
        return null;
    }

    private function currentCycle(): ?array
    {
        return $this->one('SELECT y.id,y.catalog_id FROM ioc_audit_cycles y JOIN ioc_audit_catalogs c ON c.id=y.catalog_id AND c.active=1 WHERE y.is_current=1 ORDER BY y.id DESC LIMIT 1');
    }

    private static function statusLabel(string $status): string
    {
        return ['pass' => '技術檢查通過', 'fail' => '技術檢查發現缺口', 'unknown' => '無法判定', 'error' => '來源故障', 'unsupported' => '尚未支援此設備'][ $status ] ?? '待查';
    }

    private static function errorLabel(string $code): string
    {
        return ['not_enrolled' => '尚未納管專用 SSH 帳號', 'not_enabled' => 'SSH 檢查尚未啟用',
            'host_key_error' => 'SSH 主機金鑰驗證失敗', 'authentication_failed' => 'SSH 認證失敗',
            'permission_denied' => '唯讀檢查權限不足', 'unsupported' => '未支援的設備格式',
            'not_found' => '找不到完整備份版本', 'timeout' => '檢查逾時',
            'invalid_structure' => '設定檔格式不完整或無法解析', 'integrity_error' => '備份雜湊驗證失敗'][ $code ] ?? '檢查來源暫時無法使用';
    }

    private function mappingEvent(string $kind, int $mappingId, int $cycleId, int $controlId,
        string $sourceKey, int $version, string $action, string $actor): void
    {
        $this->exec('INSERT INTO ioc_audit_mapping_history
            (mapping_kind,mapping_id,cycle_id,control_id,source_key,source_version,action,actor,occurred_at)
            VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
            [$kind, $mappingId, $cycleId, $controlId, $sourceKey, $version, $action, $actor]);
    }

    private function event(string $actor, string $action, string $target, int $id): void
    {
        $this->exec('INSERT INTO ioc_audit_events (occurred_at,actor,action,target_type,target_id) VALUES (UTC_TIMESTAMP(),?,?,?,?)', [$actor, $action, $target, $id]);
    }

    private function exec(string $sql, array $values = []): void
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($values);
    }

    private function all(string $sql, array $values = []): array
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($values);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function one(string $sql, array $values = []): ?array
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($values);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function value(string $sql, array $values = []): mixed
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($values);
        return $stmt->fetchColumn();
    }
}
