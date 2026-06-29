<?php

use App\Models\Application;
use LibreNMS\Util\Url;

$graph_array['height'] = '100';
$graph_array['width']  = '220';
$graph_array['to']     = \App\Facades\LibrenmsConfig::get('time.now');
$graph_array['from']   = \App\Facades\LibrenmsConfig::get('time.day');
$graph_array_zoom = $graph_array;
$graph_array_zoom['height'] = '150';
$graph_array_zoom['width']  = '400';
$graph_array['legend'] = 'no';

$ora_graphs = [
    'sessions', 'buffer', 'tablespaces', 'health',
    'dataguard', 'mview',
    'io', 'sql', 'redo', 'lib_cache',
    'sga', 'sga_memory', 'waits',
];

$apps = Application::query()->hasAccess(Auth::user())
    ->where('app_type', $vars['app'])->with('device')->get();

foreach ($apps as $app) {
    echo '<div class="panel panel-default">
        <div class="panel-heading">
        <h3 class="panel-title">' . e($app->displayName()) . '
        <div class="pull-right"><small class="muted">採集自 ' . e($app->device->displayName()) . '</small></div>
        </h3>
        </div>
        <div class="panel-body">
        <div class="row">';

    foreach ($ora_graphs as $g) {
        $graph_array['type'] = 'application_oracle-l1hweb_' . $g;
        $graph_array['id']   = $app->app_id;
        $graph_array_zoom['type'] = 'application_oracle-l1hweb_' . $g;
        $graph_array_zoom['id']   = $app->app_id;
        $link = Url::generate(['page' => 'device', 'device' => $app->device_id, 'tab' => 'apps', 'app' => $app->app_type]);
        echo '<div class="pull-left">';
        echo Url::overlibLink($link, Url::lazyGraphTag($graph_array), Url::graphTag($graph_array_zoom));
        echo '</div>';
    }

    echo '</div></div></div>';
}
