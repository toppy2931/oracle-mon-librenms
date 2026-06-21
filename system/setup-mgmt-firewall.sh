#!/usr/bin/env bash
#
# setup-mgmt-firewall.sh — 自動偵測本機網段並開放「管理 UI 埠」（可攜，免每站手調）
#
# 為何存在：
#   佈署到不同客戶站點時，IP 與網段都不一樣，硬編碼網段（如 172.16.0.0/16）不可攜。
#   本腳本用 `ip route` 自動偵測「本機直連網段」，把各管理介面埠只開放給該網段，
#   既不暴露給整個網際網路，也不需要每個站點手動改防火牆。冪等，可重複執行。
#
# 用法：
#   sudo ./setup-mgmt-firewall.sh
#   sudo MGMT_PORTS="80,443,9000,8990,8099" ./setup-mgmt-firewall.sh
#   sudo EXTRA_CIDRS="10.20.0.0/16,192.168.50.0/24" ./setup-mgmt-firewall.sh   # 另含遠端管理網段
#
# 注意：
#   - 日誌「接收」埠（514/udp、12201）需對「log 來源」開放，來源可能不在本機網段，
#     故不納入本腳本預設；請依來源另行 `ufw allow`。
#   - 本腳本只「新增」允許規則，不刪除既有規則。要清掉本腳本加的規則：
#       sudo ufw status numbered | grep "mgmt-auto"   # 找出編號後逐一 ufw delete
#
set -euo pipefail

# 預設要保護的管理埠（tcp）。可用環境變數 MGMT_PORTS 覆寫。
#   22   SSH
#   80   LibreNMS Web / nginx
#   443  HTTPS（如有啟用）
#   9000 Graylog Web/API
#   8990 jt-glogarch Web UI
#   8099 jt-gelflow Web UI
MGMT_PORTS="${MGMT_PORTS:-22,80,443,9000,8990,8099}"
EXTRA_CIDRS="${EXTRA_CIDRS:-}"

say(){ echo -e "\033[1;36m[fw]\033[0m $*"; }
[ "$(id -u)" -eq 0 ] || { echo "請以 sudo 執行"; exit 1; }
command -v ufw >/dev/null 2>&1 || { echo "錯誤：未安裝 ufw"; exit 1; }
command -v ip  >/dev/null 2>&1 || { echo "錯誤：找不到 ip 指令"; exit 1; }

# 偵測本機直連 IPv4 網段（scope link = 同網段路由），排除 link-local 169.254/16
mapfile -t SUBNETS < <(ip -o -f inet route show scope link 2>/dev/null \
    | awk '{print $1}' | grep -vE '^169\.254\.' | sort -u)

# 併入使用者額外指定的網段
if [ -n "$EXTRA_CIDRS" ]; then
    IFS=',' read -ra _EX <<< "$EXTRA_CIDRS"
    SUBNETS+=("${_EX[@]}")
fi

if [ "${#SUBNETS[@]}" -eq 0 ]; then
    echo "錯誤：偵測不到任何本機網段，請用 EXTRA_CIDRS 明確指定，例如："
    echo "  sudo EXTRA_CIDRS=\"192.168.1.0/24\" ./setup-mgmt-firewall.sh"
    exit 1
fi

say "偵測到的管理網段：${SUBNETS[*]}"
say "將開放的管理埠：$MGMT_PORTS"
echo ""

IFS=',' read -ra PORTS <<< "$MGMT_PORTS"
for cidr in "${SUBNETS[@]}"; do
    for p in "${PORTS[@]}"; do
        p="$(echo "$p" | tr -d ' ')"
        [ -n "$p" ] || continue
        # ufw allow 本身冪等：同規則存在時會 Skipping
        ufw allow from "$cidr" to any port "$p" proto tcp comment "mgmt-auto" >/dev/null
        say "  allow $cidr -> ${p}/tcp"
    done
done

echo ""
say "完成。本腳本管理的規則（comment=mgmt-auto）："
ufw status | grep "mgmt-auto" || say "（尚無，請確認 ufw 為 active：sudo ufw status）"
