#!/usr/bin/env python3
"""
Idempotently inject the Oracle gear-menu entries into LibreNMS
resources/views/layouts/menu.blade.php.

Adds a managed block (Oracle 監控管理 + Oracle 戰情室) inside an @can('admin')
guard, right after the Settings "Validate Config" @endcanany. Safe to re-run:
detects the BEGIN marker and does nothing if already present.

Usage: python3 menu-patch.py [/path/to/menu.blade.php]
"""
import io
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
    "                        @endcan\n"
    "                        " + END + "\n"
)

with io.open(PATH, "r", encoding="utf-8") as f:
    text = f.read()

if BEGIN in text:
    print("ALREADY PRESENT - no change")
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
