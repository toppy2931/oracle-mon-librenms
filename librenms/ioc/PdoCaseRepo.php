<?php
declare(strict_types=1);

namespace Ioc;

/**
 * CaseRepo 的 MariaDB 實作。只用預先準備的陳述式；欄位名稱只取自下面的白名單，
 * 不會把使用者輸入拼進 SQL。PDO 必須是 ERRMODE_EXCEPTION（LibreNMS／Laravel 預設如此）。
 */
final class PdoCaseRepo implements CaseRepo
{
    private const CASE_COLUMNS = [
        'title', 'summary', 'status', 'severity', 'subject_user', 'subject_ip', 'subject_host',
        'evidence_query', 'source', 'dedup_key', 'assignee', 'resolution', 'created_by',
        'created_at', 'updated_at', 'closed_at',
    ];
    private const HISTORY_COLUMNS = ['ts', 'actor', 'action', 'from_status', 'to_status', 'resolution', 'note'];

    private \PDO $pdo;
    private int $depth = 0;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insertCase(array $row): int
    {
        $cols = $this->only($row, self::CASE_COLUMNS);
        $sql  = sprintf(
            'INSERT INTO ioc_cases (%s) VALUES (%s)',
            implode(', ', array_keys($cols)),
            implode(', ', array_map(static fn ($c) => ':' . $c, array_keys($cols)))
        );
        $this->pdo->prepare($sql)->execute($cols);
        return (int) $this->pdo->lastInsertId();
    }

    public function findByDedupKey(string $key): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ioc_cases WHERE dedup_key = :k');
        $st->execute(['k' => $key]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function getCase(int $id, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM ioc_cases WHERE id = :id' . ($forUpdate && $this->depth > 0 ? ' FOR UPDATE' : '');
        $st  = $this->pdo->prepare($sql);
        $st->execute(['id' => $id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function updateCase(int $id, array $fields): void
    {
        $cols = $this->only($fields, self::CASE_COLUMNS);
        if ($cols === []) {
            return;
        }
        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($cols)));
        $cols['__id'] = $id;
        $this->pdo->prepare("UPDATE ioc_cases SET $set WHERE id = :__id")->execute($cols);
    }

    public function addHistory(int $caseId, array $row): void
    {
        $cols = $this->only($row, self::HISTORY_COLUMNS);
        $cols['case_id'] = $caseId;
        $sql = sprintf(
            'INSERT INTO ioc_case_history (%s) VALUES (%s)',
            implode(', ', array_keys($cols)),
            implode(', ', array_map(static fn ($c) => ':' . $c, array_keys($cols)))
        );
        $this->pdo->prepare($sql)->execute($cols);
    }

    public function history(int $caseId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ioc_case_history WHERE case_id = :id ORDER BY id ASC');
        $st->execute(['id' => $caseId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function listCases(array $statuses, int $limit, int $offset): array
    {
        $where  = '';
        $params = [];
        if ($statuses !== []) {
            $marks = [];
            foreach (array_values($statuses) as $i => $s) {
                $marks[] = ':s' . $i;
                $params['s' . $i] = $s;
            }
            $where = ' WHERE status IN (' . implode(', ', $marks) . ')';
        }
        $st = $this->pdo->prepare("SELECT * FROM ioc_cases$where ORDER BY updated_at DESC, id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }
        $st->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $st->bindValue(':off', $offset, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countByStatus(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM ioc_cases GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    public function countOpenBySeverity(): array
    {
        $marks = [];
        $params = [];
        foreach (CaseFlow::OPEN as $i => $s) {
            $marks[] = ':s' . $i;
            $params['s' . $i] = $s;
        }
        $st = $this->pdo->prepare('SELECT severity, COUNT(*) AS n FROM ioc_cases WHERE status IN (' . implode(', ', $marks) . ') GROUP BY severity');
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[$r['severity']] = (int) $r['n'];
        }
        return $out;
    }

    public function createdSince(string $utc): array
    {
        // 有筆數上限（趨勢圖只需要日期與嚴重性，案件量再大也不能讓一次畫面載入讀爆）。
        // 由新到舊排序再截：超過上限時被捨棄的是最舊的，不是最近幾天（呼叫端靠筆數判斷是否截斷）
        $st = $this->pdo->prepare('SELECT created_at, severity FROM ioc_cases WHERE created_at >= :since ORDER BY created_at DESC LIMIT ' . (int) self::CREATED_SINCE_LIMIT);
        $st->execute(['since' => $utc]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function transaction(callable $fn)
    {
        if ($this->depth > 0) {          // 已在交易內就直接執行，由最外層負責 commit／rollback
            return $fn();
        }
        $this->pdo->beginTransaction();
        $this->depth++;
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth--;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param string[] $allowed
     * @return array<string,mixed>
     */
    private function only(array $row, array $allowed): array
    {
        return array_intersect_key($row, array_flip($allowed));
    }
}
