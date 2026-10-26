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

test('基線訓練門檻：少於 6048 個完整區間不訓練；達標後依本地「平日／週末 × 小時」分桶', static function (): void {
    assertSame(6048, BehaviorAnalyzer::TRAIN_MIN_WINDOWS);
    assertSame(null, BehaviorAnalyzer::trainingGroups(array_slice(bhWindows(21), 0, 6047), 'Asia/Taipei'), '差一格');
    $rows = bhWindows(21, [
        '2026-09-01 01:00:00' => ['bytes_total' => 777],     // 台北週二 09:00
        '2026-09-05 17:00:00' => ['bytes_total' => 888],     // UTC 週六 17:00 ＝ 台北週日 01:00
        '2026-09-04 16:00:00' => ['bytes_total' => 999],     // UTC 週五 16:00 ＝ 台北週六 00:00
    ]);
    assertSame(6048, count($rows));
    $g = BehaviorAnalyzer::trainingGroups($rows, 'Asia/Taipei');
    assertTrue($g !== null, '剛好 6048 格、橫跨 22 個本地日期、全部有連線：達標');
    assertSame(6048, array_sum(array_map('count', $g)), '每一格都要分到某個桶');
    assertSame(48, count($g), '平日／週末 × 24 小時');
    assertTrue(in_array(777, array_column($g['weekday:9'], 'bytes_total'), true), '週二 09:00（台北）進 weekday:9');
    assertTrue(in_array(888, array_column($g['weekend:1'], 'bytes_total'), true), '日期要換成本地的：UTC 週六 17:00 是台北週日 01:00');
    assertTrue(in_array(999, array_column($g['weekend:0'], 'bytes_total'), true), 'UTC 週五 16:00 是台北週六 00:00');
    $utc = BehaviorAnalyzer::trainingGroups($rows, 'UTC');
    assertTrue(in_array(777, array_column($utc['weekday:1'], 'bytes_total'), true), '同一格換成 UTC 排程就落在別的桶');
});

test('基線訓練門檻：有效天數、有連線的天數與區間數不足也不訓練', static function (): void {
    // 全部沒有連線：沒有任何「有連線的區間」
    assertSame(null, BehaviorAnalyzer::trainingGroups(bhWindows(21, [], 0), 'Asia/Taipei'));
    // 有連線的日期夠（7 天，每天 1 個區間），但有連線的區間只有 7 個，少於 100
    $few = bhWindows(21, [], 0);
    for ($day = 0; $day < 7; $day++) { $few[$day * 288 + 100]['connections'] = 5; }
    assertSame(null, BehaviorAnalyzer::trainingGroups($few, 'Asia/Taipei'), '有連線的區間少於 100');
    // 100 個區間有連線，但只集中在 2 個本地日期
    $two = bhWindows(21, [], 0);
    for ($i = 0; $i < 100; $i++) { $two[$i * 2]['connections'] = 5; }            // 前 200 格 ≈ 16 小時，跨兩個台北日期
    assertSame(null, BehaviorAnalyzer::trainingGroups($two, 'Asia/Taipei'), '有連線的日期少於 7 天');
    // 100 個區間有連線、分散在 7 個本地日期：達標
    $seven = bhWindows(21, [], 0);
    for ($i = 0; $i < 100; $i++) { $seven[($i % 7) * 288 + intdiv($i, 7)]['connections'] = 5; }
    assertTrue(BehaviorAnalyzer::trainingGroups($seven, 'Asia/Taipei') !== null, '7 個日期、100 個有連線的區間');
    // 區間數夠、有連線的日期也夠（11 天），但涵蓋的日期不到 20 天（10 天的資料重複 3 次；真實資料有唯一鍵，不會發生，門檻仍要成立）
    $short = [];
    foreach (range(1, 3) as $_) { $short = array_merge($short, bhWindows(10)); }
    assertTrue(count($short) >= BehaviorAnalyzer::TRAIN_MIN_WINDOWS);
    assertSame(null, BehaviorAnalyzer::trainingGroups($short, 'Asia/Taipei'), '涵蓋的日期少於 20');
});

test('combineTraffic：多個位址的流量併成一筆；單一有流量的位址沿用日誌端的基數；沒流量的位址不影響結果', static function (): void {
    $part = static fn (int $count, int $bytes, array $dest, array $ports, array $o = []): array => $o + ['count' => $count, 'bytes' => $bytes, 'with_bytes' => $count,
        'dest_count' => $count, 'port_count' => $count, 'card_dst' => count($dest), 'card_port' => count($ports), 'dest_list' => $dest, 'port_list' => $ports, 'ambiguous' => false];
    $a = $part(20, 5000, ['203.0.113.1', '203.0.113.2'], [443]);
    $b = $part(10, 3000, ['203.0.113.2', '203.0.113.3'], [443, 80]);
    $silent = $part(0, 0, [], []);

    $t = BehaviorAnalyzer::combineTraffic([$a, $b]);
    assertSame([30, 8000, 3, 2, 30, 30, 30, false], [$t['connections'], $t['bytes'], $t['destinations'], $t['ports'], $t['with_bytes'], $t['dest_count'], $t['port_count'], $t['ambiguous']]);
    assertSame(['203.0.113.1', '203.0.113.2', '203.0.113.3'], $t['dest_list']);
    assertSame([443, 80], $t['port_list']);

    $single = BehaviorAnalyzer::combineTraffic([$silent, $b]);
    assertSame([10, 3000, 2, 2], [$single['connections'], $single['bytes'], $single['destinations'], $single['ports']], '只有一個位址有流量：和只有一個位址的資產完全一樣');
    $card = $part(10, 3000, ['203.0.113.2'], [443], ['card_dst' => 7, 'card_port' => 5]);
    $c = BehaviorAnalyzer::combineTraffic([$card, $silent]);
    assertSame([7, 5], [$c['destinations'], $c['ports']], '單一位址：沿用日誌端算好的基數，不是清單長度');

    $none = BehaviorAnalyzer::combineTraffic([$silent, $silent]);
    assertSame([0, 0, 0, 0, []], [$none['connections'], $none['bytes'], $none['destinations'], $none['ports'], $none['dest_list']]);
    assertSame(0, BehaviorAnalyzer::combineTraffic([])['connections']);
    assertSame(true, BehaviorAnalyzer::combineTraffic([$a, $part(1, 1, [], [], ['ambiguous' => true])])['ambiguous'], '任何一個位址有衝突，整筆就有衝突');
    assertSame('complete', BehaviorAnalyzer::windowQuality($t['connections'], false, $t['ambiguous'], $t['with_bytes'], $t['dest_count'], $t['port_count']));
    $partial = BehaviorAnalyzer::combineTraffic([$a, $part(10, 3000, ['203.0.113.2'], [443], ['with_bytes' => 9])]);
    assertSame('partial', BehaviorAnalyzer::windowQuality($partial['connections'], false, $partial['ambiguous'], $partial['with_bytes'], $partial['dest_count'], $partial['port_count']), '缺的筆數逐項相加：一個位址缺位元組，整筆就是 partial');
});

test('combineTraffic：top_ip 是連線數最多的位址（打平取前面的）；沒有流量的位址不算；全都沒流量是 null；沒帶位址也不會出錯', static function (): void {
    $part = static fn (string $ip, int $count): array => ['ip' => $ip, 'count' => $count, 'bytes' => 0, 'with_bytes' => $count, 'dest_count' => $count, 'port_count' => $count,
        'card_dst' => 0, 'card_port' => 0, 'dest_list' => [], 'port_list' => [], 'ambiguous' => false];
    assertSame('172.16.1.77', BehaviorAnalyzer::combineTraffic([$part('172.16.1.10', 3), $part('172.16.1.77', 9), $part('172.16.1.88', 0)])['top_ip']);
    assertSame('172.16.1.10', BehaviorAnalyzer::combineTraffic([$part('172.16.1.10', 5), $part('172.16.1.77', 5)])['top_ip'], '打平：取前面的');
    assertSame(null, BehaviorAnalyzer::combineTraffic([$part('172.16.1.10', 0), $part('172.16.1.77', 0)])['top_ip']);
    assertSame(null, BehaviorAnalyzer::combineTraffic([])['top_ip']);
    $noIp = $part('x', 4);
    unset($noIp['ip']);
    assertSame(null, BehaviorAnalyzer::combineTraffic([$noIp])['top_ip'], '呼叫端沒帶位址：不回報，也不丟錯');
});

test('mergeWindowRows：連線與位元組相加、目的地與埠取聯集；兩邊都完整才完整；原因合併後不超過欄位長度', static function (): void {
    $row = static fn (string $q, int $c, int $b, array $d, array $p, string $r = '', string $obs = '2026-10-20 10:00:00'): array => ['connections' => $c, 'bytes_total' => $b,
        'destinations_json' => json_encode($d), 'ports_json' => json_encode($p), 'quality' => $q, 'quality_reason' => $r, 'observed_at' => $obs];
    $m = BehaviorAnalyzer::mergeWindowRows($row('complete', 5, 500, ['3.3.3.3'], [443]), $row('complete', 7, 700, ['3.3.3.3', '4.4.4.4'], [443, 8080], '', '2026-10-21 10:00:00'));
    assertSame([12, 1200, 2, 2, 'complete', '', '2026-10-21 10:00:00'], [$m['connections'], $m['bytes_total'], $m['destinations'], $m['ports'], $m['quality'], $m['quality_reason'], $m['observed_at']]);
    assertSame(['3.3.3.3', '4.4.4.4'], json_decode($m['destinations_json'], true));
    assertSame([443, 8080], json_decode($m['ports_json'], true), '埠仍是數字');

    $p = BehaviorAnalyzer::mergeWindowRows($row('partial', 3, 300, ['6.6.6.6'], [443], '1 筆日誌缺 net_bytes'), $row('complete', 4, 400, ['6.6.6.6'], [443]));
    assertSame('partial', $p['quality']);
    assertSame('合併了兩個位址的區間：1 筆日誌缺 net_bytes', $p['quality_reason']);
    $both = BehaviorAnalyzer::mergeWindowRows($row('partial', 1, 1, [], [], '甲'), $row('partial', 1, 1, [], [], '甲'));
    assertSame('合併了兩個位址的區間：甲', $both['quality_reason'], '相同的原因不重複');
    $long = BehaviorAnalyzer::mergeWindowRows($row('partial', 1, 1, [], [], str_repeat('長', 100)), $row('partial', 1, 1, [], [], str_repeat('短', 100)));
    assertTrue(mb_strlen($long['quality_reason']) <= 120, '欄位是 VARCHAR(120)，超過在嚴格模式會讓寫入失敗');
    assertSame('partial', BehaviorAnalyzer::mergeWindowRows($row('complete', 1, 1, [], []), $row('partial', 1, 1, [], [], 'x'))['quality']);
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
