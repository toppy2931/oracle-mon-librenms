#!/bin/bash
set -e
CONF_DIR="/opt/oracle-mon/dbs"
SNMPD_CONF="/etc/snmp/snmpd.conf"
UPDATE_PY="/opt/oracle-mon/admin/update-snmpd-extends.py"
EXTENDS_TMP=$(mktemp)

for conf in "$CONF_DIR"/*.conf; do
    [ -f "$conf" ] || continue
    alias_val=$(grep '^DB_ALIAS=' "$conf" 2>/dev/null | cut -d= -f2-)
    enabled_val=$(grep '^DB_ENABLED=' "$conf" 2>/dev/null | cut -d= -f2-)
    if [ "$enabled_val" = "1" ] && [ -n "$alias_val" ]; then
        echo "extend oracle-${alias_val} /opt/oracle-mon/run.sh ${alias_val}" >> "$EXTENDS_TMP"
    fi
done

python3 "$UPDATE_PY" "$SNMPD_CONF" "$EXTENDS_TMP"
rc=$?
rm -f "$EXTENDS_TMP"
[ $rc -eq 0 ] || exit 1

service snmpd reload
echo "snmpd reloaded successfully"
