#!/bin/bash
# oracle-monitor-run.sh — 執行 OracleMonitor.java 並輸出至 /tmp/oracle-monitor.json
# 部署位置：/opt/oracle-monitor/oracle-monitor-run.sh
# crontab：*/5 * * * * librenms /opt/oracle-monitor/oracle-monitor-run.sh

MONITOR_DIR=/opt/oracle-monitor
LOG=/opt/oracle-monitor/logs/run.log
OJDBC=/opt/oracle-monitor/lib/ojdbc14.jar

# ojdbc14.jar 必須存在
if [ ! -f "${OJDBC}" ]; then
    echo "$(date): ERROR ojdbc14.jar not found at ${OJDBC}" >> "${LOG}"
    exit 1
fi

cd "${MONITOR_DIR}"
java -cp ".:${OJDBC}" OracleMonitor >> "${LOG}" 2>&1
echo "$(date): exit=$?" >> "${LOG}"
