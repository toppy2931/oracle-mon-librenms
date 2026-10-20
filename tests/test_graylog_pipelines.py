"""system/graylog-pipelines.py 的測試。執行：python -m unittest discover -s tests"""
import io
import os
import re
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from unittest import mock

from helpers import ROOT, FakeGraylog, load_module

gp = load_module("graylog_pipelines", "system/graylog-pipelines.py")
STREAM = gp.DEFAULT_STREAM_ID


def run(argv, env):
    """以指定環境變數跑 main()，回傳 (退出碼, stdout, stderr)。"""
    out, err = io.StringIO(), io.StringIO()
    with mock.patch.dict(os.environ, env, clear=False), redirect_stdout(out), redirect_stderr(err):
        # reconfigure 在 StringIO 上不存在，main() 會自行略過
        code = gp.main(argv)
    return code, out.getvalue(), err.getvalue()


class RuleGenerationTests(unittest.TestCase):
    def test_lint_passes_for_all_rules(self):
        self.assertEqual(gp.lint_all(), [])

    def test_every_windows_event_has_exactly_one_rule(self):
        titles = [t for t, _ in gp.windows_rules()]
        for eid, *_ in gp.WIN_EVENTS:
            self.assertEqual(titles.count("ioc-win-" + eid), 1, eid)
        self.assertEqual(len(titles), len(set(titles)))

    def test_rule_titles_are_unique_and_prefixed(self):
        titles = [t for rules in gp.pipelines().values() for t, _ in rules]
        self.assertEqual(len(titles), len(set(titles)))
        self.assertTrue(all(t.startswith(gp.RULE_PREFIX) for t in titles))

    def test_pipeline_source_references_all_its_rules(self):
        for title, rules in gp.pipelines().items():
            src = gp.pipeline_source(title, rules)
            self.assertTrue(src.startswith('pipeline "%s"\nstage 0 match either' % title))
            self.assertTrue(src.endswith("\nend"))
            for rtitle, _ in rules:
                self.assertIn('  rule "%s"' % rtitle, src)
            self.assertEqual(src.count("stage "), 1, "只能有 stage 0，見檔頭設計取捨")

    def test_windows_event_id_and_user_use_both_shipper_field_names(self):
        src = dict(gp.windows_rules())["ioc-win-4625"]
        self.assertIn("$message.winlog_event_id", src)    # Winlogbeat
        self.assertIn("$message.EventID", src)            # NXLog
        self.assertIn("$message.winlog_event_data_TargetUserName", src)
        self.assertIn("$message.TargetUserName", src)

    def test_local_logon_ip_placeholder_is_excluded(self):
        src = dict(gp.windows_rules())["ioc-win-src-ip"]
        for bad in ('"-"', '"::1"', '"127.0.0.1"'):
            self.assertIn("!= " + bad, src)

    def test_firewall_deny_and_allow_are_mutually_exclusive(self):
        rules = dict(gp.firewall_rules())
        self.assertIn("!(", rules["ioc-fw-allow"])
        written = {name: set(re.findall(r'set_field\("(\w+)"', src)) - {"ioc_schema"} for name, src in rules.items()}
        self.assertFalse(written["ioc-fw-base"] & written["ioc-fw-deny"])
        self.assertFalse(written["ioc-fw-base"] & written["ioc-fw-allow"])

    def test_grok_patterns_capture_only_standard_fields(self):
        for rules in gp.pipelines().values():
            for _, src in rules:
                for f in re.findall(r"%\{\w+:(\w+)\}", src):
                    self.assertIn(f, gp.STANDARD_FIELDS)

    def test_every_event_action_is_documented(self):
        """新增 event_action 代碼卻沒補 docs/ioc/phase0.md 的中文對照，入口之後就會顯示英文代碼。"""
        doc = (ROOT / "docs" / "ioc" / "phase0.md").read_text(encoding="utf-8")
        actions = set()
        for rules in gp.pipelines().values():
            for _, src in rules:
                actions |= set(re.findall(r'set_field\("event_action", "(\w+)"\)', src))
        self.assertGreaterEqual(len(actions), 15)
        for a in sorted(actions):
            self.assertIn("`%s`" % a, doc, a)

    def test_lint_catches_bad_rules(self):
        self.assertTrue(gp.lint("x", 'rule "x"\nwhen\n  true\nthen\n  set_field("nope", "1");\nend'))
        self.assertTrue(gp.lint("x", 'rule "x"\nwhen\n  true\nthen\n  set_field("log_type", "a\\b");\nend'))
        self.assertTrue(gp.lint("x", 'rule "x"\nwhen\n  (true\nthen\nend'))
        self.assertTrue(gp.lint("x", 'rule "x"\nwhen\n  true\nthen\n'))


class ConfigTests(unittest.TestCase):
    def test_conf_file_parsed_and_env_overrides(self):
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "g.conf")
            with open(path, "w", encoding="utf-8") as f:
                f.write("# c\nGRAYLOG_USER=u1\nGRAYLOG_PASS='p w'\nGRAYLOG_URL=https://x:9000\n")
            conf = gp.load_config({"GRAYLOG_API_CONF": path, "GRAYLOG_USER": "u2"})
            self.assertEqual(conf["GRAYLOG_USER"], "u2")          # 環境變數優先
            self.assertEqual(conf["GRAYLOG_PASS"], "p w")
            self.assertEqual(conf["GRAYLOG_URL"], "https://x:9000")
            self.assertEqual(conf["GRAYLOG_STREAM_ID"], STREAM)   # 預設值

    def test_missing_credentials_exit_2_without_requests(self):
        code, _, err = run(["apply"], {"GRAYLOG_API_CONF": "/nonexistent", "GRAYLOG_USER": "", "GRAYLOG_PASS": ""})
        self.assertEqual(code, 2)
        self.assertIn("GRAYLOG_USER", err)

    def test_print_works_offline(self):
        code, out, _ = run(["print"], {"GRAYLOG_API_CONF": "/nonexistent"})
        self.assertEqual(code, 0)
        self.assertIn('rule "ioc-win-4625"', out)
        self.assertIn('pipeline "IOC Normalize - Firewall"', out)


class SyncTests(unittest.TestCase):
    def setUp(self):
        self.gl = FakeGraylog()
        self.addCleanup(self.gl.close)
        self.env = {"GRAYLOG_API_CONF": "/nonexistent", "GRAYLOG_URL": self.gl.url,
                    "GRAYLOG_USER": "admin", "GRAYLOG_PASS": "secret"}
        self.n_rules = sum(len(r) for r in gp.pipelines().values())
        self.n_pipes = len(gp.pipelines())

    def test_apply_on_empty_creates_everything_and_connects(self):
        code, out, _ = run(["apply"], self.env)
        self.assertEqual(code, 0, out)
        self.assertEqual(len(self.gl.rules), self.n_rules)
        self.assertEqual(len(self.gl.pipes), self.n_pipes)
        self.assertEqual(len(self.gl.conns[STREAM]), self.n_pipes)
        self.assertIn("APPLY: changes=%d" % (self.n_rules + 2 * self.n_pipes), out)

    def test_second_apply_makes_no_changes_and_no_mutating_requests(self):
        run(["apply"], self.env)
        self.gl.requests.clear()
        code, out, _ = run(["apply"], self.env)
        self.assertEqual(code, 0)
        self.assertIn("APPLY: changes=0", out)
        self.assertEqual(self.gl.mutations(), [])

    def test_plan_is_read_only(self):
        code, out, _ = run(["plan"], self.env)
        self.assertEqual(code, 0)
        self.assertIn("PLAN: changes=", out)
        self.assertEqual(self.gl.mutations(), [])
        self.assertEqual(self.gl.rules, {})

    def test_changed_rule_on_server_is_updated_in_place(self):
        run(["apply"], self.env)
        rid, rule = next((i, r) for i, r in self.gl.rules.items() if r["title"] == "ioc-win-4625")
        rule["source"] = rule["source"] + "\n// 被人手改過"
        self.gl.requests.clear()
        code, out, _ = run(["apply"], self.env)
        self.assertEqual(code, 0)
        self.assertEqual(self.gl.mutations(), [("PUT", "/api/system/pipelines/rule/" + rid)])
        self.assertIn("APPLY: changes=1", out)

    def test_every_request_carries_requested_by_header(self):
        # Graylog 會拒絕缺 X-Requested-By 的寫入請求（CSRF 防護）
        run(["apply"], self.env)
        self.assertTrue(self.gl.requests)
        self.assertEqual(self.gl.header_violations, [])

    def test_wrong_password_fails_with_message(self):
        env = dict(self.env, GRAYLOG_PASS="wrong")
        code, _, err = run(["apply"], env)
        self.assertEqual(code, 1)
        self.assertIn("401", err)

    def test_rejected_rule_stops_before_pipelines_and_connections(self):
        bad = {"IOC Normalize - X": [("ioc-bad", 'rule "ioc-bad"\nwhen\n  true\nthen\n  // SYNTAXERR\nend')]}
        with mock.patch.object(gp, "pipelines", lambda: bad):
            code, _, err = run(["apply"], self.env)
        self.assertEqual(code, 1)
        self.assertIn("400", err)
        self.assertEqual(self.gl.pipes, {})
        self.assertEqual(self.gl.conns, {})

    def test_lint_failure_sends_no_requests(self):
        bad = {"IOC Normalize - X": [("ioc-bad", 'rule "ioc-bad"\nwhen\n  true\nthen\n  set_field("nope", "1");\nend')]}
        with mock.patch.object(gp, "pipelines", lambda: bad):
            code, _, err = run(["apply"], self.env)
        self.assertEqual(code, 1)
        self.assertIn("非標準欄位", err)
        self.assertEqual(self.gl.requests, [])

    def test_unexpected_list_response_fails_closed(self):
        self.gl.route = lambda m, p, b: (200, {"rules": []})   # 假裝 API 改成分頁物件
        code, _, err = run(["apply"], self.env)
        self.assertEqual(code, 1)
        self.assertIn("非預期的回應格式", err)
        self.assertEqual(self.gl.mutations(), [])

    def test_foreign_pipelines_survive_apply_and_remove(self):
        self.gl.seed_foreign()
        run(["apply"], self.env)
        self.assertIn("f2", self.gl.conns[STREAM])            # apply 不能把既有 GeoIP pipeline 擠掉
        code, out, _ = run(["remove"], self.env)
        self.assertEqual(code, 0)
        self.assertEqual(set(self.gl.rules), {"f1", "f3"})   # f3：別人同前綴的規則
        self.assertEqual(set(self.gl.pipes), {"f2"})
        self.assertEqual(self.gl.conns[STREAM], ["f2"])
        self.assertIn("REMOVE: deleted=%d" % (self.n_rules + self.n_pipes), out)

    def test_remove_on_clean_graylog_is_a_noop(self):
        code, out, _ = run(["remove"], self.env)
        self.assertEqual(code, 0)
        self.assertIn("REMOVE: deleted=0", out)
        self.assertEqual(self.gl.mutations(), [])


if __name__ == "__main__":
    unittest.main()
