<?php
/**
 * oracle-firewall.php — 管理「管理網段」防火牆設定（區塊 D）
 * action: list | add | remove，經 sudo 呼叫 manage-mgmt-cidrs.sh。
 */
$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';

header('Content-Type: application/json');

if (!Auth::check() || !Auth::user()->hasRole("admin")) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Unauthorized']));
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals(csrf_token(), $token)) {
    http_response_code(419);
    exit(json_encode(['ok' => false, 'error' => 'CSRF mismatch']));
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$cidr   = $body['cidr'] ?? '';

if (!in_array($action, ['list', 'add', 'remove', 'rules'], true)) {
    exit(json_encode(['ok' => false, 'error' => '未知動作']));
}
// 寫入類動作（add/remove）才需 CIDR；先做格式把關（後端腳本會再驗一次）
$needs_cidr = in_array($action, ['add', 'remove'], true);
if ($needs_cidr && !preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $cidr)) {
    exit(json_encode(['ok' => false, 'error' => 'CIDR 格式不正確（需如 172.16.5.0/24）']));
}

$cmd = ['sudo', '/opt/oracle-mon/admin/manage-mgmt-cidrs.sh', $action];
if ($needs_cidr) {
    $cmd[] = $cidr;
}

$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$rc = proc_close($proc);

$username  = Auth::user()->username;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
@file_put_contents('/var/log/oracle-admin.log',
    date('Y-m-d H:i:s') . " [FW_$action] user=$username from=$client_ip cidr=$cidr rc=$rc\n",
    FILE_APPEND);

if ($rc !== 0) {
    exit(json_encode(['ok' => false, 'error' => '執行失敗：' . trim($err ?: $out)]));
}

$result = json_decode($out, true);
if (!is_array($result)) {
    exit(json_encode(['ok' => false, 'error' => '輸出格式錯誤', 'raw' => trim($out)]));
}
echo json_encode($result);
