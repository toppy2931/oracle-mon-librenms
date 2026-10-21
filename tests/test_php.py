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
            # 後來用 ALTER TABLE … ADD COLUMN IF NOT EXISTS 補的欄位（已部署的主機靠它）也算這張表的欄位
            cols |= set(re.findall(r"ALTER TABLE %s ADD COLUMN IF NOT EXISTS (\w+) " % name, schema))
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
        for col in ("assignee", "created_by", "actor", "updated_by"):
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

    def test_every_library_class_is_loaded_by_bootstrap(self):
        """新增類別卻忘了加進 bootstrap.php 的載入清單，單元測試與網頁會在載入時才炸（Class not found）。"""
        boot = (ROOT / "librenms" / "ioc" / "bootstrap.php").read_text(encoding="utf-8")
        listed = set(re.findall(r"'(\w+)'", re.search(r"foreach \(\[(.*?)\] as \$f\)", boot, re.S).group(1)))
        files = {p.stem for p in (ROOT / "librenms" / "ioc").glob("*.php")} - {"bootstrap", "endpoint"}   # endpoint 只在網頁環境載入
        self.assertEqual(files - listed, set(), "bootstrap.php 沒有載入這些檔案")
        self.assertEqual(listed - files, set(), "bootstrap.php 列了不存在的檔案")

    def test_portal_navigation_marks_pages_without_data_as_unavailable(self):
        """簡報有、但沒有資料來源的頁面要顯示成停用（尚未提供），不能放個能點的空白頁或假數字。"""
        src = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        nav = re.search(r"const NAV = \[(.*?)\n  \];", src, re.S).group(1)
        for key in ("risk", "config", "audit", "reports"):
            self.assertRegex(nav, r"key: '%s'[^}]*off: true" % key, key)
        for key in ("overview", "cases", "security", "settings", "identity"):
            self.assertNotRegex(nav, r"key: '%s'[^}]*off: true" % key, key)
            self.assertIn('id="view-%s"' % key, src, key)

    def test_portal_nav_and_topbar_are_updated_in_place(self):
        """頂列每秒更新、導覽每次切頁都更新：整個重建會讓鍵盤焦點掉光，按不到鈴鐺與導覽按鈕。"""
        src = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        for name in ("renderNav", "renderTopbar"):
            body = re.search(r"function %s\(\) \{(.*?)\n  \}\n" % name, src, re.S).group(1)
            self.assertNotIn("replaceChildren", body, name + " 不能整個重建節點，只能就地更新文字與屬性")
        self.assertIn("buildNav(); buildTopbar();", src, "導覽與頂列要在啟動時建立一次")

    def test_portal_site_level_names_come_from_the_server(self):
        portal = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        self.assertIn("'siteLevel'    => Ioc\\Fleet::LEVELS", portal)
        self.assertIn("const SITE_LABEL = CFG.labels.siteLevel", portal, "站點等級名稱不要在畫面另外寫一份")

    def test_snmp_default_locations_are_not_treated_as_sites(self):
        """monitor-vm 實測：Unknown、End User Premise. 等 SNMP 預設值會被 LibreNMS 建成位置，不能當站點。"""
        src = (ROOT / "librenms" / "ioc" / "Fleet.php").read_text(encoding="utf-8")
        for placeholder in ("'unknown'", "'end user premise.'", "'rack, room, building, city, country [lat, lon]'", "'unknown ('"):
            self.assertIn(placeholder, src, placeholder)

    def test_availability_null_is_not_turned_into_zero(self):
        """LibreNMS 的可用率欄位為 NULL 是「沒有資料」；(float) null 會變成 0% 把整體可用率拉低。"""
        src = (ROOT / "librenms" / "ioc" / "endpoint.php").read_text(encoding="utf-8")
        self.assertRegex(src, r"availability_perc === null\)\s*\{\s*continue;")

    def test_evidence_action_checks_view_permission_before_it_queries_graylog(self):
        """證據時間軸會拿事件案存的查詢條件去問 Graylog：沒有檢視權限的人不能觸發這個查詢。"""
        src = (ROOT / "librenms" / "html" / "ioc-cases.php").read_text(encoding="utf-8")
        block = re.search(r"case 'evidence':(.*?)case 'create':", src, re.S).group(1)
        self.assertIn("$svc->get(", block)
        self.assertLess(block.index("$svc->get("), block.index("ioc_evidence("), "要先通過 CaseService::get 的權限檢查才能查 Graylog")
        self.assertIn("=== true", block, "demo 旗標只收真正的 true，不收字串")

    def test_case_create_whitelist_has_every_user_editable_case_column(self):
        """事件案新增的欄位若沒進建立的白名單，畫面送了也會被丟掉（能存但沒入口可填）。"""
        src = (ROOT / "librenms" / "html" / "ioc-cases.php").read_text(encoding="utf-8")
        block = re.search(r"case 'create':(.*?)case 'transition':", src, re.S).group(1)
        for col in ("category", "target_host", "related_host", "impact_scope", "tags"):
            self.assertIn("'%s'" % col, block, col)

    def test_evidence_queries_always_exclude_or_select_demo_data(self):
        """入口與報表必須排除範例資料（IS-054～056）；證據查詢也一樣。"""
        overview = (ROOT / "librenms" / "ioc" / "OverviewService.php").read_text(encoding="utf-8")
        self.assertRegex(overview, r"ioc_demo:true' : 'NOT ioc_demo:true'", "包裝只在 OverviewService::demoFilter 一處")
        for name in ("OverviewService", "EvidenceService", "HostLoginService"):
            src = (ROOT / "librenms" / "ioc" / (name + ".php")).read_text(encoding="utf-8")
            self.assertIn("OverviewService::demoFilter(" if name != "OverviewService" else "self::demoFilter(", src,
                          name + " 的 Graylog 查詢要走 demoFilter，不能自己拼")
            if name != "OverviewService":
                self.assertNotIn("ioc_demo:true", src, name + " 不能另外寫一份範例資料的條件")

    def test_identity_api_checks_admin_before_it_reads_anything(self):
        """稽核軌跡列出所有人的登入位址與失敗嘗試：沒有 admin 權限就不能去讀任何資料（檢視者也不行）。"""
        src = (ROOT / "librenms" / "html" / "ioc-identity.php").read_text(encoding="utf-8")
        self.assertIn("ioc_begin(true)", src, "身分與稽核 API 只接受 POST")
        gate = src.index("Access::canHandle($level)")
        self.assertLess(src.index("ioc_begin("), gate)
        for reader in ("ioc_identity_accounts(", "ioc_audit_trail(", "ioc_host_logins(", "saveAccountReview("):
            self.assertLess(gate, src.index(reader), reader + " 要在權限檢查之後")
        portal = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        self.assertRegex(portal, r"CAN_HANDLE \? \[\{ key: 'identity'", "導覽的「身分與稽核」只有 admin 才建立")

    def test_identity_helpers_check_admin_before_they_read_any_source(self):
        """身分與稽核的三支讀取函式自己也要先擋非管理者，不能只靠端點檔那一道（兩層各自守）。"""
        src = (ROOT / "librenms" / "ioc" / "endpoint.php").read_text(encoding="utf-8")
        for fn in ("ioc_identity_accounts", "ioc_host_logins", "ioc_audit_trail"):
            body = re.search(r"function %s\(.*?\n\}\n" % fn, src, re.S).group(0)
            self.assertIn("Access::requireHandler(", body, fn)
            self.assertLess(body.index("Access::requireHandler("), min(i for i in (body.find("ioc_identity_sources("), body.find("fromConf("), body.find("ioc_audit_sources(")) if i >= 0), fn + " 要先檢查權限再讀資料")

    def test_two_factor_secret_is_never_read(self):
        """LibreNMS 把雙因子的密鑰放在 users_prefs.value：只能問「哪些 user_id 有這一筆」，不能選 value。"""
        found = 0
        for f in sorted((ROOT / "librenms").rglob("*.php")):
            for m in re.finditer(r"DB::table\('users_prefs'\)[^;]*;", f.read_text(encoding="utf-8")):
                found += 1
                self.assertIn("pluck('user_id')", m.group(0), "%s：只能 pluck('user_id')" % f.name)
                self.assertNotRegex(m.group(0), r"value", "%s：不能碰 users_prefs.value（密鑰）" % f.name)
        self.assertGreaterEqual(found, 1, "找不到雙因子的查詢，這個檢查失效了")

    def test_admin_log_is_read_from_the_tail_only(self):
        """oracle-admin.log 沒有輪替，會一直長大：不能整份讀進記憶體。"""
        src = (ROOT / "librenms" / "ioc" / "endpoint.php").read_text(encoding="utf-8")
        self.assertIn("IOC_ADMIN_LOG_TAIL", src)
        self.assertNotRegex(src, r"file_get_contents\(IOC_ADMIN_LOG|file\(IOC_ADMIN_LOG")

    def test_settings_api_is_post_only_and_a_save_clears_the_overview_cache(self):
        """風險等級是算好存在總覽快取裡的：存了新門檻卻不清快取，畫面最多 20 秒還是舊等級。"""
        src = (ROOT / "librenms" / "html" / "ioc-settings.php").read_text(encoding="utf-8")
        self.assertIn("ioc_begin(true)", src, "設定 API 只接受 POST")
        block = re.search(r"case 'save':(.*?)(?=case '|default:)", src, re.S).group(1)
        self.assertIn("ioc_overview_cache_forget()", block)
        self.assertIn("ioc_pick($in, ['risk_low', 'risk_medium'])", block, "只收白名單內的欄位")

    def test_overview_does_not_fall_back_to_default_thresholds_when_settings_are_unreadable(self):
        """門檻讀不到時套預設值，畫面上的等級就可能跟管理者設定的不一致（例如設了 95 卻顯示低風險）。"""
        src = (ROOT / "librenms" / "ioc" / "endpoint.php").read_text(encoding="utf-8")
        self.assertRegex(src, r"(?s)riskThresholds\(\$level\);\s*\} catch \(\\Throwable \$e\) \{.*?\$thresholds = null;", "讀不到要傳 null，由 RiskScore 只給分數不判等級")
        self.assertNotRegex(src, r"DEFAULT_THRESHOLDS", "endpoint 不能自己套預設門檻；預設值只在 SettingsService／RiskScore")

    def test_every_table_in_the_schema_is_created_by_the_setup_script(self):
        """Schema 新增資料表卻忘了加進 ioc-db-setup.php 的清單：安裝時不會驗證它有沒有建立成功。"""
        schema = (ROOT / "librenms" / "ioc" / "Schema.php").read_text(encoding="utf-8")
        setup = (ROOT / "system" / "ioc-db-setup.php").read_text(encoding="utf-8")
        tables = set(re.findall(r"CREATE TABLE IF NOT EXISTS (\w+)", schema))
        listed = set(re.findall(r"'(ioc_\w+)'", re.search(r"\$tables = \[(.*?)\];", setup, re.S).group(1)))
        self.assertGreaterEqual(len(tables), 4)
        self.assertEqual(tables, listed)

    def test_history_tables_have_no_update_or_delete_path_in_the_library(self):
        """歷程只增不改不刪（IS-026）；程式裡一旦出現 UPDATE／DELETE 就等於留了竄改的路徑。"""
        for f in sorted((ROOT / "librenms" / "ioc").glob("*.php")):
            src = f.read_text(encoding="utf-8")
            self.assertNotRegex(src, r"(UPDATE|DELETE\s+FROM)\s+ioc_(case|settings)_history", f.name)

    def test_portal_risk_thresholds_are_not_hardcoded_in_the_page(self):
        """門檻是使用者可調的：畫面上的說明若寫死 85／65，調過之後就會說謊。"""
        src = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        self.assertNotRegex(src, r"[≥>=]\s*(85|65)\b", "畫面不能寫死等級門檻，要用伺服器回傳的 thresholds")
        self.assertIn("d.thresholds.low", src)
        self.assertIn("'riskLevel'    => Ioc\\RiskScore::LEVEL_LABEL", src)

    def test_case_status_labels_cover_the_flow(self):
        labels = (ROOT / "librenms" / "ioc" / "Labels.php").read_text(encoding="utf-8")
        flow = (ROOT / "librenms" / "ioc" / "CaseFlow.php").read_text(encoding="utf-8")
        statuses = set(re.findall(r"'(\w+)'\s*=>", re.search(r"CASE_STATUS = \[(.*?)\];", labels, re.S).group(1)))
        in_flow = set(re.findall(r"'(\w+)'", re.search(r"TRANSITIONS = \[(.*?)\];", flow, re.S).group(1)))
        self.assertEqual(statuses, in_flow)


if __name__ == "__main__":
    unittest.main()
