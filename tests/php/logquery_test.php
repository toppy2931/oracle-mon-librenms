<?php
declare(strict_types=1);

// 日誌查詢頁（LogQuery）與 CSV 輸出（CsvExport）。FakeWindowedSource 在 detail_test.php（檔名排序在前，會先載入）。

use Ioc\CsvExport;
use Ioc\GraylogUnavailable;
use Ioc\LogQuery;
use Ioc\UnavailableLogSource;
use Ioc\ValidationError;

const LQ_NOW = 1760000000;

function lqMake(?FakeWindowedSource &$src = null, bool $demo = false, ?callable $msg = null, ?callable $agg = null, bool $keyword = true): LogQuery
{
    $src = new FakeWindowedSource($msg, $agg);
    return new LogQuery($src, $demo, LQ_NOW, $keyword);
}

function lqRow(array $extra = []): array
{
    return $extra + ['timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', LQ_NOW - 600), 'log_type' => 'firewall', 'event_action' => 'fw_deny', 'severity' => 'medium',
                     'user_name' => null, 'src_ip' => '10.20.5.23', 'dst_ip' => '203.0.113.9', 'host_name' => 'FW-01', 'target_name' => null];
}

// ── CSV ──────────────────────────────────────────────────────────────

test('CSV：UTF-8 BOM、CRLF、字串一律加雙引號、引號加倍；數字原樣；null 是空字串', function () {
    $csv = CsvExport::build([['報表', '測試']], ['甲', '乙', '丙'], [['a"b', 12, null], ['x,y', -3.5, true]]);
    assertSame("\xEF\xBB\xBF", substr($csv, 0, 3), 'Excel 要靠 BOM 認得 UTF-8');
    assertSame("\"報表\",\"測試\"\r\n\r\n\"甲\",\"乙\",\"丙\"\r\n\"a\"\"b\",12,\"\"\r\n\"x,y\",-3.5,\"是\"\r\n", substr($csv, 3));
    assertSame("\"甲\"\r\n\"x\"\r\n", substr(CsvExport::build([], ['甲'], [['x']]), 3), '沒有說明列就沒有空白列');
});

test('CSV：試算表公式注入——以 = + - @ Tab CR 開頭的字串前面加單引號，數字與中間出現的符號不動', function () {
    foreach (['=HYPERLINK("http://x","y")', '+1+1', '-2+3', '@SUM(A1)', "\t=1", "\r=1"] as $bad) {
        $cell = CsvExport::cell($bad);
        assertTrue(strncmp($cell, "\"'", 2) === 0, 'Excel 會當成公式：' . json_encode($bad));
    }
    assertSame('-5', CsvExport::cell(-5), '真的數字不是字串，不加單引號');
    assertSame('"a=b"', CsvExport::cell('a=b'));
    assertSame('"王小明"', CsvExport::cell('王小明'));
    assertSame('""', CsvExport::cell(''));
    assertSame('"ab"', CsvExport::cell("a\x00b\x07"), '控制字元拿掉');
    assertSame("\"a\nb\"", CsvExport::cell("a\nb"), '換行在引號裡是合法的');
});

// ── 條件驗證 ─────────────────────────────────────────────────────────

test('日誌查詢：預設最近 24 小時、沒有條件就是全部（查詢字串 *，仍排除範例資料）', function () {
    $q = lqMake($src);
    $r = $q->search([]);
    assertSame(true, $r['ok']);
    assertSame('(*) AND NOT ioc_demo:true', $src->calls[0]['query']);
    assertSame([gmdate('Y-m-d\TH:i:s.000\Z', LQ_NOW - 86400), gmdate('Y-m-d\TH:i:s.000\Z', LQ_NOW)], [$src->calls[0]['from'], $src->calls[0]['to']]);
    assertSame('（沒有篩選條件）', $r['data']['summary']);
});

test('日誌查詢：範例資料模式只查範例資料', function () {
    $q = lqMake($src, true);
    $q->search(['src_ip' => '10.0.0.1']);
    assertSame('(src_ip:"10.0.0.1") AND ioc_demo:true', $src->calls[0]['query']);
});

test('日誌查詢：時間範圍只收預設選項；自訂範圍要嚴格的 UTC ISO 8601、結束晚於開始、最長 7 日、不能在未來', function () {
    $q = lqMake($src);
    foreach ([3600, 21600, 86400, 604800] as $s) {
        assertSame(true, $q->search(['range_seconds' => $s])['ok']);
    }
    $iso = fn (int $t) => gmdate('Y-m-d\TH:i:s.000\Z', $t);
    $bad = [
        ['range_seconds' => 7200], ['range_seconds' => '3600'], ['range_seconds' => [3600]],
        ['from' => $iso(LQ_NOW - 7200)],                                                      // 只給一個
        ['from' => '2026-10-20 14:00:00', 'to' => $iso(LQ_NOW)],                              // 不是 ISO
        ['from' => $iso(LQ_NOW - 7200) . "\n", 'to' => $iso(LQ_NOW)],                         // 結尾換行
        ['from' => $iso(LQ_NOW), 'to' => $iso(LQ_NOW - 60)],                                  // 結束早於開始
        ['from' => $iso(LQ_NOW - 8 * 86400), 'to' => $iso(LQ_NOW)],                           // 超過 7 日
        ['from' => $iso(LQ_NOW - 3600), 'to' => $iso(LQ_NOW + 7200)],                         // 在未來
        ['from' => ['x'], 'to' => $iso(LQ_NOW)],
    ];
    foreach ($bad as $in) {
        assertThrows(ValidationError::class, fn () => $q->search($in), '');
    }
    $r = $q->search(['from' => $iso(LQ_NOW - 7 * 86400), 'to' => $iso(LQ_NOW)]);
    assertSame(true, $r['ok'], '剛好 7 日可以');
});

test('日誌查詢：選項欄位只收認得的代碼、位址要是完整的 IP、文字有長度與字元限制、型別不對（陣列、數字）一律拒絕，錯誤一次回報', function () {
    $q = lqMake($src);
    $bad = [
        ['log_type' => 'mainframe'], ['event_action' => 'fw_deny OR *'], ['severity' => 'critical'],
        ['src_ip' => '10.0.0'], ['src_ip' => '10.0.0.1 OR ioc_demo:true'], ['dst_ip' => '*'], ['src_ip' => ['10.0.0.1']],
        ['user_name' => str_repeat('字', 101)], ['user_name' => "a\x00b"], ['host_name' => "ba\nd"], ['keyword' => "\xff\xfe"], ['keyword' => 123], ['user_name' => ['a']], ['log_type' => ['firewall']],
    ];
    foreach ($bad as $in) {
        assertThrows(ValidationError::class, fn () => $q->search($in), '');
    }
    $e = assertThrows(ValidationError::class, fn () => $q->search(['log_type' => 'x', 'src_ip' => 'y', 'user_name' => ['z']]));
    assertSame(3, count($e->errors()), '三個欄位的錯誤一次回報');
    assertSame([], $src->calls, '被拒絕的條件不能送到 Graylog');
    assertSame(true, $q->search(['user_name' => str_repeat('字', 100), 'src_ip' => '2001:db8::1'])['ok'], '剛好 100 字、IPv6 都可以');
});

test('日誌查詢：值只當成文字——引號與反斜線跳脫，使用者寫的查詢語法跳不出引號，也跳不出「排除範例資料」的包裝', function () {
    $q = lqMake($src);
    $q->search(['user_name' => 'a" OR ioc_demo:true OR x:"b', 'keyword' => 'back\\slash*?']);
    assertSame('(user_name:"a\\" OR ioc_demo:true OR x:\\"b" AND message:"back\\\\slash*?") AND NOT ioc_demo:true', $src->calls[0]['query']);
    $q->search(['log_type' => 'firewall', 'event_action' => 'fw_deny', 'severity' => 'high', 'src_ip' => ' 10.1.1.1 ', 'dst_ip' => '203.0.113.9', 'host_name' => 'FW-01']);
    assertSame('(log_type:"firewall" AND event_action:"fw_deny" AND severity:"high" AND src_ip:"10.1.1.1" AND dst_ip:"203.0.113.9" AND host_name:"FW-01") AND NOT ioc_demo:true', $src->calls[2]['query']);
});

// ── 結果 ─────────────────────────────────────────────────────────────

test('日誌查詢：只向 Graylog 要標準化欄位，不要原始訊息；結果換成中文標籤，沒分類的日誌明講', function () {
    $q = lqMake($src, false, fn () => [lqRow(), lqRow(['event_action' => null, 'log_type' => 'linux', 'severity' => null, 'user_name' => 'alice', 'message' => 'password=hunter2'])],
        fn () => [['log_type' => 'firewall', 'count' => 1], ['log_type' => 'linux', 'count' => 1]]);
    $r = $q->search([]);
    assertTrue(!in_array('message', $src->calls[0]['fields'], true), '不取原始訊息');
    assertSame(200, $src->calls[0]['size']);
    $i = $r['data']['items'];
    assertSame(['防火牆', '防火牆阻擋', '中', '10.20.5.23', '203.0.113.9'], [$i[0]['log_type_label'], $i[0]['action_label'], $i[0]['severity_label'], $i[0]['src_ip'], $i[0]['dst_ip']]);
    assertSame(['Linux 主機', '（尚未分類的日誌）', '', 'alice'], [$i[1]['log_type_label'], $i[1]['action_label'], $i[1]['severity_label'], $i[1]['user_name']]);
    assertTrue(strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'hunter2') === false, '原始訊息不能出現在結果裡');
    assertSame([2, false], [$r['data']['total'], $r['data']['truncated']]);
});

test('日誌查詢：實際筆數比列出的多就標「只列最新 N 筆」', function () {
    $q = lqMake($src, false, fn () => [lqRow()], fn () => [['log_type' => 'firewall', 'count' => 5000]]);
    $r = $q->search([]);
    assertSame([5000, true, 1], [$r['data']['total'], $r['data']['truncated'], count($r['data']['items'])]);
});

test('日誌查詢：Graylog 取不到是 ok=false 與原因，不是空表（INT-017）；其他例外是 bug，往上丟', function () {
    $q = new LogQuery(new UnavailableLogSource('尚未設定 Graylog 唯讀帳號'), false, LQ_NOW);
    assertSame([false, '尚未設定 Graylog 唯讀帳號'], [$q->search([])['ok'], $q->search([])['error']]);
    $failing = new LogQuery(new FakeWindowedSource(fn () => throw new GraylogUnavailable('Graylog 回應逾時')), false, LQ_NOW);
    assertSame([false, 'Graylog 回應逾時'], [$failing->search([])['ok'], $failing->search([])['error']]);
    $bug = new LogQuery(new FakeWindowedSource(fn () => throw new RuntimeException('bug')), false, LQ_NOW);
    assertThrows(RuntimeException::class, fn () => $bug->search([]));
});

// ── 匯出 ─────────────────────────────────────────────────────────────

test('日誌匯出：上限 5000 筆；開頭寫明資料來源、時間範圍、條件與筆數；帳號是公式也只是文字', function () {
    $q = lqMake($src, false, fn () => [lqRow(['user_name' => '=HYPERLINK("http://evil","x")']), lqRow()], fn () => [['log_type' => 'firewall', 'count' => 2]]);
    $r = $q->export(['src_ip' => '10.20.5.23']);
    assertSame(true, $r['ok']);
    assertSame(5000, $src->calls[0]['size']);
    assertSame(2, $r['rows']);
    $csv = $r['csv'];
    assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
    foreach (['"資料來源","Graylog', '"篩選條件","來源位址＝10.20.5.23"', '"筆數","2"', '"時間（台北）","來源類型","發生狀況"', '"\'=HYPERLINK(""http://evil"",""x"")"'] as $needle) {
        assertTrue(strpos($csv, $needle) !== false, $needle);
    }
    assertTrue(strpos($csv, ',"=HYPERLINK') === false, '不能有以 = 開頭的儲存格');
    assertTrue(strpos($r['summary'], '來源位址＝10.20.5.23') !== false);
});

test('日誌匯出：超過上限寫明只匯出最新的幾筆；Graylog 取不到不產生 CSV', function () {
    $q = lqMake($src, false, fn () => array_fill(0, 3, lqRow()), fn () => [['log_type' => 'firewall', 'count' => 9000]]);
    $csv = $q->export([])['csv'];
    assertTrue(strpos($csv, '符合條件共 9000 筆，只匯出最新的 3 筆') !== false, $csv);
    $r = (new LogQuery(new UnavailableLogSource('x'), false, LQ_NOW))->export([]);
    assertSame([false, true], [$r['ok'], !isset($r['csv'])]);
});
