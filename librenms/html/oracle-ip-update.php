<?php
/**
 * oracle-ip-update.php — update monitor-vm IP in LibreNMS settings
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
$new_ip = $body['new_ip'] ?? '';
$old_ip = $body['old_ip'] ?? '';

if (!filter_var($new_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    exit(json_encode(['error' => 'IP 格式不正確（需為 IPv4）']));
}
// old_ip 為可選；若提供則必須是合法 IPv4，後續用來自動掃描殘留
if ($old_ip !== '' && !filter_var($old_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $old_ip = '';
}

// Only run the update-librenms-url.sh — it handles all three steps internally
$proc = proc_open(
    ['sudo', '/opt/oracle-mon/admin/update-librenms-url.sh', $new_ip],
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
    date('Y-m-d H:i:s') . " [IP_UPDATE] user=$username from=$client_ip new_ip=$new_ip rc=$rc\n",
    FILE_APPEND);

if ($rc !== 0) {
    exit(json_encode(['error' => '更新失敗：' . trim($err ?: $out)]));
}

// Parse steps from output
$steps = array_filter(array_map('trim', explode("\n", $out)));
$steps = array_values(array_filter($steps, fn($s) => str_starts_with($s, 'OK:') || str_starts_with($s, 'WARN:')));

// Auto-scan：IP 更新成功後，用「舊 IP」掃一遍已知設定檔，回報殘留
// 注意：base_url/menu.blade.php 等設定通常都該被更新為新 IP，所以掃舊 IP 時若還命中，
// 表示有檔案沒被同步（典型場景：第三方寫死的設定、手動加進去的 blade 連結等）
$scan_results = null;
if ($old_ip !== '') {
    $scan_proc = proc_open(
        ['sudo', '/opt/oracle-mon/admin/scan-old-ip.sh', $old_ip],
        [1 => ['pipe','w'], 2 => ['pipe','w']],
        $scan_pipes
    );
    if (is_resource($scan_proc)) {
        $scan_out = stream_get_contents($scan_pipes[1]);
        fclose($scan_pipes[1]);
        fclose($scan_pipes[2]);
        $scan_rc = proc_close($scan_proc);
        if ($scan_rc === 0) {
            $scan_results = json_decode($scan_out, true);
        }
    }
}

// 若提供「遮罩(CIDR)＋閘道」→ 產生 netplan（只寫不套用，交 root applier 經佇列）
$netplan  = null;
$new_cidr = ltrim((string)($body['new_cidr'] ?? ''), '/');
$new_gw   = (string)($body['new_gateway'] ?? '');
if ($new_cidr !== '' && $new_gw !== '') {
    if (!preg_match('/^\d{1,2}$/', $new_cidr) || (int)$new_cidr > 32) {
        $netplan = ['ok' => false, 'error' => '遮罩需為 CIDR 字首（0..32）'];
    } elseif (!filter_var($new_gw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $netplan = ['ok' => false, 'error' => '閘道格式不正確（需為 IPv4）'];
    } else {
        $np = proc_open(
            ['sudo', '/opt/oracle-mon/admin/queue-request.sh', 'net', 'plan', $new_ip, $new_cidr, $new_gw],
            [1 => ['pipe','w'], 2 => ['pipe','w']], $nppipes);
        if (is_resource($np)) {
            $npout = stream_get_contents($nppipes[1]);
            fclose($nppipes[1]); fclose($nppipes[2]); proc_close($np);
            $netplan = json_decode(trim($npout), true) ?: ['ok' => false, 'error' => 'netplan 佇列無回應', 'raw' => trim($npout)];
        } else {
            $netplan = ['ok' => false, 'error' => '無法排入 netplan 佇列'];
        }
        @file_put_contents('/var/log/oracle-admin.log',
            date('Y-m-d H:i:s') . " [IP_NETPLAN] user=$username from=$client_ip ip=$new_ip/$new_cidr gw=$new_gw\n",
            FILE_APPEND);
    }
}

echo json_encode([
    'ok'           => true,
    'new_ip'       => $new_ip,
    'steps'        => $steps,
    'scan_results' => $scan_results,
    'netplan'      => $netplan,
]);
