<?php
/**
 * oracle-dashboard.php — Oracle 戰情室即時監控畫面
 * URL: http://<monitor-vm>/oracle-dashboard.php
 * 集中呈現所有受監控 Oracle DB 的 Data Guard / Materialized View / 健康 / 效能狀態，
 * 每 60 秒自動刷新（資料來自 oracle-dashboard-data.php）。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';

if (!Auth::check() || !Auth::user()->hasRole('admin')) {
    header('Location: /');
    exit;
}

$csrf_token = csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
<title>Oracle 戰情室 — LibreNMS</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#0b1020;color:#dfe6f5;font-family:"Segoe UI","Microsoft JhengHei",sans-serif;font-size:14px}
.topbar{display:flex;align-items:center;gap:18px;padding:12px 22px;background:linear-gradient(90deg,#0f3460,#102a4c);border-bottom:3px solid #e94560;position:sticky;top:0;z-index:10}
.topbar h1{margin:0;font-size:20px;color:#fff;font-weight:700;letter-spacing:1px}
.topbar a{color:#9fc0ec;text-decoration:none;font-size:13px}
.topbar a:hover{color:#fff}
.clock{font-size:18px;color:#cfe0ff;font-variant-numeric:tabular-nums}
.lights{display:flex;gap:14px;align-items:center;margin-left:auto}
.light{display:flex;align-items:center;gap:6px;font-size:18px;font-weight:700}
.dot{width:14px;height:14px;border-radius:50%;display:inline-block;box-shadow:0 0 8px currentColor}
.red{color:#ff4d5e}.yellow{color:#ffc24b}.green{color:#3ddc84}.grey{color:#7b8aa6}
.dot.red{background:#ff4d5e}.dot.yellow{background:#ffc24b}.dot.green{background:#3ddc84}.dot.grey{background:#7b8aa6}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(440px,1fr));gap:18px;padding:20px}
.dbcard{background:#121a30;border:1px solid #1e2c4e;border-radius:8px;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,.4)}
.dbcard.s-red{border-color:#e94560}.dbcard.s-yellow{border-color:#d99a30}.dbcard.s-green{border-color:#1f6b45}
.dbhead{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#16213e;border-bottom:1px solid #1e2c4e}
.dbhead .title{font-size:16px;font-weight:700;color:#fff}
.dbhead .sub{font-size:12px;color:#7b8aa6}
.dbhead .state{margin-left:auto;font-size:14px;font-weight:700}
.panels{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#1e2c4e}
.panel{background:#101830;padding:12px 14px}
.panel h4{margin:0 0 8px;font-size:12px;color:#7fa8e0;letter-spacing:.5px;text-transform:uppercase;border-bottom:1px solid #1e2c4e;padding-bottom:5px}
.panel.full{grid-column:1 / -1}
.kv{display:flex;justify-content:space-between;padding:2px 0;font-size:13px}
.kv .k{color:#9aa8c4}.kv .v{font-weight:600;font-variant-numeric:tabular-nums}
.muted{color:#5f6f8c}
.bar{height:8px;background:#1c2949;border-radius:4px;overflow:hidden;margin-top:2px}
.bar>span{display:block;height:100%}
.tsline{margin-bottom:6px}
.tsline .lab{display:flex;justify-content:space-between;font-size:12px;color:#9aa8c4}
.pill{padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700}
.pill.green{background:#14361f;color:#3ddc84}.pill.red{background:#3a1117;color:#ff6b78}
.pill.yellow{background:#3a2c10;color:#ffc24b}.pill.grey{background:#1c2438;color:#8a99b8}
.notconf{color:#7b8aa6;font-style:italic;padding:8px 0}
.err{color:#ff6b78;font-size:12px}
.updated-bar{display:flex;align-items:center;gap:14px;padding:6px 22px;font-size:12px}
.updated-bar #updated{color:#5f6f8c}
.updated-bar label{color:#7b8aa6}
.updated-bar select{background:#16213e;color:#cfe0ff;border:1px solid #2a3d6a;border-radius:4px;padding:2px 6px;font-size:12px;cursor:pointer}
.empty{padding:40px;text-align:center;color:#7b8aa6}
.warn-section{margin:0 20px 20px;border:2px solid #e94560;border-radius:8px;padding:16px;display:none}
.warn-title{color:#ff4d5e;font-weight:700;font-size:14px;margin-bottom:12px;letter-spacing:.5px}
.warn-item{display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-top:1px solid #2a1a20}
.warn-item:first-child{border-top:none}
.warn-host{min-width:120px;font-weight:700;color:#ffc24b;flex-shrink:0}
.warn-msg{color:#f0c0c8;line-height:1.5}
.warn-sev-red .warn-host{color:#ff6b78}
/* 版面偏好：每張卡片各自的區塊顯隱（dbcard class 控制，撐得過 grid.innerHTML 重建）*/
.dbcard.hide-dg .panel[data-block=dg],
.dbcard.hide-mview .panel[data-block=mview],
.dbcard.hide-health .panel[data-block=health],
.dbcard.hide-ts .panel[data-block=ts]{display:none !important}
/* 卡片拖拉 */
.dbcard[draggable=true]{cursor:grab}
.dbcard.dragging{opacity:.4}
.dbcard.drag-over{outline:2px dashed #4d8cff;outline-offset:-2px}
/* 卡片標題列的單卡設定齒輪 */
.card-gear{background:none;border:none;color:#7b8aa6;font-size:15px;cursor:pointer;padding:0 2px;line-height:1;margin-left:8px}
.card-gear:hover{color:#fff}
/* 設定面板（topbar 全域 + 卡片浮動選單共用樣式）*/
.gear-btn{background:none;border:none;color:#9fc0ec;font-size:18px;cursor:pointer;padding:0 4px;line-height:1}
.gear-btn:hover{color:#fff}
.settings-pop{background:#16213e;border:1px solid #2a3d6a;border-radius:8px;padding:14px 16px;z-index:40;box-shadow:0 6px 20px rgba(0,0,0,.5);min-width:230px;display:none}
.settings-pop.topbar-pop{position:fixed;top:56px;right:22px}
#card-pop{position:fixed}
.settings-pop.open{display:block}
.settings-pop h5{margin:0 0 10px;font-size:13px;color:#7fa8e0;letter-spacing:.5px}
.settings-pop label{display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;color:#cfe0ff;cursor:pointer}
.settings-pop .hint{margin-top:10px;font-size:11px;color:#5f6f8c;line-height:1.5}
.settings-pop .sp-foot{margin-top:10px;border-top:1px solid #2a3d6a;padding-top:10px}
.settings-pop button.reset{background:#2a3d6a;color:#cfe0ff;border:none;border-radius:4px;padding:5px 10px;font-size:12px;cursor:pointer}
.settings-pop button.reset:hover{background:#37508a}
</style>
</head>
<body>

<div class="topbar">
    <a href="/">← LibreNMS</a>
    <h1>🛢 Oracle 資料庫戰情室</h1>
    <a href="/oracle-admin.php">⚙ 監控管理</a>
    <span class="clock" id="clock">--:--:--</span>
    <div class="lights">
        <span class="light red"><span class="dot red"></span><span id="cnt-red">0</span></span>
        <span class="light yellow"><span class="dot yellow"></span><span id="cnt-yellow">0</span></span>
        <span class="light green"><span class="dot green"></span><span id="cnt-green">0</span></span>
        <span class="light grey"><span class="dot grey"></span><span id="cnt-grey">0</span></span>
    </div>
    <button class="gear-btn" id="settings-btn" title="顯示設定">⚙</button>
</div>

<div class="settings-pop topbar-pop" id="settings-pop">
    <h5>版面設定</h5>
    <div class="hint">每張卡片右上角的 ⚙ 可單獨設定該卡片要顯示哪些區塊；拖拉卡片可調整顯示順序。</div>
    <div class="sp-foot">
        <button class="reset" id="reset-order">重設版面（全部顯示＋預設順序）</button>
    </div>
</div>

<!-- 單一浮動選單，點各卡片 ⚙ 時定位到該卡片並載入其狀態 -->
<div class="settings-pop" id="card-pop">
    <h5 id="card-pop-title">顯示區塊</h5>
    <label><input type="checkbox" class="blk-toggle" value="dg"> Data Guard</label>
    <label><input type="checkbox" class="blk-toggle" value="mview"> Materialized View</label>
    <label><input type="checkbox" class="blk-toggle" value="health"> 資料庫健康</label>
    <label><input type="checkbox" class="blk-toggle" value="ts"> 表空間使用率／效能</label>
    <label><input type="checkbox" class="blk-toggle" value="warn"> 警示說明</label>
</div>

<div class="updated-bar">
    <label for="refresh-sel">刷新間隔：</label>
    <select id="refresh-sel">
        <option value="30">30 秒</option>
        <option value="60" selected>1 分鐘</option>
        <option value="180">3 分鐘</option>
        <option value="300">5 分鐘</option>
        <option value="600">10 分鐘</option>
        <option value="900">15 分鐘</option>
    </select>
    <span id="updated">載入中…</span>
</div>
<div class="grid" id="grid"></div>
<div class="warn-section" id="warn-section">
    <div class="warn-title">⚠ 警示說明 ／ 異常主機</div>
    <div id="warn-list"></div>
</div>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const ROLE = {0:'未知/單機', 1:'PRIMARY', 2:'PHYSICAL STANDBY', 3:'LOGICAL STANDBY'};

function num(v){ return (v===undefined||v===null||v==='') ? 0 : Number(v); }
function fmtInt(v){ return num(v).toLocaleString(); }

function clock(){
    const d = new Date();
    const p = n => String(n).padStart(2,'0');
    document.getElementById('clock').textContent =
        `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}
setInterval(clock, 1000); clock();

// 判斷單台 DB 的號誌狀態
function statusOf(db){
    if(!db.enabled) return 'grey';
    if(!db.connected) return 'red';
    const m = db.metrics || {};
    const dgConf = num(m.dg_configured)===1;
    if(dgConf && num(m.dg_gap)>0) return 'red';
    const warn =
        num(m.mview_stale)>0 || num(m.mview_jobs_broken)>0 || num(m.mview_jobs_failed)>0 ||
        num(m.mview_oldest_hours)>168 ||
        num(m.archivelog_mode)===0 || num(m.invalid_objects)>0 ||
        num(m.temp_pct_used)>=90 ||
        (dgConf && num(m.dg_apply_lag_min)>15) ||
        (Array.isArray(m.tablespaces) && m.tablespaces.some(t=>num(t.pct_used)>=90));
    return warn ? 'yellow' : 'green';
}

function kv(k,v,cls){ return `<div class="kv"><span class="k">${k}</span><span class="v ${cls||''}">${v}</span></div>`; }

function dgPanel(m){
    if(num(m.dg_configured)!==1){
        return `<div class="panel" data-block="dg"><h4>Data Guard</h4>
            <div class="kv"><span class="k">角色</span><span class="v">${ROLE[num(m.dg_role)]||'—'}</span></div>
            <div class="notconf">未設定 Data Guard（無 standby）</div></div>`;
    }
    const gap = num(m.dg_gap), lag = num(m.dg_apply_lag_min);
    return `<div class="panel" data-block="dg"><h4>Data Guard</h4>
        ${kv('角色', ROLE[num(m.dg_role)]||'—')}
        ${kv('Switchover', num(m.dg_switchover)===1?'<span class="pill green">就緒</span>':'<span class="pill yellow">未就緒</span>')}
        ${kv('Standby 程序', fmtInt(m.dg_standby_cnt))}
        ${kv('Archive Gap', gap>0?`<span class="pill red">${gap}</span>`:'<span class="pill green">0</span>')}
        ${kv('Apply Lag', lag>15?`<span class="pill yellow">${lag} 分</span>`:`${lag} 分`)}
        ${kv('Log 序號', fmtInt(m.dg_seq_current))}
    </div>`;
}

function mvPanel(m){
    const stale = num(m.mview_stale), broken = num(m.mview_jobs_broken), failed = num(m.mview_jobs_failed);
    const hrs = num(m.mview_oldest_hours);
    const days = hrs>0 ? (hrs/24).toFixed(0) : 0;
    return `<div class="panel" data-block="mview"><h4>Materialized View（Snapshot）</h4>
        ${kv('總數', fmtInt(m.mview_total))}
        ${kv('過期 (Stale)', stale>0?`<span class="pill red">${fmtInt(stale)}</span>`:'<span class="pill green">0</span>')}
        ${kv('中斷的 Job', broken>0?`<span class="pill red">${fmtInt(broken)}</span>`:'<span class="pill green">0</span>')}
        ${kv('失敗的 Job', failed>0?`<span class="pill red">${fmtInt(failed)}</span>`:'<span class="pill green">0</span>')}
        ${kv('最舊刷新', hrs>0?`<span class="${hrs>168?'red':''}">${days} 天前</span>`:'—')}
    </div>`;
}

function healthPanel(m){
    const arch = num(m.archivelog_mode)===1;
    const io = num(m.invalid_objects), ii = num(m.invalid_indexes);
    return `<div class="panel" data-block="health"><h4>資料庫健康</h4>
        ${kv('Archivelog', arch?'<span class="pill green">ON</span>':'<span class="pill yellow">OFF</span>')}
        ${kv('DB 開啟', num(m.db_open)===1?'<span class="pill green">READ WRITE</span>':'<span class="pill yellow">其他</span>')}
        ${kv('無效物件', io>0?`<span class="pill yellow">${fmtInt(io)}</span>`:'0')}
        ${kv('無效索引', ii>0?`<span class="pill yellow">${fmtInt(ii)}</span>`:'0')}
        ${kv('連線數 (active)', `${fmtInt(m.sessions_total)} (${fmtInt(m.sessions_active)})`)}
    </div>`;
}

function tsColor(p){ return p>=95?'#ff4d5e':p>=85?'#ffc24b':'#3ddc84'; }
function tsPanel(m){
    let list = Array.isArray(m.tablespaces) ? m.tablespaces.slice() : [];
    list.sort((a,b)=>num(b.pct_used)-num(a.pct_used));
    const top = list.slice(0,6);
    let rows = top.map(t=>{
        const p = num(t.pct_used);
        return `<div class="tsline"><div class="lab"><span>${t.name}</span><span>${p}%</span></div>
            <div class="bar"><span style="width:${Math.min(p,100)}%;background:${tsColor(p)}"></span></div></div>`;
    }).join('');
    if(!rows) rows = '<div class="muted">無資料</div>';
    const perf = kv('Buffer Hit', num(m.buffer_hit_pct)+'%') + kv('Library Hit', num(m.lib_cache_hit_pct)+'%') + kv('Temp 使用', num(m.temp_pct_used)+'%');
    return `<div class="panel full" data-block="ts"><h4>表空間使用率（Top 6）／效能</h4>
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
        <div>${rows}</div><div>${perf}</div></div></div>`;
}

function card(db){
    const s = statusOf(db);
    const stateText = !db.enabled ? '<span class="grey">停用</span>'
        : db.connected ? '<span class="green">● OPEN</span>'
        : '<span class="red">● DOWN</span>';
    let body;
    if(!db.enabled){
        body = `<div class="panel full"><div class="notconf">此資料庫已停用監控</div></div>`;
    } else if(!db.connected){
        body = `<div class="panel full"><h4>連線狀態</h4><div class="err">✖ 無法連線：${db.error||'未知錯誤'}</div></div>`;
    } else {
        const m = db.metrics || {};
        body = dgPanel(m) + mvPanel(m) + healthPanel(m) + tsPanel(m);
    }
    // 把此卡片已隱藏的區塊（warn 除外，warn 影響底部警示區）烘進 class，避免刷新閃爍
    const hideCls = (cardHidden[db.alias]||[]).filter(b=>b!=='warn').map(b=>'hide-'+b).join(' ');
    return `<div class="dbcard s-${s} ${hideCls}" data-alias="${db.alias}" draggable="true">
        <div class="dbhead">
            <span class="dot ${s}"></span>
            <div>
                <div class="title">${db.label||db.alias}</div>
                <div class="sub">${db.host||''}${db.port?':'+db.port:''}${db.sid?' / '+db.sid:''}</div>
            </div>
            <span class="state">${stateText}</span>
            <button class="card-gear" data-alias="${db.alias}" title="此卡片顯示設定">⚙</button>
        </div>
        <div class="panels">${body}</div>
    </div>`;
}

// --- 警示生成 ---
function isoDateFromHoursAgo(hrs){
    const d = new Date(Date.now() - hrs * 3600000);
    const p = n => String(n).padStart(2,'0');
    return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}`;
}

function generateWarnings(dbs){
    const warns = [];
    dbs.forEach(db=>{
        if(!db.enabled || !db.connected) return;
        if((cardHidden[db.alias]||[]).includes('warn')) return;   // 此卡片隱藏了警示說明
        const m = db.metrics || {};
        const label = db.label || db.alias;
        const sev = statusOf(db);

        const stale = num(m.mview_stale), broken = num(m.mview_jobs_broken), failed = num(m.mview_jobs_failed);
        const hrs = num(m.mview_oldest_hours);
        if(stale>0 || broken>0 || failed>0){
            const dateStr = hrs>0 ? isoDateFromHoursAgo(hrs) : '—';
            warns.push({label, sev:'red',
                msg:`Materialized View 刷新已停擺：${fmtInt(stale)} 個全 stale、${fmtInt(broken)} 個 refresh job 中斷／失敗、最舊刷新停在 ${dateStr}`});
        }

        const dgConf = num(m.dg_configured)===1;
        if(dgConf && num(m.dg_gap)>0)
            warns.push({label, sev:'red', msg:`Data Guard Archive Gap = ${num(m.dg_gap)}（Standby 端歸檔日誌缺口未同步）`});
        if(dgConf && num(m.dg_apply_lag_min)>15)
            warns.push({label, sev:'yellow', msg:`Data Guard Apply Lag = ${num(m.dg_apply_lag_min)} 分（超過 15 分鐘閾值）`});

        if(num(m.archivelog_mode)===0)
            warns.push({label, sev:'yellow', msg:'Archivelog 模式關閉（無法做時間點還原）'});

        const io = num(m.invalid_objects);
        if(io>0) warns.push({label, sev:'yellow', msg:`無效物件 ${fmtInt(io)} 個`});

        if(Array.isArray(m.tablespaces))
            m.tablespaces.filter(t=>num(t.pct_used)>=90).forEach(t=>
                warns.push({label, sev:'red', msg:`表空間 ${t.name} 使用率 ${Number(t.pct_used).toFixed(1)}%（≥ 90%）`}));
    });
    return warns;
}

function renderWarnings(warns){
    const sec = document.getElementById('warn-section');
    const list = document.getElementById('warn-list');
    if(!warns.length){ sec.style.display='none'; return; }
    sec.style.display='block';
    list.innerHTML = warns.map(w=>
        `<div class="warn-item warn-sev-${w.sev}">
            <span class="warn-host">${w.label}</span>
            <span class="warn-msg">${w.msg}</span>
        </div>`
    ).join('');
}

// --- 刷新間隔控制 ---
let refreshTimer = null;

function intervalLabel(secs){
    return secs < 60 ? `${secs} 秒` : `${secs/60} 分鐘`;
}

function setRefreshInterval(secs){
    if(refreshTimer) clearInterval(refreshTimer);
    refreshTimer = setInterval(refresh, secs * 1000);
}

document.getElementById('refresh-sel').addEventListener('change', function(){
    setRefreshInterval(parseInt(this.value));
    refresh();
});

async function refresh(){
    try{
        const r = await fetch('/oracle-dashboard-data.php', {headers:{'X-CSRF-Token':CSRF}});
        if(!r.ok){ document.getElementById('updated').innerHTML = '<span class="err">資料讀取失敗 HTTP '+r.status+'</span>'; return; }
        const j = await r.json();
        const dbs = j.dbs||[];
        lastDbs = dbs;
        const cnt = {red:0,yellow:0,green:0,grey:0};
        dbs.forEach(d=>cnt[statusOf(d)]++);
        document.getElementById('cnt-red').textContent = cnt.red;
        document.getElementById('cnt-yellow').textContent = cnt.yellow;
        document.getElementById('cnt-green').textContent = cnt.green;
        document.getElementById('cnt-grey').textContent = cnt.grey;
        const grid = document.getElementById('grid');
        grid.innerHTML = dbs.length ? dbs.map(card).join('') : '<div class="empty">尚無受監控的資料庫（請至「⚙ 監控管理」新增）</div>';
        applyCardOrder();   // 依伺服器存的順序重排卡片（body class 由 CSS 自動套用顯隱）
        const sel = document.getElementById('refresh-sel');
        document.getElementById('updated').textContent =
            '資料更新時間：' + (j.ts||'') + `（每 ${intervalLabel(parseInt(sel.value))} 自動刷新）`;
        renderWarnings(generateWarnings(dbs));
    }catch(e){
        document.getElementById('updated').innerHTML = '<span class="err">資料讀取錯誤：'+e.message+'</span>';
    }
}

// --- 版面偏好（每張卡片各自的區塊顯隱 + 卡片排序，全機共用伺服器設定）---
const BLOCKS = ['dg','mview','health','ts','warn'];
let cardOrder = [];     // alias 顯示順序
let cardHidden = {};    // {alias: [blocks]} 每張卡片隱藏的區塊
let menuAlias = null;   // 目前開啟卡片選單的 alias
let lastDbs = [];       // 最近一次資料（warn 切換時重繪警示用）

async function api(url, body){
    const r = await fetch(url, {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
        body:JSON.stringify(body)
    });
    return r.json();
}

function showToast(msg){
    let t = document.getElementById('__toast');
    if(!t){
        t = document.createElement('div');
        t.id = '__toast';
        t.style.cssText = 'position:fixed;bottom:30px;right:30px;background:#16213e;color:#cfe0ff;border:1px solid #2a3d6a;border-radius:6px;padding:10px 16px;font-size:13px;z-index:50;opacity:0;transition:opacity .3s';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t.__t);
    t.__t = setTimeout(()=>{ t.style.opacity='0'; }, 2200);
}

// 即時把某卡片的隱藏狀態套到該卡片 DOM（warn 除外，warn 走底部警示區重繪）
function applyHiddenToCard(alias){
    const card = document.querySelector('#grid .dbcard[data-alias="'+alias+'"]');
    if(!card) return;
    const hid = cardHidden[alias] || [];
    BLOCKS.forEach(b=>{
        if(b==='warn') return;
        card.classList.toggle('hide-'+b, hid.includes(b));
    });
}

function readDomOrder(){
    return Array.from(document.getElementById('grid').querySelectorAll('.dbcard')).map(c=>c.dataset.alias);
}

// 依 cardOrder 重排；未記錄的（新主機）保持原相對順序排最後
function applyCardOrder(){
    if(!cardOrder.length) return;
    const grid = document.getElementById('grid');
    const cards = Array.from(grid.querySelectorAll('.dbcard'));
    if(!cards.length) return;
    const byAlias = {};
    cards.forEach(c=>{ byAlias[c.dataset.alias] = c; });
    const ordered = [];
    cardOrder.forEach(a=>{ if(byAlias[a]){ ordered.push(byAlias[a]); delete byAlias[a]; } });
    cards.forEach(c=>{ if(byAlias[c.dataset.alias]) ordered.push(c); });
    ordered.forEach(c=>grid.appendChild(c));
}

async function saveLayout(orderOverride){
    cardOrder = (orderOverride !== undefined) ? orderOverride : readDomOrder();
    try{
        const r = await api('/oracle-dashboard-layout.php', {action:'set', hidden:cardHidden, order:cardOrder});
        showToast(r && r.ok ? '版面已儲存' : '儲存失敗：'+((r&&r.error)||'未知'));
    }catch(e){ showToast('儲存失敗：'+e.message); }
}

async function loadLayout(){
    try{
        const r = await api('/oracle-dashboard-layout.php', {action:'get'});
        if(r && r.ok){
            cardHidden = (r.hidden && typeof r.hidden==='object' && !Array.isArray(r.hidden)) ? r.hidden : {};
            cardOrder  = Array.isArray(r.order) ? r.order : [];
        }
    }catch(e){ /* 取不到就用預設（全顯示、原順序）*/ }
}

// --- 拖拉排序（原生 HTML5 DnD，事件委派在穩定的 #grid，撐得過 innerHTML 重建）---
let dragEl = null;
const gridEl = document.getElementById('grid');
gridEl.addEventListener('dragstart', e=>{
    const c = e.target.closest('.dbcard'); if(!c) return;
    dragEl = c; c.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
});
gridEl.addEventListener('dragend', ()=>{
    if(dragEl) dragEl.classList.remove('dragging');
    gridEl.querySelectorAll('.drag-over').forEach(c=>c.classList.remove('drag-over'));
    dragEl = null;
});
gridEl.addEventListener('dragover', e=>{
    if(!dragEl) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
});
gridEl.addEventListener('dragenter', e=>{
    const c = e.target.closest('.dbcard');
    if(c && c!==dragEl) c.classList.add('drag-over');
});
gridEl.addEventListener('dragleave', e=>{
    const c = e.target.closest('.dbcard');
    if(c && !c.contains(e.relatedTarget)) c.classList.remove('drag-over');
});
gridEl.addEventListener('drop', e=>{
    e.preventDefault();
    const target = e.target.closest('.dbcard');
    if(!dragEl || !target || target===dragEl) return;
    target.classList.remove('drag-over');
    const cards = Array.from(gridEl.querySelectorAll('.dbcard'));
    const from = cards.indexOf(dragEl), to = cards.indexOf(target);
    gridEl.insertBefore(dragEl, from < to ? target.nextSibling : target);
    saveLayout();
});

// --- topbar 全域設定面板（只剩重設順序 + 說明）---
const settingsBtn = document.getElementById('settings-btn');
const settingsPop = document.getElementById('settings-pop');
const cardPop = document.getElementById('card-pop');

settingsBtn.addEventListener('click', e=>{
    e.stopPropagation();
    cardPop.classList.remove('open');
    settingsPop.classList.toggle('open');
});
document.getElementById('reset-order').addEventListener('click', async ()=>{
    // 全部還原：清掉每卡片隱藏 + 卡片順序，存檔後立即重繪（不必等下次刷新）
    cardHidden = {};
    cardOrder = [];
    settingsPop.classList.remove('open');
    await saveLayout([]);   // 送 hidden={}、order=[]
    await refresh();        // 立即以「全部顯示 + 預設順序」重繪
});

// --- 每張卡片的 ⚙：開啟浮動選單並載入該卡片狀態（事件委派於穩定的 #grid）---
gridEl.addEventListener('click', e=>{
    const g = e.target.closest('.card-gear');
    if(!g) return;
    e.stopPropagation();
    menuAlias = g.dataset.alias;
    const hid = cardHidden[menuAlias] || [];
    // checkbox 勾選 = 顯示，故 checked = 「未隱藏」
    cardPop.querySelectorAll('.blk-toggle').forEach(cb=>{ cb.checked = !hid.includes(cb.value); });
    const titleEl = document.querySelector('#grid .dbcard[data-alias="'+menuAlias+'"] .title');
    document.getElementById('card-pop-title').textContent = (titleEl?titleEl.textContent:menuAlias) + ' — 顯示區塊';
    // 浮動定位到齒輪下方（fixed）
    const rect = g.getBoundingClientRect();
    cardPop.style.top = (rect.bottom + 6) + 'px';
    let left = rect.right - 230;          // 對齊右緣，min-width 230
    if(left < 8) left = 8;
    cardPop.style.left = left + 'px';
    settingsPop.classList.remove('open');
    cardPop.classList.add('open');
});

// card-pop 勾選 → 更新該卡片 cardHidden、即時套用、存檔
cardPop.querySelectorAll('.blk-toggle').forEach(cb=>{
    cb.addEventListener('change', function(){
        if(!menuAlias) return;
        const set = new Set(cardHidden[menuAlias] || []);
        if(this.checked) set.delete(this.value); else set.add(this.value);
        if(set.size) cardHidden[menuAlias] = Array.from(set);
        else delete cardHidden[menuAlias];
        if(this.value==='warn') renderWarnings(generateWarnings(lastDbs));
        else applyHiddenToCard(menuAlias);
        saveLayout();
    });
});

// 點空白處關閉兩個選單
document.addEventListener('click', e=>{
    if(settingsPop.classList.contains('open') && !settingsPop.contains(e.target) && e.target!==settingsBtn)
        settingsPop.classList.remove('open');
    if(cardPop.classList.contains('open') && !cardPop.contains(e.target) && !e.target.closest('.card-gear'))
        cardPop.classList.remove('open');
});

// --- 啟動：先載入版面偏好，再開始刷新 ---
(async function init(){
    await loadLayout();
    await refresh();
    setRefreshInterval(60);
})();
</script>
</body>
</html>
