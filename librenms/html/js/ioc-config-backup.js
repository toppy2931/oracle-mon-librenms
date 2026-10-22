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
    const list = h('div');
    const rows = [];
    const message = h('div', { role: 'status', 'aria-live': 'polite' });
    const enabled = h('input', { type: 'checkbox', checked: settings.enabled });
    const interval = h('select', { 'aria-label': '備份頻率' }, [1, 6, 12, 24, 48, 168].map((n) => h('option', { value: String(n) }, n === 168 ? '每週一次' : '每 ' + n + ' 小時')));
    if (![1, 6, 12, 24, 48, 168].includes(settings.interval_hours)) interval.append(h('option', { value: String(settings.interval_hours) }, '每 ' + settings.interval_hours + ' 小時'));
    interval.value = String(settings.interval_hours);
    const addRow = (device) => {
      const choices = catalog.slice();
      if (device && !choices.some((d) => d.device_id === device.device_id)) choices.push({ device_id: device.device_id, name: device.name + '（已不在可備份設備清單，請移除）', host: device.host });
      const select = h('select', { 'aria-label': '選擇設備', required: true, style: 'width:100%' }, h('option', { value: '' }, '請選擇設備'),
        choices.map((d) => h('option', { value: String(d.device_id) }, d.name + '（' + d.host + '）')));
      select.value = device ? String(device.device_id) : '';
      const model = h('select', { 'aria-label': '設備品牌', style: 'width:100%' }, models.map(([v, title]) => h('option', { value: v }, title)));
      model.value = device ? device.model : 'fortios';
      const username = h('input', { type: 'text', maxlength: 128, autocomplete: 'off', required: true, style: 'width:100%' });
      username.value = device ? device.username : '';
      const password = h('input', { type: 'password', maxlength: 512, autocomplete: 'new-password', style: 'width:100%',
        placeholder: device && device.password_set ? '已設定；留白可沿用' : '請輸入設備備份帳號的密碼' });
      const active = h('input', { type: 'checkbox', checked: device ? device.enabled : true });
      // 只有仍選擇原設備才允許沿用密碼，避免將 A 設備的憑證套到 B。
      select.addEventListener('change', () => {
        const same = device && select.value === String(device.device_id);
        password.required = !same || !device.password_set;
        password.placeholder = same && device.password_set ? '已設定；留白可沿用' : '請輸入此設備的密碼';
      });
      password.required = !device || !device.password_set;
      const entry = { select, model, username, password, active };
      const row = h('fieldset', { style: 'border:1px solid var(--line);border-radius:10px;margin:12px 0;padding:12px' },
        h('legend', null, '備份設備'), label('設備', select), label('設備品牌／系統', model),
        h('div', { class: 'grid even' }, label('備份帳號', username), label('備份密碼', password)),
        label('納入備份', active), note('使用 SSH 加密連線，連接埠為 22。請使用可讀取完整設定的專用帳號。'),
        h('button', { class: 'btn', type: 'button', onclick: () => { rows.splice(rows.indexOf(entry), 1); row.remove(); } }, '移除此設備'));
      rows.push(entry); list.append(row);
    };
    settings.devices.forEach(addRow);
    const save = h('button', { class: 'btn primary', type: 'submit' }, '儲存並套用備份設定');
    const add = h('button', { class: 'btn', type: 'button', onclick: () => { if (rows.length < 50) addRow(null); } }, '＋ 加入設備');
    const form = h('form', { onsubmit: async (event) => {
      event.preventDefault(); save.disabled = true; add.disabled = true;
      const fields = Array.from(form.querySelectorAll('input, select, button'));
      fields.forEach((el) => { el.disabled = true; });
      message.replaceChildren(note('正在儲存並確認工具設定…'));
      try {
        const r = await request('save', { settings: { enabled: enabled.checked, interval_hours: Number(interval.value), devices: rows.map((row) => ({
          device_id: Number(row.select.value), model: row.model.value, username: row.username.value,
          password: row.password.value, port: 22, enabled: row.active.checked,
        })) } });
        rows.forEach((row) => { row.password.value = ''; });
        renderSettings(box, r);
      } catch (e) {
        message.replaceChildren(unavailable(e.message));
        fields.forEach((el) => { el.disabled = false; }); save.disabled = false; add.disabled = false;
      }
    } }, engineNotice(b.engine),
      note('依序選擇設備、填入專用備份帳號，再設定備份頻率。已儲存不代表備份已完成，結果請到「組態治理」查看。'),
      label('啟用自動備份', enabled), label('備份頻率（固定間隔執行）', interval),
      note('停用自動備份不會刪除歷史備份。首次備份建立基準，之後自動比較設定變更。'), list,
      h('div', { class: 'toolbar' }, add, save), message);
    box.replaceChildren(section('設備組態備份', '在這裡完成設定，不必編輯設備備份工具的設定檔', form));
  }

  function renderGovernance(root, response) {
    const b = response.backup;
    const message = h('div', { role: 'status', 'aria-live': 'polite' });
    const action = async (kind, id, button) => {
      button.disabled = true;
      try {
        const r = await request(kind, { device_id: id });
        renderGovernance(root, r);
      } catch (e) { message.replaceChildren(unavailable(e.message)); button.disabled = false; }
    };
    const ready = b.engine && b.engine.state === 'ready';
    const devices = b.settings.devices.map((d) => {
      const test = h('button', { class: 'btn', type: 'button', disabled: !ready || !d.enabled }, '測試並建立備份');
      const run = h('button', { class: 'btn', type: 'button', disabled: !ready || !d.enabled }, '立即備份');
      test.addEventListener('click', () => action('test', d.device_id, test));
      run.addEventListener('click', () => action('run', d.device_id, run));
      return [d.name, d.host, d.enabled ? '已納入' : '已停用', h('div', { class: 'toolbar' }, test, run)];
    });
    const runs = b.runs.map((r) => [r.device_name, kinds[r.kind] || '備份', time(r.requested_at),
      statuses[r.status] || '狀態無法辨識', time(r.completed_at), r.message || '']);
    const changes = b.changes.map((c) => h('details', { style: 'margin:12px 0' },
      h('summary', null, c.device_name + '｜' + time(c.detected_at) + '｜新增 ' + c.added + ' 行、刪除 ' + c.removed + ' 行'),
      note('這是兩次成功備份之間偵測到的差異，無法單靠備份判定實際修改者。敏感設定已遮罩；差異過長時會截斷。'),
      h('pre', { style: 'white-space:pre-wrap;overflow-wrap:anywhere;background:var(--bg);padding:12px;max-height:480px;overflow:auto' }, c.diff || '這次只有敏感欄位或未顯示內容發生變更。')));
    root.replaceChildren(h('div', { class: 'toolbar' },
      h('button', { class: 'btn', type: 'button', onclick: () => governance(root) }, '重新整理備份狀態'),
      h('a', { href: '#settings', onclick: (e) => { e.preventDefault(); openSettings(); } }, '前往設定頁調整設備與排程')),
      engineNotice(b.engine), note('已排入不代表成功；請重新整理查看完成結果。時間為台北時間。'), message,
      section('備份設備', '設備與排程到「設定 → 設備組態備份」管理', devices.length ? table(['設備', '連線位址', '狀態', '操作'], devices) : note('尚未設定備份設備，請先到設定頁加入設備。')),
      section('最近備份紀錄', '最多顯示最近 50 筆，沒有變更也保留執行紀錄', runs.length ? table(['設備', '方式', '要求時間', '結果', '完成時間', '說明'], runs) : note('目前沒有備份執行紀錄。')),
      section('設定變更紀錄', '最多顯示最近 50 筆；＋為新增，－為刪除', changes.length ? changes : note('目前沒有可顯示的設定變更。首次備份只建立基準；有成功備份後才能比較。')),
      section('備份管理操作紀錄', '誰在什麼時候儲存設定或要求備份；不包含密碼', (b.audit || []).length
        ? table(['時間', '操作者', '操作', '說明'], b.audit.map((a) => [time(a.occurred_at), a.actor,
          ({ save: '儲存備份設定', test: '要求測試備份', run: '要求立即備份', scheduled: '排程備份' })[a.action] || '備份操作', a.detail]))
        : note('目前沒有備份管理操作紀錄。')));
    if (b.runs.some((r) => r.status === 'queued')) {
      setTimeout(() => { if (root.isConnected && !root.hidden) governance(root); }, 5000);
    }
  }
  function settings(box) { return load(box, (r) => renderSettings(box, r)); }
  function governance(root) { return load(root, (r) => renderGovernance(root, r)); }
  return { settings, governance };
};
