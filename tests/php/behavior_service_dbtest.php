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
    $s = (new BehaviorService($db))->state(DBT_H);
    assertSame(0, $s['summary']['learning'] + $s['summary']['monitoring']);
    assertSame([], $s['assets']);
});

// ---- 身分：LibreNMS 裝置、未知 IP 的暫時資產、人工關聯 ----

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

dbtest('syncDevice：站點歸屬變更要重新學習（清已知目的地、記歷史）；已退役的不動', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = $svc->syncDevice(10, 'fw-1', '172.16.1.1', '台南');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='ready',quality_status='ready',baseline_version=1,training_checked_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);
    $db->prepare("INSERT INTO ioc_behavior_known (asset_id,source_key,kind,value_key,first_seen_at,last_seen_at) VALUES (?,'fortigate_graylog','destination','203.0.113.1',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$id]);

    $svc->syncDevice(10, 'fw-1', '172.16.1.1', '台南');
    assertSame('ready', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]), '站點沒變就不重新學習');

    $svc->syncDevice(10, 'fw-1', '172.16.1.1', '台中');
    $row = dbtRow($db, 'SELECT mode,quality_status,training_checked_at,baseline_version FROM ioc_behavior_assets WHERE id=?', [$id]);
    assertSame(['learning', 'learning', null], [$row['mode'], $row['quality_status'], $row['training_checked_at']]);
    assertSame(1, (int) $row['baseline_version'], '舊版基線保留，只是重新學習期間不用它判斷');
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_known WHERE asset_id=?', [$id]));
    assertTrue(in_array('site_changed', dbtHistory($db, $id), true));

    $db->prepare("UPDATE ioc_behavior_assets SET mode='retired' WHERE id=?")->execute([$id]);
    $svc->syncDevice(10, 'fw-1', '172.16.1.1', '高雄');
    assertSame('retired', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]), '已退役的資產不會因站點變更復活');
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

dbtest('linkAsset：權限、輸入與對象檢查；成功時退役暫時資產、複製位址、留關聯／歷史／稽核', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(30, 'fw-3', '172.16.3.1', '台南');
    $temp = $svc->observeUnknown('172.16.3.99');

    assertThrows(AccessDenied::class, fn () => $svc->linkAsset($temp, 30, '核對過 DHCP 租約', 'viewer', DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->linkAsset($temp, 30, '核對過 DHCP 租約', 'nobody', null));
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($temp, 30, '  ', 'admin', DBT_H), '依據');
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($temp, 30, str_repeat('a', 201), 'admin', DBT_H), '依據');
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($dev, 30, '依據', 'admin', DBT_H), '暫時設備', '已確認的裝置資產不能被當成暫時資產關聯');
    assertThrows(NotFound::class, fn () => $svc->linkAsset($temp, 999, '依據', 'admin', DBT_H), 'LibreNMS 裝置尚未同步');
    assertThrows(NotFound::class, fn () => $svc->linkAsset(424242, 30, '依據', 'admin', DBT_H), '找不到');
    assertSame('learning', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$temp]), '被拒絕的關聯不能改到資料');

    $svc->linkAsset($temp, 30, '核對過 DHCP 租約與 MAC', 'admin', DBT_H);
    assertSame('retired', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$temp]));
    $link = dbtRow($db, 'SELECT * FROM ioc_behavior_links WHERE observed_asset_id=?', [$temp]);
    assertSame([$dev, 'admin', '核對過 DHCP 租約與 MAC'], [(int) $link['target_asset_id'], $link['confirmed_by'], $link['evidence_note']]);
    $kinds = dbtAll($db, 'SELECT address,evidence_kind FROM ioc_behavior_addresses WHERE asset_id=? ORDER BY id', [$dev]);
    assertSame([['address' => '172.16.3.1', 'evidence_kind' => 'librenms'], ['address' => '172.16.3.99', 'evidence_kind' => 'admin_confirmed']], $kinds, '暫時資產的位址複製到裝置');
    assertTrue(in_array('linked_to_device', dbtHistory($db, $temp), true) && in_array('linked_observation', dbtHistory($db, $dev), true));
    assertSame('asset:' . $temp . '→' . $dev, dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='asset_linked'"));
    assertThrows(ValidationError::class, fn () => $svc->linkAsset($temp, 30, '再關聯一次', 'admin', DBT_H), '暫時設備', '已退役的暫時資產不能再關聯');
});

// ---- 模式與啟用監控的條件 ----

dbtest('setMode：模式與權限檢查；啟用監控的每一個前置條件各自擋得住', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = dbtReadyAsset($db);
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'bogus', 'a', DBT_H), '模式不正確');
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'ready', 'a', DBT_H), '模式不正確', 'ready 是系統訓練完才會有的狀態，管理者不能直接設');
    assertThrows(AccessDenied::class, fn () => $svc->setMode($id, 'monitoring', 'v', DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->setMode($id, 'monitoring', 'v', null));
    assertThrows(NotFound::class, fn () => $svc->setMode(424242, 'monitoring', 'a', DBT_H));

    $set = static fn (string $sql, array $p = []) => $db->prepare($sql)->execute(array_merge($p, [$id]));
    $set("UPDATE ioc_behavior_assets SET mode='learning' WHERE id=?");
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'a', DBT_H), '本輪學習');
    $set("UPDATE ioc_behavior_assets SET mode='ready', device_id=NULL WHERE id=?");
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'a', DBT_H), '待確認設備');
    $set('UPDATE ioc_behavior_assets SET device_id=1, learning_started_at=? WHERE id=?', [gmdate('Y-m-d H:i:s', time() - 10 * 86400)]);
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'a', DBT_H), '28 天');
    $set('UPDATE ioc_behavior_assets SET learning_started_at=?, baseline_version=0 WHERE id=?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'a', DBT_H), '基線');
    $set("UPDATE ioc_behavior_assets SET baseline_version=1, quality_status='partial' WHERE id=?");
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'a', DBT_H), '基線', '最近一個區間是 partial：資料品質不過');
    $set("UPDATE ioc_behavior_assets SET quality_status='ready' WHERE id=?");
    // 沒有任何時段設定也不會被擋：沒有設備與站點層級的時段就用預設（見 behavior_hosts_dbtest.php）
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'a', DBT_H), '防火牆日誌來源', '沒有來源狀態');
    dbtSource($db, 'partial');
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'a', DBT_H), '防火牆日誌來源', '來源不是 ready');
    dbtSource($db, 'ready', gmdate('Y-m-d H:i:s', time() - 20 * 60));
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'a', DBT_H), '防火牆日誌來源', '來源超過 15 分鐘沒更新');
    assertSame('ready', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]), '每一次被擋下都不能改到資料');

    dbtSource($db, 'ready');
    $svc->setMode($id, 'monitoring', 'admin', DBT_H);
    assertSame('monitoring', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]));
    assertSame(['mode_changed'], dbtHistory($db, $id));
    assertSame('ready → monitoring', dbtVal($db, 'SELECT detail FROM ioc_behavior_history WHERE asset_id=?', [$id]));
    assertSame('asset:' . $id . ':monitoring', dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='mode_changed'"));
    $svc->setMode($id, 'monitoring', 'admin', DBT_H);
    assertSame(['mode_changed'], dbtHistory($db, $id), '已經是監控：沒有新的歷史');
});

dbtest('setMode：站點層級的上班時段也算；改回學習會重設起算時間並清掉已知目的地；退役後不能直接啟用監控', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = dbtReadyAsset($db, ['site_name' => '台南']);
    dbtSchedule($db, 'site', '台南');
    dbtSource($db, 'ready');
    $svc->setMode($id, 'monitoring', 'admin', DBT_H);
    $db->prepare("INSERT INTO ioc_behavior_known (asset_id,source_key,kind,value_key,first_seen_at,last_seen_at) VALUES (?,'fortigate_graylog','port','443',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$id]);
    $db->prepare('UPDATE ioc_behavior_assets SET training_checked_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);

    $svc->setMode($id, 'learning', 'admin', DBT_H);
    $row = dbtRow($db, 'SELECT mode,quality_status,training_checked_at,learning_started_at FROM ioc_behavior_assets WHERE id=?', [$id]);
    assertSame(['learning', 'learning', null], [$row['mode'], $row['quality_status'], $row['training_checked_at']]);
    assertTrue(abs(strtotime($row['learning_started_at'] . ' UTC') - time()) < 10, '學習起算時間重設為現在');
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_known WHERE asset_id=?', [$id]));
    assertSame(['mode_changed', 'mode_changed'], dbtHistory($db, $id));

    $svc->setMode($id, 'retired', 'admin', DBT_H);
    assertSame('retired', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]));
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'admin', DBT_H), '本輪學習');
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
    $a = dbtReadyAsset($db, ['device_id' => 5, 'site_name' => '台南']);
    $b = dbtReadyAsset($db, ['device_id' => 6, 'site_name' => '台南']);
    $c = dbtReadyAsset($db, ['device_id' => 7, 'site_name' => '台中']);
    $gone = dbtReadyAsset($db, ['device_id' => 8, 'site_name' => '台南', 'mode' => 'retired']);
    $learning = dbtAsset($db, ['device_id' => 9, 'site_name' => '台南', 'name' => '學習中', 'learning_started_at' => gmdate('Y-m-d H:i:s', time() - 20 * 86400)]);
    $learningStart = dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learning]);
    $mode = static fn (int $id) => dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]);

    $svc->saveSchedule('device', '6', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'admin', DBT_H);
    assertSame(['ready', 'learning', 'ready'], [$mode($a), $mode($b), $mode($c)], '只有裝置 6 重新學習');
    assertSame(['schedule_changed'], dbtHistory($db, $b));
    assertSame('device:6', dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='schedule_saved'"));
    $learningDevice = dbtAsset($db, ['device_id' => 10, 'name' => '學習中的設備', 'learning_started_at' => gmdate('Y-m-d H:i:s', time() - 20 * 86400)]);
    $learningDeviceStart = dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learningDevice]);
    $svc->saveSchedule('device', '10', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'admin', DBT_H);
    assertSame([$learningDeviceStart, []], [dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learningDevice]), array_diff(dbtHistory($db, $learningDevice), ['address_seen'])],
        '設備層級也一樣：學習中的設備存了自己的時段，不重設起算時間、不留重新學習的歷史');

    $db->exec("UPDATE ioc_behavior_assets SET mode='ready' WHERE id IN ($b)");
    $svc->saveSchedule('device', '6', 'Asia/Taipei', '1,2,3,4,5', '09:00', '18:00', 'admin', DBT_H);
    assertSame('ready', $mode($b), '時段沒變：不重新學習');
    assertSame(2, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_audit WHERE action='schedule_saved'"), '裝置 6、裝置 10 各一筆；裝置 6 再存同樣的值沒變，不再留稽核');

    $svc->saveSchedule('site', '台南', 'Asia/Taipei', '1,2,3,4,5', '08:30', '17:30', 'admin', DBT_H);
    assertSame(['learning', 'ready', 'ready', 'retired'], [$mode($a), $mode($b), $mode($c), $mode($gone)],
        '站點層級：台南的裝置 5 重新學習；裝置 6 自己有時段、台中不在範圍、已退役的不動');
    assertSame($learningStart, dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learning]),
        '本來就在學習中的資產（站點也是台南）不重設起算時間：沒有任何已訓練的東西依賴舊時段');

    $svc->saveSchedule('device', '6', 'Asia/Taipei', '1,2,3,4,5,6', '09:00', '18:00', 'admin', DBT_H);
    assertSame('learning', $mode($b), '工作日改了就是有變動');
});

// ---- 警示處置與通知重試 ----

dbtest('setAlertStatus：處置狀態流程、理由必填、留歷史與稽核；模擬警示不能處置', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $asset = dbtReadyAsset($db, ['mode' => 'monitoring']);
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
    $asset = dbtReadyAsset($db, ['mode' => 'monitoring']);
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
    $ready = dbtAsset($db, ['mode' => 'ready']);
    dbtAsset($db, ['mode' => 'retired']);
    dbtAlert($db, $ready);
    dbtAlert($db, $ready, 'preview');
    dbtSource($db, 'ready', gmdate('Y-m-d H:i:s', time() - 20 * 60));        // 防火牆來源 20 分鐘沒更新
    $db->exec("INSERT INTO ioc_behavior_sources (source_key,kind,scope_label,status,coverage,checked_at,reason) VALUES
        ('span1','span','核心交換器','planned','盤點中',UTC_TIMESTAMP(),''),('flow1','flow','邊界','partial','x',UTC_TIMESTAMP(),'')");

    $v = $svc->state(DBT_V);
    assertSame(true, $v['limited']);
    assertTrue(!isset($v['assets']) && !isset($v['sources']) && !isset($v['schedules']), '檢視者拿不到任何資產、位址或來源明細');
    assertSame([1, 1, 1, 1, 2, 1], [$v['summary']['learning'], $v['summary']['ready'], $v['summary']['monitoring'], $v['summary']['retired'],
        $v['summary']['source_error'], $v['summary']['open_alerts']], '來源異常＝過期的防火牆來源＋partial 的 flow1；模擬警示不算待處理');

    $h = $svc->state(DBT_H);
    assertSame(false, $h['limited']);
    assertSame(4, count($h['assets']));
    $byName = array_column($h['assets'], null, 'name');
    assertSame('172.16.1.1', $byName['fw-1']['address']);
    $sources = array_column($h['sources'], null, 'source_key');
    assertSame(['stale', 'planned', 'partial'], [$sources['fortigate_graylog']['status'], $sources['span1']['status'], $sources['flow1']['status']]);
    assertSame('採集器超過 10 分鐘未更新', $sources['fortigate_graylog']['reason']);
    assertSame(null, $h['webhook_status']['recent'], '還沒有任何通知');
});

dbtest('detail：只有管理者；回傳位址、區間、警示（含通知狀態）、歷史、目前版本的基線與關聯', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(1, 'fw-1', '172.16.1.1', '台南');
    $temp = $svc->observeUnknown('172.16.1.77');
    $svc->linkAsset($temp, 1, '核對過 DHCP', 'admin', DBT_H);
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
    assertSame(['asset', 'addresses', 'windows', 'alerts', 'history', 'baseline', 'links', 'schedule'], array_keys($d));
    assertSame(288, count($d['windows']), '最近 288 格（一天）');
    assertTrue(strcmp($d['windows'][0]['window_start'], $d['windows'][1]['window_start']) > 0, '新到舊');
    assertSame([200.0], array_map('floatval', array_column($d['baseline'], 'p95_bytes')), '只回目前版本（2）的基線');
    assertSame('queued', $d['alerts'][0]['delivery_status']);
    assertSame(['172.16.1.77', '172.16.1.1'], array_column($d['addresses'], 'address'), '新到舊：人工確認過的位址也在');
    assertSame(1, count($d['links']));
    assertSame($temp, (int) $d['links'][0]['observed_asset_id']);
    assertSame(1, count($svc->detail($temp, DBT_H)['links']), '從暫時資產那端看也看得到關聯');
});

dbtest('已知限制：人工關聯之後，同一個 IP 的流量仍會另建新的暫時資產（關聯只留紀錄，不改流量歸屬）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $dev = $svc->syncDevice(30, 'fw-3', '172.16.3.1', '台南');     // LibreNMS 認為這台的 IP 還是 .1
    $temp = $svc->observeUnknown('172.16.3.99');                  // 實際流量來自 .99
    $svc->linkAsset($temp, 30, '核對過 DHCP 租約與 MAC', 'admin', DBT_H);
    $next = $svc->observeUnknown('172.16.3.99');                  // 採集器下一輪看到同一個 IP
    assertTrue($next !== $temp && $next !== $dev, '退役的暫時資產不會被沿用，裝置資產也不會接手：又多一個待確認設備');
    assertSame(null, dbtVal($db, 'SELECT device_id FROM ioc_behavior_assets WHERE id=?', [$next]));
});
