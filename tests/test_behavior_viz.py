"""3D 連線視圖（tools/behavior-viz/behavior-3d.html）與資料匯出（tools/export-behavior-viz.php）的靜態檢查。

頁面會發布成 Artifact（存在 claude.ai），而且會在瀏覽器裡讀入使用者的真實資料：這些檢查擋的是「資料被送出去、被存起來、被當成 HTML」。
行為本身（分頁、載入驗證、WebGL 失效替代、手機版）是用瀏覽器實際操作驗證的，見 docs/ioc/behavior-analysis.md。
"""
import base64
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE = ROOT / "tools" / "behavior-viz" / "behavior-3d.html"
THREE_URLS = ["https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js", "https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"]
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

    def test_only_the_two_pinned_jsdelivr_scripts_are_loaded(self):
        src = text(PAGE)
        urls = re.findall(r"""(?:src|href)\s*=\s*["'](https?://[^"']+)["']""", src)
        self.assertEqual(urls, THREE_URLS, "只能有兩個外部資源：釘在 0.128.0 的 three.js 與 OrbitControls（r128 的 OrbitControls 在 cdnjs 沒有；Artifact 的 CSP 放行 cdn.jsdelivr.net/npm/）")

    def test_orbit_controls_replace_the_hand_written_orbit(self):
        js = script(text(PAGE))
        self.assertIn("new THREE.OrbitControls(camera, canvas)", js)
        for setting in ("enableDamping = true", "dampingFactor", "enablePan = false", "minDistance", "maxDistance", "minPolarAngle", "maxPolarAngle"):
            self.assertIn("controls." + setting, js, "OrbitControls 的設定：" + setting)
        self.assertIsNone(re.search(r"addEventListener\('wheel'|tRadius|orbit\.yaw|orbit\.pitch|ptrs\b", js), "自寫的軌道控制（wheel／pointer／平滑）要整個刪掉，交給 OrbitControls")
        self.assertIn("THREE.OrbitControls", js.split("function initGL()")[1].split("buildGlobe();")[0], "initGL 要檢查 OrbitControls 是否載入，沒有就退成文字版")
        self.assertRegex(js, r"controls\.update\(\)", "每幀要呼叫 controls.update()（阻尼）")
        self.assertRegex(js, r"dragPx|>\s*5\b", "點選要和拖曳分開（移動超過 5px 不算點選）")

    def test_embed_mode_trusts_only_the_same_origin_parent_and_validates_the_data(self):
        js = script(text(PAGE))
        self.assertIn("'viz-ready'", js)
        self.assertIn("'viz-data'", js)
        handler = js[js.index("addEventListener('message'"):]
        handler = handler[:handler.index("});") + 3]
        self.assertIn("e.origin !== location.origin", handler, "只接受同源的訊息")
        self.assertIn("e.source !== window.parent", handler, "只接受父視窗送來的訊息")
        self.assertIn("validate(", handler, "收到的資料一樣要逐欄驗證")
        self.assertRegex(js, r"postMessage\(\{ type: 'viz-ready' \}, location\.origin\)", "回報就緒的 targetOrigin 要是 location.origin，不能是 '*'")
        self.assertNotIn("postMessage(", js.replace("postMessage({ type: 'viz-ready' }, location.origin)", ""), "頁面只能送一種訊息")
        self.assertRegex(js, r"EMBED\s*=\s*.*embed.*window\.parent\s*!==\s*window", "內嵌模式要同時看網址參數與是否在 iframe 裡")

    def test_viewer_controls_cluster_and_mobile_sheet(self):
        src = text(PAGE)
        for ident in ("btnHome", "btnRotate", "btnZoomIn", "btnZoomOut", "sheet"):
            self.assertIn('id="' + ident + '"', src, "借自參考檔的操作元件：" + ident)
        self.assertIn("controls.autoRotate", script(src))
        self.assertIsNone(re.search(r"@import|<link\b", src), "樣式不能引用外部檔案")
        # 例外：SVG 命名空間（createElementNS 用，不是網路位址）
        self.assertIsNone(re.search(r"""['"`]https?://(?!www\.w3\.org/2000/svg)""", script(src)), "腳本裡不能寫死任何外部網址")

    def test_inline_script_is_syntactically_valid(self):
        # 其他靜態檢查都是字串比對，行尾多一個註解就能把同一行後面的程式吃掉而全部照過（做換圖示時真的發生過）
        node = shutil.which("node")
        if not node:
            self.skipTest("這台沒有 node，略過語法檢查")
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "page.js"
            p.write_text(script(text(PAGE)), encoding="utf-8")
            r = subprocess.run([node, "--check", str(p)], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)

    def test_labels_sit_on_the_device_they_name(self):
        src = text(PAGE)
        js = script(src)
        # 標籤原本錨在面板上方 1.4 單位，會壓在上一台、最上面那台的標籤還被裁出畫面，讓人把卡片的 IP 對到錯的設備
        self.assertIn("const sp = project(P);", js, "標籤的錨點是自己設備的中心")
        self.assertIsNone(re.search(r"project\(P\.clone\(\)\.add\(", js), "不能再把錨點往上偏")
        self.assertNotIn("translate(-50%,-100%)", src)
        self.assertNotIn("translate(-50%, -100%)", src)
        self.assertEqual(js.count("translate(-50%,-50%)"), 1)
        self.assertRegex(src, r"\.tag3d \{[^}]*translate\(-50%, -50%\)")

    def test_devices_are_built_from_primitives_and_keep_the_state_behaviour(self):
        js = script(text(PAGE))
        self.assertIsNone(re.search(r"GLTFLoader|OBJLoader|TextureLoader|ImageLoader|FileLoader|new\s+Image\s*\(", js), "設備圖示全用內建幾何與 canvas 畫的光暈拼出來，不載入模型或圖片")
        self.assertIn("function buildCloud(cluster)", js)
        self.assertNotIn("buildServer", js, "目的地只畫成雲：資料沒有設備類型，不能憑空換成伺服器、路由器之類")
        self.assertIn("buildCloud(it.others)", js, "每個目的地一朵雲，「其他 N 個目的地」是一團（和單一目的地分得出來）")
        self.assertIn("if (cluster) {", js)
        self.assertIn("panelPos(k, items.length, it.others)", js, "雲比伺服器大，要比照往下挪，不然會壓到上一台")
        # 來源筆電不跟著球面法線倒下去：每幀抵銷球的旋轉
        self.assertRegex(js, r"srcDev\.quaternion\.copy\(tmpQ\)\.invert\(\)\.multiply\(DEV_Q\)")
        # 整台筆電都能點（看不見的命中盒），缺資料的設備連細節都不畫、也點不到；雲的外框只在缺資料時畫成空框
        self.assertIn("pickables.push(srcHit)", js)
        self.assertIn("p.mesh.visible = p.detail.visible = st.st !== 'missing'", js)
        self.assertIn("p.edges.visible = st.st === 'missing'", js)
        self.assertIn("srcBodyMat.opacity = ss === 'missing'", js, "來源缺資料時筆電本體也要淡出，不能只剩螢幕變淡")
        self.assertIn("srcKeyMat.opacity = srcBodyMat.opacity", js)
        self.assertRegex(js, r"p\.glow\.material\.color\.setHex\(sty\.edge\)", "光暈的顏色跟著狀態")
        for state in ("known", "new", "idle", "partial", "missing", "hidden"):
            self.assertRegex(js, state + r": \{[^}]*led: 0x", "每種狀態都要有指示燈顏色：" + state)
        # 直式螢幕把伺服器放大，窄螢幕的標籤只寫 IP，否則標籤比圖示還寬會把它蓋住
        self.assertRegex(js, r"layout\.s = asp >= 1\.2 \? 1 : 2")
        self.assertIn("p.others || narrow() ? p.d.ip", js)

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
        for name in ("install.sh", "update.sh", "uninstall.sh", "system/install-behavior.sh"):
            p = ROOT / name
            if p.exists():
                self.assertNotIn("export-behavior-viz", text(p), name + " 不能部署匯出腳本（只給管理員手動執行）")
        self.assertIn("'BehaviorVizExport'", text(ROOT / "librenms" / "ioc" / "bootstrap.php"))


VENDOR = ROOT / "librenms" / "html" / "vendor" / "three"
MAKE_LOCAL = ROOT / "tools" / "behavior-viz" / "make-local-page.py"
# three r128（npm three@0.128.0）；OrbitControls 在 jsdelivr 與 unpkg 的 SHA-256 相同
VENDOR_SHA256 = {"three.min.js": "9274bbcec8d96168626c732b5d31c775aa8cfb7eaa0599bec0c175908a2c1ce2",
                 "OrbitControls.js": "02bb4ade710f3e607329e37a21f098bc3ac70eb6e33daf8a65e79f4db785e7b2"}


def load_make_local():
    import importlib.util
    spec = importlib.util.spec_from_file_location("make_local_page", MAKE_LOCAL)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


class BehaviorVizLocalCopyTests(unittest.TestCase):
    """入口內嵌播放：VM 上不能引用外部 CDN，所以部署的是同一份頁面原始碼轉換出來的本機副本＋repo 內釘死雜湊的 three.js。"""

    def test_vendored_three_files_are_exactly_the_pinned_release(self):
        import hashlib
        for name, digest in VENDOR_SHA256.items():
            self.assertEqual(hashlib.sha256((VENDOR / name).read_bytes()).hexdigest(), digest, name + " 的內容和釘住的版本不同（換行被改掉？被手動改過？）")
        self.assertIn("MIT License", text(VENDOR / "LICENSE"))
        self.assertIn("librenms/html/vendor/** -text", text(ROOT / ".gitattributes"), "第三方檔案不能被換行轉換")

    def test_local_page_replaces_only_the_two_script_urls_and_has_no_external_url(self):
        mod = load_make_local()
        src = text(PAGE)
        full = mod.make_local(src, "aaaaaaaaaaaa", "bbbbbbbbbbbb")
        # Artifact 檢視器會替頁面加上文件外框；本機副本自己要有，不然 iframe 會用怪異模式（quirks）渲染
        self.assertTrue(full.startswith("<!doctype html>") and full.rstrip().endswith("</html>"), "要有 doctype 與 html，才是標準模式")
        self.assertIn('<meta charset="utf-8">', full[:300])
        self.assertTrue(full.startswith(mod.WRAP_HEAD) and full.endswith(mod.WRAP_TAIL))
        out = full[len(mod.WRAP_HEAD):len(full) - len(mod.WRAP_TAIL)]
        self.assertIn('<script src="vendor/three/three.min.js?v=aaaaaaaaaaaa"></script>', out)
        self.assertIn('<script src="vendor/three/OrbitControls.js?v=bbbbbbbbbbbb"></script>', out)
        self.assertIsNone(re.search(r"https?://(?!www\.w3\.org/2000/svg)", out), "本機副本不能有任何外部網址")
        back = out.replace("vendor/three/three.min.js?v=aaaaaaaaaaaa", THREE_URLS[0]).replace("vendor/three/OrbitControls.js?v=bbbbbbbbbbbb", THREE_URLS[1])
        self.assertEqual(back, src.rstrip("\n"), "轉換只能換這兩個網址，其他一個字都不能動（單一來源；只有檔尾的換行會被外框接手）")
        self.assertEqual(re.findall(r"""<script src="([^"]+)">""", out), ["vendor/three/three.min.js?v=aaaaaaaaaaaa", "vendor/three/OrbitControls.js?v=bbbbbbbbbbbb"])

    def test_transform_refuses_a_page_it_does_not_understand(self):
        mod = load_make_local()
        src = text(PAGE)
        for broken in (src.replace(THREE_URLS[0], "https://example.com/x.js"), src.replace('<script src="' + THREE_URLS[1] + '"></script>', ""),
                       src + '<script src="https://evil.example/x.js"></script>', src.replace(THREE_URLS[0], THREE_URLS[0] + "?x=1"),
                       src + '<script src="//evil.example/x.js"></script>', src + '<script src="local.js"></script>', src + "<script  src='/x.js'></script>"):
            with self.assertRaises(ValueError):
                mod.make_local(broken, "a" * 12, "b" * 12)
        with self.assertRaises(ValueError):
            mod.make_local(src, "../etc/passwd", "bbbbbbbbbbbb")      # 版本戳記只收雜湊

    def test_install_script_deploys_vendor_files_and_the_local_page_and_uninstall_removes_them(self):
        inst = text(ROOT / "system" / "install-behavior.sh")
        self.assertIn("for f in three.min.js OrbitControls.js LICENSE; do", inst, "三個檔都要部署")
        self.assertIn("../librenms/html/vendor/three/$f", inst)
        self.assertIn('html/vendor/three', inst)
        self.assertIn('html/ioc-behavior-viz.html', inst)
        self.assertIn("make-local-page.py", inst)
        self.assertIn("command -v python3", inst, "沒有 python3 就略過播放器，不能讓整個安裝失敗")
        # 轉換結果不能由 root 直接寫進 librenms 可寫的網站目錄（可預測的檔名＋會跟隨符號連結）：先寫進 mktemp 目錄，再用 install 放過去
        self.assertIn('viz_tmp="$(mktemp -d)"', inst)
        self.assertIn('"$viz_tmp/ioc-behavior-viz.html" --vendor-dir', inst)
        self.assertRegex(inst, r'install -m 644 -o "\$LIBRENMS_USER" -g "\$LIBRENMS_USER" "\$viz_tmp/ioc-behavior-viz\.html" "\$LIBRENMS_DIR/html/ioc-behavior-viz\.html"')
        self.assertNotRegex(inst, r'make-local-page\.py[^\n]*"\$LIBRENMS_DIR/html/ioc-behavior-viz\.html"', "轉換腳本的輸出不能直接指向網站目錄")
        self.assertNotRegex(inst, r'chown "\$LIBRENMS_USER":"\$LIBRENMS_USER" "\$LIBRENMS_DIR/html/ioc-behavior-viz', "不用會跟隨符號連結的 chown")
        uninst = text(ROOT / "uninstall.sh")
        self.assertIn("html/ioc-behavior-viz.html", uninst)
        self.assertIn("html/vendor/three", uninst)


if __name__ == "__main__":
    unittest.main()
