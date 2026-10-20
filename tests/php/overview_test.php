<?php
declare(strict_types=1);

use Ioc\GraylogUnavailable;
use Ioc\Labels;
use Ioc\LogSource;
use Ioc\OverviewService;
use Ioc\Time;

/** 假的日誌來源：記下每次查詢，結果由測試給的函式決定。 */
final class FakeLogSource implements LogSource
{
    /** @var array<int,array{kind:string,query:string,groupBy:array,metrics:array}> */
    public array $calls = [];
    /** @var callable */
    public $agg;
    /** @var callable */
    public $msg;

    public function __construct(?callable $agg = null, ?callable $msg = null)
    {
        $this->agg = $agg ?? static fn () => [];
        $this->msg = $msg ?? static fn () => [];
    }

    public function aggregate(string $query, int $rangeSeconds, array $groupBy, array $metrics, int $limit = 100): array
    {
        $this->calls[] = ['kind' => 'aggregate', 'query' => $query, 'groupBy' => $groupBy, 'metrics' => $metrics];
        return ($this->agg)($query, $groupBy, $metrics);
    }

    public function messages(string $query, int $rangeSeconds, array $fields, int $size): array
    {
        $this->calls[] = ['kind' => 'messages', 'query' => $query, 'groupBy' => [], 'metrics' => []];
        return ($this->msg)($query, $fields, $size);
    }
}

const NOW = 1760000000;   // 固定「現在」，讓新鮮度可預期

function ago(int $seconds): string
{
    return gmdate('Y-m-d\TH:i:s.000\Z', NOW - $seconds);
}

/** 一組「一切正常」的假資料。 */
function healthySource(): FakeLogSource
{
    return new FakeLogSource(
        function (string $q, array $groupBy, array $metrics): array {
            if ($groupBy === ['log_type'] && in_array('latest:timestamp', $metrics, true)) {
                return [
                    ['log_type' => 'windows', 'count' => 120, 'latest_timestamp' => ago(120)],
                    ['log_type' => 'linux', 'count' => 40, 'latest_timestamp' => ago(30)],
                    ['log_type' => 'firewall', 'count' => 9000, 'latest_timestamp' => ago(2)],
                    ['log_type' => 'network', 'count' => 8, 'latest_timestamp' => ago(60)],
                ];
            }
            if ($groupBy === ['log_type']) {
                return [['log_type' => 'windows', 'count' => 7], ['log_type' => 'linux', 'count' => 5]];
            }
            if ($groupBy === ['user_name']) {
                return [['user_name' => 'administrator', 'count' => 8], ['user_name' => 'svc_land', 'count' => 3], ['user_name' => 'tuser01', 'count' => 1]];
            }
            if ($groupBy === ['user_name', 'src_ip']) {
                return [
                    ['user_name' => 'svc_land', 'src_ip' => '10.20.2.11', 'count' => 3],
                    ['user_name' => 'administrator', 'src_ip' => '10.20.5.23', 'count' => 12],
                    ['user_name' => 'tuser01', 'src_ip' => '10.20.1.35', 'count' => 1],
                ];
            }
            if ($groupBy === ['src_ip']) {
                return [['src_ip' => '10.60.0.140', 'count' => 20], ['src_ip' => '8.8.8.8', 'count' => 900]];
            }
            return [];
        },
        fn () => [['timestamp' => '2026-10-20T05:17:50.406Z', 'user_name' => 'administrator', 'event_action' => 'login_failed',
                   'src_ip' => '10.20.5.23', 'host_name' => 'GIS-SRV-01', 'severity' => 'low', 'log_type' => 'windows']]
    );
}

function devices(int $total = 50, int $up = 48): array
{
    $down = [];
    for ($i = 0; $i < $total - $up; $i++) {
        $down[] = ['name' => "sw-$i", 'reason' => 'icmp', 'last_polled' => '2026-10-20 13:00:00'];
    }
    return ['total' => $total, 'up' => $up, 'down_list' => $down];
}

function cases(int $new = 2, int $ack = 0, int $inProgress = 1, int $closed = 5): array
{
    return ['new' => $new, 'ack' => $ack, 'in_progress' => $inProgress, 'closed' => $closed, 'open' => $new + $ack + $inProgress];
}

function kpiByKey(array $overview): array
{
    $out = [];
    foreach ($overview['kpi'] as $k) {
        $out[$k['key']] = $k;
    }
    return $out;
}

test('總覽：一切正常時各區塊與 KPI 都有值', function () {
    $o = (new OverviewService(healthySource(), false, NOW))->build(devices(), cases());
    $k = kpiByKey($o);
    assertSame('48/50', $k['devices']['value']);
    assertSame('2 台設備狀態異常', $k['devices']['sub']);
    assertSame(3, $k['cases']['value']);
    assertSame('2 件待確認 · 0 件已確認 · 1 件處理中', $k['cases']['sub']);
    assertSame(12, $k['suspicious']['value'], '7+5 筆可疑事件');
    assertSame('4/4', $k['sources']['value']);
    assertSame('各類來源都有資料', $k['sources']['sub']);
    assertSame('96%', $k['ops']['value'], '(48 設備 + 4 來源) / (50 + 4)');
    assertSame('good', $k['ops']['state']);
    assertSame(true, $o['coverage']['ok']);
    assertSame(['windows', 'linux', 'firewall', 'network'], array_column($o['coverage']['data'], 'type'));
    assertSame(['ok', 'ok', 'ok', 'ok'], array_column($o['coverage']['data'], 'status'));
    assertSame(false, $o['meta']['demo']);
    assertSame('最近 24 小時', $o['meta']['range_label']);
});

test('總覽：Graylog 無法連線時，相關區塊標示無法取得，數字是 null 而不是 0，其他區塊照常', function () {
    $down = new FakeLogSource(
        function () { throw new GraylogUnavailable('Graylog 連線失敗'); },
        function () { throw new GraylogUnavailable('Graylog 連線失敗'); }
    );
    $o = (new OverviewService($down, false, NOW))->build(devices(), cases());
    foreach (['coverage', 'activity'] as $section) {
        assertSame(false, $o[$section]['ok'], $section);
        assertSame('Graylog 連線失敗', $o[$section]['error']);
    }
    assertSame(false, $o['attention']['accounts']['ok']);
    assertSame(false, $o['attention']['denied_sources']['ok']);
    $k = kpiByKey($o);
    foreach (['ops', 'suspicious', 'sources'] as $key) {
        assertSame(null, $k[$key]['value'], "$key 不能顯示成 0 或正常");
        assertSame('unknown', $k[$key]['state'], $key);
    }
    assertSame('48/50', $k['devices']['value'], '設備資料不受影響');
    assertSame(3, $k['cases']['value'], '事件案不受影響');
    assertSame(true, $o['devices']['ok']);
});

test('總覽：LibreNMS 設備讀不到時，設備相關 KPI 是無法取得，日誌那幾塊照常', function () {
    $o = (new OverviewService(healthySource(), false, NOW))->build(null, cases());
    $k = kpiByKey($o);
    assertSame(null, $k['devices']['value']);
    assertSame(null, $k['ops']['value'], '少了設備就不能算整體運作');
    assertSame(12, $k['suspicious']['value']);
    assertSame(false, $o['devices']['ok']);
    assertTrue(mb_strpos($o['devices']['error'], '無法') !== false);
});

test('總覽：事件案讀不到時，待處理事件是無法取得，不是 0', function () {
    $k = kpiByKey((new OverviewService(healthySource(), false, NOW))->build(devices(), null));
    assertSame(null, $k['cases']['value']);
    assertSame('unknown', $k['cases']['state']);
});

test('總覽：沒有任何設備也沒有任何來源時，整體運作是無法計算，不是 100%', function () {
    $empty = new FakeLogSource();   // 所有查詢都回傳空
    $k = kpiByKey((new OverviewService($empty, false, NOW))->build(['total' => 0, 'up' => 0, 'down_list' => []], cases(0, 0, 0, 0)));
    assertSame(null, $k['ops']['value']);
    assertSame('0/4', $k['sources']['value']);
    assertSame('4 類來源尚未接入', $k['sources']['sub']);
    assertSame(0, $k['cases']['value']);
    assertSame('good', $k['cases']['state']);
});

test('資料涵蓋：依最後收到資料的時間分正常／延遲／中斷；沒有資料是待接入；算不出時間是無法判斷', function () {
    $src = new FakeLogSource(function (string $q, array $g, array $m): array {
        if ($g === ['log_type'] && in_array('latest:timestamp', $m, true)) {
            return [
                ['log_type' => 'windows', 'count' => 5, 'latest_timestamp' => ago(300)],        // 5 分鐘
                ['log_type' => 'linux', 'count' => 5, 'latest_timestamp' => ago(1800)],         // 30 分鐘
                ['log_type' => 'firewall', 'count' => 5, 'latest_timestamp' => ago(3 * 3600)],  // 3 小時
                // network：24 小時內完全沒有
            ];
        }
        return [];
    });
    $c = array_column((new OverviewService($src, false, NOW))->build(devices(), cases())['coverage']['data'], null, 'type');
    assertSame('ok', $c['windows']['status']);
    assertSame('delayed', $c['linux']['status']);
    assertSame('stale', $c['firewall']['status']);
    assertSame('none', $c['network']['status']);
    assertSame(0, $c['network']['count']);
    assertSame(null, $c['network']['age_seconds']);
    assertSame(300, $c['windows']['age_seconds']);
    assertSame('待接入', OverviewService::COVERAGE_LABEL[$c['network']['status']]);

    // 邊界：剛好 10 分鐘仍正常，多 1 秒就延遲
    $edge = new FakeLogSource(fn ($q, $g, $m) => $g === ['log_type'] && in_array('latest:timestamp', $m, true)
        ? [['log_type' => 'windows', 'count' => 1, 'latest_timestamp' => ago(600)], ['log_type' => 'linux', 'count' => 1, 'latest_timestamp' => ago(601)]] : []);
    $e = array_column((new OverviewService($edge, false, NOW))->build(devices(), cases())['coverage']['data'], null, 'type');
    assertSame(['ok', 'delayed'], [$e['windows']['status'], $e['linux']['status']]);
});

test('資料來源連線：有資料但不是正常接收時，說明不能寫「各類來源都有資料」（VM 畫面出現過 0/4 配這句）', function () {
    $stale = new FakeLogSource(fn ($q, $g, $m) => $g === ['log_type'] && in_array('latest:timestamp', $m, true)
        ? array_map(fn ($t) => ['log_type' => $t, 'count' => 3, 'latest_timestamp' => ago(7200)], ['windows', 'linux', 'firewall', 'network']) : []);
    $k = kpiByKey((new OverviewService($stale, false, NOW))->build(devices(), cases()));
    assertSame('0/4', $k['sources']['value']);
    assertSame('bad', $k['sources']['state']);
    assertSame('4 類來源不是正常接收', $k['sources']['sub']);

    $mixed = new FakeLogSource(fn ($q, $g, $m) => $g === ['log_type'] && in_array('latest:timestamp', $m, true)
        ? [['log_type' => 'windows', 'count' => 3, 'latest_timestamp' => ago(300)], ['log_type' => 'linux', 'count' => 3, 'latest_timestamp' => ago(1800)]] : []);
    $k = kpiByKey((new OverviewService($mixed, false, NOW))->build(devices(), cases()));
    assertSame('1/4', $k['sources']['value']);
    assertSame('1 類來源不是正常接收 · 2 類來源尚未接入', $k['sources']['sub']);
});

test('需注意帳號：來源位址缺值時是空字串（不會變成假的位址，建立事件案才不會帶進去）', function () {
    $src = new FakeLogSource(fn ($q, $g, $m) => $g === ['user_name', 'src_ip'] ? [['user_name' => 'alice', 'src_ip' => null, 'count' => 1]] : []);
    $items = (new OverviewService($src, false, NOW))->build(devices(), cases())['attention']['accounts']['data']['items'];
    assertSame('', $items[0]['src_ip']);
    assertSame('alice', $items[0]['user']);
});

test('資料涵蓋：有筆數但算不出最後時間，絕不能顯示成正常', function () {
    $src = new FakeLogSource(fn ($q, $g, $m) => $g === ['log_type'] ? [['log_type' => 'windows', 'count' => 50]] : []);
    $c = array_column((new OverviewService($src, false, NOW))->build(devices(), cases())['coverage']['data'], null, 'type');
    assertSame('unknown', $c['windows']['status']);
    $k = kpiByKey((new OverviewService($src, false, NOW))->build(devices(), cases()));
    assertSame('0/4', $k['sources']['value'], '無法判斷不算正常');
});

test('範例資料：一般模式的每個查詢都排除 ioc_demo，demo 模式只看範例資料', function () {
    $normal = healthySource();
    (new OverviewService($normal, false, NOW))->build(devices(), cases());
    assertTrue(count($normal->calls) >= 6, '應該查了多個區塊');
    foreach ($normal->calls as $c) {
        assertTrue(mb_strpos($c['query'], 'NOT ioc_demo:true') !== false, '漏掉排除範例資料：' . $c['query']);
    }
    $demo = healthySource();
    $o = (new OverviewService($demo, true, NOW))->build(devices(), cases());
    foreach ($demo->calls as $c) {
        assertTrue(mb_strpos($c['query'], 'ioc_demo:true') !== false && mb_strpos($c['query'], 'NOT ioc_demo') === false, '範例模式的查詢不對：' . $c['query']);
    }
    assertSame(true, $o['meta']['demo']);
});

test('近期活動：中文名稱、台北時間；未知的 event_action 原樣顯示', function () {
    $src = new FakeLogSource(null, fn () => [
        ['timestamp' => '2026-10-20T05:17:50.406Z', 'user_name' => 'administrator', 'event_action' => 'login_failed', 'src_ip' => '10.20.5.23',
         'host_name' => 'GIS-SRV-01', 'severity' => 'low', 'log_type' => 'windows'],
        ['timestamp' => 'garbage', 'event_action' => 'brand_new_action', 'severity' => 'high', 'log_type' => 'mystery'],
    ]);
    $rows = (new OverviewService($src, false, NOW))->build(devices(), cases())['activity']['data'];
    assertSame('10-20 13:17:50', $rows[0]['time'], 'UTC 05:17 → 台北 13:17');
    assertSame(['登入失敗', 'Windows 伺服器／網域', '低'], [$rows[0]['action'], $rows[0]['type'], $rows[0]['level']]);
    assertSame(['—', 'brand_new_action', 'mystery', '高', ''], [$rows[1]['time'], $rows[1]['action'], $rows[1]['type'], $rows[1]['level'], $rows[1]['user']]);
    $q = $src->calls[0]['query'] ?? '';
});

test('近期活動：查詢排除防火牆，避免被每秒數十筆的連線紀錄洗版', function () {
    $src = healthySource();
    (new OverviewService($src, false, NOW))->build(devices(), cases());
    $msgs = array_values(array_filter($src->calls, fn ($c) => $c['kind'] === 'messages'));
    assertSame(1, count($msgs));
    assertTrue(mb_strpos($msgs[0]['query'], 'NOT log_type:firewall') !== false);
    assertTrue(mb_strpos($msgs[0]['query'], '_exists_:event_action') !== false);
});

test('需注意帳號：依次數排序，只取前 10，並依次數標等級', function () {
    $rows = [];
    foreach ([1, 2, 3, 9, 10, 25] as $i => $n) {
        $rows[] = ['user_name' => "u$i", 'src_ip' => "10.0.0.$i", 'count' => $n];
    }
    for ($i = 0; $i < 10; $i++) {
        $rows[] = ['user_name' => "x$i", 'src_ip' => "10.9.0.$i", 'count' => 1];
    }
    $src = new FakeLogSource(fn ($q, $g) => $g === ['user_name', 'src_ip'] ? $rows : []);
    $a = (new OverviewService($src, false, NOW))->build(devices(), cases())['attention']['accounts']['data'];
    assertSame(10, count($a['items']));
    assertSame([25, 10, 9, 3, 2, 1], array_slice(array_column($a['items'], 'count'), 0, 6));
    assertSame(['high', 'high', 'medium', 'medium', 'low', 'low'], array_slice(array_column($a['items'], 'level'), 0, 6));
    assertSame(false, $a['capped']);
});

test('分組碰到上限時要標示「可能不只」，不能默默少算', function () {
    $many = [];
    for ($i = 0; $i < OverviewService::GROUP_LIMIT; $i++) {
        $many[] = ['user_name' => "u$i", 'src_ip' => '10.0.0.1', 'count' => 1];
    }
    $src = new FakeLogSource(fn ($q, $g) => in_array('user_name', $g, true) ? $many : []);
    $o = (new OverviewService($src, false, NOW))->build(devices(), cases());
    assertSame(true, $o['attention']['accounts']['data']['capped']);
    assertTrue(mb_strpos(kpiByKey($o)['suspicious']['sub'], '至少 200') !== false, '涉及帳號數要標「至少」');
});

test('防火牆阻擋來源：內部逐一列出、外部只彙總；廣播與多播排除；壞資料不算', function () {
    $src = new FakeLogSource(fn ($q, $g) => $g === ['src_ip'] ? [
        ['src_ip' => '10.20.5.23', 'count' => 50], ['src_ip' => '172.16.1.5', 'count' => 5], ['src_ip' => '192.168.1.1', 'count' => 3],
        ['src_ip' => '8.8.8.8', 'count' => 9000], ['src_ip' => '203.0.113.9', 'count' => 100],
        ['src_ip' => '', 'count' => 77], ['src_ip' => 'not-an-ip', 'count' => 66],
    ] : []);
    $d = (new OverviewService($src, false, NOW))->build(devices(), cases())['attention']['denied_sources']['data'];
    assertSame(['10.20.5.23', '172.16.1.5', '192.168.1.1'], array_column($d['internal'], 'src_ip'));
    assertSame(58, $d['internal_events']);
    assertSame(9100, $d['external_events'], '空白與格式不對的來源不能算進外部');
    $deny = array_values(array_filter($src->calls, fn ($c) => $c['groupBy'] === ['src_ip']))[0]['query'];
    foreach (['event_action:fw_deny', '255.255.255.255', 'dst_ip:224.*', 'dst_ip:239.*'] as $needle) {
        assertTrue(mb_strpos($deny, $needle) !== false, "阻擋查詢要包含 $needle");
    }
});

test('isInternal：私有、迴路、連結本地是內部；公開位址與壞字串不是', function () {
    foreach (['10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.0.9', '127.0.0.1', '169.254.1.1', 'fd00::1'] as $ip) {
        assertTrue(OverviewService::isInternal($ip), "$ip 應為內部");
    }
    foreach (['8.8.8.8', '172.32.0.1', '203.0.113.9', '2001:4860:4860::8888', '', 'abc', '10.0.0.256'] as $ip) {
        assertTrue(!OverviewService::isInternal($ip), "$ip 不應為內部");
    }
});

test('KPI 狀態門檻：整體運作 95%／80%、設備異常比例', function () {
    $mk = fn (int $total, int $up) => kpiByKey((new OverviewService(new FakeLogSource(), false, NOW))->build(devices($total, $up), cases()));
    // 沒有任何來源有資料 → 分母只有設備
    assertSame(['95%', 'good'], [$mk(100, 95)['ops']['value'], $mk(100, 95)['ops']['state']]);
    assertSame(['94%', 'warn'], [$mk(100, 94)['ops']['value'], $mk(100, 94)['ops']['state']]);
    assertSame(['80%', 'warn'], [$mk(100, 80)['ops']['value'], $mk(100, 80)['ops']['state']]);
    assertSame(['79%', 'bad'], [$mk(100, 79)['ops']['value'], $mk(100, 79)['ops']['state']]);
    assertSame('good', $mk(50, 50)['devices']['state']);
    assertSame('warn', $mk(50, 49)['devices']['state']);
    assertSame('bad', $mk(50, 40)['devices']['state']);
});

test('待處理事件的狀態色：沒有=綠、有待確認=紅、只有處理中=黃', function () {
    $mk = fn (array $c) => kpiByKey((new OverviewService(healthySource(), false, NOW))->build(devices(), $c))['cases']['state'];
    assertSame('good', $mk(cases(0, 0, 0, 3)));
    assertSame('bad', $mk(cases(1, 0, 0)));
    assertSame('warn', $mk(cases(0, 1, 2)));
});

test('Time：UTC 轉台北、各種格式、壞字串', function () {
    assertSame('2026-10-20 13:17:50', Time::toTaipei('2026-10-20T05:17:50.406Z'));
    assertSame('2026-10-20 13:17:50', Time::toTaipei('2026-10-20 05:17:50'), '沒有時區的字串視為 UTC');
    assertSame('2026-10-20 13:17:50', Time::toTaipei('2026-10-20T13:17:50+08:00'), '有時區就照時區');
    assertSame('10-21 00:30:00', Time::toTaipei('2026-10-20T16:30:00Z', 'm-d H:i:s'), '跨日');
    assertSame('—', Time::toTaipei(null));
    assertSame('—', Time::toTaipei(''));
    assertSame('—', Time::toTaipei('garbage'));
    assertSame(null, Time::parse('garbage'));
});

test('Time：LibreNMS 本地時間欄位要指定來源時區（devices.last_polled 不是 UTC）', function () {
    assertSame('2026-10-20 10:00:00', Time::toTaipei('2026-10-20 02:00:00', 'Y-m-d H:i:s', 'UTC'));
    assertSame('2026-10-20 02:00:00', Time::toTaipei('2026-10-20 02:00:00', 'Y-m-d H:i:s', 'Asia/Taipei'), '本來就是台北時間就不能再加 8 小時');
    assertSame('2026-10-20 17:00:00', Time::toTaipei('2026-10-20 02:00:00', 'Y-m-d H:i:s', 'America/Los_Angeles'), '10/20 洛杉磯還在夏令時間（UTC-7）');
    assertSame('2026-10-20 13:17:50', Time::toTaipei('2026-10-20T05:17:50Z', 'Y-m-d H:i:s', 'Asia/Taipei'), '字串自己帶時區就以它為準');
    assertSame('—', Time::toTaipei('2026-10-20 02:00:00', 'Y-m-d H:i:s', 'Not/AZone'), '時區名稱不對要回報算不出，不能猜');
});
