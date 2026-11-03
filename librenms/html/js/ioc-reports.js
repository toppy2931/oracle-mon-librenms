(function () {
  'use strict';

  /**
   * 報表中心（規格 RPT）。報表是伺服器產生的結構化資料（標題、時間範圍、篩選條件、資料來源、若干區塊），
   * 這裡把它排成可列印的 HTML（瀏覽器「列印」可存成 PDF）；CSV 由伺服器用同一份結構輸出，兩邊內容一致。
   * 每份報表標明資料來源與時間範圍（RPT-005）；取不到的來源與區塊寫「無法取得」與原因，不能顯示成空表（RPT-007）。
   * 畫面一律用 DOM＋textContent。
   */
  window.IocReports = function (cfg) {
    const { h, table, api, root, toast, unavailable, demo, csrf, labels } = cfg;
    const DAYS = [[1, '最近 1 日'], [7, '最近 7 日'], [30, '最近 30 日']];
    let catalog = null;
    let built = false;
    const f = {};
    const sheet = h('div', { class: 'report-sheet' });
    const msg = h('div', { class: 'note warn', role: 'alert' });
    const about = h('p', { class: 'note', style: 'margin:4px 0' });
    let controls = null;
    let lastReport = null;
    let lastBody = null;      // 畫面上這份報表用的條件：列印與匯出都以它為準，不隨下拉選單後來的變動而變
    let seq = 0;              // 連續產生時，只採用最後一次的回應（晚到的舊回應不能畫在新選的報表底下）

    const field = (id, label, control) => h('div', { id: 'rf-' + id }, h('label', null, label), control);

    function currentKey() { return f.report.value; }

    function filterBody() {
      const body = {};
      const wanted = catalog[currentKey()].filters;
      if (wanted.includes('days')) body.days = Number(f.days.value);
      if (wanted.includes('severity') && f.severity.value) body.severity = f.severity.value;
      if (wanted.includes('event') && f.event.value) body.event = f.event.value;
      if (wanted.includes('device') && f.device.value.trim()) body.device = f.device.value.trim();
      return body;
    }

    /** 依目前選的報表顯示或隱藏用得到的篩選欄位（不適用的欄位不出現，免得以為有套用） */
    function syncFilters() {
      const wanted = catalog[currentKey()].filters;
      ['days', 'severity', 'event', 'device'].forEach((k) => { $q('#rf-' + k).hidden = !wanted.includes(k); });
      about.textContent = catalog[currentKey()].about;
    }
    const $q = (sel) => root.querySelector(sel);

    function renderSection(s) {
      const box = h('section', { class: 'report-section' }, h('h3', { class: 'sec-h' }, s.title));
      if (!s.ok) { box.append(unavailable(s.error || '無法取得')); return box; }
      if (!s.rows.length) box.append(h('p', { class: 'note' }, '沒有符合條件的資料。'));
      else box.append(table(s.columns, s.rows.map((r) => r.map((c) => (c === null || c === undefined ? '' : String(c))))));
      if (s.note) box.append(h('p', { class: 'note' }, s.note));
      return box;
    }

    function renderReport(r) {
      lastReport = r;
      const srcs = h('ul', { class: 'src-list' }, r.sources.map((x) => h('li', null, (x.ok ? '✓ ' : '✗ ') + x.name + (x.ok ? '' : '：無法取得（' + (x.error || '') + '）'))));
      sheet.replaceChildren(
        h('header', { class: 'report-head' },
          h('h2', null, r.title),
          h('dl', { class: 'kv' },
            h('dt', null, '產生時間'), h('dd', null, r.generated_at + '（台北時間）'),
            h('dt', null, '時間範圍'), h('dd', null, r.range),
            h('dt', null, '篩選條件'), h('dd', null, r.filters.join('；')),
            h('dt', null, '資料來源'), h('dd', null, srcs))),
        ...r.sections.map(renderSection),
        h('p', { class: 'note' }, '本報表由 IOC 依上列資料來源與時間範圍自動產生' + (demo ? '（範例資料模式：資料不是正式資料）' : '') + '；資料取不到的區塊已標「無法取得」，不代表沒有事件。'));
    }

    async function generate() {
      const mine = ++seq;
      msg.textContent = '';
      lastReport = null; lastBody = null;
      const body = Object.assign({ report: currentKey() }, filterBody());
      sheet.replaceChildren(h('p', { class: 'note' }, '產生中…（會查詢 Graylog 與資料庫，可能要幾秒）'));
      try {
        const res = await api('/ioc-reports.php', Object.assign({ action: 'build', demo }, body));
        if (mine !== seq) return;
        lastBody = body;
        renderReport(res.report);
      } catch (e) {
        if (mine !== seq) return;
        sheet.replaceChildren();
        msg.replaceChildren(...(e.errors && e.errors.length ? e.errors : [e.message]).map((t) => h('div', null, t)));
      }
    }

    async function exportCsv() {
      msg.textContent = '';
      f.exportBtn.disabled = true;
      try {
        if (typeof window.IocCsvDownload !== 'function') { msg.textContent = '下載模組尚未載入，請重新整理頁面'; return; }
        // 有畫面上的報表就用它的條件（匯出的要和看到的、列印的是同一份）；還沒產生過就用目前選的
        const r = await window.IocCsvDownload(csrf, '/ioc-reports.php', Object.assign({ action: 'export', demo }, lastBody || Object.assign({ report: currentKey() }, filterBody())));
        if (r.ok) toast('已匯出 ' + r.name + '（匯出已留紀錄）');
        else msg.replaceChildren(...r.messages.map((t) => h('div', null, t)));
      } finally {
        f.exportBtn.disabled = false;
      }
    }

    function build() {
      f.report = h('select', { 'aria-label': '報表', onchange: () => { ++seq; lastReport = null; lastBody = null; syncFilters(); sheet.replaceChildren(); } },
        Object.entries(catalog).map(([k, v]) => h('option', { value: k }, v.title)));
      f.days = h('select', { 'aria-label': '期間' }, DAYS.map(([v, t]) => h('option', { value: String(v), selected: v === 7 }, t)));
      f.severity = h('select', { 'aria-label': '嚴重度' }, [h('option', { value: '' }, '全部')].concat(Object.entries(labels.caseSeverity).map(([k, t]) => h('option', { value: k }, t))));
      f.event = h('select', { 'aria-label': '事件類型' }, [h('option', { value: '' }, '全部')].concat(Object.entries(labels.caseCategory).map(([k, t]) => h('option', { value: k }, t))));
      f.device = h('input', { type: 'text', maxlength: 100, placeholder: '設備名稱包含…（不分大小寫）', 'aria-label': '設備名稱' });
      f.exportBtn = h('button', { class: 'btn', type: 'button', onclick: exportCsv }, '匯出 CSV');
      controls = h('form', { class: 'report-controls', onsubmit: (ev) => { ev.preventDefault(); generate(); } },
        h('div', { class: 'sumrow c4' }, field('report', '報表', f.report), field('days', '期間', f.days), field('severity', '嚴重度', f.severity),
          field('event', '事件類型', f.event), field('device', '設備', f.device)),
        about,
        h('div', { class: 'toolbar', style: 'margin-top:10px' },
          h('button', { class: 'btn primary', type: 'submit' }, '產生報表'),
          h('button', { class: 'btn', type: 'button', onclick: () => { if (!lastReport) { msg.textContent = '請先產生報表'; return; } window.print(); } }, '列印／另存 PDF'),
          f.exportBtn,
          h('span', { class: 'note', style: 'margin:0' }, '匯出會留紀錄（誰、何時、條件、筆數）。')),
        msg);
      root.replaceChildren(
        h('div', { class: 'card report-controls' }, h('div', { class: 'card-h' }, h('h2', null, '報表中心'), h('span', null, demo ? '範例資料模式' : '依需要產生，不排程')), h('div', { class: 'card-b' }, controls)),
        h('div', { class: 'card', style: 'margin-top:14px' }, h('div', { class: 'card-b' }, sheet)));
      syncFilters();
      built = true;
    }

    return {
      /** 切進這一頁：第一次向伺服器取這個身分能產生的報表清單，再建表單；之後沿用 */
      async load() {
        if (built) return;
        root.replaceChildren(h('p', { class: 'note' }, '載入中…'));
        try {
          catalog = (await api('/ioc-reports.php', { action: 'catalog', demo })).reports;
          build();
        } catch (e) {
          root.replaceChildren(unavailable(e.message));
        }
      },
    };
  };
})();
