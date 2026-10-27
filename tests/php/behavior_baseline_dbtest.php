<?php
declare(strict_types=1);

/**
 * 取消強制 28 天：按「啟用監控」時立即用現有資料訓練基線（BehaviorService::previewBaseline／setMode）、
 * 狀態只剩學習中／監控中（沒有 ready）、站點與時段變動只動監控中的資產。真的 MariaDB，一次性的 ioc_test_* schema。
 * 用到 behavior_collector_dbtest.php 的 bcd* 輔助函式（檔名排序在前面，先載入）。
 */

use Ioc\AccessDenied;
use Ioc\BehaviorCollector;
use Ioc\BehaviorService;
use Ioc\NotFound;
use Ioc\Schema;
use Ioc\ValidationError;

/** 一個學習中的 LibreNMS 設備資產（設備 1），學習起點預設是 3 天前。 */
function bbdAsset(PDO $db, array $o = []): int
{
    return dbtAsset($db, $o + ['device_id' => 1, 'name' => 'fw', 'mode' => 'learning',
        'learning_started_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
}

/** 從 $hoursAgo 小時前起、連續 $count 格完整區間（每格 10 筆連線、1000 位元組、3 個目的地）。 */
function bbdWindows(PDO $db, int $assetId, int $count, int $hoursAgo = 24, int $connections = 10): void
{
    bcdBulkWindows($db, $assetId, $count, intdiv(time() - $hoursAgo * 3600, 300) * 300, $connections);
}

dbtest('previewBaseline：只有管理者；回報區間數、有連線區間數、涵蓋天數與離峰時段數、是否達標；唯讀', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = bbdAsset($db);
    bbdWindows($db, $id, 144);
    assertThrows(AccessDenied::class, fn () => $svc->previewBaseline($id, DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->previewBaseline($id, null));
    assertThrows(NotFound::class, fn () => $svc->previewBaseline(424242, DBT_H));
    $p = $svc->previewBaseline($id, DBT_H);
    assertSame([144, 144, true, []], [$p['windows'], $p['active_windows'], $p['ok'], $p['reasons']]);
    assertSame([144, 12], [$p['min_windows'], $p['min_active_windows']]);
    assertTrue($p['days'] >= 1 && $p['days'] <= 2 && $p['offhours_buckets'] >= 0, '涵蓋 12 小時，跨一到兩個本地日期');
    assertSame(['learning', 0, 0], [dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]), (int) dbtVal($db, 'SELECT baseline_version FROM ioc_behavior_assets WHERE id=?', [$id]),
        (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_baselines')], '預覽不改任何資料');

    $few = bbdAsset($db, ['device_id' => 2]);
    bbdWindows($db, $few, 143);
    $q = $svc->previewBaseline($few, DBT_H);
    assertSame([143, false], [$q['windows'], $q['ok']]);
    assertTrue(strpos($q['reasons'][0], '143') !== false && strpos($q['reasons'][0], '144') !== false, $q['reasons'][0]);
});

dbtest('previewBaseline：只算學習起點之後、最多最近 28 天、品質完整的區間', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = bbdAsset($db, ['learning_started_at' => gmdate('Y-m-d H:i:s', time() - 40 * 86400)]);
    bbdWindows($db, $id, 10, 30 * 24);                       // 30 天前：超過 28 天，不算
    bbdWindows($db, $id, 20, 2 * 24);                         // 2 天前：算
    $db->prepare("INSERT INTO ioc_behavior_windows (asset_id,source_key,window_start,connections,bytes_total,destinations,ports,destinations_json,ports_json,signals_json,quality,quality_reason,observed_at)
        VALUES (?,?,?,5,500,1,1,'[]','[]','{}','partial','x',UTC_TIMESTAMP())")->execute([$id, BehaviorService::SOURCE, gmdate('Y-m-d H:i:s', intdiv(time() - 3600, 300) * 300)]);   // 不完整：不算
    assertSame(20, $svc->previewBaseline($id, DBT_H)['windows']);
    // 學習起點之後：起點之前結束的區間不算（重新學習要真的忘掉舊的）
    $db->prepare('UPDATE ioc_behavior_assets SET learning_started_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 12 * 3600), $id]);
    assertSame(0, $svc->previewBaseline($id, DBT_H)['windows'], '20 格都在起點之前');
});

dbtest('setMode 啟用監控：用目前的資料立即訓練基線、進入監控、留歷史與稽核；已在監控再按一次不重訓', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = bbdAsset($db);
    bbdWindows($db, $id, 144);
    dbtSource($db, 'ready');
    $svc->setMode($id, 'monitoring', 'admin', DBT_H);
    $row = dbtRow($db, 'SELECT mode,baseline_version,quality_status FROM ioc_behavior_assets WHERE id=?', [$id]);
    assertSame(['monitoring', 1], [$row['mode'], (int) $row['baseline_version']]);
    $buckets = (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_baselines WHERE asset_id=? AND version=1', [$id]);
    assertTrue($buckets >= 12 && $buckets <= 24, '12 小時的資料：每個小時一個桶（可能跨平日週末）：' . $buckets);
    assertTrue((int) dbtVal($db, 'SELECT MIN(sample_count) FROM ioc_behavior_baselines WHERE asset_id=?', [$id]) >= 1);
    assertSame(['baseline_ready', 'mode_changed'], array_values(array_intersect(dbtHistory($db, $id), ['baseline_ready', 'mode_changed'])), '歷程：訓練與模式變更各一筆');
    assertTrue(strpos((string) dbtVal($db, "SELECT detail FROM ioc_behavior_history WHERE asset_id=? AND action='baseline_ready'", [$id]), '144') !== false, '歷程寫出用了幾個區間');
    assertSame('asset:' . $id . ':monitoring', dbtVal($db, "SELECT subject FROM ioc_behavior_audit WHERE action='mode_changed'"));
    $svc->setMode($id, 'monitoring', 'admin', DBT_H);
    assertSame(1, (int) dbtVal($db, 'SELECT baseline_version FROM ioc_behavior_assets WHERE id=?', [$id]), '已經在監控：不重訓、不重複記歷程');
    assertSame(1, count(array_keys(dbtHistory($db, $id), 'baseline_ready')));
});

dbtest('setMode 啟用監控：資料不夠就擋下並說明缺多少，不改任何資料；來源、身分與已移除各自擋得住', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $few = bbdAsset($db);
    bbdWindows($db, $few, 143);
    $quiet = bbdAsset($db, ['device_id' => 2]);
    bbdWindows($db, $quiet, 200, 24, 0);                      // 完整區間夠，但全部沒有連線
    $host = dbtAsset($db, ['identity_key' => 'observed:172.16.9.9:x', 'name' => '待確認設備 172.16.9.9', 'learning_started_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
    bbdWindows($db, $host, 200);
    $gone = bbdAsset($db, ['device_id' => 3, 'mode' => 'retired']);
    bbdWindows($db, $gone, 200);
    assertThrows(ValidationError::class, fn () => $svc->setMode($few, 'monitoring', 'a', DBT_H), '防火牆日誌來源', '沒有來源狀態');
    dbtSource($db, 'partial');
    assertThrows(ValidationError::class, fn () => $svc->setMode($few, 'monitoring', 'a', DBT_H), '防火牆日誌來源', '來源不是 ready');
    dbtSource($db, 'ready', gmdate('Y-m-d H:i:s', time() - 20 * 60));
    assertThrows(ValidationError::class, fn () => $svc->setMode($few, 'monitoring', 'a', DBT_H), '防火牆日誌來源', '來源超過 15 分鐘沒更新');
    dbtSource($db, 'ready');
    assertThrows(ValidationError::class, fn () => $svc->setMode($few, 'monitoring', 'a', DBT_H), '143', '差一格：說出現有幾格');
    assertThrows(ValidationError::class, fn () => $svc->setMode($quiet, 'monitoring', 'a', DBT_H), '有連線', '全部沒連線');
    assertThrows(ValidationError::class, fn () => $svc->setMode($host, 'monitoring', 'a', DBT_H), '確認為受管主機', '待確認設備須先確認');
    assertThrows(ValidationError::class, fn () => $svc->setMode($gone, 'monitoring', 'a', DBT_H), '已移除', '已移除的不能啟用');
    foreach ([$few, $quiet, $host] as $id) {
        assertSame(['learning', 0], [dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]), (int) dbtVal($db, 'SELECT baseline_version FROM ioc_behavior_assets WHERE id=?', [$id])], '每一次被擋下都不能改到資料');
    }
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_baselines'));
    $svc->confirmHosts([['id' => $host]], '核對過', 'admin', DBT_H);
    $svc->setMode($host, 'monitoring', 'admin', DBT_H);
    assertSame('monitoring', dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$host]), '確認過的主機可以啟用');
});

dbtest('setMode 重新學習：重設學習起點、清已知目的地、不再有「28 天」；之後啟用監控只用重設之後的區間（新版基線）', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $id = bbdAsset($db);
    bbdWindows($db, $id, 144);
    dbtSource($db, 'ready');
    $svc->setMode($id, 'monitoring', 'admin', DBT_H);
    $db->prepare("INSERT INTO ioc_behavior_known (asset_id,source_key,kind,value_key,first_seen_at,last_seen_at) VALUES (?,'fortigate_graylog','port','443',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$id]);

    $svc->setMode($id, 'learning', 'admin', DBT_H);
    $row = dbtRow($db, 'SELECT mode,quality_status,learning_started_at,baseline_version FROM ioc_behavior_assets WHERE id=?', [$id]);
    assertSame(['learning', 'learning', 1], [$row['mode'], $row['quality_status'], (int) $row['baseline_version']], '舊版基線保留，只是學習期間不用它判斷');
    assertTrue(abs(strtotime($row['learning_started_at'] . ' UTC') - time()) < 10);
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_known WHERE asset_id=?', [$id]));
    assertThrows(ValidationError::class, fn () => $svc->setMode($id, 'monitoring', 'admin', DBT_H), '0', '舊的區間不算：重新學習之後還沒有資料');
    bbdWindows($db, $id, 150, 0);                              // 從現在起算的新區間（時間往後排）
    $svc->setMode($id, 'monitoring', 'admin', DBT_H);
    assertSame([2, 'monitoring'], [(int) dbtVal($db, 'SELECT baseline_version FROM ioc_behavior_assets WHERE id=?', [$id]), dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id])], '新版基線');
    $svc->setMode($id, 'learning', 'admin', DBT_H);
    assertSame(['learning', 'learning'], [dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]), dbtVal($db, 'SELECT quality_status FROM ioc_behavior_assets WHERE id=?', [$id])]);
});

dbtest('採集器不再自動訓練基線：學習滿很久、區間再多，沒有按「啟用監控」就一直是學習中', static function (PDO $db): void {
    $id = (new BehaviorService($db))->syncDevice(1, 'fw-1', '172.16.1.11', '台南');
    $db->prepare('UPDATE ioc_behavior_assets SET learning_started_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 40 * 86400), $id]);
    bcdBulkWindows($db, $id, 6048, intdiv(time() - 27 * 86400, 300) * 300);
    bcdTickOnceFor($db);
    assertSame(['learning', 0], [dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$id]), (int) dbtVal($db, 'SELECT baseline_version FROM ioc_behavior_assets WHERE id=?', [$id])]);
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_baselines'));
});

/** 讓採集器跑一個新的區間（內容無關緊要）。 */
function bcdTickOnceFor(PDO $db): void
{
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdNormal('172.16.1.11'))))->tick([bcdDevice(1, '172.16.1.11')], bcdNets(), time());
}

dbtest('遺留的 ready 資產：資料庫更新把它改回學習中（基線保留）；採集器對不是監控中的資產一律只學習、不產生警示', static function (PDO $db): void {
    $id = dbtAsset($db, ['device_id' => 5, 'name' => 'fw', 'mode' => 'ready', 'baseline_version' => 1, 'quality_status' => 'ready']);
    foreach (Schema::statements() as $sql) { $db->exec($sql); }
    foreach (Schema::statements() as $sql) { $db->exec($sql); }          // 冪等：重跑不出事
    $row = dbtRow($db, 'SELECT mode,quality_status,baseline_version FROM ioc_behavior_assets WHERE id=?', [$id]);
    assertSame(['learning', 'learning', 1], [$row['mode'], $row['quality_status'], (int) $row['baseline_version']]);

    $legacy = bcdMonitored($db, 'monitoring');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='ready' WHERE id=?")->execute([$legacy]);       // 萬一還有（資料庫更新沒跑到）
    (new BehaviorCollector($db, DbtFakeLogs::traffic(bcdSurge())))->tick([bcdDevice()], bcdNets(), bcdNow());
    assertSame(0, (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_alerts'), '不是監控中就不判斷，也不開模擬警示');
});

dbtest('站點歸屬變更：只有監控中的資產改回學習中（基線依賴上班時段）；學習中的不重設，已累積的區間才有用', static function (PDO $db): void {
    $svc = new BehaviorService($db);
    $learning = $svc->syncDevice(10, 'fw-1', '172.16.1.1', '台南');
    $started = dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learning]);
    $db->prepare("INSERT INTO ioc_behavior_known (asset_id,source_key,kind,value_key,first_seen_at,last_seen_at) VALUES (?,'fortigate_graylog','port','443',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$learning]);
    $svc->syncDevice(10, 'fw-1', '172.16.1.1', '台中');
    assertSame(['台中', $started, 1], [dbtVal($db, 'SELECT site_name FROM ioc_behavior_assets WHERE id=?', [$learning]), dbtVal($db, 'SELECT learning_started_at FROM ioc_behavior_assets WHERE id=?', [$learning]),
        (int) dbtVal($db, 'SELECT COUNT(*) FROM ioc_behavior_known WHERE asset_id=?', [$learning])], '學習中：只換站點');
    assertTrue(!in_array('site_changed', dbtHistory($db, $learning), true));

    $host = $svc->observeUnknown('172.16.9.9', static fn (string $ip): ?string => '台南');
    $db->prepare("UPDATE ioc_behavior_assets SET mode='monitoring',baseline_version=1 WHERE id=?")->execute([$host]);
    $svc->observeUnknown('172.16.9.9', static fn (string $ip): ?string => '台中');
    assertSame(['台中', 'learning'], [dbtVal($db, 'SELECT site_name FROM ioc_behavior_assets WHERE id=?', [$host]), dbtVal($db, 'SELECT mode FROM ioc_behavior_assets WHERE id=?', [$host])], '監控中：改回學習中');
    assertTrue(in_array('site_changed', dbtHistory($db, $host), true));
});
