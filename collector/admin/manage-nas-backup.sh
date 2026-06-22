#!/bin/bash
#
# manage-nas-backup.sh — NAS 備份（本地歸檔 → 同步到 NAS）管理（供 GUI 區塊 E 呼叫）
#
# 策略 B：jt-glogarch 歸檔仍寫本地 /data/graylog-archives（獨立 500G 碟），
# 再用 rsync 定期同步到 NAS 掛載點。NAS 掉線不影響歸檔本身。
#
# 三層信任鏈下層：由 oracle-nasbackup.php 經 sudo 呼叫（sudoers 白名單）。
#
# 子指令：
#   status                                          → JSON 現況
#   save <nfs|cifs> <server> <export> <mountpoint> <hourly|6h|daily> [cifs_user] [cifs_pass]
#   test                                            → 測試掛載點可達/可寫
#   sync                                            → 立即同步（timer 也呼叫此）
#   unmount                                         → 卸載 + 移除 fstab + 停用排程
#
set -euo pipefail

# 設定/憑證/時間戳放 /var/lib（非 /etc）：php-fpm.service 設 ProtectSystem=full，
# /etc 對其 sudo 子行程唯讀（EROFS）。fstab credentials= 也指向此處（本腳本自寫，一致）。
CONF=/var/lib/oracle-mon/nas-backup.conf
CRED=/var/lib/oracle-mon/nas-credentials
SRC=/data/graylog-archives
LAST=/var/lib/oracle-mon/nas-last-sync
TIMER=/etc/systemd/system/oracle-nas-sync.timer
SVC=/etc/systemd/system/oracle-nas-sync.service
FSTAG="# oracle-mon-nas"

ACTION="${1:-}"
json_err(){ printf '{"ok":false,"error":"%s"}\n' "$1"; exit 0; }
load(){ [ -f "$CONF" ] && . "$CONF" || true; }

mkdir -p /var/lib/oracle-mon

case "$ACTION" in
  status)
    load
    MOUNTED=false; mountpoint -q "${MOUNTPOINT:-/nonexistent}" 2>/dev/null && MOUNTED=true
    ENABLED=false; systemctl is-enabled oracle-nas-sync.timer >/dev/null 2>&1 && ENABLED=true
    LASTSYNC=""; [ -f "$LAST" ] && LASTSYNC="$(cat "$LAST")"
    ASIZE="$(du -sh "$SRC" 2>/dev/null | awk '{print $1}')"; ASIZE="${ASIZE:-0}"
    NAVAIL=""; $MOUNTED && NAVAIL="$(df -h "$MOUNTPOINT" 2>/dev/null | awk 'NR==2{print $4}')"
    printf '{"ok":true,"configured":%s,"protocol":"%s","server":"%s","export":"%s","mountpoint":"%s","schedule":"%s","mounted":%s,"enabled":%s,"last_sync":"%s","archive_size":"%s","nas_avail":"%s"}\n' \
        "$([ -f "$CONF" ] && echo true || echo false)" \
        "${PROTOCOL:-}" "${SERVER:-}" "${EXPORT:-}" "${MOUNTPOINT:-}" "${SCHEDULE:-}" \
        "$MOUNTED" "$ENABLED" "${LASTSYNC}" "${ASIZE}" "${NAVAIL}"
    ;;

  save)
    PROTOCOL="${2:-}"; SERVER="${3:-}"; EXPORT="${4:-}"; MOUNTPOINT="${5:-}"; SCHEDULE="${6:-}"
    CUSER="${7:-}"; CPASS="${8:-}"
    echo "$PROTOCOL"   | grep -qE '^(nfs|cifs)$'              || json_err "協定需為 nfs 或 cifs"
    echo "$SERVER"     | grep -qE '^[A-Za-z0-9.-]+$'         || json_err "NAS 位址格式不正確"
    echo "$EXPORT"     | grep -qE '^[A-Za-z0-9._/-]+$'       || json_err "匯出路徑/共享名格式不正確"
    echo "$MOUNTPOINT" | grep -qE '^/mnt/[A-Za-z0-9._-]+$'   || json_err "掛載點需為 /mnt/xxx"
    echo "$SCHEDULE"   | grep -qE '^(hourly|6h|daily)$'      || json_err "排程需為 hourly/6h/daily"

    # 安裝對應掛載工具
    if [ "$PROTOCOL" = nfs ]; then
        dpkg -l nfs-common 2>/dev/null | grep -q '^ii' || { apt-get update -qq && apt-get install -y -qq nfs-common; }
    else
        dpkg -l cifs-utils 2>/dev/null | grep -q '^ii' || { apt-get update -qq && apt-get install -y -qq cifs-utils; }
        [ -n "$CUSER" ] || json_err "CIFS 需提供帳號"
        printf 'username=%s\npassword=%s\n' "$CUSER" "$CPASS" > "$CRED"
        chmod 600 "$CRED"
    fi

    mkdir -p "$MOUNTPOINT"

    # 組 fstab 行（nofail：NAS 掉線不卡開機）
    if [ "$PROTOCOL" = nfs ]; then
        FSLINE="${SERVER}:${EXPORT} ${MOUNTPOINT} nfs nofail,_netdev,soft,timeo=150,retrans=3 0 0  ${FSTAG}"
    else
        FSLINE="//${SERVER}/${EXPORT} ${MOUNTPOINT} cifs credentials=${CRED},nofail,_netdev,iocharset=utf8,file_mode=0640,dir_mode=0750 0 0  ${FSTAG}"
    fi
    # 移除舊的本工具 fstab 行後重寫
    sed -i "\|${FSTAG}|d" /etc/fstab   # 用 | 當分隔；FSTAG 以 # 開頭，不可用 # 當分隔
    echo "$FSLINE" >> /etc/fstab

    # 先卸載再掛載（套用新設定）
    umount "$MOUNTPOINT" 2>/dev/null || true
    if ! mount "$MOUNTPOINT" 2>/tmp/nasmnt.err; then
        json_err "掛載失敗：$(tr -d '\n' < /tmp/nasmnt.err | sed 's/\"/ /g' | cut -c1-200)"
    fi

    # 寫設定檔
    cat > "$CONF" <<CONFEOF
PROTOCOL=$PROTOCOL
SERVER=$SERVER
EXPORT=$EXPORT
MOUNTPOINT=$MOUNTPOINT
SCHEDULE=$SCHEDULE
CONFEOF
    chmod 640 "$CONF"

    # 排程 OnCalendar 對應
    case "$SCHEDULE" in
        hourly) ONCAL="hourly" ;;
        6h)     ONCAL="*-*-* 00/6:00:00" ;;
        daily)  ONCAL="*-*-* 02:30:00" ;;
    esac
    cat > "$SVC" <<SVCEOF
[Unit]
Description=oracle-mon NAS sync (graylog archives -> NAS)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/opt/oracle-mon/admin/manage-nas-backup.sh sync
SVCEOF
    cat > "$TIMER" <<TIMEREOF
[Unit]
Description=oracle-mon NAS sync timer

[Timer]
OnCalendar=$ONCAL
Persistent=true

[Install]
WantedBy=timers.target
TIMEREOF
    systemctl daemon-reload
    systemctl enable --now oracle-nas-sync.timer >/dev/null 2>&1

    printf '{"ok":true,"mounted":true,"schedule":"%s"}\n' "$SCHEDULE"
    ;;

  test)
    load
    [ -n "${MOUNTPOINT:-}" ] || json_err "尚未設定 NAS"
    mountpoint -q "$MOUNTPOINT" || json_err "掛載點未掛載"
    TF="$MOUNTPOINT/.oracle-mon-write-test"
    if touch "$TF" 2>/dev/null; then rm -f "$TF"; printf '{"ok":true,"writable":true}\n'
    else json_err "掛載點不可寫（檢查 NAS 權限）"; fi
    ;;

  sync)
    load
    [ -n "${MOUNTPOINT:-}" ] || json_err "尚未設定 NAS"
    mountpoint -q "$MOUNTPOINT" || json_err "NAS 未掛載，略過同步"
    # 差異性備份（rsync 預設行為）：
    #   - 預設用 mtime + size 比對，只同步「不存在」或「大小/日期不同」的檔案
    #   - --update：若目標較新就跳過（安全網，避免從舊源覆蓋新目標）
    #   - --stats：尾端輸出統計（傳了 N 檔 / 跳過 M 檔 / 大小 / 速度）
    #   - -i (itemize)：每個被處理檔案的變更類型摘要
    #   - --no-perms/owner/group/omit-dir-times：相容 CIFS 不支援的屬性
    OUT=$(mktemp)
    ERR=/tmp/nassync.err
    rsync -rt --update --stats -i \
          --no-perms --no-owner --no-group --omit-dir-times \
          "$SRC/" "$MOUNTPOINT/" >"$OUT" 2>"$ERR" || {
        rm -f "$OUT"
        json_err "rsync 失敗：$(tr -d '\n' < "$ERR" | sed 's/\"/ /g' | cut -c1-200)"
    }
    # 從 --stats 解析關鍵數字
    files_xferred=$(grep -E "^Number of (regular )?files transferred:" "$OUT" | awk -F: '{print $2}' | tr -d ' ,')
    files_total=$(grep -E "^Number of files:" "$OUT" | awk -F: '{print $2}' | sed 's/[^0-9].*//' | tr -d ' ,')
    bytes_total=$(grep -E "^Total transferred file size:" "$OUT" | awk -F: '{print $2}' | tr -d ' ' | grep -oE '^[0-9,]+' | tr -d ',')
    rm -f "$OUT"
    : "${files_xferred:=0}"
    : "${files_total:=0}"
    : "${bytes_total:=0}"
    files_skipped=$(( files_total - files_xferred ))
    [ "$files_skipped" -lt 0 ] && files_skipped=0
    date '+%Y-%m-%d %H:%M:%S' > "$LAST"
    printf '{"ok":true,"synced_at":"%s","files_total":%d,"files_transferred":%d,"files_skipped":%d,"bytes_transferred":%d,"mode":"differential (rsync mtime+size)"}\n' \
        "$(cat "$LAST")" "$files_total" "$files_xferred" "$files_skipped" "$bytes_total"
    ;;

  unmount)
    load
    systemctl disable --now oracle-nas-sync.timer >/dev/null 2>&1 || true
    [ -n "${MOUNTPOINT:-}" ] && umount "${MOUNTPOINT}" 2>/dev/null || true
    sed -i "\|${FSTAG}|d" /etc/fstab   # 用 | 當分隔；FSTAG 以 # 開頭，不可用 # 當分隔
    rm -f "$CONF" "$CRED"
    printf '{"ok":true,"removed":true}\n'
    ;;

  *)
    json_err "未知動作：$ACTION"
    ;;
esac
