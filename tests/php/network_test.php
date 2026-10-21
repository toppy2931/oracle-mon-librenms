<?php
declare(strict_types=1);

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\GraylogUnavailable;
use Ioc\IocError;
use Ioc\IpNets;
use Ioc\NetworkVisibilityService;
use Ioc\SettingsService;
use Ioc\ValidationError;
use Ioc\WindowedLogSource;

const NV_NOW = 1760000000;      // 2025-10-09 08:53:20 UTC（台北 16:53:20）

/** 假的「指定時間範圍」日誌來源：只接受 aggregateBetween，記下每次查詢；結果由「後加的優先」的路由決定。 */
final class FakeNetworkSource implements WindowedLogSource
{
    /** @var array<int,array<string,mixed>> */
    public array $calls = [];
    /** @var array<int,array{0:callable,1:mixed}> */
    public array $routes = [];

    /** @param mixed $result 列的陣列，或 Closure（收到 [$query, $from, $to]，可以丟例外） */
    public function route(callable $when, $result): self
    {
        array_unshift($this->routes, [$when, $result]);
        return $this;
    }

    public function aggregateBetween(string $query, string $fromIso, string $toIso, array $groupBy, array $metrics, int $limit = 100): array
    {
        $this->calls[] = ['q' => $query, 'from' => $fromIso, 'to' => $toIso, 'groupBy' => $groupBy, 'metrics' => $metrics, 'limit' => $limit];
        foreach ($this->routes as [$when, $result]) {
            if ($when($query, $fromIso, $toIso, $groupBy)) {
                return $result instanceof Closure ? $result($query, $fromIso, $toIso) : $result;
            }
        }
        throw new RuntimeException('沒有對應的假資料：' . $query);
    }

    public function aggregate(string $query, int $rangeSeconds, array $groupBy, array $metrics, int $limit = 100): array
    {
        throw new RuntimeException('內網可視性要用絕對時間範圍（aggregateBetween）');
    }

    public function messages(string $query, int $rangeSeconds, array $fields, int $size): array
    {
        throw new RuntimeException('內網可視性不撈原始訊息');
    }

    public function messagesBetween(string $query, string $fromIso, string $toIso, array $fields, int $size): array
    {
        throw new RuntimeException('內網可視性不撈原始訊息');
    }
}

function nvIso(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s.000\Z', $ts);
}

/** 一組「一切正常」的假資料：24 小時轉送流量 60,000 位元組、內部對內部 15,000（25%），前一個 24 小時是 50%。 */
function nvSource(): FakeNetworkSource
{
    $prevStart = nvIso(NV_NOW - 86400);          // 前一個 24 小時的起點比這個早
    $when = '2025-10-09T08:00:00.000Z';           // 台北 16:00:00
    $src = new FakeNetworkSource();
    // 涵蓋：防火牆日誌 6,010 筆，其中轉送流量 1,000 筆
    $src->route(static fn ($q, $f, $t, $g) => $g === ['cat'] && strpos($q, 'utm:ips') === false,
        [['cat' => 'traffic:forward', 'count' => 1000], ['cat' => 'traffic:local', 'count' => 5000], ['cat' => null, 'count' => 10]]);
    $src->route(static fn ($q, $f, $t, $g) => $g === ['cat'] && strpos($q, 'utm:ips') !== false, []);
    // 位元組數：每格 10,000（內部對內部 2,500）；前一個 24 小時 40,000（內部對內部 20,000）
    $src->route(static fn ($q, $f, $t, $g) => $g === ['log_type'], static function ($q, $f) use ($prevStart) {
        $east = strpos($q, 'dst_ip:(') !== false;
        $prev = $f < $prevStart;
        return [['log_type' => 'firewall', 'sum_network_bytes' => (float) ($east ? ($prev ? 20000 : 2500) : ($prev ? 40000 : 10000))]];
    });
    $src->route(static fn ($q, $f, $t, $g) => $g === ['src_ip'], [
        ['src_ip' => '10.0.0.5', 'sum_network_bytes' => 5000.0, 'count' => 40],
        ['src_ip' => '10.0.0.9', 'sum_network_bytes' => 9000.0, 'count' => 3],
        ['src_ip' => '8.8.8.8', 'sum_network_bytes' => 99999.0, 'count' => 1],       // 不在內部網段：不列
        ['src_ip' => null, 'sum_network_bytes' => 88888.0, 'count' => 1],            // 沒有來源位址：不列
    ]);
    $src->route(static fn ($q, $f, $t, $g) => $g === ['src_ip', 'dstport'], static function ($q, $f) use ($prevStart, $when) {
        $deny = strpos($q, 'event_action:fw_deny') !== false;
        if ($f < $prevStart) {                                       // 前一個 24 小時（只查 445）
            return $deny ? [] : [['src_ip' => '10.0.0.5', 'dstport' => 445, 'card_dst_ip' => 11, 'count' => 20, 'latest_timestamp' => $when]];
        }
        if ($deny) {
            return [
                ['src_ip' => '10.0.0.5', 'dstport' => 445, 'card_dst_ip' => 2, 'count' => 500, 'latest_timestamp' => $when],     // 已算橫向探測：不重複列
                ['src_ip' => '10.0.0.8', 'dstport' => 445, 'card_dst_ip' => 1, 'count' => 150, 'latest_timestamp' => $when],
                ['src_ip' => '10.0.0.11', 'dstport' => 22, 'card_dst_ip' => 1, 'count' => 3, 'latest_timestamp' => $when],
            ];
        }
        return [
            ['src_ip' => '10.0.0.5', 'dstport' => 445, 'card_dst_ip' => 30, 'count' => 120, 'latest_timestamp' => $when],
            ['src_ip' => '10.0.0.6', 'dstport' => 3389, 'card_dst_ip' => 12, 'count' => 12, 'latest_timestamp' => $when],
            ['src_ip' => '10.0.0.7', 'dstport' => 445, 'card_dst_ip' => 3, 'count' => 99, 'latest_timestamp' => $when],           // 不到門檻
            ['src_ip' => '203.0.113.9', 'dstport' => 445, 'card_dst_ip' => 80, 'count' => 80, 'latest_timestamp' => $when],      // 不在內部網段
        ];
    });
    return $src;
}

function nvBuild(FakeNetworkSource $src, bool $demo = false, ?array $nets = IpNets::DEFAULTS, string $why = '', ?string $level = Access::VIEWER): array
{
    return (new NetworkVisibilityService($src, $demo, NV_NOW))->build($nets, $why, $level);
}

function nvKpi(array $d, string $key): array
{
    foreach ($d['kpi'] as $k) {
        if ($k['key'] === $key) {
            return $k;
        }
    }
    fail('沒有這張 KPI：' . $key);
}

// ── IpNets ─────────────────────────────────────────────────────────

test('內部網段：整理成「網路位址/前綴長度」；沒寫前綴當 /32；前綴不在 8～32、不是合法 IPv4 一律拒絕', function () {
    assertSame('10.0.0.0/8', IpNets::normalize('10.0.0.0/8'));
    assertSame('192.168.1.0/24', IpNets::normalize('192.168.1.5/24'), '主機位元換成 0');
    assertSame('10.1.1.1/32', IpNets::normalize('10.1.1.1'));
    assertSame('172.16.0.0/12', IpNets::normalize("  172.16.0.0/12\n"), '前後空白可以');
    foreach (['10.0.0.0/7', '10.0.0.0/0', '10.0.0.0/33', '256.1.1.1', '01.2.3.4', '10.0.0/8', '10.0.0.0/8x', '10.0.0.0//8', 'abc', '', '::1', 'fe80::1/64', '10.0.0.0/-8'] as $bad) {
        assertSame(null, IpNets::normalize($bad), var_export($bad, true));
    }
});

test('內部網段：輸入文字（換行、逗號、空白、全形標點都能分隔）→ 排序去重；錯誤用中文寫清楚', function () {
    $r = IpNets::parseInput("192.168.1.5/24,\n10.0.0.0/8；10.0.0.0/8 172.16.0.0/12，192.168.0.0/16");
    assertSame([], $r['errors']);
    assertSame(['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '192.168.1.0/24'], $r['nets'], '依位址排序、去除重複');
    $same = IpNets::parseInput('192.168.1.0/24 192.168.0.0/16 172.16.0.0/12 10.0.0.0/8');
    assertSame($r['nets'], $same['nets'], '同一組網段不管寫法與順序，整理結果都一樣（沒變動就不留異動紀錄）');

    $e = IpNets::parseInput("10.0.0.0/8\nnot-a-net\n10.0.0.0/4");
    assertSame([], $e['nets']);
    assertSame(2, count($e['errors']));
    assertTrue(strpos($e['errors'][0], 'not-a-net') !== false && strpos($e['errors'][0], 'IPv4') !== false);
    assertTrue(strpos(IpNets::parseInput('')['errors'][0], '至少要有一個') !== false);
    assertTrue(strpos(IpNets::parseInput('  ,;  ')['errors'][0], '至少要有一個') !== false);
});

test('內部網段：個數與展開後的查詢項數有上限', function () {
    $many = implode(' ', array_map(static fn ($i) => "10.$i.0.0/16", range(1, IpNets::MAX_NETS + 1)));
    assertTrue(strpos(IpNets::parseInput($many)['errors'][0], '最多 ' . IpNets::MAX_NETS) !== false);
    $ok = implode(' ', array_map(static fn ($i) => "10.$i.0.0/16", range(1, IpNets::MAX_NETS)));
    assertSame([], IpNets::parseInput($ok)['errors'], '剛好上限可以');
    $fine = IpNets::parseInput('10.0.0.0/9 11.0.0.0/9 12.0.0.0/9');       // 每個 /9 展開成 128 個 /16，共 384 > 256
    assertTrue(strpos($fine['errors'][0], '切得太細') !== false, json_encode($fine, JSON_UNESCAPED_UNICODE));
    assertSame([], IpNets::parseInput('10.0.0.0/9 11.0.0.0/9')['errors'], '256 項剛好可以');
});

test('內部網段：位址在不在網段裡（只認 IPv4）', function () {
    $nets = ['10.0.0.0/8', '172.16.0.0/12', '192.168.1.0/24'];
    foreach (['10.1.2.3', '10.255.255.255', '172.16.0.1', '172.31.255.255', '192.168.1.200'] as $in) {
        assertTrue(IpNets::contains($nets, $in), $in . ' 應該在裡面');
    }
    foreach (['11.0.0.1', '172.15.255.255', '172.32.0.1', '192.168.2.1', '8.8.8.8', '', 'abc', '10.0.0', '::1', 'fd00::1', '10.0.0.1 ', "10.0.0.1\n"] as $out) {
        assertTrue(!IpNets::contains($nets, $out), var_export($out, true) . ' 不該在裡面');
    }
});

test('內部網段：轉成 Graylog 查詢條件的前綴，和 CIDR 的判斷完全一致（隨機位址對照）', function () {
    assertSame(['10.*'], IpNets::patterns(['10.0.0.0/8']));
    assertSame(['10.1.0.*', '10.1.1.*'], IpNets::patterns(['10.1.0.0/23']), '/23 展開成兩個 /24');
    assertSame(['192.168.1.5'], IpNets::patterns(['192.168.1.5/32']), '/32 是完整位址');
    $p12 = IpNets::patterns(['172.16.0.0/12']);
    assertSame(16, count($p12));
    assertSame(['172.16.*', '172.31.*'], [$p12[0], $p12[15]]);
    assertSame(128, count(IpNets::patterns(['192.168.1.0/25'])), '/25 展開成 128 個完整位址');
    assertSame('src_ip:(10.* OR 192.168.1.*)', IpNets::lucene('src_ip', ['10.0.0.0/8', '192.168.1.0/24']));
    assertThrows(InvalidArgumentException::class, fn () => IpNets::lucene('src_ip', []));

    // 用「前綴比對」的規則（Lucene 的 a.b.* 與完整位址）模擬 Graylog，要和 contains() 對每個位址的結果一樣
    $match = static function (array $patterns, string $ip): bool {
        foreach ($patterns as $p) {
            if (substr($p, -2) === '.*' ? strpos($ip, substr($p, 0, -1)) === 0 : $ip === $p) {
                return true;
            }
        }
        return false;
    };
    $sets = [['10.0.0.0/8'], ['172.16.0.0/12', '192.168.1.0/24'], ['10.20.0.0/14', '192.168.100.64/26', '172.16.5.9/32'], ['11.0.0.0/9', '203.0.113.0/25']];
    mt_srand(20261021);
    foreach ($sets as $nets) {
        $patterns = IpNets::patterns($nets);
        for ($i = 0; $i < 1500; $i++) {
            $ip = mt_rand(0, 1) ? sprintf('%d.%d.%d.%d', mt_rand(9, 12), mt_rand(16, 31), mt_rand(0, 255), mt_rand(0, 255))
                                : sprintf('%d.%d.%d.%d', mt_rand(0, 1) ? 172 : 192, mt_rand(0, 1) ? 16 + mt_rand(0, 17) : 168, mt_rand(0, 101), mt_rand(0, 129));
            assertSame(IpNets::contains($nets, $ip), $match($patterns, $ip), $ip . ' 對 ' . implode(',', $nets));
        }
    }
});

test('內部網段：資料庫裡存的字串只有「整理過的樣子」才算合法（被手動改過就是壞掉）', function () {
    assertSame(['10.0.0.0/8', '192.168.1.0/24'], IpNets::fromStored('10.0.0.0/8,192.168.1.0/24'));
    foreach (['', 'garbage', '192.168.1.5/24', '192.168.0.0/16,10.0.0.0/8', '10.0.0.0/8,10.0.0.0/8', '10.0.0.0/8, 192.168.0.0/16'] as $bad) {
        assertSame(null, IpNets::fromStored($bad), var_export($bad, true));
    }
    assertSame('10.0.0.0/8,192.168.1.0/24', IpNets::toStored(['10.0.0.0/8', '192.168.1.0/24']));
    assertSame('10.0.0.0/8,172.16.0.0/12,192.168.0.0/16', IpNets::toStored(IpNets::DEFAULTS));
});

// ── 內部網段的設定（SettingsService）─────────────────────────────────

$nvH = Access::HANDLER;
$nvV = Access::VIEWER;

test('內部網段設定：沒設定過就是私有位址，標示不是自訂；儲存後整理、排序、去重並記下台北時間', function () use ($nvH) {
    $svc = stMake($repo);
    $s = $svc->networkSettings($nvH);
    assertSame([IpNets::DEFAULTS, false, false, [], null], [$s['nets'], $s['custom'], $s['invalid'], $s['history'], $s['updated_by']]);
    assertSame(IpNets::DEFAULTS, $svc->internalNets($nvH));
    assertSame(['max_nets' => IpNets::MAX_NETS, 'min_bits' => IpNets::MIN_BITS], $s['limits']);

    $s = $svc->saveNetwork(['cidrs' => "192.168.1.5/24,\n10.0.0.0/8\n10.0.0.0/8"], 'alice', $nvH);
    assertSame(['10.0.0.0/8', '192.168.1.0/24'], $s['nets']);
    assertSame([true, false, 'alice', '2026-10-20 16:00:00'], [$s['custom'], $s['invalid'], $s['updated_by'], $s['updated_at']]);
    assertSame('10.0.0.0/8,192.168.1.0/24', $repo->rows[SettingsService::NET_INTERNAL]['value']);
    assertSame(['10.0.0.0/8', '192.168.1.0/24'], $svc->internalNets($nvH));
    assertSame(1, count($repo->history));
    assertSame(['alice', SettingsService::NET_INTERNAL, '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16', '10.0.0.0/8,192.168.1.0/24'],
        [$repo->history[0]['actor'], $repo->history[0]['skey'], $repo->history[0]['old_value'], $repo->history[0]['new_value']], '原值是預設網段');
    assertSame('內部網段', SettingsService::KEY_LABEL[SettingsService::NET_INTERNAL]);
});

test('內部網段設定：整理後沒有變動就不寫、不留紀錄；沒自訂過又存回預設值也不寫', function () use ($nvH) {
    $svc = stMake($repo);
    $svc->saveNetwork(['cidrs' => implode("\n", IpNets::DEFAULTS)], 'u', $nvH);
    assertSame([[], [], 0], [$repo->rows, $repo->history, $repo->puts], '存回預設值：還是「沒自訂過」');
    $svc->saveNetwork(['cidrs' => '10.0.0.0/8'], 'u', $nvH);
    $puts = $repo->puts;
    $svc->saveNetwork(['cidrs' => ' 10.0.0.0/8 , 10.0.0.0/8 '], 'u', $nvH);
    $svc->saveNetwork(['cidrs' => '10.5.5.5/8'], 'u', $nvH);                 // 整理後也是 10.0.0.0/8
    assertSame([$puts, 1], [$repo->puts, count($repo->history)]);
});

test('內部網段設定：輸入驗證——不合法的值一律拒絕，且不寫任何東西', function () use ($nvH) {
    $svc = stMake($repo);
    $bad = ['', '  ', 'abc', '10.0.0.0/4', '10.0.0.0/33', '300.1.1.1/8', '10.0.0.0/8 abc', '10.0.0.0/9 11.0.0.0/9 12.0.0.0/9',
        implode(' ', array_map(static fn ($i) => "10.$i.0.0/16", range(1, 11))), str_repeat('10.0.0.0/8 ', 100)];
    foreach ($bad as $text) {
        assertThrows(ValidationError::class, fn () => $svc->saveNetwork(['cidrs' => $text], 'u', $nvH));
    }
    assertThrows(ValidationError::class, fn () => $svc->saveNetwork([], 'u', $nvH));                              // 沒送欄位
    assertThrows(ValidationError::class, fn () => $svc->saveNetwork(['cidrs' => ['10.0.0.0/8']], 'u', $nvH));   // 送了陣列
    assertSame([[], [], 0], [$repo->rows, $repo->history, $repo->puts], '被拒絕的儲存不能留下痕跡');
});

test('內部網段設定：檢視者與沒有角色——能讀（檢視者）不能改；沒有角色什麼都不行', function () use ($nvH, $nvV) {
    $svc = stMake($repo);
    $svc->saveNetwork(['cidrs' => '10.0.0.0/8'], 'admin', $nvH);
    assertSame(['10.0.0.0/8'], $svc->internalNets($nvV));
    assertSame(['10.0.0.0/8'], $svc->networkSettings($nvV)['nets']);
    assertThrows(AccessDenied::class, fn () => $svc->saveNetwork(['cidrs' => '192.168.0.0/16'], 'viewer', $nvV));
    assertThrows(AccessDenied::class, fn () => $svc->saveNetwork(['cidrs' => '192.168.0.0/16'], 'nobody', null));
    assertThrows(AccessDenied::class, fn () => $svc->internalNets(null));
    assertThrows(AccessDenied::class, fn () => $svc->networkSettings(null));
    assertSame(['10.0.0.0/8'], $svc->internalNets($nvH), '被拒絕的儲存不能改到資料');
});

test('內部網段設定：資料表裡的值壞了（手動改過）——不套預設，標 invalid；重新儲存即修復並記下壞掉的原值', function () use ($nvH) {
    foreach (['garbage', '192.168.0.0/16,10.0.0.0/8', '10.0.0.0/4', ''] as $broken) {
        $svc = stMake($repo);
        $repo->rows[SettingsService::NET_INTERNAL] = stRow($broken);
        $s = $svc->networkSettings($nvH);
        assertSame([true, IpNets::DEFAULTS], [$s['invalid'], $s['nets']], '畫面只是顯示預設值當起點，不是生效的值：' . var_export($broken, true));
        assertThrows(IocError::class, fn () => $svc->internalNets($nvH), '不合法');
        $fixed = $svc->saveNetwork(['cidrs' => '10.0.0.0/8'], 'admin', $nvH);
        assertSame([false, ['10.0.0.0/8']], [$fixed['invalid'], $fixed['nets']]);
        assertSame([$broken, '10.0.0.0/8'], [$repo->history[0]['old_value'], $repo->history[0]['new_value']]);
    }
});

test('內部網段設定：異動紀錄只列自己的；風險門檻與帳號盤點的異動不會混進來，反之亦然', function () use ($nvH) {
    $svc = stMake($repo, $clock);
    $svc->saveRisk(['risk_low' => '90', 'risk_medium' => '70'], 'a', $nvH);
    $svc->saveAccountReview(['last_date' => '2026-10-19', 'owner' => 'o', 'period_days' => '60'], 'a', $nvH);
    $svc->saveNetwork(['cidrs' => '10.0.0.0/8'], 'a', $nvH);
    assertSame(1, count($svc->networkSettings($nvH)['history']));
    $labels = array_column($svc->riskSettings($nvH)['history'], 'label');
    assertSame([], array_values(array_diff($labels, ['低風險門檻', '中等風險門檻'])), '風險門檻頁的異動紀錄只有風險門檻');
    assertTrue(!in_array('內部網段', $labels, true));
});

test('內部網段設定：寫異動紀錄失敗時整筆復原（不會設定值變了卻沒紀錄）', function () use ($nvH) {
    $svc = stMake($repo);
    $repo->failOnHistory = true;
    assertThrows(RuntimeException::class, fn () => $svc->saveNetwork(['cidrs' => '10.0.0.0/8'], 'u', $nvH));
    assertSame([], $repo->rows);
});

// ── NetworkVisibilityService ─────────────────────────────────────────

test('內網可視性：東西向流量、Top Talkers、異常連線、SMB、KPI 都算對', function () {
    $d = nvBuild(nvSource());
    foreach (['source', 'talkers', 'anomalies', 'ids', 'trend'] as $k) {
        assertTrue($d[$k]['ok'], $k . ' ' . json_encode($d[$k], JSON_UNESCAPED_UNICODE));
    }
    assertSame(['firewall' => 6010, 'forward' => 1000], $d['source']['data']);
    assertSame(['最近 24 小時', '前一個 24 小時', false, IpNets::DEFAULTS], [$d['meta']['range_label'], $d['meta']['previous_label'], $d['meta']['demo'], $d['meta']['nets']]);
    assertSame('2025-10-09 16:53:20', $d['meta']['generated_at'], '台北時間');

    $t = $d['trend']['data'];
    assertSame([60000, 15000, 25, 6, 4], [$t['total'], $t['east_west'], $t['share'], count($t['buckets']), $t['bucket_hours']]);
    assertSame(['16:53', '20:53', '00:53', '04:53', '08:53', '12:53'], array_column($t['buckets'], 'label'));
    assertSame([10000, 2500, 25], [$t['buckets'][0]['total'], $t['buckets'][0]['east_west'], $t['buckets'][0]['share']]);
    assertSame(['total' => 40000, 'east_west' => 20000, 'share' => 50], $t['previous']);
    assertSame('58.6 KB', $t['total_label']);

    $tk = $d['talkers']['data'];
    assertSame(['10.0.0.9', '10.0.0.5'], array_column($tk['items'], 'ip'), '依位元組數由多到少；外部位址與沒有來源的不列');
    assertSame(['8.8 KB', '4.9 KB'], array_column($tk['items'], 'bytes_label'));
    assertSame([15, 8], array_column($tk['items'], 'share'), '占轉送流量總量的比例');
    assertSame([2, false], [$tk['sources'], $tk['capped']]);

    $a = $d['anomalies']['data'];
    assertSame([['10.0.0.8', 445, 'blocked', 'medium'], ['10.0.0.5', 445, 'probe', 'medium'], ['10.0.0.6', 3389, 'probe', 'low'], ['10.0.0.11', 22, 'blocked', 'low']],
        array_map(static fn ($i) => [$i['src_ip'], $i['port'], $i['kind'], $i['level']], $a['items']),
        '嚴重的在前、同級依筆數多的在前；未達門檻、外部來源、已算橫向探測的重複項都不列');
    assertSame([4, ['high' => 0, 'medium' => 2, 'low' => 2], false], [$a['total'], $a['levels'], $a['capped']]);
    assertSame(['sources' => 2, 'previous' => 1], $a['smb']);
    assertSame('內部對內部的連線被防火牆擋下 150 次，涉及 1 個目的主機，目的埠 445 SMB', $a['items'][0]['behavior']);
    assertSame('橫向探測：連到 30 個不同的內部主機，目的埠 445 SMB（120 筆連線紀錄）', $a['items'][1]['behavior']);
    assertSame('10-09 16:00:00', $a['items'][0]['time']);
    assertSame('src_ip:"10.0.0.8" AND dstport:445 AND event_action:fw_deny', $a['items'][0]['evidence_query']);
    assertSame('src_ip:"10.0.0.5" AND dstport:445', $a['items'][1]['evidence_query']);

    $east = nvKpi($d, 'eastwest');
    assertSame(['25%', 'info'], [$east['value'], $east['state']]);
    foreach (['內部對內部 14.6 KB', '轉送流量共 58.6 KB', '低了 25 個百分點'] as $part) {
        assertTrue(strpos($east['sub'], $part) !== false, $part . ' ／ ' . $east['sub']);
    }
    $smb = nvKpi($d, 'smb');
    assertSame([2, 'warn'], [$smb['value'], $smb['state']]);
    assertTrue(strpos($smb['sub'], '較前一個 24 小時增加 100%') !== false, $smb['sub']);
    $anom = nvKpi($d, 'anomalies');
    assertSame([4, 'warn'], [$anom['value'], $anom['state']]);
    assertTrue(strpos($anom['sub'], '高 0 · 中 2 · 低 2') !== false, $anom['sub']);
    $ids = nvKpi($d, 'ids');
    assertSame([null, 'unknown', '尚未接入'], [$ids['value'], $ids['state'], $ids['na']], 'IPS 日誌一筆都沒有：尚未接入，不是 0');
    assertTrue(strpos($ids['sub'], '分不出來') !== false);
});

test('內網可視性：查詢條件——一律用絕對時間、排除範例資料、內部網段轉成前綴、先查優先的區塊', function () {
    $src = nvSource();
    nvBuild($src);
    $calls = $src->calls;
    assertSame(21, count($calls), '涵蓋 1＋熱門來源 1＋異常 2＋前期 SMB 2＋IDS 1＋趨勢 14');
    assertSame(['(log_type:firewall) AND NOT ioc_demo:true', nvIso(NV_NOW - 86400), nvIso(NV_NOW), ['cat']], [$calls[0]['q'], $calls[0]['from'], $calls[0]['to'], $calls[0]['groupBy']], '第一個查詢是涵蓋檢查');
    foreach ($calls as $c) {
        assertTrue(substr($c['q'], -22) === ' AND NOT ioc_demo:true', '每個查詢都要排除範例資料：' . $c['q']);
    }
    $internal = static fn (string $f) => IpNets::lucene($f, IpNets::DEFAULTS);
    $first = static function (array $calls, callable $pred) {
        foreach ($calls as $i => $c) {
            if ($pred($c)) {
                return [$i, $c];
            }
        }
        fail('找不到符合的查詢');
    };
    [$iTalk, $talk] = $first($calls, static fn ($c) => $c['groupBy'] === ['src_ip']);
    foreach (['cat:"traffic:forward"', $internal('src_ip'), 'NOT dst_ip:"255.255.255.255"', 'NOT dst_ip:224.*', 'NOT dst_ip:239.*'] as $part) {
        assertTrue(strpos($talk['q'], $part) !== false, '熱門來源的查詢要有 ' . $part);
    }
    assertSame(['sum:network_bytes', 'count'], $talk['metrics']);
    [$iProbe, $probe] = $first($calls, static fn ($c) => $c['groupBy'] === ['src_ip', 'dstport'] && strpos($c['q'], 'fw_deny') === false);
    foreach (['cat:"traffic:forward"', $internal('src_ip') . ' AND ' . $internal('dst_ip'), 'NOT dstport:(67 OR 68 OR 137 OR 138 OR 1900 OR 5353 OR 5355)'] as $part) {
        assertTrue(strpos($probe['q'], $part) !== false, '橫向探測的查詢要有 ' . $part);
    }
    assertSame(['card:dst_ip', 'count', 'latest:timestamp'], $probe['metrics']);
    [, $deny] = $first($calls, static fn ($c) => $c['groupBy'] === ['src_ip', 'dstport'] && strpos($c['q'], 'event_action:fw_deny') !== false);
    assertTrue(strpos($deny['q'], $internal('src_ip') . ' AND ' . $internal('dst_ip')) !== false, '被擋下的查詢也只看內部對內部');
    [, $ew] = $first($calls, static fn ($c) => $c['groupBy'] === ['log_type'] && strpos($c['q'], 'dst_ip:(') !== false);
    assertTrue(strpos($ew['q'], $internal('src_ip') . ' AND ' . $internal('dst_ip')) !== false, '內部對內部的位元組數');
    [$iIds] = $first($calls, static fn ($c) => strpos($c['q'], 'utm:ips') !== false);
    [$iTrend] = $first($calls, static fn ($c) => $c['groupBy'] === ['log_type']);
    assertTrue($iTalk < $iTrend && $iProbe < $iTrend && $iIds < $iTrend, '最花查詢次數的趨勢放最後，Graylog 的時間預算用完時才不會連累重要的區塊');
    // 趨勢 6 格剛好蓋滿 24 小時，沒有縫也沒有重疊
    $buckets = array_values(array_filter($calls, static fn ($c) => $c['groupBy'] === ['log_type'] && $c['from'] >= nvIso(NV_NOW - 86400)));
    assertSame(12, count($buckets));
    assertSame([nvIso(NV_NOW - 86400), nvIso(NV_NOW)], [$buckets[0]['from'], $buckets[11]['to']]);
    for ($i = 2; $i < 12; $i += 2) {
        assertSame($buckets[$i - 2]['to'], $buckets[$i]['from'], '格與格要接得起來');
    }
});

test('內網可視性：範例資料模式只看範例資料', function () {
    $src = nvSource();
    nvBuild($src, true);
    foreach ($src->calls as $c) {
        assertTrue(substr($c['q'], -18) === ' AND ioc_demo:true' && strpos($c['q'], 'NOT ioc_demo') === false, $c['q']);
    }
});

test('內網可視性：自訂內部網段——查詢與過濾都用它；網段外的來源不列', function () {
    $src = nvSource();
    $d = nvBuild($src, false, ['172.16.1.0/24']);
    $talk = array_values(array_filter($src->calls, static fn ($c) => $c['groupBy'] === ['src_ip']))[0];
    assertTrue(strpos($talk['q'], 'src_ip:(172.16.1.*)') !== false, $talk['q']);
    assertSame([], $d['talkers']['data']['items'], '假資料裡的來源都不在 172.16.1.0/24：不列');
    assertSame([true, []], [$d['talkers']['ok'], $d['anomalies']['data']['items']]);
    assertSame(['172.16.1.0/24'], $d['meta']['nets']);
});

test('內網可視性：沒有收到防火牆日誌——流量類區塊都寫原因（不是 0 或沒有異常），IDS 照常查，且不再多打查詢', function () {
    $src = nvSource();
    $src->route(static fn ($q, $f, $t, $g) => $g === ['cat'] && strpos($q, 'utm:ips') === false, []);
    $d = nvBuild($src);
    foreach (['talkers', 'anomalies', 'trend'] as $k) {
        assertSame(false, $d[$k]['ok'], $k);
        assertTrue(strpos($d[$k]['error'], '沒有收到防火牆的日誌') !== false && strpos($d[$k]['error'], '不代表沒有流量') !== false, $d[$k]['error']);
    }
    assertSame(2, count($src->calls), '只剩涵蓋檢查與 IDS 兩個查詢');
    foreach (['eastwest', 'smb', 'anomalies'] as $key) {
        $k = nvKpi($d, $key);
        assertSame([null, 'unknown', null], [$k['value'], $k['state'], $k['na']], $key);
        assertTrue(strpos($k['sub'], '沒有收到防火牆的日誌') !== false, $key);
    }
    assertSame('尚未接入', nvKpi($d, 'ids')['na']);
});

test('內網可視性：有防火牆日誌卻沒有轉送流量——說清楚是記錄或欄位的問題', function () {
    $src = nvSource();
    $src->route(static fn ($q, $f, $t, $g) => $g === ['cat'] && strpos($q, 'utm:ips') === false, [['cat' => 'traffic:local', 'count' => 50], ['cat' => 'utm:dns', 'count' => 9]]);
    $d = nvBuild($src);
    foreach (['talkers', 'anomalies', 'trend'] as $k) {
        assertSame(false, $d[$k]['ok'], $k);
        assertTrue(strpos($d[$k]['error'], 'traffic:forward') !== false && strpos($d[$k]['error'], '收到了防火牆日誌') !== false, $d[$k]['error']);
    }
    assertTrue($d['ids']['ok']);
});

test('內網可視性：Graylog 連不上——每個區塊都標原因，只打了一次查詢', function () {
    $src = nvSource();
    $src->route(static fn ($q, $f, $t, $g) => $g === ['cat'], static function () {
        throw new GraylogUnavailable('Graylog 連線失敗');
    });
    $d = nvBuild($src);
    foreach (['source', 'talkers', 'anomalies', 'ids', 'trend'] as $k) {
        assertSame([false, 'Graylog 連線失敗'], [$d[$k]['ok'], $d[$k]['error']], $k);
    }
    assertSame(1, count($src->calls), '連不上就不要每個區塊各打一次（每次都等逾時）');
    foreach ($d['kpi'] as $k) {
        assertSame([null, 'unknown', 'Graylog 連線失敗'], [$k['value'], $k['state'], $k['sub']], $k['key']);
    }
});

test('內網可視性：日誌沒有位元組數欄位——流量區塊寫原因，不能是 0 位元組；異常連線不受影響', function () {
    $src = nvSource();
    $src->route(static fn ($q, $f, $t, $g) => $g === ['log_type'], []);
    $src->route(static fn ($q, $f, $t, $g) => $g === ['src_ip'], [['src_ip' => '10.0.0.5', 'sum_network_bytes' => 0, 'count' => 3]]);
    $d = nvBuild($src);
    foreach (['trend', 'talkers'] as $k) {
        assertSame(false, $d[$k]['ok'], $k);
        assertTrue(strpos($d[$k]['error'], 'network_bytes') !== false, $d[$k]['error']);
    }
    assertTrue($d['anomalies']['ok'], '異常連線靠筆數與目的主機數，不需要位元組數');
    assertSame([null, 'unknown'], [nvKpi($d, 'eastwest')['value'], nvKpi($d, 'eastwest')['state']]);
    assertTrue(strpos(nvKpi($d, 'eastwest')['sub'], 'network_bytes') !== false);
});

test('內網可視性：前一個 24 小時讀不到只影響「較前期」，其他照常', function () {
    $src = nvSource();
    $prevStart = nvIso(NV_NOW - 86400);
    $boom = static function () {
        throw new GraylogUnavailable('Graylog 回應逾時');
    };
    $src->route(static fn ($q, $f, $t, $g) => $f < $prevStart, $boom);
    $d = nvBuild($src);
    assertTrue($d['trend']['ok'] && $d['anomalies']['ok']);
    assertSame(null, $d['trend']['data']['previous']);
    assertSame(null, $d['anomalies']['data']['smb']['previous']);
    assertTrue(strpos(nvKpi($d, 'eastwest')['sub'], '沒有資料可比較') !== false);
    assertTrue(strpos(nvKpi($d, 'smb')['sub'], '資料無法取得') !== false);
    assertSame(25, $d['trend']['data']['share']);
});

test('內網可視性：IDS 有日誌時顯示筆數與較前期的變化', function () {
    $src = nvSource();
    $prevStart = nvIso(NV_NOW - 86400);
    $src->route(static fn ($q, $f, $t, $g) => $g === ['cat'] && strpos($q, 'utm:ips') !== false,
        static fn ($q, $f) => $f < $prevStart ? [['cat' => 'utm:ips', 'count' => 14]] : [['cat' => 'utm:ips', 'count' => 5], ['cat' => 'utm:anomaly', 'count' => 2]]);
    $d = nvBuild($src);
    assertSame([true, 7, 14], [$d['ids']['data']['seen'], $d['ids']['data']['count'], $d['ids']['data']['previous']]);
    $k = nvKpi($d, 'ids');
    assertSame([7, 'warn', null], [$k['value'], $k['state'], $k['na']]);
    assertTrue(strpos($k['sub'], '較前一個 24 小時減少 50%') !== false, $k['sub']);
    assertTrue(strpos(json_encode($src->calls, JSON_UNESCAPED_UNICODE), 'cat:(\"utm:ips\" OR \"utm:anomaly\")') !== false, 'IPS 與異常偵測兩種都算');
});

/** 把「最近 24 小時」的（來源, 埠）分組結果換成指定的資料（前一個 24 小時維持 nvSource 的預設）。 */
function nvAnomalyRows(FakeNetworkSource $src, array $probe, array $deny): FakeNetworkSource
{
    $prevStart = nvIso(NV_NOW - 86400);
    $src->route(static fn ($q, $f, $t, $g) => $g === ['src_ip', 'dstport'] && strpos($q, 'fw_deny') === false && $f >= $prevStart, $probe);
    $src->route(static fn ($q, $f, $t, $g) => $g === ['src_ip', 'dstport'] && strpos($q, 'fw_deny') !== false && $f >= $prevStart, $deny);
    return $src;
}

function nvProbeRow(string $ip, ?int $port, int $distinct, int $count): array
{
    return ['src_ip' => $ip, 'dstport' => $port, 'card_dst_ip' => $distinct, 'count' => $count, 'latest_timestamp' => '2025-10-09T08:00:00.000Z'];
}

test('內網可視性：異常連線清單最多列 20 筆，其餘只算在總數與 KPI', function () {
    $rows = [];
    for ($i = 1; $i <= 30; $i++) {
        $rows[] = nvProbeRow('10.1.0.' . $i, $i === 1 ? 0 : 445, 10 + ($i % 20), $i);
    }
    $d = nvBuild(nvAnomalyRows(nvSource(), $rows, []));
    $a = $d['anomalies']['data'];
    assertSame([20, 30, false], [count($a['items']), $a['total'], $a['capped']]);
    assertSame(29, $a['smb']['sources'], '445 的來源有 29 個（第 1 個是沒有埠的協定）');
    assertSame(30, nvKpi($d, 'anomalies')['value']);
});

test('內網可視性：結果被查詢上限截斷才標「可能不完整」——來源層或某來源的埠層滿了；來源 × 埠的總列數大不算', function () {
    // 40 個來源各 3 個埠 ＝ 120 列：沒有任何一層碰到上限
    $rows = [];
    for ($i = 1; $i <= 40; $i++) {
        foreach ([445, 3389, 22] as $port) {
            $rows[] = nvProbeRow('10.1.0.' . $i, $port, 12, 5);
        }
    }
    $d = nvBuild(nvAnomalyRows(nvSource(), $rows, []));
    assertSame(false, $d['anomalies']['data']['capped'], '120 列但沒有被截斷');
    assertTrue(strpos(nvKpi($d, 'anomalies')['sub'], '結果可能不完整') === false);
    // 來源層滿了
    $rows = [];
    for ($i = 1; $i <= NetworkVisibilityService::GROUP_LIMIT; $i++) {
        $rows[] = nvProbeRow('10.2.0.' . $i, 445, 12, 5);
    }
    $d = nvBuild(nvAnomalyRows(nvSource(), $rows, []));
    assertSame(true, $d['anomalies']['data']['capped']);
    assertTrue(strpos(nvKpi($d, 'anomalies')['sub'], '結果可能不完整') !== false);
    // 某一個來源的埠層滿了
    $rows = [];
    for ($p = 1; $p <= NetworkVisibilityService::GROUP_LIMIT; $p++) {
        $rows[] = nvProbeRow('10.3.0.9', 1000 + $p, 12, 5);
    }
    assertSame(true, nvBuild(nvAnomalyRows(nvSource(), $rows, []))['anomalies']['data']['capped']);
    // 被擋下的那份滿了也算
    $deny = [];
    for ($i = 1; $i <= NetworkVisibilityService::GROUP_LIMIT; $i++) {
        $deny[] = nvProbeRow('10.4.0.' . $i, 22, 1, 3);
    }
    assertSame(true, nvBuild(nvAnomalyRows(nvSource(), [], $deny))['anomalies']['data']['capped']);
});

test('內網可視性：沒有連接埠的協定（dstport 是 0，例如 ICMP）——不寫埠，建案的查詢也不帶 dstport 條件', function () {
    $d = nvBuild(nvAnomalyRows(nvSource(), [nvProbeRow('10.0.0.5', 0, 40, 400)], []));
    $one = $d['anomalies']['data']['items'][0];
    assertSame(0, $one['port']);
    assertSame('橫向探測：連到 40 個不同的內部主機，協定沒有連接埠（例如 ICMP）（400 筆連線紀錄）', $one['behavior']);
    assertSame('src_ip:"10.0.0.5"', $one['evidence_query']);
    assertSame('medium', $one['level']);
});

test('內網可視性：日誌根本沒有 dstport 欄位（每一列的埠都是缺值）——異常區塊與 SMB 標「無法取得」，不是綠色的 0；其他區塊照常', function () {
    $d = nvBuild(nvAnomalyRows(nvSource(), [nvProbeRow('10.0.0.5', null, 30, 120), nvProbeRow('10.0.0.6', null, 12, 12)], [nvProbeRow('10.0.0.8', null, 1, 150)]));
    assertSame(false, $d['anomalies']['ok']);
    assertTrue(strpos($d['anomalies']['error'], 'dstport') !== false, $d['anomalies']['error']);
    foreach (['smb', 'anomalies'] as $key) {
        $k = nvKpi($d, $key);
        assertSame([null, 'unknown'], [$k['value'], $k['state']], $key . ' 不能顯示成 0');
        assertTrue(strpos($k['sub'], 'dstport') !== false, $key);
    }
    assertTrue($d['trend']['ok'] && $d['talkers']['ok'], '位元組數相關的區塊不受影響');
    // 只有一部分列缺值（例如 ICMP）不算欄位不存在
    $mixed = nvBuild(nvAnomalyRows(nvSource(), [nvProbeRow('10.0.0.5', 445, 30, 120), nvProbeRow('10.0.0.6', null, 12, 12)], []));
    assertTrue($mixed['anomalies']['ok']);
    // 完全沒有資料（沒有內部對內部的流量）：沒有東西可以判斷缺不缺欄位，照常顯示沒有異常
    assertSame(0, nvBuild(nvAnomalyRows(nvSource(), [], []))['anomalies']['data']['total']);
});

test('內網可視性：熱門來源碰到查詢上限要標示', function () {
    $src = nvSource();
    $src->route(static fn ($q, $f, $t, $g) => $g === ['src_ip'], static function () {
        $rows = [];
        for ($i = 1; $i <= NetworkVisibilityService::TALKER_LIMIT; $i++) {
            $rows[] = ['src_ip' => '10.2.' . intdiv($i, 250) . '.' . ($i % 250), 'sum_network_bytes' => (float) (1000 + $i), 'count' => 1];
        }
        return $rows;
    });
    $tk = nvBuild($src)['talkers']['data'];
    assertSame([NetworkVisibilityService::TALKERS, true], [count($tk['items']), $tk['capped']]);
    assertSame(true, $tk['items'][0]['bytes'] >= $tk['items'][1]['bytes']);
});

test('內網可視性：內部網段讀不到——所有區塊與 KPI 都標「無法取得」，一個 Graylog 查詢都不打', function () {
    $src = nvSource();
    $d = nvBuild($src, false, null, '內部網段的設定值不合法（資料表被手動改過？）');
    foreach (['source', 'talkers', 'anomalies', 'ids', 'trend'] as $k) {
        assertSame([false, '內部網段的設定值不合法（資料表被手動改過？）'], [$d[$k]['ok'], $d[$k]['error']], $k);
    }
    assertSame([0, null], [count($src->calls), $d['meta']['nets']]);
    assertSame(4, count($d['kpi']));
    foreach ($d['kpi'] as $k) {
        assertSame([null, 'unknown'], [$k['value'], $k['state']], $k['key']);
    }
    assertTrue(strpos(nvBuild(nvSource(), false, null)['trend']['error'], '讀不到內部網段') !== false, '沒給原因也要有一句話');
});

test('內網可視性：admin 與檢視者都能看，沒有角色不行', function () {
    foreach ([Access::HANDLER, Access::VIEWER] as $lv) {
        assertTrue(nvBuild(nvSource(), false, IpNets::DEFAULTS, '', $lv)['trend']['ok'], (string) $lv);
    }
    assertThrows(AccessDenied::class, fn () => nvBuild(nvSource(), false, IpNets::DEFAULTS, '', null));
});

test('內網可視性：位元組數的顯示（1024 進位）', function () {
    foreach ([[0, '0 B'], [1023, '1023 B'], [1024, '1.0 KB'], [1536, '1.5 KB'], [10 * 1024 * 1024, '10.0 MB'], [150 * 1024 ** 3, '150 GB'], [3 * 1024 ** 4, '3.0 TB'], [-5, '0 B']] as [$n, $label]) {
        assertSame($label, NetworkVisibilityService::humanBytes($n), (string) $n);
    }
});
