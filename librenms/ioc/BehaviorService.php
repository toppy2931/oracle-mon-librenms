<?php
declare(strict_types=1);

namespace Ioc;

use PDO;

/** 裝置行為生命週期。所有時間以 UTC 儲存；原始日誌不進 IOC 資料庫。 */
final class BehaviorService
{
    public const SOURCE = 'fortigate_graylog';
    public const LEARNING_DAYS = 28;
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
            a.baseline_version,a.last_seen_at,a.quality_status,
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
        return ['summary' => $summary, 'limited' => false, 'assets' => $assets, 'sources' => $sources,
            'schedules' => $schedules, 'audit' => $audit, 'webhook_status' => $this->webhookStatus()];
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
        return compact('asset', 'addresses', 'windows', 'alerts', 'history', 'baseline', 'links');
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
            if ($asset['device_id'] === null) { throw new ValidationError(['待確認設備須先確認身分，才能啟用監控']); }
            $since = strtotime($asset['learning_started_at'] . ' UTC');
            if ($since === false || time() - $since < self::LEARNING_DAYS * 86400) { throw new ValidationError(['學習期間尚未滿 28 天']); }
            if ((int) $asset['baseline_version'] < 1 || $asset['quality_status'] !== 'ready') { throw new ValidationError(['資料品質或基線尚未達到監控條件']); }
            $q = $this->db->prepare("SELECT COUNT(*) FROM ioc_behavior_schedules WHERE (scope='device' AND scope_key=?) OR (scope='site' AND scope_key=?)");
            $q->execute([(string) ($asset['device_id'] ?? ''), (string) ($asset['site_name'] ?? '')]);
            if ((int) $q->fetchColumn() === 0) { throw new ValidationError(['請先設定上班時段']); }
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

    public function saveSchedule(string $scope, string $key, string $timezone, string $workdays, string $start, string $end, string $actor, ?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以設定上班時段');
        if (!in_array($scope, ['site', 'device'], true) || $key === '' || mb_strlen($key) > 100
            || preg_match('/[\x00-\x1f\x7f]/', $key) || ($scope === 'device' && !ctype_digit($key))) { throw new ValidationError(['範圍不正確']); }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) { throw new ValidationError(['時區不正確']); }
        if (!preg_match('/\A[1-7](?:,[1-7]){0,6}\z/D', $workdays) || count(array_unique(explode(',', $workdays))) !== count(explode(',', $workdays))) { throw new ValidationError(['工作日格式不正確']); }
        if (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $start) || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D', $end) || $start >= $end) { throw new ValidationError(['上班時間不正確']); }
        $q = $this->db->prepare('SELECT timezone,workdays,start_time,end_time FROM ioc_behavior_schedules WHERE scope=? AND scope_key=?');
        $q->execute([$scope, $key]); $before = $q->fetch(PDO::FETCH_ASSOC);
        $changed = !$before || $before['timezone'] !== $timezone || $before['workdays'] !== $workdays
            || substr($before['start_time'], 0, 5) !== $start || substr($before['end_time'], 0, 5) !== $end;
        $this->db->beginTransaction();
        try {
        $q = $this->db->prepare('INSERT INTO ioc_behavior_schedules (scope,scope_key,timezone,workdays,start_time,end_time,updated_by,updated_at)
            VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE timezone=VALUES(timezone),workdays=VALUES(workdays),start_time=VALUES(start_time),end_time=VALUES(end_time),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)');
        $q->execute([$scope, $key, $timezone, $workdays, $start . ':00', $end . ':00', $actor]);
        if ($changed) {
            $sql = $scope === 'device'
                ? "SELECT id FROM ioc_behavior_assets WHERE device_id=? AND mode IN ('learning','ready','monitoring')"
                : "SELECT a.id FROM ioc_behavior_assets a WHERE a.site_name=? AND a.mode IN ('learning','ready','monitoring')
                    AND NOT EXISTS (SELECT 1 FROM ioc_behavior_schedules d WHERE d.scope='device'
                        AND d.scope_key=CAST(a.device_id AS CHAR))";
            $q = $this->db->prepare($sql);
            $q->execute([$key]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $this->db->prepare("UPDATE ioc_behavior_assets SET mode='learning',quality_status='learning',learning_started_at=UTC_TIMESTAMP(),training_checked_at=NULL WHERE id=?")->execute([$id]);
                $this->db->prepare('DELETE FROM ioc_behavior_known WHERE asset_id=?')->execute([$id]);
                $this->history((int) $id, 'schedule_changed', $actor, '上班時段變更，重新學習 28 天');
            }
        }
        if ($changed) { $this->audit('schedule_saved', $scope . ':' . $key, $actor); }
        $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
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
                $this->db->prepare("UPDATE ioc_behavior_assets SET mode='learning',quality_status='learning',
                    learning_started_at=UTC_TIMESTAMP(),training_checked_at=NULL WHERE id=? AND mode<>'retired'")->execute([$id]);
                $this->db->prepare('DELETE FROM ioc_behavior_known WHERE asset_id=?')->execute([$id]);
                $this->history($id, 'site_changed', 'system', '站點歸屬變更，重新學習 28 天');
                $this->db->commit();
            } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        }
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP)) { $this->observeAddress($id, $ip, 'librenms'); }
        return $id;
    }

    public function observeUnknown(string $ip): int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { throw new ValidationError(['IP 格式不正確']); }
        $q = $this->db->prepare("SELECT a.id FROM ioc_behavior_assets a JOIN ioc_behavior_addresses x ON x.asset_id=a.id
            WHERE x.address=? AND a.device_id IS NULL AND a.mode<>'retired' AND x.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
            ORDER BY x.last_seen_at DESC,x.id DESC LIMIT 1");
        $q->execute([$ip]); $id = (int) $q->fetchColumn();
        if (!$id) {
            $key = 'observed:' . $ip . ':' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(3));
            $q = $this->db->prepare('INSERT INTO ioc_behavior_assets (identity_key,name,learning_started_at)
                VALUES (?,?,UTC_TIMESTAMP())');
            $q->execute([$key, '待確認設備 ' . $ip]);
            $id = (int) $this->db->lastInsertId();
        }
        $this->observeAddress($id, $ip, 'observed');
        return $id;
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
