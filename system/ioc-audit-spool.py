#!/usr/bin/env python3
"""Publish only redacted scanner results from isolated service accounts."""

import argparse
from contextlib import closing
import importlib.util
import json
import os
from pathlib import Path
import re
import sqlite3
import sys
import time

ROOT = Path(__file__).resolve().parent
REQUESTS = Path('/var/lib/oracle-mon/ioc-audit-requests')
RESULTS = {
    'network': Path('/var/lib/oracle-mon/ioc-audit-network-results'),
    'linux': Path('/var/lib/oracle-mon/ioc-audit-linux-results'),
}


def scanner_module(kind):
    file = ROOT / ('ioc-audit-network.py' if kind == 'network' else 'ioc-audit-linux.py')
    spec = importlib.util.spec_from_file_location('ioc_audit_scanner_' + kind, file)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def safe_result_dir(path):
    info = path.lstat()
    if not path.is_dir() or path.is_symlink() or info.st_uid != os.geteuid() or info.st_mode & 0o027:
        raise RuntimeError('unsafe_result_directory')


def due(path, asset_id, interval):
    try:
        last = path.stat().st_mtime if path.is_file() and not path.is_symlink() else 0
    except OSError:
        last = 0
    try:
        request = (REQUESTS / f'{asset_id}.request').stat().st_mtime
    except OSError:
        request = 0
    return last == 0 or time.time() - last >= interval or request > last


def publish(path, record):
    data = json.dumps(record, separators=(',', ':'), sort_keys=True).encode('utf-8')
    if len(data) > 65536:
        raise RuntimeError('result_limit')
    temp = path.parent / f'.{path.name}.{os.getpid()}.tmp'
    descriptor = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
    try:
        with os.fdopen(descriptor, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp, path)
    finally:
        if temp.exists():
            temp.unlink()


def network_targets(scanner):
    db_path = Path('/var/lib/oracle-mon/config-backup/state.sqlite3')
    with closing(sqlite3.connect(db_path.as_uri() + '?mode=ro', uri=True, timeout=3)) as db:
        row = db.execute("SELECT value FROM meta WHERE key='settings'").fetchone()
        settings = json.loads(row[0]) if row else {}
    return sorted({(int(item['device_id']), str(item['host'])) for item in settings.get('devices', [])
                   if isinstance(item, dict) and type(item.get('device_id')) is int
                   and item['device_id'] > 0 and isinstance(item.get('host'), str)})


def run(kind):
    os.umask(0o027)
    output_dir = RESULTS[kind]
    safe_result_dir(output_dir)
    scanner = scanner_module(kind)
    if kind == 'network':
        targets = network_targets(scanner)
        interval = 1800
    else:
        try:
            hosts = scanner.load_config(scanner.DEFAULT_CONFIG)
        except scanner.ScanError:
            return 0  # Unenrolled LibreNMS assets remain visible as pending.
        targets = [(int(key), host) for key, host in hosts.items()
                   if re.fullmatch(r'[1-9][0-9]{0,9}', key)]
        targets.sort()
        interval = 86400
    processed = 0
    for asset_id, host in targets:
        path = output_dir / f'{asset_id}.json'
        if not due(path, asset_id, interval):
            continue
        if kind == 'network':
            version = scanner.latest_version_id('/var/lib/oracle-mon/config-backup/state.sqlite3', asset_id)
            result = scanner.scan_version('/var/lib/oracle-mon/config-backup/state.sqlite3', asset_id, version) if version else scanner._result(asset_id, None, error_code='not_found')
            record = {'asset_id': asset_id, 'kind': kind, 'address': host,
                      'captured_at': int(time.time()), 'result': result}
        else:
            result = scanner.scan_host(str(asset_id), host)
            result['host_id'] = asset_id
            record = {'asset_id': asset_id, 'kind': kind, 'address': host.get('address'),
                      'captured_at': int(time.time()), 'result': result}
        publish(path, record)
        processed += 1
        if processed >= (3 if kind == 'linux' else 4):
            break
    return 0


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--kind', choices=tuple(RESULTS), required=True)
    args = parser.parse_args()
    try:
        sys.exit(run(args.kind))
    except (OSError, ValueError, sqlite3.Error, RuntimeError):
        print('IOC audit source unavailable', file=sys.stderr)
        sys.exit(2)
