"""IOC 入口的 PHP 部分：語法檢查、PHP 單元測試，以及 PHP 與 Python 兩邊的一致性檢查。

有 php 就用 php；Windows 本機沒有的話改用 WSL 的 php（只做語法檢查與不碰資料庫的邏輯測試）。
都沒有就整組略過。VM 上直接跑 `python3 -m unittest discover -s tests` 即可。
"""
import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

from helpers import ROOT, load_module

gp = load_module("graylog_pipelines", "system/graylog-pipelines.py")


def to_wsl(p):
    s = str(Path(p).resolve())
    drive, rest = os.path.splitdrive(s)
    return "/mnt/%s%s" % (drive[0].lower(), rest.replace("\\", "/")) if drive else s


def find_php():
    """回傳 (php 指令前綴, 路徑轉換函式)，找不到回傳 None。"""
    if shutil.which("php"):
        return ["php"], str
    if os.name == "nt" and shutil.which("wsl.exe"):
        try:
            r = subprocess.run(["wsl.exe", "-e", "sh", "-c", "command -v php"], capture_output=True, timeout=30)
        except (OSError, subprocess.TimeoutExpired):
            return None
        if r.returncode == 0 and r.stdout.strip():
            return ["wsl.exe", "-e", "php"], to_wsl
    return None


PHP = find_php()


def php_files():
    out = []
    for sub in ("librenms", "system", "tests/php"):
        out += sorted((ROOT / sub).rglob("*.php"))
    return out


@unittest.skipUnless(PHP, "找不到 php（本機可用 WSL 的 php）")
class PhpTests(unittest.TestCase):
    def run_php(self, *args, timeout=120):
        cmd, conv = PHP
        return subprocess.run(cmd + [conv(a) if isinstance(a, Path) else a for a in args],
                              capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=timeout)

    def test_every_php_file_passes_lint(self):
        files = php_files()
        self.assertGreater(len(files), 5)
        bad = []
        for f in files:
            r = self.run_php("-l", f)
            if r.returncode != 0:
                bad.append("%s\n%s" % (f.relative_to(ROOT), (r.stdout + r.stderr).strip()))
        self.assertEqual(bad, [], "\n".join(bad))

    def test_php_unit_tests_pass(self):
        r = self.run_php(ROOT / "tests" / "php" / "run.php")
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertIn("passed", r.stdout)
        self.assertNotIn("FAIL", r.stdout)


class CrossLanguageConsistencyTests(unittest.TestCase):
    """不需要 php：直接讀 PHP 原始碼比對，防止 PHP 與 Python 兩邊各改各的。"""

    def test_every_event_action_has_a_chinese_label_and_no_dead_labels(self):
        src = (ROOT / "librenms" / "ioc" / "Labels.php").read_text(encoding="utf-8")
        block = re.search(r"public const ACTIONS = \[(.*?)\];", src, re.S).group(1)
        php_codes = set(re.findall(r"'(\w+)'\s*=>", block))
        py_codes = set()
        for rules in gp.pipelines().values():
            for _, rule_src in rules:
                py_codes |= set(re.findall(r'set_field\("event_action", "(\w+)"\)', rule_src))
        self.assertEqual(py_codes - php_codes, set(), "pipeline 會寫入、但入口沒有中文名稱的 event_action")
        self.assertEqual(php_codes - py_codes, set(), "入口有中文名稱、但 pipeline 不會寫入的 event_action（多餘）")

    def test_schema_columns_match_the_repo_whitelists(self):
        """資料表欄位與 PdoCaseRepo 的欄位白名單必須一致，否則寫入時才會在實機炸開。"""
        schema = (ROOT / "librenms" / "ioc" / "Schema.php").read_text(encoding="utf-8")
        repo = (ROOT / "librenms" / "ioc" / "PdoCaseRepo.php").read_text(encoding="utf-8")

        def table_columns(name):
            body = re.search(r"CREATE TABLE IF NOT EXISTS %s \((.*?)\n\) ENGINE" % name, schema, re.S).group(1)
            cols = set()
            for line in body.splitlines():
                m = re.match(r"\s+([a-z_]+) (?:INT|BIGINT|VARCHAR|TEXT|DATETIME)", line)
                if m:
                    cols.add(m.group(1))
            return cols

        def whitelist(const):
            block = re.search(r"const %s = \[(.*?)\];" % const, repo, re.S).group(1)
            return set(re.findall(r"'(\w+)'", block))

        self.assertEqual(table_columns("ioc_cases") - {"id"}, whitelist("CASE_COLUMNS"))
        self.assertEqual(table_columns("ioc_case_history") - {"id", "case_id"}, whitelist("HISTORY_COLUMNS"))

    def test_schema_statements_are_idempotent(self):
        schema = (ROOT / "librenms" / "ioc" / "Schema.php").read_text(encoding="utf-8")
        creates = re.findall(r"CREATE TABLE (?!IF NOT EXISTS)", schema)
        self.assertEqual(creates, [], "每個 CREATE TABLE 都要有 IF NOT EXISTS，install.sh 才能重複執行")
        alters = re.findall(r"ALTER TABLE \w+ ADD COLUMN (?!IF NOT EXISTS)", schema)
        self.assertEqual(alters, [], "加欄位的 ALTER 要用 ADD COLUMN IF NOT EXISTS，update.sh 才能重複執行")
        self.assertIn("ioc_cases", schema)

    def test_operator_columns_hold_any_librenms_username(self):
        """LibreNMS 的 users.username 最長 255；欄位太短會讓長帳號永遠寫不進事件案（500）。"""
        schema = (ROOT / "librenms" / "ioc" / "Schema.php").read_text(encoding="utf-8")
        for col in ("assignee", "created_by", "actor"):
            self.assertRegex(schema, r"\n\s+%s VARCHAR\(255\)" % col, col)

    def test_uninstall_removes_every_ioc_page_and_the_library(self):
        uninstall = (ROOT / "uninstall.sh").read_text(encoding="utf-8")
        for page in sorted(p.name for p in (ROOT / "librenms" / "html").glob("ioc-*.php")):
            self.assertIn(page, uninstall, "uninstall.sh 沒有移除 " + page)
        self.assertIn("includes/ioc", uninstall)

    def test_db_setup_gets_the_librenms_dir_through_sudo(self):
        """sudo 預設清掉環境變數；不明確用 env 帶過去，自訂安裝路徑時建表腳本會找預設的 /opt/librenms。"""
        for name in ("install.sh", "update.sh"):
            src = (ROOT / name).read_text(encoding="utf-8")
            self.assertIn('env LIBRENMS_DIR="$LIBRENMS_DIR" php "$IOC_TMP"', src, name)

    def test_portal_hidden_attribute_is_not_overridden_by_display_rules(self):
        """.badge-n 設了 display，瀏覽器內建的 [hidden]{display:none} 就會失效，角標藏不起來。"""
        src = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        self.assertRegex(src, r"\[hidden\]\s*\{\s*display:\s*none\s*!important")

    def test_portal_refuses_to_be_framed(self):
        src = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        self.assertIn("X-Frame-Options: SAMEORIGIN", src)
        self.assertIn("frame-ancestors 'self'", src)

    def test_case_changes_invalidate_the_overview_cache(self):
        cases = (ROOT / "librenms" / "html" / "ioc-cases.php").read_text(encoding="utf-8")
        data = (ROOT / "librenms" / "html" / "ioc-data.php").read_text(encoding="utf-8")
        endpoint = (ROOT / "librenms" / "ioc" / "endpoint.php").read_text(encoding="utf-8")
        self.assertIn("IOC_OVERVIEW_CACHE", data)
        self.assertIn("IOC_OVERVIEW_CACHE", endpoint)
        for case in ("create", "transition"):       # 會改件數的動作；留言不改件數
            block = re.search(r"case '%s':(.*?)(?=case '|default:)" % case, cases, re.S).group(1)
            self.assertIn("ioc_overview_cache_forget()", block, case)

    def test_every_ioc_endpoint_checks_login_role_and_csrf_first(self):
        """每支 API 一開始都要呼叫 ioc_begin()（登入、角色、CSRF）；漏掉就等於沒有權限控管（IS-013）。"""
        endpoints = sorted((ROOT / "librenms" / "html").glob("ioc-*.php"))
        self.assertTrue(endpoints)
        for f in endpoints:
            src = f.read_text(encoding="utf-8")
            if f.name == "ioc-portal.php":      # 頁面：沒登入或沒角色就轉走
                self.assertIn("Auth::check()", src, f.name)
                self.assertIn("Ioc\\Access::canView($level)", src, f.name)
                continue
            self.assertIn("ioc_begin(", src, "%s 沒有呼叫 ioc_begin()" % f.name)
            self.assertLess(src.index("ioc_begin("), src.index("ioc_json("), "%s 要先檢查才能回資料" % f.name)

    def test_cases_api_is_post_only_and_whitelists_create_fields(self):
        src = (ROOT / "librenms" / "html" / "ioc-cases.php").read_text(encoding="utf-8")
        self.assertIn("ioc_begin(true)", src, "事件案 API 只接受 POST")
        self.assertNotIn("dedup_key", re.search(r"case 'create':.*?case 'transition'", src, re.S).group(0).replace("dedup_key 只給", ""),
                         "dedup_key 不能由瀏覽器傳入")

    def test_portal_never_puts_data_into_innerhtml(self):
        """日誌內容都來自外部，用 innerHTML 放進畫面就是 XSS。畫面只能用 DOM 與 textContent。"""
        src = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        # 只抓「真的在用」的寫法（賦值、呼叫）；註解裡提到這些詞不算
        dangerous = re.search(r"\.(inner|outer)HTML\s*=|insertAdjacentHTML\s*\(|document\.write(ln)?\s*\(|\beval\s*\(|new\s+Function\s*\(", src)
        self.assertIsNone(dangerous, "ioc-portal.php 不能使用：%s" % (dangerous.group(0) if dangerous else ""))

    def test_portal_embeds_config_safely(self):
        src = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        for flag in ("JSON_HEX_TAG", "JSON_HEX_AMP", "JSON_HEX_APOS", "JSON_HEX_QUOT"):
            self.assertIn(flag, src, "內嵌在 <script> 裡的 JSON 要用 " + flag)

    def test_libraries_are_not_web_reachable(self):
        """函式庫要裝在 includes/ioc/（瀏覽器無法直接要求），不能放進 html/。"""
        # 精確比對檔名（Windows 的 glob 不分大小寫，不能用萬用字元比對）
        lib = {p.name for p in (ROOT / "librenms" / "ioc").glob("*.php")}
        web = {p.name for p in (ROOT / "librenms" / "html").glob("*.php")}
        self.assertTrue(lib and web)
        self.assertEqual(lib & web, set(), "函式庫不能同時出現在 html/ 底下")
        for name in web:
            self.assertRegex(name, r"^(oracle|ioc)-", "html/ 底下的頁面檔名要有 oracle- 或 ioc- 前綴：" + name)

    def test_case_status_labels_cover_the_flow(self):
        labels = (ROOT / "librenms" / "ioc" / "Labels.php").read_text(encoding="utf-8")
        flow = (ROOT / "librenms" / "ioc" / "CaseFlow.php").read_text(encoding="utf-8")
        statuses = set(re.findall(r"'(\w+)'\s*=>", re.search(r"CASE_STATUS = \[(.*?)\];", labels, re.S).group(1)))
        in_flow = set(re.findall(r"'(\w+)'", re.search(r"TRANSITIONS = \[(.*?)\];", flow, re.S).group(1)))
        self.assertEqual(statuses, in_flow)


if __name__ == "__main__":
    unittest.main()
