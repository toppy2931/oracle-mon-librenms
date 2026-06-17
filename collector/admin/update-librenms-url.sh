#!/bin/bash
set -e
NEW_IP="$1"
echo "$NEW_IP" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || { echo "ERROR: Invalid IP" >&2; exit 1; }

echo "Updating LibreNMS base_url..."
sudo -u librenms php /opt/librenms/lnms config:set base_url "http://${NEW_IP}" && echo "OK: base_url = http://${NEW_IP}" || echo "WARN: CLI set failed"

echo "Updating .env APP_URL..."
if grep -q '^APP_URL=' /opt/librenms/.env; then
    sed -i "s|^APP_URL=.*|APP_URL=http://${NEW_IP}|" /opt/librenms/.env
elif grep -q 'APP_URL=' /opt/librenms/.env; then
    sed -i "s|.*APP_URL=.*|APP_URL=http://${NEW_IP}|" /opt/librenms/.env
else
    echo "APP_URL=http://${NEW_IP}" >> /opt/librenms/.env
fi
echo "OK: .env APP_URL = http://${NEW_IP}"

echo "Clearing Laravel config cache..."
sudo -u librenms php /opt/librenms/artisan config:clear && echo "OK: cache cleared"

echo "ALL_DONE"
