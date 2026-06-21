<?php
/**
 * oracle-custom-map.php — manage LibreNMS custom_map_refresh
 *
 * POST {action: "get"}                 → 回現值 {value: int|null, source: "config.php"|"fallback"}
 * POST {action: "set", value: <int>}   → 套用並 clear config cache
 * POST {action: "clear"}                → 移除設定（fallback 回 page_refresh）
 *
 * 配合 collector/admin/set-custom-map-refresh.sh 使用。
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
    case 'clear':
        $cmd = ['sudo', '/opt/oracle-mon/admin/set-custom-map-refresh.sh', $action];
        break;
    case 'set':
        $value = (int)($body['value'] ?? 0);
        if ($value < 5 || $value > 86400) {
            exit(json_encode(['error' => 'value 必須是 5..86400 之間的整數']));
        }
        $cmd = ['sudo', '/opt/oracle-mon/admin/set-custom-map-refresh.sh', 'set', (string)$value];
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
    date('Y-m-d H:i:s') . " [CUSTOM_MAP] user=$username from=$client_ip action=$action rc=$rc\n",
    FILE_APPEND);

if ($rc !== 0) {
    exit(json_encode(['error' => '執行失敗：' . trim($err ?: $out)]));
}

$result = json_decode(trim($out), true);
if (!is_array($result)) {
    exit(json_encode(['error' => '輸出格式錯誤', 'raw' => trim($out)]));
}

echo json_encode(['ok' => true] + $result);
