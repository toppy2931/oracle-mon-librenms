<?php

namespace App\Http\Controllers\Widgets;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OracleStatusController extends WidgetController
{
    protected string $name = 'oracle-status';
    protected $defaults = [
        'title'           => 'Oracle DG + MV Status',
        'refresh'         => 60,
        'stale_threshold' => 60,
    ];

    public function getView(Request $request): View|string
    {
        $settings = $this->getSettings();

        $dg_apps = Application::with('metrics')
            ->where('app_type', 'oracle-dg')
            ->get();

        $mv_apps = Application::with('metrics')
            ->where('app_type', 'oracle-mv')
            ->get();

        $dg_data = $dg_apps->map(function ($app) {
            $m = $app->metrics->pluck('value', 'metric');

            return [
                'device_id'      => $app->device_id,
                'hostname'       => $app->device->hostname ?? 'unknown',
                'can_connect'    => (int) ($m['can_connect'] ?? 0),
                'is_primary'     => (int) ($m['is_primary'] ?? -1),
                'db_open'        => (int) ($m['db_open'] ?? 0),
                'mrp_running'    => (int) ($m['mrp_running'] ?? -1),
                'rfs_connected'  => (int) ($m['rfs_connected'] ?? -1),
                'lag_seconds'    => (int) ($m['lag_seconds'] ?? 0),
                'apply_lag_seqs' => (int) ($m['apply_lag_seqs'] ?? 0),
                'dest_ok'        => (int) ($m['dest_ok'] ?? -1),
                'dest_has_error' => (int) ($m['dest_has_error'] ?? -1),
            ];
        });

        $mv_data = $mv_apps->map(function ($app) use ($settings) {
            $m = $app->metrics->pluck('value', 'metric');

            $mv_list = [];
            foreach ($m as $key => $value) {
                if (preg_match('/^(.+)_age_minutes$/', $key, $matches)) {
                    $mv_name = $matches[1];
                    $mv_list[$mv_name] = [
                        'age_minutes' => (int) $value,
                        'is_stale'    => (int) ($m["{$mv_name}_is_stale"] ?? 1),
                        'refresh_ok'  => (int) ($m["{$mv_name}_refresh_ok"] ?? 0),
                    ];
                }
            }

            return [
                'device_id'       => $app->device_id,
                'hostname'        => $app->device->hostname ?? 'unknown',
                'can_connect'     => (int) ($m['can_connect'] ?? 0),
                'mv_total'        => (int) ($m['mv_total'] ?? 0),
                'mv_stale_count'  => (int) ($m['mv_stale_count'] ?? 0),
                'mv_failed_count' => (int) ($m['mv_failed_count'] ?? 0),
                'mv_list'         => $mv_list,
                'threshold'       => (int) $settings['stale_threshold'],
            ];
        });

        return view('widgets.oracle-status', [
            'dg_data' => $dg_data,
            'mv_data' => $mv_data,
        ] + $settings);
    }

    public function getSettingsView(Request $request): View
    {
        return view('widgets.settings.oracle-status', $this->getSettings(true));
    }
}
