"""ioc-behavior.service 的沙箱設定：ProtectSystem=strict 之下，只開放 LibreNMS 設定快取目錄可寫。

這支服務每分鐘用 librenms 身分啟動 LibreNMS（bootstrap）再跑採集；LibreNMS 啟動時會把設定快取寫進
storage/framework/cache。沙箱讓整個檔案系統唯讀，不開放就會每 5 分鐘（快取過期時）出現
`file_put_contents(...): Read-only file system`（monitor-vm 實測，2026-10-26）。
"""
import unittest

from helpers import ROOT

CACHE_DIR = "/opt/librenms/storage/framework/cache"


def service_section(relpath):
    """[Service] 區段的 key → 值清單（systemd 允許同一個 key 重複，所以值是清單）。"""
    out, in_service = {}, False
    for raw in (ROOT / relpath).read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith(("#", ";")):
            continue
        if line.startswith("["):
            in_service = line == "[Service]"
            continue
        if in_service and "=" in line:
            key, _, value = line.partition("=")
            out.setdefault(key.strip(), []).append(value.strip())
    return out


class BehaviorServiceSandboxTests(unittest.TestCase):
    def setUp(self):
        self.svc = service_section("system/ioc-behavior.service")

    def test_only_the_librenms_cache_directory_is_writable(self):
        paths = " ".join(self.svc.get("ReadWritePaths", [])).split()
        self.assertEqual([p.lstrip("-") for p in paths], [CACHE_DIR],
                         "只能開放 LibreNMS 設定快取目錄；放寬成 /opt/librenms 或整個 storage 會讓被攻陷的採集程式能改 LibreNMS 的程式與紀錄")

    def test_a_missing_cache_directory_does_not_stop_the_service(self):
        """沒有 `-` 前綴時，目錄不存在會讓 systemd 建不起沙箱（status=226/NAMESPACE），整個採集每分鐘失敗，比一條警告嚴重得多。"""
        self.assertEqual(self.svc["ReadWritePaths"], ["-" + CACHE_DIR])

    def test_cache_directory_is_under_the_librenms_dir_the_unit_declares(self):
        env = " ".join(self.svc.get("Environment", []))
        self.assertIn("LIBRENMS_DIR=/opt/librenms", env.split(), "LIBRENMS_DIR 與可寫路徑要指向同一個 LibreNMS")
        self.assertTrue(CACHE_DIR.startswith("/opt/librenms/"))

    def test_the_rest_of_the_hardening_is_kept(self):
        for key, value in (("ProtectSystem", "strict"), ("ProtectHome", "yes"), ("PrivateTmp", "yes"),
                           ("PrivateDevices", "yes"), ("NoNewPrivileges", "yes"), ("CapabilityBoundingSet", ""),
                           ("User", "librenms")):
            self.assertEqual(self.svc.get(key), [value], "%s 不能被改掉" % key)

    def test_installer_deploys_the_unit_and_reloads_systemd(self):
        """單元檔改了要讓 systemd 重新讀取，下一輪 tick 才會套用新的沙箱設定。"""
        src = (ROOT / "system" / "install-behavior.sh").read_text(encoding="utf-8")
        self.assertIn("ioc-behavior.service", src)
        self.assertLess(src.index('"/etc/systemd/system/$unit"'), src.index("systemctl daemon-reload"))


if __name__ == "__main__":
    unittest.main()
