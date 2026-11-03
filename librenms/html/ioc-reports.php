<?php
/**
 * ioc-reports.php — 報表中心 API。POST /ioc-reports.php，內容 {"action": "...", "demo": true|false, ...}
 *
 *   catalog                                 這個身分能產生的報表清單（檢視者四份、管理者六份）
 *   build   {report, days?, severity?, event?, device?}   產生一份報表（結構化資料；畫面用它排出可列印的 HTML）
 *   export  {report, ...同上}                 同一份報表的 CSV；先寫匯出紀錄（ioc_export_log）才給檔案
 *
 * admin 與 global-read 都能產生與匯出一般報表；稽核證據清單與改善追蹤表只有管理者（ReportService 檢查）。
 * 報表的內容與規則在 Ioc\ReportService（可單元測試）；資料提供者在 endpoint.php 的 ioc_report_service()。
 * 這裡只負責登入、CSRF、解析內容與回應格式。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

use Illuminate\Support\Facades\DB;
use Ioc\ExportLog;
use Ioc\ReportService;

[$actor, $level] = ioc_begin(true);

$raw = (string) file_get_contents('php://input', false, null, 0, 2049);
if (strlen($raw) > 2048) {
    ioc_fail(413, '內容太大');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    ioc_fail(400, '內容不是合法的 JSON');
}

try {
    $demo = ($in['demo'] ?? false) === true;       // 只收真正的 true，不收字串
    $key = is_string($in['report'] ?? null) ? $in['report'] : '';
    $filters = array_intersect_key($in, array_flip(['days', 'severity', 'event', 'device']));   // 型別原樣保留，ReportService 嚴格檢查
    switch ((string) ($in['action'] ?? '')) {
        case 'catalog':
            ioc_json(['reports' => ReportService::catalog($level)]);
            // no break（ioc_json 會結束）
        case 'build':
            ioc_json(['report' => ioc_report_service($demo, $level)->build($key, $filters, $level)]);
            // no break
        case 'export':
            $report = ioc_report_service($demo, $level)->build($key, $filters, $level);
            // 先記錄才給檔案：寫不進去就讓整個請求失敗，不能有「匯出了卻沒留紀錄」
            (new ExportLog(DB::connection()->getPdo()))->record($actor, 'report:' . $key, ($demo ? '【範例資料】' : '') . implode('；', $report['filters']) . '；' . $report['range'], ReportService::rowCount($report));
            ioc_csv_download(ReportService::csv($report), 'ioc-report-' . $key);
            // no break（ioc_csv_download 會結束）
        default:
            ioc_fail(400, '不認得的動作');
    }
} catch (\Throwable $e) {
    ioc_handle($e);
}
