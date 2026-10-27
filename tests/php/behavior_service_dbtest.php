<?php
declare(strict_types=1);

/**
 * BehaviorService 的資料庫行為（真的 MariaDB，一次性的 ioc_test_* schema；見 tests/run-db-tests.sh）。
 * 名稱標「已知限制」的測試釘住的是目前實際的行為，不是理想的行為：改掉那個限制時，這個測試要一起改。
 */

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\BehaviorService;
use Ioc\NotFound;
use Ioc\ValidationError;

dbtest('資料表結構可以重複建立（冪等），且服務讀得到空資料', static function (PDO $db): void {
    foreach (\Ioc\Schema::statements() as $sql) {
        $db->exec($sql);
    }
    $svc = new BehaviorService($db);
    $s = $svc->state(DBT_H);
    assertSame(0, $s['summary']['learning'] + $s['summary']['monitoring']);
    assertSame([], $svc->listAssets('lifecycle', 1, 10, 'name', 'asc', DBT_H)['rows']);
});

// ---- 身分：LibreNMS 裝置、未知 IP 的暫時資產 ----

dbtest('syncDevice：以 LibreNMS device_id 建立資產並記位址；重複同步不重複；IP 變更多一筆位址歷史', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = $svc->syncDevice(10, 'fw-1', '172.16.1.1', '台南');
    $row = dbtRow($db, 'SELECT * FROM ioc_behavior_assets WHERE id=?', [$id]);
    assertSame(['librenms:10', 10, 'fw-1', '台南', 'learning'], [$row['identity_key'], $row['device_id'], $row['name'], $row['site_name'], $row['mode']]);
    assertSame(['172.16.1.1'], array_column(dbtAll($db, 'SELECT address FROM ioc_behavior_addresses WHERE asset_id=?', [$id]), 'address'));
    assertSame(['address_seen'], dbtHistory($db, $id));

    assertSame($id, $svc->syncDevice(10, 'fw-1-renamed', '172.16.1.1', '台南'), '同一台設備同一個資產');
    assertSame('fw-1-renamed', dbtVal($db, 'SELECT name FROM ioc_behavior_assets WHERE id=?', [$id]));
    assertSame(1, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_addresses WHERE asset_id=?', [$id]), '位址沒變就不新增');

    $svc->syncDevice(10, 'fw-1-renamed', '172.16.1.9', '台南');
    $addr = array_column(dbtAll($db, 'SELECT address FROM ioc_behavior_addresses WHERE asset_id=? ORDER BY id', [$id]), 'address');
    assertSame(['172.16.1.1', '172.16.1.9'], $addr, 'IP 變更：舊位址留著當歷史');

    $svc->syncDevice(10, 'fw-1-renamed', 'not-an-ip', '台南');
    $svc->syncDevice(10, 'fw-1-renamed', null, '台南');
    assertSame(2, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_addresses WHERE asset_id=?', [$id]), '不合法或沒有 IP 就不記位址，也不丟例外');
    $svc->syncDevice(11, str_repeat('長', 300), null, null);
    assertSame(200, mb_strlen((string) dbtVal($db, "SELECT name FROM ioc_behavior_assets WHERE identity_key='librenms:11'")), '名稱截到 200 字');
});

dbtest('observeUnknown：未知 IP 建暫時資產；24 小時內同 IP 沿用；超過 24 小時另建；不沿用裝置資產', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    assertThrows(ValidationError::class, fn () => $svc->observeUnknown('not-an-ip'), 'IP 格式');
    $t = $svc->observeUnknown('172.16.9.9');
    $row = dbtRow($db, 'SELECT identity_key,device_id,name,mode FROM ioc_behavior_assets WHERE id=?', [$t]);
    assertTrue(strpos($row['identity_key'], 'observed:172.16.9.9:') === 0 && $row['device_id'] === null && $row['mode'] === 'learning');
    assertSame('待確認設備 172.16.9.9', $row['name']);
    assertSame($t, $svc->observeUnknown('172.16.9.9'), '24 小時內同一個 IP 沿用同一個暫時資產');
    assertSame(1, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_addresses WHERE asset_id=?', [$t]));

    $db->prepare('UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() - INTERVAL 25 HOUR WHERE asset_id=?')->execute([$t]);
    $t2 = $svc->observeUnknown('172.16.9.9');
    assertTrue($t2 !== $t, '超過 24 小時沒看到，重新出現就另建暫時身分');

    $dev = $svc->syncDevice(20, 'fw-2', '172.16.9.20', null);
    $t3 = $svc->observeUnknown('172.16.9.20');
    assertTrue($t3 !== $dev, '位址屬於 LibreNMS 裝置的資產不會被當成暫時資產沿用');
});

// ---- 模式與啟用監控的條件 ----

dbtest('setMode：模式與權限檢查（不認得的模式、沒有權限、找不到資產）；ready 已不是可以設定的模式', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = dbtAsset($db, ['device_id' => 1, 'name' => 'fw']);
    foreach (['bogus', 'ready', ''] as $mode) {
        assertThrows(ValidationError::class, fn () => $svc->setMode($id, $mode, 'a', DBT_H), '模式不正確', $mode);
    }
    assertThrows(AccessDenied::class, fn () => $svc->setMode($id, 'monitoring', 'v', DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->setMode($id, 'monitoring', 'v', null));
    assertThrows(NotFound::class, fn () => $svc->setMode(424242, 'monitoring', 'a', DBT_H));
    $svc->setMode($id, 'retired', 'admin', DBT_H);
    assertSame('retired', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]), '移除（停用）');
    $svc->setMode($id, 'learning', 'admin', DBT_H);
    assertSame('learning', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]), '移除之後還可以重新學習');
});

// ---- 上班時段 ----

dbtest('saveSchedule：輸入驗證與權限；不合法的一律不寫', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    assertThrows(AccessDenied::class, fn () => $svc->saveSchedule('device', '5', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'v', DBT_V));
    $bad = [
        ['bogus', '5', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00'],
        ['device', 'abc', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00'],      // 裝置範圍的 key 必須是 LibreNMS 的數字 id
        ['device', '', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00'],
        ['site', str_repeat('a', 101), 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00'],
        ['site', "x\x01y", 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00'],
        ['site', '台南', 'Mars/Base', '1,2,3,4,5', '09:00', '18:00'],
        ['site', '台南', 'Asia/Taipei', '8', '09:00', '18:00'],
        ['site', '台南', 'Asia/Taipei', '1,1', '09:00', '18:00'],
        ['site', '台南', 'Asia/Taipei', '', '09:00', '18:00'],
        ['site', '台南', 'Asia/Taipei', '1,2,3,4,5,6,7,1', '09:00', '18:00'],
        ['site', '台南', 'Asia/Taipei', '1,2,3,4,5', '9:00', '18:00'],
        ['site', '台南', 'Asia/Taipei', '1,2,3,4,5', '24:00', '18:00'],
        ['site', '台南', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:60'],
        ['site', '台南', 'Asia/Taipei', '1,2,3,4,5', '18:00', '09:00'],        // 開始不能晚於結束
        ['site', '台南', 'Asia/Taipei', '1,2,3,4,5', '09:00', '09:00'],        // 也不能相等
    ];
    foreach ($bad as [$scope, $key, $tz, $days, $start, $end]) {
        assertThrows(ValidationError::class, fn () => $svc->saveSchedule($scope, $key, $tz, $days, $start, $end, 'admin', DBT_H), '', json_encode([$scope, $key, $tz, $days, $start, $end], JSON_UNESCAPED_UNICODE));
    }
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_schedules'));
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_audit'));
});

dbtest('saveSchedule：時段有變動才重新學習（裝置層級只動該裝置、站點層級不動已有裝置層級時段的），沒變動什麼都不做', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $a = dbtBaselineAsset($db, ['device_id' => 5, 'site_name' => '台南']);
    $b = dbtBaselineAsset($db, ['device_id' => 6, 'site_name' => '台南']);
    $c = dbtBaselineAsset($db, ['device_id' => 7, 'site_name' => '台中']);
    $gone = dbtBaselineAsset($db, ['device_id' => 8, 'site_name' => '台南', 'mode' => 'retired']);
    $learning = dbtAsset($db, ['device_id' => 9, 'site_name' => '台南', 'name' => '學習中', 'learning_started_at' => gmdate('Y-m-d H:i:s', time() - 20 * 86400)]);
    $learningStart = dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learning]);
    $mode = static fn (int $id) => dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]);

    $svc->saveSchedule('device', '6', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'admin', DBT_H);
    assertSame(['monitoring', 'learning', 'monitoring'], [$mode($a), $mode($b), $mode($c)], '只有裝置 6 重新學習');
    assertSame(['schedule_changed'], dbtHistory($db, $b));
    assertSame('device:6', dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='schedule_saved'"));
    $learningDevice = dbtAsset($db, ['device_id' => 10, 'name' => '學習中的設備', 'learning_started_at' => gmdate('Y-m-d H:i:s', time() - 20 * 86400)]);
    $learningDeviceStart = dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learningDevice]);
    $svc->saveSchedule('device', '10', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'admin', DBT_H);
    assertSame([$learningDeviceStart, []], [dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learningDevice]), array_diff(dbtHistory($db, $learningDevice), ['address_seen'])],
        '設備層級也一樣：學習中的設備存了自己的時段，不重設起算時間、不留重新學習的歷史');

    $db->exec("UPDATE ioc_behavior_assets SET mode='monitoring' WHERE id IN ($b)");
    $svc->saveSchedule('device', '6', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'admin', DBT_H);
    assertSame('monitoring', $mode($b), '時段沒變：不重新學習');
    assertSame(2, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_audit WHERE action='schedule_saved'"), '裝置 6、裝置 10 各一筆；裝置 6 再存同樣的值沒變，不再留稽核');

    $svc->saveSchedule('site', '台南', 'Asia/Taipei', '1,2,3,4,5', '08:30', '17:30', 'admin', DBT_H);
    assertSame(['learning', 'monitoring', 'monitoring', 'retired'], [$mode($a), $mode($b), $mode($c), $mode($gone)],
        '站點層級：台南的裝置 5 重新學習；裝置 6 自己有時段、台中不在範圍、已退役的不動');
    assertSame($learningStart, dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learning]),
        '本來就在學習中的資產（站點也是台南）不重設起算時間：沒有任何已訓練的東西依賴舊時段');

    $svc->saveSchedule('device', '6', 'Asia/Taipei', '1,2,3,4,5,6', '09:00', '18:00', 'admin', DBT_H);
    assertSame('learning', $mode($b), '工作日改了就是有變動');
});

// ---- 警示處置與通知重試 ----

dbtest('setAlertStatus：處置狀態流程、理由必填、留歷史與稽核；模擬警示不能處置', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $asset = dbtBaselineAsset($db, ['mode' => 'monitoring']);
    $open = dbtAlert($db, $asset);
    assertThrows(AccessDenied::class, fn () => $svc->setAlertStatus($open, 'acknowledged', '看過了', 'v', DBT_V));
    foreach ([['acknowledged', ''], ['acknowledged', '   '], ['acknowledged', str_repeat('a', 201)], ['open', '理由'], ['bogus', '理由']] as [$st, $why]) {
        assertThrows(ValidationError::class, fn () => $svc->setAlertStatus($open, $st, $why, 'admin', DBT_H), '', $st . '／' . strlen($why));
    }
    assertThrows(NotFound::class, fn () => $svc->setAlertStatus(424242, 'resolved', '理由', 'admin', DBT_H));

    $svc->setAlertStatus($open, 'acknowledged', '已通知網管確認', 'admin', DBT_H);
    assertSame('acknowledged', dbtVal($db, 'SELECT status FROM ioc_behavior_alerts WHERE id=?', [$open]));
    assertSame(['alert_acknowledged'], dbtHistory($db, $asset));
    assertSame('警示 #' . $open . '：已通知網管確認', dbtVal($db, 'SELECT detail FROM ioc_behavior_history WHERE asset_id=?', [$asset]));
    assertSame('alert:' . $open, dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='alert_acknowledged'"));
    assertThrows(ValidationError::class, fn () => $svc->setAlertStatus($open, 'acknowledged', '再確認一次', 'admin', DBT_H), '無法執行');
    $svc->setAlertStatus($open, 'resolved', '預期的維運作業', 'admin', DBT_H);
    assertSame('resolved', dbtVal($db, 'SELECT status FROM ioc_behavior_alerts WHERE id=?', [$open]));
    foreach (['acknowledged', 'resolved'] as $st) {
        assertThrows(ValidationError::class, fn () => $svc->setAlertStatus($open, $st, '已結案後再改', 'admin', DBT_H), '無法執行');
    }
    $direct = dbtAlert($db, $asset);
    $svc->setAlertStatus($direct, 'resolved', '直接結案', 'admin', DBT_H);
    assertSame('resolved', dbtVal($db, 'SELECT status FROM ioc_behavior_alerts WHERE id=?', [$direct]), 'open 可以直接結案');

    $preview = dbtAlert($db, $asset, 'preview');
    assertThrows(ValidationError::class, fn () => $svc->setAlertStatus($preview, 'resolved', '模擬警示', 'admin', DBT_H), '無法執行', '已知限制：模擬（preview）警示沒有任何處置方式');
});

dbtest('retryDelivery：只有未處置且投遞失敗的警示能重試；重試會重設次數與錯誤碼', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $asset = dbtBaselineAsset($db, ['mode' => 'monitoring']);
    $deliver = static function (int $alert, string $status) use ($db): void {
        $db->prepare("INSERT INTO ioc_behavior_deliveries (alert_id,status,attempts,next_attempt_at,error_code) VALUES (?,?,5,UTC_TIMESTAMP() + INTERVAL 1 HOUR,'http_error')")->execute([$alert, $status]);
    };
    $failed = dbtAlert($db, $asset);   $deliver($failed, 'failed');
    $queued = dbtAlert($db, $asset);   $deliver($queued, 'queued');
    $acked = dbtAlert($db, $asset, 'acknowledged');   $deliver($acked, 'failed');
    $none = dbtAlert($db, $asset);
    assertThrows(AccessDenied::class, fn () => $svc->retryDelivery($failed, 'v', DBT_V));
    foreach ([$queued, $acked, $none, 424242] as $alert) {
        assertThrows(ValidationError::class, fn () => $svc->retryDelivery($alert, 'admin', DBT_H), '只有未處置', '警示 #' . $alert);
    }
    $svc->retryDelivery($failed, 'admin', DBT_H);
    $d = dbtRow($db, 'SELECT status,attempts,error_code,next_attempt_at<=UTC_TIMESTAMP() due FROM ioc_behavior_deliveries WHERE alert_id=?', [$failed]);
    assertSame(['queued', 0, '', 1], [$d['status'], (int) $d['attempts'], $d['error_code'], (int) $d['due']]);
    assertSame('alert:' . $failed, dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='delivery_retried'"));
    assertSame(['delivery_retried'], dbtHistory($db, $asset));
});

// ---- 工作台資料 ----

dbtest('state：沒有權限不能看；檢視者只拿到去識別的彙總；管理者拿到資產、來源（過期標 stale）與時段', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    assertThrows(AccessDenied::class, fn () => $svc->state(null));
    $svc->syncDevice(1, 'fw-1', '172.16.1.1', '台南');
    dbtAsset($db, ['mode' => 'monitoring']);
    $ready = dbtAsset($db, ['mode' => 'learning']);
    dbtAsset($db, ['mode' => 'retired']);
    dbtAlert($db, $ready);
    dbtAlert($db, $ready, 'preview');
    dbtSource($db, 'ready', gmdate('Y-m-d H:i:s', time() - 20 * 60));        // 防火牆來源 20 分鐘沒更新
    $db->exec("INSERT INTO ioc_behavior_sources (source_key,kind,scope_label,status,coverage,checked_at,reason) VALUES
        ('span1','span','核心交換器','planned','盤點中',UTC_TIMESTAMP(),''),('flow1','flow','邊界','partial','x',UTC_TIMESTAMP(),'')");

    $v = $svc->state(DBT_V);
    assertSame(true, $v['limited']);
    assertTrue(!isset($v['assets']) && !isset($v['sources']) && !isset($v['schedules']), '檢視者拿不到任何資產、位址或來源明細');
    assertSame([2, 1, 1, 2, 1], [$v['summary']['learning'], $v['summary']['monitoring'], $v['summary']['retired'],
        $v['summary']['source_error'], $v['summary']['open_alerts']], '來源異常＝過期的防火牆來源＋partial 的 flow1；模擬警示不算待處理');

    $h = $svc->state(DBT_H);
    assertSame(false, $h['limited']);
    $list = $svc->listAssets('lifecycle', 1, 10, 'name', 'asc', DBT_H);
    assertSame(3, $list['total'], '已移除的不列在清單');
    $byName = array_column($list['rows'], null, 'name');
    assertSame('172.16.1.1', $byName['fw-1']['address']);
    $sources = array_column($h['sources'], null, 'source_key');
    assertSame(['stale', 'planned', 'partial'], [$sources['fortigate_graylog']['status'], $sources['span1']['status'], $sources['flow1']['status']]);
    assertSame('採集器超過 10 分鐘未更新', $sources['fortigate_graylog']['reason']);
    assertSame(null, $h['webhook_status']['recent'], '還沒有任何通知');
});

dbtest('detail：只有管理者；回傳位址、區間、警示（含通知狀態）、歷史與目前版本的基線', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-1', '172.16.1.1', '台南');
    $db->prepare('UPDATE ioc_behavior_assets SET baseline_version=2 WHERE id=?')->execute([$dev]);
    foreach ([1 => 100, 2 => 200] as $version => $p95) {
        $db->prepare("INSERT INTO ioc_behavior_baselines (asset_id,version,source_key,day_kind,hour_of_day,median_bytes,p95_bytes,mad_bytes,median_connections,p95_connections,mad_connections,median_destinations,p95_destinations,mad_destinations,sample_count,created_at)
            VALUES (?,?,'fortigate_graylog','weekday',9,1,?,1,1,1,1,1,1,1,10,UTC_TIMESTAMP())")->execute([$dev, $version, $p95]);
    }
    $alert = dbtAlert($db, $dev);
    $db->prepare("INSERT INTO ioc_behavior_deliveries (alert_id,status,attempts,next_attempt_at) VALUES (?,'queued',1,UTC_TIMESTAMP())")->execute([$alert]);
    for ($i = 0; $i < 300; $i++) {
        $db->prepare("INSERT INTO ioc_behavior_windows (asset_id,source_key,window_start,destinations_json,ports_json,signals_json,quality,observed_at)
            VALUES (?,'fortigate_graylog',?, '[]','[]','{}','complete',UTC_TIMESTAMP())")->execute([$dev, gmdate('Y-m-d H:i:s', 1790000000 + $i * 300)]);
    }

    assertThrows(AccessDenied::class, fn () => $svc->detail($dev, DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->detail($dev, null));
    assertThrows(NotFound::class, fn () => $svc->detail(424242, DBT_H));
    $d = $svc->detail($dev, DBT_H);
    assertSame(['asset', 'addresses', 'windows', 'alerts', 'history', 'baseline', 'schedule'], array_keys($d));
    assertSame(288, count($d['windows']), '最近 288 格（一天）');
    assertTrue(strcmp($d['windows'][0]['window_start'], $d['windows'][1]['window_start']) > 0, '新到舊');
    assertSame([200.0], array_map('floatval', array_column($d['baseline'], 'p95_bytes')), '只回目前版本（2）的基線');
    assertSame('queued', $d['alerts'][0]['delivery_status']);
    assertSame(['172.16.1.1'], array_column($d['addresses'], 'address'));
});

dbtest('位址紀錄：同一個位址再出現只更新那一列（A→B→A 不新增重複列、不重複記 address_seen）；目前位址是最近看到的', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = $svc->syncDevice(10, 'fw', '172.16.1.1', null);
    $svc->syncDevice(10, 'fw', '172.16.1.9', null);
    $db->exec('UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE address=\'172.16.1.1\'');
    $db->exec('UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE address=\'172.16.1.9\'');
    $svc->syncDevice(10, 'fw', '172.16.1.1', null);       // 換回第一個位址
    assertSame(['172.16.1.1', '172.16.1.9'], array_column(dbtAll($db, 'SELECT address FROM ioc_behavior_addresses WHERE asset_id=? ORDER BY id', [$id]), 'address'), '換回舊位址：用原來那一列，不新增');
    assertSame(['address_seen', 'address_seen'], dbtHistory($db, $id), '只有第一次看到每個位址才記歷史');
    $byId = array_column($svc->listAssets('lifecycle', 1, 10, 'name', 'asc', DBT_H)['rows'], null, 'id');
    assertSame('172.16.1.1', $byId[$id]['address'], '工作台的目前位址＝最近看到的');
});

dbtest('事件案的位址：用最近看到的位址，不是最後新增的那一列', static function (PDO $db): void {
    $id = dbtAsset($db, ['device_id' => 1, 'name' => 'fw', 'mode' => 'monitoring', 'baseline_version' => 1]);
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,'172.16.1.1',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'librenms')")->execute([$id]);
    $db->prepare("INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,'172.16.1.9',UTC_TIMESTAMP(),UTC_TIMESTAMP() - INTERVAL 2 HOUR,'librenms')")->execute([$id]);
    dbtAlert($db, $id);
    (new \Ioc\BehaviorCollector($db, DbtFakeLogs::traffic([])))->repairOpenCases();
    assertSame('172.16.1.1', dbtVal($db, 'SELECT subject_ip FROM ioc_cases ORDER BY id LIMIT 1'), '最近看到的位址，不是 id 最大的那一列');
});

dbtest('state：mode_names 由伺服器給（畫面不再自己寫一份），檢視者拿不到', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    assertTrue(isset($svc->state(DBT_H)['mode_names']['learning']) && isset($svc->state(DBT_H)['mode_names']['monitoring']));
    assertTrue(!isset($svc->state(DBT_V)['mode_names']));
});
