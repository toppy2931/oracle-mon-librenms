<?php
declare(strict_types=1);

/**
 * 入口「連線動態」播放用的資料（BehaviorService::vizData）：真的 MariaDB，一次性的 ioc_test_* schema。
 * 場景與輔助函式用 viz_export_dbtest.php 的 vzScenario()（檔名排序在前面，先載入）。
 */

use Ioc\AccessDenied;
use Ioc\BehaviorService;
use Ioc\NotFound;
use Ioc\ValidationError;

/** 固定「現在」（跟 vzScenario 的資料同一個時間），測試才不會隨真實時間漂移。 */
function vpPlay(BehaviorService $svc, int $id, int $hours, string $actor, ?string $level): array
{
    return $svc->vizData($id, $hours, $actor, $level, vzNow());
}

function vpAudit(PDO $db): array
{
    return dbtAll($db, "SELECT actor,action,subject FROM ioc_behavior_audit WHERE action='viz_viewed' ORDER BY id");
}

dbtest('vizData：管理員取得單一主機近 N 小時的連線資料（不遮罩，有真實名稱、位址與目的 IP），其他主機不會夾帶', static function (PDO $db): void {
    [$a, $b] = vzScenario($db);
    $svc = new BehaviorService($db);
    $x = vpPlay($svc, $a, 2, 'admin', DBT_H);
    assertSame(['ioc-behavior-viz/1', false, 2], [$x['schema'], $x['masked'], $x['hours']]);
    assertSame(1, count($x['assets']), '只有被點的那一台');
    $ma = $x['assets'][0];
    assertSame([$a, '財務部 PC-01', '172.16.111.2', 24], [$ma['id'], $ma['label'], $ma['address'], count($ma['windows'])]);
    assertTrue(in_array('203.0.113.5', array_column($ma['destinations'], 'ip'), true), '目的 IP 是真實的，沒有併成 /24');
    $other = vpPlay($svc, $b, 2, 'admin', DBT_H)['assets'][0];
    assertSame([$b, 'observed', array_fill(0, 24, 'missing')], [$other['id'], $other['kind'], array_column($other['windows'], 'q')], '完全沒有區間的主機：整排 missing，不是空場景或零流量');
});

dbtest('vizData：成功才留稽核（誰、看了哪台主機），每次呼叫一筆；失敗不留', static function (PDO $db): void {
    [$a, $b, $gone] = vzScenario($db);
    $svc = new BehaviorService($db);
    assertSame([], vpAudit($db));
    vpPlay($svc, $a, 2, 'alice', DBT_H);
    assertSame([['actor' => 'alice', 'action' => 'viz_viewed', 'subject' => 'asset:' . $a]], vpAudit($db));
    vpPlay($svc, $a, 2, 'alice', DBT_H);
    vpPlay($svc, $b, 2, 'bob', DBT_H);
    assertSame(3, count(vpAudit($db)), '每次呼叫一筆（誰看了幾次都查得到）');
    assertSame(['asset:' . $a, 'asset:' . $a, 'asset:' . $b], array_column(vpAudit($db), 'subject'));
    // 失敗：沒有權限、找不到、已移除、參數不合法——都不留稽核
    foreach ([[$a, 2, DBT_V], [$a, 2, null]] as [$id, $h, $level]) {
        assertThrows(AccessDenied::class, fn () => vpPlay($svc, $id, $h, 'x', $level));
    }
    assertThrows(NotFound::class, fn () => vpPlay($svc, 424242, 2, 'x', DBT_H));
    assertThrows(NotFound::class, fn () => vpPlay($svc, $gone, 2, 'x', DBT_H), '找不到主機');       // 已移除的主機不能播放
    foreach ([0, 169, -1] as $hours) {
        assertThrows(ValidationError::class, fn () => vpPlay($svc, $a, $hours, 'x', DBT_H), '', '時數 ' . $hours);
    }
    assertThrows(ValidationError::class, fn () => vpPlay($svc, 0, 2, 'x', DBT_H), '', '編號 0');
    assertSame(3, count(vpAudit($db)), '失敗的呼叫沒有留下稽核');
});

dbtest('vizData：沒有權限時連資料庫都不查（檢視者拿不到任何 IP）', static function (PDO $db): void {
    [$a] = vzScenario($db);
    $svc = new BehaviorService($db);
    try { vpPlay($svc, $a, 2, 'x', DBT_V); fail('應該被拒絕'); } catch (AccessDenied $e) { assertTrue(strpos($e->getMessage(), '172.16') === false && strpos($e->getMessage(), '財務部') === false, '錯誤訊息不帶資料'); }
    assertSame(0, (int) dbtVal($db, "SELECT COUNT(*) FROM ioc_behavior_audit"), '連一筆稽核都沒有');
});
