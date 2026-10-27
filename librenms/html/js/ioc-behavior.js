/* 裝置行為工作台。所有遠端文字都交給 h()/textContent，沒有 innerHTML。 */
(() => {
  'use strict';
  window.IocBehavior = ({ h, section, table, api, root, toast, canHandle, unavailable, openCases }) => {
    let selected = null;
    let requestSeq = 0;
    const call = (body) => api('/ioc-behavior.php', body);
    const note = (value) => h('p', { class: 'note' }, value);
    const line = (label, value) => h('div', { class: 'rank' }, h('span', { class: 'nm' }, label), h('span', null, value == null ? '—' : String(value)));
    const modeName = {};      // 由伺服器的 mode_names 填（load()）
    const qualityName = { ready: '資料足夠', learning: '學習中', complete: '完整', partial: '不完整', missing: '來源缺資料', error: '來源錯誤', no_data: '尚無資料' };
    const fmt = (n) => Number(n || 0).toLocaleString('zh-TW');

    // 清單的分頁與排序狀態（在伺服器端做）：切換分頁籤、返回清單時保留。每頁筆數的白名單與伺服器一致
    const PAGE_SIZES = [10, 25, 50, 100];
    const views = {
      lifecycle: { page: 1, per: 10, sort: 'last_seen', dir: 'desc' },
      confirmed: { page: 1, per: 10, sort: 'last_seen', dir: 'desc' },
    };
    const listSeq = { lifecycle: 0, confirmed: 0 };
    let tab = 'lifecycle';

    async function change(body, message) {
      try { await call(body); toast(message); await load(); }
      catch (e) { toast(e.message, true); }
    }
    function summary(data) {
      const s = data.summary || {};
      return section('裝置行為概況', '管理員確認身分後，資料量足夠就能啟用監控',
        h('div', { class: 'sumrow c4' },
          ...[['學習中', s.learning], ['監控中', s.monitoring], ['開啟警示', s.open_alerts], ['來源異常', s.source_error]]
            .map(([title, value]) => h('div', null, h('span', { class: 'k' }, title), h('b', { class: 'v' }, fmt(value))))));
    }
    function sourcePanel(data) {
      const rows = data.sources || [];
      return section('資料來源與涵蓋', '感測器尚未完成驗收時，只分析經過防火牆的流量；同網段未經防火牆的連線不可見',
        rows.length ? rows.map((s) => line(s.scope_label + '（' + s.source_key + '）',
          s.status + ' · ' + s.coverage + (s.checked_at ? ' · 檢查 ' + s.checked_at + ' UTC' : '')
            + (s.reason ? ' · ' + s.reason : ''))) : note('尚未收到資料來源狀態。'));
    }
    const kindName = (a) => (a.device_id ? 'LibreNMS 設備' : (a.confirmed_at ? '已確認主機' : '待確認'));
    const confirmable = (a) => !a.device_id && a.mode !== 'retired' && !a.confirmed_at && String(a.identity_key || '').startsWith('observed:');

    // ---- 分頁清單（共用）：欄位標題可點排序、每頁筆數、頁碼 ----
    const pageList = (page, pages) => {
      const keep = new Set([1, 2, pages - 1, pages, page - 1, page, page + 1].filter((n) => n >= 1 && n <= pages));
      const out = [];
      let last = 0;
      for (const n of [...keep].sort((a, b) => a - b)) {
        if (n - last > 1) out.push('…');
        out.push(n);
        last = n;
      }
      return out;
    };
    function sortHead(st, key, label, refill) {
      const on = st.sort === key;
      return h('button', { class: 'sort', type: 'button',
        'aria-label': '依「' + label + '」排序' + (on ? (st.dir === 'asc' ? '（目前由小到大）' : '（目前由大到小）') : ''),
        onclick: () => {
          if (on) st.dir = st.dir === 'asc' ? 'desc' : 'asc';
          else { st.sort = key; st.dir = key === 'last_seen' || key === 'confirmed_at' ? 'desc' : 'asc'; }
          st.page = 1;
          refill();
        } }, label, h('span', { class: on ? 'on' : 'dim' }, on ? (st.dir === 'asc' ? ' ▲' : ' ▼') : ' ⇅'));
    }
    function pager(res, st, refill) {
      const go = (p) => { st.page = p; refill(); };
      const btn = (label, p, aria, disabled, current) => h('button', { class: 'btn' + (current ? ' primary' : ''), type: 'button',
        'aria-label': aria, 'aria-current': current ? 'page' : null, disabled: disabled || current, onclick: () => go(p) }, label);
      const first = res.page <= 1; const lastPage = res.page >= res.pages;
      return h('div', { class: 'pager' },
        h('span', { class: 'info' }, '共 ' + fmt(res.total) + ' 筆，第 ' + res.page + '／' + res.pages + ' 頁'),
        h('span', { class: 'pages' },
          btn('«', 1, '第一頁', first, false), btn('‹', res.page - 1, '上一頁', first, false),
          ...pageList(res.page, res.pages).map((n) => (n === '…' ? h('span', { class: 'gap' }, '…') : btn(String(n), n, '第 ' + n + ' 頁', false, n === res.page))),
          btn('›', res.page + 1, '下一頁', lastPage, false), btn('»', res.pages, '最後一頁', lastPage, false)),
        h('label', { class: 'per' }, '每頁 ',
          h('select', { 'aria-label': '每頁筆數', onchange: (e) => { st.per = Number(e.target.value); st.page = 1; refill(); } },
            PAGE_SIZES.map((n) => h('option', { value: String(n), selected: n === st.per }, String(n)))), ' 筆'));
    }

    /** 清單區塊：自己向伺服器取目前這一頁；排序、換頁、換每頁筆數都只重取這個區塊。 */
    function listPanel(view, sourceReady) {
      const st = views[view];
      const box = h('div', null, note('載入清單中…'));
      const lifecycle = view === 'lifecycle';
      const title = lifecycle ? '裝置生命週期' : '已確認主機';
      const hint = lifecycle
        ? 'LibreNMS 設備與尚待確認的主機。未知 IP 先是「待確認」；確認為受管主機後會移到「已確認主機」分頁，才能訓練基線與啟用監控'
        : '管理員確認過的受管主機。可訓練基線、啟用監控；不再需要追蹤的按「移除」（紀錄保留）';
      async function fill() {
        const seq = ++listSeq[view];
        try {
          const res = (await call({ action: 'list_assets', view, page: st.page, per_page: st.per, sort: st.sort, dir: st.dir })).list;
          if (seq !== listSeq[view]) return;
          st.page = res.page;
          box.replaceChildren(...render(res));
        } catch (e) { if (seq === listSeq[view]) box.replaceChildren(unavailable(e.message)); }
      }
      function render(res) {
        if (!res.total) {
          return [note(lifecycle ? '沒有待處理的裝置。工作器啟用後會從 LibreNMS 載入設備，未知來源 IP 會出現在這裡。' : '還沒有已確認的主機。到「裝置與待確認」分頁勾選主機並確認。')];
        }
        const boxes = new Map();
        const col = (key, label) => sortHead(st, key, label, fill);
        const rows = res.rows.map((a) => {
          const common = [a.address || '未確認', a.site_name || '未歸屬', modeName[a.mode] || a.mode,
            sourceReady ? (qualityName[a.quality_status] || a.quality_status) : '來源異常／未驗證', a.last_seen_at || '尚無資料'];
          const name = h('button', { class: 'btn', type: 'button', onclick: () => detail(a.id) }, a.name);
          if (!lifecycle) {
            return [name, ...common, (a.confirmed_at || '—') + (a.confirmed_by ? ' · ' + a.confirmed_by : ''),
              h('button', { class: 'btn danger', type: 'button', onclick: () => {
                if (window.confirm('移除主機「' + a.name + '」（' + (a.address || '—') + '）？\n移除後不再追蹤與監控這台主機，歷史與警示紀錄保留；之後這個位址的流量會成為新的待確認主機。'))
                  change({ action: 'set_mode', asset_id: Number(a.id), mode: 'retired' }, '已移除主機');
              } }, '移除')];
          }
          let box = '';
          if (confirmable(a)) {
            box = h('input', { type: 'checkbox', 'aria-label': '選取 ' + a.name });
            boxes.set(a.id, box);
          }
          return [box, name, kindName(a), ...common];
        });
        const heads = lifecycle
          ? ['選取', col('name', '裝置'), col('kind', '身分'), col('address', '目前位址'), col('site', '站點'), col('mode', '模式'), col('quality', '資料品質'), col('last_seen', '最近觀測')]
          : [col('name', '主機'), col('address', '目前位址'), col('site', '站點'), col('mode', '模式'), col('quality', '資料品質'), col('last_seen', '最近觀測'), col('confirmed_at', '確認時間／確認者'), '操作'];
        const confirmBar = lifecycle && boxes.size ? h('div', { class: 'toolbar' },
          h('button', { class: 'btn', type: 'button', onclick: () => { for (const b of boxes.values()) b.checked = true; } }, '全選本頁待確認'),
          h('button', { class: 'btn primary', type: 'button', onclick: () => {
            const ids = [...boxes].filter(([, b]) => b.checked).map(([id]) => Number(id));
            if (!ids.length) { toast('請先勾選要確認的主機', true); return; }
            const reason = window.prompt('請填寫確認依據：DHCP 租約、資產清冊或人工核對結果（勿填機密）');
            if (reason && reason.trim() && window.confirm('確認這 ' + ids.length + ' 台是受管主機？確認後可訓練基線並啟用監控，既有的觀測紀錄會保留。位址會出現在事件案，檢視者也看得到。'))
              change({ action: 'confirm_hosts', items: ids.map((id) => ({ id })), note: reason.trim() }, '已確認 ' + ids.length + ' 台主機');
          } }, '確認勾選的主機為受管主機'),
          h('span', { class: 'note' }, '勾選只限目前這一頁，換頁會清除勾選')) : null;
        return [confirmBar, table(heads, rows), pager(res, st, fill)].filter(Boolean);     // replaceChildren(null) 會插入文字「null」
      }
      fill();
      return section(title, hint, box);
    }
    function schedulePanel(data) {
      const schedules = data.schedules || [];
      const scopeName = { device: '設備', site: '站點', default: '預設' };
      const scope = h('select', { 'aria-label': '時段範圍' }, h('option', { value: 'device' }, '設備'), h('option', { value: 'site' }, '站點'),
        h('option', { value: 'default' }, '預設（其餘全部）'));
      const key = h('input', { 'aria-label': '設備 ID 或站點名稱', placeholder: 'LibreNMS 設備 ID 或站點名稱；選「預設」不用填' });
      const d = data.default_schedule || null;
      const zone = h('input', { 'aria-label': '時區', value: 'Asia/Taipei' });
      const days = h('input', { 'aria-label': '工作日', value: '1,2,3,4,5' });
      const start = h('input', { 'aria-label': '上班開始', type: 'time', value: '09:00' });
      const end = h('input', { 'aria-label': '上班結束', type: 'time', value: '18:00' });
      return section('上班時段', '星期一＝1、星期日＝7；套用順序：設備 → 站點 → 預設。沒有自訂預設時，用內建的台北、週一到五 09:00–18:00',
        [d ? note('目前的預設時段（沒有設備或站點時段的資產套用）：' + d.timezone + '，工作日 ' + d.workdays + '，'
          + String(d.start_time).slice(0, 5) + '–' + String(d.end_time).slice(0, 5) + (d.custom ? '（自訂）' : '（內建）')) : null,
        schedules.length ? table(['範圍', '識別', '時區', '工作日', '時間'], schedules.map((x) =>
          [scopeName[x.scope] || x.scope, x.scope_key, x.timezone, x.workdays,
            String(x.start_time).slice(0, 5) + '–' + String(x.end_time).slice(0, 5)])) : note('尚未自訂任何時段。'),
        canHandle ? h('div', { class: 'toolbar' }, scope, key, zone, days, start, end,
          h('button', { class: 'btn primary', type: 'button', onclick: async () => {
            const body = { scope: scope.value, scope_key: scope.value === 'default' ? 'default' : key.value.trim(), timezone: zone.value.trim(),
              workdays: days.value.trim(), start_time: start.value, end_time: end.value };
            try {
              // 先問伺服器這份時段會讓幾台監控中的資產改回學習中，有影響才請管理員確認
              const impact = (await call({ ...body, action: 'preview_schedule' })).impact;
              if (impact.changed && impact.monitoring > 0 && !window.confirm('儲存後，已啟用監控而且套用這個時段的 ' + impact.monitoring
                + ' 台資產會改回學習中，需要重新按「啟用監控」（期間不判斷離峰異常）。確定儲存？')) return;
            } catch (e) { toast(e.message, true); return; }
            await change({ ...body, action: 'save_schedule' }, '已儲存上班時段');
          } }, '儲存時段')) : null]);
    }
    function inventoryPanel() {
      if (!canHandle) return null;
      const key = h('input', { 'aria-label': '來源代碼', placeholder: '例如 core_span_1' });
      const kind = h('select', { 'aria-label': '來源類型' }, ['span', 'tap', 'flow', 'dns'].map((x) => h('option', { value: x }, x)));
      const scope = h('input', { 'aria-label': '感測範圍', placeholder: '交換器／鏡像埠／網段' });
      const coverage = h('input', { 'aria-label': '涵蓋說明', placeholder: '例如 172.16.1.0/24；待驗收' });
      const status = h('select', { 'aria-label': '盤點狀態' }, ['inventory', 'planned', 'unavailable']
        .map((x) => h('option', { value: x }, x)));
      return section('感測來源盤點', '先確認 SPAN／TAP／Flow 能力；狀態紀錄不會自動啟用感測器',
        h('div', { class: 'toolbar' }, key, kind, scope, coverage, status,
          h('button', { class: 'btn', type: 'button', onclick: () => change({ action: 'save_source_inventory',
            source_key: key.value.trim(), kind: kind.value, scope_label: scope.value.trim(),
            coverage: coverage.value.trim(), status: status.value }, '已儲存來源盤點') }, '儲存盤點')));
    }
    function webhookPanel(data) {
      const configured = !!(data.webhook_status && data.webhook_status.configured);
      return section('管理員通知', '正式監控警示會建立事件案並以 HMAC 簽章 HTTPS Webhook 投遞',
        [note(configured ? 'Webhook 已在 VM 設定；密鑰不會顯示於網頁。' :
          'Webhook 尚未設定。VM 管理員可執行 /opt/oracle-mon/admin/configure-behavior-webhook.sh。'),
        data.webhook_status && data.webhook_status.recent ?
          note('最近投遞：' + data.webhook_status.recent.status + '；' + (data.webhook_status.recent.delivered_at || '尚未成功')) : null]);
    }
    function auditPanel(data) {
      const rows = data.audit || [];
      return section('操作稽核', '只記錄時間、操作者、動作與目標；不保存密鑰或原始日誌',
        rows.length ? table(['時間（UTC）', '操作者', '動作', '目標'], rows.map((x) =>
          [x.occurred_at, x.actor, x.action, x.subject])) : note('尚無操作紀錄。'));
    }

    // 啟用監控：先看資料量與涵蓋度（唯讀預覽），管理員確認後伺服器才用現有資料訓練基線並啟用
    async function enableMonitoring(a, schedText, sched) {
      let b;
      try { b = (await call({ action: 'preview_baseline', asset_id: Number(a.id) })).baseline; }
      catch (e) { toast(e.message, true); return; }
      if (!b.ok) { toast('資料量還不夠，無法啟用監控：' + b.reasons.join('；'), true); return; }
      const text = ['啟用監控會用現有資料訓練基線：',
        '・' + b.windows + ' 個完整五分鐘區間（涵蓋 ' + b.days + ' 天，其中 ' + b.active_windows + ' 個有連線）',
        '・涵蓋 ' + b.offhours_buckets + ' 個離峰時段；沒涵蓋的時段不判斷',
        ...b.warnings.map((x) => '注意：' + x),
        '上班時段：' + schedText + (sched.source === 'builtin' ? '\n注意：這個資產還沒有自訂時段，離峰判斷依內建預設；夜班或週末作業請先到「上班時段」設定。' : ''),
        '', '確定啟用監控？'].join('\n');
      if (window.confirm(text)) await change({ action: 'set_mode', asset_id: Number(a.id), mode: 'monitoring' }, '已啟用監控');
    }
    async function detail(id) {
      const seq = ++requestSeq;
      selected = id;
      root.replaceChildren(note('載入裝置歷程中…'));
      try {
        const result = (await call({ action: 'detail', asset_id: Number(id) })).detail;
        if (seq !== requestSeq || selected !== id) return;
        const a = result.asset;
        const sched = result.schedule || {};
        const hhmm = (t) => String(t || '').slice(0, 5);
        const schedSource = { device: '設備', site: '站點', default: '自訂的預設', builtin: '內建預設（尚未自訂）' };
        const schedText = sched.timezone ? sched.timezone + '，工作日 ' + sched.workdays + '，' + hhmm(sched.start_time) + '–' + hhmm(sched.end_time)
          + '（來源：' + (schedSource[sched.source] || sched.source) + '）' : '—';
        const unconfirmed = !a.device_id && !a.confirmed_at;
        const modeBar = canHandle ? h('div', { class: 'toolbar' },
          h('button', { class: 'btn', type: 'button', disabled: a.mode === 'learning', onclick: () => {
            if (window.confirm('重新學習會重設學習起點並清除已知目的地，之後訓練只用重設之後的資料；監控中的會改回學習中，需要重新按「啟用監控」。確定？'))
              change({ action: 'set_mode', asset_id: Number(id), mode: 'learning' }, '已改為重新學習');
          } }, '重新學習'),
          h('button', { class: 'btn primary', type: 'button', disabled: a.mode === 'monitoring' || unconfirmed, title: unconfirmed ? '請先確認為受管主機' : null,
            onclick: () => enableMonitoring(a, schedText, sched) }, '啟用監控')) : null;
        const confirmName = h('input', { 'aria-label': '主機名稱（選填）', placeholder: '主機名稱（選填）：填用途或編號，勿填人名' });
        root.replaceChildren(h('div', { class: 'toolbar' }, h('button', { class: 'btn', type: 'button', onclick: load }, '返回清單'),
          h('strong', null, a.name + ' · ' + (modeName[a.mode] || a.mode))),
        section('裝置身分與模式', '身分來源：' + a.identity_key,
          [line('身分', kindName(a) + (a.confirmed_at ? '（' + a.confirmed_at + ' UTC 由 ' + a.confirmed_by + ' 確認：' + a.confirm_note + '）' : '')),
            line('資料品質', qualityName[a.quality_status] || a.quality_status), line('學習起點（UTC）', a.learning_started_at),
            line('基線版本', a.baseline_version), line('上班時段', schedText), modeBar,
            canHandle && confirmable(a) ? h('div', { class: 'toolbar' }, confirmName,
              h('button', { class: 'btn', type: 'button', onclick: () => {
                const reason = window.prompt('請填寫確認依據：DHCP 租約、資產清冊或人工核對結果（勿填機密）');
                if (reason && reason.trim() && window.confirm('確認這是受管主機？確認後可訓練基線並啟用監控，既有的觀測紀錄會保留。名稱與位址會出現在事件案，檢視者也看得到。'))
                  change({ action: 'confirm_hosts', items: [{ id: Number(id), name: confirmName.value.trim() }], note: reason.trim() }, '已確認為受管主機');
              } }, '確認為受管主機')) : null]),
        section('IP 使用歷程', '依最近看到的時間排列，第一列是目前位址',
          result.addresses.length ? table(['位址', '首次', '最近', '依據'], result.addresses.map((x) =>
            [x.address, x.first_seen_at, x.last_seen_at, x.evidence_kind])) : note('沒有位址紀錄。')),
        section('警示與模擬警示', '舊版的模擬警示僅供參考，不建立事件案或傳送 Webhook；指標顯示目前值與基線門檻',
          result.alerts.length ? table(['時間', '狀態', '類型', '分數', '說明與觸發指標', '事件案', '通知', '處置'], result.alerts.map((x) => {
            let m = {};
            try { m = JSON.parse(x.metrics_json || '{}'); } catch (_) { /* 舊資料缺指標 */ }
            const metrics = [m.bytes == null ? null : '位元組 ' + fmt(m.bytes) + '／門檻 ' + fmt(m.byte_limit),
              m.connections == null ? null : '連線 ' + fmt(m.connections) + '／基線 P95 ' + fmt(m.baseline_p95_connections),
              m.destinations == null ? null : '目的地 ' + fmt(m.destinations) + '／門檻 ' + fmt(m.destination_limit)]
              .filter(Boolean).join('；');
            return [x.window_start, x.status, x.detector, x.score,
              h('span', null, x.summary + (metrics ? '。' + metrics : '')),
              x.case_id ? h('button', { class: 'btn', type: 'button', onclick: () => openCases(x.case_id) }, '#' + x.case_id) : '—',
              x.delivery_status === 'failed' && x.status === 'open' ? h('button', { class: 'btn', type: 'button',
                onclick: () => change({ action: 'retry_delivery', alert_id: Number(x.id) }, '已排入通知重試') }, '失敗，重試') :
                (x.delivery_status || '—'),
              canHandle && ['open', 'acknowledged'].includes(x.status) ? h('div', { class: 'toolbar' },
                ...(x.status === 'open' ? [['acknowledged', '確認']] : []).concat([['resolved', '結案']]).map(([status, label]) =>
                  h('button', { class: 'btn', type: 'button', onclick: () => {
                    const reason = window.prompt('請填寫「' + label + '」理由（不含機密資訊）');
                    if (reason && reason.trim()) change({ action: 'set_alert_status', alert_id: Number(x.id),
                      status, reason: reason.trim() }, '警示已' + label);
                  } }, label))) : '—'];
          })) : note('尚無警示。')),
        section('最近 50 個五分鐘區間', '資料不足或來源故障不得視為正常；完整 24 小時資料保留於後端',
          result.windows.length ? table(['時間', '來源', '連線', '位元組', '目的地', '品質'], result.windows.slice(0, 50).map((x) =>
            [x.window_start, x.source_key, fmt(x.connections), fmt(x.bytes_total), fmt(x.destinations),
              (qualityName[x.quality] || x.quality) + (x.quality_reason ? '：' + x.quality_reason : '')])) : note('尚無統計。')),
        section('操作與生命週期歷程', '模式、位址及基線異動保留時間與依據',
          result.history.length ? table(['時間', '動作', '操作者', '摘要'], result.history.map((x) =>
            [x.occurred_at, x.action, x.actor, x.detail])) : note('尚無歷程。')));
      } catch (e) {
        if (seq === requestSeq) root.replaceChildren(h('div', { class: 'alert' }, e.message),
          h('button', { class: 'btn', type: 'button', onclick: load }, '返回清單'));
      }
    }

    // 畫面框架：概況＋分頁籤；換分頁籤只重畫，不重新向伺服器取概況
    function draw(data) {
      const sourceReady = (data.sources || []).some((s) => s.source_key === 'fortigate_graylog' && s.status === 'ready');
      const tabs = h('div', { class: 'toolbar', role: 'tablist' }, [['lifecycle', '裝置與待確認'], ['confirmed', '已確認主機']].map(([key, label]) =>
        h('button', { class: 'btn' + (tab === key ? ' primary' : ''), type: 'button', role: 'tab', 'aria-selected': tab === key ? 'true' : 'false',
          onclick: () => { tab = key; draw(data); } }, label)));
      root.replaceChildren(h('div', { class: 'toolbar' }, h('button', { class: 'btn', type: 'button', onclick: load }, '重新整理')),
        h('div', { class: 'alert' }, '試行來源為 FortiGate 防火牆日誌；尚未接入被動式感測器時，同網段流量不可見。443 埠不等於已確認 HTTPS，沒有 DNS／TLS 欄位時不顯示網站名稱。'),
        summary(data), ...(data.limited ? [note('檢視者僅可查看去識別摘要。')] :
          [tabs, ...(tab === 'confirmed' ? [listPanel('confirmed', sourceReady)]
            : [sourcePanel(data), listPanel('lifecycle', sourceReady), schedulePanel(data), inventoryPanel(), webhookPanel(data), auditPanel(data)])].filter(Boolean)));
    }
    async function load() {
      const seq = ++requestSeq;
      selected = null;
      root.replaceChildren(note('載入裝置行為資料中…'));
      try {
        const data = (await call({ action: 'get' })).behavior;
        if (seq !== requestSeq) return;
        Object.assign(modeName, data.mode_names || {});
        draw(data);
      } catch (e) { if (seq === requestSeq) root.replaceChildren(unavailable(e.message)); }
    }
    return { load };
  };
})();
