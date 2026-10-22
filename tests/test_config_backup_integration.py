"""組態備份整合邊界：權限、背景執行與秘密資料不可暴露。"""
import unittest
import shutil
import subprocess
import json
import os
import sys
import tempfile
from helpers import ROOT

class ConfigBackupIntegrationTests(unittest.TestCase):
    def test_api_requires_admin_before_device_or_backend_reads(self):
        src=(ROOT/'librenms/html/ioc-backup.php').read_text(encoding='utf-8')
        self.assertLess(src.index('ioc_begin(true)'),src.index('Access::requireHandler'))
        gate=src.index('Access::requireHandler')
        self.assertLess(gate,src.index('ioc_backup_devices('))
        self.assertLess(gate,src.index('ConfigBackupService('))
        self.assertIn('262144',src)

    def test_module_is_local_and_secrets_never_use_html(self):
        src=(ROOT/'librenms/html/js/ioc-config-backup.js').read_text(encoding='utf-8')
        self.assertNotIn('innerHTML',src)
        self.assertNotIn('outerHTML',src)
        self.assertIn("type: 'password'",src)
        self.assertIn('password_set',src)
        self.assertIn('尚未安裝',src)
        portal=(ROOT/'librenms/html/ioc-portal.php').read_text(encoding='utf-8')
        self.assertIn('/js/ioc-config-backup.js',portal)
        self.assertIn('id="view-config"',portal)
        self.assertNotIn("key: 'config', label: '組態治理', off: true",portal)

    def test_cli_save_status_and_unavailable_are_structured_without_secrets(self):
        with tempfile.TemporaryDirectory() as folder:
            env = dict(os.environ, IOC_BACKUP_STATE_DIR=str(ROOT.__class__(folder)/'private'), PYTHONDONTWRITEBYTECODE='1')
            def call(body):
                result = subprocess.run([sys.executable, '-X', 'utf8', '-B', str(ROOT/'system/ioc-config-backup.py')],
                    input=json.dumps(body), text=True, encoding='utf-8', capture_output=True, env=env, timeout=10)
                self.assertEqual(result.stderr, '')
                self.assertNotIn('cli-test-only-secret', result.stdout)
                return result.returncode, json.loads(result.stdout)
            settings = {'enabled': False, 'interval_hours': 24, 'devices': [
                {'device_id': 9, 'name': '測試設備', 'host': '192.0.2.9', 'model': 'fortios', 'username': 'backup',
                 'password': 'cli-test-only-secret', 'port': 22, 'enabled': True}]}
            code, saved = call({'action': 'save', 'actor': 'test-admin', 'settings': settings})
            self.assertEqual(code, 0)
            self.assertEqual(saved['data']['engine']['state'], 'pending_apply')
            code, status = call({'action': 'status', 'actor': ''})
            self.assertEqual(code, 0)
            self.assertTrue(status['data']['settings']['devices'][0]['password_set'])
            code, run = call({'action': 'run', 'actor': 'test-admin', 'device_id': 9})
            self.assertEqual(code, 1)
            self.assertFalse(run['ok'])
            self.assertEqual(run['error']['code'], 'unavailable')

    @unittest.skipUnless(shutil.which('node'), '需要 Node 執行介面行為測試')
    def test_ui_behaviors(self):
        result = subprocess.run(['node', '--test', str(ROOT/'tests/js/config_backup_test.js')], capture_output=True, text=True, encoding='utf-8', timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_deployment_installs_worker_without_auto_enabling(self):
        for name in ('install.sh','update.sh'):
            self.assertIn('install-config-backup.sh',(ROOT/name).read_text(encoding='utf-8'))
        src=(ROOT/'system/install-config-backup.sh').read_text(encoding='utf-8')
        self.assertNotIn('enable --now',src)
        self.assertNotIn('gem install',src)
        self.assertIn('oracle-backup',src)
        self.assertIn('ioc-config-backup.py',src)
        self.assertIn('ioc-backup.php',(ROOT/'uninstall.sh').read_text(encoding='utf-8'))

if __name__=='__main__':
    unittest.main()
