"""tools/replay-sample-logs.py 與 system/ioc-preflight.sh 的測試。執行：python -m unittest discover -s tests"""
import base64
import gzip
import json
import os
import shutil
import socket
import subprocess
import tempfile
import threading
import unittest
from contextlib import redirect_stderr, redirect_stdout
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import io

from helpers import ROOT, load_module

replay = load_module("replay", "tools/replay-sample-logs.py")
gp = load_module("graylog_pipelines", "system/graylog-pipelines.py")
SAMPLES = ROOT / "tests" / "fixtures" / "sample-logs.jsonl"


def quiet_main(argv):
    """跑 replay.main() 並吞掉它的輸出，保持測試報告乾淨。"""
    with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
        return replay.main(argv)


class SampleFixtureTests(unittest.TestCase):
    def setUp(self):
        self.samples = replay.load_samples(SAMPLES)

    def test_fixture_is_not_empty_and_covers_all_log_types(self):
        types = {s["expect"].get("log_type") for s in self.samples}
        self.assertEqual(types, {"windows", "linux", "firewall", "network"})

    def test_expected_fields_are_standard_fields(self):
        for s in self.samples:
            for k in s["expect"]:
                self.assertTrue(k in gp.STANDARD_FIELDS or k == "note", k)

    def test_windows_expectations_match_rule_table(self):
        """樣本預期值不得和 WIN_EVENTS 脫鉤。"""
        table = {eid: (action, sev) for eid, action, sev, _, _ in gp.WIN_EVENTS}
        checked = 0
        for s in self.samples:
            eid = str(s.get("winlog_event_id", s.get("EventID", "")))
            if not eid:
                continue
            action, sev = table[eid]
            self.assertEqual(s["expect"]["event_action"], action, eid)
            if "severity" in s["expect"]:
                self.assertEqual(s["expect"]["severity"], sev, eid)
            checked += 1
        self.assertGreaterEqual(checked, 5)

    def test_non_windows_actions_exist_in_rules(self):
        known = {a for _, _, a, _, _ in gp.LINUX_EVENTS} | {a for _, _, _, a, _, _ in gp.NET_EVENTS} | {"fw_deny", "fw_allow"}
        for s in self.samples:
            if s["expect"]["log_type"] != "windows":
                self.assertIn(s["expect"]["event_action"], known)


class ReplayTests(unittest.TestCase):
    def test_gelf_conversion(self):
        g = replay.to_gelf({"host": "h", "short_message": "m", "act": "deny", "level": 3, "expect": {"x": 1}}, 100.0)
        self.assertEqual(g["version"], "1.1")
        self.assertEqual(g["host"], "h")
        self.assertEqual(g["_act"], "deny")          # 自訂欄位加底線
        self.assertEqual(g["level"], 3)              # GELF 標準欄位不加
        self.assertEqual(g["_ioc_demo"], "true")     # 測試資料標記
        self.assertNotIn("_expect", g)
        self.assertNotIn("expect", g)

    def test_udp_replay_sends_gzipped_gelf_marked_as_demo(self):
        srv = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        srv.bind(("127.0.0.1", 0))
        srv.settimeout(5)
        self.addCleanup(srv.close)
        port = srv.getsockname()[1]
        code = quiet_main(["--target", "127.0.0.1:%d" % port, "--delay", "0"])
        self.assertEqual(code, 0)
        got = [json.loads(gzip.decompress(srv.recvfrom(65535)[0])) for _ in replay.load_samples(SAMPLES)]
        self.assertEqual(len(got), len(replay.load_samples(SAMPLES)))
        self.assertTrue(all(m["_ioc_demo"] == "true" for m in got))
        self.assertTrue(all("_expect" not in m and "expect" not in m for m in got))

    def test_tcp_replay_uses_null_delimiter(self):
        srv = socket.socket()
        srv.bind(("127.0.0.1", 0))
        srv.listen(1)
        srv.settimeout(5)
        self.addCleanup(srv.close)
        chunks = []

        def serve():
            conn, _ = srv.accept()
            with conn:
                while True:
                    data = conn.recv(65535)
                    if not data:
                        break
                    chunks.append(data)

        t = threading.Thread(target=serve)
        t.start()
        quiet_main(["--target", "127.0.0.1:%d" % srv.getsockname()[1], "--proto", "tcp", "--delay", "0"])
        t.join(5)
        parts = b"".join(chunks).split(b"\0")
        self.assertEqual(parts[-1], b"")
        msgs = [json.loads(p) for p in parts[:-1]]
        self.assertEqual(len(msgs), len(replay.load_samples(SAMPLES)))

    def test_target_is_required(self):
        with self.assertRaises(SystemExit):
            quiet_main([])

    def test_bad_target_is_rejected(self):
        with self.assertRaises(SystemExit):
            quiet_main(["--target", "no-port"])


class FakeApi:
    """只回應 /api/system/lbstatus 與 /api/system（要求特定帳密）。"""

    def __init__(self, accepted_password):
        self.accepted = "Basic " + base64.b64encode(("admin:%s" % accepted_password).encode()).decode()
        fake = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_GET(self):
                if self.path == "/api/system/lbstatus":
                    code = 200
                elif self.path == "/api/system":
                    code = 200 if self.headers.get("Authorization") == fake.accepted else 401
                else:
                    code = 404
                self.send_response(code)
                self.send_header("Content-Length", "0")
                self.end_headers()

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.url = "http://127.0.0.1:%d" % self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()


def find_bash():
    """Windows 上 subprocess 會優先挑 System32 的 WSL 啟動器（環境變數傳不進去、輸出被轉碼），
    所以自己走 PATH 找，並排除 WSL / WindowsApps 的 bash。"""
    for d in os.environ.get("PATH", "").split(os.pathsep):
        low = d.lower()
        if "system32" in low or "windowsapps" in low:
            continue
        for name in ("bash.exe", "bash"):
            p = os.path.join(d, name)
            if os.path.isfile(p):
                return p
    return None


BASH = find_bash()


@unittest.skipUnless(BASH and shutil.which("curl"), "需要 bash（非 WSL）與 curl")
class PreflightTests(unittest.TestCase):
    FACTORY = "Graylog@2026!"

    def run_preflight(self, url, app_url="https://librenms.example"):
        with tempfile.TemporaryDirectory() as d:
            d = d.replace("\\", "/")
            with open(os.path.join(d, ".env"), "w") as f:
                f.write("APP_URL=%s\n" % app_url)
            env = dict(os.environ, GRAYLOG_URL=url, GRAYLOG_API_CONF=d + "/none.conf",
                       LIBRENMS_DIR=d, GRAYLOG_USER="", GRAYLOG_PASS="")
            env.pop("GRAYLOG_CA_FILE", None)
            p = subprocess.run([BASH, "system/ioc-preflight.sh"], cwd=str(ROOT), env=env,
                               capture_output=True, text=True, encoding="utf-8", timeout=60)
            return p.returncode, p.stdout

    def test_factory_password_still_valid_fails(self):
        api = FakeApi(self.FACTORY)
        self.addCleanup(api.close)
        rc, out = self.run_preflight(api.url)
        self.assertEqual(rc, 1, out)
        self.assertIn("[FAIL] IS-051", out)

    def test_changed_password_passes_and_http_only_warns(self):
        api = FakeApi("some-other-password")
        self.addCleanup(api.close)
        rc, out = self.run_preflight(api.url)
        self.assertEqual(rc, 0, out)
        self.assertIn("[PASS] IS-051", out)
        self.assertIn("[WARN] IS-041 Graylog API 目前是 HTTP", out)
        self.assertIn("[PASS] IS-041 LibreNMS APP_URL 使用 HTTPS", out)

    def test_unreachable_graylog_skips_instead_of_failing(self):
        s = socket.socket()
        s.bind(("127.0.0.1", 0))
        port = s.getsockname()[1]
        s.close()
        rc, out = self.run_preflight("http://127.0.0.1:%d" % port)
        self.assertEqual(rc, 0, out)
        self.assertIn("[SKIP] 連不上 Graylog", out)
        self.assertNotIn("[FAIL]", out)

    def test_http_librenms_warns(self):
        api = FakeApi("x")
        self.addCleanup(api.close)
        _, out = self.run_preflight(api.url, app_url="http://10.0.0.1")
        self.assertIn("[WARN] IS-041 LibreNMS APP_URL 目前是 HTTP", out)


if __name__ == "__main__":
    unittest.main()
