<?php
declare(strict_types=1);

use Ioc\SettingsRepo;

/** 測試用的記憶體版 SettingsRepo。只模擬規則需要的行為（覆寫、由新到舊、交易復原），不碰任何資料庫。 */
class MemorySettingsRepo implements SettingsRepo
{
    /** @var array<string,array{value:string,updated_by:string,updated_at:string}> */
    public array $rows = [];
    /** @var array<int,array{ts:string,actor:string,skey:string,old_value:string,new_value:string}> 由舊到新（最新的在最後） */
    public array $history = [];
    public int $puts = 0;
    /** 設成 true 就讓寫異動紀錄時丟例外，用來驗證交易會整筆復原。 */
    public bool $failOnHistory = false;

    public function all(): array
    {
        return $this->rows;
    }

    public int $locks = 0;

    public function allForUpdate(): array
    {
        $this->locks++;          // 測試用：確認「讀了再寫」的流程有走鎖定讀取
        return $this->rows;
    }

    public function put(string $key, string $value, string $actor, string $utc): void
    {
        $this->puts++;
        $this->rows[$key] = ['value' => $value, 'updated_by' => $actor, 'updated_at' => $utc];
    }

    public function delete(string $key): void
    {
        unset($this->rows[$key]);
    }

    public function addHistory(string $utc, string $actor, string $key, string $old, string $new): void
    {
        if ($this->failOnHistory) {
            throw new RuntimeException('寫入異動紀錄失敗');
        }
        $this->history[] = ['ts' => $utc, 'actor' => $actor, 'skey' => $key, 'old_value' => $old, 'new_value' => $new];
    }

    public function recentHistory(int $limit, ?array $keys = null, ?string $keyPrefix = null): array
    {
        $rows = array_reverse($this->history);
        if ($keys !== null) {
            $rows = array_values(array_filter($rows, static fn ($h) => in_array($h['skey'], $keys, true)));
        }
        if ($keyPrefix !== null) {
            $rows = array_values(array_filter($rows, static fn ($h) => strncmp($h['skey'], $keyPrefix, strlen($keyPrefix)) === 0));
        }
        return array_slice($rows, 0, max(1, $limit));
    }

    public function transaction(callable $fn)
    {
        $snapshot = [$this->rows, $this->history, $this->puts];
        try {
            return $fn();
        } catch (\Throwable $e) {
            [$this->rows, $this->history, $this->puts] = $snapshot;
            throw $e;
        }
    }
}
