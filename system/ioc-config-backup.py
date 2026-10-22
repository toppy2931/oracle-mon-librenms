#!/usr/bin/env python3
"""IOC 組態備份閘道：受保護狀態、受管 Oxidized、排程與版本比較。"""
import difflib
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import sqlite3
import stat
import sys
import time
from datetime import datetime, timezone
from urllib.error import URLError
from urllib.request import build_opener, ProxyHandler, HTTPRedirectHandler
from urllib.parse import quote

STATE_DIR = '/var/lib/oracle-mon/config-backup'
ENGINE_URL = 'http://127.0.0.1:8879'
MODELS = {'ios', 'fortios', 'junos', 'routeros'}
# FortiOS 內建模型預設採互動式 shell，部分 FortiGate CLI 提示字元無法辨識；
# IOC 專用模型改用已驗證的 SSH exec channel。
ENGINE_MODELS = {'fortios': 'iocfortios'}
LIMIT = 2 * 1024 * 1024


class BackupError(Exception):
    def __init__(self, code, message):
        self.code, self.message = code, message
        super().__init__(message)


def utcnow():
    return datetime.now(timezone.utc).isoformat()


def timestamp(value):
    if not isinstance(value, str):
        raise ValueError('時間格式錯誤')
    if value.endswith(' UTC'):
        return datetime.strptime(value, '%Y-%m-%d %H:%M:%S UTC').replace(tzinfo=timezone.utc)
    return datetime.fromisoformat(value.replace('Z', '+00:00')).astimezone(timezone.utc)


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise BackupError('unavailable', '備份工具回傳非預期重新導向')


class Engine:
    def __init__(self):
        self.deadline = time.monotonic() + 4

    def request(self, route):
        # 固定回環端點，不接受使用者 URL，不採用代理、不跟隨重新導向。
        opener = build_opener(ProxyHandler({}), NoRedirect())
        try:
            remaining = self.deadline - time.monotonic()
            if remaining <= 0:
                raise BackupError('unavailable', '備份工具本次查詢時間已用盡，將於下次排程繼續')
            with opener.open(ENGINE_URL + route, timeout=min(1, remaining)) as response:
                raw = response.read(LIMIT + 1)
            if len(raw) > LIMIT:
                raise BackupError('invalid_response', '備份內容超過 2 MiB 上限')
            return json.loads(raw.decode('utf-8'))
        except BackupError:
            raise
        except (OSError, URLError, ValueError):
            raise BackupError('unavailable', '備份工具尚未啟動或回應格式不相容，請聯絡維護人員') from None


def safe_text(value, label, maximum=128, allow_empty=False):
    if not isinstance(value, str) or len(value) > maximum or (not value and not allow_empty):
        raise BackupError('validation', label + '格式或長度不正確')
    # CSV 不支援引號逸出，亦禁止 Ruby source 的插值語法。
    if any(ord(c) < 32 or ord(c) == 127 for c in value) or '|' in value or '#{' in value:
        raise BackupError('validation', label + '含有不支援的字元')
    return value


def secure_path(path, directory=False):
    for part in [path, *path.parents]:
        if part.is_symlink():
            raise BackupError('unsafe_state', '備份狀態路徑不可使用符號連結')
    if path.exists() and os.name == 'posix':
        info = path.stat()
        if info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise BackupError('unsafe_state', '備份狀態擁有者或權限不正確')
        if not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)):
            raise BackupError('unsafe_state', '備份狀態類型不正確')


class Worker:
    def __init__(self, root=None, engine=None):
        self.root = Path(root or os.environ.get('IOC_BACKUP_STATE_DIR', STATE_DIR)).absolute()
        secure_path(self.root, True)
        self.root.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.engine = engine or Engine()
        db_path = self.root / 'state.sqlite3'
        secure_path(db_path)
        self.db = sqlite3.connect(db_path, timeout=3, isolation_level=None)
        self.db.row_factory = sqlite3.Row
        self.db.execute('PRAGMA journal_mode=WAL')
        if os.name == 'posix':
            os.chmod(db_path, 0o600)
        self.db.executescript('''
            CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS runs (
                id INTEGER PRIMARY KEY, device_id INTEGER, device_name TEXT, kind TEXT,
                status TEXT, requested_at TEXT, completed_at TEXT, message TEXT,
                version_id INTEGER, previous_end TEXT, dispatched_at TEXT);
            CREATE TABLE IF NOT EXISTS versions (
                id INTEGER PRIMARY KEY, device_id INTEGER, created_at TEXT, digest TEXT,
                content TEXT);
            CREATE TABLE IF NOT EXISTS changes (
                id INTEGER PRIMARY KEY, device_id INTEGER, device_name TEXT, detected_at TEXT,
                previous_version_id INTEGER, version_id INTEGER, added INTEGER, removed INTEGER,
                diff TEXT);
            CREATE TABLE IF NOT EXISTS audit (
                id INTEGER PRIMARY KEY, occurred_at TEXT, actor TEXT, action TEXT,
                detail TEXT);
            CREATE INDEX IF NOT EXISTS runs_device_id_id ON runs(device_id,id);
            CREATE INDEX IF NOT EXISTS versions_device_id_id ON versions(device_id,id);
        ''')

    def close(self):
        self.db.close()

    def get_meta(self, key, default=None):
        row = self.db.execute('SELECT value FROM meta WHERE key=?', (key,)).fetchone()
        return json.loads(row[0]) if row else default

    def put_meta(self, key, value):
        self.db.execute('INSERT INTO meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',
                        (key, json.dumps(value, ensure_ascii=False)))

    def settings(self):
        return self.get_meta('settings', {'enabled': False, 'interval_hours': 24, 'devices': []})

    def public_settings(self):
        settings = self.settings()
        settings['devices'] = [{**{k: v for k, v in d.items() if k != 'password'},
                                'password_set': bool(d.get('password'))} for d in settings['devices']]
        return settings

    def validate(self, submitted):
        if not isinstance(submitted, dict) or type(submitted.get('enabled')) is not bool:
            raise BackupError('validation', '請指定是否啟用排程')
        interval = submitted.get('interval_hours')
        if type(interval) is not int or not 1 <= interval <= 168:
            raise BackupError('validation', '備份間隔必須為 1 至 168 小時')
        devices = submitted.get('devices')
        if not isinstance(devices, list) or len(devices) > 50:
            raise BackupError('validation', '最多可設定 50 台設備')
        previous = {d['device_id']: d for d in self.settings()['devices']}
        result, seen, hosts = [], set(), set()
        for item in devices:
            if not isinstance(item, dict):
                raise BackupError('validation', '設備資料格式錯誤')
            ident = item.get('device_id')
            if type(ident) is not int or not 1 <= ident <= 2147483647 or ident in seen:
                raise BackupError('validation', '設備識別碼無效或重複')
            seen.add(ident)
            host = safe_text(item.get('host'), '設備位址', 253).lower()
            try:
                address = ipaddress.ip_address(host)
                if address.is_loopback or address.is_link_local or address.is_multicast or address.is_unspecified or '%' in host:
                    raise BackupError('validation', '設備不可使用本機、鏈路本地或群播位址')
            except ValueError:
                if not re.fullmatch(r'(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*', host) or host == 'localhost' or host.endswith('.localhost'):
                    raise BackupError('validation', '請輸入有效的設備 IP 或主機名稱') from None
                if re.fullmatch(r'[0-9.]+', host):
                    raise BackupError('validation', '設備 IP 格式錯誤')
            if host in hosts:
                raise BackupError('validation', '同一設備位址不可重複納管')
            hosts.add(host)
            model = item.get('model')
            if not isinstance(model, str) or model not in MODELS:
                raise BackupError('validation', '尚未支援此設備類型')
            if type(item.get('port', 22)) is not int or item.get('port', 22) != 22:
                raise BackupError('validation', '第一版僅支援 SSH 連接埠 22')
            if type(item.get('enabled')) is not bool:
                raise BackupError('validation', '請指定設備是否參與備份')
            password = item.get('password', '')
            if password == '':
                password = previous.get(ident, {}).get('password', '')
            if not password:
                raise BackupError('password_required', '首次設定設備時請填寫備份帳號密碼')
            password = safe_text(password, '設備密碼', 512)
            result.append({'device_id': ident, 'name': safe_text(item.get('name'), '設備名稱', 128),
                           'host': host, 'model': model, 'username': safe_text(item.get('username'), '設備帳號', 128),
                           'password': password, 'port': 22, 'enabled': item['enabled']})
        return {'enabled': submitted['enabled'], 'interval_hours': interval, 'devices': result}

    def write_private(self, path, content):
        secure_path(path)
        # 固定受保護目錄內原子替換，保留上次狀態在資料庫，不動既有工具設定。
        temp = path.with_name(path.name + '.new')
        secure_path(temp)
        fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temp, path)

    def generate(self):
        home = self.root / 'oxidized'
        secure_path(home, True)
        home.mkdir(exist_ok=True, mode=0o700)
        output = home / 'configs'
        secure_path(output, True)
        output.mkdir(exist_ok=True, mode=0o700)
        models = home / 'model'
        secure_path(models, True)
        models.mkdir(exist_ok=True, mode=0o700)
        config = {'interval': 0, 'next_adds_job': True, 'threads': 1, 'use_max_threads': True,
                  'timeout': 20, 'timelimit': 300, 'retries': 0, 'debug': False,
                  'resolve_dns': True, 'pid': str(home / 'oxidized.pid'),
                  'input': {'default': 'ssh', 'debug': False, 'ssh': {'secure': True}},
                  'vars': {'remove_secret': False},
                  'extensions': {'oxidized-web': {'load': True, 'listen': '127.0.0.1', 'port': 8879}},
                  'output': {'default': 'file', 'clean_obsolete_nodes': False, 'file': {'directory': str(output)}},
                  'source': {'default': 'csv', 'csv': {'file': str(home / 'router.db'), 'delimiter': '|',
                             'map': {'name': 0, 'ip': 1, 'model': 2, 'username': 3, 'password': 4}, 'vars_map': {}}}}
        # JSON 是 YAML 子集，無需新增 YAML 依賴；來源分隔符不允許出現在輸入。
        self.write_private(home / 'config', json.dumps(config, ensure_ascii=False, indent=2))
        # 不修改系統安裝的 Oxidized 模型；僅在 IOC 受管目錄提供 FortiOS exec 相容層。
        self.write_private(models / 'iocfortios.rb', """require 'oxidized/model/fortios'

class IocFortiOS < FortiOS
  cfg :ssh do
    exec true
  end
end
""")
        lines = ['|'.join([d['host'], d['host'], ENGINE_MODELS.get(d['model'], d['model']), d['username'], d['password']])
                 for d in self.settings()['devices'] if d['enabled']]
        self.write_private(home / 'router.db', '\n'.join(lines) + ('\n' if lines else ''))

    def applied_engine(self, retry=True):
        settings = self.settings()
        revision = hashlib.sha256(json.dumps(settings, sort_keys=True).encode()).hexdigest()
        out = {'backend': 'oxidized', 'endpoint': ENGINE_URL, 'state': 'unavailable',
               'applied': False, 'checked_at': utcnow(), 'message': '備份工具尚未啟動，設定已儲存但尚未生效'}
        try:
            nodes = self.engine.request('/nodes.json')
            if not isinstance(nodes, list) or any(not isinstance(n, dict) or not isinstance(n.get('name'), str) or (n.get('last') is not None and not isinstance(n.get('last'), dict)) for n in nodes):
                raise BackupError('invalid_response', '備份工具節點回應格式不相容')
            expected = {d['host'] for d in settings['devices'] if d['enabled']}
            actual = {n.get('name') for n in nodes if isinstance(n, dict)}
            if self.get_meta('applied_revision') != revision or actual != expected:
                if not retry:
                    out.update(state='pending_apply', message='設定等待套用')
                    return out, nodes
                self.engine.request('/reload.json')
                nodes = self.engine.request('/nodes.json')
                expected = {d['host'] for d in settings['devices'] if d['enabled']}
                if not isinstance(nodes, list) or {n.get('name') for n in nodes if isinstance(n, dict)} != expected:
                    out.update(state='pending_apply', message='設備清單尚未套用，請檢查受管備份服務')
                    return out, []
                self.put_meta('applied_revision', revision)
            out.update(state='ready', applied=True, message='備份工具已連線，設定已套用')
            return out, nodes
        except BackupError:
            return out, []

    def audit(self, actor, action, detail):
        self.db.execute('INSERT INTO audit(occurred_at,actor,action,detail) VALUES(?,?,?,?)',
                        (utcnow(), actor, action, detail))

    def queue(self, device_id, kind):
        device = next((d for d in self.settings()['devices'] if d['device_id'] == device_id), None)
        if not device or not device['enabled']:
            raise BackupError('validation', '設備未納管或尚未啟用')
        if self.db.execute("SELECT 1 FROM runs WHERE device_id=? AND status='queued'", (device_id,)).fetchone():
            raise BackupError('busy', '此設備已有備份執行中，請稍後再試')
        self.db.execute('INSERT INTO runs(device_id,device_name,kind,status,requested_at,message) VALUES(?,?,?,?,?,?)',
                        (device_id, device['name'], kind, 'queued', utcnow(), '已排入佇列，等待背景程序備份'))

    def dispatch_pending(self, nodes):
        devices = {d['device_id']: d for d in self.settings()['devices']}
        by_name = {n.get('name'): n for n in nodes if isinstance(n, dict)}
        for run in self.db.execute("SELECT * FROM runs WHERE status='queued' AND dispatched_at IS NULL ORDER BY id LIMIT 5").fetchall():
            device = devices[run['device_id']]
            last = by_name.get(device['host'], {}).get('last') or {}
            # 送出前先保存，回應中斷時也不會再次觸發相同工作。
            claimed = self.db.execute("UPDATE runs SET dispatched_at=?,previous_end=?,message=? WHERE id=? AND dispatched_at IS NULL AND status='queued'",
                            (utcnow(), last.get('end'), '已送交備份工具，等待完成證據', run['id']))
            self.db.commit()
            if claimed.rowcount != 1:
                continue
            try:
                self.engine.request('/node/next/' + quote(device['host'], safe='') + '.json')
            except BackupError:
                self.db.execute('UPDATE runs SET message=? WHERE id=?',
                                ('工具回應不明，將等待完成證據或逾時，不重複觸發', run['id']))
                break

    def redact(self, content):
        secrets = set(self.get_meta('redaction_secrets', []))
        secrets.update(d['password'] for d in self.settings()['devices'])
        for secret in sorted(secrets, key=len, reverse=True):
            if secret:
                content = content.replace(secret, '[已遮罩]')
        content = re.sub(r'-----BEGIN [^-]*PRIVATE KEY-----.*?(?:-----END [^-]*PRIVATE KEY-----|\Z)', '[私密金鑰已遮罩]', content, flags=re.S | re.I)
        pattern = re.compile(r'password|passwd|passphrase|\bpsk\b|\bkey\b|secret|community|private.?key|pre.?shared|token|credential|auth(?:entication)?[- _]?(?:key|pass)', re.I)
        return '\n'.join('[敏感設定已遮罩]' if pattern.search(line) else line for line in content.splitlines()) + '\n'

    def store_version(self, run, content):
        if isinstance(content, list) and all(isinstance(line, str) for line in content):
            content = ''.join(content)
        if not isinstance(content, str) or not content.strip() or len(content.encode()) > LIMIT or '\x00' in content:
            raise BackupError('invalid_backup', '備份內容為空、超過上限或格式不正確')
        content = content.replace('\r\n', '\n').replace('\r', '\n')
        digest = hashlib.sha256(content.encode()).hexdigest()
        prior = self.db.execute('SELECT * FROM versions WHERE device_id=? ORDER BY id DESC LIMIT 1', (run['device_id'],)).fetchone()
        if prior and prior['digest'] == digest:
            return 'unchanged', prior['id'], '備份完成，設定沒有變更'
        cursor = self.db.execute('INSERT INTO versions(device_id,created_at,digest,content) VALUES(?,?,?,?)',
                                 (run['device_id'], utcnow(), digest, content))
        version = cursor.lastrowid
        if prior:
            diff = list(difflib.unified_diff(self.redact(prior['content']).splitlines(), self.redact(content).splitlines(),
                                           fromfile='前次設定', tofile='本次設定', lineterm=''))
            added = sum(line.startswith('+') for line in diff[2:])
            removed = sum(line.startswith('-') for line in diff[2:])
            displayed = '\n'.join(diff)
            if not displayed:
                displayed = '內容雜湊已變更；差異位於被遮罩的敏感設定。'
            if len(displayed.encode('utf-8')) > 8000:
                displayed = displayed.encode('utf-8')[:8000].decode('utf-8', errors='ignore') + '\n[差異過長，已截斷]'
            self.db.execute('INSERT INTO changes(device_id,device_name,detected_at,previous_version_id,version_id,added,removed,diff) VALUES(?,?,?,?,?,?,?,?)',
                            (run['device_id'], run['device_name'], utcnow(), prior['id'], version, added, removed, displayed))
        return 'success', version, '備份完成，偵測到組態變更' if prior else '備份完成，已建立第一份基準版本'

    def reconcile(self, nodes):
        by_name = {n.get('name'): n for n in nodes if isinstance(n, dict)}
        devices = {d['device_id']: d for d in self.settings()['devices']}
        fetched = 0
        for run in self.db.execute("SELECT * FROM runs WHERE status='queued' ORDER BY id").fetchall():
            last = by_name.get(devices.get(run['device_id'], {}).get('host'), {}).get('last') or {}
            status, version, message = None, None, None
            try:
                started_at = timestamp(last.get('start'))
                dispatched_at = timestamp(run['dispatched_at'])
                # Oxidized 的 API 只回傳到秒；派送與完成在同一秒時，以既有
                # previous_end 防止舊結果誤判，再容許秒級回應完成本次工作。
                valid = (run['dispatched_at'] is not None and last.get('end') != run['previous_end'] and
                         started_at >= dispatched_at.replace(microsecond=0) and
                         timestamp(last.get('end')) >= started_at)
            except (ValueError, TypeError):
                valid = False
            if (datetime.now(timezone.utc) - timestamp(run['requested_at'])).total_seconds() > 900:
                status, message = 'timeout', '等待備份結果超過 15 分鐘，未取得完整成功證據'
            elif valid:
                if last.get('status') != 'success':
                    status, message = 'failed', '設備備份失敗，請檢查連線、帳號權限及設備類型'
                else:
                    if fetched >= 5:
                        continue
                    fetched += 1
                    try:
                        content = self.engine.request('/node/fetch/' + quote(devices[run['device_id']]['host'], safe='') + '.json')
                        status, version, message = self.store_version(run, content)
                    except BackupError as error:
                        if error.code == 'unavailable':
                            continue
                        status, message = 'failed', error.message
            if status:
                self.db.execute('UPDATE runs SET status=?,completed_at=?,message=?,version_id=? WHERE id=?',
                                (status, utcnow(), message, version, run['id']))

    def response(self, engine):
        runs = [dict(row) for row in self.db.execute('SELECT id,device_id,device_name,kind,status,requested_at,completed_at,message,version_id FROM runs ORDER BY id DESC LIMIT 50')]
        changes = [dict(row) for row in self.db.execute('SELECT * FROM changes ORDER BY id DESC LIMIT 50')]
        # 刪除設備後也不直接回傳既有設定內可能新增認定為秘密的內容。
        for change in changes:
            change['diff'] = self.redact(change['diff']).rstrip('\n').encode('utf-8')[:8200].decode('utf-8', errors='ignore')
        audit = [dict(row) for row in self.db.execute('SELECT id,occurred_at,actor,action,detail FROM audit ORDER BY id DESC LIMIT 50')]
        return {'settings': self.public_settings(), 'engine': engine, 'runs': runs, 'changes': changes, 'audit': audit, 'history_devices': self.history_devices()}

    @staticmethod
    def identifier(value, allow_zero=False):
        if type(value) is not int or not (0 if allow_zero else 1) <= value <= 2147483647:
            raise BackupError('validation', '識別碼或分頁位置不正確')
        return value

    def history_devices(self):
        devices = {d['device_id']: {'device_id': d['device_id'], 'name': d['name'], 'configured': True}
                   for d in self.settings()['devices']}
        for row in self.db.execute('SELECT device_id,device_name FROM runs WHERE id IN (SELECT MAX(id) FROM runs GROUP BY device_id) ORDER BY device_id'):
            devices.setdefault(row['device_id'], {'device_id': row['device_id'], 'name': row['device_name'], 'configured': False})
        return list(devices.values())

    def history(self, request):
        ident = self.identifier(request.get('device_id'))
        offset = self.identifier(request.get('offset', 0), allow_zero=True)
        if ident not in {d['device_id'] for d in self.history_devices()}:
            raise BackupError('validation', '找不到此設備的備份紀錄')
        runs = [dict(r) for r in self.db.execute('SELECT id,device_id,device_name,kind,status,requested_at,completed_at,message,version_id FROM runs WHERE device_id=? ORDER BY id DESC LIMIT 20 OFFSET ?', (ident, offset))]
        versions = [dict(r) for r in self.db.execute('SELECT id,device_id,created_at,digest FROM versions WHERE device_id=? ORDER BY id DESC LIMIT 20 OFFSET ?', (ident, offset))]
        return {'device_id': ident, 'offset': offset, 'limit': 20, 'runs': runs, 'versions': versions,
                'total_runs': self.db.execute('SELECT COUNT(*) FROM runs WHERE device_id=?', (ident,)).fetchone()[0],
                'total_versions': self.db.execute('SELECT COUNT(*) FROM versions WHERE device_id=?', (ident,)).fetchone()[0]}

    def compare(self, request):
        ident = self.identifier(request.get('device_id'))
        first = self.identifier(request.get('from_version_id'))
        second = self.identifier(request.get('to_version_id'))
        versions = [self.db.execute('SELECT * FROM versions WHERE device_id=? AND id=?', (ident, version)).fetchone()
                    for version in [first, second]]
        if any(v is None for v in versions):
            raise BackupError('validation', '版本不存在或不屬於同一設備')
        before, after = versions
        lines = list(difflib.unified_diff(self.redact(before['content']).splitlines(), self.redact(after['content']).splitlines(),
                                        fromfile='版本 ' + str(first), tofile='版本 ' + str(second), lineterm=''))
        added = sum(line.startswith('+') for line in lines[2:])
        removed = sum(line.startswith('-') for line in lines[2:])
        changed = before['digest'] != after['digest']
        diff = '\n'.join(lines) or ('內容雜湊已變更；差異位於被遮罩的敏感設定。' if changed else '兩個版本的內容相同。')
        truncated = len(diff.encode('utf-8')) > 12000
        if truncated:
            diff = diff.encode('utf-8')[:12000].decode('utf-8', errors='ignore') + '\n[差異過長，已截斷]'
        return {'device_id': ident, 'from_version_id': first, 'to_version_id': second,
                'from_created_at': before['created_at'], 'to_created_at': after['created_at'],
                'diff': diff, 'added': added, 'removed': removed, 'changed': changed, 'truncated': truncated}

    def cached_engine(self):
        engine = self.get_meta('engine', {'backend': 'oxidized', 'endpoint': ENGINE_URL,
                                      'state': 'unavailable', 'applied': False, 'checked_at': None,
                                      'message': '尚未確認備份工具已安裝並啟動，請聯絡維護人員'})
        if engine['state'] == 'ready':
            try:
                stale = (datetime.now(timezone.utc) - timestamp(engine.get('checked_at'))).total_seconds() > 180
            except (ValueError, TypeError):
                stale = True
            if stale:
                engine.update(state='unavailable', applied=False, message='超過三分鐘未確認備份工具，請檢查背景排程')
        return engine

    def dispatch(self, request):
        if not isinstance(request, dict) or request.get('action') not in {'settings', 'status', 'save', 'test', 'run', 'tick', 'history', 'compare'}:
            raise BackupError('validation', '不支援的組態備份操作')
        action = request['action']
        actor = safe_text(request.get('actor', 'system'), '操作者', 255, allow_empty=action in {'settings', 'status', 'history', 'compare'})
        self.db.execute('BEGIN' if action in {'settings', 'status', 'history', 'compare'} else 'BEGIN IMMEDIATE')
        try:
            engine = self.cached_engine()
            if action == 'save':
                settings = self.validate(request.get('settings'))
                if self.db.execute("SELECT 1 FROM runs WHERE status='queued'").fetchone():
                    raise BackupError('busy', '仍有備份執行中，請完成後再修改設定')
                secrets = set(self.get_meta('redaction_secrets', []))
                secrets.update(d['password'] for d in self.settings()['devices'])
                secrets.update(d['password'] for d in settings['devices'])
                self.put_meta('redaction_secrets', sorted(secrets))
                self.put_meta('settings', settings)
                self.generate()
                engine.update(state='pending_apply', applied=False, message='設定已儲存，等待背景程序套用；尚未啟用備份')
                self.put_meta('engine', engine)
                self.audit(actor, 'save', '儲存備份政策；設備數：' + str(len(settings['devices'])))
            if action in {'test', 'run'}:
                if not engine['applied']:
                    raise BackupError('unavailable', engine['message'])
                ident = request.get('device_id')
                if type(ident) is not int:
                    raise BackupError('validation', '請指定設備識別碼')
                self.queue(ident, 'test' if action == 'test' else 'manual')
                self.audit(actor, action, '設備識別碼：' + str(ident))
            if action == 'tick':
                # 以交易成功的資料庫設定重建，修復前次寫檔中斷造成的不一致。
                self.generate()
                engine, nodes = self.applied_engine()
                self.put_meta('engine', engine)
                self.reconcile(nodes)
                if engine['applied']:
                    if self.settings()['enabled']:
                        count = 0
                        for device in self.settings()['devices']:
                            if not device['enabled']:
                                continue
                            recent = self.db.execute('SELECT * FROM runs WHERE device_id=? ORDER BY id DESC LIMIT 1', (device['device_id'],)).fetchone()
                            if recent and (recent['status'] == 'queued' or (datetime.now(timezone.utc) - timestamp(recent['requested_at'])).total_seconds() < self.settings()['interval_hours'] * 3600):
                                continue
                            self.queue(device['device_id'], 'scheduled')
                            self.audit('system', 'scheduled', '設備識別碼：' + str(device['device_id']))
                            count += 1
                            if count >= 5:
                                break
                    self.dispatch_pending(nodes)
            result = self.response(engine)
            if action == 'history':
                result['history'] = self.history(request)
            if action == 'compare':
                result['comparison'] = self.compare(request)
            self.db.commit()
            return result
        except Exception:
            self.db.rollback()
            raise


def main():
    os.umask(0o077)
    worker = None
    try:
        if sys.argv[1:] == ['--tick']:
            request = {'action': 'tick', 'actor': 'system'}
        elif len(sys.argv) != 1:
            raise BackupError('validation', '不支援的命令列參數')
        else:
            raw = sys.stdin.buffer.read(262145)
            if len(raw) > 262144:
                raise BackupError('validation', '請求內容超過上限')
            request = json.loads(raw.decode('utf-8'))
        worker = Worker()
        output = {'ok': True, 'data': worker.dispatch(request)}
    except BackupError as error:
        output = {'ok': False, 'error': {'code': error.code, 'message': error.message}}
    except (ValueError, UnicodeError):
        output = {'ok': False, 'error': {'code': 'validation', 'message': '請求或備份狀態格式不正確'}}
    except (OSError, sqlite3.Error):
        output = {'ok': False, 'error': {'code': 'state_error', 'message': '無法存取備份狀態或系統忙碌，請聯絡維護人員'}}
    except Exception:
        # 不將損壞狀態或工具格式造成的例外與可能含秘密的內容傳到 Web。
        output = {'ok': False, 'error': {'code': 'state_error', 'message': '備份狀態或工具回應格式不正確，請聯絡維護人員'}}
    finally:
        if worker:
            worker.close()
    print(json.dumps(output, ensure_ascii=False))
    return 0 if output['ok'] else 1


if __name__ == '__main__':
    sys.exit(main())
