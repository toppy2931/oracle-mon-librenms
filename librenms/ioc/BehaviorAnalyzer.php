<?php
declare(strict_types=1);

namespace Ioc;

/** 固定規則的可解釋分析器。AI 擴充僅能讀這份去識別特徵契約。 */
final class BehaviorAnalyzer
{
    public const CONTRACT_VERSION = 1;

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
