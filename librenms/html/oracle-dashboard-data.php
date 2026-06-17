<?php
/**
 * oracle-dashboard-data.php — JSON data feed for the Oracle 戰情室 dashboard.
 * Aggregates live metrics for every enabled DB by invoking the existing
 * collector wrapper (sudo /opt/oracle-mon/admin/test-db.sh <alias>).
 * URL: http://<monitor-vm>/oracle-dashboard-data.php  (auth + CSRF required)
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check() || !Auth::user()->hasRole('admin')) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals(csrf_token(), $token)) {
    http_response_code(419);
    exit(json_encode(['error' => 'CSRF mismatch']));
}

$conf_dir = '/opt/oracle-mon/dbs';
$dbs = [];
if (is_dir($conf_dir)) {
    foreach (glob("$conf_dir/*.conf") as $f) {
        $d = parse_ini_file($f);
        if ($d && !empty($d['DB_ALIAS'])) {
            $dbs[] = $d;
        }
    }
}

$results = [];
foreach ($dbs as $d) {
    $alias = preg_replace('/[^a-z0-9\-]/', '', strtolower($d['DB_ALIAS']));
    if (!$alias) {
        continue;
    }
    $enabled = ($d['DB_ENABLED'] ?? '1') == '1';
    $entry = [
        'alias'   => $alias,
        'label'   => $d['DB_LABEL'] ?? $alias,
        'host'    => $d['DB_HOST'] ?? '',
        'port'    => $d['DB_PORT'] ?? '',
        'sid'     => $d['DB_SID'] ?? '',
        'enabled' => $enabled,
    ];

    if (!$enabled) {
        $entry['connected'] = false;
        $entry['skipped']   = true;
        $entry['metrics']   = [];
        $results[] = $entry;
        continue;
    }

    $out = '';
    $err = '';
    $process = proc_open(
        ['sudo', '/opt/oracle-mon/admin/test-db.sh', $alias],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (is_resource($process)) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    $j    = json_decode(trim($out), true);
    $data = $j['data'] ?? [];
    $entry['connected'] = ($j['error'] ?? 1) === 0 && ($data['instance_up'] ?? 0) == 1;
    $entry['error']     = $entry['connected'] ? null : ($j['errorString'] ?? (trim($err) ?: '連線失敗'));
    $entry['metrics']   = $data;
    $results[] = $entry;
}

echo json_encode([
    'ts'  => date('Y-m-d H:i:s'),
    'dbs' => $results,
], JSON_UNESCAPED_UNICODE);
