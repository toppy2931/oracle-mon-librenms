<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 日誌來源根本沒設定好（例如還沒建立唯讀帳號）時的替身：每次查詢都丟 GraylogUnavailable。
 * 這樣總覽的各區塊會顯示「尚未設定 Graylog 唯讀帳號」，設備與事件案那幾塊仍照常顯示，
 * 而不是整頁壞掉，更不能讓畫面看起來像「沒有事件」。
 */
final class UnavailableLogSource implements WindowedLogSource
{
    private string $reason;

    public function __construct(string $reason)
    {
        $this->reason = $reason;
    }

    public function aggregate(string $query, int $rangeSeconds, array $groupBy, array $metrics, int $limit = 100): array
    {
        throw new GraylogUnavailable($this->reason);
    }

    public function messages(string $query, int $rangeSeconds, array $fields, int $size): array
    {
        throw new GraylogUnavailable($this->reason);
    }

    public function aggregateBetween(string $query, string $fromIso, string $toIso, array $groupBy, array $metrics, int $limit = 100): array
    {
        throw new GraylogUnavailable($this->reason);
    }

    public function messagesBetween(string $query, string $fromIso, string $toIso, array $fields, int $size): array
    {
        throw new GraylogUnavailable($this->reason);
    }
}
