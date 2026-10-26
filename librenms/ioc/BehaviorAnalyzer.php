<?php
declare(strict_types=1);

namespace Ioc;

/** 固定規則的可解釋分析器。AI 擴充僅能讀這份去識別特徵契約。 */
final class BehaviorAnalyzer
{
    public const CONTRACT_VERSION = 1;
    /** 訓練基線至少要有的完整區間數：28 天 × 288 格 × 75%；缺資料不得視為正常。 */
    public const TRAIN_MIN_WINDOWS = 6048;
    public const TRAIN_MIN_DAYS = 20;
    public const TRAIN_MIN_ACTIVE_DAYS = 7;
    public const TRAIN_MIN_ACTIVE_WINDOWS = 100;

    /**
     * 一個五分鐘區間裡，某個來源 IP 的資料品質。沒有日誌是 missing（全域有日誌不代表這台沒中斷，不能補成零流量）；
     * 分組被截斷、IP 對到兩台設備、或有日誌缺 net_bytes／目的 IP／目的埠（筆數比總連線數少）是 partial；其餘 complete。
     * 只有 complete 的區間會進基線與偵測。
     */
    public static function windowQuality(int $count, bool $truncated, bool $ambiguous, int $withBytes, int $destCount, int $portCount): string
    {
        if ($count === 0) { return 'missing'; }
        return $truncated || $ambiguous || $withBytes < $count || $destCount < $count || $portCount < $count ? 'partial' : 'complete';
    }

    /**
     * 區間起點（UTC）換成排程時區後的分桶與是否在上班時段。上班＝本地星期在工作日內且時間落在 [開始, 結束)。
     * 基線分桶固定把週一到週五算 weekday、週六日算 weekend，與設定的工作日無關。
     *
     * @param array{timezone:string,workdays:string,start_time:string,end_time:string} $schedule
     * @return array{day_kind:string,hour:int,on_duty:bool}
     */
    public static function localBucket(string $windowStartUtc, array $schedule): array
    {
        $local   = self::localTime($windowStartUtc, new \DateTimeZone($schedule['timezone']));
        $weekday = (int) $local->format('N');
        $hm      = $local->format('H:i');
        $onDuty  = in_array((string) $weekday, explode(',', $schedule['workdays']), true)
            && $hm >= substr($schedule['start_time'], 0, 5) && $hm < substr($schedule['end_time'], 0, 5);
        return ['day_kind' => $weekday <= 5 ? 'weekday' : 'weekend', 'hour' => (int) $local->format('G'), 'on_duty' => $onDuty];
    }

    /** 同一資產、偵測器、基線版本、模式（模擬／正式）在同一個 UTC 小時只算一個警示。 */
    public static function dedupKey(int $assetId, string $detector, int $baselineVersion, bool $preview, string $windowStart): string
    {
        return hash('sha256', $assetId . '|' . $detector . '|' . $baselineVersion
            . '|' . ($preview ? 'preview' : 'monitoring') . '|' . substr($windowStart, 0, 13));
    }

    /**
     * 訓練基線前的門檻檢查與分桶。完整區間不足 TRAIN_MIN_WINDOWS、涵蓋的本地日期不足 TRAIN_MIN_DAYS、
     * 有連線的日期不足 TRAIN_MIN_ACTIVE_DAYS、有連線的區間不足 TRAIN_MIN_ACTIVE_WINDOWS 都回傳 null（不訓練）。
     * 達標時依本地「平日／週末:小時」分桶，鍵像 'weekday:9'。
     *
     * @param array<int,array{window_start:string,connections:mixed}> $rows 完整區間，window_start 是 UTC
     * @return array<string,array<int,array<string,mixed>>>|null
     */
    public static function trainingGroups(array $rows, string $timezone): ?array
    {
        if (count($rows) < self::TRAIN_MIN_WINDOWS) { return null; }
        $zone = new \DateTimeZone($timezone);
        $days = []; $activeDays = []; $activeWindows = 0; $groups = [];
        foreach ($rows as $row) {
            $local = self::localTime($row['window_start'], $zone);
            $days[$local->format('Y-m-d')] = true;
            if ((int) $row['connections'] > 0) {
                $activeDays[$local->format('Y-m-d')] = true;
                $activeWindows++;
            }
            $groups[((int) $local->format('N') <= 5 ? 'weekday' : 'weekend') . ':' . $local->format('G')][] = $row;
        }
        if (count($days) < self::TRAIN_MIN_DAYS || count($activeDays) < self::TRAIN_MIN_ACTIVE_DAYS
            || $activeWindows < self::TRAIN_MIN_ACTIVE_WINDOWS) { return null; }
        return $groups;
    }

    private static function localTime(string $utc, \DateTimeZone $zone): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($utc . ' UTC'))->setTimezone($zone);
    }

    /** @param number[] $values */
    public static function stats(array $values): array
    {
        $v = array_map('floatval', $values);
        sort($v, SORT_NUMERIC);
        if (!$v) { return ['median' => 0.0, 'p95' => 0.0, 'mad' => 0.0]; }
        $n = count($v);
        $middle = intdiv($n, 2);
        $median = $n % 2 ? $v[$middle] : ($v[$middle - 1] + $v[$middle]) / 2;
        $deviation = array_map(static fn ($x) => abs($x - $median), $v);
        sort($deviation, SORT_NUMERIC);
        $mad = $n % 2 ? $deviation[$middle] : ($deviation[$middle - 1] + $deviation[$middle]) / 2;
        return ['median' => $median, 'p95' => $v[max(0, (int) ceil($n * 0.95) - 1)], 'mad' => $mad];
    }

    /** @param array<string,mixed> $window @param array<string,mixed> $baseline */
    public static function evaluate(array $window, array $baseline, bool $afterHours, array $newDestinations, array $newPorts): array
    {
        if (($window['quality'] ?? '') !== 'complete' || !$afterHours || (int) ($baseline['sample_count'] ?? 0) < 6) { return []; }
        $bytes = (int) $window['bytes_total'];
        $connections = (int) $window['connections'];
        $destinations = (int) $window['destinations'];
        $byteLimit = max(100 * 1024 * 1024, 5.0 * (float) $baseline['p95_bytes']);
        $destLimit = max(20, 5.0 * (float) $baseline['p95_destinations']);
        $connectionLimit = max(40, 5.0 * (float) $baseline['p95_connections']);
        $out = [];
        if (($bytes > $byteLimit && $connections >= 10) || ($destinations > $destLimit && $connections > $connectionLimit)) {
            $out[] = ['detector' => 'after_hours_surge', 'severity' => $bytes > 5 * $byteLimit ? 'high' : 'medium',
                'score' => min(100, (int) (65 + 10 * log(max(1.0, $bytes / $byteLimit)))),
                'summary' => '非上班時段對外流量或目的地數明顯高於裝置基線',
                'metrics' => ['bytes' => $bytes, 'connections' => $connections, 'destinations' => $destinations,
                    'baseline_p95_bytes' => (float) $baseline['p95_bytes'], 'baseline_p95_destinations' => (float) $baseline['p95_destinations'],
                    'byte_limit' => $byteLimit, 'destination_limit' => $destLimit]];
        }
        if (($newDestinations || $newPorts) && $connections > $connectionLimit && $bytes >= 10 * 1024 * 1024) {
            $out[] = ['detector' => 'new_external_pattern', 'severity' => 'medium', 'score' => 70,
                'summary' => '非上班時段出現新目的地或目的埠且連線量高於基線',
                'metrics' => ['connections' => $connections, 'bytes' => $bytes, 'new_destination_count' => count($newDestinations),
                    'new_port_count' => count($newPorts), 'baseline_p95_connections' => (float) $baseline['p95_connections']]];
        }
        return $out;
    }

    /** 不傳 IP、網域、帳號或原始日誌給日後接入的模型。 */
    public static function aiFeatures(array $window, array $baseline): array
    {
        return ['schema_version' => self::CONTRACT_VERSION, 'connections' => (int) $window['connections'],
            'bytes_total' => (int) $window['bytes_total'], 'destinations' => (int) $window['destinations'],
            'ports' => (int) $window['ports'], 'baseline_p95_bytes' => (float) $baseline['p95_bytes'],
            'baseline_p95_connections' => (float) $baseline['p95_connections'], 'quality' => (string) $window['quality']];
    }
}
