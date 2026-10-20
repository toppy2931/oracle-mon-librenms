<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 事件案的狀態流程（規格 EVT-004）。
 *
 *   待確認 ─► 已確認 ─► 處理中 ─► 已結案
 *     └────────────────────────────►┘   （誤報等可直接結案）
 *   已結案 ─► 處理中                     （重新開啟）
 *
 * 結案一定要有處理結果（已處理／誤報／接受風險）。
 */
final class CaseFlow
{
    public const TRANSITIONS = [
        'new'         => ['ack', 'closed'],
        'ack'         => ['in_progress', 'closed'],
        'in_progress' => ['closed'],
        'closed'      => ['in_progress'],
    ];

    /** @return string[] */
    public static function allowed(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::allowed($from), true);
    }
}
