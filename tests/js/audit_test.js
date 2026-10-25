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
const automation = (overrides = {}) => ({ counts: { total: 2, ready: 1, pending: 1, source_errors: 0, pending_proposals: 1, stale: 0, overdue: 0 },
  assets: [{ id: 13, name: 'FW-01', type: 'network', readiness: 'ready', reason: '' },
    { id: 22, name: 'Linux-01', type: 'linux', readiness: 'pending', reason: '缺少 SSH 主機金鑰' }],
  packs: [{ key: 'fortigate', version: '1', enabled: false, rules: [{ id: 'FG-1', title: '設定檢查', description: '唯讀' }] }],
  mappings: [], runs: [], proposals: [], ...overrides });
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
    const { root, app } = ui(async (_, body) => { actions.push(body.action); return body.action === 'summary' ? { summary }
      : body.action === 'automation_state' ? { automation: automation() } : { detail }; }, canHandle);
    await app.load();
    assert.deepEqual(actions, canHandle ? ['summary', 'detail', 'automation_state'] : ['summary']);
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
    if (body.action === 'automation_state') return { automation: automation() };
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
    if (body.action === 'automation_state') return { automation: automation() };
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

test('管理者工作台顯示資產就緒與檢查包，並送出啟用、對應及執行動作', async () => {
  const calls = [];
  const detail = { selected_cycle: 1, current_cycle_id: 1, catalogs: [], cycles: [],
    controls: [{ id: 9, code: 'CTRL-1', title: '網路設備' }], evidence: [], remediation: [], events: [], storage_free_bytes: 1000 };
  const { root, app } = ui(async (_, body) => {
    calls.push(body);
    if (body.action === 'summary') return { summary: { ready: false, message: '未建立評估基準' } };
    if (body.action === 'detail') return { detail };
    if (body.action === 'automation_state') return { automation: automation() };
    return { ok: true };
  }, true);
  await app.load();
  assert.match(words(root), /Linux-01/);
  assert.match(words(root), /缺少 SSH 主機金鑰/);
  assert.equal(root.walk().filter((x) => x.tag === 'button' && words(x) === '執行檢查').length, 2);
  const activate = root.walk().find((x) => x.tag === 'button' && words(x) === '啟用檢查包');
  await activate.onclick();
  const choice = root.walk().find((x) => x.tag === 'select' && x.children.some((c) => c instanceof Element && words(c).includes('CTRL-1')));
  choice.value = '9';
  const map = root.walk().find((x) => x.tag === 'button' && words(x) === '建立對應');
  await map.onclick({ currentTarget: map });
  const execute = root.walk().find((x) => x.tag === 'button' && words(x) === '執行檢查');
  await execute.onclick();
  assert.deepEqual(calls.filter((x) => x.action === 'automation_activate').map((x) => [x.pack_key, x.enabled]), [['fortigate', true]]);
  assert.deepEqual(calls.filter((x) => x.action === 'automation_map').map((x) => [x.cycle_id, x.control_id, x.rule_id]), [[1, 9, 'FG-1']]);
  assert.deepEqual(calls.filter((x) => x.action === 'automation_run').map((x) => x.asset_id), [13]);
});

test('待審建議須由管理者明確選取；核定送出原建議，駁回需理由', async () => {
  const calls = [];
  const detail = { selected_cycle: 1, current_cycle_id: 1, catalogs: [], cycles: [], controls: [], evidence: [], remediation: [], events: [], storage_free_bytes: 1000 };
  const proposed = automation({ proposals: [
    { id: 7, control_id: 9, control_code: 'CTRL-1', status: 'compliant', reason: '所有檢查通過', evidence_id: 41, created_at: '2026-10-24' },
    { id: 8, control_id: 10, control_code: 'CTRL-2', status: 'compliant', reason: '所有檢查通過', evidence_id: 42, created_at: '2026-10-24' }] });
  const { root, app } = ui(async (_, body) => {
    calls.push(body);
    if (body.action === 'summary') return { summary: { ready: false, message: '未建立評估基準' } };
    if (body.action === 'detail') return { detail };
    if (body.action === 'automation_state') return { automation: proposed };
    return { ok: true };
  }, true);
  await app.load();
  const boxes = root.walk().filter((x) => x.tag === 'input' && x.type === 'checkbox');
  boxes.forEach((box) => { box.checked = true; box.listeners.change(); });
  const approve = root.walk().find((x) => x.tag === 'button' && words(x) === '核定所選');
  await approve.onclick({ currentTarget: approve });
  const review = calls.find((x) => x.action === 'automation_review');
  assert.deepEqual([...review.proposal_ids], [7, 8]);
  assert.equal(review.decision, 'approve');
  assert.equal(review.status, 'compliant');

  const reject = root.walk().find((x) => x.tag === 'button' && words(x) === '駁回所選');
  const newBoxes = root.walk().filter((x) => x.tag === 'input' && x.type === 'checkbox');
  newBoxes[0].checked = true; newBoxes[0].listeners.change();
  await reject.onclick({ currentTarget: reject });
  assert.equal(calls.filter((x) => x.action === 'automation_review').length, 1);
  const reason = root.walk().find((x) => x.tag === 'textarea' && x.placeholder === '駁回或覆寫時請填寫理由');
  reason.value = '需要重新取樣';
  await reject.onclick({ currentTarget: reject });
  const rejected = calls.filter((x) => x.action === 'automation_review')[1];
  assert.equal(rejected.decision, 'reject');
  assert.equal(rejected.reason, '需要重新取樣');
  assert.equal('status' in rejected, false);
});

test('沒有目前週期時可看技術檢查，但不能對應或核定；自動化故障不遮蔽手動流程', async () => {
  const detail = { selected_cycle: null, current_cycle_id: 0, catalogs: [], cycles: [], controls: [], evidence: [], remediation: [], events: [], storage_free_bytes: 1000 };
  let broken = false;
  const { root, app } = ui(async (_, body) => {
    if (body.action === 'summary') return { summary: { ready: false, message: '未建立評估基準' } };
    if (body.action === 'detail') return { detail };
    if (body.action === 'automation_state') {
      if (broken) throw new Error('來源暫時無法使用');
      return { automation: automation({ proposals: [{ id: 7, control_code: 'CTRL-1', status: 'unknown' }] }) };
    }
  }, true);
  await app.load();
  assert.match(words(root), /Linux-01/);
  assert.equal(root.walk().some((x) => x.tag === 'button' && words(x) === '建立對應'), false);
  assert.equal(root.walk().find((x) => x.tag === 'button' && words(x) === '核定所選').disabled, true);
  broken = true;
  await app.load();
  assert.match(words(root), /來源暫時無法使用/);
  assert.match(words(root), /控制項目錄/);
});

test('事件案類型須明確對應控制項，來源故障與空結果分開顯示', async () => {
  const calls = [];
  const detail = { selected_cycle: 1, current_cycle_id: 1, catalogs: [], cycles: [],
    controls: [{ id: 9, code: 'CTRL-1', title: '證據保存' }], evidence: [], remediation: [], events: [], storage_free_bytes: 1000 };
  const state = automation({ case_categories: [{ key: 'suspicious_login', label: '可疑登入' }],
    case_mappings: [{ id: 14, category: 'suspicious_login', control_id: 9, control_code: 'CTRL-1' }],
    case_attempts: [{ case_id: 21, status: 'empty', attempted_at: '2026-10-24' },
      { case_id: 22, status: 'error', attempted_at: '2026-10-24' }] });
  const { root, app } = ui(async (_, body) => {
    calls.push(body);
    if (body.action === 'summary') return { summary: { ready: false, message: '未建立評估基準' } };
    if (body.action === 'detail') return { detail };
    if (body.action === 'automation_state') return { automation: state };
    return { ok: true };
  }, true);
  await app.load();
  assert.match(words(root), /查無資料（已保存空結果）/);
  assert.match(words(root), /來源故障/);
  const control = root.walk().filter((x) => x.tag === 'select' && x.children.some((c) => words(c).includes('CTRL-1'))).at(-1);
  control.value = '9';
  await root.walk().find((x) => x.tag === 'button' && words(x) === '啟用此類型擷取').onclick();
  const mapped = calls.find((x) => x.action === 'automation_map_case');
  assert.deepEqual([mapped.cycle_id, mapped.control_id, mapped.category], [1, 9, 'suspicious_login']);
  await root.walk().find((x) => x.tag === 'button' && words(x) === '停用').onclick();
  const unmapped = calls.find((x) => x.action === 'automation_unmap');
  assert.deepEqual([unmapped.mapping_id, unmapped.kind], [14, 'case']);
});

test('新掃描通過只提醒待結案改善事項，不自動結案', async () => {
  const detail = { selected_cycle: 1, current_cycle_id: 1, catalogs: [], cycles: [],
    controls: [], evidence: [], remediation: [], events: [], storage_free_bytes: 1000 };
  const actions = [];
  const { root, app } = ui(async (_, body) => {
    actions.push(body.action);
    if (body.action === 'summary') return { summary: { ready: false, message: '未建立評估基準' } };
    if (body.action === 'detail') return { detail };
    if (body.action === 'automation_state') return { automation: automation({
      remediation_hints: [{ id: 17, control_code: 'CTRL-1', due_on: '2026-11-01' }]
    }) };
    throw new Error('unexpected action');
  }, true);
  await app.load();
  assert.match(words(root), /改善事項待結案提醒/);
  assert.match(words(root), /CTRL-1/);
  assert.match(words(root), /確認實際改善情形/);
  assert.deepEqual(actions, ['summary', 'detail', 'automation_state']);
});
