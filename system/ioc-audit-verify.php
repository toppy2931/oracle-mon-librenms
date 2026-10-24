<?php
/** VM 還原後以 librenms 身分執行；只印結果，不輸出證據內容。 */
declare(strict_types=1);

$librenms = getenv('LIBRENMS_DIR') ?: '/opt/librenms';
$library = getenv('IOC_LIB_DIR') ?: "$librenms/includes/ioc";
chdir($librenms);
require "$librenms/vendor/autoload.php";
$app = require "$librenms/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require "$library/bootstrap.php";

$db = Illuminate\Support\Facades\DB::connection()->getPdo();
$rows = $db->query("SELECT id,snapshot_name,sha256,bytes FROM ioc_audit_evidence WHERE kind='case_snapshot' ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);
$store = new Ioc\AuditSnapshotStore();
$failed = 0;
foreach ($rows as $row) {
    try {
        $store->get((string) $row['snapshot_name'], (string) $row['sha256'], (int) $row['bytes']);
    } catch (Throwable $e) {
        $failed++;
        fprintf(STDERR, "證據快照 #%d 驗證失敗\n", (int) $row['id']);
    }
}
printf("證據快照驗證：%d 筆，失敗 %d 筆\n", count($rows), $failed);
exit($failed === 0 ? 0 : 1);
