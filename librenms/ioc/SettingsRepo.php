<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 入口設定值的資料存取介面。規則（權限、驗證、預設值）全在 SettingsService，這裡只管存取：
 * 正式環境用 PdoSettingsRepo（MariaDB），測試用 tests/php/MemorySettingsRepo.php。
 * 時間一律存 UTC（'Y-m-d H:i:s'），顯示時才轉台北時間（INT-072）。
 */
interface SettingsRepo
{
    /** @return array<string,array{value:string,updated_by:string,updated_at:string}> 以設定名稱為鍵 */
    public function all(): array;

    /** 新增或覆寫一個設定值。 */
    public function put(string $key, string $value, string $actor, string $utc): void;

    /** 異動紀錄（只增不改不刪）。$old 是異動前生效的值（沒設定過就是預設值）。 */
    public function addHistory(string $utc, string $actor, string $key, string $old, string $new): void;

    /** @return array<int,array{ts:string,actor:string,skey:string,old_value:string,new_value:string}> 由新到舊 */
    public function recentHistory(int $limit): array;

    /**
     * 在交易內執行；$fn 丟出例外就回復。回傳 $fn 的結果。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn);
}
