<?php
/**
 * 事件案資料層的自我測試：在真正的 MariaDB 上走一遍建立、狀態流程、歷程、交易復原與中文／emoji 讀寫。
 * 本機的單元測試用的是記憶體版資料存取，驗證不到 SQL；這支補上那一塊，要在 LibreNMS 主機上跑。
 *
 *   sudo -u librenms php system/ioc-db-selftest.php
 *
 * 只會碰自己建立的資料（標題以「[IOC 自我測試]」開頭），結束時一定清掉，不會動到正式的事件案。
 * 環境變數（測試用）：LIBRENMS_DIR、IOC_LIB_DIR（可指向 repo 內的 librenms/ioc）
 */
$librenms = getenv('LIBRENMS_DIR') ?: '/opt/librenms';
$lib      = getenv('IOC_LIB_DIR') ?: "$librenms/includes/ioc";

chdir($librenms);
require "$librenms/vendor/autoload.php";
$app = require "$librenms/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require "$lib/bootstrap.php";

use Ioc\Access;
use Ioc\CaseService;
use Ioc\InvalidTransition;
use Ioc\PdoCaseRepo;

$pdo  = Illuminate\Support\Facades\DB::connection()->getPdo();
$repo = new PdoCaseRepo($pdo);
$svc  = new CaseService($repo);
$H    = Access::HANDLER;
$tag  = '[IOC 自我測試]';
$key  = 'selftest-' . bin2hex(random_bytes(4));

$results = [];
$check = static function (string $name, callable $fn) use (&$results): void {
    try {
        $fn();
        $results[] = [$name, true, ''];
    } catch (Throwable $e) {
        $results[] = [$name, false, get_class($e) . ': ' . $e->getMessage()];
    }
};
$assert = static function (bool $cond, string $msg = '條件不成立'): void {
    if (!$cond) {
        throw new RuntimeException($msg);
    }
};

$id = null;
try {
    $check('建立案件（含中文與 emoji，驗證 utf8mb4）', function () use ($svc, $H, $tag, $key, &$id, $assert, $repo) {
        $r = $svc->create(['title' => "$tag 無效帳號 😀", 'severity' => 'high', 'subject_user' => 'administrator',
                           'subject_ip' => '10.20.5.23', 'summary' => '自我測試', 'dedup_key' => $key], 'selftest', $H);
        $assert($r['created'] === true, '應建立新案件');
        $id = $r['id'];
        $assert($repo->getCase($id)['title'] === "$tag 無效帳號 😀", '中文與 emoji 讀回來不一致');
    });
    $check('同一個 dedup_key 不會重複建立', function () use ($svc, $H, $tag, $key, &$id, $assert) {
        $r = $svc->create(['title' => "$tag again", 'dedup_key' => $key], 'selftest', $H);
        $assert($r['created'] === false && $r['id'] === $id, '應回傳既有案件');
    });
    $check('狀態流程：確認 → 處理中 → 結案 → 重新開啟', function () use ($svc, $H, &$id, $assert, $repo) {
        $svc->transition($id, 'ack', 'selftest', $H);
        $svc->transition($id, 'in_progress', 'selftest', $H, null, '開始查');
        $svc->transition($id, 'closed', 'selftest', $H, 'fixed', '已處理');
        $c = $repo->getCase($id);
        $assert($c['status'] === 'closed' && $c['resolution'] === 'fixed' && $c['closed_at'] !== null, '結案欄位不對');
        $svc->transition($id, 'in_progress', 'selftest', $H, null, '重新調查');
        $c = $repo->getCase($id);
        $assert($c['status'] === 'in_progress' && $c['resolution'] === null && $c['closed_at'] === null, '重新開啟沒清掉結案欄位');
    });
    $check('不合法的轉換被拒絕，且不留歷程', function () use ($svc, $H, &$id, $assert, $repo) {
        $before = count($repo->history($id));
        try {
            $svc->transition($id, 'ack', 'selftest', $H);   // 處理中 → 已確認 不允許
            throw new RuntimeException('應該被拒絕');
        } catch (InvalidTransition $e) {
        }
        $assert(count($repo->history($id)) === $before, '失敗的轉換不能寫歷程');
    });
    $check('留言與歷程順序', function () use ($svc, $H, &$id, $assert) {
        $svc->comment($id, 'selftest', $H, '已通知主管');
        $h = $svc->get($id, $H)['history'];
        $assert(count($h) === 6, '預期 6 筆歷程，實際 ' . count($h));
        $assert($h[0]['action'] === 'created' && $h[5]['action'] === 'comment', '歷程順序不對');
    });
    $check('列表與統計', function () use ($svc, $H, &$id, $assert) {
        $open = array_column($svc->listCases(['status' => 'open', 'limit' => 200], $H)['items'], 'id');
        $assert(in_array((string) $id, array_map('strval', $open), true), '未結案列表應包含這個案件');
        $assert($svc->summary($H)['open'] >= 1, '統計的未結案數應 >= 1');
    });
    $check('交易失敗時整筆復原', function () use ($repo, $pdo, $assert, $tag) {
        $k = 'selftest-rollback-' . bin2hex(random_bytes(3));
        try {
            $repo->transaction(function () use ($repo, $tag, $k) {
                $repo->insertCase(['title' => "$tag rollback", 'status' => 'new', 'severity' => 'low', 'source' => 'manual',
                    'dedup_key' => $k, 'created_by' => 'selftest', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
        }
        $assert($repo->findByDedupKey($k) === null, '交易沒有復原');
    });
} finally {
    // 只清自己的資料：歷程先刪（有外鍵），再刪案件
    $st = $pdo->prepare("SELECT id FROM ioc_cases WHERE title LIKE ? OR dedup_key LIKE 'selftest-%'");
    $st->execute([$tag . '%']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $pdo->prepare('DELETE FROM ioc_case_history WHERE case_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM ioc_cases WHERE id = ?')->execute([$cid]);
    }
}

$fail = 0;
foreach ($results as [$name, $ok, $msg]) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($ok ? '' : "\n      $msg") . "\n";
    $fail += $ok ? 0 : 1;
}
echo "\n" . ($fail ? "$fail 項失敗" : '全部通過（測試資料已清除）') . "\n";
exit($fail ? 1 : 0);
