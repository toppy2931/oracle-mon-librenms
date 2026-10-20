<?php
/**
 * 建立 IOC 事件案的資料表（冪等：只有 CREATE TABLE IF NOT EXISTS，重複執行不會動既有資料）。
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

$tables = ['ioc_cases', 'ioc_case_history'];
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
