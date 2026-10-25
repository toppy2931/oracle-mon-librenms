"""The Linux audit scanner must remain agentless, bounded and non-disclosing."""
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from helpers import load_module


scanner = load_module('ioc_audit_linux', 'system/ioc-audit-linux.py')


class LinuxScannerTests(unittest.TestCase):
    def setUp(self):
        self.host = {
            'address': '192.0.2.10', 'username': 'ioc_audit',
            'identity_file': '/private/audit_ed25519',
            'known_hosts_file': '/private/known_hosts',
            'port': 22, 'enabled': True,
        }
        self.private = patch.object(scanner, '_private_regular_file', side_effect=lambda value, private: Path(value))
        self.private.start()
        self.addCleanup(self.private.stop)

    def test_fixed_commands_produce_deterministic_redacted_checks(self):
        outputs = {
            '/usr/bin/id -u': (0, '1001'),
            '/usr/bin/systemctl is-active auditd.service': (0, 'active'),
            '/usr/bin/timedatectl show -p NTPSynchronized --value': (0, 'yes'),
            "/usr/bin/sudo -n /usr/bin/stat -c '%a %u %g' /etc/ssh/sshd_config": (0, '640 0 0'),
        }
        commands = []

        def fake_runner(_host, command):
            commands.append(command)
            return outputs[command]

        first = scanner.scan_host('device-10', self.host, fake_runner)
        second = scanner.scan_host('device-10', self.host, fake_runner)
        self.assertEqual(first, second)
        self.assertEqual('pass', first['status'])
        self.assertEqual('linux_ssh', first['source'])
        self.assertEqual('linux_ssh_baseline', first['rule_pack'])
        self.assertEqual(1, first['rule_pack_version'])
        self.assertTrue(all(check['status'] == 'pass' for check in first['checks']))
        self.assertEqual([command for _, command in scanner.CHECKS] * 2, commands)
        self.assertNotIn('192.0.2.10', json.dumps(first))
        self.assertNotIn('audit_ed25519', json.dumps(first))

    def test_failure_unknown_and_source_error_are_distinct(self):
        answers = iter([(0, '1001'), (3, 'inactive'), (0, 'no'), (0, '644 0 0')])
        failed = scanner.scan_host('device-10', self.host, lambda *_: next(answers))
        self.assertEqual('fail', failed['status'])
        self.assertEqual(['pass', 'fail', 'fail', 'fail'], [item['status'] for item in failed['checks']])

        answers = iter([(0, '1001'), (1, ''), (0, 'yes'), (0, '640 0 0')])
        unknown = scanner.scan_host('device-10', self.host, lambda *_: next(answers))
        self.assertEqual('unknown', unknown['status'])
        self.assertEqual('unknown', unknown['checks'][1]['status'])

        error = scanner.scan_host('device-10', self.host, lambda *_: (_ for _ in ()).throw(scanner.ScanError('host_key_error')))
        self.assertEqual('error', error['status'])
        self.assertEqual('host_key_error', error['error_code'])
        self.assertEqual(['error', 'unknown', 'unknown', 'unknown'], [item['status'] for item in error['checks']])

    def test_untrusted_config_cannot_supply_command_or_ssh_option(self):
        hostile = {**self.host, 'command': 'rm -rf /'}
        self.assertEqual('invalid_config', scanner.scan_host('device-10', hostile, lambda *_: None)['error_code'])
        for field, value in [('address', '-oProxyCommand=evil'), ('username', '-oProxyCommand=evil'),
                             ('port', '22'), ('port', True)]:
            with self.subTest(field=field, value=value):
                result = scanner.scan_host('device-10', {**self.host, field: value}, lambda *_: None)
                self.assertEqual('error', result['status'])
        self.assertEqual('invalid_command', self._invalid_command_code())

    def _invalid_command_code(self):
        with self.assertRaises(scanner.ScanError) as raised:
            scanner.run_fixed_command(self.host, '/bin/sh -c touch /tmp/evil')
        return raised.exception.code

    def test_ssh_argv_forces_identity_host_key_and_no_forwarding(self):
        argv = scanner._ssh_arguments(self.host, '/usr/bin/id -u')
        self.assertEqual('/usr/bin/ssh', argv[0])
        for option in ('StrictHostKeyChecking=yes', 'BatchMode=yes', 'IdentitiesOnly=yes',
                       'ClearAllForwardings=yes', 'ProxyCommand=none', 'ProxyJump=none',
                       'UserKnownHostsFile=/private/known_hosts', 'GlobalKnownHostsFile=/dev/null'):
            self.assertIn(option, argv)
        self.assertIn('/dev/null', argv)
        self.assertEqual(['-l', 'ioc_audit', '192.0.2.10', '/usr/bin/id -u'], argv[-4:])
        self.assertNotIn('sh', argv)

    def test_disabled_host_is_unknown_without_network_call(self):
        result = scanner.scan_host('device-10', {**self.host, 'enabled': False},
                                   lambda *_: self.fail('SSH should not run'))
        self.assertEqual('unknown', result['status'])
        self.assertEqual('not_enabled', result['error_code'])

    @unittest.skipUnless(os.name == 'posix', 'file mode and ownership check is Linux-specific')
    def test_config_requires_private_regular_file_and_directory(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            root.chmod(0o700)
            config = root / 'linux-hosts.json'
            config.write_text(json.dumps({'hosts': {'device-10': self.host}}), encoding='utf-8')
            config.chmod(0o600)
            with patch.object(scanner, '_private_regular_file', wraps=scanner._private_regular_file):
                self.assertIn('device-10', scanner.load_config(config))
                config.chmod(0o644)
                with self.assertRaises(scanner.ScanError) as error:
                    scanner.load_config(config)
                self.assertEqual('unsafe_permissions', error.exception.code)


if __name__ == '__main__':
    unittest.main()
