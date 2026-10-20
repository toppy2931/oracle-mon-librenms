"""system/graylog-reader.py 的測試（對假的 Graylog）。執行：python -m unittest discover -s tests"""
import io
import os
import stat
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from unittest import mock

from helpers import FakeGraylog, load_module

gr = load_module("graylog_reader", "system/graylog-reader.py")
gp = gr.gp
DEFAULT = gp.DEFAULT_STREAM_ID
FW = "6a33ef93a68ab16ef006f414"
CONFIG_KEYS = ("GRAYLOG_URL", "GRAYLOG_USER", "GRAYLOG_PASS", "GRAYLOG_CA_FILE", "GRAYLOG_STREAM_ID", "GRAYLOG_FIREWALL_STREAMS")


class ReaderTests(unittest.TestCase):
    def setUp(self):
        self.gl = FakeGraylog()
        self.addCleanup(self.gl.close)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.conf = os.path.join(self.tmp.name, "graylog-reader.conf")
        self.env = {"GRAYLOG_API_CONF": "/nonexistent", "GRAYLOG_URL": self.gl.url, "GRAYLOG_USER": "admin",
                    "GRAYLOG_PASS": "secret", "GRAYLOG_READER_CONF": self.conf, "GRAYLOG_FIREWALL_STREAMS": "FortiGate Syslog"}

    def run_cmd(self, cmd, **extra_env):
        out, err = io.StringIO(), io.StringIO()
        full = dict.fromkeys(CONFIG_KEYS, "")
        full.update(self.env)
        full.update(extra_env)
        with mock.patch.dict(os.environ, full, clear=False), redirect_stdout(out), redirect_stderr(err):
            code = gr.main([cmd])
        return code, out.getvalue(), err.getvalue()

    def token_in_conf(self):
        return gp.parse_conf(self.conf).get("GRAYLOG_TOKEN")

    def test_create_makes_reader_user_token_and_conf(self):
        code, out, err = self.run_cmd("create")
        self.assertEqual(code, 0, out + err)
        user = self.gl.users["ioc-reader"]
        self.assertEqual(user["roles"], ["Reader"])
        self.assertIn("streams:read:" + DEFAULT, user["permissions"])
        self.assertIn("streams:read:" + FW, user["permissions"])
        conf = gp.parse_conf(self.conf)
        self.assertEqual(conf["GRAYLOG_URL"], self.gl.url)
        self.assertEqual(conf["GRAYLOG_STREAMS"], "%s,%s" % (DEFAULT, FW))
        self.assertTrue(conf["GRAYLOG_TOKEN"])

    def test_token_never_appears_in_output(self):
        code, out, err = self.run_cmd("create")
        token = self.token_in_conf()
        self.assertTrue(token)
        self.assertNotIn(token, out + err)

    @unittest.skipUnless(os.name == "posix", "檔案權限只在 POSIX 上檢查")
    def test_conf_is_not_world_readable(self):
        self.run_cmd("create")
        mode = stat.S_IMODE(os.stat(self.conf).st_mode)
        self.assertEqual(mode & 0o007, 0, oct(mode))

    def test_the_new_token_really_works_with_the_portal_style_login(self):
        """入口用 Token 登入並查詢；不能是 admin 才查得到。"""
        self.run_cmd("create")
        client = gp.Graylog(self.gl.url, self.token_in_conf(), "token")
        body = {"query": "_exists_:source", "streams": [DEFAULT, FW], "timerange": {"type": "relative", "range": 60},
                "group_by": [{"field": "source", "limit": 1}], "metrics": [{"function": "count"}]}
        self.assertIn("datarows", client.call("POST", "/api/search/aggregate", body))
        with self.assertRaises(gp.ApiError) as ctx:                      # 唯讀帳號不能管理使用者
            client.call("GET", "/api/users/ioc-reader")
        self.assertEqual(ctx.exception.status, 403)

    def test_second_create_changes_nothing(self):
        self.run_cmd("create")
        before = self.token_in_conf()
        self.gl.requests.clear()
        code, out, _ = self.run_cmd("create")
        self.assertEqual(code, 0)
        self.assertIn("無需變更", out)
        self.assertEqual(self.token_in_conf(), before)
        self.assertEqual([m for m in self.gl.mutations() if "tokens" in m[1] or m[0] in ("POST", "PUT", "DELETE") and "/api/users" in m[1]], [])

    def test_new_stream_in_config_adds_permission_and_keeps_the_same_token(self):
        """stream 清單變動只補權限、更新設定檔，不換 Token：舊 Token 不會自動作廢，換了就留下沒人記得的有效憑證。"""
        self.run_cmd("create", GRAYLOG_FIREWALL_STREAMS="")
        self.assertNotIn("streams:read:" + FW, self.gl.users["ioc-reader"]["permissions"])
        before = self.token_in_conf()
        self.gl.requests.clear()
        code, out, _ = self.run_cmd("create")                              # 設定檔多了 FortiGate Syslog
        self.assertEqual(code, 0, out)
        self.assertIn("streams:read:" + FW, self.gl.users["ioc-reader"]["permissions"])
        self.assertIn("users:edit:ioc-reader", self.gl.users["ioc-reader"]["permissions"], "Graylog 自己加的預設權限要保留")
        self.assertEqual(gp.parse_conf(self.conf)["GRAYLOG_STREAMS"], "%s,%s" % (DEFAULT, FW))
        self.assertEqual(self.token_in_conf(), before, "Token 不該換")
        self.assertEqual(len(self.gl.users["ioc-reader"]["tokens"]), 1, "不能多出沒人記得的 Token")
        self.assertEqual([m for m in self.gl.mutations() if "tokens" in m[1]], [])
        # 換過清單後的 Token 真的能查新 stream
        client = gp.Graylog(self.gl.url, self.token_in_conf(), "token")
        body = {"query": "_exists_:source", "streams": [FW], "timerange": {"type": "relative", "range": 60},
                "group_by": [{"field": "source", "limit": 1}], "metrics": [{"function": "count"}]}
        self.assertIn("datarows", client.call("POST", "/api/search/aggregate", body))

    @unittest.skipUnless(os.name == "posix", "chown／群組只在 POSIX 上有意義")
    def test_group_problem_is_reported_not_swallowed(self):
        """以 root 執行但找不到 librenms 群組：設定檔 PHP-FPM 讀不到，必須明確警告，不能只說成功。"""
        with mock.patch.object(gr.os, "geteuid", return_value=0, create=True), \
                mock.patch("grp.getgrnam", side_effect=KeyError("librenms")), \
                mock.patch.object(gr.os, "chown", create=True) as chown:
            code, out, _ = self.run_cmd("create")
        self.assertEqual(code, 0, out)
        self.assertIn("警告", out)
        self.assertIn("librenms", out)
        chown.assert_not_called()
        self.assertTrue(os.path.exists(self.conf), "設定檔仍然寫了，只是警告管理者要自己處理群組")

    def test_revoked_token_is_replaced(self):
        self.run_cmd("create")
        old = self.token_in_conf()
        user = self.gl.users["ioc-reader"]
        for t in list(user["tokens"].values()):                            # 有人在 Graylog 把 Token 刪了
            self.gl.token_auth.pop(self.gl._token_header(t), None)
        code, out, _ = self.run_cmd("create")
        self.assertEqual(code, 0, out)
        self.assertNotEqual(self.token_in_conf(), old)

    def test_failed_verification_writes_no_conf_and_deletes_the_token(self):
        self.gl.overrides[("POST", "/api/search/aggregate")] = (403, {"message": "forbidden"})
        code, out, _ = self.run_cmd("create")
        self.assertEqual(code, 1)
        self.assertIn("驗證失敗", out)
        self.assertIn("權限", out)
        self.assertFalse(os.path.exists(self.conf))
        self.assertEqual(self.gl.users["ioc-reader"]["tokens"], {}, "驗證不過的 Token 不能留著")

    def test_unknown_stream_name_fails_before_creating_anything(self):
        code, _, err = self.run_cmd("create", GRAYLOG_FIREWALL_STREAMS="FortiGate Sylog")
        self.assertEqual(code, 1)
        self.assertIn("找不到 stream", err)
        self.assertEqual(self.gl.users, {})
        self.assertFalse(os.path.exists(self.conf))

    def test_status_is_read_only_and_reports(self):
        code, out, _ = self.run_cmd("status")
        self.assertEqual(code, 0)
        self.assertIn("不存在", out)
        self.assertEqual(self.gl.mutations(), [])
        self.run_cmd("create")
        self.gl.requests.clear()
        code, out, _ = self.run_cmd("status")
        self.assertIn("存在（角色 Reader）", out)
        self.assertEqual(self.gl.mutations(), [])

    def test_remove_deletes_user_and_conf_and_is_repeatable(self):
        self.run_cmd("create")
        token_header = self.gl._token_header(self.token_in_conf())
        code, out, _ = self.run_cmd("remove")
        self.assertEqual(code, 0, out)
        self.assertEqual(self.gl.users, {})
        self.assertFalse(os.path.exists(self.conf))
        self.assertNotIn(token_header, self.gl.token_auth, "刪帳號後 Token 要失效")
        code, out, _ = self.run_cmd("remove")
        self.assertEqual(code, 0)
        self.assertIn("本來就不存在", out)

    def test_missing_admin_credentials_exit_2(self):
        code, _, err = self.run_cmd("create", GRAYLOG_USER="", GRAYLOG_PASS="")
        self.assertEqual(code, 2)
        self.assertIn("GRAYLOG_USER", err)
        self.assertEqual(self.gl.requests, [])


if __name__ == "__main__":
    unittest.main()
