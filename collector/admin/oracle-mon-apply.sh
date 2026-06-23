#!/bin/bash
# oracle-mon-apply.sh — 佇列套用器：由 systemd path unit 觸發，以 root 執行
# （無 ProtectSystem 限制），把 web 排入的特權請求實際套用。
#
# 讀 /var/lib/oracle-mon/requests/*.req（每行一個 argv），依白名單分派到既有
# manage-mgmt-cidrs.sh / manage-nas-backup.sh，把 JSON 結果寫 results/<id>.json。
#
# 信任邊界：requests 目錄為 root:700，只有 queue-request.sh（root，經 sudo）能寫；
#           web（librenms）無法直接塞請求。applier 仍以白名單再驗 type/action。
set -u

BASE=/var/lib/oracle-mon
REQ_DIR="$BASE/requests"
RES_DIR="$BASE/results"
ADMIN=/opt/oracle-mon/admin
LOG=/var/log/oracle-admin.log
LOCK="$BASE/.apply.lock"

mkdir -p "$REQ_DIR" "$RES_DIR"

# 單一實例（path unit 可能連續觸發）
exec 9>"$LOCK"
flock -n 9 || exit 0

shopt -s nullglob
for req in "$REQ_DIR"/*.req; do
    id="$(basename "$req" .req)"
    mapfile -t P < "$req"
    type="${P[0]:-}"
    action="${P[1]:-}"
    args=("${P[@]:2}")

    case "$type/$action" in
        fw/add|fw/remove)
            out="$("$ADMIN/manage-mgmt-cidrs.sh" "$action" "${args[@]}" 2>>"$LOG")" ;;
        nas/save|nas/sync|nas/unmount)
            out="$("$ADMIN/manage-nas-backup.sh" "$action" "${args[@]}" 2>>"$LOG")" ;;
        net/plan)
            out="$("$ADMIN/manage-host-net.sh" plan "${args[@]}" 2>>"$LOG")" ;;
        snmpd/update)
            if "$ADMIN/update-snmpd-extends.sh" >> "$LOG" 2>&1; then
                out='{"ok":true,"msg":"snmpd reloaded"}'
            else
                out='{"ok":false,"error":"snmpd 更新失敗，詳見 oracle-admin.log"}'
            fi ;;
        *)
            out='{"ok":false,"error":"applier 拒絕未知請求"}' ;;
    esac

    # 取最後一行合法 JSON 物件當結果（避免 apt 等雜訊污染）
    json="$(printf '%s\n' "$out" | grep -E '^\{.*\}$' | tail -1)"
    [ -n "$json" ] || json='{"ok":false,"error":"applier：無 JSON 輸出（詳見 oracle-admin.log）"}'
    printf '%s\n' "$json" > "$RES_DIR/$id.json"
    chmod 600 "$RES_DIR/$id.json"

    echo "$(date '+%Y-%m-%d %H:%M:%S') [APPLY] id=$id $type/$action" >> "$LOG"
    rm -f "$req"
done

# 清掉超過 1 小時沒被取走的舊結果
find "$RES_DIR" -name '*.json' -mmin +60 -delete 2>/dev/null || true
