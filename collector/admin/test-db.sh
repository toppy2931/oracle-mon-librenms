#!/bin/bash
ALIAS="${1:-l1hweb}"
echo "$ALIAS" | grep -qE '^[a-z0-9-]+$' || { echo '{"error":"invalid alias"}'; exit 1; }
CONF="/opt/oracle-mon/dbs/${ALIAS}.conf"
[ -f "$CONF" ] || { echo "{\"error\":\"conf not found for alias: ${ALIAS}\"}"; exit 1; }
exec /opt/oracle-mon/run.sh "$ALIAS"
