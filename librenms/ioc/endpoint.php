<?php
declare(strict_types=1);

/**
 * 網頁端點的共用前置檢查與工具。只在 LibreNMS 的網頁環境載入（需要 Auth、csrf_token、資料庫），
 * 所以不放進 bootstrap.php，也無法在沒有 LibreNMS 的地方單元測試 → 要到 VM 驗證。
 * 規則本身（權限、狀態流程、驗證）都在 CaseService，這裡只管「是誰、有沒有 CSRF、錯誤怎麼回」。
 */

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Ioc\Access;
use Ioc\CaseService;
use Ioc\CaseView;
use Ioc\EvidenceService;
use Ioc\Fleet;
use Ioc\GraylogClient;
use Ioc\GraylogUnavailable;
use Ioc\LandscapeService;
use Ioc\OverviewService;
use Ioc\PdoCaseRepo;
use Ioc\PdoSettingsRepo;
use Ioc\SettingsService;
use Ioc\UnavailableLogSource;

const IOC_READER_CONF = '/etc/oracle-mon/graylog-reader.conf';
const IOC_OVERVIEW_CACHE = 'ioc:overview:v3:';     // 後面接 live 或 demo；總覽的伺服器端快取（20 秒）。內容形狀改了就要升版號

/**
 * 事件案或影響總覽內容的設定（例如風險等級門檻）有異動就讓總覽快取失效，
 * 否則 KPI「待處理事件」會比（即時查詢的）分頁角標落後最多 20 秒，改了門檻的畫面也還是舊等級。
 */
function ioc_overview_cache_forget(): void
{
    foreach (['live', 'demo'] as $k) {
        \Illuminate\Support\Facades\Cache::forget(IOC_OVERVIEW_CACHE . $k);
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
function ioc_evidence(array $case, bool $demo): array
{
    try {
        $logs = GraylogClient::fromConf(IOC_READER_CONF);
    } catch (GraylogUnavailable $e) {
        $logs = new UnavailableLogSource($e->getMessage());
    }
    return (new EvidenceService($logs, $demo))->build($case);
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
 * LibreNMS 的設備（含站點＝設備的「位置」、可用率）。讀不到回傳 null（總覽會標示「無法取得」，不是 0 台）。
 * devices.last_polled 是主機本地時間（LibreNMS 要求 PHP 與資料庫時區一致），不是 UTC，Fleet 會照這個時區轉換。
 */
function ioc_fleet(): ?Fleet
{
    try {
        $rows = DB::table('devices')
            ->leftJoin('locations', 'locations.id', '=', 'devices.location_id')
            ->where('devices.disabled', 0)->where('devices.ignore', 0)
            ->get(['devices.device_id', 'devices.hostname', 'devices.sysName', 'devices.display', 'devices.type', 'devices.status',
                   'devices.status_reason', 'devices.last_polled', 'locations.location as location_name', 'locations.lat', 'locations.lng']);
    } catch (\Throwable $e) {
        error_log('[ioc] devices: ' . $e->getMessage());
        return null;
    }
    // 可用率讀不到不拖垮整份設備清單：該欄位就是 null，畫面標「沒有可用率資料」
    $avail = [];
    try {
        foreach (DB::table('availability')->whereIn('duration', [86400, 604800])->get(['device_id', 'duration', 'availability_perc']) as $a) {
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
            'name'        => (string) ($r->display ?: ($r->sysName ?: $r->hostname)),
            'aliases'     => [$r->hostname, $r->sysName, $r->display],
            'type'        => $r->type,
            'status'      => $r->status,
            'reason'      => $r->status_reason,
            'last_polled' => $r->last_polled,
            'location'    => $r->location_name,
            'lat'         => $r->lat,
            'lng'         => $r->lng,
            'avail_1d'    => $avail[$id][86400] ?? null,
            'avail_7d'    => $avail[$id][604800] ?? null,
        ];
    }
    return new Fleet($out, date_default_timezone_get());
}

/** @return array<string,mixed> */
function ioc_overview(bool $demo, string $level): array
{
    try {
        $logs = GraylogClient::fromConf(IOC_READER_CONF);
    } catch (GraylogUnavailable $e) {
        $logs = new UnavailableLogSource($e->getMessage());   // 例如還沒建立唯讀帳號：日誌區塊標示尚未設定，其他照常
    }
    $fleet = ioc_fleet();
    try {
        $svc    = ioc_service();
        $cases  = $svc->summary($level);
        $open   = $svc->openBySeverity($level);
        $latest = array_map([CaseView::class, 'row'], $svc->listCases(['status' => '', 'limit' => LandscapeService::LATEST_CASES], $level)['items']);
        $trend  = $svc->createdWithin(LandscapeService::TREND_DAYS, $level);
    } catch (\Throwable $e) {
        error_log('[ioc] cases: ' . $e->getMessage());
        $cases = $open = $latest = $trend = null;              // 例如資料表還沒建立：事件案相關的區塊整組標「無法取得」
    }
    try {
        $thresholds = ioc_settings()->riskThresholds($level);
    } catch (\Throwable $e) {
        error_log('[ioc] risk thresholds: ' . $e->getMessage());
        $thresholds = null;                       // 資料表還沒建立或值壞了：風險分數只顯示分數、不判定等級
    }
    $overview = (new OverviewService($logs, $demo))->build($fleet === null ? null : $fleet->legacy(), $cases);
    $overview['landscape'] = (new LandscapeService())->build($fleet, $cases, $open, $latest, $trend, $overview['coverage'], $thresholds);
    return $overview;
}
