<?php
declare(strict_types=1);

/**
 * M2b：關聯 LibreNMS 設備時合併歷史、採集器依「管理員確認過的位址」對應流量、一個資產多個位址的區間彙整。
 * 真的 MariaDB，一次性的 ioc_test_* schema；見 tests/run-db-tests.sh。
 * 用到 behavior_collector_dbtest.php 的 bcd* 與 behavior_hosts_dbtest.php 的 bhd* 輔助函式（檔名排序在前面，先載入）。
 */

use Ioc\AccessDenied;
use Ioc\BehaviorCollector;
use Ioc\BehaviorService;
use Ioc\NotFound;
use Ioc\ValidationError;

/** 管理員確認過、屬於這個資產的位址（直接寫，繞過 linkAsset；$ageHours＝最近一次看到是幾小時前）。 */
function bmdLinked(PDO $db, int $assetId, string $ip, int $ageHours = 0): void
{
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind)
        VALUES (?,?,UTC_TIMESTAMP() - INTERVAL ? HOUR,UTC_TIMESTAMP() - INTERVAL ? HOUR,'admin_confirmed')")->execute([$assetId, $ip, $ageHours, $ageHours]);
}

/** 一個來源 IP 在一個區間的流量（日誌都有 net_bytes、目的 IP 與目的埠）。 */
function bmdTraffic(string $ip, int $count, int $bytes, array $dest, array $port): array
{
    return [$ip => ['count' => $count, 'bytes' => $bytes, 'dest' => $dest, 'port' => $port]];
}

/** @return array<int,string> 某個資產的位址（依 id）。 */
function bmdAddresses(PDO $db, int $assetId): array
{
    return array_column(dbtAll($db, 'SELECT address FROM ioc_behavior_addresses WHERE asset_id=? ORDER BY id', [$assetId]), 'address');
}

// ---- 位址紀錄：依（資產，位址）比對，不是只看最新一列 ----

dbtest('位址紀錄：同一個位址再出現只更新那一列（A→B→A 不新增重複列、不重複記 address_seen）；目前位址是最近看到的', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = $svc->syncDevice(10, 'fw', '172.16.1.1', null);
    $svc->syncDevice(10, 'fw', '172.16.1.9', null);
    $db->exec('UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE address=\'172.16.1.1\'');
    $db->exec('UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE address=\'172.16.1.9\'');
    $svc->syncDevice(10, 'fw', '172.16.1.1', null);       // 換回第一個位址
    assertSame(['172.16.1.1', '172.16.1.9'], bmdAddresses($db, $id), '換回舊位址：用原來那一列，不新增');
    assertSame(['address_seen', 'address_seen'], dbtHistory($db, $id), '只有第一次看到每個位址才記歷史');
    $row = dbtRow($db, 'SELECT address FROM ioc_behavior_addresses WHERE asset_id=? ORDER BY last_seen_at DESC,id DESC LIMIT 1', [$id]);
    assertSame('172.16.1.1', $row['address'], '被更新的是舊的那一列，所以最近看到的是它');
    $byId = array_column($svc->state(DBT_H)['assets'], null, 'id');
    assertSame('172.16.1.1', $byId[$id]['address'], '工作台的目前位址＝最近看到的');
});

dbtest('事件案的位址：用最近看到的位址，不是最後新增的那一列', static function (PDO $db): void {
    $id = dbtAsset($db, ['device_id' => 1, 'name' => 'fw', 'mode' => 'monitoring', 'baseline_version' => 1]);
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,'172.16.1.1',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'librenms')")->execute([$id]);
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,'172.16.1.9',UTC_TIMESTAMP(),UTC_TIMESTAMP() - INTERVAL 2 HOUR,'admin_confirmed')")->execute([$id]);
    dbtAlert($db, $id);
    (new BehaviorCollector($db, DbtFakeLogs::traffic([])))->repairOpenCases();
    assertSame('172.16.1.1', dbtVal($db, 'SELECT subject_ip FROM ioc_cases ORDER BY id LIMIT 1'), '最近看到的位址，不是 id 最大的那一列');
});

// ---- 採集器：一個資產有多個位址 ----

dbtest('tick：一個資產有兩個位址（LibreNMS 的 IP 加上管理員確認過的）同一區間都有流量 → 只寫一列：連線與位元組相加、目的地與埠取聯集，不另建暫時資產', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    bmdLinked($db, $dev, '172.16.1.77');
    $traffic = bmdTraffic('172.16.1.10', 20, 5000000, ['203.0.113.1' => 12, '203.0.113.2' => 8], ['443' => 20])
        + bmdTraffic('172.16.1.77', 10, 3000000, ['203.0.113.2' => 6, '203.0.113.3' => 4], ['443' => 6, '80' => 4]);
    (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick([bcdDevice()], bcdNets(), bcdNow());
    $w = bcdWindows($db, $dev);
    assertSame(1, count($w), '同一個資產同一個區間只能有一列（唯一鍵），後寫的不能蓋掉先寫的');
    assertSame([30, 8000000, 3, 2, 'complete', ''], [(int) $w[0]['connections'], (int) $w[0]['bytes_total'], (int) $w[0]['destinations'], (int) $w[0]['ports'], $w[0]['quality'], $w[0]['quality_reason']]);
    $dest = json_decode($w[0]['destinations_json'], true);
    $ports = json_decode($w[0]['ports_json'], true);
    sort($dest);
    sort($ports);
    assertSame([['203.0.113.1', '203.0.113.2', '203.0.113.3'], [80, 443]], [$dest, $ports]);
    assertSame(0, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:%'"), '管理員確認過的位址不會再建暫時資產');
    assertSame(1, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_windows'));
});

dbtest('tick：多個位址只有一個有流量 → complete（用有流量那個位址的資料）；全部沒流量 → missing；有流量的缺位元組 → partial', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    bmdLinked($db, $dev, '172.16.1.77');
    $only = bmdTraffic('172.16.1.77', 10, 3000000, ['203.0.113.2' => 10], ['443' => 10]);
    (new BehaviorCollector($db, DbtFakeLogs::traffic($only)))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:07:30'));
    $partial = $only;
    $partial['172.16.1.77']['with_bytes'] = 9;
    (new BehaviorCollector($db, DbtFakeLogs::traffic($partial)))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:12:30'));
    $none = bmdTraffic('172.16.7.7', 5, 1, ['203.0.113.5' => 5], ['80' => 5]);       // 全域有日誌，但這台的兩個位址都沒有
    (new BehaviorCollector($db, DbtFakeLogs::traffic($none)))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:17:30'));
    $w = bcdWindows($db, $dev);
    assertSame(['complete', 10, 3000000], [$w[0]['quality'], (int) $w[0]['connections'], (int) $w[0]['bytes_total']]);
    assertSame(['partial', '1 筆日誌缺 net_bytes'], [$w[1]['quality'], $w[1]['quality_reason']]);
    assertSame(['missing', 0], [$w[2]['quality'], (int) $w[2]['connections']]);
});

dbtest('tick：LibreNMS 的當前 IP 優先於「已確認的位址」（LibreNMS 說是誰的就是誰的）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $a = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    $b = $svc->syncDevice(2, 'fw-b', '172.16.1.20', '台南');
    bmdLinked($db, $a, '172.16.1.20');      // a 宣告了 b 的當前 IP
    $devices = [bcdDevice(1, '172.16.1.10'), bcdDevice(2, '172.16.1.20', '台南', 'fw-b')];
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bmdTraffic('172.16.1.20', 7, 700, ['203.0.113.1' => 7], ['443' => 7]))))->tick($devices, bcdNets(), bcdNow());
    assertSame(['complete', 7], [bcdWindows($db, $b)[0]['quality'], (int) bcdWindows($db, $b)[0]['connections']], '.20 的流量算在 b');
    assertSame('missing', bcdWindows($db, $a)[0]['quality'], 'a 自己的 IP 沒流量；.20 不歸它');
});

dbtest('tick：已退役設備的已確認位址不對應（流量照舊建暫時資產，退役的資產不寫區間）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $gone = $svc->syncDevice(4, 'fw-gone', '172.16.1.40', '台南');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$gone]);
    bmdLinked($db, $gone, '172.16.1.99');
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bmdTraffic('172.16.1.99', 3, 300, ['203.0.113.3' => 3], ['443' => 3]))))->tick([], bcdNets(), bcdNow());
    assertSame([], bcdWindows($db, $gone));
    assertSame(1, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:172.16.1.99:%'"));
});

dbtest('tick：已確認位址隔 24 小時以上才又出現，留一筆 reappeared 歷史；位址那一列更新最近看到、不新增', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    bmdLinked($db, $dev, '172.16.1.77', 24 * 3);
    $tick = static fn (string $now) => (new BehaviorCollector($db, DbtFakeLogs::traffic(bmdTraffic('172.16.1.77', 4, 400, ['203.0.113.1' => 4], ['443' => 4]))))->tick([bcdDevice()], bcdNets(), bcdNow($now));
    $tick('2026-10-26 12:07:30');
    $tick('2026-10-26 12:12:30');
    assertSame(1, count(array_keys(dbtHistory($db, $dev), 'reappeared')), '隔了 3 天才又出現：記一次；連續出現不重複記');
    assertTrue(strpos((string) dbtVal($db, "SELECT detail FROM ioc_behavior_history WHERE asset_id=? AND action='reappeared'", [$dev]), '3 天') !== false);
    assertSame(['172.16.1.10', '172.16.1.77'], bmdAddresses($db, $dev), '位址那一列只更新、不新增');
    assertTrue((int) dbtVal($db, "SELECT TIMESTAMPDIFF(MINUTE,last_seen_at,UTC_TIMESTAMP()) FROM ioc_behavior_addresses WHERE asset_id=? AND address='172.16.1.77'", [$dev]) < 5);
});

dbtest('tick：多個位址的資產只評估一次（兩個位址各自暴增，警示次數是 1、位元組是兩邊相加）', static function (PDO $db): void {
    $id = bcdMonitored($db, 'monitoring');
    bmdLinked($db, $id, '172.16.1.77');
    $traffic = bcdSurge('172.16.1.10') + bcdSurge('172.16.1.77');
    (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:07:30'));
    $alerts = dbtAll($db, 'SELECT detector,occurrences,metrics_json FROM ioc_behavior_alerts ORDER BY id');
    assertSame(['after_hours_surge', 'new_external_pattern'], array_column($alerts, 'detector'));
    assertSame([1, 1], array_map(fn ($a) => (int) $a['occurrences'], $alerts), '每個資產每個區間只評估一次：不是每個位址評估一次');
    assertSame(500000000, json_decode($alerts[0]['metrics_json'], true)['bytes'], '兩個位址的位元組相加');
});

// ---- 關聯 LibreNMS 設備：合併歷史 ----

const BMD_SRC = 'fortigate_graylog';
const BMD_MISSING = \Ioc\BehaviorAnalyzer::MISSING_REASON;

/** 直接寫一個區間（繞過採集器）。 */
function bmdWin(PDO $db, int $asset, string $start, string $quality, int $conn = 0, int $bytes = 0, array $dest = [], array $ports = [], string $reason = ''): void
{
    $db->prepare("INSERT INTO ioc_behavior_windows (asset_id,source_key,window_start,connections,bytes_total,destinations,ports,destinations_json,ports_json,signals_json,quality,quality_reason,observed_at)
        VALUES (?,?,?,?,?,?,?,?,?,'{}',?,?,UTC_TIMESTAMP())")
        ->execute([$asset, BMD_SRC, $start, $conn, $bytes, count($dest), count($ports), json_encode($dest), json_encode($ports), $quality, $reason]);
}

/** @return array<string,mixed>|null */
function bmdW(PDO $db, int $asset, string $start): ?array
{
    return dbtRow($db, 'SELECT * FROM ioc_behavior_windows WHERE asset_id=? AND window_start=?', [$asset, $start]);
}

function bmdKnown(PDO $db, int $asset, string $kind, string $value, string $first, string $last, int $seen): void
{
    $db->prepare('INSERT INTO ioc_behavior_known (asset_id,source_key,kind,value_key,first_seen_at,last_seen_at,seen_count) VALUES (?,?,?,?,?,?,?)')
        ->execute([$asset, BMD_SRC, $kind, $value, $first, $last, $seen]);
}

function bmdT(int $i): string
{
    return gmdate('Y-m-d H:i:s', strtotime('2026-10-20 10:00:00 UTC') + $i * 300);
}

/**
 * 一次擺出「暫時資產 O 與設備資產 D 各有一些區間、已知目的地與位址」的情境；回傳 [O, D]。
 * 區間（t1…t7）：O 有 t1、t2、t3、t4(缺資料)、t5(缺資料)、t6；D 有 t2(缺資料)、t3、t4、t5(缺資料)、t6(partial)、t7。
 */
function bmdScenario(PDO $db, bool $confirmed = false, string $deviceMode = 'learning'): array
{
    $svc = new BehaviorService($db);
    $d = $svc->syncDevice(30, 'fw-3', '172.16.3.1', '台南');
    $o = $svc->observeUnknown('172.16.3.99');
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,'172.16.3.1','2026-10-02 00:00:00','2026-10-18 00:00:00','observed')")->execute([$o]);
    $db->prepare("UPDATE ioc_behavior_addresses SET first_seen_at='2026-10-03 00:00:00' WHERE asset_id=? AND address='172.16.3.1'")->execute([$d]);
    $db->prepare('UPDATE ioc_behavior_assets SET learning_started_at=? WHERE id=?')->execute(['2026-10-01 00:00:00', $o]);
    $db->prepare('UPDATE ioc_behavior_assets SET learning_started_at=?,mode=? WHERE id=?')->execute(['2026-10-20 00:00:00', $deviceMode, $d]);
    if ($confirmed) {
        $svc->confirmHosts([['id' => $o]], '核對過', 'admin', DBT_H);
    }
    bmdWin($db, $o, bmdT(1), 'complete', 10, 1000, ['1.1.1.1'], [443]);
    bmdWin($db, $o, bmdT(2), 'complete', 20, 2000, ['2.2.2.2'], [80]);
    bmdWin($db, $o, bmdT(3), 'complete', 7, 700, ['3.3.3.3', '4.4.4.4'], [443, 8080]);
    bmdWin($db, $o, bmdT(4), 'missing', 0, 0, [], [], BMD_MISSING);
    bmdWin($db, $o, bmdT(5), 'missing', 0, 0, [], [], BMD_MISSING);
    bmdWin($db, $o, bmdT(6), 'complete', 4, 400, ['6.6.6.6', '7.7.7.7'], [443]);
    bmdWin($db, $d, bmdT(2), 'missing', 0, 0, [], [], BMD_MISSING);
    bmdWin($db, $d, bmdT(3), 'complete', 5, 500, ['3.3.3.3'], [443]);
    bmdWin($db, $d, bmdT(4), 'complete', 8, 800, ['8.8.8.8'], [443]);
    bmdWin($db, $d, bmdT(5), 'missing', 0, 0, [], [], BMD_MISSING);
    bmdWin($db, $d, bmdT(6), 'partial', 3, 300, ['6.6.6.6'], [443], '1 筆日誌缺 net_bytes');
    bmdWin($db, $d, bmdT(7), 'complete', 9, 900, ['9.9.9.9'], [443]);
    bmdKnown($db, $o, 'destination', '1.1.1.1', '2026-10-05 00:00:00', '2026-10-18 00:00:00', 3);
    bmdKnown($db, $o, 'port', '443', '2026-10-06 00:00:00', '2026-10-19 00:00:00', 2);
    bmdKnown($db, $d, 'port', '443', '2026-10-10 00:00:00', '2026-10-25 00:00:00', 5);
    return [$o, $d];
}

/** 與關聯有關的所有資料表的筆數與關鍵欄位快照（預覽與被拒絕的關聯不能改任何東西）。 */
function bmdSnapshot(PDO $db): array
{
    $out = [];
    foreach (['ioc_behavior_windows', 'ioc_behavior_known', 'ioc_behavior_addresses', 'ioc_behavior_links', 'ioc_behavior_history', 'ioc_behavior_audit', 'ioc_behavior_alerts'] as $table) {
        $out[$table] = (int) dbtVal($db, 'SELECT COUNT(*) FROM ' . $table);
    }
    $out['assets'] = dbtAll($db, 'SELECT id,mode,learning_started_at,baseline_version FROM ioc_behavior_assets ORDER BY id');
    $out['window_sum'] = dbtVal($db, 'SELECT SUM(connections*7+bytes_total+LENGTH(destinations_json)) FROM ioc_behavior_windows');
    return $out;
}

dbtest('previewLink：只有管理者；回報區間要複製／取代／合併／不動的個數、已知目的地、位址與學習起點；不改任何資料', static function (PDO $db): void {
    [$o, $d] = bmdScenario($db);
    $svc = new BehaviorService($db);
    assertThrows(AccessDenied::class, fn () => $svc->previewLink($o, 30, DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->previewLink($o, 30, null));
    assertThrows(NotFound::class, fn () => $svc->previewLink($o, 999, DBT_H));
    $before = bmdSnapshot($db);
    $p = $svc->previewLink($o, 30, DBT_H);
    assertSame($before, bmdSnapshot($db), '預覽不改任何資料');
    assertSame(['copy' => 1, 'replace' => 1, 'combine' => 2, 'keep' => 2], $p['windows'], 't1 複製；t2 取代設備的缺資料；t3、t6 兩邊都有流量要合併；t4、t5 沒有東西可併');
    assertSame(['new' => 1, 'merge' => 1], $p['known']);
    assertSame(['new' => 1, 'existing' => 1], $p['addresses']);
    assertSame('2026-10-01 00:00:00', $p['learning_started_after'], '設備還在學習：學習起點取較早的那個');
    assertSame([$o, $d], [$p['observed']['id'], $p['device']['id']]);
    assertSame(1, count($p['warnings']), '兩邊都是學習中、站點相同、沒有警示：只剩「合併後有多個位址」的提醒');
});

dbtest('linkAsset 合併：區間（複製／取代／合併／不動）、已知目的地、位址、學習起點、關聯與歷史；暫時資產那一側原封不動', static function (PDO $db): void {
    [$o, $d] = bmdScenario($db);
    $svc = new BehaviorService($db);
    $own = static fn () => dbtAll($db, 'SELECT window_start,quality,connections,bytes_total,destinations_json FROM ioc_behavior_windows WHERE asset_id=? ORDER BY window_start', [$o]);
    $ownBefore = $own();
    $preview = $svc->previewLink($o, 30, DBT_H);
    $svc->linkAsset($o, 30, '核對過 DHCP 租約與 MAC', 'admin', DBT_H);

    $w1 = bmdW($db, $d, bmdT(1));
    assertSame(['complete', 10, 1000], [$w1['quality'], (int) $w1['connections'], (int) $w1['bytes_total']], '設備沒有的區間：複製暫時資產的');
    $w2 = bmdW($db, $d, bmdT(2));
    assertSame(['complete', 20, 2000], [$w2['quality'], (int) $w2['connections'], (int) $w2['bytes_total']], '設備那邊是缺資料：用暫時資產的取代（LibreNMS 的 IP 沒流量，不代表這台沒活動）');
    $w3 = bmdW($db, $d, bmdT(3));
    assertSame(['complete', 12, 1200, 2, 2, ''], [$w3['quality'], (int) $w3['connections'], (int) $w3['bytes_total'], (int) $w3['destinations'], (int) $w3['ports'], $w3['quality_reason']], '兩邊都有流量：相加、取聯集');
    $dest = json_decode($w3['destinations_json'], true);
    sort($dest);
    assertSame(['3.3.3.3', '4.4.4.4'], $dest);
    $w4 = bmdW($db, $d, bmdT(4));
    assertSame(['complete', 8, 800], [$w4['quality'], (int) $w4['connections'], (int) $w4['bytes_total']], '設備有資料、暫時資產是缺資料：不動');
    assertSame('missing', bmdW($db, $d, bmdT(5))['quality'], '兩邊都缺資料：還是缺資料');
    $w6 = bmdW($db, $d, bmdT(6));
    assertSame(['partial', 7, 700], [$w6['quality'], (int) $w6['connections'], (int) $w6['bytes_total']], '有一邊不完整，合併後就是 partial（不進基線）');
    assertTrue(strpos($w6['quality_reason'], '合併') !== false && strpos($w6['quality_reason'], '缺 net_bytes') !== false && mb_strlen($w6['quality_reason']) <= 120, $w6['quality_reason']);
    assertSame(['complete', 9], [bmdW($db, $d, bmdT(7))['quality'], (int) bmdW($db, $d, bmdT(7))['connections']], '只有設備有的區間：不動');
    assertSame(7, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_windows WHERE asset_id=?', [$d]), '原本 6 列＋複製 1 列');
    assertSame($ownBefore, $own(), '暫時資產那一側的區間原封不動（複製，不是搬移）');

    $k = dbtAll($db, 'SELECT kind,value_key,first_seen_at,last_seen_at,seen_count FROM ioc_behavior_known WHERE asset_id=? ORDER BY kind,value_key', [$d]);
    assertSame([['destination', '1.1.1.1', '2026-10-05 00:00:00', '2026-10-18 00:00:00', 3], ['port', '443', '2026-10-06 00:00:00', '2026-10-25 00:00:00', 7]],
        array_map(fn ($r) => [$r['kind'], $r['value_key'], $r['first_seen_at'], $r['last_seen_at'], (int) $r['seen_count']], $k), '新的複製；重複的：first 取較早、last 取較晚、次數相加');

    $addr = dbtAll($db, 'SELECT address,first_seen_at,evidence_kind FROM ioc_behavior_addresses WHERE asset_id=? ORDER BY id', [$d]);
    assertSame(2, count($addr));
    assertSame(['172.16.3.1', 'librenms', '2026-10-02 00:00:00'], [$addr[0]['address'], $addr[0]['evidence_kind'], $addr[0]['first_seen_at']], '設備本來就有的位址：不重複新增、保留原本的證據種類、起點取較早的');
    assertSame(['172.16.3.99', 'admin_confirmed'], [$addr[1]['address'], $addr[1]['evidence_kind']]);

    assertSame(['learning', '2026-10-01 00:00:00'], [dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$d]), dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$d])]);
    assertSame('retired', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$o]));
    $link = dbtRow($db, 'SELECT * FROM ioc_behavior_links WHERE observed_asset_id=?', [$o]);
    assertSame([$d, 'admin', '核對過 DHCP 租約與 MAC'], [(int) $link['target_asset_id'], $link['confirmed_by'], $link['evidence_note']]);
    $detail = (string) dbtVal($db, "SELECT detail FROM ioc_behavior_history WHERE asset_id=? AND action='linked_observation'", [$d]);
    assertTrue(strpos($detail, '#' . $o) !== false && strpos($detail, '複製區間 1') !== false && strpos($detail, '取代 1') !== false && strpos($detail, '合併 2') !== false, $detail);
    assertSame(['copy' => 1, 'replace' => 1, 'combine' => 2, 'keep' => 2], $preview['windows'], '預覽的數字與實際合併一致');
    assertSame('asset:' . $o . '→' . $d, dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='asset_linked'"));
});

dbtest('linkAsset 合併：設備已有基線或在監控中 → 設備保持自己的模式與學習起點；已確認、已訓練、監控中的暫時資產也可以關聯；警示留在原資產；預覽會提醒', static function (PDO $db): void {
    [$o, $d] = bmdScenario($db, true, 'monitoring');
    $svc = new BehaviorService($db);
    $db->prepare("UPDATE ioc_behavior_assets SET mode='monitoring',baseline_version=2,quality_status='ready',site_name='台中' WHERE id=?")->execute([$o]);
    $db->prepare('UPDATE ioc_behavior_assets SET baseline_version=1 WHERE id=?')->execute([$d]);
    $db->prepare("UPDATE ioc_behavior_assets SET mode='ready' WHERE id=?")->execute([$d]);       // 設備有基線、尚未啟用監控
    $alert = dbtAlert($db, $o);
    $p = $svc->previewLink($o, 30, DBT_H);
    assertSame(null, $p['learning_started_after'], '設備已經有基線：學習起點不動');
    $text = implode("\n", $p['warnings']);
    foreach (['監控中', '不會延續', '站點', '警示'] as $needle) {
        assertTrue(strpos($text, $needle) !== false, '提醒裡要有「' . $needle . '」：' . $text);
    }
    $svc->linkAsset($o, 30, '核對過', 'admin', DBT_H);
    assertSame(['ready', 1, '2026-10-20 00:00:00'], [dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$d]), (int) dbtVal($db, 'SELECT baseline_version FROM ioc_behavior_assets WHERE id=?', [$d]),
        dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$d])], '設備保持自己的模式、基線與學習起點');
    assertSame($o, (int) dbtVal($db, 'SELECT asset_id FROM ioc_behavior_alerts WHERE id=?', [$alert]), '開著的警示與事件案留在原資產上');
    assertSame('retired', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$o]));
});

dbtest('linkAsset：權限、輸入與對象檢查；被拒絕的關聯不留任何痕跡', static function (PDO $db): void {
    [$o, $d] = bmdScenario($db);
    $svc = new BehaviorService($db);
    $other = $svc->syncDevice(31, 'fw-4', '172.16.4.1', '台南');
    $retiredDev = $svc->syncDevice(32, 'fw-5', '172.16.5.1', '台南');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$retiredDev]);
    $gone = $svc->observeUnknown('172.16.6.6');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$gone]);
    $claimed = $svc->observeUnknown('172.16.7.7');
    bmdLinked($db, $other, '172.16.7.7');                                        // 這個位址已經關聯給設備 31
    $before = bmdSnapshot($db);

    assertThrows(AccessDenied::class, fn () => $svc->linkAsset($o, 30, '依據', 'viewer', DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->linkAsset($o, 30, '依據', 'nobody', null));
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($o, 30, '  ', 'admin', DBT_H), '依據');
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($d, 30, '依據', 'admin', DBT_H), '暫時設備', '設備資產不能當暫時資產');
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($gone, 30, '依據', 'admin', DBT_H), '暫時設備', '已退役的暫時資產');
    assertThrows(NotFound::class, fn () => $svc->linkAsset($o, 999, '依據', 'admin', DBT_H), '尚未同步');
    assertThrows(NotFound::class, fn () => $svc->linkAsset(424242, 30, '依據', 'admin', DBT_H));
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($o, 32, '依據', 'admin', DBT_H), '已停用', '設備的資產已退役');
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($claimed, 30, '依據', 'admin', DBT_H), '已關聯到設備資產', '位址已經關聯給別台設備');
    assertThrows(ValidationError::class, fn () => $svc->previewLink($claimed, 30, DBT_H), '已關聯到設備資產', '預覽也一樣擋');
    assertSame($before, bmdSnapshot($db), '被拒絕的關聯與預覽不能改到任何資料');
});

dbtest('linkAsset：合併中途失敗整筆不動（交易）', static function (PDO $db): void {
    [$o, $d] = bmdScenario($db);
    $svc = new BehaviorService($db);
    $db->prepare("INSERT INTO ioc_behavior_links (observed_asset_id,target_asset_id,confirmed_by,confirmed_at,evidence_note) VALUES (?,?,'x',UTC_TIMESTAMP(),'x')")->execute([$o, $d]);   // 讓最後寫關聯的那一步失敗
    $before = bmdSnapshot($db);
    assertThrows(PDOException::class, fn () => $svc->linkAsset($o, 30, '依據', 'admin', DBT_H));
    assertSame($before, bmdSnapshot($db), '前面已經做完的區間、已知目的地、位址與學習起點都要還原');
    assertSame(false, $db->inTransaction());
});

dbtest('關聯之後：暫時資產位址的流量進設備、不再另建暫時資產；已確認主機累積的歷史接上，設備隨後訓練出基線', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $d = $svc->syncDevice(1, 'fw-1', '172.16.1.11', '台南');
    $o = $svc->observeUnknown('172.16.9.9');
    $svc->confirmHosts([['id' => $o, 'name' => '財務部 PC-01']], '核對過', 'admin', DBT_H);
    $db->prepare('UPDATE ioc_behavior_assets SET learning_started_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 29 * 86400), $o]);
    bcdBulkWindows($db, $o, 6048, intdiv(time() - 27 * 86400, 300) * 300);
    $svc->linkAsset($o, 1, '核對過 DHCP 租約與 MAC', 'admin', DBT_H);
    assertSame(6048, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_windows WHERE asset_id=? AND quality='complete'", [$d]), '歷史區間接到設備上');
    assertSame(gmdate('Y-m-d H', time() - 29 * 86400), substr((string) dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$d]), 0, 13), '設備的學習起點接上暫時資產的');

    $traffic = bcdNormal('172.16.9.9') + bcdNormal('172.16.1.11');
    (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick([bcdDevice(1, '172.16.1.11')], bcdNets(), time());
    assertSame(1, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:%'"), '沒有再多出暫時資產（只有已退役的那一個）');
    $w = dbtRow($db, 'SELECT connections,quality FROM ioc_behavior_windows WHERE asset_id=? ORDER BY window_start DESC LIMIT 1', [$d]);
    assertSame([40, 'complete'], [(int) $w['connections'], $w['quality']], '兩個位址（LibreNMS 的 IP 與確認過的 .9.9）的流量併成設備的一筆');
    assertSame(['ready', 1], [dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$d]), (int) dbtVal($db, 'SELECT baseline_version FROM ioc_behavior_assets WHERE id=?', [$d])], '接上歷史後，設備馬上有足夠的資料訓練出基線');
});

// ---- 建議關聯與解除位址 ----

dbtest('state：管理者看得到「建議關聯」（觀測或已確認的主機，位址等於某台設備最近的 LibreNMS 位址）；檢視者看不到；退役的、位址不符的、設備位址已過期的都不列', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(40, 'fw-6', '172.16.6.6', '台南');
    $stale = $svc->syncDevice(41, 'fw-7', '172.16.7.7', '台南');
    $db->prepare("UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() - INTERVAL 3 HOUR WHERE asset_id=? AND address='172.16.7.7'")->execute([$stale]);
    $match = $svc->observeUnknown('172.16.6.6');
    $confirmed = $svc->observeUnknown('172.16.6.6');            // 同一個 IP：沿用同一個暫時資產
    assertSame($match, $confirmed);
    $none = $svc->observeUnknown('172.16.8.8');
    $old = $svc->observeUnknown('172.16.7.7');                  // 設備的這個位址已經 3 小時沒看到：LibreNMS 已經不是這個 IP
    $retired = dbtAsset($db, ['identity_key' => 'observed:172.16.6.6:gone', 'name' => '退役', 'mode' => 'retired']);
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,'172.16.6.6',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'observed')")->execute([$retired]);
    $svc->confirmHosts([['id' => $match]], '核對過', 'admin', DBT_H);

    $s = $svc->state(DBT_H)['link_suggestions'];
    assertSame(1, count($s), '只有一筆符合');
    assertSame([$match, $dev, 40, '172.16.6.6', true], [$s[0]['observed_id'], $s[0]['device_asset_id'], (int) $s[0]['device_id'], $s[0]['address'], (bool) $s[0]['observed_confirmed']]);
    assertTrue(!isset($svc->state(DBT_V)['link_suggestions']), '檢視者拿不到任何建議');
    unset($none, $old);
});

dbtest('unlinkAddress：只有管理者；只能解除管理員確認過的位址；解除後採集器不再把那個位址的流量對到這台設備', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    $o = $svc->observeUnknown('172.16.9.9');
    $svc->linkAsset($o, 1, '核對過 DHCP', 'admin', DBT_H);
    $temp = $svc->observeUnknown('172.16.9.8');
    $before = bmdSnapshot($db);

    assertThrows(AccessDenied::class, fn () => $svc->unlinkAddress($dev, '172.16.9.9', 'viewer', DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->unlinkAddress($dev, '172.16.9.9', 'nobody', null));
    assertThrows(NotFound::class, fn () => $svc->unlinkAddress(424242, '172.16.9.9', 'admin', DBT_H));
    assertThrows(ValidationError::class, fn () => $svc->unlinkAddress($dev, 'not-an-ip', 'admin', DBT_H), 'IP');
    assertThrows(ValidationError::class, fn () => $svc->unlinkAddress($temp, '172.16.9.8', 'admin', DBT_H), '設備', '暫時資產沒有可解除的位址');
    assertThrows(ValidationError::class, fn () => $svc->unlinkAddress($dev, '172.16.1.10', 'admin', DBT_H), '管理員確認過', 'LibreNMS 自己的位址不能解除');
    assertThrows(ValidationError::class, fn () => $svc->unlinkAddress($dev, '172.16.5.5', 'admin', DBT_H), '管理員確認過', '這台沒有的位址');
    assertSame($before, bmdSnapshot($db), '被拒絕的解除不能改到任何資料');

    $svc->unlinkAddress($dev, '172.16.9.9', 'admin', DBT_H);
    assertSame(['172.16.1.10'], bmdAddresses($db, $dev), '只移除那個位址，其他位址留著');
    assertSame([], $svc->linkedAddresses());
    assertTrue(in_array('address_unlinked', dbtHistory($db, $dev), true));
    assertSame('asset:' . $dev, dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='address_unlinked'"));
    assertTrue(strpos((string) dbtVal($db, "SELECT detail FROM ioc_behavior_history WHERE asset_id=? AND action='address_unlinked'", [$dev]), '172.16.9.9') !== false);

    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdNormal('172.16.9.9'))))->tick([bcdDevice(1, '172.16.1.10')], bcdNets(), bcdNow());
    assertSame(1, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:172.16.9.9:%' AND mode<>'retired'"), '解除後，這個位址的流量又變成待確認的暫時資產');
    assertSame('missing', bcdWindows($db, $dev)[0]['quality'], '設備自己的位址沒流量');
});

dbtest('tick：LibreNMS 的舊 IP 不對應（只認管理員確認過的位址；舊 IP 可能已被 DHCP 租給別台）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    $svc->syncDevice(1, 'fw-a', '172.16.1.11', '台南');        // 設備換了 IP：.10 留在位址歷史裡（evidence=librenms）
    assertSame([], $svc->linkedAddresses(), 'LibreNMS 自己回報過的位址不算「管理員確認過」');
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bmdTraffic('172.16.1.10', 6, 600, ['203.0.113.1' => 6], ['443' => 6]))))->tick([bcdDevice(1, '172.16.1.11')], bcdNets(), bcdNow());
    assertSame(1, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:172.16.1.10:%'"), '舊 IP 的流量是待確認的暫時資產，不算給這台設備');
    assertSame('missing', bcdWindows($db, $dev)[0]['quality']);
});

dbtest('state：devices 列出所有能接收關聯的設備（資產清單只有最新 300 筆，設備不能被擠掉）；停用的不列；檢視者拿不到', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-a', '172.16.1.10', null);
    $gone = $svc->syncDevice(2, 'fw-gone', null, null);
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$gone]);
    for ($i = 0; $i < 305; $i++) {
        dbtAsset($db, ['identity_key' => 'observed:bulk' . $i]);
    }
    $s = $svc->state(DBT_H);
    assertSame(300, count($s['assets']), '資產清單只有最新 300 筆');
    assertTrue(!in_array($dev, array_column($s['assets'], 'id'), true), '設備資產 id 小，被擠出清單了');
    assertSame([['id' => $dev, 'device_id' => 1, 'name' => 'fw-a']], $s['devices'], '但關聯的選單還是看得到它');
    assertTrue(!isset($svc->state(DBT_V)['devices']));
});

// ---- code-review 後的修正：位址歸屬、衝突、事件案位址、預覽與執行一致、採集器與關聯互斥、設備存活、提醒 ----

dbtest('關聯：暫時資產的位址目前是另一台設備的 LibreNMS 位址 → 擋下（流量仍會算給那台，關聯等於白做）；那台的位址已過期就可以', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    $d2 = $svc->syncDevice(2, 'fw-b', '172.16.5.5', '台南');
    $o = $svc->observeUnknown('172.16.5.5');
    $before = bmdSnapshot($db);
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($o, 1, '依據', 'admin', DBT_H), '目前是 LibreNMS 設備', '正式關聯擋下');
    assertThrows(ValidationError::class, fn () => $svc->previewLink($o, 1, DBT_H), 'fw-b', '預覽也擋下，並說出是哪一台');
    assertSame($before, bmdSnapshot($db), '被擋下不能改到任何資料');
    $svc->previewLink($o, 2, DBT_H);                                    // 選對那一台就沒問題
    $db->prepare("UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() - INTERVAL 3 HOUR WHERE asset_id=? AND address='172.16.5.5'")->execute([$d2]);
    $svc->linkAsset($o, 1, '依據', 'admin', DBT_H);                      // 設備 2 已經 3 小時沒回報這個位址：LibreNMS 已不是這個 IP
    assertSame('retired', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$o]));
});

dbtest('tick：兩台設備宣告同一個已確認位址 → 兩台都拿到 partial 的區間（衝突看得到、沒有人被餓死）；兩邊的位址都更新最近看到', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $b = $svc->syncDevice(2, 'fw-b', '172.16.1.20', '台南');
    $c = $svc->syncDevice(3, 'fw-c', '172.16.1.30', '台南');
    bmdLinked($db, $b, '172.16.1.88', 5);
    bmdLinked($db, $c, '172.16.1.88', 5);
    $devices = [bcdDevice(2, '172.16.1.20', '台南', 'fw-b'), bcdDevice(3, '172.16.1.30', '台南', 'fw-c')];
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bmdTraffic('172.16.1.88', 9, 900, ['203.0.113.2' => 9], ['443' => 9]))))->tick($devices, bcdNets(), bcdNow());
    foreach ([$b, $c] as $id) {
        $w = bcdWindows($db, $id);
        assertSame(['partial', '同一個 IP 對到多台設備', 9], [$w[0]['quality'], $w[0]['quality_reason'], (int) $w[0]['connections']], '兩台都看得到衝突');
        assertTrue((int) dbtVal($db, "SELECT TIMESTAMPDIFF(HOUR,last_seen_at,UTC_TIMESTAMP()) FROM ioc_behavior_addresses WHERE asset_id=? AND address='172.16.1.88'", [$id]) < 1, '兩邊的位址都更新了最近看到');
    }
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'), 'partial 不評估，不會有警示');
});

dbtest('事件案的位址：多個位址的資產用「真的有異常流量的那個位址」，不是 LibreNMS 每輪都會刷新的那個', static function (PDO $db): void {
    $id = bcdMonitored($db, 'monitoring');
    bmdLinked($db, $id, '172.16.1.77', -1);                             // 最近看到排在未來：舊的「最近看到」規則會誤選它
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge('172.16.1.10'))))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:07:30'));
    assertSame('172.16.1.10', dbtVal($db, 'SELECT subject_ip FROM ioc_cases ORDER BY id LIMIT 1'), '異常流量來自 .10');
    $metrics = json_decode((string) dbtVal($db, 'SELECT metrics_json FROM ioc_behavior_alerts ORDER BY id LIMIT 1'), true);
    assertSame('172.16.1.10', $metrics['source_ip'], '警示指標記下流量來源位址（只有管理者看得到；Webhook 不含）');
    dbtAlert($db, $id);                                                 // 沒有 source_ip 的舊警示（指標是空的）
    (new BehaviorCollector($db, DbtFakeLogs::traffic([])))->repairOpenCases();
    assertSame('172.16.1.77', dbtVal($db, 'SELECT subject_ip FROM ioc_cases ORDER BY id DESC LIMIT 1'), '沒有來源位址的舊警示：退回最近看到的位址');
});

dbtest('linkAsset：預覽的 plan_token 與執行時重算的不同（設備模式變了、出現新提醒）就拒絕並要求重新預覽；只有區間數字在動不算', static function (PDO $db): void {
    [$o, $d] = bmdScenario($db);
    $svc = new BehaviorService($db);
    $p = $svc->previewLink($o, 30, DBT_H);
    assertTrue(is_string($p['token']) && strlen($p['token']) >= 32);
    bmdWin($db, $o, bmdT(20), 'complete', 1, 1, ['9.9.9.9'], [443]);        // 採集器每五分鐘都會新增區間：預覽之後多一格是正常的
    assertSame($p['token'], $svc->previewLink($o, 30, DBT_H)['token'], '區間個數在動，不影響 token');

    $db->prepare("UPDATE ioc_behavior_assets SET mode='monitoring',baseline_version=1 WHERE id=?")->execute([$d]);     // 預覽之後設備被啟用監控
    $before = bmdSnapshot($db);
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($o, 30, '依據', 'admin', DBT_H, $p['token']), '重新預覽');
    assertSame($before, bmdSnapshot($db), '拒絕時不能改到任何資料');
    $fresh = $svc->previewLink($o, 30, DBT_H);
    assertTrue($fresh['token'] !== $p['token']);
    $svc->linkAsset($o, 30, '依據', 'admin', DBT_H, $fresh['token']);
    assertSame('retired', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$o]));
});

dbtest('採集器與關聯互斥（資料庫命名鎖）：採集器寫入中，關聯等不到鎖就拒絕、不動資料；關聯中，採集器整個 tick 略過、下一輪補上；成功或失敗都會放鎖', static function (PDO $db): void {
    [$o, $d] = bmdScenario($db);
    $svc = new BehaviorService($db);
    $svc->lockWait = 0;
    $other = dbtSecondPdo();
    $lock = static fn (PDO $c): int => (int) $c->query("SELECT GET_LOCK('" . $svc->lockName() . "',0)")->fetchColumn();
    $unlock = static fn (PDO $c) => $c->query("SELECT RELEASE_LOCK('" . $svc->lockName() . "')")->fetchColumn();

    assertSame(1, $lock($other));
    $before = bmdSnapshot($db);
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($o, 30, '依據', 'admin', DBT_H), '採集器正在寫入');
    assertSame($before, bmdSnapshot($db), '拿不到鎖：不能改到任何資料');
    $unlock($other);

    // 失敗也要放鎖：預先塞一筆關聯讓最後一步失敗
    $db->prepare("INSERT INTO ioc_behavior_links (observed_asset_id,target_asset_id,confirmed_by,confirmed_at,evidence_note) VALUES (?,?,'x',UTC_TIMESTAMP(),'x')")->execute([$o, $d]);
    assertThrows(PDOException::class, fn () => $svc->linkAsset($o, 30, '依據', 'admin', DBT_H));
    assertSame(1, $lock($other), '失敗之後鎖已放掉');
    $unlock($other);
    $db->exec('DELETE FROM ioc_behavior_links');
    $svc->linkAsset($o, 30, '依據', 'admin', DBT_H);
    assertSame(1, $lock($other), '成功之後鎖已放掉');
    $unlock($other);

    // 解除位址也要和採集器互斥
    assertSame(1, $lock($other));
    assertThrows(ValidationError::class, fn () => $svc->unlinkAddress($d, '172.16.3.99', 'admin', DBT_H), '採集器正在寫入');
    assertTrue(in_array('172.16.3.99', bmdAddresses($db, $d), true), '拿不到鎖：位址還在');
    $unlock($other);
    $svc->unlinkAddress($d, '172.16.3.99', 'admin', DBT_H);
    assertTrue(!in_array('172.16.3.99', bmdAddresses($db, $d), true));
    assertSame(1, $lock($other), '解除之後鎖也放掉了');
    $unlock($other);

    // 關聯進行中（鎖被別的連線持有）：採集器略過整個 tick，不寫區間、不前進，鎖放掉之後下一輪補上
    $c = new BehaviorCollector($db, DbtFakeLogs::traffic(bcdNormal('172.16.1.10')));
    $c->lockWait = 0;
    assertSame(1, $lock($other));
    $c->tick([bcdDevice(1, '172.16.1.10')], bcdNets(), bcdNow());
    assertSame([0, null], [count(bcdWindows($db, (int) dbtVal($db, "SELECT id FROM ioc_behavior_assets WHERE identity_key='librenms:1'"))), bcdSourceRow($db)['last_window_at'] ?? null], '拿不到鎖：不寫區間、來源沒前進');
    $skipped = bcdSourceRow($db);
    assertSame('waiting', $skipped['status'], '略過的 tick 要讓工作台看得到（不是安靜地什麼都沒發生，也不會因為沒更新而被誤報成採集器故障）');
    assertTrue(strpos($skipped['reason'], '關聯合併') !== false && (int) dbtVal($db, 'SELECT TIMESTAMPDIFF(MINUTE,checked_at,UTC_TIMESTAMP()) FROM ioc_behavior_sources') < 2, $skipped['reason']);
    $unlock($other);
    $c->tick([bcdDevice(1, '172.16.1.10')], bcdNets(), bcdNow());
    assertSame('ready', bcdSourceRow($db)['status'], '鎖放掉後的下一輪恢復正常');
    assertSame('2026-10-26 12:00:00', bcdSourceRow($db)['last_window_at'], '鎖放掉之後下一輪就補上同一個區間');
    assertSame(1, $lock($other), 'tick 做完也放了鎖');
    $unlock($other);
});

dbtest('tick：已關聯位址只算給「LibreNMS 設備清單裡還在的」設備；設備被移出 LibreNMS（但資產沒退役）後，那個位址的流量變回待確認的暫時資產', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    bmdLinked($db, $dev, '172.16.1.77');
    assertSame([$dev], $svc->linkedAddresses([1])['172.16.1.77'], '設備在清單裡：對得到');
    assertSame([], $svc->linkedAddresses([2, 3]), '設備不在清單裡：不認領');
    assertSame([$dev], $svc->linkedAddresses()['172.16.1.77'], '不傳清單＝不過濾（維持原行為）');
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bmdTraffic('172.16.1.77', 4, 400, ['203.0.113.1' => 4], ['443' => 4]))))->tick([], bcdNets(), bcdNow());
    assertSame([], bcdWindows($db, $dev), '不在 LibreNMS 清單裡的設備不寫區間');
    assertSame(1, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:172.16.1.77:%'"), '流量變回待確認的暫時資產');
});

dbtest('previewLink：合併後設備有多個位址時提醒「只要一個位址有流量就算有資料」；位址數不多於一個就不提醒', static function (PDO $db): void {
    [$o, $d] = bmdScenario($db);
    $svc = new BehaviorService($db);
    $text = implode("\n", $svc->previewLink($o, 30, DBT_H)['warnings']);
    assertTrue(strpos($text, '2 個位址') !== false && strpos($text, '日誌中斷') !== false, $text);
    $solo = $svc->syncDevice(31, 'fw-solo', '172.16.8.1', null);
    $same = $svc->observeUnknown('172.16.8.1');                          // 暫時資產的位址就是設備自己的位址：合併後仍只有一個位址
    assertSame([], $svc->previewLink($same, 31, DBT_H)['warnings']);
    unset($solo);
});

dbtest('state：mode_names 由伺服器給（畫面不再自己寫一份）；建議關聯不列已停用的設備', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    assertSame(['learning', 'ready', 'monitoring', 'retired'], array_keys($svc->state(DBT_H)['mode_names']));
    assertTrue(!isset($svc->state(DBT_V)['mode_names']));
    $gone = $svc->syncDevice(5, 'fw-gone', '172.16.6.6', null);
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$gone]);
    $svc->observeUnknown('172.16.6.6');
    assertSame([], $svc->state(DBT_H)['link_suggestions'], '已停用的設備不接收關聯，也不該被建議');
});

dbtest('關聯後第一輪：合併複製過來的位址最近看到若已超過 24 小時，設備歷程留一筆 reappeared', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $d = $svc->syncDevice(1, 'fw-1', '172.16.1.11', '台南');
    $o = $svc->observeUnknown('172.16.9.9');
    $db->prepare("UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() - INTERVAL 3 DAY WHERE asset_id=?")->execute([$o]);
    $svc->linkAsset($o, 1, '核對過', 'admin', DBT_H);
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdNormal('172.16.9.9'))))->tick([bcdDevice(1, '172.16.1.11')], bcdNets(), bcdNow());
    assertSame(1, count(array_keys(dbtHistory($db, $d), 'reappeared')), '位址 3 天沒出現，關聯後第一次又看到：提醒管理員確認');
});

dbtest('linkAsset：暫時資產沒有任何區間、已知目的地時也能關聯（計畫全是 0），設備原本的資料不動', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $d = $svc->syncDevice(1, 'fw-1', '172.16.1.11', '台南');
    bmdWin($db, $d, bmdT(1), 'complete', 3, 300, ['1.1.1.1'], [443]);
    $o = $svc->observeUnknown('172.16.9.9');
    $p = $svc->previewLink($o, 1, DBT_H);
    assertSame([['copy' => 0, 'replace' => 0, 'combine' => 0, 'keep' => 0], ['new' => 0, 'merge' => 0], ['new' => 1, 'existing' => 0]], [$p['windows'], $p['known'], $p['addresses']]);
    $svc->linkAsset($o, 1, '核對過', 'admin', DBT_H, $p['token']);
    assertSame([1, 3], [(int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_windows WHERE asset_id=?', [$d]), (int) bmdW($db, $d, bmdT(1))['connections']]);
    assertSame(['172.16.1.11', '172.16.9.9'], bmdAddresses($db, $d));
});

dbtest('tick：LibreNMS 裡停用（disabled）或被忽略（ignore）的設備仍認領它的位址：傳入的是「LibreNMS 裡所有還在的設備」；只有從 LibreNMS 刪掉的才放掉', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-a', '172.16.1.10', '台南');
    bmdLinked($db, $dev, '172.16.1.77');
    $logs = static fn () => DbtFakeLogs::traffic(bmdTraffic('172.16.1.77', 4, 400, ['203.0.113.1' => 4], ['443' => 4]));
    // 維修時在 LibreNMS 把設備停用：本輪的設備清單（只含啟用、未忽略的）是空的，但設備還在
    (new BehaviorCollector($db, $logs()))->tick([], bcdNets(), bcdNow('2026-10-26 12:07:30'), null, [1]);
    assertSame(1, count(bcdWindows($db, $dev)), '設備還在 LibreNMS（只是停用）：位址的流量照算給它');
    assertSame(0, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:%'"), '不會多出待確認的暫時資產');
    // 從 LibreNMS 刪掉：清單裡沒有它
    (new BehaviorCollector($db, $logs()))->tick([], bcdNets(), bcdNow('2026-10-26 12:12:30'), null, []);
    assertSame(1, count(bcdWindows($db, $dev)), '沒有再算給它');
    assertSame(1, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_assets WHERE identity_key LIKE 'observed:172.16.1.77:%'"), '流量變回待確認的暫時資產');
});

dbtest('命名鎖：名稱含資料庫名稱的雜湊（GET_LOCK 的名稱是整台伺服器共用的），別的資料庫用固定名稱不會擋到這裡', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $name = $svc->lockName();
    assertTrue(strpos($name, BehaviorService::TICK_LOCK . ':') === 0 && strlen($name) <= 64 && $name !== BehaviorService::TICK_LOCK, $name);
    assertSame($name, (new BehaviorService($db))->lockName(), '同一個資料庫每次都是同一個名稱');
    $other = dbtSecondPdo();
    assertSame(1, (int) $other->query("SELECT GET_LOCK('" . BehaviorService::TICK_LOCK . "',0)")->fetchColumn(), '別的資料庫（或舊版）持有固定名稱的鎖');
    [$o] = bmdScenario($db);
    $svc->lockWait = 0;
    $svc->linkAsset($o, 30, '依據', 'admin', DBT_H);
    assertSame('retired', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$o]), '不受影響');
    $other->query("SELECT RELEASE_LOCK('" . BehaviorService::TICK_LOCK . "')")->fetchColumn();
});

dbtest('事件案的位址：警示指標不是合法 JSON、或 source_ip 不是 IP 時退回最近看到的位址（不丟錯、也不把亂東西寫進案件）', static function (PDO $db): void {
    $id = dbtAsset($db, ['device_id' => 1, 'name' => 'fw', 'mode' => 'monitoring', 'baseline_version' => 1]);
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,'172.16.1.1',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'librenms')")->execute([$id]);
    $a1 = dbtAlert($db, $id);
    $a2 = dbtAlert($db, $id);
    $db->prepare("UPDATE ioc_behavior_alerts SET metrics_json='not json' WHERE id=?")->execute([$a1]);
    $db->prepare('UPDATE ioc_behavior_alerts SET metrics_json=? WHERE id=?')->execute(['{"source_ip":"<script>"}', $a2]);
    (new BehaviorCollector($db, DbtFakeLogs::traffic([])))->repairOpenCases();
    assertSame(['172.16.1.1', '172.16.1.1'], array_column(dbtAll($db, 'SELECT subject_ip FROM ioc_cases ORDER BY id'), 'subject_ip'));
});
