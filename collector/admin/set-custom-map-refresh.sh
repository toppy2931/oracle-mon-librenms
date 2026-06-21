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
PATTERN_RE="^\s*\$config\['${KEY}'\]\s*="

current_value() {
    [ -f "$CONFIG_FILE" ] || { echo ""; return; }
    grep -E "$PATTERN_RE" "$CONFIG_FILE" \
        | sed -E "s/.*=[[:space:]]*([0-9]+).*/\1/" \
        | tail -1
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

        [ -f "$CONFIG_FILE" ] || touch "$CONFIG_FILE"
        if grep -qE "$PATTERN_RE" "$CONFIG_FILE"; then
            # 替換既有行
            sed -i -E "s|${PATTERN_RE}.*|\$config['${KEY}'] = ${VALUE};  // managed by oracle-admin GUI|" "$CONFIG_FILE"
        else
            # 確保 <?php 之後 append（首次寫入）
            if ! grep -q '^<?php' "$CONFIG_FILE"; then
                printf '<?php\n' > "$CONFIG_FILE"
            fi
            printf "\n\$config['%s'] = %d;  // managed by oracle-admin GUI\n" "$KEY" "$VALUE" >> "$CONFIG_FILE"
        fi
        chown librenms:librenms "$CONFIG_FILE"

        # 清 Laravel config cache 讓新值立即生效
        sudo -u librenms php /opt/librenms/artisan config:clear >/dev/null 2>&1 || true

        printf '{"status":"ok","value":%d,"action":"set"}\n' "$VALUE"
        ;;

    clear)
        if [ -f "$CONFIG_FILE" ] && grep -qE "$PATTERN_RE" "$CONFIG_FILE"; then
            sed -i -E "/${PATTERN_RE}/d" "$CONFIG_FILE"
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
