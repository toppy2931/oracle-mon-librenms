<?php
declare(strict_types=1);

/**
 * 網頁端點的共用前置檢查與工具。只在 LibreNMS 的網頁環境載入（需要 Auth、csrf_token、資料庫），
 * 所以不放進 bootstrap.php，需要它們的函式無法在沒有 LibreNMS 的地方單元測試 → 要到 VM 驗證
 * （載入這個檔案只定義函式與常數，不碰 LibreNMS；不需要 LibreNMS 的純函式，例如 ioc_log_settings_errors，測試可以直接呼叫）。
 * 規則本身（權限、狀態流程、驗證）都在 CaseService，這裡只管「是誰、有沒有 CSRF、錯誤怎麼回」。
 */

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Ioc\Access;
use Ioc\AuditTrail;
use Ioc\CaseService;
use Ioc\CaseView;
use Ioc\EvidenceService;
use Ioc\Fleet;
use Ioc\GraylogClient;
use Ioc\GraylogUnavailable;
use Ioc\HostLoginService;
use Ioc\IdentityService;
use Ioc\LandscapeService;
use Ioc\LogQuery;
use Ioc\NetworkVisibilityService;
use Ioc\OverviewService;
use Ioc\PdoCaseRepo;
use Ioc\PdoSettingsRepo;
use Ioc\SettingsService;
use Ioc\Sites;
use Ioc\UnavailableLogSource;

const IOC_READER_CONF = '/etc/oracle-mon/graylog-reader.conf';
const IOC_OVERVIEW_CACHE = 'ioc:overview:v7:';     // 依統計期間、趨勢視窗與 demo 模式分開快取（20 秒）
const IOC_CORRELATION_CACHE = 'ioc:correlation:v1:';   // 依 demo 模式分開快取（60 秒）；內部網段一改就要清掉
const IOC_NETWORK_CACHE ='ioc:network:v2:';       // 依統計期間與 demo 模式分開快取；內部網段一改就要清掉

/** 內部網段的設定有異動就讓內網可視性的快取失效，否則 60 秒內畫面還是用舊網段算的。 */
function ioc_network_cache_forget(): void
{
    foreach (['live', 'demo'] as $mode) {
        foreach (array_keys(NetworkVisibilityService::RANGE_LABELS) as $range) {
            \Illuminate\Support\Facades\Cache::forget(IOC_NETWORK_CACHE . $mode . ':' . $range);
        }
        \Illuminate\Support\Facades\Cache::forget(IOC_CORRELATION_CACHE . $mode);      // 規則三用內部網段，網段一改建議就要重算
    }
}

/**
 * 事件案或影響總覽內容的設定（例如風險等級門檻）有異動就讓總覽快取失效，
 * 否則 KPI「待處理事件」會比（即時查詢的）分頁角標落後最多 20 秒，改了門檻的畫面也還是舊等級。
 */
function ioc_overview_cache_forget(): void
{
    foreach (['live', 'demo'] as $mode) {
        foreach (array_keys(NetworkVisibilityService::RANGE_LABELS) as $range) {
            foreach (array_keys(LandscapeService::TREND_OPTIONS) as $trend) {
                \Illuminate\Support\Facades\Cache::forget(IOC_OVERVIEW_CACHE . $mode . ':' . $range . ':' . $trend);
            }
        }
    }
}

/** @param mixed $data */
function ioc_json($data, int $code = 200): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {                    // 壞掉的 UTF-8 以外的原因
        $code = 500;
        $json = json_encode(['error' => '回應內容無法編碼']);
    }
    http_response_code($code);
    echo $json;
    exit;
}

/** @param array<string,mixed> $extra */
function ioc_fail(int $code, string $message, array $extra = []): void
{
    ioc_json(['error' => $message] + $extra, $code);
}

/**
 * 每支 API 一開始都要呼叫：確認已登入、是 admin 或 global-read、（POST 的話）CSRF 正確。
 * 任何一項不符就直接回錯誤並結束。
 *
 * @return array{0:string,1:string} [操作者帳號, 權限等級 viewer|handler]
 */
function ioc_begin(bool $postOnly): array
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (!\Auth::check()) {
        ioc_fail(401, '請先登入');
    }
    $user  = \Auth::user();
    $level = Access::level($user->hasRole('admin'), $user->hasRole('global-read'));
    if (!Access::canView($level)) {
        ioc_fail(403, '沒有檢視權限');
    }
    if ($postOnly && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        ioc_fail(405, '只接受 POST');
    }
    // 不論 GET 或 POST 都要 CSRF 標頭，與既有的 oracle-dashboard-data.php 一致
    if (!hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        ioc_fail(419, 'CSRF 驗證失敗，請重新整理頁面');
    }
    return [(string) $user->username, (string) $level];
}

/**
 * 例外 → 對使用者安全的回應。IocError 的訊息本來就是給人看的中文；
 * 其他例外只記 log，回通用訊息（IS-047：錯誤訊息不得揭露連線資訊或秘密）。
 */
function ioc_handle(\Throwable $e): void
{
    if ($e instanceof \Ioc\ValidationError) {
        ioc_fail(400, $e->getMessage(), ['errors' => $e->errors()]);
    }
    if ($e instanceof \Ioc\AccessDenied) {
        ioc_fail(403, $e->getMessage());
    }
    if ($e instanceof \Ioc\NotFound) {
        ioc_fail(404, $e->getMessage());
    }
    if ($e instanceof \Ioc\InvalidTransition) {
        ioc_fail(409, $e->getMessage());
    }
    error_log('[ioc] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    ioc_fail(500, '系統發生錯誤，請稍後再試；若持續發生請聯絡管理者');
}

function ioc_service(): CaseService
{
    return new CaseService(new PdoCaseRepo(DB::connection()->getPdo()));
}

function ioc_settings(): SettingsService
{
    return new SettingsService(new PdoSettingsRepo(DB::connection()->getPdo()));
}

/**
 * 事件詳情的證據時間軸與關聯指標（唯讀查 Graylog）。Graylog 還沒設定好時，EvidenceService 一樣回 ok=false 與原因，
 * 畫面顯示「無法取得」，不是空的時間軸。$case 是資料庫的事件案列（要先通過 CaseService::get 的檢視權限）。
 *
 * @param array<string,mixed> $case
 * @return array<string,mixed>
 */
function ioc_evidence(array $case, bool $demo, ?string $level): array
{
    try {
        $logs = GraylogClient::fromConf(IOC_READER_CONF);
    } catch (GraylogUnavailable $e) {
        $logs = new UnavailableLogSource($e->getMessage());
    }
    // $level 沒有預設值：呼叫端一定要決定身分。裝置行為異常的事件案，目的位址只給管理者（EvidenceService）
    return (new EvidenceService($logs, $demo))->build($case, $level);
}

/**
 * 事件詳情的「裝置行為警示」：事件案是裝置行為分析的警示建立的才有，不是就回 null（畫面不顯示這一塊）。
 * 與 Graylog 證據各自成敗：資料庫讀不到（PDOException，例如資料表還沒建立）時，行為警示建立的事件案回 ['ok'=>false,'error'=>…]，
 * 其他事件案回 null（不相干的案件不該多出一個紅色的錯誤區塊）；不是資料庫錯誤的例外是 bug，照專案慣例往上丟（含權限拒絕）。
 * 管理者看目的位址會留稽核（BehaviorService::caseEvidence）。
 *
 * @param array<string,mixed> $case 資料庫的事件案列
 * @return array<string,mixed>|null
 */
function ioc_behavior_evidence(array $case, string $actor, ?string $level): ?array
{
    try {
        return (new \Ioc\BehaviorService(DB::connection()->getPdo()))->caseEvidence((int) $case['id'], $actor, $level);
    } catch (\PDOException $e) {
        error_log('[ioc] behavior evidence: ' . $e->getMessage());
        return ($case['category'] ?? null) === \Ioc\CaseCatalog::BEHAVIOR ? ['ok' => false, 'error' => '裝置行為警示讀不到（資料表還沒建立，或資料庫連不上）'] : null;
    }
}

const IOC_ADMIN_LOG      = '/var/log/oracle-admin.log';
const IOC_ADMIN_LOG_TAIL = 262144;     // 只讀檔案最後 256 KB：這個檔案沒有輪替，不能整份讀進記憶體

/** 轉成純陣列（資料庫的列是 stdClass；函式庫只吃陣列）。 */
function ioc_rows($rows): array
{
    return array_map(static fn ($r) => (array) $r, is_array($rows) ? $rows : $rows->all());
}

/**
 * 身分與稽核的帳號資料。每一塊各自讀、各自 try/catch：讀不到是 null（畫面標「無法取得」），不是空陣列。
 *
 * ⚠️ 雙因子只問「哪些 user_id 有 twofactor 這一筆」（只 pluck user_id），**絕不能選 users_prefs.value**：那是密鑰。
 *
 * @return array{users:?array,roles:?array,twofactor:?array,last_logins:?array,authlog_oldest:?string}
 */
function ioc_identity_sources(): array
{
    $out = ['users' => null, 'roles' => null, 'twofactor' => null, 'last_logins' => null, 'authlog_oldest' => null];
    $try = static function (string $what, callable $fn) {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('[ioc] identity ' . $what . ': ' . $e->getMessage());
            return null;
        }
    };
    $out['users'] = $try('users', static function () {
        $users = [];
        foreach (DB::table('users')->get(['user_id', 'username', 'enabled', 'auth_type']) as $u) {
            $users[] = ['id' => (int) $u->user_id, 'username' => (string) $u->username, 'enabled' => (int) $u->enabled === 1, 'local' => (string) $u->auth_type === 'mysql'];
        }
        return $users;
    });
    $out['roles'] = $try('roles', static function () {
        $map = [];
        foreach (DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->get(['model_has_roles.model_id as uid', 'roles.name as role']) as $r) {
            $map[(int) $r->uid][] = (string) $r->role;
        }
        return $map;
    });
    $out['twofactor'] = $try('twofactor', static fn () => DB::table('users_prefs')->where('pref', 'twofactor')->pluck('user_id')->map(static fn ($v) => (int) $v)->all());
    $out['last_logins'] = $try('last_logins', static function () {
        $map = [];
        foreach (DB::table('authlog')->where('result', 'Logged In')->groupBy('user')->selectRaw('`user` AS u, MAX(`datetime`) AS last_login')->get() as $r) {
            $map[(string) $r->u] = (string) $r->last_login;
        }
        return $map;
    });
    $oldest = $try('authlog_oldest', static fn () => [DB::table('authlog')->min('datetime')]);
    $out['authlog_oldest'] = $oldest === null || $oldest[0] === null ? null : (string) $oldest[0];
    return $out;
}

/**
 * oracle-admin.log 最後一段的行。檔案不存在＝還沒有人用過管理主控台（真的沒有紀錄，回空陣列）；
 * 存在卻讀不了（權限）是 null，畫面要標「無法取得」，不能當成沒有紀錄。
 *
 * @return string[]|null
 */
function ioc_admin_log_lines(): ?array
{
    if (!file_exists(IOC_ADMIN_LOG)) {
        return [];
    }
    $fh = is_readable(IOC_ADMIN_LOG) ? @fopen(IOC_ADMIN_LOG, 'rb') : false;
    if ($fh === false) {
        return null;
    }
    $size = (int) filesize(IOC_ADMIN_LOG);
    $from = max(0, $size - IOC_ADMIN_LOG_TAIL);
    if ($from > 0) {
        fseek($fh, $from);
    }
    $data = (string) stream_get_contents($fh);
    fclose($fh);
    $lines = preg_split('/\R/', $data) ?: [];
    if ($from > 0) {
        array_shift($lines);                    // 第一行多半被攔腰截斷
    }
    return array_slice(array_values(array_filter($lines, static fn ($l) => $l !== '')), -400);
}

/**
 * 稽核軌跡的原始資料：每個來源各自讀，讀不到是 null。
 *
 * @return array{login:?array,admin_op:?array,case:?array,settings:?array,export:?array}
 */
function ioc_audit_sources(): array
{
    $read = static function (string $what, callable $fn) {
        try {
            return $fn();
        } catch (\Throwable $e) {
            error_log('[ioc] audit ' . $what . ': ' . $e->getMessage());
            return null;
        }
    };
    return [
        'login'    => $read('authlog', static fn () => ioc_rows(DB::table('authlog')->orderByDesc('id')->limit(300)->get(['datetime', 'user', 'address', 'result']))),
        'admin_op' => $read('admin log', static fn () => ioc_admin_log_lines()),
        'case'     => $read('case history', static fn () => ioc_rows(DB::table('ioc_case_history')->orderByDesc('id')->limit(200)->get(['case_id', 'ts', 'actor', 'action', 'from_status', 'to_status', 'resolution']))),
        // 匯出紀錄（階段 2 新增）：資料表沒建立就是 null，稽核軌跡標「無法取得」
        'export'   => $read('export log', static fn () => ioc_rows(DB::table('ioc_export_log')->orderByDesc('id')->limit(100)->get(['actor', 'kind', 'detail', 'row_count', 'occurred_at']))),
        'settings' => $read('settings history', static fn () => ioc_rows(DB::table('ioc_settings_history')->orderByDesc('id')->limit(100)->get(['ts', 'actor', 'skey', 'old_value', 'new_value']))),
    ];
}

/** @return array<string,array<string,mixed>> IdentityService::build 的各區塊 */
function ioc_identity_accounts(string $level): array
{
    Access::requireHandler($level, '只有管理者可以看身分與稽核');      // 先檢查再讀資料；IdentityService 還會再檢查一次
    $s = ioc_identity_sources();
    try {
        $review = ioc_settings()->accountReview($level);
    } catch (\Ioc\AccessDenied $e) {
        throw $e;
    } catch (\Throwable $e) {
        error_log('[ioc] account review: ' . $e->getMessage());
        $review = null;                        // 資料表還沒建立：盤點區塊標「無法取得」，其他照常
    }
    return (new IdentityService())->build($s['users'], $s['roles'], $s['twofactor'], $s['last_logins'], $s['authlog_oldest'], $review, $level);
}

/**
 * 主機的遠端登入（SSH 與 Windows 登入）：Graylog 還沒設定好時，HostLoginService 一樣回 ok=false 與原因。
 *
 * @return array<string,mixed>
 */
function ioc_host_logins(bool $demo, string $level): array
{
    Access::requireHandler($level, '只有管理者可以看身分與稽核');      // 先檢查再讀 Graylog 憑證檔；HostLoginService 還會再檢查一次
    try {
        $logs = GraylogClient::fromConf(IOC_READER_CONF);
    } catch (GraylogUnavailable $e) {
        $logs = new UnavailableLogSource($e->getMessage());
    }
    return (new HostLoginService($logs, $demo))->build($level);
}

/**
 * 稽核軌跡（只有 admin；AuditTrail 會再檢查一次權限）。篩選條件只收白名單欄位，值不正確由 AuditTrail 回 400。
 *
 * @param mixed $in 請求內容
 * @return array<string,mixed>
 */
function ioc_audit_trail($in, string $level): array
{
    Access::requireHandler($level, '只有管理者可以看身分與稽核');      // 先檢查再讀資料（資料庫與記錄檔）
    return (new AuditTrail())->build(ioc_audit_sources(), ioc_pick($in, ['category', 'result', 'limit']), $level);
}

/**
 * 內網可視性（admin 與 global-read 都能看，全部唯讀）。Graylog 還沒設定好時各區塊標示原因；
 * 內部網段的設定讀不到（資料表壞了或值不合法）時整頁各區塊標「無法取得」，不套用預設網段冒充。
 *
 * @return array<string,mixed>
 */
function ioc_network(bool $demo, string $level, int $range = NetworkVisibilityService::RANGE): array
{
    try {
        $logs = GraylogClient::fromConf(IOC_READER_CONF);
    } catch (GraylogUnavailable $e) {
        $logs = new UnavailableLogSource($e->getMessage());
    }
    $nets = null;
    $why  = '';
    try {
        $nets = ioc_settings()->internalNets($level);
    } catch (\Ioc\AccessDenied $e) {
        throw $e;
    } catch (\Throwable $e) {
        error_log('[ioc] internal nets: ' . $e->getMessage());
        $why = $e instanceof \Ioc\IocError ? $e->getMessage() : '無法讀取內部網段的設定（資料表還沒建立？）';
    }
    return (new NetworkVisibilityService($logs, $demo, null, $range))->build($nets, $why, $level);
}

/**
 * 只取指定欄位、只收純量並轉成字串，其他（陣列、物件）一律丟掉，
 * 避免瀏覽器送來奇怪的型別讓後端出現轉型警告。
 *
 * @param mixed $input
 * @param string[] $keys
 * @return array<string,string>
 */
function ioc_pick($input, array $keys): array
{
    $out = [];
    if (!is_array($input)) {
        return $out;
    }
    foreach ($keys as $k) {
        if (isset($input[$k]) && is_scalar($input[$k])) {
            $out[$k] = (string) $input[$k];
        }
    }
    return $out;
}

/**
 * LibreNMS 的設備（含站點、可用率）。站點：設備 IP 落在設定頁自訂站點的網段內 → 該站點，否則是設備的「位置」。
 * 讀不到回傳 null（總覽會標示「無法取得」，不是 0 台）。
 * devices.last_polled 是主機本地時間（LibreNMS 要求 PHP 與資料庫時區一致），不是 UTC，Fleet 會照這個時區轉換。
 * devices.ip 是 varbinary(16)：用查詢建構器讀到的是原始位元組，轉成文字由 Fleet::deviceIp 處理。
 *
 * @param Sites|null $sites 設定頁自訂的站點；null＝沒有或讀不到（呼叫端要自己說明原因）
 */
function ioc_fleet(?Sites $sites = null): ?Fleet
{
    try {
        $rows = DB::table('devices')
            ->leftJoin('locations', 'locations.id', '=', 'devices.location_id')
            ->where('devices.disabled', 0)->where('devices.ignore', 0)
            ->get(['devices.device_id', 'devices.hostname', 'devices.sysName', 'devices.display', 'devices.type', 'devices.status',
                   'devices.status_reason', 'devices.last_polled', 'devices.ip', 'devices.overwrite_ip',
                   'locations.location as location_name', 'locations.lat', 'locations.lng']);
    } catch (\Throwable $e) {
        error_log('[ioc] devices: ' . $e->getMessage());
        return null;
    }
    // 可用率讀不到不拖垮整份設備清單：該欄位就是 null，畫面標「沒有可用率資料」
    $avail = [];
    try {
        foreach (DB::table('availability')->whereIn('duration', [86400, 604800, 2592000])->get(['device_id', 'duration', 'availability_perc']) as $a) {
            if ($a->availability_perc === null) {
                continue;            // 還沒累積出可用率：是「沒有資料」，(float) null 會變成 0% 把平均拉低
            }
            $avail[(int) $a->device_id][(int) $a->duration] = (float) $a->availability_perc;
        }
    } catch (\Throwable $e) {
        error_log('[ioc] availability: ' . $e->getMessage());
    }
    $out = [];
    foreach ($rows as $r) {
        $id    = (int) $r->device_id;
        $out[] = [
            'id'          => $id,
            'name'        => (string) ($r->display ?: ($r->sysName ?: $r->hostname)),
            'aliases'     => [$r->hostname, $r->sysName, $r->display],
            'type'        => $r->type,
            'status'      => $r->status,
            'reason'      => $r->status_reason,
            'last_polled' => $r->last_polled,
            'location'    => $r->location_name,
            'ip'          => Fleet::deviceIp($r->overwrite_ip, $r->ip, $r->hostname),
            'lat'         => $r->lat,
            'lng'         => $r->lng,
            'avail_1d'    => $avail[$id][86400] ?? null,
            'avail_7d'    => $avail[$id][604800] ?? null,
            'avail_30d'   => $avail[$id][2592000] ?? null,
        ];
    }
    return new Fleet($out, date_default_timezone_get(), $sites);
}

/**
 * 設定頁「服務水準」選核心設備用的設備清單（LibreNMS 啟用中的設備，依名稱排序）。只有管理者拿得到（設定頁的其他區塊檢視者看得到，
 * 但這份清單只有要編輯的人需要）；讀不到回傳 null，畫面標「無法取得」。
 *
 * @return array<int,array{id:int,name:string,group:string}>|null
 */
function ioc_core_candidates(?string $level): ?array
{
    Access::requireHandler($level, '只有管理者可以選擇核心設備');
    try {
        $rows = DB::table('devices')->where('disabled', 0)->where('ignore', 0)->get(['device_id', 'hostname', 'sysName', 'display', 'type']);
    } catch (\Throwable $e) {
        error_log('[ioc] core candidates: ' . $e->getMessage());
        return null;
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int) $r->device_id, 'name' => (string) ($r->display ?: ($r->sysName ?: $r->hostname)), 'group' => Fleet::group((string) $r->type)];
    }
    usort($out, static fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])) ?: $a['id'] <=> $b['id']);
    return $out;
}

/**
 * SettingsService::overviewSettings() 的失敗原因記 log。整張表讀不到時三組共用同一個例外，只記一行（列出受影響的組），
 * 不要一個請求記三條一模一樣的。
 *
 * @param array<string,\Throwable> $errors
 */
function ioc_log_settings_errors(array $errors): void
{
    $groups = [];
    foreach ($errors as $part => $e) {
        $id = spl_object_id($e);
        $groups[$id] = ['error' => $e, 'parts' => array_merge($groups[$id]['parts'] ?? [], [$part])];
    }
    foreach ($groups as $g) {
        error_log('[ioc] settings ' . implode('/', $g['parts']) . ': ' . $g['error']->getMessage());
    }
}

/** @return array<string,mixed> */
function ioc_overview(bool $demo, string $level, int $range = OverviewService::RANGE, int $trend = LandscapeService::TREND_DEFAULT): array
{
    try {
        $logs = GraylogClient::fromConf(IOC_READER_CONF);
    } catch (GraylogUnavailable $e) {
        $logs = new UnavailableLogSource($e->getMessage());   // 例如還沒建立唯讀帳號：日誌區塊標示尚未設定，其他照常
    }
    // 站點、風險門檻、內部網段只讀一次設定表（AccessDenied 直接往上丟）；三組各自成敗，讀不到的那組是 null、原因在 errors
    $settings = ioc_settings()->overviewSettings($level);
    ioc_log_settings_errors($settings['errors']);
    $sites        = $settings['sites'];
    $thresholds   = $settings['thresholds'];      // 資料表還沒建立或值壞了：風險分數只顯示分數、不判定等級
    $nets         = $settings['nets'];            // 讀不到內部網段：「防火牆阻擋的來源」分不出內外，該區塊標「無法取得」，不套預設網段
    $sla          = $settings['sla'];             // 服務水準：沒設定過是「尚未設定」（空值）；值壞了或讀不到是 null，核心業務服務與 SLA 達成率標「無法取得」，不套預設
    $slaWarning   = null;
    if (isset($settings['errors']['sla'])) {
        $e = $settings['errors']['sla'];
        $slaWarning = $e instanceof \Ioc\IocError ? $e->getMessage() : '服務水準的設定讀不到（資料表還沒建立，或資料庫連不上）';
    }
    $sitesWarning = null;
    if (isset($settings['errors']['sites'])) {
        $e = $settings['errors']['sites'];
        // 站點設定讀不到：設備只能依 LibreNMS 位置分站點。畫面一定要寫出來，不能默默換一套結果
        // 不是 IocError 就是整張表讀不到（見 overviewSettings）：訊息可能含連線資訊，只記 log，畫面寫固定的說明
        $sitesWarning = '站點設定讀不到，暫時只依 LibreNMS 的位置分站點：' . ($e instanceof \Ioc\IocError ? $e->getMessage() : '設定資料表讀不到（還沒建立，或資料庫連不上）');
    }
    $fleet = ioc_fleet($sites);
    try {
        $svc    = ioc_service();
        $cases  = $svc->summary($level);
        $open   = $svc->openBySeverity($level);
        $latest = array_map([CaseView::class, 'row'], $svc->listCases(['status' => '', 'limit' => LandscapeService::LATEST_CASES], $level)['items']);
        // 事件趨勢有自己的視窗，不跟 Graylog 區塊的統計期間綁在一起：7／30 日逐日、24 小時分六格；只讀事件案資料表，不打 Graylog
        $trendRows = $trend > 86400 ? $svc->createdWithin(intdiv($trend, 86400), $level) : $svc->createdInLast($trend, $level);
        $dayRows   = $svc->createdWithin(2, $level);           // 「待處理事件」卡片的今日／昨日新建件數
    } catch (\Throwable $e) {
        error_log('[ioc] cases: ' . $e->getMessage());
        $cases = $open = $latest = $trendRows = $dayRows = null;      // 例如資料表還沒建立：事件案相關的區塊整組標「無法取得」
    }
    $now = time();
    $overview = (new OverviewService($logs, $demo, $now, $nets, $range))->build($fleet === null ? null : $fleet->legacy(), $cases);
    $landscape = new LandscapeService($now, $trend);
    $overview['landscape'] = $landscape->build($fleet, $cases, $open, $latest, $trendRows, $overview['coverage'], $thresholds, $sitesWarning,
        $sla, $slaWarning, $dayRows === null ? null : $landscape->dayCounts($dayRows), ioc_audit_summary($level));
    return $overview;
}

/**
 * 總覽「合規覆蓋率」要的稽核摘要：只有計數（AuditEvidenceService::summary 檢視者也能看），所以可以放進不分角色的總覽快取。
 * 總覽只讀摘要，不碰案件與備份來源，那兩個來源給一個不會被呼叫的函式；讀不到（例如資料表還沒建立）回 null，卡片標「無法取得」。
 * 稽核頁的異動不會清總覽快取：這張卡片最多落後 20 秒（總覽快取的存活時間）。
 *
 * @return array<string,mixed>|null
 */
function ioc_audit_summary(string $level): ?array
{
    try {
        $unused = static function (): array {
            throw new \LogicException('總覽的合規覆蓋率只讀稽核摘要');
        };
        return (new \Ioc\AuditEvidenceService(DB::connection()->getPdo(), new \Ioc\AuditSnapshotStore(), $unused, $unused))->summary($level);
    } catch (\Ioc\AccessDenied $e) {
        throw $e;
    } catch (\Throwable $e) {
        error_log('[ioc] audit summary: ' . $e->getMessage());
        return null;
    }
}


/** 組態備份設備目錄：先驗管理者，再讀可信的 LibreNMS 設備，瀏覽器不能指定任意目的位址。 */
function ioc_backup_devices(?string $level): array
{
    Access::requireHandler($level, '只有管理者可以管理設備組態備份');
    try {
        $rows = DB::table('devices')->where('disabled', 0)->where('ignore', 0)
            ->whereIn('type', ['network', 'firewall', 'wireless', 'loadbalancer'])
            ->orderBy('device_id')->get(['device_id', 'hostname', 'sysName', 'display', 'overwrite_ip', 'ip']);
        $devices = [];
        foreach ($rows as $r) {
            $host = Fleet::deviceIp($r->overwrite_ip, $r->ip, $r->hostname);
            if ($host === null) {
                $host = (string) $r->hostname;
            }
            $devices[] = ['device_id' => (int) $r->device_id,
                'name' => (string) ($r->display ?: ($r->sysName ?: $r->hostname)), 'host' => $host];
        }
        return $devices;
    } catch (\Throwable $e) {
        throw new \Ioc\IocError('無法讀取 LibreNMS 設備清單，請稍後重試');
    }
}

// ── 日誌查詢與匯出（ioc-logs.php）────────────────────────────────────

/** 日誌查詢頁用的查詢條件欄位。只把這幾個鍵傳進 LogQuery（型別原樣保留，LogQuery 會嚴格檢查；不能用 ioc_pick，它會把 range_seconds 轉成字串）。 */
const IOC_LOG_FILTER_KEYS = ['range_seconds', 'from', 'to', 'log_type', 'event_action', 'severity', 'user_name', 'src_ip', 'dst_ip', 'host_name', 'keyword'];

/**
 * @param array<string,mixed> $in
 * @return array<string,mixed>
 */
function ioc_log_filters(array $in): array
{
    return array_intersect_key($in, array_flip(IOC_LOG_FILTER_KEYS));
}

function ioc_log_query(bool $demo, ?string $level): LogQuery
{
    try {
        $logs = GraylogClient::fromConf(IOC_READER_CONF);
    } catch (GraylogUnavailable $e) {
        $logs = new UnavailableLogSource($e->getMessage());
    }
    // 關鍵字查詢（比對原始訊息）只給管理者；檢視者送關鍵字會被擋下（LogQuery 的 allowKeyword）
    return new LogQuery($logs, $demo, null, \Ioc\Access::canHandle($level));
}

/**
 * 日誌的設備名稱對得上 LibreNMS 設備的，附上 device_id（畫面連到設備頁）。只是方便：設備清單讀不到就不附，不影響日誌本身。
 *
 * @param array<int,array<string,string>> $items
 * @return array<int,array<string,mixed>>
 */
function ioc_link_log_devices(array $items): array
{
    $fleet = ioc_fleet();
    foreach ($items as &$i) {
        $i['device_id'] = $fleet === null ? null : $fleet->deviceIdOfHost((string) $i['host_name']);
    }
    unset($i);
    return $items;
}

/**
 * 跨來源關聯建議（ioc-correlation.php）。內部網段讀不到時規則三標「無法取得」（原因寫在規則狀態），不套預設網段。
 *
 * @return array<string,mixed>
 */
function ioc_correlation(bool $demo, string $level): array
{
    try {
        $logs = GraylogClient::fromConf(IOC_READER_CONF);
    } catch (GraylogUnavailable $e) {
        $logs = new UnavailableLogSource($e->getMessage());
    }
    $nets = null;
    $why  = '';
    try {
        $nets = ioc_settings()->internalNets($level);
    } catch (\Ioc\AccessDenied $e) {
        throw $e;
    } catch (\Throwable $e) {
        error_log('[ioc] internal nets: ' . $e->getMessage());
        $why = $e instanceof \Ioc\IocError ? $e->getMessage() : '無法讀取內部網段的設定（資料表還沒建立？）';
    }
    return (new \Ioc\CorrelationService($logs, $demo, $nets, $why))->build();
}

/**
 * 報表用的站點設定：讀得到就用；讀不到（資料表壞了或還沒建立）站點欄退回 LibreNMS 的位置，並回傳原因，報表要寫出來
 * （與總覽同一個原則：不能默默換一套結果）。權限拒絕往上丟。
 *
 * @return array{sites:?Sites,warning:?string}
 */
function ioc_report_sites(string $level): array
{
    $settings = ioc_settings()->overviewSettings($level);
    ioc_log_settings_errors($settings['errors']);
    $e = $settings['errors']['sites'] ?? null;
    if ($e === null) {
        return ['sites' => $settings['sites'], 'warning' => null];
    }
    return ['sites' => null, 'warning' => '站點設定讀不到，暫時只依 LibreNMS 的位置分站點：'
        . ($e instanceof \Ioc\IocError ? $e->getMessage() : '設定資料表讀不到（還沒建立，或資料庫連不上）')];
}

/**
 * 報表中心（ioc-reports.php）的資料提供者。總覽與內網可視性共用既有的伺服器端快取（同一把鍵：報表與畫面看到的是同一份數字）；
 * 事件案與稽核讀不到（例如資料表還沒建立）回 null，報表該區塊標「無法取得」。
 */
function ioc_report_service(bool $demo, string $level): \Ioc\ReportService
{
    $mode = $demo ? 'demo' : 'live';
    $siteInfo = null;
    return new \Ioc\ReportService([
        'overview' => static fn () => \Illuminate\Support\Facades\Cache::remember(
            IOC_OVERVIEW_CACHE . $mode . ':' . NetworkVisibilityService::RANGE . ':' . LandscapeService::TREND_DEFAULT, 20,
            static fn () => ioc_overview($demo, $level, NetworkVisibilityService::RANGE, LandscapeService::TREND_DEFAULT)),
        'network'  => static fn () => \Illuminate\Support\Facades\Cache::remember(
            IOC_NETWORK_CACHE . $mode . ':' . NetworkVisibilityService::RANGE, 60, static fn () => ioc_network($demo, $level, NetworkVisibilityService::RANGE)),
        // 設備與站點：站點設定讀不到時（overviewSettings 各自成敗）設備仍可讀，站點欄退回 LibreNMS 的位置，並用 sites_warning 把原因寫進報表
        'fleet'    => static function () use ($level, &$siteInfo) {
            $siteInfo = ioc_report_sites($level);
            return ioc_fleet($siteInfo['sites']);
        },
        'sites_warning' => static function () use ($level, &$siteInfo): ?string {
            $siteInfo ??= ioc_report_sites($level);
            return $siteInfo['warning'];
        },
        'cases'    => static function (int $since) use ($level): ?array {
            try {
                $svc = ioc_service();
                // 列表依更新時間新到舊，分頁與停止條件在 ReportService::collectCases（有單元測試）
                return \Ioc\ReportService::collectCases(
                    static fn (int $offset): array => $svc->listCases(['status' => '', 'limit' => 100, 'offset' => $offset], $level), $since);
            } catch (\PDOException $e) {
                error_log('[ioc] report cases: ' . $e->getMessage());
                return null;
            }
        },
        'audit'    => static function () use ($level): ?array {
            try {
                $unused = static function (): array {
                    throw new \LogicException('報表只讀稽核摘要與明細');
                };
                $svc = new \Ioc\AuditEvidenceService(DB::connection()->getPdo(), new \Ioc\AuditSnapshotStore(), $unused, $unused);
                $summary = $svc->summary($level);
                return ['summary' => $summary, 'detail' => ($summary['ready'] ?? false) ? $svc->detail(0, $level) : []];
            } catch (\PDOException $e) {
                error_log('[ioc] report audit: ' . $e->getMessage());
                return null;
            }
        },
    ]);
}

/** 下載 CSV。檔名只含固定字樣與時間，不放使用者輸入（避免標頭注入與奇怪的檔名）。 */
function ioc_csv_download(string $csv, string $prefix): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $prefix . '-' . date('Ymd-His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    http_response_code(200);
    echo $csv;
    exit;
}
