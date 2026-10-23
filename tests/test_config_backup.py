"""組態備份的狀態、排程、敏感資訊與採集證據測試，不連線設備。"""
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import unittest
import base64
import struct
from datetime import datetime, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from helpers import load_module

backup = load_module('ioc_config_backup', 'system/ioc-config-backup.py')


class FakeEngine:
    def __init__(self):
        self.nodes = [{'name': '192.168.10.1', 'last': None}]
        self.content = 'hostname router\ninterface eth0\n description office\n'
        self.calls = []
        self.online = True

    def request(self, route):
        self.calls.append(route)
        if not self.online:
            raise backup.BackupError('unavailable', '備份工具尚未啟動')
        if route == '/reload.json' and not self.nodes:
            self.nodes = [{'name': '192.168.10.1', 'last': None}]
        if route == '/nodes.json':
            return self.nodes
        if route.startswith('/node/fetch/'):
            return self.content
        return 'ok'


class BackupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.engine = FakeEngine()
        self.worker = backup.Worker(Path(self.temp.name) / 'state', self.engine)
        self.settings = {'enabled': False, 'interval_hours': 24, 'devices': [
            {'device_id': 7, 'name': '核心交換器', 'host': '192.168.10.1', 'model': 'ios',
             'username': 'backup', 'password': 'private-secret', 'port': 22, 'enabled': True}]}

    def tearDown(self):
        self.worker.close()
        self.temp.cleanup()

    def call(self, action, **kwargs):
        result = self.worker.dispatch({'action': action, 'actor': 'admin', **kwargs})
        if action in {'test', 'run'}:
            self.worker.dispatch({'action': 'tick', 'actor': 'system'})
        return result

    def save(self):
        self.call('save', settings=self.settings)
        return self.call('tick')

    def finish(self, status='success', content=None):
        now = datetime.now(timezone.utc)
        self.engine.nodes = [{'name': '192.168.10.1', 'last': {
            'start': (now + timedelta(seconds=1)).isoformat(),
            'end': (now + timedelta(seconds=2)).isoformat(), 'status': status}}]
        if content is not None:
            self.engine.content = content
        return self.call('tick')

    def test_history_paginates_all_runs_and_versions_after_device_removal(self):
        self.save()
        for n in range(25):
            self.worker.store_version({'device_id': 7, 'device_name': 'router'}, 'hostname r' + str(n))
            self.worker.db.execute("INSERT INTO runs(device_id,device_name,status) VALUES(7,'router','success')")
        self.settings['devices'] = []
        self.call('save', settings=self.settings)
        page = self.call('history', device_id=7, offset=10)['history']
        self.assertEqual((10, 10, 10), (page['limit'], len(page['runs']), len(page['versions'])))
        result = self.call('history', device_id=7, offset=20)
        history = result['history']
        self.assertEqual((25, 25, 5, 5), (history['total_runs'], history['total_versions'], len(history['runs']), len(history['versions'])))
        self.assertFalse(result['history_devices'][0]['configured'])
        self.assertNotIn('content', history['versions'][0])
        self.assertEqual([], self.call('history', device_id=7, offset=40)['history']['versions'])

    def test_compare_arbitrary_versions_masks_rotated_and_removed_credentials(self):
        self.settings['devices'][0]['password'] = 'uniqueOldValue'
        self.save()
        self.worker.store_version({'device_id': 7, 'device_name': 'router'}, 'hostname old\nremark uniqueOldValue')
        self.settings['devices'][0]['password'] = 'uniqueNewValue'
        self.call('save', settings=self.settings)
        self.worker.store_version({'device_id': 7, 'device_name': 'router'}, 'hostname middle')
        self.worker.store_version({'device_id': 7, 'device_name': 'router'}, 'hostname new\nremark uniqueNewValue')
        self.settings['devices'] = []
        self.call('save', settings=self.settings)
        result = self.call('compare', device_id=7, from_version_id=1, to_version_id=3)['comparison']
        self.assertTrue(result['changed'])
        self.assertIn('-hostname old', result['diff'])
        self.assertIn('+hostname new', result['diff'])
        self.assertNotIn('uniqueOldValue', result['diff'])
        self.assertNotIn('uniqueNewValue', result['diff'])
        self.assertFalse(self.call('compare', device_id=7, from_version_id=1, to_version_id=1)['comparison']['changed'])
        self.worker.store_version({'device_id': 8, 'device_name': 'other'}, 'hostname other')
        with self.assertRaises(backup.BackupError):
            self.call('compare', device_id=7, from_version_id=1, to_version_id=4)

    def test_diff_counts_content_that_resembles_diff_headers(self):
        run = {'device_id': 7, 'device_name': 'router'}
        self.worker.store_version(run, '--old banner\nunchanged\n')
        self.worker.store_version(run, '++new banner\nunchanged\n')
        compared = self.call('compare', device_id=7, from_version_id=1, to_version_id=2)['comparison']
        self.assertEqual((1, 1), (compared['added'], compared['removed']))
        automatic = self.call('status')['changes'][0]
        self.assertEqual((1, 1), (automatic['added'], automatic['removed']))

    def test_download_returns_only_requested_version_and_audits_actor(self):
        run = {'device_id': 7, 'device_name': 'router'}
        self.worker.store_version(run, 'hostname downloadable')
        result = self.call('download', actor='admin', device_id=7, version_id=1)['download']
        self.assertEqual('hostname downloadable', result['content'])
        self.assertEqual((7, 1), (result['device_id'], result['version_id']))
        self.assertEqual('download', self.call('status')['audit'][0]['action'])
        with self.assertRaises(backup.BackupError):
            self.call('download', actor='admin', device_id=7, version_id=2)

        oversized = 'x' * (backup.DOWNLOAD_LIMIT + 1)
        self.worker.db.execute(
            'INSERT INTO versions(device_id,created_at,digest,content) VALUES(?,?,?,?)',
            (7, backup.utcnow(), hashlib.sha256(oversized.encode()).hexdigest(), oversized),
        )
        self.worker.db.commit()
        with self.assertRaises(backup.BackupError) as raised:
            self.call('download', actor='admin', device_id=7, version_id=2)
        self.assertEqual('download_too_large', raised.exception.code)

    def test_large_comparison_is_truncated_with_complete_change_counts(self):
        run = {'device_id': 7, 'device_name': 'router'}
        before = ''.join('interface ethernet' + str(n) + ' description original\n' for n in range(35000))
        self.worker.store_version(run, before)
        self.worker.store_version(run, before.replace('original', 'modified'))
        result = self.call('compare', device_id=7, from_version_id=1, to_version_id=2)['comparison']
        self.assertTrue(result['truncated'])
        self.assertEqual((35000, 35000), (result['added'], result['removed']))
        self.assertLess(len(result['diff'].encode()), 12500)

    def test_history_and_comparison_reject_invalid_identifiers(self):
        for value in [True, '7', 0, -1, 2147483648, None]:
            with self.subTest(value=value), self.assertRaises(backup.BackupError):
                self.call('history', device_id=value)
        for offset in [True, '0', -1, 2147483648]:
            with self.subTest(offset=offset), self.assertRaises(backup.BackupError):
                self.call('history', device_id=7, offset=offset)
        with self.assertRaises(backup.BackupError):
            self.call('compare', device_id=7, from_version_id=999, to_version_id=1000)

    def test_settings_never_return_password(self):
        out = self.save()
        self.assertNotIn('private-secret', json.dumps(out))
        self.assertTrue(out['settings']['devices'][0]['password_set'])
        self.assertIn('private-secret', (self.worker.root / 'oxidized' / 'router.db').read_text())

    def test_blank_password_preserves_saved_secret(self):
        self.save()
        self.settings['devices'][0]['password'] = ''
        self.save()
        self.assertTrue(self.call('settings')['settings']['devices'][0]['password_set'])

    def test_unknown_action_is_rejected(self):
        with self.assertRaises(backup.BackupError):
            self.call('shell')

    def test_host_and_csv_injection_rejected(self):
        for value in ['localhost', '127.0.0.1', '169.254.1.1', '224.0.0.1', '::1', '-router', 'router\nroot', 'host:22']:
            with self.subTest(value=value):
                self.settings['devices'][0]['host'] = value
                with self.assertRaises(backup.BackupError):
                    self.save()

    def test_secret_newline_and_interpolation_rejected(self):
        for value in ['abc\ndef', 'x|y', '#{ENV["HOME"]}']:
            with self.subTest(value=value):
                self.settings['devices'][0]['password'] = value
                with self.assertRaises(backup.BackupError):
                    self.save()

    def test_port_model_limits_rejected(self):
        for field, value in [('port', 2222), ('model', '../../evil'), ('device_id', True)]:
            with self.subTest(field=field):
                prior = self.settings['devices'][0][field]
                self.settings['devices'][0][field] = value
                with self.assertRaises(backup.BackupError):
                    self.save()
                self.settings['devices'][0][field] = prior

    def test_interval_limit_and_duplicate_device(self):
        self.settings['interval_hours'] = 0
        with self.assertRaises(backup.BackupError):
            self.save()
        self.settings['interval_hours'] = 24
        self.settings['devices'] *= 2
        with self.assertRaises(backup.BackupError):
            self.save()

    def test_unavailable_is_not_applied(self):
        self.engine.online = False
        out = self.save()
        self.assertEqual('unavailable', out['engine']['state'])
        self.assertFalse(out['engine']['applied'])
        with self.assertRaises(backup.BackupError):
            self.call('run', device_id=7)

    def test_enqueue_is_not_success_and_no_overlap(self):
        self.save()
        out = self.call('run', device_id=7)
        self.assertEqual('queued', out['runs'][0]['status'])
        with self.assertRaises(backup.BackupError):
            self.call('test', device_id=7)
        self.assertEqual(1, len(out['runs']))

    def test_old_completion_cannot_complete_new_run(self):
        self.save()
        old = (datetime.now(timezone.utc) - timedelta(days=1)).isoformat()
        self.engine.nodes = [{'name': '192.168.10.1', 'last': {'start': old, 'end': old, 'status': 'success'}}]
        self.call('run', device_id=7)
        self.assertEqual('queued', self.call('status')['runs'][0]['status'])

    def test_baseline_unchanged_and_changed(self):
        self.save()
        self.call('run', device_id=7)
        out = self.finish()
        self.assertEqual('success', out['runs'][0]['status'])
        self.assertEqual([], out['changes'])
        self.engine.nodes = []
        self.call('run', device_id=7)
        out = self.finish()
        self.assertEqual('unchanged', out['runs'][0]['status'])
        self.engine.nodes = []
        self.call('run', device_id=7)
        out = self.finish(content='hostname router\ninterface eth0\n description changed\npassword private-secret\n')
        self.assertEqual(1, len(out['changes']))
        self.assertNotIn('private-secret', json.dumps(out))
        self.assertIn('description changed', out['changes'][0]['diff'])

    def test_failure_and_empty_content_keep_previous_version(self):
        self.save()
        self.call('run', device_id=7)
        self.finish()
        for status, content in [('no_connection', None), ('success', '')]:
            self.engine.nodes = []
            self.call('run', device_id=7)
            out = self.finish(status=status, content=content)
            self.assertEqual('failed', out['runs'][0]['status'])
        self.assertEqual(1, self.worker.db.execute('SELECT COUNT(*) FROM versions').fetchone()[0])

    def test_paged_fortigate_output_is_rejected_and_never_saved(self):
        self.save()
        self.call('run', device_id=7)
        out = self.finish(content='config system interface\nedit "port1"\n--More--  edit "port2"\n')
        self.assertEqual('failed', out['runs'][0]['status'])
        self.assertIn('分頁提示', out['runs'][0]['message'])
        self.assertEqual(0, self.worker.db.execute('SELECT COUNT(*) FROM versions').fetchone()[0])

    def test_legacy_paged_version_is_marked_incomplete_and_cannot_download(self):
        self.save()
        partial = 'config system interface\n--More--  edit "port2"\n'
        self.worker.db.execute(
            'INSERT INTO versions(device_id,created_at,digest,content) VALUES(?,?,?,?)',
            (7, backup.utcnow(), hashlib.sha256(partial.encode()).hexdigest(), partial),
        )
        self.worker.db.commit()
        history = self.call('history', device_id=7, offset=0)['history']
        self.assertFalse(history['versions'][0]['complete'])
        with self.assertRaises(backup.BackupError) as raised:
            self.call('download', actor='admin', device_id=7, version_id=1)
        self.assertEqual('incomplete_backup', raised.exception.code)

    def test_oxidized_utc_result_marks_failed_run_complete(self):
        self.save()
        self.call('run', device_id=7)
        dispatched = backup.timestamp(self.worker.db.execute('SELECT dispatched_at FROM runs').fetchone()[0])
        reported = dispatched.strftime('%Y-%m-%d %H:%M:%S UTC')
        self.engine.nodes = [{'name': '192.168.10.1', 'last': {
            'start': reported, 'end': reported, 'status': 'no_connection'}}]
        result = self.call('tick')
        self.assertEqual('failed', result['runs'][0]['status'])

    def test_tick_schedules_only_due_and_enabled(self):
        self.settings['enabled'] = True
        self.save()
        self.call('tick')
        self.assertEqual(1, len(self.call('status')['runs']))
        self.finish()
        self.call('tick')
        self.assertEqual(1, len(self.call('status')['runs']))
        self.settings['enabled'] = False
        self.save()
        self.call('tick')
        self.assertEqual(1, len(self.call('status')['runs']))

    def test_fetch_lines_array_is_valid_backup(self):
        self.save()
        self.call('run', device_id=7)
        out = self.finish(content=['hostname router\n', 'interface eth0\n'])
        self.assertEqual('success', out['runs'][0]['status'])

    def test_save_applies_engine_immediately_while_status_remains_read_only(self):
        self.worker.dispatch({'action': 'save', 'actor': 'admin', 'settings': self.settings})
        out = self.call('status')
        self.assertIn('/reload.json', self.engine.calls)
        self.assertEqual('ready', out['engine']['state'])
        self.assertTrue(out['engine']['applied'])

    def test_fetch_unavailable_after_valid_completion_still_times_out(self):
        self.save()
        self.call('run', device_id=7)
        now = datetime.now(timezone.utc)
        self.engine.nodes = [{'name': '192.168.10.1', 'last': {
            'start': (now + timedelta(seconds=1)).isoformat(),
            'end': (now + timedelta(seconds=2)).isoformat(), 'status': 'success'}}]
        self.worker.db.execute("UPDATE runs SET requested_at='2000-01-01T00:00:00+00:00'")
        original = self.engine.request
        def request(route):
            if route.startswith('/node/fetch/'):
                raise backup.BackupError('unavailable', '工具暫時無法取得內容')
            return original(route)
        self.engine.request = request
        self.assertEqual('timeout', self.call('tick')['runs'][0]['status'])

    def test_parallel_dispatch_does_not_send_same_work_twice(self):
        self.settings['devices'].append({**self.settings['devices'][0], 'device_id': 8, 'host': '192.168.10.2'})
        self.engine.nodes.append({'name': '192.168.10.2', 'last': None})
        self.save()
        self.worker.queue(7, 'manual')
        self.worker.queue(8, 'manual')
        other = backup.Worker(self.worker.root, self.engine)
        original = self.engine.request
        entered = False
        def request(route):
            nonlocal entered
            result = original(route)
            if route.startswith('/node/next/') and not entered:
                entered = True
                other.dispatch_pending(self.engine.nodes)
            return result
        self.engine.request = request
        try:
            self.worker.dispatch_pending(self.engine.nodes)
        finally:
            other.close()
        self.assertEqual(1, self.engine.calls.count('/node/next/192.168.10.2.json'))

    def test_unclosed_private_key_is_redacted_to_end(self):
        content = 'hostname safe\n-----BEGIN RSA PRIVATE KEY-----\nSENSITIVE_BASE64_BODY\n'
        output = self.worker.redact(content)
        self.assertNotIn('SENSITIVE_BASE64_BODY', output)
        self.assertIn('hostname safe', output)

    def test_cli_malformed_state_returns_safe_json(self):
        self.worker.put_meta('settings', {'devices': None, 'enabled': False, 'interval_hours': 24})
        env = {**os.environ, 'IOC_BACKUP_STATE_DIR': str(self.worker.root), 'PYTHONIOENCODING': 'utf-8'}
        result = subprocess.run([sys.executable, backup.__file__], input=b'{"action":"status","actor":""}', capture_output=True, env=env, timeout=10)
        self.assertEqual(1, result.returncode)
        self.assertEqual('state_error', json.loads(result.stdout)['error']['code'])
        self.assertNotIn(b'Traceback', result.stderr)

    def test_status_read_does_not_wait_for_writer(self):
        self.save()
        self.worker.db.execute('BEGIN IMMEDIATE')
        other = None
        try:
            other = backup.Worker(self.worker.root, self.engine)
            out = other.dispatch({'action': 'status', 'actor': ''})
            self.assertEqual(7, out['settings']['devices'][0]['device_id'])
        finally:
            if other:
                other.close()
            self.worker.db.rollback()

    def test_stale_engine_is_not_ready(self):
        self.save()
        engine = self.worker.get_meta('engine')
        engine['checked_at'] = '2000-01-01T00:00:00+00:00'
        self.worker.put_meta('engine', engine)
        out = self.call('status')
        self.assertEqual('unavailable', out['engine']['state'])
        self.assertFalse(out['engine']['applied'])
        with self.assertRaises(backup.BackupError):
            self.call('run', device_id=7)

    def test_different_daemon_nodes_are_not_ready(self):
        self.save()
        self.engine.nodes = [{'name': 'foreign', 'last': None}]
        out = self.call('tick')
        self.assertEqual('pending_apply', out['engine']['state'])
        self.assertFalse(out['engine']['applied'])

    def test_engine_configuration_revision_reloads_legacy_applied_settings(self):
        self.save()
        legacy_revision = hashlib.sha256(json.dumps(self.worker.settings(), sort_keys=True).encode()).hexdigest()
        self.worker.put_meta('applied_revision', legacy_revision)
        self.engine.calls.clear()
        out = self.call('tick')
        self.assertTrue(out['engine']['applied'])
        self.assertIn('/reload.json', self.engine.calls)

    def test_sensitive_only_change_still_records_version(self):
        self.save()
        self.call('run', device_id=7)
        self.finish(content='hostname router\npassword one\n')
        self.engine.nodes = []
        self.call('run', device_id=7)
        out = self.finish(content='hostname router\npassword two\n')
        self.assertEqual(1, len(out['changes']))
        self.assertIn('敏感設定', out['changes'][0]['diff'])
        self.assertNotIn('password two', json.dumps(out))

    def test_engine_config_disables_own_schedule_and_secret_api(self):
        self.save()
        config = json.loads((self.worker.root / 'oxidized' / 'config').read_text())
        self.assertEqual(0, config['interval'])
        self.assertTrue(config['next_adds_job'])
        self.assertTrue(config['input']['ssh']['secure'])
        self.assertEqual(5, config['source']['csv']['vars_map']['ssh_no_exec'])
        self.assertEqual('127.0.0.1', config['extensions']['oxidized-web']['listen'])
        self.assertFalse(config['output']['clean_obsolete_nodes'])

    def test_fortios_uses_interactive_pager_aware_model_without_modifying_system_model(self):
        self.settings['devices'][0]['model'] = 'fortios'
        self.save()
        home = self.worker.root / 'oxidized'
        router = (home / 'router.db').read_text()
        model = (home / 'model' / 'iocfortios.rb').read_text(encoding='utf-8')
        self.assertIn('|iocfortios|', router)
        self.assertTrue(router.rstrip().endswith('|true'))
        self.assertIn('class IocFortiOS < FortiOS', model)
        self.assertIn('[-\\w.~()]+', model)
        self.assertIn('expect /--More--/', model)
        self.assertIn("send ' '", model)
        self.assertNotIn('exec true', model)

    def test_host_key_requires_explicit_fingerprint_confirmation(self):
        self.save()
        blob = struct.pack('>I', 11) + b'ssh-ed25519' + struct.pack('>I', 32) + b'x' * 32
        key = base64.b64encode(blob).decode('ascii')
        line = '192.168.10.1 ssh-ed25519 ' + key + '\n'
        with patch.object(backup.subprocess, 'run', return_value=SimpleNamespace(stdout=line.encode('ascii'))) as scan:
            candidate = self.call('host_key_scan', device_id=7)['host_key']
        self.assertEqual(['/usr/bin/ssh-keyscan', '-T', '4', '-t', 'ed25519', '--', '192.168.10.1'], scan.call_args.args[0])
        self.assertFalse(candidate['trusted'])
        self.assertFalse(self.call('status')['settings']['devices'][0]['host_key_trusted'])
        confirmed = self.call('host_key_confirm', device_id=7, token=candidate['token'],
                              fingerprint=candidate['fingerprint'])['host_key']
        self.assertTrue(confirmed['trusted'])
        self.assertTrue(self.call('status')['settings']['devices'][0]['host_key_trusted'])
        known = (self.worker.root / '.ssh' / 'known_hosts').read_text(encoding='ascii')
        self.assertEqual(line, known)

    def test_host_key_does_not_overwrite_a_different_trusted_key(self):
        self.save()
        (self.worker.root / '.ssh').mkdir(mode=0o700)
        known_hosts = self.worker.root / '.ssh' / 'known_hosts'
        known_hosts.write_text('192.168.10.1 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEstaleKeyShouldNotBeTrusted=\n', encoding='ascii')
        known_hosts.chmod(0o600)
        blob = struct.pack('>I', 11) + b'ssh-ed25519' + struct.pack('>I', 32) + b'x' * 32
        line = '192.168.10.1 ssh-ed25519 ' + base64.b64encode(blob).decode('ascii') + '\n'
        with patch.object(backup.subprocess, 'run', return_value=SimpleNamespace(stdout=line.encode('ascii'))):
            candidate = self.call('host_key_scan', device_id=7)['host_key']
        with self.assertRaises(backup.BackupError) as error:
            self.call('host_key_confirm', device_id=7, token=candidate['token'], fingerprint=candidate['fingerprint'])
        self.assertEqual('host_key_conflict', error.exception.code)

    def test_save_during_run_rejected(self):
        self.save()
        self.call('run', device_id=7)
        with self.assertRaises(backup.BackupError):
            self.save()

    def test_timeout_is_recorded(self):
        self.save()
        self.call('run', device_id=7)
        self.worker.db.execute("UPDATE runs SET requested_at='2000-01-01T00:00:00+00:00'")
        self.assertEqual('timeout', self.call('tick')['runs'][0]['status'])

    def test_symlink_state_rejected(self):
        target = Path(self.temp.name) / 'target'
        target.mkdir()
        link = Path(self.temp.name) / 'link'
        try:
            link.symlink_to(target, target_is_directory=True)
        except OSError:
            self.skipTest('此環境未授予建立符號連結權限')
        with self.assertRaises(backup.BackupError):
            backup.Worker(link, self.engine)


if __name__ == '__main__':
    unittest.main()
