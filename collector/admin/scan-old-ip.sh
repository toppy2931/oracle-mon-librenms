#!/bin/bash
# scan-old-ip.sh — scan known config files for hard-coded occurrences of an IP.
# Output: single-line JSON {"status":"ok","old_ip":...,"count":N,"matches":[...]}
# Read-only: never modifies any file.
#
# Usage:  scan-old-ip.sh <OLD_IP>
# Called by oracle-scan-old-ip.php and (post-update) by oracle-ip-update.php.

set -e
OLD_IP="${1:?usage: $0 <old_ip>}"

# IPv4 sanity (no shell injection via path char in IP)
echo "$OLD_IP" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' \
    || { printf '{"status":"error","error":"invalid_ip"}\n'; exit 1; }

# Targets to scan. Globs are expanded by the shell before the for-loop.
# 加新目標時：唯讀掃描，不限大小（小心 binary file），路徑要實際存在。
shopt -s nullglob
TARGETS=(
    # nginx
    /etc/nginx/conf.d/*.conf
    /etc/nginx/sites-enabled/*
    # LibreNMS
    /opt/librenms/resources/views/layouts/menu.blade.php
    /opt/librenms/.env
    # jt-gelflow / jt-ipam
    /opt/jt-gelflow/config.json
    /opt/jt-ipam/.env
    /opt/jt-ipam/config.json
    # Graylog
    /etc/graylog/server/server.conf
    /etc/graylog/datanode/datanode.conf
    # SNMP（trap target、agent address 可能寫 IP）
    /etc/snmp/snmpd.conf
    /etc/snmp/snmp.conf
    # Oracle 監控套件（DB 設定檔可能含 monitor URL callback）
    /opt/oracle-mon/dbs/*.conf
)

hits=()
for f in "${TARGETS[@]}"; do
    [ -f "$f" ] || continue
    # -F = fixed string (no regex surprises); -n = line numbers
    while IFS=: read -r ln content; do
        # Escape content as JSON string via jq -Rs ("raw input, slurp")
        text=$(printf '%s' "$content" | jq -Rs .)
        hits+=("{\"file\":\"$f\",\"line\":$ln,\"text\":$text}")
    done < <(grep -nF "$OLD_IP" "$f" 2>/dev/null || true)
done

if [ ${#hits[@]} -gt 0 ]; then
    matches="[$(IFS=,; echo "${hits[*]}")]"
else
    matches='[]'
fi

printf '{"status":"ok","old_ip":"%s","count":%d,"matches":%s}\n' \
    "$OLD_IP" "${#hits[@]}" "$matches"
