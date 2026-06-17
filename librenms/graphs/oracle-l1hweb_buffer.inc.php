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

$rrd_filename = Rrd::name($device['hostname'], ['app', 'oracle-l1hweb', $app->app_id, 'performance']);

$array = [
    'buffer_hit_pct' => 'Buffer Cache Hit %',
];

$rrd_list = [];
$i = 0;
foreach ($array as $ds => $descr) {
    $rrd_list[$i]['filename'] = $rrd_filename;
    $rrd_list[$i]['descr'] = $descr;
    $rrd_list[$i]['ds'] = $ds;
    $i++;
}

require 'includes/html/graphs/generic_multi_line_exact_numbers.inc.php';
