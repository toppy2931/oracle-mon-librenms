<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 事件案的規則都在這裡：誰能做什麼（IS-013、IS-015）、狀態流程（EVT-004）、輸入驗證（IS-058）、
 * 歷程紀錄（EVT-003、IS-021～025）。所有方法都先檢查權限，端點不必也不該自己判斷。
 *
 * $level 是 Access::level() 的結果。
 */
final class CaseService
{
    private const TEXT_MAX  = 2000;
    private const SHORT_MAX = 128;
    private const OPEN      = ['new', 'ack', 'in_progress'];

    private CaseRepo $repo;
    /** @var callable():string */
    private $now;

    /** @param callable():string|null $now 回傳 UTC 的 'Y-m-d H:i:s'，測試用 */
    public function __construct(CaseRepo $repo, ?callable $now = null)
    {
        $this->repo = $repo;
        $this->now  = $now ?? static fn (): string => gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<string,mixed> $in
     * @return array{id:int,created:bool} dedup_key 已存在時回傳既有的案件（created=false）
     */
    public function create(array $in, string $actor, ?string $level, string $source = 'manual'): array
    {
        $this->requireHandler($level);
        $row = $this->validateNew($in);
        $now = ($this->now)();

        if ($row['dedup_key'] !== null) {
            $existing = $this->repo->findByDedupKey($row['dedup_key']);
            if ($existing !== null) {
                return ['id' => (int) $existing['id'], 'created' => false];
            }
        }

        $row += [
            'status'     => 'new',
            'source'     => $source,
            'assignee'   => null,
            'resolution' => null,
            'created_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
            'closed_at'  => null,
        ];

        try {
            $id = $this->repo->transaction(function () use ($row, $actor, $now): int {
                $id = $this->repo->insertCase($row);
                $this->repo->addHistory($id, $this->historyRow($now, $actor, 'created', null, 'new', null, null));
                return $id;
            });
        } catch (\PDOException $e) {
            // 兩個請求同時用同一個 dedup_key 建立時，唯一索引會擋下後到的那個
            if ($row['dedup_key'] !== null && (string) $e->getCode() === '23000') {
                $existing = $this->repo->findByDedupKey($row['dedup_key']);
                if ($existing !== null) {
                    return ['id' => (int) $existing['id'], 'created' => false];
                }
            }
            throw $e;
        }

        return ['id' => $id, 'created' => true];
    }

    /** @return array{case:array<string,mixed>,history:array<int,array<string,mixed>>,allowed:string[]} */
    public function get(int $id, ?string $level): array
    {
        $this->requireView($level);
        $case = $this->repo->getCase($id);
        if ($case === null) {
            throw new NotFound('找不到這個事件案');
        }
        return [
            'case'    => $case,
            'history' => $this->repo->history($id),
            'allowed' => Access::canHandle($level) ? CaseFlow::allowed((string) $case['status']) : [],
        ];
    }

    /**
     * @param array{status?:string,limit?:int,offset?:int} $filter status 可為各狀態、'open'（未結案）或空（全部）
     * @return array{items:array<int,array<string,mixed>>,has_more:bool}
     */
    public function listCases(array $filter, ?string $level): array
    {
        $this->requireView($level);
        $status = (string) ($filter['status'] ?? '');
        if ($status === 'open') {
            $statuses = self::OPEN;
        } elseif ($status === '') {
            $statuses = [];
        } elseif (isset(Labels::CASE_STATUS[$status])) {
            $statuses = [$status];
        } else {
            throw new ValidationError(['狀態不正確']);
        }
        $limit  = max(1, min(200, (int) ($filter['limit'] ?? 50)));
        $offset = max(0, (int) ($filter['offset'] ?? 0));

        $rows = $this->repo->listCases($statuses, $limit + 1, $offset);   // 多取一筆判斷還有沒有下一頁
        $more = count($rows) > $limit;
        return ['items' => array_slice($rows, 0, $limit), 'has_more' => $more];
    }

    /** @return array<string,int> new／ack／in_progress／closed 各幾件，另有 open（未結案合計） */
    public function summary(?string $level): array
    {
        $this->requireView($level);
        $counts = array_fill_keys(array_keys(Labels::CASE_STATUS), 0);
        foreach ($this->repo->countByStatus() as $status => $n) {
            if (isset($counts[$status])) {
                $counts[$status] = (int) $n;
            }
        }
        $counts['open'] = $counts['new'] + $counts['ack'] + $counts['in_progress'];
        return $counts;
    }

    public function transition(int $id, string $to, string $actor, ?string $level, ?string $resolution = null, ?string $note = null): void
    {
        $this->requireHandler($level);
        if (!isset(Labels::CASE_STATUS[$to])) {
            throw new ValidationError(['狀態不正確']);
        }
        $note = $this->text($note, '備註', false);
        if ($to === 'closed') {
            if ($resolution === null || !isset(Labels::RESOLUTION[$resolution])) {
                throw new ValidationError(['結案要選處理結果（已處理、誤報或接受風險）']);
            }
        } else {
            $resolution = null;
        }
        $now = ($this->now)();

        $this->repo->transaction(function () use ($id, $to, $actor, $resolution, $note, $now): void {
            $case = $this->repo->getCase($id, true);
            if ($case === null) {
                throw new NotFound('找不到這個事件案');
            }
            $from = (string) $case['status'];
            if (!CaseFlow::canMove($from, $to)) {
                throw new InvalidTransition(sprintf(
                    '「%s」不能直接變成「%s」',
                    Labels::caseStatus($from),
                    Labels::caseStatus($to)
                ));
            }
            $fields = [
                'status'     => $to,
                'resolution' => $resolution,
                'updated_at' => $now,
                'closed_at'  => $to === 'closed' ? $now : null,   // 重新開啟時清掉
            ];
            if (($to === 'ack' || $to === 'in_progress') && empty($case['assignee'])) {
                $fields['assignee'] = $actor;
            }
            $this->repo->updateCase($id, $fields);
            $this->repo->addHistory($id, $this->historyRow($now, $actor, 'status_changed', $from, $to, $resolution, $note));
        });
    }

    public function comment(int $id, string $actor, ?string $level, string $note): void
    {
        $this->requireHandler($level);
        $note = $this->text($note, '備註', true);
        $now  = ($this->now)();

        $this->repo->transaction(function () use ($id, $actor, $note, $now): void {
            if ($this->repo->getCase($id, true) === null) {
                throw new NotFound('找不到這個事件案');
            }
            $this->repo->updateCase($id, ['updated_at' => $now]);
            $this->repo->addHistory($id, $this->historyRow($now, $actor, 'comment', null, null, null, $note));
        });
    }

    // ── 內部 ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function validateNew(array $in): array
    {
        $errors = [];

        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = '標題要填，且不能超過 200 字';
        }

        $severity = (string) ($in['severity'] ?? 'medium');
        if (!isset(Labels::CASE_SEVERITY[$severity])) {
            $errors[] = '等級不正確';
        }

        $ip = $this->optional($in['subject_ip'] ?? null);
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $errors[] = '來源位址不是合法的 IP';
        }

        $out = [
            'title'          => $title,
            'severity'       => $severity,
            'subject_user'   => $this->short($in['subject_user'] ?? null, '帳號', $errors),
            'subject_ip'     => $ip,
            'subject_host'   => $this->short($in['subject_host'] ?? null, '設備', $errors),
            'dedup_key'      => $this->short($in['dedup_key'] ?? null, '去重鍵', $errors),
            'summary'        => $this->longText($in['summary'] ?? null, '說明', $errors),
            'evidence_query' => $this->longText($in['evidence_query'] ?? null, '查詢條件', $errors, 1000),
        ];
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return $out;
    }

    private function optional($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    /** @param string[] $errors */
    private function short($v, string $label, array &$errors): ?string
    {
        $s = $this->optional($v);
        if ($s !== null && mb_strlen($s) > self::SHORT_MAX) {
            $errors[] = $label . '不能超過 ' . self::SHORT_MAX . ' 字';
        }
        return $s;
    }

    /** @param string[] $errors */
    private function longText($v, string $label, array &$errors, int $max = self::TEXT_MAX): ?string
    {
        $s = $this->optional($v);
        if ($s !== null && mb_strlen($s) > $max) {
            $errors[] = $label . '不能超過 ' . $max . ' 字';
        }
        return $s;
    }

    private function text(?string $v, string $label, bool $required): ?string
    {
        $s = $this->optional($v);
        if ($s === null) {
            if ($required) {
                throw new ValidationError([$label . '要填']);
            }
            return null;
        }
        if (mb_strlen($s) > self::TEXT_MAX) {
            throw new ValidationError([$label . '不能超過 ' . self::TEXT_MAX . ' 字']);
        }
        return $s;
    }

    /** @return array<string,mixed> */
    private function historyRow(string $ts, string $actor, string $action, ?string $from, ?string $to, ?string $resolution, ?string $note): array
    {
        return [
            'ts'          => $ts,
            'actor'       => $actor,
            'action'      => $action,
            'from_status' => $from,
            'to_status'   => $to,
            'resolution'  => $resolution,
            'note'        => $note,
        ];
    }

    private function requireView(?string $level): void
    {
        if (!Access::canView($level)) {
            throw new AccessDenied('沒有檢視權限');
        }
    }

    private function requireHandler(?string $level): void
    {
        if (!Access::canHandle($level)) {
            throw new AccessDenied('沒有處理事件案的權限');
        }
    }
}
