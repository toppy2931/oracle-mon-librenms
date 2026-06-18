<?php

/**
 * oracle-mv.inc.php — Oracle Materialized View Application Poller
 *
 * 部署位置：/opt/librenms/includes/polling/applications/oracle-mv.inc.php
 *
 * 資料來源：/tmp/oracle-monitor.json（由 OracleMonitor.java 每 5 分鐘產生）
 */

use App\Models\Eventlog;
use LibreNMS\RRD\RrdDefinition;

$name = 'oracle-mv';
$json_file = '/tmp/oracle-monitor.json';

if (! file_exists($json_file) || (time() - filemtime($json_file)) > 600) {
    Eventlog::log("oracle-mv: /tmp/oracle-monitor.json 不存在或超過 10 分鐘未更新", $device['device_id'], 'application');

    return;
}

$data = json_decode(file_get_contents($json_file), true);
if (! $data) {
    Eventlog::log("oracle-mv: JSON 解析失敗", $device['device_id'], 'application');

    return;
}

$can_connect = (int) ($data['can_connect'] ?? 0);
$mv_list = $data['materialized_views'] ?? [];

$mv_total        = count($mv_list);
$mv_stale_count  = 0;
$mv_failed_count = 0;
foreach ($mv_list as $mv) {
    if (($mv['is_stale'] ?? 1) === 1) $mv_stale_count++;
    if (($mv['refresh_ok'] ?? 0) === 0) $mv_failed_count++;
}

// ── Aggregate RRD ──────────────────────────────────────────────────────────
$rrd_name = ['app', $name, $app->app_id];
$rrd_def = RrdDefinition::make()
    ->addDataset('can_connect',     'GAUGE', 0, 1)
    ->addDataset('mv_total',        'GAUGE', 0)
    ->addDataset('mv_stale_count',  'GAUGE', 0)
    ->addDataset('mv_failed_count', 'GAUGE', 0);

$agg_fields = [
    'can_connect'     => $can_connect,
    'mv_total'        => $mv_total,
    'mv_stale_count'  => $mv_stale_count,
    'mv_failed_count' => $mv_failed_count,
];

$tags = [
    'name'     => $name,
    'app_id'   => $app->app_id,
    'rrd_def'  => $rrd_def,
    'rrd_name' => $rrd_name,
];
app('Datastore')->put($device, 'app', $tags, $agg_fields);
$metrics['none'] = $agg_fields;

// ── Per-MV RRD ────────────────────────────────────────────────────────────
$rrd_def_mv = RrdDefinition::make()
    ->addDataset('age_minutes', 'GAUGE', 0, 99999)
    ->addDataset('is_stale',    'GAUGE', 0, 1)
    ->addDataset('refresh_ok',  'GAUGE', 0, 1);

foreach ($mv_list as $mv) {
    $mv_name     = $mv['name'] ?? '';
    $age_minutes = (int) ($mv['age_minutes'] ?? 0);
    $is_stale    = (int) ($mv['is_stale']    ?? 1);
    $refresh_ok  = (int) ($mv['refresh_ok']  ?? 0);

    if (empty($mv_name)) continue;

    $mv_rrd_name = ['app', $name, $app->app_id, $mv_name];
    $mv_tags = [
        'name'     => $name,
        'app_id'   => $app->app_id,
        'rrd_def'  => $rrd_def_mv,
        'rrd_name' => $mv_rrd_name,
    ];
    $mv_fields = [
        'age_minutes' => $age_minutes,
        'is_stale'    => $is_stale,
        'refresh_ok'  => $refresh_ok,
    ];
    app('Datastore')->put($device, 'app', $mv_tags, $mv_fields);
    $metrics[$mv_name] = $mv_fields;
}

// ── 事件記錄 ───────────────────────────────────────────────────────────────
if ($can_connect === 0) {
    Eventlog::log("oracle-mv [{$device['hostname']}]: 無法連線 Oracle DB", $device['device_id'], 'application');
} elseif ($mv_failed_count > 0) {
    Eventlog::log("oracle-mv [{$device['hostname']}]: ⚠️ {$mv_failed_count} 個 MV 狀態 UNUSABLE", $device['device_id'], 'application');
}

$status = $can_connect ? "ok:{$mv_total} stale:{$mv_stale_count}" : 'down';
update_application($app, $status, $metrics);
