#!/usr/bin/env python3
"""
Idempotently upsert the Oracle/監控工具 gear-menu entries into LibreNMS
resources/views/layouts/menu.blade.php.

Managed block (inside @can('admin')):
  - Oracle 監控管理      → /oracle-admin.php
  - Oracle 戰情室        → /oracle-dashboard.php
  - jt-gelflow 流量視覺化 → /jt-gelflow/（相對路徑，走 nginx 反代，IP 異動自動跟隨）
  - jt-glogarch 日誌歸檔 → https://<目前瀏覽器主機>:8990
       （jt-glogarch 強制 secure cookie / https_only，故須走 HTTPS；HTTP 會導致登入無法保持）
       用 JS location.hostname 動態組 URL，故 monitor-vm IP 異動時，
       按鈕連線會自動跟著使用者當前存取的 IP/主機名，無需改設定。

Upsert 行為：若已存在 BEGIN..END 區塊則「整段替換」為最新內容（可重跑更新）；
若不存在則插入於 Settings「Validate Config」@endcanany 之後。

Usage: python3 menu-patch.py [/path/to/menu.blade.php]
"""
import io
import re
import sys

PATH = sys.argv[1] if len(sys.argv) > 1 else \
    "/opt/librenms/resources/views/layouts/menu.blade.php"

BEGIN = "{{-- BEGIN oracle-mon menu --}}"
END = "{{-- END oracle-mon menu --}}"
BLOCK = (
    "                        " + BEGIN + "\n"
    "                        @can('admin')\n"
    "                        <li role=\"presentation\" class=\"divider\"></li>\n"
    "                        <li><a href=\"/oracle-admin.php\"><i class=\"fa fa-database fa-fw fa-lg\" aria-hidden=\"true\"></i> Oracle 監控管理</a></li>\n"
    "                        <li><a href=\"/oracle-dashboard.php\"><i class=\"fa fa-desktop fa-fw fa-lg\" aria-hidden=\"true\"></i> Oracle 戰情室</a></li>\n"
    "                        <li><a href=\"/jt-gelflow/\" target=\"_blank\"><i class=\"fa fa-globe fa-fw fa-lg\" aria-hidden=\"true\"></i> jt-gelflow 流量視覺化</a></li>\n"
    "                        <li><a href=\"#\" onclick=\"window.open('https://'+location.hostname+':8990','_blank');return false;\"><i class=\"fa fa-archive fa-fw fa-lg\" aria-hidden=\"true\"></i> jt-glogarch 日誌歸檔</a></li>\n"
    "                        @endcan\n"
    "                        " + END + "\n"
)

with io.open(PATH, "r", encoding="utf-8") as f:
    text = f.read()

# 已存在 → 整段替換（更新到最新內容）
if BEGIN in text and END in text:
    # 連同 BEGIN 行前的水平空白一起吃掉，避免每次重跑縮排累積變長
    new_text = re.sub(
        r"[ \t]*" + re.escape(BEGIN) + r".*?" + re.escape(END) + r"\n?",
        BLOCK,
        text,
        flags=re.DOTALL,
    )
    if new_text != text:
        with io.open(PATH, "w", encoding="utf-8") as f:
            f.write(new_text)
        print("UPDATED oracle-mon menu block")
    else:
        print("ALREADY UP TO DATE - no change")
    sys.exit(0)

# Preferred anchor: the @endcanany that closes the Settings (Validate Config) block.
lines = text.splitlines(keepends=True)
out = []
inserted = False
for i, ln in enumerate(lines):
    out.append(ln)
    if (not inserted) and "@endcanany" in ln:
        # only the first @endcanany after a 'Validate Config' reference
        prior = "".join(lines[max(0, i - 8):i + 1])
        if "Validate Config" in prior or "settings" in prior.lower():
            out.append(BLOCK)
            inserted = True

if not inserted:
    print("ANCHOR NOT FOUND - menu not patched; add the block manually (see README)")
    sys.exit(1)

with io.open(PATH, "w", encoding="utf-8") as f:
    f.write("".join(out))
print("INSERTED oracle-mon menu block")
