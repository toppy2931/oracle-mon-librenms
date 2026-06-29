#!/usr/bin/env python3
"""
Idempotently patch LibreNMS application graph auth
(includes/html/graphs/application/auth.inc.php) so that any
oracle-<alias>_<metric> graph is ALWAYS bound to that alias's live app_id,
regardless of the id passed in by the caller.

為什麼需要（強制自動對應）：
  application graph 是用 $vars['id']（= dashboard widget 的「應用」欄位
  graph_application、或概觀/device 頁傳入的 app_id）去 firstWhere(app_id) 建 $app，
  再用 $app->app_id / $app->device 拼 RRD 路徑。
  若 widget 的「圖表類型」選了 oracle-paweb_*，但「應用」欄位選錯成別的 app_id
  （例：oracle-l1hweb=1，oracle-paweb=3），就會去找 app-oracle-paweb-1-*.rrd
  （不存在）→ 破圖（資訊看板顯示破圖、概觀顯示 No Data）。

本 patch 在 auth.inc.php 建 $app 之前注入一段：若 $subtype 形如
  oracle-<alias>_<metric>，就以 <alias> 反查唯一現役（deleted_at IS NULL）的
  app_id，覆蓋 $vars['id']。如此原本的 auth 邏輯就會用正確 id 建出正確的
  $app + $device，圖一律對應到 graph_type 指定的那個 Oracle DB。
  → widget「應用」欄位選什麼都不再影響；未來新增任何 Oracle 主機零配置自動正確。

  alias 僅含 [a-z0-9-]（見 oracle-db-add.php），不含底線，故 subtype 第一個底線
  即為 alias 與 metric 的分界，非貪婪比對不會誤判（sga_memory / lib_cache 等
  多段 metric 也安全）。非 oracle 的 subtype 完全不受影響（零副作用）。

幂等：已存在 BEGIN..END 區塊則整段替換；否則插入於
      `if (isset($vars['id'])` 之前。

⚠️ auth.inc.php 是 LibreNMS 內建檔，LibreNMS 升級會覆蓋 → 升級後重跑
   install.sh（或 update.sh）即可恢復本 patch。

Usage: python3 graph-app-autocorrect-patch.py [/path/to/auth.inc.php]
"""
import io
import re
import sys

PATH = sys.argv[1] if len(sys.argv) > 1 else \
    "/opt/librenms/includes/html/graphs/application/auth.inc.php"

BEGIN = "// BEGIN oracle-mon graph autocorrect"
END = "// END oracle-mon graph autocorrect"
BLOCK = (
    BEGIN + "\n"
    "// 強制自動對應：oracle-<alias>_<metric> 圖一律綁定該 alias 的現役 app_id，\n"
    "// 無視呼叫端（dashboard widget「應用」欄位等）傳入的 id，避免選錯 app_id 破圖。\n"
    "if (isset($subtype) && preg_match('/^(oracle-[a-z0-9-]+?)_/', $subtype, $__ora_m)) {\n"
    "    $__ora_id = \\App\\Models\\Application::query()->where('app_type', $__ora_m[1])\n"
    "        ->whereNull('deleted_at')->value('app_id');\n"
    "    if ($__ora_id) {\n"
    "        $vars['id'] = $__ora_id;\n"
    "    }\n"
    "}\n"
    + END + "\n"
)

with io.open(PATH, "r", encoding="utf-8") as f:
    text = f.read()

# 已存在 → 整段替換（更新到最新內容）
if BEGIN in text and END in text:
    new_text = re.sub(
        r"[ \t]*" + re.escape(BEGIN) + r".*?" + re.escape(END) + r"\n?",
        BLOCK,
        text,
        flags=re.DOTALL,
    )
    if new_text != text:
        with io.open(PATH, "w", encoding="utf-8") as f:
            f.write(new_text)
        print("UPDATED oracle-mon graph autocorrect block")
    else:
        print("ALREADY UP TO DATE - no change")
    sys.exit(0)

# 插入錨點：auth 邏輯起點（$app 在其內建立，故 patch 須在它之前覆蓋 $vars['id']）
anchor = "if (isset($vars['id'])"
idx = text.find(anchor)
if idx == -1:
    print("ANCHOR NOT FOUND - auth.inc.php not patched; LibreNMS 版本結構可能已變")
    sys.exit(1)

new_text = text[:idx] + BLOCK + "\n" + text[idx:]
with io.open(PATH, "w", encoding="utf-8") as f:
    f.write(new_text)
print("INSERTED oracle-mon graph autocorrect block")
