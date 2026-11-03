<?php
declare(strict_types=1);

// 報表中心（ReportService）。dev() 在 landscape_test.php（檔名排序在前，會先載入）。

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\Fleet;
use Ioc\GraylogUnavailable;
use Ioc\ReportService;
use Ioc\ValidationError;

const RP_NOW = 1760000000;

function rpOverview(array $over = []): array
{
    return $over + [
        'meta' => ['range_label' => '最近 24 小時'],
        'landscape' => ['kpi' => [['key' => 'sites', 'label' => '站點數', 'value' => 2, 'sub' => '兩個站點'], ['key' => 'core_services', 'label' => '核心業務服務', 'value' => null, 'na' => '尚未定義', 'sub' => '到設定頁選擇']],
                        'kpi2' => [['key' => 'sla', 'label' => 'SLA 達成率', 'value' => '99.50%', 'sub' => '目標 99.5%'], ['key' => 'network_availability', 'label' => '網路可用性', 'value' => null, 'sub' => '讀不到']]],
        'coverage' => ['ok' => true, 'data' => [['label' => '防火牆', 'status' => 'ok', 'last' => '10:00', 'count' => 1200], ['label' => 'Windows', 'status' => 'none', 'last' => '—', 'count' => 0]]],
        'devices' => ['ok' => true, 'data' => ['total' => 4, 'up' => 2, 'down_list' => [['name' => 'GIS-SRV-02', 'last_polled' => '2026-10-20 10:00:00', 'reason' => 'icmp'], ['name' => 'SW-02', 'last_polled' => '—', 'reason' => '']]]],
        'attention' => ['accounts' => ['ok' => true, 'data' => ['items' => [['user' => 'alice', 'src_ip' => '10.0.0.5', 'count' => 12, 'level' => 'high']]]],
                        'denied_sources' => ['ok' => true, 'data' => ['internal' => [['src_ip' => '10.0.0.9', 'count' => 40]], 'external_events' => 900, 'capped' => false]]],
    ];
}

function rpCase(int $id, string $created, string $sev = 'high', string $status = 'new', ?string $cat = 'suspicious_login', string $host = 'GIS-SRV-01'): array
{
    return ['id' => $id, 'created_at' => $created, 'title' => "事件 $id", 'severity' => $sev, 'status' => $status, 'category' => $cat, 'subject_host' => $host,
            'target_host' => null, 'related_host' => null, 'subject_ip' => '10.0.0.' . $id, 'assignee' => 'alice'];
}

function rpMake(array $over = []): ReportService
{
    $day = fn (int $n) => gmdate('Y-m-d H:i:s', RP_NOW - $n * 86400 + 3600);
    $fleet = new Fleet([
        dev('GIS-SRV-01', 'server', 1, '北區所', ['id' => 1, 'avail_1d' => 100.0, 'avail_7d' => 99.5, 'avail_30d' => 99.9]),
        dev('GIS-SRV-02', 'server', 0, '北區所', ['id' => 2, 'avail_1d' => 98.0, 'avail_7d' => 97.0, 'avail_30d' => 95.0]),
        dev('SW-01', 'network', 1, '南區所', ['id' => 3]),
    ]);
    $audit = ['summary' => ['ready' => true, 'message' => null, 'catalog' => 'v1', 'cycle' => ['title' => '2026 年度'],
                            'counts' => ['total' => 3, 'assessed' => 2, 'compliant' => 1, 'gap' => 1, 'not_applicable' => 0, 'unreviewed' => 1, 'overdue' => 1]],
              'detail' => ['controls' => [['id' => 10, 'code' => 'AC-01', 'domain' => '存取控制', 'title' => '帳號管理', 'status' => 'compliant', 'assessed_by' => 'alice', 'assessed_at' => '2026-10-20 02:00:00'],
                                          ['id' => 11, 'code' => 'AC-02', 'domain' => '存取控制', 'title' => '=cmd|calc', 'status' => 'gap', 'assessed_by' => 'bob', 'assessed_at' => null]],
                           'evidence' => [['id' => 5, 'control_id' => 10, 'kind' => 'case_snapshot', 'captured_by' => 'alice', 'captured_at' => '2026-10-20 02:00:00', 'sha256' => str_repeat('ab', 32), 'bytes' => 2048]],
                           'remediation' => [['id' => 1, 'control_id' => 11, 'owner' => 'bob', 'due_on' => '2025-10-01', 'status' => 'open', 'note' => str_repeat('字', 200), 'created_by' => 'alice', 'updated_at' => '2026-10-02 00:00:00'],
                                             ['id' => 2, 'control_id' => 10, 'owner' => 'alice', 'due_on' => '2025-10-01', 'status' => 'done', 'note' => null, 'created_by' => 'alice', 'updated_at' => '2026-10-03 00:00:00'],
                                             ['id' => 3, 'control_id' => 10, 'owner' => 'alice', 'due_on' => '2030-01-01', 'status' => 'open', 'note' => null, 'created_by' => 'alice', 'updated_at' => '2026-10-03 00:00:00']]]];
    $network = ['kpi' => [['label' => '東西向流量佔比', 'value' => '12%', 'sub' => '近 24 小時'], ['label' => 'IDS 告警', 'value' => null, 'na' => '尚未接入', 'sub' => '']],
                'anomalies' => ['ok' => true, 'data' => ['items' => [['time' => '10-20 10:00', 'src_ip' => '10.0.0.7', 'dst_ips' => ['10.0.1.1', '10.0.1.2'], 'distinct' => 12, 'port' => '445', 'behavior' => '橫向探測', 'level' => 'high']], 'total' => 1]],
                'talkers' => ['ok' => true, 'data' => ['items' => [['ip' => '10.0.0.8', 'bytes' => 5000, 'bytes_label' => '4.9 KB', 'sessions' => 30]]]]];
    return new ReportService($over + [
        'overview' => fn () => rpOverview(),
        'fleet'    => fn () => $fleet,
        'cases'    => fn (int $since) => ['rows' => [rpCase(1, $day(1)), rpCase(2, $day(2), 'low', 'closed', 'other', 'PRN-01'), rpCase(3, $day(5), 'high', 'ack', 'fw_internal_block', 'SW-01'), rpCase(4, $day(20), 'medium', 'new', null)], 'capped' => false],
        'network'  => fn () => $network,
        'audit'    => fn () => $audit,
    ], RP_NOW);
}

function rpSec(array $report, string $title): array
{
    foreach ($report['sections'] as $s) {
        if ($s['title'] === $title) {
            return $s;
        }
    }
    fail('找不到區塊：' . $title . '（有：' . implode('、', array_column($report['sections'], 'title')) . '）');
    return [];
}

// ── 目錄、權限、篩選 ─────────────────────────────────────────────────

test('報表：檢視者看得到四份、管理者六份；稽核證據與改善追蹤只有管理者能產生，沒有身分一份都不行', function () {
    assertSame(['ops', 'cases', 'security', 'availability'], array_keys(ReportService::catalog(Access::VIEWER)));
    assertSame(['ops', 'cases', 'security', 'availability', 'audit', 'remediation'], array_keys(ReportService::catalog(Access::HANDLER)));
    $svc = rpMake();
    foreach (['audit', 'remediation'] as $k) {
        assertThrows(AccessDenied::class, fn () => $svc->build($k, [], Access::VIEWER), '只有管理者');
        assertThrows(AccessDenied::class, fn () => $svc->build($k, [], null));
    }
    assertThrows(AccessDenied::class, fn () => $svc->build('ops', [], null));
    assertThrows(ValidationError::class, fn () => $svc->build('nope', [], Access::HANDLER));
    foreach (['ops', 'cases', 'security', 'availability'] as $k) {
        assertSame($k, $svc->build($k, [], Access::VIEWER)['key']);
    }
});

test('報表：篩選條件只收認得的值——期間只有 1／7／30 日、嚴重度與事件類型要是選項、設備名稱有長度與字元限制、型別不對一律拒絕', function () {
    assertSame(['days' => 7, 'severity' => '', 'event' => '', 'device' => ''], ReportService::parseFilters([]));
    assertSame(['days' => 30, 'severity' => 'high', 'event' => 'lateral_probe', 'device' => 'GIS'], ReportService::parseFilters(['days' => 30, 'severity' => 'high', 'event' => 'lateral_probe', 'device' => ' GIS ']));
    foreach ([['days' => 14], ['days' => '7'], ['days' => [7]], ['severity' => 'critical'], ['severity' => ['high']], ['event' => 'made_up'], ['device' => ['x']], ['device' => str_repeat('字', 101)], ['device' => "a\x00b"], ['device' => "\xff"]] as $bad) {
        assertThrows(ValidationError::class, fn () => ReportService::parseFilters($bad));
    }
    $e = assertThrows(ValidationError::class, fn () => ReportService::parseFilters(['days' => 1.5, 'severity' => 'x', 'device' => ['y']]));
    assertSame(3, count($e->errors()), '錯誤一次回報');
});

// ── 各份報表 ─────────────────────────────────────────────────────────

test('維運摘要：關鍵指標沒有值時寫原因（尚未定義／無法取得），不是 0；涵蓋與離線設備；設備名稱篩選只作用在離線名單', function () {
    $r = rpMake()->build('ops', [], Access::VIEWER);
    $k = array_column(rpSec($r, '關鍵指標')['rows'], 1, 0);
    assertSame([2, '尚未定義', '99.50%', '無法取得'], [$k['站點數'], $k['核心業務服務'], $k['SLA 達成率'], $k['網路可用性']]);
    assertSame([['防火牆', '正常接收', '10:00', 1200], ['Windows', '期間無資料', '—', '—']], rpSec($r, '資料來源涵蓋')['rows']);
    assertSame(2, count(rpSec($r, '需要關注的設備（離線）')['rows']));
    $f = rpMake()->build('ops', ['device' => 'sw-'], Access::VIEWER);
    assertSame([['SW-02', '—', '']], rpSec($f, '需要關注的設備（離線）')['rows'], '不分大小寫的子字串');
    assertSame(['設備名稱包含「sw-」'], $f['filters']);
    assertTrue(strpos($r['range'], '最近 24 小時') !== false);
});

test('維運摘要：總覽讀不到整份標無法取得；涵蓋讀不到只有該區塊失敗，來源清單寫明哪個取不到', function () {
    $r = rpMake(['overview' => fn () => null])->build('ops', [], Access::HANDLER);
    assertSame([false], array_column($r['sections'], 'ok'));
    assertSame(false, $r['sources'][0]['ok']);
    $r = rpMake(['overview' => fn () => rpOverview(['coverage' => ['ok' => false, 'error' => 'Graylog 回應逾時']])])->build('ops', [], Access::HANDLER);
    $cov = rpSec($r, '資料來源涵蓋');
    assertSame([false, 'Graylog 回應逾時'], [$cov['ok'], $cov['error']]);
    assertSame(true, rpSec($r, '關鍵指標')['ok'], '其他區塊照常');
    $names = array_column($r['sources'], 'ok', 'name');
    assertSame([true, false], [$names['LibreNMS 設備與可用率、IOC 事件案'], $names['Graylog 日誌涵蓋']]);
});

test('事件彙整：期間、嚴重度、事件類型、設備篩選；沒有類型的案件算「其他」；件數統計與明細一致', function () {
    $svc = rpMake();
    $r = $svc->build('cases', ['days' => 7], Access::VIEWER);
    $stat = array_column(rpSec($r, '件數統計')['rows'], 1, 0);
    assertSame([3, 2, 1, 1, 1, 1], [$stat['事件案總數'], $stat['高'], $stat['低'], $stat['待確認'], $stat['已確認'], $stat['已結案']], '7 日內有 3 件（第 4 件在 20 日前）');
    assertSame([1, 2, 3], array_column(rpSec($r, '事件案明細')['rows'], 0));
    assertSame('其他（未分類）', array_column(rpSec($svc->build('cases', ['days' => 30], Access::VIEWER), '事件案明細')['rows'], 5)[3], '沒有類型的案件');
    assertSame([1, 3], array_column(rpSec($svc->build('cases', ['severity' => 'high'], Access::VIEWER), '事件案明細')['rows'], 0));
    assertSame([3], array_column(rpSec($svc->build('cases', ['event' => 'fw_internal_block'], Access::VIEWER), '事件案明細')['rows'], 0));
    assertSame([2], array_column(rpSec($svc->build('cases', ['device' => 'prn'], Access::VIEWER), '事件案明細')['rows'], 0));
    assertSame(['期間＝最近 7 日', '嚴重度＝高'], $svc->build('cases', ['severity' => 'high'], Access::VIEWER)['filters']);
    assertSame('最近 7 日', $r['range']);
});

test('事件彙整：事件案太多讀取被截斷要寫明統計可能偏低；讀不到是無法取得，不是 0 件', function () {
    $r = rpMake(['cases' => fn () => ['rows' => [rpCase(1, gmdate('Y-m-d H:i:s', RP_NOW - 3600))], 'capped' => true]])->build('cases', [], Access::HANDLER);
    assertTrue(strpos(rpSec($r, '件數統計')['note'], '只讀取到最新的 500 件') !== false);
    $r = rpMake(['cases' => fn () => null])->build('cases', [], Access::HANDLER);
    assertSame([false, false], [rpSec($r, '事件彙整')['ok'], $r['sources'][0]['ok']]);
    $empty = rpMake(['cases' => fn () => ['rows' => [], 'capped' => false]])->build('cases', [], Access::HANDLER);
    assertSame(0, array_column(rpSec($empty, '件數統計')['rows'], 1, 0)['事件案總數'], '讀得到而沒有案件：這是真的 0');
});

test('資安摘要：內網可視性、異常連線（目的最多列出的幾個加「等 N 個」）、Top Talkers、可疑登入與阻擋來源；內網可視性讀不到整段標無法取得', function () {
    $r = rpMake()->build('security', [], Access::VIEWER);
    assertSame([['東西向流量佔比', '12%', '近 24 小時'], ['IDS 告警', '尚未接入', '']], rpSec($r, '內網可視性指標')['rows']);
    assertSame([['10-20 10:00', '10.0.0.7', '10.0.1.1、10.0.1.2 等 12 個', '445', '橫向探測', '高']], rpSec($r, '異常連線')['rows']);
    assertSame([['10.0.0.8', '4.9 KB', 30]], rpSec($r, 'Top Talkers（內部來源流量）')['rows']);
    assertSame([['alice', '10.0.0.5', 12, '高']], rpSec($r, '可疑登入的帳號與來源')['rows']);
    $den = rpSec($r, '被防火牆阻擋的內部來源');
    assertSame([['10.0.0.9', 40]], $den['rows']);
    assertTrue(strpos($den['note'], '900') !== false);
    $f = rpMake(['network' => fn () => null])->build('security', [], Access::VIEWER);
    assertSame(false, rpSec($f, '內網可視性')['ok']);
    assertSame(true, rpSec($f, '可疑登入的帳號與來源')['ok'], '登入類區塊不受影響');
    $g = rpMake(['network' => fn () => throw new GraylogUnavailable('逾時')])->build('security', [], Access::VIEWER);
    assertSame(false, rpSec($g, '內網可視性')['ok'], 'Graylog 取不到不能讓整份報表失敗');
});

test('可用率與 SLA：30 日由低到高、沒資料顯示「—」不是 0%、設備名稱篩選、依類型平均只算有資料的；設備讀不到標無法取得', function () {
    $r = rpMake()->build('availability', [], Access::VIEWER);
    $rows = rpSec($r, '設備可用率（30 日由低到高）')['rows'];
    assertSame(['GIS-SRV-02', 'GIS-SRV-01', 'SW-01'], array_column($rows, 0), '沒有 30 日資料的排最後');
    assertSame(['離線', '95.00%', '98.00%'], [$rows[0][3], $rows[0][6], $rows[0][4]]);
    assertSame(['—（沒有資料）', '—（沒有資料）', '—（沒有資料）'], [$rows[2][4], $rows[2][5], $rows[2][6]]);
    $avg = array_column(rpSec($r, '依類型的平均可用率')['rows'], null, 0);
    assertSame([2, '98.25%', '97.45%'], [$avg['伺服器'][1], $avg['伺服器'][2], $avg['伺服器'][3]]);
    assertSame(['網路設備', 1, '—（沒有資料）', '—（沒有資料）'], $avg['網路設備']);
    assertSame(['核心業務服務', 'SLA 達成率', '網路可用性'], array_column(rpSec($r, 'SLA 與核心服務')['rows'], 0));
    assertSame(['GIS-SRV-01'], array_column(rpSec(rpMake()->build('availability', ['device' => 'srv-01'], Access::VIEWER), '設備可用率（30 日由低到高）')['rows'], 0));
    $f = rpMake(['fleet' => fn () => null])->build('availability', [], Access::VIEWER);
    assertSame(false, rpSec($f, '設備可用率')['ok']);
});

test('稽核證據清單與改善追蹤：控制項、證據（雜湊只列前 12 碼）、逾期判定（狀態不是 done 且期限早於今日）；尚未建立基準時說明原因', function () {
    $r = rpMake()->build('audit', [], Access::HANDLER);
    assertSame([['v1', '2026 年度', 3, 2, 1, 1, 0, 1]], rpSec($r, '評估週期')['rows']);
    $ctl = rpSec($r, '控制項評估')['rows'];
    assertSame(['AC-01', '存取控制', '帳號管理', '符合', 'alice'], array_slice($ctl[0], 0, 5));
    assertSame(['缺口', ''], [$ctl[1][3], $ctl[1][5]], '沒有評估時間就是空白，不編造');
    assertSame([[5, 'AC-01', 'case_snapshot', 'alice', '2026-10-20 10:00:00', 'abababababab', 2048]], rpSec($r, '證據清單')['rows']);
    $rem = rpSec(rpMake()->build('remediation', [], Access::HANDLER), '改善事項');
    assertSame(['逾期', '', ''], [$rem['rows'][0][5], $rem['rows'][1][5], $rem['rows'][2][5]], '已結案與期限未到的不算逾期');
    assertSame(120, mb_strlen($rem['rows'][0][6]), '備註截到 120 字');
    assertTrue(strpos($rem['note'], '逾期 1 件') !== false, $rem['note']);
    $none = rpMake(['audit' => fn () => ['summary' => ['ready' => false, 'message' => '未建立評估基準'], 'detail' => []]])->build('audit', [], Access::HANDLER);
    assertTrue(strpos(json_encode($none['sections'], JSON_UNESCAPED_UNICODE), '未建立評估基準') !== false);
    $bad = rpMake(['audit' => fn () => null])->build('remediation', [], Access::HANDLER);
    assertSame(false, $bad['sections'][0]['ok']);
});

test('報表：提供者丟出非 Graylog 的例外是 bug，往上丟，不能被吞成「無法取得」', function () {
    assertThrows(RuntimeException::class, fn () => rpMake(['cases' => fn () => throw new RuntimeException('bug')])->build('cases', [], Access::HANDLER));
});

// ── CSV ──────────────────────────────────────────────────────────────

test('報表 CSV：開頭寫明報表、產生時間、時間範圍、篩選條件與資料來源（取不到的也寫）；區塊讀不到只寫原因；公式只是文字；列數是資料列數', function () {
    $r = rpMake(['network' => fn () => null])->build('security', [], Access::VIEWER);
    $csv = ReportService::csv($r);
    assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
    foreach (['"報表","資安與內網可視性摘要"', '"產生時間（台北時間）","', '"時間範圍","', '"篩選條件","（沒有篩選條件）"', '（無法取得：內網可視性資料無法讀取）', '"【內網可視性】"', '"無法取得","內網可視性資料無法讀取"', '"【可疑登入的帳號與來源】"'] as $needle) {
        assertTrue(strpos($csv, $needle) !== false, $needle);
    }
    assertSame(2, ReportService::rowCount($r), '可疑登入 1 列、阻擋來源 1 列；失敗的區塊不算');
    $audit = ReportService::csv(rpMake()->build('audit', [], Access::HANDLER));
    assertTrue(strpos($audit, ",\"'=cmd|calc\",") !== false, '=開頭的標題只是文字');
    assertTrue(strpos($audit, ',"=cmd') === false);
    assertTrue(strpos(ReportService::csv(rpMake()->build('cases', ['severity' => 'high', 'device' => 'GIS'], Access::VIEWER)), '設備名稱包含「GIS」') !== false);
});
