<?php
declare(strict_types=1);

namespace Ioc;

/** 固定規則的可解釋分析器。AI 擴充僅能讀這份去識別特徵契約。 */
final class BehaviorAnalyzer
{
    public const CONTRACT_VERSION = 1;
    /**
     * 啟用監控時訓練基線的最低資料量（提案值，可調）：完整區間 144 格（約 12 小時）、有連線的區間 12 格；
     * 不足就擋下並說明缺多少。沒有強制的學習天數：資料少只是涵蓋少，沒涵蓋的時段不判斷，不會誤報。
     */
    public const BASELINE_MIN_WINDOWS = 144;
    public const BASELINE_MIN_ACTIVE_WINDOWS = 12;
    /** 一個時段桶至少要有幾個樣本，偵測才會拿它的基線判斷（樣本太少不判斷）。 */
    public const BASELINE_MIN_SAMPLES = 6;

    /** 沒有該裝置的日誌時，區間的說明（全域有日誌不代表這台沒中斷，不能補成零流量）。 */
    public const MISSING_REASON = '此裝置沒有觀測到日誌，不能判為零流量';

    /**
     * 一個五分鐘區間裡，某個來源 IP 的資料品質。沒有日誌是 missing；
     * 分組被截斷、IP 對到兩台設備、或有日誌缺 net_bytes／目的 IP／目的埠（筆數比總連線數少）是 partial；其餘 complete。
     * 只有 complete 的區間會進基線與偵測。partial 的具體原因看 partialReasons()。
     */
    public static function windowQuality(int $count, bool $truncated, bool $ambiguous, int $withBytes, int $destCount, int $portCount): string
    {
        if ($count === 0) { return 'missing'; }
        return self::partialReasons($count, $truncated, $ambiguous, $withBytes, $destCount, $portCount) === [] ? 'complete' : 'partial';
    }

    /**
     * 區間不完整的具體原因（品質判斷的唯一來源：沒有原因才是 complete）。缺欄位的原因會寫出缺幾筆，
     * 維運才分得出是常態（例如 ICMP 沒有目的埠）還是日誌來源出了問題。
     *
     * @return string[] 沒有問題回傳 []；$count 是 0（missing）時沒有意義，呼叫端不要用
     */
    public static function partialReasons(int $count, bool $truncated, bool $ambiguous, int $withBytes, int $destCount, int $portCount): array
    {
        $reasons = [];
        if ($truncated) { $reasons[] = '分組達查詢列數上限，這個區間的資料可能被截斷'; }
        if ($ambiguous) { $reasons[] = '同一個 IP 對到多台設備'; }
        if ($withBytes < $count) { $reasons[] = ($count - $withBytes) . ' 筆日誌缺 net_bytes'; }
        if ($destCount < $count) { $reasons[] = ($count - $destCount) . ' 筆日誌缺目的 IP'; }
        if ($portCount < $count) { $reasons[] = ($count - $portCount) . ' 筆日誌缺目的埠'; }
        return $reasons;
    }

    /** 區間品質的說明，存進 ioc_behavior_windows.quality_reason（VARCHAR(120)）：complete 是空字串。 */
    public static function windowQualityReason(int $count, bool $truncated, bool $ambiguous, int $withBytes, int $destCount, int $portCount): string
    {
        if ($count === 0) { return self::MISSING_REASON; }
        return mb_substr(implode('；', self::partialReasons($count, $truncated, $ambiguous, $withBytes, $destCount, $portCount)), 0, 120);
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
     * 依本地「平日／週末:小時」分桶，鍵像 'weekday:9'。不做任何門檻（門檻見 baselineCheck）。
     *
     * @param array<int,array{window_start:string,connections:mixed}> $rows 完整區間，window_start 是 UTC
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function baselineBuckets(array $rows, string $timezone): array
    {
        $zone = new \DateTimeZone($timezone);
        $groups = [];
        foreach ($rows as $row) {
            $local = self::localTime($row['window_start'], $zone);
            $groups[((int) $local->format('N') <= 5 ? 'weekday' : 'weekend') . ':' . $local->format('G')][] = $row;
        }
        return $groups;
    }

    /**
     * 啟用監控前的資料量檢查與涵蓋度（預覽與訓練共用）。完整區間不足 BASELINE_MIN_WINDOWS、或有連線的區間不足
     * BASELINE_MIN_ACTIVE_WINDOWS 就不達標，原因說出現有幾格、至少需要幾格。涵蓋度：涵蓋幾個本地日期、有幾個離峰時段桶
     * 的樣本夠用（偵測只在離峰時段判斷，所以只算離峰）；一個離峰桶都沒有時警告：啟用後不會判斷離峰異常。
     *
     * @param array<int,array{window_start:string,connections:mixed}> $rows 完整區間，window_start 是 UTC
     * @param array{timezone:string,workdays:string,start_time:string,end_time:string} $schedule
     * @return array{windows:int,active_windows:int,days:int,offhours_buckets:int,min_windows:int,min_active_windows:int,ok:bool,reasons:string[],warnings:string[]}
     */
    public static function baselineCheck(array $rows, array $schedule): array
    {
        $zone = new \DateTimeZone($schedule['timezone']);
        $days = []; $active = 0; $off = [];
        foreach ($rows as $row) {
            $days[self::localTime($row['window_start'], $zone)->format('Y-m-d')] = true;
            if ((int) $row['connections'] > 0) { $active++; }
            $b = self::localBucket($row['window_start'], $schedule);
            if (!$b['on_duty']) {
                $key = $b['day_kind'] . ':' . $b['hour'];
                $off[$key] = ($off[$key] ?? 0) + 1;
            }
        }
        $windows = count($rows);
        $reasons = [];
        if ($windows < self::BASELINE_MIN_WINDOWS) {
            $reasons[] = sprintf('完整區間只有 %d 格，至少需要 %d 格（約 12 小時）', $windows, self::BASELINE_MIN_WINDOWS);
        }
        if ($active < self::BASELINE_MIN_ACTIVE_WINDOWS) {
            $reasons[] = sprintf('有連線的區間只有 %d 格，至少需要 %d 格', $active, self::BASELINE_MIN_ACTIVE_WINDOWS);
        }
        $buckets = count(array_filter($off, static fn (int $n): bool => $n >= self::BASELINE_MIN_SAMPLES));
        $warnings = [];
        if ($reasons === [] && $buckets === 0) {
            $warnings[] = '還沒有任何離峰時段的資料：啟用後不會判斷離峰異常（偵測只看離峰時段）；資料累積到離峰之後可以重新學習再啟用';
        }
        return ['windows' => $windows, 'active_windows' => $active, 'days' => count($days), 'offhours_buckets' => $buckets,
            'min_windows' => self::BASELINE_MIN_WINDOWS, 'min_active_windows' => self::BASELINE_MIN_ACTIVE_WINDOWS,
            'ok' => $reasons === [], 'reasons' => $reasons, 'warnings' => $warnings];
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
        if (($window['quality'] ?? '') !== 'complete' || !$afterHours || (int) ($baseline['sample_count'] ?? 0) < self::BASELINE_MIN_SAMPLES) { return []; }
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
