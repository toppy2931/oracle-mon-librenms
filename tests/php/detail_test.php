<?php
declare(strict_types=1);

use Ioc\Access;
use Ioc\CaseCatalog;
use Ioc\CaseView;
use Ioc\EvidenceService;
use Ioc\GraylogUnavailable;
use Ioc\Time;
use Ioc\UnavailableLogSource;
use Ioc\ValidationError;
use Ioc\WindowedLogSource;

const DT_NOW = 1760000000;

/** 假的「指定時間範圍」日誌來源：記下每次查詢的範圍與查詢字串，結果由測試給的函式決定。 */
final class FakeWindowedSource implements WindowedLogSource
{
    /** @var array<int,array<string,mixed>> */
    public array $calls = [];
    /** @var callable */
    public $msg;
    /** @var callable */
    public $agg;

    public function __construct(?callable $msg = null, ?callable $agg = null)
    {
        $this->msg = $msg ?? static fn () => [];
        $this->agg = $agg ?? static fn () => [];
    }

    public function aggregate(string $query, int $rangeSeconds, array $groupBy, array $metrics, int $limit = 100): array
    {
        throw new RuntimeException('證據要用絕對時間範圍查詢，不能用「最近 N 秒」');
    }

    public function messages(string $query, int $rangeSeconds, array $fields, int $size): array
    {
        throw new RuntimeException('證據要用絕對時間範圍查詢，不能用「最近 N 秒」');
    }

    public function messagesBetween(string $query, string $fromIso, string $toIso, array $fields, int $size): array
    {
        $this->calls[] = ['kind' => 'messages', 'query' => $query, 'from' => $fromIso, 'to' => $toIso, 'size' => $size, 'fields' => $fields];
        return ($this->msg)();
    }

    public function aggregateBetween(string $query, string $fromIso, string $toIso, array $groupBy, array $metrics, int $limit = 100): array
    {
        $this->calls[] = ['kind' => 'aggregate', 'query' => $query, 'from' => $fromIso, 'to' => $toIso, 'groupBy' => $groupBy];
        return ($this->agg)();
    }
}

function dtCase(int $secondsAgo = 7200, ?string $query = 'user_name:"administrator"'): array
{
    return ['id' => 7, 'evidence_query' => $query, 'created_at' => gmdate('Y-m-d H:i:s', DT_NOW - $secondsAgo)];
}

function dtIso(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s.000\Z', $ts);
}

function dtMsg(int $secondsAgo, array $extra = []): array
{
    return $extra + ['timestamp' => dtIso(DT_NOW - $secondsAgo), 'event_action' => 'login_failed', 'user_name' => 'administrator',
                     'src_ip' => '10.20.5.23', 'dst_ip' => null, 'host_name' => 'GIS-SRV-01', 'severity' => 'medium', 'log_type' => 'linux'];
}

// ── 事件類型目錄 ─────────────────────────────────────────────────────

test('事件類型目錄：每個類型都有名稱與至少 3 步建議處置；攻擊技術編號格式正確，只有「其他」沒有', function () {
    foreach (CaseCatalog::CATEGORIES as $key => $c) {
        assertTrue($c['label'] !== '' && count($c['steps']) >= 3, $key);
        foreach ($c['steps'] as $s) {
            assertTrue(is_string($s) && $s !== '', "{$key} 的建議處置不能有空白項目");
        }
        if ($key === CaseCatalog::OTHER) {
            assertSame(null, $c['attack'], '其他（未分類）不能掛一個攻擊技術');
        } else {
            assertTrue(preg_match('/^T\d{4}(\.\d{3})?$/', $c['attack']['id']) === 1 && $c['attack']['name'] !== '', "{$key} 的攻擊技術：" . json_encode($c['attack'], JSON_UNESCAPED_UNICODE));
        }
    }
    assertSame(array_keys(CaseCatalog::CATEGORIES), array_keys(CaseCatalog::labels()));
});

test('事件類型目錄：沒有類型、空字串、不認得的代碼（例如目錄之後移除了某類型）一律當成「其他」', function () {
    foreach ([null, '', 'removed_type', 'OTHER'] as $k) {
        assertSame('other', CaseCatalog::get($k)['key'], var_export($k, true));
    }
    assertSame('suspicious_login', CaseCatalog::get('suspicious_login')['key']);
    assertTrue(CaseCatalog::isValid('fw_internal_block') && !CaseCatalog::isValid('') && !CaseCatalog::isValid('nope'));
});

// ── 建立事件案的新欄位 ───────────────────────────────────────────────

test('事件案：類型、目的／關聯主機、影響範圍、標籤存得進去；沒填就是 NULL', function () {
    $svc = makeService($repo);
    $H = Access::HANDLER;
    $id = $svc->create(['title' => 't', 'category' => 'suspicious_login', 'target_host' => 'DC01', 'related_host' => 'FS02',
                        'impact_scope' => '財產科共用資料夾', 'tags' => '登入失敗, 內網'], 'alice', $H)['id'];
    $c = $repo->getCase($id);
    assertSame(['suspicious_login', 'DC01', 'FS02', '財產科共用資料夾', '登入失敗,內網'], [$c['category'], $c['target_host'], $c['related_host'], $c['impact_scope'], $c['tags']]);
    $bare = $repo->getCase($svc->create(['title' => 'bare'], 'alice', $H)['id']);
    assertSame([null, null, null, null, null], [$bare['category'], $bare['target_host'], $bare['related_host'], $bare['impact_scope'], $bare['tags']]);
});

test('事件案：不認得的事件類型被拒絕，且不建立', function () {
    $svc = makeService($repo);
    $e = assertThrows(ValidationError::class, fn () => $svc->create(['title' => 't', 'category' => 'made_up'], 'alice', Access::HANDLER), '事件類型');
    assertSame(1, count($e->errors()));
    assertSame([], $repo->cases);
});

test('事件案：標籤整理——各種分隔符號、去空白、去重複；超過 5 個、單個超過 20 字、控制字元、壞編碼都被拒絕', function () {
    $svc = makeService($repo);
    $H = Access::HANDLER;
    $tag = fn ($in) => $repo->getCase($svc->create(['title' => 't', 'tags' => $in], 'a', $H)['id'])['tags'];
    assertSame('登入,帳號,外部', $tag("登入, 帳號，登入、 \n外部;  "));
    assertSame('a', $tag('  a  '));
    assertSame(null, $tag(' , ，、 '), '只有分隔符號等於沒有標籤');
    assertSame('1,2,3,4,5', $tag('1,2,3,4,5'));
    assertSame(str_repeat('字', 20), $tag(str_repeat('字', 20)), '剛好 20 字可以');
    foreach (['1,2,3,4,5,6' => '最多 5 個', str_repeat('字', 21) => '20 個字', "ok,bad\x07tag" => '控制字元', "ok,\xff\xfe" => '編碼'] as $bad => $why) {
        $e = assertThrows(ValidationError::class, fn () => $svc->create(['title' => 't', 'tags' => $bad], 'a', $H), $why);
        assertSame(1, count($e->errors()), $why);
    }
});

test('事件案：標籤的前後空白包含全形空白（U+3000）與不換行空白（U+00A0），去重與顯示才不會錯', function () {
    $svc = makeService($repo);
    $tag = fn ($in) => $repo->getCase($svc->create(['title' => 't', 'tags' => $in], 'a', Access::HANDLER)['id'])['tags'];
    assertSame('登入', $tag("登入，\u{3000}登入"), '全形空白開頭的重複標籤要被去掉');
    assertSame('a,b', $tag("\u{3000}a\u{3000},\u{00A0}b\u{00A0}"));
    assertSame(null, $tag("\u{3000}，\u{3000}"), '只有全形空白等於沒有標籤');
    assertSame('登　入', $tag("登\u{3000}入"), '標籤中間的全形空白不動');
});

test('查詢條件的括號與引號要成對（引號裡的括號、反斜線跳脫的字元不算）', function () {
    foreach (['user_name:"administrator" AND src_ip:"10.20.5.23"', '(a OR b) AND c', 'x:"a)b"', 'x:a\\(b', 'x:"say \\"hi\\" (now"', '((a) OR (b))', '帳號:"王小明"', '', 'a b c'] as $ok) {
        assertTrue(Ioc\CaseService::isBalancedQuery($ok), "應該成對：{$ok}");
    }
    foreach (['a) OR (b', '(a', 'a)', 'x:"abc', ')(', '((a)', 'x:"a\\"', '(x:"a)'] as $bad) {
        assertTrue(!Ioc\CaseService::isBalancedQuery($bad), "應該不成對：{$bad}");
    }
});

test('事件案：括號或引號不成對的查詢條件在建立時被拒絕（不然會跳出「排除範例資料」的包裝，或讓每次打開詳情都收到 Graylog 400）', function () {
    $svc = makeService($repo);
    $H = Access::HANDLER;
    $e = assertThrows(ValidationError::class, fn () => $svc->create(['title' => 't', 'evidence_query' => 'x) OR (y'], 'a', $H), '括號');
    assertSame(1, count($e->errors()));
    assertSame([], $repo->cases, '被拒絕不能建立');
    $id = $svc->create(['title' => 't', 'evidence_query' => 'user_name:"a)b" AND (src_ip:"10.0.0.1" OR src_ip:"10.0.0.2")'], 'a', $H)['id'];
    assertSame('user_name:"a)b" AND (src_ip:"10.0.0.1" OR src_ip:"10.0.0.2")', $repo->getCase($id)['evidence_query']);
});

test('CaseView：有結案時間但時間壞掉，持續時間是「—」並標示已結案，不能算成還在計時', function () {
    $v = CaseView::detail(['id' => 1, 'title' => 't', 'status' => 'closed', 'severity' => 'low', 'resolution' => 'fixed', 'created_at' => '2026-10-20 05:00:00',
                           'updated_at' => '2026-10-20 05:00:00', 'closed_at' => 'garbage'], strtotime('2026-10-20 09:00:00 UTC'));
    assertSame(['—', true], [$v['duration_label'], $v['duration_ended']]);
});

test('事件類型目錄：稽核日誌被清除與稽核原則被修改是兩個類型，各有各的攻擊技術', function () {
    assertSame('T1070.001', CaseCatalog::get('audit_log_cleared')['attack']['id']);
    assertSame('T1562.002', CaseCatalog::get('audit_policy_change')['attack']['id']);
    assertTrue(!CaseCatalog::isValid('audit_tamper'), '舊的合併類型不再存在');
});

test('事件案：目的主機、關聯主機最多 128 字，影響範圍最多 200 字', function () {
    $svc = makeService($repo);
    $H = Access::HANDLER;
    $svc->create(['title' => 't', 'target_host' => str_repeat('a', 128), 'related_host' => str_repeat('b', 128), 'impact_scope' => str_repeat('字', 200)], 'a', $H);
    foreach ([['target_host', 129, '目的主機'], ['related_host', 129, '關聯主機'], ['impact_scope', 201, '影響範圍']] as [$field, $n, $label]) {
        assertThrows(ValidationError::class, fn () => $svc->create(['title' => 't', $field => str_repeat('字', $n)], 'a', $H), $label);
    }
});

test('事件案：檢視者不能用新欄位建立事件案（權限先於驗證）', function () {
    $svc = makeService($repo);
    assertThrows(Ioc\AccessDenied::class, fn () => $svc->create(['title' => 't', 'category' => 'made_up'], 'v', Access::VIEWER));
    assertSame([], $repo->cases);
});

// ── 顯示轉換 ─────────────────────────────────────────────────────────

test('CaseView：新欄位的顯示（標籤變陣列、沒有類型顯示「其他（未分類）」、舊案沒有這些鍵也不會出錯）', function () {
    $v = CaseView::row(['id' => 1, 'title' => 't', 'status' => 'new', 'severity' => 'low', 'created_at' => '2026-10-20 05:00:00', 'updated_at' => '2026-10-20 05:00:00',
                        'category' => 'suspicious_login', 'target_host' => 'DC01', 'related_host' => null, 'impact_scope' => '全所', 'tags' => '登入失敗,內網', 'resolution' => null]);
    assertSame(['suspicious_login', '可疑登入', 'DC01', '', '全所', ['登入失敗', '內網']], [$v['category'], $v['category_label'], $v['target_host'], $v['related_host'], $v['impact_scope'], $v['tags']]);
    $old = CaseView::row(['id' => 2, 'title' => 'old', 'status' => 'new', 'severity' => 'low', 'created_at' => '2026-10-20 05:00:00', 'updated_at' => '2026-10-20 05:00:00', 'resolution' => null]);
    assertSame(['other', '其他（未分類）', '', '', '', []], [$old['category'], $old['category_label'], $old['target_host'], $old['related_host'], $old['impact_scope'], $old['tags']]);
    assertSame([], CaseView::row(['id' => 3, 'title' => 't', 'status' => 'new', 'severity' => 'low', 'tags' => ',, ,', 'created_at' => null, 'updated_at' => null, 'resolution' => null])['tags']);
});

test('CaseView：詳情帶攻擊技術與建議處置（依類型）、已持續時間（未結案算到現在，已結案算到結案）', function () {
    $base = ['id' => 1, 'title' => 't', 'status' => 'new', 'severity' => 'high', 'resolution' => null, 'category' => 'suspicious_login',
             'created_at' => '2026-10-20 05:00:00', 'updated_at' => '2026-10-20 05:00:00', 'closed_at' => null];
    $now = strtotime('2026-10-20 07:15:00 UTC');
    $open = CaseView::detail($base, $now);
    assertSame(['T1110', '2 小時 15 分', false], [$open['attack']['id'], $open['duration_label'], $open['duration_ended']]);
    assertSame(CaseCatalog::get('suspicious_login')['steps'], $open['steps']);
    $closed = CaseView::detail(['closed_at' => '2026-10-20 05:30:00', 'status' => 'closed', 'resolution' => 'fixed'] + $base, $now);
    assertSame(['30 分鐘', true], [$closed['duration_label'], $closed['duration_ended']]);
    $other = CaseView::detail(['category' => null] + $base, $now);
    assertSame([null, '其他（未分類）'], [$other['attack'], $other['category_label']]);
    assertSame('—', CaseView::detail(['created_at' => 'garbage'] + $base, $now)['duration_label'], '壞掉的建立時間不能算出一個時間');
});

test('Time：經過時間的中文寫法', function () {
    foreach ([[-5, '不到 1 分鐘'], [0, '不到 1 分鐘'], [59, '不到 1 分鐘'], [60, '1 分鐘'], [3599, '59 分鐘'], [3600, '1 小時 0 分'], [8100, '2 小時 15 分'],
              [86399, '23 小時 59 分'], [86400, '1 天 0 小時'], [93784, '1 天 2 小時'], [10 * 86400 + 5 * 3600, '10 天 5 小時']] as [$s, $want]) {
        assertSame($want, Time::humanDuration($s), (string) $s);
    }
});

// ── 證據時間軸與關聯指標 ──────────────────────────────────────────────

test('證據：查詢範圍是事件建立前 24 小時到建立後 1 小時，用 UTC 絕對時間；查詢條件包在括號裡並排除範例資料', function () {
    $src = new FakeWindowedSource();
    $case = dtCase(7200);
    $created = DT_NOW - 7200;
    $r = (new EvidenceService($src, false, DT_NOW))->build($case);
    assertSame(true, $r['ok']);
    assertSame(2, count($src->calls), '時間軸一次、各來源筆數一次');
    foreach ($src->calls as $c) {
        assertSame([dtIso($created - 86400), dtIso($created + 3600), '(user_name:"administrator") AND NOT ioc_demo:true'], [$c['from'], $c['to'], $c['query']]);
    }
    assertSame(30, $src->calls[0]['size'], '時間軸最多 30 筆');
    assertSame(['log_type'], $src->calls[1]['groupBy']);
    assertSame(Time::toTaipei(gmdate('Y-m-d H:i:s', $created - 86400)), $r['data']['window']['from'], '畫面的時間是台北時間');
    assertSame(['from' => gmdate('Y-m-d H:i:s', $created - 86400), 'to' => gmdate('Y-m-d H:i:s', $created + 3600)],
        $r['data']['window_utc'], '快照必須保存與實際查詢完全相同的 UTC 區間');
    assertSame('(user_name:"administrator") AND NOT ioc_demo:true', $r['data']['query'], '快照必須保存實際查詢條件');
});

test('證據：範例資料模式只查範例資料，兩者不混雜', function () {
    $src = new FakeWindowedSource();
    (new EvidenceService($src, true, DT_NOW))->build(dtCase());
    assertSame('(user_name:"administrator") AND ioc_demo:true', $src->calls[0]['query']);
});

test('證據：剛建立的事件，查詢結束時間不超過現在', function () {
    $src = new FakeWindowedSource();
    (new EvidenceService($src, false, DT_NOW))->build(dtCase(600));
    assertSame(dtIso(DT_NOW), $src->calls[0]['to'], '建立 10 分鐘前：建立後 1 小時還沒到，只查到現在');
});

test('證據：時間軸由舊到新（Graylog 回新到舊），中文動作、需注意程度、帳號／來源／設備；沒分類的日誌明講，不放原始訊息', function () {
    $src = new FakeWindowedSource(fn () => [
        dtMsg(60),
        dtMsg(120, ['event_action' => null, 'user_name' => null, 'src_ip' => '', 'host_name' => 'SW-01', 'log_type' => 'network', 'severity' => null, 'message' => '機密內容 password=hunter2']),
        dtMsg(180, ['event_action' => 'privileged_logon', 'severity' => 'high']),
    ]);
    $r = (new EvidenceService($src, false, DT_NOW))->build(dtCase(0));
    $items = $r['data']['items'];
    assertSame(3, count($items));
    assertSame(['使用高權限', '（尚未分類的日誌）', '登入失敗'], array_column($items, 'action'), '最舊的在最前面');
    assertSame('高', $items[0]['level']);
    assertSame('帳號 administrator · 來源 10.20.5.23 · 設備 GIS-SRV-01', $items[0]['detail']);
    assertSame(['（尚未分類的日誌）', '設備 SW-01', '網路設備', ''], [$items[1]['action'], $items[1]['detail'], $items[1]['type'], $items[1]['level']]);
    assertTrue(strpos(json_encode($r, JSON_UNESCAPED_UNICODE), 'hunter2') === false, '原始訊息不能被帶出來');
    assertSame('登入失敗', $items[2]['action']);
    assertTrue($items[0]['time'] <= $items[1]['time'] && $items[1]['time'] <= $items[2]['time'], '時間由舊到新：' . json_encode(array_column($items, 'time')));
});

test('證據：關聯指標——總筆數、各來源類型筆數（只列有資料的）、其他來源；弱點明講尚未接入，不放數字', function () {
    $src = new FakeWindowedSource(null, fn () => [['log_type' => 'firewall', 'count' => 120], ['log_type' => 'linux', 'count' => 3], ['log_type' => null, 'count' => 2]]);
    $r = (new EvidenceService($src, false, DT_NOW))->build(dtCase());
    $by = array_column($r['data']['indicators'], null, 'label');
    assertSame(125, $r['data']['total']);
    assertSame([125, 120, 3, 2], [$by['相關日誌總筆數']['value'], $by['防火牆日誌']['value'], $by['Linux 主機日誌']['value'], $by['其他來源日誌']['value']]);
    assertSame([null, '尚未接入'], [$by['相關弱點']['value'], $by['相關弱點']['na']]);
    assertTrue(!isset($by['Windows 伺服器／網域日誌']) && !isset($by['網路設備日誌']), '沒有資料的來源類型不列（不假裝是 0 台設備正常）');
});

test('證據：被截斷要標示（時間軸只列最新 30 筆，實際更多）；剛好沒超過不標', function () {
    $many = fn (int $n) => fn () => array_map(fn ($i) => dtMsg($i), range(1, $n));
    $r = (new EvidenceService(new FakeWindowedSource($many(30), fn () => [['log_type' => 'linux', 'count' => 500]]), false, DT_NOW))->build(dtCase());
    assertSame([30, 500, true], [count($r['data']['items']), $r['data']['total'], $r['data']['truncated']]);
    $r2 = (new EvidenceService(new FakeWindowedSource($many(30), fn () => [['log_type' => 'linux', 'count' => 30]]), false, DT_NOW))->build(dtCase());
    assertSame(false, $r2['data']['truncated'], '剛好 30 筆，沒有被截掉');
    $r3 = (new EvidenceService(new FakeWindowedSource($many(5), fn () => [['log_type' => 'linux', 'count' => 5]]), false, DT_NOW))->build(dtCase());
    assertSame(false, $r3['data']['truncated']);
});

test('證據：真的沒有符合的日誌是「ok、0 筆」，不是失敗', function () {
    $r = (new EvidenceService(new FakeWindowedSource(), false, DT_NOW))->build(dtCase());
    assertSame([true, [], 0, false], [$r['ok'], $r['data']['items'], $r['data']['total'], $r['data']['truncated']]);
});

test('證據：Graylog 取不到是「無法取得」並帶原因，絕對不會變成空的時間軸', function () {
    $r = (new EvidenceService(new UnavailableLogSource('尚未設定 Graylog 唯讀帳號'), false, DT_NOW))->build(dtCase());
    assertSame([false, 'unavailable', '尚未設定 Graylog 唯讀帳號'], [$r['ok'], $r['reason'], $r['error']]);
    assertTrue(!isset($r['data']), '失敗時不能有資料欄位');
    $failing = new FakeWindowedSource(fn () => throw new GraylogUnavailable('Graylog 回應逾時'));
    $r2 = (new EvidenceService($failing, false, DT_NOW))->build(dtCase());
    assertSame([false, 'Graylog 回應逾時'], [$r2['ok'], $r2['error']]);
});

test('證據：只攔 GraylogUnavailable，其他例外是程式的錯，要往上拋', function () {
    $bug = new FakeWindowedSource(fn () => throw new RuntimeException('bug'));
    assertThrows(RuntimeException::class, fn () => (new EvidenceService($bug, false, DT_NOW))->build(dtCase()), 'bug');
});

test('證據：沒填查詢條件、或建立時間壞掉，不查 Graylog，並說明原因', function () {
    $src = new FakeWindowedSource();
    $svc = new EvidenceService($src, false, DT_NOW);
    foreach ([null, '', '   '] as $q) {
        $r = $svc->build(dtCase(7200, $q));
        assertSame([false, 'no_query'], [$r['ok'], $r['reason']], var_export($q, true));
        assertTrue(mb_strpos($r['error'], '查詢條件') !== false);
    }
    $bad = $svc->build(['id' => 1, 'evidence_query' => 'x', 'created_at' => 'garbage']);
    assertSame([false, 'bad_time'], [$bad['ok'], $bad['reason']]);
    assertSame([], $src->calls, '這兩種情況都不應該送出任何查詢');
});

test('證據：測試替身不允許用相對時間查詢（證據一定是絕對範圍）', function () {
    assertThrows(RuntimeException::class, fn () => (new FakeWindowedSource())->messages('q', 60, ['a'], 1), '絕對時間');
    assertThrows(GraylogUnavailable::class, fn () => (new UnavailableLogSource('x'))->messagesBetween('q', dtIso(0), dtIso(1), ['a'], 1));
    assertThrows(GraylogUnavailable::class, fn () => (new UnavailableLogSource('x'))->aggregateBetween('q', dtIso(0), dtIso(1), ['a'], ['count']));
});
