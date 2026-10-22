/* IOC 設備組態備份：設定、執行紀錄與差異，使用純 DOM 建立畫面。 */
'use strict';
window.IocConfigBackup = function ({ h, section, table, api, unavailable, canHandle, demo, openSettings }) {
  const endpoint = '/ioc-backup.php';
  const tokens = new WeakMap();
  const models = [['fortios', 'FortiGate'], ['ios', 'Cisco IOS'], ['junos', 'Juniper Junos'], ['routeros', 'MikroTik RouterOS']];
  const statuses = { queued: '等待設備備份完成', success: '備份完成', unchanged: '備份完成，設定沒有變更', failed: '備份失敗', timeout: '備份逾時' };
  const kinds = { test: '測試備份', manual: '手動備份', scheduled: '排程備份' };
  const time = (s) => {
    if (!s) return '—';
    const d = new Date(s.includes('T') ? (/[Zz]|[+-]\d\d:\d\d$/.test(s) ? s : s + 'Z') : s.replace(' ', 'T') + 'Z');
    return Number.isNaN(d.getTime()) ? '時間無法辨識' : d.toLocaleString('zh-TW', { timeZone: 'Asia/Taipei', hour12: false });
  };
  const note = (s) => h('p', { class: 'note' }, s);
  const label = (title, input) => h('label', { style: 'display:block;margin:8px 0' }, h('span', null, title), input);
  const request = (action, extra) => api(endpoint, Object.assign({ action }, extra || {}));
  const engineNotice = (engine) => note(engine && engine.message ? engine.message : '備份工具尚未安裝或無法連線，請聯絡管理者完成安裝。');

  async function load(box, render) {
    if (!canHandle) { box.replaceChildren(note('只有管理者可以管理設備組態備份。')); return; }
    if (demo) { box.replaceChildren(note('範例模式不連接設備，也不提供正式備份設定。請切換到正式模式後操作。')); return; }
    const token = {};
    tokens.set(box, token);
    box.replaceChildren(note('正在讀取設備備份狀態…'));
    try {
      const r = await request('get');
      if (tokens.get(box) === token) render(r);
    } catch (e) {
      if (tokens.get(box) === token) box.replaceChildren(section('設備組態備份', '尚未安裝或暫時無法使用', [unavailable(e.message),
        h('button', { class: 'btn', type: 'button', onclick: () => load(box, render) }, '重新讀取')]));
    }
  }

  function renderSettings(box, response) {
    const b = response.backup;
    const settings = b.settings;
    const catalog = response.devices;
    const message = h('div', { role: 'status', 'aria-live': 'polite' });
    const enabled = h('input', { type: 'checkbox', checked: settings.enabled });
    const interval = h('select', { 'aria-label': '備份頻率' }, [1, 6, 12, 24, 48, 168].map((n) => h('option', { value: String(n) }, n === 168 ? '每週一次' : '每 ' + n + ' 小時')));
    if (![1, 6, 12, 24, 48, 168].includes(settings.interval_hours)) interval.append(h('option', { value: String(settings.interval_hours) }, '每 ' + settings.interval_hours + ' 小時'));
    interval.value = String(settings.interval_hours);
    const entries = settings.devices.map((d) => Object.assign({ password: '' }, d));
    const deviceTable = h('div');
    const editor = h('div');
    let selected = entries[0] || null;
    let editorFields = null;
    const deviceInfo = (entry) => catalog.find((d) => d.device_id === entry.device_id) || {
      device_id: entry.device_id, name: entry.name || '已移除設備', host: entry.host || '位址未提供',
    };
    const captureEditor = () => {
      if (!selected || !editorFields) return;
      selected.device_id = Number(editorFields.select.value);
      selected.model = editorFields.model.value;
      selected.username = editorFields.username.value;
      selected.password = editorFields.password.value;
      selected.enabled = editorFields.active.checked;
    };
    const drawDevices = () => {
      const rows = entries.map((entry) => {
        const info = deviceInfo(entry);
        return [info.name, info.host, models.find(([value]) => value === entry.model)?.[1] || entry.model,
          entry.username || '尚未設定', entry.enabled ? '已納入' : '已停用',
          h('div', { class: 'toolbar' },
            h('button', { class: 'btn', type: 'button', onclick: () => { captureEditor(); selected = entry; drawEditor(); } }, '編輯'),
            h('button', { class: 'btn', type: 'button', onclick: () => {
              const index = entries.indexOf(entry); entries.splice(index, 1);
              if (selected === entry) selected = entries[index] || entries[index - 1] || null;
              drawDevices(); drawEditor();
            } }, '移除'))];
      });
      deviceTable.replaceChildren(rows.length ? table(['設備', '連線位址', '品牌／系統', '備份帳號', '狀態', '操作'], rows) : note('尚未加入備份設備。'));
    };
    const drawEditor = () => {
      if (!selected) { editorFields = null; editor.replaceChildren(note('請按「加入設備」或在表格選擇「編輯」。')); return; }
      const current = selected;
      const choices = catalog.slice();
      if (current.device_id && !choices.some((d) => d.device_id === current.device_id)) choices.push(deviceInfo(current));
      const select = h('select', { 'aria-label': '選擇設備', required: true, style: 'width:100%' }, h('option', { value: '' }, '請選擇設備'),
        choices.map((d) => h('option', { value: String(d.device_id) }, d.name + '（' + d.host + '）')));
      select.value = current.device_id ? String(current.device_id) : '';
      const model = h('select', { 'aria-label': '設備品牌', style: 'width:100%' }, models.map(([v, title]) => h('option', { value: v }, title)));
      model.value = current.model || 'fortios';
      const username = h('input', { type: 'text', maxlength: 128, autocomplete: 'off', required: true, style: 'width:100%' });
      username.value = current.username || '';
      const password = h('input', { type: 'password', maxlength: 512, autocomplete: 'new-password', style: 'width:100%',
        placeholder: current.password_set ? '已設定；留白可沿用' : '請輸入設備備份帳號的密碼' });
      password.value = current.password || '';
      const active = h('input', { type: 'checkbox', checked: current.enabled !== false });
      const updatePasswordRule = () => {
        const same = current.device_id && select.value === String(current.device_id);
        password.required = !same || !current.password_set;
        password.placeholder = same && current.password_set ? '已設定；留白可沿用' : '請輸入此設備的密碼';
      };
      select.addEventListener('change', updatePasswordRule); updatePasswordRule();
      editorFields = { select, model, username, password, active };
      editor.replaceChildren(h('fieldset', { style: 'border:1px solid var(--line);border-radius:10px;margin:12px 0;padding:12px' },
        h('legend', null, '設備備份設定'), label('設備', select), label('設備品牌／系統', model),
        h('div', { class: 'grid even' }, label('備份帳號', username), label('備份密碼', password)),
        label('納入備份', active), note('使用 SSH 加密連線，連接埠為 22。請使用可讀取完整設定的專用帳號。')));
    };
    drawDevices(); drawEditor();
    const save = h('button', { class: 'btn primary', type: 'submit' }, '儲存並套用備份設定');
    const add = h('button', { class: 'btn', type: 'button', onclick: () => {
      captureEditor();
      if (entries.length >= 50) return;
      selected = { device_id: null, model: 'fortios', username: '', password: '', password_set: false, enabled: true };
      entries.push(selected); drawDevices(); drawEditor();
    } }, '＋ 加入設備');
    const form = h('form', { onsubmit: async (event) => {
      event.preventDefault(); save.disabled = true; add.disabled = true;
      const fields = Array.from(form.querySelectorAll('input, select, button'));
      fields.forEach((el) => { el.disabled = true; });
      message.replaceChildren(note('正在儲存並確認工具設定…'));
      try {
        captureEditor();
        const r = await request('save', { settings: { enabled: enabled.checked, interval_hours: Number(interval.value), devices: entries.map((entry) => ({
          device_id: Number(entry.device_id), model: entry.model, username: entry.username,
          password: entry.password, port: 22, enabled: entry.enabled,
        })) } });
        entries.forEach((entry) => { entry.password = ''; });
        if (editorFields) editorFields.password.value = '';
        renderSettings(box, r);
      } catch (e) {
        message.replaceChildren(unavailable(e.message));
        fields.forEach((el) => { el.disabled = false; }); save.disabled = false; add.disabled = false;
      }
    } }, engineNotice(b.engine),
      note('設備集中顯示於表格；按「加入設備」或「編輯」後，只會開啟一筆設定。儲存後會立即套用至備份引擎。'),
      label('啟用自動備份', enabled), label('備份頻率（固定間隔執行）', interval),
      note('停用自動備份不會刪除歷史備份。首次備份建立基準，之後自動比較設定變更。'),
      section('已設定備份設備', '可新增、編輯或移除；密碼不會顯示於表格', [deviceTable]), editor,
      h('div', { class: 'toolbar' }, add, save), message);
    box.replaceChildren(section('設備組態備份', '在這裡完成設定，不必編輯設備備份工具的設定檔', form));
  }

  function renderGovernance(root, response) {
    const renderToken = {}; tokens.set(root, renderToken);
    const b = response.backup;
    const message = h('div', { role: 'status', 'aria-live': 'polite' });
    const action = async (kind, id, button) => {
      button.disabled = true;
      try {
        const r = await request(kind, { device_id: id });
        if (tokens.get(root) === renderToken) renderGovernance(root, r);
      } catch (e) { message.replaceChildren(unavailable(e.message)); button.disabled = false; }
    };
    const ready = b.engine && b.engine.state === 'ready';
    const search = h('input', { type: 'search', 'aria-label': '搜尋已設定設備', placeholder: '搜尋設備名稱或位址' });
    const deviceTable = h('div');
    const drawDevices = () => {
    const devices = b.settings.devices.filter((d) => (d.name + ' ' + d.host).toLowerCase().includes(search.value.toLowerCase())).map((d) => {
      const test = h('button', { class: 'btn', type: 'button', disabled: !ready || !d.enabled }, '測試並建立備份');
      const run = h('button', { class: 'btn', type: 'button', disabled: !ready || !d.enabled }, '立即備份');
      test.addEventListener('click', () => action('test', d.device_id, test));
      run.addEventListener('click', () => action('run', d.device_id, run));
      return [d.name, d.host, d.enabled ? '已納入' : '已停用', h('div', { class: 'toolbar' }, test, run)];
    });
    deviceTable.replaceChildren(devices.length ? table(['設備', '連線位址', '狀態', '操作'], devices) : note('沒有符合條件的設備。'));
    };
    search.addEventListener('input', drawDevices); drawDevices();
    const runs = b.runs.map((r) => [r.device_name, kinds[r.kind] || '備份', time(r.requested_at),
      statuses[r.status] || '狀態無法辨識', time(r.completed_at), r.message || '']);
    const changes = b.changes.map((c) => h('details', { style: 'margin:12px 0' },
      h('summary', null, c.device_name + '｜' + time(c.detected_at) + '｜新增 ' + c.added + ' 行、刪除 ' + c.removed + ' 行'),
      note('這是兩次成功備份之間偵測到的差異，無法單靠備份判定實際修改者。敏感設定已遮罩；差異過長時會截斷。'),
      h('pre', { style: 'white-space:pre-wrap;overflow-wrap:anywhere;background:var(--bg);padding:12px;max-height:480px;overflow:auto' }, c.diff || '這次只有敏感欄位或未顯示內容發生變更。')));
    root.replaceChildren(h('div', { class: 'toolbar' },
      h('button', { class: 'btn', type: 'button', onclick: () => load(root, (r) => renderGovernance(root, r)) }, '重新整理備份狀態'),
      note('設備與排程請在本頁「備份設定」調整。')),
      engineNotice(b.engine), note('已排入不代表成功；請重新整理查看完成結果。時間為台北時間。'), message,
      section('備份設備', '可依設備名稱或位址查詢', [search, deviceTable]),
      section('最近備份紀錄', '最多顯示最近 50 筆，沒有變更也保留執行紀錄', runs.length ? table(['設備', '方式', '要求時間', '結果', '完成時間', '說明'], runs) : note('目前沒有備份執行紀錄。')),
      section('設定變更紀錄', '最多顯示最近 50 筆；＋為新增，－為刪除', changes.length ? changes : note('目前沒有可顯示的設定變更。首次備份只建立基準；有成功備份後才能比較。')),
      section('備份管理操作紀錄', '誰在什麼時候儲存設定或要求備份；不包含密碼', (b.audit || []).length
        ? table(['時間', '操作者', '操作', '說明'], b.audit.map((a) => [time(a.occurred_at), a.actor,
          ({ save: '儲存備份設定', test: '要求測試備份', run: '要求立即備份', scheduled: '排程備份' })[a.action] || '備份操作', a.detail]))
        : note('目前沒有備份管理操作紀錄。')));
  }
  function settings(box) { return load(box, (r) => renderSettings(box, r)); }

  function renderHistory(root, response) {
    let sequence = 0, comparisonSequence = 0, offset = 0;
    const versions = new Map();
    const devices = response.backup.history_devices || response.backup.settings.devices;
    const picker = h('select', { 'aria-label': '查詢備份設備' }, h('option', { value: '' }, '請選擇設備'),
      devices.map((d) => h('option', { value: String(d.device_id) }, d.name + (d.configured === false ? '（已移除，保留歷史）' : ''))));
    const records = h('div');
    const comparison = h('div', { role: 'status', 'aria-live': 'polite' });
    const from = h('select', { 'aria-label': '比較起始版本' });
    const to = h('select', { 'aria-label': '比較結束版本' });
    const compare = h('button', { type: 'button', class: 'btn', disabled: true }, '比較兩個版本');
    const updateSelections = () => {
      for (const select of [from, to]) {
        const selected = select.value;
        select.replaceChildren(h('option', { value: '' }, '請選擇版本'), ...[...versions.values()].map((v) =>
          h('option', { value: String(v.id) }, '版本 #' + v.id + '｜' + time(v.created_at))));
        select.value = selected;
      }
      compare.disabled = !from.value || !to.value || from.value === to.value;
    };
    const invalidateComparison = () => { comparisonSequence++; comparison.replaceChildren(); compare.disabled = !from.value || !to.value || from.value === to.value; };
    from.addEventListener('change', invalidateComparison); to.addEventListener('change', invalidateComparison);
    const fetchPage = async (pageOffset) => {
      const id = Number(picker.value), requestSequence = ++sequence;
      comparisonSequence++;
      compare.disabled = true;
      records.replaceChildren(note('正在讀取備份歷史…')); comparison.replaceChildren();
      if (!id) { records.replaceChildren(note('請先選擇要查詢的設備。')); return; }
      try {
        const r = await request('history', { device_id: id, offset: pageOffset });
        if (requestSequence !== sequence) return;
        const history = r.backup.history;
        offset = history.offset;
        history.versions.forEach((v) => versions.set(v.id, v)); updateSelections();
        const previous = h('button', { type: 'button', class: 'btn', disabled: offset === 0, onclick: () => fetchPage(Math.max(0, offset - history.limit)) }, '上一頁');
        const next = h('button', { type: 'button', class: 'btn', disabled: offset + history.limit >= Math.max(history.total_runs, history.total_versions), onclick: () => fetchPage(offset + history.limit) }, '下一頁');
        records.replaceChildren(note('執行紀錄共 ' + history.total_runs + ' 筆；內容版本共 ' + history.total_versions + ' 代。第 ' + (Math.floor(offset / history.limit) + 1) + ' 頁'),
          h('div', { class: 'toolbar' }, previous, next),
          section('備份執行紀錄', '內容沒有變更也會保留執行紀錄', history.runs.length ? table(['要求時間', '方式', '結果', '完成時間', '說明'], history.runs.map((r) => [time(r.requested_at), kinds[r.kind] || '備份', statuses[r.status] || '狀態無法辨識', time(r.completed_at), r.message || ''])) : note('此頁沒有執行紀錄。')),
          section('備份版本', '內容變更才建立新版本；首次成功備份為基準', history.versions.length ? table(['版本', '建立時間'], history.versions.map((v) => ['#' + v.id, time(v.created_at)])) : note('此頁沒有備份版本。')));
      } catch (e) { if (requestSequence === sequence) records.replaceChildren(unavailable(e.message)); }
    };
    picker.addEventListener('change', () => { versions.clear(); from.value = ''; to.value = ''; updateSelections(); return fetchPage(0); });
    compare.addEventListener('click', async () => {
      if (!from.value || !to.value || from.value === to.value) return;
      const requestSequence = ++comparisonSequence;
      compare.disabled = true; comparison.replaceChildren(note('正在比較版本…'));
      try {
        const r = await request('compare', { device_id: Number(picker.value), from_version_id: Number(from.value), to_version_id: Number(to.value) });
        if (requestSequence !== comparisonSequence) return;
        const c = r.backup.comparison;
        comparison.replaceChildren(note('版本 #' + c.from_version_id + '（' + time(c.from_created_at) + '） → #' + c.to_version_id + '（' + time(c.to_created_at) + '）'),
          note('新增 ' + c.added + ' 行、刪除 ' + c.removed + ' 行。' + (c.truncated ? '內容過長，已截斷預覽。' : '')),
          h('pre', { style: 'white-space:pre-wrap;overflow-wrap:anywhere;max-height:600px;overflow:auto' }, c.diff || (c.changed ? '原始內容不同，但可顯示的差異已遮罩。' : '兩個版本的內容相同。')));
      } catch (e) { if (requestSequence === comparisonSequence) comparison.replaceChildren(unavailable(e.message)); }
      finally { if (requestSequence === comparisonSequence) compare.disabled = false; }
    });
    root.replaceChildren(h('button', { type: 'button', class: 'btn', onclick: async () => {
      const refreshSequence = ++sequence; comparisonSequence++;
      try { const r = await request('get'); if (refreshSequence !== sequence) return;
        const selected = picker.value;
        const updated = r.backup.history_devices || r.backup.settings.devices;
        picker.replaceChildren(h('option', { value: '' }, '請選擇設備'), ...updated.map((d) => h('option', { value: String(d.device_id) }, d.name + (d.configured === false ? '（已移除，保留歷史）' : ''))));
        picker.value = updated.some((d) => String(d.device_id) === selected) ? selected : '';
        if (!picker.value) { versions.clear(); from.value = ''; to.value = ''; updateSelections(); }
        await fetchPage(picker.value === selected ? offset : 0);
      } catch (e) { if (refreshSequence === sequence) records.replaceChildren(unavailable(e.message)); }
    } }, '重新整理設備與歷史'), section('查詢備份歷史', '時間為台北時間；可查詢已移除設備留下的歷史', [label('設備', picker), records]),
      section('比較備份代數', '選擇同一設備任兩個版本。翻頁保留已載入版本與選取；敏感資料已遮罩。＋為新增，－為刪除。', [label('起始版本', from), label('結束版本', to), compare, comparison]));
    records.replaceChildren(note('請先選擇要查詢的設備。'));
  }
  function governance(root) {
    return load(root, (response) => {
      const overview = h('div'), configuration = h('div', { hidden: true }), history = h('div', { hidden: true });
      let settingsLoaded = false, historyLoaded = false;
      const show = (name) => {
        overview.hidden = name !== 'overview'; configuration.hidden = name !== 'settings'; history.hidden = name !== 'history';
        if (name === 'overview') load(overview, (r) => renderGovernance(overview, r));
        if (name === 'settings' && !settingsLoaded) { settingsLoaded = true; return settings(configuration); }
        if (name === 'history' && !historyLoaded) { historyLoaded = true; return load(history, (r) => renderHistory(history, r)); }
      };
      root.replaceChildren(section('組態備份管理', '統一管理設備、排程、備份紀錄與版本差異',
        h('div', { class: 'toolbar' }, h('button', { type: 'button', class: 'btn', onclick: () => show('overview') }, '設備與狀態'),
          h('button', { type: 'button', class: 'btn', onclick: () => show('settings') }, '備份設定'),
          h('button', { type: 'button', class: 'btn', onclick: () => show('history') }, '備份歷史與比較'))), overview, configuration, history);
      renderGovernance(overview, response);
    });
  }
  return { settings, governance };
};
