#!/bin/bash
# manage-host-net.sh — 產生 monitor-vm 的 netplan 靜態網路設定（IP / 遮罩 / 閘道）
#
# 由 oracle-mon-apply.sh（systemd、root）呼叫，不直接給 web sudo（避免 web 能改網路）。
# 設計原則：**只寫檔、不套用**。寫好 /etc/netplan 後須由人到 console 執行
#           `sudo netplan apply` 才會生效——避免遠端套用瞬間斷線把主機鎖死。
#
# Usage: manage-host-net.sh plan <new_ip> <cidr_prefix> <gateway>
#   會：備份現有 /etc/netplan/*.yaml → 寫入新設定（保留偵測到的 DNS）→
#       `netplan generate` 驗證語法（不套用）→ 回 JSON（含預覽與套用指令）。
set -u

ACTION="${1:-}"
[ "$ACTION" = "plan" ] || { printf '{"ok":false,"error":"usage: %s plan <ip> <cidr> <gateway>"}\n' "$0"; exit 1; }

IP="${2:-}"; CIDR="${3:-}"; GW="${4:-}"
CIDR="${CIDR#/}"   # 容許輸入 "/24" 或 "24"

BASE=/var/lib/oracle-mon
BK_DIR="$BASE/netplan-backup/$(date +%Y%m%d-%H%M%S)"
NETPLAN_DIR=/etc/netplan
OUT_FILE="$NETPLAN_DIR/50-cloud-init.yaml"   # 取代現有主設定（先備份）
CLOUD_DROP=/etc/cloud/cloud.cfg.d/99-disable-network-config.cfg

err(){ printf '{"ok":false,"error":"%s"}\n' "$1"; exit 1; }

# ── 驗證 ──────────────────────────────────────────────
echo "$IP" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || err "新 IP 格式不正確"
echo "$GW" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || err "閘道格式不正確"
echo "$CIDR" | grep -qE '^[0-9]{1,2}$' || err "遮罩需為 CIDR 字首（0-32）"
[ "$CIDR" -ge 0 ] && [ "$CIDR" -le 32 ] || err "CIDR 字首需在 0..32"
for o in $(echo "$IP" | tr '.' ' ') $(echo "$GW" | tr '.' ' '); do
    [ "$o" -le 255 ] || err "IP/閘道八位元組超出範圍"
done

# ── 偵測主介面 ────────────────────────────────────────
IFACE="$(ip route show default 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}')"
[ -n "$IFACE" ] || IFACE="$(ip -o -4 addr show 2>/dev/null | awk '$2!="lo"{print $2; exit}')"
[ -n "$IFACE" ] || err "偵測不到主要網路介面"

# 找出目前定義該介面的 netplan 檔（作備份/DNS 來源）
PRIMARY="$(grep -lE "(^|[[:space:]])${IFACE}:" "$NETPLAN_DIR"/*.yaml 2>/dev/null | head -1)"
[ -n "$PRIMARY" ] || PRIMARY="$OUT_FILE"

# ── 保留現有 DNS（先 resolvectl，再退而 grep 現檔）────────
DNS_ADDRS="$(resolvectl dns "$IFACE" 2>/dev/null | sed -E 's/^Link[^:]*: *//' | tr -s ' ' '\n' | grep -E '^[0-9A-Fa-f:.]+$' || true)"
SEARCH="$(resolvectl domain "$IFACE" 2>/dev/null | sed -E 's/^Link[^:]*: *//; s/~//g' | tr -s ' ' '\n' | grep -vE '^$' || true)"
if [ -z "$DNS_ADDRS" ] && [ -f "$PRIMARY" ]; then
    DNS_ADDRS="$(awk 'BEGIN{a=0} /nameservers:/{n=1} n&&/addresses:/{a=1;next} a&&/^[[:space:]]*-/{s=$0; gsub(/[^0-9A-Fa-f:.]/,"",s); if(s!="")print s; next} a&&/[^[:space:]-]/{a=0;n=0}' "$PRIMARY")"
fi

# ── 備份現有設定 ──────────────────────────────────────
mkdir -p "$BK_DIR"
cp -a "$NETPLAN_DIR"/*.yaml "$BK_DIR"/ 2>/dev/null || true
[ -f "$CLOUD_DROP" ] && cp -a "$CLOUD_DROP" "$BK_DIR"/ 2>/dev/null || true

# ── 組 YAML ───────────────────────────────────────────
TMP="$(mktemp)"
{
    echo "network:"
    echo "  version: 2"
    echo "  ethernets:"
    echo "    ${IFACE}:"
    echo "      addresses:"
    echo "        - \"${IP}/${CIDR}\""
    if [ -n "$DNS_ADDRS" ]; then
        echo "      nameservers:"
        echo "        addresses:"
        for d in $DNS_ADDRS; do echo "          - ${d}"; done
        if [ -n "$SEARCH" ]; then
            echo "        search:"
            for s in $SEARCH; do echo "          - ${s}"; done
        fi
    fi
    echo "      routes:"
    echo "        - to: \"default\""
    echo "          via: \"${GW}\""
} > "$TMP"

# 防 cloud-init 重開機後覆蓋網路設定
mkdir -p "$(dirname "$CLOUD_DROP")"
printf 'network: {config: disabled}\n' > "$CLOUD_DROP"
chmod 644 "$CLOUD_DROP"

# 寫入並設權限（netplan 要求 600，否則告警）
install -m 600 -o root -g root "$TMP" "$OUT_FILE"
rm -f "$TMP"

# ── 語法驗證（netplan generate 不會套用到 live 介面）──────
if ! gen_err="$(netplan generate 2>&1)"; then
    # 還原備份，撤銷本次寫入
    cp -a "$BK_DIR"/*.yaml "$NETPLAN_DIR"/ 2>/dev/null || true
    err "netplan 語法驗證失敗：$(printf '%s' "$gen_err" | tr -d '\n\"' | cut -c1-200)"
fi

PREVIEW="$(sed 's/\\/\\\\/g; s/"/\\"/g' "$OUT_FILE" | awk '{printf "%s\\n", $0}')"

printf '{"ok":true,"applied":false,"iface":"%s","file":"%s","backup":"%s","apply_cmd":"sudo netplan apply","preview":"%s","note":"設定已寫入但尚未套用；請確認 console 可達後執行 sudo netplan apply（套用瞬間連線會切到新 IP）。還原：將 %s 內的 *.yaml 複製回 %s 後再 netplan apply。"}\n' \
    "$IFACE" "$OUT_FILE" "$BK_DIR" "$PREVIEW" "$BK_DIR" "$NETPLAN_DIR"
