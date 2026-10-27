<?php
/**
 * IOC「設備連線行為」3D 視圖的資料匯出（唯讀，只給 VM 管理員在終端機手動執行；不是網頁端點，不進 install.sh）。
 * 輸出 JSON 到 stdout，用 tools/behavior-viz/behavior-3d.html 的「載入 JSON」在瀏覽器本機打開（不上傳）。
 *
 *   sudo -u librenms php /opt/oracle-mon-librenms/tools/export-behavior-viz.php > behavior-viz.json
 *   選項：--hours 24（1–168） --assets 21,29（只匯出這些主機） --top 8（每台最多幾個目的地） --max-assets 12 --no-mask --help
 *
 * 預設遮罩（位址末段 x、目的地併成 /24、主機名改「主機 A／B」）；--no-mask 才輸出真實名稱與位址，請只在內部使用。
 * 遮罩的代號對照（主機 A ＝ 哪一台）只印在終端機（stderr），不在 JSON 裡。需要先跑過 update.sh（匯出邏輯在 includes/ioc 的函式庫）。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

$args = getopt('', ['hours:', 'assets:', 'top:', 'max-assets:', 'no-mask', 'help']);
if ($args === false || isset($args['help'])) {
    fwrite(STDERR, "用法：php export-behavior-viz.php [--hours 24] [--assets 21,29] [--top 8] [--max-assets 12] [--no-mask] > behavior-viz.json\n");
    exit($args === false ? 2 : 0);
}
$int = static function (string $key) use ($args): ?int {
    if (!isset($args[$key])) { return null; }
    if (!is_string($args[$key]) || !preg_match('/\A\d{1,9}\z/', $args[$key])) { fwrite(STDERR, "--$key 要是整數\n"); exit(2); }
    return (int) $args[$key];
};
$opts = ['mask' => !isset($args['no-mask'])];
foreach (['hours' => 'hours', 'top' => 'top', 'max-assets' => 'max_assets'] as $flag => $key) {
    $v = $int($flag);
    if ($v !== null) { $opts[$key] = $v; }
}
if (isset($args['assets'])) {
    if (!is_string($args['assets']) || !preg_match('/\A\d{1,9}(,\d{1,9})*\z/', $args['assets'])) { fwrite(STDERR, "--assets 要是用逗號分隔的主機編號，例如 21,29\n"); exit(2); }
    $opts['assets'] = array_map('intval', explode(',', $args['assets']));
}

$librenms = getenv('LIBRENMS_DIR') ?: '/opt/librenms';
chdir($librenms);
require "$librenms/vendor/autoload.php";
$app = require "$librenms/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require "$librenms/includes/ioc/bootstrap.php";
if (!class_exists('Ioc\BehaviorVizExport')) { fwrite(STDERR, "找不到匯出函式庫：請先執行 update.sh 部署最新版\n"); exit(1); }

try {
    $export = new Ioc\BehaviorVizExport(Illuminate\Support\Facades\DB::connection()->getPdo());
    $data = $export->export($opts, Ioc\Access::HANDLER);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Ioc\ValidationError $e) {
    fwrite(STDERR, '參數不正確：' . implode('；', $e->errors()) . "\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, '匯出失敗：' . get_class($e) . "\n");
    exit(1);
}
fwrite(STDERR, sprintf("已匯出 %d 台主機、%d 小時（%s）。\n", count($data['assets']), $data['hours'], $data['masked'] ? '已遮罩' : '未遮罩：含真實名稱與位址，請勿外傳'));
foreach ($export->mapping() as $m) { fwrite(STDERR, sprintf("  %s ＝ #%d %s\n", $m['label'], $m['id'], $m['name'])); }
