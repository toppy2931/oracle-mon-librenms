<?php
/**
 * ioc-cases.php — 事件案 API（JSON）。POST /ioc-cases.php，內容 {"action": "...", ...}
 *
 *   summary                               各狀態件數（檢視者可）
 *   list    {status,limit,offset}         列表（檢視者可）
 *   get     {id}                          單一事件案與歷程（檢視者可）
 *   create  {case:{title,severity,…}}     建立（僅 admin）
 *   transition {id,to,resolution,note}    變更狀態（僅 admin）
 *   comment {id,note}                     留言（僅 admin）
 *
 * 權限、狀態流程與輸入驗證都在 Ioc\CaseService；這裡只負責登入、CSRF、解析內容與回應格式。
 * 需登入、角色為 admin 或 global-read，並帶 X-CSRF-Token 標頭。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/endpoint.php';

use Ioc\CaseView;

[$actor, $level] = ioc_begin(true);

$raw = (string) file_get_contents('php://input', false, null, 0, 65537);
if (strlen($raw) > 65536) {
    ioc_fail(413, '內容太大');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    ioc_fail(400, '內容不是合法的 JSON');
}

try {
    $svc = ioc_service();
    switch ((string) ($in['action'] ?? '')) {
        case 'summary':
            ioc_json(['summary' => $svc->summary($level)]);
            // no break（ioc_json 會結束）
        case 'list':
            $r = $svc->listCases([
                'status' => is_string($in['status'] ?? null) ? $in['status'] : 'open',
                'limit'  => (int) ($in['limit'] ?? 50),
                'offset' => (int) ($in['offset'] ?? 0),
            ], $level);
            ioc_json(['items' => array_map([CaseView::class, 'row'], $r['items']), 'has_more' => $r['has_more']]);
        case 'get':
            $r = $svc->get((int) ($in['id'] ?? 0), $level);
            ioc_json([
                'case'        => CaseView::row($r['case']),
                'history'     => array_map([CaseView::class, 'history'], $r['history']),
                'transitions' => CaseView::transitions($r['allowed']),
            ]);
        case 'create':
            // 欄位白名單；dedup_key 只給自動建立用，不接受瀏覽器傳入
            $fields = ioc_pick($in['case'] ?? null, ['title', 'severity', 'subject_user', 'subject_ip', 'subject_host', 'summary', 'evidence_query']);
            $r = $svc->create($fields, $actor, $level);
            ioc_overview_cache_forget();          // 總覽快取裡有事件案件數
            ioc_json($r, $r['created'] ? 201 : 200);
        case 'transition':
            $f = ioc_pick($in, ['to', 'resolution', 'note']);
            $svc->transition((int) ($in['id'] ?? 0), $f['to'] ?? '', $actor, $level, $f['resolution'] ?? null, $f['note'] ?? null);
            ioc_overview_cache_forget();
            ioc_json(['ok' => true]);
        case 'comment':
            $f = ioc_pick($in, ['note']);
            $svc->comment((int) ($in['id'] ?? 0), $actor, $level, $f['note'] ?? '');
            ioc_json(['ok' => true]);               // 留言不改件數，不用清快取
        default:
            ioc_fail(400, '不認得的動作');
    }
} catch (\Throwable $e) {
    ioc_handle($e);
}
