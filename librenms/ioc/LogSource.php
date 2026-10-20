<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 日誌查詢來源的介面。正式用 GraylogClient；測試用 tests/php 裡的假來源。
 * 任何失敗一律丟 GraylogUnavailable，呼叫端要顯示「無法取得」，不能當成「沒有資料」（EVT-007、INT-017）。
 */
interface LogSource
{
    /**
     * 彙整查詢。Graylog 的搜尋彙整 API 一定要有 group_by（只有 metrics 會回 400）。
     *
     * @param string[] $groupBy 分組欄位
     * @param string[] $metrics 'count'，或 'latest:timestamp' 這種「函式:欄位」
     * @return array<int,array<string,mixed>> 每列的鍵：分組欄位名稱、count、latest_timestamp…
     * @throws GraylogUnavailable
     */
    public function aggregate(string $query, int $rangeSeconds, array $groupBy, array $metrics, int $limit = 100): array;

    /**
     * 取最新的訊息。
     *
     * @param string[] $fields
     * @return array<int,array<string,mixed>> 每列的鍵：欄位名稱
     * @throws GraylogUnavailable
     */
    public function messages(string $query, int $rangeSeconds, array $fields, int $size): array;
}
