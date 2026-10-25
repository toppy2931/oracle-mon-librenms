<?php
/** Bounded audit scan dispatcher. Run as librenms from the systemd timer. */
declare(strict_types=1);

$librenms = getenv('LIBRENMS_DIR') ?: '/opt/librenms';
chdir($librenms);
require "$librenms/vendor/autoload.php";
$app = require "$librenms/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require "$librenms/includes/ioc/bootstrap.php";

$db = Illuminate\Support\Facades\DB::connection()->getPdo();
$service = new Ioc\AuditAutomationService($db, new Ioc\AuditSnapshotStore());
$devices = Illuminate\Support\Facades\DB::table('devices')->where('disabled', 0)->where('ignore', 0)
    ->get(['device_id', 'hostname', 'sysName', 'display', 'type', 'os', 'overwrite_ip', 'ip']);
$assets = [];
foreach ($devices as $device) {
    $asset = Ioc\AuditAssetDiscovery::fromLibreNms($device);
    if ($asset !== null) { $assets[] = $asset; }
}
$service->syncAssets($assets);
$enabled = $db->query('SELECT pack_key FROM ioc_audit_packs WHERE enabled=1')->fetchAll(PDO::FETCH_COLUMN);
// Scanners run under their own unprivileged accounts and publish only redacted
// JSON to read-only group directories. The importer never reads credentials.
$spools = [
    'network' => ['/var/lib/oracle-mon/ioc-audit-network-results', 'fortios_network_baseline'],
    'linux' => ['/var/lib/oracle-mon/ioc-audit-linux-results', 'linux_ssh_baseline'],
];
$assetRows = $db->query('SELECT id,kind,address,requested_at FROM ioc_audit_assets WHERE last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)')->fetchAll(PDO::FETCH_ASSOC);
$known = [];
foreach ($assetRows as $asset) { $known[$asset['kind'] . ':' . $asset['id']] = $asset; }
$processed = 0;
foreach ($spools as $kind => [$directory, $pack]) {
    if (!in_array($pack, $enabled, true) || !is_dir($directory) || is_link($directory)) { continue; }
    foreach (glob($directory . '/*.json') ?: [] as $path) {
        if ($processed >= 40) { break 2; }
        if (!preg_match('/\A[1-9][0-9]{0,9}\.json\z/D', basename($path)) || is_link($path)
            || !is_file($path) || filesize($path) > 65536) { continue; }
        $id = (int) basename($path, '.json');
        $asset = $known[$kind . ':' . $id] ?? null;
        if (!$asset) { continue; }
        $content = file_get_contents($path);
        if ($content === false || strlen($content) > 65536) { continue; }
        try {
            $record = json_decode($content, true, 12, JSON_THROW_ON_ERROR);
            if (!is_array($record) || ($record['asset_id'] ?? null) !== $id
                || ($record['kind'] ?? null) !== $kind || !is_int($record['captured_at'] ?? null)
                || abs(time() - $record['captured_at']) > 2 * 86400
                || ($record['address'] ?? null) !== $asset['address']) {
                continue;
            }
            $result = $record['result'] ?? null;
            if (!is_array($result)) { continue; }
            $revision = $kind === 'linux'
                ? gmdate('Y-m-d', $record['captured_at']) . ':' . hash('sha256', json_encode([$result['status'] ?? null, $result['checks'] ?? null, $result['error_code'] ?? null], JSON_THROW_ON_ERROR))
                : null;
            $service->recordScan($id, $result, $revision);
            $processed++;
        } catch (Throwable $e) {
            error_log('IOC audit result rejected for asset #' . $id . ' (' . $kind . ')');
        }
    }
}
$service->refreshCurrentProposals();
