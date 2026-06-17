<?php

// key => [圖表標題, 繁中說明（意義 + 注意事項）]
$graphs = [
    'oracle-l1hweb_sga' => [
        'SGA Hit Ratios (Dict/Lib/Buffer/Latch)',
        'SGA 各快取命中率（%），越高越好。健康門檻：Dictionary ≥90%、Library ≥95%、Latch ≥90%、Buffer ≥90%。低於門檻多代表 SHARED_POOL_SIZE 或 DB_BLOCK_BUFFERS 不足，應調大。',
    ],
    'oracle-l1hweb_health' => [
        'Database Health (Invalid Objects / Indexes)',
        '資料庫健康：無效物件／索引數量，應維持低且穩定。數量突增代表有物件失效，請 DBA 執行 utlrp.sql 重編譯。另採集 archivelog 模式（0＝未開歸檔，PRD 無法熱備份／PITR，高風險）與 DB 開啟狀態。',
    ],
    'oracle-l1hweb_dataguard' => [
        'Data Guard 同步狀態',
        'Data Guard 通用監控：Standby 處理程序數（MRP/RFS）、有效 standby 歸檔目的地、Archive Gap（>0 代表日誌缺口未同步）、Apply Lag（分鐘，standby 套用延遲）、DG Configured（1＝已設定 DG）。若本機無 DG，各項為 0 且 DG Configured=0 屬正常。',
    ],
    'oracle-l1hweb_mview' => [
        'Materialized View（Snapshot）刷新健康',
        '具體化檢視／快照刷新健康：Total（總數）、Stale（過期未刷新數，應為 0）、Broken/Failed Refresh Jobs（中斷／失敗的刷新 job，>0 代表自動刷新故障，需 DBA 檢查 dba_jobs 並重啟 job）。Stale 與 Broken 偏高代表刷新機制停擺，下游報表資料會過舊。',
    ],
    'oracle-l1hweb_sessions' => [
        'Oracle Sessions',
        '連線數：總連線／活躍／登入。注意異常飆高（程式連線洩漏）或逼近 PROCESSES／SESSIONS 參數上限導致無法登入。',
    ],
    'oracle-l1hweb_buffer' => [
        'Buffer Cache Hit %',
        '資料區塊快取命中率（%）。≥90% 為健康；持續偏低代表記憶體不足或大量全表掃描，應查 SQL 與索引。',
    ],
    'oracle-l1hweb_io' => [
        'Physical I/O (Reads/Writes/Redo)',
        '每秒實體讀／寫／Redo 寫入速率。突增代表 I/O 壓力，常見原因：缺索引造成大量磁碟讀、批次作業、大量交易。',
    ],
    'oracle-l1hweb_redo' => [
        'Redo Log Activity',
        'Redo 產生速率（bytes/s）與空間請求數（redo log space requests/s）。速率反映交易量；space requests > 0 代表 redo buffer 不足，需調大 LOG_BUFFER 或增加 redo log 群組大小。',
    ],
    'oracle-l1hweb_lib_cache' => [
        'Library Cache Hit %',
        'Library Cache 命中率（%）。≥95% 為健康，代表 SQL/PL-SQL 在 shared pool 中被重用。持續偏低代表 hard parse 過多（應使用綁定變數）或 shared pool 太小（應調大 SHARED_POOL_SIZE）。',
    ],
    'oracle-l1hweb_sql' => [
        'SQL Activity (Exec/Parse/Hard Parse)',
        'SQL 活動：每秒執行數／總解析／硬解析。Hard Parse 佔比偏高代表 SQL 未使用綁定變數，浪費 shared pool 並增加 CPU。',
    ],
    'oracle-l1hweb_sga_memory' => [
        'SGA Shared Pool Memory',
        'Shared Pool 的 free 與 total（bytes）。free 持續趨近 0 代表 shared pool 不足，可能觸發 ORA-04031，應調大 SHARED_POOL_SIZE。',
    ],
    'oracle-l1hweb_waits' => [
        'Problem Indicators (Rollback/Temp/Sort)',
        '問題指標：Rollback Wait%（>5% 需增加 rollback segment）、Temp%（接近 100% 代表暫存／排序空間不足）、Disk Sort%（>5% 需調大 SORT_AREA_SIZE）。',
    ],
    'oracle-l1hweb_tablespaces' => [
        'Tablespace Usage %',
        '各表空間使用率（%）。>85% 注意、>95% 緊急（擴充 datafile 或清理資料）。SYSTEM 表空間尤其關鍵，滿了會導致全庫異常。',
    ],
];

foreach ($graphs as $key => $info) {
    $title = $info[0];
    $desc  = $info[1];

    $graph_type = $key;
    $graph_array['height'] = '100';
    $graph_array['width'] = '215';
    $graph_array['to'] = time();
    $graph_array['id'] = $app['app_id'];
    $graph_array['type'] = 'application_' . $key;

    echo '<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">' . $title . '</h3>
        <p style="margin:6px 0 0;font-size:16px;color:#64b5f6;font-weight:600;line-height:1.6;">' . $desc . '</p>
    </div>
    <div class="panel-body">
    <div class="row">';
    include 'includes/html/print-graphrow.inc.php';
    echo '</div>
    </div>
    </div>';
}
