#!/bin/bash
set -e
ALIAS="$1"
echo "$ALIAS" | grep -qE '^[a-z0-9-]+$' || { echo "ERROR: Invalid alias" >&2; exit 1; }
CONF_FILE="/opt/oracle-mon/dbs/${ALIAS}.conf"
if [ -f "$CONF_FILE" ]; then
    rm -f "$CONF_FILE"
    echo "Removed: $CONF_FILE"
else
    echo "Not found: $CONF_FILE"
fi
