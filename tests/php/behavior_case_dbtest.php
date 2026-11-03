<?php
declare(strict_types=1);

/**
 * 裝置行為警示建立的事件案：事件類型（不宣稱攻擊技術）、警示當時使用的位址（不是「最近一個」）、可查的證據條件，
 * 以及事件詳情的「裝置行為警示」區塊（BehaviorService::caseEvidence）：彙總指標所有人看得到，目的位址只給管理者並留稽核。
 * 真的 MariaDB，一次性的 ioc_test_* schema。
 */

use Ioc\AccessDenied;
use Ioc\BehaviorCollector;
use Ioc\BehaviorService;
use Ioc\CaseCatalog;
use Ioc\CaseService;
use Ioc\PdoCaseRepo;

/** 一個資產加一個警示（尚未建事件案）：警示的區間是 $window（UTC），指標用 $metrics。 */
function bcsAlert(PDO $db, int $assetId, string $window = '2026-10-26 12:05:00', ?array $metrics = null, string $detector = 'after_hours_surge'): int
{
    $metrics ??= ['bytes' => 250000000, 'connections' => 100, 'destinations' => 25, 'baseline_p95_bytes' => 1048576.0, 'baseline_p95_destinations' => 3.0,
                  'byte_limit' => 104857600.0, 'destination_limit' => 20.0];
    $id = dbtAlert($db, $assetId, 'open', null, $detector);
    $db->prepare('UPDATE ioc_behavior_alerts SET window_start=?, metrics_json=? WHERE id=?')->execute([$window, json_encode($metrics), $id]);
    return $id;
}

function bcsAddress(PDO $db, int $assetId, string $ip, string $first, string $last): void
{
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,?,?,?,'observed')")
        ->execute([$assetId, $ip, $first, $last]);
}

function bcsWindow(PDO $db, int $assetId, string $start, array $dest, array $ports): void
{
    $db->prepare("INSERT INTO ioc_behavior_windows (asset_id,source_key,window_start,connections,bytes_total,destinations,ports,destinations_json,ports_json,signals_json,quality,quality_reason,observed_at)
        VALUES (?,?,?,100,250000000,?,?,?,?,'{}','complete','',UTC_TIMESTAMP())")
        ->execute([$assetId, BehaviorService::SOURCE, $start, count($dest), count($ports), json_encode($dest), json_encode($ports)]);
}

function bcsKnown(PDO $db, int $assetId, string $kind, string $value, string $first): void
{
    $db->prepare('INSERT INTO ioc_behavior_known (asset_id,source_key,kind,value_key,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,?)')
        ->execute([$assetId, BehaviorService::SOURCE, $kind, $value, $first, $first]);
}

function bcsRepair(PDO $db): void
{
    (new BehaviorCollector($db, DbtFakeLogs::traffic([])))->repairOpenCases();
}

dbtest('警示建立的事件案：類型是「裝置行為異常」（沒有攻擊技術）、位址是警示當時用的那個（不是最近一個）、證據條件查該位址', static function (PDO $db): void {
    $asset = dbtAsset($db, ['name' => 'PC-會計01', 'mode' => 'monitoring', 'baseline_version' => 1]);
    bcsAddress($db, $asset, '172.16.9.10', '2026-10-20 00:00:00', '2026-10-26 13:00:00');        // 警示那一刻用的位址
    bcsAddress($db, $asset, '172.16.9.99', '2026-10-27 01:00:00', '2026-10-27 02:00:00');        // 之後 DHCP 換的新位址（最近一個）
    $alert = bcsAlert($db, $asset, '2026-10-26 12:05:00');
    bcsRepair($db);
    $case = dbtRow($db, 'SELECT c.* FROM ioc_cases c JOIN ioc_behavior_alerts a ON a.case_id=c.id WHERE a.id=?', [$alert]);
    assertTrue($case !== null, '補建事件案');
    assertSame([CaseCatalog::BEHAVIOR, '172.16.9.10', 'PC-會計01', 'src_ip:"172.16.9.10"'], [$case['category'], $case['subject_ip'], $case['subject_host'], $case['evidence_query']],
        '換了新位址之後，事件案仍然記警示當時的位址（拿最近一個位址會把別台的流量當證據）');
    assertSame(null, CaseCatalog::get($case['category'])['attack'], '不宣稱攻擊技術');
});

dbtest('警示建立的事件案：沒有哪個位址涵蓋警示時間就退回最近一個；完全沒有位址就不填位址也不填證據條件', static function (PDO $db): void {
    $a = dbtAsset($db, ['name' => 'a']);
    bcsAddress($db, $a, '172.16.9.20', '2026-10-27 00:00:00', '2026-10-27 01:00:00');           // 警示之後才出現
    $alertA = bcsAlert($db, $a, '2026-10-26 12:05:00');
    $b = dbtAsset($db, ['name' => 'b']);
    $alertB = bcsAlert($db, $b, '2026-10-26 12:05:00');
    bcsRepair($db);
    $caseA = dbtRow($db, 'SELECT c.* FROM ioc_cases c JOIN ioc_behavior_alerts x ON x.case_id=c.id WHERE x.id=?', [$alertA]);
    assertSame(['172.16.9.20', 'src_ip:"172.16.9.20"'], [$caseA['subject_ip'], $caseA['evidence_query']], '退回最近一個位址');
    $caseB = dbtRow($db, 'SELECT c.* FROM ioc_cases c JOIN ioc_behavior_alerts x ON x.case_id=c.id WHERE x.id=?', [$alertB]);
    assertTrue($caseB !== null, '沒有位址也要建得起事件案');
    assertSame([null, null], [$caseB['subject_ip'], $caseB['evidence_query']]);
});

dbtest('事件詳情的裝置行為警示：彙總指標所有人看得到，目的位址只給管理者；管理者看目的位址會留稽核，檢視者不留', static function (PDO $db): void {
    $asset = dbtAsset($db, ['name' => 'PC-會計01', 'mode' => 'monitoring', 'baseline_version' => 1]);
    bcsAddress($db, $asset, '172.16.9.10', '2026-10-20 00:00:00', '2026-10-26 13:00:00');
    bcsWindow($db, $asset, '2026-10-26 12:05:00', ['203.0.113.7', '203.0.113.8', '198.51.100.5'], [443, 8443]);
    bcsKnown($db, $asset, 'destination', '203.0.113.7', '2026-10-01 00:00:00');                 // 早就見過
    bcsKnown($db, $asset, 'destination', '203.0.113.8', '2026-10-26 12:10:00');                 // 這個時段才第一次出現
    $alert = bcsAlert($db, $asset, '2026-10-26 12:05:00');
    bcsRepair($db);
    $caseId = (int) dbtVal($db, 'SELECT case_id FROM ioc_behavior_alerts WHERE id=?', [$alert]);
    $svc = new BehaviorService($db);

    $v = $svc->caseEvidence($caseId, 'viewer', DBT_V);
    assertSame(true, $v['ok']);
    assertSame(true, $v['destinations_hidden']);
    $a = $v['alerts'][0];
    assertSame(['after_hours_surge', '非上班時段流量或目的地數暴增', 'PC-會計01', null], [$a['detector'], $a['detector_label'], $a['asset'], $a['destinations']], '檢視者沒有目的位址');
    assertSame('2026-10-26 20:05', $a['window'], '台北時間');
    $m = array_column($a['metrics'], 'text', 'label');
    assertSame('238.4 MiB', $m['實際位元組數'], '位元組換成人看得懂的單位');
    assertSame(['100', '25', '1.0 MiB', '100.0 MiB', '20'], [$m['實際連線數'], $m['實際不同目的地數'], $m['基線 P95 位元組數'], $m['位元組門檻'], $m['目的地數門檻']]);
    assertTrue(strpos(json_encode($v, JSON_UNESCAPED_UNICODE), '203.0.113') === false, '檢視者拿到的整份資料裡沒有任何目的位址');
    assertSame(0, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_audit WHERE action='case_destinations_viewed'"), '檢視者看的是彙總，不留稽核');

    $h = $svc->caseEvidence($caseId, 'alice', DBT_H);
    assertSame(false, $h['destinations_hidden']);
    $d = $h['alerts'][0]['destinations'];
    assertSame(true, $d['ok']);
    $byIp = array_column($d['items'], 'first_seen', 'ip');
    assertSame(['203.0.113.7', '203.0.113.8', '198.51.100.5'], array_column($d['items'], 'ip'));
    assertSame(['2026-10-01 08:00', '2026-10-26 20:10', null], [$byIp['203.0.113.7'], $byIp['203.0.113.8'], $byIp['198.51.100.5']], '首次出現的時間（台北）；沒有紀錄就是 null，不是猜的');
    assertSame([443, 8443], $d['ports']);
    $audit = dbtRow($db, "SELECT actor,subject FROM ioc_behavior_audit WHERE action='case_destinations_viewed'");
    assertSame(['alice', 'case:' . $caseId], [$audit['actor'], $audit['subject']], '管理者看了目的位址：誰、哪一件事件案');

    $count = fn () => (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_audit WHERE action='case_destinations_viewed'");
    $svc->caseEvidence($caseId, 'alice', DBT_H);
    assertSame(1, $count(), '同一個管理者十分鐘內重複打開同一件事件案：不再寫（免得把行為頁最近 30 筆的稽核清單擠滿）');
    $svc->caseEvidence($caseId, 'bob', DBT_H);
    assertSame(2, $count(), '換一個管理者要另外留');
    $db->exec("UPDATE ioc_behavior_audit SET occurred_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 11 MINUTE) WHERE actor = 'alice'");
    $svc->caseEvidence($caseId, 'alice', DBT_H);
    assertSame(3, $count(), '超過十分鐘再看，要再留一筆');
});

dbtest('事件詳情的裝置行為警示：不是行為警示建立的事件案是 null；沒有身分被拒絕；該時段的區間不在了，管理者看到原因而不是空清單', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $plain = (new CaseService(new PdoCaseRepo($db)))->create(['title' => '一般事件案'], 'a', DBT_H)['id'];
    assertSame(null, $svc->caseEvidence($plain, 'a', DBT_H), '沒有警示指向它：不顯示這一塊');
    $asset = dbtAsset($db, ['name' => 'n']);
    bcsAddress($db, $asset, '172.16.9.30', '2026-10-20 00:00:00', '2026-10-26 13:00:00');
    $alert = bcsAlert($db, $asset, '2026-10-26 12:05:00');                                       // 沒有 windows 資料列
    bcsRepair($db);
    $caseId = (int) dbtVal($db, 'SELECT case_id FROM ioc_behavior_alerts WHERE id=?', [$alert]);
    assertThrows(AccessDenied::class, fn () => $svc->caseEvidence($caseId, 'x', null));
    $d = $svc->caseEvidence($caseId, 'alice', DBT_H)['alerts'][0]['destinations'];
    assertSame(false, $d['ok']);
    assertTrue(strpos($d['error'], '區間資料') !== false, $d['error']);
});

dbtest('事件詳情的裝置行為警示：指標欄位走白名單（不認得的鍵、非數字的值不會出現在畫面）', static function (PDO $db): void {
    $asset = dbtAsset($db, ['name' => 'n']);
    bcsAddress($db, $asset, '172.16.9.31', '2026-10-20 00:00:00', '2026-10-26 13:00:00');
    $alert = bcsAlert($db, $asset, '2026-10-26 12:05:00', ['connections' => 7, 'dest_ips' => ['203.0.113.1'], 'bytes' => 'drop table', 'secret' => 'x'], 'new_external_pattern');
    bcsRepair($db);
    $caseId = (int) dbtVal($db, 'SELECT case_id FROM ioc_behavior_alerts WHERE id=?', [$alert]);
    $a = (new BehaviorService($db))->caseEvidence($caseId, 'v', DBT_V)['alerts'][0];
    assertSame(['實際連線數' => '7'], array_column($a['metrics'], 'text', 'label'));
    assertSame('非上班時段出現新目的地或埠', $a['detector_label']);
});
