(function () {
  'use strict';

  /**
   * 跨來源關聯建議（規格 EVT-005），放在「資安」頁。規則式、只給建議：管理者按「建立事件案」才會帶入表單，不自動建案。
   * 每條規則各自成敗：取不到就寫原因，不能顯示成「沒有建議」。沒有建議不代表沒事，畫面要寫出來。
   * 畫面一律用 DOM＋textContent。
   */
  window.IocCorrelation = function (cfg) {
    const { h, table, api, box, unavailable, demo, canHandle, openCaseForm } = cfg;
    let seq = 0;
    const LEVEL = { high: ['高', 'red'], medium: ['中', 'yellow'], low: ['低', 'green'] };

    function render(c) {
      const out = [];
      out.push(h('ul', { class: 'src-list' }, Object.values(c.rules).map((r) => h('li', null,
        (r.ok ? '✓ ' : '✗ ') + r.label + (r.ok ? '：' + r.count + ' 項建議' + (r.partial ? '（來源很多，只分析了最多的前 100 個，可能還有沒列進來的）' : '') : '：無法取得（' + (r.error || '') + '）')))));
      if (!c.items.length) {
        const failed = Object.values(c.rules).some((r) => !r.ok);
        const partial = Object.values(c.rules).some((r) => r.ok && r.partial);
        out.push(h('p', { class: 'note' }, failed || partial
          ? '沒有可顯示的建議，但有規則取不到資料或只分析了一部分來源（見上面），所以不能說沒有可疑情況。'
          : c.range_label + '沒有符合規則的關聯建議。這不代表沒事：來源沒接入、規則沒涵蓋的情況都看不到。'));
        return out;
      }
      out.push(table(['風險', '建議', '說明', '規則'].concat(canHandle ? [''] : []), c.items.map((x) => {
        const [text, color] = LEVEL[x.severity] || ['—', 'gray'];
        const btn = canHandle && openCaseForm
          ? h('button', { class: 'btn', type: 'button', onclick: () => openCaseForm({ title: x.title, severity: x.severity, category: x.category, subject_ip: x.subject_ip || '',
              target_host: x.target_host || '', evidence_query: x.evidence_query, summary: x.summary }) }, '建立事件案')
          : null;
        return [h('span', { class: 'badge b-' + color }, text), x.title, x.summary, x.rule_label].concat(canHandle ? [btn] : []);
      })));
      if (c.total > c.items.length) out.push(h('p', { class: 'note warn' }, '只列前 ' + c.items.length + ' 項，共 ' + c.total + ' 項。'));
      out.push(h('p', { class: 'note' }, '規則與門檻是提案版（可依現場調整）；只比較' + c.range_label + '內有沒有同時出現，不檢查先後順序。這裡只給建議，不會自動建立事件案。'));
      return out;
    }

    return {
      async load() {
        const mine = ++seq;
        box.replaceChildren(h('p', { class: 'note' }, '分析中…（要查幾次 Graylog，可能要幾秒）'));
        try {
          const res = await api('/ioc-correlation.php', { action: 'get', demo });
          if (mine === seq) box.replaceChildren(...render(res.correlation));
        } catch (e) {
          if (mine === seq) box.replaceChildren(unavailable(e.message));
        }
      },
    };
  };
})();
