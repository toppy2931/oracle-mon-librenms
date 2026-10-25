"""FortiOS offline audit scanner tests; no network or device access."""

import hashlib
import json
from contextlib import closing
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest

from helpers import load_module


scanner = load_module('ioc_audit_network', 'system/ioc-audit-network.py')
ROOT = Path(__file__).resolve().parents[1]
CONFIG = '''#config-version=FGT-7.2
config system interface
    edit "port1"
        set alias "secret-interface-name"
        set allowaccess ping https ssh
    next
end
config system admin
    edit "administrator"
        set password ENC very-secret-password
        set trusthost1 192.0.2.0 255.255.255.0
    next
end
'''


class NetworkScanTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(dir=ROOT / 'tests')
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / 'state.sqlite3'
        with closing(sqlite3.connect(self.path)) as db, db:
            db.executescript('''
                CREATE TABLE meta(key TEXT PRIMARY KEY,value TEXT);
                CREATE TABLE versions(id INTEGER PRIMARY KEY,device_id INTEGER,created_at TEXT,digest TEXT,content TEXT);
                CREATE TABLE runs(id INTEGER PRIMARY KEY,device_id INTEGER,status TEXT,version_id INTEGER);
            ''')
            db.execute('INSERT INTO meta VALUES(?,?)', ('settings', json.dumps({'devices': [
                {'device_id': 7, 'model': 'fortios', 'host': '192.0.2.7'},
                {'device_id': 8, 'model': 'panos', 'host': '192.0.2.8'},
            ]})))
        self.insert(1, 7, CONFIG)

    def insert(self, version_id, device_id, content, *, digest=None, run_status='success'):
        digest = digest or hashlib.sha256(content.encode()).hexdigest()
        with closing(sqlite3.connect(self.path)) as db, db:
            db.execute('INSERT INTO versions VALUES(?,?,?,?,?)', (version_id, device_id, '2026-10-24', digest, content))
            if run_status:
                db.execute('INSERT INTO runs(device_id,status,version_id) VALUES(?,?,?)',
                           (device_id, run_status, version_id))

    def test_verified_version_yields_only_safe_statuses_and_stable_rule_pack(self):
        result = scanner.scan_version(self.path, 7, 1)
        self.assertEqual(('fortios_network_baseline', 1),
                         (result['rule_pack'], result['rule_pack_version']))
        self.assertEqual('pass', result['status'])
        self.assertEqual(['pass', 'pass'], [check['status'] for check in result['checks']])
        self.assertEqual(list(scanner.CHECK_IDS), [check['id'] for check in result['checks']])
        dumped = json.dumps(result)
        for secret in ('secret-interface-name', 'very-secret-password', '192.0.2.0', 'administrator'):
            self.assertNotIn(secret, dumped)

    def test_explicit_insecure_management_or_wildcard_is_failed(self):
        insecure = CONFIG.replace('ping https ssh', 'ping http telnet')
        insecure = insecure.replace('192.0.2.0 255.255.255.0', '0.0.0.0 0.0.0.0')
        self.insert(2, 7, insecure)
        result = scanner.scan_version(self.path, 7, 2)
        self.assertEqual('fail', result['status'])
        self.assertEqual(['fail', 'fail'], [check['status'] for check in result['checks']])
        self.assertNotIn('0.0.0.0', json.dumps(result))

    def test_absent_explicit_settings_are_unknown_not_compliant(self):
        self.insert(2, 7, 'config system global\n    set hostname device\nend\n')
        result = scanner.scan_version(self.path, 7, 2)
        self.assertEqual('unknown', result['status'])
        self.assertEqual(['unknown', 'unknown'], [check['status'] for check in result['checks']])

    def test_ignores_lookalike_content_outside_target_scope(self):
        content = CONFIG + 'config firewall address\nedit "set allowaccess telnet"\nnext\nend\n'
        self.insert(2, 7, content)
        self.assertEqual('pass', scanner.scan_version(self.path, 7, 2)['status'])

    def test_rejects_unverified_or_tampered_or_paged_versions(self):
        self.insert(2, 7, CONFIG, run_status='failed')
        self.assertEqual('unverified_version', scanner.scan_version(self.path, 7, 2)['error_code'])
        self.insert(3, 7, CONFIG, digest='a' * 64)
        self.assertEqual('integrity_error', scanner.scan_version(self.path, 7, 3)['error_code'])
        self.insert(4, 7, CONFIG + '--More--\n')
        self.assertEqual('unverified_version', scanner.scan_version(self.path, 7, 4)['error_code'])
        self.insert(5, 7, CONFIG[:-4])
        self.assertEqual('invalid_structure', scanner.scan_version(self.path, 7, 5)['error_code'])
        self.assertEqual([], scanner.scan_version(self.path, 7, 5)['checks'])

    def test_unsupported_model_and_unknown_device_never_scan_content(self):
        self.insert(2, 8, CONFIG)
        result = scanner.scan_version(self.path, 8, 2)
        self.assertEqual('unsupported', result['status'])
        self.assertEqual([], result['checks'])
        self.assertEqual('not_found', scanner.scan_version(self.path, 9, 2)['error_code'])

    def test_cli_latest_selects_last_completed_version_and_prints_safe_json(self):
        self.insert(2, 7, CONFIG.replace('ping https ssh', 'ping http'))
        script = ROOT / 'system' / 'ioc-audit-network.py'
        proc = subprocess.run([sys.executable, '-B', str(script), '--db', str(self.path),
                               '--device-id', '7', '--latest'], capture_output=True, text=True, check=True)
        result = json.loads(proc.stdout)
        self.assertEqual((2, 'fail'), (result['version_id'], result['status']))
        self.assertEqual('', proc.stderr)
        self.assertNotIn('very-secret-password', proc.stdout)


if __name__ == '__main__':
    unittest.main()
