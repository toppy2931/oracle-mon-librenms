<?php
declare(strict_types=1);

namespace Ioc;

/** 控制項、來源證據與改善事項的共用治理核心。所有寫入都先檢查目前啟用版本與週期。 */
final class AuditEvidenceService
{
    private \PDO $db;
    private AuditSnapshotStore $store;
    /** @var callable(int):array */
    private $caseSource;
    /** @var callable(int,int):array */
    private $backupSource;

    public function __construct(\PDO $db, AuditSnapshotStore $store, callable $caseSource, callable $backupSource)
    {
        $this->db = $db;
        $this->store = $store;
        $this->caseSource = $caseSource;
        $this->backupSource = $backupSource;
    }

    /** @return array<string,mixed> */
    public function preview(string $csv, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以匯入控制項');
        $rows = self::parseCsv($csv);
        $current = $this->one('SELECT id FROM ioc_audit_catalogs WHERE active=1 ORDER BY id DESC LIMIT 1');
        $prior = [];
        if ($current) {
            foreach ($this->all('SELECT code,domain,title,requirement,description FROM ioc_audit_controls WHERE catalog_id=?', [(int) $current['id']]) as $row) {
                $prior[$row['code']] = $row;
            }
        }
        $added = $changed = $unchanged = 0;
        $addedCodes = $changedCodes = [];
        foreach ($rows as $row) {
            if (!isset($prior[$row['code']])) {
                $added++;
                $addedCodes[] = $row['code'];
            } elseif (self::sameControl($prior[$row['code']], $row)) {
                $unchanged++;
            } else {
                $changed++;
                $changedCodes[] = $row['code'];
            }
            unset($prior[$row['code']]);
        }
        return ['count' => count($rows), 'added' => $added, 'changed' => $changed,
            'unchanged' => $unchanged, 'removed' => count($prior), 'added_codes' => $addedCodes,
            'changed_codes' => $changedCodes, 'removed_codes' => array_keys($prior), 'digest' => self::catalogDigest($rows)];
    }

    /** 匯入與啟用一筆交易完成；相同內容重匯會重用舊版本。 */
    public function import(string $csv, string $label, string $expectedDigest, string $actor, ?string $level): int
    {
        $this->admin($level, $actor);
        $rows = self::parseCsv($csv);
        $label = self::required($label, 100, '版本名稱');
        $digest = self::catalogDigest($rows);
        if (!hash_equals($digest, $expectedDigest)) {
            throw new ValidationError(['控制項目錄已變更，請重新預覽後啟用']);
        }
        return $this->transaction(function () use ($rows, $label, $actor, $digest): int {
            $existing = $this->one('SELECT id FROM ioc_audit_catalogs WHERE digest=?', [$digest]);
            if ($existing) {
                $id = (int) $existing['id'];
            } else {
                $this->exec('INSERT INTO ioc_audit_catalogs (version_label,digest,active,created_by,created_at) VALUES (?,?,0,?,UTC_TIMESTAMP())', [$label, $digest, $actor]);
                $id = (int) $this->db->lastInsertId();
                foreach ($rows as $row) {
                    $this->exec('INSERT INTO ioc_audit_controls (catalog_id,code,domain,title,requirement,description) VALUES (?,?,?,?,?,?)',
                        [$id, $row['code'], $row['domain'], $row['title'], $row['requirement'], $row['description']]);
                }
            }
            $this->event($actor, 'catalog_import', 'catalog', $id);
            $this->activate($id, $actor);
            return $id;
        });
    }

    public function activateCatalog(int $id, string $actor, ?string $level): void
    {
        $this->admin($level, $actor);
        $this->transaction(function () use ($id, $actor): void {
            $this->requireRow('SELECT id FROM ioc_audit_catalogs WHERE id=?', [$id]);
            $this->activate($id, $actor);
        });
    }

    private function activate(int $id, string $actor): void
    {
        $current = $this->one('SELECT id FROM ioc_audit_catalogs WHERE active=1 ORDER BY id DESC LIMIT 1 FOR UPDATE');
        if ((int) ($current['id'] ?? 0) === $id) {
            return;
        }
        $this->exec('UPDATE ioc_audit_catalogs SET active=0 WHERE active=1');
        $this->exec('UPDATE ioc_audit_catalogs SET active=1 WHERE id=?', [$id]);
        $this->exec('UPDATE ioc_audit_cycles SET is_current=0 WHERE is_current=1');
        $this->event($actor, 'catalog_activate', 'catalog', $id);
    }

    public function createCycle(string $title, string $start, string $end, string $actor, ?string $level): int
    {
        $this->admin($level, $actor);
        $title = self::required($title, 150, '評估週期名稱');
        self::date($start); self::date($end);
        if ($end < $start) {
            throw new ValidationError(['評估結束日不能早於開始日']);
        }
        return $this->transaction(function () use ($title, $start, $end, $actor): int {
            $catalog = $this->requireRow('SELECT id FROM ioc_audit_catalogs WHERE active=1 ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $this->exec('UPDATE ioc_audit_cycles SET is_current=0 WHERE is_current=1');
            $this->exec('INSERT INTO ioc_audit_cycles (catalog_id,title,starts_on,ends_on,is_current,created_by,created_at) VALUES (?,?,?,?,1,?,UTC_TIMESTAMP())',
                [(int) $catalog['id'], $title, $start, $end, $actor]);
            $id = (int) $this->db->lastInsertId();
            $this->event($actor, 'cycle_create', 'cycle', $id);
            return $id;
        });
    }

    /** 檢視者只收到不含控制內容、人員、來源與證據識別碼的統計。 */
    public function summary(?string $level): array
    {
        if (!Access::canView($level)) {
            throw new AccessDenied('沒有稽核摘要檢視權限');
        }
        $catalog = $this->one('SELECT id,version_label FROM ioc_audit_catalogs WHERE active=1 ORDER BY id DESC LIMIT 1');
        if (!$catalog) {
            return ['ready' => false, 'message' => '未建立評估基準', 'catalog' => null, 'cycle' => null, 'counts' => null, 'domains' => []];
        }
        $cycle = $this->one('SELECT id,title,starts_on,ends_on FROM ioc_audit_cycles WHERE catalog_id=? AND is_current=1 ORDER BY id DESC LIMIT 1', [(int) $catalog['id']]);
        if (!$cycle) {
            return ['ready' => false, 'message' => '尚未建立評估週期', 'catalog' => $catalog['version_label'], 'cycle' => null, 'counts' => null, 'domains' => []];
        }
        $rows = $this->all('SELECT c.domain,COALESCE(a.status,\'unreviewed\') AS status,COUNT(*) AS n FROM ioc_audit_controls c LEFT JOIN ioc_audit_assessments a ON a.control_id=c.id AND a.cycle_id=? WHERE c.catalog_id=? GROUP BY c.domain,COALESCE(a.status,\'unreviewed\')', [(int) $cycle['id'], (int) $catalog['id']]);
        $counts = ['total' => 0, 'unreviewed' => 0, 'compliant' => 0, 'gap' => 0, 'not_applicable' => 0, 'assessed' => 0];
        $domains = [];
        foreach ($rows as $row) {
            $n = (int) $row['n']; $status = $row['status']; $domain = $row['domain'];
            $counts['total'] += $n; $counts[$status] += $n;
            $domains[$domain] ??= ['domain' => $domain, 'total' => 0, 'unreviewed' => 0, 'compliant' => 0, 'gap' => 0, 'not_applicable' => 0];
            $domains[$domain]['total'] += $n; $domains[$domain][$status] += $n;
        }
        $counts['assessed'] = $counts['total'] - $counts['unreviewed'];
        $rem = $this->one('SELECT COUNT(*) AS total,COALESCE(SUM(CASE WHEN status<>\'done\' AND due_on<CURRENT_DATE() THEN 1 ELSE 0 END),0) AS overdue,COALESCE(SUM(CASE WHEN status=\'done\' THEN 1 ELSE 0 END),0) AS done FROM ioc_audit_remediation WHERE cycle_id=?', [(int) $cycle['id']]);
        $snap = $this->one('SELECT COUNT(*) AS snapshots,COALESCE(SUM(bytes),0) AS bytes FROM ioc_audit_evidence WHERE cycle_id=? AND kind IN (\'case_snapshot\',\'auto_snapshot\')', [(int) $cycle['id']]);
        $counts += ['remediations' => (int) $rem['total'], 'overdue' => (int) $rem['overdue'], 'remediations_done' => (int) $rem['done'],
            'snapshots' => (int) $snap['snapshots'], 'snapshot_bytes' => (int) $snap['bytes']];
        return ['ready' => true, 'message' => null, 'catalog' => $catalog['version_label'],
            'cycle' => ['title' => $cycle['title'], 'starts_on' => $cycle['starts_on'], 'ends_on' => $cycle['ends_on']],
            'counts' => $counts, 'domains' => array_values($domains)];
    }

    /** @return array<string,mixed> 管理者明細，歷史版本僅供檢視。 */
    public function detail(int $cycleId, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以檢視稽核明細');
        $stored = $this->one("SELECT (SELECT COALESCE(SUM(bytes),0) FROM ioc_audit_evidence WHERE kind IN ('case_snapshot','auto_snapshot')) + (SELECT COALESCE(SUM(bytes),0) FROM ioc_audit_auto_evidence WHERE snapshot_name IS NOT NULL) AS used_bytes");
        $storage = ['storage_free_bytes' => $this->store->freeBytes(), 'storage_used_bytes' => (int) $stored['used_bytes']];
        $events = $this->all('SELECT occurred_at,actor,action,target_type,target_id FROM ioc_audit_events ORDER BY id DESC LIMIT 100');
        $catalogs = $this->all('SELECT id,version_label,digest,active,created_at FROM ioc_audit_catalogs ORDER BY id DESC LIMIT 50');
        $cycles = $this->all('SELECT y.id,y.catalog_id,y.title,y.starts_on,y.ends_on,y.is_current,c.version_label,c.active FROM ioc_audit_cycles y JOIN ioc_audit_catalogs c ON c.id=y.catalog_id ORDER BY y.id DESC LIMIT 50');
        $activeCycle = $this->one('SELECT y.* FROM ioc_audit_cycles y JOIN ioc_audit_catalogs c ON c.id=y.catalog_id WHERE c.active=1 AND y.is_current=1 ORDER BY y.id DESC LIMIT 1');
        $cycle = $cycleId > 0 ? $this->requireRow('SELECT * FROM ioc_audit_cycles WHERE id=?', [$cycleId]) : ($activeCycle ?? ($cycles[0] ?? null));
        if (!$cycle) {
            return ['catalogs' => $catalogs, 'cycles' => $cycles, 'selected_cycle' => null, 'current_cycle_id' => null,
                'controls' => [], 'evidence' => [], 'remediation' => [], 'events' => $events] + $storage;
        }
        $current = $this->one('SELECT y.id FROM ioc_audit_cycles y JOIN ioc_audit_catalogs c ON c.id=y.catalog_id WHERE c.active=1 AND y.is_current=1 ORDER BY y.id DESC LIMIT 1');
        $controls = $this->all('SELECT c.id,c.code,c.domain,c.title,c.requirement,c.description,COALESCE(a.status,\'unreviewed\') AS status,a.reason,a.evidence_id,a.assessed_by,a.assessed_at FROM ioc_audit_controls c LEFT JOIN ioc_audit_assessments a ON a.control_id=c.id AND a.cycle_id=? WHERE c.catalog_id=? ORDER BY c.domain,c.code LIMIT 2000', [(int) $cycle['id'], (int) $cycle['catalog_id']]);
        return ['catalogs' => $catalogs, 'cycles' => $cycles, 'selected_cycle' => (int) $cycle['id'],
            'current_cycle_id' => (int) ($current['id'] ?? 0), 'controls' => $controls,
            'evidence' => $this->all('SELECT id,control_id,kind,source_ref,source_from,source_to,sha256,bytes,item_count,truncated,captured_by,captured_at FROM ioc_audit_evidence WHERE cycle_id=? ORDER BY id DESC LIMIT 500', [(int) $cycle['id']]),
            'remediation' => $this->all('SELECT * FROM ioc_audit_remediation WHERE cycle_id=? ORDER BY id DESC LIMIT 500', [(int) $cycle['id']]),
            'events' => $events] + $storage;
    }

    public function history(int $cycleId, int $controlId, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以檢視稽核歷程');
        $this->requireRow('SELECT c.id FROM ioc_audit_controls c JOIN ioc_audit_cycles y ON y.catalog_id=c.catalog_id WHERE c.id=? AND y.id=?', [$controlId, $cycleId]);
        return ['assessments' => $this->all('SELECT status,reason,evidence_id,actor,occurred_at FROM ioc_audit_assessment_history WHERE cycle_id=? AND control_id=? ORDER BY id DESC LIMIT 100', [$cycleId, $controlId]),
            'remediation' => $this->all('SELECT h.remediation_id,h.status,h.owner,h.due_on,h.note,h.closing_evidence_id,h.actor,h.occurred_at FROM ioc_audit_remediation_history h JOIN ioc_audit_remediation r ON r.id=h.remediation_id WHERE r.cycle_id=? AND r.control_id=? ORDER BY h.id DESC LIMIT 100', [$cycleId, $controlId])];
    }

    public function assess(int $cycleId, int $controlId, string $status, string $reason, ?int $evidenceId, string $actor, ?string $level): void
    {
        $this->admin($level, $actor);
        if (!in_array($status, ['unreviewed', 'compliant', 'gap', 'not_applicable'], true)) {
            throw new ValidationError(['控制項判定不正確']);
        }
        $reason = self::optional($reason, 2000, '判定理由');
        if ($status === 'not_applicable' && $reason === '') {
            throw new ValidationError(['不適用時須填寫理由']);
        }
        if ($status === 'compliant' && (!$evidenceId || $evidenceId < 1)) {
            throw new ValidationError(['判定符合時須關聯證據']);
        }
        $this->transaction(function () use ($cycleId, $controlId, $status, $reason, $evidenceId, $actor): void {
            $this->currentControl($cycleId, $controlId);
            if ($evidenceId !== null) { $this->evidenceForControl($cycleId, $controlId, $evidenceId); }
            $this->exec('INSERT INTO ioc_audit_assessments (cycle_id,control_id,status,reason,evidence_id,assessed_by,assessed_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=VALUES(status),reason=VALUES(reason),evidence_id=VALUES(evidence_id),assessed_by=VALUES(assessed_by),assessed_at=VALUES(assessed_at)',
                [$cycleId, $controlId, $status, $reason, $evidenceId, $actor]);
            $this->exec('INSERT INTO ioc_audit_assessment_history (cycle_id,control_id,status,reason,evidence_id,actor,occurred_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',
                [$cycleId, $controlId, $status, $reason, $evidenceId, $actor]);
            $this->event($actor, 'assessment_save', 'control', $controlId);
        });
    }

    public function captureCase(int $cycleId, int $controlId, int $caseId, string $actor, ?string $level): int
    {
        $this->admin($level, $actor);
        $this->currentControl($cycleId, $controlId);
        if ($caseId < 1) { throw new ValidationError(['請指定有效的事件案']); }
        $source = ($this->caseSource)($caseId);
        $case = $source['case'] ?? null; $evidence = $source['evidence'] ?? null;
        if (!is_array($case) || !is_array($evidence) || ($evidence['ok'] ?? false) !== true || !is_array($evidence['data'] ?? null)) {
            throw new IocError('事件案證據來源無法取得；未建立快照');
        }
        $data = $evidence['data'];
        $from = $data['window_utc']['from'] ?? null;
        $to = $data['window_utc']['to'] ?? null;
        $query = $data['query'] ?? null;
        if (!is_string($from) || !is_string($to) || !is_string($query) || $query === ''
            || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $from)
            || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $to)) {
            throw new IocError('事件案查詢範圍不正確；未建立快照');
        }
        $content = json_encode(['schema' => 1, 'source' => 'ioc_case_graylog', 'case_id' => $caseId,
            'query' => $query, 'from_utc' => $from, 'to_utc' => $to,
            'count' => (int) ($data['total'] ?? 0), 'limit' => EvidenceService::LIMIT,
            'truncated' => ($data['truncated'] ?? false) === true, 'items' => $data['items'] ?? []],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $file = $this->store->put($content);
        try {
            return $this->transaction(function () use ($cycleId, $controlId, $caseId, $actor, $from, $to, $data, $file): int {
                $this->currentControl($cycleId, $controlId);
                $this->exec('INSERT INTO ioc_audit_evidence (cycle_id,control_id,kind,source_ref,source_from,source_to,snapshot_name,sha256,bytes,item_count,truncated,captured_by,captured_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
                    [$cycleId, $controlId, 'case_snapshot', 'case:' . $caseId, $from, $to, $file['name'], $file['sha256'], $file['bytes'],
                        (int) ($data['total'] ?? 0), ($data['truncated'] ?? false) === true ? 1 : 0, $actor]);
                $id = (int) $this->db->lastInsertId(); $this->event($actor, 'evidence_capture', 'evidence', $id);
                return $id;
            });
        } catch (\Throwable $e) {
            $this->store->discard($file['name']);
            throw $e;
        }
    }

    public function linkBackup(int $cycleId, int $controlId, int $deviceId, int $versionId, string $actor, ?string $level): int
    {
        $this->admin($level, $actor); $this->currentControl($cycleId, $controlId);
        if ($deviceId < 1 || $versionId < 1) { throw new ValidationError(['請選擇有效的備份版本']); }
        $version = ($this->backupSource)($deviceId, $versionId);
        if ((int) ($version['device_id'] ?? 0) !== $deviceId || (int) ($version['id'] ?? 0) !== $versionId
            || ($version['complete'] ?? false) !== true || !preg_match('/\A[a-f0-9]{64}\z/D', (string) ($version['digest'] ?? ''))
            || (int) ($version['bytes'] ?? 0) < 1 || (int) ($version['bytes'] ?? 0) > 2 * 1024 * 1024) {
            throw new IocError('備份版本不存在或不完整；未建立證據連結');
        }
        $created = Time::parse((string) ($version['created_at'] ?? ''));
        if ($created === null) { throw new IocError('備份版本建立時間不正確；未建立證據連結'); }
        $sourceAt = gmdate('Y-m-d H:i:s', $created);
        return $this->transaction(function () use ($cycleId, $controlId, $deviceId, $versionId, $version, $sourceAt, $actor): int {
            $this->currentControl($cycleId, $controlId);
            $this->exec('INSERT INTO ioc_audit_evidence (cycle_id,control_id,kind,source_ref,source_from,source_to,sha256,bytes,captured_by,captured_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
                [$cycleId, $controlId, 'backup_ref', 'device:' . $deviceId . ':version:' . $versionId,
                    $sourceAt, $sourceAt, $version['digest'], (int) $version['bytes'], $actor]);
            $id = (int) $this->db->lastInsertId(); $this->event($actor, 'evidence_link', 'evidence', $id);
            return $id;
        });
    }

    public function linkExternal(int $cycleId, int $controlId, string $reference, string $actor, ?string $level): int
    {
        $this->admin($level, $actor);
        $reference = self::required($reference, 200, '外部封存識別碼');
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]*\z/D', $reference)) {
            throw new ValidationError(['外部封存識別碼格式不正確']);
        }
        return $this->transaction(function () use ($cycleId, $controlId, $reference, $actor): int {
            $this->currentControl($cycleId, $controlId);
            $this->exec('INSERT INTO ioc_audit_evidence (cycle_id,control_id,kind,source_ref,captured_by,captured_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())',
                [$cycleId, $controlId, 'external_ref', $reference, $actor]);
            $id = (int) $this->db->lastInsertId(); $this->event($actor, 'evidence_link', 'evidence', $id);
            return $id;
        });
    }

    /** @return array{content:string,filename:string} */
    public function download(int $id, string $actor, ?string $level): array
    {
        $this->admin($level, $actor);
        $row = $this->requireRow('SELECT * FROM ioc_audit_evidence WHERE id=?', [$id]);
        if (!in_array($row['kind'], ['case_snapshot', 'auto_snapshot'], true)) { throw new ValidationError(['此證據只有來源連結，沒有可下載快照']); }
        $content = $this->store->get((string) $row['snapshot_name'], (string) $row['sha256'], (int) $row['bytes']);
        $this->event($actor, 'evidence_download', 'evidence', $id);
        return ['content' => $content, 'filename' => 'ioc-evidence-' . $id . '.json'];
    }

    public function saveRemediation(int $id, int $cycleId, int $controlId, string $owner, string $due,
        string $status, string $note, ?int $closingEvidence, string $actor, ?string $level): int
    {
        $this->admin($level, $actor);
        $owner = self::required($owner, 255, '改善負責人'); self::date($due);
        $note = self::optional($note, 2000, '改善說明');
        if (!in_array($status, ['open', 'in_progress', 'done'], true)) { throw new ValidationError(['改善狀態不正確']); }
        if ($status === 'done' && (!$closingEvidence || $closingEvidence < 1)) { throw new ValidationError(['結案時須關聯證據']); }
        return $this->transaction(function () use ($id, $cycleId, $controlId, $owner, $due, $status, $note, $closingEvidence, $actor): int {
            $this->currentControl($cycleId, $controlId);
            if ($closingEvidence !== null) { $this->evidenceForControl($cycleId, $controlId, $closingEvidence); }
            if ($id > 0) {
                $row = $this->requireRow('SELECT id FROM ioc_audit_remediation WHERE id=? AND cycle_id=? AND control_id=? FOR UPDATE', [$id, $cycleId, $controlId]);
                $this->exec('UPDATE ioc_audit_remediation SET owner=?,due_on=?,status=?,note=?,closing_evidence_id=?,updated_at=UTC_TIMESTAMP() WHERE id=?',
                    [$owner, $due, $status, $note, $closingEvidence, $row['id']]);
            } else {
                $assessment = $this->one('SELECT status FROM ioc_audit_assessments WHERE cycle_id=? AND control_id=?', [$cycleId, $controlId]);
                if (($assessment['status'] ?? '') !== 'gap') { throw new ValidationError(['只能從已判定的缺口建立改善事項']); }
                $this->exec('INSERT INTO ioc_audit_remediation (cycle_id,control_id,owner,due_on,status,note,closing_evidence_id,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
                    [$cycleId, $controlId, $owner, $due, $status, $note, $closingEvidence, $actor]);
                $id = (int) $this->db->lastInsertId();
            }
            $this->exec('INSERT INTO ioc_audit_remediation_history (remediation_id,status,owner,due_on,note,closing_evidence_id,actor,occurred_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())',
                [$id, $status, $owner, $due, $note, $closingEvidence, $actor]);
            $this->event($actor, 'remediation_save', 'remediation', $id);
            return $id;
        });
    }

    private function currentControl(int $cycleId, int $controlId): void
    {
        $row = $this->one('SELECT y.id FROM ioc_audit_cycles y JOIN ioc_audit_catalogs v ON v.id=y.catalog_id AND v.active=1 JOIN ioc_audit_controls c ON c.catalog_id=y.catalog_id AND c.id=? WHERE y.id=? AND y.is_current=1', [$controlId, $cycleId]);
        if (!$row) { throw new InvalidTransition('歷史版本或週期僅供檢視，請選擇目前評估週期'); }
    }

    private function evidenceForControl(int $cycleId, int $controlId, int $id): void
    {
        $this->requireRow('SELECT id FROM ioc_audit_evidence WHERE id=? AND cycle_id=? AND control_id=? AND kind<>\'external_ref\'', [$id, $cycleId, $controlId]);
    }

    private function admin(?string $level, string $actor): void
    {
        Access::requireHandler($level, '只有管理者可以管理稽核與證據');
        self::required($actor, 255, '操作者');
    }

    /** @return array<int,array{code:string,domain:string,title:string,requirement:string,description:string}> */
    public static function parseCsv(string $csv): array
    {
        if ($csv === '' || strlen($csv) > 1024 * 1024 || !mb_check_encoding($csv, 'UTF-8')) {
            throw new ValidationError(['CSV 必須是 UTF-8，且不可超過 1 MiB']);
        }
        $csv = preg_replace('/\A\xEF\xBB\xBF/', '', $csv);
        $fh = fopen('php://temp', 'w+'); fwrite($fh, $csv); rewind($fh);
        try {
            $head = fgetcsv($fh, 0, ',', '"', '');
            if ($head !== ['code', 'domain', 'title', 'requirement', 'description']) {
                throw new ValidationError(['CSV 標題須為 code,domain,title,requirement,description']);
            }
            $rows = []; $seen = [];
            while (($cols = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                if ($cols === [null]) { continue; }
                if (count($cols) !== 5 || count($rows) >= 2000) { throw new ValidationError(['CSV 欄位數不正確或超過 2,000 筆']); }
                [$code, $domain, $title, $requirement, $description] = $cols;
                $code = self::required($code, 64, '控制項代碼');
                if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $code) || isset($seen[$code])) {
                    throw new ValidationError(['控制項代碼格式不正確或重複：' . $code]);
                }
                $seen[$code] = true;
                $rows[] = ['code' => $code, 'domain' => self::required($domain, 100, '領域'),
                    'title' => self::required($title, 200, '名稱'), 'requirement' => self::required($requirement, 4000, '要求'),
                    'description' => self::optional($description, 4000, '說明')];
            }
            if ($rows === []) { throw new ValidationError(['CSV 沒有控制項']); }
            usort($rows, static fn ($a, $b) => strcmp($a['code'], $b['code']));
            return $rows;
        } finally { fclose($fh); }
    }

    private static function catalogDigest(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function sameControl(array $prior, array $current): bool
    {
        foreach (['code', 'domain', 'title', 'requirement', 'description'] as $key) {
            if ((string) ($prior[$key] ?? '') !== $current[$key]) { return false; }
        }
        return true;
    }

    private static function required(string $value, int $max, string $label): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new ValidationError([$label . '不可為空、超長或包含控制字元']);
        }
        return $value;
    }

    private static function optional(string $value, int $max, string $label): string
    {
        $value = trim($value);
        return $value === '' ? '' : self::required($value, $max, $label);
    }

    private static function date(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) { throw new ValidationError(['日期格式須為 YYYY-MM-DD']); }
    }

    private function event(string $actor, string $action, string $target, int $id): void
    {
        $this->exec('INSERT INTO ioc_audit_events (occurred_at,actor,action,target_type,target_id) VALUES (UTC_TIMESTAMP(),?,?,?,?)', [$actor, $action, $target, $id]);
    }

    private function exec(string $sql, array $args = []): void
    {
        $st = $this->db->prepare($sql); $st->execute($args);
    }

    private function one(string $sql, array $args = []): ?array
    {
        $st = $this->db->prepare($sql); $st->execute($args);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function requireRow(string $sql, array $args = []): array
    {
        return $this->one($sql, $args) ?? throw new NotFound('找不到指定資料或來源已不屬於此評估週期');
    }

    private function all(string $sql, array $args = []): array
    {
        $st = $this->db->prepare($sql); $st->execute($args);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function transaction(callable $fn)
    {
        $this->db->beginTransaction();
        try { $value = $fn(); $this->db->commit(); return $value; }
        catch (\Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
    }
}
