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
  const ctx = { window: {}, setTimeout: () => 0 };
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
test('已排入的工作不顯示成備份成功；差異以文字節點呈現', async () => {
  const p = payload(); p.backup.runs = [{ device_name: 'fw', kind: 'test', status: 'queued', requested_at: '2026-10-22 01:00:00' }];
  p.backup.changes = [{ device_name: 'fw', detected_at: '2026-10-22 01:00:00', added: 1, removed: 1, diff: '+ <script>alert(1)</script>' }];
  const root = h('div'); await create(async () => p).governance(root);
  assert.ok(text(root).includes('等待設備備份完成'));
  const pre = walk(root).find((e) => e.tag === 'pre'); assert.equal(pre.children[0], '+ <script>alert(1)</script>');
  assert.equal(walk(root).filter((e) => e.tag === 'script').length, 0);
  assert.ok(walk(root).filter((e) => e.tag === 'button' && e.disabled).length === 2);
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
