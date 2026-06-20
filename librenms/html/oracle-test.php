<?php
/**
 * oracle-test.php — test Oracle DB connection
 *
 * 支援兩種模式：
 *   1. 既有：POST {alias: "l1hweb"}              → 讀 dbs/<alias>.conf 測試
 *   2. ad-hoc：POST {host, port, sid, user, pass, alias?} → 用表單即時值測試
 *      若 pass 空白且有 alias，從對應 .conf 撈現存密碼（沿用「空白=不變更」UX）
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

$body  = json_decode(file_get_contents('php://input'), true) ?: [];
$alias = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['alias'] ?? ''));

// 偵測模式：host/sid 非空 → ad-hoc；否則用 alias 走原有 .conf 路徑
$adhoc_host = trim($body['host'] ?? '');
$adhoc_sid  = trim($body['sid']  ?? '');
$is_adhoc   = $adhoc_host !== '' && $adhoc_sid !== '';

if ($is_adhoc) {
    // === ad-hoc 模式：用表單值即時測試 ===
    $host = $adhoc_host;
    $port = (string)((int)($body['port'] ?? 1521));
    $sid  = $adhoc_sid;
    $user = trim($body['user'] ?? '');
    $pass = (string)($body['pass'] ?? '');

    // 表單字段驗證
    if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host) || !filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z]/', $host)) {
        // 允許 IP 或 hostname；上面 regex 已含 dash 與 dot
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            exit(json_encode(['connected' => false, 'error' => 'host 格式不合法']));
        }
    }
    if ((int)$port < 1 || (int)$port > 65535) {
        exit(json_encode(['connected' => false, 'error' => 'port 不在 1-65535']));
    }
    if (!preg_match('/^[A-Za-z0-9_$.\-]+$/', $sid)) {
        exit(json_encode(['connected' => false, 'error' => 'sid 格式不合法']));
    }
    if ($user === '') {
        exit(json_encode(['connected' => false, 'error' => 'user 不可為空']));
    }

    // 密碼空白 + 有 alias → 從 .conf 撈現存密碼（沿用「空白 = 不變更」UX）
    if ($pass === '' && $alias) {
        $conf_path = "/opt/oracle-mon/dbs/{$alias}.conf";
        if (is_readable($conf_path)) {
            $conf = parse_ini_file($conf_path);
            $pass = $conf['DB_PASS'] ?? '';
        }
    }
    if ($pass === '') {
        exit(json_encode(['connected' => false, 'error' => '密碼為空（且無對應 .conf 可撈）']));
    }

    $cmd = ['sudo', '/opt/oracle-mon/admin/test-db-adhoc.sh', $host, $port, $sid, $user, $pass];
} else {
    // === alias 模式（既有）：讀 .conf 測試 ===
    if (!$alias) {
        http_response_code(400);
        exit(json_encode(['error' => 'alias required']));
    }
    $cmd = ['sudo', '/opt/oracle-mon/admin/test-db.sh', $alias];
}

$process = proc_open(
    $cmd,
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
    echo json_encode(['connected' => false, 'error' => trim($error), 'mode' => $is_adhoc ? 'adhoc' : 'alias']);
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
    'mode'           => $is_adhoc ? 'adhoc' : 'alias',
]);
