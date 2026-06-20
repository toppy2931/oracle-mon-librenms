<?php
/**
 * oracle-scan-old-ip.php — scan known config files for hard-coded occurrences
 * of an IP, return JSON {ok, status, old_ip, count, matches[]}.
 * Read-only: never writes to scanned files.
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
$old_ip = $body['old_ip'] ?? '';

if (!filter_var($old_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    exit(json_encode(['error' => 'IP 格式不正確（需為 IPv4）']));
}

$proc = proc_open(
    ['sudo', '/opt/oracle-mon/admin/scan-old-ip.sh', $old_ip],
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
    date('Y-m-d H:i:s') . " [IP_SCAN] user=$username from=$client_ip old_ip=$old_ip rc=$rc\n",
    FILE_APPEND);

if ($rc !== 0) {
    exit(json_encode(['error' => '掃描失敗：' . trim($err ?: $out)]));
}

$result = json_decode($out, true);
if (!is_array($result)) {
    exit(json_encode(['error' => '掃描輸出格式錯誤', 'raw' => trim($out)]));
}

echo json_encode(['ok' => true] + $result);
