#!/bin/bash
ALIAS=${1:-l1hweb}
CONF="/opt/oracle-mon/dbs/${ALIAS}.conf"
if [ ! -f "$CONF" ]; then
    CONF="/etc/oracle-mon.conf"
fi
set -a; . "$CONF"; set +a
if [ -n "${DB_HOST}" ]; then
    ORA_URL="jdbc:oracle:thin:@//${DB_HOST}:${DB_PORT}/${DB_SID}"
    ORA_USER="${DB_USER}"
    ORA_PASS="${DB_PASS}"
    export ORA_URL ORA_USER ORA_PASS
fi
exec java -cp /opt/oracle-mon:/opt/oracle-mon/lib/ojdbc14.jar OracleStats
