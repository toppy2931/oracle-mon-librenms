#!/usr/bin/env python3
"""
Patch LibreNMS resources/views/map/custom-view.blade.php to make the
custom-map refresh interval configurable via $config['custom_map_refresh']
(falls back to $page_refresh if not set).

冪等：用 marker comment 偵測；已 patch 過則 skip。

Usage: python3 custom-map-patch.py [/path/to/custom-view.blade.php]
"""
import io
import re
import sys

PATH = sys.argv[1] if len(sys.argv) > 1 else \
    "/opt/librenms/resources/views/map/custom-view.blade.php"

# 用一個 sentinel comment 確認是否已 patch
MARKER = "{{-- oracle-mon: custom_map_refresh patched --}}"

# 原本：sec: {{$page_refresh}},
# 改後：sec: {{ \LibrenmsConfig::get('custom_map_refresh', $page_refresh) }},
NEW_EXPR = "{{ \\LibrenmsConfig::get('custom_map_refresh', $page_refresh) }}"

with io.open(PATH, "r", encoding="utf-8") as f:
    text = f.read()

if MARKER in text:
    print("ALREADY PATCHED - no change")
    sys.exit(0)

# 兩處 $page_refresh 都要改：
#   line ~186: sec: {{$page_refresh}},
#   line ~194: cur.sec = {{$page_refresh}};
# 注意：NEW_EXPR 含反斜線（\LibrenmsConfig），不能當 re.sub 的 repl 字串直接傳
#       （會把 \L 當 backreference 解析失敗）。用 lambda 繞過 backreference 解析。
patched, n = re.subn(r"\{\{\s*\$page_refresh\s*\}\}", lambda m: NEW_EXPR, text)

if n == 0:
    print("ANCHOR NOT FOUND - {{$page_refresh}} not present; nothing patched")
    sys.exit(1)

# 在檔尾加 marker（在 blade 註解內，使用者看不到，但 grep 偵測得到）
patched = patched.rstrip() + "\n" + MARKER + "\n"

with io.open(PATH, "w", encoding="utf-8") as f:
    f.write(patched)

print(f"PATCHED {n} occurrence(s) of {{$page_refresh}} → custom_map_refresh fallback")
