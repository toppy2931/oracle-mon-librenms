<?php
declare(strict_types=1);

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\AuditTrail;
use Ioc\Fleet;
use Ioc\IocError;
use Ioc\LandscapeService;
use Ioc\RiskScore;
use Ioc\SettingsService;
use Ioc\Sites;
use Ioc\ValidationError;

// 這個檔案用到 landscape_test.php 的 dev()、lsCoverage()、LS_NOW；變數與函式名稱加 si 前綴，避免和其他測試檔撞名

const SI_T1 = '2026-10-22 02:00:00';      // UTC；台北是 10:00:00

/** 一個帶假時鐘的設定服務。 */
function siMake(&$repo, ?ArrayObject &$clock = null): SettingsService
{
    $repo  = new MemorySettingsRepo();
    $clock = new ArrayObject(['now' => SI_T1]);
    return new SettingsService($repo, static fn (): string => $clock['now']);
}

function siItem(string $name, string $cidrs, $id = null): array
{
    return ['id' => $id, 'name' => $name, 'cidrs' => $cidrs];
}

function siRow(string $value, string $by = 'admin', string $at = '2026-10-01 00:00:00'): array
{
    return ['value' => $value, 'updated_by' => $by, 'updated_at' => $at];
}

/** 驗證過的站點（id 從 1 連續編）：[[名稱, 網段文字], …]。 */
function siSites(array $defs): Sites
{
    $items = [];
    foreach (array_values($defs) as $i => [$name, $cidrs]) {
        $items[] = siItem($name, $cidrs, $i + 1);
    }
    $r = Sites::parseSubmitted($items);
    if ($r['errors'] !== []) {
        fail(implode('；', $r['errors']));
    }
    return new Sites(array_map(static fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'nets' => $s['nets']], $r['sites']));
}

$siH = Access::HANDLER;
$siV = Access::VIEWER;

// ── Sites：驗證 ─────────────────────────────────────────────────────

test('站點：網段文字整理——補成網路位址、去重、依位址排序；逗號、分號、空白都能分隔', function () {
    $r = Sites::parseNets("192.168.1.9\n172.16.1.5/24, 10.0.0.0/8 ; 172.16.1.0/24");
    assertSame(['nets' => ['10.0.0.0/8', '172.16.1.0/24', '192.168.1.9/32'], 'errors' => []], $r);
});

test('站點：網段文字的錯誤——不是 IPv4、前綴太小、IPv6、空白、太多、太長，都用中文說明，且不回傳部分結果', function () {
    foreach (['abc', '10.0.0.0/7', '300.1.1.1', '2001:db8::/32', '10.0.0.0/33', '10.0.0/24'] as $bad) {
        $r = Sites::parseNets("10.0.0.0/8\n" . $bad);
        assertSame([], $r['nets'], $bad);
        assertTrue(count($r['errors']) === 1 && mb_strpos($r['errors'][0], '不是合法的 IPv4 網段') !== false, $bad . ' ／ ' . json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
    }
    assertSame(['至少要有一個網段'], Sites::parseNets("  \n ")['errors']);
    $nine = implode("\n", array_map(static fn ($i) => "10.0.$i.0/24", range(1, 9)));
    assertTrue(mb_strpos(Sites::parseNets($nine)['errors'][0], '最多 8 個網段') !== false);
    assertSame(8, count(Sites::parseNets(implode("\n", array_map(static fn ($i) => "10.0.$i.0/24", range(1, 8))))['nets']), '剛好 8 個可以');
    assertTrue(mb_strpos(Sites::parseNets(str_repeat('10.0.0.0/8 ', 200))['errors'][0], '太長') !== false);
});

test('站點：名稱規則——空白、超過 32 字、含 | 或控制字元、SNMP 預設位置都不行', function () {
    assertSame('站點名稱不能是空的', Sites::nameError(''));
    assertSame(null, Sites::nameError(str_repeat('站', 32)));
    assertTrue(mb_strpos((string) Sites::nameError(str_repeat('站', 33)), '不能超過 32 個字') !== false, '用字數算，不是位元組');
    assertTrue(Sites::nameError('A|B') !== null);
    assertTrue(Sites::nameError("A\nB") !== null);
    assertTrue(Sites::nameError("A\x07") !== null);
    foreach (['Unknown', 'unknown', 'End User Premise.', 'Unknown (edit /etc/snmp)'] as $placeholder) {
        assertTrue(mb_strpos((string) Sites::nameError($placeholder), '預設的位置名稱') !== false, $placeholder);
    }
    assertTrue(Sites::nameError('Rack, Room, Building, City, Country [Lat, Lon]') !== null, '這個範本字串本來就超過 32 字');
    // 不可見的空白與零寬字元：只有這類字元的名稱不是名稱（trim() 只會去 ASCII 空白）
    foreach (["\u{3000}", "\u{00A0}", "\u{200B}", " \u{3000}\u{00A0} ", "\u{FEFF}"] as $blank) {
        assertSame('站點名稱不能是空的', Sites::nameError(Sites::cleanName($blank)), json_encode($blank));
    }
    assertSame('總部', Sites::cleanName("\u{3000}總部\u{00A0} "), '前後的全形空白、不換行空白一起去掉');
    assertSame("總 部", Sites::cleanName("總 部"), '中間的空白保留');
    assertTrue(Sites::nameError("總\u{200B}部") !== null, '名稱中間的零寬字元也不行（兩個看起來一樣的名稱會繞過重複檢查）');
    assertSame(null, Sites::nameError('總 部'));
    assertSame(null, Sites::fromStored("\u{3000}總部|10.0.0.0/8"), '名稱前有全形空白的存檔值讀不回來（不是整理過的）');
    $dup = Sites::parseSubmitted([siItem('總部', '10.0.0.0/24'), siItem("\u{3000}總部\u{00A0}", '10.0.1.0/24')]);
    assertTrue(count($dup['errors']) === 1 && mb_strpos($dup['errors'][0], '重複') !== false, '全形空白包起來的同名站點算重複');
    assertSame(['站點名稱不能是空的'], array_map(static fn ($m) => preg_replace('/^[^：]+：/u', '', $m), array_slice(Sites::parseSubmitted([siItem("\u{3000}", '10.0.0.0/24')])['errors'], 0, 1)));
    foreach (['總部機房', 'Unknown HQ', 'DC-1'] as $ok) {
        assertSame(null, Sites::nameError($ok), $ok);
    }
});

test('站點：整份清單驗證——整理後回傳；名稱不分大小寫不可重複；同一個網段不能屬於兩個站點；重疊但前綴不同可以', function () {
    $r = Sites::parseSubmitted([siItem(' 總部 ', "172.16.1.7/24\n10.1.0.0/16"), siItem('分部', '192.168.5.0/24', '4')]);
    assertSame([], $r['errors']);
    assertSame([['id' => null, 'name' => '總部', 'nets' => ['10.1.0.0/16', '172.16.1.0/24']], ['id' => 4, 'name' => '分部', 'nets' => ['192.168.5.0/24']]], $r['sites']);

    $dup = Sites::parseSubmitted([siItem('Hq', '10.0.0.0/24'), siItem(' hq ', '10.0.1.0/24')]);
    assertTrue(count($dup['errors']) === 1 && mb_strpos($dup['errors'][0], '重複') !== false, json_encode($dup['errors'], JSON_UNESCAPED_UNICODE));
    assertSame([], $dup['sites'], '有錯誤就不回傳部分結果');

    $same = Sites::parseSubmitted([siItem('甲', '10.0.0.0/24'), siItem('乙', '10.0.0.5/24')]);
    assertTrue(count($same['errors']) === 1 && mb_strpos($same['errors'][0], '乙') !== false && mb_strpos($same['errors'][0], '10.0.0.0/24') !== false
        && mb_strpos($same['errors'][0], '甲') !== false && mb_strpos($same['errors'][0], '只能屬於一個站點') !== false, json_encode($same['errors'], JSON_UNESCAPED_UNICODE));

    assertSame([], Sites::parseSubmitted([siItem('總部', '10.0.0.0/16'), siItem('分部', '10.0.1.0/24')])['errors'], '大網段裡切出小網段是合理的用法');
    assertSame(['sites' => [], 'errors' => []], Sites::parseSubmitted([]), '空清單＝刪除全部站點');
});

test('站點：整份清單驗證——數量、格式、識別碼，錯誤一次全部回報', function () {
    $many = [];
    for ($i = 0; $i <= Sites::MAX_SITES; $i++) {
        $many[] = siItem("站$i", '10.' . $i . '.0.0/16');
    }
    assertTrue(mb_strpos(Sites::parseSubmitted($many)['errors'][0], '最多 50 個站點') !== false);
    assertSame([], Sites::parseSubmitted(array_slice($many, 0, Sites::MAX_SITES))['errors'], '剛好 50 個可以');

    assertSame(['第 1 個站點：格式不對'], Sites::parseSubmitted(['x'])['errors']);
    foreach (['abc', '0', '-1', '1234567', '1.5'] as $badId) {
        $e = Sites::parseSubmitted([siItem('甲', '10.0.0.0/24', $badId)])['errors'];
        assertTrue(count($e) === 1 && mb_strpos($e[0], '識別碼') !== false, $badId);
    }
    $e = Sites::parseSubmitted([siItem('甲', '10.0.0.0/24', 3), siItem('乙', '10.0.1.0/24', 3)])['errors'];
    assertTrue(count($e) === 1 && mb_strpos($e[0], '識別碼重複') !== false);
    // 一個站點同時名稱空白、網段不合法：兩個問題都要說，而且指出是哪一個站點
    $e = Sites::parseSubmitted([siItem('', 'abc'), siItem('乙', '')])['errors'];
    assertSame(3, count($e), json_encode($e, JSON_UNESCAPED_UNICODE));
    assertTrue(mb_strpos($e[0], '第 1 個站點') !== false && mb_strpos($e[2], '站點「乙」') !== false);
    // 欄位不是字串（陣列、物件）當成空的，不能出現型別錯誤
    $e = Sites::parseSubmitted([['name' => ['x'], 'cidrs' => ['10.0.0.0/8']]])['errors'];
    assertSame(2, count($e));
});

test('站點：用 IP 找站點——最長前綴優先；不是 IPv4 或沒有符合的是 null', function () {
    $s = siSites([['總部', '172.16.0.0/16'], ['機房', '172.16.1.0/24'], ['單機', '172.16.1.77/32']]);
    assertSame('機房', $s->siteOf('172.16.1.5'));
    assertSame('總部', $s->siteOf('172.16.2.5'));
    assertSame('單機', $s->siteOf('172.16.1.77'), '/32 比 /24 更具體');
    foreach (['10.0.0.1', '2001:db8::1', 'abc', '', '172.16.1', '172.16.1.5/24'] as $no) {
        assertSame(null, $s->siteOf($no), $no);
    }
    assertSame(null, (new Sites())->siteOf('172.16.1.5'));
});

test('站點：資料庫存的格式——來回一致；被手動改壞的值（格式、排序、未整理、重複、預設名稱）都讀不回來', function () {
    $stored = Sites::toStored('總部', ['10.1.0.0/16', '172.16.1.0/24']);
    assertSame('總部|10.1.0.0/16,172.16.1.0/24', $stored);
    assertSame(['name' => '總部', 'nets' => ['10.1.0.0/16', '172.16.1.0/24']], Sites::fromStored($stored));
    foreach (['沒有分隔', '總部|', '|10.0.0.0/8', ' 總部|10.0.0.0/8', '總部|172.16.1.0/24,10.1.0.0/16', '總部|10.0.0.5/8',
              '總部|10.0.0.0/8,10.0.0.0/8', 'Unknown|10.0.0.0/8', '總部|10.0.0.0/8|x', '總部|abc'] as $bad) {
        assertSame(null, Sites::fromStored($bad), $bad);
    }
});

test('站點：設定名稱 site.<id>——只有 1～999999 的整數 id 是站點', function () {
    assertSame(12, Sites::idOfKey('site.12'));
    assertSame('site.7', Sites::keyOf(7));
    foreach (['site.0', 'site.007', 'site.abc', 'site.1234567', 'site.', 'risk.low_from', 'xsite.1', 'site.1 '] as $bad) {
        assertSame(null, Sites::idOfKey($bad), $bad);
    }
    assertTrue(Sites::isSiteKey('site.abc') && Sites::isSiteKey('site.1') && !Sites::isSiteKey('net.internal_cidrs'), '壞掉的 site.* 也要認得是站點的列（才會被修復時清掉）');
});

// ── Fleet：站點歸屬與設備 IP ────────────────────────────────────────

test('Fleet：設定了站點，站點就只由設定的網段決定——LibreNMS 位置不再當站點，沒符合網段的設備一律未歸屬', function () {
    // 使用者：「站點數請以設定的內容為主要依據」。VM 上設了台南、台中兩個站點，LibreNMS 另有位置「台中機房」的設備，站點數卻變成 3
    $sites = siSites([['總部', '172.16.1.0/24'], ['分部', "172.16.2.0/24\n10.9.0.0/16"]]);
    $f = new Fleet([
        dev('a', 'server', 1, null, ['ip' => '172.16.1.10']),
        dev('b', 'server', 0, '北區所', ['ip' => '172.16.1.11']),     // 網段決定：總部（位置不管）
        dev('c', 'server', 1, '總部', ['ip' => '8.8.8.8']),           // 位置和站點同名也不算：IP 不在網段內就是未歸屬
        dev('d', 'server', 1, '台中機房', ['ip' => '8.8.4.4']),       // LibreNMS 位置不是設定頁的站點
        dev('e', 'server', 1, null, ['ip' => '10.9.3.3']),
        dev('f', 'server', 1, null),                                   // 沒有 IP
        dev('g', 'server', 1, 'Unknown', ['ip' => '1.1.1.1']),        // SNMP 預設值
    ], 'UTC', $sites);
    assertSame(['分部' => 1, '總部' => 2], array_column($f->sites(), 'devices', 'name'), '站點只有設定的那兩個，沒有「北區所」「台中機房」');
    assertSame(4, $f->unlocated());
    $hq = array_values(array_filter($f->sites(), static fn ($s) => $s['name'] === '總部'))[0];
    assertSame([2, 1, 1], [$hq['devices'], $hq['up'], $hq['down']]);
    assertSame('總部', $f->siteOfHost('a'));
    assertSame('總部', $f->siteOfHost('B.EXAMPLE'), '事件案的主機也用網段歸站點');
    assertSame(null, $f->siteOfHost('c'));
    assertSame(null, $f->siteOfHost('f'));
    assertSame([true, []], [$f->hasConfiguredSites(), $f->emptyConfiguredSites()]);
    assertSame(['台中機房', '總部'], $f->unassignedLocations(), '沒歸屬的設備身上的有效位置（SNMP 預設值不算），畫面列出來提示：這些位置不是站點');
});

test('Fleet：站點數＝設定的站點數——不論有沒有設備歸進去，LibreNMS 位置都不會讓站點變多', function () {
    $sites = siSites([['台南公司', '172.16.1.0/24'], ['台中公司', '172.16.17.0/24']]);
    $f = new Fleet([dev('a', 'server', 1, '機房', ['ip' => '172.16.1.254']), dev('b', 'server', 1, '台中機房', ['ip' => '172.16.9.9'])], 'UTC', $sites);
    assertSame(['台南公司'], array_column($f->sites(), 'name'));
    assertSame(['台中公司'], $f->emptyConfiguredSites());
    assertSame(2, count($f->sites()) + count($f->emptyConfiguredSites()), '有設備的站點＋沒設備的站點＝設定的 2 個');
    assertSame(['台中機房'], $f->unassignedLocations());
    // 沒設定站點時才是 LibreNMS 位置，也沒有「未歸屬的位置」提示
    $none = new Fleet([dev('a', 'server', 1, '機房', ['ip' => '172.16.1.254']), dev('b', 'server', 1, '台中機房', ['ip' => '172.16.9.9'])]);
    assertSame(['台中機房', '機房'], array_column($none->sites(), 'name'));
    assertSame([], $none->unassignedLocations());
});

test('Fleet：沒設定站點時行為與只有 LibreNMS 位置時完全一樣（設備有 IP 也不影響）', function () {
    $rows = [dev('a', 'server', 1, '北區所', ['ip' => '172.16.1.10']), dev('b', 'server', 0, null, ['ip' => '172.16.1.11']), dev('c', 'server', 1, 'Unknown')];
    foreach ([new Fleet($rows), new Fleet($rows, 'UTC', null), new Fleet($rows, 'UTC', new Sites())] as $f) {
        assertSame(['北區所' => 1], array_column($f->sites(), 'devices', 'name'));
        assertSame([2, false, []], [$f->unlocated(), $f->hasConfiguredSites(), $f->emptyConfiguredSites()]);
    }
});

test('Fleet：設定了站點卻沒有設備歸進去——不列為站點（0 台的站點不能顯示成「正常」），另外列出名稱讓管理者檢查網段', function () {
    $sites = siSites([['總部', '172.16.1.0/24'], ['空站', '203.0.113.0/24'], ['也空', '198.51.100.0/24']]);
    $f = new Fleet([dev('a', 'server', 1, null, ['ip' => '172.16.1.10'])], 'UTC', $sites);
    assertSame(['總部'], array_column($f->sites(), 'name'));
    assertSame(['也空', '空站'], $f->emptyConfiguredSites());
});

test('Fleet：站點的座標沿用設備在 LibreNMS 位置上的座標；只靠網段定義、設備沒有座標就沒有座標', function () {
    $f = new Fleet([dev('a', 'server', 1, null, ['ip' => '10.0.0.5']), dev('b', 'server', 1, '總部', ['ip' => '10.0.0.6', 'lat' => 25.1, 'lng' => 121.5])],
        'UTC', siSites([['總部', '10.0.0.0/24'], ['分部', '10.1.0.0/24']]));
    assertSame([25.1, 121.5], [$f->sites()[0]['lat'] ?? null, $f->sites()[0]['lng'] ?? null], '同一站點裡有座標的設備提供座標');
    $g = new Fleet([dev('x', 'server', 1, null, ['ip' => '10.1.0.9'])], 'UTC', siSites([['分部', '10.1.0.0/24']]));
    assertSame([null, null], [$g->sites()[0]['lat'], $g->sites()[0]['lng']]);
});

test('Fleet::deviceIp：overwrite_ip → devices.ip（4 位元組二進位）→ hostname；IPv6、網域名稱、空值都略過', function () {
    $packed = inet_pton('172.16.1.5');
    assertSame('10.0.0.9', Fleet::deviceIp('10.0.0.9', $packed, 'host.example'), '使用者指定的優先');
    assertSame('10.0.0.9', Fleet::deviceIp(' 10.0.0.9 ', null, null), '前後空白可以');
    assertSame('172.16.1.5', Fleet::deviceIp(null, $packed, 'host.example'), '用查詢建構器讀到的是原始位元組，要轉成文字');
    assertSame('172.16.1.5', Fleet::deviceIp('', $packed, null));
    assertSame('192.0.2.7', Fleet::deviceIp(null, null, '192.0.2.7'), 'hostname 本身就是 IPv4');
    assertSame('192.0.2.7', Fleet::deviceIp(null, inet_pton('2001:db8::1'), '192.0.2.7'), '16 位元組是 IPv6，略過');
    assertSame('172.16.1.5', Fleet::deviceIp('2001:db8::5', $packed, null), '指定的是 IPv6 就看下一個來源');
    foreach ([[null, null, null], ['', '', ''], [null, inet_pton('2001:db8::1'), 'host.example'], ['not-an-ip', 'abc', 'host.example'], [null, "\x01\x02\x03", null]] as [$o, $p, $hn]) {
        assertSame(null, Fleet::deviceIp($o, $p, $hn), json_encode([$o, bin2hex((string) $p), $hn]));
    }
});

// ── LandscapeService：文字與警告 ────────────────────────────────────

function siBuild(Fleet $fleet, ?string $warning = null): array
{
    return (new LandscapeService(LS_NOW))->build($fleet, ['new' => 0, 'ack' => 0, 'in_progress' => 0, 'closed' => 0, 'open' => 0],
        ['high' => 0, 'medium' => 0, 'low' => 0], [], [], lsCoverage(), RiskScore::DEFAULT_THRESHOLDS, $warning);
}

test('總覽：有自訂站點時的說明文字（未歸屬、全部歸屬、沒有符合的網段）與站點區塊的附加欄位', function () {
    $sites = siSites([['總部', '172.16.1.0/24'], ['空站', '203.0.113.0/24']]);
    $o = siBuild(new Fleet([dev('a', 'server', 1, null, ['ip' => '172.16.1.5']), dev('b')], 'UTC', $sites));
    $k = lsKpi($o)['sites'];
    // 站點數＝有設備的站點（總部）＋設定了卻沒有設備歸進去的站點（空站）：要和設定頁自訂的 2 個站點對得起來
    assertSame([2, 'good', '其中 1 個還沒有設備歸進去（空站）；另有 1 台設備沒有歸屬站點（IP 不在任何站點的網段內）'], [$k['value'], $k['state'], $k['sub']]);
    assertSame([true, ['空站'], null, 1], [$o['sites']['data']['configured'], $o['sites']['data']['empty_sites'], $o['sites']['data']['warning'], $o['sites']['data']['unlocated']]);
    assertSame([], $o['sites']['data']['unassigned_locations'], '沒歸屬的設備沒有 LibreNMS 位置，沒有位置提示');
    assertSame(1, count($o['sites']['data']['items']), '分佈與健康等級只算有設備的站點');
    assertSame([0, 'good'], [lsKpi($o)['high_sites']['value'], lsKpi($o)['high_sites']['state']], '高風險站點的計算不含沒有設備的站點（沒有設備不是「正常」也不是「異常」）');
    assertSame(['normal' => 1, 'minor' => 0, 'moderate' => 0, 'severe' => 0], $o['sites']['data']['counts'], '空站不算在「正常」那一格');
    // 排行：有設備的在前，沒有設備的排最後、標 empty（畫面是灰色的「尚無設備」，不是「正常」）
    $rank = $o['ranking']['data'];
    assertSame([['總部', false, 1], ['空站', true, 0]], array_map(static fn ($r) => [$r['name'], $r['empty'] ?? false, $r['devices']], $rank));
    assertSame([1, 2], array_column($rank, 'rank'));

    $all = lsKpi(siBuild(new Fleet([dev('a', 'server', 1, null, ['ip' => '172.16.1.5'])], 'UTC', $sites)))['sites'];
    assertSame([2, '其中 1 個還沒有設備歸進去（空站）；各設備都已歸屬站點'], [$all['value'], $all['sub']]);
    $one = lsKpi(siBuild(new Fleet([dev('a', 'server', 1, null, ['ip' => '172.16.1.5'])], 'UTC', siSites([['總部', '172.16.1.0/24']]))))['sites'];
    assertSame([1, '各設備都已歸屬站點'], [$one['value'], $one['sub']], '沒有空站點時不多說');

    // 設定了站點，但沒有任何設備歸進去：站點數是設定的數量（不是「無法取得」或 0），但不是綠色，並說明去檢查網段
    $none = siBuild(new Fleet([dev('a', 'server', 1, null, ['ip' => '8.8.8.8'])], 'UTC', $sites));
    $nk = lsKpi($none);
    assertSame([2, 'warn', null], [$nk['sites']['value'], $nk['sites']['state'], $nk['sites']['na'] ?? null]);
    assertTrue(mb_strpos($nk['sites']['sub'], '已經設定了站點') !== false && mb_strpos($nk['sites']['sub'], '請檢查') !== false, $nk['sites']['sub']);
    assertSame(false, $none['sites']['ok'], '健康分佈沒有任何有設備的站點可畫');
    assertTrue(mb_strpos($none['sites']['error'], '已經設定了站點') !== false);
    assertSame([null, 'unknown'], [$nk['high_sites']['value'], $nk['high_sites']['state']], '沒有任何站點有設備，高風險站點算不出來');
    assertSame(true, $none['ranking']['ok'], '排行仍列出設定的站點（都是尚無設備）');
    assertSame([['空站', true], ['總部', true]], array_map(static fn ($r) => [$r['name'], $r['empty']], $none['ranking']['data']));
});

test('總覽：站點數以設定的站點為準（VM 實際情況：設了台南、台中兩個站點，LibreNMS 另有位置「台中機房」的設備，站點數仍是 2）', function () {
    $sites = siSites([['台南公司', '172.16.1.0/24'], ['台中公司', '172.16.17.0/24']]);
    $o = siBuild(new Fleet([
        dev('a', 'server', 1, '機房', ['ip' => '172.16.1.254']),
        dev('b', 'server', 0, null, ['ip' => '172.16.1.31']),
        dev('c', 'server', 1, '台中機房', ['ip' => '172.16.9.9']),      // LibreNMS 位置，IP 不在任何站點網段內
        dev('d', 'server', 1, 'Unknown', ['ip' => '127.0.0.1']),
    ], 'UTC', $sites));
    $k = lsKpi($o)['sites'];
    assertSame([2, '其中 1 個還沒有設備歸進去（台中公司）；另有 2 台設備沒有歸屬站點（IP 不在任何站點的網段內）'], [$k['value'], $k['sub']], '站點數是設定的 2 個，不是 3');
    assertSame(['台南公司'], array_column($o['sites']['data']['items'], 'name'), '分佈裡沒有「機房」「台中機房」這種 LibreNMS 位置');
    assertSame(['台南公司', '台中公司'], array_column($o['ranking']['data'], 'name'));
    assertSame(['台中機房'], $o['sites']['data']['unassigned_locations'], '站點區塊要說明「台中機房」這個位置的設備為什麼沒有歸屬');
    assertSame(2, $o['sites']['data']['unlocated']);
});

test('總覽：沒有自訂站點時，有站點的畫面文字不變（只談 LibreNMS 位置），站點區塊的附加欄位是空的', function () {
    $o = siBuild(new Fleet([dev('a', 'server', 1, '北區所'), dev('b')]));
    assertSame('另有 1 台設備沒有有效位置（未指定，或是 Unknown 這類預設值）', lsKpi($o)['sites']['sub']);
    assertSame([false, [], null], [$o['sites']['data']['configured'], $o['sites']['data']['empty_sites'], $o['sites']['data']['warning']]);
    assertSame('各設備都已指定位置', lsKpi(siBuild(new Fleet([dev('a', 'server', 1, '北區所')])))['sites']['sub']);
});

test('總覽：沒有任何站點可顯示時，站點數、高風險站點、站點分佈、排行四處說同一句原因（沒有設備／網段沒對上／只談位置並指向設定頁），警告也帶到四處', function () {
    $hint = '到「設定」頁新增站點與網段';
    $w = '站點設定讀不到，暫時只依 LibreNMS 的位置分站點：原因';
    $four = static function (array $o): array {
        $k = lsKpi($o);
        // 有自訂站點但都沒有設備時，排行仍列出設定的站點（尚無設備），所以沒有 error；其他情況四處都有
        return array_values(array_filter([$k['sites']['sub'], $k['high_sites']['sub'], $o['sites']['error'] ?? null, $o['ranking']['error'] ?? null], static fn ($t) => $t !== null));
    };
    // 沒有自訂站點、設備都沒有有效位置：只談 LibreNMS 位置，並指向設定頁
    foreach ($four(siBuild(new Fleet([dev('a'), dev('b')]))) as $text) {
        assertTrue(mb_strpos($text, 'Unknown 這類 SNMP 預設值') !== false && mb_strpos($text, $hint) !== false, $text);
    }
    foreach ($four(siBuild(new Fleet([dev('a')]), $w)) as $text) {
        assertTrue(substr($text, -strlen('；' . $w)) === '；' . $w, '警告要帶到每一處：' . $text);
    }
    // 有自訂站點但網段沒對上
    $unmatched = $four(siBuild(new Fleet([dev('a', 'server', 1, null, ['ip' => '8.8.8.8'])], 'UTC', siSites([['總部', '172.16.1.0/24']]))));
    assertSame(3, count($unmatched), '站點數、高風險站點、站點分佈各一句（排行改列出尚無設備的站點）');
    foreach ($unmatched as $text) {
        assertTrue(mb_strpos($text, '已經設定了站點') !== false && mb_strpos($text, '請檢查') !== false, $text);
    }
    // 沒有任何設備
    foreach ($four(siBuild(new Fleet([], 'UTC', siSites([['總部', '172.16.1.0/24']])))) as $text) {
        assertTrue(mb_strpos($text, '還沒有納管任何設備') !== false && mb_strpos($text, '已經設定了站點') === false, $text);
    }
});

test('IpNets::parseList：內部網段與站點共用的切字與整理——依位址排序、去重、逐個報錯、不檢查數量；parseInput 的結果不變', function () {
    $r = Ioc\IpNets::parseList("192.168.1.9\n172.16.1.5/24, 10.0.0.0/8 ; 172.16.1.0/24 abc");
    assertSame(['10.0.0.0/8', '172.16.1.0/24', '192.168.1.9/32'], $r['nets']);
    assertTrue(count($r['errors']) === 1 && mb_strpos($r['errors'][0], '「abc」') !== false);
    assertSame(['nets' => [], 'errors' => []], Ioc\IpNets::parseList('  '), '空白不是錯誤（有沒有「至少一個」由呼叫端決定）');
    $twelve = implode("\n", array_map(static fn ($i) => "10.$i.0.0/16", range(1, 12)));
    assertSame(12, count(Ioc\IpNets::parseList($twelve)['nets']), '不檢查數量上限');
    assertTrue(mb_strpos(Ioc\IpNets::parseInput($twelve)['errors'][0], '最多 10 個網段') !== false, '內部網段自己的上限仍然在');
    assertSame(['至少要有一個內部網段'], Ioc\IpNets::parseInput('')['errors']);
});

test('總覽：站點設定讀不到時，警告帶到站點數卡片與站點區塊（不能默默換一套結果）', function () {
    $w = '站點設定讀不到，暫時只依 LibreNMS 的位置分站點（原因）';
    $o = siBuild(new Fleet([dev('a', 'server', 1, '北區所')]), $w);
    $k = lsKpi($o)['sites'];
    assertSame('warn', $k['state'], '有警告就不是綠色');
    assertTrue(substr($k['sub'], -strlen('；' . $w)) === '；' . $w, $k['sub']);
    assertSame($w, $o['sites']['data']['warning']);
    $none = siBuild(new Fleet([dev('a')]), $w);
    assertTrue(mb_strpos(lsKpi($none)['sites']['sub'], $w) !== false && mb_strpos($none['sites']['error'], $w) !== false, '沒有站點可顯示時也要說');
});

// ── SettingsService：站點的讀寫 ─────────────────────────────────────

test('站點設定：沒設定過是空的（不是錯誤）；檢視者可讀、沒有角色不行', function () use ($siH, $siV) {
    $svc = siMake($repo);
    assertTrue($svc->sites($siH)->isEmpty() && $svc->sites($siV)->isEmpty());
    $s = $svc->siteSettings($siV);
    assertSame([[], 0, false, null, null, []], [$s['sites'], $s['broken'], $s['invalid'], $s['updated_by'], $s['updated_at'], $s['history']]);
    assertSame(['max_sites' => 50, 'max_nets' => 8, 'name_max' => 32], $s['limits']);
    assertThrows(AccessDenied::class, fn () => $svc->sites(null));
    assertThrows(AccessDenied::class, fn () => $svc->siteSettings(null));
});

test('站點設定：儲存後立刻生效，一個站點一列 site.<id>，資料表存 UTC，畫面看到台北時間與「新增」紀錄；檢視者也看得到', function () use ($siH, $siV) {
    $svc = siMake($repo);
    $s = $svc->saveSites(['sites' => [siItem('總部', '172.16.1.7/24'), siItem('分部', '10.9.0.0/16')]], 'alice', $siH);
    assertSame([['id' => 1, 'name' => '總部', 'nets' => ['172.16.1.0/24']], ['id' => 2, 'name' => '分部', 'nets' => ['10.9.0.0/16']]], $s['sites']);
    assertSame(['alice', '2026-10-22 10:00:00', false], [$s['updated_by'], $s['updated_at'], $s['invalid']]);
    assertSame(['site.1', 'site.2', Sites::NEXT_ID_KEY], array_keys($repo->rows), '兩個站點，加一個內部計數器（下一個可用的 id）');
    assertSame('3', $repo->rows[Sites::NEXT_ID_KEY]['value']);
    assertSame(1, $repo->locks, '「讀現況再寫」走鎖定讀取，兩個管理者同時儲存會排隊');
    assertSame(['value' => '總部|172.16.1.0/24', 'updated_by' => 'alice', 'updated_at' => SI_T1], $repo->rows['site.1']);
    assertSame(['總部：172.16.1.0/24', '（新增）'], [$s['history'][1]['to'], $s['history'][1]['from']], '紀錄由新到舊：第一個站點的新增排在後面');
    assertSame('分部', $svc->sites($siV)->siteOf('10.9.1.1'), '檢視者可以讀');
    assertSame(2, count($svc->siteSettings($siV)['history']));
});

test('站點設定：只寫有變動的站點；整份沒有變動什麼都不寫；順序不同不算變動', function () use ($siH) {
    $svc = siMake($repo, $clock);
    $svc->saveSites(['sites' => [siItem('總部', '172.16.1.0/24'), siItem('分部', '10.9.0.0/16')]], 'alice', $siH);
    [$puts, $hist] = [$repo->puts, count($repo->history)];

    $svc->saveSites(['sites' => [siItem('分部', '10.9.0.0/16', 2), siItem('總部', "172.16.1.99/24", 1)]], 'bob', $siH);
    assertSame([$puts, $hist], [$repo->puts, count($repo->history)], '內容相同（換順序、網段寫法不同但整理後一樣）：不寫也不留紀錄');

    $clock['now'] = '2026-10-23 03:00:00';
    $s = $svc->saveSites(['sites' => [siItem('總部二', '172.16.1.0/24', 1), siItem('分部', '10.9.0.0/16', 2)]], 'bob', $siH);
    assertSame([$puts + 1, $hist + 1], [$repo->puts, count($repo->history)], '只有改名的那個站點');
    assertSame(['bob', '2026-10-23 03:00:00'], [$repo->rows['site.1']['updated_by'], $repo->rows['site.1']['updated_at']]);
    assertSame(['alice', SI_T1], [$repo->rows['site.2']['updated_by'], $repo->rows['site.2']['updated_at']], '沒變動的站點維持原本的最後修改');
    assertSame(['總部：172.16.1.0/24', '總部二：172.16.1.0/24', 'bob'], [$s['history'][0]['from'], $s['history'][0]['to'], $s['history'][0]['actor']]);
});

test('站點設定：沒送來的站點就是刪除（留下「刪除」紀錄）；新站點的 id 只增不減，不會拿到刪掉的 id', function () use ($siH) {
    $svc = siMake($repo);
    $svc->saveSites(['sites' => [siItem('甲', '10.0.0.0/24'), siItem('乙', '10.0.1.0/24'), siItem('丙', '10.0.2.0/24')]], 'alice', $siH);
    $s = $svc->saveSites(['sites' => [siItem('乙', '10.0.1.0/24', 2)]], 'alice', $siH);
    assertSame(['site.2', Sites::NEXT_ID_KEY], array_keys($repo->rows));
    assertSame([['丙：10.0.2.0/24', '（刪除）'], ['甲：10.0.0.0/24', '（刪除）']],
        [[$s['history'][0]['from'], $s['history'][0]['to']], [$s['history'][1]['from'], $s['history'][1]['to']]], '兩個被刪的站點各一筆（由新到舊）');
    $s = $svc->saveSites(['sites' => [siItem('乙', '10.0.1.0/24', 2), siItem('新', '10.0.9.0/24')]], 'alice', $siH);
    assertSame([2, 4], array_column($s['sites'], 'id'), '刪掉的 1、3 不會被重用：計數器已經到 4');
    $s = $svc->saveSites(['sites' => []], 'alice', $siH);
    assertSame([[], [Sites::NEXT_ID_KEY]], [$s['sites'], array_keys($repo->rows)], '空清單＝全部刪除；計數器留著，之後新增的站點不會拿到舊 id');
    assertSame([5], array_column($svc->saveSites(['sites' => [siItem('再來', '10.0.5.0/24')]], 'alice', $siH)['sites'], 'id'));
});

test('站點設定：刪掉最大的站點再新增，新站點不會拿到同一個 id——開著舊畫面的人用舊 id 存檔會被擋下，不會蓋掉別人新建的站點', function () use ($siH) {
    $svc = siMake($repo);
    $svc->saveSites(['sites' => [siItem('甲', '10.0.0.0/24'), siItem('乙', '10.0.1.0/24')]], 'alice', $siH);          // A 的畫面：id 1、2
    $svc->saveSites(['sites' => [siItem('甲', '10.0.0.0/24', 1)]], 'bob', $siH);                                        // B 刪掉最大的 2
    $s = $svc->saveSites(['sites' => [siItem('甲', '10.0.0.0/24', 1), siItem('丙', '10.0.2.0/24')]], 'bob', $siH);       // B 再新增
    assertSame([1, 3], array_column($s['sites'], 'id'), '新站點是 3，不是 2');
    $before = $repo->rows;
    $stale = assertThrows(ValidationError::class, fn () => $svc->saveSites(['sites' => [siItem('甲', '10.0.0.0/24', 1), siItem('乙改', '10.0.9.0/24', 2)]], 'alice', $siH), '重新整理');
    assertSame(1, count($stale->errors()));
    assertSame($before, $repo->rows, 'A 用舊 id 2 存檔被擋下，B 新建的站點沒有被蓋掉');
});

test('站點設定：異動紀錄的篩選在資料庫端依前綴做——其他設定的異動再多，站點的紀錄也不會被擠出視窗', function () use ($siH) {
    $svc = siMake($repo);
    $svc->saveSites(['sites' => [siItem('總部', '10.0.0.0/24')]], 'alice', $siH);
    for ($i = 0; $i < 400; $i++) {
        $repo->addHistory(SI_T1, 'bob', SettingsService::NET_INTERNAL, '10.0.0.0/8', '10.0.0.0/8,172.16.0.0/12');
    }
    $h = $svc->siteSettings($siH)['history'];
    assertSame([1, '（新增）'], [count($h), $h[0]['from']], '站點的新增紀錄還在，後面 400 筆別的設定異動沒有把它擠掉');
    assertSame(0, count($repo->recentHistory(10, null, 'nosuch.')), '前綴沒有符合的就是空的');
    assertSame(0, count($repo->recentHistory(10, [SettingsService::NET_INTERNAL], Sites::KEY_PREFIX)), '名稱清單與前綴同時給時兩個條件都要符合');
});

test('站點設定：驗證失敗、送來的 id 不存在、格式不對——都不寫任何東西', function () use ($siH) {
    $svc = siMake($repo);
    $svc->saveSites(['sites' => [siItem('總部', '172.16.1.0/24')]], 'alice', $siH);
    [$rows, $puts, $hist] = [$repo->rows, $repo->puts, $repo->history];
    $e = assertThrows(ValidationError::class, fn () => $svc->saveSites(['sites' => [siItem('總部', 'abc', 1)]], 'u', $siH));
    assertTrue(count($e->errors()) >= 1);
    $stale = assertThrows(ValidationError::class, fn () => $svc->saveSites(['sites' => [siItem('總部', '172.16.1.0/24', 1), siItem('別人刪掉的', '10.0.0.0/24', 99)]], 'u', $siH), '重新整理');
    assertSame(['這份站點設定已經被別人更新過（或其中的站點已被刪除），請重新整理頁面再修改'], $stale->errors());
    assertThrows(ValidationError::class, fn () => $svc->saveSites(['sites' => 'abc'], 'u', $siH), '沒有收到站點清單');
    assertThrows(ValidationError::class, fn () => $svc->saveSites([], 'u', $siH), '沒有收到站點清單');
    assertSame([$rows, $puts, $hist], [$repo->rows, $repo->puts, $repo->history], '任何一次被拒絕都不能留下痕跡');
});

test('站點設定：只有 admin 能儲存；寫異動紀錄失敗整筆復原', function () use ($siH, $siV) {
    $svc = siMake($repo);
    assertThrows(AccessDenied::class, fn () => $svc->saveSites(['sites' => [siItem('總部', '10.0.0.0/24')]], 'v', $siV));
    assertThrows(AccessDenied::class, fn () => $svc->saveSites(['sites' => []], 'v', null));
    assertSame([[], 0], [$repo->rows, $repo->puts]);
    $repo->failOnHistory = true;
    assertThrows(RuntimeException::class, fn () => $svc->saveSites(['sites' => [siItem('總部', '10.0.0.0/24')]], 'a', $siH));
    assertSame([[], []], [$repo->rows, $repo->history], '紀錄寫不進去，站點也不能留下');
});

test('站點設定：資料表被手動改壞——sites() 丟例外（不套預設、不默默少算），編輯器標壞了幾筆，重新儲存即修復並留下刪除紀錄', function () use ($siH) {
    $svc = siMake($repo);
    $repo->rows = [
        'site.1'   => siRow('總部|10.0.0.0/24'),
        'site.2'   => siRow('總部|10.0.1.0/24'),          // 名稱和 site.1 重複
        'site.3'   => siRow('乙|10.0.0.0/24'),            // 網段和 site.1 重複
        'site.4'   => siRow('壞掉'),                       // 讀不懂
        'site.abc' => siRow('丙|10.0.5.0/24'),            // id 不合法
        'site.5'   => siRow('丁|10.0.6.0/24'),            // 好的
        'risk.low_from' => siRow('85'),                    // 別的設定不受影響
    ];
    $e = assertThrows(IocError::class, fn () => $svc->sites($siH));
    assertTrue(mb_strpos($e->getMessage(), '站點的設定值不合法') !== false);
    $s = $svc->siteSettings($siH);
    assertSame([true, 4, [1, 5]], [$s['invalid'], $s['broken'], array_column($s['sites'], 'id')], '先出現的算數，後面重複的算壞');

    $s = $svc->saveSites(['sites' => [siItem('總部', '10.0.0.0/24', 1), siItem('丁', '10.0.6.0/24', 5)]], 'alice', $siH);
    assertSame([false, 0], [$s['invalid'], $s['broken']]);
    assertSame(['site.1', 'site.5', 'risk.low_from', Sites::NEXT_ID_KEY], array_keys($repo->rows), '壞掉的列都清掉，好的站點與別的設定還在，另有內部計數器');
    assertSame('6', $repo->rows[Sites::NEXT_ID_KEY]['value'], '計數器不會低於任何出現過的 id（含壞掉的 site.4、site.5）');
    assertSame(1, $repo->puts, '好的站點沒有變動，不重寫；只寫了計數器');
    assertSame([4, ['（刪除）']], [count($s['history']), array_values(array_unique(array_column($s['history'], 'to')))], '每一列被清掉的壞資料各留一筆刪除紀錄');
    assertTrue($svc->sites($siH)->siteOf('10.0.6.9') === '丁', '修復後 sites() 恢復正常');
});

test('站點設定：異動紀錄只放站點自己的，風險門檻的紀錄不會混進來，反過來也一樣；稽核軌跡認得站點的設定名稱', function () use ($siH) {
    $svc = siMake($repo);
    $svc->saveRisk(['risk_low' => '90', 'risk_medium' => '70'], 'alice', $siH);
    $svc->saveSites(['sites' => [siItem('總部', '10.0.0.0/24')]], 'alice', $siH);
    assertSame(1, count($svc->siteSettings($siH)['history']));
    assertSame(2, count($svc->riskSettings($siH)['history']), '風險門檻只列自己的兩筆');
    assertSame('站點與網段', SettingsService::keyLabel('site.3'));
    assertSame('內部網段', SettingsService::keyLabel(SettingsService::NET_INTERNAL));
    assertSame('risk.unknown', SettingsService::keyLabel('risk.unknown'));
    $events = (new AuditTrail())->fromSettingsHistory([['ts' => SI_T1, 'actor' => 'alice', 'skey' => 'site.3', 'old_value' => '', 'new_value' => '總部|10.0.0.0/8']]);
    assertTrue(mb_strpos(json_encode($events, JSON_UNESCAPED_UNICODE), '修改設定：站點與網段') !== false);
});
