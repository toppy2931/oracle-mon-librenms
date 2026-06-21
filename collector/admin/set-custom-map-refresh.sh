#!/bin/bash
# set-custom-map-refresh.sh — manage LibreNMS custom_map_refresh (秒)
#
# 不走 lnms config:set（會被 config_definitions.json 白名單擋下），
# 直接維護 /opt/librenms/config.php 內的 $config['custom_map_refresh']。
#
# Usage:
#   set-custom-map-refresh.sh get          → 回 JSON {value: <int>|null}
#   set-custom-map-refresh.sh set <int>    → 寫入並 clear config cache
#   set-custom-map-refresh.sh clear        → 從 config.php 移除設定（fallback 回 page_refresh）

set -e
ACTION="${1:?usage: $0 get|set <seconds>|clear}"
CONFIG_FILE="/opt/librenms/config.php"
KEY='custom_map_refresh'
# 用 fixed-string 偵測，避免 grep -E 對 \s 解讀的跨發行版差異
FIXED_PAT="\$config['${KEY}']"

current_value() {
    # 取最後一個 $config['custom_map_refresh'] = NNN; 數字
    # 用 grep -F 字面比對，再 sed 抽出 = 之後的數字
    [ -f "$CONFIG_FILE" ] || { echo ""; return; }
    grep -F "$FIXED_PAT" "$CONFIG_FILE" 2>/dev/null \
        | sed -nE 's/^[[:space:]]*\$config\['"'"'[^'"'"']+'"'"'\][[:space:]]*=[[:space:]]*([0-9]+).*/\1/p' \
        | tail -1
}

remove_existing_lines() {
    # 移除既有所有相關行（含可能的重複）
    [ -f "$CONFIG_FILE" ] || return
    grep -vF "$FIXED_PAT" "$CONFIG_FILE" > "${CONFIG_FILE}.tmp" || true
    mv "${CONFIG_FILE}.tmp" "$CONFIG_FILE"
}

case "$ACTION" in
    get)
        v=$(current_value)
        if [ -z "$v" ]; then
            printf '{"status":"ok","value":null,"source":"fallback (page_refresh)"}\n'
        else
            printf '{"status":"ok","value":%d,"source":"config.php"}\n' "$v"
        fi
        ;;

    set)
        VALUE="${2:?usage: $0 set <seconds>}"
        echo "$VALUE" | grep -qE '^[0-9]+$' \
            || { printf '{"status":"error","error":"value must be a positive integer"}\n'; exit 1; }
        [ "$VALUE" -ge 5 ] && [ "$VALUE" -le 86400 ] \
            || { printf '{"status":"error","error":"value out of range (5..86400)"}\n'; exit 1; }

        # 確保 config.php 存在且有 <?php header
        if [ ! -f "$CONFIG_FILE" ]; then
            printf '<?php\n' > "$CONFIG_FILE"
        elif ! head -1 "$CONFIG_FILE" | grep -q '^<?php'; then
            # 罕見情境：existing file 沒 <?php，補上
            sed -i '1i <?php' "$CONFIG_FILE"
        fi

        # 先清掉所有舊行（去重 + 改值用同一 path）
        remove_existing_lines

        # Append 單一行（保證唯一）
        printf "\$config['%s'] = %d;  // managed by oracle-admin GUI\n" "$KEY" "$VALUE" >> "$CONFIG_FILE"
        chown librenms:librenms "$CONFIG_FILE"

        # 清 Laravel config cache 讓新值立即生效
        sudo -u librenms php /opt/librenms/artisan config:clear >/dev/null 2>&1 || true

        printf '{"status":"ok","value":%d,"action":"set"}\n' "$VALUE"
        ;;

    clear)
        if [ -f "$CONFIG_FILE" ] && grep -qF "$FIXED_PAT" "$CONFIG_FILE"; then
            remove_existing_lines
            sudo -u librenms php /opt/librenms/artisan config:clear >/dev/null 2>&1 || true
            printf '{"status":"ok","action":"clear"}\n'
        else
            printf '{"status":"ok","action":"noop","detail":"not set"}\n'
        fi
        ;;

    *)
        printf '{"status":"error","error":"unknown action: %s"}\n' "$ACTION"
        exit 1
        ;;
esac
