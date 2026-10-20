<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 事件案的資料存取介面。規則（權限、狀態流程、驗證）全在 CaseService，這裡只管存取：
 * 正式環境用 PdoCaseRepo（MariaDB），測試用 tests/php/MemoryCaseRepo.php。
 * 時間一律存 UTC（'Y-m-d H:i:s'），顯示時才轉台北時間（INT-072）。
 */
interface CaseRepo
{
    /** @param array<string,mixed> $row ioc_cases 的欄位 */
    public function insertCase(array $row): int;

    /** @return array<string,mixed>|null */
    public function findByDedupKey(string $key): ?array;

    /** @return array<string,mixed>|null */
    public function getCase(int $id, bool $forUpdate = false): ?array;

    /** @param array<string,mixed> $fields */
    public function updateCase(int $id, array $fields): void;

    /** @param array<string,mixed> $row ioc_case_history 的欄位（只增不改不刪） */
    public function addHistory(int $caseId, array $row): void;

    /** @return array<int,array<string,mixed>> 依時間由舊到新 */
    public function history(int $caseId): array;

    /**
     * @param string[] $statuses 空陣列＝不限狀態
     * @return array<int,array<string,mixed>> 依更新時間由新到舊
     */
    public function listCases(array $statuses, int $limit, int $offset): array;

    /** @return array<string,int> 各狀態的筆數 */
    public function countByStatus(): array;

    /**
     * 在交易內執行；$fn 丟出例外就回復。回傳 $fn 的結果。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn);
}
