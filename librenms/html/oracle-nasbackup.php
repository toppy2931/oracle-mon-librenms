<?php
/**
 * oracle-nasbackup.php — NAS 備份管理（區塊 E）
 *
 * 讀取（status/test）直接呼叫 manage-nas-backup.sh（不寫 /etc）。
 * 異動（save/sync/unmount）改「排入佇列」由 root applier 套用——save 需寫
 * /etc/fstab + /etc/systemd/system 並呼叫 mount/systemctl，php-fpm（ProtectSystem=full）
 * 的 sudo 子行程做不到。
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

$readonly = in_array($action, ['status', 'test'], true);

if ($readonly) {
    // 唯讀 → 直接呼叫
    $result = run_json(['sudo', '/opt/oracle-mon/admin/manage-nas-backup.sh', $action]);
} else {
    // 異動 → 排入佇列
    $args = [];
    if ($action === 'save') {
        $protocol   = $body['protocol']   ?? '';
        $server     = $body['server']     ?? '';
        $export     = $body['export']     ?? '';
        $mountpoint = $body['mountpoint'] ?? '';
        $schedule   = $body['schedule']   ?? '';
        // 基本前端把關（後端腳本會再嚴格驗一次）
        if (!preg_match('#^(nfs|cifs)$#', $protocol))             exit(json_encode(['ok'=>false,'error'=>'協定需為 nfs/cifs']));
        if (!preg_match('#^[A-Za-z0-9.\-]+$#', $server))          exit(json_encode(['ok'=>false,'error'=>'NAS 位址格式不正確']));
        if (!preg_match('#^[A-Za-z0-9._/\-]+$#', $export))        exit(json_encode(['ok'=>false,'error'=>'匯出路徑格式不正確']));
        if (!preg_match('#^/mnt/[A-Za-z0-9._\-]+$#', $mountpoint)) exit(json_encode(['ok'=>false,'error'=>'掛載點需為 /mnt/xxx']));
        if (!preg_match('#^(hourly|6h|daily)$#', $schedule))      exit(json_encode(['ok'=>false,'error'=>'排程需為 hourly/6h/daily']));
        $args = [$protocol, $server, $export, $mountpoint, $schedule];
        if ($protocol === 'cifs') {
            $args[] = $body['cifs_user'] ?? '';
            $args[] = $body['cifs_pass'] ?? '';
        }
    }
    $result = run_json(array_merge(
        ['sudo', '/opt/oracle-mon/admin/queue-request.sh', 'nas', $action],
        $args
    ));
}

$username  = Auth::user()->username;
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
@file_put_contents('/var/log/oracle-admin.log',
    date('Y-m-d H:i:s') . " [NAS_$action] user=$username from=$client_ip\n",
    FILE_APPEND);

echo json_encode($result);
