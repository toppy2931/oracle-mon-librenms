<?php

namespace App\Api\Controllers;

use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OracleDgMvController
{
    public function status(Request $request): JsonResponse
    {
        $dg_apps = Application::with(['metrics', 'device'])
            ->where('app_type', 'oracle-dg')
            ->get();

        $mv_apps = Application::with(['metrics', 'device'])
            ->where('app_type', 'oracle-mv')
            ->get();

        $dataguard = $dg_apps->map(function ($app) {
            $m = $app->metrics->pluck('value', 'metric');

            return [
                'device_id'      => $app->device_id,
                'hostname'       => $app->device->hostname ?? null,
                'app_id'         => $app->app_id,
                'app_state'      => $app->app_state,
                'can_connect'    => (int) ($m['can_connect'] ?? 0),
                'is_primary'     => (int) ($m['is_primary'] ?? -1),
                'db_open'        => (int) ($m['db_open'] ?? 0),
                'mrp_running'    => (int) ($m['mrp_running'] ?? -1),
                'rfs_connected'  => (int) ($m['rfs_connected'] ?? -1),
                'lag_seconds'    => (int) ($m['lag_seconds'] ?? 0),
                'apply_lag_seqs' => (int) ($m['apply_lag_seqs'] ?? 0),
                'dest_ok'        => (int) ($m['dest_ok'] ?? -1),
                'dest_has_error' => (int) ($m['dest_has_error'] ?? -1),
                'protection_mode'=> (int) ($m['protection_mode'] ?? 2),
            ];
        })->values()->all();

        $materialized_views = $mv_apps->map(function ($app) {
            $m = $app->metrics->pluck('value', 'metric');

            $mv_list = [];
            foreach ($m as $key => $value) {
                if (preg_match('/^(.+)_age_minutes$/', $key, $matches)) {
                    $mv_name = $matches[1];
                    $mv_list[] = [
                        'name'        => $mv_name,
                        'age_minutes' => (int) $value,
                        'is_stale'    => (int) ($m["{$mv_name}_is_stale"] ?? 1),
                        'refresh_ok'  => (int) ($m["{$mv_name}_refresh_ok"] ?? 0),
                    ];
                }
            }

            return [
                'device_id'       => $app->device_id,
                'hostname'        => $app->device->hostname ?? null,
                'app_id'          => $app->app_id,
                'app_state'       => $app->app_state,
                'can_connect'     => (int) ($m['can_connect'] ?? 0),
                'mv_total_count'  => (int) ($m['mv_total'] ?? 0),
                'mv_stale_count'  => (int) ($m['mv_stale_count'] ?? 0),
                'mv_failed_count' => (int) ($m['mv_failed_count'] ?? 0),
                'snapshots'       => $mv_list,
            ];
        })->values()->all();

        return response()->json([
            'status'       => 'ok',
            'dataguard'    => $dataguard,
            'materialized_views' => $materialized_views,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
