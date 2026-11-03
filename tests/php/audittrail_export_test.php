<?php
declare(strict_types=1);

// 稽核軌跡（AuditTrail）的「資料匯出」來源（匯出紀錄 ioc_export_log）。

use Ioc\Access;
use Ioc\AuditTrail;

function atxRows(): array
{
    return [
        ['actor' => 'alice', 'kind' => 'logs', 'detail' => '2026-10-20 10:00:00 ～ 2026-10-21 10:00:00；來源位址＝10.0.0.1', 'row_count' => 12, 'occurred_at' => '2026-10-21 02:00:00'],
        ['actor' => 'bob', 'kind' => 'report:cases', 'detail' => '期間＝最近 7 日；最近 7 日', 'row_count' => 30, 'occurred_at' => '2026-10-21 03:00:00'],
        ['actor' => 'bob', 'kind' => 'report:made_up', 'detail' => 'x', 'row_count' => 0, 'occurred_at' => '2026-10-21 04:00:00'],
        ['actor' => 'x', 'kind' => 'other', 'detail' => str_repeat('字', 300), 'row_count' => 1, 'occurred_at' => 'garbage'],
    ];
}

test('稽核軌跡：資料匯出——誰、何時、匯出什麼、條件與筆數；報表用中文名稱；時間壞掉的列不列', function () {
    $r = (new AuditTrail('Asia/Taipei'))->build(['login' => [], 'admin_op' => [], 'case' => [], 'settings' => [], 'export' => atxRows()], ['category' => 'export'], Access::HANDLER);
    assertSame(3, $r['total'], '時間壞掉的那列不能排進時間表');
    $byActor = array_column($r['items'], null, 'action');
    $logs = $byActor['匯出日誌 CSV'];
    assertSame(['alice', '2026-10-21 10:00:00', 'export', '資料匯出', 'ok', ''], [$logs['actor'], $logs['time'], $logs['category'], $logs['category_label'], $logs['result'], $logs['ip']], '時間 UTC 轉台北');
    assertTrue(strpos($logs['detail'], '來源位址＝10.0.0.1') !== false && mb_substr($logs['detail'], -5) === '；12 筆', $logs['detail']);
    assertTrue(isset($byActor['匯出報表 CSV（事件彙整）']), '報表代碼換成中文名稱');
    assertTrue(isset($byActor['匯出報表 CSV（made_up）']), '不認得的報表代碼原樣顯示，不丟失這筆紀錄');
    assertSame(true, $r['sources']['export']['ok']);
});

test('稽核軌跡：資料匯出是選用來源——呼叫端沒給這個鍵就不列；給了卻是 null 才標「無法取得」，其他來源照常', function () {
    $none = (new AuditTrail())->build(['login' => [], 'admin_op' => [], 'case' => [], 'settings' => []], [], Access::HANDLER);
    assertSame(['login', 'admin_op', 'case', 'settings'], array_keys($none['sources']), '沒給就不列（舊的呼叫端不受影響）');
    $bad = (new AuditTrail())->build(['login' => [], 'admin_op' => [], 'case' => [], 'settings' => [], 'export' => null], [], Access::HANDLER);
    assertSame([false, true], [$bad['sources']['export']['ok'], $bad['sources']['login']['ok']]);
    assertSame(1, count(array_filter($bad['sources'], fn ($s) => !$s['ok'])));
});

test('稽核軌跡：資料匯出的詳情截到 120 字；只有管理者看得到（沿用稽核軌跡的權限檢查）', function () {
    $r = (new AuditTrail())->build(['login' => [], 'admin_op' => [], 'case' => [], 'settings' => [], 'export' => [['actor' => 'x', 'kind' => 'logs', 'detail' => str_repeat('字', 300), 'row_count' => 1, 'occurred_at' => '2026-10-21 02:00:00']]], [], Access::HANDLER);
    assertSame(120 + mb_strlen('；1 筆'), mb_strlen($r['items'][0]['detail']));
    assertThrows(Ioc\AccessDenied::class, fn () => (new AuditTrail())->build(['export' => []], [], Access::VIEWER));
});
