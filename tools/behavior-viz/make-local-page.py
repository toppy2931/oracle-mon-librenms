#!/usr/bin/env python3
"""把 behavior-3d.html（Artifact 版，three.js 從 jsdelivr 載入）轉成入口內嵌播放用的本機副本。

VM 上的入口不能引用外部 CDN，所以 system/install-behavior.sh 在部署時執行：

    python3 make-local-page.py tools/behavior-viz/behavior-3d.html /opt/librenms/html/ioc-behavior-viz.html \\
        --vendor-dir /opt/librenms/html/vendor/three

轉換**只換兩個 script 網址**（three.min.js、OrbitControls.js → 本機 vendor/three/…?v=<檔案雜湊前 12 碼>，雜湊讓更新後不會吃到舊快取），
其餘一個字都不動：Artifact 與入口用的是同一份原始碼。頁面不是預期的樣子（網址對不上、多出別的外部 script）就拒絕，不產生半成品。
"""
import argparse
import hashlib
import re
import sys
from pathlib import Path

THREE = "https://cdn.jsdelivr.net/npm/three@0.128.0/build/three.min.js"
ORBIT = "https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"
LOCAL_THREE = "vendor/three/three.min.js"
LOCAL_ORBIT = "vendor/three/OrbitControls.js"
SCRIPT_TAG = '<script src="%s"></script>'
STAMP = re.compile(r"\A[0-9a-f]{8,64}\Z")
# Artifact 檢視器會替頁面補上文件外框（doctype、charset、viewport、[hidden] 規則）；VM 上的本機副本要自己有，不然 iframe 會用怪異模式渲染
WRAP_HEAD = ('<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8">'
             '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
             '<style>:root{color-scheme:dark}body{margin:0;padding:0}img{max-width:100%}[hidden]{display:none!important}</style></head><body>\n')
WRAP_TAIL = "\n</body></html>\n"


def make_local(html: str, three_stamp: str, orbit_stamp: str) -> str:
    for stamp in (three_stamp, orbit_stamp):
        if not STAMP.match(stamp):
            raise ValueError("版本戳記只收十六進位雜湊：%r" % stamp)
    for url in (THREE, ORBIT):
        if html.count(SCRIPT_TAG % url) != 1:
            raise ValueError("頁面要剛好有一個 %s" % (SCRIPT_TAG % url))
    external = re.findall(r"""<script\b[^>]*\bsrc\s*=\s*["']([^"']+)["']""", html)
    if sorted(external) != sorted([THREE, ORBIT]):
        raise ValueError("頁面有預期之外的 script 來源：%s" % external)
    out = html.replace(SCRIPT_TAG % THREE, SCRIPT_TAG % ("%s?v=%s" % (LOCAL_THREE, three_stamp)))
    out = out.replace(SCRIPT_TAG % ORBIT, SCRIPT_TAG % ("%s?v=%s" % (LOCAL_ORBIT, orbit_stamp)))
    out = WRAP_HEAD + out.rstrip("\n") + WRAP_TAIL
    left = re.findall(r"https?://(?!www\.w3\.org/2000/svg)[^\s'\"`)]+", out)      # 例外：SVG 命名空間，不是網路位址
    if left:
        raise ValueError("轉換後還有外部網址：%s" % left)
    return out


def stamp_of(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()[:12]


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("src")
    ap.add_argument("dst")
    ap.add_argument("--vendor-dir", required=True, help="已部署的 vendor/three 目錄（用來算檔案雜湊當版本戳記）")
    args = ap.parse_args()
    vendor = Path(args.vendor_dir)
    try:
        out = make_local(Path(args.src).read_text(encoding="utf-8"), stamp_of(vendor / "three.min.js"), stamp_of(vendor / "OrbitControls.js"))
    except (ValueError, OSError) as e:
        print("轉換失敗：%s" % e, file=sys.stderr)
        return 1
    dst = Path(args.dst)
    tmp = dst.with_name(dst.name + ".tmp")
    tmp.write_text(out, encoding="utf-8", newline="\n")
    tmp.replace(dst)                      # 原子替換：瀏覽器不會讀到寫一半的檔案
    return 0


if __name__ == "__main__":
    sys.exit(main())
