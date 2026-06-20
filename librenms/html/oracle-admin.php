<?php
/**
 * Oracle Admin GUI — unified settings management for LibreNMS
 * URL: http://<monitor-vm>/oracle-admin.php
 */

use App\Facades\LibrenmsConfig;

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';

if (!Auth::check() || !Auth::user()->hasRole("admin")) {
    header('Location: /');
    exit;
}

$csrf_token = csrf_token();
$dbs = [];
$conf_dir = '/opt/oracle-mon/dbs';
if (is_dir($conf_dir)) {
    foreach (glob("$conf_dir/*.conf") as $f) {
        $d = parse_ini_file($f);
        if ($d && !empty($d['DB_ALIAS'])) {
            $dbs[$d['DB_ALIAS']] = $d;
        }
    }
}

// Detect current IP from base_url
$base_url = LibrenmsConfig::get('base_url', '');
$current_ip = preg_replace('#^https?://#', '', $base_url);
$current_ip = rtrim($current_ip, '/');
if (empty($current_ip)) {
    $current_ip = $_SERVER['SERVER_ADDR'] ?? '172.16.1.94';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
<title>系統設定管理 — LibreNMS</title>
<link href="/css/oracle-admin/bootstrap-5.3.2.min.css" rel="stylesheet">
<style>
body{background:#1a1a2e;color:#d0d0e0;font-size:14px}
.navbar-top{background:#0f3460;padding:8px 16px;display:flex;align-items:center;gap:16px;border-bottom:2px solid #e94560;margin-bottom:24px}
.navbar-top a{color:#aac4e8;text-decoration:none;font-size:13px}
.navbar-top a:hover{color:#fff}
h4{color:#e94560;font-size:16px;margin:0}
.card{background:#16213e;border:1px solid #0f3460;margin-bottom:20px}
.card-header{background:#0f3460;border-bottom:1px solid #e94560;padding:10px 16px}
.card-header h5{margin:0;color:#aac4e8;font-size:14px}
.form-control,.form-select{background:#0d1b2a;color:#d0d0e0;border-color:#1e4080;font-size:13px}
.form-control:focus,.form-select:focus{background:#0d1b2a;color:#d0d0e0;border-color:#e94560;box-shadow:none}
.form-label{font-size:12px;color:#8899bb;margin-bottom:4px}
.btn-primary{background:#e94560;border-color:#e94560}
.btn-primary:hover{background:#c73652;border-color:#c73652}
.result-box{background:#0d1b2a;border:1px solid #1e4080;border-radius:4px;padding:10px 12px;min-height:48px;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-all}
.ok{color:#4ade80}.err{color:#f87171}.info{color:#60b4f8}
.table{color:#d0d0e0;font-size:13px}
.table-dark{--bs-table-bg:#0d1b2a;--bs-table-border-color:#1e4080}
.badge-enabled{background:#166534;color:#4ade80;padding:2px 8px;border-radius:3px;font-size:11px}
.badge-disabled{background:#3f1111;color:#f87171;padding:2px 8px;border-radius:3px;font-size:11px}
.form-check-input:checked{background-color:#e94560;border-color:#e94560}
code{color:#60b4f8;background:transparent}
</style>
</head>
<body>

<div class="navbar-top">
    <a href="/">← LibreNMS</a>
    <h4>⚙ 系統設定統一管理</h4>
    <span class="ms-auto" style="font-size:12px;color:#556677">登入者：<?= htmlspecialchars(Auth::user()->username) ?></span>
</div>

<div class="container-fluid" style="max-width:1100px">

<!-- ═══ 區塊 A：Oracle 連線設定 ═══════════════════════════════════ -->
<div class="card">
  <div class="card-header d-flex justify-content-between">
    <h5>▌ 區塊 A — Oracle 資料庫連線設定</h5>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-5">
        <div class="mb-2">
          <label class="form-label">選擇 DB</label>
          <select class="form-select" id="dbSelect" onchange="loadConf()">
            <?php foreach ($dbs as $alias => $d): ?>
            <option value="<?= htmlspecialchars($alias) ?>"><?= htmlspecialchars($alias) ?> — <?= htmlspecialchars($d['DB_LABEL'] ?? '') ?></option>
            <?php endforeach; ?>
            <?php if (empty($dbs)): ?>
            <option value="">（尚無 DB）</option>
            <?php endif; ?>
          </select>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-8"><label class="form-label">Oracle 主機 IP</label><input type="text" class="form-control" id="aHost" placeholder="172.16.1.101"></div>
          <div class="col-4"><label class="form-label">Port</label><input type="number" class="form-control" id="aPort" placeholder="1521" min="1" max="65535"></div>
        </div>
        <div class="mb-2"><label class="form-label">SID / Service Name</label><input type="text" class="form-control" id="aSid" placeholder="L1HWEB"></div>
        <div class="mb-2"><label class="form-label">監控帳號</label><input type="text" class="form-control" id="aUser" placeholder="librenms"></div>
        <div class="mb-2">
          <label class="form-label">密碼（空白 = 不變更）</label>
          <div class="input-group">
            <input type="password" class="form-control" id="aPass">
            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="togglePwd('aPass',this)">顯示</button>
          </div>
        </div>
        <div class="d-flex gap-2 mt-3 align-items-center">
          <button class="btn btn-primary btn-sm" onclick="saveConf()">儲存設定</button>
          <button class="btn btn-outline-info btn-sm" onclick="testConn()">測試連線</button>
          <span class="text-muted" style="font-size:11px">測試使用上方表單當前值（未存檔也可測）；密碼留空時用既存密碼</span>
        </div>
      </div>
      <div class="col-md-7">
        <label class="form-label">測試 / 操作結果</label>
        <div class="result-box" id="aResult">—</div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ 區塊 C：多台 DB 管理 ══════════════════════════════════════ -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5>▌ 區塊 C — 多台資料庫主機管理</h5>
    <div class="d-flex gap-2">
      <button class="btn btn-success btn-sm" onclick="toggleAddForm()">＋ 新增 DB</button>
      <button class="btn btn-outline-info btn-sm" onclick="testAll()">全部測試</button>
    </div>
  </div>
  <div class="card-body">
    <!-- 新增表單 -->
    <div id="addForm" class="mb-3 p-3" style="background:#0d1b2a;border-radius:6px;display:none">
      <div class="row g-2 mb-2">
        <div class="col-2"><label class="form-label">別名 <span class="text-danger">*</span></label><input class="form-control" id="nAlias" placeholder="db2"></div>
        <div class="col-3"><label class="form-label">主機 IP <span class="text-danger">*</span></label><input class="form-control" id="nHost" placeholder="10.0.0.5"></div>
        <div class="col-1"><label class="form-label">Port</label><input class="form-control" id="nPort" value="1521"></div>
        <div class="col-2"><label class="form-label">SID <span class="text-danger">*</span></label><input class="form-control" id="nSid" placeholder="ORCL"></div>
        <div class="col-2"><label class="form-label">帳號</label><input class="form-control" id="nUser" placeholder="librenms"></div>
        <div class="col-2"><label class="form-label">密碼</label><input type="password" class="form-control" id="nPass"></div>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6"><label class="form-label">標籤（顯示名稱）</label><input class="form-control" id="nLabel" placeholder="說明文字"></div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-success btn-sm" onclick="addDb()">確認新增</button>
        <button class="btn btn-secondary btn-sm" onclick="toggleAddForm()">取消</button>
      </div>
      <div class="result-box mt-2" id="addResult" style="display:none"></div>
    </div>

    <!-- DB 清單 -->
    <div class="table-responsive">
      <table class="table table-dark table-hover table-sm" id="dbTable">
        <thead><tr>
          <th>別名</th><th>主機</th><th>Port</th><th>SID</th><th>標籤</th><th>狀態</th><th>測試結果</th><th>操作</th>
        </tr></thead>
        <tbody>
<?php foreach ($dbs as $alias => $d): ?>
          <tr id="row-<?= htmlspecialchars($alias) ?>">
            <td><code><?= htmlspecialchars($alias) ?></code></td>
            <td><?= htmlspecialchars($d['DB_HOST'] ?? '') ?></td>
            <td><?= htmlspecialchars($d['DB_PORT'] ?? '') ?></td>
            <td><?= htmlspecialchars($d['DB_SID'] ?? '') ?></td>
            <td><?= htmlspecialchars($d['DB_LABEL'] ?? '') ?></td>
            <td><?= ($d['DB_ENABLED'] ?? '0') === '1'
                ? '<span class="badge-enabled">啟用</span>'
                : '<span class="badge-disabled">停用</span>' ?></td>
            <td class="tr-cell">—</td>
            <td>
              <button class="btn btn-outline-warning btn-sm py-0 me-1" onclick="editDb('<?= htmlspecialchars($alias) ?>')">編輯</button>
              <button class="btn btn-outline-info btn-sm py-0 me-1" onclick="testOne('<?= htmlspecialchars($alias) ?>')">測試</button>
<?php if (($d['DB_ENABLED'] ?? '0') === '1'): ?>
              <button class="btn btn-outline-secondary btn-sm py-0 me-1" onclick="toggleDb('<?= htmlspecialchars($alias) ?>',0)">停用</button>
<?php else: ?>
              <button class="btn btn-outline-success btn-sm py-0 me-1" onclick="toggleDb('<?= htmlspecialchars($alias) ?>',1)">啟用</button>
<?php endif; ?>
              <button class="btn btn-outline-danger btn-sm py-0" onclick="delDb('<?= htmlspecialchars($alias) ?>')">刪除</button>
            </td>
          </tr>
<?php endforeach; ?>
<?php if (empty($dbs)): ?>
          <tr><td colspan="8" class="text-center text-muted py-3">尚未設定任何 DB，點擊「新增 DB」開始</td></tr>
<?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══ 區塊 B：monitor-vm IP 異動 ════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <h5>▌ 區塊 B — 監控主機 IP 異動設定</h5>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-5">
        <div class="mb-2"><label class="form-label">目前 IP（偵測自 base_url）</label>
          <input type="text" class="form-control" id="bCurrent" value="<?= htmlspecialchars($current_ip) ?>" readonly style="background:#0a1525;color:#8899bb">
        </div>
        <div class="mb-3"><label class="form-label">新 IP <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="bNew" placeholder="172.16.1.xxx">
        </div>
        <div class="mb-3">
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="chkBase" checked>
            <label class="form-check-label" for="chkBase">LibreNMS <code>base_url</code> → <code>lnms config:set</code></label>
          </div>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="chkEnv" checked>
            <label class="form-check-label" for="chkEnv">.env <code>APP_URL=http://&lt;新IP&gt;</code></label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="chkCache" checked>
            <label class="form-check-label" for="chkCache">清除 Laravel config cache → <code>artisan config:clear</code></label>
          </div>
        </div>
        <button class="btn btn-warning btn-sm" onclick="doIpUpdate()">套用 IP 變更</button>
        <button class="btn btn-info btn-sm ms-2" onclick="doScanOldIp()">🔍 掃描舊 IP</button>
        <div class="text-muted mt-2" style="font-size:11px">⚠ 套用後頁面將自動跳轉至新 IP；掃描為唯讀檢查，不修改任何檔案</div>
      </div>
      <div class="col-md-7">
        <label class="form-label">執行結果</label>
        <div class="result-box" id="bResult">—</div>
        <label class="form-label mt-2">舊 IP 掃描結果</label>
        <div class="result-box" id="bScan">—（點「🔍 掃描舊 IP」或在 IP 變更後自動執行）</div>
      </div>
    </div>
  </div>
</div>

</div><!-- /container -->

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const DBS  = <?= json_encode($dbs, JSON_UNESCAPED_UNICODE) ?>;

async function api(url, body) {
    const r = await fetch(url, {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN': CSRF},
        body: JSON.stringify(body)
    });
    return r.json();
}

function togglePwd(id, btn) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.textContent = el.type === 'password' ? '顯示' : '隱藏';
}

// ── Block A ──────────────────────────────────────────
function loadConf(alias) {
    alias = alias || document.getElementById('dbSelect').value;
    const d = DBS[alias];
    if (!d) return;
    document.getElementById('dbSelect').value = alias;
    document.getElementById('aHost').value = d.DB_HOST || '';
    document.getElementById('aPort').value = d.DB_PORT || '';
    document.getElementById('aSid').value  = d.DB_SID  || '';
    document.getElementById('aUser').value = d.DB_USER || '';
    document.getElementById('aPass').value = '';
    document.getElementById('aResult').innerHTML = '—';
}

async function saveConf() {
    const alias = document.getElementById('dbSelect').value;
    if (!alias) return;
    const d = DBS[alias] || {};
    setResult('aResult', '<span class="info">儲存中...</span>');
    const j = await api('/oracle-save.php', {
        alias,
        host:  document.getElementById('aHost').value,
        port:  document.getElementById('aPort').value,
        sid:   document.getElementById('aSid').value,
        user:  document.getElementById('aUser').value,
        pass:  document.getElementById('aPass').value,
        label: d.DB_LABEL || alias,
        enabled: d.DB_ENABLED || '1',
    });
    setResult('aResult', j.ok
        ? '<span class="ok">✓ 設定已儲存</span>'
        : `<span class="err">✗ ${j.error||'失敗'}</span>`);
}

async function testConn() {
    // 區塊 A 測試：用「目前表單輸入值」即時測試（不需存檔）
    // 密碼空白 + 有 alias → 後端會從對應 .conf 撈現存密碼
    const alias = document.getElementById('dbSelect').value;
    const host  = document.getElementById('aHost').value.trim();
    const port  = document.getElementById('aPort').value.trim();
    const sid   = document.getElementById('aSid').value.trim();
    const user  = document.getElementById('aUser').value.trim();
    const pass  = document.getElementById('aPass').value;  // 不 trim，避免吃掉前後空白密碼

    if (!host || !sid || !user) {
        setResult('aResult', '<span class="err">✗ 主機 IP、SID、帳號 不可為空</span>');
        return;
    }

    setResult('aResult', '<span class="info">連線測試中（用當前表單值，約 15 秒）...</span>');
    const j = await api('/oracle-test.php', { alias, host, port, sid, user, pass });
    if (j.connected) {
        setResult('aResult', `<span class="ok">● 連線成功（模式：${j.mode||'?'}）\ninstance_up=${j.instance_up}\nDB 狀態：${j.db_status||'OK'}\nsessions：${j.sessions_total||'—'}</span>`);
    } else {
        setResult('aResult', `<span class="err">✗ 連線失敗（模式：${j.mode||'?'}）\n${j.error||''}</span>`);
    }
}

// ── Block C ──────────────────────────────────────────
function toggleAddForm() {
    const f = document.getElementById('addForm');
    f.style.display = f.style.display === 'none' ? '' : 'none';
}

async function addDb() {
    const alias = document.getElementById('nAlias').value.trim();
    if (!alias) { alert('請填寫別名'); return; }
    const res = document.getElementById('addResult');
    res.style.display = '';
    res.innerHTML = '<span class="info">新增中...</span>';
    const j = await api('/oracle-db-add.php', {
        alias,
        host:  document.getElementById('nHost').value.trim(),
        port:  document.getElementById('nPort').value.trim(),
        sid:   document.getElementById('nSid').value.trim(),
        user:  document.getElementById('nUser').value.trim(),
        pass:  document.getElementById('nPass').value,
        label: document.getElementById('nLabel').value.trim() || alias,
    });
    if (j.ok) {
        res.innerHTML = '<span class="ok">✓ 新增成功，重新整理中...</span>';
        setTimeout(() => location.reload(), 1200);
    } else {
        res.innerHTML = `<span class="err">✗ ${j.error||'新增失敗'}</span>`;
    }
}

function editDb(alias) {
    if (!DBS[alias]) {
        showToast(`⚠ 找不到 "${alias}" 設定（DBS keys: ${Object.keys(DBS).join(', ')}）`);
        return;
    }
    loadConf(alias);
    window.scrollTo({top: 0, behavior: 'smooth'});
    // Flash 區塊 A 邊框讓使用者知道載入到哪裡
    const blockA = document.querySelector('.card');  // 第一個 card 就是區塊 A
    if (blockA) {
        blockA.style.transition = 'box-shadow 0.4s, border-color 0.4s';
        const origBorder = blockA.style.borderColor;
        const origShadow = blockA.style.boxShadow;
        blockA.style.borderColor = '#e94560';
        blockA.style.boxShadow = '0 0 24px rgba(233, 69, 96, 0.7)';
        setTimeout(() => {
            blockA.style.borderColor = origBorder;
            blockA.style.boxShadow = origShadow;
        }, 1600);
    }
    showToast(`已載入「${alias}」設定至區塊 A，請在上方表單修改`);
}

function showToast(msg) {
    let toast = document.getElementById('__toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = '__toast';
        toast.style.cssText = 'position:fixed;bottom:30px;right:30px;background:#0f3460;color:#aac4e8;padding:12px 20px;border-radius:6px;border:1px solid #e94560;font-size:13px;z-index:9999;opacity:0;transition:opacity 0.3s;max-width:400px;box-shadow:0 4px 16px rgba(0,0,0,0.4)';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.opacity = '1';
    clearTimeout(toast.__t);
    toast.__t = setTimeout(() => { toast.style.opacity = '0'; }, 2800);
}

async function testOne(alias) {
    const cell = document.querySelector(`#row-${alias} .tr-cell`);
    if (cell) cell.innerHTML = '<span class="info">測試中...</span>';
    setResult('aResult', '<span class="info">測試中...</span>');
    const j = await api('/oracle-test.php', {alias});
    const txt = j.connected
        ? `<span class="ok">✓ 成功 (up=${j.instance_up})</span>`
        : `<span class="err">✗ ${j.error||'失敗'}</span>`;
    if (cell) cell.innerHTML = txt;
    setResult('aResult', j.connected
        ? `<span class="ok">● ${alias} 連線成功\ninstance_up=${j.instance_up}\nsessions：${j.sessions_total||'—'}</span>`
        : `<span class="err">✗ ${alias} 失敗\n${j.error||''}</span>`);
}

async function testAll() {
    const rows = document.querySelectorAll('#dbTable tbody tr[id^="row-"]');
    rows.forEach(r => r.querySelector('.tr-cell').innerHTML = '<span class="info">測試中...</span>');
    await Promise.all(Array.from(rows).map(async row => {
        const alias = row.id.replace('row-', '');
        try {
            const j = await api('/oracle-test.php', {alias});
            row.querySelector('.tr-cell').innerHTML = j.connected
                ? `<span class="ok">✓ 成功</span>`
                : `<span class="err">✗ ${j.error||'失敗'}</span>`;
        } catch(e) {
            row.querySelector('.tr-cell').innerHTML = `<span class="err">✗ 錯誤</span>`;
        }
    }));
}

async function toggleDb(alias, enable) {
    const j = await api('/oracle-db-remove.php', {action:'toggle', alias, enable: enable ? '1':'0'});
    if (j.ok) location.reload();
    else alert('操作失敗：' + (j.error||''));
}

async function delDb(alias) {
    if (!confirm(`確定要刪除 DB「${alias}」？\n這將停止監控並移除設定（LibreNMS 歷史圖表資料保留）。`)) return;
    const j = await api('/oracle-db-remove.php', {action:'delete', alias});
    if (j.ok) location.reload();
    else alert('刪除失敗：' + (j.error||''));
}

// ── Block B ──────────────────────────────────────────
async function doIpUpdate() {
    const newIp = document.getElementById('bNew').value.trim();
    if (!newIp) { alert('請輸入新 IP'); return; }
    if (!confirm(`確定將 monitor-vm IP 更新為 ${newIp}？\n套用後頁面將跳轉至新 IP。`)) return;
    setResult('bResult', '<span class="info">更新中...</span>');
    setResult('bScan', '<span class="info">等待 IP 變更完成後自動掃描...</span>');
    const j = await api('/oracle-ip-update.php', {
        new_ip: newIp,
        old_ip: document.getElementById('bCurrent').value.trim(),
        update_base_url: document.getElementById('chkBase').checked,
        update_app_url:  document.getElementById('chkEnv').checked,
        clear_cache:     document.getElementById('chkCache').checked,
    });
    if (j.ok) {
        const steps = (j.steps||[]).join('\n');
        const targetUrl = `${location.protocol}//${newIp}/oracle-admin.php`;
        setResult('bResult', `<span class="ok">✓ 更新完成\n${steps}\n\n3 秒後跳轉至 ${targetUrl}</span>`);
        // Render auto-scan results (oracle-ip-update.php already invoked scan-old-ip.sh on the OLD IP)
        renderScanResult(j.scan_results, document.getElementById('bCurrent').value.trim());
        setTimeout(() => { window.location.href = targetUrl; }, 3000);
    } else {
        setResult('bResult', `<span class="err">✗ ${j.error||'更新失敗'}</span>`);
    }
}

async function doScanOldIp() {
    const oldIp = document.getElementById('bCurrent').value.trim();
    if (!oldIp) { setResult('bScan', '<span class="err">⚠ 找不到目前 IP（bCurrent 為空）</span>'); return; }
    setResult('bScan', '<span class="info">掃描中...</span>');
    const r = await api('/oracle-scan-old-ip.php', { old_ip: oldIp });
    renderScanResult(r, oldIp);
}

function renderScanResult(r, oldIp) {
    const box = document.getElementById('bScan');
    if (!r || !r.ok || r.status !== 'ok') {
        box.innerHTML = `<span class="err">✗ ${(r && r.error) || '掃描失敗或無回應'}</span>`;
        return;
    }
    if ((r.count || 0) === 0) {
        box.innerHTML = `<span class="ok">✓ 無命中（${oldIp} 未出現在任何已掃描設定檔）</span>`;
        return;
    }
    const rows = r.matches.map(m =>
        `<div><code>${escapeHtml(m.file)}:${m.line}</code> — <span style="color:#ffaa66">${escapeHtml(m.text)}</span></div>`
    ).join('');
    box.innerHTML = `<span class="err">⚠ 發現 ${r.count} 筆殘留命中（請手動評估是否需更新；本工具不自動修改）：</span><br>${rows}`;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function setResult(id, html) {
    document.getElementById(id).innerHTML = html;
}

// Init: load first DB into Block A form
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('dbSelect');
    if (sel && sel.value) loadConf(sel.value);
});
</script>
</body>
</html>
