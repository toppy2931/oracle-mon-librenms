<?php
/**
 * oracle-db-add.php — add a new Oracle DB to monitoring
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
if (!$body) { http_response_code(400); exit(json_encode(['error' => 'Invalid JSON'])); }

$alias = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['alias'] ?? ''));
$host  = $body['host'] ?? '';
$port  = (int)($body['port'] ?? 1521);
$sid   = preg_replace('/[^A-Za-z0-9_]/', '', $body['sid'] ?? '');
$user  = preg_replace('/[^A-Za-z0-9_]/', '', $body['user'] ?? '');
$pass  = $body['pass'] ?? '';
$label = substr(preg_replace('/[^\w\s\-（）().]/', '', $body['label'] ?? $alias), 0, 80);

if (!$alias) exit(json_encode(['error' => '別名不可為空，且只允許小寫字母、數字、連字號']));
if (!filter_var($host, FILTER_VALIDATE_IP)) exit(json_encode(['error' => 'IP 格式不正確']));
if ($port < 1 || $port > 65535) exit(json_encode(['error' => 'Port 範圍錯誤（1-65535）']));
if (!$sid) exit(json_encode(['error' => 'SID 不可為空']));

// Check alias uniqueness
$conf_dir = '/opt/oracle-mon/dbs';
if (file_exists("$conf_dir/$alias.conf")) {
    exit(json_encode(['error' => "別名「$alias」已存在，請使用不同的別名"]));
}

// Step 1: Save conf
$proc = proc_open(
    ['sudo', '/opt/oracle-mon/admin/save-db-conf.sh', $alias, $host, (string)$port, $sid, $user ?: 'librenms', $pass, $label, '1'],
    [1 => ['pipe','w'], 2 => ['pipe','w']],
    $pipes
);
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$rc = proc_close($proc);
if ($rc !== 0) exit(json_encode(['error' => '建立 conf 失敗：' . trim($err ?: $out)]));

// Step 2: Update snmpd extends
$proc2 = proc_open(
    ['sudo', '/opt/oracle-mon/admin/update-snmpd-extends.sh'],
    [1 => ['pipe','w'], 2 => ['pipe','w']],
    $pipes2
);
$out2 = stream_get_contents($pipes2[1]); $err2 = stream_get_contents($pipes2[2]);
fclose($pipes2[1]); fclose($pipes2[2]);
$rc2 = proc_close($proc2);
if ($rc2 !== 0) exit(json_encode(['error' => 'snmpd 更新失敗：' . trim($err2 ?: $out2)]));

// Step 3: Add LibreNMS application (device_id=1 = monitor-vm)
try {
    $existing = \DB::table('applications')
        ->where('device_id', 1)
        ->where('app_type', "oracle-$alias")
        ->whereNull('deleted_at')
        ->count();
    if ($existing === 0) {
        \DB::table('applications')->insert([
            'device_id'    => 1,
            'app_type'     => "oracle-$alias",
            'app_state'    => 'NOTPOLLED',
            'app_instance' => '',
            'app_status'   => '',
            'discovered'   => 0,
        ]);
    }
} catch (\Exception $e) {
    // Non-fatal: LibreNMS discovery will register it on next poll
}

// Log
$username = Auth::user()->username;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
@file_put_contents('/var/log/oracle-admin.log',
    date('Y-m-d H:i:s') . " [ADD_DB] user=$username from=$client_ip alias=$alias host=$host\n",
    FILE_APPEND);

echo json_encode(['ok' => true, 'msg' => "DB oracle-$alias 已新增，snmpd 重啟成功"]);
