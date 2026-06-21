<div class="oracle-status-widget">

    {{-- DataGuard section --}}
    <div class="oracle-section">
        <h5 class="widget-section-title">
            <span class="glyphicon glyphicon-hdd"></span> DataGuard
        </h5>

        @forelse ($dg_data as $dg)
            <div class="oracle-dg-instance" style="margin-bottom:8px;">
                <small class="text-muted">{{ $dg['hostname'] }}</small>
                <div style="margin-top:4px;">

                    @if ($dg['can_connect'] == 0)
                        <span class="label label-danger">OFFLINE</span>
                    @else
                        {{-- Role --}}
                        <span class="label {{ $dg['is_primary'] == 1 ? 'label-primary' : 'label-info' }}">
                            {{ $dg['is_primary'] == 1 ? 'PRIMARY' : 'STANDBY' }}
                        </span>

                        {{-- DB Open --}}
                        <span class="label {{ $dg['db_open'] == 1 ? 'label-success' : 'label-warning' }}">
                            {{ $dg['db_open'] == 1 ? 'OPEN' : 'MOUNTED' }}
                        </span>

                        {{-- Lag --}}
                        @if ($dg['is_primary'] == 0)
                            <span class="label {{ $dg['lag_seconds'] > 300 ? 'label-danger' : ($dg['lag_seconds'] > 60 ? 'label-warning' : 'label-success') }}">
                                Lag: {{ $dg['lag_seconds'] }}s
                            </span>
                            <span class="label {{ $dg['mrp_running'] == 1 ? 'label-success' : ($dg['mrp_running'] == 0 ? 'label-danger' : 'label-default') }}">
                                MRP: {{ $dg['mrp_running'] == 1 ? 'OK' : ($dg['mrp_running'] == 0 ? 'STOPPED' : 'N/A') }}
                            </span>
                        @endif

                        @if ($dg['is_primary'] == 1)
                            <span class="label {{ $dg['dest_has_error'] == 1 ? 'label-danger' : 'label-success' }}">
                                DEST: {{ $dg['dest_has_error'] == 1 ? 'ERROR' : 'OK' }}
                            </span>
                        @endif
                    @endif
                </div>
            </div>
        @empty
            <p class="text-muted"><small>未發現 oracle-dg 應用程式</small></p>
        @endforelse
    </div>

    <hr style="margin:6px 0;">

    {{-- Materialized View section --}}
    <div class="oracle-mv-section">
        <h5 class="widget-section-title">
            <span class="glyphicon glyphicon-list-alt"></span> Materialized Views
        </h5>

        @forelse ($mv_data as $mv_app)
            <div class="oracle-mv-instance">
                <small class="text-muted">{{ $mv_app['hostname'] }}</small>

                @if ($mv_app['can_connect'] == 0)
                    <div><span class="label label-danger">DB OFFLINE</span></div>
                @else
                    {{-- Summary badges --}}
                    <div style="margin:4px 0;">
                        <span class="label label-default">Total: {{ $mv_app['mv_total'] }}</span>
                        @if ($mv_app['mv_stale_count'] > 0)
                            <span class="label label-warning">Stale: {{ $mv_app['mv_stale_count'] }}</span>
                        @endif
                        @if ($mv_app['mv_failed_count'] > 0)
                            <span class="label label-danger">Failed: {{ $mv_app['mv_failed_count'] }}</span>
                        @endif
                        @if ($mv_app['mv_stale_count'] == 0 && $mv_app['mv_failed_count'] == 0 && $mv_app['mv_total'] > 0)
                            <span class="label label-success">All Fresh</span>
                        @endif
                    </div>

                    {{-- Per-MV detail table --}}
                    @if (!empty($mv_app['mv_list']))
                        <table class="table table-condensed table-bordered oracle-mv-table" style="font-size:11px;margin-bottom:4px;">
                            <thead>
                                <tr>
                                    <th>Materialized View</th>
                                    <th>Age (min)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($mv_app['mv_list'] as $mv_name => $mv)
                                    <tr class="{{ $mv['is_stale'] ? 'danger' : ($mv['age_minutes'] > $mv_app['threshold'] ? 'warning' : 'success') }}">
                                        <td>{{ $mv_name }}</td>
                                        <td>{{ $mv['age_minutes'] }}</td>
                                        <td>{{ $mv['is_stale'] ? ($mv['refresh_ok'] ? 'STALE' : 'UNUSABLE') : 'FRESH' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </div>
        @empty
            <p class="text-muted"><small>未發現 oracle-mv 應用程式</small></p>
        @endforelse
    </div>

</div>
