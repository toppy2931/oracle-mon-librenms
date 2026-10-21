<?php
/**
 * 入口資料層的自我測試：在真正的 MariaDB 上走一遍事件案（建立、狀態流程、歷程、交易復原、中文／emoji 讀寫）
 * 與設定（風險等級門檻的儲存、覆寫、異動紀錄、權限）。
 * 本機的單元測試用的是記憶體版資料存取，驗證不到 SQL；這支補上那一塊，要在 LibreNMS 主機上跑。
 *
 *   sudo -u librenms php system/ioc-db-selftest.php
 *
 * 事件案只會碰自己建立的資料（標題以「[IOC 自我測試]」開頭），結束時一定清掉，不會動到正式的事件案。
 * 設定的檢查全部在一個永遠 rollBack 的交易裡，正式的風險等級門檻與異動紀錄不會被改到，
 * 網頁（別的連線）也看不到測試用的值；最後一項會確認回復後的內容跟測試前一樣。
 * 環境變數（測試用）：LIBRENMS_DIR、IOC_LIB_DIR（可指向 repo 內的 librenms/ioc）
 */
$librenms = getenv('LIBRENMS_DIR') ?: '/opt/librenms';
$lib      = getenv('IOC_LIB_DIR') ?: "$librenms/includes/ioc";

chdir($librenms);
require "$librenms/vendor/autoload.php";
$app = require "$librenms/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require "$lib/bootstrap.php";
require_once "$lib/endpoint.php";      // 身分與稽核的資料讀取函式在這裡（只定義函式與常數，不會做任何事）

use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\AuditTrail;
use Ioc\IdentityService;
use Ioc\CaseService;
use Ioc\InvalidTransition;
use Ioc\PdoCaseRepo;
use Ioc\PdoSettingsRepo;
use Ioc\SettingsService;
use Ioc\ValidationError;

$pdo  = Illuminate\Support\Facades\DB::connection()->getPdo();
$repo = new PdoCaseRepo($pdo);
$svc  = new CaseService($repo);
$settingsRepo = new PdoSettingsRepo($pdo);
$settings     = new SettingsService($settingsRepo);
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
    $check('事件詳情的新欄位（類型、目的／關聯主機、影響範圍、標籤）寫入並讀回，含中文與 emoji（驗證 ALTER 補的欄位）', function () use ($svc, $H, $tag, $assert, $repo) {
        $r = $svc->create(['title' => "$tag 詳情欄位", 'severity' => 'medium', 'category' => 'privilege_change', 'target_host' => 'DC01', 'related_host' => 'FS02',
                           'impact_scope' => '財產科共用資料夾 😀', 'tags' => '權限, 內網'], 'selftest', $H);
        $c = $repo->getCase($r['id']);
        $assert([$c['category'], $c['target_host'], $c['related_host'], $c['impact_scope'], $c['tags']] === ['privilege_change', 'DC01', 'FS02', '財產科共用資料夾 😀', '權限,內網'], '新欄位讀回來不一致');
        $d = Ioc\CaseView::detail($svc->get($r['id'], $H)['case']);
        $assert($d['category_label'] === '權限或群組異動' && $d['attack']['id'] === 'T1098' && $d['tags'] === ['權限', '內網'] && count($d['steps']) >= 3, '詳情的顯示轉換不對');
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
    $check('統計：未結案依嚴重性、最近建立的案件（總覽用）', function () use ($svc, $H, $assert) {
        $open = $svc->openBySeverity($H);
        $assert(($open['high'] ?? 0) >= 1, '自我測試的案件是高風險、處理中，未結案的高風險應 >= 1');
        $recent = $svc->createdWithin(1, $H);
        $assert(count($recent) >= 1, '最近 1 天建立的案件應包含自我測試的案件');
        foreach ($recent as $r) {
            $assert(isset($r['created_at'], $r['severity']), '每列要有建立時間與嚴重性');
        }
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
    // 設定的檢查全部在「一個永遠回復的交易」裡跑：不論測試成敗，或程式中途被砍（SSH 斷線、kill），
    // 正式的風險等級門檻與異動紀錄都不會被動到，網頁（別的連線）也看不到測試用的值。
    // PdoSettingsRepo::transaction() 會加入這個外層交易，所以 saveRisk() 照常運作。
    $settingsBefore = null;
    $check('設定：資料表可讀，並記下測試前的狀態', function () use ($settingsRepo, &$settingsBefore) {
        $settingsBefore = ['rows' => $settingsRepo->all(), 'history' => count($settingsRepo->recentHistory(1000000))];
    });
    $pdo->beginTransaction();
    try {
        $check('設定：儲存風險等級門檻、讀回、覆寫與異動紀錄（驗證 upsert 與 LIMIT 的 SQL）', function () use ($settings, $H, $assert) {
            $r = $settings->saveRisk(['risk_low' => '93', 'risk_medium' => '71'], 'selftest', $H);
            $assert($r['low'] === 93 && $r['medium'] === 71 && $r['custom'] === true && $r['invalid'] === false, '儲存後讀回的值不對');
            $assert($r['updated_by'] === 'selftest' && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $r['updated_at']) === 1, '最後修改的人或時間不對');
            $assert($settings->riskThresholds($H) === ['low' => 93, 'medium' => 71], 'riskThresholds 與儲存的不一致');
            $settings->saveRisk(['risk_low' => '94', 'risk_medium' => '71'], 'selftest', $H);   // 已有資料列：走 ON DUPLICATE KEY UPDATE
            $assert($settings->riskThresholds($H) === ['low' => 94, 'medium' => 71], '覆寫沒有生效');
            $h = $settings->riskSettings($H)['history'];
            $assert(count($h) >= 1 && $h[0]['actor'] === 'selftest' && $h[0]['from'] === '93' && $h[0]['to'] === '94', '最新一筆異動紀錄不對（由新到舊）');
        });
        $check('設定：沒有變動就不寫、不合法的值被擋下且資料不變', function () use ($settings, $settingsRepo, $H, $assert) {
            $before = count($settingsRepo->recentHistory(1000000));
            $settings->saveRisk(['risk_low' => '94', 'risk_medium' => '71'], 'selftest', $H);
            $assert(count($settingsRepo->recentHistory(1000000)) === $before, '值沒變卻多了一筆紀錄');
            try {
                $settings->saveRisk(['risk_low' => '60', 'risk_medium' => '70'], 'selftest', $H);   // 中等門檻不能大於低風險門檻
                throw new RuntimeException('應該被拒絕');
            } catch (ValidationError $e) {
            }
            $assert($settings->riskThresholds($H) === ['low' => 94, 'medium' => 71], '被拒絕的儲存不能改到資料');
        });
        $check('設定：檢視者只能讀不能改', function () use ($settings, $assert) {
            $assert($settings->riskThresholds(Access::VIEWER) === ['low' => 94, 'medium' => 71], '檢視者應能讀到目前的門檻');
            try {
                $settings->saveRisk(['risk_low' => '90', 'risk_medium' => '70'], 'viewer', Access::VIEWER);
                throw new RuntimeException('檢視者不應該能儲存');
            } catch (AccessDenied $e) {
            }
            $assert($settings->riskThresholds(Access::VIEWER) === ['low' => 94, 'medium' => 71], '被拒絕的儲存不能改到資料');
        });
        $check('帳號盤點紀錄：儲存、讀回、改一個值只多一筆異動紀錄（三個設定值一起寫）', function () use ($settings, $settingsRepo, $H, $assert) {
            $r = $settings->saveAccountReview(['last_date' => gmdate('Y-m-d', time() - 86400), 'owner' => '自我測試 😀', 'period_days' => '60'], 'selftest', $H);
            $assert($r['recorded'] === true && $r['invalid'] === false && $r['owner'] === '自我測試 😀' && $r['period_days'] === 60, '儲存後讀回的值不對（含中文與 emoji）');
            $n = count($settingsRepo->recentHistory(1000000));
            $settings->saveAccountReview(['last_date' => gmdate('Y-m-d', time() - 86400), 'owner' => '自我測試 😀', 'period_days' => '61'], 'selftest', $H);
            $assert(count($settingsRepo->recentHistory(1000000)) === $n + 1, '只改週期卻不是多一筆紀錄');
            $assert(count($settingsRepo->recentHistory(1000000, [SettingsService::RISK_LOW, SettingsService::RISK_MEDIUM])) === count(array_filter($settingsRepo->recentHistory(1000000), fn ($h) => in_array($h['skey'], [SettingsService::RISK_LOW, SettingsService::RISK_MEDIUM], true))), '依設定名稱篩選異動紀錄（SQL 的 IN）不對');
        });
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    $check('設定：測試結束後設定值與異動紀錄跟測試前完全一樣（交易已回復）', function () use ($settingsRepo, &$settingsBefore, $assert) {
        $assert($settingsBefore !== null, '測試前的狀態沒有記下來（上面的資料表檢查失敗）');
        $assert($settingsRepo->all() === $settingsBefore['rows'], '設定值沒有復原');
        $assert(count($settingsRepo->recentHistory(1000000)) === $settingsBefore['history'], '異動紀錄沒有復原');
    });
    // 身分與稽核的資料讀取（只讀）：LibreNMS 的 users／roles／users_prefs／authlog 與 oracle-admin.log。
    // 本機的單元測試用假資料，驗證不到這些 SQL 與檔案權限；這裡用跟網頁相同的 PHP 身分（librenms）實際讀一次。
    $check('身分與稽核：帳號、角色、雙因子、登入紀錄的 SQL 都讀得出來（只讀）', function () use ($assert, $H) {
        $s = ioc_identity_sources();
        foreach (['users', 'roles', 'twofactor', 'last_logins'] as $part) {
            $assert($s[$part] !== null, "「{$part}」讀不到（看 PHP 的錯誤 log，訊息以 [ioc] identity 開頭）");
        }
        $assert(count($s['users']) >= 1, 'LibreNMS 至少有一個帳號（你自己），結果是空的');
        $b = (new IdentityService())->build($s['users'], $s['roles'], $s['twofactor'], $s['last_logins'], $s['authlog_oldest'], ['last_date' => null, 'owner' => null, 'period_days' => 90, 'recorded' => false, 'invalid' => false], $H);
        $assert($b['summary']['ok'] && $b['roles']['ok'] && $b['two_factor']['ok'] && $b['least_privilege']['ok'], '帳號統計算不出來');
    });
    $check('身分與稽核：稽核軌跡的四個來源都讀得到（含 /var/log/oracle-admin.log 的權限，只讀）', function () use ($assert, $H) {
        $a = ioc_audit_sources();
        foreach (['login' => 'LibreNMS 登入紀錄（authlog）', 'admin_op' => '管理操作紀錄檔 ' . IOC_ADMIN_LOG . '（librenms 要讀得到）', 'case' => '事件案歷程', 'settings' => '設定異動紀錄'] as $k => $what) {
            $assert($a[$k] !== null, "{$what} 讀不到");
        }
        $t = (new AuditTrail())->build($a, [], $H);
        $assert(count(array_filter($t['sources'], fn ($s) => !$s['ok'])) === 0, '稽核軌跡有來源標成無法取得');
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
