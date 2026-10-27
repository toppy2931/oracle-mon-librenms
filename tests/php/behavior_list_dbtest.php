<?php
declare(strict_types=1);

/**
 * 裝置生命週期清單的伺服器端分頁與排序（BehaviorService::listAssets）。真的 MariaDB，一次性的 ioc_test_* schema。
 */

use Ioc\AccessDenied;
use Ioc\BehaviorService;
use Ioc\ValidationError;

function bldList(BehaviorService $svc, string $view = 'lifecycle', int $page = 1, int $per = 10, string $sort = 'name', string $dir = 'asc'): array
{
    return $svc->listAssets($view, $page, $per, $sort, $dir, DBT_H);
}

/** 這組參數必須被拒絕（ValidationError）；失敗時帶出是哪一組。 */
function bldRejects(string $label, callable $fn): void
{
    try {
        $fn();
    } catch (ValidationError $e) {
        return;
    }
    fail('應該被拒絕：' . $label);
}

dbtest('listAssets：只有管理者；清單範圍、頁碼、每頁筆數、排序欄位與方向都走白名單（注入字串、不認得的值一律拒絕）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $svc->observeUnknown('172.16.9.1');
    assertThrows(AccessDenied::class, fn () => $svc->listAssets('lifecycle', 1, 10, 'name', 'asc', DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->listAssets('lifecycle', 1, 10, 'name', 'asc', null));
    foreach (['all', '', 'lifecycle;', 'Lifecycle'] as $view) {
        bldRejects('view ' . json_encode($view), fn () => bldList($svc, $view));
    }
    foreach ([0, 5, 9, 11, 101, 1000, -10] as $per) {
        bldRejects('每頁 ' . $per, fn () => bldList($svc, 'lifecycle', 1, $per));
    }
    foreach ([0, -1] as $page) {
        bldRejects('頁碼 ' . $page, fn () => bldList($svc, 'lifecycle', $page));
    }
    foreach (['bogus', 'name; DROP TABLE ioc_behavior_assets', 'name desc', '', 'a.name', '1', 'name,id', "name\0", 'NAME'] as $sort) {
        bldRejects('排序 ' . json_encode($sort), fn () => bldList($svc, 'lifecycle', 1, 10, $sort));
    }
    foreach (['ASC', 'asc ', 'up', '', 'asc;--', 'desc,id'] as $dir) {
        bldRejects('方向 ' . json_encode($dir), fn () => bldList($svc, 'lifecycle', 1, 10, 'name', $dir));
    }
    assertSame(1, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_assets'), '資料表還在、資料沒被動到');
    foreach (['name', 'kind', 'address', 'site', 'mode', 'quality', 'last_seen', 'confirmed_at'] as $ok) {
        foreach (['asc', 'desc'] as $dir) {
            assertSame(1, bldList($svc, 'lifecycle', 1, 10, $ok, $dir)['total'], '白名單內的欄位都能排：' . $ok . ' ' . $dir);
        }
    }
    foreach ([10, 25, 50, 100] as $per) {
        assertSame($per, bldList($svc, 'lifecycle', 1, $per)['per_page']);
    }
});

dbtest('listAssets：lifecycle 是 LibreNMS 設備＋待確認主機，confirmed 只有已確認主機；已移除的兩邊都不列；兩邊互斥', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-a', '172.16.1.1', '台南');
    $p1 = $svc->observeUnknown('172.16.9.1');
    $p2 = $svc->observeUnknown('172.16.9.2');
    $c1 = $svc->observeUnknown('172.16.9.3');
    $c2 = $svc->observeUnknown('172.16.9.5');
    $svc->confirmHosts([['id' => $c1], ['id' => $c2]], '核對過', 'admin', DBT_H);
    $gone = $svc->observeUnknown('172.16.9.4');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$gone]);
    $goneDev = $svc->syncDevice(2, 'fw-gone', null, null);
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$goneDev]);
    $goneConfirmed = $svc->observeUnknown('172.16.9.6');
    $svc->confirmHosts([['id' => $goneConfirmed]], '核對過', 'admin', DBT_H);
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$goneConfirmed]);
    // 邊界：LibreNMS 設備即使帶了 confirmed_at（不該發生）也只算設備，列在 lifecycle，不進「已確認主機」
    $devConfirmed = dbtAsset($db, ['device_id' => 9, 'identity_key' => 'librenms:9', 'name' => 'fw-confirmed', 'confirmed_at' => gmdate('Y-m-d H:i:s'), 'confirmed_by' => 'x']);

    $life = bldList($svc, 'lifecycle', 1, 100);
    $conf = bldList($svc, 'confirmed', 1, 100);
    $ids = static fn (array $r): array => array_map('intval', array_column($r['rows'], 'id'));
    $a = $ids($life); sort($a);
    $b = $ids($conf); sort($b);
    assertSame([$dev, $p1, $p2, $devConfirmed], $a, '設備與待確認');
    assertSame([$c1, $c2], $b, '已確認');
    assertSame([4, 2], [$life['total'], $conf['total']]);
    assertSame([], array_values(array_intersect($a, $b)), '兩邊互斥');
});

dbtest('listAssets：分頁——總筆數、頁數、每頁筆數；超過最後一頁就回最後一頁；沒有資料是 1 頁 0 筆；頁與頁之間不重複', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $e = bldList($svc);
    assertSame([0, 1, 1, []], [$e['total'], $e['pages'], $e['page'], $e['rows']]);
    for ($i = 1; $i <= 25; $i++) { $svc->observeUnknown('172.16.9.' . $i); }
    $p1 = bldList($svc, 'lifecycle', 1, 10);
    $p2 = bldList($svc, 'lifecycle', 2, 10);
    $p3 = bldList($svc, 'lifecycle', 3, 10);
    assertSame([25, 3, 10, 10, 5], [$p1['total'], $p1['pages'], count($p1['rows']), count($p2['rows']), count($p3['rows'])]);
    assertSame(25, count(array_unique(array_merge(array_column($p1['rows'], 'id'), array_column($p2['rows'], 'id'), array_column($p3['rows'], 'id')))), '25 筆各出現一次');
    $far = bldList($svc, 'lifecycle', 99, 10);
    assertSame([3, 5], [$far['page'], count($far['rows'])], '超過最後一頁：回最後一頁');
    $all = bldList($svc, 'lifecycle', 1, 25);
    assertSame([1, 25, 1], [$all['pages'], count($all['rows']), $all['page']]);
});

dbtest('listAssets：排序——名稱、位址（依數值：.2 在 .10 前）、最近觀測、身分、站點；空值排最後；方向可反轉；同值用 id 穩定排序', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $addr = static function (int $asset, string $ip, ?string $seen) use ($db): void {
        $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'observed')")->execute([$asset, $ip]);
        if ($seen !== null) { $db->prepare('UPDATE ioc_behavior_assets SET last_seen_at=? WHERE id=?')->execute([$seen, $asset]); }
    };
    $a = dbtAsset($db, ['device_id' => 1, 'identity_key' => 'librenms:1', 'name' => 'b-host', 'site_name' => '台南']);
    $addr($a, '172.16.1.10', '2026-10-26 10:00:00');
    $b = dbtAsset($db, ['identity_key' => 'observed:172.16.1.2:x', 'name' => 'a-host']);
    $addr($b, '172.16.1.2', null);
    $c = dbtAsset($db, ['identity_key' => 'observed:172.16.1.100:x', 'name' => 'c-host', 'site_name' => '台中']);
    $addr($c, '172.16.1.100', '2026-10-26 12:00:00');
    $d = dbtAsset($db, ['identity_key' => 'observed:172.16.1.50:x', 'name' => 'c-host']);       // 與 c 同名：用 id 排
    $addr($d, '172.16.1.50', '2026-10-26 11:00:00');
    $order = static fn (string $sort, string $dir): array => array_map('intval', array_column(bldList($svc, 'lifecycle', 1, 10, $sort, $dir)['rows'], 'id'));

    assertSame([$b, $a, $c, $d], $order('name', 'asc'), '名稱，同名依 id');
    assertSame([$c, $d, $a, $b], $order('name', 'desc'), '反過來：同名仍依 id 由小到大');
    assertSame([$b, $a, $d, $c], $order('address', 'asc'), '位址依數值：.2 < .10 < .50 < .100');
    assertSame([$c, $d, $a, $b], $order('address', 'desc'));
    assertSame([$c, $d, $a, $b], $order('last_seen', 'desc'), '最近觀測新到舊，沒有的排最後');
    assertSame([$a, $d, $c, $b], $order('last_seen', 'asc'), '舊到新，沒有的仍排最後');
    assertSame($a, $order('kind', 'asc')[0], '身分：LibreNMS 設備在前');
    assertSame($a, $order('kind', 'desc')[3], '反過來：設備在後');
    assertSame([$b, $d], array_slice($order('site', 'asc'), 2), '沒有站點的排最後，彼此依 id');
    assertSame([$b, $d], array_slice($order('site', 'desc'), 2), '反過來也一樣排最後');
});

dbtest('listAssets：每列的欄位；位址取最近看到的；已確認的列帶確認者與確認時間', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = $svc->observeUnknown('172.16.9.9');
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,'172.16.9.8',UTC_TIMESTAMP(),UTC_TIMESTAMP() - INTERVAL 2 HOUR,'observed')")->execute([$id]);
    $svc->confirmHosts([['id' => $id, 'name' => '財務部 PC-01']], '核對過', 'admin', DBT_H);
    $row = bldList($svc, 'confirmed')['rows'][0];
    foreach (['id', 'identity_key', 'device_id', 'name', 'site_name', 'mode', 'learning_started_at', 'baseline_version', 'last_seen_at', 'quality_status', 'confirmed_at', 'confirmed_by', 'address'] as $key) {
        assertTrue(array_key_exists($key, $row), '缺欄位：' . $key);
    }
    assertSame([$id, '財務部 PC-01', '172.16.9.9', 'admin', 'learning', 0], [$row['id'], $row['name'], $row['address'], $row['confirmed_by'], $row['mode'], $row['baseline_version']]);
    assertTrue(is_int($row['id']) && is_int($row['baseline_version']), 'id 與基線版本是整數');
    assertSame(null, $row['device_id']);
    assertTrue($row['confirmed_at'] !== null, '帶確認時間');
});

dbtest('state 不再回資產清單（沒有 300 筆上限，改由 listAssets 分頁取得）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    for ($i = 0; $i < 305; $i++) { dbtAsset($db, ['identity_key' => 'observed:bulk' . $i]); }
    assertTrue(!array_key_exists('assets', $svc->state(DBT_H)), 'state 不該帶 assets');
    assertSame(305, bldList($svc, 'lifecycle', 1, 10)['total'], '305 筆都在，分頁取得');
});
