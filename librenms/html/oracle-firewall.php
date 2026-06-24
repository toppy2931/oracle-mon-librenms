<?php
/**
 * oracle-firewall.php — 管理「管理網段」防火牆設定（區塊 D）
 *
 * 讀取（list/rules）直接呼叫 manage-mgmt-cidrs.sh（不寫 /etc，可在 php-fpm 下跑）。
 * 異動（add/remove）改「排入佇列」，由 root 權限的 systemd applier 套用——因為
 * php-fpm.service 設 ProtectSystem=full，其 sudo 子行程無法寫 /etc/ufw（EROFS）。
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
$rule   = $body['rule'] ?? '';

if (!in_array($action, ['list', 'add', 'remove', 'rules', 'delete-rule'], true)) {
    exit(json_encode(['ok' => false, 'error' => '未知動作']));
}
$needs_cidr = in_array($action, ['add', 'remove'], true);
if ($needs_cidr && !preg_match('#^(\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $cidr)) {
    exit(json_encode(['ok' => false, 'error' => 'CIDR 格式不正確（需如 172.16.5.0/24）']));
}
if ($action === 'delete-rule') {
    // 規則字串：白名單字元集，避免奇怪輸入
    if (!preg_match('#^[A-Za-z0-9 /.():\-\#_,]+$#', $rule) || strlen($rule) > 200) {
        exit(json_encode(['ok' => false, 'error' => '規則字串格式不合法']));
    }
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

if ($needs_cidr) {
    // 異動 → 排入佇列，由 root applier 套 ufw + 寫 /var/lib conf
    $result = run_json(['sudo', '/opt/oracle-mon/admin/queue-request.sh', 'fw', $action, $cidr]);
} elseif ($action === 'delete-rule') {
    // 也是異動 → 排佇列（須動 /etc/ufw）
    $result = run_json(['sudo', '/opt/oracle-mon/admin/queue-request.sh', 'fw', 'delete-rule', $rule]);
} else {
    // 唯讀 → 直接呼叫（不寫 /etc）
    $result = run_json(['sudo', '/opt/oracle-mon/admin/manage-mgmt-cidrs.sh', $action]);
}

$username  = Auth::user()->username;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
@file_put_contents('/var/log/oracle-admin.log',
    date('Y-m-d H:i:s') . " [FW_$action] user=$username from=$client_ip cidr=$cidr rule=$rule\n",
    FILE_APPEND);

echo json_encode($result);
