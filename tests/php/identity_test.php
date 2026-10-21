<?php
declare(strict_types=1);

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\AuditTrail;
use Ioc\GraylogUnavailable;
use Ioc\HostLoginService;
use Ioc\IdentityService;
use Ioc\SettingsService;
use Ioc\Time;
use Ioc\ValidationError;

const ID_NOW = 1760000000;       // 2025-10-09 08:53:20 UTC（台北 16:53）

function idAgo(int $days): string
{
    return gmdate('Y-m-d H:i:s', ID_NOW - $days * 86400);
}

/** 一組有代表性的帳號：兩位 admin（一位沒雙因子）、一位久未登入的一般帳號、一位從沒登入的唯讀帳號、一位停用的 admin。 */
function idUsers(): array
{
    return [
        ['id' => 1, 'username' => 'alice', 'enabled' => true],
        ['id' => 2, 'username' => 'Carol', 'enabled' => true],
        ['id' => 3, 'username' => 'bob', 'enabled' => true],
        ['id' => 4, 'username' => 'dave', 'enabled' => false],
        ['id' => 5, 'username' => 'erin', 'enabled' => true],
    ];
}

function idBlocks(?array $users = null, ?array $roles = [1 => ['admin'], 2 => ['admin'], 3 => ['user'], 4 => ['admin'], 5 => ['global-read']], ?array $twofactor = [1],
                  $last = false, ?string $oldest = 'D40', ?array $review = ['last_date' => null, 'owner' => null, 'period_days' => 90, 'recorded' => false, 'invalid' => false],
                  ?string $level = Access::HANDLER): array
{
    $users = $users ?? idUsers();
    // $last：false＝用預設的一組；null＝「讀不到」（要能明確傳 null 測試失敗的情況）
    if ($last === false) {
        $last = ['alice' => idAgo(1), 'carol' => idAgo(2), 'bob' => idAgo(45)];      // authlog 的帳號是登入時打的，小寫的 carol 要對得到 Carol
    }
    $oldest = $oldest === 'D40' ? idAgo(40) : $oldest;
    return (new IdentityService(ID_NOW, 'UTC'))->build($users, $roles, $twofactor, $last, $oldest, $review, $level);
}

// ── IdentityService ────────────────────────────────────────────────

test('身分：只有 admin 能用（檢視者與沒有角色都被擋）', function () {
    foreach ([Access::VIEWER, null] as $lv) {
        assertThrows(AccessDenied::class, fn () => idBlocks(null, [], [], [], null, null, $lv), '只有管理者');
    }
});

test('身分：帳號總數、啟用與停用；角色分布只算啟用中的帳號，沒有角色的另外列', function () {
    $b = idBlocks();
    assertSame(['all' => 5, 'enabled' => 4, 'disabled' => 1], $b['summary']['data']);
    assertSame([['admin', 2], ['global-read', 1], ['user', 1]], array_map(fn ($r) => [$r['key'], $r['count']], $b['roles']['data']), '停用的 dave 不算');
    $none = idBlocks(null, [1 => ['admin']], [])['roles']['data'];
    assertSame([['admin', 1], ['none', 3]], array_map(fn ($r) => [$r['key'], $r['count']], $none));
    assertSame('沒有角色', $none[1]['label']);
    $multi = idBlocks([['id' => 1, 'username' => 'a', 'enabled' => true]], [1 => ['admin', 'custom-role']])['roles']['data'];
    assertSame([['admin', 1], ['custom-role', 1]], array_map(fn ($r) => [$r['key'], $r['count']], $multi), '一個帳號多個角色各算一次，不認得的角色名稱照原樣');
});

test('身分：雙因子覆蓋——只算啟用中的帳號，列出沒有雙因子的管理者', function () {
    $d = idBlocks()['two_factor']['data'];
    assertSame([1, 4, ['Carol']], [$d['enabled'], $d['total'], $d['admins_without']], '停用的 dave（也是 admin）不算');
    $all = idBlocks(null, [1 => ['admin'], 2 => ['admin'], 3 => ['user'], 5 => ['user']], [1, 2, 3, 5])['two_factor']['data'];
    assertSame([4, 4, []], [$all['enabled'], $all['total'], $all['admins_without']]);
    assertSame(0, $all['external']);
});

test('身分：雙因子覆蓋——外部驗證（LDAP、SSO）的帳號不算進分母，也不會被列成「管理者未啟用」', function () {
    $users = [
        ['id' => 1, 'username' => 'alice', 'enabled' => true, 'local' => true],
        ['id' => 2, 'username' => 'ldap-admin', 'enabled' => true, 'local' => false],
        ['id' => 3, 'username' => 'ldap-user', 'enabled' => true, 'local' => false],
        ['id' => 4, 'username' => 'dave', 'enabled' => false, 'local' => false],
    ];
    $d = idBlocks($users, [1 => ['admin'], 2 => ['admin'], 3 => ['user'], 4 => ['user']], [1])['two_factor']['data'];
    assertSame([1, 1, [], 2], [$d['enabled'], $d['total'], $d['admins_without'], $d['external']], '外部驗證的管理者沒有本機雙因子是正常的；停用的帳號不算');
    // 全部都是外部驗證：分母是 0，要讓畫面顯示「不適用」，不能是 0／0 或「已全面啟用」
    $ext = idBlocks([['id' => 2, 'username' => 'ldap-admin', 'enabled' => true, 'local' => false]], [2 => ['admin']], [])['two_factor']['data'];
    assertSame([0, 0, [], 1], [$ext['enabled'], $ext['total'], $ext['admins_without'], $ext['external']]);
});

test('身分：最小權限只給事實（管理者幾位、是誰），按位元組排序', function () {
    assertSame(['admins' => 2, 'admin_names' => ['Carol', 'alice'], 'total' => 4], idBlocks()['least_privilege']['data']);
});

test('身分：久未登入——登入紀錄只有 40 天，看近 30 天；從沒登入的排最前面；帳號大小寫不同也對得到', function () {
    $d = idBlocks()['dormant']['data'];
    assertSame([30, 40, 2], [$d['window_days'], $d['covered_days'], $d['count']]);
    assertSame([['erin', '—'], ['bob', Time::toTaipei(idAgo(45), 'Y-m-d H:i:s', 'UTC')]], array_map(fn ($i) => [$i['username'], $i['last_login']], $d['items']),
        'alice（1 天前）與 Carol（2 天前，紀錄是小寫 carol）都有登入，dave 停用不算');
    assertSame(Time::toTaipei(idAgo(40), 'Y-m-d H:i:s', 'UTC'), $d['oldest']);
    assertSame(false, $d['capped']);
});

test('身分：登入紀錄比 30 天短時，只能看那麼久，並如實回報實際天數', function () {
    $d = idBlocks(null, [1 => ['admin']], [], ['alice' => idAgo(1), 'bob' => idAgo(6)], idAgo(5))['dormant']['data'];
    assertSame([5, 5], [$d['window_days'], $d['covered_days']]);
    assertSame(['Carol', 'erin', 'bob'], array_map(fn ($i) => $i['username'], $d['items']), '6 天前登入的 bob 超過 5 天的範圍；alice 沒問題；從沒登入的 Carol、erin 在前（依名稱排序）');
});

test('身分：登入紀錄是空的，不能說「沒有人久未登入」，要說無法判斷；其他區塊不受影響', function () {
    $b = idBlocks(null, [1 => ['admin']], [], [], null);
    assertSame(false, $b['dormant']['ok']);
    assertTrue(mb_strpos($b['dormant']['error'], '沒有任何登入紀錄') !== false, $b['dormant']['error']);
    assertSame(true, $b['summary']['ok']);
});

test('身分：每種來源讀不到，只有依賴它的區塊是「無法取得」，不是 0 或全部正常', function () {
    $roles = idBlocks(null, null);
    assertSame([true, false, false, false, true], [$roles['summary']['ok'], $roles['roles']['ok'], $roles['two_factor']['ok'], $roles['least_privilege']['ok'], $roles['dormant']['ok']]);
    $tf = idBlocks(null, [1 => ['admin']], null);
    assertSame([false, true], [$tf['two_factor']['ok'], $tf['roles']['ok']]);
    $login = idBlocks(null, [1 => ['admin']], [], null);
    assertSame([false, true], [$login['dormant']['ok'], $login['two_factor']['ok']]);
    $noUsers = (new IdentityService(ID_NOW, 'UTC'))->build(null, [], [], [], idAgo(1), ['last_date' => null, 'owner' => null, 'period_days' => 90, 'recorded' => false, 'invalid' => false], Access::HANDLER);
    foreach (['summary', 'roles', 'two_factor', 'least_privilege', 'dormant'] as $k) {
        assertSame([false, 'LibreNMS 的帳號資料無法讀取'], [$noUsers[$k]['ok'], $noUsers[$k]['error']], $k);
    }
    assertSame(true, $noUsers['review']['ok'], '盤點紀錄是另一份資料，讀得到就照常顯示');
});

test('身分：帳號盤點——沒記錄、週期內、已逾期、紀錄壞掉、讀不到各有各的說法（日期用台北時間算天數）', function () {
    $rv = fn (?string $date, int $period = 90, bool $invalid = false) => idBlocks(null, [], [], [], idAgo(1), ['last_date' => $date, 'owner' => $date ? '王小明' : null, 'period_days' => $period, 'recorded' => $date !== null, 'invalid' => $invalid])['review'];
    assertSame(['none', null], [$rv(null)['data']['state'], $rv(null)['data']['days_since']]);
    assertSame(['ok', 10], [$rv('2025-09-29')['data']['state'], $rv('2025-09-29')['data']['days_since']], '台北 2025-10-09，10 天前');
    assertSame(['ok', 90], [$rv('2025-07-11')['data']['state'], $rv('2025-07-11')['data']['days_since']], '剛好 90 天不算逾期');
    assertSame(['overdue', 91], [$rv('2025-07-10')['data']['state'], $rv('2025-07-10')['data']['days_since']]);
    assertSame(['none', true], [$rv(null, 90, true)['data']['state'], $rv(null, 90, true)['data']['invalid']]);
    assertSame(0, $rv('2025-10-09')['data']['days_since'], '今天盤點是 0 天前');
    $broken = (new IdentityService(ID_NOW, 'UTC'))->build([], [], [], [], idAgo(1), null, Access::HANDLER)['review'];
    assertSame([false, '帳號盤點的紀錄無法讀取'], [$broken['ok'], $broken['error']]);
});

test('身分：久未登入的名單最多 50 個，超過要標示，總數仍是完整的', function () {
    $users = [];
    for ($i = 1; $i <= 60; $i++) {
        $users[] = ['id' => $i, 'username' => sprintf('u%02d', $i), 'enabled' => true];
    }
    $d = idBlocks($users, [], [], [], idAgo(40))['dormant']['data'];
    assertSame([60, 50, true], [$d['count'], count($d['items']), $d['capped']]);
});

// ── AuditTrail ─────────────────────────────────────────────────────

function idTrail(): AuditTrail
{
    return new AuditTrail('Asia/Taipei');       // authlog 與 oracle-admin.log 的時間是伺服器本地時間
}

test('稽核：登入紀錄——成功、登出、失敗；沒有時間的列不放進時間表；伺服器本地時間轉成明確的時間點', function () {
    $e = idTrail()->fromAuthlog([
        ['datetime' => '2026-10-20 10:00:00', 'user' => 'alice', 'address' => '10.1.1.5', 'result' => 'Logged In'],
        ['datetime' => '2026-10-20 10:01:00', 'user' => 'alice', 'address' => '10.1.1.5', 'result' => 'Logged Out'],
        ['datetime' => '2026-10-20 10:02:00', 'user' => 'root', 'address' => '203.0.113.9', 'result' => 'Invalid credentials'],
        ['datetime' => 'garbage', 'user' => 'x', 'address' => '', 'result' => 'Logged In'],
    ]);
    assertSame(3, count($e));
    assertSame([strtotime('2026-10-20 02:00:00 UTC'), '登入', 'ok', 'login'], [$e[0]['ts'], $e[0]['action'], $e[0]['result'], $e[0]['category']], '台北 10:00 ＝ UTC 02:00');
    assertSame(['登出', 'ok'], [$e[1]['action'], $e[1]['result']]);
    assertSame(['登入失敗', 'fail', 'Invalid credentials', '203.0.113.9'], [$e[2]['action'], $e[2]['result'], $e[2]['detail'], $e[2]['ip']]);
});

test('稽核：管理操作紀錄——動作中文名稱、成功與失敗、來源位址；只帶白名單欄位，指令輸出與任何像密碼的欄位都不出現', function () {
    $e = idTrail()->fromAdminLog([
        '2026-10-20 11:00:00 [SAVE] user=admin from=10.1.1.9 alias=l1hweb host=10.0.0.1 rc=0',
        '2026-10-20 11:05:00 [DELETE_FAIL] user=admin from=10.1.1.9 alias=x rc=1 out=secret-output err=pass=hunter2',
        '2026-10-20 11:06:00 [FW_add] user=bob from=10.1.1.10 cidr=10.9.0.0/16 rule=',
        '2026-10-20 11:07:00 [NAS_save] user=admin from=10.1.1.9 password=hunter2 token=abc',
        '2026-10-20 11:08:00 [APPLY] id=12 fw/add',
        '2026-10-20 11:09:00 [MYSTERY] user=x from=1.1.1.1',
        '2026-10-20 11:10:00 [CUSTOM_MAP] user=admin from=10.1.1.9 action=set rc=3',
        '這不是一行紀錄',
        '',
    ]);
    $by = array_column($e, null, 'action');
    assertSame(7, count($e), '看不懂的行直接略過');
    assertSame(['ok', 'admin', '10.1.1.9', 'alias=l1hweb · host=10.0.0.1'], [$by['儲存 Oracle 連線設定']['result'], $by['儲存 Oracle 連線設定']['actor'], $by['儲存 Oracle 連線設定']['ip'], $by['儲存 Oracle 連線設定']['detail']]);
    assertSame(['fail', 'alias=x'], [$by['刪除資料庫失敗']['result'], $by['刪除資料庫失敗']['detail']]);
    assertSame(['info', 'cidr=10.9.0.0/16', 'bob'], [$by['防火牆管理網段：add']['result'], $by['防火牆管理網段：add']['detail'], $by['防火牆管理網段：add']['actor']]);
    assertSame(['info', ''], [$by['NAS 備份：save']['result'], $by['NAS 備份：save']['detail']]);
    assertSame(['系統（套用器）', 'id=12 fw/add', ''], [$by['套用管理操作（系統）']['actor'], $by['套用管理操作（系統）']['detail'], $by['套用管理操作（系統）']['ip']]);
    assertSame('x', $by['MYSTERY']['actor'], '不認得的動作代碼照原樣顯示');
    assertSame(['fail', 'action=set'], [$by['Custom Map 刷新秒數']['result'], $by['Custom Map 刷新秒數']['detail']], 'rc 不是 0 就是失敗');
    $json = json_encode($e, JSON_UNESCAPED_UNICODE);
    foreach (['hunter2', 'secret-output', 'token=abc', 'password'] as $leak) {
        assertTrue(strpos($json, $leak) === false, "{$leak} 不能出現在稽核軌跡裡");
    }
});

test('稽核：事件案處理與設定異動（UTC 時間），事件案沒有來源位址', function () {
    $c = idTrail()->fromCaseHistory([
        ['case_id' => 7, 'ts' => '2026-10-20 05:00:00', 'actor' => 'alice', 'action' => 'created', 'from_status' => null, 'to_status' => 'new', 'resolution' => null],
        ['case_id' => 7, 'ts' => '2026-10-20 06:00:00', 'actor' => 'bob', 'action' => 'status_changed', 'from_status' => 'in_progress', 'to_status' => 'closed', 'resolution' => 'false_positive'],
        ['case_id' => 7, 'ts' => '2026-10-20 06:05:00', 'actor' => 'bob', 'action' => 'comment', 'from_status' => null, 'to_status' => null, 'resolution' => null],
    ]);
    assertSame(['建立事件案 #7', '變更事件案 #7的狀態', '在事件案 #7留言'], array_column($c, 'action'));
    assertSame('處理中 → 已結案（誤報）', $c[1]['detail']);
    assertSame(['', 'case', strtotime('2026-10-20 05:00:00 UTC')], [$c[0]['ip'], $c[0]['category'], $c[0]['ts']]);
    $s = idTrail()->fromSettingsHistory([['ts' => '2026-10-20 07:00:00', 'actor' => 'admin', 'skey' => 'review.owner', 'old_value' => '', 'new_value' => '王小明'],
                                         ['ts' => '2026-10-20 07:00:00', 'actor' => 'admin', 'skey' => 'risk.low_from', 'old_value' => '85', 'new_value' => '95']]);
    assertSame(['修改設定：帳號盤點負責人', ' → 王小明'], [$s[0]['action'], $s[0]['detail']]);
    assertSame(['修改設定：低風險門檻', '85 → 95'], [$s[1]['action'], $s[1]['detail']]);
});

function idSources(): array
{
    return [
        'login'    => [['datetime' => '2026-10-20 10:00:00', 'user' => 'alice', 'address' => '10.1.1.5', 'result' => 'Logged In'],
                       ['datetime' => '2026-10-20 10:30:00', 'user' => 'root', 'address' => '203.0.113.9', 'result' => 'Invalid credentials']],
        'admin_op' => ['2026-10-20 11:00:00 [SAVE] user=alice from=10.1.1.5 alias=a rc=0'],
        'case'     => [['case_id' => 1, 'ts' => '2026-10-20 03:30:00', 'actor' => 'alice', 'action' => 'created', 'from_status' => null, 'to_status' => 'new', 'resolution' => null]],   // 台北 11:30
        'settings' => [['ts' => '2026-10-20 05:00:00', 'actor' => 'admin', 'skey' => 'risk.low_from', 'old_value' => '85', 'new_value' => '95']],                                       // 台北 13:00
    ];
}

test('稽核：各來源合成一張時間表，最新的在前（本地時間與 UTC 的來源混在一起也排得對）', function () {
    $r = idTrail()->build(idSources(), [], Access::HANDLER);
    assertSame(['13:00:00', '11:30:00', '11:00:00', '10:30:00', '10:00:00'], array_map(fn ($i) => substr($i['time'], 11), $r['items']));
    assertSame(['settings', 'case', 'admin_op', 'login', 'login'], array_column($r['items'], 'category'));
    assertSame([5, false], [$r['total'], $r['truncated']]);
    assertSame(['', '', '10.1.1.5', '203.0.113.9', '10.1.1.5'], array_column($r['items'], 'ip'), '事件案與設定異動沒有來源位址');
});

test('稽核：依類別與結果篩選、筆數上限與截斷標示；篩選條件不正確被拒絕', function () {
    $t = idTrail();
    assertSame(2, $t->build(idSources(), ['category' => 'login'], Access::HANDLER)['total']);
    assertSame(1, $t->build(idSources(), ['category' => 'login', 'result' => 'fail'], Access::HANDLER)['total']);
    assertSame(1, $t->build(idSources(), ['result' => 'fail'], Access::HANDLER)['total']);
    $cut = $t->build(idSources(), ['limit' => 2], Access::HANDLER);
    assertSame([2, 5, true], [count($cut['items']), $cut['total'], $cut['truncated']]);
    $many = [];
    for ($i = 0; $i < 250; $i++) {
        $many[] = ['datetime' => gmdate('Y-m-d H:i:s', 1760000000 + $i), 'user' => 'u', 'address' => '', 'result' => 'Logged In'];
    }
    $big = $t->build(['login' => $many, 'admin_op' => [], 'case' => [], 'settings' => []], ['limit' => '99999'], Access::HANDLER);
    assertSame([AuditTrail::MAX_LIMIT, 250, true], [count($big['items']), $big['total'], $big['truncated']], '筆數上限 200，不能由瀏覽器放大');
    assertSame(AuditTrail::DEFAULT_LIMIT, count($t->build(['login' => $many, 'admin_op' => [], 'case' => [], 'settings' => []], [], Access::HANDLER)['items']), '沒指定就是預設筆數');
    foreach ([['category' => 'nope'], ['result' => 'maybe']] as $bad) {
        assertThrows(ValidationError::class, fn () => $t->build(idSources(), $bad, Access::HANDLER), '篩選條件');
    }
});

test('稽核：某個來源讀不到，標示「無法取得」與原因，其他來源照常列出（不能讓那個來源的事件默默消失）', function () {
    $src = idSources();
    $src['admin_op'] = null;
    $src['case'] = null;
    $r = idTrail()->build($src, [], Access::HANDLER);
    assertSame([false, false, true, true], [$r['sources']['admin_op']['ok'], $r['sources']['case']['ok'], $r['sources']['login']['ok'], $r['sources']['settings']['ok']]);
    assertTrue(mb_strpos($r['sources']['admin_op']['error'], '/var/log/oracle-admin.log') !== false, $r['sources']['admin_op']['error']);
    assertSame(3, $r['total'], '登入兩筆加設定一筆');
    assertSame([2, 1], [$r['sources']['login']['count'], $r['sources']['settings']['count']]);
    $none = idTrail()->build(array_fill_keys(['login', 'admin_op', 'case', 'settings'], null), [], Access::HANDLER);
    assertSame([0, []], [$none['total'], $none['items']]);
    assertSame(4, count(array_filter($none['sources'], fn ($s) => !$s['ok'])), '全部讀不到時四個都標出來');
});

test('稽核：來源是空的（真的沒有紀錄）是 ok、0 筆，不是失敗', function () {
    $r = idTrail()->build(array_fill_keys(['login', 'admin_op', 'case', 'settings'], []), [], Access::HANDLER);
    assertSame([true, true, true, true], array_column($r['sources'], 'ok'));
    assertSame(0, $r['total']);
});

test('稽核：只有 admin 能看（所有人的登入位址與失敗嘗試）', function () {
    foreach ([Access::VIEWER, null] as $lv) {
        assertThrows(AccessDenied::class, fn () => idTrail()->build(idSources(), [], $lv), '只有管理者');
    }
});

test('稽核：管理操作紀錄的白名單欄位裡沒有任何像密碼、金鑰的名稱', function () {
    $r = new ReflectionClassConstant(AuditTrail::class, 'ADMIN_DETAIL_KEYS');
    foreach ($r->getValue() as $key) {
        assertTrue(preg_match('/pass|pwd|secret|token|key|out|err/i', $key) === 0, "白名單欄位 {$key} 看起來不該顯示");
    }
});

// ── HostLoginService ───────────────────────────────────────────────

function idHostSource(array $present, array $actionCounts = [], array $rows = []): FakeLogSource
{
    return new FakeLogSource(
        function (string $q, array $groupBy) use ($present, $actionCounts): array {
            if ($groupBy === ['log_type']) {
                return array_map(fn ($t) => ['log_type' => $t, 'count' => 10], $present);
            }
            return array_map(fn ($a, $n) => ['event_action' => $a, 'count' => $n], array_keys($actionCounts), array_values($actionCounts));
        },
        fn () => $rows
    );
}

test('主機遠端登入：Linux 與 Windows 都沒有日誌是「尚未接入」，不是沒人登入，也不去查登入事件', function () {
    $src = idHostSource(['firewall', 'network']);
    $r = (new HostLoginService($src))->build(Access::HANDLER);
    assertSame([true, false, ['Linux 主機', 'Windows 伺服器／網域'], []], [$r['ok'], $r['data']['connected'], $r['data']['missing'], $r['data']['items']]);
    assertSame(1, count($src->calls), '只問有哪些來源，沒有再查登入事件');
});

test('主機遠端登入：只接入 Linux 時，列出登入並提醒不含 Windows；成功與失敗分開；SSH 是連線方式', function () {
    $rows = [
        ['timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', ID_NOW - 60), 'user_name' => 'root', 'event_action' => 'login_failed', 'src_ip' => '203.0.113.9', 'host_name' => 'web01', 'log_type' => 'linux'],
        ['timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', ID_NOW - 120), 'user_name' => 'alice', 'event_action' => 'login_success', 'src_ip' => '10.1.1.5', 'host_name' => 'web01', 'log_type' => 'linux'],
    ];
    $src = idHostSource(['linux', 'firewall'], ['login_failed' => 4, 'login_success' => 1], $rows);
    $r = (new HostLoginService($src))->build(Access::HANDLER)['data'];
    assertSame([true, ['Windows 伺服器／網域'], ['login_success' => 1, 'login_failed' => 4, 'invalid_user' => 0], false], [$r['connected'], $r['missing'], $r['counts'], $r['capped']]);
    assertSame(['SSH（Linux）', '登入失敗', 'fail', 'root'], [$r['items'][0]['channel'], $r['items'][0]['action'], $r['items'][0]['result'], $r['items'][0]['user']]);
    assertSame(['ok', '10.1.1.5'], [$r['items'][1]['result'], $r['items'][1]['ip']]);
});

test('主機遠端登入：Graylog 取不到是「無法取得」並帶原因；範例資料與正式資料互相排除', function () {
    $down = new FakeLogSource(fn () => throw new GraylogUnavailable('Graylog 回應逾時'));
    $r = (new HostLoginService($down))->build(Access::HANDLER);
    assertSame([false, 'Graylog 回應逾時'], [$r['ok'], $r['error']]);
    $real = idHostSource(['linux']);
    (new HostLoginService($real, false))->build(Access::HANDLER);
    $demo = idHostSource(['linux']);
    (new HostLoginService($demo, true))->build(Access::HANDLER);
    foreach ($real->calls as $c) {
        assertTrue(strpos($c['query'], ') AND NOT ioc_demo:true') !== false, $c['query']);
    }
    foreach ($demo->calls as $c) {
        assertTrue(strpos($c['query'], ') AND ioc_demo:true') !== false && strpos($c['query'], 'NOT ioc_demo') === false, $c['query']);
    }
});

test('主機遠端登入：只有 admin 能看', function () {
    assertThrows(AccessDenied::class, fn () => (new HostLoginService(idHostSource([])))->build(Access::VIEWER), '只有管理者');
});

// ── 帳號盤點紀錄（SettingsService） ───────────────────────────────

test('盤點紀錄：沒記錄過是 recorded=false、週期 90 天；只有 admin 能讀與寫', function () {
    $svc = stMake($repo);
    $r = $svc->accountReview(Access::HANDLER);
    assertSame([null, null, 90, false, false], [$r['last_date'], $r['owner'], $r['period_days'], $r['recorded'], $r['invalid']]);
    foreach ([Access::VIEWER, null] as $lv) {
        assertThrows(AccessDenied::class, fn () => $svc->accountReview($lv), '只有管理者');
        assertThrows(AccessDenied::class, fn () => $svc->saveAccountReview(['last_date' => '2026-10-20', 'owner' => 'x', 'period_days' => '90'], 'v', $lv), '只有管理者');
    }
    assertSame([], $repo->rows, '被拒絕不能寫');
});

test('盤點紀錄：儲存後讀得回來，三個值一起寫；每個實際變動的值留一筆異動紀錄；沒變動不寫', function () {
    $svc = stMake($repo, $clock);
    $r = $svc->saveAccountReview(['last_date' => '2026-10-15', 'owner' => ' 王小明 ', 'period_days' => '60'], 'alice', Access::HANDLER);
    assertSame(['2026-10-15', '王小明', 60, true, false, 'alice', '2026-10-20 16:00:00'], [$r['last_date'], $r['owner'], $r['period_days'], $r['recorded'], $r['invalid'], $r['updated_by'], $r['updated_at']]);
    assertSame(3, count($repo->history), '三個值都從「沒有」變成有值（週期從預設 90 變 60）');
    $puts = $repo->puts;
    $clock['now'] = ST_T2;
    $svc->saveAccountReview(['last_date' => '2026-10-15', 'owner' => '王小明', 'period_days' => '60'], 'bob', Access::HANDLER);
    assertSame([$puts, 3], [$repo->puts, count($repo->history)], '值沒變：不寫、不留紀錄');
    $svc->saveAccountReview(['last_date' => '2026-10-20', 'owner' => '王小明', 'period_days' => '60'], 'bob', Access::HANDLER);
    assertSame(4, count($repo->history), '只有日期變了，只多一筆');
    assertSame(['最近一次帳號盤點日期', '2026-10-15', '2026-10-20'], [SettingsService::KEY_LABEL[$repo->history[3]['skey']], $repo->history[3]['old_value'], $repo->history[3]['new_value']]);
});

test('盤點紀錄：驗證——日期要真實且不能是未來、負責人要填且不超過 64 字、週期 7～365 的整數；被拒絕不留痕跡', function () {
    $svc = stMake($repo);   // 現在是台北 2026-10-20 16:00
    $ok = ['last_date' => '2026-10-20', 'owner' => 'x', 'period_days' => '90'];
    $svc->saveAccountReview($ok, 'a', Access::HANDLER);
    $repo->rows = [];
    $repo->history = [];
    $repo->puts = 0;
    foreach ([
        ['last_date' => '2026-10-21'] + $ok, ['last_date' => '2026-02-30'] + $ok, ['last_date' => '10/20/2026'] + $ok, ['last_date' => ''] + $ok, ['last_date' => ['2026-10-20']] + $ok,
        ['owner' => ''] + $ok, ['owner' => '   '] + $ok, ['owner' => str_repeat('字', 65)] + $ok, ['owner' => "a\x07b"] + $ok,
        ['period_days' => '6'] + $ok, ['period_days' => '366'] + $ok, ['period_days' => 'abc'] + $ok, ['period_days' => '90.5'] + $ok, ['period_days' => ''] + $ok,
    ] as $bad) {
        try {
            $svc->saveAccountReview($bad, 'a', Access::HANDLER);
            fail('應該被拒絕：' . json_encode($bad, JSON_UNESCAPED_UNICODE));
        } catch (ValidationError $e) {
        }
    }
    assertSame([[], [], 0], [$repo->rows, $repo->history, $repo->puts]);
    foreach ([['owner' => str_repeat('字', 64)], ['period_days' => '7'], ['period_days' => '365'], ['last_date' => '2020-01-01']] as $good) {
        $svc->saveAccountReview($good + $ok, 'a', Access::HANDLER);
    }
    $e = assertThrows(ValidationError::class, fn () => $svc->saveAccountReview(['last_date' => 'x', 'owner' => '', 'period_days' => 'y'], 'a', Access::HANDLER));
    assertSame(3, count($e->errors()), '三個欄位都錯就三條都列出來');
});

test('盤點紀錄：資料表裡只剩一部分、或被手動改成不合法的值，是壞掉（invalid），不悄悄補預設；重新儲存就修好', function () {
    $svc = stMake($repo);
    $bad = [
        '只有日期' => [SettingsService::REVIEW_DATE => stRow('2026-10-01')],
        '日期不存在' => [SettingsService::REVIEW_DATE => stRow('2026-02-30'), SettingsService::REVIEW_OWNER => stRow('x'), SettingsService::REVIEW_PERIOD => stRow('90')],
        '負責人是空的' => [SettingsService::REVIEW_DATE => stRow('2026-10-01'), SettingsService::REVIEW_OWNER => stRow(''), SettingsService::REVIEW_PERIOD => stRow('90')],
        '週期不合法' => [SettingsService::REVIEW_DATE => stRow('2026-10-01'), SettingsService::REVIEW_OWNER => stRow('x'), SettingsService::REVIEW_PERIOD => stRow('3')],
    ];
    foreach ($bad as $name => $rows) {
        $repo->rows = $rows;
        $r = $svc->accountReview(Access::HANDLER);
        assertSame([true, false, null], [$r['invalid'], $r['recorded'], $r['last_date']], $name);
        $fixed = $svc->saveAccountReview(['last_date' => '2026-10-15', 'owner' => 'y', 'period_days' => '90'], 'a', Access::HANDLER);
        assertSame([false, true], [$fixed['invalid'], $fixed['recorded']], $name);
    }
});

test('盤點紀錄：風險門檻頁的異動紀錄（檢視者看得到）不含盤點紀錄的異動', function () {
    $svc = stMake($repo);
    $svc->saveAccountReview(['last_date' => '2026-10-15', 'owner' => '王小明', 'period_days' => '90'], 'a', Access::HANDLER);
    $svc->saveRisk(['risk_low' => '90', 'risk_medium' => '70'], 'a', Access::HANDLER);
    $labels = array_column($svc->riskSettings(Access::VIEWER)['history'], 'label');
    assertSame(['中等風險門檻', '低風險門檻'], $labels, '盤點的負責人與日期只有 admin 看得到');
    assertTrue(count($repo->history) > 2, '盤點的異動仍然有記錄，稽核軌跡看得到');
});
