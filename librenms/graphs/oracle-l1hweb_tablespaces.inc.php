<?php

require 'includes/html/graphs/common.inc.php';

$scale_min = 0;
$scale_max = 100;
$nototal = 1;
$unit_text = 'Percent';
$unitlen = 15;
$bigdescrlen = 20;
$smalldescrlen = 15;
$colours = 'mixed';

$rrd_list = [];
$i = 0;

$rrd_dir = \LibreNMS\Config::get('rrd_dir', '/opt/librenms/rrd') . '/' . $device['hostname'];
$glob_pattern = $rrd_dir . '/app-oracle-l1hweb-' . $app->app_id . '-ts_*.rrd';

foreach (glob($glob_pattern) as $rrd_file) {
    if (preg_match('/ts_(.+)\.rrd$/', basename($rrd_file), $matches)) {
        $rrd_list[$i]['filename'] = $rrd_file;
        $rrd_list[$i]['descr'] = strtoupper($matches[1]);
        $rrd_list[$i]['ds'] = 'pct_used';
        $i++;
    }
}

usort($rrd_list, function ($a, $b) {
    return strcmp($a['descr'], $b['descr']);
});

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
