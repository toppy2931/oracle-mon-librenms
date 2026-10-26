<?php
/**
 * 建立 IOC 入口的資料表：事件案與設定（冪等：只有 CREATE TABLE IF NOT EXISTS，重複執行不會動既有資料）。
 *
 * 以 librenms 身分執行（install.sh／update.sh 會自動呼叫）：
 *   sudo -u librenms php system/ioc-db-setup.php
 *
 * 環境變數（測試用）：LIBRENMS_DIR（預設 /opt/librenms）、IOC_LIB_DIR（預設 $LIBRENMS_DIR/includes/ioc）
 */
$librenms = getenv('LIBRENMS_DIR') ?: '/opt/librenms';
$lib      = getenv('IOC_LIB_DIR') ?: "$librenms/includes/ioc";

chdir($librenms);
require "$librenms/vendor/autoload.php";
$app = require "$librenms/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require "$lib/bootstrap.php";

$pdo = Illuminate\Support\Facades\DB::connection()->getPdo();

$exists = static function (string $table) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
};

$tables = ['ioc_cases', 'ioc_case_history', 'ioc_settings', 'ioc_settings_history',
    'ioc_audit_catalogs', 'ioc_audit_controls', 'ioc_audit_cycles', 'ioc_audit_evidence',
    'ioc_audit_assessments', 'ioc_audit_assessment_history', 'ioc_audit_remediation',
    'ioc_audit_remediation_history', 'ioc_audit_events', 'ioc_audit_assets',
    'ioc_audit_packs', 'ioc_audit_mappings', 'ioc_audit_runs', 'ioc_audit_auto_evidence',
    'ioc_audit_auto_links', 'ioc_audit_proposal_evidence', 'ioc_audit_case_mappings', 'ioc_audit_case_attempts', 'ioc_audit_mapping_history',
    'ioc_audit_results', 'ioc_audit_proposals',
    'ioc_behavior_assets', 'ioc_behavior_addresses', 'ioc_behavior_links', 'ioc_behavior_windows', 'ioc_behavior_baselines', 'ioc_behavior_known',
    'ioc_behavior_alerts', 'ioc_behavior_deliveries', 'ioc_behavior_history', 'ioc_behavior_audit', 'ioc_behavior_sources', 'ioc_behavior_schedules'];
$before = array_map($exists, $tables);

foreach (Ioc\Schema::statements() as $sql) {
    $pdo->exec($sql);
}

$ok = true;
foreach ($tables as $i => $t) {
    $now = $exists($t);
    $ok  = $ok && $now;
    printf("%s：%s\n", $t, !$now ? '建立失敗' : ($before[$i] ? '已存在，略過' : '已建立'));
}
exit($ok ? 0 : 1);
