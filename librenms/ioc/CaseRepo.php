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

    /** @return array<string,int> 還沒結案的案件，各嚴重性的筆數 */
    public function countOpenBySeverity(): array;

    /** createdSince() 最多回幾筆。 */
    public const CREATED_SINCE_LIMIT = 5000;

    /**
     * 給趨勢圖用：指定時間（UTC，'Y-m-d H:i:s'）之後建立的案件，只取建立時間與嚴重性。
     * 最多 CREATED_SINCE_LIMIT 筆，超過時留下**最新的**（捨棄較早的），呼叫端要用筆數判斷有沒有被截斷。
     *
     * @return array<int,array{created_at:string,severity:string}> 依建立時間由新到舊
     */
    public function createdSince(string $utc): array;

    /**
     * 在交易內執行；$fn 丟出例外就回復。回傳 $fn 的結果。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn);
}
