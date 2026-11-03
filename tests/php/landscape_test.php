<?php
declare(strict_types=1);

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\CaseRepo;
use Ioc\CaseService;
use Ioc\Fleet;
use Ioc\LandscapeService;
use Ioc\RiskScore;

/** 一台假設備。location 為 null 表示沒有指定位置。 */
function dev(string $name, string $type = 'server', int $status = 1, ?string $loc = null, array $extra = []): array
{
    return $extra + ['name' => $name, 'aliases' => [$name . '.example'], 'type' => $type, 'status' => $status, 'reason' => $status ? '' : 'icmp',
                     'last_polled' => '2026-10-20 10:00:00', 'location' => $loc, 'lat' => null, 'lng' => null, 'avail_1d' => null, 'avail_7d' => null];
}

const LS_NOW = 1760000000;

function taipeiDay(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d');
}

// ── Fleet ───────────────────────────────────────────────────────────

test('Fleet：設備類型分成伺服器／網路設備／其他（不分大小寫）', function () {
    foreach (['server', 'SERVER', ' Server '] as $t) {
        assertSame('server', Fleet::group($t), $t);
    }
    foreach (['network', 'firewall', 'wireless', 'loadbalancer', 'Network'] as $t) {
        assertSame('network', Fleet::group($t), $t);
    }
    foreach (['workstation', 'printer', 'power', '', 'unknown'] as $t) {
        assertSame('other', Fleet::group($t), $t);
    }
});

test('Fleet：資產數、正常數、依類型的數量', function () {
    $f = new Fleet([dev('a', 'server'), dev('b', 'server', 0), dev('c', 'firewall'), dev('d', 'printer')]);
    assertSame(4, $f->total());
    assertSame(3, $f->up());
    assertSame(['server' => 2, 'network' => 1, 'other' => 1], $f->byGroup());
});

test('Fleet：某一類設備的平均可用率只算那一類、只算有資料的設備；沒有資料是 null，不是 0%', function () {
    $f = new Fleet([dev('sw1', 'network', 1, null, ['avail_7d' => 99.0]), dev('fw', 'firewall', 1, null, ['avail_7d' => 97.0]),
                    dev('sw2', 'network', 1), dev('srv', 'server', 1, null, ['avail_7d' => 50.0])]);
    assertSame(['value' => 98.0, 'n' => 2, 'devices' => 3], $f->groupAvailability('network'), '網路設備 3 台（sw2 沒資料不計入平均）');
    assertSame(['value' => 50.0, 'n' => 1, 'devices' => 1], $f->groupAvailability('server'));
    assertSame(['value' => null, 'n' => 0, 'devices' => 0], (new Fleet([dev('srv', 'server')]))->groupAvailability('network'), '沒有網路設備：無資料');
    assertSame(['value' => null, 'n' => 0, 'devices' => 1], (new Fleet([dev('sw', 'network')]))->groupAvailability('network'), '有設備但沒有可用率：null，不是 0');
    $d30 = new Fleet([dev('a', 'network', 1, null, ['avail_30d' => 99.5]), dev('b', 'network', 1, null, ['avail_30d' => 98.5])]);
    assertSame(99.0, $d30->groupAvailability('network', 'avail_30d')['value'], '可以指定 30 日');
    assertThrows(InvalidArgumentException::class, fn () => $f->groupAvailability('network', 'avail_x'));       // 欄位走白名單
});

test('Fleet：核心設備清單的現況（以 LibreNMS device_id 對應；不在 LibreNMS 的另外數，30 日可用率只算有資料的）', function () {
    $f = new Fleet([dev('a', 'server', 1, null, ['id' => 1, 'avail_30d' => 99.9]), dev('b', 'server', 0, null, ['id' => 2, 'avail_30d' => 90.0]),
                    dev('c', 'network', 1, null, ['id' => 3]), dev('d', 'server', 1, null, ['id' => 4, 'avail_30d' => 100.0])]);
    assertSame(['listed' => 4, 'found' => 3, 'missing' => 1, 'up' => 2, 'down' => 1, 'down_names' => ['b'], 'avail30' => 94.95, 'avail30_exact' => 94.95, 'n30' => 2],
        $f->core([1, 2, 3, 99]), '99 不在 LibreNMS（被刪除或停用）：missing');
    assertSame(['listed' => 1, 'found' => 1, 'missing' => 0, 'up' => 1, 'down' => 0, 'down_names' => [], 'avail30' => null, 'avail30_exact' => null, 'n30' => 0],
        $f->core([3]), '核心設備沒有 30 日可用率：null，不是 0%');
    assertSame(['listed' => 0, 'found' => 0, 'missing' => 0, 'up' => 0, 'down' => 0, 'down_names' => [], 'avail30' => null, 'avail30_exact' => null, 'n30' => 0], $f->core([]));
    assertSame(2, $f->core([1, 1, 1, 2])['listed'], '重複的 id 只算一次');
    $many = new Fleet(array_map(static fn (int $i) => dev('h' . $i, 'server', 0, null, ['id' => $i]), range(1, 9)));
    assertSame(['h1', 'h2', 'h3', 'h4', 'h5'], $many->core(range(1, 9))['down_names'], '離線名單最多列 5 台');
});

test('Fleet：站點異常等級的門檻（<25% 輕微、<50% 中度、≥50% 嚴重）', function () {
    assertSame('normal', Fleet::level(0, 5));
    assertSame('normal', Fleet::level(0, 0), '沒有設備不能算異常');
    assertSame('minor', Fleet::level(1, 5), '20%');
    assertSame('moderate', Fleet::level(1, 4), '剛好 25% 是中度');
    assertSame('moderate', Fleet::level(2, 5), '40%');
    assertSame('severe', Fleet::level(1, 2), '剛好 50% 是嚴重');
    assertSame('severe', Fleet::level(3, 3));
});

test('Fleet：站點只算有指定位置的設備；沒指定的另外計數，不假裝成某個站點', function () {
    $f = new Fleet([dev('a', 'server', 1, '北區所'), dev('b', 'server', 0, '北區所'), dev('c', 'network', 1, '南區所'), dev('d', 'server', 1, null), dev('e', 'server', 0, '  ')]);
    assertSame(2, $f->unassigned());
    $sites = $f->sites();
    assertSame(['北區所', '南區所'], array_column($sites, 'name'), '站點依名稱排序（位元組順序，結果固定）');
    $by = array_column($sites, null, 'name');
    assertSame([2, 1, 1, '1 項異常'], [$by['北區所']['devices'], $by['北區所']['up'], $by['北區所']['down'], $by['北區所']['issues']]);
    assertSame('severe', $by['北區所']['level'], '2 台裡 1 台離線＝50%＝嚴重');
    assertSame('運作正常', $by['南區所']['issues']);
    assertSame('normal', $by['南區所']['level']);
    assertSame(['normal' => 1, 'minor' => 0, 'moderate' => 0, 'severe' => 1], $f->levelCounts());
});

test('Fleet：SNMP 預設的位置（Unknown、End User Premise. 等）不是站點，視為沒有指定位置', function () {
    foreach (['Unknown', 'UNKNOWN', ' unknown ', 'End User Premise.', 'end user premise', 'Rack, Room, Building, City, Country [Lat, Lon]',
              'Unknown (edit /etc/snmp/snmpd.local.conf)', 'unknown (edit me)', ''] as $placeholder) {
        assertSame(null, Fleet::realLocation($placeholder), "「{$placeholder}」應視為沒有指定");
    }
    foreach (['機房', 'Unknown Lab', 'Unknownville', '北區所', ' 機房 '] as $real) {
        assertSame(trim($real), Fleet::realLocation($real), "「{$real}」是真的站點名稱");
    }
});

test('Fleet：monitor-vm 實際的位置資料形狀（14 台：6 台沒位置、其餘是 SNMP 預設值與一個真的「機房」）→ 只有 1 個站點', function () {
    $rows = [];
    foreach ([[6, null], [4, 'Unknown'], [2, 'End User Premise.'], [1, 'Rack, Room, Building, City, Country [Lat, Lon]'], [1, '機房']] as [$n, $loc]) {
        for ($i = 0; $i < $n; $i++) {
            $rows[] = dev("vm-{$n}-{$i}", 'server', 1, $loc);
        }
    }
    $f = new Fleet($rows);
    assertSame(14, $f->total());
    assertSame(13, $f->unassigned());
    assertSame(['機房'], array_column($f->sites(), 'name'));
    $o = (new LandscapeService(LS_NOW))->build($f, ['new' => 0, 'ack' => 0, 'in_progress' => 0, 'closed' => 0, 'open' => 0], ['high' => 0, 'medium' => 0, 'low' => 0], [], [], lsCoverage(), RiskScore::DEFAULT_THRESHOLDS);
    $k = lsKpi($o);
    assertSame(1, $k['sites']['value']);
    assertTrue(mb_strpos($k['sites']['sub'], '另有 13 台設備沒有有效位置') !== false, $k['sites']['sub']);
    assertSame(false, $o['sites']['data']['has_locations'], '這個站點沒有座標，不畫散佈圖');
    assertSame(13, $o['sites']['data']['unassigned']);
});

test('Fleet：沒有任何設備有位置時，沒有站點（不是一個叫「未指定」的站點）', function () {
    $f = new Fleet([dev('a'), dev('b', 'server', 0)]);
    assertSame([], $f->sites());
    assertSame(2, $f->unassigned());
    assertSame([], $f->ranking());
});

test('Fleet：站點健康度排行：等級重的在前，其次離線台數，最後名稱；名次從 1 開始，可限制筆數', function () {
    $rows = [];
    foreach (['甲' => [10, 0], '乙' => [10, 1], '丙' => [10, 3], '丁' => [2, 2], '戊' => [10, 3]] as $name => [$n, $down]) {
        for ($i = 0; $i < $n; $i++) {
            $rows[] = dev("$name$i", 'server', $i < $down ? 0 : 1, $name);
        }
    }
    $r = (new Fleet($rows))->ranking(4);
    assertSame(['丁', '丙', '戊', '乙'], array_column($r, 'name'), '丁嚴重；丙、戊同為中度且離線 3 台，名稱排序；乙輕微');
    assertSame([1, 2, 3, 4], array_column($r, 'rank'));
    assertSame('severe', $r[0]['level']);
});

test('Fleet：可用率只算有資料的設備；完全沒有資料是 null，不是 0%', function () {
    $f = new Fleet([dev('a', 'server', 1, null, ['avail_1d' => 100.0, 'avail_7d' => 99.0]), dev('b', 'server', 1, null, ['avail_1d' => 99.5]), dev('c')]);
    assertSame(['d1' => 99.75, 'd7' => 99.0, 'n1' => 2, 'n7' => 1], $f->availability());
    assertSame(['d1' => null, 'd7' => null, 'n1' => 0, 'n7' => 0], (new Fleet([dev('x')]))->availability());
    assertSame(['d1' => null, 'd7' => null, 'n1' => 0, 'n7' => 0], (new Fleet([]))->availability());
});

test('Fleet：事件案的主機名稱對應到站點（名稱或別名、不分大小寫）；沒位置或找不到是 null', function () {
    $f = new Fleet([dev('GIS-SRV-01', 'server', 1, '北區所'), dev('lonely', 'server', 1, null)]);
    assertSame('北區所', $f->siteOfHost('gis-srv-01'));
    assertSame('北區所', $f->siteOfHost('GIS-SRV-01.example'));
    assertSame(null, $f->siteOfHost('lonely'), '設備沒有位置');
    assertSame(null, $f->siteOfHost('nobody'));
    assertSame(null, $f->siteOfHost(''));
});

test('Fleet：舊版總覽用的形狀，最後輪詢時間依來源時區轉成台北時間', function () {
    $rows = [dev('a'), dev('b', 'server', 0, null, ['last_polled' => '2026-07-10 16:10:08']), dev('c', 'server', 0, null, ['last_polled' => null])];
    $utc = (new Fleet($rows, 'UTC'))->legacy();
    assertSame([3, 1], [$utc['total'], $utc['up']]);
    assertSame('2026-07-11 00:10:08', $utc['down_list'][0]['last_polled'], 'UTC 的字串要加 8 小時');
    assertSame('—', $utc['down_list'][1]['last_polled'], '沒有輪詢時間就是「—」');
    assertSame('2026-07-10 16:10:08', (new Fleet($rows, 'Asia/Taipei'))->legacy()['down_list'][0]['last_polled'], '本來就是台北時間不能再加');
    assertSame(1, count((new Fleet($rows))->legacy(1)['down_list']), '可限制筆數');
});

test('Fleet：座標 0,0 當成沒設定；數字字串可用', function () {
    $f = new Fleet([dev('a', 'server', 1, 'X', ['lat' => '0', 'lng' => '0']), dev('b', 'server', 1, 'Y', ['lat' => '25.04', 'lng' => '121.5'])]);
    $by = array_column($f->sites(), null, 'name');
    assertSame([null, null], [$by['X']['lat'], $by['X']['lng']]);
    assertSame([25.04, 121.5], [$by['Y']['lat'], $by['Y']['lng']]);
});

// ── RiskScore ───────────────────────────────────────────────────────

test('風險分數：一切正常是 100 分、低風險', function () {
    $r = RiskScore::compute(['total' => 14, 'up' => 14], ['high' => 0, 'medium' => 0, 'low' => 0], ['present' => 4, 'ok' => 4], RiskScore::DEFAULT_THRESHOLDS);
    assertSame([100, 'low', '低風險', []], [$r['score'], $r['level'], $r['level_label'], $r['missing']]);
    assertSame(['devices' => 40, 'cases' => 35, 'sources' => 25], array_map(fn ($p) => $p['weight'], $r['parts']));
});

test('風險分數：monitor-vm 目前的情況（12/14 設備、沒有未結案件、1 類來源正常）是 94 分', function () {
    $r = RiskScore::compute(['total' => 14, 'up' => 12], ['high' => 0, 'medium' => 0, 'low' => 0], ['present' => 1, 'ok' => 1], RiskScore::DEFAULT_THRESHOLDS);
    assertSame(94, $r['score'], '(40×85.7 + 35×100 + 25×100) ÷ 100');
    assertSame(86, $r['parts']['devices']['score']);
    assertSame('low', $r['level'], '預設門檻 85：94 分是低風險');
    assertSame('medium', RiskScore::compute(['total' => 14, 'up' => 12], ['high' => 0, 'medium' => 0, 'low' => 0], ['present' => 1, 'ok' => 1], ['low' => 95, 'medium' => 80])['level'],
        '使用者把低風險門檻調到 95：同一個 94 分變成中等風險');
});

test('風險分數：未結案件依嚴重性扣分（高 15、中 6、低 2），扣到 0 為止', function () {
    $score = fn (array $open) => RiskScore::compute(null, $open, null, RiskScore::DEFAULT_THRESHOLDS)['parts']['cases']['score'];
    assertSame(100, $score(['high' => 0, 'medium' => 0, 'low' => 0]));
    assertSame(64, $score(['high' => 2, 'medium' => 1, 'low' => 0]), '100 − 30 − 6');
    assertSame(98, $score(['low' => 1]), '缺的鍵當 0');
    assertSame(0, $score(['high' => 10]), '不會變負數');
});

test('風險分數：某個分項資料取不到就不納入，其餘分項重新分配權重，並列出缺哪些', function () {
    $r = RiskScore::compute(null, ['high' => 0, 'medium' => 0, 'low' => 0], ['present' => 2, 'ok' => 1], RiskScore::DEFAULT_THRESHOLDS);
    assertSame(['設備與服務'], $r['missing']);
    assertSame(null, $r['parts']['devices']['score']);
    assertSame(79, $r['score'], '(35×100 + 25×50) ÷ 60＝79.2');
});

test('風險分數：沒有設備、沒有來源不是 100 分；全部都沒有就是「無法計算」', function () {
    $r = RiskScore::compute(['total' => 0, 'up' => 0], null, ['present' => 0, 'ok' => 0], RiskScore::DEFAULT_THRESHOLDS);
    assertSame([null, null, null], [$r['score'], $r['level'], $r['level_label']]);
    assertSame(['設備與服務', '事件處置', '資料來源'], $r['missing']);
});

test('風險分數：預設的等級門檻是 85／65', function () {
    assertSame(['low' => 85, 'medium' => 65], RiskScore::DEFAULT_THRESHOLDS);
    $lv = fn (int $ok) => RiskScore::compute(null, null, ['present' => 100, 'ok' => $ok], RiskScore::DEFAULT_THRESHOLDS)['level'];
    assertSame('low', $lv(85));
    assertSame('medium', $lv(84));
    assertSame('medium', $lv(65));
    assertSame('high', $lv(64));
});

test('風險分數：等級門檻可以自訂，剛好在門檻上算較好的那一級', function () {
    $lv = fn (int $ok, array $t) => RiskScore::compute(null, null, ['present' => 100, 'ok' => $ok], $t)['level'];
    $t = ['low' => 95, 'medium' => 80];
    assertSame('low', $lv(95, $t));
    assertSame('medium', $lv(94, $t));
    assertSame('medium', $lv(80, $t));
    assertSame('high', $lv(79, $t));
    assertSame('low', $lv(100, ['low' => 100, 'medium' => 1]), '低風險門檻 100：只有滿分才是低風險');
    assertSame('medium', $lv(99, ['low' => 100, 'medium' => 1]));
    assertSame('high', $lv(0, ['low' => 100, 'medium' => 1]), '0 分低於中等門檻 1：高風險');
    assertSame('high', RiskScore::level(10, ['low' => 50, 'medium' => 20]));
});

test('風險分數：門檻讀不到時只給分數、不判定等級（不能套預設值冒充），算出的門檻會帶回去給畫面', function () {
    $r = RiskScore::compute(null, null, ['present' => 100, 'ok' => 90], null);
    assertSame([90, null, null, null], [$r['score'], $r['level'], $r['level_label'], $r['thresholds']]);
    $t = ['low' => 92, 'medium' => 70];
    assertSame($t, RiskScore::compute(null, null, ['present' => 100, 'ok' => 90], $t)['thresholds']);
});

// ── CaseService 新方法 ──────────────────────────────────────────────

test('事件案：未結案件依嚴重性（結案的不算，沒有的是 0），需要檢視權限', function () {
    $svc = makeService($repo);
    $H = Access::HANDLER;
    $svc->create(['title' => 'a', 'severity' => 'high'], 'u', $H);
    $svc->create(['title' => 'b', 'severity' => 'high'], 'u', $H);
    $m = $svc->create(['title' => 'c', 'severity' => 'medium'], 'u', $H)['id'];
    $svc->transition($m, 'closed', 'u', $H, 'fixed');
    assertSame(['low' => 0, 'medium' => 0, 'high' => 2], array_intersect_key($svc->openBySeverity(Access::VIEWER), ['low' => 1, 'medium' => 1, 'high' => 1]));
    assertSame(2, $svc->openBySeverity($H)['high']);
    assertThrows(AccessDenied::class, fn () => $svc->openBySeverity(null));
});

test('事件案：趨勢用的建立時間只取最近 N+1 天內的，需要檢視權限', function () {
    $repo = new MemoryCaseRepo();
    $now = '2026-10-20 08:00:00';
    $svc = new CaseService($repo, fn () => $now);
    foreach ([['2026-10-20 07:00:00', 'high'], ['2026-09-19 08:00:00', 'low'], ['2026-09-19 07:59:59', 'medium'], ['2026-08-01 00:00:00', 'high']] as [$at, $sev]) {
        $repo->insertCase(['title' => 't', 'severity' => $sev, 'status' => 'new', 'created_at' => $at, 'updated_at' => $at]);
    }
    $rows = $svc->createdWithin(30, Access::VIEWER);
    assertSame(['2026-10-20 07:00:00', '2026-09-19 08:00:00'], array_column($rows, 'created_at'), '由新到舊；現在往前 31 天（2026-09-19 08:00:00）是分界：剛好在分界上的要，早 1 秒的不要');
    assertThrows(AccessDenied::class, fn () => $svc->createdWithin(30, null));
});

test('事件案：所選短期間從精確 UTC 起點讀取，且需要檢視權限', function () {
    $repo = new MemoryCaseRepo();
    $now = '2026-10-20 08:00:00';
    $svc = new CaseService($repo, fn () => $now);
    foreach (['2026-10-20 07:29:59', '2026-10-20 07:30:00', '2026-10-20 07:59:59'] as $at) {
        $repo->insertCase(['title' => 't', 'severity' => 'low', 'status' => 'new', 'created_at' => $at, 'updated_at' => $at]);
    }
    assertSame(['2026-10-20 07:59:59', '2026-10-20 07:30:00'], array_column($svc->createdInLast(1800, Access::VIEWER), 'created_at'));
    assertThrows(AccessDenied::class, fn () => $svc->createdInLast(1800, null));
    assertThrows(Ioc\ValidationError::class, fn () => $svc->createdInLast(7200, Access::VIEWER));
});

// ── LandscapeService ────────────────────────────────────────────────

function lsFleet(): Fleet
{
    return new Fleet([
        dev('GIS-SRV-01', 'server', 1, '北區所', ['avail_1d' => 100.0, 'avail_7d' => 99.5, 'lat' => 25.1, 'lng' => 121.5]),
        dev('GIS-SRV-02', 'server', 0, '北區所', ['avail_1d' => 98.0, 'lat' => 25.1, 'lng' => 121.5]),
        dev('SW-01', 'network', 1, '南區所', ['avail_1d' => 99.0, 'lat' => 22.6, 'lng' => 120.3]),
        dev('PRN-01', 'printer', 1, null),
    ]);
}

function lsCoverage(): array
{
    return ['ok' => true, 'data' => [
        ['type' => 'windows', 'status' => 'none'], ['type' => 'linux', 'status' => 'none'],
        ['type' => 'firewall', 'status' => 'ok'], ['type' => 'network', 'status' => 'none'],
    ]];
}

function lsCaseRow(int $id, string $host, string $sev = 'high'): array
{
    return ['id' => $id, 'created_at' => '2026-10-20 10:00:00', 'title' => "事件 $id", 'subject_host' => $host, 'severity' => $sev,
            'severity_label' => ['high' => '高', 'medium' => '中', 'low' => '低'][$sev], 'status' => 'new', 'status_label' => '待確認', 'assignee' => 'alice'];
}

function lsKpi(array $overview): array
{
    return array_column($overview['kpi'], null, 'key');
}

function lsBuild(?Fleet $fleet, ?array $summary = null, ?array $open = null, ?array $latest = [], ?array $trend = [], ?array $coverage = null, ?array $thresholds = RiskScore::DEFAULT_THRESHOLDS): array
{
    return (new LandscapeService(LS_NOW))->build($fleet, $summary, $open, $latest, $trend, $coverage ?? lsCoverage(), $thresholds);
}

$lsSummary = ['new' => 2, 'ack' => 1, 'in_progress' => 0, 'closed' => 5, 'open' => 3];
$lsOpen = ['high' => 1, 'medium' => 2, 'low' => 0];

test('總覽：六張 KPI 的順序與內容（站點、資產、待處理事件、高風險站點、可用率、核心服務）', function () use ($lsSummary, $lsOpen) {
    $o = lsBuild(lsFleet(), $lsSummary, $lsOpen);
    assertSame(['sites', 'assets', 'cases', 'high_sites', 'availability', 'core_services'], array_column($o['kpi'], 'key'));
    $k = lsKpi($o);
    assertSame([2, '另有 1 台設備沒有有效位置（未指定，或是 Unknown 這類預設值）'], [$k['sites']['value'], $k['sites']['sub']]);
    assertSame([4, '伺服器 2 · 網路設備 1 · 其他 1 · 離線 1 台'], [$k['assets']['value'], $k['assets']['sub']]);
    assertSame([3, 'bad', '高風險 1 · 中風險 2 · 低風險 0'], [$k['cases']['value'], $k['cases']['state'], $k['cases']['sub']]);
    assertSame([1, 'bad', '中度異常 0 · 輕微異常 0'], [$k['high_sites']['value'], $k['high_sites']['state'], $k['high_sites']['sub']], '北區所 2 台離線 1 台＝嚴重');
    assertSame(['99.00%', 'good'], [$k['availability']['value'], $k['availability']['state']]);
    assertTrue(mb_strpos($k['availability']['sub'], '近 7 日 99.50%') !== false && mb_strpos($k['availability']['sub'], '3 台設備平均') !== false, $k['availability']['sub']);
    assertSame([null, 'unknown'], [$k['core_services']['value'], $k['core_services']['state']], '沒有核心服務清單，不能編一個數字');
    assertSame('尚未定義', $k['core_services']['na'], '大字寫原因，不是「無法取得」（那是讀取失敗）');
    assertSame(null, $k['sites']['na'] ?? null);
});

/** 帶服務水準設定與今日／昨日件數的總覽（具名參數，免得位置參數弄錯）。 */
function lsSla(?Fleet $fleet, ?array $sla, ?string $slaWarning = null, ?array $dayCounts = null, ?array $summary = null, ?array $open = null): array
{
    return (new LandscapeService(LS_NOW))->build($fleet, $summary, $open, [], [], lsCoverage(), RiskScore::DEFAULT_THRESHOLDS, null, $sla, $slaWarning, $dayCounts);
}

function lsCard(array $o, string $list, string $key): array
{
    return array_column($o[$list], null, 'key')[$key];
}

test('總覽：核心業務服務——沒設定是「尚未定義」；設定後算核心設備目前正常幾台，離線與不在 LibreNMS 的各自交代', function () {
    $fleet = new Fleet([dev('a', 'server', 1, null, ['id' => 1]), dev('b', 'server', 0, null, ['id' => 2]), dev('c', 'network', 1, null, ['id' => 3])]);
    $core = fn (?array $sla, ?Fleet $f = null, ?string $w = null) => lsCard(lsSla($f ?? $fleet, $sla, $w), 'kpi', 'core_services');
    $none = ['target' => null, 'window' => null, 'core' => []];
    $k = $core($none);
    assertSame([null, 'unknown', '尚未定義'], [$k['value'], $k['state'], $k['na']], '沒有核心設備清單，不能編一個數字');
    $k = $core(['core' => [1, 3]] + $none);
    assertSame(['2/2', 'good'], [$k['value'], $k['state']]);
    $k = $core(['core' => [1, 2, 3]] + $none);
    assertSame(['2/3', 'bad'], [$k['value'], $k['state']]);
    assertTrue(mb_strpos($k['sub'], '離線：b') !== false, $k['sub']);
    $k = $core(['core' => [1, 99]] + $none);
    assertSame(['1/2', 'warn'], [$k['value'], $k['state']], '不在 LibreNMS 的不能算正常，也不是離線');
    assertTrue(mb_strpos($k['sub'], '1 台不在 LibreNMS') !== false, $k['sub']);
    $k = $core(['core' => [98, 99]] + $none);
    assertSame([null, 'unknown', '設備不在 LibreNMS'], [$k['value'], $k['state'], $k['na']], '清單裡的設備全都找不到：不能顯示 0/2');
    $k = $core(null, null, '服務水準的設定值不合法');
    assertSame([null, 'unknown', null], [$k['value'], $k['state'], $k['na']], '設定讀不到：無法取得，不套預設');
    assertTrue(mb_strpos($k['sub'], '不合法') !== false, $k['sub']);
    $k = lsCard(lsSla(null, ['core' => [1]] + $none), 'kpi', 'core_services');
    assertSame([null, 'unknown', null], [$k['value'], $k['state'], $k['na']], 'LibreNMS 設備讀不到：無法取得');
    $many = new Fleet(array_map(static fn (int $i) => dev('h' . $i, 'server', 0, null, ['id' => $i]), range(1, 8)));
    assertTrue(mb_strpos(lsCard(lsSla($many, ['core' => range(1, 8)] + $none), 'kpi', 'core_services')['sub'], '等 8 台') !== false, '離線超過 5 台只列前 5 台，後面寫總數');
});

test('總覽：SLA 達成率——目標、服務時段、核心設備缺一不可，不是 7×24 就說無法計算，資料不夠不編數字', function () {
    $fleet = new Fleet([dev('a', 'server', 1, null, ['id' => 1, 'avail_30d' => 99.9]), dev('b', 'server', 1, null, ['id' => 2, 'avail_30d' => 99.1]),
                        dev('c', 'server', 1, null, ['id' => 3])]);
    $full = ['target' => 99.5, 'window' => '24x7', 'core' => [1, 2]];
    $sla = fn (array $s, ?Fleet $f = null) => lsCard(lsSla($f ?? $fleet, $s), 'kpi2', 'sla');
    $k = $sla($full);
    assertSame(['99.50%', 'good'], [$k['value'], $k['state']], '(99.9+99.1)/2＝99.5，剛好達標');
    assertTrue(mb_strpos($k['sub'], '目標 99.5%') !== false && mb_strpos($k['sub'], '最近 30 日') !== false && mb_strpos($k['sub'], '未排除計畫性維護') !== false, $k['sub']);
    $k = $sla(['target' => 99.6] + $full);
    assertSame(['99.50%', 'bad'], [$k['value'], $k['state']], '低於目標');
    // 目標最多三位小數：先捨入再比會把 99.9985 當成 100.00 而判達標（五個九這種目標會被灌水）
    $edge = fn (float $avail, float $target) => $sla(['target' => $target] + $full, new Fleet([dev('a', 'server', 1, null, ['id' => 1, 'avail_30d' => $avail]), dev('b', 'server', 1, null, ['id' => 2, 'avail_30d' => $avail])]));
    $k = $edge(99.9985, 99.999);
    assertSame(['99.998%', 'bad'], [$k['value'], $k['state']], '99.9985 < 99.999：未達標，顯示也不能是 100.00%');
    $k = $edge(99.9994, 99.999);
    assertSame(['99.999%', 'good'], [$k['value'], $k['state']], '99.9994 ≥ 99.999：達標，顯示的值與目標一致');
    $k = $edge(99.9894, 99.99);
    assertSame(['99.989%', 'bad'], [$k['value'], $k['state']], '99.9894 < 99.99：未達標');
    $k = $sla(['core' => [1, 2, 99]] + $full);
    assertSame(['99.50%', 'warn'], [$k['value'], $k['state']], '清單裡有設備已不在 LibreNMS：不計入平均，但不能默默顯示達標（綠色）');
    assertTrue(mb_strpos($k['sub'], '另有 1 台不在 LibreNMS') !== false, $k['sub']);
    $k = $sla(['core' => [1, 2, 3]] + $full);
    assertSame('99.50%', $k['value'], '沒有 30 日可用率的設備不計入平均');
    assertTrue(mb_strpos($k['sub'], '1 台沒有資料') !== false, $k['sub']);
    foreach ([['target', null, 'F-20'], ['window', null, 'F-25'], ['core', [], '核心設備']] as [$key, $empty, $mention]) {
        $k = $sla([$key => $empty] + $full);
        assertSame([null, 'unknown', '尚未設定'], [$k['value'], $k['state'], $k['na']], $key);
        assertTrue(mb_strpos($k['sub'], $mention) !== false, $key . '：要說缺哪一項：' . $k['sub']);
    }
    $k = $sla(['window' => 'custom'] + $full);
    assertSame([null, 'unknown', '無法計算'], [$k['value'], $k['state'], $k['na']], '不是 7×24：LibreNMS 的可用率是全天算的');
    $k = $sla($full, new Fleet([dev('a', 'server', 1, null, ['id' => 1]), dev('b', 'server', 1, null, ['id' => 2])]));
    assertSame([null, 'unknown', '尚無資料'], [$k['value'], $k['state'], $k['na']], '核心設備都還沒有 30 日可用率：不是 0%');
    $k = $sla(['core' => [98]] + $full);
    assertSame([null, 'unknown', '設備不在 LibreNMS'], [$k['value'], $k['state'], $k['na']]);
    $k = lsCard(lsSla($fleet, null, '服務水準的設定值不合法'), 'kpi2', 'sla');
    assertSame([null, 'unknown', null], [$k['value'], $k['state'], $k['na']], '設定讀不到：無法取得');
    $k = lsCard(lsSla(null, $full), 'kpi2', 'sla');
    assertSame([null, 'unknown', null], [$k['value'], $k['state'], $k['na']], '設備讀不到：無法取得');
    assertSame(['sla', 'network_availability', 'compliance'], array_column(lsSla($fleet, $full)['kpi2'], 'key'));
});

/** 帶稽核摘要（AuditEvidenceService::summary() 的結果）的總覽。null＝稽核資料讀不到。 */
function lsAudit(?array $audit): array
{
    return (new LandscapeService(LS_NOW))->build(lsFleet(), null, null, [], [], lsCoverage(), RiskScore::DEFAULT_THRESHOLDS, null, LandscapeService::NO_SLA, null, null, $audit);
}

/** @return array<string,mixed> 一份已建立基準與週期的稽核摘要；$c 覆寫 counts 的欄位 */
function lsAuditReady(array $c = []): array
{
    $counts = $c + ['total' => 40, 'unreviewed' => 10, 'compliant' => 24, 'gap' => 4, 'not_applicable' => 2, 'assessed' => 30,
                    'remediations' => 4, 'overdue' => 0, 'remediations_done' => 1, 'snapshots' => 3, 'snapshot_bytes' => 1024];
    return ['ready' => true, 'message' => null, 'catalog' => 'v1', 'cycle' => ['title' => '2026 年度', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'], 'counts' => $counts, 'domains' => []];
}

test('總覽：合規覆蓋率——已評估的控制項占全部的比例（不是符合率）；還沒建立基準或週期、讀不到各說各的，不是 0%', function () {
    $card = fn (?array $a) => lsCard(lsAudit($a), 'kpi2', 'compliance');
    $k = $card(lsAuditReady());
    assertSame(['compliance', '合規覆蓋率', '75.0%', 'warn'], [$k['key'], $k['label'], $k['value'], $k['state']], '30／40 已評估；有缺口與未評估，不是綠色');
    foreach (['已評估 30／40', '符合 24', '缺口 4', '不適用 2', '未評估 10', '2026 年度'] as $s) {
        assertTrue(mb_strpos($k['sub'], $s) !== false, $s . '：' . $k['sub']);
    }
    $all = $card(lsAuditReady(['total' => 40, 'unreviewed' => 0, 'compliant' => 38, 'gap' => 0, 'not_applicable' => 2, 'assessed' => 40]));
    assertSame(['100.0%', 'good'], [$all['value'], $all['state']], '全部評估完、沒有缺口、沒有逾期');
    $almost = $card(lsAuditReady(['total' => 2000, 'unreviewed' => 1, 'compliant' => 1999, 'gap' => 0, 'not_applicable' => 0, 'assessed' => 1999]));
    assertSame('99.9%', $almost['value'], '1999／2000＝99.95%，還有一項沒評估：無條件捨去，不能四捨五入成 100%');
    $late = $card(lsAuditReady(['overdue' => 2, 'gap' => 0, 'unreviewed' => 0, 'assessed' => 40, 'compliant' => 38]));
    assertSame('bad', $late['state'], '有改善事項逾期');
    assertTrue(mb_strpos($late['sub'], '改善逾期 2 件') !== false, $late['sub']);
    $k = $card(['ready' => false, 'message' => '未建立評估基準', 'catalog' => null, 'cycle' => null, 'counts' => null, 'domains' => []]);
    assertSame([null, 'unknown', '尚未建立'], [$k['value'], $k['state'], $k['na']]);
    assertTrue(mb_strpos($k['sub'], '未建立評估基準') !== false && mb_strpos($k['sub'], '稽核與證據') !== false, $k['sub']);
    $k = $card(lsAuditReady(['total' => 0, 'unreviewed' => 0, 'compliant' => 0, 'gap' => 0, 'not_applicable' => 0, 'assessed' => 0]));
    assertSame([null, 'unknown', '無控制項'], [$k['value'], $k['state'], $k['na']], '基準裡沒有控制項：不能除以零，也不是 0%');
    $k = $card(null);
    assertSame([null, 'unknown', null], [$k['value'], $k['state'], $k['na']], '稽核資料讀不到：無法取得');
});

test('總覽：網路可用性——只算網路類設備最近 7 日的平均；沒有網路設備或沒有資料各說各的，不是 0%', function () {
    $net = fn (Fleet $f) => lsCard(lsSla($f, LandscapeService::NO_SLA), 'kpi2', 'network_availability');
    $k = $net(new Fleet([dev('sw', 'network', 1, null, ['avail_7d' => 99.9]), dev('fw', 'firewall', 1, null, ['avail_7d' => 99.5]), dev('srv', 'server', 1, null, ['avail_7d' => 10.0])]));
    assertSame(['99.70%', 'good'], [$k['value'], $k['state']], '伺服器的 10% 不能混進網路可用性');
    assertTrue(mb_strpos($k['sub'], '最近 7 日') !== false && mb_strpos($k['sub'], '2 台網路設備') !== false, $k['sub']);
    assertSame('warn', $net(new Fleet([dev('sw', 'network', 1, null, ['avail_7d' => 96.0])]))['state']);
    assertSame('bad', $net(new Fleet([dev('sw', 'network', 1, null, ['avail_7d' => 90.0])]))['state']);
    $k = $net(new Fleet([dev('sw', 'network', 1, null, ['avail_7d' => 99.0]), dev('sw2', 'network', 1)]));
    assertTrue(mb_strpos($k['sub'], '1 台沒有資料') !== false, $k['sub']);
    $k = $net(new Fleet([dev('srv', 'server', 1, null, ['avail_7d' => 99.0])]));
    assertSame([null, 'unknown', '無網路設備'], [$k['value'], $k['state'], $k['na']]);
    $k = $net(new Fleet([dev('sw', 'network')]));
    assertSame([null, 'unknown', '尚無資料'], [$k['value'], $k['state'], $k['na']], '有網路設備但沒有可用率：不是 0%');
    $k = lsCard(lsSla(null, LandscapeService::NO_SLA), 'kpi2', 'network_availability');
    assertSame([null, 'unknown', null], [$k['value'], $k['state'], $k['na']], '設備讀不到：無法取得');
});

test('總覽：待處理事件帶「今日新建、昨日新建」（台北時間分日，由事件案資料算，不用快照）；讀不到或超過上限就不寫', function () use ($lsSummary, $lsOpen) {
    $svc = new LandscapeService(LS_NOW);
    $ts0 = strtotime(taipeiDay(LS_NOW) . ' 00:00:00 +08:00');
    $at = fn (int $ts) => gmdate('Y-m-d H:i:s', $ts);
    $rows = [['created_at' => $at(LS_NOW)], ['created_at' => $at($ts0)], ['created_at' => $at($ts0 - 1)], ['created_at' => $at($ts0 - 86400)],
             ['created_at' => $at($ts0 - 86401)], ['created_at' => 'garbage']];
    assertSame(['today' => 2, 'yesterday' => 2], $svc->dayCounts($rows), '昨天 00:00:00～23:59:59 都算昨天；前天與壞時間不算');
    assertSame(['today' => 0, 'yesterday' => 0], $svc->dayCounts([]), '資料表讀得到而沒有案件：這是真的 0');
    $capped = array_fill(0, CaseRepo::CREATED_SINCE_LIMIT, ['created_at' => $at(LS_NOW)]);
    assertSame(null, $svc->dayCounts($capped), '碰到查詢上限：較早的一天可能少算，不能寫一個偏低的數字');
    $k = lsCard(lsSla(lsFleet(), LandscapeService::NO_SLA, null, ['today' => 2, 'yesterday' => 5], $lsSummary, $lsOpen), 'kpi', 'cases');
    assertSame('高風險 1 · 中風險 2 · 低風險 0；今日新建 2 件（昨日 5 件）', $k['sub']);
    $k = lsCard(lsSla(lsFleet(), LandscapeService::NO_SLA, null, null, $lsSummary, $lsOpen), 'kpi', 'cases');
    assertSame('高風險 1 · 中風險 2 · 低風險 0', $k['sub'], '沒有件數就不寫，也不影響原本的內容');
});

test('總覽：監控資產數要帶出離線台數（含沒指定位置的設備，站點相關卡片看不到它們）', function () use ($lsSummary, $lsOpen) {
    $assets = function (int $total, int $down, ?string $loc = null) use ($lsSummary, $lsOpen) {
        $rows = [];
        for ($i = 0; $i < $total; $i++) {
            $rows[] = dev("d$i", 'server', $i < $down ? 0 : 1, $loc);
        }
        return lsKpi(lsBuild(new Fleet($rows), $lsSummary, $lsOpen))['assets'];
    };
    assertSame(['good', '伺服器 5 · 網路設備 0 · 其他 0'], [$assets(5, 0)['state'], $assets(5, 0)['sub']], '都正常就不寫離線');
    assertSame('warn', $assets(20, 1)['state'], '5% 離線');
    assertSame('bad', $assets(20, 2)['state'], '10% 離線');
    assertSame('bad', $assets(3, 1)['state'], '至少 1 台離線就算比例夠高的下限');
    $noLocation = $assets(14, 2, null);      // 沒有指定位置：沒有站點，但離線台數仍然看得到
    assertTrue(mb_strpos($noLocation['sub'], '離線 2 台') !== false, $noLocation['sub']);
    assertSame(0, (new Fleet([dev('x', 'server', 0)]))->up());
    assertSame('warn', $assets(0, 0)['state'], '沒有任何設備不是綠色');
});

test('總覽：趨勢資料碰到資料層的筆數上限時要標示（較早的日期可能少算）', function () {
    $mk = fn (int $n) => array_fill(0, $n, ['created_at' => gmdate('Y-m-d H:i:s', LS_NOW), 'severity' => 'low']);
    $limit = Ioc\CaseRepo::CREATED_SINCE_LIMIT;
    assertSame(false, lsBuild(lsFleet(), null, null, [], $mk($limit - 1))['trend']['data']['capped']);
    assertSame(true, lsBuild(lsFleet(), null, null, [], $mk($limit))['trend']['data']['capped']);
});

test('事件案：趨勢用的資料超過上限時，留下最新的、捨棄最舊的', function () {
    $repo = new MemoryCaseRepo();
    $svc = new CaseService($repo, fn () => '2026-10-20 08:00:00');
    $limit = Ioc\CaseRepo::CREATED_SINCE_LIMIT;
    for ($i = 0; $i < $limit + 3; $i++) {
        $at = gmdate('Y-m-d H:i:s', strtotime('2026-10-01 00:00:00 UTC') + $i * 10);
        $repo->insertCase(['title' => 't', 'severity' => 'low', 'status' => 'new', 'created_at' => $at, 'updated_at' => $at]);
    }
    $rows = $svc->createdWithin(30, Access::VIEWER);
    assertSame($limit, count($rows));
    assertSame(gmdate('Y-m-d H:i:s', strtotime('2026-10-01 00:00:00 UTC') + ($limit + 2) * 10), $rows[0]['created_at'], '第一筆是最新的');
    assertSame(gmdate('Y-m-d H:i:s', strtotime('2026-10-01 00:00:00 UTC') + 3 * 10), $rows[$limit - 1]['created_at'], '最舊的 3 筆被捨棄');
});

test('資料涵蓋的統計：有資料的來源幾類、正常幾類；涵蓋取不到是 null（風險分數與整體運作共用）', function () {
    $cov = ['ok' => true, 'data' => [['status' => 'ok'], ['status' => 'delayed'], ['status' => 'none'], ['status' => 'unknown'], ['status' => 'stale']]];
    assertSame(['present' => 4, 'ok' => 1], Ioc\OverviewService::sourceCounts($cov));
    assertSame(['present' => 0, 'ok' => 0], Ioc\OverviewService::sourceCounts(['ok' => true, 'data' => []]));
    assertSame(null, Ioc\OverviewService::sourceCounts(['ok' => false, 'error' => 'x']));
});

test('Fleet：站點結果有快取，排行不會把快取排亂', function () {
    $f = new Fleet([dev('a', 'server', 0, 'Z區'), dev('b', 'server', 1, 'A區'), dev('c', 'server', 1, 'M區')]);
    $first = $f->sites();
    assertSame(['A區', 'M區', 'Z區'], array_column($first, 'name'));
    assertSame('Z區', $f->ranking(1)[0]['name'], '排行最糟的在前');
    assertSame(['A區', 'M區', 'Z區'], array_column($f->sites(), 'name'), '排行之後站點仍是依名稱排序');
    assertSame($first, $f->sites());
});

test('總覽：沒有值的 KPI，大字依原因區分「讀取失敗」與「還沒設定」', function () use ($lsSummary, $lsOpen) {
    $failed = lsKpi(lsBuild(null, $lsSummary, $lsOpen));
    foreach (['sites', 'assets', 'high_sites', 'availability'] as $key) {
        assertSame(null, $failed[$key]['na'], "{$key}：讀取失敗就用預設的「無法取得」");
    }
    $noLoc = lsKpi(lsBuild(new Fleet([dev('a')]), $lsSummary, $lsOpen));
    assertSame(['尚未設定', '尚未設定'], [$noLoc['sites']['na'], $noLoc['high_sites']['na']]);
    $noAvail = lsKpi(lsBuild(new Fleet([dev('a', 'server', 1, 'X')]), $lsSummary, $lsOpen));
    assertSame('尚無資料', $noAvail['availability']['na']);
});

test('總覽：LibreNMS 設備讀不到時，設備相關 KPI 與區塊是「無法取得」，事件案那幾塊照常', function () use ($lsSummary, $lsOpen) {
    $o = lsBuild(null, $lsSummary, $lsOpen, [lsCaseRow(1, 'x')]);
    $k = lsKpi($o);
    foreach (['sites', 'assets', 'high_sites', 'availability'] as $key) {
        assertSame([null, 'unknown'], [$k[$key]['value'], $k[$key]['state']], $key);
    }
    assertSame(3, $k['cases']['value']);
    assertSame(false, $o['sites']['ok']);
    assertSame(false, $o['ranking']['ok']);
    assertSame(true, $o['latest_cases']['ok']);
    assertSame('LibreNMS 的設備資料無法讀取', $k['assets']['sub']);
    assertSame(true, $o['risk']['ok'], '還有事件案與資料來源可以算');
    assertSame(['設備與服務'], $o['risk']['data']['missing']);
});

test('總覽：設備都沒有指定位置時，站點數是「無法取得」並說明去哪裡設定，不是 0 個站點', function () use ($lsSummary, $lsOpen) {
    $o = lsBuild(new Fleet([dev('a'), dev('b', 'server', 0)]), $lsSummary, $lsOpen);
    $k = lsKpi($o);
    assertSame([null, 'unknown'], [$k['sites']['value'], $k['sites']['state']]);
    assertTrue(mb_strpos($k['sites']['sub'], '位置') !== false);
    assertSame(null, $k['high_sites']['value']);
    assertSame(2, $k['assets']['value'], '資產數不受影響');
    assertSame(false, $o['sites']['ok']);
    assertTrue(mb_strpos($o['sites']['error'], '位置') !== false);
});

test('總覽：事件案讀不到時，待處理事件、趨勢、最新事件是「無法取得」，設備那幾塊照常', function () {
    $o = lsBuild(lsFleet(), null, null, null, null);
    assertSame([null, 'unknown'], [lsKpi($o)['cases']['value'], lsKpi($o)['cases']['state']]);
    assertSame(false, $o['trend']['ok']);
    assertSame(false, $o['latest_cases']['ok']);
    assertSame(4, lsKpi($o)['assets']['value']);
    assertSame(true, $o['risk']['ok']);
    assertSame(['事件處置'], $o['risk']['data']['missing']);
});

test('總覽：可用率沒有資料時是「無法取得」，狀態依 99／95 分三級', function () use ($lsSummary, $lsOpen) {
    assertSame([null, 'unknown'], [lsKpi(lsBuild(new Fleet([dev('a', 'server', 1, 'X')]), $lsSummary, $lsOpen))['availability']['value'], 'unknown']);
    $state = fn (float $v) => lsKpi(lsBuild(new Fleet([dev('a', 'server', 1, 'X', ['avail_1d' => $v])]), $lsSummary, $lsOpen))['availability']['state'];
    assertSame('good', $state(99.0));
    assertSame('warn', $state(98.99));
    assertSame('warn', $state(95.0));
    assertSame('bad', $state(94.99));
});

test('總覽：高風險站點的狀態色：有嚴重＝紅、只有中度＝黃、都沒有＝綠', function () use ($lsSummary, $lsOpen) {
    $mk = function (int $up, int $down) use ($lsSummary, $lsOpen) {
        $rows = [];
        for ($i = 0; $i < $up + $down; $i++) {
            $rows[] = dev("d$i", 'server', $i < $down ? 0 : 1, 'S');
        }
        return lsKpi(lsBuild(new Fleet($rows), $lsSummary, $lsOpen))['high_sites'];
    };
    assertSame(['bad', 1], [$mk(1, 1)['state'], $mk(1, 1)['value']]);
    assertSame(['warn', 0], [$mk(3, 1)['state'], $mk(3, 1)['value']]);
    assertSame(['warn', 0], [$mk(9, 1)['state'], $mk(9, 1)['value']], '輕微異常也要提醒，不能是綠色');
    assertSame(['good', 0], [$mk(5, 0)['state'], $mk(5, 0)['value']]);
});

test('總覽：風險儀表帶出分項與缺的項目；三項都沒有資料是「無法計算」', function () use ($lsSummary, $lsOpen) {
    $o = lsBuild(lsFleet(), $lsSummary, $lsOpen);
    $d = $o['risk']['data'];
    assertSame(true, $o['risk']['ok']);
    assertSame(['devices', 'cases', 'sources'], array_keys($d['parts']));
    assertSame(75, $d['parts']['devices']['score'], '4 台裡 3 台正常');
    assertSame(100 - 15 - 12, $d['parts']['cases']['score']);
    assertSame(100, $d['parts']['sources']['score'], '只算有資料的來源：防火牆正常');
    $none = lsBuild(null, null, null, null, null, ['ok' => false, 'error' => 'x']);
    assertSame(false, $none['risk']['ok']);
});

test('總覽：風險等級用使用者設的門檻，並把門檻帶給畫面（畫面上的說明文字不能另外寫死）', function () use ($lsSummary, $lsOpen) {
    $mk = fn (array $t) => lsBuild(lsFleet(), $lsSummary, $lsOpen, [], [], null, $t)['risk']['data'];
    $default = $mk(['low' => 85, 'medium' => 65]);
    assertSame(['low' => 85, 'medium' => 65], $default['thresholds']);
    assertSame('medium', $default['level'], '分數 ' . $default['score'] . ' 介於 65 與 85 之間');
    $strict = $mk(['low' => 50, 'medium' => 30]);
    assertSame(['low', '低風險', ['low' => 50, 'medium' => 30]], [$strict['level'], $strict['level_label'], $strict['thresholds']]);
    assertSame($default['score'], $strict['score'], '改門檻只影響等級，不影響分數');
    assertSame(false, isset($default['warning']), '門檻讀得到就沒有警告');
});

test('總覽：風險等級門檻讀不到時，仍顯示分數但不判定等級，並明講原因', function () use ($lsSummary, $lsOpen) {
    $o = lsBuild(lsFleet(), $lsSummary, $lsOpen, [], [], null, null);
    assertSame(true, $o['risk']['ok'], '分數還是算得出來');
    $d = $o['risk']['data'];
    assertSame([null, null, null], [$d['level'], $d['level_label'], $d['thresholds']]);
    assertTrue(is_int($d['score']), '分數照常');
    assertTrue(mb_strpos($d['warning'], '門檻') !== false && mb_strpos($d['warning'], '不判定') !== false, $d['warning']);
});

test('總覽：近 30 日趨勢：台北時間分日、依嚴重性分線，範圍外與不認得的嚴重性不算', function () {
    $ts0 = strtotime(taipeiDay(LS_NOW) . ' 00:00:00 +08:00');
    $at = fn (int $ts) => gmdate('Y-m-d H:i:s', $ts);
    $rows = [
        ['created_at' => $at(LS_NOW), 'severity' => 'high'],
        ['created_at' => $at($ts0), 'severity' => 'low'],                       // 台北今天 00:00:00＝今天
        ['created_at' => $at($ts0 - 1), 'severity' => 'medium'],                // 台北昨天 23:59:59＝昨天
        ['created_at' => $at(LS_NOW - 31 * 86400), 'severity' => 'high'],       // 範圍外
        ['created_at' => $at(LS_NOW), 'severity' => 'critical'],                // 不認得的嚴重性
        ['created_at' => 'garbage', 'severity' => 'high'],                      // 壞時間
    ];
    $t = lsBuild(lsFleet(), null, null, [], $rows)['trend']['data'];
    assertSame(30, count($t['days']));
    assertSame(taipeiDay(LS_NOW), $t['days'][29]);
    assertSame(taipeiDay(LS_NOW - 29 * 86400), $t['days'][0]);
    assertSame([1, 0], [$t['series']['high'][29], $t['series']['high'][28]]);
    assertSame([1, 0], [$t['series']['low'][29], $t['series']['low'][28]]);
    assertSame([0, 1], [$t['series']['medium'][29], $t['series']['medium'][28]]);
    assertSame(3, $t['total']);
    assertSame(0, array_sum($t['series']['high']) - 1, '只有今天那一筆高風險');
});

test('總覽：短期間事件趨勢分六格，起迄與邊界不漏算也不混入區間外資料', function () {
    $start = LS_NOW - 1800;
    $at = static fn (int $ts) => gmdate('Y-m-d H:i:s', $ts);
    $rows = [
        ['created_at' => $at($start - 1), 'severity' => 'high'],
        ['created_at' => $at($start), 'severity' => 'high'],
        ['created_at' => $at($start + 299), 'severity' => 'medium'],
        ['created_at' => $at($start + 300), 'severity' => 'low'],
        ['created_at' => $at(LS_NOW), 'severity' => 'high'],
        ['created_at' => $at(LS_NOW + 1), 'severity' => 'high'],
    ];
    $result = (new LandscapeService(LS_NOW, 1800))->build(lsFleet(), null, null, [], $rows, lsCoverage(), RiskScore::DEFAULT_THRESHOLDS);
    $trend = $result['trend']['data'];
    assertSame(6, count($trend['labels']));
    assertSame([1, 0, 0, 0, 0, 1], $trend['series']['high']);
    assertSame([1, 0, 0, 0, 0, 0], $trend['series']['medium']);
    assertSame([0, 1, 0, 0, 0, 0], $trend['series']['low']);
    assertSame(4, $trend['total']);
});

/** 趨勢有自己的視窗（24 小時／7 日／30 日），不跟 Graylog 區塊的統計期間綁在一起（簡報是「近 30 日」；正式端點以前一律傳統計期間，30 日分支走不到）。 */
test('總覽：事件趨勢視窗只有 24 小時、7 日、30 日，預設 30 日，標籤直接寫在資料裡', function () {
    assertSame([86400 => '24 小時', 604800 => '7 日', 2592000 => '30 日'], LandscapeService::TREND_OPTIONS);
    assertSame(2592000, LandscapeService::TREND_DEFAULT);
    $label = fn (?int $w) => (new LandscapeService(LS_NOW, $w))->build(lsFleet(), null, null, [], [], lsCoverage(), RiskScore::DEFAULT_THRESHOLDS)['trend']['data']['label'];
    assertSame('近 30 日', $label(null), '沒指定視窗＝30 日（簡報的預設）');
    assertSame('近 30 日', $label(2592000));
    assertSame('近 7 日', $label(604800));
    assertSame('最近 24 小時', $label(86400));
    assertSame('最近 30 分鐘', $label(1800), '舊的短期間（Graylog 區塊用的）仍可當趨勢視窗，行為不變');
    foreach ([0, 7200, 172800, 86401, 31536000] as $bad) {
        assertThrows(Ioc\ValidationError::class, fn () => new LandscapeService(LS_NOW, $bad), '不支援的趨勢期間');
    }
});

test('總覽：7 日趨勢逐日 7 格（台北時間分日），範圍外、不認得的嚴重性與壞時間不算', function () {
    $ts0 = strtotime(taipeiDay(LS_NOW) . ' 00:00:00 +08:00');
    $at = fn (int $ts) => gmdate('Y-m-d H:i:s', $ts);
    $rows = [
        ['created_at' => $at(LS_NOW), 'severity' => 'high'],
        ['created_at' => $at($ts0 - 6 * 86400), 'severity' => 'low'],            // 台北 6 天前 00:00:00＝第一格
        ['created_at' => $at($ts0 - 6 * 86400 - 1), 'severity' => 'medium'],     // 再早 1 秒＝範圍外
        ['created_at' => $at(LS_NOW - 20 * 86400), 'severity' => 'high'],        // 範圍外
        ['created_at' => $at(LS_NOW), 'severity' => 'critical'],                 // 不認得的嚴重性
        ['created_at' => 'garbage', 'severity' => 'high'],                       // 壞時間
    ];
    $t = (new LandscapeService(LS_NOW, 604800))->build(lsFleet(), null, null, [], $rows, lsCoverage(), RiskScore::DEFAULT_THRESHOLDS)['trend']['data'];
    assertSame(7, count($t['days']));
    assertSame(taipeiDay(LS_NOW), $t['days'][6]);
    assertSame(taipeiDay(LS_NOW - 6 * 86400), $t['days'][0]);
    assertSame([1, 0], [$t['series']['high'][6], $t['series']['high'][0]]);
    assertSame([0, 1], [$t['series']['low'][6], $t['series']['low'][0]]);
    assertSame(0, array_sum($t['series']['medium']), '範圍外的 medium 不算');
    assertSame(2, $t['total']);
    assertSame('近 7 日', $t['label']);
    assertSame(false, isset($t['labels']), '逐日的趨勢用 days，不是 labels（畫面靠這個分辨要不要截 MM-DD）');
});

test('總覽：沒有任何事件案時，趨勢是 30 天的 0（資料表讀得到，這是真的 0）', function () {
    $t = lsBuild(lsFleet(), null, null, [], [])['trend'];
    assertSame(true, $t['ok']);
    assertSame(0, $t['data']['total']);
});

test('總覽：最新事件帶出站點（由主機對應，對不到是空字串），最多 8 筆', function () {
    $cases = [lsCaseRow(1, 'gis-srv-01'), lsCaseRow(2, 'unknown-host', 'low'), lsCaseRow(3, '')];
    for ($i = 4; $i <= 12; $i++) {
        $cases[] = lsCaseRow($i, 'SW-01', 'medium');
    }
    $l = lsBuild(lsFleet(), null, null, $cases)['latest_cases']['data'];
    assertSame(8, count($l));
    assertSame(['北區所', '', '', '南區所'], array_column(array_slice($l, 0, 4), 'site'));
    assertSame(['id' => '1', 'time' => '2026-10-20 10:00:00', 'title' => '事件 1', 'site' => '北區所', 'severity' => 'high',
                'severity_label' => '高', 'status' => 'new', 'status_label' => '待確認', 'assignee' => 'alice'], $l[0]);
    $noFleet = lsBuild(null, null, null, [lsCaseRow(1, 'gis-srv-01')])['latest_cases']['data'];
    assertSame('', $noFleet[0]['site'], '設備讀不到時不能編站點');
});

test('總覽：站點區塊與排行', function () use ($lsSummary, $lsOpen) {
    $o = lsBuild(lsFleet(), $lsSummary, $lsOpen);
    $s = $o['sites']['data'];
    assertSame(['北區所', '南區所'], array_column($s['items'], 'name'), '站點區塊依名稱排序');
    assertSame(['normal' => 1, 'minor' => 0, 'moderate' => 0, 'severe' => 1], $s['counts']);
    assertSame(1, $s['unassigned']);
    assertSame(true, $s['has_locations'], '有站點帶座標才畫散佈圖');
    assertSame(['北區所', '南區所'], array_column($o['ranking']['data'], 'name'), '嚴重的排前面');
    $noCoord = lsBuild(new Fleet([dev('a', 'server', 1, 'X')]), $lsSummary, $lsOpen)['sites']['data'];
    assertSame(false, $noCoord['has_locations']);
});
