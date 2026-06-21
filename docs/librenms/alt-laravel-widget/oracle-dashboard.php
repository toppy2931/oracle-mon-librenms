<?php
/**
 * oracle-dashboard.php — Oracle 監控戰情室
 * URL: http://localhost/oracle-dashboard
 * 自動每 30 秒 AJAX 更新，不需要重整頁面
 */

// API data fetch (server-side initial load)
function fetchApiJson(string $path): array
{
    // Read API token from config or generate a service token
    $token = config('app.oracle_dashboard_token', '');
    $ch = curl_init("http://localhost/api/v0/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["X-Auth-Token: {$token}"],
        CURLOPT_TIMEOUT        => 5,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body ?: '{}', true) ?: [];
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Oracle 監控戰情室 — Monitor-VM</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<style>
  body { background: #0d1117; color: #c9d1d9; font-family: 'Segoe UI', sans-serif; }
  .dashboard-header { background: #161b22; border-bottom: 1px solid #30363d; padding: 10px 20px; }
  .dashboard-header h4 { margin: 0; color: #58a6ff; font-weight: 600; letter-spacing: 1px; }
  .dashboard-header .sub { color: #8b949e; font-size: 12px; }
  .panel { background: #161b22; border: 1px solid #30363d; border-radius: 6px; margin-bottom: 16px; }
  .panel-heading { padding: 8px 14px; border-bottom: 1px solid #30363d; font-size: 13px; font-weight: 600; }
  .panel-body { padding: 12px 14px; }
  .badge-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 5px; }
  .dot-green  { background: #3fb950; box-shadow: 0 0 6px #3fb950; }
  .dot-red    { background: #f85149; box-shadow: 0 0 6px #f85149; }
  .dot-orange { background: #d29922; box-shadow: 0 0 6px #d29922; }
  .dot-gray   { background: #6e7681; }
  .status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; margin: 2px; }
  .badge-primary-role { background: #1f6feb; color: #fff; }
  .badge-standby-role { background: #1b4332; color: #3fb950; border: 1px solid #3fb950; }
  .badge-ok    { background: #1b4332; color: #3fb950; }
  .badge-warn  { background: #3a2700; color: #d29922; }
  .badge-error { background: #3d1212; color: #f85149; }
  .badge-na    { background: #21262d; color: #6e7681; }
  .mv-table { font-size: 11px; width: 100%; border-collapse: collapse; }
  .mv-table th { color: #8b949e; font-weight: 500; padding: 4px 8px; border-bottom: 1px solid #30363d; }
  .mv-table td { padding: 4px 8px; border-bottom: 1px solid #21262d; }
  .mv-table tr.fresh td { color: #c9d1d9; }
  .mv-table tr.stale td { color: #d29922; }
  .mv-table tr.failed td { color: #f85149; }
  .seq-num { font-family: monospace; font-size: 13px; color: #79c0ff; }
  .lag-ok  { color: #3fb950; }
  .lag-warn { color: #d29922; }
  .lag-crit { color: #f85149; }
  #last-update { color: #6e7681; font-size: 11px; }
  #alert-badge { display: none; background: #f85149; color: #fff; border-radius: 12px; padding: 1px 8px; font-size: 11px; margin-left: 8px; }
  .alert-row { background: #1c1c1c; border-left: 3px solid #f85149; padding: 6px 10px; margin-bottom: 4px; font-size: 12px; border-radius: 2px; }
  .alert-row.warning { border-left-color: #d29922; }
  .counter-box { text-align: center; padding: 8px 4px; }
  .counter-box .num { font-size: 24px; font-weight: 700; }
  .counter-box .lbl { font-size: 10px; color: #8b949e; }
</style>
</head>
<body>

<!-- Header -->
<div class="dashboard-header d-flex align-items-center justify-content-between">
  <div>
    <h4>Oracle 監控戰情室 <small id="alert-badge">!</small></h4>
    <div class="sub">monitor-vm · LibreNMS · <span id="last-update">載入中...</span></div>
  </div>
  <div class="d-flex align-items-center" style="gap:16px;">
    <div class="counter-box">
      <div class="num" id="cnt-total" style="color:#58a6ff">—</div>
      <div class="lbl">MV 總數</div>
    </div>
    <div class="counter-box">
      <div class="num" id="cnt-fresh" style="color:#3fb950">—</div>
      <div class="lbl">FRESH</div>
    </div>
    <div class="counter-box">
      <div class="num" id="cnt-stale" style="color:#d29922">—</div>
      <div class="lbl">STALE</div>
    </div>
    <div class="counter-box">
      <div class="num" id="cnt-failed" style="color:#f85149">—</div>
      <div class="lbl">FAILED</div>
    </div>
  </div>
</div>

<div class="container-fluid" style="padding:16px;">
  <div class="row">

    <!-- DataGuard Panel -->
    <div class="col-lg-5">
      <div class="panel">
        <div class="panel-heading">
          <span class="badge-dot" id="dg-dot"></span> DataGuard 同步狀態
        </div>
        <div class="panel-body" id="dg-panel">
          <span style="color:#6e7681">載入中...</span>
        </div>
      </div>
    </div>

    <!-- MV Panel -->
    <div class="col-lg-7">
      <div class="panel">
        <div class="panel-heading">
          <span class="badge-dot" id="mv-dot"></span> Materialized View (快照) 狀態
        </div>
        <div class="panel-body" id="mv-panel">
          <span style="color:#6e7681">載入中...</span>
        </div>
      </div>
    </div>

  </div>

  <!-- Alerts Panel -->
  <div class="row">
    <div class="col-12">
      <div class="panel">
        <div class="panel-heading">⚠ 告警記錄</div>
        <div class="panel-body" id="alerts-panel">
          <span style="color:#6e7681">載入中...</span>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const API_BASE = '/api/v0';
const TOKEN    = '<?php echo htmlspecialchars(getenv('LIBRENMS_API_TOKEN') ?: '', ENT_QUOTES); ?>';

async function apiFetch(path) {
  const r = await fetch(API_BASE + path, { headers: { 'X-Auth-Token': TOKEN } });
  return r.ok ? r.json() : null;
}

function dot(color) {
  return `<span class="badge-dot dot-${color}"></span>`;
}

function statusBadge(text, cls) {
  return `<span class="status-badge ${cls}">${text}</span>`;
}

function renderDG(data) {
  const dg = data?.dataguard ?? [];
  if (!dg.length) return '<span style="color:#6e7681">未發現 oracle-dg 應用程式</span>';

  return dg.map(d => {
    if (!d.can_connect) {
      document.getElementById('dg-dot').className = 'badge-dot dot-red';
      return `${dot('red')} <strong>${d.hostname ?? 'Oracle'}</strong> ${statusBadge('OFFLINE','badge-error')}`;
    }
    const role = d.is_primary === 1 ? statusBadge('PRIMARY','badge-primary-role') : statusBadge('STANDBY','badge-standby-role');
    const open = d.db_open ? statusBadge('OPEN','badge-ok') : statusBadge('MOUNTED','badge-warn');
    let lagHtml = '';
    if (d.is_primary === 0) {
      const lagClass = d.lag_seconds > 300 ? 'lag-crit' : d.lag_seconds > 60 ? 'lag-warn' : 'lag-ok';
      const mrp = d.mrp_running === 1 ? statusBadge('MRP OK','badge-ok') : d.mrp_running === 0 ? statusBadge('MRP STOPPED','badge-error') : statusBadge('MRP N/A','badge-na');
      lagHtml = `<br><small>Lag: <span class="${lagClass}">${d.lag_seconds}s</span> &nbsp; ${mrp}</small>`;
    } else {
      const dest = d.dest_has_error === 1 ? statusBadge('DEST ERROR','badge-error') : statusBadge('DEST OK','badge-ok');
      lagHtml = `<br><small>${dest}</small>`;
    }
    const seqLabel = d.is_primary === 1 ? 'Current Seq' : 'Applied Seq';
    const seqVal   = d.is_primary === 1 ? d.current_seq : d.applied_seq;

    const hasIssue = (!d.db_open) || (d.is_primary === 0 && d.mrp_running === 0) || (d.is_primary === 1 && d.dest_has_error === 1) || d.lag_seconds > 300;
    document.getElementById('dg-dot').className = 'badge-dot ' + (hasIssue ? 'dot-orange' : 'dot-green');

    return `<div style="margin-bottom:10px;">
      ${dot(hasIssue ? 'orange' : 'green')} <strong>${d.hostname ?? 'Oracle'}</strong>
      ${role} ${open} ${lagHtml}
      <br><small style="color:#8b949e">${seqLabel}: <span class="seq-num">${seqVal ?? '—'}</span></small>
    </div>`;
  }).join('');
}

function renderMV(data) {
  const mvApps = data?.materialized_views ?? [];
  if (!mvApps.length) return '<span style="color:#6e7681">未發現 oracle-mv 應用程式</span>';

  let totalAll = 0, staleAll = 0, failedAll = 0;
  const html = mvApps.map(app => {
    if (!app.can_connect) {
      document.getElementById('mv-dot').className = 'badge-dot dot-red';
      return `${dot('red')} ${app.hostname ?? 'Oracle'} <span class="status-badge badge-error">DB OFFLINE</span>`;
    }
    const snapshots = app.snapshots ?? [];
    totalAll  += app.mv_total_count  ?? 0;
    staleAll  += app.mv_stale_count  ?? 0;
    failedAll += app.mv_failed_count ?? 0;

    const rows = snapshots.map(mv => {
      const rowClass = mv.refresh_ok === 0 ? 'failed' : mv.is_stale ? 'stale' : 'fresh';
      const statusText = mv.refresh_ok === 0 ? 'UNUSABLE' : mv.is_stale ? 'STALE' : 'FRESH';
      return `<tr class="${rowClass}">
        <td>${mv.name}</td>
        <td>${mv.age_minutes} min</td>
        <td>${mv.type ?? '—'}</td>
        <td>${statusText}</td>
      </tr>`;
    }).join('');

    return `<table class="mv-table">
      <thead><tr><th>快照名稱</th><th>距上次刷新</th><th>類型</th><th>狀態</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
  }).join('');

  // Update counters
  document.getElementById('cnt-total').textContent  = totalAll;
  document.getElementById('cnt-fresh').textContent  = totalAll - staleAll;
  document.getElementById('cnt-stale').textContent  = staleAll - failedAll;
  document.getElementById('cnt-failed').textContent = failedAll;

  const hasIssue = staleAll > 0 || failedAll > 0;
  document.getElementById('mv-dot').className = 'badge-dot ' + (hasIssue ? (failedAll > 0 ? 'dot-red' : 'dot-orange') : 'dot-green');

  return html;
}

function renderAlerts(data) {
  const alerts = data?.alerts ?? [];
  const active = alerts.filter(a => a.state === 1);
  const badge = document.getElementById('alert-badge');
  if (active.length) {
    badge.style.display = 'inline';
    badge.textContent = active.length;
  } else {
    badge.style.display = 'none';
  }

  if (!active.length) return '<span style="color:#3fb950;font-size:12px;">✓ 無告警</span>';
  return active.map(a => {
    const sev = a.severity === 'critical' ? '' : ' warning';
    return `<div class="alert-row${sev}">
      <strong>${a.hostname ?? ''}</strong> · ${a.rule ?? ''} · <span style="color:#8b949e">${a.alert_date ?? ''}</span>
    </div>`;
  }).join('');
}

async function refresh() {
  const [oracle, alertData] = await Promise.all([
    apiFetch('/oracle-dg-mv-status'),
    apiFetch('/alerts?state=1&count=20'),
  ]);

  if (oracle) {
    document.getElementById('dg-panel').innerHTML = renderDG(oracle);
    document.getElementById('mv-panel').innerHTML = renderMV(oracle);
  }
  if (alertData) {
    document.getElementById('alerts-panel').innerHTML = renderAlerts(alertData);
  }
  document.getElementById('last-update').textContent = '更新：' + new Date().toLocaleTimeString('zh-TW');
}

refresh();
setInterval(refresh, 30000);
</script>
</body>
</html>
