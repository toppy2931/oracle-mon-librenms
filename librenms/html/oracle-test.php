<?php
/**
 * oracle-test.php — test Oracle DB connection
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

$body  = json_decode(file_get_contents('php://input'), true);
$alias = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['alias'] ?? ''));

if (!$alias) {
    http_response_code(400);
    exit(json_encode(['error' => 'alias required']));
}

$process = proc_open(
    ['sudo', '/opt/oracle-mon/admin/test-db.sh', $alias],
    [1 => ['pipe','w'], 2 => ['pipe','w']],
    $pipes
);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$rc = proc_close($process);

$data = json_decode(trim($out), true);

if (!$data || $rc !== 0) {
    $error = $data['errorString'] ?? ($err ?: ($out ?: '連線失敗'));
    echo json_encode(['connected' => false, 'error' => trim($error)]);
    exit;
}

$d = $data['data'] ?? [];
$connected = ($data['error'] ?? 1) === 0 && ($d['instance_up'] ?? 0) == 1;

echo json_encode([
    'connected'      => $connected,
    'instance_up'    => $d['instance_up'] ?? 0,
    'db_status'      => $connected ? 'OPEN' : 'DOWN',
    'sessions_total' => $d['sessions_total'] ?? null,
    'error'          => $connected ? null : ($data['errorString'] ?? '實例未啟動'),
]);
