<?php
/**
 * oracle-save.php — save Oracle DB connection config
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

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid JSON']));
}

$alias   = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['alias'] ?? ''));
$host    = $body['host'] ?? '';
$port    = (int)($body['port'] ?? 1521);
$sid     = preg_replace('/[^A-Za-z0-9_]/', '', $body['sid'] ?? '');
$user    = preg_replace('/[^A-Za-z0-9_]/', '', $body['user'] ?? '');
$pass    = $body['pass'] ?? '';
$label   = substr(preg_replace('/[^\w\s\-（）().]/', '', $body['label'] ?? $alias), 0, 80);
$enabled = ($body['enabled'] ?? '1') === '0' ? '0' : '1';

if (!$alias || !filter_var($host, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535 || !$sid || !$user) {
    http_response_code(400);
    exit(json_encode(['error' => '參數驗證失敗：請確認 IP / Port / SID / 帳號格式']));
}

$args = [
    'sudo', '/opt/oracle-mon/admin/save-db-conf.sh',
    $alias, $host, (string)$port, $sid, $user, $pass, $label, $enabled
];

$process = proc_open($args, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$rc = proc_close($process);

// Log
$username = Auth::user()->username;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
@file_put_contents('/var/log/oracle-admin.log',
    date('Y-m-d H:i:s') . " [SAVE] user=$username from=$client_ip alias=$alias host=$host rc=$rc\n",
    FILE_APPEND);

if ($rc === 0) {
    echo json_encode(['ok' => true, 'msg' => trim($out)]);
} else {
    echo json_encode(['error' => trim($err ?: $out) ?: '儲存失敗']);
}
