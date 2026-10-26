/* 裝置行為工作台。所有遠端文字都交給 h()/textContent，沒有 innerHTML。 */
(() => {
  'use strict';
  window.IocBehavior = ({ h, section, table, api, root, toast, canHandle, unavailable, openCases }) => {
    let selected = null;
    let requestSeq = 0;
    const call = (body) => api('/ioc-behavior.php', body);
    const note = (value) => h('p', { class: 'note' }, value);
    const line = (label, value) => h('div', { class: 'rank' }, h('span', { class: 'nm' }, label), h('span', null, value == null ? '—' : String(value)));
    const modeName = {};      // 由伺服器的 mode_names 填（load()），與關聯預覽的提醒用同一份
    const qualityName = { ready: '資料足夠', learning: '學習中', complete: '完整', partial: '不完整', missing: '來源缺資料', error: '來源錯誤', no_data: '尚無資料' };
    const fmt = (n) => Number(n || 0).toLocaleString('zh-TW');

    // 可以接收關聯的 LibreNMS 設備資產（load() 時更新）；關聯用 LibreNMS 的設備 ID
    let deviceOptions = [];
    async function change(body, message) {
      try { await call(body); toast(message); await load(); }
      catch (e) { toast(e.message, true); }
    }
    // 關聯：先問伺服器合併會動到什麼（唯讀預覽），管理員看過再確認；確認時伺服器照同一份計畫做
    async function linkFlow(assetId, deviceId) {
      if (!Number(deviceId)) { toast('請先選擇要關聯的 LibreNMS 設備', true); return; }
      let plan;
      try { plan = (await call({ action: 'preview_link', asset_id: Number(assetId), device_id: Number(deviceId) })).plan; }
      catch (e) { toast(e.message, true); return; }
      const w = plan.windows; const k = plan.known; const a = plan.addresses;
      const text = ['把「' + plan.observed.name + '」（' + (plan.observed.confirmed ? '已確認主機' : '待確認') + '）的歷史合併到設備「'
          + plan.device.name + '」（' + (modeName[plan.device.mode] || plan.device.mode) + '）：',
        '・五分鐘區間：複製 ' + w.copy + '、取代設備缺資料的 ' + w.replace + '、兩邊都有流量而合併 ' + w.combine + '、不動 ' + w.keep,
        '・已知目的地：新增 ' + k.new + '、併入 ' + k.merge + '；位址：新增 ' + a.new,
        plan.learning_started_after ? '・設備的學習起點提前到 ' + plan.learning_started_after + ' UTC' : null,
        ...plan.warnings.map((x) => '注意：' + x),
        '', '這台會停用（紀錄保留可追溯）；關聯後，它的位址的流量算在這台設備上。確定關聯？'].filter((x) => x !== null).join('\n');
      if (!window.confirm(text)) return;
      const reason = window.prompt('請填寫 DHCP 租約、MAC／VLAN 或人工核對依據（勿填機密）');
      if (!reason || !reason.trim()) return;
      await change({ action: 'link_asset', asset_id: Number(assetId), device_id: Number(deviceId), reason: reason.trim(), plan_token: plan.token }, '已關聯裝置');
    }
    function summary(data) {
      const s = data.summary || {};
      return section('裝置行為概況', '學習 28 天後，由管理員確認資料品質並啟用監控',
        h('div', { class: 'sumrow' },
          ...[['學習中', s.learning], ['待啟用', s.ready], ['監控中', s.monitoring], ['開啟警示', s.open_alerts], ['來源異常', s.source_error]]
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
    const observedAsset = (a) => !a.device_id && a.mode !== 'retired' && String(a.identity_key || '').startsWith('observed:');
    const confirmable = (a) => observedAsset(a) && !a.confirmed_at;
    const linkable = observedAsset;       // 已確認的主機也能關聯：關聯會把歷史（含基線用的區間）合併到設備
    function assetPanel(data) {
      const assets = data.assets || [];
      const sourceReady = (data.sources || []).some((s) => s.source_key === 'fortigate_graylog' && s.status === 'ready');
      const boxes = new Map();
      const rows = assets.map((a) => {
        let box = '';
        if (canHandle && confirmable(a)) {
          box = h('input', { type: 'checkbox', 'aria-label': '選取 ' + a.name });
          boxes.set(a.id, box);
        }
        return [...(canHandle ? [box] : []),
          h('button', { class: 'btn', type: 'button', onclick: () => detail(a.id) }, a.name), kindName(a),
          a.address || '未確認', a.site_name || '未歸屬', modeName[a.mode] || a.mode,
          sourceReady ? (qualityName[a.quality_status] || a.quality_status) : '來源異常／未驗證', a.last_seen_at || '尚無資料'];
      });
      const confirmBar = canHandle && boxes.size ? h('div', { class: 'toolbar' },
        h('button', { class: 'btn', type: 'button', onclick: () => { for (const b of boxes.values()) b.checked = true; } }, '全選待確認'),
        h('button', { class: 'btn primary', type: 'button', onclick: () => {
          const ids = [...boxes].filter(([, b]) => b.checked).map(([id]) => Number(id));
          if (!ids.length) { toast('請先勾選要確認的主機', true); return; }
          const reason = window.prompt('請填寫確認依據：DHCP 租約、資產清冊或人工核對結果（勿填機密）');
          if (reason && reason.trim() && window.confirm('確認這 ' + ids.length + ' 台是受管主機？確認後可訓練基線並啟用監控，既有的觀測紀錄會保留。位址會出現在事件案，檢視者也看得到。'))
            change({ action: 'confirm_hosts', items: ids.map((id) => ({ id })), note: reason.trim() }, '已確認 ' + ids.length + ' 台主機');
        } }, '確認勾選的主機為受管主機')) : null;
      return section('裝置生命週期', '未知 IP 先是「待確認」的暫時身分；管理員確認身分（關聯 LibreNMS 設備，或確認為受管主機）後才能訓練基線與啟用監控',
        assets.length ? [confirmBar, table((canHandle ? ['選取'] : []).concat(['裝置', '身分', '目前位址', '站點', '模式', '資料品質', '最近觀測']), rows)]
          : note('尚未同步裝置。工作器啟用後會從 LibreNMS 載入設備。'));
    }
    function suggestionPanel(data) {
      const rows = data.link_suggestions || [];
      if (!canHandle || !rows.length) return null;
      return section('建議關聯', '這些主機的位址等於某台 LibreNMS 設備最近的位址，可能是同一台。只提示、不會自動合併：DHCP 可能已把位址租給別台，請核對後再關聯。',
        table(['主機', '身分', '位址', '可能是這台設備', ''], rows.map((x) => [
          h('button', { class: 'btn', type: 'button', onclick: () => detail(x.observed_id) }, x.observed_name),
          x.observed_confirmed ? '已確認主機' : '待確認', x.address, x.device_name,
          h('button', { class: 'btn', type: 'button', onclick: () => linkFlow(x.observed_id, x.device_id) }, '預覽並關聯')])));
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
              // 先問伺服器這份時段會讓幾台資產重新學習，有影響才請管理員確認（變動會暫停監控 28 天）
              const impact = (await call({ ...body, action: 'preview_schedule' })).impact;
              if (impact.changed && impact.ready + impact.monitoring > 0 && !window.confirm('儲存後，已有基線而且套用這個時段的資產會重新學習 28 天：就緒 '
                + impact.ready + ' 台、監控中 ' + impact.monitoring + ' 台（監控會暫停，期間不判斷離峰異常）。確定儲存？')) return;
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
        const modeBar = canHandle ? h('div', { class: 'toolbar' },
          ...[['learning', '重新學習 28 天'], ['monitoring', '啟用監控'], ['retired', '停用追蹤']].map(([mode, label]) =>
            h('button', { class: 'btn', type: 'button', disabled: a.mode === mode,
              onclick: () => {
                // 啟用監控前要看到離峰判斷依哪個上班時段；還在用內建預設就特別提醒，免得夜班或週末作業被當成異常
                const warn = mode === 'monitoring' ? '\n上班時段：' + schedText + (sched.source === 'builtin' ? '\n注意：這個資產還沒有自訂時段，離峰判斷依內建預設；夜班或週末作業請先到「上班時段」設定。' : '') : '';
                if (window.confirm('確定將此裝置切換為「' + label + '」？' + warn)) change({ action: 'set_mode', asset_id: Number(id), mode }, '模式已更新');
              } }, label))) : null;
        const linkId = h('select', { 'aria-label': '要關聯的 LibreNMS 設備' }, h('option', { value: '' }, '選擇 LibreNMS 設備'),
          ...deviceOptions.map((x) => h('option', { value: String(x.device_id) }, x.name + '（#' + x.device_id + '）')));
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
              } }, '確認為受管主機')) : null,
            // 待確認與已確認的主機都能關聯 LibreNMS 設備（先預覽合併內容）；LibreNMS 設備本來就不必關聯
            canHandle && linkable(a) ? h('div', { class: 'toolbar' }, linkId,
              h('button', { class: 'btn', type: 'button', onclick: () => linkFlow(id, linkId.value) }, '預覽並關聯')) : null]),
        section('人工身分關聯', '關聯會把暫時資產的歷史合併到設備；原始暫時資產與五分鐘活動紀錄保留，可由此追溯',
          result.links.length ? table(['暫時資產', '目標資產', '確認時間', '確認者', '依據'], result.links.map((x) =>
            [h('button', { class: 'btn', type: 'button', onclick: () => detail(x.observed_asset_id) },
              '#' + x.observed_asset_id + ' ' + x.observed_name),
            '#' + x.target_asset_id, x.confirmed_at, x.confirmed_by, x.evidence_note])) : note('尚無人工關聯。')),
        section('IP 使用歷程', '關聯需可信 DHCP／MAC／VLAN 證據或管理者確認',
          result.addresses.length ? table(['位址', '首次', '最近', '依據'].concat(canHandle && a.device_id ? [''] : []), result.addresses.map((x) =>
            [x.address, x.first_seen_at, x.last_seen_at, x.evidence_kind].concat(canHandle && a.device_id ? [x.evidence_kind === 'admin_confirmed'
              ? h('button', { class: 'btn', type: 'button', onclick: () => {
                if (window.confirm('解除位址 ' + x.address + '？之後這個位址的流量不再算給這台設備（變回待確認的暫時主機）；已經合併進來的歷史不會收回。'))
                  change({ action: 'unlink_address', asset_id: Number(id), address: x.address }, '已解除位址');
              } }, '解除') : ''] : []))) : note('沒有位址紀錄。')),
        section('警示與模擬警示', '學習模式僅保留模擬結果，不建立事件案或傳送 Webhook；指標顯示目前值與基線門檻',
          result.alerts.length ? table(['時間', '狀態', '類型', '分數', '說明與觸發指標', '事件案', '通知', '處置'], result.alerts.map((x) => {
            let m = {};
            try { m = JSON.parse(x.metrics_json || '{}'); } catch (_) { /* 舊資料缺指標 */ }
            const metrics = [m.bytes == null ? null : '位元組 ' + fmt(m.bytes) + '／門檻 ' + fmt(m.byte_limit),
              m.connections == null ? null : '連線 ' + fmt(m.connections) + '／基線 P95 ' + fmt(m.baseline_p95_connections),
              m.destinations == null ? null : '目的地 ' + fmt(m.destinations) + '／門檻 ' + fmt(m.destination_limit),
              m.source_ip ? '流量來源位址 ' + m.source_ip : null]
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
        section('操作與生命週期歷程', 'IP、模式及基線異動保留時間與依據',
          result.history.length ? table(['時間', '動作', '操作者', '摘要'], result.history.map((x) =>
            [x.occurred_at, x.action, x.actor, x.detail])) : note('尚無歷程。')));
      } catch (e) {
        if (seq === requestSeq) root.replaceChildren(h('div', { class: 'alert' }, e.message),
          h('button', { class: 'btn', type: 'button', onclick: load }, '返回清單'));
      }
    }
    async function load() {
      const seq = ++requestSeq;
      selected = null;
      root.replaceChildren(note('載入裝置行為資料中…'));
      try {
        const data = (await call({ action: 'get' })).behavior;
        if (seq !== requestSeq) return;
        deviceOptions = data.devices || [];      // 伺服器另外給齊（資產清單只有最新 300 筆）
        Object.assign(modeName, data.mode_names || {});
        root.replaceChildren(h('div', { class: 'toolbar' }, h('button', { class: 'btn', type: 'button', onclick: load }, '重新整理')),
          h('div', { class: 'alert' }, '試行來源為 FortiGate 防火牆日誌；尚未接入被動式感測器時，同網段流量不可見。443 埠不等於已確認 HTTPS，沒有 DNS／TLS 欄位時不顯示網站名稱。'),
          summary(data), ...(data.limited ? [note('檢視者僅可查看去識別摘要。')] :
            [sourcePanel(data), assetPanel(data), suggestionPanel(data), schedulePanel(data), inventoryPanel(), webhookPanel(data), auditPanel(data)].filter(Boolean)));
      } catch (e) { if (seq === requestSeq) root.replaceChildren(unavailable(e.message)); }
    }
    return { load };
  };
})();
