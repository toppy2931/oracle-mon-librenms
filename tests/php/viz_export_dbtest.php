<?php
declare(strict_types=1);

/**
 * 連線視圖匯出（BehaviorVizExport）：真的 MariaDB，一次性的 ioc_test_* schema。
 * LibreNMS 的 devices／locations 表在測試 schema 裡不存在，用到座標的測試自己建兩張最小的表。
 */

use Ioc\AccessDenied;
use Ioc\BehaviorVizExport;

const VZ_NOW = '2026-10-27 08:00:00';

function vzNow(): int { return (int) strtotime(VZ_NOW . ' UTC'); }
function vzAt(int $slot, int $hours = 2): string { return gmdate('Y-m-d H:i:s', vzNow() - $hours * 3600 + $slot * 300); }

function vzWin(PDO $db, int $asset, string $start, int $conn, int $bytes, array $dest, array $ports, string $q, ?int $destN = null): void
{
    $db->prepare("INSERT INTO ioc_behavior_windows (asset_id,source_key,window_start,connections,bytes_total,destinations,ports,destinations_json,ports_json,signals_json,quality,quality_reason,observed_at)
        VALUES (?,'fortigate_graylog',?,?,?,?,?,?,?,'{}',?,'',UTC_TIMESTAMP())")
        ->execute([$asset, $start, $conn, $bytes, $destN ?? count($dest), count($ports), json_encode($dest), json_encode($ports), $q]);
}
function vzAddr(PDO $db, int $asset, string $ip): void
{
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'observed')")->execute([$asset, $ip]);
}
function vzKnown(PDO $db, int $asset, string $ip, string $first): void
{
    $db->prepare("INSERT INTO ioc_behavior_known (asset_id,source_key,kind,value_key,first_seen_at,last_seen_at,seen_count) VALUES (?,'fortigate_graylog','destination',?,?,?,3)")->execute([$asset, $ip, $first, $first]);
}
function vzBaseline(PDO $db, int $asset, int $version, string $kind, int $hour, float $p95c, float $p95b, int $n): void
{
    $db->prepare("INSERT INTO ioc_behavior_baselines (asset_id,version,source_key,day_kind,hour_of_day,median_bytes,p95_bytes,mad_bytes,median_connections,p95_connections,mad_connections,median_destinations,p95_destinations,mad_destinations,sample_count,created_at)
        VALUES (?,?,'fortigate_graylog',?,?,1,?,1,1,?,1,1,1,1,?,UTC_TIMESTAMP())")->execute([$asset, $version, $kind, $hour, $p95b, $p95c, $n]);
}
function vzAlert(PDO $db, int $asset, string $start, string $summary): void
{
    $db->prepare("INSERT INTO ioc_behavior_alerts (dedup_key,asset_id,detector,window_start,baseline_version,severity,status,score,summary,metrics_json,first_seen_at,last_seen_at)
        VALUES (?,?,'after_hours_surge',?,1,'high','open',90,?,'{}',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([hash('sha256', $asset . $start . $summary), $asset, $start, $summary]);
}
function vzLibreNms(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS devices (device_id INT PRIMARY KEY, location_id INT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS locations (id INT PRIMARY KEY, lat DOUBLE NULL, lng DOUBLE NULL)');
    $db->exec('TRUNCATE TABLE devices'); $db->exec('TRUNCATE TABLE locations');
}

/** 兩台主機的標準情境：A 是監控中的 LibreNMS 設備（有流量、基線、警示），B 是待確認的觀測主機（完全沒有區間）。 */
function vzScenario(PDO $db): array
{
    vzLibreNms($db);
    $db->exec("INSERT INTO locations VALUES (5, 23.0123, 120.2456)"); $db->exec('INSERT INTO devices VALUES (13, 5)');
    $a = dbtAsset($db, ['device_id' => 13, 'identity_key' => 'librenms:13', 'name' => '財務部 PC-01', 'site_name' => '台南', 'mode' => 'monitoring', 'baseline_version' => 1, 'last_seen_at' => vzAt(20)]);
    $b = dbtAsset($db, ['identity_key' => 'observed:172.16.9.9:x', 'name' => '待確認設備 172.16.9.9', 'last_seen_at' => vzAt(10)]);
    $gone = dbtAsset($db, ['identity_key' => 'observed:172.16.9.10:x', 'name' => '已移除主機', 'mode' => 'retired', 'last_seen_at' => vzAt(23)]);
    vzAddr($db, $a, '172.16.111.2'); vzAddr($db, $b, '172.16.9.9'); vzAddr($db, $gone, '172.16.9.10');
    vzWin($db, $a, vzAt(0), 100, 1000, ['203.0.113.5', '203.0.113.77'], [443, 53], 'complete', 4);
    vzWin($db, $a, vzAt(1), 80, 800, ['203.0.113.5', '198.51.100.9'], [443], 'partial');
    vzWin($db, $a, vzAt(2), 7, 70, ['192.0.2.1'], [8080], 'missing');               // 缺資料的格子：就算資料表裡帶了數字，目的地、埠與數字都不能匯出
    vzWin($db, $a, vzAt(5), 60, 600, ['203.0.113.9', 'not-an-ip'], [443], 'complete');
    vzWin($db, $gone, vzAt(0), 5, 50, ['203.0.113.1'], [443], 'complete');
    vzKnown($db, $a, '203.0.113.5', '2026-10-20 01:00:00');
    vzKnown($db, $a, '203.0.113.77', vzAt(0));
    vzKnown($db, $a, '198.51.100.9', vzAt(2));
    vzBaseline($db, $a, 1, 'weekday', 2, 40, 4000, 10); vzBaseline($db, $a, 1, 'weekday', 3, 60, 6000, 10); vzBaseline($db, $a, 1, 'weekend', 5, 20, 2000, 10);
    vzBaseline($db, $a, 1, 'weekday', 12, 900, 90000, 10);      // 上班時段：不算
    vzBaseline($db, $a, 1, 'weekend', 12, 1000, 10000, 10);     // 週末：不論幾點都算離峰
    vzBaseline($db, $a, 1, 'weekday', 4, 9999, 99999, 3);       // 樣本不足：不算
    vzBaseline($db, $a, 2, 'weekday', 2, 7777, 77777, 10);      // 不是目前版本：不算
    vzAlert($db, $a, vzAt(1), '離峰連線暴增');
    vzAlert($db, $a, gmdate('Y-m-d H:i:s', vzNow() - 5 * 3600), '兩小時視窗之外的舊警示');
    return [$a, $b, $gone];
}

function vzExport(PDO $db, array $opts = [], ?string $level = DBT_H): array
{
    return (new BehaviorVizExport($db))->export($opts + ['hours' => 2, 'now' => vzNow()], $level);
}

dbtest('連線視圖匯出（預設遮罩）：資料形狀、固定格數、缺的格子是 missing、缺資料的格子不算目的地與埠、已移除的不匯出', static function (PDO $db): void {
    [$a, $b] = vzScenario($db);
    $x = vzExport($db);
    assertSame(['ioc-behavior-viz/1', false, true, 2, 5], [$x['schema'], $x['demo'], $x['masked'], $x['hours'], $x['window_minutes']]);
    assertSame('2026-10-27T08:00:00Z', $x['generated_at']);
    assertSame(2, count($x['assets']), '已移除的不匯出');
    [$ma, $mb] = $x['assets'];
    assertSame(['主機 A', 1, 'device', 'monitoring', 1, '台南'], [$ma['label'], $ma['id'], $ma['kind'], $ma['mode'], $ma['baseline_version'], $ma['site']], '依最近觀測排序：A 是比較新的那台');
    assertSame(['主機 B', 2, 'observed', 'learning', 0, null], [$mb['label'], $mb['id'], $mb['kind'], $mb['mode'], $mb['baseline_version'], $mb['site']]);
    assertSame(['172.16.111.x', '172.16.9.x'], [$ma['address'], $mb['address']]);
    assertSame(24, count($ma['windows']));
    assertSame(['2026-10-27T06:00:00Z', 100, 1000, 4, 2, 'complete'], array_values($ma['windows'][0]));
    assertSame('partial', $ma['windows'][1]['q']);
    assertSame(['2026-10-27T06:10:00Z', 0, 0, 0, 0, 'missing'], array_values($ma['windows'][2]), '資料表裡就是 missing 的格子');
    assertSame(['2026-10-27T06:15:00Z', 0, 0, 0, 0, 'missing'], array_values($ma['windows'][3]), '資料表裡沒有這一列：也是 missing，不是零流量');
    assertSame('complete', $ma['windows'][5]['q']);
    assertSame('2026-10-27T07:55:00Z', $ma['windows'][23]['t']);
    assertSame(array_fill(0, 24, 'missing'), array_column($mb['windows'], 'q'), '完全沒有區間：整排都是 missing');
    assertSame([], $mb['destinations']);
    $byGroup = array_column($ma['destinations'], null, 'ip');
    assertSame(['198.51.100.0/24', '203.0.113.0/24'], array_keys($byGroup) === ['203.0.113.0/24', '198.51.100.0/24'] ? ['198.51.100.0/24', '203.0.113.0/24'] : array_keys($byGroup), '兩個 /24；缺資料的格子裡的 192.0.2.1 與不合法的位址都沒有');
    assertSame(['203.0.113.0/24', '198.51.100.0/24'], array_column($ma['destinations'], 'ip'), '出現的格子多的排前面');
    assertSame([3, [0, 1, 5], false, '2026-10-20T01:00:00Z', '2026-10-27T06:25:00Z'], [$byGroup['203.0.113.0/24']['seen_windows'], $byGroup['203.0.113.0/24']['w'], $byGroup['203.0.113.0/24']['new'],
        $byGroup['203.0.113.0/24']['first_seen'], $byGroup['203.0.113.0/24']['last_seen']], '首次出現取成員裡最早的「已知」時間；其中一個成員早就看過，所以不是新目的地');
    assertSame([1, [1], true, '2026-10-27T06:10:00Z'], [$byGroup['198.51.100.0/24']['seen_windows'], $byGroup['198.51.100.0/24']['w'], $byGroup['198.51.100.0/24']['new'], $byGroup['198.51.100.0/24']['first_seen']], '期間內第一次看到：新目的地');
    assertSame(2, $ma['destinations_total']);
    assertSame([['port' => 443, 'share' => 0.75], ['port' => 53, 'share' => 0.25]], $ma['ports'], '缺資料的格子裡的 8080 不算');
    assertSame([['t' => '2026-10-27T06:05:00Z', 'detector' => 'after_hours_surge', 'severity' => 'high', 'summary' => '離峰連線暴增']], $ma['alerts'], '視窗之外的警示不匯出');
    assertSame(['timezone' => 'Asia/Taipei', 'workdays' => '1,2,3,4,5', 'start' => '09:00', 'end' => '18:00'], $ma['schedule']);
    assertSame(['p95_conn' => 50.0, 'p95_bytes' => 5000.0], $ma['baseline'], '離峰桶（週末，或小時在上班時段以外）、樣本夠、目前版本的 P95 取中位數：連線 [20,40,60,1000]→50');
    assertSame(null, $mb['baseline']);
    assertSame(['lat' => 23.0, 'lng' => 120.2], $ma['geo'], '遮罩時座標只留一位小數');
    assertSame(null, $mb['geo']);
});

dbtest('連線視圖匯出（預設遮罩）：輸出的 JSON 裡找不到任何真實的名稱、位址、目的 IP，代號對照只在 mapping()', static function (PDO $db): void {
    [$a, $b] = vzScenario($db);
    $export = new BehaviorVizExport($db);
    $json = json_encode($export->export(['hours' => 2, 'now' => vzNow()], DBT_H), JSON_UNESCAPED_UNICODE);
    foreach (['財務部', 'PC-01', '待確認設備', '172.16.111.2', '172.16.9.9', '203.0.113.5', '203.0.113.77', '203.0.113.9', '198.51.100.9', '192.0.2.1', '23.0123', '120.2456'] as $secret) {
        assertTrue(strpos($json, $secret) === false, '遮罩後不該出現：' . $secret);
    }
    assertSame([['label' => '主機 A', 'id' => $a, 'name' => '財務部 PC-01'], ['label' => '主機 B', 'id' => $b, 'name' => '待確認設備 172.16.9.9']], $export->mapping());
});

dbtest('連線視圖匯出（--no-mask）：真實名稱、位址、目的 IP 與內部編號都在，座標不四捨五入', static function (PDO $db): void {
    [$a] = vzScenario($db);
    $x = vzExport($db, ['mask' => false]);
    assertSame(false, $x['masked']);
    $ma = $x['assets'][0];
    assertSame([$a, '財務部 PC-01', '172.16.111.2'], [$ma['id'], $ma['label'], $ma['address']]);
    assertSame(['203.0.113.5', '203.0.113.77', '198.51.100.9', '203.0.113.9'], array_values(array_intersect(['203.0.113.5', '203.0.113.77', '198.51.100.9', '203.0.113.9'], array_column($ma['destinations'], 'ip'))));
    assertSame(4, $ma['destinations_total'], '不遮罩就不併 /24');
    assertSame(['lat' => 23.0123, 'lng' => 120.2456], $ma['geo']);
    $byIp = array_column($ma['destinations'], null, 'ip');
    assertSame(['2026-10-20T01:00:00Z', false], [$byIp['203.0.113.5']['first_seen'], $byIp['203.0.113.5']['new']]);
    assertSame(true, $byIp['203.0.113.77']['new']);
    assertSame(true, $byIp['198.51.100.9']['new']);
    assertSame(false, $byIp['203.0.113.9']['new'], '不在「已知」裡的目的地不能宣稱是新的（只有完整區間會登錄成已知）');
    assertSame('2026-10-27T06:25:00Z', $byIp['203.0.113.9']['first_seen'], '沒有已知紀錄：用期間內第一次出現的格子');
});

dbtest('連線視圖匯出：主機編號篩選依給的順序、top 只切目的地清單（總數照實）、主機數上限、時數決定格數', static function (PDO $db): void {
    [$a, $b] = vzScenario($db);
    $x = vzExport($db, ['assets' => [$b, $a], 'mask' => false]);
    assertSame([$b, $a], array_column($x['assets'], 'id'), '依給的順序');
    assertSame(1, count(vzExport($db, ['assets' => [$a], 'mask' => false])['assets']));
    assertSame(0, count(vzExport($db, ['assets' => [999999]])['assets']), '不存在的編號：空');
    $t = vzExport($db, ['top' => 1])['assets'][0];
    assertSame([1, 2], [count($t['destinations']), $t['destinations_total']]);
    assertSame(1, count(vzExport($db, ['max_assets' => 1])['assets']));
    $h = vzExport($db, ['hours' => 1])['assets'][0];
    assertSame(12, count($h['windows']));
    assertSame('2026-10-27T07:00:00Z', $h['windows'][0]['t']);
    assertSame('missing', $h['windows'][0]['q'], '一小時視窗之外的區間不會混進來');
    assertSame(48, count(vzExport($db, ['hours' => 4])['assets'][0]['windows']));
});

dbtest('連線視圖匯出：沒有 LibreNMS 的資料表也能匯出（座標是 null）；沒有位置或座標是 0,0 也是 null', static function (PDO $db): void {
    [$a] = vzScenario($db);
    assertSame(['lat' => 23.0, 'lng' => 120.2], vzExport($db)['assets'][0]['geo']);
    $db->exec('UPDATE locations SET lat=0,lng=0');
    assertSame(null, vzExport($db)['assets'][0]['geo'], '0,0 視為未設定');
    $db->exec('UPDATE locations SET lat=NULL,lng=NULL');
    assertSame(null, vzExport($db)['assets'][0]['geo']);
    $db->exec('DROP TABLE locations');
    assertSame(null, vzExport($db)['assets'][0]['geo'], 'locations 表不存在：略過座標，其餘照常');
    assertSame(24, count(vzExport($db)['assets'][0]['windows']));
    $db->exec('CREATE TABLE locations (id INT PRIMARY KEY, lat DOUBLE NULL, lng DOUBLE NULL)');
});

dbtest('連線視圖匯出：只有管理者能匯出（含 IP）；檢視者與沒有身分都拒絕', static function (PDO $db): void {
    vzScenario($db);
    assertThrows(AccessDenied::class, fn () => vzExport($db, [], DBT_V));
    assertThrows(AccessDenied::class, fn () => vzExport($db, [], null));
});

dbtest('連線視圖匯出：唯讀——資料一列都沒動、匯出完連線不留在唯讀交易裡（之後還能寫）；中途出錯也一樣', static function (PDO $db): void {
    vzScenario($db);
    $count = static fn (): array => dbtAll($db, "SELECT TABLE_NAME t, TABLE_ROWS r FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'ioc\\_behavior\\_%' ORDER BY 1");
    $rows = static fn (): array => array_map(static fn (string $t): int => (int) dbtVal($db, 'SELECT COUNT(*) FROM ' . $t), array_column($count(), 't'));
    $before = $rows();
    vzExport($db);
    assertSame($before, $rows(), '匯出不改任何資料');
    assertSame(0, (int) dbtVal($db, 'SELECT @@in_transaction'), '匯出完不在交易裡');
    dbtAsset($db, ['identity_key' => 'observed:after:x']);        // 還能寫：沒有卡在 READ ONLY
    // 中途出錯（讀已知目的地的表不見了）：例外照丟，交易要收掉
    $db->exec('DROP TABLE ioc_behavior_known');
    try { vzExport($db); fail('應該丟出例外'); } catch (PDOException $e) { /* 預期 */ }
    assertSame(0, (int) dbtVal($db, 'SELECT @@in_transaction'), '出錯也不能留下開著的交易');
    foreach (\Ioc\Schema::statements() as $sql) { $db->exec($sql); }       // 補回被刪的表，不影響後面的測試
    dbtAsset($db, ['identity_key' => 'observed:after2:x']);
});

dbtest('連線視圖匯出：每個資產的時間序列格數固定，匯出內容能被畫面的驗證規則接受（欄位型別與範圍）', static function (PDO $db): void {
    vzScenario($db);
    $file = getenv('IOC_VIZ_EXPORT_FILE');       // 手動端到端檢查用：把遮罩後的匯出寫成檔案，丟給 behavior-3d.html 的「載入 JSON」
    $x = vzExport($db, ['hours' => 24]);
    if (is_string($file) && $file !== '') { file_put_contents($file, json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
    foreach ($x['assets'] as $a) {
        assertSame(288, count($a['windows']));
        foreach ($a['windows'] as $i => $w) {
            assertTrue(in_array($w['q'], ['complete', 'partial', 'missing'], true) && is_int($w['conn']) && is_int($w['bytes']) && $w['conn'] >= 0 && $w['bytes'] >= 0, 'window ' . $i);
            assertTrue($w['q'] !== 'missing' || ($w['conn'] === 0 && $w['bytes'] === 0), '缺資料的格子不帶數字');
        }
        foreach ($a['destinations'] as $d) {
            assertTrue($d['seen_windows'] === count($d['w']) && $d['w'] === array_values(array_unique($d['w'])) && ($d['w'] === [] || (min($d['w']) >= 0 && max($d['w']) < 288)), '目的地的區間索引');
        }
        assertTrue(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $a['windows'][0]['t']) === 1);
    }
});
