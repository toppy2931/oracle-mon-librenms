<?php
declare(strict_types=1);

/**
 * BehaviorCollector 與 BehaviorWebhook 的資料庫行為（真的 MariaDB，一次性的 ioc_test_* schema；見 tests/run-db-tests.sh）。
 * Graylog 用假的（DbtFakeLogs），所以可以精確擺出每個五分鐘區間的流量。
 * 名稱標「已知限制」的測試釘住的是目前實際的行為，不是理想的行為：改掉那個限制時，這個測試要一起改。
 */

use Ioc\Access;
use Ioc\BehaviorCollector;
use Ioc\BehaviorService;
use Ioc\BehaviorWebhook;

const BCD_SRC = 'fortigate_graylog';

/** @return string[] */
function bcdNets(): array
{
    return ['172.16.0.0/16'];
}

/** @return array{id:int,name:string,ip:?string,site:?string} */
function bcdDevice(int $id = 1, string $ip = '172.16.1.10', ?string $site = '台南', string $name = 'fw-a'): array
{
    return ['id' => $id, 'name' => $name, 'ip' => $ip, 'site' => $site];
}

/** 這個 UTC 時間所屬的「上一個完整五分鐘區間」就是 tick 要處理的區間。 */
function bcdNow(string $utc = '2026-10-26 12:07:30'): int
{
    return strtotime($utc . ' UTC');
}

/** @return array<string,mixed> */
function bcdSourceRow(PDO $db): array
{
    return dbtRow($db, 'SELECT * FROM ioc_behavior_sources WHERE source_key=?', [BCD_SRC]) ?? [];
}

/** @return array<int,array<string,mixed>> */
function bcdWindows(PDO $db, ?int $assetId = null): array
{
    return $assetId === null
        ? dbtAll($db, 'SELECT * FROM ioc_behavior_windows ORDER BY window_start,asset_id')
        : dbtAll($db, 'SELECT * FROM ioc_behavior_windows WHERE asset_id=? ORDER BY window_start', [$assetId]);
}

/** 一個一般的、品質完整的區間流量（每一筆日誌都有 net_bytes、目的 IP 與目的埠）。 */
function bcdNormal(string $ip = '172.16.1.10'): array
{
    return [$ip => ['count' => 20, 'bytes' => 5000000, 'dest' => ['203.0.113.1' => 12, '203.0.113.2' => 8], 'port' => ['443' => 20]]];
}

/** 離峰的大流量：25 個目的 IP、4 個埠、2.5 億位元組、100 筆連線。 */
function bcdSurge(string $ip = '172.16.1.10'): array
{
    $dest = [];
    for ($i = 1; $i <= 25; $i++) {
        $dest['203.0.113.' . $i] = 4;
    }
    return [$ip => ['count' => 100, 'bytes' => 250000000, 'dest' => $dest, 'port' => ['443' => 25, '8443' => 25, '22' => 25, '25' => 25]]];
}

/**
 * 擺出「已有基線、有排程」的設備 1（台北、週一到五 09:00–18:00），基線涵蓋週間 10／20／21 點
 * （2026-10-26 週一 02:00 UTC＝台北 10:00 上班；12:00 UTC＝20:00、13:00 UTC＝21:00 離峰）。
 */
function bcdMonitored(PDO $db, string $mode, int $sampleCount = 10): int
{
    $id = (new BehaviorService($db))->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    $db->prepare("UPDATE ioc_behavior_assets SET mode=?,baseline_version=1,quality_status='ready',learning_started_at=? WHERE id=?")
        ->execute([$mode, gmdate('Y-m-d H:i:s', time() - 30 * 86400), $id]);
    dbtSchedule($db, 'device', '1');
    foreach ([10, 20, 21] as $hour) {
        $db->prepare("INSERT INTO ioc_behavior_baselines (asset_id,version,source_key,day_kind,hour_of_day,median_bytes,p95_bytes,mad_bytes,median_connections,p95_connections,mad_connections,median_destinations,p95_destinations,mad_destinations,sample_count,created_at)
            VALUES (?,1,'fortigate_graylog','weekday',?,500000,1000000,1,3,5,1,2,3,1,?,UTC_TIMESTAMP())")->execute([$id, $hour, $sampleCount]);
    }
    return $id;
}

/** 一次寫很多個完整區間（從 $firstTs 起每 5 分鐘一格）。 */
function bcdBulkWindows(PDO $db, int $assetId, int $count, int $firstTs, int $connections = 10): void
{
    for ($off = 0; $off < $count; $off += 500) {
        $n = min(500, $count - $off);
        $ph = [];
        $args = [];
        for ($i = 0; $i < $n; $i++) {
            $ph[] = "(?,'fortigate_graylog',?,?,1000,3,1,'[]','[]','{}','complete','',UTC_TIMESTAMP())";
            array_push($args, $assetId, gmdate('Y-m-d H:i:s', $firstTs + ($off + $i) * 300), $connections);
        }
        $db->prepare('INSERT INTO ioc_behavior_windows (asset_id,source_key,window_start,connections,bytes_total,destinations,ports,destinations_json,ports_json,signals_json,quality,quality_reason,observed_at) VALUES ' . implode(',', $ph))->execute($args);
    }
}

// ---- 來源狀態與資料取不到 ----

dbtest('tick：沒有內部網段 → 來源 error、不查 Graylog、不寫區間（設備資產照常建立）', static function (PDO $db): void {
    $logs = DbtFakeLogs::traffic(bcdNormal());
    (new BehaviorCollector($db, $logs))->tick([bcdDevice()], [], bcdNow());
    assertSame(0, count($logs->calls));
    assertSame(['error', '內部網段未設定', null], [bcdSourceRow($db)['status'], bcdSourceRow($db)['reason'], bcdSourceRow($db)['last_window_at']]);
    assertSame([], bcdWindows($db));
    assertSame(1, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key='librenms:1'"));
});

dbtest('tick：整個區間沒有任何防火牆日誌 → missing（不是零流量）、不寫區間、下一輪還會重試同一個區間', static function (PDO $db): void {
    $logs = DbtFakeLogs::traffic([], 0);
    $c = new BehaviorCollector($db, $logs);
    $c->tick([bcdDevice()], bcdNets(), bcdNow());
    $s = bcdSourceRow($db);
    assertSame('missing', $s['status']);
    assertTrue(strpos($s['reason'], '不能視為零流量') !== false, $s['reason']);
    assertSame([null, 0], [$s['last_window_at'], count(bcdWindows($db))]);
    assertSame(1, count($logs->calls), '只問了健康檢查就停下');
    $c->tick([bcdDevice()], bcdNets(), bcdNow());
    assertSame(2, count($logs->calls), '區間沒處理完（last_window_at 沒前進），下一輪會再問一次');
});

dbtest('tick：Graylog 查詢失敗 → 來源 error（只寫固定說明，不帶例外訊息）、不寫區間', static function (PDO $db): void {
    $logs = DbtFakeLogs::traffic(bcdNormal());
    $logs->failWith = 'secret-host.example:9000 連線逾時';
    (new BehaviorCollector($db, $logs))->tick([bcdDevice()], bcdNets(), bcdNow());
    $s = bcdSourceRow($db);
    assertSame(['error', 'Graylog 查詢失敗或逾時', null], [$s['status'], $s['reason'], $s['last_window_at']]);
    assertSame([], bcdWindows($db));
});

// ---- 區間的寫入 ----

dbtest('tick：寫入完整區間——設備與未知內部 IP 各一筆，外部來源 IP 忽略；查詢用絕對時間並排除範例資料', static function (PDO $db): void {
    $traffic = bcdNormal() + ['172.16.9.9' => ['count' => 4, 'bytes' => 1000, 'dest' => ['198.51.100.7' => 4], 'port' => ['53' => 4]],
        '8.8.8.8' => ['count' => 3, 'bytes' => 10, 'dest' => ['203.0.113.9' => 3], 'port' => ['443' => 3]]];
    $logs = DbtFakeLogs::traffic($traffic);
    (new BehaviorCollector($db, $logs))->tick([bcdDevice()], bcdNets(), bcdNow());

    assertSame(5, count($logs->calls), '健康檢查＋四種彙整');
    foreach ($logs->calls as $call) {
        assertSame(['2026-10-26T12:00:00Z', '2026-10-26T12:05:00Z'], [$call['from'], $call['to']]);
        assertTrue(strpos($call['query'], 'NOT ioc_demo:true') !== false, '一定要排除範例資料：' . $call['query']);
    }
    $s = bcdSourceRow($db);
    assertSame(['ready', '', '2026-10-26 12:00:00'], [$s['status'], $s['reason'], $s['last_window_at']]);

    $dev = (int) dbtVal($db, "SELECT id FROM ioc_behavior_assets WHERE identity_key='librenms:1'");
    $w = bcdWindows($db, $dev);
    assertSame(1, count($w));
    assertSame([20, 5000000, 2, 1, 'complete', BCD_SRC, '2026-10-26 12:00:00'],
        [(int) $w[0]['connections'], (int) $w[0]['bytes_total'], (int) $w[0]['destinations'], (int) $w[0]['ports'], $w[0]['quality'], $w[0]['source_key'], $w[0]['window_start']]);
    assertSame(['203.0.113.1', '203.0.113.2'], json_decode($w[0]['destinations_json'], true));
    assertSame([443], json_decode($w[0]['ports_json'], true), '埠存成數字（PHP 陣列鍵）');

    $temp = dbtRow($db, "SELECT id,name FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:172.16.9.9:%'");
    assertTrue($temp !== null, '未知的內部 IP 建暫時資產');
    assertSame('complete', bcdWindows($db, (int) $temp['id'])[0]['quality']);
    assertSame(2, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_assets'), '外部來源 IP 8.8.8.8 不建資產');
    assertSame(2, count(bcdWindows($db)));
    assertTrue(dbtVal($db, 'SELECT last_seen_at FROM ioc_behavior_assets WHERE id=?', [$dev]) !== null, '有流量就更新最後看到時間');
});

dbtest('tick：同一個區間不重複處理；下一個區間才寫新的一筆', static function (PDO $db): void {
    $logs = DbtFakeLogs::traffic(bcdNormal());
    $c = new BehaviorCollector($db, $logs);
    $c->tick([bcdDevice()], bcdNets(), bcdNow());
    $c->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:09:59'));
    assertSame(5, count($logs->calls), '同一個五分鐘區間（12:00–12:05）已處理完：不再查 Graylog');
    assertSame(1, count(bcdWindows($db)));
    $c->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:12:30'));
    assertSame(10, count($logs->calls));
    assertSame(['2026-10-26 12:00:00', '2026-10-26 12:05:00'], array_column(bcdWindows($db), 'window_start'));
    assertSame('2026-10-26 12:05:00', bcdSourceRow($db)['last_window_at']);
});

dbtest('區間品質：缺欄位是 partial、設備沒日誌是 missing；partial 與 missing 都不評估、來源與資產品質跟著標', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    $partial = bcdNormal();
    $partial['172.16.1.10']['with_bytes'] = 19;                              // 有一筆日誌沒有 net_bytes
    (new BehaviorCollector($db, DbtFakeLogs::traffic($partial)))->tick([bcdDevice()], bcdNets(), bcdNow());
    $w = bcdWindows($db, $id);
    assertSame('partial', $w[0]['quality']);
    assertSame('1 筆日誌缺 net_bytes', $w[0]['quality_reason'], '要說得出是哪一種缺值、缺幾筆');
    assertSame(['partial', 'partial'], [bcdSourceRow($db)['status'], dbtVal($db, 'SELECT quality_status FROM ioc_behavior_assets WHERE id=?', [$id])]);

    // 下一個區間：全域有日誌（別的 IP），但這台設備沒有 → missing，不能補成零流量
    $other = ['172.16.7.7' => ['count' => 5, 'bytes' => 1, 'dest' => ['203.0.113.5' => 5], 'port' => ['80' => 5]]];
    (new BehaviorCollector($db, DbtFakeLogs::traffic($other)))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:12:30'));
    $w = bcdWindows($db, $id);
    assertSame(['partial', 'missing', 0], [$w[0]['quality'], $w[1]['quality'], (int) $w[1]['connections']]);
    assertSame('此裝置沒有觀測到日誌，不能判為零流量', $w[1]['quality_reason']);
    assertSame('missing', dbtVal($db, 'SELECT quality_status FROM ioc_behavior_assets WHERE id=?', [$id]));
    assertSame('ready', bcdSourceRow($db)['status'], '一台設備沒日誌不代表來源有問題');
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'));
});

dbtest('區間品質的原因：缺目的 IP、缺目的埠各自記缺幾筆（例如 ICMP 沒有目的埠）；complete 沒有說明', static function (PDO $db): void {
    $id = (new BehaviorService($db))->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    $t = bcdNormal();                                                       // 20 筆連線
    $t['172.16.1.10']['dest'] = ['203.0.113.1' => 10, '203.0.113.2' => 8];  // 只有 18 筆有目的 IP
    $t['172.16.1.10']['port'] = ['443' => 19];                              // 只有 19 筆有目的埠
    (new BehaviorCollector($db, DbtFakeLogs::traffic($t)))->tick([bcdDevice()], bcdNets(), bcdNow());
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdNormal())))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:12:30'));
    $w = bcdWindows($db, $id);
    assertSame(['partial', '2 筆日誌缺目的 IP；1 筆日誌缺目的埠'], [$w[0]['quality'], $w[0]['quality_reason']]);
    assertSame(['complete', ''], [$w[1]['quality'], $w[1]['quality_reason']]);
});

dbtest('區間品質：任何一組分組達到 500 列上限，這個區間所有設備都是 partial（資料可能被截斷）', static function (PDO $db): void {
    $traffic = bcdNormal();
    for ($i = 1; $i < 500; $i++) {
        $traffic['172.16.' . (10 + intdiv($i, 250)) . '.' . ($i % 250 + 1)] = ['count' => 1, 'bytes' => 1, 'dest' => ['203.0.113.1' => 1], 'port' => ['443' => 1]];
    }
    assertSame(500, count($traffic));
    (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick([bcdDevice()], bcdNets(), bcdNow());
    assertSame('partial', bcdSourceRow($db)['status']);
    $qualities = array_unique(array_column(bcdWindows($db), 'quality'));
    assertSame(['partial'], $qualities, '沒有任何一台的區間是 complete');
    assertSame(500, count(bcdWindows($db)));
    assertSame(['分組達查詢列數上限，這個區間的資料可能被截斷'], array_unique(array_column(bcdWindows($db), 'quality_reason')), '原因是分組被截斷，不是這些設備各自缺欄位');
});

dbtest('區間品質：兩台 LibreNMS 設備共用同一個 IP → 第一台的區間是 partial；已退役的資產不寫區間', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $devices = [bcdDevice(1, '172.16.1.10', '台南', 'fw-a'), bcdDevice(2, '172.16.1.10', '台南', 'fw-b'), bcdDevice(3, '172.16.1.30', '台南', 'fw-c')];
    $retired = $svc->syncDevice(3, 'fw-c', '172.16.1.30', '台南');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$retired]);
    $traffic = bcdNormal() + bcdNormal('172.16.1.30');
    (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick($devices, bcdNets(), bcdNow());
    $first = (int) dbtVal($db, "SELECT id FROM ioc_behavior_assets WHERE identity_key='librenms:1'");
    $second = (int) dbtVal($db, "SELECT id FROM ioc_behavior_assets WHERE identity_key='librenms:2'");
    assertSame('partial', bcdWindows($db, $first)[0]['quality'], '位址衝突：不知道流量是誰的');
    assertSame('同一個 IP 對到多台設備', bcdWindows($db, $first)[0]['quality_reason']);
    assertSame([], bcdWindows($db, $second), '已知限制：共用 IP 的第二台設備永遠沒有區間（流量只會對到第一台）');
    assertSame([], bcdWindows($db, $retired), '已退役的資產不寫區間');
    assertSame(0, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:%'"), '退役設備的 IP 也不會被當成未知 IP 再建暫時資產');
});

// ---- 學習與警示 ----

dbtest('學習中（或重新學習中）只記已知目的地與埠、不產生警示，即使流量暴增', static function (PDO $db): void {
    $id = (new BehaviorService($db))->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    $c = new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge()));
    $c->tick([bcdDevice()], bcdNets(), bcdNow());
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'));
    assertSame([25, 4], [(int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_known WHERE asset_id=? AND kind='destination'", [$id]),
        (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_known WHERE asset_id=? AND kind='port'", [$id])]);
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge())))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:12:30'));
    assertSame(2, (int) dbtVal($db, "SELECT MAX(seen_count) FROM ioc_behavior_known WHERE asset_id=?", [$id]), '再看到一次：次數累加');

    // 重新學習：有舊版基線但模式是 learning，不能拿舊基線判斷
    $db->prepare("UPDATE ioc_behavior_assets SET baseline_version=1 WHERE id=?")->execute([$id]);
    dbtSchedule($db, 'device', '1');
    $db->prepare("INSERT INTO ioc_behavior_baselines (asset_id,version,source_key,day_kind,hour_of_day,median_bytes,p95_bytes,mad_bytes,median_connections,p95_connections,mad_connections,median_destinations,p95_destinations,mad_destinations,sample_count,created_at)
        VALUES (?,1,'fortigate_graylog','weekday',21,1,1,1,1,1,1,1,1,1,10,UTC_TIMESTAMP())")->execute([$id]);
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge())))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 13:07:30'));
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'), '重新學習期間不產生警示');
});

dbtest('就緒但尚未啟用監控：只產生模擬（preview）警示——沒有事件案、沒有通知；已知目的地之後不再算新的', static function (PDO $db): void {
    $id = bcdMonitored($db, 'ready');
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge())))->tick([bcdDevice()], bcdNets(), bcdNow());
    $alerts = dbtAll($db, 'SELECT * FROM ioc_behavior_alerts ORDER BY id');
    assertSame(['after_hours_surge', 'new_external_pattern'], array_column($alerts, 'detector'));
    assertSame(['preview', 'preview'], array_column($alerts, 'status'));
    assertSame(['medium', 'medium'], array_column($alerts, 'severity'));
    assertSame([73, 70], array_map('intval', array_column($alerts, 'score')));
    assertSame(1, (int) $alerts[0]['baseline_version']);
    assertSame(250000000, json_decode($alerts[0]['metrics_json'], true)['bytes']);
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_cases'));
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_deliveries'));
    assertSame(29, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_known WHERE asset_id=?', [$id]), '評估之後才學習：25 個目的地＋4 個埠');
});

dbtest('監控中：警示（open）自動建事件案並排入通知；同一小時重複只累計次數，下一小時另開新的（已知限制：不會合併成同一件）', static function (PDO $db): void {
    $id = bcdMonitored($db, 'monitoring');
    $tick = static fn (string $now, array $traffic) => (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick([bcdDevice()], bcdNets(), bcdNow($now));
    $tick('2026-10-26 12:07:30', bcdSurge());

    $alerts = dbtAll($db, 'SELECT * FROM ioc_behavior_alerts ORDER BY id');
    assertSame(['open', 'open'], array_column($alerts, 'status'));
    assertTrue($alerts[0]['case_id'] !== null && $alerts[1]['case_id'] !== null, '每個警示一件事件案');
    $cases = dbtAll($db, 'SELECT * FROM ioc_cases ORDER BY id');
    assertSame(2, count($cases));
    assertSame(['medium', '172.16.1.10', 'fw-a', 'automation', 'behavior:' . $alerts[0]['dedup_key']],
        [$cases[0]['severity'], $cases[0]['subject_ip'], $cases[0]['subject_host'], $cases[0]['source'], $cases[0]['dedup_key']]);
    assertTrue(strpos($cases[0]['title'], '裝置行為異常：') === 0);
    assertSame(['queued', 'queued'], array_column(dbtAll($db, 'SELECT status FROM ioc_behavior_deliveries ORDER BY alert_id'), 'status'));

    $tick('2026-10-26 12:12:30', bcdSurge());                                    // 同一個 UTC 小時的下一個區間
    $alerts = dbtAll($db, 'SELECT detector,occurrences,window_start FROM ioc_behavior_alerts ORDER BY id');
    assertSame([['after_hours_surge', 2, '2026-10-26 12:05:00'], ['new_external_pattern', 1, '2026-10-26 12:00:00']],
        array_map(fn ($a) => [$a['detector'], (int) $a['occurrences'], $a['window_start']], $alerts),
        '目的地第一次就已學習，第二個區間不再算新的；突增那筆累計 2 次');
    assertSame(2, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_cases'));
    assertSame(2, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_deliveries'));

    $tick('2026-10-26 13:07:30', bcdSurge());                                    // 下一個 UTC 小時
    assertSame(3, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'), '持續的異常每小時重新開一個警示');
    assertSame(3, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_cases'), '…也重新開一件事件案');
    assertSame(3, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_deliveries'), '…也重新送一次通知');
    unset($id);
});

dbtest('不該有警示的情況：上班時段、基線樣本不足、沒有對應的基線時段、沒有排程；但有基線時仍會學習已知目的地（沒排程除外）', static function (PDO $db): void {
    $id = bcdMonitored($db, 'monitoring');
    $at = static fn (string $now) => (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge())))->tick([bcdDevice()], bcdNets(), bcdNow($now));

    $at('2026-10-26 02:07:30');                                                   // 台北週一 10:00：上班
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'), '上班時段不判斷');
    assertSame(29, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_known WHERE asset_id=?', [$id]));

    $db->exec('UPDATE ioc_behavior_baselines SET sample_count=5');
    $at('2026-10-26 12:07:30');
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'), '基線樣本少於 6 不判斷');

    $db->exec('UPDATE ioc_behavior_baselines SET sample_count=10');
    $at('2026-10-31 12:07:30');                                                   // 台北週六 20:00：只有週間的基線
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'), '沒有對應的基線時段（週末）不判斷');

    $db->exec('DELETE FROM ioc_behavior_known');
    $db->exec('DELETE FROM ioc_behavior_schedules');
    $at('2026-11-02 12:07:30');                                                   // 週一 20:00，但沒有排程
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'), '沒有排程就沒辦法判斷上班與否');
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_known'), '沒有排程時連已知目的地也不學（直接略過）');
});

dbtest('repairOpenCases：補建沒有事件案的未處置警示（一次最多 5 個，不重複建立；模擬與已處置的不補）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    $open = [];
    for ($i = 0; $i < 7; $i++) {
        $open[] = dbtAlert($db, $id);
    }
    dbtAlert($db, $id, 'preview');
    dbtAlert($db, $id, 'acknowledged');
    $c = new BehaviorCollector($db, DbtFakeLogs::traffic([]));
    $c->repairOpenCases();
    assertSame(5, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_cases'), '一次最多補 5 件');
    assertSame(5, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_alerts WHERE status='open' AND case_id IS NOT NULL"));
    $c->repairOpenCases();
    assertSame(7, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_cases'));
    $c->repairOpenCases();
    assertSame(7, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_cases'), '都補完了，不重複建立');
    assertSame(2, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_alerts WHERE case_id IS NULL"), '模擬與已處置的警示不補案');
    assertSame('172.16.1.10', dbtVal($db, 'SELECT subject_ip FROM ioc_cases ORDER BY id LIMIT 1'));
});

// ---- 基線訓練 ----

/** 讓設備 $deviceId 進入「學習滿 28 天、有 $count 個完整區間」的狀態。 */
function bcdTrainable(PDO $db, int $deviceId, int $count = 6048, bool $schedule = true, int $learningDays = 29): int
{
    $id = (new BehaviorService($db))->syncDevice($deviceId, 'fw-' . $deviceId, '172.16.1.' . (10 + $deviceId), '台南');
    $db->prepare('UPDATE ioc_behavior_assets SET learning_started_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - $learningDays * 86400), $id]);
    bcdBulkWindows($db, $id, $count, intdiv(time() - 27 * 86400, 300) * 300);
    if ($schedule) {
        dbtSchedule($db, 'device', (string) $deviceId);
    }
    return $id;
}

/** 讓 tick 跑一個新的區間（內容無關緊要）。 */
function bcdTickOnce(PDO $db, int $offset = 0): void
{
    $traffic = ['172.16.1.11' => ['count' => 10, 'bytes' => 1000, 'dest' => ['203.0.113.1' => 10], 'port' => ['443' => 10]]];
    $devices = [bcdDevice(1, '172.16.1.11'), bcdDevice(2, '172.16.1.12')];
    (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick($devices, bcdNets(), time() + $offset);
}

dbtest('訓練：學習滿 28 天、區間夠多、有排程 → 建第 1 版基線、進入 ready、留歷史；一次 tick 只訓練一台', static function (PDO $db): void {
    $a = bcdTrainable($db, 1);
    $b = bcdTrainable($db, 2);
    bcdTickOnce($db);
    $state = static fn (int $id) => dbtRow($db, 'SELECT mode,baseline_version,quality_status,training_checked_at FROM ioc_behavior_assets WHERE id=?', [$id]);
    assertSame(['ready', 1, 'ready'], [$state($a)['mode'], (int) $state($a)['baseline_version'], $state($a)['quality_status']]);
    assertTrue($state($a)['training_checked_at'] !== null);
    assertSame(['learning', 0], [$state($b)['mode'], (int) $state($b)['baseline_version']], '一個 tick 只訓練一台：另一台下一輪');
    assertSame(48, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_baselines WHERE asset_id=?', [$a]), '平日／週末 × 24 小時');
    $bl = dbtRow($db, "SELECT * FROM ioc_behavior_baselines WHERE asset_id=? AND day_kind='weekday' AND hour_of_day=12", [$a]);
    assertSame([1000.0, 1000.0, 0.0, 10.0, 1], [(double) $bl['median_bytes'], (double) $bl['p95_bytes'], (double) $bl['mad_bytes'], (double) $bl['median_connections'], (int) ($bl['sample_count'] > 100)]);
    assertTrue(in_array('baseline_ready', dbtHistory($db, $a), true));
    assertSame('system', dbtVal($db, "SELECT actor FROM ioc_behavior_history WHERE asset_id=? AND action='baseline_ready'", [$a]));

    bcdTickOnce($db, 300);
    assertSame(['ready', 1], [$state($b)['mode'], (int) $state($b)['baseline_version']], '下一輪輪到另一台');
    assertSame(48, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_baselines WHERE asset_id=?', [$a]), '第一台不會被重複訓練');
});

dbtest('訓練：不夠格就不訓練（區間差一格、沒有排程、學習未滿 28 天）；被挑中檢查過的會記下檢查時間', static function (PDO $db): void {
    $young = bcdTrainable($db, 1, 6048, true, 10);      // id 最小：若學習天數的條件失效，第一個 tick 就會挑中它
    $few = bcdTrainable($db, 2, 6047);                  // 差一格（這台沒有流量，tick 不會替它多寫完整區間）
    $noSchedule = bcdTrainable($db, 3, 6048, false);
    $state = static fn (int $id) => dbtRow($db, 'SELECT mode,baseline_version,training_checked_at FROM ioc_behavior_assets WHERE id=?', [$id]);
    for ($i = 0; $i < 2; $i++) {                        // 兩個 tick：各檢查一台
        bcdTickOnce($db, $i * 300);
    }
    foreach ([$few, $noSchedule] as $id) {
        assertSame(['learning', 0], [$state($id)['mode'], (int) $state($id)['baseline_version']]);
        assertTrue($state($id)['training_checked_at'] !== null, '被檢查過、沒通過');
    }
    assertSame(['learning', 0, null], [$state($young)['mode'], (int) $state($young)['baseline_version'], $state($young)['training_checked_at']], '學習未滿 28 天：根本不會被挑來檢查');
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_baselines'));
});

dbtest('已知限制：暫時資產（沒有 LibreNMS 裝置、沒有站點）即使有 28 天完整資料也不會訓練基線', static function (PDO $db): void {
    $temp = (new BehaviorService($db))->observeUnknown('172.16.9.9');
    $db->prepare('UPDATE ioc_behavior_assets SET learning_started_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 29 * 86400), $temp]);
    bcdBulkWindows($db, $temp, 6048, intdiv(time() - 27 * 86400, 300) * 300);
    dbtSchedule($db, 'device', '1');
    dbtSchedule($db, 'site', '台南');
    bcdTickOnce($db);
    $row = dbtRow($db, 'SELECT mode,baseline_version,training_checked_at FROM ioc_behavior_assets WHERE id=?', [$temp]);
    assertSame(['learning', 0], [$row['mode'], (int) $row['baseline_version']], '訓練要排程，而排程只能依裝置或站點設定，暫時資產兩者都沒有');
    assertTrue($row['training_checked_at'] !== null);
});

dbtest('已知限制：ready／monitoring 的資產不會重新訓練（基線只在學習模式建一次）', static function (PDO $db): void {
    $id = bcdTrainable($db, 1);
    $db->prepare("UPDATE ioc_behavior_assets SET mode='monitoring',baseline_version=1,quality_status='ready' WHERE id=?")->execute([$id]);
    bcdTickOnce($db);
    assertSame(1, (int) dbtVal($db, 'SELECT baseline_version FROM ioc_behavior_assets WHERE id=?', [$id]));
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_baselines'), '沒有新的基線，也沒有舊的被換掉');
});

// ---- Webhook 通知 ----

/** 寫一個暫存的 Webhook 設定檔，回傳路徑（呼叫端用完要刪）。 */
function bcdWebhookConf(string $url): string
{
    $file = tempnam(sys_get_temp_dir(), 'iocwh');
    file_put_contents($file, "WEBHOOK_URL={$url}\nWEBHOOK_SECRET_HEX=" . str_repeat('ab', 32) . "\n");
    return $file;
}

dbtest('Webhook：沒有設定檔 → 來源標 unavailable、佇列原封不動', static function (PDO $db): void {
    $id = bcdMonitoredDevice($db);
    $alert = dbtAlert($db, $id);
    $db->prepare("INSERT INTO ioc_behavior_deliveries (alert_id,status,attempts,next_attempt_at) VALUES (?,'queued',0,UTC_TIMESTAMP())")->execute([$alert]);
    (new BehaviorWebhook($db, '/nonexistent/ioc-behavior-webhook.conf'))->deliverDue();
    $s = dbtRow($db, "SELECT status,reason FROM ioc_behavior_sources WHERE source_key='webhook'");
    assertSame('unavailable', $s['status']);
    assertSame(['queued', 0], [dbtVal($db, 'SELECT status FROM ioc_behavior_deliveries WHERE alert_id=?', [$alert]), (int) dbtVal($db, 'SELECT attempts FROM ioc_behavior_deliveries WHERE alert_id=?', [$alert])]);
});

/** 擺一個監控中的設備資產（不需要基線）。 */
function bcdMonitoredDevice(PDO $db): int
{
    return dbtAsset($db, ['device_id' => 1, 'name' => 'fw-a', 'mode' => 'monitoring', 'baseline_version' => 1, 'quality_status' => 'ready']);
}

dbtest('Webhook：投遞失敗會退避重試（1、2、4、8 分鐘），第 5 次失敗就標 failed；只處理未處置且到期的、一輪最多 6 筆', static function (PDO $db): void {
    $id = bcdMonitoredDevice($db);
    $conf = bcdWebhookConf('https://127.0.0.1:9/hook');          // 埠 9：連線被拒，失敗得又快又確定
    try {
        $hook = new BehaviorWebhook($db, $conf);
        $due = [];
        foreach ([0, 1, 2, 3, 4] as $attempts) {                  // 已失敗的次數
            $alert = dbtAlert($db, $id);
            $db->prepare("INSERT INTO ioc_behavior_deliveries (alert_id,status,attempts,next_attempt_at) VALUES (?,'queued',?,UTC_TIMESTAMP())")->execute([$alert, $attempts]);
            $due[$attempts] = $alert;
        }
        $acked = dbtAlert($db, $id, 'acknowledged');
        $db->prepare("INSERT INTO ioc_behavior_deliveries (alert_id,status,attempts,next_attempt_at) VALUES (?,'queued',0,UTC_TIMESTAMP())")->execute([$acked]);
        $later = dbtAlert($db, $id);
        $db->prepare("INSERT INTO ioc_behavior_deliveries (alert_id,status,attempts,next_attempt_at) VALUES (?,'queued',0,UTC_TIMESTAMP() + INTERVAL 1 HOUR)")->execute([$later]);

        $hook->deliverDue();

        $row = static fn (int $alert) => dbtRow($db, 'SELECT status,attempts,error_code,last_http_status,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),next_attempt_at) wait FROM ioc_behavior_deliveries WHERE alert_id=?', [$alert]);
        foreach ([0 => 60, 1 => 120, 2 => 240, 3 => 480] as $attempts => $delay) {
            $r = $row($due[$attempts]);
            assertSame(['queued', $attempts + 1, 'transport_error', null], [$r['status'], (int) $r['attempts'], $r['error_code'], $r['last_http_status']], '已失敗 ' . $attempts . ' 次');
            assertTrue((int) $r['wait'] >= $delay - 5 && (int) $r['wait'] <= $delay + 1, '下次重試要等約 ' . $delay . ' 秒，實際 ' . $r['wait']);
        }
        $last = $row($due[4]);
        assertSame(['failed', 5, 'transport_error'], [$last['status'], (int) $last['attempts'], $last['error_code']], '第 5 次失敗：不再自動重試（共 5 次嘗試，不是「5 次重試」）');
        assertSame(['queued', 0], [$row($acked)['status'], (int) $row($acked)['attempts']], '警示已處置的不送');
        assertSame(['queued', 0], [$row($later)['status'], (int) $row($later)['attempts']], '還沒到期的不送');
        assertSame('ready', dbtVal($db, "SELECT status FROM ioc_behavior_sources WHERE source_key='webhook'"));

        for ($i = 0; $i < 8; $i++) {                               // 一輪最多處理 6 筆
            $alert = dbtAlert($db, $id);
            $db->prepare("INSERT INTO ioc_behavior_deliveries (alert_id,status,attempts,next_attempt_at) VALUES (?,'queued',0,UTC_TIMESTAMP() - INTERVAL 1 MINUTE)")->execute([$alert]);
        }
        $hook->deliverDue();
        assertSame(6, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_deliveries WHERE status='queued' AND attempts=1 AND alert_id>?", [$later]));
        assertSame(2, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_deliveries WHERE status='queued' AND attempts=0 AND alert_id>?", [$later]));
    } finally {
        @unlink($conf);
    }
});
