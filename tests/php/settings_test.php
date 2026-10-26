<?php
declare(strict_types=1);

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\IocError;
use Ioc\IpNets;
use Ioc\RiskScore;
use Ioc\SettingsService;
use Ioc\ValidationError;

const ST_LOW  = SettingsService::RISK_LOW;
const ST_MED  = SettingsService::RISK_MEDIUM;
const ST_T1   = '2026-10-20 08:00:00';      // UTC；台北是 16:00:00
const ST_T2   = '2026-10-21 02:30:00';      // UTC；台北是 10:30:00

/** 一個帶假時鐘的設定服務。$clock['now'] 改了，之後的操作就用新的時間。 */
function stMake(&$repo, ?ArrayObject &$clock = null): SettingsService
{
    $repo  = new MemorySettingsRepo();
    $clock = new ArrayObject(['now' => ST_T1]);
    return new SettingsService($repo, static fn (): string => $clock['now']);
}

function stRow(string $value, string $by = 'admin', string $at = '2026-10-01 00:00:00'): array
{
    return ['value' => $value, 'updated_by' => $by, 'updated_at' => $at];
}

/** 生效的風險等級門檻：正式程式只經由 overviewSettings() 讀，壞掉時 thresholds 是 null、原因在 errors（這裡轉成例外，方便斷言）。 */
function stThresholds(SettingsService $svc, ?string $level): array
{
    $c = $svc->overviewSettings($level);
    if (isset($c['errors']['thresholds'])) {
        throw $c['errors']['thresholds'];
    }
    return $c['thresholds'];
}

// 這個檔案的變數會落在 run.php 的全域範圍，名稱加 st 前綴，避免和其他測試檔撞名
$stH = Access::HANDLER;
$stV = Access::VIEWER;

test('設定：沒有設定過就是預設值 85／65，標示「不是自訂」', function () use ($stH) {
    $svc = stMake($repo);
    $s = $svc->riskSettings($stH);
    assertSame([85, 65, false, false], [$s['low'], $s['medium'], $s['custom'], $s['invalid']]);
    assertSame(['low' => 85, 'medium' => 65], $s['defaults']);
    assertSame([null, null, []], [$s['updated_by'], $s['updated_at'], $s['history']]);
    assertSame(['low' => 85, 'medium' => 65], stThresholds($svc, $stH));
    assertSame(RiskScore::DEFAULT_THRESHOLDS, stThresholds($svc, $stH), '預設值只有 RiskScore 一個來源');
});

test('設定：儲存後立刻生效，標示自訂、最後修改的人與台北時間', function () use ($stH) {
    $svc = stMake($repo);
    $s = $svc->saveRisk(['risk_low' => '93', 'risk_medium' => '71'], 'alice', $stH);
    assertSame([93, 71, true, false, 'alice', '2026-10-20 16:00:00'], [$s['low'], $s['medium'], $s['custom'], $s['invalid'], $s['updated_by'], $s['updated_at']]);
    assertSame(['low' => 93, 'medium' => 71], stThresholds($svc, $stH));
    assertSame('93', $repo->rows[ST_LOW]['value'], '資料表存的是 UTC 時間與字串值');
    assertSame(ST_T1, $repo->rows[ST_LOW]['updated_at']);
});

test('設定：輸入驗證——整數 1～100，中等風險要比低風險小；被拒絕的儲存不寫任何東西', function () use ($stH) {
    $svc = stMake($repo);
    $bad = [
        ['abc', '65'], ['85.5', '65'], ['-5', '65'], ['', '65'], [null, '65'], [['85'], '65'], ['0', '65'], ['101', '65'], ['1000', '65'],
        ['１００', '65'],                                    // 全形數字
        ['85', 'abc'], ['85', ''], ['85', '0'], ['85', '85'], ['85', '90'], ['1', '1'], ['100', '100'], ['65', '85'],
    ];
    foreach ($bad as [$lo, $me]) {
        try {
            $svc->saveRisk(['risk_low' => $lo, 'risk_medium' => $me], 'u', $stH);
            fail('應該被拒絕：' . json_encode([$lo, $me], JSON_UNESCAPED_UNICODE));
        } catch (ValidationError $e) {
            // 預期的結果
        }
    }
    assertThrows(ValidationError::class, fn () => $svc->saveRisk([], 'u', $stH));      // 兩個欄位都沒送
    assertSame([[], [], 0], [$repo->rows, $repo->history, $repo->puts], '任何一次被拒絕都不能留下痕跡');
});

test('設定：邊界值與前後空白可以', function () use ($stH) {
    $svc = stMake($repo);
    assertSame([100, 1], array_values(array_intersect_key($svc->saveRisk(['risk_low' => '100', 'risk_medium' => '1'], 'u', $stH), ['low' => 1, 'medium' => 1])));
    assertSame([2, 1], array_values(array_intersect_key($svc->saveRisk(['risk_low' => '2', 'risk_medium' => '1'], 'u', $stH), ['low' => 1, 'medium' => 1])));
    $s = $svc->saveRisk(['risk_low' => ' 90 ', 'risk_medium' => "70\n"], 'u', $stH);
    assertSame([90, 70], [$s['low'], $s['medium']]);
    assertSame('90', $repo->rows[ST_LOW]['value'], '存的是整理過的數字，不是原字串');
});

test('設定：錯誤訊息說得出原因（哪一個欄位、哪兩個數字衝突）', function () use ($stH) {
    $svc = stMake($repo);
    $e = assertThrows(ValidationError::class, fn () => $svc->saveRisk(['risk_low' => '60', 'risk_medium' => '70'], 'u', $stH));
    assertSame(1, count($e->errors()));
    assertTrue(mb_strpos($e->errors()[0], '70') !== false && mb_strpos($e->errors()[0], '60') !== false && mb_strpos($e->errors()[0], '要比') !== false, $e->errors()[0]);
    $e = assertThrows(ValidationError::class, fn () => $svc->saveRisk(['risk_low' => 'x', 'risk_medium' => 'y'], 'u', $stH));
    assertSame(2, count($e->errors()), '兩個欄位都有錯就兩條都列出來');
    assertTrue(mb_strpos($e->errors()[0], '低風險') !== false && mb_strpos($e->errors()[1], '中等風險') !== false);
});

test('設定：值沒有變動就什麼都不寫（也不留異動紀錄、不更新「最後修改」）', function () use ($stH) {
    $svc = stMake($repo, $clock);
    $svc->saveRisk(['risk_low' => '85', 'risk_medium' => '65'], 'a', $stH);
    assertSame([[], [], 0], [$repo->rows, $repo->history, $repo->puts], '就是預設值，沒設定過：不建立自訂資料列');
    $svc->saveRisk(['risk_low' => '93', 'risk_medium' => '71'], 'a', $stH);
    assertSame([2, 2], [$repo->puts, count($repo->history)]);
    $clock['now'] = ST_T2;
    $s = $svc->saveRisk(['risk_low' => '93', 'risk_medium' => '71'], 'bob', $stH);
    assertSame([2, 2], [$repo->puts, count($repo->history)], '再存一次同樣的值：沒有新的寫入');
    assertSame(['a', '2026-10-20 16:00:00'], [$s['updated_by'], $s['updated_at']], '最後修改還是第一次的人與時間');
});

test('設定：異動紀錄只記真的有變的值（誰、何時、舊值、新值），由新到舊，時間是台北時間', function () use ($stH) {
    $svc = stMake($repo, $clock);
    $svc->saveRisk(['risk_low' => '93', 'risk_medium' => '71'], 'alice', $stH);
    $h = $svc->riskSettings($stH)['history'];
    assertSame([['中等風險門檻', '65', '71'], ['低風險門檻', '85', '93']], array_map(fn ($x) => [$x['label'], $x['from'], $x['to']], $h), '舊值就是當時生效的值（沒設定過是預設值）');
    assertSame(['alice', '2026-10-20 16:00:00'], [$h[0]['actor'], $h[0]['time']]);

    $clock['now'] = ST_T2;
    $s = $svc->saveRisk(['risk_low' => '93', 'risk_medium' => '75'], 'bob', $stH);      // 只改中等風險
    assertSame(3, count($s['history']), '低風險沒變，不多記一筆');
    assertSame(['中等風險門檻', '71', '75', 'bob', '2026-10-21 10:30:00'], [$s['history'][0]['label'], $s['history'][0]['from'], $s['history'][0]['to'], $s['history'][0]['actor'], $s['history'][0]['time']]);
    assertSame(['bob', '2026-10-21 10:30:00'], [$s['updated_by'], $s['updated_at']], '兩個值一起寫，最後修改一致');
    assertSame('bob', $repo->rows[ST_LOW]['updated_by']);
});

test('設定：從預設值只改一個時，沒變的那個不記紀錄', function () use ($stH) {
    $svc = stMake($repo);
    $s = $svc->saveRisk(['risk_low' => '85', 'risk_medium' => '70'], 'u', $stH);
    assertSame(1, count($s['history']));
    assertSame(['中等風險門檻', '65', '70'], [$s['history'][0]['label'], $s['history'][0]['from'], $s['history'][0]['to']]);
    assertSame(true, $s['custom'], '已經有資料列了，就是自訂（即使低風險那個數字等於預設）');
});

test('設定：異動紀錄最多回 10 筆，最新的在前', function () use ($stH) {
    $svc = stMake($repo, $clock);
    for ($i = 0; $i < 14; $i++) {
        $clock['now'] = gmdate('Y-m-d H:i:s', strtotime(ST_T1 . ' UTC') + $i * 60);
        $svc->saveRisk(['risk_low' => (string) (90 + $i % 2), 'risk_medium' => '60'], 'u', $stH);
    }
    $h = $svc->riskSettings($stH)['history'];
    assertSame(SettingsService::HISTORY_LIMIT, count($h));
    assertTrue(count($repo->history) > 10, '資料表裡的比畫面多');
    assertSame(Ioc\Time::toTaipei(gmdate('Y-m-d H:i:s', strtotime(ST_T1 . ' UTC') + 13 * 60)), $h[0]['time'], '最新的一筆是最後一次儲存（台北時間）');
});

test('設定：只有 admin 能儲存，檢視者只能讀；沒有角色什麼都不行', function () use ($stH, $stV) {
    $svc = stMake($repo);
    assertThrows(AccessDenied::class, fn () => $svc->saveRisk(['risk_low' => '90', 'risk_medium' => '70'], 'viewer', $stV));
    assertThrows(AccessDenied::class, fn () => $svc->saveRisk(['risk_low' => '90', 'risk_medium' => '70'], 'nobody', null));
    assertSame([[], [], 0], [$repo->rows, $repo->history, $repo->puts], '被拒絕不能寫');
    assertSame(85, $svc->riskSettings($stV)['low'], '檢視者能讀設定頁');
    assertSame(['low' => 85, 'medium' => 65], stThresholds($svc, $stV));
    assertThrows(AccessDenied::class, fn () => $svc->riskSettings(null));
    assertThrows(AccessDenied::class, fn () => stThresholds($svc, null));
    $svc->saveRisk(['risk_low' => '90', 'risk_medium' => '70'], 'admin', $stH);
    assertThrows(AccessDenied::class, fn () => $svc->saveRisk(['risk_low' => '99', 'risk_medium' => '1'], 'viewer', $stV));
    assertSame(['low' => 90, 'medium' => 70], stThresholds($svc, $stV), '被拒絕的儲存沒有改到已設定的值');
});

test('設定：資料表裡的值壞了（只剩一個、不是數字、大小顛倒、超出範圍）就是壞掉，不悄悄套預設值', function () use ($stH) {
    $cases = [
        '只有低風險那一列' => [ST_LOW => stRow('90')],
        '只有中等風險那一列' => [ST_MED => stRow('70')],
        '不是數字' => [ST_LOW => stRow('abc'), ST_MED => stRow('70')],
        '小數' => [ST_LOW => stRow('90.5'), ST_MED => stRow('70')],
        '兩個一樣' => [ST_LOW => stRow('70'), ST_MED => stRow('70')],
        '大小顛倒' => [ST_LOW => stRow('60'), ST_MED => stRow('80')],
        '超過 100' => [ST_LOW => stRow('101'), ST_MED => stRow('70')],
        '中等是 0' => [ST_LOW => stRow('90'), ST_MED => stRow('0')],
    ];
    foreach ($cases as $name => $rows) {
        $svc = stMake($repo);
        $repo->rows = $rows;
        assertThrows(IocError::class, fn () => stThresholds($svc, $stH), '不合法');
        $s = $svc->riskSettings($stH);
        assertSame([true, false], [$s['invalid'], $s['custom']], $name);
        assertSame([85, 65], [$s['low'], $s['medium']], $name . '：設定頁填的是預設值，讓管理者能直接重存');
        // 重新儲存就修好，連「剛好等於預設值」也要寫進去，否則資料表還是壞的
        $fixed = $svc->saveRisk(['risk_low' => '85', 'risk_medium' => '65'], 'admin', $stH);
        assertSame([false, true, 85, 65], [$fixed['invalid'], $fixed['custom'], $fixed['low'], $fixed['medium']], $name);
        assertSame(['low' => 85, 'medium' => 65], stThresholds($svc, $stH), $name);
    }
});

test('設定：寫入中途失敗整筆復原（不會只剩一個門檻值被改掉）', function () use ($stH) {
    $svc = stMake($repo);
    $svc->saveRisk(['risk_low' => '90', 'risk_medium' => '70'], 'admin', $stH);
    $before = [$repo->rows, $repo->history, $repo->puts];
    $repo->failOnHistory = true;
    assertThrows(RuntimeException::class, fn () => $svc->saveRisk(['risk_low' => '95', 'risk_medium' => '80'], 'admin', $stH));
    assertSame($before, [$repo->rows, $repo->history, $repo->puts]);
    $repo->failOnHistory = false;
    assertSame(['low' => 90, 'medium' => 70], stThresholds($svc, $stH));
});

test('設定：設定名稱都有中文標籤，預設值與畫面用的等級名稱一致', function () {
    foreach ([ST_LOW, ST_MED] as $key) {
        assertTrue(isset(SettingsService::KEY_LABEL[$key]), $key);
    }
    assertSame(['low', 'medium', 'high'], array_keys(RiskScore::LEVEL_LABEL));
    assertTrue(RiskScore::DEFAULT_THRESHOLDS['medium'] >= 1 && RiskScore::DEFAULT_THRESHOLDS['medium'] < RiskScore::DEFAULT_THRESHOLDS['low'] && RiskScore::DEFAULT_THRESHOLDS['low'] <= 100,
        '預設值本身要符合驗證規則');
});

test('總覽設定：沒設定過——站點是空的、門檻與內部網段是預設值、沒有錯誤；整張表只讀一次', function () use ($stV) {
    $svc = stMake($repo);
    $c = $svc->overviewSettings($stV);
    assertSame([1, []], [$repo->reads, $c['errors']]);
    assertTrue($c['sites']->isEmpty());
    assertSame([RiskScore::DEFAULT_THRESHOLDS, IpNets::DEFAULTS], [$c['thresholds'], $c['nets']]);
});

test('總覽設定：設定過的值與設定頁讀到的一致，而且三組合起來仍然只讀一次表', function () use ($stH, $stV) {
    $svc = stMake($repo);
    $svc->saveRisk(['risk_low' => '95', 'risk_medium' => '80'], 'admin', $stH);
    $svc->saveNetwork(['cidrs' => "192.168.0.0/16\n10.0.0.0/8"], 'admin', $stH);
    $svc->saveSites(['sites' => [['id' => null, 'name' => '台南', 'cidrs' => '10.1.0.0/16']]], 'admin', $stH);
    $repo->reads = 0;
    $c = $svc->overviewSettings($stV);
    assertSame(1, $repo->reads, '站點、門檻、內部網段不能各讀一次整張表');
    assertSame(['low' => 95, 'medium' => 80], $c['thresholds']);
    assertSame(['10.0.0.0/8', '192.168.0.0/16'], $c['nets']);
    assertSame('台南', $c['sites']->siteOf('10.1.2.3'));
    $page = [$svc->riskSettings($stV), $svc->networkSettings($stV)];
    assertSame([[], $page[0]['low'], $page[0]['medium'], $page[1]['nets']], [$c['errors'], $c['thresholds']['low'], $c['thresholds']['medium'], $c['nets']]);
});

test('總覽設定：三組各自成敗——壞掉的那組是 null 並附原因，不影響另外兩組，也不套預設值', function () use ($stH) {
    $broken = [
        'thresholds' => [ST_LOW => stRow('90')],                                    // 只剩一個門檻值
        'nets'       => [SettingsService::NET_INTERNAL => stRow('garbage')],        // 不是網段
        'sites'      => ['site.1' => stRow('壞掉')],                                 // 讀不懂的站點
    ];
    foreach ($broken as $bad => $rows) {
        $svc = stMake($repo);
        $repo->rows = $rows;
        $c = $svc->overviewSettings($stH);
        assertSame([$bad], array_keys($c['errors']), $bad . '：只有壞掉的那組有錯誤');
        assertTrue($c['errors'][$bad] instanceof IocError && $c['errors'][$bad]->getMessage() !== '', $bad . '：要附原因');
        assertSame(null, $c[$bad], $bad . '：壞掉就是 null，不能套預設值');
        foreach (array_diff(['sites', 'thresholds', 'nets'], [$bad]) as $good) {
            assertTrue($c[$good] !== null, $bad . ' 壞掉不能拖累 ' . $good);
        }
        assertSame(1, $repo->reads, $bad);
    }
    $svc = stMake($repo);
    $repo->rows = $broken['thresholds'] + $broken['nets'] + $broken['sites'];
    $c = $svc->overviewSettings($stH);
    assertSame([null, null, null], [$c['sites'], $c['thresholds'], $c['nets']]);
    $parts = array_keys($c['errors']);
    sort($parts);
    assertSame(['nets', 'sites', 'thresholds'], $parts, '三組都壞就三個原因');
});

test('總覽設定：整張表讀不到（資料表還沒建立）——三組都是 null、原因是同一個例外，不往外丟', function () use ($stV) {
    $svc = stMake($repo);
    $repo->failOnRead = true;
    $c = $svc->overviewSettings($stV);
    assertSame([null, null, null], [$c['sites'], $c['thresholds'], $c['nets']]);
    $parts = array_keys($c['errors']);
    sort($parts);
    assertSame(['nets', 'sites', 'thresholds'], $parts);
    assertTrue($c['errors']['sites'] instanceof RuntimeException && $c['errors']['sites'] === $c['errors']['nets'], '同一個讀取失敗');
    assertSame(1, $repo->reads, '失敗了也不重試三次');
});

test('總覽設定：失敗原因記 log——共用同一個例外只記一行（列出受影響的組），不同的例外各記一行', function () {
    require_once dirname(__DIR__, 2) . '/librenms/ioc/endpoint.php';      // 只有函式與常數，載入不會碰 LibreNMS
    $file = tempnam(sys_get_temp_dir(), 'ioclog');
    $old  = (string) ini_set('error_log', $file);
    try {
        $shared = new RuntimeException('連線失敗');
        ioc_log_settings_errors(['sites' => $shared, 'thresholds' => $shared, 'nets' => $shared]);
        ioc_log_settings_errors(['sites' => new IocError('站點壞了'), 'nets' => new IocError('網段壞了')]);
        ioc_log_settings_errors([]);
    } finally {
        ini_set('error_log', $old);
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        unlink($file);
    }
    assertSame(3, count($lines), '共用的例外一行＋兩個不同的例外各一行，沒有錯誤就不記：' . json_encode($lines, JSON_UNESCAPED_UNICODE));
    assertTrue(strpos($lines[0], '[ioc] settings sites/thresholds/nets: 連線失敗') !== false, $lines[0]);
    assertTrue(strpos($lines[1], '[ioc] settings sites: 站點壞了') !== false && strpos($lines[2], '[ioc] settings nets: 網段壞了') !== false);
});

test('總覽設定：讀進來之後解析出程式 bug（不是 IocError）要往上拋，不能被歸類成「讀不到」', function () use ($stH) {
    $svc = stMake($repo);
    // 資料列的值不是字串：repo 契約被破壞，Sites::fromStored(string) 會丟 TypeError。這種情況是 bug，應該炸出來（500＋log），
    // 而不是在畫面上顯示成站點設定讀不到
    $repo->rows = ['site.1' => ['value' => ['不是字串'], 'updated_by' => 'admin', 'updated_at' => '2026-10-01 00:00:00']];
    assertThrows(TypeError::class, fn () => $svc->overviewSettings($stH));
});

test('總覽設定：沒有角色是 AccessDenied（不算讀不到，也不碰資料表）；檢視者可以讀', function () use ($stV) {
    $svc = stMake($repo);
    $repo->failOnRead = true;
    assertThrows(AccessDenied::class, fn () => $svc->overviewSettings(null));
    assertSame(0, $repo->reads, '權限不夠就不能去讀資料表');
    $repo->failOnRead = false;
    assertSame([], $svc->overviewSettings($stV)['errors']);
});
