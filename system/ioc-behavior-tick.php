<?php
/** IOC 裝置行為採集與告警，供 systemd 以 librenms 身分每分鐘執行。 */
declare(strict_types=1);

$librenms = getenv('LIBRENMS_DIR') ?: '/opt/librenms';
chdir($librenms);
require "$librenms/vendor/autoload.php";
$app = require "$librenms/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require "$librenms/includes/ioc/bootstrap.php";

$db = Illuminate\Support\Facades\DB::connection()->getPdo();
$settings = new Ioc\SettingsService(new Ioc\PdoSettingsRepo($db));
$devices = [];
try {
    $nets = $settings->internalNets(Ioc\Access::HANDLER);
    $sites = $settings->sites(Ioc\Access::HANDLER);
    foreach (Illuminate\Support\Facades\DB::table('devices')->where('disabled', 0)->where('ignore', 0)
        ->get(['device_id', 'hostname', 'sysName', 'display', 'overwrite_ip', 'ip']) as $row) {
        $ip = Ioc\Fleet::deviceIp($row->overwrite_ip ?? null, $row->ip ?? null, $row->hostname ?? null);
        $devices[] = ['id' => (int) $row->device_id,
            'name' => (string) ($row->display ?: ($row->sysName ?: $row->hostname)),
            'ip' => $ip, 'site' => $ip !== null ? $sites->siteOf($ip) : null];
    }
    try { $logs = Ioc\GraylogClient::fromConf('/etc/oracle-mon/graylog-reader.conf'); }
    catch (Ioc\GraylogUnavailable $e) { $logs = new Ioc\UnavailableLogSource('Graylog 來源尚未就緒'); }
    $collector = new Ioc\BehaviorCollector($db, $logs);
    $collector->tick($devices, $nets);
    $collector->repairOpenCases();
} catch (Throwable $e) {
    error_log('IOC behavior collection failed: ' . get_class($e));
    try {
        $db->prepare("INSERT INTO ioc_behavior_sources (source_key,kind,scope_label,status,coverage,checked_at,reason)
            VALUES ('fortigate_graylog','fortigate','FortiGate → Graylog','error','僅經過防火牆的對外流量',UTC_TIMESTAMP(),'採集器設定或設備清單無法讀取')
            ON DUPLICATE KEY UPDATE status='error',checked_at=UTC_TIMESTAMP(),reason=VALUES(reason)")->execute();
    } catch (Throwable $ignored) { /* 資料庫本身不可用時，僅保留 systemd 日誌。 */ }
}
try { (new Ioc\BehaviorWebhook($db))->deliverDue(); }
catch (Throwable $e) { error_log('IOC behavior notification failed: ' . get_class($e)); }
