const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

class Element {
  constructor(tag, attrs, children) { this.tag = tag; this.attrs = attrs || {}; Object.assign(this, this.attrs); this.children = children || []; this.value = this.attrs.value || ''; this.listeners = {}; }
  replaceChildren(...children) {
    this.children = children;
    if (this.tag === 'select') this.value = children[0] instanceof Element ? String(children[0].value) : '';
  }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  querySelector(selector) { return this.walk().find((x) => x.attrs.id === selector.slice(1)); }
  walk() { return [this, ...this.children.flatMap((c) => c instanceof Element ? c.walk() : [])]; }
}
const h = (tag, attrs, ...children) => new Element(tag, attrs, children.flat(Infinity).filter((x) => x != null && x !== false));
const words = (node) => node instanceof Element ? node.children.map(words).join(' ') : String(node || '');
function ui(api, canHandle) {
  const ctx = { window: {}, setTimeout() {} };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../librenms/html/js/ioc-audit.js'), 'utf8'), ctx);
  const root = h('main');
  return { root, app: ctx.window.IocAudit({ api, root, h, toast() {}, canHandle, csrf: 'csrf-test' }) };
}

test('沒有正式目錄時明確顯示未建立基準，不顯示假符合率', async () => {
  const actions = [];
  const { root, app } = ui(async (_, body) => { actions.push(body.action); return { summary: { ready: false, message: '未建立評估基準' } }; }, false);
  await app.load();
  assert.deepEqual(actions, ['summary']);
  assert.match(words(root), /未建立評估基準/);
  assert.doesNotMatch(words(root), /符合率.*%/);
});

test('檢視者只拿摘要，管理者才會要求目錄、證據與改善明細', async () => {
  const summary = { ready: true, catalog: 'v1', cycle: { title: '年度', starts_on: '2026-01-01', ends_on: '2026-12-31' },
    counts: { total: 2, assessed: 1, unreviewed: 1, compliant: 1, gap: 0, not_applicable: 0, snapshots: 1, overdue: 0,
      remediations: 0, remediations_done: 0, snapshot_bytes: 100 }, domains: [] };
  const detail = { selected_cycle: null, catalogs: [], cycles: [], controls: [], evidence: [], remediation: [], events: [], storage_free_bytes: 1000 };
  for (const canHandle of [false, true]) {
    const actions = [];
    const { root, app } = ui(async (_, body) => { actions.push(body.action); return body.action === 'summary' ? { summary } : { detail }; }, canHandle);
    await app.load();
    assert.deepEqual(actions, canHandle ? ['summary', 'detail'] : ['summary']);
    assert.match(words(root), /1／2/);
    assert.equal(root.walk().some((x) => x.tag === 'input'), canHandle);
  }
});

test('管理者預覽後匯入時送出同一份目錄雜湊', async () => {
  const calls = [];
  const detail = { selected_cycle: null, catalogs: [], cycles: [], controls: [], evidence: [], remediation: [], events: [], storage_free_bytes: 1000 };
  const { root, app } = ui(async (_, body) => {
    calls.push(body);
    if (body.action === 'summary') return { summary: { ready: false, message: '未建立評估基準' } };
    if (body.action === 'detail') return { detail };
    if (body.action === 'preview') return { preview: { digest: 'a'.repeat(64), count: 1, added: 1, changed: 0, removed: 0, unchanged: 0,
      added_codes: ['CTRL-1'], changed_codes: [], removed_codes: [] } };
    if (body.action === 'import') return { catalog_id: 1 };
    throw new Error('unexpected action');
  }, true);
  await app.load();
  const file = root.walk().find((x) => x.tag === 'input' && x.type === 'file');
  file.files = [{ size: 70, text: async () => 'code,domain,title,requirement,description\nCTRL-1,網路,檢查,須保存證據,\n' }];
  const label = root.walk().find((x) => x.tag === 'input' && x.placeholder === '例如 2026 年正式控制項');
  label.value = '2026 正式版';
  await root.walk().find((x) => x.tag === 'button' && words(x) === '預覽差異').onclick();
  await root.walk().find((x) => x.tag === 'button' && words(x) === '匯入並啟用').onclick();
  const imported = calls.find((x) => x.action === 'import');
  assert.equal(imported.expected_digest, 'a'.repeat(64));
  assert.match(imported.csv, /CTRL-1/);
});

test('管理者可從既有備份設備選擇完整版本建立連結', async () => {
  const calls = [];
  const detail = { selected_cycle: 1, current_cycle_id: 1, catalogs: [], cycles: [{ id: 1, title: '年度', version_label: 'v1', active: 1 }],
    controls: [{ id: 9, code: 'CTRL-1', domain: '網路', title: '設定備份', requirement: '保存版本', description: '', status: 'unreviewed' }],
    evidence: [], remediation: [], events: [], storage_free_bytes: 1000 };
  const { root, app } = ui(async (url, body) => {
    calls.push({ url, ...body });
    if (body.action === 'summary') return { summary: { ready: false, message: '尚未建立評估週期' } };
    if (body.action === 'detail') return { detail };
    if (body.action === 'get') return { backup: { history_devices: [{ device_id: 13, name: 'FW-01' }] } };
    if (body.action === 'history') return { backup: { history: { versions: [{ id: 10, complete: true, digest: 'b'.repeat(64), created_at: '2026-10-23' }], total_versions: 1 } } };
    if (body.action === 'link_backup') return { evidence_id: 8 };
    throw new Error('unexpected action');
  }, true);
  await app.load();
  await root.walk().find((x) => x.tag === 'button' && words(x) === '載入備份設備').onclick();
  await root.walk().find((x) => x.tag === 'button' && words(x) === '連結所選版本').onclick();
  const linked = calls.find((x) => x.action === 'link_backup');
  assert.deepEqual([linked.device_id, linked.version_id, linked.control_id], [13, 10, 9]);
  assert.equal(calls.some((x) => x.action === 'download'), false);
});
