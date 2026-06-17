<?php

$name      = 'oracle-l1hweb';
$category  = 'lib_cache';
$scale_min = 0;
$scale_max = 100;
$colours   = 'mixed';
$unit_text = '%';
$unitlen   = 5;
$bigdescrlen   = 22;
$smalldescrlen = 22;
$dostack   = 0;
$printtotal = 0;
$addarea   = 1;
$transparency = 15;

$rrd_filename = Rrd::name($device['hostname'], ['app', $name, $app->app_id, $category]);

$rrd_list = [
    [
        'filename' => $rrd_filename,
        'descr'    => 'Library Cache Hit %',
        'ds'       => 'hit_pct',
        'colour'   => '00AA44',
    ],
];

require 'includes/html/graphs/generic_v3_multiline.inc.php';
