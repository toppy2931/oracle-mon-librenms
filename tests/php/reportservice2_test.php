<?php
declare(strict_types=1);

// 報表中心（ReportService）：獨立審查後補的規則。rpMake()／rpOverview()／rpSec() 在 reportservice_test.php（檔名排序在前，會先載入）。

use Ioc\Access;
use Ioc\ReportService;

test('事件案分頁讀取：停止條件看更新時間——很久以前建立、昨天剛結案的案件排在前面，不能讓後面期間內建立的案件被漏掉', function () {
    $since = RP_NOW - 7 * 86400;
    $iso = fn (int $t) => gmdate('Y-m-d H:i:s', $t);
    // 列表依「更新時間」新到舊：第 1 頁最後一筆是 60 天前建立、1 天前更新的舊案；第 2 頁才有 6 天前建立的案件
    $first = array_map(fn ($i) => ['id' => $i, 'created_at' => $iso(RP_NOW - 86400), 'updated_at' => $iso(RP_NOW - 3600 * $i)], range(1, 99));
    $first[] = ['id' => 100, 'created_at' => $iso(RP_NOW - 60 * 86400), 'updated_at' => $iso(RP_NOW - 86400)];
    $pages = [
        0   => ['items' => $first, 'has_more' => true],
        100 => ['items' => [['id' => 101, 'created_at' => $iso(RP_NOW - 6 * 86400), 'updated_at' => $iso(RP_NOW - 5 * 86400)]], 'has_more' => false],
    ];
    $r = ReportService::collectCases(fn (int $o) => $pages[$o], $since);
    assertTrue(in_array(101, array_column($r['rows'], 'id'), true), '第 2 頁 6 天前建立的案件要讀到（用建立時間判斷會在第 1 頁就停下來漏掉它）');
    assertSame([101, false], [count($r['rows']), $r['capped']]);
    // 最後一筆的更新時間早於期間起點：後面的案件建立時間一定更早，可以停
    $calls = [];
    $r2 = ReportService::collectCases(function (int $o) use (&$calls, $iso, $since) {
        $calls[] = $o;
        return ['items' => [['id' => 1, 'created_at' => $iso($since - 86400), 'updated_at' => $iso($since - 3600)]], 'has_more' => true];
    }, $since);
    assertSame([[0], false], [$calls, $r2['capped']], '更新時間都早於起點：只讀一頁就停');
});

test('事件案分頁讀取：筆數碰到上限而還有更新的案件沒讀完才標 capped；讀完沒有更多不標；空頁停止', function () {
    $iso = fn (int $t) => gmdate('Y-m-d H:i:s', $t);
    $row = fn (int $i) => ['id' => $i, 'created_at' => $iso(RP_NOW - 600), 'updated_at' => $iso(RP_NOW - 600)];
    $endless = fn (int $o) => ['items' => array_map($row, range($o + 1, $o + 100)), 'has_more' => true];
    $r = ReportService::collectCases($endless, RP_NOW - 86400);
    assertSame([ReportService::CASE_LIMIT, true], [count($r['rows']), $r['capped']]);
    $done = ReportService::collectCases(fn (int $o) => ['items' => array_map($row, range(1, 30)), 'has_more' => false], RP_NOW - 86400);
    assertSame([30, false], [count($done['rows']), $done['capped']]);
    $empty = ReportService::collectCases(fn (int $o) => ['items' => [], 'has_more' => false], RP_NOW - 86400);
    assertSame([0, false], [count($empty['rows']), $empty['capped']]);
});

test('報表：資料來源的取得與否由實際使用它的區塊決定——區塊失敗，來源就要標失敗並帶原因（RPT-005／007）', function () {
    $ov = rpOverview();
    $ov['attention']['accounts'] = ['ok' => false, 'error' => 'Graylog 回應逾時'];
    $r = rpMake(['overview' => fn () => $ov])->build('security', [], Access::VIEWER);
    $src = array_column($r['sources'], null, 'name');
    $g = $src['Graylog 登入與防火牆日誌（可疑登入、阻擋來源）'];
    assertSame(false, $g['ok'], '可疑登入區塊讀不到，來源不能標成取得');
    assertTrue(strpos((string) $g['error'], '可疑登入：Graylog 回應逾時') !== false, (string) $g['error']);
    $ok = rpMake()->build('security', [], Access::VIEWER);
    assertSame(true, array_column($ok['sources'], 'ok', 'name')['Graylog 登入與防火牆日誌（可疑登入、阻擋來源）']);
    $ov2 = rpOverview(['devices' => ['ok' => false, 'error' => '設備資料讀不到']]);
    $ops = rpMake(['overview' => fn () => $ov2])->build('ops', [], Access::VIEWER);
    $lib = array_column($ops['sources'], null, 'name')['LibreNMS 設備與可用率、IOC 事件案'];
    assertSame(false, $lib['ok']);
    assertTrue(strpos(ReportService::csv($ops), '（無法取得：設備清單：設備資料讀不到）') !== false, 'CSV 的資料來源列也要寫');
});

test('稽核證據清單與改善追蹤：碰到 SQL 的上限（證據、改善事項各 500、控制項 2000）要寫明只列最新幾筆，逾期件數只含列出的', function () {
    $ctl = array_map(fn ($i) => ['id' => $i, 'code' => 'C-' . $i, 'domain' => 'd', 'title' => 't', 'status' => 'gap', 'assessed_by' => 'a', 'assessed_at' => null], range(1, ReportService::AUDIT_CONTROL_LIMIT));
    $ev = array_map(fn ($i) => ['id' => $i, 'control_id' => 1, 'kind' => 'k', 'captured_by' => 'a', 'captured_at' => '2026-10-20 00:00:00', 'sha256' => str_repeat('a', 64), 'bytes' => 1], range(1, ReportService::AUDIT_EVIDENCE_LIMIT));
    $rem = array_map(fn ($i) => ['id' => $i, 'control_id' => 1, 'owner' => 'a', 'due_on' => '2025-01-01', 'status' => 'open', 'note' => null, 'created_by' => 'a', 'updated_at' => '2026-10-20 00:00:00'], range(1, ReportService::AUDIT_EVIDENCE_LIMIT));
    $audit = ['summary' => ['ready' => true, 'catalog' => 'v', 'cycle' => ['title' => 'y'], 'counts' => ['total' => 1, 'assessed' => 1, 'compliant' => 0, 'gap' => 1, 'not_applicable' => 0, 'unreviewed' => 0]],
              'detail' => ['controls' => $ctl, 'evidence' => $ev, 'remediation' => $rem]];
    $svc = rpMake(['audit' => fn () => $audit]);
    $a = $svc->build('audit', [], Access::HANDLER);
    assertTrue(strpos(rpSec($a, '控制項評估')['note'], '只列了前 2000 個') !== false);
    assertTrue(strpos(rpSec($a, '證據清單')['note'], '只列了最新的 500 筆') !== false);
    assertTrue(strpos(rpSec($svc->build('remediation', [], Access::HANDLER), '改善事項')['note'], '逾期件數只含列出的這些') !== false);
    $small = rpSec(rpMake()->build('audit', [], Access::HANDLER), '證據清單')['note'];
    assertTrue(strpos($small, '只列了') === false, '沒碰到上限不寫');
});

test('可用率報表：站點設定讀不到時，報表要寫明站點欄暫時依 LibreNMS 的位置（不能默默換一套歸屬）', function () {
    $r = rpMake(['sites_warning' => fn () => '站點設定讀不到，暫時只依 LibreNMS 的位置分站點：資料表不合法'])->build('availability', [], Access::VIEWER);
    assertTrue(strpos(rpSec($r, '設備可用率（30 日由低到高）')['note'], '站點設定讀不到') !== false);
    $none = rpSec(rpMake()->build('availability', [], Access::VIEWER), '設備可用率（30 日由低到高）')['note'];
    assertTrue(strpos($none, '注意：') === false);
});
