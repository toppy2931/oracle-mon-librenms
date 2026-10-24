(function () {
  'use strict';

  window.IocAudit = function (cfg) {
    const { api, root, h, toast, canHandle, csrf } = cfg;
    let selectedCycle = 0;
    let selectedControl = 0;
    let detail = null;
    let previewDigest = null;
    let csvText = '';
    const request = (body) => api('/ioc-audit.php', body);
    const card = (title, ...children) => h('section', { class: 'card', style: 'margin-bottom:12px' },
      h('div', { class: 'card-h' }, h('h2', null, title)), h('div', { class: 'card-b' }, ...children));
    const info = (text) => h('p', { class: 'note' }, text);
    const button = (text, fn, primary) => h('button', { type: 'button', class: 'btn' + (primary ? ' primary' : ''), onclick: fn }, text);
    const field = (title, input) => h('div', null, h('label', null, title), input);
    const input = (type, value, attrs) => h('input', Object.assign({ type, value: value || '' }, attrs || {}));
    const select = (items, value) => h('select', null, items.map(([key, label]) => h('option', { value: key, selected: String(key) === String(value) }, label)));
    const errText = (e) => (e.errors && e.errors.length ? e.errors.join('；') : e.message);
    const run = async (fn, success) => {
      try { await fn(); if (success) toast(success); await load(); }
      catch (e) { toast(errText(e), true); }
    };

    async function load() {
      previewDigest = null; csvText = '';
      root.replaceChildren(info('正在讀取稽核資料…'));
      try {
        const summary = (await request({ action: 'summary' })).summary;
        detail = canHandle ? (await request({ action: 'detail', cycle_id: selectedCycle })).detail : null;
        if (detail && detail.selected_cycle) selectedCycle = detail.selected_cycle;
        render(summary);
      } catch (e) { root.replaceChildren(card('稽核與證據', h('div', { class: 'alert' }, '無法取得稽核資料：' + errText(e)))); }
    }

    function render(summary) {
      const sections = [card('評估摘要', summary.ready ? summaryView(summary) : h('div', { class: 'alert' }, summary.message || '未建立評估基準'))];
      if (canHandle && detail) {
        sections.push(catalogView(), cycleView());
        if (detail.selected_cycle) sections.push(controlView());
        sections.push(adminTrail());
      }
      sections.push(info('本頁保存的是內部稽核工作紀錄；尚未建立正式控制項時不計算符合率。事件案快照最多保存 30 筆標準化日誌，並非完整 Graylog 封存。'));
      root.replaceChildren(...sections);
    }

    function summaryView(s) {
      const n = s.counts;
      const percent = n.total ? Math.round(n.assessed / n.total * 100) : 0;
      const applicable = n.total - n.not_applicable;
      return h('div', null,
        info('控制項目錄：' + s.catalog + '；週期：' + s.cycle.title + '（' + s.cycle.starts_on + ' 至 ' + s.cycle.ends_on + '）'),
        h('div', { class: 'counts', style: 'grid-template-columns:repeat(4,minmax(0,1fr))' },
          [[n.total, '控制項'], [n.assessed + '／' + n.total, '已評估（' + percent + '%）'], [n.unreviewed, '未評估'], [n.compliant + '／' + applicable, '符合／適用'],
            [n.gap, '缺口'], [n.not_applicable, '不適用'], [n.snapshots, '已保存快照'], [n.overdue, '改善逾期']]
            .map(([value, label]) => h('div', { class: 'cnt' }, h('b', null, value), label))),
        info('改善事項：' + n.remediations_done + '／' + n.remediations + ' 已完成；快照容量：' + n.snapshot_bytes + ' 位元組。符合率須以正式目錄與實際證據為準。'),
        s.domains.length ? table(['領域', '控制項', '符合', '缺口', '未評估', '不適用'],
          s.domains.map((d) => [d.domain, d.total, d.compliant, d.gap, d.unreviewed, d.not_applicable])) : null);
    }

    function table(headers, rows) {
      return h('div', { style: 'overflow-x:auto' }, h('table', null,
        h('thead', null, h('tr', null, headers.map((x) => h('th', null, x)))),
        h('tbody', null, rows.map((cells) => h('tr', null, cells.map((x) => h('td', null, x)))))));
    }

    function downloadTemplate() {
      const blob = new Blob(['\ufeffcode,domain,title,requirement,description\r\n'], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = h('a', { href: url, download: 'ioc-audit-controls-template.csv' });
      document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 0);
    }

    function catalogView() {
      const label = input('text', '', { maxlength: 100, placeholder: '例如 2026 年正式控制項' });
      const file = input('file', '', { accept: '.csv,text/csv' });
      const result = h('p', { class: 'note' }, '先選擇 UTF-8 CSV 並預覽差異，再啟用整版目錄。');
      const previewBtn = button('預覽差異', async () => {
        try {
          if (!file.files || !file.files[0] || file.files[0].size > 1024 * 1024) throw new Error('請選擇不超過 1 MiB 的 CSV');
          csvText = await file.files[0].text();
          const p = (await request({ action: 'preview', csv: csvText })).preview;
          previewDigest = p.digest;
          result.textContent = '共 ' + p.count + ' 項；新增 ' + p.added + '（' + p.added_codes.join('、') + '）、變更 ' + p.changed + '（' + p.changed_codes.join('、') + '）、移除 ' + p.removed + '（' + p.removed_codes.join('、') + '）、未變更 ' + p.unchanged + '。確認後才啟用。';
        } catch (e) { previewDigest = null; result.textContent = errText(e); }
      });
      file.addEventListener('change', () => { previewDigest = null; csvText = ''; result.textContent = '檔案已變更，請重新預覽。'; });
      const importBtn = button('匯入並啟用', () => run(async () => {
        if (!previewDigest || !csvText) throw new Error('請先預覽目前的 CSV');
        const recheck = (await request({ action: 'preview', csv: csvText })).preview;
        if (recheck.digest !== previewDigest) throw new Error('CSV 內容已變更，請重新預覽');
        selectedCycle = 0;
        await request({ action: 'import', label: label.value, csv: csvText, expected_digest: previewDigest });
      }, '控制項目錄已啟用'), true);
      const catalogs = detail.catalogs || [];
      const old = catalogs.filter((c) => Number(c.active) !== 1);
      const oldSelect = select(old.map((c) => [c.id, c.version_label + '（#' + c.id + '）']), old[0] && old[0].id);
      return card('控制項目錄',
        info('正式控制項由管理者匯入；啟用新版後，舊版與既有評估週期保留唯讀。'),
        h('div', { class: 'toolbar' }, button('下載 CSV 範本', downloadTemplate), file), field('版本名稱', label),
        h('div', { class: 'toolbar' }, previewBtn, importBtn), result,
        old.length ? h('div', { class: 'toolbar' }, oldSelect, button('重新啟用舊版', () => run(async () => {
          selectedCycle = 0; await request({ action: 'activate_catalog', catalog_id: Number(oldSelect.value) });
        }, '控制項版本已切換'))) : null);
    }

    function cycleView() {
      const cycles = detail.cycles || [];
      const cycleSelect = select(cycles.map((c) => [c.id, '#' + c.id + ' ' + c.title + '／' + c.version_label + (Number(c.active) === 1 ? '' : '（歷史目錄）')]), selectedCycle);
      cycleSelect.addEventListener('change', async () => { selectedCycle = Number(cycleSelect.value); selectedControl = 0; await load(); });
      const title = input('text', '', { maxlength: 150, placeholder: '例如 2026 年度評估' });
      const from = input('date'); const to = input('date');
      return card('評估週期',
        cycles.length ? field('檢視週期', cycleSelect) : info('啟用控制項後，請建立第一個評估週期。'),
        h('div', { class: 'toolbar' }, field('開始日期', from), field('結束日期', to)), field('週期名稱', title),
        button('建立新週期', () => run(async () => {
          const r = await request({ action: 'create_cycle', title: title.value, starts_on: from.value, ends_on: to.value });
          selectedCycle = Number(r.cycle_id); selectedControl = 0;
        }, '評估週期已建立'), true));
    }

    function controlView() {
      const editable = selectedCycle === detail.current_cycle_id;
      const controls = detail.controls || [];
      const selected = controls.find((c) => Number(c.id) === selectedControl) || controls[0];
      if (selected) selectedControl = Number(selected.id);
      const statuses = { unreviewed: '未評估', compliant: '符合', gap: '缺口', not_applicable: '不適用' };
      return card('控制項、證據與改善',
        editable ? info('目前週期可評估與保存證據。') : h('p', { class: 'note warn' }, '歷史週期唯讀，不能變更判定或證據。'),
        table(['代碼', '領域', '名稱', '判定', '操作'], controls.map((c) => [c.code, c.domain, c.title, statuses[c.status] || c.status,
          button('檢視', () => { selectedControl = Number(c.id); renderControlPanel(); })])),
        h('div', { id: 'ioc-audit-control-panel', style: 'margin-top:14px' }, selected ? controlPanel(selected, editable) : info('此週期沒有控制項。')));
    }

    function renderControlPanel() {
      const control = detail.controls.find((c) => Number(c.id) === selectedControl);
      const box = root.querySelector('#ioc-audit-control-panel');
      if (box && control) box.replaceChildren(controlPanel(control, selectedCycle === detail.current_cycle_id));
    }

    function controlPanel(c, editable) {
      const evidence = detail.evidence.filter((e) => Number(e.control_id) === Number(c.id));
      const remediation = detail.remediation.filter((r) => Number(r.control_id) === Number(c.id));
      const validEvidence = evidence.filter((e) => e.kind !== 'external_ref');
      const evidenceChoices = [[0, '請選擇證據'], ...validEvidence.map((e) => [e.id, '#' + e.id + ' ' + evidenceLabel(e)])];
      return h('div', null,
        h('h3', { class: 'sec-h' }, c.code + '　' + c.title), info('要求：' + c.requirement), c.description ? info('說明：' + c.description) : null,
        h('h3', { class: 'sec-h' }, '來源證據'),
        evidence.length ? table(['編號', '類型／來源', '取得時間', '範圍', '雜湊', '操作'], evidence.map((e) => [
          '#' + e.id, evidenceLabel(e), e.captured_at, e.kind === 'case_snapshot' ? '共 ' + e.item_count + ' 筆；最多 ' + 30 + ' 筆' + (Number(e.truncated) ? '（已截取）' : '') : '僅來源參照',
          e.sha256 || '未保存快照', e.kind === 'case_snapshot' ? button('驗證並下載', () => download(e.id)) : '—'])) : info('尚無證據。'),
        editable ? evidenceForms(c) : null,
        h('h3', { class: 'sec-h' }, '控制項評估'),
        editable ? assessmentForm(c, evidenceChoices) : info('判定：' + (c.status || '未評估') + '；理由：' + (c.reason || '—')),
        h('h3', { class: 'sec-h' }, '改善事項'),
        remediation.length ? table(['編號', '負責人', '期限', '狀態', '說明', '操作'], remediation.map((r) => [
          '#' + r.id, r.owner, r.due_on, r.status, r.note || '—', editable ? button('編輯', () => renderRemediationForm(c, r, evidenceChoices)) : '—'])) : info('尚無改善事項。'),
        editable && c.status === 'gap' ? button('新增改善事項', () => renderRemediationForm(c, null, evidenceChoices)) : null,
        h('div', { id: 'ioc-audit-remediation-form' }),
        button('檢視判定與改善歷程', () => showHistory(c)));
    }

    function evidenceLabel(e) {
      return ({ case_snapshot: '事件案快照', backup_ref: '組態備份版本', external_ref: '外部參照（未驗證）' }[e.kind] || e.kind) + '：' + e.source_ref;
    }

    function evidenceForms(c) {
      const caseId = input('number', '', { min: 1, placeholder: '事件案編號' });
      const deviceId = input('number', '', { min: 1, placeholder: 'LibreNMS 設備編號' });
      const versionId = input('number', '', { min: 1, placeholder: '備份版本編號' });
      const deviceChoices = h('select', { disabled: true, 'aria-label': '選擇備份設備' });
      const versionChoices = h('select', { disabled: true, 'aria-label': '選擇完整備份版本' });
      const versionOffset = input('number', '0', { min: 0, step: 10, style: 'width:6em', 'aria-label': '版本起始筆數' });
      const backupHint = h('span', { class: 'note' });
      const external = input('text', '', { maxlength: 200, placeholder: '外部封存識別碼，不含網址或秘密' });
      const common = { cycle_id: selectedCycle, control_id: Number(c.id) };
      async function loadVersions() {
        const id = Number(deviceChoices.value);
        if (!id) return;
        const offset = Number(versionOffset.value);
        if (!Number.isInteger(offset) || offset < 0) throw new Error('版本起始筆數不正確');
        const response = await api('/ioc-backup.php', { action: 'history', device_id: id, offset });
        const history = response.backup.history;
        const versions = history.versions.filter((v) => v.complete && v.digest);
        versionChoices.replaceChildren(...versions.map((v) => h('option', { value: v.id }, '#' + v.id + '／' + v.created_at)));
        versionChoices.disabled = versions.length === 0;
        backupHint.textContent = '此頁完整版本 ' + versions.length + ' 筆；總版本 ' + history.total_versions + ' 筆。';
      }
      deviceChoices.addEventListener('change', () => { versionOffset.value = '0'; loadVersions().catch((e) => { backupHint.textContent = errText(e); }); });
      return h('div', null,
        h('div', { class: 'toolbar' }, caseId, button('擷取事件案快照', () => run(() => request(Object.assign({ action: 'capture_case', case_id: Number(caseId.value) }, common)), '快照已保存'))),
        h('div', { class: 'toolbar' }, button('載入備份設備', async () => {
          try {
            const response = await api('/ioc-backup.php', { action: 'get' });
            const devices = response.backup.history_devices || [];
            deviceChoices.replaceChildren(...devices.map((d) => h('option', { value: d.device_id }, d.name + '（#' + d.device_id + '）')));
            deviceChoices.disabled = devices.length === 0;
            if (devices.length) await loadVersions(); else backupHint.textContent = '尚無備份設備。';
          } catch (e) { backupHint.textContent = errText(e); }
        }), deviceChoices, versionOffset, button('查詢版本', () => loadVersions().catch((e) => { backupHint.textContent = errText(e); })),
        versionChoices, button('連結所選版本', () => run(() => request(Object.assign({ action: 'link_backup', device_id: Number(deviceChoices.value), version_id: Number(versionChoices.value) }, common)), '備份版本已連結')),
        backupHint),
        h('div', { class: 'toolbar' }, deviceId, versionId, button('連結組態備份版本', () => run(() => request(Object.assign({ action: 'link_backup', device_id: Number(deviceId.value), version_id: Number(versionId.value) }, common)), '備份版本已連結'))),
        h('div', { class: 'toolbar' }, external, button('記錄外部來源參照', () => run(() => request(Object.assign({ action: 'link_external', reference: external.value }, common)), '來源參照已記錄'))),
        info('外部參照尚未由系統驗證或保存內容，不能用於「符合」判定或改善結案。組態備份只保存版本連結與雜湊。'));
    }

    function assessmentForm(c, choices) {
      const status = select([['unreviewed', '未評估'], ['compliant', '符合'], ['gap', '缺口'], ['not_applicable', '不適用']], c.status);
      const evidence = select(choices, c.evidence_id || 0);
      const reason = h('textarea', { maxlength: 2000 }, c.reason || '');
      return h('div', null, h('div', { class: 'toolbar' }, field('判定', status), field('關聯證據', evidence)), field('判定理由（不適用時必填）', reason),
        button('儲存判定', () => run(() => request({ action: 'assess', cycle_id: selectedCycle, control_id: Number(c.id), status: status.value,
          reason: reason.value, evidence_id: Number(evidence.value) || null }), '判定已記錄'), true));
    }

    function renderRemediationForm(c, item, choices) {
      const box = root.querySelector('#ioc-audit-remediation-form');
      if (!box) return;
      const owner = input('text', item && item.owner, { maxlength: 255 });
      const due = input('date', item && item.due_on);
      const status = select([['open', '待處理'], ['in_progress', '處理中'], ['done', '已完成']], item && item.status || 'open');
      const closing = select(choices, item && item.closing_evidence_id || 0);
      const note = h('textarea', { maxlength: 2000 }, item && item.note || '');
      box.replaceChildren(h('div', null, h('h3', { class: 'sec-h' }, item ? '編輯改善 #' + item.id : '新增改善事項'),
        h('div', { class: 'toolbar' }, field('負責人', owner), field('期限', due), field('狀態', status), field('結案證據', closing)),
        field('說明', note), button('儲存改善事項', () => run(() => request({ action: 'save_remediation', remediation_id: item ? Number(item.id) : 0,
          cycle_id: selectedCycle, control_id: Number(c.id), owner: owner.value, due_on: due.value, status: status.value,
          note: note.value, closing_evidence_id: Number(closing.value) || null }), '改善事項已記錄'), true)));
    }

    async function showHistory(c) {
      try {
        const history = (await request({ action: 'history', cycle_id: selectedCycle, control_id: Number(c.id) })).history;
        const box = root.querySelector('#ioc-audit-remediation-form');
        box.replaceChildren(h('div', null, h('h3', { class: 'sec-h' }, '判定歷程'),
          history.assessments.length ? table(['時間', '操作者', '狀態', '證據', '理由'], history.assessments.map((x) => [x.occurred_at, x.actor, x.status, x.evidence_id || '—', x.reason || '—'])) : info('尚無判定歷程。'),
          h('h3', { class: 'sec-h' }, '改善歷程'), history.remediation.length ? table(['時間', '操作者', '改善編號', '狀態', '負責人', '期限'],
            history.remediation.map((x) => [x.occurred_at, x.actor, x.remediation_id, x.status, x.owner, x.due_on])) : info('尚無改善歷程。')));
      } catch (e) { toast(errText(e), true); }
    }

    async function download(id) {
      try {
        const res = await fetch('/ioc-audit-download.php', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ evidence_id: Number(id) }) });
        if (!res.ok) { const problem = await res.json().catch(() => null); throw new Error(problem && problem.error || '下載失敗（HTTP ' + res.status + '）'); }
        const url = URL.createObjectURL(await res.blob());
        const a = h('a', { href: url, download: 'ioc-evidence-' + id + '.json' });
        document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 0);
        toast('快照已通過雜湊驗證並開始下載');
      } catch (e) { toast(errText(e), true); }
    }

    function adminTrail() {
      return card('模組操作紀錄與儲存狀態',
        info('已保存快照：' + detail.storage_used_bytes + ' 位元組；剩餘儲存空間：' + (detail.storage_free_bytes == null ? '無法取得' : detail.storage_free_bytes + ' 位元組') + '；第一版不自動刪除快照。'),
        detail.events.length ? table(['時間', '操作者', '動作', '目標'], detail.events.map((e) => [e.occurred_at, e.actor, e.action, e.target_type + ' #' + e.target_id])) : info('尚無操作紀錄。'));
    }

    return { load };
  };
})();
