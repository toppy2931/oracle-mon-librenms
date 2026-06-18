<?php

/**
 * oracle-dg.inc.php — Oracle 9i DataGuard Application Poller
 *
 * 部署位置：/opt/librenms/includes/polling/applications/oracle-dg.inc.php
 *
 * 資料來源：/tmp/oracle-monitor.json（由 OracleMonitor.java 每 5 分鐘產生）
 */

use App\Models\Eventlog;
use LibreNMS\RRD\RrdDefinition;

$name = 'oracle-dg';
$json_file = '/tmp/oracle-monitor.json';

if (! file_exists($json_file) || (time() - filemtime($json_file)) > 600) {
    Eventlog::log("oracle-dg: /tmp/oracle-monitor.json 不存在或超過 10 分鐘未更新", $device['device_id'], 'application');

    return;
}

$data = json_decode(file_get_contents($json_file), true);
if (! $data || ! isset($data['dataguard'])) {
    Eventlog::log("oracle-dg: JSON 解析失敗或缺少 dataguard 欄位", $device['device_id'], 'application');

    return;
}

$can_connect = (int) ($data['can_connect'] ?? 0);
$dg = $data['dataguard'];

$is_primary     = (int) ($dg['is_primary']     ?? -1);
$db_open        = (int) ($dg['db_open']        ?? 0);
$mrp_running    = (int) ($dg['mrp_running']    ?? -1);
$rfs_connected  = (int) ($dg['rfs_connected']  ?? -1);
$current_seq    = (int) ($dg['current_seq']    ?? 0);
$applied_seq    = (int) ($dg['applied_seq']    ?? -1);
$apply_lag_seqs = (int) ($dg['apply_lag_seqs'] ?? 0);
$lag_seconds    = (int) ($dg['lag_seconds']    ?? 0);
$dest_ok        = (int) ($dg['dest_ok']        ?? -1);
$dest_has_error = (int) ($dg['dest_has_error'] ?? -1);
$protection_mode = (int) ($dg['protection_mode'] ?? 2);

// ── RRD 定義 ──────────────────────────────────────────────────────────────
$rrd_name = ['app', $name, $app->app_id];
$rrd_def = RrdDefinition::make()
    ->addDataset('can_connect',    'GAUGE', 0, 1)
    ->addDataset('is_primary',     'GAUGE', 0, 1)
    ->addDataset('db_open',        'GAUGE', 0, 1)
    ->addDataset('mrp_running',    'GAUGE', -1, 1)
    ->addDataset('rfs_connected',  'GAUGE', -1, 1)
    ->addDataset('dest_ok',        'GAUGE', -1, 1)
    ->addDataset('dest_has_error', 'GAUGE', -1, 1)
    ->addDataset('protection_mode','GAUGE', 0, 2)
    ->addDataset('current_seq',    'GAUGE', 0)
    ->addDataset('applied_seq',    'GAUGE', -1)
    ->addDataset('apply_lag_seqs', 'GAUGE', 0)
    ->addDataset('lag_seconds',    'GAUGE', 0);

$fields = [
    'can_connect'    => $can_connect,
    'is_primary'     => $is_primary,
    'db_open'        => $db_open,
    'mrp_running'    => $mrp_running,
    'rfs_connected'  => $rfs_connected,
    'dest_ok'        => $dest_ok,
    'dest_has_error' => $dest_has_error,
    'protection_mode'=> $protection_mode,
    'current_seq'    => $current_seq,
    'applied_seq'    => $applied_seq,
    'apply_lag_seqs' => $apply_lag_seqs,
    'lag_seconds'    => $lag_seconds,
];

$metrics['none'] = $fields;
$tags = [
    'name'     => $name,
    'app_id'   => $app->app_id,
    'rrd_def'  => $rrd_def,
    'rrd_name' => $rrd_name,
];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── 事件記錄 ───────────────────────────────────────────────────────────────
if ($can_connect === 0) {
    Eventlog::log("oracle-dg [{$device['hostname']}]: 無法連線 Oracle DB", $device['device_id'], 'application');
} elseif ($is_primary === 0 && $mrp_running === 0) {
    Eventlog::log("oracle-dg [{$device['hostname']}]: ⚠️ Standby MRP 未執行，DataGuard apply 停止！", $device['device_id'], 'application');
} elseif ($is_primary === 1 && $dest_has_error === 1) {
    Eventlog::log("oracle-dg [{$device['hostname']}]: ⚠️ Archive dest 傳送至 Standby 發生錯誤", $device['device_id'], 'application');
}

update_application($app, ($data['collected_at'] ?? ''), $metrics);
