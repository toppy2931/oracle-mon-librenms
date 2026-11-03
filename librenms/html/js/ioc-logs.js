(function () {
  'use strict';

  /**
   * 下載伺服器產生的 CSV（日誌查詢與報表共用）。一般的連結下載帶不了 CSRF 標頭，所以用 fetch 取回再存檔。
   * 檔名只收伺服器回的固定格式（英數字與 . _ -），其他一律用預設名稱。
   * @returns {Promise<{ok:true,name:string}|{ok:false,messages:string[]}>}
   */
  window.IocCsvDownload = async function (csrf, url, body) {
    try {
      const res = await fetch(url, { method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      if (!res.ok) {
        let data = null;
        try { data = await res.json(); } catch (e) { /* 不是 JSON */ }
        return { ok: false, messages: data && data.errors && data.errors.length ? data.errors : [(data && data.error) || ('伺服器回應 HTTP ' + res.status)] };
      }
      const name = ((res.headers.get('Content-Disposition') || '').match(/filename="([A-Za-z0-9._-]+)"/) || [])[1] || 'ioc-export.csv';
      const href = URL.createObjectURL(await res.blob());
      const a = document.createElement('a');
      a.href = href; a.download = name;
      document.body.append(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(href), 1000);
      return { ok: true, name };
    } catch (e) {
      return { ok: false, messages: [e.message] };
    }
  };
})();

(function () {
  'use strict';

  /**
   * 日誌查詢頁（規格 LOG）。admin 與 global-read 都能查詢與匯出 CSV（匯出一律留紀錄）。
   * 畫面一律用 DOM＋textContent：日誌內容來自外部，不能經過 innerHTML。
   * 查詢條件由伺服器（LogQuery）驗證並組成查詢；這裡只負責收集欄位與顯示。Graylog 取不到要寫「無法取得」與原因，不能顯示成空表。
   */
  window.IocLogs = function (cfg) {
    const { h, table, api, root, toast, unavailable, canHandle, demo, csrf, labels, openCaseForm } = cfg;
    const RANGES = [[3600, '最近 1 小時'], [21600, '最近 6 小時'], [86400, '最近 24 小時'], [604800, '最近 7 日'], ['custom', '自訂時間範圍（最長 7 日）']];
    let built = false;
    let seq = 0;
    let lastFilters = null;
    const f = {};
    const resultBox = h('div', null);
    const msg = h('div', { class: 'note warn', role: 'alert' });

    const options = (all, extra) => [h('option', { value: '' }, '全部')].concat(
      Object.entries(all).map(([code, text]) => h('option', { value: code }, extra ? extra(code, text) : text)));
    const sevText = (code, text) => (code === 'info' ? text + '（資訊）' : text);
    const field = (label, control) => h('div', null, h('label', null, label), control);

    /** 台北時間的 datetime-local 值 → UTC ISO 8601；空的或不合法回傳 null */
    const toUtcIso = (local) => {
      if (!local) return null;
      const d = new Date((local.length === 16 ? local + ':00' : local) + '+08:00');     // datetime-local 預設不帶秒，帶了就不再補
      return Number.isNaN(d.getTime()) ? null : d.toISOString();
    };

    function collect() {
      const body = {};
      if (f.range.value === 'custom') {
        const from = toUtcIso(f.from.value);
        const to = toUtcIso(f.to.value);
        if (!from || !to) throw new Error('自訂時間範圍要填開始與結束時間');
        body.from = from; body.to = to;
      } else {
        body.range_seconds = Number(f.range.value);
      }
      ['log_type', 'event_action', 'severity', 'user_name', 'src_ip', 'dst_ip', 'host_name', 'keyword'].forEach((k) => {
        if (!f[k]) return;                       // 關鍵字欄位只有管理者有（比對原始訊息，伺服器端也會擋）
        const v = f[k].value.trim();
        if (v !== '') body[k] = v;
      });
      return body;
    }

    /** 這一筆日誌的 Graylog 查詢條件（建立事件案帶進去，詳情頁的證據時間軸靠它） */
    const rowQuery = (r) => {
      const q = (field, value) => field + ':"' + String(value).replace(/[\\"]/g, '\\$&') + '"';
      return [r.user_name ? q('user_name', r.user_name) : null, r.src_ip ? q('src_ip', r.src_ip) : null, r.event_action ? q('event_action', r.event_action) : null]
        .filter(Boolean).join(' AND ');
    };

    function caseButton(r) {
      if (!canHandle || !openCaseForm) return null;
      return h('button', { class: 'btn', type: 'button', onclick: () => openCaseForm({
        title: '日誌事件：' + r.action_label + (r.src_ip ? '（' + r.src_ip + '）' : ''),
        severity: ['high', 'medium', 'low'].includes(r.severity) ? r.severity : 'medium',
        subject_user: r.user_name, subject_ip: r.src_ip, subject_host: r.host_name, evidence_query: rowQuery(r),
        summary: '從日誌查詢建立：' + r.time + '　' + r.log_type_label + '　' + r.action_label,
      }) }, '建立事件案');
    }

    function renderResult(r) {
      if (!r.ok) return [unavailable(r.error)];
      const d = r.data;
      const out = [h('p', { class: 'note', style: 'margin:4px 0' },
        '查詢範圍：' + d.window.from + ' ～ ' + d.window.to + '（台北時間）；條件：' + d.summary + '；符合 ' + Number(d.total).toLocaleString('zh-TW') + ' 筆。')];
      if (!d.items.length) {
        out.push(h('p', { class: 'note' }, '這段時間沒有符合條件的日誌。可能是對應的來源尚未接入，或日誌已超過 Graylog 的保存期限；不代表沒有事件。'));
        return out;
      }
      if (d.truncated) out.push(h('p', { class: 'note warn' }, '只列最新 ' + d.items.length + ' 筆，請縮小時間範圍或加上條件（匯出 CSV 最多 5000 筆）。'));
      const sevBadge = (x) => (x.severity_label ? h('span', { class: 'badge b-' + ({ '高': 'red', '中': 'yellow', '低': 'green' }[x.severity_label] || 'gray') }, x.severity_label) : '—');
      const device = (x) => (!x.host_name ? '—' : (x.device_id ? h('a', { href: '/device/' + encodeURIComponent(x.device_id) + '/', target: '_blank', rel: 'noopener' }, x.host_name) : x.host_name));
      out.push(table(['時間', '來源類型', '發生狀況', '等級', '帳號', '來源位址', '目的位址', '設備', canHandle ? '' : null].filter((x) => x !== null),
        d.items.map((x) => [x.time, x.log_type_label, x.action_label, sevBadge(x), x.user_name || '—', x.src_ip || '—', x.dst_ip || '—', device(x), canHandle ? caseButton(x) : null]
          .filter((c, i) => canHandle || i < 8))));
      out.push(h('p', { class: 'note' }, '只列標準化後的欄位，不含原始訊息（可能含密碼等不該擴散的內容）；要看原始日誌請到 Graylog，查詢條件：' + d.query));
      return out;
    }

    async function search() {
      const mine = ++seq;
      msg.textContent = '';
      let body;
      try { body = collect(); } catch (e) { msg.textContent = e.message; return; }
      lastFilters = body;
      resultBox.replaceChildren(h('p', { class: 'note' }, '查詢中…（要查兩次 Graylog，可能要幾秒）'));
      try {
        const res = await api('/ioc-logs.php', Object.assign({ action: 'search', demo }, body));
        if (mine === seq) resultBox.replaceChildren(...renderResult(res.logs));
      } catch (e) {
        if (mine !== seq) return;
        resultBox.replaceChildren();
        msg.replaceChildren(...(e.errors && e.errors.length ? e.errors : [e.message]).map((t) => h('div', null, t)));
      }
    }

    /** 匯出：用共用的下載函式（fetch 帶 CSRF 標頭取回 CSV 再存檔）。伺服器先記匯出紀錄才給檔案。 */
    async function exportCsv() {
      msg.textContent = '';
      let body;
      try { body = collect(); } catch (e) { msg.textContent = e.message; return; }
      f.exportBtn.disabled = true;
      try {
        const r = await window.IocCsvDownload(csrf, '/ioc-logs.php', Object.assign({ action: 'export', demo }, body));
        if (r.ok) toast('已匯出 ' + r.name + '（匯出已留紀錄）');
        else msg.replaceChildren(...r.messages.map((t) => h('div', null, t)));
      } finally {
        f.exportBtn.disabled = false;
      }
    }

    function build() {
      f.range = h('select', { 'aria-label': '時間範圍', onchange: () => { custom.hidden = f.range.value !== 'custom'; } },
        RANGES.map(([v, t]) => h('option', { value: String(v), selected: v === 86400 }, t)));
      f.from = h('input', { type: 'datetime-local', 'aria-label': '開始時間（台北時間）' });
      f.to = h('input', { type: 'datetime-local', 'aria-label': '結束時間（台北時間）' });
      const custom = h('div', { class: 'row', hidden: true }, h('span', null, '自訂範圍（台北時間）'), h('span', null, f.from, ' ～ ', f.to));
      f.log_type = h('select', null, options(labels.logType));
      f.event_action = h('select', null, options(labels.action));
      f.severity = h('select', null, options(labels.logSeverity, sevText));
      f.user_name = h('input', { type: 'text', maxlength: 100, placeholder: '完全相符，例如 administrator' });
      f.src_ip = h('input', { type: 'text', maxlength: 45, placeholder: '完整 IP，例如 10.20.5.23' });
      f.dst_ip = h('input', { type: 'text', maxlength: 45, placeholder: '完整 IP' });
      f.host_name = h('input', { type: 'text', maxlength: 100, placeholder: '設備名稱，完全相符' });
      if (canHandle) f.keyword = h('input', { type: 'text', maxlength: 100, placeholder: '在日誌內文中找這個詞（當成文字，不是查詢語法）' });
      f.exportBtn = h('button', { class: 'btn', type: 'button', onclick: exportCsv }, '匯出 CSV');
      const form = h('form', { onsubmit: (ev) => { ev.preventDefault(); search(); } },
        h('div', { class: 'sumrow c4' },
          field('時間範圍', f.range), field('來源類型', f.log_type), field('發生狀況', f.event_action), field('等級', f.severity),
          field('帳號', f.user_name), field('來源位址', f.src_ip), field('目的位址', f.dst_ip), field('設備', f.host_name)),
        custom,
        canHandle ? h('div', null, h('label', null, '關鍵字（管理者；比對原始訊息的內容）'), f.keyword) : null,
        h('div', { class: 'toolbar', style: 'margin-top:10px' },
          h('button', { class: 'btn primary', type: 'submit' }, '查詢'),
          h('button', { class: 'btn', type: 'button', onclick: () => { form.reset(); custom.hidden = true; resultBox.replaceChildren(); msg.textContent = ''; } }, '清除'),
          f.exportBtn,
          h('span', { class: 'note', style: 'margin:0' }, '匯出最多 5000 筆，每次匯出都會留紀錄（誰、何時、條件、筆數）。')),
        msg);
      root.replaceChildren(
        h('div', { class: 'card' }, h('div', { class: 'card-h' }, h('h2', null, '日誌查詢'), h('span', null, demo ? '範例資料模式：只查範例資料' : '來源：Graylog（唯讀）')), h('div', { class: 'card-b' }, form)),
        h('div', { class: 'card', style: 'margin-top:14px' }, h('div', { class: 'card-h' }, h('h2', null, '查詢結果'), h('span', null, '新到舊')), h('div', { class: 'card-b' }, resultBox)));
      built = true;
    }

    return {
      /** 切進這一頁：第一次建好表單並用預設條件查一次；之後沿用，不重複打 Graylog */
      load() {
        if (!built) { build(); search(); }
      },
    };
  };
})();
