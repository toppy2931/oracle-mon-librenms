#!/usr/bin/env bash
#
# prepare-for-clone.sh — 把 monitor-vm 清成「乾淨樣板」，供匯出 OVA 後佈署到其他站點
#
# 為何存在：
#   monitor-vm（預設 IP 172.16.1.94）已裝好 LibreNMS + Graylog + jt-glogarch +
#   oracle-mon-librenms 客製套件。要把這台當「樣板」複製到其他 ESXi 主機時，
#   必須先清掉「這台獨有」的資料（受監控 DB 密碼、歷史圖表、機器身分、SSH 主機
#   金鑰...），否則每台 clone 都會共用同一組 SSH host key / machine-id，且新站點
#   會看到舊站點的歷史資料與帳密。
#
# 安全設計（避免把 VM 弄到開不了機或這次操作把自己鎖在外面）：
#   1. 預設 --dry-run：只印出「會做什麼」，不會真的動任何東西。
#   2. 真正執行需要 --yes，且互動模式下還要再手動輸入 CONFIRM 二次確認。
#   3. 每一步都是獨立函式，一步失敗不會中斷後面的步驟（非 set -e），
#      結束時印出每步 OK/SKIP/FAIL 總表。
#   4. 每個刪除動作前一定檢查路徑「存在且非空字串」才動手，絕不對空變數
#      展開後的路徑下 rm -rf（避免 rm -rf "$EMPTY_VAR"/* 变成 rm -rf /*）。
#   5. 不會修改網路設定（IP 本來就是樣板預設值 172.16.1.94，不需要重寫 netplan）、
#      不會呼叫 netplan apply、不會重開機、不會自動關機——最後要不要 poweroff
#      由人工確認後自己執行。
#   6. SSH 主機金鑰用「開機時偵測缺少才重生」的 systemd oneshot 服務處理，
#      不是砍掉金鑰後賭 openssh 套件會自動重建；就算判斷失誤，服務本身
#      冪等（金鑰存在就不會重跑），不會把新 clone 鎖在 SSH 外面。
#   7. LibreNMS 資料庫只 TRUNCATE「資料表」（先 SHOW TABLES 確認存在才動手），
#      不 DROP DATABASE、不動 users/config 等結構性資料表，預設保留現有帳密。
#
# 用法：
#   sudo ./prepare-for-clone.sh                 # 預覽模式（什麼都不會被刪除）
#   sudo ./prepare-for-clone.sh --yes           # 真正執行（互動仍會再問一次）
#   sudo ./prepare-for-clone.sh --yes --force   # 全自動免互動確認（僅供腳本化流程用，小心）
#
# 可選旗標（預設全部關閉／保守）：
#   --skip-oracle-mon       跳過清除受監控 DB 連線設定（區塊 A/B 的 dbs/*.conf）
#   --skip-librenms-db      跳過清除 LibreNMS 資料庫內的設備/歷史資料
#   --skip-graylog          跳過清除 Graylog / OpenSearch / MongoDB 資料
#   --skip-glogarch         跳過清除 jt-glogarch 歸檔檔案
#   --reset-librenms-users  同時清空 LibreNMS users 表（新站點需要重新建帳號，預設不做）
#   --apt-autoremove        額外跑 apt-get autoremove（預設不做，避免誤刪被依賴的套件）
#
set -uo pipefail

# ── 常數 ──────────────────────────────────────────────────────────
DEFAULT_IP="172.16.1.94"                      # 本樣板的預設/原生 IP，新站點請用 GUI 區塊 C 改
TS="$(date '+%Y%m%d-%H%M%S')"
LOG_FILE="/root/clone-prep-${TS}.log"         # 放 /root（不放 /tmp，因為腳本最後會清 /tmp）
ORACLE_MON_DIR="/opt/oracle-mon"
LIBRENMS_DIR="/opt/librenms"
LIBRENMS_DB_NAME="librenms"

DRY_RUN=1
FORCE=0
SKIP_ORACLE_MON=0
SKIP_LIBRENMS_DB=0
SKIP_GRAYLOG=0
SKIP_GLOGARCH=0
RESET_LIBRENMS_USERS=0
APT_AUTOREMOVE=0

declare -A STEP_STATUS   # 步驟名 -> OK / SKIP / FAIL

# ── 參數解析 ──────────────────────────────────────────────────────
for arg in "$@"; do
    case "$arg" in
        --yes)                  DRY_RUN=0 ;;
        --force)                FORCE=1 ;;
        --skip-oracle-mon)      SKIP_ORACLE_MON=1 ;;
        --skip-librenms-db)     SKIP_LIBRENMS_DB=1 ;;
        --skip-graylog)         SKIP_GRAYLOG=1 ;;
        --skip-glogarch)        SKIP_GLOGARCH=1 ;;
        --reset-librenms-users) RESET_LIBRENMS_USERS=1 ;;
        --apt-autoremove)       APT_AUTOREMOVE=1 ;;
        -h|--help)
            grep -E '^#( |$)' "$0" | sed 's/^# \?//'
            exit 0 ;;
        *)
            echo "未知參數：$arg（--help 看用法）" >&2
            exit 1 ;;
    esac
done

# ── 小工具 ────────────────────────────────────────────────────────
say(){  echo -e "\033[1;36m[prep]\033[0m $*" | tee -a "$LOG_FILE"; }
warn(){ echo -e "\033[1;33m[prep][警告]\033[0m $*" | tee -a "$LOG_FILE"; }
err(){  echo -e "\033[1;31m[prep][錯誤]\033[0m $*" | tee -a "$LOG_FILE"; }

# 每一步用這個包起來跑：失敗不中斷、記錄狀態、dry-run 時只印出會執行的內容
run_step(){
    local name="$1"; shift
    if [ $DRY_RUN -eq 1 ]; then
        say "（預覽）步驟「$name」將會執行 — 用 --yes 才會真的動手"
        STEP_STATUS["$name"]="DRY-RUN"
        return 0
    fi
    say "開始：$name"
    if "$@" 2>>"$LOG_FILE"; then
        say "完成：$name"
        STEP_STATUS["$name"]="OK"
    else
        err "失敗：$name（詳見 $LOG_FILE，繼續執行下一步）"
        STEP_STATUS["$name"]="FAIL"
    fi
}

# 安全刪除單一路徑：只有在路徑是「非空字串」且「真的存在」時才刪，防止空變數展開意外
safe_rm_rf(){
    local target="$1"
    if [ -z "$target" ] || [ "$target" = "/" ]; then
        err "safe_rm_rf 拒絕執行：路徑為空或為根目錄（$target）"
        return 1
    fi
    if [ -e "$target" ] || [ -L "$target" ]; then
        rm -rf -- "$target"
    fi
    return 0
}

# 安全刪除「萬用字元配對到的一批檔案」：逐一過 safe_rm_rf。
# 呼叫時務必把「路徑+萬用字元」整段包成一個字串傳入，例如：
#   safe_rm_rf_glob "/opt/oracle-mon/dbs/*.conf"     ← 正確：函式內部才展開
#   safe_rm_rf_glob "/opt/oracle-mon/dbs"/*.conf     ← 錯誤：呼叫前就被展開，
#                                                        配對多檔時其餘會遺漏
safe_rm_rf_glob(){
    local pattern="$1" f
    for f in $pattern; do
        # 沒配對到任何檔案時，變數展開會保留原始萬用字元字串本身；
        # 用 -e 排除這種「字面上不存在的路徑」，避免誤判為要刪除的目標
        [ -e "$f" ] || continue
        safe_rm_rf "$f"
    done
    return 0
}

# 去掉字串前後可能包著的單引號或雙引號（用來解析 .env 這類 KEY="value" / KEY='value')
strip_quotes(){
    local s="$1"
    s="${s%\"}"; s="${s#\"}"
    s="${s%\'}"; s="${s#\'}"
    printf '%s' "$s"
}

# 清空目錄內容但保留目錄本身（同樣先檢查存在）
safe_empty_dir(){
    local dir="$1"
    if [ -z "$dir" ] || [ "$dir" = "/" ]; then
        err "safe_empty_dir 拒絕執行：路徑為空或為根目錄（$dir）"
        return 1
    fi
    if [ -d "$dir" ]; then
        find "$dir" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + 2>/dev/null || true
    fi
    return 0
}

[ "$(id -u)" -eq 0 ] || { err "請以 root 執行（sudo ./prepare-for-clone.sh ...）"; exit 1; }

# ── 開場提示 + 二次確認 ──────────────────────────────────────────
echo ""
echo "=========================================================="
echo "  monitor-vm 樣板清理工具 — 匯出 OVA 前使用"
echo "=========================================================="
echo "  模式：$([ $DRY_RUN -eq 1 ] && echo '預覽（不會刪除任何東西）' || echo '★★★ 真正執行 ★★★')"
echo "  日誌：$LOG_FILE"
echo "  預設樣板 IP：$DEFAULT_IP（清完後不會改網路設定）"
echo "=========================================================="
echo ""

if [ $DRY_RUN -eq 0 ] && [ $FORCE -eq 0 ]; then
    warn "接下來會清除：受監控 DB 密碼設定、LibreNMS 歷史資料、Graylog 訊息、"
    warn "SSH 主機金鑰、machine-id、系統日誌等——此操作不可逆！"
    read -r -p "確定要繼續嗎？請輸入大寫 CONFIRM 以繼續： " ans
    if [ "$ans" != "CONFIRM" ]; then
        say "使用者未確認，中止（未做任何變更）"
        exit 0
    fi
fi

touch "$LOG_FILE" 2>/dev/null || LOG_FILE="/tmp/clone-prep-${TS}.log"
say "開始記錄到 $LOG_FILE"

# ── 步驟 0：拍一份清理前快照（不含密碼），方便日後對照 ─────────────
step_manifest(){
    local manifest="/root/clone-prep-manifest-${TS}.txt"
    {
        echo "# clone-prep 快照 — $(date)"
        echo "## 目前 IP"
        ip -4 -o addr show scope global 2>/dev/null | awk '{print $2, $4}'
        echo "## 受監控 DB 別名清單（不含密碼）"
        [ -d "$ORACLE_MON_DIR/dbs" ] && ls -1 "$ORACLE_MON_DIR/dbs"/*.conf 2>/dev/null | xargs -n1 basename || echo "（無）"
        echo "## netplan 設定檔案清單"
        ls -1 /etc/netplan/*.yaml 2>/dev/null || echo "（無）"
    } > "$manifest" 2>/dev/null
    say "快照已寫入 $manifest（供日後比對用，此檔不會被本腳本刪除）"
}

# ── 步驟 1：清 oracle-mon-librenms 客製套件的站點資料 ───────────────
step_oracle_mon(){
    local rc=0
    # 1a. 受監控 DB 連線設定（含明文密碼）
    if [ -d "$ORACLE_MON_DIR/dbs" ]; then
        # 注意：整個「路徑+萬用字元」必須包成一個字串傳入，不可讓呼叫端的
        # shell 先展開——否則配對到多個檔案時 safe_rm_rf_glob 只會收到
        # 一個參數（外層展開後的第一個檔名），其餘檔案會被漏掉。
        safe_rm_rf_glob "$ORACLE_MON_DIR/dbs/*.conf" || rc=1
        say "  已清空 $ORACLE_MON_DIR/dbs/*.conf"
    fi
    # 1b. 讓 snmpd extend 區塊跟著清掉（腳本本身有存在性檢查，找不到就 no-op）
    if [ -x "$ORACLE_MON_DIR/admin/update-snmpd-extends.sh" ]; then
        "$ORACLE_MON_DIR/admin/update-snmpd-extends.sh" || rc=1
    fi
    # 1c. 佇列殘檔
    safe_empty_dir "/var/lib/oracle-mon/requests"
    safe_empty_dir "/var/lib/oracle-mon/results"
    # 1d. 管理網段持久化設定（新站點網段不同，交給新站點用 GUI 區塊 D 重設）
    safe_rm_rf "/var/lib/oracle-mon/mgmt-cidrs.conf"
    # 1e. NAS 備份設定（含帳密）
    safe_rm_rf "/etc/oracle-mon/nas-backup.conf"
    safe_rm_rf "/etc/oracle-mon/nas-credentials"
    if command -v systemctl >/dev/null 2>&1; then
        systemctl disable --now oracle-nas-sync.timer >/dev/null 2>&1 || true
    fi
    # 1f. custom map 刷新設定（非敏感，但也是「這台站點自訂」，一併清）
    if [ -f "$LIBRENMS_DIR/config.php" ]; then
        sed -i "/custom_map_refresh/d" "$LIBRENMS_DIR/config.php" 2>/dev/null || true
    fi
    # 1g. 操作 log（保留檔案本身，只清內容，避免其他工具對檔案存在與否有假設）
    [ -f /var/log/oracle-admin.log ] && : > /var/log/oracle-admin.log
    return $rc
}

# ── 步驟 2：清 LibreNMS 資料（只 TRUNCATE 資料表，不動結構、不動 config）───
step_librenms_db(){
    command -v mysql >/dev/null 2>&1 || { warn "  找不到 mysql client，跳過 DB 清理"; return 0; }

    # 讀 LibreNMS 自己的 .env 取得 DB 連線資訊，避免把密碼寫死在本腳本裡
    local envf="$LIBRENMS_DIR/.env"
    [ -f "$envf" ] || { warn "  找不到 $envf，跳過 DB 清理"; return 0; }
    local db_user db_pass db_host db_name
    db_user="$(strip_quotes "$(grep -oP '^DB_USERNAME=\K.*' "$envf" 2>/dev/null)")"
    db_pass="$(strip_quotes "$(grep -oP '^DB_PASSWORD=\K.*' "$envf" 2>/dev/null)")"
    db_host="$(strip_quotes "$(grep -oP '^DB_HOST=\K.*' "$envf" 2>/dev/null)")"
    db_name="$(strip_quotes "$(grep -oP '^DB_DATABASE=\K.*' "$envf" 2>/dev/null)")"
    db_host="${db_host:-127.0.0.1}"
    db_name="${db_name:-$LIBRENMS_DB_NAME}"
    [ -n "$db_user" ] && [ -n "$db_pass" ] || { warn "  .env 讀不到 DB 帳密，跳過 DB 清理"; return 0; }

    # 只清「資料/歷史」表，絕不動 users / config / api_tokens 等帳號結構表
    local tables=(
        devices ports sensors applications application_metrics
        alert_log alert_ok eventlog syslog syslog_alert
        mempools processors storage ipv4_addresses ipv6_addresses
        vlans links ports_statistics devices_attribs device_perf
    )
    if [ $RESET_LIBRENMS_USERS -eq 1 ]; then
        tables+=(users users_prefs)
    fi

    # 用 MYSQL_PWD 環境變數傳密碼，避免 -p明文密碼 出現在 `ps aux` 可見的指令列
    local mysql_cmd=(mysql -h "$db_host" -u "$db_user" "$db_name")
    local rc=0
    for t in "${tables[@]}"; do
        # SHOW TABLES 先確認存在，不存在就跳過（版本差異、表名可能不同）
        if MYSQL_PWD="$db_pass" "${mysql_cmd[@]}" -N -e "SHOW TABLES LIKE '$t'" 2>>"$LOG_FILE" | grep -q "^$t\$"; then
            MYSQL_PWD="$db_pass" "${mysql_cmd[@]}" -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE \`$t\`; SET FOREIGN_KEY_CHECKS=1;" 2>>"$LOG_FILE" \
                && say "  已清空資料表 $t" \
                || { warn "  清空資料表 $t 失敗（略過）"; rc=1; }
        fi
    done

    # RRD 歷史圖檔（只有真的存在才刪）
    if [ -d "$LIBRENMS_DIR/rrd" ]; then
        safe_empty_dir "$LIBRENMS_DIR/rrd"
        say "  已清空 $LIBRENMS_DIR/rrd"
    fi
    # LibreNMS 自身 log
    if [ -d "$LIBRENMS_DIR/logs" ]; then
        find "$LIBRENMS_DIR/logs" -type f -name '*.log' -exec truncate -s 0 {} \; 2>/dev/null || true
    fi
    return $rc
}

# ── 步驟 3：清 Graylog / OpenSearch / MongoDB（找不到服務就整段跳過）──────
step_graylog(){
    local rc=0
    local found_any=0

    if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files 2>/dev/null | grep -q '^graylog-server'; then
        found_any=1
        systemctl stop graylog-server >/dev/null 2>&1 || true
        [ -d /var/log/graylog-server ] && find /var/log/graylog-server -type f -exec truncate -s 0 {} \; 2>/dev/null
    fi

    if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files 2>/dev/null | grep -q 'graylog-datanode'; then
        found_any=1
        systemctl stop graylog-datanode >/dev/null 2>&1 || true
        # opensearch 實際資料目錄依安裝方式而異，從 datanode.conf 讀 opensearch_data_location，
        # 讀不到就只印出提示、不亂猜路徑刪除，避免刪錯目錄
        local dn_conf="/etc/graylog/datanode/datanode.conf"
        local data_dir=""
        if [ -f "$dn_conf" ]; then
            data_dir="$(grep -oP '^opensearch_data_location\s*=\s*\K.*' "$dn_conf" 2>/dev/null | tr -d ' ')"
        fi
        if [ -n "$data_dir" ] && [ -d "$data_dir" ]; then
            safe_empty_dir "$data_dir"
            say "  已清空 OpenSearch 資料目錄 $data_dir"
        else
            warn "  找不到明確的 OpenSearch 資料目錄設定，未刪除任何資料——請於 $dn_conf 確認後手動清理"
            rc=1
        fi
        [ -d /var/log/graylog-datanode ] && find /var/log/graylog-datanode -type f -exec truncate -s 0 {} \; 2>/dev/null
    fi

    if command -v mongosh >/dev/null 2>&1; then
        found_any=1
        mongosh graylog --quiet --eval "db.dropDatabase()" >/dev/null 2>>"$LOG_FILE" \
            && say "  已清空 MongoDB graylog 資料庫" \
            || { warn "  MongoDB graylog 資料庫清理失敗（略過）"; rc=1; }
    elif command -v mongo >/dev/null 2>&1; then
        found_any=1
        mongo graylog --quiet --eval "db.dropDatabase()" >/dev/null 2>>"$LOG_FILE" \
            && say "  已清空 MongoDB graylog 資料庫" \
            || { warn "  MongoDB graylog 資料庫清理失敗（略過）"; rc=1; }
    fi

    [ $found_any -eq 0 ] && say "  未偵測到 Graylog 相關服務，跳過（可能未安裝於本機）"
    return $rc
}

# ── 步驟 4：清 jt-glogarch 歸檔檔案 + 自己的狀態資料庫 ─────────────────
step_glogarch(){
    local archive_dir="/data/graylog-archives"
    local app_dir="/opt/jt-glogarch"

    if [ ! -d "$archive_dir" ] && [ ! -d "$app_dir" ]; then
        say "  找不到 $archive_dir 或 $app_dir，跳過（可能未部署 jt-glogarch）"
        return 0
    fi

    local rc=0
    local has_service=0
    if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files 2>/dev/null | grep -q '^jt-glogarch'; then
        has_service=1
        systemctl stop jt-glogarch >/dev/null 2>&1 || true
    fi

    # 額外保險：只有當 /data 是獨立掛載點（非根目錄底下的一般資料夾）才清空，
    # 避免萬一路徑設定錯誤，實際上清到系統碟的一般目錄
    if [ -d "$archive_dir" ]; then
        if mountpoint -q /data 2>/dev/null; then
            safe_empty_dir "$archive_dir"
            say "  已清空 $archive_dir（/data 為獨立掛載點，安全）"
        else
            warn "  /data 不是獨立掛載點，為安全起見不自動清空 $archive_dir，請手動確認後處理"
            rc=1
        fi
    fi

    # jt-glogarch 自己的 SQLite 狀態庫（作業歷程/歸檔清單/通知設定/排程/行為稽核），
    # 獨立於 $archive_dir 的實體歸檔檔案，不屬於本 repo 但同台部署。不清的話：
    #   1. 會留下站點專屬的歷史紀錄（樣板不該有）
    #   2. 其中快取的 Graylog 認證狀態在 MongoDB 被清空後會失效，造成背景 API
    #      呼叫持續 401、被 jt-glogarch 判定 session 失效而不斷跳回登入頁
    # 清掉後服務重啟會自動建立全新空白 DB，不影響登入（登入是即時問 Graylog API 驗證）。
    if [ -f "$app_dir/jt-glogarch.db" ]; then
        safe_rm_rf "$app_dir/jt-glogarch.db"
        safe_rm_rf "$app_dir/jt-glogarch.db-shm"
        safe_rm_rf "$app_dir/jt-glogarch.db-wal"
        say "  已清空 jt-glogarch 狀態資料庫（作業歷程/歸檔清單/通知/排程）"
    fi

    # session 簽署金鑰：不清的話所有從本樣板 clone 出去的站點會共用同一把金鑰，
    # 清掉後下次啟動自動重新產生（跟 SSH host key 同一種邏輯）
    if [ -f "$app_dir/.session_secret" ]; then
        safe_rm_rf "$app_dir/.session_secret"
        say "  已清空 jt-glogarch session 簽署金鑰（下次啟動自動重新產生）"
    fi

    [ $has_service -eq 1 ] && { systemctl start jt-glogarch >/dev/null 2>&1 || true; }

    [ -d /var/log/jt-glogarch ] && find /var/log/jt-glogarch -type f -exec truncate -s 0 {} \; 2>/dev/null
    return $rc
}

# ── 步驟 5：系統層清理（快取、日誌、歷史紀錄；不動任何已安裝套件本體）──────
step_system_generic(){
    command -v apt-get >/dev/null 2>&1 && apt-get clean >/dev/null 2>&1
    if [ $APT_AUTOREMOVE -eq 1 ]; then
        command -v apt-get >/dev/null 2>&1 && apt-get autoremove -y >/dev/null 2>&1
    fi

    if command -v journalctl >/dev/null 2>&1; then
        journalctl --rotate >/dev/null 2>&1 || true
        journalctl --vacuum-time=1s >/dev/null 2>&1 || true
    fi

    # 清一般系統日誌內容（保留檔案，只清內容；本腳本自己的 log 在 /root，不受影響）
    find /var/log -type f \( -name '*.gz' -o -regex '.*\.[0-9]+$' \) -delete 2>/dev/null || true
    find /var/log -type f -exec truncate -s 0 {} \; 2>/dev/null || true

    # bash 歷史（不影響目前這個 shell 還在跑，只清檔案內容）
    for home in /root /home/*; do
        [ -f "$home/.bash_history" ] && : > "$home/.bash_history"
    done

    safe_rm_rf_glob "/var/lib/dhcp/*.leases"

    # /tmp、/var/tmp 放最後清；腳本本身已載入記憶體執行，這裡清空不影響本次執行
    safe_empty_dir "/tmp"
    safe_empty_dir "/var/tmp"
    return 0
}

# ── 步驟 6：重置 machine-id（systemd 官方文件建議的樣板作法，開機自動重生）──
step_machine_id(){
    # 官方作法：清空 /etc/machine-id，開機時 systemd 會自動生成新的
    : > /etc/machine-id
    if [ -e /var/lib/dbus/machine-id ] || [ -L /var/lib/dbus/machine-id ]; then
        rm -f /var/lib/dbus/machine-id
    fi
    ln -s /etc/machine-id /var/lib/dbus/machine-id
    return 0
}

# ── 步驟 7：SSH 主機金鑰 — 裝「開機時偵測缺少才重生」的 systemd 服務 ─────
#    先裝好服務並 enable，最後才刪金鑰；就算判斷失誤，服務本身冪等，
#    金鑰存在就不會重跑，不會把新 clone 鎖在 SSH 外面。
step_ssh_hostkeys(){
    command -v systemctl >/dev/null 2>&1 || { warn "  無 systemd，跳過 SSH host key 重生設定"; return 1; }

    cat > /etc/systemd/system/regen-ssh-hostkeys.service <<'EOF'
[Unit]
Description=Regenerate SSH host keys if missing (post-clone first boot)
Before=ssh.service sshd.service
ConditionPathExists=!/etc/ssh/ssh_host_rsa_key

[Service]
Type=oneshot
ExecStart=/usr/bin/ssh-keygen -A
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable regen-ssh-hostkeys.service >/dev/null 2>&1

    # 服務裝好、確認 enable 成功後，才刪除現有金鑰
    if systemctl is-enabled regen-ssh-hostkeys.service >/dev/null 2>&1; then
        rm -f /etc/ssh/ssh_host_*
        say "  已刪除現有 SSH host key，新 clone 開機時會由 regen-ssh-hostkeys.service 自動重生"
    else
        warn "  regen-ssh-hostkeys.service 未成功 enable，為安全起見不刪除現有 SSH host key"
        return 1
    fi
    return 0
}

# ── 步驟 8：確認網路設定（只檢查/回報，絕不改寫、絕不 netplan apply）──────
step_verify_network(){
    local cur_ip
    cur_ip="$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1)"
    if [ "$cur_ip" = "$DEFAULT_IP" ]; then
        say "  目前 IP（$cur_ip）與樣板預設 IP 一致，未變更任何網路設定"
    else
        warn "  目前 IP（$cur_ip）與樣板預設 IP（$DEFAULT_IP）不同"
        warn "  本腳本不會自動修改 netplan（避免弄壞連線）；若要讓樣板固定回到 $DEFAULT_IP，"
        warn "  請自行編輯 /etc/netplan/*.yaml 後 'sudo netplan apply'，或用 GUI 區塊 C 處理"
    fi
    ls /etc/netplan/*.yaml >/dev/null 2>&1 && {
        say "  目前 netplan 設定檔："
        ls -1 /etc/netplan/*.yaml | while read -r f; do say "    $f"; done
    }
    return 0
}

# ── 主流程 ────────────────────────────────────────────────────────
run_step "0. 清理前快照"            step_manifest
[ $SKIP_ORACLE_MON  -eq 0 ] && run_step "1. 清除 oracle-mon-librenms 站點資料" step_oracle_mon  || STEP_STATUS["1. 清除 oracle-mon-librenms 站點資料"]="SKIP"
[ $SKIP_LIBRENMS_DB -eq 0 ] && run_step "2. 清除 LibreNMS 歷史資料"           step_librenms_db || STEP_STATUS["2. 清除 LibreNMS 歷史資料"]="SKIP"
[ $SKIP_GRAYLOG     -eq 0 ] && run_step "3. 清除 Graylog/OpenSearch/Mongo"    step_graylog     || STEP_STATUS["3. 清除 Graylog/OpenSearch/Mongo"]="SKIP"
[ $SKIP_GLOGARCH    -eq 0 ] && run_step "4. 清除 jt-glogarch 歸檔"            step_glogarch    || STEP_STATUS["4. 清除 jt-glogarch 歸檔"]="SKIP"
run_step "5. 系統層清理（快取/日誌/歷史）" step_system_generic
run_step "6. 重置 machine-id"              step_machine_id
run_step "7. 設定 SSH host key 開機重生"   step_ssh_hostkeys
run_step "8. 確認網路設定（唯讀）"          step_verify_network

# ── 收尾報告 ──────────────────────────────────────────────────────
echo ""
echo "=========================================================="
echo "  執行結果總表"
echo "=========================================================="
for k in "${!STEP_STATUS[@]}"; do
    printf "  %-40s %s\n" "$k" "${STEP_STATUS[$k]}"
done | sort
echo "=========================================================="
echo ""

if [ $DRY_RUN -eq 1 ]; then
    say "以上為預覽結果，未做任何變更。確認無誤後執行：sudo $0 --yes"
else
    say "清理完成。日誌：$LOG_FILE"
    say "下一步（人工確認，本腳本不會自動做）："
    say "  1. 確認 GUI（/oracle-admin.php）與 LibreNMS 首頁仍可正常開啟"
    say "  2. 確認上面總表沒有 FAIL；有的話先排除再繼續"
    say "  3. 一切正常後手動關機：sudo poweroff"
    say "  4. 關機後在 ESXi/vCenter 匯出 OVA"
    say "  5. 新站點匯入後：改 IP（GUI 區塊 C）、新增受監控 DB、依需要重跑 install.sh"
fi
