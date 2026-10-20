<?php
declare(strict_types=1);

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\CaseFlow;
use Ioc\CaseService;
use Ioc\InvalidTransition;
use Ioc\Labels;
use Ioc\NotFound;
use Ioc\ValidationError;

/** 時鐘每呼叫一次往前走一分鐘，讓排序與時間戳可預期。 */
function makeService(?MemoryCaseRepo &$repo = null): CaseService
{
    $repo = new MemoryCaseRepo();
    $t = 0;
    return new CaseService($repo, static function () use (&$t): string {
        return gmdate('Y-m-d H:i:s', 1760000000 + 60 * $t++);
    });
}

$H = Access::HANDLER;
$V = Access::VIEWER;

// ── 權限 ──
test('Access：admin=處理者、global-read=檢視者、其他=拒絕', function () {
    assertSame('handler', Access::level(true, false));
    assertSame('handler', Access::level(true, true));
    assertSame('viewer', Access::level(false, true));
    assertSame(null, Access::level(false, false));
    assertTrue(Access::canView('viewer') && Access::canView('handler') && !Access::canView(null));
    assertTrue(Access::canHandle('handler') && !Access::canHandle('viewer') && !Access::canHandle(null));
});

// ── 狀態流程 ──
test('CaseFlow：只有定義過的轉換才允許', function () {
    $ok = [['new', 'ack'], ['new', 'closed'], ['ack', 'in_progress'], ['ack', 'closed'], ['in_progress', 'closed'], ['closed', 'in_progress']];
    foreach ($ok as [$from, $to]) {
        assertTrue(CaseFlow::canMove($from, $to), "$from → $to 應允許");
    }
    $bad = [['new', 'in_progress'], ['new', 'new'], ['ack', 'new'], ['in_progress', 'ack'], ['in_progress', 'new'], ['closed', 'ack'], ['closed', 'new'], ['x', 'ack']];
    foreach ($bad as [$from, $to]) {
        assertTrue(!CaseFlow::canMove($from, $to), "$from → $to 應拒絕");
    }
});

test('Labels：每個狀態、處理結果、等級都有中文；未知代碼原樣顯示', function () {
    foreach (array_merge(Labels::ACTIONS, Labels::LOG_TYPES, Labels::SEVERITY, Labels::CASE_STATUS, Labels::RESOLUTION) as $code => $label) {
        assertTrue(is_string($label) && $label !== '', "$code 沒有中文");
    }
    assertSame('登入失敗', Labels::action('login_failed'));
    assertSame('something_new', Labels::action('something_new'));
    assertSame('待確認', Labels::caseStatus('new'));
});

// ── 建立 ──
test('建立：寫入案件，狀態待確認，並留下 created 歷程', function () use ($H) {
    $svc = makeService($repo);
    $r = $svc->create(['title' => ' 可疑登入 ', 'severity' => 'high', 'subject_user' => 'administrator', 'subject_ip' => '10.20.5.23'], 'alice', $H);
    assertSame(true, $r['created']);
    $c = $repo->cases[$r['id']];
    assertSame('可疑登入', $c['title']);
    assertSame('new', $c['status']);
    assertSame('manual', $c['source']);
    assertSame('alice', $c['created_by']);
    assertSame(null, $c['assignee']);
    $h = $repo->history[$r['id']];
    assertSame(1, count($h));
    assertSame(['created', null, 'new', 'alice'], [$h[0]['action'], $h[0]['from_status'], $h[0]['to_status'], $h[0]['actor']]);
});

test('建立：輸入驗證', function () use ($H) {
    $svc = makeService();
    $bad = [
        'title 空白'      => ['title' => '  '],
        'title 太長'      => ['title' => str_repeat('字', 201)],
        '等級不正確'      => ['title' => 'x', 'severity' => 'critical'],
        'info 不是事件案等級' => ['title' => 'x', 'severity' => 'info'],
        'IP 不合法'       => ['title' => 'x', 'subject_ip' => '10.20.5.999'],
        '帳號太長'        => ['title' => 'x', 'subject_user' => str_repeat('a', 129)],
        '說明太長'        => ['title' => 'x', 'summary' => str_repeat('a', 2001)],
        '查詢條件太長'    => ['title' => 'x', 'evidence_query' => str_repeat('a', 1001)],
    ];
    foreach ($bad as $label => $in) {
        assertThrows(ValidationError::class, fn () => $svc->create($in, 'alice', $H), '');
    }
    $e = assertThrows(ValidationError::class, fn () => $svc->create(['title' => '', 'severity' => 'x', 'subject_ip' => 'nope'], 'a', $H));
    assertSame(3, count($e->errors()), '多個錯誤要一次回報');
});

test('建立：IPv6 與中文標題可以', function () use ($H) {
    $svc = makeService($repo);
    $r = $svc->create(['title' => '防火牆阻擋突增', 'subject_ip' => '2001:db8::1'], 'a', $H);
    assertSame('2001:db8::1', $repo->cases[$r['id']]['subject_ip']);
});

test('建立：dedup_key 重複時回傳既有案件，不重複建立（冪等）', function () use ($H) {
    $svc = makeService($repo);
    $a = $svc->create(['title' => 'A', 'dedup_key' => 'k1'], 'a', $H, 'auto');
    $b = $svc->create(['title' => 'A again', 'dedup_key' => 'k1'], 'a', $H, 'auto');
    assertSame(true, $a['created']);
    assertSame(false, $b['created']);
    assertSame($a['id'], $b['id']);
    assertSame(1, count($repo->cases));
    assertSame('auto', $repo->cases[$a['id']]['source']);
});

test('建立：同時建立的競爭（唯一索引擋下後到者）也回傳既有案件', function () use ($H) {
    $svc = makeService($repo);
    // 模擬「先查沒有、插入時才撞唯一索引」：先查詢回傳 null，插入前偷偷放入對手的那筆
    $racing = new class($repo) extends MemoryCaseRepo {
        private bool $first = true;
        private MemoryCaseRepo $inner;
        public function __construct(MemoryCaseRepo $inner) { $this->inner = $inner; }
        public function findByDedupKey(string $key): ?array
        {
            if ($this->first) { $this->first = false; return null; }
            return $this->inner->findByDedupKey($key);
        }
        public function insertCase(array $row): int { return $this->inner->insertCase($row); }
        public function addHistory(int $caseId, array $row): void { $this->inner->addHistory($caseId, $row); }
        public function transaction(callable $fn) { return $this->inner->transaction($fn); }
    };
    $repo->insertCase(['title' => 'winner', 'dedup_key' => 'race', 'status' => 'new', 'updated_at' => 'x']);
    $svc2 = new CaseService($racing);
    $r = $svc2->create(['title' => 'loser', 'dedup_key' => 'race'], 'a', $H);
    assertSame(false, $r['created']);
    assertSame(1, $r['id']);
});

// ── 權限 ──
test('權限：檢視者與無權限者不能建立、處理、留言', function () use ($H, $V) {
    $svc = makeService($repo);
    $id = $svc->create(['title' => 'x'], 'a', $H)['id'];
    foreach ([$V, null] as $level) {
        assertThrows(AccessDenied::class, fn () => $svc->create(['title' => 'y'], 'v', $level));
        assertThrows(AccessDenied::class, fn () => $svc->transition($id, 'ack', 'v', $level));
        assertThrows(AccessDenied::class, fn () => $svc->comment($id, 'v', $level, 'hi'));
    }
    assertSame('new', $repo->cases[$id]['status'], '被拒絕後案件不能被改動');
    assertSame(1, count($repo->history[$id]));
});

test('權限：無權限者連檢視都不行；檢視者可以看但看不到可用的轉換', function () use ($H, $V) {
    $svc = makeService();
    $id = $svc->create(['title' => 'x'], 'a', $H)['id'];
    assertThrows(AccessDenied::class, fn () => $svc->get($id, null));
    assertThrows(AccessDenied::class, fn () => $svc->listCases([], null));
    assertThrows(AccessDenied::class, fn () => $svc->summary(null));
    assertSame([], $svc->get($id, $V)['allowed']);
    assertSame(['ack', 'closed'], $svc->get($id, $H)['allowed']);
    assertSame(1, count($svc->listCases([], $V)['items']));
});

// ── 狀態轉換 ──
test('轉換：完整流程，每一步都有歷程，結案帶處理結果', function () use ($H) {
    $svc = makeService($repo);
    $id = $svc->create(['title' => 'x'], 'alice', $H)['id'];
    $svc->transition($id, 'ack', 'bob', $H);
    $svc->transition($id, 'in_progress', 'bob', $H, null, '開始查');
    $svc->transition($id, 'closed', 'bob', $H, 'fixed', '已改密碼');
    $c = $repo->cases[$id];
    assertSame('closed', $c['status']);
    assertSame('fixed', $c['resolution']);
    assertTrue($c['closed_at'] !== null, '結案要有 closed_at');
    assertSame('bob', $c['assignee'], '確認時指派給操作者');
    $trail = array_map(fn ($h) => [$h['action'], $h['from_status'], $h['to_status'], $h['note']], $repo->history[$id]);
    assertSame([
        ['created', null, 'new', null],
        ['status_changed', 'new', 'ack', null],
        ['status_changed', 'ack', 'in_progress', '開始查'],
        ['status_changed', 'in_progress', 'closed', '已改密碼'],
    ], $trail);
    assertSame('fixed', $repo->history[$id][3]['resolution']);
});

test('轉換：不合法的轉換被拒絕，而且不留任何痕跡', function () use ($H) {
    $svc = makeService($repo);
    $id = $svc->create(['title' => 'x'], 'a', $H)['id'];
    $e = assertThrows(InvalidTransition::class, fn () => $svc->transition($id, 'in_progress', 'a', $H));
    assertTrue(mb_strpos($e->getMessage(), '待確認') !== false && mb_strpos($e->getMessage(), '處理中') !== false, '訊息要用中文狀態名稱');
    assertSame('new', $repo->cases[$id]['status']);
    assertSame(1, count($repo->history[$id]), '失敗的轉換不能寫歷程');
});

test('轉換：結案一定要有合法的處理結果；非結案不保留處理結果', function () use ($H) {
    $svc = makeService($repo);
    $id = $svc->create(['title' => 'x'], 'a', $H)['id'];
    assertThrows(ValidationError::class, fn () => $svc->transition($id, 'closed', 'a', $H), '處理結果');
    assertThrows(ValidationError::class, fn () => $svc->transition($id, 'closed', 'a', $H, 'whatever'));
    assertThrows(ValidationError::class, fn () => $svc->transition($id, 'bogus', 'a', $H));
    assertSame('new', $repo->cases[$id]['status']);
    $svc->transition($id, 'closed', 'a', $H, 'false_positive');
    assertSame('false_positive', $repo->cases[$id]['resolution']);
});

test('轉換：重新開啟會清掉處理結果與結案時間', function () use ($H) {
    $svc = makeService($repo);
    $id = $svc->create(['title' => 'x'], 'a', $H)['id'];
    $svc->transition($id, 'closed', 'a', $H, 'accepted');
    $svc->transition($id, 'in_progress', 'b', $H, 'resolution ignored', '重新調查');
    $c = $repo->cases[$id];
    assertSame('in_progress', $c['status']);
    assertSame(null, $c['resolution']);
    assertSame(null, $c['closed_at']);
    assertSame('b', $c['assignee'], '沒有負責人時指派給重新開啟的人');
});

test('轉換：已有負責人不會被換掉；找不到案件', function () use ($H) {
    $svc = makeService($repo);
    $id = $svc->create(['title' => 'x'], 'a', $H)['id'];
    $svc->transition($id, 'ack', 'first', $H);
    $svc->transition($id, 'in_progress', 'second', $H);
    assertSame('first', $repo->cases[$id]['assignee']);
    assertThrows(NotFound::class, fn () => $svc->transition(999, 'ack', 'a', $H));
    assertThrows(NotFound::class, fn () => $svc->get(999, $H));
    assertThrows(NotFound::class, fn () => $svc->comment(999, 'a', $H, 'x'));
});

test('轉換：備註超長被拒絕', function () use ($H) {
    $svc = makeService();
    $id = $svc->create(['title' => 'x'], 'a', $H)['id'];
    assertThrows(ValidationError::class, fn () => $svc->transition($id, 'ack', 'a', $H, null, str_repeat('a', 2001)));
});

// ── 留言 ──
test('留言：寫進歷程並更新時間，不改狀態；空白留言被拒絕', function () use ($H) {
    $svc = makeService($repo);
    $id = $svc->create(['title' => 'x'], 'a', $H)['id'];
    $before = $repo->cases[$id]['updated_at'];
    $svc->comment($id, 'bob', $H, ' 已通知單位主管 ');
    assertSame('new', $repo->cases[$id]['status']);
    assertTrue($repo->cases[$id]['updated_at'] > $before, 'updated_at 要更新');
    $last = end($repo->history[$id]);
    assertSame(['comment', '已通知單位主管', 'bob'], [$last['action'], $last['note'], $last['actor']]);
    assertThrows(ValidationError::class, fn () => $svc->comment($id, 'bob', $H, '   '));
});

// ── 列表與統計 ──
test('列表：依更新時間由新到舊；open=未結案；分頁與 has_more', function () use ($H) {
    $svc = makeService($repo);
    $ids = [];
    foreach (['a', 'b', 'c', 'd'] as $t) {
        $ids[$t] = $svc->create(['title' => $t], 'x', $H)['id'];
    }
    $svc->transition($ids['a'], 'closed', 'x', $H, 'fixed');
    $svc->transition($ids['b'], 'ack', 'x', $H);
    $all = $svc->listCases([], $H);
    assertSame(['b', 'a', 'd', 'c'], array_column($all['items'], 'title'), '最近動過的在最前面');
    assertSame(['b', 'd', 'c'], array_column($svc->listCases(['status' => 'open'], $H)['items'], 'title'));
    assertSame(['a'], array_column($svc->listCases(['status' => 'closed'], $H)['items'], 'title'));
    $p1 = $svc->listCases(['limit' => 3], $H);
    assertSame([3, true], [count($p1['items']), $p1['has_more']]);
    $p2 = $svc->listCases(['limit' => 3, 'offset' => 3], $H);
    assertSame([1, false], [count($p2['items']), $p2['has_more']]);
    assertThrows(ValidationError::class, fn () => $svc->listCases(['status' => 'bogus'], $H));
});

test('列表：limit 被夾在 1～200', function () use ($H) {
    $svc = makeService($repo);
    $svc->create(['title' => 'x'], 'a', $H);
    assertSame(1, count($svc->listCases(['limit' => 0], $H)['items']));
    assertSame(1, count($svc->listCases(['limit' => 100000], $H)['items']));
});

test('統計：各狀態都有值（沒有的是 0），open 是未結案合計', function () use ($H) {
    $svc = makeService($repo);
    assertSame(['new' => 0, 'ack' => 0, 'in_progress' => 0, 'closed' => 0, 'open' => 0], $svc->summary($H));
    $a = $svc->create(['title' => 'a'], 'x', $H)['id'];
    $svc->create(['title' => 'b'], 'x', $H);
    $svc->create(['title' => 'c'], 'x', $H);
    $svc->transition($a, 'closed', 'x', $H, 'fixed');
    assertSame(['new' => 2, 'ack' => 0, 'in_progress' => 0, 'closed' => 1, 'open' => 2], $svc->summary($H));
});

test('交易：歷程寫入失敗時，案件也要一起復原', function () use ($H) {
    $svc = makeService($repo);
    $id = $svc->create(['title' => 'x'], 'a', $H)['id'];
    $broken = new class($repo) extends MemoryCaseRepo {
        private MemoryCaseRepo $inner;
        public function __construct(MemoryCaseRepo $inner) { $this->inner = $inner; }
        public function getCase(int $id, bool $forUpdate = false): ?array { return $this->inner->getCase($id); }
        public function updateCase(int $id, array $fields): void { $this->inner->updateCase($id, $fields); }
        public function addHistory(int $caseId, array $row): void { throw new RuntimeException('disk full'); }
        public function transaction(callable $fn) { return $this->inner->transaction($fn); }
    };
    $svc2 = new CaseService($broken);
    assertThrows(RuntimeException::class, fn () => $svc2->transition($id, 'ack', 'a', $H));
    assertSame('new', $repo->cases[$id]['status'], '歷程失敗就不能留下狀態變更');
});
