<?php
declare(strict_types=1);

use Ioc\CaseRepo;

/** 測試用的記憶體版 CaseRepo。只模擬規則需要的行為（唯一索引、交易復原、排序），不碰任何資料庫。 */
class MemoryCaseRepo implements CaseRepo
{
    /** @var array<int,array<string,mixed>> */
    public array $cases = [];
    /** @var array<int,array<int,array<string,mixed>>> */
    public array $history = [];
    private int $nextId = 1;
    public int $transactions = 0;

    public function insertCase(array $row): int
    {
        if (isset($row['dedup_key'])) {
            foreach ($this->cases as $c) {
                if (($c['dedup_key'] ?? null) === $row['dedup_key']) {
                    // 真的 PDOException 的 getCode() 是字串 SQLSTATE（'23000'），建構子只收整數，所以要用反射設定
                    $e = new PDOException('Duplicate entry');
                    $code = new ReflectionProperty(Exception::class, 'code');
                    $code->setAccessible(true);
                    $code->setValue($e, '23000');
                    throw $e;
                }
            }
        }
        $id = $this->nextId++;
        $this->cases[$id] = ['id' => $id] + $row;
        $this->history[$id] = [];
        return $id;
    }

    public function findByDedupKey(string $key): ?array
    {
        foreach ($this->cases as $c) {
            if (($c['dedup_key'] ?? null) === $key) {
                return $c;
            }
        }
        return null;
    }

    public function getCase(int $id, bool $forUpdate = false): ?array
    {
        return $this->cases[$id] ?? null;
    }

    public function updateCase(int $id, array $fields): void
    {
        $this->cases[$id] = array_merge($this->cases[$id], $fields);
    }

    public function addHistory(int $caseId, array $row): void
    {
        $this->history[$caseId][] = ['case_id' => $caseId] + $row;
    }

    public function history(int $caseId): array
    {
        return $this->history[$caseId] ?? [];
    }

    public function listCases(array $statuses, int $limit, int $offset): array
    {
        $rows = array_values(array_filter(
            $this->cases,
            static fn ($c) => $statuses === [] || in_array($c['status'], $statuses, true)
        ));
        usort($rows, static fn ($a, $b) => [$b['updated_at'], $b['id']] <=> [$a['updated_at'], $a['id']]);
        return array_slice($rows, $offset, $limit);
    }

    public function countByStatus(): array
    {
        $out = [];
        foreach ($this->cases as $c) {
            $out[$c['status']] = ($out[$c['status']] ?? 0) + 1;
        }
        return $out;
    }

    public function countOpenBySeverity(): array
    {
        $out = [];
        foreach ($this->cases as $c) {
            if (in_array($c['status'], \Ioc\CaseFlow::OPEN, true)) {
                $out[$c['severity']] = ($out[$c['severity']] ?? 0) + 1;
            }
        }
        return $out;
    }

    public function createdSince(string $utc): array
    {
        $rows = [];
        foreach ($this->cases as $c) {
            if ($c['created_at'] >= $utc) {
                $rows[] = ['created_at' => $c['created_at'], 'severity' => $c['severity']];
            }
        }
        usort($rows, static fn ($a, $b) => $b['created_at'] <=> $a['created_at']);     // 由新到舊，與 PdoCaseRepo 一致
        return array_slice($rows, 0, self::CREATED_SINCE_LIMIT);
    }

    public function transaction(callable $fn)
    {
        // 模擬交易復原：失敗時把案件與歷程還原成開始前的樣子
        $snapshot = [$this->cases, $this->history, $this->nextId];
        $this->transactions++;
        try {
            return $fn();
        } catch (\Throwable $e) {
            [$this->cases, $this->history, $this->nextId] = $snapshot;
            throw $e;
        }
    }
}
