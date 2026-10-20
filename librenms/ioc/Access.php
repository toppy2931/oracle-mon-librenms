<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 入口的權限等級（規格 IS-011～018）。沿用 LibreNMS 內建角色，不另建帳號系統：
 *   admin        → handler（可檢視，也可建立／處理事件案）
 *   global-read  → viewer（只能檢視）
 *   其他         → null（一律拒絕）
 * 每支後端 API 都要檢查，不能只靠前端隱藏按鈕（IS-013）。
 */
final class Access
{
    public const VIEWER  = 'viewer';
    public const HANDLER = 'handler';

    public static function level(bool $isAdmin, bool $hasGlobalRead): ?string
    {
        if ($isAdmin) {
            return self::HANDLER;
        }
        return $hasGlobalRead ? self::VIEWER : null;
    }

    public static function canView(?string $level): bool
    {
        return $level === self::VIEWER || $level === self::HANDLER;
    }

    public static function canHandle(?string $level): bool
    {
        return $level === self::HANDLER;
    }
}
