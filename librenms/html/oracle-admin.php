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
.result-box{background:#0d1b2a;border:1px solid #1e4080;border-radius:4px;padding:10px 12px;min-height:48px;font-family:monospace;font-size:12px;line-height:1.6;color:#cfe0ff;white-space:pre-wrap;word-break:break-all}
.ok{color:#4ade80}.err{color:#f87171}.info{color:#60b4f8}
.table{color:#d0d0e0;font-size:13px}
.table-dark{--bs-table-bg:#0d1b2a;--bs-table-border-color:#1e4080}
.badge-enabled{background:#166534;color:#4ade80;padding:2px 8px;border-radius:3px;font-size:11px}
.badge-disabled{background:#3f1111;color:#f87171;padding:2px 8px;border-radius:3px;font-size:11px}
.form-check-input:checked{background-color:#e94560;border-color:#e94560}
.form-check-label{color:#d0d0e0}
.form-label{color:#cfe0ff}
code{color:#60b4f8;background:transparent}
</style>
</head>
<body>

<div class="navbar-top">
    <a href="/">← LibreNMS</a>
    <h4>⚙ 監控管理客製化設定</h4>
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
        <div class="mb-2"><label class="form-label">標籤 <span class="text-muted" style="font-size:11px">（戰情室、列表顯示用）</span></label><input type="text" class="form-control" id="aLabel" placeholder="L1HWEB Oracle 9i" maxlength="80"></div>
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

<!-- ═══ 區塊 B：多台 DB 管理 ══════════════════════════════════════ -->

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5>▌ 區塊 B — 多台資料庫主機管理</h5>
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

    <!-- 📋 新增 DB 前置作業說明 -->
    <details class="mt-3" style="border:1px solid #1e4080;border-radius:6px;background:#0d1b2a">
      <summary style="padding:10px 14px;cursor:pointer;color:#60b4f8;font-size:13px;user-select:none;list-style:none">
        📋 <strong>新增受監控 DB 前置作業</strong> — 需在目標 Oracle DB 主機上以 DBA 身分執行的 SQL（點此展開）
      </summary>
      <div style="padding:12px 16px;border-top:1px solid #1e4080;font-size:13px;line-height:1.8">

        <div style="color:#8899bb;margin-bottom:10px">在目標 Oracle DB 以 <code>SYSDBA</code> 或具 DBA 權限帳號登入後，執行以下 SQL：</div>

        <pre style="background:#070f1a;border:1px solid #1e4080;border-radius:4px;padding:10px 14px;color:#7fe0a0;font-size:12px;margin-bottom:12px;overflow-x:auto">-- 1) 建立監控專用唯讀帳號（密碼自訂）
CREATE USER librenms IDENTIFIED BY "你的密碼";

-- 2) 允許登入
GRANT CREATE SESSION TO librenms;

-- 3) 唯讀存取所有資料字典 / 動態效能視圖（涵蓋 v$* + dba_*）
GRANT SELECT_CATALOG_ROLE TO librenms;</pre>

        <div style="color:#8899bb;margin-bottom:6px">驗證帳號權限（以 librenms 登入後執行，全部有回傳值即代表 OK）：</div>

        <pre style="background:#070f1a;border:1px solid #1e4080;border-radius:4px;padding:10px 14px;color:#aac4e8;font-size:12px;margin-bottom:12px;overflow-x:auto">SELECT status   FROM v$instance;    -- 應回 OPEN
SELECT count(*) FROM dba_data_files; -- 應回數字
SELECT log_mode FROM v$database;     -- 應回 ARCHIVELOG 或 NOARCHIVELOG</pre>

        <div style="color:#8899bb;margin-bottom:6px">若公司資安不接受角色授權，可改用等效的系統權限（二擇一即可）：</div>

        <pre style="background:#070f1a;border:1px solid #1e4080;border-radius:4px;padding:10px 14px;color:#f8c070;font-size:12px;margin-bottom:14px;overflow-x:auto">GRANT SELECT ANY DICTIONARY TO librenms;  -- 替代 SELECT_CATALOG_ROLE</pre>

        <div style="background:#0f2a10;border:1px solid #2d6e30;border-radius:4px;padding:8px 12px;color:#7fe0a0;font-size:12px">
          <strong>⚠ 網路確認：</strong>monitor-vm (172.16.1.94) 至目標 DB 主機的 <strong>TCP 1521</strong> 必須放行，否則連線測試會 timeout。
          可於 monitor-vm 上執行 <code>nc -zv &lt;DB_IP&gt; 1521</code> 確認。
        </div>

      </div>
    </details>
  </div>
</div>

<!-- ═══ 區塊 C：monitor-vm IP 異動 ════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <h5>▌ 區塊 C — 監控主機 IP 異動設定</h5>
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
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label">子網路遮罩 (CIDR)</label>
            <input type="text" class="form-control" id="bCidr" placeholder="24">
          </div>
          <div class="col-6"><label class="form-label">預設閘道</label>
            <input type="text" class="form-control" id="bGw" placeholder="172.16.1.254">
          </div>
        </div>
        <div class="text-muted mb-3" style="font-size:11px">遮罩＋閘道兩者都填 → 產生主機 netplan 網路設定（只寫檔，不自動套用）；兩者留空 → 只改 LibreNMS base_url</div>
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
        <div class="text-muted mt-2" style="font-size:11px">⚠ 未填遮罩/閘道時，套用後頁面跳轉至新 IP；有填則僅寫 netplan，需到 console 執行 <code>sudo netplan apply</code> 才生效。掃描為唯讀檢查。</div>
      </div>
      <div class="col-md-7">
        <label class="form-label">執行結果</label>
        <div class="result-box" id="bResult">—</div>
        <label class="form-label mt-2">舊 IP 掃描結果</label>
        <div class="result-box" id="bScan">—（點「🔍 掃描舊 IP」或在 IP 變更後自動執行）</div>
      </div>
    </div>
    <div class="alert mt-3 mb-0" style="background:#3a2c10;border:1px solid #d99a30;color:#ffd98a;font-size:12px;border-radius:6px;padding:10px 12px">
      <strong>📌 重要提醒（有填遮罩/閘道時）：</strong>
      系統<strong>只會寫入 netplan 設定檔，不會自動套用</strong>。務必到 VM 的 <strong>console</strong>（Proxmox／vSphere）執行下列指令才會生效：
      <div style="margin-top:6px"><code style="background:#0a1525;color:#7fe0a0;padding:3px 8px;border-radius:4px;font-size:13px">sudo netplan apply</code></div>
      <div style="margin-top:6px;color:#e0b870">套用瞬間連線會切到新 IP，請確認 console 可達後再執行；若出錯，把備份目錄的 <code>*.yaml</code> 複製回 <code>/etc/netplan</code> 後再 <code>netplan apply</code> 還原。</div>
    </div>
  </div>
</div>

<!-- ═══ 區塊 D：防火牆管理網段 ════════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <h5>▌ 區塊 D — 防火牆管理網段（可允許連入管理介面的來源）</h5>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">自動偵測的本機網段（直連，永遠開放）</label>
        <div class="result-box" id="fwAuto">載入中…</div>
        <div class="text-muted mt-1" style="font-size:11px">由 <code>ip route</code> 自動偵測，IP/網段變動時自動跟隨，無需手動維護。</div>

        <label class="form-label mt-3">額外允許的網段（其他內網網段 / 遠端管理）</label>
        <div id="fwExtra"><div class="text-muted" style="font-size:12px">載入中…</div></div>

        <div class="input-group input-group-sm mt-2">
          <input type="text" class="form-control" id="fwNewCidr" placeholder="172.16.5.0/24">
          <button class="btn btn-success" onclick="addCidr()">＋ 新增網段</button>
        </div>
        <div class="text-muted mt-1" style="font-size:11px">格式 <code>CIDR</code>，例 <code>172.16.5.0/24</code>。寫入持久化設定檔，套件更新重跑也不會漏。</div>
      </div>
      <div class="col-md-6">
        <label class="form-label">操作結果</label>
        <div class="result-box" id="fwResult">—</div>
        <div class="text-muted mt-2" style="font-size:11px">
          開放的管理埠：<code id="fwPorts">—</code><br>
          ⚠ 日誌「接收」埠（514/12201/5044）需對 log 來源另行開放，不在此管理。
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3">
          <label class="form-label mb-0">目前允許連入管理介面的來源（ufw 實際規則）</label>
          <button class="btn btn-outline-info btn-sm py-0" onclick="listFwRules()">🔍 查詢</button>
        </div>
        <div class="result-box mt-1" id="fwRules">點「🔍 查詢」列出 ufw 目前的允許規則（含舊手動規則與 Anywhere）</div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ 區塊 E：NAS 備份（本地歸檔 → 同步 NAS）════════════════════ -->
<div class="card">
  <div class="card-header">
    <h5>▌ 區塊 E — NAS 備份（jt-glogarch 歸檔本地優先，定期同步至 NAS）</h5>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">目前狀態</label>
        <div class="result-box" id="nasStatus">載入中…</div>

        <div class="row g-2 mt-2">
          <div class="col-5">
            <label class="form-label">協定</label>
            <select class="form-select" id="nasProto" onchange="nasToggleCifs()">
              <option value="nfs">NFS</option>
              <option value="cifs">SMB / CIFS</option>
            </select>
          </div>
          <div class="col-7">
            <label class="form-label">NAS 位址（IP / 主機名）</label>
            <input class="form-control" id="nasServer" placeholder="172.16.1.x">
          </div>
        </div>
        <div class="mb-2 mt-2">
          <label class="form-label" id="nasExportLbl">匯出路徑（NFS export）</label>
          <input class="form-control" id="nasExport" placeholder="/volume1/glogarch">
        </div>
        <div class="row g-2 mb-2">
          <div class="col-7">
            <label class="form-label">本機掛載點</label>
            <input class="form-control" id="nasMount" value="/mnt/nas-glogarch">
          </div>
          <div class="col-5">
            <label class="form-label">同步頻率</label>
            <select class="form-select" id="nasSched">
              <option value="hourly">每小時</option>
              <option value="6h">每 6 小時</option>
              <option value="daily" selected>每日</option>
            </select>
          </div>
        </div>
        <div id="nasCifsCreds" style="display:none">
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label">CIFS 帳號</label><input class="form-control" id="nasUser"></div>
            <div class="col-6"><label class="form-label">CIFS 密碼</label><input type="password" class="form-control" id="nasPass"></div>
          </div>
        </div>
        <div class="d-flex gap-2 mt-2 flex-wrap">
          <button class="btn btn-primary btn-sm" onclick="nasSave()">儲存並掛載</button>
          <button class="btn btn-outline-info btn-sm" onclick="nasTest()">測試</button>
          <button class="btn btn-outline-success btn-sm" onclick="nasSync()">立即同步</button>
          <button class="btn btn-outline-danger btn-sm" onclick="nasUnmount()">卸載 / 停用</button>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label">操作結果</label>
        <div class="result-box" id="nasResult">—</div>
        <div class="text-muted mt-2" style="font-size:11px">
          策略：jt-glogarch 歸檔仍寫本地 <code>/data/graylog-archives</code>（獨立 500G 碟），
          再依頻率 rsync 同步到 NAS；NAS 掉線不影響歸檔。fstab 以 <code>nofail</code> 掛載，不卡開機。
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ 區塊 F：自訂地圖刷新秒數 ══════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <h5>▌ 區塊 F — Custom Map 自動刷新秒數</h5>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-5">
        <div class="mb-2">
          <label class="form-label">目前設定值</label>
          <input type="text" class="form-control" id="fCurrent" value="—" readonly
                 style="background:#0a1525;color:#8899bb">
        </div>
        <div class="mb-3">
          <label class="form-label">新刷新秒數 <span class="text-danger">*</span>
            <span class="text-muted" style="font-size:11px">（5 ~ 86400 秒；建議 30 ~ 300）</span>
          </label>
          <input type="number" class="form-control" id="fValue" min="5" max="86400"
                 placeholder="例如 60">
        </div>
        <div class="d-flex gap-2 align-items-center">
          <button class="btn btn-warning btn-sm" onclick="doSetCustomMapRefresh()">套用秒數</button>
          <button class="btn btn-outline-secondary btn-sm" onclick="doClearCustomMapRefresh()">回復預設</button>
          <span class="text-muted" style="font-size:11px">套用後 F5 重新整理地圖頁生效；只影響 Custom Map，不影響其他頁面</span>
        </div>
      </div>
      <div class="col-md-7">
        <label class="form-label">執行結果</label>
        <div class="result-box" id="fResult">—</div>
      </div>
    </div>
  </div>
</div>

</div><!-- /container -->

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const DBS  = <?= json_encode($dbs, JSON_UNESCAPED_UNICODE) ?>;

async function api(url, body) {
    let r;
    try {
        r = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN': CSRF},
            body: JSON.stringify(body)
        });
    } catch (e) {
        return {ok:false, error:`網路錯誤：${e.message}`};
    }
    const text = await r.text();
    if (!text) {
        return {ok:false, error:`HTTP ${r.status}：伺服器回傳空 body（可能 PHP fatal / 逾時，請查 /var/log/nginx/error.log 或 php-fpm log）`};
    }
    try {
        return JSON.parse(text);
    } catch (e) {
        return {ok:false, error:`HTTP ${r.status}：回應非 JSON — ${text.substring(0,200)}`};
    }
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
    document.getElementById('aLabel').value = d.DB_LABEL || alias;
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
        label: document.getElementById('aLabel').value.trim() || alias,
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
    const cidr = document.getElementById('bCidr').value.trim().replace(/^\//, '');
    const gw   = document.getElementById('bGw').value.trim();
    const willNet = cidr !== '' && gw !== '';
    const msg = willNet
        ? `確定產生主機網路設定？\nIP ${newIp}/${cidr}　閘道 ${gw}\n\n注意：只會「寫入」netplan，不會自動套用。\n需到 console 執行 sudo netplan apply 才生效（套用瞬間連線會切到新 IP）。`
        : `確定將 monitor-vm IP 更新為 ${newIp}？\n（未填遮罩/閘道 → 只改 LibreNMS base_url，套用後跳轉新 IP）`;
    if (!confirm(msg)) return;
    setResult('bResult', '<span class="info">處理中...</span>');
    setResult('bScan', '<span class="info">等待 IP 變更完成後自動掃描...</span>');
    const j = await api('/oracle-ip-update.php', {
        new_ip: newIp,
        old_ip: document.getElementById('bCurrent').value.trim(),
        new_cidr: cidr,
        new_gateway: gw,
        update_base_url: document.getElementById('chkBase').checked,
        update_app_url:  document.getElementById('chkEnv').checked,
        clear_cache:     document.getElementById('chkCache').checked,
    });
    if (!j.ok) { setResult('bResult', `<span class="err">✗ ${j.error||'更新失敗'}</span>`); return; }

    const steps = (j.steps||[]).join('\n');
    let html = `<span class="ok">✓ LibreNMS 設定已更新\n${steps}</span>`;
    renderScanResult(j.scan_results, document.getElementById('bCurrent').value.trim());

    const np = j.netplan;
    if (np) {
        if (np.ok) {
            html += `\n\n<span class="ok">✓ netplan 已寫入：${np.file}</span>`
                 +  `\n備份：${np.backup}`
                 +  `\n<span class="info">⚠ 尚未套用。請到 console 執行：\n  ${np.apply_cmd}\n套用瞬間連線會切到新 IP（${newIp}）。</span>`;
            if (np.preview) html += `\n\n— netplan 預覽 —\n${np.preview}`;
        } else {
            html += `\n\n<span class="err">✗ netplan：${np.error||'失敗'}</span>`;
        }
        setResult('bResult', html);   // 有改網路 → 不自動跳轉（主機尚未移到新 IP）
    } else {
        const targetUrl = `${location.protocol}//${newIp}/oracle-admin.php`;
        setResult('bResult', html + `\n\n3 秒後跳轉至 ${targetUrl}`);
        setTimeout(() => { window.location.href = targetUrl; }, 3000);
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

// ── Block D：防火牆管理網段 ──────────────────────────────
async function loadFirewall() {
    try {
        const j = await api('/oracle-firewall.php', {action: 'list'});
        if (!j.ok) {
            const errMsg = `<span class="err">${j.error||'載入失敗'}</span>`;
            setResult('fwAuto', errMsg);
            document.getElementById('fwExtra').innerHTML = errMsg;
            return;
        }
        document.getElementById('fwAuto').innerHTML =
            (j.auto && j.auto.length) ? j.auto.map(c => `<code>${escapeHtml(c)}</code>`).join('  ')
                                      : '<span class="text-muted">（偵測不到本機網段）</span>';
        document.getElementById('fwPorts').textContent = j.ports || '—';
        const ex = document.getElementById('fwExtra');
        if (j.extra && j.extra.length) {
            ex.innerHTML = j.extra.map(c =>
                `<div class="d-flex align-items-center gap-2 mb-1">
                    <code>${escapeHtml(c)}</code>
                    <button class="btn btn-outline-danger btn-sm py-0" onclick="removeCidr('${escapeHtml(c)}')">移除</button>
                 </div>`).join('');
        } else {
            ex.innerHTML = '<div class="text-muted" style="font-size:12px">（尚無額外網段；僅開放本機網段）</div>';
        }
    } catch (e) {
        setResult('fwAuto', `<span class="err">載入錯誤：${e.message}</span>`);
        document.getElementById('fwExtra').innerHTML = `<span class="err" style="font-size:12px">載入錯誤：${e.message}</span>`;
    }
}

async function addCidr() {
    const cidr = document.getElementById('fwNewCidr').value.trim();
    if (!cidr) { alert('請輸入網段 CIDR'); return; }
    setResult('fwResult', '<span class="info">新增並套用中…</span>');
    const j = await api('/oracle-firewall.php', {action: 'add', cidr});
    if (j.ok) {
        setResult('fwResult', `<span class="ok">✓ 已新增並套用：${escapeHtml(j.added||cidr)}</span>`);
        document.getElementById('fwNewCidr').value = '';
        loadFirewall();
    } else {
        setResult('fwResult', `<span class="err">✗ ${escapeHtml(j.error||'新增失敗')}</span>`);
    }
}

async function listFwRules() {
    const box = document.getElementById('fwRules');
    box.innerHTML = '<span class="info">查詢中…</span>';
    const j = await api('/oracle-firewall.php', {action: 'rules'});
    if (!j.ok) { box.innerHTML = `<span class="err">✗ ${escapeHtml(j.error||'查詢失敗')}</span>`; return; }
    if (!j.rules || !j.rules.length) {
        box.innerHTML = '<span class="info">（ufw 無管理埠允許規則，或 ufw 未啟用）</span>';
        return;
    }
    box.innerHTML = j.rules.map(r => {
        const safe = escapeHtml(r);
        let color = '#cfe0ff';                          // 一般：亮藍灰
        if (/Anywhere/i.test(r))      color = '#ffb454'; // 全開放 → 橘色警示
        else if (/mgmt-auto/.test(r)) color = '#4ade80'; // 自動管理 → 綠色
        return `<span style="color:${color}">${safe}</span>`;
    }).join('\n');
}

async function removeCidr(cidr) {
    if (!confirm(`確定移除網段「${cidr}」？\n將從設定檔刪除並移除對應防火牆規則。`)) return;
    setResult('fwResult', '<span class="info">移除中…</span>');
    const j = await api('/oracle-firewall.php', {action: 'remove', cidr});
    if (j.ok) {
        setResult('fwResult', `<span class="ok">✓ 已移除：${escapeHtml(j.removed||cidr)}</span>`);
        loadFirewall();
    } else {
        setResult('fwResult', `<span class="err">✗ ${escapeHtml(j.error||'移除失敗')}</span>`);
    }
}

// ── Block E：NAS 備份 ──────────────────────────────────
function nasToggleCifs() {
    const isCifs = document.getElementById('nasProto').value === 'cifs';
    document.getElementById('nasCifsCreds').style.display = isCifs ? '' : 'none';
    document.getElementById('nasExportLbl').textContent = isCifs ? '共享名稱（CIFS share）' : '匯出路徑（NFS export）';
    document.getElementById('nasExport').placeholder = isCifs ? 'backup' : '/volume1/glogarch';
}

async function loadNas() {
    try {
        const j = await api('/oracle-nasbackup.php', {action: 'status'});
        if (!j.ok) { setResult('nasStatus', `<span class="err">${escapeHtml(j.error||'載入失敗')}</span>`); return; }
        if (!j.configured) {
            setResult('nasStatus', '<span class="info">尚未設定 NAS 備份</span>');
        } else {
            const mounted = j.mounted ? '<span class="ok">● 已掛載</span>' : '<span class="err">● 未掛載</span>';
            const enabled = j.enabled ? '<span class="ok">啟用</span>' : '<span class="err">停用</span>';
            setResult('nasStatus',
                `${mounted}　排程：${enabled}（${escapeHtml(j.schedule||'')}）\n`+
                `${escapeHtml(j.protocol||'')}  //${escapeHtml(j.server||'')}/${escapeHtml(j.export||'')}\n`+
                `掛載點：${escapeHtml(j.mountpoint||'')}\n`+
                `本地歸檔大小：${escapeHtml(j.archive_size||'?')}　NAS 可用：${escapeHtml(j.nas_avail||'?')}\n`+
                `上次同步：${escapeHtml(j.last_sync||'（尚未同步）')}`);
            // 回填表單
            if (j.protocol) document.getElementById('nasProto').value = j.protocol;
            if (j.server)   document.getElementById('nasServer').value = j.server;
            if (j.export)   document.getElementById('nasExport').value = j.export;
            if (j.mountpoint) document.getElementById('nasMount').value = j.mountpoint;
            if (j.schedule) document.getElementById('nasSched').value = j.schedule;
            nasToggleCifs();
        }
    } catch (e) {
        setResult('nasStatus', `<span class="err">載入錯誤：${e.message}</span>`);
    }
}

async function nasSave() {
    const proto = document.getElementById('nasProto').value;
    const payload = {
        action: 'save',
        protocol: proto,
        server: document.getElementById('nasServer').value.trim(),
        export: document.getElementById('nasExport').value.trim(),
        mountpoint: document.getElementById('nasMount').value.trim(),
        schedule: document.getElementById('nasSched').value,
    };
    if (proto === 'cifs') {
        payload.cifs_user = document.getElementById('nasUser').value.trim();
        payload.cifs_pass = document.getElementById('nasPass').value;
    }
    setResult('nasResult', '<span class="info">掛載並設定排程中…</span>');
    const j = await api('/oracle-nasbackup.php', payload);
    setResult('nasResult', j.ok
        ? `<span class="ok">✓ 已掛載並啟用同步排程（${escapeHtml(j.schedule||'')}）</span>`
        : `<span class="err">✗ ${escapeHtml(j.error||'失敗')}</span>`);
    loadNas();
}

async function nasTest() {
    setResult('nasResult', '<span class="info">測試中…</span>');
    const j = await api('/oracle-nasbackup.php', {action: 'test'});
    setResult('nasResult', j.ok
        ? '<span class="ok">✓ 掛載點可讀寫</span>'
        : `<span class="err">✗ ${escapeHtml(j.error||'測試失敗')}</span>`);
}

async function nasSync() {
    setResult('nasResult', '<span class="info">差異性同步中（只傳大小/日期不同或不存在的檔案）…</span>');
    const j = await api('/oracle-nasbackup.php', {action: 'sync'});
    if (j.ok) {
        const mb = ((j.bytes_transferred || 0) / 1024 / 1024).toFixed(2);
        setResult('nasResult',
            `<span class="ok">✓ 同步完成 ${escapeHtml(j.synced_at||'')}\n` +
            `模式：${escapeHtml(j.mode||'differential')}\n` +
            `檔案總數：${j.files_total||0} ｜ 實際傳送：${j.files_transferred||0} ｜ 跳過（未變動）：${j.files_skipped||0}\n` +
            `傳送大小：${mb} MB</span>`);
    } else {
        setResult('nasResult', `<span class="err">✗ ${escapeHtml(j.error||'同步失敗')}</span>`);
    }
    loadNas();
}

async function nasUnmount() {
    if (!confirm('確定卸載並停用 NAS 同步？\n（本地歸檔與 NAS 上已同步的檔案都會保留）')) return;
    setResult('nasResult', '<span class="info">卸載中…</span>');
    const j = await api('/oracle-nasbackup.php', {action: 'unmount'});
    setResult('nasResult', j.ok
        ? '<span class="ok">✓ 已卸載並停用排程</span>'
        : `<span class="err">✗ ${escapeHtml(j.error||'失敗')}</span>`);
    loadNas();
}

// ── Block F: Custom Map Refresh ───────────────────────
async function loadCustomMapRefresh() {
    const r = await api('/oracle-custom-map.php', { action: 'get' });
    const cur = document.getElementById('fCurrent');
    if (r.ok && r.status === 'ok') {
        cur.value = r.value !== null && r.value !== undefined
            ? `${r.value} 秒（${r.source || ''}）`
            : `未設定，目前使用全域 page_refresh（${r.source || ''}）`;
    } else {
        cur.value = '讀取失敗：' + (r.error || '');
    }
}

async function doSetCustomMapRefresh() {
    const val = parseInt(document.getElementById('fValue').value, 10);
    if (!val || val < 5 || val > 86400) {
        setResult('fResult', '<span class="err">⚠ 請輸入 5 ~ 86400 之間的整數</span>');
        return;
    }
    setResult('fResult', '<span class="info">套用中...</span>');
    const r = await api('/oracle-custom-map.php', { action: 'set', value: val });
    if (r.ok && r.status === 'ok') {
        setResult('fResult',
            `<span class="ok">✓ 已套用 ${r.value} 秒\n下次開啟（或 F5 重整）Custom Map 自動生效</span>`);
        loadCustomMapRefresh();
    } else {
        setResult('fResult', `<span class="err">✗ ${escapeHtml(r.error || '套用失敗')}</span>`);
    }
}

async function doClearCustomMapRefresh() {
    if (!confirm('確定移除 custom_map_refresh 設定？\n刷新時間將回到全域 page_refresh 值。')) return;
    setResult('fResult', '<span class="info">移除中...</span>');
    const r = await api('/oracle-custom-map.php', { action: 'clear' });
    if (r.ok && r.status === 'ok') {
        setResult('fResult',
            `<span class="ok">✓ 已移除自訂設定\n下次 Custom Map 重整將套用全域 page_refresh</span>`);
        document.getElementById('fValue').value = '';
        loadCustomMapRefresh();
    } else {
        setResult('fResult', `<span class="err">✗ ${escapeHtml(r.error || '移除失敗')}</span>`);
    }
}

// Init: load first DB into Block A form
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('dbSelect');
    if (sel && sel.value) loadConf(sel.value);
    loadFirewall();
    loadNas();
    loadCustomMapRefresh();
});
</script>
</body>
</html>
