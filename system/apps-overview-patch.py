#!/usr/bin/env python3
"""
Idempotently register oracle-* applications into the LibreNMS Apps 概觀
graph list（pages/apps.inc.php 內寫死的 $graphs 陣列）。

為什麼需要：
  includes/html/pages/apps/overview.inc.php 對每個 app 畫縮圖時用
      $graph_type = $graphs[$app->app_type][0] ?? '';
      $graph_array['type'] = 'application_' . $app->app_type . '_' . $graph_type;
  自訂的 oracle-<alias> app 不在 apps.inc.php 寫死的 $graphs 陣列中，
  $graph_type 取到空字串 → type 變成 'application_oracle-<alias>_'（結尾空 metric）
  → graph.inc.php 找不到對應 graph 檔 → 概觀頁顯示
      "application*oracle-<alias>_ Graph Template Missing"（紅字）。
  注意：個別 app 頁（pages/apps/oracle-l1hweb.inc.php）自帶 $ora_graphs，
  不受影響，只有「概觀」頁（overview.inc.php）會踩到。

本 patch 在 app 列表 foreach 前注入受管理區塊，對「所有 oracle-* app_type」
動態注冊 graph 清單（不硬編個別 alias），故未來透過 GUI 新增任何 Oracle DB，
概觀頁都自動有縮圖，無需再 patch。overview.inc.php 只取 [0]（sessions）當縮圖，
給完整清單只是為了相容未來可能改用整份清單。

幂等：已存在 BEGIN..END 區塊則整段替換為最新內容；否則插入於
      `foreach ($apps as $app_group) {`（此時所有 $graphs[...] 字面定義已完成）之前。

⚠️ apps.inc.php 是 LibreNMS 內建檔，LibreNMS 升級會覆蓋 → 升級後重跑
   install.sh（或 update.sh）即可恢復本 patch。

Usage: python3 apps-overview-patch.py [/path/to/apps.inc.php]
"""
import io
import re
import sys

PATH = sys.argv[1] if len(sys.argv) > 1 else \
    "/opt/librenms/includes/html/pages/apps.inc.php"

BEGIN = "// BEGIN oracle-mon overview"
END = "// END oracle-mon overview"
BLOCK = (
    BEGIN + "\n"
    "foreach (\\App\\Models\\Application::query()->where('app_type', 'like', 'oracle-%')"
    "->distinct()->pluck('app_type') as $__ora_t) {\n"
    "    $graphs[$__ora_t] = ['sessions', 'buffer', 'tablespaces', 'health', 'dataguard', "
    "'mview', 'io', 'sql', 'redo', 'lib_cache', 'sga', 'sga_memory', 'waits'];\n"
    "}\n"
    + END + "\n"
)

with io.open(PATH, "r", encoding="utf-8") as f:
    text = f.read()

# 已存在 → 整段替換（更新到最新內容）
if BEGIN in text and END in text:
    new_text = re.sub(
        r"[ \t]*" + re.escape(BEGIN) + r".*?" + re.escape(END) + r"\n?",
        lambda _m: BLOCK,  # lambda：避免 BLOCK 內反斜線（\App）被當 re repl 轉義
        text,
        flags=re.DOTALL,
    )
    if new_text != text:
        with io.open(PATH, "w", encoding="utf-8") as f:
            f.write(new_text)
        print("UPDATED oracle-mon overview block")
    else:
        print("ALREADY UP TO DATE - no change")
    sys.exit(0)

# 插入錨點：app 列表的 foreach（所有 $graphs[...] 字面定義都在它之前）
anchor = "foreach ($apps as $app_group) {"
idx = text.find(anchor)
if idx == -1:
    print("ANCHOR NOT FOUND - apps.inc.php not patched; LibreNMS 版本結構可能已變")
    sys.exit(1)

new_text = text[:idx] + BLOCK + "\n" + text[idx:]
with io.open(PATH, "w", encoding="utf-8") as f:
    f.write(new_text)
print("INSERTED oracle-mon overview block")
