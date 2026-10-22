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
  const root = h('div'); const ui = create(async () => payload());
  await ui.settings(root);
  const password = walk(root).find((e) => e.type === 'password');
  assert.equal(password.value, ''); assert.equal(password.required, false);
  assert.ok(password.placeholder.includes('留白可沿用'));
  const select = walk(root).find((e) => e.attrs['aria-label'] === '選擇設備');
  select.value = '8'; select.listeners.change();
  assert.equal(password.required, true);
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
  const root = h('div'); const ui = create(async () => payload());
  await ui.settings(root);
  assert.ok(walk(root).some((e) => e.tag === 'table' && text(e).includes('備份帳號')));
  assert.equal(walk(root).filter((e) => e.tag === 'fieldset').length, 1);
  button(root, '＋ 加入設備').onclick();
  assert.equal(walk(root).filter((e) => e.tag === 'fieldset').length, 1);
  const select = findLabel(root, '選擇設備'); select.value = '7'; select.listeners.change();
  const password = walk(root).find((e) => e.type === 'password');
  assert.equal(password.required, true);
  const remove = walk(root).filter((e) => e.tag === 'button' && text(e) === '移除').at(-1);
  remove.onclick();
  assert.ok(text(root).includes('尚未加入備份設備') === false);
});
test('已排入的工作不顯示成備份成功；差異以文字節點呈現', async () => {
  const p = payload(); p.backup.runs = [{ device_name: 'fw', kind: 'test', status: 'queued', requested_at: '2026-10-22 01:00:00' }];
  p.backup.changes = [{ device_name: 'fw', detected_at: '2026-10-22 01:00:00', added: 1, removed: 1, diff: '+ <script>alert(1)</script>' }];
  const root = h('div'); await create(async () => p).governance(root);
  assert.ok(text(root).includes('等待設備備份完成'));
  const pre = walk(root).find((e) => e.tag === 'pre'); assert.equal(pre.children[0], '+ <script>alert(1)</script>');
  assert.equal(walk(root).filter((e) => e.tag === 'script').length, 0);
  assert.ok(walk(root).filter((e) => e.tag === 'button' && e.disabled).length === 2);
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
  p.backup.history = { device_id: device, offset, limit: 20, total_runs: 21, total_versions: 21, runs: [], versions: [{ id: offset ? 1 : 21, device_id: device, created_at: '2026-10-22 01:00:00' }] };
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
  assert.ok(walk(root).some((e) => e.tag === 'pre' && text(e) === '+ <script>bad</script>'));
  picker.value = ''; await picker.listeners.change();
  assert.equal(from.value, ''); assert.equal(to.value, ''); assert.ok(button(root, '比較兩個版本').disabled);
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
