<?php
declare(strict_types=1);

/** 匯出紀錄（ExportLog）對真的 MariaDB：寫入、新到舊、欄位長度截斷（不能因為條件太長就寫不進去而讓匯出失敗）、中文與 emoji。 */

use Ioc\ExportLog;

dbtest('匯出紀錄：記下誰、何時、匯出什麼、幾筆；最近的在前；中文與 emoji 不亂碼', static function (PDO $db): void {
    $log = new ExportLog($db);
    $log->record('alice', 'logs', '來源位址＝10.0.0.1；最近 24 小時 😀', 12);
    $log->record('bob', 'report:cases', '事件彙整', 0);
    $r = $log->recent(10);
    assertSame(['bob', 'alice'], array_column($r, 'actor'));
    assertSame(['report:cases', 'logs'], array_column($r, 'kind'));
    assertSame([0, 12], array_map('intval', array_column($r, 'row_count')));
    assertSame('來源位址＝10.0.0.1；最近 24 小時 😀', $r[1]['detail']);
    assertTrue(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $r[0]['occurred_at']) === 1, '時間是 UTC 的 DATETIME');
    assertSame(1, count($log->recent(1)), '筆數上限');
});

dbtest('匯出紀錄：過長的條件、帳號、類型被截斷而不是讓寫入失敗（嚴格模式下超過欄位寬度會丟錯，匯出就跟著失敗）', static function (PDO $db): void {
    $db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
    $log = new ExportLog($db);
    $log->record(str_repeat('甲', 300), str_repeat('k', 80), str_repeat('字', 400), -5);
    $r = $log->recent(1)[0];
    assertSame([255, 40, 255, 0], [mb_strlen($r['actor']), mb_strlen($r['kind']), mb_strlen($r['detail']), (int) $r['row_count']], '截斷到欄位寬度，負數筆數當 0');
});
