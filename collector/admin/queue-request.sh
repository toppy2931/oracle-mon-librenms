#!/bin/bash
# queue-request.sh — 把「特權操作請求」排入佇列，交由 root 權限的 systemd applier 套用。
#
# 由 web（librenms 經 sudo）呼叫。本身只寫 /var/lib（php-fpm ProtectSystem=full
# 下 /var 可寫、/etc 唯讀），真正動 /etc/ufw、/etc/fstab、systemd、mount 的特權
# 操作交給 oracle-mon-apply.sh（systemd 觸發、root、無 namespace 限制）。
#
# Usage: queue-request.sh <type> <action> [args...]
#   type/action 白名單：fw/add  fw/remove  nas/save  nas/sync  nas/unmount
#
# 流程：寫 requests/<id>.req（每行一個 argv token）→ 等 applier 產生
#       results/<id>.json（最多 ~12s）→ 輸出之；逾時則回 {queued:true}。
set -e

BASE=/var/lib/oracle-mon
REQ_DIR="$BASE/requests"
RES_DIR="$BASE/results"

TYPE="${1:-}"
ACTION="${2:-}"
shift 2 2>/dev/null || true

# 白名單：擋掉任意 type/action（applier 端會再驗一次）
case "$TYPE/$ACTION" in
    fw/add|fw/remove|nas/save|nas/sync|nas/unmount|net/plan) ;;
    *) printf '{"ok":false,"error":"不允許的請求：%s/%s"}\n' "$TYPE" "$ACTION"; exit 1 ;;
esac

mkdir -p "$REQ_DIR" "$RES_DIR"
chmod 700 "$REQ_DIR" "$RES_DIR"

# 唯一 id（不依賴 uuidgen）：epoch 奈秒 + PID
id="$(date +%s%N)-$$"
tmp="$REQ_DIR/.$id.tmp"
req="$REQ_DIR/$id.req"

# argv 一行一個 token（type, action, args...）
{
    printf '%s\n' "$TYPE"
    printf '%s\n' "$ACTION"
    for a in "$@"; do printf '%s\n' "$a"; done
} > "$tmp"
chmod 600 "$tmp"
mv "$tmp" "$req"      # 原子改名，避免 applier 讀到半截檔

# 等 applier 回結果（path unit 觸發通常 <1s；nas save 含 apt 安裝可能較久 → 逾時回 queued）
res="$RES_DIR/$id.json"
for _ in $(seq 1 60); do
    if [ -f "$res" ]; then
        cat "$res"
        rm -f "$res"
        exit 0
    fi
    sleep 0.2
done

printf '{"ok":true,"queued":true,"id":"%s","note":"已排入佇列，數秒內由系統套用；請稍後重新整理狀態"}\n' "$id"
