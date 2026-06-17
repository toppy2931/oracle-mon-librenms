<?php

$name      = 'oracle-l1hweb';
$category  = 'redo';
$scale_min = 0;
$colours   = 'mixed';
$unit_text = 'Per Second';
$unitlen   = 10;
$bigdescrlen   = 22;
$smalldescrlen = 22;
$dostack    = 0;
$printtotal = 0;
$addarea    = 1;
$transparency = 15;

$rrd_filename = Rrd::name($device['hostname'], ['app', $name, $app->app_id, $category]);

$rrd_list = [
    [
        'filename' => $rrd_filename,
        'descr'    => 'Redo Size (bytes/s)',
        'ds'       => 'redo_size',
        'colour'   => '3366CC',
    ],
    [
        'filename' => $rrd_filename,
        'descr'    => 'Space Requests/s',
        'ds'       => 'redo_space_req',
        'colour'   => 'FF6600',
    ],
];

require 'includes/html/graphs/generic_v3_multiline.inc.php';
