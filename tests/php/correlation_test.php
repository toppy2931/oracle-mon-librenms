<?php
declare(strict_types=1);

// 跨來源關聯建議（CorrelationService，規格 EVT-005）。

use Ioc\CorrelationService;
use Ioc\GraylogUnavailable;
use Ioc\LogSource;

/** 假的日誌來源：依查詢內容挑回傳的列（$answer 收到查詢字串、分組欄位、指標），記下每次呼叫。 */
final class CorFakeLogs implements LogSource
{
    /** @var array<int,array{query:string,groupBy:array,metrics:array,range:int}> */
    public array $calls = [];
    /** @var callable */
    private $answer;

    public function __construct(callable $answer)
    {
        $this->answer = $answer;
    }

    public function aggregate(string $query, int $rangeSeconds, array $groupBy, array $metrics, int $limit = 100): array
    {
        $this->calls[] = ['query' => $query, 'groupBy' => $groupBy, 'metrics' => $metrics, 'range' => $rangeSeconds];
        return ($this->answer)($query, $groupBy, $metrics);
    }

    public function messages(string $query, int $rangeSeconds, array $fields, int $size): array
    {
        throw new RuntimeException('關聯建議只用彙整查詢，不撈原始訊息');
    }
}

/** 一組有「命中」的資料：10.0.0.5 被擋 12 次且登入失敗 4 次；10.0.0.6 嘗試 4 個帳號失敗 6 次、也有成功；203.0.113.9 被 4 個內部來源擋 15 次。 */
/** Graylog 的行為：查詢有 src_ip:("a" OR "b") 時，只回這些來源的列（規則會「只針對候選來源」再查一次）。 */
function corData(string $query, array $groupBy): array
{
    $rows = corRaw($query, $groupBy);
    if (preg_match('/src_ip:\(("[^"]+"(?: OR "[^"]+")*)\)/', $query, $m) === 1) {
        $only = array_map(fn (string $x): string => trim($x, '"'), explode(' OR ', $m[1]));
        $rows = array_values(array_filter($rows, fn (array $r): bool => in_array($r['src_ip'] ?? null, $only, true)));
    }
    return $rows;
}

function corRaw(string $query, array $groupBy): array
{
    $failed = strpos($query, 'login_failed') !== false;
    $deny = strpos($query, 'fw_deny') !== false;
    $success = strpos($query, 'login_success') !== false && !$failed;
    if ($groupBy === ['dst_ip']) {
        return [['dst_ip' => '203.0.113.9', 'card_src_ip' => 4, 'count' => 15], ['dst_ip' => '203.0.113.10', 'card_src_ip' => 2, 'count' => 50]];
    }
    if ($deny) {
        return [['src_ip' => '10.0.0.5', 'count' => 12], ['src_ip' => '10.0.0.7', 'count' => 3], ['src_ip' => null, 'count' => 99]];
    }
    if ($failed) {
        return [['src_ip' => '10.0.0.5', 'count' => 4, 'card_user_name' => 1], ['src_ip' => '10.0.0.6', 'count' => 6, 'card_user_name' => 4], ['src_ip' => '10.0.0.7', 'count' => 20, 'card_user_name' => 1]];
    }
    if ($success) {
        return [['src_ip' => '10.0.0.6', 'count' => 2], ['src_ip' => '10.0.0.5', 'count' => 9], ['src_ip' => '10.0.0.7', 'count' => 1]];     // 10.0.0.7：失敗很多次但只有 1 個帳號（不是猜多個帳號）
    }
    return [];
}

function corMake(?CorFakeLogs &$logs = null, bool $demo = false, ?array $nets = ['10.0.0.0/8'], ?callable $answer = null): CorrelationService
{
    $logs = new CorFakeLogs($answer ?? static fn (string $q, array $g) => corData($q, $g));
    return new CorrelationService($logs, $demo, $nets);
}

test('關聯建議：同一來源既被防火牆擋下又登入失敗（門檻都要達到）；事件類型、查詢條件與說明帶得出去', function () {
    $r = corMake()->build();
    $items = array_column($r['items'], null, 'title');
    $a = $items['10.0.0.5 同時被防火牆阻擋與登入失敗'];
    assertSame(['medium', 'suspicious_login', '10.0.0.5', null, 16, 'deny_and_login_failed'], [$a['severity'], $a['category'], $a['subject_ip'], $a['target_host'], $a['hits'], $a['rule']]);
    assertSame('src_ip:"10.0.0.5" AND (event_action:"fw_deny" OR event_action:"login_failed" OR event_action:"invalid_user")', $a['evidence_query']);
    assertTrue(strpos($a['summary'], '被阻擋 12 次') !== false && strpos($a['summary'], '登入失敗 4 次') !== false && strpos($a['summary'], '最近 24 小時') !== false, $a['summary']);
    assertTrue(!isset($items['10.0.0.7 同時被防火牆阻擋與登入失敗']), '被擋只有 3 次：沒到門檻');
    assertTrue(!isset($items['10.0.0.6 同時被防火牆阻擋與登入失敗']), '沒有被擋：不算');
});

test('關聯建議：嘗試多個帳號、同時段有成功登入——高風險；說明寫明沒有檢查先後順序', function () {
    $items = array_column(corMake()->build()['items'], null, 'title');
    $b = $items['10.0.0.6 嘗試多個帳號後有登入成功'];
    assertSame(['high', 'suspicious_login', '10.0.0.6', 8], [$b['severity'], $b['category'], $b['subject_ip'], $b['hits']]);
    assertTrue(strpos($b['summary'], '沒有檢查先後順序') !== false, $b['summary']);
    assertTrue(!isset($items['10.0.0.7 嘗試多個帳號後有登入成功']), '只有 1 個帳號：不算多帳號');
    assertTrue(!isset($items['10.0.0.5 嘗試多個帳號後有登入成功']), '失敗只有 4 次、1 個帳號');
});

test('關聯建議：同一目的被多個內部來源擋下——至少 3 個來源、被擋 10 次；10 個來源以上為高風險；證據只用目的位址（查詢條件長度）', function () {
    $r = corMake()->build();
    $items = array_column($r['items'], null, 'title');
    $c = $items['203.0.113.9 被 4 個內部來源嘗試連線但被擋下'];
    assertSame(['medium', 'fw_internal_block', null, '203.0.113.9', 15], [$c['severity'], $c['category'], $c['subject_ip'], $c['target_host'], $c['hits']]);
    assertSame('dst_ip:"203.0.113.9" AND event_action:"fw_deny"', $c['evidence_query']);
    assertTrue(!isset($items['203.0.113.10 被 2 個內部來源嘗試連線但被擋下']), '只有 2 個來源');
    $many = corMake($logs, false, ['10.0.0.0/8'], fn (string $q, array $g) => $g === ['dst_ip'] ? [['dst_ip' => '203.0.113.9', 'card_src_ip' => 10, 'count' => 15]] : []);
    assertSame('high', $many->build()['items'][0]['severity']);
    $dstCall = array_values(array_filter($logs->calls, fn (array $c): bool => $c['groupBy'] === ['dst_ip']))[0];
    assertTrue(strpos($dstCall['query'], 'src_ip:(10.*)') !== false, '內部來源的篩選在 Graylog 端做：' . $dstCall['query']);
    // 一定要限定轉送流量並排除廣播與探索埠：FortiGate 自己收發的 traffic:local 也是 deny，天天有、沒人要處理（monitor-vm 實測），不限定會每天誤報
    foreach (['log_type:firewall AND cat:"traffic:forward"', 'NOT dstport:(67 OR 68 OR 137 OR 138 OR 1900 OR 5353 OR 5355)'] as $needle) {
        assertTrue(strpos($dstCall['query'], $needle) !== false, '規則三要帶：' . $needle);
    }
});

test('關聯建議：建議依風險由高到低、同風險依命中數；總數與清單上限', function () {
    $r = corMake()->build();
    assertSame(['high', 'medium', 'medium'], array_column($r['items'], 'severity'));
    assertSame(['many_accounts_then_success', 'deny_and_login_failed', 'many_sources_one_target'], array_column($r['items'], 'rule'), '同為中風險：命中 16 的在 15 的前面');
    assertSame(3, $r['total']);
    $flood = corMake($logs, false, ['10.0.0.0/8'], function (string $q, array $g) {
        if ($g === ['dst_ip']) {
            return array_map(fn ($i) => ['dst_ip' => '198.51.100.' . $i, 'card_src_ip' => 4, 'count' => 20], range(1, 100));
        }
        return [];
    });
    $f = $flood->build();
    assertSame([CorrelationService::MAX_SUGGESTIONS, 100], [count($f['items']), $f['total']], '清單有上限，總數照實告訴畫面');
});

test('關聯建議：每條規則各自成敗——某條查不到只讓那條標無法取得與原因，不是 0 個建議；全部查不到也是如此', function () {
    $r = corMake($logs, false, ['10.0.0.0/8'], function (string $q, array $g) {
        if ($g === ['dst_ip']) {
            throw new GraylogUnavailable('Graylog 回應逾時');
        }
        return corData($q, $g);
    })->build();
    assertSame([true, true, false], array_values(array_column($r['rules'], 'ok')));
    assertSame('Graylog 回應逾時', $r['rules']['many_sources_one_target']['error']);
    assertSame(2, count($r['items']), '其他兩條規則的建議照常');
    $all = corMake($logs, false, ['10.0.0.0/8'], fn () => throw new GraylogUnavailable('尚未設定 Graylog 唯讀帳號'))->build();
    assertSame([false, false, false], array_values(array_column($all['rules'], 'ok')));
    assertSame([[], 0], [$all['items'], $all['total']]);
});

test('關聯建議：內部網段讀不到，規則三標無法取得（不套預設網段）；其他規則不受影響', function () {
    $r = (new CorrelationService(new CorFakeLogs(fn (string $q, array $g) => corData($q, $g)), false, null, '內部網段的設定不合法'))->build();
    assertSame([true, true, false], array_values(array_column($r['rules'], 'ok')));
    assertSame('內部網段的設定不合法', $r['rules']['many_sources_one_target']['error']);
});

test('關聯建議：只用彙整查詢；範例資料模式只看範例資料；分組值不是合法 IP（空值、注入字串）的列丟掉，不拿去組查詢', function () {
    $dirty = corMake($logs, true, ['10.0.0.0/8'], function (string $q, array $g) {
        if ($g === ['dst_ip']) {
            return [];
        }
        $rows = [['src_ip' => '1.2.3.4" OR ioc_demo:false OR x:"', 'count' => 100, 'card_user_name' => 9], ['src_ip' => '(Empty Value)', 'count' => 100, 'card_user_name' => 9], ['src_ip' => ['10.0.0.1'], 'count' => 100, 'card_user_name' => 9]];
        return $rows;
    });
    $r = $dirty->build();
    assertSame([], $r['items'], '髒的分組值不能產生建議，也不能出現在任何查詢裡');
    foreach ($logs->calls as $c) {
        assertTrue(strpos($c['query'], ') AND ioc_demo:true') !== false, '範例資料模式：' . $c['query']);
        assertSame(86400, $c['range']);
    }
    corMake($logs2)->build();
    foreach ($logs2->calls as $c) {
        assertTrue(strpos($c['query'], ') AND NOT ioc_demo:true') !== false, '正式模式排除範例資料：' . $c['query']);
    }
});

test('關聯建議：第二份查詢只針對第一份的候選來源（不拿兩份各自的前 100 名做交集）——前 100 名被別的來源占滿也不會漏報', function () {
    // 沒有限縮的登入失敗／成功查詢只回 100 個雜訊來源（10.0.0.5、10.0.0.6 不在其中）；只有「針對候選來源」的查詢才看得到它們
    $noise = fn (string $field) => array_map(fn ($i) => [$field => '198.51.100.' . $i, 'count' => 1000 - $i, 'card_user_name' => 1], range(1, 100));
    $answer = function (string $q, array $g) use ($noise) {
        $restricted = preg_match('/src_ip:\(("[^"]+"(?: OR "[^"]+")*)\)/', $q, $m) === 1;
        $failed = strpos($q, 'login_failed') !== false;
        if ($g === ['dst_ip']) {
            return [];
        }
        if (strpos($q, 'fw_deny') !== false) {
            return [['src_ip' => '10.0.0.5', 'count' => 12]];
        }
        if ($failed) {
            return $restricted ? [['src_ip' => '10.0.0.5', 'count' => 4, 'card_user_name' => 1], ['src_ip' => '10.0.0.6', 'count' => 6, 'card_user_name' => 4]] : array_merge($noise('src_ip'), [['src_ip' => '10.0.0.6', 'count' => 6, 'card_user_name' => 4]]);
        }
        return $restricted ? [['src_ip' => '10.0.0.6', 'count' => 1]] : $noise('src_ip');
    };
    $svc = corMake($logs, false, ['10.0.0.0/8'], $answer);
    $items = array_column($svc->build()['items'], null, 'rule');
    assertTrue(isset($items['deny_and_login_failed']), '內部主機被擋 12 次、登入失敗 4 次：就算失敗名單前 100 名被雜訊占滿也要抓到');
    foreach ($logs->calls as $c) {
        if (strpos($c['query'], 'login_success') !== false || (strpos($c['query'], 'login_failed') !== false && strpos($c['query'], 'fw_deny') === false && $c['metrics'] === ['count'])) {
            assertTrue(strpos($c['query'], 'src_ip:("10.0.0.') !== false, '第二份查詢要限縮到候選來源：' . $c['query']);
        }
    }
});

test('關聯建議：第一份查詢碰到列數上限時，規則標 partial（來源太多只分析了前 100 個），沒碰到就不標', function () {
    $many = corMake($logs, false, ['10.0.0.0/8'], fn (string $q, array $g) => $g === ['dst_ip'] ? [] : (strpos($q, 'fw_deny') !== false
        ? array_map(fn ($i) => ['src_ip' => '198.51.100.' . $i, 'count' => 5], range(1, CorrelationService::LIMIT)) : []));
    $r = $many->build();
    assertSame([true, false, false], array_values(array_column($r['rules'], 'partial')), '被擋名單剛好 100 列：還有沒列進來的來源');
    $few = corMake()->build();
    assertSame([false, false, false], array_values(array_column($few['rules'], 'partial')));
});
