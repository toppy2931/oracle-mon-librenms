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


# load_config() 會讀的環境變數。先全部清空再套用測試指定的，避免實機上 export 過的值（例如
# GRAYLOG_FIREWALL_STREAMS）漏進來改變行為
CONFIG_KEYS = ("GRAYLOG_URL", "GRAYLOG_USER", "GRAYLOG_PASS", "GRAYLOG_CA_FILE", "GRAYLOG_STREAM_ID",
               "GRAYLOG_FIREWALL_STREAMS")


def run(argv, env):
    """以指定環境變數跑 main()，回傳 (退出碼, stdout, stderr)。"""
    out, err = io.StringIO(), io.StringIO()
    full = dict.fromkeys(CONFIG_KEYS, "")
    full.update(env)
    with mock.patch.dict(os.environ, full, clear=False), redirect_stdout(out), redirect_stderr(err):
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
            self.assertTrue(src.startswith('pipeline "%s"\nstage %d match either' % (title, gp.stage_of(title))))
            self.assertTrue(src.endswith("\nend"))
            for rtitle, _ in rules:
                self.assertIn('  rule "%s"' % rtitle, src)
            self.assertEqual(src.count("stage "), 1, "每條 pipeline 只能有一個 stage，見檔頭設計取捨")

    def test_firewall_runs_after_fortigate_normalize_others_at_stage_0(self):
        """既有 fortigate_normalize 在 stage 0、1 產生 srcip/dstip/action，防火牆規則必須排在它們之後。"""
        fw = gp.PIPELINE_PREFIX + "Firewall"
        self.assertGreaterEqual(gp.stage_of(fw), 2)
        for title in gp.pipelines():
            if title != fw:
                self.assertEqual(gp.stage_of(title), 0, title)

    def test_firewall_prefers_normalized_fields_and_falls_back_to_cef_raw(self):
        base = dict(gp.firewall_rules())["ioc-fw-base"]
        self.assertIn("to_string($message.srcip, to_string($message.src,", base)
        self.assertIn("to_string($message.dstip, to_string($message.dst,", base)
        deny = dict(gp.firewall_rules())["ioc-fw-deny"]
        self.assertIn("to_string($message.action, to_string($message.act,", deny)
        for word in ("deny", "drop", "block", "reject"):   # blocked、dropped 等 UTM 動作也要命中
            self.assertIn('"%s"' % word, deny)

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
        for other in ("ioc-fw-base", "ioc-fw-deny", "ioc-fw-allow"):    # 位元組數規則寫自己的欄位，不與其他規則重疊
            self.assertFalse(written["ioc-fw-bytes"] & written[other], other)

    def test_firewall_bytes_come_from_cef_out_and_in_and_are_numeric(self):
        """這台 FortiGate 的 CEF 把位元組數放在 out／in；既有 pipeline 讀 sentbyte／rcvdbyte，network_bytes 每筆都是 0（monitor-vm 實測）。"""
        src = dict(gp.firewall_rules())["ioc-fw-bytes"]
        self.assertRegex(src, r'set_field\("net_bytes", to_long\(regex_replace\("\^\.\* out=\(\[0-9\]\+\)\.\*\$", [^\n]*?"\$1"\), 0\) \+ to_long\(regex_replace\("\^\.\* in=\(\[0-9\]\+\)\.\*\$", [^\n]*?"\$1"\), 0\)\);',
                         "從原文取 out= 與 in=，轉成數字才能 sum")
        self.assertIn('contains(to_string($message.message, ""), " out=") || contains(to_string($message.message, ""), " in=")', src,
                      "沒有 out／in 的日誌不寫 net_bytes：沒有資料不等於 0 位元組")
        self.assertNotIn("sentbyte", src, "不依賴既有 pipeline 的 sentbyte／rcvdbyte")
        self.assertNotIn("$message.in", src, "in 可能是 Graylog 規則語言的保留字，不用欄位寫法")
        # 與 FortiGate 實際日誌的格式對得上：... FTNTFGTduration=74 out=4456 in=11139 FTNTFGTsentpkt=20 ...
        sample = "x FTNTFGTduration=74 out=4456 in=11139 FTNTFGTsentpkt=20 FTNTFGTrcvdpkt=18"
        self.assertEqual(re.sub(r"^.* out=([0-9]+).*$", r"\1", sample), "4456")
        self.assertEqual(re.sub(r"^.* in=([0-9]+).*$", r"\1", sample), "11139")
        self.assertIn("net_bytes", gp.STANDARD_FIELDS)
        self.assertEqual(list(gp.lint("ioc-fw-bytes", src)), [])

    def test_grok_patterns_capture_only_standard_fields(self):
        for rules in gp.pipelines().values():
            for _, src in rules:
                for f in re.findall(r"%\{\w+:(\w+)\}", src):
                    self.assertIn(f, gp.STANDARD_FIELDS)

    def test_ssh_invalid_user_line_has_its_own_action(self):
        """帳號不存在時 sshd 記兩行；兩行若同為 login_failed，一次嘗試會被算成兩次。"""
        actions = {name: action for name, _, action, _, _ in gp.LINUX_EVENTS}
        self.assertNotEqual(actions["linux-ssh-invalid-user"], actions["linux-ssh-failed"])

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

    def test_only_a_matching_pair_of_outer_quotes_is_stripped(self):
        """密碼結尾是引號時不能被吃掉（舊寫法 strip 會拿掉所有首尾引號）。"""
        with tempfile.TemporaryDirectory() as d:
            path = os.path.join(d, "g.conf")
            with open(path, "w", encoding="utf-8") as f:
                f.write('GRAYLOG_PASS=abc"\nGRAYLOG_USER=\'u\'\nGRAYLOG_URL="http://x:9000"\n'
                        'GRAYLOG_CA_FILE="it\'s"\nGRAYLOG_STREAM_ID=\'"\'\n')
            conf = gp.parse_conf(path)
        self.assertEqual(conf["GRAYLOG_PASS"], 'abc"')
        self.assertEqual(conf["GRAYLOG_USER"], "u")
        self.assertEqual(conf["GRAYLOG_URL"], "http://x:9000")
        self.assertEqual(conf["GRAYLOG_CA_FILE"], "it's")
        self.assertEqual(conf["GRAYLOG_STREAM_ID"], '"')

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

    # ── 防火牆日誌在自訂 stream（移出 Default Stream）的情境 ──
    FW_STREAM = "6a33ef93a68ab16ef006f414"
    FW_TITLE = gp.PIPELINE_PREFIX + "Firewall"

    def fw_id(self):
        return next(i for i, p in self.gl.pipes.items() if p["title"] == self.FW_TITLE)

    def test_firewall_also_connected_to_named_stream_others_only_default(self):
        env = dict(self.env, GRAYLOG_FIREWALL_STREAMS="FortiGate Syslog")
        code, out, _ = run(["apply"], env)
        self.assertEqual(code, 0, out)
        self.assertEqual(self.gl.conns[self.FW_STREAM], [self.fw_id()])
        self.assertEqual(len(self.gl.conns[STREAM]), self.n_pipes)
        self.assertIn("APPLY: changes=%d" % (self.n_rules + 2 * self.n_pipes + 1), out)

    def test_stream_can_be_given_by_id_and_default_is_not_duplicated(self):
        env = dict(self.env, GRAYLOG_FIREWALL_STREAMS="%s, Default Stream" % self.FW_STREAM)
        code, out, _ = run(["apply"], env)
        self.assertEqual(code, 0, out)
        self.assertEqual(self.gl.conns[self.FW_STREAM], [self.fw_id()])
        self.assertEqual(self.gl.conns[STREAM].count(self.fw_id()), 1)

    def test_second_apply_with_extra_stream_is_idempotent(self):
        env = dict(self.env, GRAYLOG_FIREWALL_STREAMS="FortiGate Syslog")
        run(["apply"], env)
        self.gl.requests.clear()
        code, out, _ = run(["apply"], env)
        self.assertEqual(code, 0)
        self.assertIn("APPLY: changes=0", out)
        self.assertEqual(self.gl.mutations(), [])

    def test_unknown_stream_name_fails_before_any_change_and_lists_known_streams(self):
        env = dict(self.env, GRAYLOG_FIREWALL_STREAMS="FortiGate Sylog")   # 打錯字
        code, _, err = run(["apply"], env)
        self.assertEqual(code, 1)
        self.assertIn("找不到 stream", err)
        self.assertIn("FortiGate Syslog", err)
        self.assertEqual(self.gl.mutations(), [])

    def test_upgrade_from_stage_0_default_only_to_stage_2_plus_fortigate_stream(self):
        """真實環境的升級路徑：第一版已套用（防火牆 stage 0、只連 Default），改設定後重跑。"""
        run(["apply"], self.env)
        fid = self.fw_id()
        self.gl.pipes[fid]["source"] = self.gl.pipes[fid]["source"].replace("stage 2", "stage 0")
        self.gl.requests.clear()
        code, out, _ = run(["apply"], dict(self.env, GRAYLOG_FIREWALL_STREAMS="FortiGate Syslog"))
        self.assertEqual(code, 0, out)
        self.assertEqual(self.gl.mutations(), [("PUT", "/api/system/pipelines/pipeline/" + fid),
                                               ("POST", "/api/system/pipelines/connections/to_pipeline")])
        self.assertIn("stage 2 match either", self.gl.pipes[fid]["source"])
        self.assertIn(fid, self.gl.conns[STREAM])           # 原本連的 Default Stream 沒被拿掉
        self.assertIn(fid, self.gl.conns[self.FW_STREAM])

    def test_manually_added_connections_are_never_removed(self):
        run(["apply"], self.env)
        self.gl.conns[self.FW_STREAM] = [self.fw_id()]       # 有人手動多連了一個 stream
        self.gl.requests.clear()
        code, out, _ = run(["apply"], self.env)               # 設定檔沒提到它
        self.assertEqual(code, 0)
        self.assertEqual(self.gl.mutations(), [])
        self.assertEqual(self.gl.conns[self.FW_STREAM], [self.fw_id()])

    # ── 審查後補的失敗情境 ──
    def test_redirect_is_not_followed_and_is_reported(self):
        """被重導向時不能帶著帳密跟過去，也不能把 POST 悄悄變成 GET。"""
        rule_path = "/api/system/pipelines/rule"
        self.gl.overrides[("POST", rule_path)] = (301, None, {"Location": self.gl.url + rule_path})
        code, _, err = run(["apply"], self.env)
        self.assertEqual(code, 1)
        self.assertIn("重新導向", err)
        self.assertEqual(self.gl.pipes, {})

    def test_unreadable_connections_stops_before_any_change(self):
        """to_pipeline 是整組取代；讀不到現況就不能送，而且要在改任何東西之前就停。"""
        self.gl.overrides[("GET", "/api/system/pipelines/connections")] = (500, {"message": "boom"})
        code, _, err = run(["apply"], self.env)
        self.assertEqual(code, 1)
        self.assertIn("500", err)
        self.assertEqual(self.gl.mutations(), [])

    def test_non_json_response_is_an_api_error_not_a_traceback(self):
        self.gl.overrides[("GET", "/api/system/pipelines/rule")] = (200, b"<html>maintenance</html>")
        code, _, err = run(["apply"], self.env)
        self.assertEqual(code, 1)
        self.assertIn("回應不是 JSON", err)
        self.assertNotIn("Traceback", err)
        self.assertEqual(self.gl.mutations(), [])

    def test_connection_failure_is_an_api_error(self):
        self.gl.close()
        code, _, err = run(["apply"], self.env)
        self.assertEqual(code, 1)
        self.assertIn("Graylog API 錯誤", err)
        self.assertNotIn("Traceback", err)

    def test_remove_still_deletes_when_disconnect_is_rejected(self):
        """解除連線的呼叫（空 stream 清單）沒在真實 Graylog 驗證過；被拒絕也不能讓回滾卡住。"""
        run(["apply"], self.env)
        self.gl.overrides[("POST", "/api/system/pipelines/connections/to_pipeline")] = (400, {"message": "empty"})
        code, out, _ = run(["remove"], self.env)
        self.assertEqual(code, 0)
        self.assertIn("警告：解除連線失敗", out)
        self.assertEqual(self.gl.pipes, {})
        self.assertEqual(self.gl.rules, {})

    def test_unexpected_streams_response_fails_closed(self):
        orig = self.gl.route
        self.gl.route = lambda m, p, b: (200, []) if p == "/api/streams" else orig(m, p, b)
        code, _, err = run(["apply"], dict(self.env, GRAYLOG_FIREWALL_STREAMS="FortiGate Syslog"))
        self.assertEqual(code, 1)
        self.assertIn("非預期的回應格式", err)
        self.assertEqual(self.gl.mutations(), [])

    def test_no_streams_lookup_when_not_configured(self):
        run(["plan"], self.env)
        self.assertNotIn("/api/streams", [p for _, p, _ in self.gl.requests])

    def test_plan_is_read_only(self):
        code, out, _ = run(["plan"], self.env)
        self.assertEqual(code, 0)
        self.assertIn("PLAN: changes=", out)
        self.assertEqual(self.gl.mutations(), [])
        self.assertEqual(self.gl.rules, {})

    def test_new_rule_is_added_to_an_already_applied_firewall_pipeline(self):
        """monitor-vm 的升級路徑：已套用過（還沒有 ioc-fw-bytes）再跑 apply，只新增那一條規則並更新防火牆 pipeline 的原始碼。"""
        run(["apply"], self.env)
        rid = next(i for i, r in self.gl.rules.items() if r["title"] == "ioc-fw-bytes")
        fid = self.fw_id()
        del self.gl.rules[rid]
        self.gl.pipes[fid]["source"] = self.gl.pipes[fid]["source"].replace('  rule "ioc-fw-bytes"\n', "")
        self.assertNotIn("ioc-fw-bytes", self.gl.pipes[fid]["source"])
        self.gl.requests.clear()
        code, out, _ = run(["apply"], self.env)
        self.assertEqual(code, 0, out)
        self.assertEqual(self.gl.mutations(), [("POST", gp.P_RULE), ("PUT", gp.P_PIPE + "/" + fid)])
        self.assertIn("APPLY: changes=2", out)
        self.assertIn('  rule "ioc-fw-bytes"', self.gl.pipes[fid]["source"])
        self.assertEqual((len(self.gl.rules), len(self.gl.pipes)), (self.n_rules, self.n_pipes), "其他規則與 pipeline 沒被動到")

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
