<?php
declare(strict_types=1);

use Ioc\BehaviorAnalyzer;
use Ioc\BehaviorWebhook;

test('行為基線採中位數、P95 與 MAD', static function (): void {
    $s = BehaviorAnalyzer::stats([1, 2, 3, 4]);
    assertSame(2.5, $s['median']);
    assertSame(4.0, $s['p95']);
    assertSame(1.0, $s['mad']);
    assertSame(['median' => 0.0, 'p95' => 0.0, 'mad' => 0.0], BehaviorAnalyzer::stats([]));
});

test('非上班時段流量突增會給可解釋指標', static function (): void {
    $baseline = ['sample_count' => 10, 'p95_bytes' => 1000000, 'p95_destinations' => 3, 'p95_connections' => 5];
    $window = ['quality' => 'complete', 'bytes_total' => 250000000, 'connections' => 100, 'destinations' => 25, 'ports' => 4];
    $findings = BehaviorAnalyzer::evaluate($window, $baseline, true, ['203.0.113.1'], ['8443']);
    assertSame(2, count($findings));
    assertSame('after_hours_surge', $findings[0]['detector']);
    assertSame(250000000, $findings[0]['metrics']['bytes']);
    assertSame('new_external_pattern', $findings[1]['detector']);
    assertSame(1, $findings[1]['metrics']['new_destination_count']);
});

test('資料品質不足、上班時間及樣本不足不能產生警示', static function (): void {
    $baseline = ['sample_count' => 10, 'p95_bytes' => 1000000, 'p95_destinations' => 3, 'p95_connections' => 5];
    $window = ['quality' => 'complete', 'bytes_total' => 250000000, 'connections' => 100, 'destinations' => 25, 'ports' => 4];
    assertSame([], BehaviorAnalyzer::evaluate($window, $baseline, false, [], []));
    $window['quality'] = 'partial';
    assertSame([], BehaviorAnalyzer::evaluate($window, $baseline, true, [], []));
    $window['quality'] = 'complete';
    $baseline['sample_count'] = 5;
    assertSame([], BehaviorAnalyzer::evaluate($window, $baseline, true, [], []));
});

test('例行離峰維運低於基線門檻時不產生警示', static function (): void {
    $baseline = ['sample_count' => 20, 'p95_bytes' => 30000000, 'p95_destinations' => 4, 'p95_connections' => 12];
    $window = ['quality' => 'complete', 'bytes_total' => 12000000, 'connections' => 15,
        'destinations' => 2, 'ports' => 2];
    assertSame([], BehaviorAnalyzer::evaluate($window, $baseline, true, [], []));
});

test('AI 契約只含統計特徵，不含裝置與目的地識別', static function (): void {
    $baseline = ['p95_bytes' => 100, 'p95_connections' => 2];
    $window = ['quality' => 'complete', 'bytes_total' => 300, 'connections' => 4, 'destinations' => 2,
        'ports' => 1, 'src_ip' => '172.16.1.5', 'dst_ip' => '203.0.113.9', 'user' => 'someone'];
    $features = BehaviorAnalyzer::aiFeatures($window, $baseline);
    assertSame(1, $features['schema_version']);
    assertTrue(!isset($features['src_ip']) && !isset($features['dst_ip']) && !isset($features['user']));
});

// ---- 採集器的純邏輯（不碰資料庫）：區間品質、上班時段與小時分桶、警示去重鍵、基線訓練門檻 ----
// 這些規則原本藏在 BehaviorCollector 的私有方法裡，沒有任何測試；抽成 BehaviorAnalyzer 的靜態函式後，
// 之後改規則（警示合併、門檻可調、基線更新）才有東西釘住現行行為。

test('區間品質：沒有該裝置的日誌是 missing（不能補成零流量）；任何必要欄位缺值或分組被截斷都是 partial', static function (): void {
    assertSame('missing', BehaviorAnalyzer::windowQuality(0, false, false, 0, 0, 0));
    assertSame('missing', BehaviorAnalyzer::windowQuality(0, true, true, 0, 0, 0), '沒有日誌就是 missing，不論分組有沒有被截斷');
    assertSame('complete', BehaviorAnalyzer::windowQuality(10, false, false, 10, 10, 10));
    assertSame('complete', BehaviorAnalyzer::windowQuality(10, false, false, 12, 11, 10), '只有「少於」才算缺值');
    assertSame('partial', BehaviorAnalyzer::windowQuality(10, true, false, 10, 10, 10), '任一組分組達上限，所有裝置的這個區間都不可信');
    assertSame('partial', BehaviorAnalyzer::windowQuality(10, false, true, 10, 10, 10), '同一個 IP 對到兩台設備');
    assertSame('partial', BehaviorAnalyzer::windowQuality(10, false, false, 9, 10, 10), '有日誌沒有 net_bytes');
    assertSame('partial', BehaviorAnalyzer::windowQuality(10, false, false, 10, 9, 10), '有日誌沒有目的 IP');
    assertSame('partial', BehaviorAnalyzer::windowQuality(10, false, false, 10, 10, 9), '有日誌沒有目的埠（例如 ICMP）');
});

test('區間品質的原因：partial 說得出具體原因與缺幾筆；missing 與 complete 的說明固定；與品質判斷一致、不超過欄位長度', static function (): void {
    assertSame([], BehaviorAnalyzer::partialReasons(10, false, false, 10, 10, 10));
    assertSame(['3 筆日誌缺 net_bytes'], BehaviorAnalyzer::partialReasons(10, false, false, 7, 10, 10));
    assertSame(['2 筆日誌缺目的 IP'], BehaviorAnalyzer::partialReasons(10, false, false, 10, 8, 10));
    assertSame(['1 筆日誌缺目的埠'], BehaviorAnalyzer::partialReasons(10, false, false, 10, 10, 9));
    assertSame(['同一個 IP 對到多台設備'], BehaviorAnalyzer::partialReasons(10, false, true, 10, 10, 10));
    assertSame(['分組達查詢列數上限，這個區間的資料可能被截斷'], BehaviorAnalyzer::partialReasons(10, true, false, 10, 10, 10));
    assertSame(['分組達查詢列數上限，這個區間的資料可能被截斷', '同一個 IP 對到多台設備', '3 筆日誌缺 net_bytes', '2 筆日誌缺目的 IP', '1 筆日誌缺目的埠'],
        BehaviorAnalyzer::partialReasons(10, true, true, 7, 8, 9), '全部同時發生：照這個順序一一列出');

    assertSame('', BehaviorAnalyzer::windowQualityReason(10, false, false, 10, 10, 10), 'complete 不需要說明');
    assertSame('此裝置沒有觀測到日誌，不能判為零流量', BehaviorAnalyzer::windowQualityReason(0, false, false, 0, 0, 0));
    assertSame('此裝置沒有觀測到日誌，不能判為零流量', BehaviorAnalyzer::windowQualityReason(0, true, true, 0, 0, 0), '沒有日誌就是 missing，不論分組有沒有被截斷');
    assertSame('3 筆日誌缺 net_bytes；1 筆日誌缺目的埠', BehaviorAnalyzer::windowQualityReason(10, false, false, 7, 10, 9));
    $longest = BehaviorAnalyzer::windowQualityReason(PHP_INT_MAX, true, true, 0, 0, 0);       // 五種原因同時發生、筆數是最大的整數
    assertSame(120, mb_strlen($longest), 'ioc_behavior_windows.quality_reason 是 VARCHAR(120)，超過會讓嚴格模式的寫入失敗，所以要截到 120 字');
    assertTrue(strpos($longest, '分組達查詢列數上限') === 0, '截的是尾巴，最前面的原因保留');

    // 不改判斷：品質與原因永遠一致（partial ⇔ 有原因；complete ⇔ 沒有說明）
    foreach ([0, 1, 10] as $count) {
        foreach ([false, true] as $truncated) {
            foreach ([false, true] as $ambiguous) {
                foreach ([-1, 0, 1] as $a) {
                    foreach ([-1, 0, 1] as $b) {
                        foreach ([-1, 0, 1] as $c) {
                            $args = [$count, $truncated, $ambiguous, max(0, $count + $a), max(0, $count + $b), max(0, $count + $c)];
                            $q = BehaviorAnalyzer::windowQuality(...$args);
                            $reasons = BehaviorAnalyzer::partialReasons(...$args);
                            assertSame($q === 'partial', $count > 0 && $reasons !== [], json_encode($args));
                            assertSame($q !== 'complete', BehaviorAnalyzer::windowQualityReason(...$args) !== '', json_encode($args));
                        }
                    }
                }
            }
        }
    }
});

function bhSchedule(string $tz = 'Asia/Taipei', string $workdays = '1,2,3,4,5'): array
{
    return ['timezone' => $tz, 'workdays' => $workdays, 'start_time' => '09:00:00', 'end_time' => '18:00:00'];
}

test('上班時段與分桶：以排程時區換算；結束時間不含；平日／週末分桶固定是週一到五／週六日', static function (): void {
    $cases = [
        // [UTC 區間起點, 預期 day_kind, 預期小時, 預期是否上班]
        ['2026-10-26 01:00:00', 'weekday', 9, true],      // 週一 09:00 台北：上班開始（含）
        ['2026-10-26 00:55:00', 'weekday', 8, false],     // 週一 08:55
        ['2026-10-26 09:55:00', 'weekday', 17, true],     // 週一 17:55
        ['2026-10-26 10:00:00', 'weekday', 18, false],    // 週一 18:00：結束（不含）
        ['2026-10-25 17:00:00', 'weekday', 1, false],     // UTC 週日 17:00 ＝ 台北週一 01:00：日期要換成本地的
        ['2026-10-30 16:00:00', 'weekend', 0, false],     // UTC 週五 16:00 ＝ 台北週六 00:00
    ];
    foreach ($cases as [$start, $kind, $hour, $duty]) {
        $b = BehaviorAnalyzer::localBucket($start, bhSchedule());
        assertSame([$kind, $hour, $duty], [$b['day_kind'], $b['hour'], $b['on_duty']], $start);
    }
    $sat = BehaviorAnalyzer::localBucket('2026-10-31 02:00:00', bhSchedule('Asia/Taipei', '1,2,3,4,5,6'));   // 台北週六 10:00
    assertSame(['weekend', 10, true], [$sat['day_kind'], $sat['hour'], $sat['on_duty']], '週六設為工作日只影響是否上班；基線分桶仍固定把週六算週末');
    // 夏令時間：同一個 UTC 時刻，紐約的本地時間會差一小時
    $edt = BehaviorAnalyzer::localBucket('2026-10-26 13:00:00', bhSchedule('America/New_York'));   // EDT（UTC-4）09:00
    $est = BehaviorAnalyzer::localBucket('2026-11-02 13:00:00', bhSchedule('America/New_York'));   // EST（UTC-5）08:00
    assertSame([9, true, 8, false], [$edt['hour'], $edt['on_duty'], $est['hour'], $est['on_duty']]);
});

test('警示去重鍵：同一資產、偵測器、基線版本、模式在同一個 UTC 小時只有一個；任一項不同就是另一個', static function (): void {
    $base = BehaviorAnalyzer::dedupKey(5, 'after_hours_surge', 2, false, '2026-10-26 10:35:00');
    assertSame($base, BehaviorAnalyzer::dedupKey(5, 'after_hours_surge', 2, false, '2026-10-26 10:00:00'), '同一小時');
    assertSame($base, BehaviorAnalyzer::dedupKey(5, 'after_hours_surge', 2, false, '2026-10-26 10:55:00'), '同一小時');
    assertTrue($base !== BehaviorAnalyzer::dedupKey(5, 'after_hours_surge', 2, false, '2026-10-26 11:00:00'), '下一小時是新的警示');
    assertTrue($base !== BehaviorAnalyzer::dedupKey(5, 'after_hours_surge', 2, true, '2026-10-26 10:35:00'), '模擬與正式不共用');
    assertTrue($base !== BehaviorAnalyzer::dedupKey(5, 'after_hours_surge', 3, false, '2026-10-26 10:35:00'), '基線版本不同');
    assertTrue($base !== BehaviorAnalyzer::dedupKey(6, 'after_hours_surge', 2, false, '2026-10-26 10:35:00'), '資產不同');
    assertTrue($base !== BehaviorAnalyzer::dedupKey(5, 'new_external_pattern', 2, false, '2026-10-26 10:35:00'), '偵測器不同');
    assertSame(64, strlen($base));
});

/**
 * 從 2026-09-01 00:00 UTC（週二）起連續 $days 天、每 5 分鐘一格的完整區間。
 *
 * @param array<string,array<string,int>> $override 以區間起點覆寫欄位
 * @return array<int,array{window_start:string,bytes_total:int,connections:int,destinations:int}>
 */
function bhWindows(int $days, array $override = [], int $connections = 10): array
{
    $rows = [];
    $t = strtotime('2026-09-01 00:00:00 UTC');
    for ($i = 0; $i < $days * 288; $i++) {
        $start = gmdate('Y-m-d H:i:s', $t + $i * 300);
        $rows[] = ['window_start' => $start, 'bytes_total' => 1000, 'connections' => $connections, 'destinations' => 3];
        if (isset($override[$start])) {
            $rows[$i] = $override[$start] + $rows[$i];
        }
    }
    return $rows;
}

test('baselineBuckets：依本地「平日／週末 × 小時」分桶，不做任何門檻', static function (): void {
    $rows = bhWindows(21, [
        '2026-09-01 01:00:00' => ['bytes_total' => 777],     // 台北週二 09:00
        '2026-09-05 17:00:00' => ['bytes_total' => 888],     // UTC 週六 17:00 ＝ 台北週日 01:00
        '2026-09-04 16:00:00' => ['bytes_total' => 999],     // UTC 週五 16:00 ＝ 台北週六 00:00
    ]);
    $g = BehaviorAnalyzer::baselineBuckets($rows, 'Asia/Taipei');
    assertSame(6048, array_sum(array_map('count', $g)), '每一格都要分到某個桶');
    assertSame(48, count($g), '平日／週末 × 24 小時');
    assertTrue(in_array(777, array_column($g['weekday:9'], 'bytes_total'), true), '週二 09:00（台北）進 weekday:9');
    assertTrue(in_array(888, array_column($g['weekend:1'], 'bytes_total'), true), '日期要換成本地的：UTC 週六 17:00 是台北週日 01:00');
    assertTrue(in_array(999, array_column($g['weekend:0'], 'bytes_total'), true), 'UTC 週五 16:00 是台北週六 00:00');
    $utc = BehaviorAnalyzer::baselineBuckets($rows, 'UTC');
    assertTrue(in_array(777, array_column($utc['weekday:1'], 'bytes_total'), true), '同一格換成 UTC 排程就落在別的桶');
    assertSame([], BehaviorAnalyzer::baselineBuckets([], 'Asia/Taipei'));
});

test('baselineCheck：最低資料量（144 格完整區間、12 格有連線）；原因說出缺多少；回報涵蓋天數與離峰時段', static function (): void {
    $sched = ['timezone' => 'Asia/Taipei', 'workdays' => '1,2,3,4,5', 'start_time' => '09:00:00', 'end_time' => '18:00:00'];
    assertSame([144, 12, 6], [BehaviorAnalyzer::BASELINE_MIN_WINDOWS, BehaviorAnalyzer::BASELINE_MIN_ACTIVE_WINDOWS, BehaviorAnalyzer::BASELINE_MIN_SAMPLES]);
    $r143 = BehaviorAnalyzer::baselineCheck(array_slice(bhWindows(1), 0, 143), $sched);
    assertSame([143, false], [$r143['windows'], $r143['ok']]);
    assertTrue(strpos($r143['reasons'][0], '143') !== false && strpos($r143['reasons'][0], '144') !== false, $r143['reasons'][0]);

    // bhWindows 從台北週二 08:00 起：144 格＝08:00–20:00。上班 09:00–18:00，離峰是 08 點與 18、19 點，各 12 格（≥ 6）
    $ok = BehaviorAnalyzer::baselineCheck(array_slice(bhWindows(1), 0, 144), $sched);
    assertSame([144, 144, 1, 3, true, [], []], [$ok['windows'], $ok['active_windows'], $ok['days'], $ok['offhours_buckets'], $ok['ok'], $ok['reasons'], $ok['warnings']]);
    assertSame(3, BehaviorAnalyzer::baselineCheck(bhWindows(2), $sched)['days'], '兩天份的區間橫跨三個本地日期');

    $quiet = array_slice(bhWindows(1, [], 0), 0, 144);
    for ($i = 0; $i < 11; $i++) { $quiet[$i]['connections'] = 5; }
    $q = BehaviorAnalyzer::baselineCheck($quiet, $sched);
    assertSame([11, false], [$q['active_windows'], $q['ok']]);
    assertTrue(strpos($q['reasons'][0], '有連線') !== false && strpos($q['reasons'][0], '11') !== false, $q['reasons'][0]);
    $quiet[11]['connections'] = 5;
    assertSame(true, BehaviorAnalyzer::baselineCheck($quiet, $sched)['ok'], '剛好 12 格');

    // 所有區間都在上班時段：夠資料訓練，但沒有離峰時段的基線，要警告（啟用後不會判斷離峰異常）
    $allDay = ['timezone' => 'Asia/Taipei', 'workdays' => '1,2,3,4,5,6,7', 'start_time' => '00:00:00', 'end_time' => '23:59:00'];
    $none = BehaviorAnalyzer::baselineCheck(array_slice(bhWindows(1), 0, 144), $allDay);
    assertSame([true, 0], [$none['ok'], $none['offhours_buckets']]);
    assertTrue(strpos($none['warnings'][0], '離峰') !== false, $none['warnings'][0]);
});

test('Webhook 設定只接受不含帳密或片段的 HTTPS 與 32 位元組密鑰', static function (): void {
    $secret = str_repeat('ab', 32);
    $valid = BehaviorWebhook::parseConfig("WEBHOOK_URL=https://notify.example.test/ioc\nWEBHOOK_SECRET_HEX=$secret\n");
    assertSame('https://notify.example.test/ioc', $valid['url']);
    assertSame(32, strlen($valid['secret']));
    foreach (['http://notify.example.test/ioc', 'https://u:p@notify.example.test/ioc',
        'https://notify.example.test/ioc#fragment'] as $url) {
        assertSame(null, BehaviorWebhook::parseConfig("WEBHOOK_URL=$url\nWEBHOOK_SECRET_HEX=$secret\n"));
    }
    assertSame(null, BehaviorWebhook::parseConfig("WEBHOOK_URL=https://notify.example.test/ioc\nWEBHOOK_SECRET_HEX=short\n"));
});
