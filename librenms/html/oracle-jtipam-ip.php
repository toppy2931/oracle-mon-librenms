<?php
/**
 * oracle-jtipam-ip.php — 查詢 / 更新 jt-ipam 對外存取 IP（區塊 G）
 *
 * 讀取（list）直接呼叫 manage-jt-ipam-ip.sh（不寫 /etc，可在 php-fpm 下跑）。
 * 異動（update-ip）改「排入佇列」，由 root 權限的 systemd applier 套用——因為
 * php-fpm.service 設 ProtectSystem=full，其 sudo 子行程無法寫 /etc/jt-ipam、
 * /etc/nginx（EROFS），也無法可靠地 restart 服務。
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
$new_ip = $body['new_ip'] ?? '';

if (!in_array($action, ['list', 'update-ip'], true)) {
    exit(json_encode(['ok' => false, 'error' => '未知動作']));
}
if ($action === 'update-ip' && !preg_match('#^(\d{1,3}\.){3}\d{1,3}$#', $new_ip)) {
    exit(json_encode(['ok' => false, 'error' => 'IP 格式不正確']));
}

// 執行命令並取回 JSON 陣列
function run_json(array $cmd): array {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return ['ok' => false, 'error' => '無法執行命令'];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($proc);
    if ($rc !== 0 && trim($out) === '') {
        return ['ok' => false, 'error' => '執行失敗：' . trim($err ?: $out)];
    }
    $r = json_decode(trim($out), true);
    return is_array($r) ? $r : ['ok' => false, 'error' => '輸出格式錯誤', 'raw' => trim($out)];
}

if ($action === 'update-ip') {
    // 異動 → 排入佇列，由 root applier 改設定 + restart 服務
    $result = run_json(['sudo', '/opt/oracle-mon/admin/queue-request.sh', 'jtipam', 'update-ip', $new_ip]);
} else {
    // 唯讀 → 直接呼叫（不寫 /etc）
    $result = run_json(['sudo', '/opt/oracle-mon/admin/manage-jt-ipam-ip.sh', 'list']);
}

$username  = Auth::user()->username;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
@file_put_contents('/var/log/oracle-admin.log',
    date('Y-m-d H:i:s') . " [JTIPAM_$action] user=$username from=$client_ip new_ip=$new_ip\n",
    FILE_APPEND);

echo json_encode($result);
