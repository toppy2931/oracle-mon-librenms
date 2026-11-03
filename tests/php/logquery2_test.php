<?php
declare(strict_types=1);

// 日誌查詢（LogQuery）：獨立審查後補的規則。lqMake()／lqRow() 在 logquery_test.php（檔名排序在前，會先載入）。

use Ioc\ValidationError;

test('日誌查詢：關鍵字查詢（比對原始訊息）只有管理者；檢視者送關鍵字被拒絕、不送到 Graylog，其他欄位照常可用', function () {
    $viewer = lqMake($src, false, null, null, false);
    $e = assertThrows(ValidationError::class, fn () => $viewer->search(['keyword' => 'password']), '關鍵字查詢只有管理者');
    assertSame(1, count($e->errors()));
    assertSame([], $src->calls, '被拒絕的條件不能送到 Graylog');
    assertSame(true, $viewer->search(['src_ip' => '10.0.0.1', 'user_name' => 'alice', 'keyword' => '   '])['ok'], '空白的關鍵字等於沒填，不算要用這個功能');
    assertSame(true, $viewer->search(['src_ip' => '10.0.0.1'])['ok']);
    $admin = lqMake($src2, false, null, null, true);
    $admin->search(['keyword' => 'password']);
    assertTrue(strpos($src2->calls[0]['query'], 'message:"password"') !== false, '管理者可以');
    $default = new Ioc\LogQuery(new FakeWindowedSource(), false, LQ_NOW);
    assertThrows(ValidationError::class, fn () => $default->search(['keyword' => 'x']), '關鍵字查詢只有管理者');   // 沒有明確允許（建構子預設）就不允許
});

test('日誌匯出：匯出紀錄的摘要把時間範圍放在最前面（紀錄欄位只有 255 字，過長被截掉的應該是篩選條件，不是時間範圍）', function () {
    $q = lqMake($src, false, fn () => [lqRow()], fn () => [['log_type' => 'firewall', 'count' => 1]]);
    $r = $q->export(['user_name' => str_repeat('字', 100), 'host_name' => str_repeat('設', 100), 'keyword' => str_repeat('詞', 100)]);
    assertSame(true, $r['ok']);
    assertTrue(mb_strlen($r['summary']) > 255, '條件很長時摘要超過紀錄欄位的寬度');
    assertTrue(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d ～ \d{4}-\d\d-\d\d \d\d:\d\d:\d\d；/u', $r['summary']) === 1, '開頭是時間範圍：' . mb_substr($r['summary'], 0, 60));
    assertTrue(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d ～ /u', mb_substr($r['summary'], 0, 255)) === 1, '截到 255 字之後時間範圍仍在');
});
