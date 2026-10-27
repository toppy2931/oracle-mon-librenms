<?php
declare(strict_types=1);

namespace Ioc;

use PDO;

/** 裝置行為生命週期。所有時間以 UTC 儲存；原始日誌不進 IOC 資料庫。 */
final class BehaviorService
{
    public const SOURCE = 'fortigate_graylog';
    /** 啟用監控時訓練基線最多往回取幾天的完整區間（沒有最少天數；資料少只是涵蓋少）。 */
    public const BASELINE_MAX_DAYS = 28;
    /** 沒有設備、站點層級的上班時段，也沒有存過「預設」時，用這個（台北、週一到五 09:00–18:00）。 */
    public const DEFAULT_SCHEDULE = ['timezone' => 'Asia/Taipei', 'workdays' => '1,2,3,4,5', 'start_time' => '09:00:00', 'end_time' => '18:00:00'];
    public const MAX_CONFIRM_BATCH = 100;
    /** 已確認主機隔這麼久才又出現，就留一筆歷史請管理員確認（身分只靠 IP 維繫，位址可能已被別台取得）。 */
    public const REAPPEAR_GAP_HOURS = 24;
    /** 模式的中文名稱：工作台（state() 的 mode_names）用這一份，畫面不再自己寫一份。 */
    public const MODE_NAMES = ['learning' => '學習中', 'monitoring' => '監控中', 'retired' => '已移除'];
    /** 清單每頁筆數的白名單。 */
    public const PAGE_SIZES = [10, 25, 50, 100];
    /** 清單範圍 → 篩選條件（固定字串，不含使用者輸入）。lifecycle＝設備與待確認；confirmed＝已確認主機；已移除的都不列。 */
    private const LIST_FILTERS = [
        'lifecycle' => "a.mode<>'retired' AND (a.device_id IS NOT NULL OR a.confirmed_at IS NULL)",
        'confirmed' => "a.mode<>'retired' AND a.device_id IS NULL AND a.confirmed_at IS NOT NULL",
    ];
    /** 排序欄位白名單 → 排序用的固定表達式（t 是 listAssets 的衍生表）。位址依數值排（.2 在 .10 前）。 */
    private const LIST_SORTS = [
        'name' => 't.name',
        'kind' => 'CASE WHEN t.device_id IS NOT NULL THEN 0 WHEN t.confirmed_at IS NOT NULL THEN 1 ELSE 2 END',
        'address' => 'INET6_ATON(t.address)',
        'site' => 't.site_name',
        'mode' => 't.mode',
        'quality' => 't.quality_status',
        'last_seen' => 't.last_seen_at',
        'confirmed_at' => 't.confirmed_at',
    ];
    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    /** 檢視者只取得去識別的整體狀態。 */
    public function state(?string $level): array
    {
        if (!Access::canView($level)) { throw new AccessDenied('沒有檢視權限'); }
        $summary = ['learning' => 0, 'monitoring' => 0, 'retired' => 0,
            'source_error' => 0, 'open_alerts' => 0];
        foreach ($this->db->query('SELECT mode,COUNT(*) n FROM ioc_behavior_assets GROUP BY mode')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($summary[$row['mode']])) { $summary[$row['mode']] = (int) $row['n']; }
        }
        $summary['source_error'] = (int) $this->db->query("SELECT COUNT(*) FROM ioc_behavior_sources WHERE status IN ('error','partial','missing')
            OR (source_key='fortigate_graylog' AND checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))")->fetchColumn();
        $summary['open_alerts'] = (int) $this->db->query("SELECT COUNT(*) FROM ioc_behavior_alerts WHERE status='open'")->fetchColumn();
        if (!Access::canHandle($level)) { return ['summary' => $summary, 'limited' => true]; }
        $sources = $this->db->query('SELECT source_key,kind,scope_label,status,coverage,last_seen_at,checked_at,reason
            FROM ioc_behavior_sources ORDER BY source_key')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sources as &$source) {
            if ($source['source_key'] === self::SOURCE && strtotime($source['checked_at'] . ' UTC') < time() - 600) {
                $source['status'] = 'stale'; $source['reason'] = '採集器超過 10 分鐘未更新';
            }
        }
        unset($source);
        $schedules = $this->db->query('SELECT scope,scope_key,timezone,workdays,start_time,end_time,updated_by,updated_at
            FROM ioc_behavior_schedules ORDER BY scope,scope_key')->fetchAll(PDO::FETCH_ASSOC);
        $audit = $this->db->query('SELECT actor,action,subject,occurred_at FROM ioc_behavior_audit ORDER BY id DESC LIMIT 30')->fetchAll(PDO::FETCH_ASSOC);
        $stored = $this->db->query("SELECT timezone,workdays,start_time,end_time FROM ioc_behavior_schedules WHERE scope='default' AND scope_key='default'")->fetch(PDO::FETCH_ASSOC);
        $default = $stored ? $stored + ['custom' => true] : self::DEFAULT_SCHEDULE + ['custom' => false];
        return ['summary' => $summary, 'limited' => false, 'sources' => $sources,
            'schedules' => $schedules, 'default_schedule' => $default, 'audit' => $audit, 'webhook_status' => $this->webhookStatus(),
            'mode_names' => self::MODE_NAMES];
    }

    /**
     * 資產清單（只有管理者），伺服器端分頁與排序。
     * view：lifecycle＝LibreNMS 設備與待確認主機；confirmed＝已確認主機。已移除的兩邊都不列。
     * 頁碼超過最後一頁就回最後一頁；排序欄位與方向、每頁筆數都走白名單，值不會進 SQL 以外的地方（欄位用固定表達式，不拼使用者字串）。
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public function listAssets(string $view, int $page, int $perPage, string $sort, string $dir, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以檢視裝置清單');
        if (!isset(self::LIST_FILTERS[$view])) { throw new ValidationError(['清單範圍不正確']); }
        if (!in_array($perPage, self::PAGE_SIZES, true)) { throw new ValidationError(['每頁筆數不正確']); }
        if ($page < 1) { throw new ValidationError(['頁碼不正確']); }
        if (!isset(self::LIST_SORTS[$sort])) { throw new ValidationError(['排序欄位不正確']); }
        if ($dir !== 'asc' && $dir !== 'desc') { throw new ValidationError(['排序方向不正確']); }
        $where = self::LIST_FILTERS[$view];
        $total = (int) $this->db->query('SELECT COUNT(*) FROM ioc_behavior_assets a WHERE ' . $where)->fetchColumn();
        $pages = max(1, intdiv($total + $perPage - 1, $perPage));
        $page = min($page, $pages);
        $key = self::LIST_SORTS[$sort];
        // 空值一律排最後（不論方向），同值用 id 穩定排序，翻頁才不會重複或漏掉
        $rows = $this->db->query('SELECT t.* FROM (SELECT a.id,a.identity_key,a.device_id,a.name,a.site_name,a.mode,a.learning_started_at,
            a.baseline_version,a.last_seen_at,a.quality_status,a.confirmed_at,a.confirmed_by,
            (SELECT x.address FROM ioc_behavior_addresses x WHERE x.asset_id=a.id ORDER BY x.last_seen_at DESC,x.id DESC LIMIT 1) address
            FROM ioc_behavior_assets a WHERE ' . $where . ') t
            ORDER BY (' . $key . ' IS NULL),' . $key . ' ' . ($dir === 'asc' ? 'ASC' : 'DESC') . ',t.id ASC
            LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage))->fetchAll(PDO::FETCH_ASSOC);
        $rows = self::castRows($rows, ['id', 'baseline_version']);
        foreach ($rows as $i => $r) { $rows[$i]['device_id'] = $r['device_id'] === null ? null : (int) $r['device_id']; }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages];
    }

    /**
     * 查詢結果的欄位轉型（驅動程式可能把數字回成字串）：$ints 轉整數、$bools 轉布林，其餘原樣。
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $ints
     * @param string[] $bools
     * @return array<int,array<string,mixed>>
     */
    private static function castRows(array $rows, array $ints, array $bools = []): array
    {
        foreach ($rows as $i => $r) {
            foreach ($ints as $k) { $rows[$i][$k] = (int) $r[$k]; }
            foreach ($bools as $k) { $rows[$i][$k] = (bool) $r[$k]; }
        }
        return $rows;
    }

    /**
     * 資產實際套用的上班時段，由具體到籠統：設備 → 站點 → 預設（資料表）→ 內建預設。永遠有結果。
     * 啟用監控、基線訓練與偵測都走這一個函式，不會各自找不一樣的時段。
     *
     * source 說明時段從哪來（device／site／default／builtin）：工作台據此提醒「正在用內建預設」。
     *
     * @param array{device_id?:mixed,site_name?:mixed} $asset
     * @return array{timezone:string,workdays:string,start_time:string,end_time:string,source:string}
     */
    public function scheduleFor(array $asset): array
    {
        $q = $this->db->prepare("SELECT scope,timezone,workdays,start_time,end_time FROM ioc_behavior_schedules
            WHERE (scope='device' AND scope_key=?) OR (scope='site' AND scope_key=?) OR (scope='default' AND scope_key='default')
            ORDER BY CASE scope WHEN 'device' THEN 0 WHEN 'site' THEN 1 ELSE 2 END LIMIT 1");
        $q->execute([(string) ($asset['device_id'] ?? ''), (string) ($asset['site_name'] ?? '')]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['source'] = $row['scope'];
            unset($row['scope']);
            return $row;
        }
        return self::DEFAULT_SCHEDULE + ['source' => 'builtin'];
    }

    public function detail(int $id, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以檢視裝置行為詳情');
        $asset = $this->asset($id);
        $q = $this->db->prepare('SELECT address,first_seen_at,last_seen_at,evidence_kind FROM ioc_behavior_addresses WHERE asset_id=? ORDER BY last_seen_at DESC,id DESC LIMIT 30');
        $q->execute([$id]); $addresses = $q->fetchAll(PDO::FETCH_ASSOC);
        $q = $this->db->prepare('SELECT window_start,source_key,connections,bytes_total,destinations,ports,quality,quality_reason FROM ioc_behavior_windows WHERE asset_id=? ORDER BY window_start DESC LIMIT 288');
        $q->execute([$id]); $windows = $q->fetchAll(PDO::FETCH_ASSOC);
        $q = $this->db->prepare('SELECT a.id,a.detector,a.window_start,a.severity,a.status,a.score,a.summary,a.metrics_json,a.case_id,
            a.first_seen_at,a.last_seen_at,a.occurrences,d.status delivery_status,d.attempts delivery_attempts,d.delivered_at
            FROM ioc_behavior_alerts a LEFT JOIN ioc_behavior_deliveries d ON d.alert_id=a.id
            WHERE a.asset_id=? ORDER BY a.id DESC LIMIT 50');
        $q->execute([$id]); $alerts = $q->fetchAll(PDO::FETCH_ASSOC);
        $q = $this->db->prepare('SELECT action,actor,occurred_at,detail FROM ioc_behavior_history WHERE asset_id=? ORDER BY id DESC LIMIT 50');
        $q->execute([$id]); $history = $q->fetchAll(PDO::FETCH_ASSOC);
        $q = $this->db->prepare('SELECT source_key,day_kind,hour_of_day,median_bytes,p95_bytes,median_connections,p95_connections,median_destinations,p95_destinations,sample_count FROM ioc_behavior_baselines WHERE asset_id=? AND version=? ORDER BY source_key,day_kind,hour_of_day');
        $q->execute([$id, $asset['baseline_version']]); $baseline = $q->fetchAll(PDO::FETCH_ASSOC);
        $schedule = $this->scheduleFor($asset);     // 實際套用的上班時段與來源：啟用監控前要看得到是不是內建預設
        return compact('asset', 'addresses', 'windows', 'alerts', 'history', 'baseline', 'schedule');
    }

    /**
     * 啟用監控的資料量檢查與涵蓋度（唯讀）：按「啟用監控」之前先給管理員看，用幾個區間、涵蓋幾個離峰時段，
     * 沒達標時原因說出缺多少。基線只用學習起點之後、最多最近 BASELINE_MAX_DAYS 天、品質完整的區間。
     *
     * @return array<string,mixed> BehaviorAnalyzer::baselineCheck 的結果
     */
    public function previewBaseline(int $id, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以預覽基線');
        $asset = $this->asset($id);
        return BehaviorAnalyzer::baselineCheck($this->baselineWindows($asset), $this->scheduleFor($asset));
    }

    /** 訓練基線用的區間：品質完整、最近 28 天內、在學習起點之後結束的（重新學習要真的忘掉舊的）。 */
    private function baselineWindows(array $asset): array
    {
        $q = $this->db->prepare('SELECT window_start,bytes_total,connections,destinations FROM ioc_behavior_windows
            WHERE asset_id=? AND source_key=? AND quality=\'complete\'
              AND window_start>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . self::BASELINE_MAX_DAYS . ' DAY)
              AND DATE_ADD(window_start,INTERVAL 5 MINUTE)>?
            ORDER BY window_start');
        $q->execute([(int) $asset['id'], self::SOURCE, $asset['learning_started_at']]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 用目前的資料訓練新版基線（啟用監控時呼叫，必須在呼叫端的交易裡）。資料量不達標就丟 ValidationError，原因說出缺多少。
     *
     * @return array<string,mixed> baselineCheck 的結果加上 version
     */
    private function trainBaseline(array $asset): array
    {
        $rows = $this->baselineWindows($asset);
        $schedule = $this->scheduleFor($asset);
        $check = BehaviorAnalyzer::baselineCheck($rows, $schedule);
        if (!$check['ok']) { throw new ValidationError($check['reasons']); }
        $version = (int) $asset['baseline_version'] + 1;
        $insert = $this->db->prepare('INSERT INTO ioc_behavior_baselines (asset_id,version,source_key,day_kind,hour_of_day,
            median_bytes,p95_bytes,mad_bytes,median_connections,p95_connections,mad_connections,
            median_destinations,p95_destinations,mad_destinations,sample_count,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        foreach (BehaviorAnalyzer::baselineBuckets($rows, $schedule['timezone']) as $key => $bucket) {
            [$kind, $hour] = explode(':', $key);
            $b = BehaviorAnalyzer::stats(array_column($bucket, 'bytes_total'));
            $c = BehaviorAnalyzer::stats(array_column($bucket, 'connections'));
            $d = BehaviorAnalyzer::stats(array_column($bucket, 'destinations'));
            $insert->execute([(int) $asset['id'], $version, self::SOURCE, $kind, (int) $hour,
                $b['median'], $b['p95'], $b['mad'], $c['median'], $c['p95'], $c['mad'],
                $d['median'], $d['p95'], $d['mad'], count($bucket)]);
        }
        return $check + ['version' => $version];
    }

    /**
     * 切換模式：learning（重新學習：重設學習起點、清已知目的地，之後訓練只用重設之後的區間）、
     * monitoring（啟用監控：立即用現有資料訓練新版基線，資料不夠就擋下）、retired（移除）。沒有強制的學習天數。
     */
    public function setMode(int $id, string $mode, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以切換模式');
        if (!in_array($mode, ['learning', 'monitoring', 'retired'], true)) { throw new ValidationError(['模式不正確']); }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            // 在交易裡鎖住這一列再讀：兩位管理者同時按「啟用監控」時，後到的看到已是監控中就不重做（不會撞基線版本的唯一鍵）
            $q = $this->db->prepare('SELECT * FROM ioc_behavior_assets WHERE id=? FOR UPDATE');
            $q->execute([$id]); $asset = $q->fetch(PDO::FETCH_ASSOC);
            if (!$asset) { throw new NotFound('找不到裝置'); }
            if ($mode === 'monitoring') {
                if ($asset['mode'] === 'retired') { throw new ValidationError(['已移除的主機不能啟用監控；請先重新學習']); }
                if ($asset['device_id'] === null && $asset['confirmed_at'] === null) {
                    throw new ValidationError(['待確認設備須先確認為受管主機，才能啟用監控']);
                }
                // 上班時段不必先設定：沒有設備、站點層級的時段就用預設（scheduleFor）
                $source = $this->db->query("SELECT status,checked_at FROM ioc_behavior_sources WHERE source_key='fortigate_graylog'")->fetch(PDO::FETCH_ASSOC);
                if (!$source || $source['status'] !== 'ready' || strtotime($source['checked_at'] . ' UTC') < time() - 900) {
                    throw new ValidationError(['防火牆日誌來源未就緒或已過期']);
                }
            }
            if ($asset['mode'] === $mode) { $this->db->rollBack(); return; }
            $trained = $mode === 'monitoring' ? $this->trainBaseline($asset) : null;
            $q = $this->db->prepare('UPDATE ioc_behavior_assets SET mode=?,learning_started_at=CASE WHEN ?=\'learning\' THEN ? ELSE learning_started_at END,
                quality_status=CASE WHEN ?=\'learning\' THEN \'learning\' ELSE quality_status END,
                training_checked_at=CASE WHEN ?=\'learning\' THEN NULL ELSE training_checked_at END WHERE id=?');
            $q->execute([$mode, $mode, $now, $mode, $mode, $id]);
            if ($trained !== null) {
                $this->db->prepare("UPDATE ioc_behavior_assets SET baseline_version=?,quality_status='ready' WHERE id=?")->execute([$trained['version'], $id]);
                $this->history($id, 'baseline_ready', $actor, sprintf('用 %d 個完整區間（%d 天）訓練第 %d 版基線，涵蓋 %d 個離峰時段，啟用監控',
                    $trained['windows'], $trained['days'], $trained['version'], $trained['offhours_buckets']));
            }
            if ($mode === 'learning') { $this->db->prepare('DELETE FROM ioc_behavior_known WHERE asset_id=?')->execute([$id]); }
            $this->history($id, 'mode_changed', $actor, $asset['mode'] . ' → ' . $mode);
            $this->audit('mode_changed', 'asset:' . $id . ':' . $mode, $actor);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function setAlertStatus(int $alertId, string $status, string $reason, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以處置警示');
        if (!in_array($status, ['acknowledged', 'resolved'], true) || trim($reason) === '' || mb_strlen($reason) > 200) {
            throw new ValidationError(['請選擇處置狀態並填寫 200 字以內的理由']);
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id,asset_id,status FROM ioc_behavior_alerts WHERE id=? FOR UPDATE');
            $q->execute([$alertId]); $alert = $q->fetch(PDO::FETCH_ASSOC);
            if (!$alert) { throw new NotFound('找不到警示'); }
            if (!in_array($alert['status'], ['open', 'acknowledged'], true)
                || ($alert['status'] === 'acknowledged' && $status !== 'resolved')) {
                throw new ValidationError(['目前警示狀態無法執行這項處置']);
            }
            $this->db->prepare('UPDATE ioc_behavior_alerts SET status=? WHERE id=?')->execute([$status, $alertId]);
            $this->history((int) $alert['asset_id'], 'alert_' . $status, $actor,
                '警示 #' . $alertId . '：' . mb_substr(trim($reason), 0, 200));
            $this->audit('alert_' . $status, 'alert:' . $alertId, $actor);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function retryDelivery(int $alertId, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以重試通知');
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT a.asset_id,a.status alert_status,d.status delivery_status
                FROM ioc_behavior_alerts a JOIN ioc_behavior_deliveries d ON d.alert_id=a.id WHERE a.id=? FOR UPDATE');
            $q->execute([$alertId]); $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['alert_status'] !== 'open' || $row['delivery_status'] !== 'failed') {
                throw new ValidationError(['只有未處置且投遞失敗的警示可以重試']);
            }
            $this->db->prepare("UPDATE ioc_behavior_deliveries SET status='queued',attempts=0,next_attempt_at=UTC_TIMESTAMP(),error_code=''
                WHERE alert_id=?")->execute([$alertId]);
            $this->audit('delivery_retried', 'alert:' . $alertId, $actor);
            $this->history((int) $row['asset_id'], 'delivery_retried', $actor, '警示 #' . $alertId);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** 時段輸入的檢查（存檔與預覽共用）。 */
    private function validateSchedule(string $scope, string $key, string $timezone, string $workdays, string $start, string $end): void
    {
        if (!in_array($scope, ['site', 'device', 'default'], true) || $key === '' || mb_strlen($key) > 100
            || preg_match('/[\x00-\x1f\x7f]/', $key) || ($scope === 'device' && !ctype_digit($key))
            || ($scope === 'default' && $key !== 'default')) { throw new ValidationError(['範圍不正確']); }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) { throw new ValidationError(['時區不正確']); }
        if (!preg_match('/\A[1-7](?:,[1-7]){0,6}\z/D', $workdays) || count(array_unique(explode(',', $workdays))) !== count(explode(',', $workdays))) { throw new ValidationError(['工作日格式不正確']); }
        if (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $start) || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $end) || $start >= $end) { throw new ValidationError(['上班時間不正確']); }
    }

    /** 這份時段和目前這個範圍已存的（沒存過預設時是內建預設）不一樣嗎。 */
    private function scheduleDiffers(string $scope, string $key, string $timezone, string $workdays, string $start, string $end): bool
    {
        $q = $this->db->prepare('SELECT timezone,workdays,start_time,end_time FROM ioc_behavior_schedules WHERE scope=? AND scope_key=?');
        $q->execute([$scope, $key]); $before = $q->fetch(PDO::FETCH_ASSOC);
        if (!$before && $scope === 'default') { $before = self::DEFAULT_SCHEDULE; }     // 沒存過預設：與內建預設相同就不算變動
        return !$before || $before['timezone'] !== $timezone || $before['workdays'] !== $workdays
            || substr($before['start_time'], 0, 5) !== $start || substr($before['end_time'], 0, 5) !== $end;
    }

    /**
     * 時段變動會讓哪些資產改回學習中：「實際套用這個時段」而且在監控中的（基線依賴上班時段，要重新啟用監控才會用新時段訓練）。
     * 設備層級只動該設備；站點層級不動已有設備層級時段的；預設層級只動沒有設備也沒有站點層級時段的（含沒有 LibreNMS 設備的主機）。
     * 學習中的不算：訓練時才讀時段，它們沒有任何依賴舊時段的東西，重設起算時間只會白白丟掉已累積的區間。
     *
     * @return array<int,array{id:int|string,mode:string}>
     */
    private function assetsAffectedBySchedule(string $scope, string $key): array
    {
        if ($scope === 'device') {
            $sql = "SELECT id,mode FROM ioc_behavior_assets WHERE device_id=? AND mode='monitoring'";
        } elseif ($scope === 'site') {
            $sql = "SELECT a.id,a.mode FROM ioc_behavior_assets a WHERE a.site_name=? AND a.mode='monitoring'
                AND NOT EXISTS (SELECT 1 FROM ioc_behavior_schedules d WHERE d.scope='device'
                    AND d.scope_key=CAST(a.device_id AS CHAR))";
        } else {
            $sql = "SELECT a.id,a.mode FROM ioc_behavior_assets a WHERE a.mode='monitoring'
                AND NOT EXISTS (SELECT 1 FROM ioc_behavior_schedules d WHERE d.scope='device' AND d.scope_key=CAST(a.device_id AS CHAR))
                AND NOT EXISTS (SELECT 1 FROM ioc_behavior_schedules s WHERE s.scope='site' AND s.scope_key=a.site_name)";
        }
        $q = $this->db->prepare($sql);
        $q->execute($scope === 'default' ? [] : [$key]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 儲存前的影響預覽（唯讀）：這份時段會讓幾台監控中的資產改回學習中（要重新按「啟用監控」）。畫面據此在儲存前請管理員確認。
     *
     * @return array{changed:bool,monitoring:int}
     */
    public function previewScheduleImpact(string $scope, string $key, string $timezone, string $workdays, string $start, string $end, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以設定上班時段');
        $this->validateSchedule($scope, $key, $timezone, $workdays, $start, $end);
        $out = ['changed' => $this->scheduleDiffers($scope, $key, $timezone, $workdays, $start, $end), 'monitoring' => 0];
        if ($out['changed']) { $out['monitoring'] = count($this->assetsAffectedBySchedule($scope, $key)); }
        return $out;
    }

    public function saveSchedule(string $scope, string $key, string $timezone, string $workdays, string $start, string $end, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以設定上班時段');
        $this->validateSchedule($scope, $key, $timezone, $workdays, $start, $end);
        $changed = $this->scheduleDiffers($scope, $key, $timezone, $workdays, $start, $end);
        $this->db->beginTransaction();
        try {
        $q = $this->db->prepare('INSERT INTO ioc_behavior_schedules (scope,scope_key,timezone,workdays,start_time,end_time,updated_by,updated_at)
            VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE timezone=VALUES(timezone),workdays=VALUES(workdays),start_time=VALUES(start_time),end_time=VALUES(end_time),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)');
        $q->execute([$scope, $key, $timezone, $workdays, $start . ':00', $end . ':00', $actor]);
        if ($changed) {
            foreach ($this->assetsAffectedBySchedule($scope, $key) as $row) {
                $this->restartLearning((int) $row['id'], 'schedule_changed', $actor, '上班時段變更，改回學習中（需重新按「啟用監控」）');
            }
            $this->audit('schedule_saved', $scope . ':' . $key, $actor);
        }
        $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /**
     * 讓資產改回學習中：學習起點與訓練檢查重設、已知目的地清掉，舊基線與區間保留（學習期間不用舊基線判斷；
     * 再次啟用監控時只用重設之後的區間訓練新版基線）。
     * 呼叫端負責交易；已退役的不動。
     */
    private function restartLearning(int $id, string $action, string $actor, string $detail): void
    {
        $this->db->prepare("UPDATE ioc_behavior_assets SET mode='learning',quality_status='learning',
            learning_started_at=UTC_TIMESTAMP(),training_checked_at=NULL WHERE id=? AND mode<>'retired'")->execute([$id]);
        $this->db->prepare('DELETE FROM ioc_behavior_known WHERE asset_id=?')->execute([$id]);
        $this->history($id, $action, $actor, $detail);
    }

    public function saveSourceInventory(string $key, string $kind, string $scope, string $status, string $coverage, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以盤點來源');
        if (!preg_match('/\A[a-z0-9_:-]{1,40}\z/D', $key) || in_array($key, [self::SOURCE, 'webhook'], true)
            || !in_array($kind, ['span', 'tap', 'flow', 'dns'], true)
            || !in_array($status, ['inventory', 'planned', 'unavailable'], true)
            || mb_strlen($scope) > 120 || mb_strlen($coverage) > 120) { throw new ValidationError(['來源盤點欄位不正確']); }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('INSERT INTO ioc_behavior_sources (source_key,kind,scope_label,status,coverage,checked_at,reason)
                VALUES (?,?,?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),scope_label=VALUES(scope_label),status=VALUES(status),coverage=VALUES(coverage),checked_at=VALUES(checked_at),reason=VALUES(reason)');
            $q->execute([$key, $kind, $scope, $status, $coverage, '管理員：' . mb_substr($actor, 0, 100)]);
            $this->audit('source_inventory_saved', $key, $actor);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /** 受監控裝置只以 LibreNMS device_id 自動合併；未知 IP 保留暫時身分。 */
    public function syncDevice(int $deviceId, string $name, ?string $ip, ?string $site): int
    {
        $key = 'librenms:' . $deviceId;
        $q = $this->db->prepare('SELECT id,site_name,mode FROM ioc_behavior_assets WHERE identity_key=?');
        $q->execute([$key]); $before = $q->fetch(PDO::FETCH_ASSOC);
        $q = $this->db->prepare('INSERT INTO ioc_behavior_assets (identity_key,device_id,name,site_name,learning_started_at)
            VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name=VALUES(name),site_name=VALUES(site_name)');
        $q->execute([$key, $deviceId, mb_substr($name, 0, 200), $site]);
        $q = $this->db->prepare('SELECT id FROM ioc_behavior_assets WHERE identity_key=?'); $q->execute([$key]); $id = (int) $q->fetchColumn();
        if ($before && $before['mode'] === 'monitoring' && $before['site_name'] !== $site) {
            $this->db->beginTransaction();
            try {
                $this->restartLearning($id, 'site_changed', 'system', '站點歸屬變更，改回學習中（需重新按「啟用監控」）');
                $this->db->commit();
            } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        }
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP)) { $this->observeAddress($id, $ip, 'librenms'); }
        return $id;
    }

    /**
     * 未知來源 IP 對到的資產。未確認的暫時資產：24 小時內看過同一個 IP 才沿用，隔太久另建新的暫時身分；
     * 已確認主機：不受 24 小時限制（管理員已確認過身分，缺席再久回來都還是同一台），且與未確認的並存時優先它。
     * 已確認主機隔 24 小時以上才又出現時留一筆「reappeared」歷史：身分只靠 IP 維繫，DHCP 可能已把位址租給別台機器，
     * 要讓管理員看得到、確認仍是同一台。
     *
     * @param (callable(string):?string)|null $siteOf 依 IP 找站點；有傳就順便維護站點歸屬（沿用查詢已讀到的站點，沒變就不多查）
     */
    public function observeUnknown(string $ip, ?callable $siteOf = null): int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { throw new ValidationError(['IP 格式不正確']); }
        $q = $this->db->prepare("SELECT a.id,a.site_name,a.mode,a.confirmed_at,x.last_seen_at FROM ioc_behavior_assets a JOIN ioc_behavior_addresses x ON x.asset_id=a.id
            WHERE x.address=? AND a.device_id IS NULL AND a.mode<>'retired'
              AND (a.confirmed_at IS NOT NULL OR x.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR))
            ORDER BY (a.confirmed_at IS NULL),x.last_seen_at DESC,x.id DESC LIMIT 1");
        $q->execute([$ip]); $row = $q->fetch(PDO::FETCH_ASSOC);
        $site = null; $mode = 'learning';
        if ($row) {
            $id = (int) $row['id'];
            $site = $row['site_name'];
            $mode = $row['mode'];
            if ($row['confirmed_at'] !== null) { $this->noteReappearance($id, $ip, (string) $row['last_seen_at']); }
        } else {
            $key = 'observed:' . $ip . ':' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(3));
            $q = $this->db->prepare('INSERT INTO ioc_behavior_assets (identity_key,name,learning_started_at)
                VALUES (?,?,UTC_TIMESTAMP())');
            $q->execute([$key, '待確認設備 ' . $ip]);
            $id = (int) $this->db->lastInsertId();
        }
        $this->observeAddress($id, $ip, 'observed');
        if ($siteOf !== null) { $this->applyObservedSite($id, $site, $siteOf($ip), $mode); }
        return $id;
    }

    /**
     * 觀測主機（沒有 LibreNMS 設備）的站點歸屬，由採集器依「站點與網段」設定填入。第一次有站點不算變更（還沒有任何時段依它而定），
     * 站點之後變了（含變成沒有），監控中的才像 syncDevice 一樣改回學習中（學習中的只換站點，已累積的區間才有用）。
     * LibreNMS 設備的站點由 syncDevice 管，已退役的不動。
     */
    public function observeSite(int $assetId, ?string $site): void
    {
        $q = $this->db->prepare('SELECT site_name,mode,device_id FROM ioc_behavior_assets WHERE id=?');
        $q->execute([$assetId]); $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!$a || $a['device_id'] !== null || $a['mode'] === 'retired') { return; }
        $this->applyObservedSite($assetId, $a['site_name'], $site, $a['mode']);
    }

    private function applyObservedSite(int $assetId, ?string $current, ?string $site, string $mode): void
    {
        $site = $site === null || $site === '' ? null : mb_substr($site, 0, 100);
        if ($current === $site) { return; }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE ioc_behavior_assets SET site_name=? WHERE id=?')->execute([$site, $assetId]);
            if ($current !== null && $mode === 'monitoring') { $this->restartLearning($assetId, 'site_changed', 'system', '站點歸屬變更，改回學習中（需重新按「啟用監控」）'); }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /**
     * 管理員把觀測到的未知主機確認為「受管主機」：可以訓練基線、啟用監控，不必綁定 LibreNMS 設備。
     * 既有的區間與已知目的地原封不動、學習起點不重設；之後同一個 IP 不受 24 小時限制（見 observeUnknown）。
     * 整批在同一個交易裡：任何一台不合格就全部不動。名稱沒填就用「主機 <最近的位址>」。
     *
     * @param array<int,array{id:mixed,name?:mixed}> $items
     * @return int 確認了幾台
     */
    public function confirmHosts(array $items, string $note, string $actor, ?string $level): int
    {
        Access::requireHandler($level, '只有管理者可以確認主機');
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 200) { throw new ValidationError(['請填寫 200 字以內的確認依據（例如 DHCP 租約、資產清冊核對結果）']); }
        if ($items === []) { throw new ValidationError(['請選擇要確認的主機']); }
        if (count($items) > self::MAX_CONFIRM_BATCH) { throw new ValidationError(['一次最多確認 ' . self::MAX_CONFIRM_BATCH . ' 台主機']); }
        $rows = []; $seen = [];
        foreach ($items as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if (!is_int($id) || $id < 1) { throw new ValidationError(['主機識別碼不正確']); }
            if (isset($seen[$id])) { throw new ValidationError(['主機重複：#' . $id]); }
            $name = $item['name'] ?? '';
            if (!is_string($name) || mb_strlen(trim($name)) > 200 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                throw new ValidationError(['名稱不正確（200 字以內、不含控制字元）：#' . $id]);
            }
            $seen[$id] = true;
            $rows[] = [$id, trim($name)];
        }
        // 一律依識別碼由小到大處理：兩位管理者交叉確認重疊的批次時，列鎖的順序一致，不會互相等到死結
        usort($rows, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        $this->db->beginTransaction();
        try {
            foreach ($rows as [$id, $name]) {
                $q = $this->db->prepare('SELECT identity_key,device_id,mode,confirmed_at FROM ioc_behavior_assets WHERE id=? FOR UPDATE');
                $q->execute([$id]); $asset = $q->fetch(PDO::FETCH_ASSOC);
                if (!$asset) { throw new NotFound('找不到主機 #' . $id); }
                if ($asset['device_id'] !== null || $asset['mode'] === 'retired' || $asset['confirmed_at'] !== null
                    || !str_starts_with($asset['identity_key'], 'observed:')) {
                    throw new ValidationError(['只能確認尚未確認的觀測主機：#' . $id]);
                }
                if ($name === '') {
                    $q = $this->db->prepare('SELECT address FROM ioc_behavior_addresses WHERE asset_id=? ORDER BY last_seen_at DESC,id DESC LIMIT 1');
                    $q->execute([$id]); $address = $q->fetchColumn();
                    $name = $address === false ? '主機 #' . $id : '主機 ' . $address;
                }
                $this->db->prepare('UPDATE ioc_behavior_assets SET name=?,confirmed_at=UTC_TIMESTAMP(),confirmed_by=?,confirm_note=? WHERE id=?')
                    ->execute([$name, mb_substr($actor, 0, 255), $note, $id]);
                $this->history($id, 'host_confirmed', $actor, '確認為受管主機：' . $note);
                $this->audit('host_confirmed', 'asset:' . $id, $actor);
            }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        return count($rows);
    }

    /** 身分只靠 IP 維繫的已確認主機隔 24 小時以上才又出現：留一筆歷史，請管理員確認仍是同一台。 */
    private function noteReappearance(int $id, string $ip, string $lastSeenAt): void
    {
        $gap = time() - (int) strtotime($lastSeenAt . ' UTC');
        if ($gap >= self::REAPPEAR_GAP_HOURS * 3600) {
            $this->history($id, 'reappeared', 'system', sprintf('位址 %s 隔 %d 天後重新出現；請確認仍是同一台主機（DHCP 可能已把位址租給別台）', $ip, intdiv($gap, 86400)));
        }
    }

    /** 依（資產，位址）比對：同一個位址再出現只更新那一列，輪流出現的位址不會每一輪都新增一列。 */
    private function observeAddress(int $id, string $ip, string $evidence): void
    {
        $q = $this->db->prepare('SELECT id FROM ioc_behavior_addresses WHERE asset_id=? AND address=? ORDER BY id DESC LIMIT 1');
        $q->execute([$id, $ip]); $current = $q->fetchColumn();
        if ($current !== false) {
            $q = $this->db->prepare('UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() WHERE id=?'); $q->execute([$current]);
        } else {
            $q = $this->db->prepare('INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?)');
            $q->execute([$id, $ip, $evidence]);
            $this->history($id, 'address_seen', 'system', $evidence);
        }
    }

    private function asset(int $id): array
    {
        $q = $this->db->prepare('SELECT * FROM ioc_behavior_assets WHERE id=?'); $q->execute([$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new NotFound('找不到裝置'); }
        return $row;
    }

    private function history(int $id, string $action, string $actor, string $detail): void
    {
        $q = $this->db->prepare('INSERT INTO ioc_behavior_history (asset_id,action,actor,occurred_at,detail) VALUES (?,?,?,UTC_TIMESTAMP(),?)');
        $q->execute([$id, $action, mb_substr($actor, 0, 255), mb_substr($detail, 0, 240)]);
    }

    private function audit(string $action, string $subject, string $actor): void
    {
        $q = $this->db->prepare('INSERT INTO ioc_behavior_audit (actor,action,subject,occurred_at) VALUES (?,?,?,UTC_TIMESTAMP())');
        $q->execute([mb_substr($actor, 0, 255), $action, mb_substr($subject, 0, 100)]);
    }

    private function webhookStatus(): array
    {
        $q = $this->db->query("SELECT status,error_code,delivered_at FROM ioc_behavior_deliveries ORDER BY next_attempt_at DESC LIMIT 1");
        return ['configured' => is_file('/etc/oracle-mon/ioc-behavior-webhook.conf'), 'recent' => $q->fetch(PDO::FETCH_ASSOC) ?: null];
    }
}
