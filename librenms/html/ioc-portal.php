<?php
/**
 * ioc-portal.php — 智慧維運與資安態勢中心（中文入口）
 * URL: http://<monitor-vm>/ioc-portal.php[?demo=1]
 *
 * 需登入，角色為 admin（可處理事件案）或 global-read（唯讀）。資料來自 ioc-data.php 與 ioc-cases.php，
 * 權限在後端檢查；這個頁面只決定「要不要顯示按鈕」，不是權限控管（IS-013）。
 * 日誌內容都用 textContent 放進畫面，不用 innerHTML，避免日誌裡的惡意內容變成 XSS。
 * 版面（左側導覽、頂列、總覽的區塊）參照提案簡報，整理見 docs/ioc/gui-from-proposal-deck.md。
 */

$init_modules = ['web', 'auth'];
require realpath(__DIR__ . '/..') . '/includes/init.php';
require realpath(__DIR__ . '/..') . '/includes/ioc/bootstrap.php';

if (!Auth::check()) {
    header('Location: /');
    exit;
}
$level = Ioc\Access::level(Auth::user()->hasRole('admin'), Auth::user()->hasRole('global-read'));
if (!Ioc\Access::canView($level)) {
    header('Location: /');
    exit;
}
$demo = ($_GET['demo'] ?? '') === '1';

// 這個頁面有會改資料的按鈕（結案等）：不允許被別的網站用透明 iframe 蓋住誘導點擊（clickjacking）
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");

$config = json_encode([
    'level'  => $level,
    'user'   => (string) Auth::user()->username,
    'csrf'   => csrf_token(),
    'demo'   => $demo,
    'labels' => [
        'caseStatus'   => Ioc\Labels::CASE_STATUS,
        'caseSeverity' => Ioc\Labels::CASE_SEVERITY,
        'resolution'   => Ioc\Labels::RESOLUTION,
        'siteLevel'    => Ioc\Fleet::LEVELS,
        'riskLevel'    => Ioc\RiskScore::LEVEL_LABEL,
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>智慧維運與資安態勢中心</title>
<style>
:root{--bg:#07111f;--panel:#0f1c2e;--panel2:#132238;--line:#203551;--text:#e7eef8;--muted:#8fa4bf;
  --green:#36d399;--yellow:#f5c451;--orange:#ff9f43;--red:#ff6b6b;--blue:#61a8ff;--gray:#778ba6}
*{box-sizing:border-box}
[hidden]{display:none!important}   /* 作者樣式的 display 會蓋掉瀏覽器內建的 [hidden]，角標就藏不起來 */
body{margin:0;background:radial-gradient(circle at 10% 0%,rgba(35,84,160,.24),transparent 32%),var(--bg);color:var(--text);
  font:14px/1.6 "Noto Sans TC","Microsoft JhengHei",system-ui,sans-serif}
a{color:var(--blue)}
.app{display:flex;min-height:100vh}
.side{width:212px;flex:none;position:sticky;top:0;height:100vh;overflow:auto;padding:16px 10px;background:linear-gradient(180deg,#0b1a2e,#08121f);border-right:1px solid var(--line)}
.brand{padding:4px 10px 14px;font-weight:800;font-size:15px;letter-spacing:.3px}
.brand small{display:block;color:var(--muted);font-weight:400;font-size:11px;letter-spacing:0}
.nav{display:flex;flex-direction:column;gap:4px}
.nav button{display:flex;justify-content:space-between;align-items:center;gap:8px;width:100%;text-align:left;font:inherit;color:#c3d2e6;background:transparent;border:1px solid transparent;border-radius:10px;padding:8px 12px;cursor:pointer}
.nav button:hover:not([disabled]){background:rgba(97,168,255,.08)}
.nav button[aria-current=page]{color:#fff;background:var(--panel2);border-color:var(--blue)}
.nav button[disabled]{color:#5f748f;cursor:not-allowed}
.nav .tag{font-size:10px;color:#5f748f;border:1px solid #2a4060;border-radius:999px;padding:0 6px}
.nav .sep{margin:10px 12px 4px;color:#5f748f;font-size:11px}
.main{flex:1;min-width:0;padding:18px;max-width:1700px}
.topbar{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:14px 20px;border:1px solid var(--line);border-radius:16px;background:linear-gradient(180deg,#111f32,#0a1727)}
.topbar h1{margin:0;font-size:20px;letter-spacing:.3px}
.topbar p{margin:2px 0 0;color:var(--muted);font-size:13px}
.chips{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.chip{padding:5px 11px;border:1px solid var(--line);border-radius:999px;background:#0a1728;color:#c8d6e7;font-size:12px;white-space:nowrap}
.chip b{color:#fff}
.chip.demo{border-color:var(--yellow);color:var(--yellow)}
.bell{position:relative;display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:1px solid var(--line);border-radius:50%;background:#0a1728;color:#c8d6e7;cursor:pointer;padding:0}
.bell:hover{border-color:var(--blue)}
.bell svg{width:18px;height:18px;fill:currentColor}
.bell .n{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;padding:0 4px;border-radius:999px;background:var(--red);color:#fff;font-size:11px;line-height:18px;text-align:center}
.badge-n{display:inline-block;min-width:20px;padding:0 6px;border-radius:999px;background:var(--red);color:#fff;font-size:11px;text-align:center}
.banner{margin:10px 0;padding:10px 14px;border:1px solid var(--yellow);border-radius:10px;background:rgba(245,196,81,.08);color:#ffe49d}
.banner.err{border-color:var(--red);background:rgba(255,107,107,.08);color:#ffc0c0}
.grid{display:grid;gap:14px;margin-top:14px}
.kpis6{grid-template-columns:repeat(6,minmax(0,1fr))}
.kpis2{grid-template-columns:repeat(2,minmax(0,1fr))}
.two{grid-template-columns:1.25fr .75fr}
.three{grid-template-columns:minmax(0,.85fr) minmax(0,1.45fr) minmax(0,1fr)}
.even{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
.card{border:1px solid var(--line);border-radius:14px;background:linear-gradient(180deg,#122238,#0c192b);overflow:hidden;min-width:0}
.card-h{padding:12px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:10px;align-items:baseline}
.card-h h2{margin:0;font-size:14px}
.card-h span{color:var(--muted);font-size:11px}
.card-b{padding:12px 16px;overflow-x:auto}
.kpi{padding:14px 16px;min-height:112px}
.kpi .l{font-size:12px;color:var(--muted)}
.kpi .v{font-size:28px;font-weight:800;margin:6px 0 4px;letter-spacing:-.5px}
.kpi .s{font-size:12px;color:#9db1ca}
.good .v{color:var(--green)}.warn .v{color:var(--yellow)}.bad .v{color:var(--red)}.unknown .v{color:var(--gray)}
.unknown .v{font-size:20px}
table{width:100%;border-collapse:collapse;font-size:12px}
th{text-align:left;color:#87a0bd;font-weight:600;padding:8px;border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:9px 8px;border-bottom:1px solid rgba(32,53,81,.65);vertical-align:middle}
tbody tr:hover{background:rgba(97,168,255,.05)}
tr.click{cursor:pointer}
tr.click:focus-visible,.btn:focus-visible,.nav button:focus-visible,.bell:focus-visible,select:focus-visible,input:focus-visible,textarea:focus-visible{outline:2px solid var(--blue);outline-offset:1px}
.badge{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;font-size:11px;border:1px solid transparent;white-space:nowrap}
.badge::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.b-green{color:#aaf2d6;border-color:rgba(54,211,153,.3);background:rgba(54,211,153,.08)}
.b-yellow{color:#ffe49d;border-color:rgba(245,196,81,.3);background:rgba(245,196,81,.08)}
.b-orange{color:#ffd0a0;border-color:rgba(255,159,67,.35);background:rgba(255,159,67,.08)}
.b-red{color:#ffc0c0;border-color:rgba(255,107,107,.35);background:rgba(255,107,107,.08)}
.b-gray{color:#b7c4d5;border-color:#425671;background:rgba(128,145,170,.08)}
.b-blue{color:#c8e0ff;border-color:rgba(97,168,255,.34);background:rgba(97,168,255,.08)}
.note{color:var(--muted);font-size:12px;margin:8px 0 0}
.note.warn{color:#ffe49d}
.alert{padding:10px 12px;border:1px dashed var(--red);border-radius:10px;color:#ffc0c0;background:rgba(255,107,107,.06);font-size:12px}
.row{display:flex;gap:10px;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(32,53,81,.55)}
.row:last-child{border-bottom:0}
.row .t{font-weight:600;font-size:13px}
.row .d{color:var(--muted);font-size:12px}
.btn{font:inherit;font-size:12px;color:#dfeafa;background:#0c1a2b;border:1px solid var(--line);border-radius:8px;padding:5px 11px;cursor:pointer}
.btn:hover{border-color:var(--blue)}
.btn.primary{background:#1b4a86;border-color:var(--blue);color:#fff}
.btn.danger{border-color:var(--red);color:#ffc0c0}
.btn[disabled]{opacity:.5;cursor:not-allowed}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
select,input,textarea{font:inherit;font-size:13px;color:#dfeafa;background:#0c1a2b;border:1px solid var(--line);border-radius:8px;padding:6px 9px;max-width:100%}
textarea{width:100%;min-height:70px}
label{display:block;font-size:12px;color:var(--muted);margin:10px 0 3px}
.split{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:14px}
.kv{display:grid;grid-template-columns:7em 1fr;gap:4px 10px;font-size:13px}
.kv dt{color:var(--muted)}.kv dd{margin:0;word-break:break-word}
code{background:#0a1423;border:1px solid var(--line);border-radius:6px;padding:2px 6px;font-size:12px;word-break:break-all}
.tl{list-style:none;margin:0;padding:0}
.tl li{padding:8px 0 8px 14px;border-left:2px solid var(--line);position:relative;font-size:13px}
.tl li::before{content:"";position:absolute;left:-5px;top:14px;width:8px;height:8px;border-radius:50%;background:var(--blue)}
.tl .when{color:var(--muted);font-size:11px}
.gauge{display:block;width:100%;max-width:240px;margin:0 auto}
.g-score{font-size:34px;font-weight:800;fill:#fff}
.g-sub{font-size:11px;fill:var(--muted)}
.g-level{text-align:center;font-weight:700;margin:2px 0 10px}
.part{margin:8px 0}
.part .pl{display:flex;justify-content:space-between;font-size:12px;color:#c8d6e7}
.bar{height:6px;margin-top:3px;background:#0a1423;border-radius:3px;overflow:hidden}
.bar i{display:block;height:100%;border-radius:3px}
.chart{display:block;width:100%;height:auto}
.chart text{font-size:10px;fill:var(--muted)}
.legend{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:#c8d6e7;margin-top:6px}
.legend i{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:5px;vertical-align:-1px}
.counts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin-bottom:8px}
.counts .cnt{padding:6px 8px;border:1px solid var(--line);border-radius:10px;background:#0a1728;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.counts .cnt b{display:block;font-size:20px;color:#fff}
.rank{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid rgba(32,53,81,.55)}
.rank:last-child{border-bottom:0}
.rank .no{width:22px;height:22px;border-radius:50%;background:#0a1728;border:1px solid var(--line);text-align:center;font-size:12px;line-height:20px;flex:none}
.rank .nm{flex:1;min-width:0;font-weight:600;font-size:13px;word-break:break-word}
.toast{position:fixed;right:16px;bottom:16px;max-width:420px;padding:10px 14px;border-radius:10px;background:#1b4a86;color:#fff;display:none;z-index:9}
.toast.err{background:#8a2b2b}
footer{margin:18px 0 6px;text-align:center;color:#7187a4;font-size:11px}
@media(max-width:1280px){.kpis6{grid-template-columns:repeat(3,minmax(0,1fr))}.three{grid-template-columns:1fr}}
@media(max-width:1180px){.two,.even,.split{grid-template-columns:1fr}}
@media(max-width:900px){.app{flex-direction:column}.side{position:static;width:auto;height:auto;padding:10px}.nav{flex-direction:row;overflow-x:auto}.nav button{white-space:nowrap;width:auto}.nav .sep{display:none}.main{padding:10px}}
@media(max-width:720px){.kpis6,.kpis2{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand">IOC 智慧維運中心<small>智慧維運與資安態勢</small></div>
    <nav class="nav" id="nav" aria-label="功能"></nav>
  </aside>
  <div class="main">
    <header class="topbar">
      <div>
        <h1 id="page-title">維運總覽</h1>
        <p id="page-sub"></p>
      </div>
      <div class="chips" id="chips"></div>
    </header>
    <div id="banner"></div>
    <main id="view-overview"></main>
    <main id="view-cases" hidden></main>
    <main id="view-security" hidden></main>
    <main id="view-settings" hidden></main>
    <footer>日誌來自 Graylog（唯讀帳號），設備與站點（LibreNMS 的「位置」）來自 LibreNMS，事件案存在 LibreNMS 資料庫。灰色的「尚未提供」項目還沒有資料來源，不會顯示假數字。</footer>
  </div>
</div>
<div class="toast" id="toast" role="status"></div>

<script type="application/json" id="ioc-config"><?= $config ?></script>
<script>
(() => {
  'use strict';
  const CFG = JSON.parse(document.getElementById('ioc-config').textContent);
  const CAN_HANDLE = CFG.level === 'handler';
  const REFRESH_MS = 60000;
  const state = { tab: 'overview', filter: 'open', selected: null, form: null, overview: null, loadedAt: 0, overviewError: null, summary: null, refreshSettingsPreview: null };

  // 左側導覽。off＝簡報有、但目前沒有資料來源的項目：顯示成停用，不能點進去看到空白或假數字
  const NAV = [
    { key: 'overview', label: '總覽', title: '維運總覽', sub: '站點、資產、事件與資料來源一覽' },
    { key: 'cases', label: '事件案', title: '事件案管理', sub: '建立、確認、處理與結案，每一步都有紀錄', badge: true },
    { key: 'security', label: '資安', title: '資安事件', sub: '登入、帳號與防火牆阻擋' },
    { key: 'settings', label: '設定', title: '系統設定', sub: '風險等級門檻' },
    { sep: '尚未提供' },
    { key: 'risk', label: '風險與弱點', off: true },
    { key: 'config', label: '組態治理', off: true },
    { key: 'audit', label: '稽核與證據', off: true },
    { key: 'reports', label: '報表', off: true },
  ];
  const PAGES = NAV.filter((n) => n.key && !n.off).map((n) => n.key);

  // ── 小工具：一律用 DOM 與 textContent 建立節點，資料不經過 innerHTML ──
  const h = (tag, attrs, ...kids) => {
    const e = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === false || v == null) continue;
      if (k === 'class') e.className = v;
      else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
      else e.setAttribute(k, v === true ? '' : String(v));
    }
    for (const c of kids.flat(Infinity)) {
      if (c === null || c === undefined || c === false) continue;
      e.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return e;
  };
  const SVG_NS = 'http://www.w3.org/2000/svg';
  const sv = (tag, attrs, ...kids) => {
    const e = document.createElementNS(SVG_NS, tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (v === false || v == null) continue;
      e.setAttribute(k, String(v));
    }
    for (const c of kids.flat(Infinity)) {
      if (c === null || c === undefined || c === false) continue;
      e.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return e;
  };
  const $ = (sel) => document.querySelector(sel);
  const badge = (text, cls) => h('span', { class: 'badge b-' + cls }, text);
  const levelBadge = (label) => badge(label, { '高': 'red', '中': 'yellow', '低': 'green' }[label] || 'gray');
  const stateOf = { ok: 'green', delayed: 'yellow', stale: 'red', none: 'gray', unknown: 'gray' };
  const STATUS_COLOR = { new: 'red', ack: 'yellow', in_progress: 'blue', closed: 'gray' };
  const LEVEL_TO_SEVERITY = { high: 'high', medium: 'medium', low: 'low' };
  // 站點異常等級（離線設備的比例）：正常、輕微、中度、嚴重
  const SITE_COLOR = { normal: '#36d399', minor: '#f5c451', moderate: '#ff9f43', severe: '#ff6b6b' };
  const SITE_BADGE = { normal: 'green', minor: 'yellow', moderate: 'orange', severe: 'red' };
  const SITE_LABEL = CFG.labels.siteLevel;     // 名稱由伺服器（Fleet::LEVELS）提供，畫面不另外寫一份
  const RISK_COLOR = { low: '#36d399', medium: '#f5c451', high: '#ff6b6b' };
  const fmt = (n) => Number(n).toLocaleString('zh-TW');
  const luceneQ = (field, value) => field + ':"' + String(value).replace(/[\\"]/g, '\\$&') + '"';
  const pct = (n) => Math.max(0, Math.min(100, Number(n) || 0));   // 長條寬度只吃數字，不吃字串

  let toastTimer = null;
  function toast(msg, isErr) {
    const t = $('#toast');
    t.textContent = msg;
    t.className = 'toast' + (isErr ? ' err' : '');
    t.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.style.display = 'none'; }, isErr ? 7000 : 3000);
  }

  async function api(url, body) {
    const res = await fetch(url, {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: Object.assign({ 'X-CSRF-Token': CFG.csrf }, body ? { 'Content-Type': 'application/json' } : {}),
      body: body ? JSON.stringify(body) : undefined,
    });
    let data = null;
    try { data = await res.json(); } catch (e) { /* 不是 JSON */ }
    if (!res.ok) {
      const err = new Error((data && data.error) || ('伺服器回應 HTTP ' + res.status));
      err.errors = data && data.errors;
      throw err;
    }
    return data;
  }

  // ── 外框：左側導覽與頂列 ──
  // 導覽與頂列只建立一次，之後只就地更新文字與屬性。整個重建（replaceChildren）會讓鍵盤焦點掉光：
  // 頂列每秒更新一次、導覽每次切頁與每分鐘重新整理都會發生，鍵盤使用者就按不到鈴鐺與導覽按鈕。
  const navEls = {};
  const top = {};

  function buildNav() {
    $('#nav').replaceChildren(...NAV.map((n) => {
      if (n.sep) return h('div', { class: 'sep' }, n.sep);
      if (n.off) return h('button', { type: 'button', disabled: true, 'aria-disabled': 'true', 'aria-label': n.label + '（尚未提供）', title: '目前沒有這項的資料來源，尚未提供' }, n.label, h('span', { class: 'tag' }, '尚未提供'));
      const badgeEl = n.badge ? h('span', { class: 'badge-n', hidden: true }) : null;
      const btn = h('button', { type: 'button', onclick: () => setTab(n.key) }, n.label, badgeEl);
      navEls[n.key] = { btn, badgeEl };
      return btn;
    }));
  }

  function renderNav() {
    for (const [key, el] of Object.entries(navEls)) {
      if (state.tab === key) el.btn.setAttribute('aria-current', 'page'); else el.btn.removeAttribute('aria-current');
      if (el.badgeEl) {
        const open = state.summary ? state.summary.open : 0;
        el.badgeEl.hidden = open <= 0;
        el.badgeEl.textContent = open > 0 ? String(open) : '';
      }
    }
  }

  function buildTopbar() {
    top.updated = h('b', null, '—');
    top.age = document.createTextNode('');
    top.bellN = h('span', { class: 'n', hidden: true });
    top.bell = h('button', { class: 'bell', type: 'button', onclick: () => openCases('new') },
      sv('svg', { viewBox: '0 0 24 24', 'aria-hidden': 'true' }, sv('path', { d: 'M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4a1.5 1.5 0 0 0-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z' })),
      top.bellN);
    // 注意：replaceChildren(null) 會把 null 當成文字「null」插進去，所以要先濾掉
    $('#chips').replaceChildren(...[
      CFG.demo ? h('span', { class: 'chip demo' }, '範例資料模式') : null,
      h('span', { class: 'chip' }, '更新：', top.updated, top.age),
      h('span', { class: 'chip' }, h('b', null, CFG.user), '　', CAN_HANDLE ? '處理者' : '檢視者（唯讀）'),
      top.bell,
      h('a', { href: '/' }, '返回 LibreNMS'),
    ].filter(Boolean));
  }

  function renderTopbar() {
    const ov = state.overview;
    const page = NAV.find((n) => n.key === state.tab) || NAV[0];
    $('#page-title').textContent = page.title;
    $('#page-sub').textContent = page.sub;
    top.updated.textContent = ov ? ov.meta.generated_at : '—';
    top.age.textContent = state.loadedAt ? '（' + Math.round((Date.now() - state.loadedAt) / 1000) + ' 秒前）' : '';
    const fresh = state.summary ? state.summary.new : 0;
    top.bellN.hidden = fresh <= 0;
    top.bellN.textContent = fresh > 0 ? String(fresh) : '';
    top.bell.title = fresh > 0 ? '有 ' + fresh + ' 件待確認的事件案' : '沒有待確認的事件案';
    top.bell.setAttribute('aria-label', '待確認的事件案 ' + fresh + ' 件');
  }

  function setTab(tab, force) {
    if (!PAGES.includes(tab)) tab = 'overview';
    const changed = state.tab !== tab;
    state.tab = tab;
    PAGES.forEach((p) => { $('#view-' + p).hidden = p !== tab; });
    try { history.replaceState(null, '', location.pathname + location.search + '#' + tab); } catch (e) { /* 沒有也無妨 */ }
    renderNav(); renderTopbar();
    // 已經在事件案頁籤時再按一次不要重載，否則填到一半的表單會被清掉
    if (tab === 'cases' && (changed || force || !$('#case-right'))) { loadCases(); }
    // 設定頁每次進來都重新讀：別的管理者可能剛改過；已經在這一頁時再按導覽不重載，免得填到一半的值被清掉
    if (tab === 'settings' && changed) { loadSettings(); }
  }

  /** 開事件案頁並套用狀態篩選（鈴鐺用）。 */
  function openCases(filter) {
    state.filter = filter;
    state.form = null;
    state.selected = null;
    setTab('cases', true);
  }

  function renderBanner() {
    const parts = [];
    if (CFG.demo) {
      parts.push(h('div', { class: 'banner' }, '目前顯示的是範例資料（重播的測試樣本），不是正式資料。',
        h('a', { href: location.pathname }, '切換到正式資料')));
    }
    if (state.overviewError) {
      const stale = state.overview ? '，以下是 ' + Math.round((Date.now() - state.loadedAt) / 1000) + ' 秒前的資料，可能已過期' : '';
      parts.push(h('div', { class: 'banner err' }, '無法更新總覽：' + state.overviewError + stale));
    }
    $('#banner').replaceChildren(...parts);
  }

  // ── 共用區塊 ──
  const unavailable = (msg) => h('div', { class: 'alert' }, '無法取得：' + msg);
  // 「沒有事件」要說清楚是真的沒事，還是根本沒有日誌（登入類來源沒接入就沒有日誌，不能讓人以為沒事）
  const emptyNote = (base) => {
    const s = state.overview && state.overview.login_sources;
    if (!s || !s.missing.length) return base;
    if (s.all_missing) return '尚未接入任何登入類來源（' + s.missing.join('、') + '），沒有資料可顯示，不代表沒有事件。';
    return base + '（' + s.missing.join('、') + '尚未接入，不含其事件）';
  };
  const section = (title, hint, body) => h('div', { class: 'card' },
    h('div', { class: 'card-h' }, h('h2', null, title), h('span', null, hint)), h('div', { class: 'card-b' }, body));
  const table = (heads, rows) => h('table', null,
    h('thead', null, h('tr', null, heads.map((x) => h('th', null, x)))),
    h('tbody', null, rows.map((r) => h('tr', null, r.map((c) => h('td', null, c))))));

  function kpiCard(k) {
    const unknown = k.value === null || k.value === undefined;
    return h('div', { class: 'card kpi ' + k.state },
      h('div', { class: 'l' }, k.label),
      h('div', { class: 'v' }, unknown ? (k.na || '無法取得') : String(k.value)),
      h('div', { class: 's' }, k.sub));
  }

  function renderActivity(sec) {
    if (!sec.ok) return unavailable(sec.error);
    if (!sec.data.length) return h('p', { class: 'note' }, emptyNote('這段期間沒有符合條件的事件。'));
    return table(['時間', '使用者', '發生狀況', '來源位址', '設備名稱', '來源類型', '需注意程度'],
      sec.data.map((r) => [r.time, r.user || '—', r.action, r.src_ip || '—', r.host || '—', r.type, levelBadge(r.level)]));
  }

  function renderCoverage(sec) {
    if (!sec.ok) return unavailable(sec.error);
    const labels = { ok: '正常接收', delayed: '延遲', stale: '中斷', none: '待接入', unknown: '無法判斷' };
    return table(['系統類型', '目前狀態', '最後收到資料', '24 小時筆數'],
      sec.data.map((c) => [c.label, badge(labels[c.status], stateOf[c.status]), c.last, c.status === 'none' ? '—' : fmt(c.count)]));
  }

  function createButton(prefill) {
    if (!CAN_HANDLE) return null;
    return h('button', { class: 'btn', type: 'button', onclick: () => { state.form = prefill; state.selected = null; setTab('cases'); } }, '建立事件案');
  }

  function renderAttention(accounts, denied) {
    const blocks = [];
    blocks.push(h('h3', { class: 'note' }, '可疑登入的帳號與來源'));
    if (!accounts.ok) blocks.push(unavailable(accounts.error));
    else if (!accounts.data.items.length) blocks.push(h('p', { class: 'note' }, emptyNote('沒有可疑的登入事件。')));
    else {
      accounts.data.items.forEach((a) => blocks.push(h('div', { class: 'row' },
        h('div', null, h('div', { class: 't' }, a.user + (a.src_ip ? ' · ' + a.src_ip : '')), h('div', { class: 'd' }, '24 小時內 ' + fmt(a.count) + ' 次可疑事件')),
        h('div', { class: 'row' }, levelBadge({ high: '高', medium: '中', low: '低' }[a.level]),
          createButton({ title: '可疑登入：' + a.user + (a.src_ip ? '（' + a.src_ip + '）' : ''), severity: LEVEL_TO_SEVERITY[a.level],
            subject_user: a.user, subject_ip: a.src_ip,
            evidence_query: [luceneQ('user_name', a.user), a.src_ip ? luceneQ('src_ip', a.src_ip) : null].filter(Boolean).join(' AND ') })))));
      if (accounts.data.capped) blocks.push(h('p', { class: 'note warn' }, '資料量超過查詢上限，實際筆數可能更多。'));
    }
    blocks.push(h('h3', { class: 'note', style: 'margin-top:14px' }, '被防火牆阻擋的內部來源'));
    if (!denied.ok) blocks.push(unavailable(denied.error));
    else {
      const d = denied.data;
      if (!d.internal.length) blocks.push(h('p', { class: 'note' }, '沒有內部來源被阻擋。'));
      d.internal.forEach((s) => blocks.push(h('div', { class: 'row' },
        h('div', null, h('div', { class: 't' }, s.src_ip), h('div', { class: 'd' }, '被阻擋 ' + fmt(s.count) + ' 次')),
        createButton({ title: '防火牆持續阻擋內部來源 ' + s.src_ip, severity: 'medium', subject_ip: s.src_ip,
          evidence_query: 'event_action:fw_deny AND ' + luceneQ('src_ip', s.src_ip) }))));
      blocks.push(h('p', { class: 'note' }, '外部來源共 ' + fmt(d.external_events) + ' 筆阻擋（網際網路的掃描雜訊，已彙總、不逐一列出）；廣播與多播已排除。'));
      if (d.capped) blocks.push(h('p', { class: 'note warn' }, '來源數超過查詢上限，數字可能不只這些。'));
    }
    return blocks;
  }

  function renderDevices(sec) {
    if (!sec.ok) return unavailable(sec.error);
    const d = sec.data;
    if (!d.down_list.length) return h('p', { class: 'note' }, d.total + ' 台設備全部正常。');
    const rows = d.down_list.map((x) => h('div', { class: 'row' },
      h('div', null, h('div', { class: 't' }, x.name), h('div', { class: 'd' }, '最後輪詢 ' + (x.last_polled || '—') + (x.reason ? ' · ' + x.reason : ''))),
      badge('異常', 'red')));
    if (d.total - d.up > d.down_list.length) rows.push(h('p', { class: 'note warn' }, '只列出前 ' + d.down_list.length + ' 台。'));
    return rows;
  }

  // ── 總覽的新區塊（簡報「地政資訊環境總覽」）──
  function gauge(score, color) {
    const cx = 90, cy = 90, r = 70;
    const pt = (deg) => [cx + r * Math.cos(deg * Math.PI / 180), cy - r * Math.sin(deg * Math.PI / 180)];
    const arc = (from, to) => {
      const a = pt(from), b = pt(to);
      return 'M' + a[0].toFixed(1) + ' ' + a[1].toFixed(1) + ' A' + r + ' ' + r + ' 0 0 1 ' + b[0].toFixed(1) + ' ' + b[1].toFixed(1);
    };
    const end = Math.max(0.001, 180 - 1.8 * pct(score));    // 左端 180 度走到右端 0 度
    return sv('svg', { viewBox: '0 0 180 108', class: 'gauge', role: 'img', 'aria-label': '整體風險分數 ' + score + ' 分（滿分 100，越高越好）' },
      sv('path', { d: arc(180, 0.001), fill: 'none', stroke: '#203551', 'stroke-width': 14, 'stroke-linecap': 'round' }),
      score > 0 ? sv('path', { d: arc(180, end), fill: 'none', stroke: color, 'stroke-width': 14, 'stroke-linecap': 'round' }) : null,
      sv('text', { x: cx, y: 84, 'text-anchor': 'middle', class: 'g-score' }, String(score)),
      sv('text', { x: cx, y: 102, 'text-anchor': 'middle', class: 'g-sub' }, '/ 100'));
  }

  function renderRisk(sec) {
    if (!sec.ok) return unavailable(sec.error);
    const d = sec.data;
    const color = RISK_COLOR[d.level] || '#778ba6';
    const parts = Object.values(d.parts).map((p) => h('div', { class: 'part' },
      h('div', { class: 'pl' }, h('span', null, p.label + '（權重 ' + p.weight + '）'), h('span', null, p.score === null ? '無資料' : p.score + ' 分')),
      h('div', { class: 'bar' }, p.score === null ? null : h('i', { style: 'width:' + pct(p.score) + '%;background:' + color }))));
    return [
      gauge(d.score, color),
      h('div', { class: 'g-level', style: 'color:' + color }, d.level_label || '無法判定等級'),
      ...parts,
      d.missing.length ? h('p', { class: 'note warn' }, d.missing.join('、') + '沒有可用的資料，分數只算其他分項。') : null,
      d.warning ? h('p', { class: 'note warn' }, d.warning) : null,
      // 門檻是使用者在「設定」頁自己調的，數字一定要用伺服器算等級時用的那組，不能在畫面另外寫死
      h('p', { class: 'note' }, '提案版公式：各分項加權平均，分數越高越好'
        + (d.thresholds ? '；≥' + d.thresholds.low + ' 低風險、≥' + d.thresholds.medium + ' 中等風險（到「設定」可調整）。' : '。')),
    ];
  }

  function renderTrend(sec) {
    if (!sec.ok) return unavailable(sec.error);
    const t = sec.data;
    const W = 560, H = 170, L = 30, R = 8, T = 10, B = 22, n = t.days.length;
    const max = Math.max(1, ...t.series.high, ...t.series.medium, ...t.series.low);
    const x = (i) => L + (W - L - R) * i / Math.max(1, n - 1);
    const y = (v) => T + (H - T - B) * (1 - v / max);
    const colors = { high: '#ff6b6b', medium: '#f5c451', low: '#36d399' };
    const names = { high: '高風險', medium: '中風險', low: '低風險' };
    const grid = [0, 0.5, 1].map((f) => {
      const v = Math.round(max * f);
      return [sv('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), stroke: '#203551', 'stroke-width': 1 }), sv('text', { x: L - 4, y: y(v) + 3, 'text-anchor': 'end' }, String(v))];
    });
    const lines = Object.keys(colors).map((k) => sv('polyline', { fill: 'none', stroke: colors[k], 'stroke-width': 2, 'stroke-linejoin': 'round',
      points: t.series[k].map((v, i) => x(i).toFixed(1) + ',' + y(v).toFixed(1)).join(' ') }));
    const ticks = [0, Math.floor((n - 1) / 2), n - 1].map((i) => sv('text', { x: x(i), y: H - 6, 'text-anchor': i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle') }, t.days[i].slice(5)));
    return [
      sv('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart', role: 'img', 'aria-label': '近 ' + n + ' 日新建事件案數，依嚴重性分三條線，共 ' + t.total + ' 件' }, grid, lines, ticks),
      h('div', { class: 'legend' }, Object.keys(colors).map((k) => h('span', null, h('i', { style: 'background:' + colors[k] }), names[k]))),
      t.total === 0 ? h('p', { class: 'note' }, '最近 ' + n + ' 日沒有新建的事件案。') : null,
    ];
  }

  function siteMap(items) {
    const pts = items.filter((s) => s.lat !== null && s.lng !== null);
    const W = 320, H = 220, P = 30;
    const lats = pts.map((s) => s.lat), lngs = pts.map((s) => s.lng);
    const minLat = Math.min(...lats), maxLat = Math.max(...lats), minLng = Math.min(...lngs), maxLng = Math.max(...lngs);
    const spanLat = Math.max(maxLat - minLat, 0.01), spanLng = Math.max(maxLng - minLng, 0.01);
    const k = Math.min((W - 2 * P) / spanLng, (H - 2 * P) / spanLat);     // 等比例，不做地圖投影；沒有底圖
    const ox = (W - spanLng * k) / 2, oy = (H - spanLat * k) / 2;
    const px = (s) => ox + (s.lng - minLng) * k, py = (s) => oy + (maxLat - s.lat) * k;
    return sv('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart', role: 'img', 'aria-label': '站點位置散佈圖，共 ' + pts.length + ' 個站點' },
      sv('rect', { x: 0.5, y: 0.5, width: W - 1, height: H - 1, rx: 10, fill: '#0a1423', stroke: '#203551' }),
      pts.map((s) => sv('circle', { cx: px(s).toFixed(1), cy: py(s).toFixed(1), r: 7, fill: SITE_COLOR[s.level], 'fill-opacity': 0.9, stroke: '#07111f', 'stroke-width': 2 },
        sv('title', null, s.name + '：' + SITE_LABEL[s.level] + '（' + s.issues + '）'))),
      pts.length <= 12 ? pts.map((s) => sv('text', { x: (px(s) + 10).toFixed(1), y: (py(s) + 3).toFixed(1) }, s.name)) : null);
  }

  function renderSites(sec) {
    if (!sec.ok) return unavailable(sec.error);
    const d = sec.data;
    const noCoord = d.items.filter((s) => s.lat === null || s.lng === null).length;
    return [
      h('div', { class: 'counts' }, Object.keys(SITE_LABEL).map((lv) => h('div', { class: 'cnt' }, h('b', { style: 'color:' + SITE_COLOR[lv] }, String(d.counts[lv])), SITE_LABEL[lv]))),
      d.has_locations ? siteMap(d.items) : h('p', { class: 'note warn' }, '這些位置在 LibreNMS 裡沒有座標，無法畫散佈圖（設備位置設定 → 座標）。'),
      // 沒座標的站點不在圖上；不說的話會被誤認為那些站點不存在或沒問題（上面的計數與排行都有算進去）
      d.has_locations && noCoord > 0 ? h('p', { class: 'note warn' }, '另有 ' + noCoord + ' 個站點在 LibreNMS 沒有座標，沒有畫在圖上；上面的計數與下方的排行有算進去。') : null,
      d.unlocated > 0 ? h('p', { class: 'note warn' }, '另有 ' + d.unlocated + ' 台設備沒有有效的位置（未指定，或是 Unknown 這類 SNMP 預設值），不屬於任何站點。') : null,
      h('p', { class: 'note' }, '站點異常等級看離線設備的比例：<25% 輕微、<50% 中度、≥50% 嚴重。散佈圖沒有底圖（離線環境不載入外部地圖）。'),
    ];
  }

  function renderRanking(sec) {
    if (!sec.ok) return unavailable(sec.error);
    return sec.data.map((s) => h('div', { class: 'rank' },
      h('span', { class: 'no' }, String(s.rank)), h('span', { class: 'nm' }, s.name),
      badge(SITE_LABEL[s.level], SITE_BADGE[s.level]), h('span', { class: 'd', style: 'color:var(--muted);font-size:12px;min-width:5em;text-align:right' }, s.issues)));
  }

  function renderLatest(sec) {
    if (!sec.ok) return unavailable(sec.error);
    if (!sec.data.length) return h('p', { class: 'note' }, '還沒有任何事件案。');
    const open = (id) => { state.selected = Number(id); state.form = null; setTab('cases'); };
    return h('table', null,
      h('thead', null, h('tr', null, ['建立時間', '事件內容', '站點', '嚴重性', '狀態', '處理人員'].map((x) => h('th', null, x)))),
      h('tbody', null, sec.data.map((c) => h('tr', { class: 'click', tabindex: '0', onclick: () => open(c.id),
        onkeydown: (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); open(c.id); } } },
        h('td', null, c.time), h('td', null, c.title), h('td', null, c.site || '—'), h('td', null, levelBadge(c.severity_label)),
        h('td', null, badge(c.status_label, STATUS_COLOR[c.status] || 'gray')), h('td', null, c.assignee || '—')))));
  }

  function renderOverview() {
    const ov = state.overview;
    const root = $('#view-overview');
    if (!ov) {
      root.replaceChildren(state.overviewError ? unavailable(state.overviewError) : h('p', { class: 'note' }, '載入中…'));
      return;
    }
    const L = ov.landscape;
    if (!L) {
      root.replaceChildren(unavailable('伺服器沒有回傳總覽資料，請重新整理頁面'));
      return;
    }
    root.replaceChildren(
      h('section', { class: 'grid kpis6' }, L.kpi.map(kpiCard)),
      h('section', { class: 'grid three' },
        section('整體風險概況', '分數越高越好', renderRisk(L.risk)),
        section('近 30 日新建事件案', '依嚴重性', renderTrend(L.trend)),
        section('站點分佈', '依 LibreNMS 的位置', renderSites(L.sites))),
      h('section', { class: 'grid even' },
        section('站點健康度排行', '離線設備比例高的在前', renderRanking(L.ranking)),
        section('最新事件', '事件案，最近更新的在前', renderLatest(L.latest_cases))),
      h('section', { class: 'grid even' },
        section('各類系統資料是否都有進來？', '資料涵蓋與新鮮度（' + ov.meta.range_label + '）', renderCoverage(ov.coverage)),
        section('需要關注的設備', '維運視角', renderDevices(ov.devices))));
  }

  function renderSecurity() {
    const ov = state.overview;
    const root = $('#view-security');
    if (!ov) {
      root.replaceChildren(state.overviewError ? unavailable(state.overviewError) : h('p', { class: 'note' }, '載入中…'));
      return;
    }
    const pick = (key) => ov.kpi.find((k) => k.key === key);
    root.replaceChildren(
      h('p', { class: 'note', style: 'margin:0' }, '這一頁的統計期間：' + ov.meta.range_label + '（「誰在什麼設備上做了什麼」列的是這段期間內最新的 15 筆）。'),
      h('section', { class: 'grid kpis2' }, [pick('suspicious'), pick('sources')].filter(Boolean).map(kpiCard)),
      h('section', { class: 'grid two' },
        section('誰在什麼設備上做了什麼？', '使用者、來源位址與設備關聯', renderActivity(ov.activity)),
        section('最需要注意的帳號與來源', ov.meta.range_label, renderAttention(ov.attention.accounts, ov.attention.denied_sources))));
  }

  async function loadOverview() {
    try {
      state.overview = await api('/ioc-data.php' + (CFG.demo ? '?demo=1' : ''));
      state.loadedAt = Date.now();
      state.overviewError = null;
    } catch (e) {
      state.overviewError = e.message;
    }
    renderOverview(); renderSecurity(); renderBanner(); renderTopbar();
    if (state.refreshSettingsPreview) state.refreshSettingsPreview();   // 設定頁開著時，預覽用的風險分數要跟著新資料更新
    refreshBadge();
  }

  // ── 事件案 ──
  async function refreshBadge() {
    try {
      const r = await api('/ioc-cases.php', { action: 'summary' });
      state.summary = r.summary;
      renderNav(); renderTopbar();
    } catch (e) { /* 角標與鈴鐺拿不到就不顯示，不影響其他功能 */ }
  }

  const openCase = (id) => { state.selected = id; state.form = null; renderRight(); };
  const statusBadge = (c) => badge(c.status_label, STATUS_COLOR[c.status] || 'gray');
  const subject = (c) => [c.subject_user, c.subject_ip, c.subject_host].filter(Boolean).join(' · ') || '—';

  let casesSeq = 0;   // 連續觸發載入時，只採用最後一次的回應（舊的慢回應不能蓋掉新的）
  async function loadCases() {
    const seq = ++casesSeq;
    const root = $('#view-cases');
    root.replaceChildren(h('p', { class: 'note' }, '載入中…'));
    let list;
    try {
      list = await api('/ioc-cases.php', { action: 'list', status: state.filter, limit: 100 });
    } catch (e) {
      if (seq === casesSeq) root.replaceChildren(unavailable(e.message));
      return;
    }
    if (seq !== casesSeq) return;
    const filters = [['open', '未結案'], ['', '全部'], ['new', '待確認'], ['ack', '已確認'], ['in_progress', '處理中'], ['closed', '已結案']];
    const select = h('select', { id: 'f-status', 'aria-label': '依狀態篩選', onchange: (ev) => { state.filter = ev.target.value; loadCases(); } },
      filters.map(([v, t]) => h('option', { value: v, selected: v === state.filter }, t)));
    const left = h('div', null,
      h('div', { class: 'toolbar' }, h('label', { for: 'f-status', style: 'margin:0' }, '狀態'), select,
        CAN_HANDLE ? h('button', { class: 'btn primary', type: 'button', onclick: () => { state.form = {}; state.selected = null; renderRight(); } }, '＋ 建立事件案') : null),
      list.items.length
        ? h('table', null,
            h('thead', null, h('tr', null, ['編號', '標題', '等級', '狀態', '對象', '負責人', '更新時間'].map((x) => h('th', null, x)))),
            h('tbody', null, list.items.map((c) => h('tr', { class: 'click', tabindex: '0',
              onclick: () => openCase(c.id),
              onkeydown: (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openCase(c.id); } } },
              h('td', null, '#' + c.id), h('td', null, c.title), h('td', null, levelBadge(c.severity_label)), h('td', null, statusBadge(c)),
              h('td', null, subject(c)), h('td', null, c.assignee || '—'), h('td', null, c.updated_at)))))
        : h('p', { class: 'note' }, '沒有符合的事件案。'),
      list.has_more ? h('p', { class: 'note warn' }, '只顯示前 100 件，請縮小狀態篩選。') : null);
    const right = h('div', { id: 'case-right' });
    root.replaceChildren(h('div', { class: 'split' }, h('div', { class: 'card' }, h('div', { class: 'card-b' }, left)), h('div', { class: 'card' }, h('div', { class: 'card-b' }, right))));
    renderRight();
  }

  async function renderRight() {
    const right = $('#case-right');
    if (!right) return;
    if (state.form) { right.replaceChildren(renderCreateForm(state.form)); return; }
    if (!state.selected) { right.replaceChildren(h('p', { class: 'note' }, '從左邊選一件事件案查看詳情' + (CAN_HANDLE ? '，或建立新的。' : '。'))); return; }
    const id = state.selected;
    // 等回應的時候使用者可能已經點了別件或開了表單：回來時對不上就丟掉，不然會顯示 A 件的詳情、
    // 按鈕卻以為在處理 B 件
    const stale = () => state.selected !== id || state.form || $('#case-right') !== right;
    right.replaceChildren(h('p', { class: 'note' }, '載入中…'));
    try {
      const d = await api('/ioc-cases.php', { action: 'get', id });
      if (!stale()) right.replaceChildren(renderDetail(d));
    } catch (e) {
      if (!stale()) right.replaceChildren(unavailable(e.message));
    }
  }

  function field(label, control) { return [h('label', null, label), control]; }

  function renderCreateForm(pre) {
    const f = {
      title: h('input', { type: 'text', maxlength: 200, value: pre.title || '', style: 'width:100%' }),
      severity: h('select', null, Object.entries(CFG.labels.caseSeverity).map(([v, t]) => h('option', { value: v, selected: v === (pre.severity || 'medium') }, t))),
      subject_user: h('input', { type: 'text', maxlength: 128, value: pre.subject_user || '' }),
      subject_ip: h('input', { type: 'text', maxlength: 45, value: pre.subject_ip || '' }),
      subject_host: h('input', { type: 'text', maxlength: 128, value: pre.subject_host || '' }),
      summary: h('textarea', { maxlength: 2000 }, pre.summary || ''),
      evidence_query: h('textarea', { maxlength: 1000 }, pre.evidence_query || ''),
    };
    const msg = h('div', { class: 'note warn' });
    const submit = h('button', { class: 'btn primary', type: 'submit' }, '建立');
    const form = h('form', { onsubmit: async (ev) => {
      ev.preventDefault();
      submit.disabled = true; msg.textContent = '';
      try {
        const body = {}; Object.entries(f).forEach(([k, el]) => { body[k] = el.value; });
        const r = await api('/ioc-cases.php', { action: 'create', case: body });
        toast('已建立事件案 #' + r.id);
        state.form = null; state.selected = r.id; refreshBadge(); loadCases();
      } catch (e) {
        msg.textContent = (e.errors && e.errors.length ? e.errors.join('；') : e.message);
        submit.disabled = false;
      }
    } },
      h('h3', { style: 'margin:0 0 6px' }, '建立事件案'),
      field('標題', f.title), field('等級', f.severity), field('帳號', f.subject_user), field('來源位址', f.subject_ip), field('設備', f.subject_host),
      field('說明', f.summary), field('Graylog 查詢條件（在 Graylog 搜尋這串就能看到原始日誌）', f.evidence_query),
      h('div', { class: 'toolbar', style: 'margin-top:12px' }, submit,
        h('button', { class: 'btn', type: 'button', onclick: () => { state.form = null; renderRight(); } }, '取消')), msg);
    return form;
  }

  function renderDetail(d) {
    const c = d.case;
    const parts = [
      h('h3', { style: 'margin:0 0 8px' }, '#' + c.id + '　' + c.title),
      h('dl', { class: 'kv' },
        h('dt', null, '狀態'), h('dd', null, statusBadge(c), c.resolution_label ? '　處理結果：' + c.resolution_label : ''),
        h('dt', null, '等級'), h('dd', null, levelBadge(c.severity_label)),
        h('dt', null, '對象'), h('dd', null, subject(c)),
        h('dt', null, '負責人'), h('dd', null, c.assignee || '—'),
        h('dt', null, '建立'), h('dd', null, c.created_at + '　' + c.created_by + '（' + c.source_label + '）'),
        h('dt', null, '最後更新'), h('dd', null, c.updated_at),
        c.closed_at ? [h('dt', null, '結案'), h('dd', null, c.closed_at)] : null,
        c.summary ? [h('dt', null, '說明'), h('dd', null, c.summary)] : null,
        c.evidence_query ? [h('dt', null, '查詢條件'), h('dd', null, h('code', null, c.evidence_query))] : null),
    ];
    if (CAN_HANDLE) parts.push(renderActions(c, d.transitions));
    parts.push(h('h3', { style: 'margin:16px 0 6px' }, '處理歷程'),
      h('ul', { class: 'tl' }, d.history.map((x) => h('li', null,
        h('div', { class: 'when' }, x.time + '　' + x.actor),
        h('div', null, x.action === 'status_changed' ? x.from_label + ' → ' + x.to_label + (x.resolution_label ? '（' + x.resolution_label + '）' : '') : x.action_label),
        x.note ? h('div', { class: 'd', style: 'color:var(--muted)' }, x.note) : null))));
    return h('div', null, parts);
  }

  function renderActions(c, transitions) {
    const note = h('textarea', { maxlength: 2000, placeholder: '備註（選填；留言時必填）' });
    const resolution = h('select', null, [h('option', { value: '' }, '— 結案要選處理結果 —'),
      ...Object.entries(CFG.labels.resolution).map(([v, t]) => h('option', { value: v }, t))]);
    const msg = h('div', { class: 'note warn' });
    const run = async (body, okText) => {
      msg.textContent = '';
      try {
        await api('/ioc-cases.php', Object.assign({ id: c.id }, body));
        toast(okText); refreshBadge(); loadCases();
      } catch (e) { msg.textContent = (e.errors && e.errors.length ? e.errors.join('；') : e.message); }
    };
    const buttons = transitions.map((t) => h('button', { class: 'btn' + (t.to === 'closed' ? ' danger' : ''), type: 'button',
      onclick: () => run({ action: 'transition', to: t.to, resolution: t.to === 'closed' ? resolution.value : '', note: note.value }, '已變更為「' + t.label + '」') }, '改為「' + t.label + '」'));
    const needsResolution = transitions.some((t) => t.to === 'closed');
    return h('div', { style: 'margin-top:14px;padding-top:10px;border-top:1px solid var(--line)' },
      h('h3', { style: 'margin:0 0 6px' }, '處理'),
      needsResolution ? resolution : null, note,
      h('div', { class: 'toolbar', style: 'margin-top:8px' }, buttons,
        h('button', { class: 'btn', type: 'button', onclick: () => run({ action: 'comment', note: note.value }, '已留言') }, '只留言')), msg);
  }

  // ── 設定：風險等級門檻 ──
  // 預覽用的等級判斷；正式的判定在伺服器（RiskScore::level），這裡只是讓使用者儲存前先看到結果
  const levelFor = (score, low, medium) => (score >= low ? 'low' : (score >= medium ? 'medium' : 'high'));
  const wholeIn = (el, min, max) => /^\d{1,3}$/.test(el.value.trim()) && Number(el.value) >= min && Number(el.value) <= max;

  let settingsSeq = 0;   // 連續觸發載入時，只採用最後一次的回應
  async function loadSettings() {
    const seq = ++settingsSeq;
    const root = $('#view-settings');
    root.replaceChildren(h('p', { class: 'note' }, '載入中…'));
    try {
      const r = await api('/ioc-settings.php', { action: 'get' });
      if (seq === settingsSeq) renderSettings(r.risk);
    } catch (e) {
      if (seq === settingsSeq) root.replaceChildren(unavailable(e.message));
    }
  }

  /** @param {boolean} [focusSave] 儲存後整個表單會重建，原本按著的儲存鈕會消失；要把鍵盤焦點放回新的儲存鈕。 */
  function renderSettings(s, focusSave) {
    const root = $('#view-settings');
    const num = (id, value) => h('input', { id, type: 'number', min: 1, max: 100, step: 1, value, disabled: !CAN_HANDLE, style: 'width:6em' });
    const low = num('risk-low', s.low);
    const medium = num('risk-medium', s.medium);
    const highAt = h('b', null, String(s.medium));
    const preview = h('p', { class: 'note', 'aria-live': 'polite' });
    const msg = h('div', { class: 'note warn', role: 'alert' });

    const refresh = () => {
      const ok = wholeIn(low, 1, 100) && wholeIn(medium, 1, 100) && Number(medium.value) < Number(low.value);
      highAt.textContent = wholeIn(medium, 1, 100) ? String(Number(medium.value)) : '—';
      const risk = state.overview && state.overview.landscape && state.overview.landscape.risk;
      const score = risk && risk.ok ? risk.data.score : null;
      if (score === null || score === undefined) { preview.textContent = '目前沒有可用的整體風險分數，無法預覽。'; return; }
      preview.textContent = ok
        ? '目前的整體風險分數是 ' + score + ' 分，依這組門檻會是「' + CFG.labels.riskLevel[levelFor(score, Number(low.value), Number(medium.value))] + '」。'
        : '目前的整體風險分數是 ' + score + ' 分。門檻填好（整數 1～100，中等風險要比低風險小）後會在這裡預覽等級。';
    };
    low.addEventListener('input', refresh);
    medium.addEventListener('input', refresh);
    refresh();
    state.refreshSettingsPreview = refresh;

    const when = s.invalid
      ? h('p', { class: 'alert' }, '資料庫裡的門檻值不合法（可能被手動改過），下面填的是預設值。在重新儲存之前，總覽只顯示分數、不判定風險等級。')
      : h('p', { class: 'note' }, s.custom ? '目前是自訂值，最後由 ' + s.updated_by + ' 在 ' + s.updated_at + ' 修改。'
        : '目前使用預設值（' + s.defaults.low + '／' + s.defaults.medium + '），還沒有人自訂過。');

    const save = h('button', { class: 'btn primary', type: 'submit' }, '儲存');
    const row = (label, id, control) => h('div', { class: 'row' }, h('label', { for: id, style: 'margin:0;font-size:13px;color:var(--text)' }, label), control);
    const form = h('form', { onsubmit: async (ev) => {
      ev.preventDefault();
      save.disabled = true; msg.textContent = '';
      try {
        const r = await api('/ioc-settings.php', { action: 'save', risk_low: low.value, risk_medium: medium.value });
        toast('已儲存風險等級門檻');
        renderSettings(r.risk, true);
        loadOverview();                  // 總覽的風險等級要用新門檻重算
      } catch (e) {
        msg.textContent = (e.errors && e.errors.length ? e.errors.join('；') : e.message);
        save.disabled = false;
      }
    } },
      h('p', { class: 'note', style: 'margin-top:0' }, '整體風險分數是 0～100，越高越好。分數落在哪一段，就是哪個風險等級；門檻都是 1～100 的整數，「中等風險」要比「低風險」小。'),
      row('低風險：分數 ≥', 'risk-low', low),
      row('中等風險：分數 ≥', 'risk-medium', medium),
      h('div', { class: 'row' }, h('span', null, '高風險：分數 <（自動，等於中等風險的門檻）'), highAt),
      preview, when,
      CAN_HANDLE
        ? h('div', { class: 'toolbar', style: 'margin-top:12px' }, save,
            h('button', { class: 'btn', type: 'button', onclick: () => { low.value = s.defaults.low; medium.value = s.defaults.medium; refresh(); } },
              '填入預設值（' + s.defaults.low + '／' + s.defaults.medium + '）'),
            h('span', { class: 'note', style: 'margin:0' }, '填入後要按「儲存」才會生效。'))
        : h('p', { class: 'note warn' }, '你的角色是檢視者（唯讀），只有管理者（admin）能修改門檻。'),
      msg);

    const history = s.history.length
      ? table(['時間', '操作者', '項目', '原值', '新值'], s.history.map((x) => [x.time, x.actor, x.label, x.from, x.to]))
      : h('p', { class: 'note' }, '還沒有人調整過門檻。');
    root.replaceChildren(h('section', { class: 'grid even' },
      section('風險等級門檻', '用來把整體風險分數分成低、中、高', form),
      section('異動紀錄', '最近 10 筆，誰在什麼時候改的', history)));
    if (focusSave && CAN_HANDLE) save.focus();
  }

  // ── 啟動 ──
  buildNav(); buildTopbar(); renderNav(); renderTopbar(); renderOverview(); renderSecurity();
  const first = location.hash.replace('#', '');
  if (PAGES.includes(first) && first !== 'overview') setTab(first);
  loadOverview();
  setInterval(loadOverview, REFRESH_MS);
  setInterval(() => { renderTopbar(); if (state.overviewError) renderBanner(); }, 1000);
})();
</script>
</body>
</html>
