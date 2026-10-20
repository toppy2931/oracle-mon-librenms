<?php
declare(strict_types=1);

use Ioc\CaseView;
use Ioc\GraylogUnavailable;
use Ioc\OverviewService;
use Ioc\UnavailableLogSource;

test('CaseView：中文名稱、UTC 轉台北時間、空值變空字串', function () {
    $v = CaseView::row([
        'id' => '7', 'title' => '可疑登入', 'summary' => null, 'status' => 'closed', 'severity' => 'high',
        'subject_user' => 'administrator', 'subject_ip' => '10.20.5.23', 'subject_host' => null, 'evidence_query' => null,
        'source' => 'manual', 'assignee' => 'bob', 'resolution' => 'false_positive', 'created_by' => 'alice',
        'created_at' => '2026-10-20 05:00:00', 'updated_at' => '2026-10-20 08:30:00', 'closed_at' => '2026-10-20 08:30:00',
    ]);
    assertSame(7, $v['id']);
    assertSame(['已結案', '高', '誤報', '手動建立'], [$v['status_label'], $v['severity_label'], $v['resolution_label'], $v['source_label']]);
    assertSame(['2026-10-20 13:00:00', '2026-10-20 16:30:00', '2026-10-20 16:30:00'], [$v['created_at'], $v['updated_at'], $v['closed_at']]);
    assertSame(['', '', ''], [$v['summary'], $v['subject_host'], $v['evidence_query']]);
});

test('CaseView：未結案沒有處理結果與結案時間；未知代碼原樣顯示', function () {
    $v = CaseView::row(['id' => 1, 'title' => 't', 'status' => 'weird', 'severity' => 'odd', 'resolution' => null, 'closed_at' => null,
                        'source' => 'x', 'created_at' => '2026-10-20 05:00:00', 'updated_at' => 'garbage']);
    assertSame(['', ''], [$v['resolution_label'], $v['closed_at']]);
    assertSame(['weird', 'odd', 'x'], [$v['status_label'], $v['severity_label'], $v['source_label']]);
    assertSame('—', $v['updated_at'], '壞掉的時間顯示 —');
});

test('CaseView：歷程的中文動作與狀態名稱', function () {
    $h = CaseView::history(['ts' => '2026-10-20 05:00:00', 'actor' => 'bob', 'action' => 'status_changed', 'from_status' => 'in_progress',
                            'to_status' => 'closed', 'resolution' => 'fixed', 'note' => '已改密碼']);
    assertSame(['2026-10-20 13:00:00', 'bob', '狀態變更', '處理中', '已結案', '已處理', '已改密碼'],
        [$h['time'], $h['actor'], $h['action_label'], $h['from_label'], $h['to_label'], $h['resolution_label'], $h['note']]);
    $c = CaseView::history(['ts' => '2026-10-20 05:00:00', 'actor' => 'a', 'action' => 'created', 'from_status' => null, 'to_status' => 'new', 'resolution' => null, 'note' => null]);
    assertSame(['建立事件案', '', '待確認', '', ''], [$c['action_label'], $c['from_label'], $c['to_label'], $c['resolution_label'], $c['note']]);
    assertSame('comment', CaseView::history(['ts' => null, 'actor' => 'a', 'action' => 'comment', 'from_status' => null, 'to_status' => null])['action']);
});

test('CaseView：可用的轉換轉成中文按鈕', function () {
    assertSame([['to' => 'ack', 'label' => '已確認'], ['to' => 'closed', 'label' => '已結案']], CaseView::transitions(['ack', 'closed']));
    assertSame([], CaseView::transitions([]));
});

test('尚未設定 Graylog 時：各日誌區塊是「尚未設定」，設備與事件案照常，沒有任何數字被當成 0', function () {
    $o = (new OverviewService(new UnavailableLogSource('尚未設定 Graylog 唯讀帳號'), false, 1760000000))
        ->build(['total' => 10, 'up' => 10, 'down_list' => []], ['new' => 0, 'ack' => 0, 'in_progress' => 0, 'closed' => 0, 'open' => 0]);
    foreach (['coverage', 'activity'] as $s) {
        assertSame([false, '尚未設定 Graylog 唯讀帳號'], [$o[$s]['ok'], $o[$s]['error']]);
    }
    $k = array_column($o['kpi'], null, 'key');
    assertSame('10/10', $k['devices']['value']);
    assertSame(0, $k['cases']['value'], '事件案真的是 0 件，這個 0 是真的');
    assertSame([null, null, null], [$k['suspicious']['value'], $k['sources']['value'], $k['ops']['value']]);
    assertThrows(GraylogUnavailable::class, fn () => (new UnavailableLogSource('x'))->aggregate('q', 1, ['a'], ['count']));
    assertThrows(GraylogUnavailable::class, fn () => (new UnavailableLogSource('x'))->messages('q', 1, ['a'], 1));
});
