<?php
declare(strict_types=1);

/**
 * M2a：已確認主機、排程解析鏈（device → site → default → 內建預設）、位址比對不受 24 小時限制、站點歸屬、啟用監控條件。
 * 真的 MariaDB，一次性的 ioc_test_* schema；見 tests/run-db-tests.sh。
 */

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\BehaviorCollector;
use Ioc\BehaviorService;
use Ioc\NotFound;
use Ioc\ValidationError;

const BHD_H = Access::HANDLER;
const BHD_V = Access::VIEWER;

function bhdObserved(PDO $db, string $ip): int
{
    return (new BehaviorService($db))->observeUnknown($ip);
}

/** 把某個資產的位址「最近看到」往前挪 $hours 小時。 */
function bhdAge(PDO $db, int $assetId, int $hours): void
{
    $db->prepare('UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() - INTERVAL ? HOUR WHERE asset_id=?')->execute([$hours, $assetId]);
}

// ---- 確認主機 ----

dbtest('confirmHosts：權限與輸入檢查；整批要嘛全成功要嘛全不動', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $a = bhdObserved($db, '172.16.9.1');
    $dev = $svc->syncDevice(5, 'fw', '172.16.5.5', null);
    $gone = bhdObserved($db, '172.16.9.3');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$gone]);

    assertThrows(AccessDenied::class, fn () => $svc->confirmHosts([['id' => $a]], '依據', 'v', BHD_V));
    assertThrows(AccessDenied::class, fn () => $svc->confirmHosts([['id' => $a]], '依據', 'v', null));
    $bad = [
        'note 空' => [[['id' => $a]], ''], 'note 空白' => [[['id' => $a]], '   '], 'note 太長' => [[['id' => $a]], str_repeat('a', 201)],
        '沒有項目' => [[], '依據'], '項目太多' => [array_fill(0, 101, ['id' => $a]), '依據'],
        '重複的 id' => [[['id' => $a], ['id' => $a]], '依據'], 'id 不是整數' => [[['id' => 'abc']], '依據'], '缺 id' => [[['name' => 'x']], '依據'],
        '名稱太長' => [[['id' => $a, 'name' => str_repeat('名', 201)]], '依據'], '名稱含控制字元' => [[['id' => $a, 'name' => "x\x01y"]], '依據'],
        'LibreNMS 設備' => [[['id' => $dev]], '依據'], '已退役' => [[['id' => $gone]], '依據'],
    ];
    foreach ($bad as $why => [$items, $note]) {
        assertThrows(ValidationError::class, fn () => $svc->confirmHosts($items, $note, 'admin', BHD_H), '', $why);
    }
    assertThrows(NotFound::class, fn () => $svc->confirmHosts([['id' => 424242]], '依據', 'admin', BHD_H));
    assertThrows(ValidationError::class, fn () => $svc->confirmHosts([['id' => $a], ['id' => $dev]], '依據', 'admin', BHD_H), '', '整批裡有一個不合格');
    assertSame(null, dbtVal($db, 'SELECT confirmed_at FROM ioc_behavior_assets WHERE id=?', [$a]), '整批被擋下：合格的那個也不能被確認');
    assertSame(0, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_audit WHERE action='host_confirmed'"));
});

dbtest('confirmHosts：確認後保留區間與學習起點；名稱可自訂、沒填就用位址；留歷史與稽核；不能重複確認', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $a = bhdObserved($db, '172.16.9.1');
    $b = bhdObserved($db, '172.16.9.2');
    $db->prepare("INSERT INTO ioc_behavior_windows (asset_id,source_key,window_start,destinations_json,ports_json,signals_json,quality,observed_at) VALUES (?,'fortigate_graylog','2026-10-26 01:00:00','[]','[]','{}','complete',UTC_TIMESTAMP())")->execute([$a]);
    $started = dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$a]);

    assertSame(2, $svc->confirmHosts([['id' => $a, 'name' => '  財務部 PC-01  '], ['id' => $b]], '核對過 DHCP 租約與資產清冊', 'admin', BHD_H));
    $ra = dbtRow($db, 'SELECT * FROM ioc_behavior_assets WHERE id=?', [$a]);
    assertSame(['財務部 PC-01', 'admin', '核對過 DHCP 租約與資產清冊', 'learning', $started], [$ra['name'], $ra['confirmed_by'], $ra['confirm_note'], $ra['mode'], $ra['learning_started_at']]);
    assertTrue($ra['confirmed_at'] !== null && strpos($ra['identity_key'], 'observed:172.16.9.1:') === 0 && $ra['device_id'] === null);
    assertSame('主機 172.16.9.2', dbtVal($db, 'SELECT name FROM ioc_behavior_assets WHERE id=?', [$b]));
    assertSame(1, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_windows WHERE asset_id=?', [$a]), '既有的區間原封不動');
    assertTrue(in_array('host_confirmed', dbtHistory($db, $a), true));
    assertSame(['asset:' . $a, 'asset:' . $b], array_column(dbtAll($db, "SELECT subject FROM ioc_behavior_audit WHERE action='host_confirmed' ORDER BY id"), 'subject'));
    assertThrows(ValidationError::class, fn () => $svc->confirmHosts([['id' => $a]], '再確認一次', 'admin', BHD_H), '尚未確認');
    assertTrue((new BehaviorService($db))->state(BHD_H)['assets'][0]['confirmed_at'] !== null || (new BehaviorService($db))->state(BHD_H)['assets'][1]['confirmed_at'] !== null, '工作台看得到哪些是已確認的');
});

// ---- 位址比對與站點歸屬 ----

dbtest('observeUnknown：已確認主機不受 24 小時限制，未確認的仍受限；兩者並存時優先已確認的；已退役的不算', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $confirmed = bhdObserved($db, '172.16.9.1');
    $svc->confirmHosts([['id' => $confirmed]], '依據', 'admin', BHD_H);
    bhdAge($db, $confirmed, 24 * 40);
    assertSame($confirmed, $svc->observeUnknown('172.16.9.1'), '40 天沒看到，回來還是同一台');
    $plain = bhdObserved($db, '172.16.9.2');
    bhdAge($db, $plain, 25);
    assertTrue($svc->observeUnknown('172.16.9.2') !== $plain, '未確認的：超過 24 小時就另建');

    // 同一個位址上有一台很久以前確認的、一台剛出現的未確認：優先已確認的
    $fresh = dbtAsset($db, ['identity_key' => 'observed:172.16.9.1:fresh', 'name' => '待確認設備 172.16.9.1']);
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,'172.16.9.1',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'observed')")->execute([$fresh]);
    assertSame($confirmed, $svc->observeUnknown('172.16.9.1'));

    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$confirmed]);
    $next = $svc->observeUnknown('172.16.9.1');
    assertTrue($next !== $confirmed, '已退役的確認主機不會被沿用');
});

dbtest('observeSite：首次歸屬不重新學習；站點變了才重新學習；LibreNMS 設備與已退役的不動', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $t = bhdObserved($db, '172.16.9.9');
    $started = dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$t]);
    $svc->observeSite($t, '台南');
    assertSame(['台南', $started], [dbtVal($db, 'SELECT site_name FROM ioc_behavior_assets WHERE id=?', [$t]), dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$t])]);
    assertSame([], array_values(array_diff(dbtHistory($db, $t), ['address_seen'])), '還沒有站點到有站點：不算變更');

    $db->prepare("UPDATE ioc_behavior_assets SET mode='ready',baseline_version=1,quality_status='ready',training_checked_at=UTC_TIMESTAMP() WHERE id=?")->execute([$t]);
    $db->prepare("INSERT INTO ioc_behavior_known (asset_id,source_key,kind,value_key,first_seen_at,last_seen_at) VALUES (?,'fortigate_graylog','port','443',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$t]);
    $svc->observeSite($t, '台南');
    assertSame('ready', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$t]), '站點沒變：什麼都不做');
    $svc->observeSite($t, '台中');
    $row = dbtRow($db, 'SELECT site_name,mode,quality_status,training_checked_at FROM ioc_behavior_assets WHERE id=?', [$t]);
    assertSame(['台中', 'learning', 'learning', null], [$row['site_name'], $row['mode'], $row['quality_status'], $row['training_checked_at']]);
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_known WHERE asset_id=?', [$t]));
    assertTrue(in_array('site_changed', dbtHistory($db, $t), true));

    $dev = $svc->syncDevice(7, 'fw', '172.16.7.7', '台南');
    $svc->observeSite($dev, '高雄');
    assertSame('台南', dbtVal($db, 'SELECT site_name FROM ioc_behavior_assets WHERE id=?', [$dev]), 'LibreNMS 設備的站點由 syncDevice 管，這裡不動');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$t]);
    $svc->observeSite($t, '高雄');
    assertSame('台中', dbtVal($db, 'SELECT site_name FROM ioc_behavior_assets WHERE id=?', [$t]), '已退役的不動');
});

// ---- 排程解析鏈 ----

dbtest('scheduleFor：設備 → 站點 → 預設（資料表）→ 內建預設，由具體到籠統', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $pick = static fn (array $s) => [$s['timezone'], $s['workdays'], substr($s['start_time'], 0, 5), substr($s['end_time'], 0, 5)];
    $asset = ['device_id' => 1, 'site_name' => '台南'];
    assertSame(['Asia/Taipei', '1,2,3,4,5', '09:00', '18:00'], $pick($svc->scheduleFor($asset)), '什麼都沒設：內建預設（台北、週一到五 09:00–18:00）');
    assertSame($pick(BehaviorService::DEFAULT_SCHEDULE), $pick($svc->scheduleFor(['device_id' => null, 'site_name' => null])));

    dbtSchedule($db, 'default', 'default', 'UTC', '1,2,3,4,5,6,7', '08:00:00', '20:00:00');
    assertSame(['UTC', '1,2,3,4,5,6,7', '08:00', '20:00'], $pick($svc->scheduleFor($asset)), '資料表裡的預設蓋過內建');
    dbtSchedule($db, 'site', '台南', 'Asia/Taipei', '1,2,3,4,5', '08:30:00', '17:30:00');
    assertSame(['Asia/Taipei', '1,2,3,4,5', '08:30', '17:30'], $pick($svc->scheduleFor($asset)), '站點蓋過預設');
    assertSame(['UTC', '1,2,3,4,5,6,7', '08:00', '20:00'], $pick($svc->scheduleFor(['device_id' => 2, 'site_name' => '台中'])), '別的站點與設備仍用預設');
    dbtSchedule($db, 'device', '1', 'America/New_York', '1,2,3,4,5', '07:00:00', '16:00:00');
    assertSame(['America/New_York', '1,2,3,4,5', '07:00', '16:00'], $pick($svc->scheduleFor($asset)), '設備蓋過站點');
    assertSame(['UTC', '1,2,3,4,5,6,7', '08:00', '20:00'], $pick($svc->scheduleFor(['device_id' => null, 'site_name' => null])), '沒有設備也沒有站點的主機用預設');
});

dbtest('saveSchedule 預設範圍：只收 default／default；有變動才讓「用預設」的資產重新學習；沒變動什麼都不做', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    assertThrows(ValidationError::class, fn () => $svc->saveSchedule('default', 'x', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'admin', BHD_H));
    assertThrows(ValidationError::class, fn () => $svc->saveSchedule('default', '', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'admin', BHD_H));
    assertThrows(AccessDenied::class, fn () => $svc->saveSchedule('default', 'default', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'v', BHD_V));

    $usesDefault = bsdReadyDeviceForHosts($db, ['device_id' => 1]);
    $host = bsdReadyDeviceForHosts($db, ['device_id' => null, 'identity_key' => 'observed:172.16.9.9:x', 'name' => '主機']);
    $withDevice = bsdReadyDeviceForHosts($db, ['device_id' => 2]);
    $withSite = bsdReadyDeviceForHosts($db, ['device_id' => 3, 'site_name' => '台南']);
    $gone = bsdReadyDeviceForHosts($db, ['device_id' => 4, 'mode' => 'retired']);
    dbtSchedule($db, 'device', '2');
    dbtSchedule($db, 'site', '台南');
    $mode = static fn (int $id) => dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]);

    $svc->saveSchedule('default', 'default', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'admin', BHD_H);     // 與內建預設相同
    assertSame(['ready', 'ready'], [$mode($usesDefault), $mode($host)], '存的值和內建預設相同：不算變動');
    assertSame(0, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_audit WHERE action='schedule_saved'"));
    assertSame(1, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_schedules WHERE scope='default' AND scope_key='default'"), '仍然存下來（之後改內建也不受影響）');

    $svc->saveSchedule('default', 'default', 'Asia/Taipei', '1,2,3,4,5', '08:00', '17:00', 'admin', BHD_H);
    assertSame(['learning', 'learning'], [$mode($usesDefault), $mode($host)], '用預設時段的資產（含沒有設備的主機）重新學習');
    assertSame(['ready', 'ready', 'retired'], [$mode($withDevice), $mode($withSite), $mode($gone)], '有自己時段的、已退役的不動');
    assertSame('default:default', dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='schedule_saved'"));
    assertTrue(in_array('schedule_changed', dbtHistory($db, $host), true));

    $db->exec("UPDATE ioc_behavior_assets SET mode='ready' WHERE id IN ($usesDefault,$host)");
    $svc->saveSchedule('default', 'default', 'Asia/Taipei', '1,2,3,4,5', '08:00', '17:00', 'admin', BHD_H);
    assertSame(['ready', 'ready'], [$mode($usesDefault), $mode($host)], '再存一次同樣的值：不動');

    $state = $svc->state(BHD_H);
    assertSame(['Asia/Taipei', '08:00', '17:00', true], [$state['default_schedule']['timezone'], substr($state['default_schedule']['start_time'], 0, 5), substr($state['default_schedule']['end_time'], 0, 5), $state['default_schedule']['custom']]);
});

dbtest('state：沒存過預設時段時，工作台看到內建預設並標示「不是自訂」', static function (PDO $db): void {
    $d = (new BehaviorService($db))->state(BHD_H)['default_schedule'];
    assertSame(['Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', false], [$d['timezone'], $d['workdays'], substr($d['start_time'], 0, 5), substr($d['end_time'], 0, 5), $d['custom']]);
});

function bsdReadyDeviceForHosts(PDO $db, array $o = []): int
{
    return dbtAsset($db, $o + ['device_id' => 1, 'name' => 'fw', 'mode' => 'ready', 'baseline_version' => 1, 'quality_status' => 'ready',
        'learning_started_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
}

// ---- 啟用監控 ----

dbtest('setMode：已確認主機也能啟用監控；未確認的不行；沒有任何時段設定也能啟用（有預設）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $host = bsdReadyDeviceForHosts($db, ['device_id' => null, 'identity_key' => 'observed:172.16.9.9:x', 'name' => '待確認設備 172.16.9.9']);
    dbtSource($db, 'ready');
    assertThrows(ValidationError::class, fn () => $svc->setMode($host, 'monitoring', 'admin', BHD_H), '待確認設備');
    $svc->confirmHosts([['id' => $host, 'name' => '財務部 PC-01']], '核對過', 'admin', BHD_H);
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_schedules'), '沒有任何時段設定');
    $svc->setMode($host, 'monitoring', 'admin', BHD_H);
    assertSame('monitoring', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$host]));

    $young = bsdReadyDeviceForHosts($db, ['device_id' => null, 'identity_key' => 'observed:172.16.9.8:x', 'learning_started_at' => gmdate('Y-m-d H:i:s', time() - 5 * 86400)]);
    $svc->confirmHosts([['id' => $young]], '核對過', 'admin', BHD_H);
    assertThrows(ValidationError::class, fn () => $svc->setMode($young, 'monitoring', 'admin', BHD_H), '28 天', '確認過的主機也要學習滿 28 天');
});

// ---- 採集器 ----

dbtest('tick：以 siteOf 幫觀測主機填站點（沒有對到網段就維持未歸屬）；站點之後變了才重新學習', static function (PDO $db): void {
    $traffic = bcdNormal('172.16.9.9') + bcdNormal('172.16.9.8');
    $siteOf = static fn (string $ip): ?string => $ip === '172.16.9.9' ? '台南' : null;
    (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick([], bcdNets(), bcdNow(), $siteOf);
    $site = static fn (string $ip) => dbtVal($db, "SELECT a.site_name FROM ioc_behavior_assets a JOIN ioc_behavior_addresses x ON x.asset_id=a.id WHERE x.address=?", [$ip]);
    assertSame(['台南', null], [$site('172.16.9.9'), $site('172.16.9.8')]);

    $id = (int) dbtVal($db, "SELECT asset_id FROM ioc_behavior_addresses WHERE address='172.16.9.9'");
    $db->prepare("UPDATE ioc_behavior_assets SET mode='ready',baseline_version=1 WHERE id=?")->execute([$id]);
    (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick([], bcdNets(), bcdNow('2026-10-26 12:12:30'), static fn (string $ip): ?string => '台中');
    assertSame(['台中', 'learning'], [$site('172.16.9.9'), dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id])], '站點變了：重新學習');
    // 沒有傳 siteOf（舊的呼叫方式）：不動站點
    (new BehaviorCollector($db, DbtFakeLogs::traffic($traffic)))->tick([], bcdNets(), bcdNow('2026-10-26 12:17:30'));
    assertSame('台中', $site('172.16.9.9'));
});

dbtest('tick：已確認主機隔了很久再出現，流量仍算在同一個資產上；未確認的另建新資產', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $confirmed = $svc->observeUnknown('172.16.9.9');
    $plain = $svc->observeUnknown('172.16.9.8');
    $svc->confirmHosts([['id' => $confirmed, 'name' => '財務部 PC-01']], '核對過', 'admin', BHD_H);
    bhdAge($db, $confirmed, 24 * 40);
    bhdAge($db, $plain, 24 * 40);
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdNormal('172.16.9.9') + bcdNormal('172.16.9.8'))))->tick([], bcdNets(), bcdNow());
    assertSame(1, count(bcdWindows($db, $confirmed)), '確認過的主機：流量算在原本的資產上');
    assertSame([], bcdWindows($db, $plain), '未確認的：隔太久，另建了新的暫時資產');
    assertSame(3, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_assets'));
});

dbtest('訓練：已確認的主機（沒有 LibreNMS 設備、沒有任何時段設定）用預設時段照樣訓練；未確認的不訓練', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $plain = $svc->observeUnknown('172.16.9.8');          // id 較小：若「只訓練確認過的」失效，tick 會先挑中它
    $confirmed = $svc->observeUnknown('172.16.9.9');
    $svc->confirmHosts([['id' => $confirmed]], '核對過', 'admin', BHD_H);
    foreach ([$confirmed, $plain] as $id) {
        $db->prepare('UPDATE ioc_behavior_assets SET learning_started_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 29 * 86400), $id]);
        bcdBulkWindows($db, $id, 6048, intdiv(time() - 27 * 86400, 300) * 300);
    }
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_schedules'));
    bcdTickOnce($db);
    $row = static fn (int $id) => dbtRow($db, 'SELECT mode,baseline_version,training_checked_at FROM ioc_behavior_assets WHERE id=?', [$id]);
    assertSame(['ready', 1], [$row($confirmed)['mode'], (int) $row($confirmed)['baseline_version']]);
    assertSame(48, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_baselines WHERE asset_id=?', [$confirmed]));
    assertSame(['learning', 0, null], [$row($plain)['mode'], (int) $row($plain)['baseline_version'], $row($plain)['training_checked_at']], '未確認：不會被挑來訓練（只累積區間，確認之後有歷史可用）');
});

dbtest('偵測：沒有任何時段設定時用內建預設（台北週一到五 09:00–18:00），離峰的大流量照樣產生警示', static function (PDO $db): void {
    bcdMonitored($db, 'monitoring');
    $db->exec('DELETE FROM ioc_behavior_schedules');
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge())))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-26 12:07:30'));   // 台北週一 20:00
    assertSame(2, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_alerts WHERE status='open'"));
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge())))->tick([bcdDevice()], bcdNets(), bcdNow('2026-10-27 02:07:30'));   // 台北週二 10:00：上班
    assertSame(2, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'), '上班時段不判斷');
});

dbtest('完整流程：確認主機 → 訓練完成 → 啟用監控 → 離峰暴增 → 開警示、建事件案（案件的主機名稱是確認時填的名稱）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $host = $svc->observeUnknown('172.16.1.10');
    $svc->confirmHosts([['id' => $host, 'name' => '財務部 PC-01']], '核對過 DHCP 租約', 'admin', BHD_H);
    $db->prepare("UPDATE ioc_behavior_assets SET mode='ready',baseline_version=1,quality_status='ready',learning_started_at=? WHERE id=?")->execute([gmdate('Y-m-d H:i:s', time() - 30 * 86400), $host]);
    foreach ([20, 21] as $hour) {
        $db->prepare("INSERT INTO ioc_behavior_baselines (asset_id,version,source_key,day_kind,hour_of_day,median_bytes,p95_bytes,mad_bytes,median_connections,p95_connections,mad_connections,median_destinations,p95_destinations,mad_destinations,sample_count,created_at)
            VALUES (?,1,'fortigate_graylog','weekday',?,500000,1000000,1,3,5,1,2,3,1,10,UTC_TIMESTAMP())")->execute([$host, $hour]);
    }
    dbtSource($db, 'ready');
    $svc->setMode($host, 'monitoring', 'admin', BHD_H);
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge('172.16.1.10'))))->tick([], bcdNets(), bcdNow('2026-10-26 12:07:30'));
    assertSame(['open', 'open'], array_column(dbtAll($db, 'SELECT status FROM ioc_behavior_alerts ORDER BY id'), 'status'));
    $case = dbtRow($db, 'SELECT subject_host,subject_ip,source FROM ioc_cases ORDER BY id LIMIT 1');
    assertSame(['財務部 PC-01', '172.16.1.10', 'automation'], [$case['subject_host'], $case['subject_ip'], $case['source']]);
});
