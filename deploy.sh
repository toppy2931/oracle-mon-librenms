#!/usr/bin/env bash
#
# deploy.sh — 從 GitHub 拉取最新版並完整重新部署 oracle-mon-librenms
#
# 用法（在 monitor-vm 上）：
#   sudo /opt/oracle-mon-librenms/deploy.sh
#
# 或安裝一次捷徑後直接輸入：
#   sudo oracle-mon-deploy
#
set -euo pipefail

REPO=/opt/oracle-mon-librenms
SYSTEMD_DIR=/etc/systemd/system
SUDOERS_DST=/etc/sudoers.d/oracle-admin
SHORTCUT=/usr/local/sbin/oracle-mon-deploy

say()  { echo -e "\033[1;36m[deploy]\033[0m $*"; }
ok()   { echo -e "\033[1;32m[  OK  ]\033[0m $*"; }
warn() { echo -e "\033[1;33m[ WARN ]\033[0m $*"; }

[ "$(id -u)" -eq 0 ] || { echo "請以 sudo 執行"; exit 1; }

cd "$REPO"

# ── 1. 拉取最新版（強制覆蓋，保留 dbs/*.conf）──────────────────────
say "從 GitHub 拉取最新版..."
before="$(git rev-parse HEAD)"
git fetch origin
git reset --hard origin/master
git clean -fd
after="$(git rev-parse HEAD)"

if [ "$before" = "$after" ]; then
    ok "已是最新版（$after）— 繼續重新部署"
else
    ok "更新：$before → $after"
    git log --oneline "${before}..${after}"
fi
echo

# ── 2. 部署 collector + PHP（update.sh 負責）──────────────────────
say "部署 collector scripts + PHP 客製頁..."
./update.sh
echo

# ── 3. Systemd units（update.sh 不處理這兩支）──────────────────────
say "檢查 systemd units..."
systemd_reload=0
for unit in oracle-mon-apply.path oracle-mon-apply.service; do
    src="$REPO/system/$unit"
    dst="$SYSTEMD_DIR/$unit"
    if [ ! -f "$src" ]; then
        warn "$unit 不在 repo，跳過"
        continue
    fi
    if ! cmp -s "$src" "$dst" 2>/dev/null; then
        install -m 644 -o root -g root "$src" "$dst"
        say "  已更新 $unit"
        systemd_reload=1
    fi
done
if [ "$systemd_reload" -eq 1 ]; then
    systemctl daemon-reload
    systemctl enable --now oracle-mon-apply.path
    ok "systemd daemon-reload 完成，oracle-mon-apply.path 已啟用"
else
    ok "systemd units 無異動"
fi
echo

# ── 4. Sudoers（update.sh 不處理）─────────────────────────────────
say "檢查 sudoers..."
src_sudo="$REPO/system/sudoers.oracle-admin"
if [ -f "$src_sudo" ]; then
    if ! cmp -s "$src_sudo" "$SUDOERS_DST" 2>/dev/null; then
        visudo -cf "$src_sudo" || { warn "sudoers 語法錯誤，跳過"; }
        install -m 440 -o root -g root "$src_sudo" "$SUDOERS_DST"
        ok "sudoers 已更新"
    else
        ok "sudoers 無異動"
    fi
else
    warn "system/sudoers.oracle-admin 不存在，跳過"
fi
echo

# ── 5. 確保佇列目錄存在並權限正確──────────────────────────────────
mkdir -p /var/lib/oracle-mon/requests /var/lib/oracle-mon/results
chmod 700 /var/lib/oracle-mon/requests /var/lib/oracle-mon/results

# ── 6. 確認 oracle-mon-apply.path 正常運行──────────────────────────
if systemctl is-active --quiet oracle-mon-apply.path; then
    ok "oracle-mon-apply.path 運行中"
else
    warn "oracle-mon-apply.path 未啟動，嘗試啟動..."
    systemctl enable --now oracle-mon-apply.path
fi
echo

# ── 7. 安裝捷徑（首次執行後可直接 sudo oracle-mon-deploy）──────────
if [ ! -L "$SHORTCUT" ] && [ ! -f "$SHORTCUT" ]; then
    ln -s "$REPO/deploy.sh" "$SHORTCUT"
    chmod +x "$REPO/deploy.sh"
    ok "已建立捷徑：$SHORTCUT → 往後可直接執行 sudo oracle-mon-deploy"
fi

# ── 完成 ───────────────────────────────────────────────────────────
echo -e "\033[1;32m════════════════════════════════════════\033[0m"
echo -e "\033[1;32m 部署完成！目前版本：$(git log --oneline -1)\033[0m"
echo -e "\033[1;32m════════════════════════════════════════\033[0m"
