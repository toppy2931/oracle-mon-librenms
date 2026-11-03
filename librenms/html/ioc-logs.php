<?php
/**
 * ioc-logs.php — 日誌查詢 API。POST /ioc-logs.php，內容 {"action": "...", "demo": true|false, <查詢條件>}
 *
 *   search  依時間與欄位、關鍵字查日誌（只取標準化欄位，不含原始訊息）；Graylog 取不到回 ok=false 與原因，不是空表
 *   export  同樣的條件匯出 CSV（最多 5000 筆）；先寫匯出紀錄（ioc_export_log）才給檔案，寫不進去就不給
 *
 * admin 與 global-read 都能查詢與匯出（使用者 2026-11-03 決定）；匯出一律留紀錄，只有管理者看得到匯出紀錄（稽核軌跡）。
 * 查詢條件的驗證與 Lucene 查詢的組成在 Ioc\LogQuery（值只當文字，跳不出引號）；這裡只負責登入、CSRF、解析內容與回應格式。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

use Illuminate\Support\Facades\DB;
use Ioc\ExportLog;

[$actor, $level] = ioc_begin(true);

$raw = (string) file_get_contents('php://input', false, null, 0, 4097);
if (strlen($raw) > 4096) {
    ioc_fail(413, '內容太大');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    ioc_fail(400, '內容不是合法的 JSON');
}

try {
    $demo = ($in['demo'] ?? false) === true;       // 只收真正的 true，不收字串
    switch ((string) ($in['action'] ?? '')) {
        case 'search':
            $r = ioc_log_query($demo, $level)->search(ioc_log_filters($in));
            if ($r['ok']) {
                $r['data']['items'] = ioc_link_log_devices($r['data']['items']);
            }
            ioc_json(['logs' => $r]);
            // no break（ioc_json 會結束）
        case 'export':
            $r = ioc_log_query($demo, $level)->export(ioc_log_filters($in));
            if (!$r['ok']) {
                ioc_fail(503, '無法取得日誌：' . $r['error']);
            }
            // 先記錄才給檔案：寫不進去（資料表沒建、資料庫連不上）就讓整個請求失敗，不能有「匯出了卻沒留紀錄」
            (new ExportLog(DB::connection()->getPdo()))->record($actor, 'logs', ($demo ? '【範例資料】' : '') . $r['summary'], $r['rows']);
            ioc_csv_download($r['csv'], 'ioc-logs');
            // no break（ioc_csv_download 會結束）
        default:
            ioc_fail(400, '不認得的動作');
    }
} catch (\Throwable $e) {
    ioc_handle($e);
}
