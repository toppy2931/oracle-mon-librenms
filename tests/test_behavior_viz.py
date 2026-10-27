"""3D 連線視圖（tools/behavior-viz/behavior-3d.html）與資料匯出（tools/export-behavior-viz.php）的靜態檢查。

頁面會發布成 Artifact（存在 claude.ai），而且會在瀏覽器裡讀入使用者的真實資料：這些檢查擋的是「資料被送出去、被存起來、被當成 HTML」。
行為本身（分頁、載入驗證、WebGL 失效替代、手機版）是用瀏覽器實際操作驗證的，見 docs/ioc/behavior-analysis.md。
"""
import base64
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = ROOT / "tools" / "behavior-viz" / "behavior-3d.html"
EXPORT_LIB = ROOT / "librenms" / "ioc" / "BehaviorVizExport.php"
EXPORT_CLI = ROOT / "tools" / "export-behavior-viz.php"


def text(p: Path) -> str:
    return p.read_text(encoding="utf-8")


def script(src: str) -> str:
    m = re.search(r"<script>\n(.*?)</script>", src, re.S)
    assert m, "找不到頁面的內嵌腳本"
    return m.group(1)


class BehaviorVizPageTests(unittest.TestCase):
    def test_imported_data_is_never_turned_into_html_stored_or_sent(self):
        js = script(text(PAGE))
        for pattern in (r"\.(inner|outer)HTML\s*=", r"insertAdjacentHTML\s*\(", r"document\.write(ln)?\s*\(", r"\beval\s*\(", r"new\s+Function\s*\(", r"\bsetTimeout\s*\(\s*['\"`]"):
            self.assertIsNone(re.search(pattern, js), "頁面不能使用：" + pattern)
        # 載入的是使用者的真實資料：不能送出去（fetch／XHR／WebSocket／sendBeacon）、也不能存起來（localStorage／sessionStorage／IndexedDB／cookie）
        for pattern in (r"\bfetch\s*\(", r"XMLHttpRequest", r"WebSocket", r"sendBeacon", r"localStorage", r"sessionStorage", r"indexedDB", r"document\.cookie", r"window\.open\s*\("):
            self.assertIsNone(re.search(pattern, js), "載入的資料不能送出或保存：" + pattern)

    def test_only_one_pinned_cdnjs_script_is_loaded(self):
        src = text(PAGE)
        urls = re.findall(r"""(?:src|href)\s*=\s*["'](https?://[^"']+)["']""", src)
        self.assertEqual(urls, ["https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"], "只能有一個外部資源：釘版本的 three.js（Artifact 的 CSP 只放行 cdnjs 等幾個來源）")
        self.assertIsNone(re.search(r"@import|<link\b", src), "樣式不能引用外部檔案")
        # 例外：SVG 命名空間（createElementNS 用，不是網路位址）
        self.assertIsNone(re.search(r"""['"`]https?://(?!www\.w3\.org/2000/svg)""", script(src)), "腳本裡不能寫死任何外部網址")

    def test_artifact_page_contract(self):
        src = text(PAGE)
        self.assertIn("<title>設備連線行為</title>", src[:8192], "標題要在檔案最前面 8 KB 內")
        self.assertIsNone(re.search(r"(?i)<!doctype|<html[\s>]|<head[\s>]|<body[\s>]", src), "Artifact 會自己加外框，檔案裡不能有 doctype／html／head／body")
        self.assertIn('<meta name="claude:viewport-fit-sides" content="cover">', src)
        self.assertIn("padding-left: max(16px, env(safe-area-inset-left, 0px))", src, "宣告了 viewport-fit-sides 就要用兩個 max() 側邊留白")
        self.assertIn("padding-right: max(16px, env(safe-area-inset-right, 0px))", src)
        root = re.search(r":root\s*\{(.*?)\}", src, re.S).group(1)
        for token in ("--bg", "--panel", "--line", "--text", "--muted", "--green", "--yellow", "--orange", "--red", "--blue", "--gray"):
            self.assertIn(token + ":", root, "色票要在 :root 定義：" + token)
        self.assertIn("color-scheme: dark", root, "單一深色外觀：明確宣告 color-scheme")
        self.assertRegex(src, r"body\s*\{[^}]*background:\s*var\(--bg\)", "body 要有明確的底色")
        self.assertIn("prefers-reduced-motion", src)
        self.assertIn("max-width: 760px", src, "要有手機版")

    def test_land_mask_is_embedded_and_plausible(self):
        js = script(text(PAGE))
        self.assertNotIn("__LAND_MASK__", js, "遮罩的佔位字串沒被取代")
        mask = re.search(r"const LAND_MASK = '([A-Za-z0-9+/=]+)'", js).group(1)
        raw = base64.b64decode(mask)
        self.assertEqual(len(raw), 360 * 180 // 8, "遮罩是 360×180 的位元圖")
        land = sum(bin(b).count("1") for b in raw) / (360 * 180)
        self.assertTrue(0.25 < land < 0.35, "陸地約佔 29%%，實際 %.1f%%" % (land * 100))
        # 幾個一定在陸地／海洋上的格子（列 0 是北緯 90→89；欄 0 是西經 180→179）
        bit = lambda lat, lng: (raw[((int(90 - lat)) * 360 + int(lng + 180)) // 8] >> (7 - ((int(90 - lat)) * 360 + int(lng + 180)) % 8)) & 1
        self.assertEqual(bit(23.5, 120.5), 1, "台灣（110m 的資料有；來源節點預設就放在這裡）")
        self.assertEqual(bit(23.5, 123.5), 0, "台灣東邊是海")
        self.assertEqual(bit(48.5, 2.5), 1, "法國")
        self.assertEqual(bit(40.5, -100.5), 1, "美國中部")
        self.assertEqual(bit(-25.5, 133.5), 1, "澳洲")
        self.assertEqual(bit(0.5, -150.5), 0, "太平洋")
        self.assertEqual(bit(-80.5, 0.5), 1, "南極")

    def test_unmasked_data_is_called_out_and_untrusted_text_is_cleaned(self):
        js = script(text(PAGE))
        self.assertIn("這份資料沒有遮罩", js)
        self.assertRegex(js, r"f\.size > 8 \* 1024 \* 1024", "載入有檔案大小上限")
        self.assertIsNotNone(re.search(r"\\u202a-\\u202e", js), "載入的文字要去掉雙向控制字元")
        self.assertIn("requestAnimationFrame(frame)", js, "動畫用 requestAnimationFrame")
        self.assertIn("document.visibilityState === 'hidden'", js, "分頁在背景時要停止繪製")

    def test_schema_name_matches_the_exporter(self):
        self.assertRegex(text(EXPORT_LIB), r"SCHEMA = 'ioc-behavior-viz/1'")
        self.assertIn("raw.schema !== 'ioc-behavior-viz/1'", script(text(PAGE)), "頁面與匯出腳本要認同一個格式版本")


class InvisibleCharacterTests(unittest.TestCase):
    def test_sources_have_no_invisible_or_bidi_control_characters(self):
        """原始碼裡不能有看不見的字元（雙向控制、零寬、控制碼）：要用 \\uXXXX 跳脫寫出來，讀的人才看得到它們在做什麼。"""
        import unicodedata
        for f in (PAGE, EXPORT_LIB, EXPORT_CLI, ROOT / "tools" / "behavior-viz" / "build-land-mask.py"):
            bad = sorted({hex(ord(c)) for c in text(f) if unicodedata.category(c) in ("Cf", "Cc", "Co", "Cs") and c not in "\n\t\r"})
            self.assertEqual(bad, [], f.name + " 含有看不見的字元")


class BehaviorVizExportTests(unittest.TestCase):
    def test_export_is_read_only(self):
        for f in (EXPORT_LIB, EXPORT_CLI):
            code = re.sub(r"/\*.*?\*/|//[^\n]*", "", text(f), flags=re.S)
            self.assertIsNone(re.search(r"\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE|GRANT)\b", code), f.name + " 只能有 SELECT")
        lib = text(EXPORT_LIB)
        self.assertIn("START TRANSACTION READ ONLY", lib, "整個匯出要包在唯讀交易裡")
        self.assertRegex(lib, r"catch \(\\Throwable \$e\) \{\s*try \{ \$this->db->exec\('ROLLBACK'\)", "出錯要收掉交易")
        self.assertRegex(lib, r"requireHandler\(\$level", "匯出含 IP：只有管理者")

    def test_cli_is_terminal_only_masked_by_default_and_not_deployed(self):
        cli = text(EXPORT_CLI)
        self.assertIn("PHP_SAPI !== 'cli'", cli, "只能從終端機執行，不能被網頁請求到")
        self.assertIn("'mask' => !isset($args['no-mask'])", cli, "預設要遮罩")
        self.assertIn("Ioc\\Access::HANDLER", cli)
        for name in ("install.sh", "update.sh", "uninstall.sh"):
            p = ROOT / name
            if p.exists():
                self.assertNotIn("export-behavior-viz", text(p), name + " 不能部署匯出腳本（只給管理員手動執行）")
                self.assertNotIn("behavior-viz", text(p), name + " 不能部署 3D 頁面（它引用外部 CDN）")
        self.assertIn("'BehaviorVizExport'", text(ROOT / "librenms" / "ioc" / "bootstrap.php"))


if __name__ == "__main__":
    unittest.main()
