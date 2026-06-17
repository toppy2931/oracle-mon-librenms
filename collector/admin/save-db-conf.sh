#!/bin/bash
set -e
ALIAS="$1"; HOST="$2"; PORT="$3"; SID="$4"; USER_="$5"; PASS="$6"
LABEL="${7:-$ALIAS}"; ENABLED="${8:-1}"

echo "$ALIAS" | grep -qE '^[a-z0-9-]+$' || { echo "ERROR: Invalid alias: $ALIAS" >&2; exit 1; }
echo "$HOST" | grep -qE '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$' || { echo "ERROR: Invalid IP" >&2; exit 1; }
[[ "$PORT" =~ ^[0-9]+$ ]] && [ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || { echo "ERROR: Invalid port" >&2; exit 1; }

CONF_FILE="/opt/oracle-mon/dbs/${ALIAS}.conf"
mkdir -p /opt/oracle-mon/dbs

if [ -z "$PASS" ] && [ -f "$CONF_FILE" ]; then
    PASS=$(grep '^DB_PASS=' "$CONF_FILE" 2>/dev/null | cut -d= -f2-)
fi

# Quote LABEL to handle spaces/special chars when sourced
cat > "$CONF_FILE" << CONFEOF
DB_HOST=${HOST}
DB_PORT=${PORT}
DB_SID=${SID}
DB_USER=${USER_}
DB_PASS=${PASS}
DB_ALIAS=${ALIAS}
DB_LABEL="${LABEL}"
DB_ENABLED=${ENABLED}
CONFEOF

chown librenms:Debian-snmp "$CONF_FILE"
chmod 640 "$CONF_FILE"
echo "Saved: $CONF_FILE"
