#!/bin/bash
#
# manage-jt-ipam-ip.sh — 查詢 / 更新 jt-ipam 對外 IP（供 GUI 區塊 G 呼叫）
#
# list：唯讀，讀 /etc/jt-ipam/backend.env（不寫檔），可直接 sudo 呼叫。
# update-ip：異動 /etc/jt-ipam/backend.env + /etc/nginx/conf.d/jt-ipam.conf，
#            並 restart jt-ipam-backend + nginx——php-fpm.service 設
#            ProtectSystem=full，其 sudo 子行程無法寫 /etc（EROFS），須排入
#            佇列由 oracle-mon-apply.sh（root，無 ProtectSystem 限制）套用。
#
# 用法：
#   manage-jt-ipam-ip.sh list
#   manage-jt-ipam-ip.sh update-ip <NEW_IP>
#
set -uo pipefail

BACKEND_ENV="/etc/jt-ipam/backend.env"
NGINX_CONF="/etc/nginx/conf.d/jt-ipam.conf"
ACTION="${1:-}"
NEW_IP="${2:-}"

json_err(){ printf '{"ok":false,"error":"%s"}\n' "$1"; exit 0; }

case "$ACTION" in
  list)
    [ -f "$BACKEND_ENV" ] || json_err "找不到 $BACKEND_ENV（jt-ipam 是否已裝為 nginx 模式？）"
    url="$(grep -oP '^APP_PUBLIC_URL=\Khttps://[0-9.]+:[0-9]+' "$BACKEND_ENV" || true)"
    ip="$(echo "$url" | grep -oP '(?<=://)[0-9.]+' || true)"
    port="$(echo "$url" | grep -oP ':\K[0-9]+$' || true)"
    [ -n "$url" ] || json_err "backend.env 內找不到 APP_PUBLIC_URL"
    printf '{"ok":true,"url":"%s","ip":"%s","port":"%s"}\n' "$url" "$ip" "$port"
    ;;

  update-ip)
    echo "$NEW_IP" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || json_err "IP 格式不正確：$NEW_IP"
    [ -f "$BACKEND_ENV" ] || json_err "找不到 $BACKEND_ENV"
    [ -f "$NGINX_CONF" ]  || json_err "找不到 $NGINX_CONF"

    old_url="$(grep -oP '^APP_PUBLIC_URL=\Khttps://[0-9.]+:[0-9]+' "$BACKEND_ENV" || true)"
    port="${old_url##*:}"
    [ -n "$port" ] || port=8443

    ts="$(date +%s)"
    cp -p "$BACKEND_ENV" "${BACKEND_ENV}.bak-${ts}"
    cp -p "$NGINX_CONF"  "${NGINX_CONF}.bak-${ts}"

    sed -i \
      -e "s|^APP_PUBLIC_URL=.*|APP_PUBLIC_URL=https://${NEW_IP}:${port}|" \
      -e "s|^API_PUBLIC_URL=.*|API_PUBLIC_URL=https://${NEW_IP}:${port}|" \
      -e "s|^CORS_ORIGINS=.*|CORS_ORIGINS=https://${NEW_IP}:${port}|" \
      "$BACKEND_ENV"

    # 任何 IPv4 的 server_name 都換掉（不假設目前值跟 backend.env 一致，避免兩邊早已漂移時比對落空）
    sed -i -E "s/server_name [0-9]+\.[0-9]+\.[0-9]+\.[0-9]+;/server_name ${NEW_IP};/g" "$NGINX_CONF"

    # 套用前先驗證語法，失敗就還原兩個檔案、不 restart，避免整個 web 服務被打掛
    if ! nginx -t 2>/tmp/jtipam-ip-nginx-check.err; then
        err="$(tr '\n' ' ' < /tmp/jtipam-ip-nginx-check.err)"
        cp -p "${BACKEND_ENV}.bak-${ts}" "$BACKEND_ENV"
        cp -p "${NGINX_CONF}.bak-${ts}"  "$NGINX_CONF"
        json_err "nginx 設定驗證失敗，已還原、未 restart：$err"
    fi

    systemctl restart jt-ipam-backend 2>/tmp/jtipam-ip-backend.err; rc1=$?
    systemctl restart nginx           2>/tmp/jtipam-ip-nginx.err;   rc2=$?

    if [ "$rc1" -ne 0 ] || [ "$rc2" -ne 0 ]; then
        json_err "服務重啟失敗（jt-ipam-backend rc=$rc1, nginx rc=$rc2），設定已寫入但服務未必生效，請查 systemctl status"
    fi

    printf '{"ok":true,"new_ip":"%s","port":"%s"}\n' "$NEW_IP" "$port"
    ;;

  *)
    json_err "未知動作：$ACTION（需 list|update-ip）"
    ;;
esac
