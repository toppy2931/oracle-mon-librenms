"""Isolated result spool contains only bounded, redacted scanner output."""
import json
import os
from pathlib import Path
import tempfile
import time
import unittest
from unittest.mock import patch

from helpers import load_module


spool = load_module('ioc_audit_spool', 'system/ioc-audit-spool.py')


class SpoolTests(unittest.TestCase):
    def test_publish_is_atomic_and_bounded(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            path = Path(directory) / '7.json'
            record = {'asset_id': 7, 'result': {'checks': [{'id': 'safe', 'status': 'pass'}]}}
            spool.publish(path, record)
            self.assertEqual(record, json.loads(path.read_text()))
            self.assertEqual([], list(Path(directory).glob('*.tmp')))
            with self.assertRaises(RuntimeError):
                spool.publish(path, {'secret': 'x' * 65537})
            self.assertEqual(record, json.loads(path.read_text()))

    def test_request_newer_than_result_triggers_retry(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            root = Path(directory)
            result = root / '7.json'
            result.write_text('{}')
            request = root / '7.request'
            request.touch()
            now = time.time()
            os.utime(result, (now - 20, now - 20))
            with patch.object(spool, 'REQUESTS', root):
                self.assertTrue(spool.due(result, 7, 86400))
                os.utime(result, (now, now))
                self.assertFalse(spool.due(result, 7, 86400))

    @unittest.skipUnless(os.name == 'posix', 'POSIX ownership and modes required')
    def test_spool_directory_rejects_group_write(self):
        with tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent) as directory:
            path = Path(directory)
            path.chmod(0o770)
            with self.assertRaises(RuntimeError):
                spool.safe_result_dir(path)
            path.chmod(0o750)
            spool.safe_result_dir(path)


if __name__ == '__main__':
    unittest.main()
