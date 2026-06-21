#!/bin/bash
#
# manage-mgmt-cidrs.sh — 管理「管理網段持久化設定檔」並套用防火牆（供 GUI 區塊 D 呼叫）
#
# 三層信任鏈下層：由 oracle-firewall.php 經 sudo 呼叫（sudoers NOPASSWD 白名單）。
# 設定檔：/etc/oracle-mon/mgmt-cidrs.conf（一行一個 CIDR，# 註解）。
#
# 用法：
#   manage-mgmt-cidrs.sh list                 → JSON {auto[], extra[], ports}
#   manage-mgmt-cidrs.sh add  172.16.5.0/24   → 加入設定檔並套用，JSON {ok}
#   manage-mgmt-cidrs.sh remove 172.16.5.0/24 → 從設定檔移除、刪 ufw 規則，JSON {ok}
#
set -euo pipefail

CIDR_CONF="/etc/oracle-mon/mgmt-cidrs.conf"
MGMT_PORTS="22,80,443,9000,8990,8099"
ACTION="${1:-}"
CIDR="${2:-}"

json_err(){ printf '{"ok":false,"error":"%s"}\n' "$1"; exit 0; }

valid_cidr(){
    # IPv4 CIDR：四段 0-255 + /0-32
    echo "$1" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}/[0-9]{1,2}$' || return 1
    local ip="${1%/*}" mask="${1#*/}"
    [ "$mask" -ge 0 ] && [ "$mask" -le 32 ] || return 1
    local IFS=.; set -- $ip
    for o in "$@"; do [ "$o" -le 255 ] || return 1; done
    return 0
}

detect_auto(){
    ip -o -f inet route show scope link 2>/dev/null \
        | awk '{print $1}' | grep -vE '^169\.254\.' | sort -u
}

read_conf(){
    [ -f "$CIDR_CONF" ] || return 0
    sed 's/#.*//; s/[[:space:]]//g' "$CIDR_CONF" | awk 'NF'
}

# 把陣列輸出成 JSON 字串陣列
json_arr(){
    local first=1 out="["
    while IFS= read -r x; do
        [ -n "$x" ] || continue
        [ $first -eq 1 ] && first=0 || out="$out,"
        out="$out\"$x\""
    done
    echo "$out]"
}

apply_fw(){
    mkdir -p /etc/oracle-mon
    # 自動偵測 + 設定檔，全部開放管理埠（ufw allow 冪等）
    local cidrs
    cidrs="$( { detect_auto; read_conf; } | sort -u )"
    IFS=',' read -ra PORTS <<< "$MGMT_PORTS"
    while IFS= read -r c; do
        [ -n "$c" ] || continue
        for p in "${PORTS[@]}"; do
            ufw allow from "$c" to any port "$p" proto tcp comment "mgmt-auto" >/dev/null 2>&1 || true
        done
    done <<< "$cidrs"
}

case "$ACTION" in
  list)
    AUTO="$(detect_auto | json_arr)"
    EXTRA="$(read_conf | json_arr)"
    printf '{"ok":true,"auto":%s,"extra":%s,"ports":"%s"}\n' "$AUTO" "$EXTRA" "$MGMT_PORTS"
    ;;

  add)
    valid_cidr "$CIDR" || json_err "CIDR 格式不正確：$CIDR"
    mkdir -p /etc/oracle-mon
    touch "$CIDR_CONF"
    if grep -qxF "$CIDR" <(read_conf); then
        json_err "網段已存在：$CIDR"
    fi
    echo "$CIDR" >> "$CIDR_CONF"
    chmod 644 "$CIDR_CONF"
    apply_fw
    printf '{"ok":true,"added":"%s"}\n' "$CIDR"
    ;;

  remove)
    valid_cidr "$CIDR" || json_err "CIDR 格式不正確：$CIDR"
    [ -f "$CIDR_CONF" ] || json_err "設定檔不存在"
    grep -qxF "$CIDR" <(read_conf) || json_err "設定檔中找不到：$CIDR"
    # 從設定檔移除該行
    grep -vxF "$CIDR" "$CIDR_CONF" > "${CIDR_CONF}.tmp" 2>/dev/null || true
    mv "${CIDR_CONF}.tmp" "$CIDR_CONF"
    # 刪除該 CIDR 對應的 ufw 規則
    IFS=',' read -ra PORTS <<< "$MGMT_PORTS"
    for p in "${PORTS[@]}"; do
        ufw delete allow from "$CIDR" to any port "$p" proto tcp >/dev/null 2>&1 || true
    done
    printf '{"ok":true,"removed":"%s"}\n' "$CIDR"
    ;;

  rules)
    # 列出 ufw 中「管理埠」目前的允許來源（含舊手動規則、Anywhere），反映真實狀態
    out="["; first=1
    while IFS= read -r ln; do
        ln="$(echo "$ln" | sed 's/  */ /g; s/^ //; s/ $//; s/"/'\''/g')"
        [ -z "$ln" ] && continue
        [ $first -eq 1 ] && first=0 || out="$out,"
        out="$out\"$ln\""
    done < <(ufw status 2>/dev/null \
        | grep -E '^(22|80|443|8080|8099|8990|9000)(/tcp)?([[:space:]]|\(v6\))' \
        | grep -E 'ALLOW')
    printf '{"ok":true,"rules":%s}\n' "$out]"
    ;;

  *)
    json_err "未知動作：$ACTION（需 list|add|remove|rules）"
    ;;
esac
