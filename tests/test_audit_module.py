"""稽核模組的部署、安全邊界與歷程保留契約；PHP 邏輯另由 tests/php 驗證。"""
import re
import unittest
from helpers import ROOT


class AuditModuleTests(unittest.TestCase):
    def test_viewer_api_only_allows_summary(self):
        source = (ROOT / 'librenms/html/ioc-audit.php').read_text(encoding='utf-8')
        self.assertLess(source.index('ioc_begin(true)'), source.index('new \\Ioc\\AuditEvidenceService'))
        self.assertIn("if ($action !== 'summary')", source)
        self.assertIn('Access::requireHandler', source)
        self.assertIn('ioc_evidence($case, false)', source)

    def test_download_is_admin_only_and_rechecks_hash(self):
        endpoint = (ROOT / 'librenms/html/ioc-audit-download.php').read_text(encoding='utf-8')
        store = (ROOT / 'librenms/ioc/AuditSnapshotStore.php').read_text(encoding='utf-8')
        self.assertLess(endpoint.index('ioc_begin(true)'), endpoint.index('$svc->download('))
        self.assertIn('Access::requireHandler', endpoint)
        self.assertIn('hash_equals($digest, hash(\'sha256\', $content))', store)
        self.assertIn('is_link($path)', store)

    def test_history_is_append_only_and_private_store_installed(self):
        service = (ROOT / 'librenms/ioc/AuditEvidenceService.php').read_text(encoding='utf-8')
        self.assertNotRegex(service, r'(?:UPDATE|DELETE FROM) ioc_audit_(?:assessment_history|remediation_history|events)')
        for script in ('install.sh', 'update.sh'):
            source = (ROOT / script).read_text(encoding='utf-8')
            self.assertIn('install -d -m 700 -o "$LIBRENMS_USER"', source)
            self.assertIn('/var/lib/oracle-mon/ioc-evidence', source)
