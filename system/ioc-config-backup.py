#!/usr/bin/env python3
"""IOC 組態備份閘道：受保護狀態、受管 Oxidized、排程與版本比較。"""
import base64
import difflib
import hashlib
import hmac
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import ssl
import stat
import subprocess
import sys
import time
from datetime import datetime, timezone
from urllib.error import HTTPError, URLError
from urllib.request import Request, HTTPSHandler, build_opener, ProxyHandler, HTTPRedirectHandler
from urllib.parse import quote, urlencode
from xml.etree import ElementTree

STATE_DIR = '/var/lib/oracle-mon/config-backup'
ENGINE_URL = 'http://127.0.0.1:8879'
# 適配器登錄是所有備份後端的唯一能力描述來源。後端名稱維持既有
# `backup_backend` 值，以免已儲存的設備設定需要資料遷移；`id` 則用於
# 對外呈現及未來擴充。
#
# 未驗證的 API 適配器會保留在登錄中，但 available=False，前端及驗證
# 流程都不能選用它們。這讓產品線能力可被明確追蹤，而不會猜測設備
# 端點或把未完成的實作暴露給管理者。
ADAPTER_REGISTRY = {
    'oxidized_cli': {
        'id': 'oxidized_cli', 'label': 'Oxidized SSH',
        'models': ('ios', 'fortios', 'junos', 'routeros'),
        'credential_kind': 'ssh_password', 'requires_known_host': True,
        'depends_on_oxidized': True, 'supports_diagnose': True,
        'available': True,
    },
    'fortigate_api': {
        'id': 'fortigate_api', 'label': 'FortiGate REST API',
        'models': ('fortios',), 'credential_kind': 'api_token',
        'requires_known_host': False, 'depends_on_oxidized': False,
        'supports_diagnose': False, 'available': True,
    },
    # PAN-OS XML API 已由專用適配器處理完整組態匯出；使用既有 api_token
    # 私密欄位保存 API Key，不回傳至前端。
    'panos_xml_api': {
        'id': 'panos_xml_api', 'label': 'Palo Alto PAN-OS XML API',
        'models': ('panos',), 'credential_kind': 'api_token',
        'requires_known_host': False, 'depends_on_oxidized': False,
        'supports_diagnose': False, 'available': True,
    },
    # 下列產品線的完整設定 API 路徑會隨 OS 與韌體改變。先登錄能力，
    # 待實機驗收後才將 available 改為 True。
    'extreme_api': {
        'id': 'extreme_api', 'label': 'Extreme SwitchEngine API',
        'models': ('extreme_exos',), 'credential_kind': 'api_token',
        'requires_known_host': False, 'depends_on_oxidized': False,
        'supports_diagnose': False, 'available': False,
    },
    'aoscx_api': {
        'id': 'aoscx_api', 'label': 'Aruba AOS-CX API',
        'models': ('aoscx',), 'credential_kind': 'api_token',
        'requires_known_host': False, 'depends_on_oxidized': False,
        'supports_diagnose': False, 'available': False,
    },
}

# 現行已驗收、可由 Oxidized 讀取的模型。未來新增 Extreme VOSS／
# Fabric Engine、HPE ProCurve／ArubaOS-Switch 前，必須先建立實機樣本。
MODELS = {model for adapter in ADAPTER_REGISTRY.values() if adapter['available']
          for model in adapter['models']}
# FortiGate 的 `show` 會輸出分頁提示；必須使用可回應提示的互動式 shell，
# 不能使用一次性 SSH exec channel，否則只會取得第一頁。
ENGINE_MODELS = {'fortios': 'iocfortigate'}
# 變更受管 Oxidized 輸出或模型時遞增，以迫使既有設定重新載入。
ENGINE_CONFIG_REVISION = 7
LIMIT = 2 * 1024 * 1024
HISTORY_PAGE_SIZE = 10
DOWNLOAD_LIMIT = 2 * 1024 * 1024
# 舊版設定使用 `oxidized`；只在讀取時相容，所有新儲存值一律採用
# `oxidized_cli`，避免前後端對後端名稱有兩套語意。
BACKEND_ALIASES = {'oxidized': 'oxidized_cli'}
API_BACKENDS = set(ADAPTER_REGISTRY) | set(BACKEND_ALIASES)


def canonical_backend(backend):
    if not isinstance(backend, str):
        raise BackupError('validation', '不支援此備份方式')
    return BACKEND_ALIASES.get(backend, backend)


def adapter_for_backend(backend):
    """Return the registered adapter or raise a safe validation error."""
    adapter = ADAPTER_REGISTRY.get(canonical_backend(backend))
    if not isinstance(adapter, dict):
        raise BackupError('validation', '不支援此備份方式')
    return adapter


def public_adapters():
    """Return the non-sensitive adapter capability contract for the portal."""
    fields = ('id', 'label', 'models', 'credential_kind', 'requires_known_host',
              'depends_on_oxidized', 'supports_diagnose', 'available')
    return [{field: adapter[field] for field in fields}
            for adapter in ADAPTER_REGISTRY.values() if adapter['available'] is True]


def adapter_for_device(device):
    if not isinstance(device, dict):
        raise BackupError('validation', '設備資料格式錯誤')
    return adapter_for_backend(device.get('backup_backend', 'oxidized_cli'))


def uses_oxidized(device):
    return adapter_for_device(device)['depends_on_oxidized'] is True


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


class PromptProbe:
    """只取得 SSH transport／PTY／shell 建立證據；絕不傳送設備 CLI 指令。"""
    TIMEOUT = 8
    _SCRIPT = r"""
require 'json'
require 'net/ssh'
request = JSON.parse(STDIN.read)
result = {'transport' => false, 'pty' => false, 'shell' => false, 'prompt_detected' => false}
buffer = ''.b
begin
  Net::SSH.start(request.fetch('host'), request.fetch('username'),
    password: request.fetch('password'), non_interactive: true,
    verify_host_key: :always, user_known_hosts_file: [request.fetch('known_hosts')],
    global_known_hosts_file: [], timeout: 5, auth_methods: ['password']) do |ssh|
    result['transport'] = true
    pty_done = shell_done = false
    ssh.open_channel do |channel|
      channel.request_pty(term: 'vt100') do |_ch, ok|
        result['pty'] = !!ok; pty_done = true
        unless ok
          result['stage'] = 'pty_rejected'
          next
        end
        channel.send_channel_request('shell') do |_shell, shell_ok|
          result['shell'] = !!shell_ok; shell_done = true
          result['stage'] = 'shell_rejected' unless shell_ok
        end
      end
      channel.on_data do |_ch, data|
        # 保留尾端，避免較長的登入訊息把真正的提示字元擠出診斷範圍。
        buffer << data.to_s.b
        buffer = buffer.byteslice(buffer.bytesize - 1024, 1024) if buffer.bytesize > 1024
      end
    end
    prompt_candidate = lambda do
      clean = buffer.gsub(/\e\[[0-?]*[ -\/]*[@-~]/, '').gsub(/[\x00-\x08\x0b-\x1f\x7f]/, '')
      last = clean.lines.map(&:strip).reject(&:empty?).last.to_s
      last.bytesize > 128 ? last.byteslice(last.bytesize - 128, 128) : last
    end
    deadline = Process.clock_gettime(Process::CLOCK_MONOTONIC) + 5
    while Process.clock_gettime(Process::CLOCK_MONOTONIC) < deadline
      ssh.process(0.1)
      break if pty_done && !result['pty']
      break if shell_done && !result['shell']
      break if shell_done && prompt_candidate.call.match?(/[#$>]\s*\z/)
    end
    if !pty_done
      result['stage'] = 'pty_timeout'
    elsif !result['pty']
      result['stage'] ||= 'pty_rejected'
    elsif !shell_done
      result['stage'] = 'shell_timeout'
    elsif !result['shell']
      result['stage'] ||= 'shell_rejected'
    else
      candidate = prompt_candidate.call
      terminator = candidate[/[#$>]\s*\z/]&.strip
      result['prompt_detected'] = !terminator.nil?
      result['prompt_signature'] = {
        'ha_role_format' => candidate.match?(/\([^)]{1,32}\)/),
        'terminator' => terminator || 'unknown'
      }
      result['stage'] = result['prompt_detected'] ? 'complete' : 'prompt_not_detected'
    end
    # 診斷只讀取初始提示字元；不要等待互動 shell 自行關閉。
    ssh.shutdown!
  end
rescue Net::SSH::HostKeyUnknown, Net::SSH::HostKeyMismatch
  result['stage'] = 'host_key_untrusted'
rescue Net::SSH::AuthenticationFailed
  result['stage'] = 'authentication_failed'
rescue Net::SSH::ConnectionTimeout, Timeout::Error
  result['stage'] = 'timeout'
rescue Errno::ECONNREFUSED, Errno::EHOSTUNREACH, SocketError
  result['stage'] = 'connection_failed'
rescue StandardError
  result['stage'] = result['transport'] ? 'shell_failed' : 'connection_failed'
end
puts JSON.generate(result)
"""

    def probe(self, device, known_hosts):
        try:
            completed = subprocess.run(
                ['/usr/bin/ruby3.2', '-rjson', '-rnet/ssh', '-e', self._SCRIPT],
                input=json.dumps({'host': device['host'], 'username': device['username'],
                                  'password': device['password'], 'known_hosts': str(known_hosts)}),
                text=True, encoding='utf-8', stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                timeout=self.TIMEOUT + 2, check=False,
                env={'PATH': '/usr/bin:/bin', 'HOME': str(known_hosts.parent)},
            )
        except (OSError, subprocess.TimeoutExpired):
            return {'transport': False, 'pty': False, 'shell': False, 'stage': 'timeout'}
        try:
            raw = completed.stdout
            if not isinstance(raw, str) or len(raw.encode('utf-8')) > 4096:
                raise ValueError()
            data = json.loads(raw)
        except (ValueError, TypeError, UnicodeError):
            return {'transport': False, 'pty': False, 'shell': False, 'stage': 'probe_failed'}
        return self._safe(data)

    @staticmethod
    def _safe(data):
        if not isinstance(data, dict):
            return {'transport': False, 'pty': False, 'shell': False, 'stage': 'probe_failed'}
        stage = data.get('stage')
        allowed = {'complete', 'prompt_not_detected', 'host_key_untrusted', 'authentication_failed',
                   'timeout', 'connection_failed', 'pty_rejected', 'pty_timeout', 'shell_rejected',
                   'shell_timeout', 'shell_failed', 'probe_failed'}
        result = {'transport': data.get('transport') is True, 'pty': data.get('pty') is True,
                  'shell': data.get('shell') is True,
                  'stage': stage if stage in allowed else 'probe_failed'}
        signature = data.get('prompt_signature')
        if isinstance(signature, dict) and result['shell']:
            term = signature.get('terminator')
            if term in {'#', '$', '>', 'unknown'}:
                result['prompt'] = {'detected': data.get('prompt_detected') is True,
                                    'ha_role_format': signature.get('ha_role_format') is True,
                                    'terminator': term}
        return result


class FortiGateApiBackup:
    """明確選用的 FortiGate REST API 後端；TLS 憑證驗證永遠開啟。"""
    def fetch(self, device):
        host = device['host']
        address = '[' + host + ']' if ':' in host else host
        url = 'https://' + address + ':443/api/v2/monitor/system/config/backup?scope=global'
        token = device.get('api_token')
        if not isinstance(token, str) or not token:
            raise BackupError('api_token_required', '尚未設定 FortiGate REST API Token')
        opener = build_opener(ProxyHandler({}), NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
        def send(method):
            request = Request(url, method=method, headers={'Authorization': 'Bearer ' + token, 'Accept': 'text/plain, application/octet-stream'})
            with opener.open(request, timeout=15) as response:
                raw = response.read(LIMIT + 1)
                content_type = response.headers.get_content_type()
            if len(raw) > LIMIT:
                raise BackupError('invalid_backup', 'REST API 回傳內容超過 2 MiB 上限')
            if content_type in {'text/html', 'application/json'}:
                raise BackupError('invalid_backup', 'REST API 未回傳設定檔內容')
            try:
                content = raw.decode('utf-8')
            except UnicodeDecodeError:
                raise BackupError('invalid_backup', 'REST API 回傳內容不是文字設定檔') from None
            if not content.strip() or '\x00' in content or '--More--' in content:
                raise BackupError('invalid_backup', 'REST API 回傳內容不完整或格式不正確')
            return content
        try:
            return send('POST')
        except HTTPError as error:
            if error.code not in {404, 405}:
                raise BackupError('api_request_failed', 'FortiGate REST API 拒絕備份要求，請確認 Token 權限與 TLS 信任') from None
        except (OSError, URLError, ssl.SSLError):
            raise BackupError('api_request_failed', '無法安全連線 FortiGate REST API，請確認 TLS 憑證與網路') from None
        try:
            return send('GET')
        except (OSError, URLError, HTTPError, ssl.SSLError):
            raise BackupError('api_request_failed', 'FortiGate REST API 備份要求失敗，請確認 Token 權限與 TLS 信任') from None


class PanOsXmlApiBackup:
    """明確選用的 PAN-OS XML API 後端；只匯出完整組態且永遠驗證 TLS。"""

    _TIMEOUT = 15

    @staticmethod
    def _configuration(raw, content_type):
        """驗證 PAN-OS 匯出內容，拒絕錯誤回應、分頁殘留與不完整 XML。"""
        if len(raw) > LIMIT:
            raise BackupError('invalid_backup', 'PAN-OS XML API 回傳內容超過 2 MiB 上限')
        if content_type in {'text/html', 'application/json'}:
            raise BackupError('invalid_backup', 'PAN-OS XML API 未回傳設定檔內容')
        try:
            content = raw.decode('utf-8')
        except UnicodeDecodeError:
            raise BackupError('invalid_backup', 'PAN-OS XML API 回傳內容不是 UTF-8 XML 設定檔') from None
        if not content.strip() or '\x00' in content or re.search(r'--more--', content, re.IGNORECASE):
            raise BackupError('invalid_backup', 'PAN-OS XML API 回傳內容不完整或格式不正確')
        # 禁止 DTD／實體宣告，避免解析器處理設備回傳的非設定 XML。
        if re.search(r'<!\s*(?:doctype|entity)\b', content, re.IGNORECASE):
            raise BackupError('invalid_backup', 'PAN-OS XML API 回傳內容不是安全的設定 XML')
        try:
            root = ElementTree.fromstring(content)
        except ElementTree.ParseError:
            raise BackupError('invalid_backup', 'PAN-OS XML API 回傳內容不是完整 XML 設定檔') from None
        tag = root.tag.rsplit('}', 1)[-1] if isinstance(root.tag, str) else ''
        if tag != 'config' or root.get('status') == 'error':
            raise BackupError('invalid_backup', 'PAN-OS XML API 未回傳完整設定檔')
        return content

    def fetch(self, device):
        """透過 PAN-OS XML API 匯出 running configuration。"""
        token = device.get('api_token')
        if not isinstance(token, str) or not token:
            raise BackupError('api_key_required', '尚未設定 PAN-OS XML API Key')
        host = device['host']
        address = '[' + host + ']' if ':' in host else host
        url = 'https://' + address + ':443/api/'
        opener = build_opener(ProxyHandler({}), NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
        body = urlencode({'type': 'export', 'category': 'configuration', 'key': token}).encode('ascii')
        request = Request(url, data=body, method='POST', headers={
            'Content-Type': 'application/x-www-form-urlencoded',
            'Accept': 'application/xml, text/xml, application/octet-stream',
        })
        try:
            with opener.open(request, timeout=self._TIMEOUT) as response:
                raw = response.read(LIMIT + 1)
                content_type = response.headers.get_content_type()
            return self._configuration(raw, content_type)
        except BackupError:
            raise
        except HTTPError:
            raise BackupError('api_request_failed', 'PAN-OS XML API 拒絕匯出要求，請確認 API Key 權限與 TLS 信任') from None
        except (OSError, URLError, ssl.SSLError):
            raise BackupError('api_request_failed', '無法安全連線 PAN-OS XML API，請確認 TLS 憑證與網路') from None


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
    def __init__(self, root=None, engine=None, probe=None, api_backup=None):
        self.root = Path(root or os.environ.get('IOC_BACKUP_STATE_DIR', STATE_DIR)).absolute()
        secure_path(self.root, True)
        self.root.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.engine = engine or Engine()
        self.probe_client = probe or PromptProbe()
        # api_backup 保留為相容的 FortiGate 測試注入點；實際派送一律由
        # adapter key 取用，避免不同原生 API 共用錯誤的採集器。
        self.api_backups = {
            'fortigate_api': api_backup or FortiGateApiBackup(),
            'panos_xml_api': PanOsXmlApiBackup(),
        }
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

    def trusted_hosts(self):
        ssh_dir = self.root / '.ssh'
        secure_path(ssh_dir, True)
        known_hosts = ssh_dir / 'known_hosts'
        secure_path(known_hosts)
        if not known_hosts.exists():
            return set()
        try:
            content = known_hosts.read_text(encoding='ascii')
        except (OSError, UnicodeError):
            raise BackupError('unsafe_state', '無法安全讀取 SSH 信任清單') from None
        if len(content.encode('ascii')) > 1024 * 1024:
            raise BackupError('unsafe_state', 'SSH 信任清單內容過大')
        return {line.split(maxsplit=1)[0] for line in content.splitlines()
                if line.strip() and not line.lstrip().startswith('#') and len(line.split()) >= 3}

    def public_settings(self):
        settings = self.settings()
        trusted = self.trusted_hosts()
        settings['devices'] = [{**{k: v for k, v in d.items() if k not in {'password', 'api_token', 'backup_backend'}},
                                'backup_backend': canonical_backend(d.get('backup_backend', 'oxidized_cli')),
                                'password_set': bool(d.get('password')),
                                'api_token_set': bool(d.get('api_token')),
                                'host_key_trusted': d['host'] in trusted} for d in settings['devices']]
        settings['adapters'] = public_adapters()
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
            backend = canonical_backend(item.get('backup_backend', previous.get(ident, {}).get('backup_backend', 'oxidized_cli')))
            adapter = adapter_for_backend(backend)
            if not adapter['available']:
                raise BackupError('validation', '此備份方式尚未完成實機驗證，暫不可使用')
            if model not in adapter['models']:
                raise BackupError('validation', adapter['label'] + '不支援此設備類型')
            # API 後端固定使用各自的 HTTPS 預設連接埠；保留既有 port=22
            # 儲存欄位以維持舊資料與 SSH 模式相容，且不讓使用者自訂連接埠。
            if type(item.get('port', 22)) is not int or item.get('port', 22) != 22:
                raise BackupError('validation', '目前不支援自訂備份連接埠')
            if type(item.get('enabled')) is not bool:
                raise BackupError('validation', '請指定設備是否參與備份')
            password = item.get('password', '')
            if password == '':
                password = previous.get(ident, {}).get('password', '')
            token = item.get('api_token', '')
            if token == '':
                token = previous.get(ident, {}).get('api_token', '')
            if adapter['credential_kind'] == 'ssh_password' and not password:
                raise BackupError('password_required', '首次設定 Oxidized SSH 備份時請填寫備份帳號密碼')
            if adapter['credential_kind'] == 'api_token' and not token:
                if backend == 'panos_xml_api':
                    raise BackupError('api_key_required', '首次設定 PAN-OS XML API 時請填寫 API Key')
                raise BackupError('api_token_required', '首次設定原生 API 備份時請填寫 API Token')
            password = safe_text(password, '設備密碼', 512,
                                 allow_empty=adapter['credential_kind'] != 'ssh_password')
            token = safe_text(token, 'REST API Token', 1024,
                              allow_empty=adapter['credential_kind'] != 'api_token')
            result.append({'device_id': ident, 'name': safe_text(item.get('name'), '設備名稱', 128),
                           'host': host, 'model': model,
                           'username': safe_text(item.get('username'), '設備帳號', 128,
                                                 allow_empty=adapter['credential_kind'] != 'ssh_password'),
                           'password': password, 'api_token': token, 'backup_backend': backend,
                           'port': 22, 'enabled': item['enabled']})
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
                             'map': {'name': 0, 'ip': 1, 'model': 2, 'username': 3, 'password': 4},
                             'vars_map': {'ssh_no_exec': 5}}}}
        # JSON 是 YAML 子集，無需新增 YAML 依賴；來源分隔符不允許出現在輸入。
        self.write_private(home / 'config', json.dumps(config, ensure_ascii=False, indent=2))
        # 不修改系統安裝的 Oxidized 模型；僅在 IOC 受管目錄補強 FortiGate 分頁處理。
        self.write_private(models / 'iocfortigate.rb', r"""require 'oxidized/model/fortigate'

class IocFortiGate < FortiGate
  # FortiGate 從 Oxidized 0.36 起使用獨立模型；繼承 FortiOS 會執行
  # 裸 show，分頁時可能無法完成。保留 HA 提示字元的相容處理。
  prompt /^(\(\w\) )?([-\w.~()]+(\s[(\w\-.)]+)?~?\s?[#>$]\s?)$/

  # 部分 FortiOS 版本在 --More-- 後附帶 CR、退格或下一頁內容，
  # 內建嚴格比對可能漏接，造成只保存第一頁組態。
  expect /--More--/ do |data, re|
    send ' '
    data.sub re, ''
  end
end
""")
        lines = ['|'.join([d['host'], d['host'], ENGINE_MODELS.get(d['model'], d['model']), d['username'], d['password'],
                           'true' if d['model'] == 'fortios' else 'false'])
                 for d in self.settings()['devices'] if d['enabled'] and uses_oxidized(d)]
        self.write_private(home / 'router.db', '\n'.join(lines) + ('\n' if lines else ''))

    def applied_engine(self, retry=True):
        settings = self.settings()
        revision = hashlib.sha256(json.dumps({
            'settings': settings,
            'engine_config_revision': ENGINE_CONFIG_REVISION,
        }, sort_keys=True).encode()).hexdigest()
        out = {'backend': 'oxidized', 'endpoint': ENGINE_URL, 'state': 'unavailable',
               'applied': False, 'checked_at': utcnow(), 'message': '備份工具尚未啟動，設定已儲存但尚未生效'}
        expected = {d['host'] for d in settings['devices'] if d['enabled'] and uses_oxidized(d)}
        if not expected:
            # 原生 API 設備不依賴 Oxidized。空 router.db 會讓 Oxidized
            # 回報「source returns no usable nodes」並進入 systemd 重啟迴圈。
            out.update(backend='native_api', state='ready', applied=True,
                       message='目前沒有 SSH 備份設備；原生 API 備份可獨立執行')
            return out, []
        try:
            nodes = self.engine.request('/nodes.json')
            if not isinstance(nodes, list) or any(not isinstance(n, dict) or not isinstance(n.get('name'), str) or (n.get('last') is not None and not isinstance(n.get('last'), dict)) for n in nodes):
                raise BackupError('invalid_response', '備份工具節點回應格式不相容')
            actual = {n.get('name') for n in nodes if isinstance(n, dict)}
            if self.get_meta('applied_revision') != revision or actual != expected:
                if not retry:
                    out.update(state='pending_apply', message='設定等待套用')
                    return out, nodes
                self.engine.request('/reload.json')
                nodes = self.engine.request('/nodes.json')
                expected = {d['host'] for d in settings['devices'] if d['enabled'] and uses_oxidized(d)}
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

    def configured_device(self, ident):
        ident = self.identifier(ident)
        device = next((item for item in self.settings()['devices'] if item['device_id'] == ident), None)
        if not device:
            raise BackupError('validation', '請先儲存此設備的備份設定，再確認 SSH 身分指紋')
        return device

    @staticmethod
    def parse_ed25519_host_key(raw, expected_host):
        """Only accept the single canonical key line that will be trusted later."""
        if not isinstance(raw, bytes) or len(raw) > 8192:
            raise BackupError('host_key_scan_failed', '無法取得有效的 SSH 主機身分指紋')
        try:
            lines = [line.strip() for line in raw.decode('ascii').splitlines()
                     if line.strip() and not line.lstrip().startswith('#')]
        except UnicodeDecodeError:
            raise BackupError('host_key_scan_failed', '無法取得有效的 SSH 主機身分指紋') from None
        if len(lines) != 1:
            raise BackupError('host_key_scan_failed', '設備未提供唯一的 SSH ED25519 主機金鑰')
        parts = lines[0].split()
        if len(parts) != 3 or parts[0] != expected_host or parts[1] != 'ssh-ed25519':
            raise BackupError('host_key_scan_failed', '設備回傳的 SSH 主機金鑰格式不正確')
        try:
            blob = base64.b64decode(parts[2], validate=True)
            if len(blob) != 51 or blob[:4] != b'\x00\x00\x00\x0b' or blob[4:15] != b'ssh-ed25519' or blob[15:19] != b'\x00\x00\x00\x20':
                raise ValueError()
        except (ValueError, base64.binascii.Error):
            raise BackupError('host_key_scan_failed', '設備回傳的 SSH 主機金鑰格式不正確') from None
        fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(blob).digest()).decode('ascii').rstrip('=')
        return {'host': expected_host, 'algorithm': parts[1], 'key': parts[2], 'fingerprint': fingerprint}

    def configured_ssh_device(self, ident):
        """Return a configured device only when its selected adapter uses SSH."""
        device = self.configured_device(ident)
        if adapter_for_device(device)['requires_known_host'] is not True:
            raise BackupError('validation', '此備份方式不使用 SSH 主機金鑰驗證')
        return device

    def scan_host_key(self, ident, actor):
        device = self.configured_ssh_device(ident)
        try:
            completed = subprocess.run(
                ['/usr/bin/ssh-keyscan', '-T', '4', '-t', 'ed25519', '--', device['host']],
                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                timeout=5, check=False,
            )
        except (OSError, subprocess.TimeoutExpired):
            raise BackupError('host_key_scan_failed', '無法連線至設備取得 SSH 身分指紋') from None
        candidate = self.parse_ed25519_host_key(completed.stdout, device['host'])
        token = secrets.token_urlsafe(24)
        expires_at = time.time() + 600
        candidates = self.get_meta('host_key_candidates', {})
        if not isinstance(candidates, dict):
            candidates = {}
        candidates[str(device['device_id'])] = {**candidate, 'token': token, 'expires_at': expires_at}
        self.put_meta('host_key_candidates', candidates)
        self.audit(actor, 'host_key_scan', '設備識別碼：' + str(device['device_id']) + '；等待確認 SSH 指紋')
        return {key: candidate[key] for key in ('host', 'algorithm', 'fingerprint')} | {
            'device_id': device['device_id'], 'token': token,
            'expires_at': datetime.fromtimestamp(expires_at, timezone.utc).isoformat(), 'trusted': False,
        }

    def confirm_host_key(self, ident, token, fingerprint, actor):
        device = self.configured_ssh_device(ident)
        if not isinstance(token, str) or len(token) > 128 or not isinstance(fingerprint, str) or len(fingerprint) > 128:
            raise BackupError('validation', 'SSH 身分指紋確認資料格式不正確')
        candidates = self.get_meta('host_key_candidates', {})
        candidate = candidates.get(str(device['device_id'])) if isinstance(candidates, dict) else None
        if not isinstance(candidate, dict) or not isinstance(candidate.get('expires_at'), (int, float)) or candidate['expires_at'] < time.time():
            raise BackupError('host_key_expired', 'SSH 身分指紋確認已逾時，請重新取得')
        required = ('host', 'algorithm', 'key', 'fingerprint', 'token')
        if any(not isinstance(candidate.get(key), str) for key in required) or candidate['host'] != device['host']:
            raise BackupError('host_key_expired', '設備資料已變更，請重新取得 SSH 身分指紋')
        if not hmac.compare_digest(candidate['token'], token) or not hmac.compare_digest(candidate['fingerprint'], fingerprint):
            raise BackupError('host_key_mismatch', '確認的 SSH 身分指紋與候選值不符')
        verified = self.parse_ed25519_host_key(
            (candidate['host'] + ' ' + candidate['algorithm'] + ' ' + candidate['key']).encode('ascii'), device['host'])
        ssh_dir = self.root / '.ssh'
        secure_path(ssh_dir, True)
        ssh_dir.mkdir(exist_ok=True, mode=0o700)
        if os.name == 'posix':
            os.chmod(ssh_dir, 0o700)
        known_hosts = ssh_dir / 'known_hosts'
        secure_path(known_hosts)
        try:
            existing = known_hosts.read_text(encoding='ascii') if known_hosts.exists() else ''
        except (OSError, UnicodeError):
            raise BackupError('unsafe_state', '無法安全讀取 SSH 信任清單') from None
        if len(existing.encode('ascii')) > 1024 * 1024:
            raise BackupError('unsafe_state', 'SSH 信任清單內容過大')
        line = verified['host'] + ' ' + verified['algorithm'] + ' ' + verified['key']
        matching = [saved.strip() for saved in existing.splitlines()
                    if saved.strip() and not saved.lstrip().startswith('#') and saved.split(maxsplit=1)[0] == device['host']]
        if matching and line not in matching:
            raise BackupError('host_key_conflict', '此設備已有不同的 SSH 身分指紋，為安全起見未覆寫')
        already_trusted = line in matching
        if not already_trusted:
            self.write_private(known_hosts, existing + ('' if not existing or existing.endswith('\n') else '\n') + line + '\n')
        candidates.pop(str(device['device_id']), None)
        self.put_meta('host_key_candidates', candidates)
        self.audit(actor, 'host_key_confirm', '設備識別碼：' + str(device['device_id']) + '；已確認 SSH 指紋')
        return {'device_id': device['device_id'], 'host': verified['host'], 'algorithm': verified['algorithm'],
                'fingerprint': verified['fingerprint'], 'trusted': True, 'already_trusted': already_trusted}

    def diagnose(self, ident, actor):
        device = self.configured_ssh_device(ident)
        known_hosts = self.root / '.ssh' / 'known_hosts'
        if device['host'] not in self.trusted_hosts():
            outcome = {'device_id': device['device_id'], 'outcome': 'failed', 'stage': 'host_key_untrusted',
                       'transport': False, 'pty': False, 'shell': False, 'prompt_detected': False}
        else:
            probe = self.probe_client.probe(device, known_hosts)
            stage = probe.get('stage', 'probe_failed')
            outcome = {'device_id': device['device_id'], 'outcome': 'success' if stage == 'complete' else 'failed',
                       'stage': stage, 'transport': probe.get('transport') is True, 'pty': probe.get('pty') is True,
                       'shell': probe.get('shell') is True, 'prompt_detected': probe.get('prompt', {}).get('detected') is True}
            prompt = probe.get('prompt')
            if isinstance(prompt, dict):
                outcome['prompt_terminator'] = prompt.get('terminator') if prompt.get('terminator') in {'#', '$', '>', 'unknown'} else 'unknown'
                outcome['ha_role_format'] = prompt.get('ha_role_format') is True
        self.audit(actor, 'diagnose', '設備識別碼：' + str(device['device_id']) + '；階段：' + outcome['stage'])
        return outcome

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
            device = devices.get(run['device_id'])
            if not device:
                continue
            last = by_name.get(device['host'], {}).get('last') or {}
            claimed = self.db.execute("UPDATE runs SET dispatched_at=?,previous_end=?,message=? WHERE id=? AND dispatched_at IS NULL AND status='queued'",
                            (utcnow(), last.get('end'), '已送交備份工具，等待完成證據', run['id']))
            if claimed.rowcount != 1:
                continue
            backend = canonical_backend(device.get('backup_backend', 'oxidized_cli'))
            if not uses_oxidized(device):
                client = self.api_backups.get(backend)
                if client is None:
                    status, version, message = 'failed', None, '此原生 API 備份方式尚未安裝'
                else:
                    try:
                        status, version, message = self.store_version(run, client.fetch(device))
                    except BackupError as error:
                        status, version, message = 'failed', None, error.message
                self.db.execute('UPDATE runs SET status=?,completed_at=?,message=?,version_id=? WHERE id=?',
                                (status, utcnow(), message, version, run['id']))
                continue
            try:
                self.engine.request('/node/next/' + quote(device['host'], safe='') + '.json')
            except BackupError:
                self.db.execute('UPDATE runs SET message=? WHERE id=?',
                                ('工具回應不明，將等待完成證據或逾時，不重複觸發', run['id']))
                break

    def redact(self, content):
        secrets = set(self.get_meta('redaction_secrets', []))
        secrets.update(d.get('password', '') for d in self.settings()['devices'])
        secrets.update(d.get('api_token', '') for d in self.settings()['devices'])
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
        if '--More--' in content:
            raise BackupError('incomplete_backup', '備份內容含有設備分頁提示，判定為不完整設定')
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
                    if last.get('status') == 'no_connection':
                        message = 'Oxidized SSH 採集失敗（no_connection）；請檢查互動式提示字元及服務日誌'
                    else:
                        message = 'Oxidized SSH 採集失敗；請檢查受管服務日誌'
                    status = 'failed'
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
        runs = [dict(r) for r in self.db.execute('SELECT id,device_id,device_name,kind,status,requested_at,completed_at,message,version_id FROM runs WHERE device_id=? ORDER BY id DESC LIMIT ? OFFSET ?', (ident, HISTORY_PAGE_SIZE, offset))]
        versions = [dict(r) for r in self.db.execute("SELECT id,device_id,created_at,digest,CASE WHEN instr(content, '--More--')=0 THEN 1 ELSE 0 END AS complete FROM versions WHERE device_id=? ORDER BY id DESC LIMIT ? OFFSET ?", (ident, HISTORY_PAGE_SIZE, offset))]
        return {'device_id': ident, 'offset': offset, 'limit': HISTORY_PAGE_SIZE, 'runs': runs, 'versions': versions,
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

    def download(self, request, actor):
        ident = self.identifier(request.get('device_id'))
        version = self.identifier(request.get('version_id'))
        row = self.db.execute('SELECT content FROM versions WHERE device_id=? AND id=?', (ident, version)).fetchone()
        if row is None or not isinstance(row['content'], str):
            raise BackupError('validation', '版本不存在或不屬於同一設備')
        content = row['content']
        if '--More--' in content:
            raise BackupError('incomplete_backup', '此版本含有設備分頁提示，判定為不完整設定，請重新備份')
        if len(content.encode('utf-8')) > DOWNLOAD_LIMIT:
            raise BackupError('download_too_large', '備份版本超過下載大小上限')
        self.audit(actor, 'download', '設備識別碼：' + str(ident) + '；版本：' + str(version))
        return {'device_id': ident, 'version_id': version, 'content': content}

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
        if not isinstance(request, dict) or request.get('action') not in {'settings', 'status', 'prepare_engine', 'save', 'test', 'run', 'tick', 'history', 'compare', 'download', 'host_key_scan', 'host_key_confirm', 'diagnose'}:
            raise BackupError('validation', '不支援的組態備份操作')
        action = request['action']
        actor = safe_text(request.get('actor', 'system'), '操作者', 255, allow_empty=action in {'settings', 'status', 'history', 'compare'})
        self.db.execute('BEGIN' if action in {'settings', 'status', 'history', 'compare'} else 'BEGIN IMMEDIATE')
        try:
            engine = self.cached_engine()
            if action == 'prepare_engine':
                if actor != 'system':
                    raise BackupError('forbidden', '只有系統更新程序可以重建備份引擎設定')
                self.generate()
            if action == 'save':
                settings = self.validate(request.get('settings'))
                if self.db.execute("SELECT 1 FROM runs WHERE status='queued'").fetchone():
                    raise BackupError('busy', '仍有備份執行中，請完成後再修改設定')
                secrets = set(self.get_meta('redaction_secrets', []))
                secrets.update(d.get('password', '') for d in self.settings()['devices'])
                secrets.update(d.get('api_token', '') for d in self.settings()['devices'])
                secrets.update(d.get('password', '') for d in settings['devices'])
                secrets.update(d.get('api_token', '') for d in settings['devices'])
                self.put_meta('redaction_secrets', sorted(secrets))
                self.put_meta('settings', settings)
                self.generate()
                # 儲存後立刻要求受管引擎重載，讓新增設備可立即測試／備份；
                # 引擎暫時不可用時仍保留設定，並以安全狀態回覆前端。
                engine, _ = self.applied_engine()
                self.put_meta('engine', engine)
                self.audit(actor, 'save', '儲存備份政策；設備數：' + str(len(settings['devices'])))
            if action in {'test', 'run'}:
                ident = request.get('device_id')
                if type(ident) is not int:
                    raise BackupError('validation', '請指定設備識別碼')
                device = self.configured_device(ident)
                if uses_oxidized(device) and not engine['applied']:
                    raise BackupError('unavailable', engine['message'])
                self.queue(ident, 'test' if action == 'test' else 'manual')
                self.audit(actor, action, '設備識別碼：' + str(ident))
            diagnosis = None
            if action == 'diagnose':
                diagnosis = self.diagnose(request.get('device_id'), actor)
            host_key = None
            if action == 'host_key_scan':
                host_key = self.scan_host_key(request.get('device_id'), actor)
            if action == 'host_key_confirm':
                host_key = self.confirm_host_key(request.get('device_id'), request.get('token'), request.get('fingerprint'), actor)
            if action == 'tick':
                # 以交易成功的資料庫設定重建，修復前次寫檔中斷造成的不一致。
                self.generate()
                engine, nodes = self.applied_engine()
                self.put_meta('engine', engine)
                self.reconcile(nodes)
                if self.settings()['enabled']:
                    count = 0
                    for device in self.settings()['devices']:
                        if not device['enabled'] or (uses_oxidized(device) and not engine['applied']):
                            continue
                        recent = self.db.execute('SELECT * FROM runs WHERE device_id=? ORDER BY id DESC LIMIT 1', (device['device_id'],)).fetchone()
                        if recent and (recent['status'] == 'queued' or (datetime.now(timezone.utc) - timestamp(recent['requested_at'])).total_seconds() < self.settings()['interval_hours'] * 3600):
                            continue
                        self.queue(device['device_id'], 'scheduled')
                        self.audit('system', 'scheduled', '設備識別碼：' + str(device['device_id']))
                        count += 1
                        if count >= 5:
                            break
                if engine['applied'] or any(not uses_oxidized(d) and d.get('enabled') for d in self.settings()['devices']):
                    self.dispatch_pending(nodes)
            result = self.response(engine)
            if action == 'history':
                result['history'] = self.history(request)
            if action == 'compare':
                result['comparison'] = self.compare(request)
            if action == 'download':
                result['download'] = self.download(request, actor)
            if host_key is not None:
                result['host_key'] = host_key
            if diagnosis is not None:
                result['diagnosis'] = diagnosis
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
