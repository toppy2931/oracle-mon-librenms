<?php
declare(strict_types=1);

namespace Ioc;

use PDO;

/** 裝置行為生命週期。所有時間以 UTC 儲存；原始日誌不進 IOC 資料庫。 */
final class BehaviorService
{
    public const SOURCE = 'fortigate_graylog';
    public const LEARNING_DAYS = 28;
    /** 沒有設備、站點層級的上班時段，也沒有存過「預設」時，用這個（台北、週一到五 09:00–18:00）。 */
    public const DEFAULT_SCHEDULE = ['timezone' => 'Asia/Taipei', 'workdays' => '1,2,3,4,5', 'start_time' => '09:00:00', 'end_time' => '18:00:00'];
    public const MAX_CONFIRM_BATCH = 100;
    /** 已確認主機隔這麼久才又出現，就留一筆歷史請管理員確認（身分只靠 IP 維繫，位址可能已被別台取得）。 */
    public const REAPPEAR_GAP_HOURS = 24;
    private PDO $db;

    public function __construct(PDO $db) { $this->db = $db; }

    /** 檢視者只取得去識別的整體狀態。 */
    public function state(?string $level): array
    {
        if (!Access::canView($level)) { throw new AccessDenied('沒有檢視權限'); }
        $summary = ['learning' => 0, 'ready' => 0, 'monitoring' => 0, 'retired' => 0,
            'source_error' => 0, 'open_alerts' => 0];
        foreach ($this->db->query('SELECT mode,COUNT(*) n FROM ioc_behavior_assets GROUP BY mode')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($summary[$row['mode']])) { $summary[$row['mode']] = (int) $row['n']; }
        }
        $summary['source_error'] = (int) $this->db->query("SELECT COUNT(*) FROM ioc_behavior_sources WHERE status IN ('error','partial','missing')
            OR (source_key='fortigate_graylog' AND checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))")->fetchColumn();
        $summary['open_alerts'] = (int) $this->db->query("SELECT COUNT(*) FROM ioc_behavior_alerts WHERE status='open'")->fetchColumn();
        if (!Access::canHandle($level)) { return ['summary' => $summary, 'limited' => true]; }
        $assets = $this->db->query('SELECT a.id,a.identity_key,a.device_id,a.name,a.site_name,a.mode,a.learning_started_at,
            a.baseline_version,a.last_seen_at,a.quality_status,a.confirmed_at,
            (SELECT x.address FROM ioc_behavior_addresses x WHERE x.asset_id=a.id ORDER BY x.last_seen_at DESC,x.id DESC LIMIT 1) address
            FROM ioc_behavior_assets a ORDER BY a.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
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
        return ['summary' => $summary, 'limited' => false, 'assets' => $assets, 'sources' => $sources,
            'schedules' => $schedules, 'default_schedule' => $default, 'audit' => $audit, 'webhook_status' => $this->webhookStatus()];
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
        $q = $this->db->prepare('SELECT l.observed_asset_id,l.target_asset_id,l.confirmed_by,l.confirmed_at,l.evidence_note,
            a.name observed_name FROM ioc_behavior_links l JOIN ioc_behavior_assets a ON a.id=l.observed_asset_id
            WHERE l.target_asset_id=? OR l.observed_asset_id=? ORDER BY l.confirmed_at DESC LIMIT 30');
        $q->execute([$id, $id]); $links = $q->fetchAll(PDO::FETCH_ASSOC);
        $schedule = $this->scheduleFor($asset);     // 實際套用的上班時段與來源：啟用監控前要看得到是不是內建預設
        return compact('asset', 'addresses', 'windows', 'alerts', 'history', 'baseline', 'links', 'schedule');
    }

    public function setMode(int $id, string $mode, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以切換模式');
        if (!in_array($mode, ['learning', 'monitoring', 'retired'], true)) { throw new ValidationError(['模式不正確']); }
        $asset = $this->asset($id);
        if ($mode === 'monitoring') {
            if (!in_array($asset['mode'], ['ready', 'monitoring'], true)) {
                throw new ValidationError(['請先完成本輪學習並確認基線，才能啟用監控']);
            }
            if ($asset['device_id'] === null && $asset['confirmed_at'] === null) {
                throw new ValidationError(['待確認設備須先確認身分（關聯 LibreNMS 設備，或確認為受管主機），才能啟用監控']);
            }
            $since = strtotime($asset['learning_started_at'] . ' UTC');
            if ($since === false || time() - $since < self::LEARNING_DAYS * 86400) { throw new ValidationError(['學習期間尚未滿 28 天']); }
            if ((int) $asset['baseline_version'] < 1 || $asset['quality_status'] !== 'ready') { throw new ValidationError(['資料品質或基線尚未達到監控條件']); }
            // 上班時段不必先設定：沒有設備、站點層級的時段就用預設（scheduleFor）
            $source = $this->db->query("SELECT status,checked_at FROM ioc_behavior_sources WHERE source_key='fortigate_graylog'")->fetch(PDO::FETCH_ASSOC);
            if (!$source || $source['status'] !== 'ready' || strtotime($source['checked_at'] . ' UTC') < time() - 900) {
                throw new ValidationError(['防火牆日誌來源未就緒或已過期']);
            }
        }
        if ($asset['mode'] === $mode) { return; }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('UPDATE ioc_behavior_assets SET mode=?,learning_started_at=CASE WHEN ?=\'learning\' THEN ? ELSE learning_started_at END,
                quality_status=CASE WHEN ?=\'learning\' THEN \'learning\' ELSE quality_status END,
                training_checked_at=CASE WHEN ?=\'learning\' THEN NULL ELSE training_checked_at END WHERE id=?');
            $q->execute([$mode, $mode, $now, $mode, $mode, $id]);
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
     * 時段變動會讓哪些資產重新學習：「實際套用這個時段」而且已有基線的（就緒或監控中）。
     * 設備層級只動該設備；站點層級不動已有設備層級時段的；預設層級只動沒有設備也沒有站點層級時段的（含沒有 LibreNMS 設備的主機）。
     * 學習中的不算：訓練時才讀時段，它們沒有任何依賴舊時段的東西，重設起算時間只會白白損失已累積的天數。
     *
     * @return array<int,array{id:int|string,mode:string}>
     */
    private function assetsAffectedBySchedule(string $scope, string $key): array
    {
        if ($scope === 'device') {
            $sql = "SELECT id,mode FROM ioc_behavior_assets WHERE device_id=? AND mode IN ('ready','monitoring')";
        } elseif ($scope === 'site') {
            $sql = "SELECT a.id,a.mode FROM ioc_behavior_assets a WHERE a.site_name=? AND a.mode IN ('ready','monitoring')
                AND NOT EXISTS (SELECT 1 FROM ioc_behavior_schedules d WHERE d.scope='device'
                    AND d.scope_key=CAST(a.device_id AS CHAR))";
        } else {
            $sql = "SELECT a.id,a.mode FROM ioc_behavior_assets a WHERE a.mode IN ('ready','monitoring')
                AND NOT EXISTS (SELECT 1 FROM ioc_behavior_schedules d WHERE d.scope='device' AND d.scope_key=CAST(a.device_id AS CHAR))
                AND NOT EXISTS (SELECT 1 FROM ioc_behavior_schedules s WHERE s.scope='site' AND s.scope_key=a.site_name)";
        }
        $q = $this->db->prepare($sql);
        $q->execute($scope === 'default' ? [] : [$key]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 儲存前的影響預覽（唯讀）：這份時段會讓幾台資產重新學習 28 天（監控中的會暫停）。畫面據此在儲存前請管理員確認。
     *
     * @return array{changed:bool,ready:int,monitoring:int}
     */
    public function previewScheduleImpact(string $scope, string $key, string $timezone, string $workdays, string $start, string $end, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以設定上班時段');
        $this->validateSchedule($scope, $key, $timezone, $workdays, $start, $end);
        $out = ['changed' => $this->scheduleDiffers($scope, $key, $timezone, $workdays, $start, $end), 'ready' => 0, 'monitoring' => 0];
        if ($out['changed']) {
            foreach ($this->assetsAffectedBySchedule($scope, $key) as $row) { $out[$row['mode']]++; }
        }
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
                $this->restartLearning((int) $row['id'], 'schedule_changed', $actor, '上班時段變更，重新學習 28 天');
            }
            $this->audit('schedule_saved', $scope . ':' . $key, $actor);
        }
        $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    /**
     * 讓資產重新學習 28 天：學習起點與訓練檢查重設、已知目的地清掉，舊基線與區間保留（重新學習期間不用舊基線判斷）。
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
        if ($before && $before['mode'] !== 'retired' && $before['site_name'] !== $site) {
            $this->db->beginTransaction();
            try {
                $this->restartLearning($id, 'site_changed', 'system', '站點歸屬變更，重新學習 28 天');
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
        $q = $this->db->prepare("SELECT a.id,a.site_name,a.confirmed_at,x.last_seen_at FROM ioc_behavior_assets a JOIN ioc_behavior_addresses x ON x.asset_id=a.id
            WHERE x.address=? AND a.device_id IS NULL AND a.mode<>'retired'
              AND (a.confirmed_at IS NOT NULL OR x.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR))
            ORDER BY (a.confirmed_at IS NULL),x.last_seen_at DESC,x.id DESC LIMIT 1");
        $q->execute([$ip]); $row = $q->fetch(PDO::FETCH_ASSOC);
        $site = null;
        if ($row) {
            $id = (int) $row['id'];
            $site = $row['site_name'];
            if ($row['confirmed_at'] !== null) {
                $gap = time() - (int) strtotime($row['last_seen_at'] . ' UTC');
                if ($gap >= self::REAPPEAR_GAP_HOURS * 3600) {
                    $this->history($id, 'reappeared', 'system', sprintf('位址 %s 隔 %d 天後重新出現；請確認仍是同一台主機（DHCP 可能已把位址租給別台）', $ip, intdiv($gap, 86400)));
                }
            }
        } else {
            $key = 'observed:' . $ip . ':' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(3));
            $q = $this->db->prepare('INSERT INTO ioc_behavior_assets (identity_key,name,learning_started_at)
                VALUES (?,?,UTC_TIMESTAMP())');
            $q->execute([$key, '待確認設備 ' . $ip]);
            $id = (int) $this->db->lastInsertId();
        }
        $this->observeAddress($id, $ip, 'observed');
        if ($siteOf !== null) { $this->applyObservedSite($id, $site, $siteOf($ip)); }
        return $id;
    }

    /**
     * 觀測主機（沒有 LibreNMS 設備）的站點歸屬，由採集器依「站點與網段」設定填入。第一次有站點不算變更（還沒有任何時段依它而定），
     * 站點之後變了（含變成沒有）才像 syncDevice 一樣重新學習。LibreNMS 設備的站點由 syncDevice 管，已退役的不動。
     */
    public function observeSite(int $assetId, ?string $site): void
    {
        $q = $this->db->prepare('SELECT site_name,mode,device_id FROM ioc_behavior_assets WHERE id=?');
        $q->execute([$assetId]); $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!$a || $a['device_id'] !== null || $a['mode'] === 'retired') { return; }
        $this->applyObservedSite($assetId, $a['site_name'], $site);
    }

    private function applyObservedSite(int $assetId, ?string $current, ?string $site): void
    {
        $site = $site === null || $site === '' ? null : mb_substr($site, 0, 100);
        if ($current === $site) { return; }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE ioc_behavior_assets SET site_name=? WHERE id=?')->execute([$site, $assetId]);
            if ($current !== null) { $this->restartLearning($assetId, 'site_changed', 'system', '站點歸屬變更，重新學習 28 天'); }
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

    private function observeAddress(int $id, string $ip, string $evidence): void
    {
        $q = $this->db->prepare('SELECT id,address FROM ioc_behavior_addresses WHERE asset_id=? ORDER BY id DESC LIMIT 1');
        $q->execute([$id]); $current = $q->fetch(PDO::FETCH_ASSOC);
        if ($current && $current['address'] === $ip) {
            $q = $this->db->prepare('UPDATE ioc_behavior_addresses SET last_seen_at=UTC_TIMESTAMP() WHERE id=?'); $q->execute([$current['id']]);
        } else {
            $q = $this->db->prepare('INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?)');
            $q->execute([$id, $ip, $evidence]);
            $this->history($id, 'address_seen', 'system', $evidence);
        }
    }

    public function linkAsset(int $observedId, int $deviceId, string $reason, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以關聯裝置');
        if (trim($reason) === '' || mb_strlen($reason) > 200) { throw new ValidationError(['請填寫 200 字以內的身分確認依據']); }
        $observed = $this->asset($observedId);
        if ($observed['device_id'] !== null || $observed['mode'] === 'retired'
            || !str_starts_with($observed['identity_key'], 'observed:')) { throw new ValidationError(['只能關聯尚未確認的暫時設備']); }
        if ($observed['confirmed_at'] !== null) {
            // 關聯只會把這台退役、不合併歷史：已確認的主機可能已經訓練出基線、正在監控，一次點擊就會把這些全丟掉（等 M2b 的合併）
            throw new ValidationError(['已確認的主機不能直接關聯 LibreNMS 設備：關聯不會合併歷史，已訓練的基線與監控會遺失。請先停用，或等合併功能']);
        }
        $q = $this->db->prepare('SELECT id FROM ioc_behavior_assets WHERE device_id=?'); $q->execute([$deviceId]); $targetId = (int) $q->fetchColumn();
        if (!$targetId) { throw new NotFound('LibreNMS 裝置尚未同步'); }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('UPDATE ioc_behavior_assets SET mode=\'retired\' WHERE id=?'); $q->execute([$observedId]);
            $q = $this->db->prepare('INSERT INTO ioc_behavior_links (observed_asset_id,target_asset_id,confirmed_by,confirmed_at,evidence_note)
                VALUES (?,?,?,UTC_TIMESTAMP(),?)');
            $q->execute([$observedId, $targetId, mb_substr($actor, 0, 255), trim($reason)]);
            $q = $this->db->prepare('INSERT INTO ioc_behavior_addresses (asset_id,address,first_seen_at,last_seen_at,evidence_kind)
                SELECT ?,address,first_seen_at,last_seen_at,\'admin_confirmed\' FROM ioc_behavior_addresses WHERE asset_id=?'); $q->execute([$targetId, $observedId]);
            $this->history($observedId, 'linked_to_device', $actor, 'LibreNMS #' . $deviceId);
            $this->history($targetId, 'linked_observation', $actor, '暫時資產 #' . $observedId);
            $this->audit('asset_linked', 'asset:' . $observedId . '→' . $targetId, $actor);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
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
