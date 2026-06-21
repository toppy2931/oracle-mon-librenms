<?php
/**
 * oracle-nasbackup.php — NAS 備份管理（區塊 E）
 * action: status | save | test | sync | unmount，經 sudo 呼叫 manage-nas-backup.sh。
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

if (!in_array($action, ['status', 'save', 'test', 'sync', 'unmount'], true)) {
    exit(json_encode(['ok' => false, 'error' => '未知動作']));
}

$cmd = ['sudo', '/opt/oracle-mon/admin/manage-nas-backup.sh', $action];

if ($action === 'save') {
    $protocol   = $body['protocol']   ?? '';
    $server     = $body['server']     ?? '';
    $export     = $body['export']     ?? '';
    $mountpoint = $body['mountpoint'] ?? '';
    $schedule   = $body['schedule']   ?? '';
    // 基本前端把關（後端腳本會再嚴格驗一次）
    if (!preg_match('#^(nfs|cifs)$#', $protocol))            exit(json_encode(['ok'=>false,'error'=>'協定需為 nfs/cifs']));
    if (!preg_match('#^[A-Za-z0-9.\-]+$#', $server))         exit(json_encode(['ok'=>false,'error'=>'NAS 位址格式不正確']));
    if (!preg_match('#^[A-Za-z0-9._/\-]+$#', $export))       exit(json_encode(['ok'=>false,'error'=>'匯出路徑格式不正確']));
    if (!preg_match('#^/mnt/[A-Za-z0-9._\-]+$#', $mountpoint)) exit(json_encode(['ok'=>false,'error'=>'掛載點需為 /mnt/xxx']));
    if (!preg_match('#^(hourly|6h|daily)$#', $schedule))     exit(json_encode(['ok'=>false,'error'=>'排程需為 hourly/6h/daily']));
    $cmd[] = $protocol; $cmd[] = $server; $cmd[] = $export; $cmd[] = $mountpoint; $cmd[] = $schedule;
    if ($protocol === 'cifs') {
        $cmd[] = $body['cifs_user'] ?? '';
        $cmd[] = $body['cifs_pass'] ?? '';
    }
}

$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$rc = proc_close($proc);

$username  = Auth::user()->username;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
@file_put_contents('/var/log/oracle-admin.log',
    date('Y-m-d H:i:s') . " [NAS_$action] user=$username from=$client_ip rc=$rc\n",
    FILE_APPEND);

if ($rc !== 0) {
    exit(json_encode(['ok' => false, 'error' => '執行失敗：' . trim($err ?: $out)]));
}
$result = json_decode($out, true);
if (!is_array($result)) {
    exit(json_encode(['ok' => false, 'error' => '輸出格式錯誤', 'raw' => trim($out)]));
}
echo json_encode($result);
