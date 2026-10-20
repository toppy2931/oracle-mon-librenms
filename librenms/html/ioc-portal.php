<?php
/**
 * ioc-portal.php — 智慧維運與資安態勢中心（中文入口）
 * URL: http://<monitor-vm>/ioc-portal.php[?demo=1]
 *
 * 需登入，角色為 admin（可處理事件案）或 global-read（唯讀）。資料來自 ioc-data.php 與 ioc-cases.php，
 * 權限在後端檢查；這個頁面只決定「要不要顯示按鈕」，不是權限控管（IS-013）。
 * 日誌內容都用 textContent 放進畫面，不用 innerHTML，避免日誌裡的惡意內容變成 XSS。
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
    'csrf'   => csrf_token(),
    'demo'   => $demo,
    'labels' => [
        'caseStatus'   => Ioc\Labels::CASE_STATUS,
        'caseSeverity' => Ioc\Labels::CASE_SEVERITY,
        'resolution'   => Ioc\Labels::RESOLUTION,
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
  --green:#36d399;--yellow:#f5c451;--red:#ff6b6b;--blue:#61a8ff;--gray:#778ba6}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(circle at 10% 0%,rgba(35,84,160,.24),transparent 32%),var(--bg);color:var(--text);
  font:14px/1.6 "Noto Sans TC","Microsoft JhengHei",system-ui,sans-serif}
a{color:var(--blue)}
.shell{max-width:1600px;margin:0 auto;padding:18px}
.top{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:16px 20px;border:1px solid var(--line);border-radius:16px;background:linear-gradient(180deg,#111f32,#0a1727)}
.top h1{margin:0;font-size:22px;letter-spacing:.3px}
.top p{margin:2px 0 0;color:var(--muted);font-size:13px}
.chips{display:flex;gap:8px;flex-wrap:wrap}
.chip{padding:5px 11px;border:1px solid var(--line);border-radius:999px;background:#0a1728;color:#c8d6e7;font-size:12px;white-space:nowrap}
.chip b{color:#fff}
.chip.demo{border-color:var(--yellow);color:var(--yellow)}
.tabs{display:flex;gap:6px;align-items:center;margin:14px 0 4px;flex-wrap:wrap}
.tabs button{font:inherit;color:var(--muted);background:transparent;border:1px solid var(--line);border-radius:10px;padding:7px 16px;cursor:pointer}
.tabs button[aria-selected=true]{color:#fff;background:var(--panel2);border-color:var(--blue)}
.tabs .sp{flex:1}
.badge-n{display:inline-block;min-width:20px;padding:0 6px;margin-left:6px;border-radius:999px;background:var(--red);color:#fff;font-size:11px;text-align:center}
[hidden]{display:none!important}   /* 作者樣式的 display 會蓋掉瀏覽器內建的 [hidden]，角標就藏不起來 */
.banner{margin:10px 0;padding:10px 14px;border:1px solid var(--yellow);border-radius:10px;background:rgba(245,196,81,.08);color:#ffe49d}
.banner.err{border-color:var(--red);background:rgba(255,107,107,.08);color:#ffc0c0}
.grid{display:grid;gap:14px;margin-top:14px}
.kpis{grid-template-columns:repeat(5,minmax(0,1fr))}
.two{grid-template-columns:1.25fr .75fr}
.even{grid-template-columns:1fr 1fr}
.card{border:1px solid var(--line);border-radius:14px;background:linear-gradient(180deg,#122238,#0c192b);overflow:hidden;min-width:0}
.card-h{padding:12px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:10px;align-items:baseline}
.card-h h2{margin:0;font-size:14px}
.card-h span{color:var(--muted);font-size:11px}
.card-b{padding:12px 16px;overflow-x:auto}
.kpi{padding:14px 16px;min-height:112px}
.kpi .l{font-size:12px;color:var(--muted)}
.kpi .v{font-size:30px;font-weight:800;margin:6px 0 4px;letter-spacing:-.5px}
.kpi .s{font-size:12px;color:#9db1ca}
.good .v{color:var(--green)}.warn .v{color:var(--yellow)}.bad .v{color:var(--red)}.unknown .v{color:var(--gray)}
.unknown .v{font-size:22px}
table{width:100%;border-collapse:collapse;font-size:12px}
th{text-align:left;color:#87a0bd;font-weight:600;padding:8px;border-bottom:1px solid var(--line);white-space:nowrap}
td{padding:9px 8px;border-bottom:1px solid rgba(32,53,81,.65);vertical-align:middle}
tbody tr:hover{background:rgba(97,168,255,.05)}
tr.click{cursor:pointer}
tr.click:focus-visible,.btn:focus-visible,.tabs button:focus-visible,select:focus-visible,input:focus-visible,textarea:focus-visible{outline:2px solid var(--blue);outline-offset:1px}
.badge{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;font-size:11px;border:1px solid transparent;white-space:nowrap}
.badge::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.b-green{color:#aaf2d6;border-color:rgba(54,211,153,.3);background:rgba(54,211,153,.08)}
.b-yellow{color:#ffe49d;border-color:rgba(245,196,81,.3);background:rgba(245,196,81,.08)}
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
.toast{position:fixed;right:16px;bottom:16px;max-width:420px;padding:10px 14px;border-radius:10px;background:#1b4a86;color:#fff;display:none;z-index:9}
.toast.err{background:#8a2b2b}
footer{margin:18px 0 6px;text-align:center;color:#7187a4;font-size:11px}
@media(max-width:1180px){.kpis{grid-template-columns:repeat(3,1fr)}.two,.even,.split{grid-template-columns:1fr}}
@media(max-width:720px){.shell{padding:10px}.kpis{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="shell">
  <header class="top">
    <div>
      <h1>智慧維運與資安態勢中心</h1>
      <p>維運總覽・事件案管理</p>
    </div>
    <div class="chips" id="chips"></div>
  </header>

  <nav class="tabs" aria-label="功能">
    <button type="button" data-tab="overview" aria-selected="true">維運總覽</button>
    <button type="button" data-tab="cases" aria-selected="false">事件案<span class="badge-n" id="cases-badge" hidden></span></button>
    <span class="sp"></span>
    <a href="/">返回 LibreNMS</a>
  </nav>

  <div id="banner"></div>
  <main id="view-overview"></main>
  <main id="view-cases" hidden></main>
  <footer>日誌來自 Graylog（唯讀帳號），設備來自 LibreNMS，事件案存在 LibreNMS 資料庫。稽核準備度的計算方式尚未定義，因此暫不顯示。</footer>
</div>
<div class="toast" id="toast" role="status"></div>

<script type="application/json" id="ioc-config"><?= $config ?></script>
<script>
(() => {
  'use strict';
  const CFG = JSON.parse(document.getElementById('ioc-config').textContent);
  const CAN_HANDLE = CFG.level === 'handler';
  const REFRESH_MS = 60000;
  const state = { tab: 'overview', filter: 'open', selected: null, form: null, overview: null, loadedAt: 0, overviewError: null };

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
  const $ = (sel) => document.querySelector(sel);
  const badge = (text, cls) => h('span', { class: 'badge b-' + cls }, text);
  const levelBadge = (label) => badge(label, { '高': 'red', '中': 'yellow', '低': 'green' }[label] || 'gray');
  const stateOf = { ok: 'green', delayed: 'yellow', stale: 'red', none: 'gray', unknown: 'gray' };
  const STATUS_COLOR = { new: 'red', ack: 'yellow', in_progress: 'blue', closed: 'gray' };
  const LEVEL_TO_SEVERITY = { high: 'high', medium: 'medium', low: 'low' };
  const fmt = (n) => Number(n).toLocaleString('zh-TW');
  const luceneQ = (field, value) => field + ':"' + String(value).replace(/[\\"]/g, '\\$&') + '"';

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

  // ── 頁首與頁籤 ──
  function renderChips() {
    const ov = state.overview;
    const age = state.loadedAt ? Math.round((Date.now() - state.loadedAt) / 1000) : null;
    // 注意：replaceChildren(null) 會把 null 當成文字「null」插進去，所以要先濾掉
    $('#chips').replaceChildren(...[
      CFG.demo ? h('span', { class: 'chip demo' }, '範例資料模式') : null,
      h('span', { class: 'chip' }, '期間：', h('b', null, ov ? ov.meta.range_label : '最近 24 小時')),
      h('span', { class: 'chip' }, '更新：', h('b', null, ov ? ov.meta.generated_at : '—'), age === null ? '' : '（' + age + ' 秒前）'),
      h('span', { class: 'chip' }, '身分：', h('b', null, CAN_HANDLE ? '處理者' : '檢視者（唯讀）')),
    ].filter(Boolean));
  }

  function setTab(tab) {
    const changed = state.tab !== tab;
    state.tab = tab;
    document.querySelectorAll('.tabs button').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
    $('#view-overview').hidden = tab !== 'overview';
    $('#view-cases').hidden = tab !== 'cases';
    // 已經在事件案頁籤時再按一次不要重載，否則填到一半的表單會被清掉
    if (tab === 'cases' && (changed || !$('#case-right'))) { loadCases(); }
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

  // ── 維運總覽 ──
  const unavailable = (msg) => h('div', { class: 'alert' }, '無法取得：' + msg);
  const section = (title, hint, body) => h('div', { class: 'card' },
    h('div', { class: 'card-h' }, h('h2', null, title), h('span', null, hint)), h('div', { class: 'card-b' }, body));
  const table = (heads, rows) => h('table', null,
    h('thead', null, h('tr', null, heads.map((x) => h('th', null, x)))),
    h('tbody', null, rows.map((r) => h('tr', null, r.map((c) => h('td', null, c))))));

  function kpiCard(k) {
    const unknown = k.value === null || k.value === undefined;
    return h('div', { class: 'card kpi ' + k.state },
      h('div', { class: 'l' }, k.label),
      h('div', { class: 'v' }, unknown ? '無法取得' : String(k.value)),
      h('div', { class: 's' }, k.sub));
  }

  function renderActivity(sec) {
    if (!sec.ok) return unavailable(sec.error);
    if (!sec.data.length) return h('p', { class: 'note' }, '這段期間沒有符合條件的事件。');
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
    else if (!accounts.data.items.length) blocks.push(h('p', { class: 'note' }, '沒有可疑的登入事件。'));
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

  function renderOverview() {
    const ov = state.overview;
    const root = $('#view-overview');
    if (!ov) {
      root.replaceChildren(state.overviewError ? unavailable(state.overviewError) : h('p', { class: 'note' }, '載入中…'));
      return;
    }
    root.replaceChildren(
      h('section', { class: 'grid kpis' }, ov.kpi.map(kpiCard)),
      h('section', { class: 'grid two' },
        section('誰在什麼設備上做了什麼？', '使用者、來源位址與設備關聯', renderActivity(ov.activity)),
        section('各類系統資料是否都有進來？', '資料涵蓋與新鮮度', renderCoverage(ov.coverage))),
      h('section', { class: 'grid even' },
        section('最需要注意的帳號與來源', ov.meta.range_label, renderAttention(ov.attention.accounts, ov.attention.denied_sources)),
        section('需要關注的設備', '維運視角', renderDevices(ov.devices))));
  }

  async function loadOverview() {
    try {
      state.overview = await api('/ioc-data.php' + (CFG.demo ? '?demo=1' : ''));
      state.loadedAt = Date.now();
      state.overviewError = null;
    } catch (e) {
      state.overviewError = e.message;
    }
    renderOverview(); renderBanner(); renderChips();
    refreshBadge();
  }

  // ── 事件案 ──
  async function refreshBadge() {
    try {
      const r = await api('/ioc-cases.php', { action: 'summary' });
      const b = $('#cases-badge');
      b.hidden = r.summary.open === 0;
      b.textContent = String(r.summary.open);
    } catch (e) { /* 角標拿不到就不顯示，不影響其他功能 */ }
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

  // ── 啟動 ──
  document.querySelectorAll('.tabs button').forEach((b) => b.addEventListener('click', () => setTab(b.dataset.tab)));
  if (location.hash === '#cases') setTab('cases');
  renderChips(); renderOverview(); loadOverview();
  setInterval(loadOverview, REFRESH_MS);
  setInterval(() => { renderChips(); if (state.overviewError) renderBanner(); }, 1000);
})();
</script>
</body>
</html>
