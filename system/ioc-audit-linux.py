#!/usr/bin/env python3
"""Agentless, read-only Linux checks for the IOC audit worker.

Only a VM-local, private enrolment file may select the SSH destination.  The
remote commands below are constants; neither HTTP input nor the enrolment file
can supply a command.  SSH output is parsed in memory and never serialized.
"""

import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import selectors
import stat
import subprocess
import sys
import time


DEFAULT_CONFIG = '/var/lib/oracle-mon/ioc-audit/linux-hosts.json'
RULE_PACK = 'linux_ssh_baseline'
RULE_PACK_VERSION = 1
SOURCE = 'linux_ssh'
MAX_CONFIG_BYTES = 256 * 1024
MAX_OUTPUT_BYTES = 16 * 1024
COMMAND_TIMEOUT = 12
CHECKS = (
    ('non_root_login', '/usr/bin/id -u'),
    ('auditd_active', '/usr/bin/systemctl is-active auditd.service'),
    ('time_synchronized', '/usr/bin/timedatectl show -p NTPSynchronized --value'),
    ('sshd_config_permissions', "/usr/bin/sudo -n /usr/bin/stat -c '%a %u %g' /etc/ssh/sshd_config"),
)
HOST_FIELDS = frozenset({'address', 'username', 'identity_file', 'known_hosts_file', 'port', 'enabled'})
HOST_ID_RE = re.compile(r'^[A-Za-z0-9_][A-Za-z0-9_.:-]{0,127}$')
USER_RE = re.compile(r'^[a-z_][a-z0-9_.-]{0,31}$')
DNS_RE = re.compile(r'^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$')


class ScanError(Exception):
    """A non-sensitive scanner status code, never a raw command failure."""

    def __init__(self, code):
        super().__init__(code)
        self.code = code


def _private_regular_file(value, *, private):
    if not isinstance(value, str) or not value.startswith('/') or '\x00' in value:
        raise ScanError('invalid_path')
    path = Path(value)
    try:
        info = path.lstat()
    except OSError:
        raise ScanError('missing_file') from None
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode):
        raise ScanError('invalid_path')
    if info.st_uid not in {0, os.geteuid()}:
        raise ScanError('unsafe_permissions')
    forbidden = 0o077 if private else 0o022
    if info.st_mode & forbidden:
        raise ScanError('unsafe_permissions')
    return path


def load_config(path):
    """Read only a restricted, regular JSON file from a restricted directory."""
    config_path = _private_regular_file(str(path), private=True)
    parent = config_path.parent
    try:
        directory = parent.lstat()
    except OSError:
        raise ScanError('missing_file') from None
    if not stat.S_ISDIR(directory.st_mode) or stat.S_ISLNK(directory.st_mode):
        raise ScanError('invalid_path')
    if directory.st_uid not in {0, os.geteuid()} or directory.st_mode & 0o077:
        raise ScanError('unsafe_permissions')
    if config_path.stat().st_size > MAX_CONFIG_BYTES:
        raise ScanError('invalid_config')
    try:
        document = json.loads(config_path.read_text(encoding='utf-8'))
    except (OSError, UnicodeError, json.JSONDecodeError):
        raise ScanError('invalid_config') from None
    if not isinstance(document, dict) or set(document) != {'hosts'} or not isinstance(document['hosts'], dict):
        raise ScanError('invalid_config')
    return document['hosts']


def validate_host(host_id, host):
    """Validate an approved inventory entry; extra fields cannot inject SSH options."""
    if not isinstance(host_id, str) or not HOST_ID_RE.fullmatch(host_id):
        raise ScanError('invalid_host_id')
    if not isinstance(host, dict) or set(host) - HOST_FIELDS:
        raise ScanError('invalid_config')
    if host.get('enabled') is not True:
        raise ScanError('not_enabled')
    address = host.get('address')
    if not isinstance(address, str) or not address or address.startswith('-'):
        raise ScanError('invalid_address')
    try:
        ipaddress.ip_address(address)
    except ValueError:
        if not DNS_RE.fullmatch(address) or '..' in address or address.endswith('.'):
            raise ScanError('invalid_address') from None
    user = host.get('username')
    if not isinstance(user, str) or not USER_RE.fullmatch(user):
        raise ScanError('invalid_username')
    port = host.get('port', 22)
    if isinstance(port, bool) or not isinstance(port, int) or not 1 <= port <= 65535:
        raise ScanError('invalid_port')
    _private_regular_file(host.get('identity_file'), private=True)
    _private_regular_file(host.get('known_hosts_file'), private=False)
    return host


def _ssh_arguments(host, command):
    """Build argv without invoking a local shell or reading user SSH config."""
    return [
        '/usr/bin/ssh', '-F', '/dev/null', '-T', '-n',
        '-o', 'BatchMode=yes',
        '-o', 'StrictHostKeyChecking=yes',
        '-o', 'GlobalKnownHostsFile=/dev/null',
        '-o', 'UserKnownHostsFile=' + host['known_hosts_file'],
        '-o', 'IdentitiesOnly=yes',
        '-o', 'IdentityAgent=none',
        '-o', 'PasswordAuthentication=no',
        '-o', 'KbdInteractiveAuthentication=no',
        '-o', 'NumberOfPasswordPrompts=0',
        '-o', 'ClearAllForwardings=yes',
        '-o', 'PermitLocalCommand=no',
        '-o', 'ProxyCommand=none',
        '-o', 'ProxyJump=none',
        '-o', 'CanonicalizeHostname=no',
        '-o', 'UpdateHostKeys=no',
        '-o', 'ConnectTimeout=5',
        '-o', 'ConnectionAttempts=1',
        '-i', host['identity_file'], '-p', str(host.get('port', 22)),
        '-l', host['username'], host['address'], command,
    ]


def _collect_bounded(process):
    """Drain stdout and stderr with hard byte and time limits."""
    streams = selectors.DefaultSelector()
    buffers = {'stdout': bytearray(), 'stderr': bytearray()}
    for name in buffers:
        pipe = getattr(process, name)
        os.set_blocking(pipe.fileno(), False)
        streams.register(pipe, selectors.EVENT_READ, name)
    deadline = time.monotonic() + COMMAND_TIMEOUT
    try:
        while streams.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise ScanError('timeout')
            for key, _ in streams.select(min(remaining, 0.25)):
                chunk = os.read(key.fileobj.fileno(), 4096)
                if not chunk:
                    streams.unregister(key.fileobj)
                    continue
                buffers[key.data].extend(chunk)
                if sum(len(value) for value in buffers.values()) > MAX_OUTPUT_BYTES:
                    raise ScanError('output_limit')
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise ScanError('timeout')
        process.wait(timeout=remaining)
        return process.returncode, bytes(buffers['stdout']), bytes(buffers['stderr'])
    except subprocess.TimeoutExpired:
        raise ScanError('timeout') from None
    finally:
        streams.close()
        if process.poll() is None:
            process.kill()
            process.wait()
        for name in buffers:
            getattr(process, name).close()


def run_fixed_command(host, command):
    if command not in {item[1] for item in CHECKS}:
        raise ScanError('invalid_command')
    try:
        process = subprocess.Popen(
            _ssh_arguments(host, command), stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, close_fds=True,
            env={'PATH': '/usr/bin:/bin', 'LC_ALL': 'C'},
        )
    except OSError:
        raise ScanError('ssh_unavailable') from None
    code, output, error = _collect_bounded(process)
    if code == 255:
        message = error.lower()
        if b'host key verification failed' in message or b'remote host identification has changed' in message:
            raise ScanError('host_key_error')
        if b'permission denied' in message:
            raise ScanError('authentication_failed')
        if b'timed out' in message:
            raise ScanError('timeout')
        raise ScanError('connection_error')
    if b'sudo:' in error.lower() and (b'password' in error.lower() or b'not allowed' in error.lower()):
        raise ScanError('permission_denied')
    return code, output.decode('utf-8', 'replace').strip()


def _evaluate(check_id, code, output):
    """Only fixed, non-secret tokens may influence a result."""
    normalized = output.strip().lower()
    if check_id == 'non_root_login':
        if code == 0 and normalized.isdecimal():
            return 'fail' if int(normalized) == 0 else 'pass'
        return 'unknown'
    if check_id == 'auditd_active':
        if normalized == 'active':
            return 'pass'
        if normalized in {'inactive', 'failed', 'deactivating'}:
            return 'fail'
        return 'unknown'
    if check_id == 'time_synchronized':
        if code == 0 and normalized in {'yes', 'true'}:
            return 'pass'
        if code == 0 and normalized in {'no', 'false'}:
            return 'fail'
        return 'unknown'
    if check_id == 'sshd_config_permissions':
        match = re.fullmatch(r'([0-7]{3,4})\s+(\d+)\s+(\d+)', normalized)
        if code != 0 or not match:
            return 'unknown'
        mode = int(match.group(1), 8)
        return 'pass' if int(match.group(2)) == 0 and mode & ~0o640 == 0 and mode & 0o400 else 'fail'
    raise ScanError('invalid_command')


def _result(host_id, status, checks, error_code=None):
    result = {'source': SOURCE, 'host_id': host_id, 'rule_pack': RULE_PACK,
              'rule_pack_version': RULE_PACK_VERSION, 'status': status,
              'checks': checks}
    if error_code:
        result['error_code'] = error_code
    return result


def scan_host(host_id, host, runner=run_fixed_command, expected_address=None):
    """Pure result projection around an injectable fixed-command runner."""
    try:
        validate_host(host_id, host)
        if expected_address is not None and host['address'] != expected_address:
            raise ScanError('inventory_changed')
    except ScanError as exc:
        return _result(host_id, 'error' if exc.code != 'not_enabled' else 'unknown', [], exc.code)
    checks = []
    error_code = None
    for check_id, command in CHECKS:
        if error_code:
            checks.append({'id': check_id, 'status': 'unknown'})
            continue
        try:
            code, output = runner(host, command)
            status = _evaluate(check_id, code, output)
        except ScanError as exc:
            error_code = exc.code
            status = 'error'
        checks.append({'id': check_id, 'status': status})
    statuses = {check['status'] for check in checks}
    overall = next((state for state in ('error', 'fail', 'unknown') if state in statuses), 'pass')
    return _result(host_id, overall, checks, error_code)


def main(argv=None):
    parser = argparse.ArgumentParser(description='IOC read-only Linux audit scanner')
    parser.add_argument('--config', default=DEFAULT_CONFIG)
    parser.add_argument('--host-id', required=True)
    parser.add_argument('--expected-address', required=True)
    args = parser.parse_args(argv)
    try:
        hosts = load_config(args.config)
    except ScanError as exc:
        print(json.dumps(_result(args.host_id, 'error', [], exc.code), separators=(',', ':')))
        return 2
    host = hosts.get(args.host_id)
    if host is None:
        result = _result(args.host_id, 'error', [], 'not_enrolled')
    else:
        result = scan_host(args.host_id, host, expected_address=args.expected_address)
    print(json.dumps(result, separators=(',', ':'), sort_keys=True))
    return 0


if __name__ == '__main__':
    sys.exit(main())
