<?php
/**
 * oracle-db-remove.php — toggle/delete Oracle DB from monitoring
 * actions: 'toggle' (enable/disable), 'delete'
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
$action = $body['action'] ?? '';
$alias  = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['alias'] ?? ''));

if (!$alias) exit(json_encode(['error' => 'alias required']));

$conf_file = "/opt/oracle-mon/dbs/$alias.conf";
$username  = Auth::user()->username;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($action === 'toggle') {
    // Read existing conf
    if (!file_exists($conf_file)) exit(json_encode(['error' => 'conf not found']));
    $conf = parse_ini_file($conf_file);
    if (!$conf) exit(json_encode(['error' => 'cannot parse conf']));

    $enable  = ($body['enable'] ?? '0') === '1' ? '1' : '0';
    $host    = $conf['DB_HOST']  ?? '';
    $port    = $conf['DB_PORT']  ?? '1521';
    $sid     = $conf['DB_SID']   ?? '';
    $user    = $conf['DB_USER']  ?? '';
    $label   = $conf['DB_LABEL'] ?? $alias;

    // Save with updated enabled flag (empty pass = preserve existing)
    $proc = proc_open(
        ['sudo', '/opt/oracle-mon/admin/save-db-conf.sh', $alias, $host, $port, $sid, $user, '', $label, $enable],
        [1 => ['pipe','w'], 2 => ['pipe','w']],
        $pipes
    );
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($proc);
    if ($rc !== 0) exit(json_encode(['error' => '更新失敗：' . trim($err ?: $out)]));

    // Update snmpd extends (via queue — /etc is EROFS under ProtectSystem=full)
    $proc2 = proc_open(
        ['sudo', '/opt/oracle-mon/admin/queue-request.sh', 'snmpd', 'update'],
        [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes2
    );
    stream_get_contents($pipes2[1]); stream_get_contents($pipes2[2]);
    fclose($pipes2[1]); fclose($pipes2[2]);
    proc_close($proc2);

    @file_put_contents('/var/log/oracle-admin.log',
        date('Y-m-d H:i:s') . " [TOGGLE] user=$username from=$client_ip alias=$alias enabled=$enable\n",
        FILE_APPEND);

    echo json_encode(['ok' => true]);

} elseif ($action === 'delete') {
    // Remove conf file
    $proc = proc_open(
        ['sudo', '/opt/oracle-mon/admin/remove-db-conf.sh', $alias],
        [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes
    );
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($proc);
    if ($rc !== 0) exit(json_encode(['error' => '刪除失敗：' . trim($err ?: $out)]));

    // Update snmpd (via queue — /etc is EROFS under ProtectSystem=full)
    $proc2 = proc_open(
        ['sudo', '/opt/oracle-mon/admin/queue-request.sh', 'snmpd', 'update'],
        [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes2
    );
    stream_get_contents($pipes2[1]); stream_get_contents($pipes2[2]);
    fclose($pipes2[1]); fclose($pipes2[2]);
    proc_close($proc2);

    // Soft-delete LibreNMS application (preserve RRD history)
    try {
        \DB::table('applications')
            ->where('device_id', 1)
            ->where('app_type', "oracle-$alias")
            ->update(['deleted_at' => now()]);
    } catch (\Exception $e) {}

    @file_put_contents('/var/log/oracle-admin.log',
        date('Y-m-d H:i:s') . " [DELETE] user=$username from=$client_ip alias=$alias\n",
        FILE_APPEND);

    echo json_encode(['ok' => true]);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
