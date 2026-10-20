<?php
declare(strict_types=1);

namespace Ioc;

/** 跨系統時間一律先轉成明確的時間點，顯示時才轉台北時間（INT-072）。Graylog 與事件案資料表都是 UTC。 */
final class Time
{
    /** @param string $tz 沒有帶時區的字串（例如 'Y-m-d H:i:s'）要當成哪個時區；字串自己帶時區（Z、+08:00）就以它為準 */
    public static function parse(?string $iso, string $tz = 'UTC'): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso, new \DateTimeZone($tz)))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /** 經過的秒數 → 「2 天 3 小時」「4 小時 5 分」「12 分鐘」「不到 1 分鐘」（負數當成 0）。 */
    public static function humanDuration(int $seconds): string
    {
        $s = max(0, $seconds);
        if ($s < 60) {
            return '不到 1 分鐘';
        }
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        if ($d > 0) {
            return $d . ' 天 ' . $h . ' 小時';
        }
        return $h > 0 ? $h . ' 小時 ' . $m . ' 分' : $m . ' 分鐘';
    }

    /**
     * ISO 字串或 'Y-m-d H:i:s' → 台北時間；解析不了回傳 '—'。
     * $sourceTz 是沒帶時區的來源字串所屬時區：預設 UTC；LibreNMS 資料表（例如 devices.last_polled）
     * 是主機本地時間，要傳 date_default_timezone_get()。
     */
    public static function toTaipei(?string $utc, string $format = 'Y-m-d H:i:s', string $sourceTz = 'UTC'): string
    {
        $ts = self::parse($utc, $sourceTz);
        if ($ts === null) {
            return '—';
        }
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Taipei'))->format($format);
    }
}
