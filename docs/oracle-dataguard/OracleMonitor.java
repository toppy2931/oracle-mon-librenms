import java.sql.*;
import java.io.*;
import java.nio.file.*;
import java.text.SimpleDateFormat;
import java.util.*;

/**
 * OracleMonitor.java — Oracle 9i DataGuard + Materialized View JDBC 監控
 *
 * 編譯：javac -cp /opt/oracle-monitor/lib/ojdbc14.jar OracleMonitor.java
 * 執行：java -cp .:/opt/oracle-monitor/lib/ojdbc14.jar OracleMonitor
 *
 * 輸出：/tmp/oracle-monitor.json
 *
 * 設定：讀取 /etc/oracle-mon.conf
 *   ORACLE_HOST=192.168.x.x
 *   ORACLE_PORT=1521
 *   ORACLE_SID=ORCL
 *   ORACLE_USER=monitor_user
 *   ORACLE_PASS=password
 */
public class OracleMonitor {

    static String host, port, sid, user, pass;

    public static void main(String[] args) throws Exception {
        loadConfig("/etc/oracle-mon.conf");

        StringBuilder json = new StringBuilder();
        json.append("{\n");
        json.append("  \"collected_at\": \"").append(nowIso()).append("\",\n");

        String url = "jdbc:oracle:thin:@" + host + ":" + port + ":" + sid;
        Class.forName("oracle.jdbc.driver.OracleDriver");

        try (Connection conn = DriverManager.getConnection(url, user, pass)) {
            json.append("  \"can_connect\": 1,\n");
            json.append(collectDataGuard(conn));
            json.append(",\n");
            json.append(collectMaterializedViews(conn));
            json.append("\n");
        } catch (SQLException e) {
            json = new StringBuilder();
            json.append("{\n");
            json.append("  \"collected_at\": \"").append(nowIso()).append("\",\n");
            json.append("  \"can_connect\": 0,\n");
            json.append("  \"error\": \"").append(escJson(e.getMessage())).append("\",\n");
            json.append("  \"dataguard\": {},\n");
            json.append("  \"materialized_views\": []\n");
        }

        json.append("}\n");

        // Atomic write to /tmp/oracle-monitor.json
        Path tmp = Paths.get("/tmp/oracle-monitor.json.tmp");
        Path out = Paths.get("/tmp/oracle-monitor.json");
        Files.writeString(tmp, json.toString());
        Files.move(tmp, out, StandardCopyOption.REPLACE_EXISTING);

        System.out.println("OK: " + out);
    }

    static String collectDataGuard(Connection conn) throws SQLException {
        StringBuilder sb = new StringBuilder();
        sb.append("  \"dataguard\": {\n");

        // Role + status + protection mode
        String role = "UNKNOWN", status = "UNKNOWN", prot = "UNKNOWN";
        try (Statement st = conn.createStatement();
             ResultSet rs = st.executeQuery(
                "SELECT TRIM(DATABASE_ROLE), TRIM(STATUS), TRIM(PROTECTION_MODE) " +
                "FROM V$DATABASE, V$INSTANCE WHERE ROWNUM=1")) {
            if (rs.next()) {
                role   = rs.getString(1);
                status = rs.getString(2);
                prot   = rs.getString(3);
            }
        }

        int isPrimary = "PRIMARY".equals(role) ? 1 : 0;
        int dbOpen    = "OPEN".equals(status) ? 1 : 0;
        int protMode  = prot.contains("PROTECTION") ? 0 : prot.contains("AVAILABILITY") ? 1 : 2;

        sb.append("    \"role\": \"").append(role).append("\",\n");
        sb.append("    \"is_primary\": ").append(isPrimary).append(",\n");
        sb.append("    \"db_open\": ").append(dbOpen).append(",\n");
        sb.append("    \"protection_mode\": ").append(protMode).append(",\n");

        if (isPrimary == 1) {
            // PRIMARY: current redo seq + archive dest status
            long currentSeq = queryLong(conn,
                "SELECT NVL(MAX(SEQUENCE#),0) FROM V$LOG WHERE STATUS='CURRENT'");
            String destStatus = queryString(conn,
                "SELECT TRIM(NVL(STATUS,'UNKNOWN')) FROM V$ARCHIVE_DEST " +
                "WHERE TARGET='STANDBY' AND STATUS!='INACTIVE' AND ROWNUM=1");
            String destError = queryString(conn,
                "SELECT TRIM(NVL(ERROR,'')) FROM V$ARCHIVE_DEST " +
                "WHERE TARGET='STANDBY' AND STATUS!='INACTIVE' AND ROWNUM=1");

            int destOk        = "VALID".equals(destStatus) ? 1 : 0;
            int destHasError  = (destError != null && !destError.isEmpty()) ? 1 : 0;

            sb.append("    \"current_seq\": ").append(currentSeq).append(",\n");
            sb.append("    \"applied_seq\": -1,\n");
            sb.append("    \"apply_lag_seqs\": 0,\n");
            sb.append("    \"lag_seconds\": 0,\n");
            sb.append("    \"mrp_running\": -1,\n");
            sb.append("    \"rfs_connected\": -1,\n");
            sb.append("    \"dest_ok\": ").append(destOk).append(",\n");
            sb.append("    \"dest_has_error\": ").append(destHasError).append(",\n");
            sb.append("    \"dest_status\": \"").append(escJson(destStatus)).append("\"");
        } else {
            // STANDBY: MRP, RFS, applied seq, lag
            String mrpInfo = queryString(conn,
                "SELECT TRIM(NVL(STATUS,'NOT_RUNNING'))||'|'||NVL(SEQUENCE#,0) " +
                "FROM V$MANAGED_STANDBY WHERE PROCESS LIKE 'MRP%' AND ROWNUM=1");
            String rfsInfo = queryString(conn,
                "SELECT TRIM(STATUS)||'|'||SEQUENCE# FROM (" +
                "  SELECT STATUS, SEQUENCE# FROM V$MANAGED_STANDBY " +
                "  WHERE PROCESS LIKE 'RFS%' ORDER BY SEQUENCE# DESC" +
                ") WHERE ROWNUM=1");

            int mrpRunning   = (mrpInfo != null && !mrpInfo.startsWith("NOT_RUNNING")) ? 1 : 0;
            int rfsConnected = (rfsInfo != null && !rfsInfo.isEmpty()) ? 1 : 0;

            long receivedSeq = 0;
            if (rfsInfo != null && rfsInfo.contains("|")) {
                try { receivedSeq = Long.parseLong(rfsInfo.split("\\|")[1].trim()); } catch (Exception ignored) {}
            }
            long appliedSeq = queryLong(conn,
                "SELECT NVL(MAX(SEQUENCE#),0) FROM V$ARCHIVED_LOG WHERE APPLIED='YES'");
            long lagSeqs    = Math.max(0, receivedSeq - appliedSeq);
            long lagSeconds = queryLong(conn,
                "SELECT ROUND((SYSDATE-COMPLETION_TIME)*86400) FROM (" +
                "  SELECT COMPLETION_TIME FROM V$ARCHIVED_LOG WHERE APPLIED='YES' ORDER BY SEQUENCE# DESC" +
                ") WHERE ROWNUM=1");
            if (lagSeconds < 0) lagSeconds = 0;

            sb.append("    \"current_seq\": ").append(receivedSeq).append(",\n");
            sb.append("    \"applied_seq\": ").append(appliedSeq).append(",\n");
            sb.append("    \"apply_lag_seqs\": ").append(lagSeqs).append(",\n");
            sb.append("    \"lag_seconds\": ").append(lagSeconds).append(",\n");
            sb.append("    \"mrp_running\": ").append(mrpRunning).append(",\n");
            sb.append("    \"rfs_connected\": ").append(rfsConnected).append(",\n");
            sb.append("    \"dest_ok\": -1,\n");
            sb.append("    \"dest_has_error\": -1");
        }

        sb.append("\n  }");
        return sb.toString();
    }

    static String collectMaterializedViews(Connection conn) throws SQLException {
        StringBuilder sb = new StringBuilder();
        sb.append("  \"materialized_views\": [\n");

        // Oracle 9i: USER_SNAPSHOTS (staleness: FRESH/STALE/NEEDS_COMPILE/UNUSABLE)
        String sql =
            "SELECT TRIM(name), " +
            "  ROUND((SYSDATE - NVL(last_refresh, TO_DATE('1970-01-01','YYYY-MM-DD'))) * 24 * 60), " +
            "  TRIM(NVL(type,'UNKNOWN')), " +
            "  TRIM(NVL(staleness,'UNKNOWN')) " +
            "FROM user_snapshots ORDER BY name";

        List<String> entries = new ArrayList<>();
        try (Statement st = conn.createStatement();
             ResultSet rs = st.executeQuery(sql)) {
            while (rs.next()) {
                String mvName    = rs.getString(1);
                long ageMinutes  = rs.getLong(2);
                String mvType    = rs.getString(3);
                String staleness = rs.getString(4);
                int isStale  = ("FRESH".equals(staleness) || "UNDEFINED".equals(staleness)) ? 0 : 1;
                int refreshOk = "UNUSABLE".equals(staleness) ? 0 : 1;

                entries.add("    {\"name\":\"" + escJson(mvName) + "\"," +
                    "\"age_minutes\":" + ageMinutes + "," +
                    "\"type\":\"" + escJson(mvType) + "\"," +
                    "\"staleness\":\"" + escJson(staleness) + "\"," +
                    "\"is_stale\":" + isStale + "," +
                    "\"refresh_ok\":" + refreshOk + "}");
            }
        }
        sb.append(String.join(",\n", entries));
        sb.append("\n  ]");
        return sb.toString();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    static long queryLong(Connection conn, String sql) {
        try (Statement st = conn.createStatement();
             ResultSet rs = st.executeQuery(sql)) {
            if (rs.next()) return rs.getLong(1);
        } catch (Exception ignored) {}
        return 0;
    }

    static String queryString(Connection conn, String sql) {
        try (Statement st = conn.createStatement();
             ResultSet rs = st.executeQuery(sql)) {
            if (rs.next()) return rs.getString(1);
        } catch (Exception ignored) {}
        return "";
    }

    static void loadConfig(String path) throws IOException {
        Properties p = new Properties();
        try (FileReader fr = new FileReader(path)) { p.load(fr); }
        host = p.getProperty("ORACLE_HOST", "127.0.0.1");
        port = p.getProperty("ORACLE_PORT", "1521");
        sid  = p.getProperty("ORACLE_SID",  "ORCL");
        user = p.getProperty("ORACLE_USER", "system");
        pass = p.getProperty("ORACLE_PASS", "");
    }

    static String escJson(String s) {
        if (s == null) return "";
        return s.replace("\\", "\\\\").replace("\"", "\\\"").replace("\n", "\\n");
    }

    static String nowIso() {
        return new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX").format(new Date());
    }
}
