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

    /**
     * 同 all()，但在交易內會鎖住這些列直到交易結束（SELECT … FOR UPDATE）：「讀現況、算變動、寫回」的流程
     * （站點整份取代）用它，兩個管理者同時儲存時第二個會等第一個做完、再讀到最新的內容。要在 transaction() 裡呼叫。
     *
     * @return array<string,array{value:string,updated_by:string,updated_at:string}>
     */
    public function allForUpdate(): array;

    /** 新增或覆寫一個設定值。 */
    public function put(string $key, string $value, string $actor, string $utc): void;

    /** 刪除一個設定值（站點被移除時用）；不存在就什麼都不做。異動紀錄不受影響（只增不改不刪）。 */
    public function delete(string $key): void;

    /** 異動紀錄（只增不改不刪）。$old 是異動前生效的值（沒設定過就是預設值）。 */
    public function addHistory(string $utc, string $actor, string $key, string $old, string $new): void;

    /**
     * @param string[]|null $keys 只要這些設定名稱的異動；null＝全部
     * @param string|null $keyPrefix 只要設定名稱以這個前綴開頭的異動（站點是一個站點一個設定名稱 site.<id>，刪掉的站點也要看得到，
     *        所以不能列舉名稱）；與 $keys 同時給時兩個條件都要符合
     * @return array<int,array{ts:string,actor:string,skey:string,old_value:string,new_value:string}> 由新到舊
     */
    public function recentHistory(int $limit, ?array $keys = null, ?string $keyPrefix = null): array;

    /**
     * 在交易內執行；$fn 丟出例外就回復。回傳 $fn 的結果。
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn);
}
