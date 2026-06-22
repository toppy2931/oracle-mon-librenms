#!/bin/bash
# manage-dashboard-layout.sh — 管理 Oracle 戰情室版面偏好（全機共用）
#   每張卡片各自的區塊顯隱 + 卡片排序
#
# 由 oracle-dashboard-layout.php 經 sudo 呼叫（sudoers 白名單）。
#
# 設定檔：/etc/oracle-mon/dashboard-layout.conf
#   CARD_ORDER="l1hweb db2"                    # 卡片顯示順序（alias）
#   CARD_HIDDEN="l1hweb=mview,warn;db2=dg"      # 每張卡片隱藏的區塊
#
# Usage:
#   manage-dashboard-layout.sh get
#       → {"status":"ok","order":[...],"hidden":{"l1hweb":["mview","warn"],...}}
#   manage-dashboard-layout.sh set "<order>" "<hidden_map>"
#       order      : "l1hweb,db2"（逗號或空白分隔）
#       hidden_map : "l1hweb=mview,warn;db2=dg"（卡片以 ; 分隔，區塊以 , 分隔）
set -e

ACTION="${1:?usage: $0 get|set <order> <hidden_map>}"
CONF_DIR="/etc/oracle-mon"
CONF_FILE="$CONF_DIR/dashboard-layout.conf"
VALID_BLOCKS="dg mview health ts warn"

is_valid_block(){ echo " $VALID_BLOCKS " | grep -q " $1 "; }
is_valid_alias(){ echo "$1" | grep -qE '^[A-Za-z0-9_-]+$'; }

# 逗號/空白分隔字串 → JSON 陣列（token 須已驗證）
to_json_array() {
    local first=1 tok out="["
    for tok in $(printf '%s' "$1" | tr ',' ' '); do
        [ $first -eq 1 ] && first=0 || out="$out,"
        out="$out\"$tok\""
    done
    printf '%s]' "$out"
}

read_conf() {
    CARD_ORDER=""
    CARD_HIDDEN=""
    # 值一律加引號寫入（見 set），source 安全
    [ -f "$CONF_FILE" ] && . "$CONF_FILE" || true
}

case "$ACTION" in
    get)
        read_conf
        # 以空白切 pair（pair 內無空白）→ for 在主 shell 執行，邏輯單純可靠
        hid="{"
        firstp=1
        for pair in $(printf '%s' "$CARD_HIDDEN" | tr ';' ' '); do
            a="${pair%%=*}"
            b="${pair#*=}"
            [ $firstp -eq 1 ] && firstp=0 || hid="$hid,"
            hid="$hid\"$a\":$(to_json_array "$b")"
        done
        hid="$hid}"
        printf '{"status":"ok","order":%s,"hidden":%s}\n' \
            "$(to_json_array "$CARD_ORDER")" "$hid"
        ;;

    set)
        RAW_ORDER=$(printf '%s' "${2:-}" | tr ',' ' ')
        RAW_HIDDEN="${3:-}"

        # 驗證 + 清理 order
        CLEAN_ORDER=""
        for tok in $RAW_ORDER; do
            is_valid_alias "$tok" || { printf '{"status":"error","error":"invalid alias: %s"}\n' "$tok"; exit 1; }
            CLEAN_ORDER="$CLEAN_ORDER $tok"
        done
        CLEAN_ORDER="${CLEAN_ORDER# }"

        # 驗證 + 清理 hidden map（以空白切 pair，for 在主 shell，exit 1 確實中止整支）
        CLEAN_HIDDEN=""
        for pair in $(printf '%s' "$RAW_HIDDEN" | tr ';' ' '); do
            a="${pair%%=*}"
            blist="${pair#*=}"
            is_valid_alias "$a" || { printf '{"status":"error","error":"invalid alias: %s"}\n' "$a"; exit 1; }
            cb=""
            for blk in $(printf '%s' "$blist" | tr ',' ' '); do
                is_valid_block "$blk" || { printf '{"status":"error","error":"invalid block: %s"}\n' "$blk"; exit 1; }
                cb="$cb,$blk"
            done
            cb="${cb#,}"
            if [ -n "$cb" ]; then CLEAN_HIDDEN="$CLEAN_HIDDEN;$a=$cb"; fi
        done
        CLEAN_HIDDEN="${CLEAN_HIDDEN#;}"

        mkdir -p "$CONF_DIR"
        # 值一律雙引號包住，避免 source 時把空白後面的字當指令
        cat > "$CONF_FILE" <<CONFEOF
CARD_ORDER="$CLEAN_ORDER"
CARD_HIDDEN="$CLEAN_HIDDEN"
CONFEOF
        chown root:root "$CONF_FILE"
        chmod 640 "$CONF_FILE"

        # 回傳寫入後的最新狀態
        "$0" get
        ;;

    *)
        printf '{"status":"error","error":"unknown action: %s"}\n' "$ACTION"
        exit 1
        ;;
esac
