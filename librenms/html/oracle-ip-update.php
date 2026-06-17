<?php
/**
 * oracle-ip-update.php — update monitor-vm IP in LibreNMS settings
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

$body   = json_decode(file_get_contents('php://input'), true);
$new_ip = $body['new_ip'] ?? '';

if (!filter_var($new_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    exit(json_encode(['error' => 'IP 格式不正確（需為 IPv4）']));
}

// Only run the update-librenms-url.sh — it handles all three steps internally
$proc = proc_open(
    ['sudo', '/opt/oracle-mon/admin/update-librenms-url.sh', $new_ip],
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
    date('Y-m-d H:i:s') . " [IP_UPDATE] user=$username from=$client_ip new_ip=$new_ip rc=$rc\n",
    FILE_APPEND);

if ($rc !== 0) {
    exit(json_encode(['error' => '更新失敗：' . trim($err ?: $out)]));
}

// Parse steps from output
$steps = array_filter(array_map('trim', explode("\n", $out)));
$steps = array_values(array_filter($steps, fn($s) => str_starts_with($s, 'OK:') || str_starts_with($s, 'WARN:')));

echo json_encode([
    'ok'     => true,
    'new_ip' => $new_ip,
    'steps'  => $steps,
]);
