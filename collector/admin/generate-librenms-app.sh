#!/bin/bash
# generate-librenms-app.sh — 以 oracle-l1hweb 為模板，為新增的 Oracle DB 產生 LibreNMS PHP 檔
# Usage: generate-librenms-app.sh <alias>
# Example: generate-librenms-app.sh paweb
set -euo pipefail

ALIAS="$1"
echo "$ALIAS" | grep -qE '^[a-z0-9-]+$' || { echo "ERROR: Invalid alias: $ALIAS" >&2; exit 1; }

TEMPLATE="oracle-l1hweb"
TARGET="oracle-${ALIAS}"
LIBRENMS="${LIBRENMS_DIR:-/opt/librenms}"
LIBRENMS_USER="${LIBRENMS_USER:-librenms}"

install_generated() {
    local src="$1"
    local dst="$2"
    if [ ! -f "$src" ]; then
        echo "SKIP: template not found: $src" >&2
        return
    fi
    if [ -f "$dst" ]; then
        echo "EXISTS: $dst"
        return
    fi
    sed "s/${TEMPLATE}/${TARGET}/g" "$src" > "$dst"
    chown "${LIBRENMS_USER}:${LIBRENMS_USER}" "$dst"
    chmod 644 "$dst"
    echo "CREATED: $dst"
}

# polling
install_generated \
    "$LIBRENMS/includes/polling/applications/${TEMPLATE}.inc.php" \
    "$LIBRENMS/includes/polling/applications/${TARGET}.inc.php"

# global apps page
install_generated \
    "$LIBRENMS/includes/html/pages/apps/${TEMPLATE}.inc.php" \
    "$LIBRENMS/includes/html/pages/apps/${TARGET}.inc.php"

# device apps page
install_generated \
    "$LIBRENMS/includes/html/pages/device/apps/${TEMPLATE}.inc.php" \
    "$LIBRENMS/includes/html/pages/device/apps/${TARGET}.inc.php"

# graph files (oracle-l1hweb_sessions.inc.php → oracle-paweb_sessions.inc.php etc.)
for src in "$LIBRENMS/includes/html/graphs/application/${TEMPLATE}_"*.inc.php; do
    [ -f "$src" ] || continue
    suffix="${src##*${TEMPLATE}_}"          # e.g. "sessions.inc.php"
    install_generated "$src" "$LIBRENMS/includes/html/graphs/application/${TARGET}_${suffix}"
done

echo "Done: LibreNMS PHP files generated for $TARGET"
