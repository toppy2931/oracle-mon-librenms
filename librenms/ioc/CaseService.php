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
    private const SCOPE_MAX = 200;
    public const TAGS_MAX     = 5;      // 一件事件案最多幾個標籤
    public const TAG_LEN_MAX  = 20;     // 每個標籤最多幾個字

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
            $statuses = CaseFlow::OPEN;
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

    /** @return array<string,int> 未結案的件數，依嚴重性（high／medium／low，沒有的是 0） */
    public function openBySeverity(?string $level): array
    {
        $this->requireView($level);
        $out = array_fill_keys(array_keys(Labels::CASE_SEVERITY), 0);
        foreach ($this->repo->countOpenBySeverity() as $severity => $n) {
            if (isset($out[$severity])) {
                $out[$severity] = (int) $n;
            }
        }
        return $out;
    }

    /**
     * 最近 $days 天建立的案件（只有建立時間 UTC 與嚴重性），給趨勢圖用。
     * 多抓一天，讓台北時間的日期邊界不會漏；怎麼分日、怎麼畫是 LandscapeService 的事。
     *
     * @return array<int,array{created_at:string,severity:string}>
     */
    public function createdWithin(int $days, ?string $level): array
    {
        $this->requireView($level);
        $days  = max(1, min(366, $days));
        $since = gmdate('Y-m-d H:i:s', (int) strtotime(($this->now)() . ' UTC') - ($days + 1) * 86400);
        return $this->repo->createdSince($since);
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

        // 事件類型：沒選就是 NULL（詳情頁當成「其他」）；選了就一定要是目錄裡有的代碼
        $category = $this->optional($in['category'] ?? null);
        if ($category !== null && !CaseCatalog::isValid($category)) {
            $errors[] = '事件類型不正確';
        }

        // 查詢條件之後會被包進「(…) AND NOT ioc_demo:true」去問 Graylog：括號或引號沒有成對，就會跳出這層包裝
        // （範例資料被混進證據）或讓每次打開詳情都收到含糊的 Graylog 400，所以建立時就擋下
        $evidence = $this->longText($in['evidence_query'] ?? null, '查詢條件', $errors, 1000);
        if ($evidence !== null && !self::isBalancedQuery($evidence)) {
            $errors[] = '查詢條件的括號或引號沒有成對';
        }

        $out = [
            'title'          => $title,
            'severity'       => $severity,
            'category'       => $category,
            'subject_user'   => $this->short($in['subject_user'] ?? null, '帳號', $errors),
            'subject_ip'     => $ip,
            'subject_host'   => $this->short($in['subject_host'] ?? null, '設備', $errors),
            'target_host'    => $this->short($in['target_host'] ?? null, '目的主機', $errors),
            'related_host'   => $this->short($in['related_host'] ?? null, '關聯主機', $errors),
            'impact_scope'   => $this->longText($in['impact_scope'] ?? null, '影響範圍', $errors, self::SCOPE_MAX),
            'tags'           => $this->tags($in['tags'] ?? null, $errors),
            'dedup_key'      => $this->short($in['dedup_key'] ?? null, '去重鍵', $errors),
            'summary'        => $this->longText($in['summary'] ?? null, '說明', $errors),
            'evidence_query' => $evidence,
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

    /**
     * Lucene 查詢條件的括號與雙引號是否成對：引號裡面的括號不算，反斜線後面那個字元是跳脫（不算）。
     * 只看 ASCII 的結構字元，UTF-8 多位元組字元的每個位元組都 ≥ 0x80，不會被誤認。
     */
    public static function isBalancedQuery(string $q): bool
    {
        $depth  = 0;
        $quoted = false;
        for ($i = 0, $n = strlen($q); $i < $n; $i++) {
            $ch = $q[$i];
            if ($ch === '\\') {
                $i++;
            } elseif ($ch === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && $ch === '(') {
                $depth++;
            } elseif (!$quoted && $ch === ')' && --$depth < 0) {
                return false;
            }
        }
        return !$quoted && $depth === 0;
    }

    /**
     * 標籤：用逗號（半形、全形、頓號）、分號或換行分隔，去掉空白與重複，最多 TAGS_MAX 個、每個最多 TAG_LEN_MAX 字、
     * 不能有控制字元。存成以半形逗號串起來的字串（標籤本身不可能含逗號，所以拆回來不會錯），沒有標籤是 NULL。
     *
     * @param mixed $v
     * @param string[] $errors
     */
    private function tags($v, array &$errors): ?string
    {
        $raw = $this->optional($v);
        if ($raw === null) {
            return null;
        }
        $parts = preg_split('/[,，、;；\r\n]+/u', $raw);
        if ($parts === false) {          // 不是合法的 UTF-8：不能悄悄丟掉標籤
            $errors[] = '標籤的文字編碼不正確';
            return null;
        }
        $tags = [];
        foreach ($parts as $t) {
            // trim() 不會去掉全形空白（U+3000）與不換行空白（U+00A0），中文輸入法很常打出來；留著會讓去重與顯示都錯
            $t = (string) preg_replace('/^[\s\x{3000}\x{00A0}]+|[\s\x{3000}\x{00A0}]+$/u', '', $t);
            if ($t === '') {
                continue;
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $t)) {
                $errors[] = '標籤不能含控制字元';
                return null;
            }
            if (mb_strlen($t) > self::TAG_LEN_MAX) {
                $errors[] = '每個標籤不能超過 ' . self::TAG_LEN_MAX . ' 個字';
                return null;
            }
            if (!in_array($t, $tags, true)) {
                $tags[] = $t;
            }
        }
        if (count($tags) > self::TAGS_MAX) {
            $errors[] = '標籤最多 ' . self::TAGS_MAX . ' 個';
            return null;
        }
        return $tags === [] ? null : implode(',', $tags);
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
