<?php

use LibreNMS\Exceptions\JsonAppException;
use LibreNMS\Exceptions\JsonAppMissingKeysException;
use LibreNMS\RRD\RrdDefinition;

$name = 'oracle-l1hweb';

try {
    $oracle_data = json_app_get($device, $name, 1)['data'];
} catch (JsonAppMissingKeysException $e) {
    $oracle_data = $e->getParsedJson()['data'] ?? [];
} catch (JsonAppException $e) {
    echo PHP_EOL . $name . ':' . $e->getCode() . ':' . $e->getMessage() . PHP_EOL;
    update_application($app, $e->getCode() . ':' . $e->getMessage(), []);
    return;
}

$metrics = [];

// ── Sessions（原有）──
$category = 'sessions';
$fields = [
    'total'  => $oracle_data['sessions_total'] ?? 0,
    'active' => $oracle_data['sessions_active'] ?? 0,
    'logons' => $oracle_data['logons_current'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('total', 'GAUGE', 0)
    ->addDataset('active', 'GAUGE', 0)
    ->addDataset('logons', 'GAUGE', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Performance（原有，加 temp）──
$category = 'performance';
$fields = [
    'instance_up'    => $oracle_data['instance_up'] ?? 0,
    'buffer_hit_pct' => $oracle_data['buffer_hit_pct'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('instance_up', 'GAUGE', 0, 1)
    ->addDataset('buffer_hit_pct', 'GAUGE', 0, 100);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── SGA Hit Ratios（dbstat2.sh）──
$category = 'sga';
$fields = [
    'dict_hit'   => $oracle_data['dict_cache_hit_pct'] ?? 0,
    'lib_hit'    => $oracle_data['lib_cache_hit_pct'] ?? 0,
    'latch_hit'  => $oracle_data['latch_hit_pct'] ?? 0,
    'buf_hit'    => $oracle_data['buffer_hit_pct'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('dict_hit', 'GAUGE', 0, 100)
    ->addDataset('lib_hit', 'GAUGE', 0, 100)
    ->addDataset('latch_hit', 'GAUGE', 0, 100)
    ->addDataset('buf_hit', 'GAUGE', 0, 100);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Physical I/O（COUNTER）──
$category = 'io';
$fields = [
    'phy_reads'  => $oracle_data['physical_reads'] ?? 0,
    'phy_writes' => $oracle_data['physical_writes'] ?? 0,
    'redo_writes' => $oracle_data['redo_writes'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('phy_reads', 'COUNTER', 0)
    ->addDataset('phy_writes', 'COUNTER', 0)
    ->addDataset('redo_writes', 'COUNTER', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── SQL Activity ──
$category = 'sql';
$fields = [
    'exec_count'   => $oracle_data['execute_count'] ?? 0,
    'parse_total'  => $oracle_data['parse_total'] ?? 0,
    'parse_hard'   => $oracle_data['parse_hard'] ?? 0,
    'sql_executing' => $oracle_data['sql_executing'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('exec_count', 'COUNTER', 0)
    ->addDataset('parse_total', 'COUNTER', 0)
    ->addDataset('parse_hard', 'COUNTER', 0)
    ->addDataset('sql_executing', 'GAUGE', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Sorts ──
$category = 'sorts';
$fields = [
    'disk'     => $oracle_data['sorts_disk'] ?? 0,
    'memory'   => $oracle_data['sorts_memory'] ?? 0,
    'disk_pct' => $oracle_data['disk_sort_pct'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('disk', 'COUNTER', 0)
    ->addDataset('memory', 'COUNTER', 0)
    ->addDataset('disk_pct', 'GAUGE', 0, 100);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── SGA Memory ──
$category = 'sga_memory';
$fields = [
    'sp_free'  => $oracle_data['shared_pool_free'] ?? 0,
    'sp_total' => $oracle_data['shared_pool_total'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('sp_free', 'GAUGE', 0)
    ->addDataset('sp_total', 'GAUGE', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Waits & Misc ──
$category = 'waits';
$fields = [
    'rollback_pct' => $oracle_data['rollback_wait_pct'] ?? 0,
    'temp_pct'     => $oracle_data['temp_pct_used'] ?? 0,
    'disk_sort_pct' => $oracle_data['disk_sort_pct'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('rollback_pct', 'GAUGE', 0, 100)
    ->addDataset('temp_pct', 'GAUGE', 0, 100)
    ->addDataset('disk_sort_pct', 'GAUGE', 0, 100);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Table Scans ──
$category = 'table_scans';
$fields = [
    'long_scans'  => $oracle_data['table_scans_long'] ?? 0,
    'short_scans' => $oracle_data['table_scans_short'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('long_scans', 'COUNTER', 0)
    ->addDataset('short_scans', 'COUNTER', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Database Health ──
$category = "health";
$fields = [
    "invalid_obj" => $oracle_data["invalid_objects"] ?? 0,
    "invalid_idx" => $oracle_data["invalid_indexes"] ?? 0,
    "archivelog"  => $oracle_data["archivelog_mode"] ?? 0,
    "db_open"     => $oracle_data["db_open"] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset("invalid_obj", "GAUGE", 0)
    ->addDataset("invalid_idx", "GAUGE", 0)
    ->addDataset("archivelog", "GAUGE", 0, 1)
    ->addDataset("db_open", "GAUGE", 0, 1);
$rrd_name = ["app", $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ["name" => $name, "app_id" => $app->app_id, "rrd_def" => $rrd_def, "rrd_name" => $rrd_name];
app("Datastore")->put($device, "app", $tags, $fields);

// ── Redo Log Activity ──
$category = 'redo';
$fields = [
    'redo_size'    => $oracle_data['redo_size'] ?? 0,
    'redo_space_req' => $oracle_data['redo_space_requests'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('redo_size', 'COUNTER', 0)
    ->addDataset('redo_space_req', 'COUNTER', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Library Cache Detail ──
$category = 'lib_cache';
$fields = [
    'hit_pct' => $oracle_data['lib_cache_hit_pct'] ?? 0,
    'pins'    => $oracle_data['lib_cache_pins'] ?? 0,
    'reloads' => $oracle_data['lib_cache_reloads'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('hit_pct', 'GAUGE', 0, 100)
    ->addDataset('pins', 'COUNTER', 0)
    ->addDataset('reloads', 'COUNTER', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Data Guard（通用能力：無 DG 時各項回 0）──
$category = 'dataguard';
$fields = [
    'dg_role'       => $oracle_data['dg_role'] ?? 0,
    'dg_switchover' => $oracle_data['dg_switchover'] ?? 0,
    'dg_standby'    => $oracle_data['dg_standby_cnt'] ?? 0,
    'dg_dest_valid' => $oracle_data['dg_dest_valid'] ?? 0,
    'dg_gap'        => $oracle_data['dg_gap'] ?? 0,
    'dg_apply_lag'  => $oracle_data['dg_apply_lag_min'] ?? 0,
    'dg_configured' => $oracle_data['dg_configured'] ?? 0,
    'seq_current'   => $oracle_data['dg_seq_current'] ?? 0,
    'seq_archived'  => $oracle_data['dg_seq_archived'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('dg_role', 'GAUGE', 0, 3)
    ->addDataset('dg_switchover', 'GAUGE', 0, 1)
    ->addDataset('dg_standby', 'GAUGE', 0)
    ->addDataset('dg_dest_valid', 'GAUGE', 0)
    ->addDataset('dg_gap', 'GAUGE', 0)
    ->addDataset('dg_apply_lag', 'GAUGE', 0)
    ->addDataset('dg_configured', 'GAUGE', 0, 1)
    ->addDataset('seq_current', 'GAUGE', 0)
    ->addDataset('seq_archived', 'GAUGE', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Materialized Views / Snapshots 刷新健康 ──
$category = 'mview';
$fields = [
    'total'          => $oracle_data['mview_total'] ?? 0,
    'stale'          => $oracle_data['mview_stale'] ?? 0,
    'jobs_broken'    => $oracle_data['mview_jobs_broken'] ?? 0,
    'jobs_failed'    => $oracle_data['mview_jobs_failed'] ?? 0,
    'refresh_groups' => $oracle_data['mview_refresh_groups'] ?? 0,
    'oldest_hours'   => $oracle_data['mview_oldest_hours'] ?? 0,
];
$rrd_def = RrdDefinition::make()
    ->addDataset('total', 'GAUGE', 0)
    ->addDataset('stale', 'GAUGE', 0)
    ->addDataset('jobs_broken', 'GAUGE', 0)
    ->addDataset('jobs_failed', 'GAUGE', 0)
    ->addDataset('refresh_groups', 'GAUGE', 0)
    ->addDataset('oldest_hours', 'GAUGE', 0);
$rrd_name = ['app', $name, $app->app_id, $category];
$metrics[$category] = $fields;
$tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
app('Datastore')->put($device, 'app', $tags, $fields);

// ── Per-Tablespace（原有）──
$tablespaces = $oracle_data['tablespaces'] ?? [];
foreach ($tablespaces as $ts) {
    $ts_name = strtolower($ts['name'] ?? 'unknown');
    $category = 'ts_' . $ts_name;
    $fields = [
        'pct_used' => $ts['pct_used'] ?? 0,
    ];
    $rrd_def = RrdDefinition::make()
        ->addDataset('pct_used', 'GAUGE', 0, 100);
    $rrd_name = ['app', $name, $app->app_id, $category];
    $metrics[$category] = $fields;
    $tags = ['name' => $name, 'app_id' => $app->app_id, 'rrd_def' => $rrd_def, 'rrd_name' => $rrd_name];
    app('Datastore')->put($device, 'app', $tags, $fields);
}

update_application($app, 'OK', $metrics);
