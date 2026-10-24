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
            if f.name in {"ioc-backup-download.php", "ioc-audit-download.php"}:
                self.assertIn("ioc_begin(true)", src, f.name)
                self.assertIn("Access::requireHandler", src, f.name)
                self.assertIn("Content-Disposition: attachment", src, f.name)
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
        for key in ("risk", "reports"):
            self.assertRegex(nav, r"key: '%s'[^}]*off: true" % key, key)
        for key in ("overview", "cases", "security", "network", "settings", "identity", "config", "audit"):
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
        for name in ("OverviewService", "EvidenceService", "HostLoginService", "NetworkVisibilityService"):
            src = (ROOT / "librenms" / "ioc" / (name + ".php")).read_text(encoding="utf-8")
            self.assertIn("OverviewService::demoFilter(" if name != "OverviewService" else "self::demoFilter(", src,
                          name + " 的 Graylog 查詢要走 demoFilter，不能自己拼")
            if name != "OverviewService":
                self.assertNotIn("ioc_demo:true", src, name + " 不能另外寫一份範例資料的條件")

    def test_network_api_is_post_only_cached_and_takes_demo_only_as_true(self):
        """內網可視性：要登入與 CSRF（ioc_begin）、demo 旗標只收真正的 true、快取鍵分正式與範例，不是 GET 就能觸發 20 次 Graylog 查詢。"""
        src = (ROOT / "librenms" / "html" / "ioc-network.php").read_text(encoding="utf-8")
        self.assertIn("ioc_begin(true)", src, "內網可視性 API 只接受 POST")
        self.assertIn("=== true", src, "demo 旗標只收真正的 true，不收字串")
        self.assertIn("IOC_NETWORK_CACHE . ($demo ? 'demo' : 'live') . ':' . $range", src, "快取要分正式／範例資料與統計期間")
        self.assertIn("is_int($range)", src, "統計期間只能接受 JSON 整數")
        self.assertIn("NetworkVisibilityService::RANGE_LABELS[$range]", src, "統計期間要用白名單")
        portal = (ROOT / "librenms" / "html" / "ioc-portal.php").read_text(encoding="utf-8")
        self.assertIn("'networkRanges' => Ioc\\NetworkVisibilityService::RANGE_LABELS", portal, "選單與 API 要共用同一份期間白名單")
        self.assertIn("range_seconds: networkRange", portal, "選定期間要送到 API")
        self.assertLess(src.index("ioc_begin("), src.index("ioc_network("), "要先檢查登入與角色才能查 Graylog")
        endpoint = (ROOT / "librenms" / "ioc" / "endpoint.php").read_text(encoding="utf-8")
        self.assertNotRegex(endpoint, r"IpNets::DEFAULTS", "endpoint 不能自己套預設網段；內部網段讀不到要傳 null，由服務標「無法取得」")
        self.assertRegex(endpoint, r"(?s)internalNets\(\$level\);\s*\} catch \(\\Ioc\\AccessDenied \$e\) \{\s*throw \$e;\s*\} catch \(\\Throwable \$e\) \{.*?\$why = ", "讀不到內部網段要帶原因")

    def test_saving_internal_nets_clears_the_network_cache_and_whitelists_fields(self):
        """內部網段改了卻不清快取，內網可視性最多 60 秒還是用舊網段算；欄位只收白名單。"""
        src = (ROOT / "librenms" / "html" / "ioc-settings.php").read_text(encoding="utf-8")
        block = re.search(r"case 'save_network':(.*?)(?=case '|default:)", src, re.S).group(1)
        self.assertIn("ioc_network_cache_forget()", block)
        self.assertIn("ioc_overview_cache_forget()", block, "總覽的「防火牆阻擋來源」也用同一份內部網段，快取裡是舊網段算的")
        self.assertIn("ioc_pick($in, ['cidrs'])", block)
        self.assertIn("saveNetwork(", block)
        endpoint = (ROOT / "librenms" / "ioc" / "endpoint.php").read_text(encoding="utf-8")
        fn = re.search(r"function ioc_network_cache_forget\(\).*?\n\}\n", endpoint, re.S).group(0)
        self.assertIn("IOC_NETWORK_CACHE", fn)
        self.assertIn("NetworkVisibilityService::RANGE_LABELS", fn, "所有統計區間的快取都要清除")

    def test_overview_and_network_page_share_one_definition_of_internal(self):
        """資安頁的「防火牆阻擋來源」與內網可視性都要用設定頁的內部網段；不能各寫一套（曾經一邊寫死 RFC1918、另一邊用設定）。"""
        overview = (ROOT / "librenms" / "ioc" / "OverviewService.php").read_text(encoding="utf-8")
        self.assertIn("IpNets::contains($this->nets", overview)
        self.assertNotIn("FILTER_FLAG_NO_PRIV_RANGE", overview, "不要再用 PHP 內建的私有位址判斷")
        endpoint = (ROOT / "librenms" / "ioc" / "endpoint.php").read_text(encoding="utf-8")
        self.assertRegex(endpoint, r"(?s)internalNets\(\$level\);\s*\} catch \(\\Throwable \$e\) \{.*?\$nets = null;.*?new OverviewService\(\$logs, \$demo, null, \$nets\)",
                         "內部網段讀不到要傳 null，由總覽標「無法取得」，不能套預設網段")

    def test_network_service_never_reads_raw_messages_or_uses_relative_ranges(self):
        """內網可視性只做彙整查詢（不撈原始訊息，免得把日誌內容帶進畫面），且一律用絕對時間範圍（前期比較要用）。"""
        src = (ROOT / "librenms" / "ioc" / "NetworkVisibilityService.php").read_text(encoding="utf-8")
        self.assertNotRegex(src, r"->messages(Between)?\(")
        self.assertNotRegex(src, r"->aggregate\(")
        self.assertIn("->aggregateBetween(", src)

    def test_network_bytes_field_is_the_one_the_ioc_firewall_pipeline_writes(self):
        """服務讀的位元組欄位（F_BYTES）必須就是 IOC 防火牆 pipeline 的 ioc-fw-bytes 規則寫的標準欄位；
        兩邊各改各的，流量那三塊會悄悄變成「沒有位元組數」，而兩邊的單元測試都還是綠的。"""
        svc = (ROOT / "librenms" / "ioc" / "NetworkVisibilityService.php").read_text(encoding="utf-8")
        field = re.search(r"const F_BYTES\s*=\s*'(\w+)'", svc).group(1)
        rule = dict(gp.firewall_rules())["ioc-fw-bytes"]
        written = set(re.findall(r'set_field\("(\w+)"', rule)) - {"ioc_schema"}
        self.assertEqual(written, {field}, "ioc-fw-bytes 寫的欄位要和服務讀的 F_BYTES 一樣")
        self.assertIn(field, gp.STANDARD_FIELDS)
        self.assertNotEqual(field, "network_bytes", "既有 pipeline 的 network_bytes 每一筆都是 0，不能用")

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

    def test_saving_sites_is_admin_only_clears_the_overview_cache_and_has_a_bounded_body(self):
        """站點設定存了卻不清總覽快取，站點數與分佈最多 20 秒還是舊的；整份清單是陣列（ioc_pick 只收純量），
        所以形狀、長度與內容都由服務嚴格檢查，而且要先檢查權限才處理內容；請求內容有上限。"""
        src = (ROOT / "librenms" / "html" / "ioc-settings.php").read_text(encoding="utf-8")
        block = re.search(r"case 'save_sites':(.*?)(?=case '|default:)", src, re.S).group(1)
        self.assertIn("ioc_overview_cache_forget()", block)
        self.assertIn("saveSites(", block)
        self.assertLess(src.index("ioc_begin("), src.index("case 'save_sites'"), "要先登入與驗 CSRF")
        self.assertIn("32768", src, "請求內容要有上限")
        self.assertRegex(src, r"strlen\(\$raw\) > 4096 && \(\$in\['action'\] \?\? ''\) !== 'save_sites'",
                         "只有 save_sites 可以超過 4 KB，其他動作維持原本的上限")
        svc = (ROOT / "librenms" / "ioc" / "SettingsService.php").read_text(encoding="utf-8")
        body = re.search(r"public function saveSites\(.*?\n    \}\n", svc, re.S).group(0)
        self.assertLess(body.index("requireHandler("), body.index("parseSubmitted("), "先檢查權限再處理內容")
        self.assertLess(body.index("parseSubmitted("), body.index("transaction("), "驗證通過才開始寫")
        self.assertIn("allForUpdate()", body, "整份取代要用鎖定讀取，兩個管理者同時儲存才不會互相覆蓋")
        self.assertNotIn("->all()", body, "saveSites 裡不能用沒有鎖的 all()")

    def test_settings_history_prefix_filter_is_parameterized_and_escaped(self):
        """站點異動紀錄依前綴在資料庫端篩：LIKE 的前綴要走參數、跳脫 % 與 _（站點名稱是 site.<id>，但前綴參數是通用的）。"""
        src = (ROOT / "librenms" / "ioc" / "PdoSettingsRepo.php").read_text(encoding="utf-8")
        self.assertIn("skey LIKE :prefix ESCAPE '!'", src)
        self.assertIn("str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyPrefix)", src)
        self.assertNotRegex(src, r"LIKE\s+'[^']*'\s*\.|LIKE\s+\"[^\"]*\"\s*\.\s*\$", "前綴不能用字串拼接進 SQL")

    def test_overview_reports_unreadable_sites_instead_of_silently_using_location_only(self):
        """站點設定讀不到時設備只能依 LibreNMS 位置分站點：一定要把原因帶到畫面，不能默默換一套結果；
        設備 IP 要經 Fleet::deviceIp 轉換（devices.ip 是二進位）。"""
        endpoint = (ROOT / "librenms" / "ioc" / "endpoint.php").read_text(encoding="utf-8")
        self.assertRegex(endpoint, r"(?s)->sites\(\$level\);\s*\} catch \(\\Ioc\\AccessDenied \$e\) \{\s*throw \$e;\s*\} catch \(\\Throwable \$e\) \{.*?\$sitesWarning = ",
                         "站點設定讀不到要帶原因（警告），不能只丟掉")
        self.assertIn("$thresholds, $sitesWarning)", endpoint)
        self.assertIn("Fleet::deviceIp(", endpoint)
        self.assertNotIn("inet_ntop", endpoint, "二進位位址的轉換只在 Fleet::deviceIp（有單元測試）")

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
