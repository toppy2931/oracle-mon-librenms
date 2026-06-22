<?php
/**
 * oracle-dashboard-layout.php — 管理 Oracle 戰情室版面偏好（全機共用）
 *
 * POST {action: "get"}                          → {ok, hidden:[...], order:[...]}
 * POST {action: "set", hidden:[...], order:[...]} → 寫入並回傳結果
 *
 * 配合 collector/admin/manage-dashboard-layout.sh 使用。
 */
$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';

header('Content-Type: application/json');

if (!Auth::check() || !Auth::user()->hasRole("admin")) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals(csrf_token(), $token)) {
    http_response_code(419);
    exit(json_encode(['error' => 'CSRF mismatch']));
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? 'get';

switch ($action) {
    case 'get':
        $cmd = ['sudo', '/opt/oracle-mon/admin/manage-dashboard-layout.sh', 'get'];
        break;
    case 'set':
        $order  = $body['order'] ?? [];
        $hidden = $body['hidden'] ?? [];   // 物件 {alias: [blocks]}（json_decode assoc → 陣列）
        if (!is_array($order)) {
            exit(json_encode(['error' => 'order 必須是陣列']));
        }
        // 把 hidden 物件序列化成 "alias=b1,b2;alias2=b3"（shell 端再做白名單 / 字元驗證）
        $pairs = [];
        if (is_array($hidden)) {
            foreach ($hidden as $alias => $blocks) {
                if (!is_array($blocks) || !count($blocks)) continue;
                $pairs[] = $alias . '=' . implode(',', $blocks);
            }
        }
        $cmd = ['sudo', '/opt/oracle-mon/admin/manage-dashboard-layout.sh', 'set',
                implode(',', $order), implode(';', $pairs)];
        break;
    default:
        http_response_code(400);
        exit(json_encode(['error' => 'unknown action: ' . $action]));
}

$proc = proc_open(
    $cmd,
    [1 => ['pipe','w'], 2 => ['pipe','w']],
    $pipes
);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$rc = proc_close($proc);

$username  = Auth::user()->username;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
@file_put_contents('/var/log/oracle-admin.log',
    date('Y-m-d H:i:s') . " [DASH_LAYOUT] user=$username from=$client_ip action=$action rc=$rc\n",
    FILE_APPEND);

if ($rc !== 0) {
    exit(json_encode(['error' => '執行失敗：' . trim($err ?: $out)]));
}

$result = json_decode(trim($out), true);
if (!is_array($result)) {
    exit(json_encode(['error' => '輸出格式錯誤', 'raw' => trim($out)]));
}

echo json_encode(['ok' => true] + $result);
