<?php
/**
 * Idempotent installer for Oracle (L1HWEB) alert rules.
 *
 * - Fixes legacy rules whose metric names no longer match application_metrics
 *   (instance_up -> performance_instance_up, archivelog_mode -> health_archivelog).
 * - Adds Data Guard + Materialized View alert rules.
 *
 * Run as the librenms user:
 *   sudo -u librenms php install_alert_rules.php
 */
chdir('/opt/librenms');
require '/opt/librenms/vendor/autoload.php';
$app = require '/opt/librenms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

function build_query(string $metric, string $op_sql, $value): string
{
    return 'SELECT * FROM devices,applications,application_metrics WHERE '
        . '(devices.device_id = ? AND devices.device_id = applications.device_id '
        . 'AND applications.app_id = application_metrics.app_id) '
        . 'AND application_metrics.metric = "' . $metric . '" '
        . 'AND application_metrics.value ' . $op_sql . ' ' . $value;
}

function build_builder(string $metric, string $op_name, $value): string
{
    return json_encode([
        'condition' => 'AND',
        'rules' => [
            ['id' => 'application_metrics.metric', 'field' => 'application_metrics.metric',
             'type' => 'string', 'input' => 'text', 'operator' => 'equal', 'value' => $metric],
            ['id' => 'application_metrics.value', 'field' => 'application_metrics.value',
             'type' => 'string', 'input' => 'text', 'operator' => $op_name, 'value' => (string) $value],
        ],
        'valid' => true,
    ]);
}

// ---- 1) Fix legacy rules (OPT-IN ONLY) ----
// Existing rules #1/#2 reference metric names (instance_up / archivelog_mode) that no
// longer match application_metrics ({category}_{field}), so they never fire. We do NOT
// touch pre-existing rules unless explicitly asked: run with `--fix-legacy`.
$fix_legacy = in_array('--fix-legacy', $argv ?? [], true);
$fixes = [
    'instance_up'     => 'performance_instance_up',
    'archivelog_mode' => 'health_archivelog',
];
foreach ($fix_legacy ? DB::table('alert_rules')->get() : [] as $r) {
    foreach ($fixes as $old => $new) {
        if (strpos($r->query ?? '', '"' . $old . '"') !== false
            || strpos($r->builder ?? '', '"value":"' . $old . '"') !== false) {
            DB::table('alert_rules')->where('id', $r->id)->update([
                'query'   => str_replace('"' . $old . '"', '"' . $new . '"', $r->query ?? ''),
                'builder' => str_replace('"value":"' . $old . '"', '"value":"' . $new . '"', $r->builder ?? ''),
            ]);
            echo "FIXED legacy rule #{$r->id} ({$r->name}): {$old} -> {$new}" . PHP_EOL;
        }
    }
}

// ---- 2) New rules (idempotent by name) ----
// op: [op_name_for_builder, op_sql]
$ops = [
    'gt' => ['greater', '>'],
    'eq' => ['equal', '='],
];

$new_rules = [
    ['Oracle MView 刷新 Job 中斷', 'mview_jobs_broken', 'gt', 0, 'warning',
        'dba_jobs 有 broken=Y 的刷新 Job。請 DBA 檢查並重啟（dbms_job.broken / dbms_refresh.refresh）。'],
    ['Oracle MView 刷新 Job 失敗', 'mview_jobs_failed', 'gt', 0, 'warning',
        'dba_jobs 有 failures>0 的刷新 Job，下游報表資料可能過舊。'],
    ['Oracle MView 超過7天未刷新', 'mview_oldest_hours', 'gt', 168, 'warning',
        '最舊具體化檢視距今超過 168 小時（7 天）未刷新。'],
    ['Oracle DataGuard Archive Gap', 'dataguard_dg_gap', 'gt', 0, 'critical',
        'Standby 端偵測到歸檔日誌缺口（gap）未同步，Data Guard 落後。'],
    ['Oracle DataGuard Apply Lag 過大', 'dataguard_dg_apply_lag', 'gt', 15, 'warning',
        'Standby 套用延遲超過 15 分鐘。'],
];

$existing = DB::table('alert_rules')->pluck('name')->all();
foreach ($new_rules as [$name, $metric, $opkey, $value, $sev, $notes]) {
    if (in_array($name, $existing, true)) {
        echo "SKIP (exists): {$name}" . PHP_EOL;
        continue;
    }
    [$op_name, $op_sql] = $ops[$opkey];
    DB::table('alert_rules')->insert([
        'name'     => $name,
        'severity' => $sev,
        'disabled' => 0,
        'query'    => build_query($metric, $op_sql, $value),
        'builder'  => build_builder($metric, $op_name, $value),
        'extra'    => '{"options":{"override_query":false},"invert":false}',
        'invert_map' => 0,
        'notes'    => $notes,
    ]);
    echo "ADDED: {$name} ({$metric} {$op_sql} {$value}, {$sev})" . PHP_EOL;
}

echo "DONE." . PHP_EOL;
