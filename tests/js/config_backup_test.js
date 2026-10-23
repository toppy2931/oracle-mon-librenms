// IOC 備份介面的行為測試：用最小 DOM 替身驗證權限、資料流與狀態，不連設備。
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
class Element {
  constructor(tag, attrs = {}, children = []) { this.tag = tag; this.attrs = attrs; Object.assign(this, attrs); this.children = children; this.value = attrs.value || ''; this.listeners = {}; }
  replaceChildren(...children) { this.children = children; }
  append(...children) { this.children.push(...children); }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  remove() { this.removed = true; }
  querySelectorAll() { return walk(this).filter((e) => ['input', 'select', 'button'].includes(e.tag)); }
}
const h = (tag, attrs, ...kids) => new Element(tag, attrs || {}, kids.flat(Infinity).filter((x) => x !== null && x !== false && x !== undefined));
const walk = (node) => node instanceof Element ? [node, ...node.children.flatMap(walk)] : [];
const text = (node) => node instanceof Element ? node.children.map(text).join(' ') : String(node || '');
const payload = () => ({ backup: { settings: { enabled: false, interval_hours: 24, devices: [{ device_id: 7, name: '機房防火牆', host: '192.0.2.1', model: 'fortios', username: 'backup', port: 22, enabled: true, password_set: true }] }, engine: { state: 'pending_apply', message: '設定已儲存，等待工具套用' }, runs: [], changes: [] }, devices: [{ device_id: 7, name: '機房防火牆', host: '192.0.2.1' }] });
function create(api, opts = {}) {
  const ctx = { window: {}, setTimeout: opts.setTimeout || (() => 0) };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../librenms/html/js/ioc-config-backup.js'), 'utf8'), ctx);
  return ctx.window.IocConfigBackup(Object.assign({ h, section: (title, sub, kids) => h('section', null, title, sub, kids), table: (heads, rows) => h('table', null, heads, rows), api, unavailable: (s) => h('p', null, s), canHandle: true, demo: false, openSettings() {} }, opts));
}
test('檢視者與範例模式不讀取正式備份設定', async () => {
  let calls = 0;
  for (const opts of [{ canHandle: false }, { demo: true }]) {
    const root = h('div'); const ui = create(async () => { calls++; }, opts);
    await ui.settings(root); await ui.governance(root);
    assert.ok(text(root).includes(opts.demo ? '範例模式' : '只有管理者'));
  }
  assert.equal(calls, 0);
});
test('已有密碼只顯示沿用提示，不把秘密放回輸入框', async () => {
  const p = payload(); p.devices.push({ device_id: 8, name: '分點防火牆', host: '192.0.2.8' });
  const root = h('div'); const ui = create(async () => p);
  await ui.settings(root);
  const password = walk(root).find((e) => e.type === 'password');
  assert.equal(password.value, ''); assert.equal(password.required, false);
  assert.ok(password.placeholder.includes('留白可沿用'));
  const fixedDevice = findLabel(root, '已固定設備');
  assert.equal(fixedDevice.readOnly, true);
  assert.ok(fixedDevice.value.includes('機房防火牆'));
  button(root, '＋ 加入設備').onclick();
  const select = walk(root).find((e) => e.attrs['aria-label'] === '選擇設備');
  select.value = '8'; select.listeners.change();
  assert.equal(walk(root).find((e) => e.type === 'password').required, true);
});
test('儲存表單送設備 id 與密碼，成功後清除密碼並保留待套用訊息', async () => {
  const calls = []; const root = h('div'); const ui = create(async (url, body) => { calls.push(body); return payload(); });
  await ui.settings(root);
  const password = walk(root).find((e) => e.type === 'password'); password.value = 'test-only-secret';
  await walk(root).find((e) => e.tag === 'form').onsubmit({ preventDefault() {} });
  assert.equal(calls[1].action, 'save'); assert.equal(calls[1].settings.devices[0].device_id, 7);
  assert.equal(calls[1].settings.devices[0].password, 'test-only-secret');
  assert.equal(password.value, '');
  assert.equal(walk(root).find((e) => e.type === 'password').value, '');
  assert.ok(text(root).includes('等待工具套用'));
});
test('備份設定以設備表格集中管理，可加入、編輯與移除單筆設備', async () => {
  const p = payload(); p.devices.push({ device_id: 8, name: '分點防火牆', host: '192.0.2.8' });
  const root = h('div'); const ui = create(async () => p);
  await ui.settings(root);
  assert.ok(walk(root).some((e) => e.tag === 'table' && text(e).includes('備份帳號')));
  assert.equal(walk(root).filter((e) => e.tag === 'fieldset').length, 2);
  button(root, '＋ 加入設備').onclick();
  assert.equal(walk(root).filter((e) => e.tag === 'fieldset').length, 2);
  const select = findLabel(root, '選擇設備'); select.value = '8'; select.listeners.change();
  const password = walk(root).find((e) => e.type === 'password');
  assert.equal(password.required, true);
  const remove = walk(root).filter((e) => e.tag === 'button' && text(e) === '移除').at(-1);
  remove.onclick();
  assert.ok(text(root).includes('尚未加入備份設備') === false);
});
test('既有設備的身分固定，新增列也不可重選已設定設備', async () => {
  const p = payload();
  p.backup.settings.devices.push({ device_id: 8, name: '分點防火牆', host: '192.0.2.8', model: 'fortios', username: 'backup-2', port: 22, enabled: true, password_set: true });
  p.devices.push({ device_id: 8, name: '分點防火牆', host: '192.0.2.8' });
  p.devices.push({ device_id: 9, name: '可新增設備', host: '192.0.2.9' });
  const root = h('div'); await create(async () => p).settings(root);
  const current = findLabel(root, '已固定設備');
  assert.equal(current.readOnly, true);
  assert.equal(findLabel(root, '選擇設備'), undefined);
  assert.ok(text(walk(root).find((e) => e.tag === 'table')).includes('機房防火牆'));
  assert.ok(!text(walk(root).find((e) => e.tag === 'table')).includes('可新增設備'));

  button(root, '＋ 加入設備').onclick();
  const addSelect = findLabel(root, '選擇設備');
  assert.notEqual(addSelect.disabled, true);
  const options = walk(addSelect).filter((e) => e.tag === 'option');
  assert.equal(options.find((e) => e.value === '7').disabled, true);
  assert.equal(options.find((e) => e.value === '8').disabled, true);
  assert.notEqual(options.find((e) => e.value === '9').disabled, true);
});
test('新增列選擇設備後立即顯示正確名稱與位址，未選設備不可儲存', async () => {
  const calls = [];
  const p = payload();
  p.devices.push({ device_id: 8, name: '分點防火牆', host: '192.0.2.8' });
  const root = h('div'); const ui = create(async (url, body) => { calls.push(body); return p; });
  await ui.settings(root);
  button(root, '＋ 加入設備').onclick();
  const form = walk(root).find((e) => e.tag === 'form');
  await form.onsubmit({ preventDefault() {} });
  assert.equal(calls.some((call) => call.action === 'save'), false);
  assert.ok(text(root).includes('請先為每一筆新增設備選擇 LibreNMS 中的設備'));

  const select = findLabel(root, '選擇設備');
  select.value = '8'; select.listeners.change();
  assert.ok(text(root).includes('分點防火牆'));
  assert.ok(text(root).includes('192.0.2.8'));
});
test('備份設定的已設定設備每頁顯示 10 筆並提供頁碼切換', async () => {
  const p = payload();
  for (let id = 8; id <= 18; id++) {
    const device = { device_id: id, name: '設備-' + id, host: '192.0.2.' + id, model: 'fortios', username: 'backup', port: 22, enabled: true, password_set: true };
    p.backup.settings.devices.push(device); p.devices.push({ device_id: id, name: device.name, host: device.host });
  }
  const root = h('div'); await create(async () => p).settings(root);
  assert.ok(text(root).includes('共 12 筆，每頁 10 筆；第 1／2 頁'));
  assert.ok(!text(walk(root).find((e) => e.tag === 'table')).includes('設備-18'));
  button(root, '下一頁').onclick();
  assert.ok(text(walk(root).find((e) => e.tag === 'table')).includes('設備-18'));
  assert.ok(text(root).includes('第 2／2 頁'));
});
test('新增設備必須先掃描並由管理者勾選確認 SSH 指紋，才會送出信任要求', async () => {
  const calls = []; const root = h('div');
  const ui = create(async (url, body) => {
    calls.push(body);
    if (body.action === 'host_key_scan') {
      const p = payload();
      p.backup.host_key = { device_id: 7, host: '192.0.2.1', algorithm: 'ssh-ed25519', fingerprint: 'SHA256:verified-on-device', token: 'one-time-candidate', expires_at: '2026-10-22T21:00:00Z', trusted: false };
      return p;
    }
    if (body.action === 'host_key_confirm') {
      const p = payload();
      p.backup.host_key = { device_id: 7, host: '192.0.2.1', key_type: 'ssh-ed25519', fingerprint: 'SHA256:verified-on-device', trusted: true, status: 'trusted' };
      return p;
    }
    return payload();
  });
  await ui.settings(root);
  const scan = button(root, '掃描 SSH 主機指紋');
  await scan.listeners.click();
  assert.ok(text(root).includes('SHA256:verified-on-device'));
  assert.ok(text(root).includes('設備端核對'));
  const trust = button(root, '確認後信任此主機金鑰');
  assert.equal(trust.disabled, true);
  assert.equal(calls.some((c) => c.action === 'host_key_confirm'), false);
  const confirm = walk(root).find((e) => e.tag === 'label' && text(e).includes('我已在設備端核對')).children.find((e) => e instanceof Element && e.type === 'checkbox');
  confirm.checked = true; confirm.listeners.change();
  assert.equal(trust.disabled, false);
  await trust.listeners.click();
  assert.deepEqual(JSON.parse(JSON.stringify(calls.at(-1))), { action: 'host_key_confirm', device_id: 7, token: 'one-time-candidate', fingerprint: 'SHA256:verified-on-device' });
  assert.ok(text(root).includes('已驗證 SSH 主機身分'));
});
test('掃描結果沒有一次性候選憑證時，不提供信任按鈕', async () => {
  const root = h('div');
  await create(async (url, body) => {
    const p = payload();
    if (body.action === 'host_key_scan') p.backup.host_key = { device_id: 7, fingerprint: 'SHA256:incomplete' };
    return p;
  }).settings(root);
  await button(root, '掃描 SSH 主機指紋').listeners.click();
  assert.equal(button(root, '確認後信任此主機金鑰'), undefined);
  assert.ok(text(root).includes('沒有提供可供核對'));
});
test('管理者可執行安全 SSH 診斷並只顯示摘要', async () => {
  const calls = []; const root = h('div');
  await create(async (url, body) => {
    calls.push(body); const p = payload();
    if (body.action === 'diagnose') p.backup.diagnosis = { device_id: 7, outcome: 'failed', stage: 'shell_timeout', transport: true, pty: true, shell: false, prompt_detected: false, raw_prompt: 'secret-banner#' };
    return p;
  }).governance(root);
  const diagnose = button(root, '診斷 SSH 連線');
  await diagnose.listeners.click();
  assert.deepEqual(JSON.parse(JSON.stringify(calls.at(-1))), { action: 'diagnose', device_id: 7 });
  assert.ok(text(root).includes('互動 shell 建立逾時'));
  assert.ok(text(root).includes('未送出任何設備 CLI 指令'));
  assert.ok(!text(root).includes('secret-banner#'));
});

test('FortiGate REST API 備援 Token 只送儲存要求且不回填畫面', async () => {
  const calls = []; const root = h('div'); const p = payload();
  await create(async (url, body) => { calls.push(body); return p; }).settings(root);
  const backend = findLabel(root, '備份方式'); backend.value = 'fortigate_api'; backend.listeners.change();
  const fields = walk(root).filter((e) => e.type === 'password');
  const apiToken = fields.at(-1); apiToken.value = 'api-token-only-for-request';
  await walk(root).find((e) => e.tag === 'form').onsubmit({ preventDefault() {} });
  const saved = calls.find((call) => call.action === 'save');
  assert.equal(saved.settings.devices[0].backup_backend, 'fortigate_api');
  assert.equal(saved.settings.devices[0].api_token, 'api-token-only-for-request');
  assert.ok(!text(root).includes('api-token-only-for-request'));
});

test('FortiGate REST fallback can run while Oxidized is unavailable', async () => {
  const p = payload();
  p.backup.settings.devices[0].backup_backend = 'fortigate_api';
  p.backup.settings.devices[0].api_token_set = true;
  p.backup.engine = { state: 'unavailable', message: 'Oxidized unavailable' };
  const root = h('div'); await create(async () => p).governance(root);
  const run = walk(root).find((e) => e.tag === 'button' && text(e) === '\u7acb\u5373\u5099\u4efd');
  const testRun = walk(root).find((e) => e.tag === 'button' && text(e) === '\u6e2c\u8a66\u4e26\u5efa\u7acb\u5099\u4efd');
  assert.equal(run.disabled, false);
  assert.equal(testRun.disabled, false);
  assert.equal(walk(root).some((e) => e.tag === 'button' && text(e) === '\u8a3a\u65b7 SSH \u9023\u7dda'), false);
});

test('已排入的工作不顯示成備份成功；差異以文字節點呈現', async () => {
  const p = payload(); p.backup.runs = [{ device_name: 'fw', kind: 'test', status: 'queued', requested_at: '2026-10-22 01:00:00' }];
  p.backup.changes = [{ device_name: 'fw', detected_at: '2026-10-22 01:00:00', added: 1, removed: 1, diff: '+ <script>alert(1)</script>' }];
  const root = h('div'); await create(async () => p).governance(root);
  assert.ok(text(root).includes('等待設備備份完成'));
  const diffLine = walk(root).find((e) => e.tag === 'span' && text(e) === '+ <script>alert(1)</script>');
  assert.equal(diffLine.children[0], '+ <script>alert(1)</script>');
  assert.equal(walk(root).filter((e) => e.tag === 'script').length, 0);
  assert.ok(walk(root).filter((e) => e.tag === 'button' && e.disabled).length === 2);
});

test('組態差異以綠紅色與圖例清楚標示新增和刪除行', async () => {
  const p = payload();
  p.backup.changes = [{ device_name: 'fw', detected_at: '2026-10-22 01:00:00', added: 1, removed: 1, diff: '+新增設定\n-刪除設定\n unchanged' }];
  const root = h('div'); await create(async () => p).governance(root);
  const added = walk(root).find((e) => e.tag === 'span' && text(e) === '+新增設定');
  const removed = walk(root).find((e) => e.tag === 'span' && text(e) === '-刪除設定');
  assert.ok(added.style.includes('color:#86efac'));
  assert.ok(removed.style.includes('color:#fca5a5'));
  assert.ok(text(root).includes('綠色＝新增設定'));
  assert.ok(text(root).includes('紅色＝刪除設定'));
});

test('設備與狀態的各項多筆清單均以每頁 10 筆分頁', async () => {
  const p = payload();
  p.backup.engine = { state: 'ready', message: '備份服務已就緒' };
  for (let index = 1; index <= 11; index++) {
    const id = index + 20;
    p.backup.settings.devices.push({ device_id: id, name: '分頁設備-' + index, host: '198.51.100.' + index, enabled: true });
    p.backup.runs.push({ device_name: '執行紀錄-' + index, kind: 'manual', status: 'success', requested_at: '2026-10-22 01:00:00' });
    p.backup.changes.push({ device_name: '變更紀錄-' + index, detected_at: '2026-10-22 01:00:00', added: 1, removed: 0, diff: '+設定-' + index });
    p.backup.audit = p.backup.audit || [];
    p.backup.audit.push({ occurred_at: '2026-10-22 01:00:00', actor: 'admin', action: 'save', detail: '操作紀錄-' + index });
  }
  const root = h('div'); await create(async () => p).governance(root);
  assert.equal((text(root).match(/共 12 筆，每頁 10 筆；第 1／2 頁/g) || []).length, 1);
  assert.equal((text(root).match(/共 11 筆，每頁 10 筆；第 1／2 頁/g) || []).length, 3);
  assert.ok(!text(root).includes('分頁設備-11'));
  assert.ok(!text(root).includes('執行紀錄-11'));
  assert.ok(!text(root).includes('變更紀錄-11'));
  assert.ok(!text(root).includes('操作紀錄-11'));
  walk(root).filter((e) => e.tag === 'button' && text(e) === '下一頁').forEach((next) => next.onclick());
  assert.ok(text(root).includes('分頁設備-11'));
  assert.ok(text(root).includes('執行紀錄-11'));
  assert.ok(text(root).includes('變更紀錄-11'));
  assert.ok(text(root).includes('操作紀錄-11'));
});

test('等待中的備份不自動重新整理整個管理頁', async () => {
  const p = payload();
  p.backup.engine = { state: 'ready', message: '備份服務已就緒' };
  p.backup.runs = [{ device_name: 'fw', kind: 'manual', status: 'queued', requested_at: '2026-10-22 01:00:00' }];
  let timers = 0;
  const root = h('div');
  await create(async () => p, { setTimeout: () => { timers++; return 0; } }).governance(root);
  assert.equal(timers, 0);
  assert.ok(text(root).includes('重新整理備份狀態'));
});
test('介接不可用時顯示原因與重新讀取入口', async () => {
  const root = h('div'); await create(async () => { throw Error('備份工具尚未安裝'); }).settings(root);
  assert.ok(text(root).includes('備份工具尚未安裝'));
  assert.ok(walk(root).some((e) => e.tag === 'button' && text(e) === '重新讀取'));
});

test('儲存期間暫停整個表單，避免使用者的修改被完成回覆覆蓋', async () => {
  let finish;
  const waiting = new Promise((resolve) => { finish = resolve; });
  const root = h('div');
  const ui = create(async (url, body) => body.action === 'save' ? waiting : payload());
  await ui.settings(root);
  const form = walk(root).find((e) => e.tag === 'form');
  const saving = form.onsubmit({ preventDefault() {} });
  assert.ok(form.querySelectorAll().every((el) => el.disabled));
  finish(payload()); await saving;
  assert.ok(walk(root).find((e) => e.tag === 'form'));
});
const findLabel = (root, label) => walk(root).find((e) => e.attrs['aria-label'] === label);
const button = (root, title) => walk(root).find((e) => e.tag === 'button' && text(e) === title);
const historyPayload = (offset = 0, device = 7) => {
  const p = payload();
  p.backup.history = { device_id: device, offset, limit: 10, total_runs: 21, total_versions: 21, runs: [], versions: [{ id: offset ? 1 : 21, device_id: device, created_at: '2026-10-22 01:00:00' }] };
  return p;
};
test('管理頁切換保留未儲存的設定表單，設備可依位址搜尋', async () => {
  const root = h('div'); await create(async () => payload()).governance(root);
  const search = findLabel(root, '搜尋已設定設備'); search.value = 'no-match'; search.listeners.input();
  assert.ok(text(root).includes('沒有符合條件'));
  await button(root, '備份設定').onclick();
  const password = walk(root).find((e) => e.type === 'password'); password.value = 'unsaved';
  button(root, '設備與狀態').onclick(); await button(root, '備份設定').onclick();
  assert.equal(walk(root).find((e) => e.type === 'password'), password);
  assert.equal(password.value, 'unsaved');
});
test('歷史翻頁保留版本選擇並傳送指定代數，比較輸出為純文字', async () => {
  const calls = []; const root = h('div');
  await create(async (url, body) => {
    calls.push(body);
    if (body.action === 'history') return historyPayload(body.offset);
    if (body.action === 'compare') { const p = payload(); p.backup.comparison = { from_version_id: 21, to_version_id: 1, changed: true, added: 1, removed: 0, diff: '+ <script>bad</script>', truncated: true }; return p; }
    return payload();
  }).governance(root);
  await button(root, '備份歷史與比較').onclick();
  const picker = findLabel(root, '查詢備份設備'); picker.value = '7'; await picker.listeners.change();
  const from = findLabel(root, '比較起始版本'); from.value = '21'; from.listeners.change();
  await button(root, '下一頁').onclick();
  assert.equal(from.value, '21'); assert.ok(text(from).includes('#21') && text(from).includes('#1'));
  const to = findLabel(root, '比較結束版本'); to.value = '1'; to.listeners.change();
  await button(root, '比較兩個版本').listeners.click();
  assert.deepEqual(JSON.parse(JSON.stringify(calls.at(-1))), { action: 'compare', device_id: 7, from_version_id: 21, to_version_id: 1 });
  assert.ok(text(root).includes('已截斷')); assert.equal(walk(root).filter((e) => e.tag === 'script').length, 0);
  const diffLine = walk(root).find((e) => e.tag === 'span' && text(e) === '+ <script>bad</script>');
  assert.ok(diffLine && diffLine.style.includes('color:#86efac'));
  assert.ok(text(root).includes('綠色＝新增設定'));
  assert.ok(walk(root).some((e) => e.class === 'config-side-by-side'));
  assert.ok(text(root).includes('起始版本 #21'));
  assert.ok(text(root).includes('結束版本 #1'));
  assert.ok(text(root).includes('左右欄只顯示有差異的設定區段'));
  picker.value = ''; await picker.listeners.change();
  assert.equal(from.value, ''); assert.equal(to.value, ''); assert.ok(button(root, '比較兩個版本').disabled);
});
test('備份版本可由專用下載函式下載，且不混入一般 API 請求', async () => {
  const downloads = []; const root = h('div');
  await create(async (url, body) => body.action === 'history' ? historyPayload() : payload(), {
    downloadVersion: async (deviceId, versionId) => { downloads.push([deviceId, versionId]); }
  }).governance(root);
  await button(root, '備份歷史與比較').onclick();
  const picker = findLabel(root, '查詢備份設備'); picker.value = '7'; await picker.listeners.change();
  const download = button(root, '下載'); await download.onclick();
  assert.deepEqual(downloads, [[7, 21]]);
  assert.ok(text(root).includes('已開始下載版本 #21'));
});
test('偵測到分頁提示的不完整舊版本不可下載或比較', async () => {
  const root = h('div'); const history = historyPayload();
  history.backup.history.versions[0].complete = false;
  await create(async (url, body) => body.action === 'history' ? history : payload()).governance(root);
  await button(root, '備份歷史與比較').onclick();
  const picker = findLabel(root, '查詢備份設備'); picker.value = '7'; await picker.listeners.change();
  assert.ok(text(root).includes('不完整，請重新備份'));
  assert.equal(button(root, '下載'), undefined);
  assert.ok(!text(findLabel(root, '比較起始版本')).includes('#21'));
});
test('切換設備後忽略舊歷史回應', async () => {
  let finish; const delayed = new Promise((resolve) => { finish = resolve; });
  const root = h('div'); await create(async (url, body) => body.action === 'history' ? delayed : payload()).governance(root);
  await button(root, '備份歷史與比較').onclick();
  const picker = findLabel(root, '查詢備份設備'); picker.value = '7'; const pending = picker.listeners.change();
  picker.value = ''; await picker.listeners.change(); finish(historyPayload()); await pending;
  assert.ok(text(root).includes('請先選擇')); assert.ok(!text(findLabel(root, '比較起始版本')).includes('#21'));
});
test('重新整理歷史更新新增設備並保留同設備的版本選取', async () => {
  let updated = false; const root = h('div');
  await create(async (url, body) => {
    if (body.action === 'history') return historyPayload();
    const p = payload(); p.backup.history_devices = [{ device_id: 7, name: '原設備', configured: true }];
    if (updated) p.backup.history_devices.push({ device_id: 8, name: '新增設備', configured: true });
    return p;
  }).governance(root);
  await button(root, '備份歷史與比較').onclick();
  const picker = findLabel(root, '查詢備份設備'); picker.value = '7'; await picker.listeners.change();
  const from = findLabel(root, '比較起始版本'); from.value = '21'; from.listeners.change();
  updated = true; await button(root, '重新整理設備與歷史').onclick();
  assert.ok(text(picker).includes('新增設備')); assert.equal(picker.value, '7'); assert.equal(from.value, '21');
});
test('比較等待期間切換設備，不顯示前一台設備的比較結果', async () => {
  let finish; const delayed = new Promise((resolve) => { finish = resolve; });
  const root = h('div'); await create(async (url, body) => body.action === 'history' ? historyPayload(body.offset) : body.action === 'compare' ? delayed : payload()).governance(root);
  await button(root, '備份歷史與比較').onclick();
  const picker = findLabel(root, '查詢備份設備'); picker.value = '7'; await picker.listeners.change();
  const from = findLabel(root, '比較起始版本'); from.value = '21'; from.listeners.change();
  await button(root, '下一頁').onclick(); const to = findLabel(root, '比較結束版本'); to.value = '1'; to.listeners.change();
  const pending = button(root, '比較兩個版本').listeners.click();
  picker.value = ''; await picker.listeners.change();
  const p = payload(); p.backup.comparison = { diff: '舊設備的比較結果', added: 1, removed: 0 }; finish(p); await pending;
  assert.ok(!text(root).includes('舊設備的比較結果'));
});
