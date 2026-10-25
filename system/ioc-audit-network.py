#!/usr/bin/env python3
"""Read-only, bounded technical observations from verified FortiOS backups.

The JSON result deliberately contains no configuration excerpts, interface names,
addresses, credentials, or device names. A finding is not an audit assessment.
"""

import argparse
from contextlib import closing
import hashlib
import json
from pathlib import Path
import re
import sqlite3
import sys


RULE_PACK = 'fortios_network_baseline'
RULE_PACK_VERSION = 1
MAX_CONFIG_BYTES = 2 * 1024 * 1024
CHECK_IDS = (
    'fortios.interface_no_plaintext_management',
    'fortios.admin_no_explicit_wildcard_trusthost',
)


def _result(device_id, version_id, digest=None, status='error', checks=(), error_code=None, size=0):
    result = {
        'schema': 1,
        'source': 'config_backup',
        'rule_pack': RULE_PACK,
        'rule_pack_version': RULE_PACK_VERSION,
        'device_id': device_id,
        'version_id': version_id,
        'digest': digest,
        'status': status,
        'checks': list(checks),
        'bytes': size,
    }
    if error_code:
        result['error_code'] = error_code
    return result


def _parse_config(content):
    """Yield the small allowlist of configuration facts needed by this pack.

FortiOS `show` and native exports are nested. Ignore values outside the exact
section being checked so identical words in banners/comments cannot cause a
finding. A balanced final `end` is required before scanning a legacy version.
    """
    scopes = []
    edits = []
    facts = {'allowaccess': [], 'trusthosts': []}
    top_level = 0
    last_command = None
    for raw in content.splitlines():
        line = raw.strip()
        if not line or line.startswith('#'):
            continue
        if line.startswith('config '):
            if not scopes:
                top_level += 1
            scopes.append(line[7:].strip().lower())
            edits.append(False)
            last_command = 'config'
        elif line == 'end':
            if not scopes:
                raise ValueError('invalid structure')
            scopes.pop()
            edits.pop()
            last_command = 'end'
        elif line.startswith('edit '):
            if not scopes or edits[-1]:
                raise ValueError('invalid structure')
            edits[-1] = True
            last_command = 'edit'
        elif line == 'next':
            if not scopes or not edits[-1]:
                raise ValueError('invalid structure')
            edits[-1] = False
            last_command = 'next'
        elif line.startswith('set '):
            if not scopes:
                raise ValueError('invalid structure')
            if scopes == ['system interface'] and edits[-1]:
                match = re.fullmatch(r'set\s+allowaccess\s+(.+)', line, re.I)
                if match:
                    facts['allowaccess'].append(set(match.group(1).lower().replace('"', '').split()))
            elif scopes == ['system admin'] and edits[-1]:
                match = re.fullmatch(r'set\s+trusthost[1-9]\s+(.+)', line, re.I)
                if match:
                    facts['trusthosts'].append(match.group(1).lower().replace('"', '').split())
            last_command = 'set'
        else:
            # FortiOS may also emit unset/append/execute commands. They are
            # outside this pack's allowlist and never interpreted as success.
            last_command = 'other'
    if scopes or top_level == 0 or last_command != 'end':
        raise ValueError('invalid structure')
    return facts


def scan_config(content):
    """Pure, conservative scan; return result state and safe check identifiers.

`pass` means an explicit setting passed this technical check, not that an
entire device or any external control is compliant. Missing settings are
unknown because FortiOS `show` may omit defaults.
    """
    facts = _parse_config(content)
    management = facts['allowaccess']
    if any({'http', 'telnet'} & values for values in management):
        management_status = 'fail'
    elif management:
        management_status = 'pass'
    else:
        management_status = 'unknown'

    trusthosts = facts['trusthosts']
    if any(values and values[0] in {'0.0.0.0', '::', '::/0', '0:0:0:0:0:0:0:0'}
           and (len(values) == 1 or values[1] in {'0.0.0.0', '0', '::', '::/0'})
           for values in trusthosts):
        trusted_status = 'fail'
    elif trusthosts:
        trusted_status = 'pass'
    else:
        trusted_status = 'unknown'

    checks = [
        {'id': CHECK_IDS[0], 'status': management_status},
        {'id': CHECK_IDS[1], 'status': trusted_status},
    ]
    status = 'fail' if any(check['status'] == 'fail' for check in checks) else (
        'unknown' if any(check['status'] == 'unknown' for check in checks) else 'pass')
    return status, checks


def scan_version(db_path, device_id, version_id):
    """Read exactly one configured FortiOS version from backup SQLite."""
    if type(device_id) is not int or type(version_id) is not int or device_id < 1 or version_id < 1:
        raise ValueError('positive identifiers required')
    db_path = Path(db_path).absolute()
    if not db_path.is_file() or db_path.is_symlink():
        return _result(device_id, version_id, error_code='database_unavailable')
    try:
        with closing(sqlite3.connect(db_path.as_uri() + '?mode=ro', uri=True, timeout=3)) as db:
            row = db.execute("SELECT value FROM meta WHERE key='settings'").fetchone()
            settings = json.loads(row[0]) if row else {}
            devices = settings.get('devices', []) if isinstance(settings, dict) else []
            device = next((d for d in devices if isinstance(d, dict) and d.get('device_id') == device_id), None)
            if device is None:
                return _result(device_id, version_id, error_code='not_found')
            if device.get('model') != 'fortios':
                return _result(device_id, version_id, status='unsupported')
            row = db.execute(
                'SELECT digest,content FROM versions WHERE device_id=? AND id=?',
                (device_id, version_id),
            ).fetchone()
            if row is None:
                return _result(device_id, version_id, error_code='not_found')
            digest, content = row
            verified_run = db.execute(
                "SELECT 1 FROM runs WHERE device_id=? AND version_id=? AND status IN ('success','unchanged') LIMIT 1",
                (device_id, version_id),
            ).fetchone()
    except (OSError, sqlite3.Error, ValueError, TypeError, json.JSONDecodeError):
        return _result(device_id, version_id, error_code='database_unavailable')

    if not verified_run or not isinstance(content, str) or not isinstance(digest, str):
        return _result(device_id, version_id, error_code='unverified_version')
    encoded = content.encode('utf-8')
    if not encoded or len(encoded) > MAX_CONFIG_BYTES or b'\x00' in encoded or b'--More--' in encoded:
        return _result(device_id, version_id, error_code='unverified_version')
    if not re.fullmatch(r'[0-9a-f]{64}', digest) or hashlib.sha256(encoded).hexdigest() != digest:
        return _result(device_id, version_id, error_code='integrity_error')
    try:
        status, checks = scan_config(content)
    except (ValueError, UnicodeError):
        return _result(device_id, version_id, digest=digest, error_code='invalid_structure')
    return _result(device_id, version_id, digest=digest, status=status, checks=checks, size=len(encoded))


def latest_version_id(db_path, device_id):
    """Resolve a completed backup version without exposing configuration text."""
    db_path = Path(db_path).absolute()
    if not db_path.is_file() or db_path.is_symlink():
        return None
    try:
        with closing(sqlite3.connect(db_path.as_uri() + '?mode=ro', uri=True, timeout=3)) as db:
            row = db.execute('''
                SELECT v.id FROM versions AS v
                WHERE v.device_id=? AND EXISTS (
                    SELECT 1 FROM runs AS r WHERE r.device_id=v.device_id
                    AND r.version_id=v.id AND r.status IN ('success','unchanged'))
                ORDER BY v.id DESC LIMIT 1
            ''', (device_id,)).fetchone()
    except (OSError, sqlite3.Error):
        return None
    return int(row[0]) if row else None


def main(argv=None):
    parser = argparse.ArgumentParser(description='Scan one verified FortiOS backup version')
    parser.add_argument('--db', default='/var/lib/oracle-mon/config-backup/state.sqlite3')
    parser.add_argument('--device-id', type=int, required=True)
    version = parser.add_mutually_exclusive_group(required=True)
    version.add_argument('--version-id', type=int)
    version.add_argument('--latest', action='store_true')
    args = parser.parse_args(argv)
    try:
        version_id = latest_version_id(args.db, args.device_id) if args.latest else args.version_id
        if version_id is None:
            result = _result(args.device_id, None, error_code='not_found')
        else:
            result = scan_version(args.db, args.device_id, version_id)
    except ValueError:
        result = _result(args.device_id, args.version_id, error_code='invalid_request')
    print(json.dumps(result, ensure_ascii=False, separators=(',', ':')))
    return 0


if __name__ == '__main__':
    sys.exit(main())
