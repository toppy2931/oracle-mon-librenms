import java.sql.*;
import java.util.*;

public class OracleStats {
  static String q1(Statement st, String sql) throws SQLException {
    try (ResultSet r = st.executeQuery(sql)) { return r.next() ? r.getString(1) : "0"; }
  }
  static String num(String v) {
    if (v == null || v.isEmpty()) return "0";
    if (v.startsWith(".")) return "0" + v;
    if (v.startsWith("-.")) return "-0" + v.substring(1);
    return v;
  }
  public static void main(String[] a) {
    String url  = System.getenv("ORA_URL");
    String user = System.getenv("ORA_USER");
    String pass = System.getenv("ORA_PASS");
    Map<String,String> m = new LinkedHashMap<>();
    StringBuilder ts = new StringBuilder("[");
    int error = 0;
    String errorString = "";
    try {
      Class.forName("oracle.jdbc.OracleDriver");
      try (Connection c = DriverManager.getConnection(url, user, pass);
           Statement st = c.createStatement()) {

        m.put("instance_up",
              "OPEN".equals(q1(st,"select status from v$instance")) ? "1" : "0");

        m.put("sessions_total", num(q1(st,"select count(*) from v$session")));
        m.put("sessions_active", num(q1(st,
              "select count(*) from v$session where status='ACTIVE'")));
        try { m.put("sessions_max", num(q1(st,
              "select value from v$parameter where name='sessions'"))); }
        catch (Exception e) { m.put("sessions_max","0"); }

        // ── v$sysstat batch（加 redo size / redo log space requests）──
        Map<String,String> ss = new HashMap<>();
        try (ResultSet r = st.executeQuery(
          "select name,value from v$sysstat where name in("+
          "'logons current','physical reads','physical writes',"+
          "'db block gets','consistent gets',"+
          "'sorts (disk)','sorts (memory)',"+
          "'table scans (long tables)','table scans (short tables)',"+
          "'parse count (total)','parse count (hard)',"+
          "'execute count','redo writes','redo size','redo log space requests')")) {
          while (r.next()) ss.put(r.getString(1), r.getString(2));
        }

        m.put("logons_current",        num(ss.getOrDefault("logons current","0")));
        m.put("physical_reads",        num(ss.getOrDefault("physical reads","0")));
        m.put("physical_writes",       num(ss.getOrDefault("physical writes","0")));
        m.put("redo_writes",           num(ss.getOrDefault("redo writes","0")));
        m.put("redo_size",             num(ss.getOrDefault("redo size","0")));
        m.put("redo_space_requests",   num(ss.getOrDefault("redo log space requests","0")));
        m.put("execute_count",         num(ss.getOrDefault("execute count","0")));
        m.put("parse_total",           num(ss.getOrDefault("parse count (total)","0")));
        m.put("parse_hard",            num(ss.getOrDefault("parse count (hard)","0")));
        m.put("sorts_disk",            num(ss.getOrDefault("sorts (disk)","0")));
        m.put("sorts_memory",          num(ss.getOrDefault("sorts (memory)","0")));
        m.put("table_scans_long",      num(ss.getOrDefault("table scans (long tables)","0")));
        m.put("table_scans_short",     num(ss.getOrDefault("table scans (short tables)","0")));

        long dbGets  = Long.parseLong(ss.getOrDefault("db block gets","0"));
        long conGets = Long.parseLong(ss.getOrDefault("consistent gets","0"));
        long phyRds  = Long.parseLong(ss.getOrDefault("physical reads","0"));
        double bufHit = (dbGets+conGets)>0 ?
          Math.round((1.0-(double)phyRds/(dbGets+conGets))*10000.0)/100.0 : 0;
        m.put("buffer_hit_pct", String.valueOf(bufHit));

        long sDisk = Long.parseLong(ss.getOrDefault("sorts (disk)","0"));
        long sMem  = Long.parseLong(ss.getOrDefault("sorts (memory)","0"));
        double dsPct = (sDisk+sMem)>0 ?
          Math.round(100.0*sDisk/(sDisk+sMem)*100.0)/100.0 : 0;
        m.put("disk_sort_pct", String.valueOf(dsPct));

        m.put("dict_cache_hit_pct", num(q1(st,
          "select round((1-(sum(getmisses)/(sum(gets)+sum(getmisses))))*100,2) from v$rowcache")));

        // ── Library Cache：命中率 + pins + reloads（單次查詢）──
        try (ResultSet r = st.executeQuery(
          "select nvl(sum(pins),0),nvl(sum(reloads),0) from v$librarycache")) {
          if (r.next()) {
            long pins    = r.getLong(1);
            long reloads = r.getLong(2);
            double libHit = (pins+reloads)>0 ?
              Math.round((1.0-(double)reloads/(pins+reloads))*10000.0)/100.0 : 100.0;
            m.put("lib_cache_hit_pct", String.valueOf(libHit));
            m.put("lib_cache_pins",    String.valueOf(pins));
            m.put("lib_cache_reloads", String.valueOf(reloads));
          }
        }

        m.put("latch_hit_pct", num(q1(st,
          "select round((1-(sum(misses)/sum(gets)))*100,2) from v$latch")));

        m.put("sql_executing", num(q1(st,
          "select nvl(sum(users_executing),0) from v$sqlarea")));

        m.put("rollback_wait_pct", num(q1(st,
          "select round(decode(sum(gets),0,0,sum(waits)/sum(gets)*100),2) from v$rollstat")));

        m.put("shared_pool_free", num(q1(st,
          "select nvl(bytes,0) from v$sgastat where name='free memory' and pool='shared pool'")));
        m.put("shared_pool_total", num(q1(st,
          "select nvl(sum(bytes),0) from v$sgastat where pool='shared pool'")));

        try (ResultSet r = st.executeQuery(
          "select t.tablespace_name,"+
          " round((d.bytes-nvl(f.bytes,0))/d.bytes*100,1) pct from"+
          " (select tablespace_name,sum(bytes) bytes from dba_data_files group by tablespace_name) d,"+
          " (select tablespace_name,sum(bytes) bytes from dba_free_space group by tablespace_name) f,"+
          " dba_tablespaces t where t.tablespace_name=d.tablespace_name"+
          " and t.tablespace_name=f.tablespace_name(+)")) {
          boolean first=true;
          while (r.next()) {
            if(!first) ts.append(",");
            ts.append("{\"name\":\"").append(r.getString(1))
              .append("\",\"pct_used\":").append(num(r.getString(2))).append("}");
            first=false;
          }
        }

        try {
          m.put("temp_pct_used", num(q1(st,
            "select round(sum(bytes_used)/decode(sum(bytes_used+bytes_free),0,1,sum(bytes_used+bytes_free))*100,1) from v$temp_space_header")));
        } catch (Exception e) { m.put("temp_pct_used","0"); }

        m.put("archivelog_mode",
              "ARCHIVELOG".equals(q1(st,"select log_mode from v$database")) ? "1" : "0");
        m.put("db_open",
              q1(st,"select open_mode from v$database").startsWith("READ WRITE") ? "1" : "0");
        m.put("invalid_objects", num(q1(st,
          "select count(*) from dba_objects where status='INVALID'"+
          " and owner not in ('SYS','SYSTEM','OUTLN','WMSYS','DBSNMP','CTXSYS','XDB')")));
        m.put("invalid_indexes", num(q1(st,
          "select count(*) from dba_indexes where status='INVALID'"+
          " and owner not in ('SYS','SYSTEM','OUTLN','WMSYS','DBSNMP','CTXSYS','XDB')")));

        // ── 即時運作指標 ──
        // 阻塞會話：v$lock.block > 0 = 此 session 正阻擋其他 session
        try { m.put("blocking_sessions", num(q1(st,
          "select count(distinct sid) from v$lock where block > 0"))); }
        catch (Exception e) { m.put("blocking_sessions","0"); }

        // 等待鎖定的會話數
        try { m.put("waiting_sessions", num(q1(st,
          "select count(*) from v$session where lockwait is not null"))); }
        catch (Exception e) { m.put("waiting_sessions","0"); }

        // 長時間執行 SQL（active > 5 分鐘，排除背景程序）
        try { m.put("long_running_sessions", num(q1(st,
          "select count(*) from v$session"+
          " where status='ACTIVE' and username is not null and type='USER' and last_call_et > 300"))); }
        catch (Exception e) { m.put("long_running_sessions","0"); }

        // 最久的 active session 已執行秒數
        try { m.put("longest_active_secs", num(q1(st,
          "select nvl(max(last_call_et),0) from v$session"+
          " where status='ACTIVE' and username is not null and type='USER'"))); }
        catch (Exception e) { m.put("longest_active_secs","0"); }

        // UNDO tablespace 使用率（取所有 UNDO TS 的最大值）
        try { m.put("undo_pct_used", num(q1(st,
          "select nvl(round(max((tot.bytes-nvl(fs.bytes,0))/tot.bytes*100),0),0)"+
          " from (select tablespace_name, sum(bytes) bytes from dba_data_files group by tablespace_name) tot,"+
          " (select tablespace_name, sum(bytes) bytes from dba_free_space group by tablespace_name) fs"+
          " where tot.tablespace_name = fs.tablespace_name(+)"+
          " and tot.tablespace_name in (select tablespace_name from dba_tablespaces where contents='UNDO')"))); }
        catch (Exception e) { m.put("undo_pct_used","0"); }

        // ── Data Guard（通用能力：無 DG 時各項回 0）──
        try {
          String role = q1(st,"select database_role from v$database");
          int roleCode = "PRIMARY".equals(role) ? 1
                       : "PHYSICAL STANDBY".equals(role) ? 2
                       : "LOGICAL STANDBY".equals(role) ? 3 : 0;
          m.put("dg_role", String.valueOf(roleCode));
        } catch (Exception e) { m.put("dg_role","0"); }

        try {
          String sw = q1(st,"select switchover_status from v$database");
          int swOk = (sw!=null && (sw.contains("SESSIONS ACTIVE")||sw.startsWith("TO "))) ? 1 : 0;
          m.put("dg_switchover", String.valueOf(swOk));
        } catch (Exception e) { m.put("dg_switchover","0"); }

        // 注意：v$managed_standby 在 Primary 上看不到 MRP/RFS（那是備庫端 process），
        // 所以 dg_standby_cnt 在 Primary 永遠是 0。改用 dg_dest_count + dg_dest_status 判斷備庫運作狀態。
        try { m.put("dg_standby_cnt", num(q1(st,
          "select count(*) from v$managed_standby where process like 'MRP%' or process like 'RFS%'"))); }
        catch (Exception e) { m.put("dg_standby_cnt","0"); }

        // STANDBY destination 設定數（9i/10g+ 都用 v$archive_dest.target，9i 也有）
        try { m.put("dg_dest_count", num(q1(st,
          "select count(*) from v$archive_dest where target='STANDBY' and destination is not null"))); }
        catch (Exception e) { m.put("dg_dest_count","0"); }

        // STANDBY destination VALID 數（status='VALID' 的）
        try { m.put("dg_dest_valid", num(q1(st,
          "select count(*) from v$archive_dest where target='STANDBY' and destination is not null and status='VALID'"))); }
        catch (Exception e) { m.put("dg_dest_valid","0"); }

        // STANDBY destination 最差狀態（VALID / ERROR / DEFERRED / INACTIVE / BAD PARAM）
        try {
          String ds = q1(st,
            "select max(status) from v$archive_dest where target='STANDBY' and destination is not null");
          m.put("dg_dest_status", "\"" + (ds!=null ? ds.trim() : "NONE") + "\"");
        } catch (Exception e) { m.put("dg_dest_status","\"NONE\""); }

        try { m.put("dg_seq_current", num(q1(st,"select nvl(max(sequence#),0) from v$log"))); }
        catch (Exception e) { m.put("dg_seq_current","0"); }

        try { m.put("dg_seq_archived", num(q1(st,"select nvl(max(sequence#),0) from v$archived_log"))); }
        catch (Exception e) { m.put("dg_seq_archived","0"); }

        // 9i-safe：用 dest_id JOIN 取 standby destination 的 applied_seq#
        try { m.put("dg_seq_standby", num(q1(st,
          "select nvl(max(s.applied_seq#),0) from v$archive_dest_status s, v$archive_dest d"+
          " where s.dest_id=d.dest_id and d.target='STANDBY' and d.destination is not null"))); }
        catch (Exception e) { m.put("dg_seq_standby","0"); }

        // standby destination 已收到的 archived_seq#
        try { m.put("dg_seq_dest_archived", num(q1(st,
          "select nvl(max(s.archived_seq#),0) from v$archive_dest_status s, v$archive_dest d"+
          " where s.dest_id=d.dest_id and d.target='STANDBY' and d.destination is not null"))); }
        catch (Exception e) { m.put("dg_seq_dest_archived","0"); }

        try { m.put("dg_gap", num(q1(st,"select count(*) from v$archive_gap"))); }
        catch (Exception e) { m.put("dg_gap","0"); }

        // v$dataguard_stats 為 10g+ 視圖，9i 抓不到時保持 0
        try { m.put("dg_apply_lag_min", num(q1(st,
          "select nvl(round((extract(day from to_dsinterval(value))*1440)"+
          "+(extract(hour from to_dsinterval(value))*60)"+
          "+extract(minute from to_dsinterval(value))),0)"+
          " from v$dataguard_stats where name='apply lag'"))); }
        catch (Exception e) { m.put("dg_apply_lag_min","0"); }

        try { m.put("dg_transport_lag_min", num(q1(st,
          "select nvl(round((extract(day from to_dsinterval(value))*1440)"+
          "+(extract(hour from to_dsinterval(value))*60)"+
          "+extract(minute from to_dsinterval(value))),0)"+
          " from v$dataguard_stats where name='transport lag'"))); }
        catch (Exception e) { m.put("dg_transport_lag_min","0"); }

        try {
          String mrpSt = q1(st,"select nvl(max(status),'NONE') from v$managed_standby where process like 'MRP%'");
          m.put("dg_mrp_status", "\"" + (mrpSt!=null ? mrpSt.trim() : "NONE") + "\"");
        } catch (Exception e) { m.put("dg_mrp_status","\"NONE\""); }

        // 9i-safe：dest_error 改從 v$archive_dest（含 target 欄位）撈
        try {
          String destErr = q1(st,
            "select max(error) from v$archive_dest where target='STANDBY' and destination is not null and error is not null");
          String de = destErr!=null ? destErr.trim().replace("\"","'") : "";
          m.put("dg_dest_error", "\"" + de + "\"");
        } catch (Exception e) { m.put("dg_dest_error","\"\""); }

        {
          int destCount = 0, stby = 0, destValid = 0;
          try { destCount = Integer.parseInt(m.getOrDefault("dg_dest_count","0")); } catch (Exception e) {}
          try { stby = Integer.parseInt(m.getOrDefault("dg_standby_cnt","0")); } catch (Exception e) {}
          try { destValid = Integer.parseInt(m.getOrDefault("dg_dest_valid","0")); } catch (Exception e) {}
          m.put("dg_configured", (destCount>0||stby>0||destValid>0) ? "1" : "0");
        }

        // ── Materialized Views / Snapshots 刷新健康 ──
        try { m.put("mview_total", num(q1(st,"select count(*) from dba_mviews"))); }
        catch (Exception e) { m.put("mview_total","0"); }
        try { m.put("mview_stale", num(q1(st,
          "select count(*) from dba_mviews where staleness not in ('FRESH','UNKNOWN')"))); }
        catch (Exception e) { m.put("mview_stale","0"); }
        try { m.put("mview_jobs_broken", num(q1(st,"select count(*) from dba_jobs where broken='Y'"))); }
        catch (Exception e) { m.put("mview_jobs_broken","0"); }
        try { m.put("mview_jobs_failed", num(q1(st,"select count(*) from dba_jobs where failures>0"))); }
        catch (Exception e) { m.put("mview_jobs_failed","0"); }
        try { m.put("mview_refresh_groups", num(q1(st,"select count(*) from dba_refresh"))); }
        catch (Exception e) { m.put("mview_refresh_groups","0"); }
        try { m.put("mview_oldest_hours", num(q1(st,
          "select nvl(round((sysdate-min(last_refresh_date))*24),0) from dba_mviews"))); }
        catch (Exception e) { m.put("mview_oldest_hours","0"); }
      }
    } catch (Exception e) {
      error = 1;
      errorString = e.getMessage().replace("\"","'").replace("\n"," ");
      m.put("instance_up", "0");
    }
    ts.append("]");
    StringBuilder out = new StringBuilder("{\"version\":1,\"error\":");
    out.append(error).append(",\"errorString\":\"").append(errorString).append("\",\"data\":{");
    boolean first=true;
    for (Map.Entry<String,String> e : m.entrySet()) {
      if(!first) out.append(",");
      out.append("\"").append(e.getKey()).append("\":")
         .append(e.getValue()==null?"0":e.getValue());
      first=false;
    }
    out.append(",\"tablespaces\":").append(ts).append("}}");
    System.out.println(out);
  }
}
