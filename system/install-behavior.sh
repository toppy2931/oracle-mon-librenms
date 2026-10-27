#!/usr/bin/env bash
# 部署 IOC 行為工作器。不安裝感測器，也不修改交換器或防火牆。
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ORACLE_MON_DIR="${ORACLE_MON_DIR:-/opt/oracle-mon}"
LIBRENMS_DIR="${LIBRENMS_DIR:-/opt/librenms}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"
[ "$(id -u)" -eq 0 ] || { echo '請以 root 執行'; exit 1; }
if [ "$ORACLE_MON_DIR" != /opt/oracle-mon ] || [ "$LIBRENMS_DIR" != /opt/librenms ]; then
    echo '行為工作器的 systemd 單元目前限 /opt/oracle-mon 與 /opt/librenms；自訂路徑需另行調整單元檔'
    exit 1
fi
install -d -m 755 "$ORACLE_MON_DIR/admin" "$LIBRENMS_DIR/html/js"
install -m 644 -o root -g root "$HERE/ioc-behavior-tick.php" "$ORACLE_MON_DIR/admin/ioc-behavior-tick.php"
install -m 700 -o root -g root "$HERE/configure-behavior-webhook.sh" "$ORACLE_MON_DIR/admin/configure-behavior-webhook.sh"
install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/../librenms/html/js/ioc-behavior.js" "$LIBRENMS_DIR/html/js/ioc-behavior.js"
# 入口內嵌的 3D「連線動態」播放器：VM 上的入口不能引用外部 CDN，所以部署 repo 內釘死雜湊的 three.js r128＋OrbitControls，
# 以及由 Artifact 版頁面原始碼轉換出來的本機副本（只換兩個 script 網址，見 tools/behavior-viz/make-local-page.py）
VENDOR_DST="$LIBRENMS_DIR/html/vendor/three"
install -d -m 755 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$LIBRENMS_DIR/html/vendor" "$VENDOR_DST"
for f in three.min.js OrbitControls.js LICENSE; do
    install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$HERE/../librenms/html/vendor/three/$f" "$VENDOR_DST/$f"
done
if command -v python3 >/dev/null; then
    # 轉換結果先寫進 root 才進得去的暫存目錄，再用 install 放到網站目錄：
    # 不在 librenms 可寫的目錄裡以 root 寫可預測的檔名（write_text／chown 都會跟著符號連結走），install 也不會跟隨目的地的符號連結
    viz_tmp="$(mktemp -d)"
    if python3 "$HERE/../tools/behavior-viz/make-local-page.py" "$HERE/../tools/behavior-viz/behavior-3d.html" "$viz_tmp/ioc-behavior-viz.html" --vendor-dir "$VENDOR_DST" \
        && install -m 644 -o "$LIBRENMS_USER" -g "$LIBRENMS_USER" "$viz_tmp/ioc-behavior-viz.html" "$LIBRENMS_DIR/html/ioc-behavior-viz.html"; then
        :
    else
        echo '3D 連線動態播放器未部署（轉換失敗）；其餘功能不受影響' >&2
    fi
    rm -rf "$viz_tmp"
else
    echo '找不到 python3，略過 3D 連線動態播放器；其餘功能不受影響' >&2
fi
for unit in ioc-behavior.service ioc-behavior.timer; do
    install -m 644 -o root -g root "$HERE/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
systemctl enable --now ioc-behavior.timer >/dev/null
echo '裝置行為工作器已安裝；感測器需另依盤點結果部署。'
