<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 可以指定「某一段絕對時間」查詢的日誌來源（事件詳情的證據時間軸要看事件前後，不是「最近 N 秒」）。
 * 另開一個介面而不是改 LogSource：總覽用的相對時間查詢與它的測試替身完全不受影響。
 * 時間一律是 UTC 的 ISO 8601，例如 2026-10-20T14:00:00.000Z。任何失敗一律丟 GraylogUnavailable（同 LogSource）。
 */
interface WindowedLogSource extends LogSource
{
    /**
     * 取 [$fromIso, $toIso] 內最新的訊息（新到舊）。
     *
     * @param string[] $fields
     * @return array<int,array<string,mixed>>
     * @throws GraylogUnavailable
     */
    public function messagesBetween(string $query, string $fromIso, string $toIso, array $fields, int $size): array;

    /**
     * 彙整查詢（一定要有 group_by，同 LogSource::aggregate）。
     *
     * @param string[] $groupBy
     * @param string[] $metrics
     * @return array<int,array<string,mixed>>
     * @throws GraylogUnavailable
     */
    public function aggregateBetween(string $query, string $fromIso, string $toIso, array $groupBy, array $metrics, int $limit = 100): array;
}
