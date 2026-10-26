<?php
declare(strict_types=1);

namespace Ioc;

use PDO;

/** 每分鐘檢查上一個完整的五分鐘區間；只讀 Graylog 彙整，不保存原始訊息。 */
final class BehaviorCollector
{
    private const LIMIT = 500;
    private PDO $db;
    private WindowedLogSource $logs;
    private BehaviorService $assets;

    public function __construct(PDO $db, WindowedLogSource $logs)
    {
        $this->db = $db; $this->logs = $logs; $this->assets = new BehaviorService($db);
    }

    /**
     * @param array<int,array{id:int,name:string,ip:?string,site:?string}> $devices
     * @param string[] $nets
     * @param (callable(string):?string)|null $siteOf 依 IP 找站點（設定頁的「站點與網段」）：觀測主機沒有 LibreNMS 設備，站點只能靠這個；
     *        不傳就不動它們的站點
     */
    public function tick(array $devices, array $nets, ?int $now = null, ?callable $siteOf = null): void
    {
        $now ??= time();
        $ids = []; $ambiguous = [];
        foreach ($devices as $device) {
            $id = $this->assets->syncDevice($device['id'], $device['name'], $device['ip'], $device['site']);
            if ($device['ip'] !== null && $nets && IpNets::contains($nets, $device['ip'])) {
                if (isset($ids[$device['ip']])) { $ambiguous[$device['ip']] = true; }
                else { $ids[$device['ip']] = $id; }
            }
        }
        if (!$nets) { $this->source('error', '內部網段未設定', null); return; }
        $start = intdiv($now, 300) * 300 - 300;
        $window = gmdate('Y-m-d H:i:s', $start);
        $q = $this->db->prepare('SELECT last_window_at FROM ioc_behavior_sources WHERE source_key=?');
        $q->execute([BehaviorService::SOURCE]);
        $done = $q->fetchColumn();
        if ($done !== false && $done !== null && $done >= $window) { return; }
        $from = gmdate('Y-m-d\TH:i:s\Z', $start);
        $to = gmdate('Y-m-d\TH:i:s\Z', $start + 300);
        try {
            $health = $this->logs->aggregateBetween(OverviewService::demoFilter('log_type:firewall', false), $from, $to, ['cat'], ['count'], 40);
            $events = array_sum(array_map(static fn ($r) => (int) ($r['count'] ?? 0), $health));
            if ($events === 0) { $this->source('missing', '此時段未收到防火牆日誌；不能視為零流量', null); return; }
            $base = 'log_type:firewall AND cat:"traffic:forward" AND ' . IpNets::lucene('src_ip', $nets)
                . ' AND NOT ' . IpNets::lucene('dst_ip', $nets);
            $base = OverviewService::demoFilter($base, false);
            $summary = $this->logs->aggregateBetween($base, $from, $to, ['src_ip'],
                ['count', 'sum:net_bytes', 'card:dst_ip', 'card:dstport'], self::LIMIT);
            $withBytes = $this->logs->aggregateBetween($base . ' AND _exists_:net_bytes', $from, $to,
                ['src_ip'], ['count'], self::LIMIT);
            $destRows = $this->logs->aggregateBetween($base, $from, $to, ['src_ip', 'dst_ip'], ['count'], self::LIMIT);
            $portRows = $this->logs->aggregateBetween($base, $from, $to, ['src_ip', 'dstport'], ['count'], self::LIMIT);
        } catch (GraylogUnavailable $e) {
            $this->source('error', 'Graylog 查詢失敗或逾時', null); return;
        }
        $truncated = count($summary) >= self::LIMIT || count($withBytes) >= self::LIMIT
            || count($destRows) >= self::LIMIT || count($portRows) >= self::LIMIT;
        $byteCounts = [];
        foreach ($withBytes as $row) { if (isset($row['src_ip'])) { $byteCounts[(string) $row['src_ip']] = (int) ($row['count'] ?? 0); } }
        $dest = []; $ports = []; $destCounts = []; $portCounts = [];
        foreach ($destRows as $row) {
            $ip = (string) ($row['src_ip'] ?? ''); $target = (string) ($row['dst_ip'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP) && filter_var($target, FILTER_VALIDATE_IP)) {
                $dest[$ip][$target] = true;
                $destCounts[$ip] = ($destCounts[$ip] ?? 0) + max(0, (int) ($row['count'] ?? 0));
            }
        }
        foreach ($portRows as $row) {
            $ip = (string) ($row['src_ip'] ?? ''); $port = $row['dstport'] ?? null;
            if (filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($port) && (int) $port >= 0 && (int) $port <= 65535) {
                $ports[$ip][(string) (int) $port] = true;
                $portCounts[$ip] = ($portCounts[$ip] ?? 0) + max(0, (int) ($row['count'] ?? 0));
            }
        }
        $byIp = [];
        foreach ($summary as $row) {
            $ip = (string) ($row['src_ip'] ?? '');
            if (IpNets::contains($nets, $ip)) { $byIp[$ip] = $row; }
        }
        foreach (array_keys($byIp) as $ip) {
            if (!isset($ids[$ip])) { $ids[$ip] = $this->assets->observeUnknown($ip, $siteOf); }
        }
        $partial = $truncated;
        $modeQuery = $this->db->prepare('SELECT mode FROM ioc_behavior_assets WHERE id=?');
        foreach ($ids as $ip => $assetId) {
            $modeQuery->execute([$assetId]);
            if ($modeQuery->fetchColumn() === 'retired') { continue; }
            $row = $byIp[$ip] ?? [];
            $count = max(0, (int) ($row['count'] ?? 0));
            // 全域日誌有資料，不代表這台設備的日誌沒有中斷；缺席不能補成零流量。
            $facts = [$count, $truncated, isset($ambiguous[$ip]), $byteCounts[$ip] ?? 0, $destCounts[$ip] ?? 0, $portCounts[$ip] ?? 0];
            $quality = BehaviorAnalyzer::windowQuality(...$facts);
            if ($quality === 'partial') { $partial = true; }
            $record = ['asset_id' => $assetId, 'source_key' => BehaviorService::SOURCE,
                'window_start' => $window, 'connections' => $count,
                'bytes_total' => max(0, (int) ($row['sum_net_bytes'] ?? 0)),
                'destinations' => max(0, (int) ($row['card_dst_ip'] ?? 0)),
                'ports' => max(0, (int) ($row['card_dstport'] ?? 0)),
                'destinations_json' => json_encode(array_keys($dest[$ip] ?? []), JSON_THROW_ON_ERROR),
                'ports_json' => json_encode(array_keys($ports[$ip] ?? []), JSON_THROW_ON_ERROR),
                'signals_json' => '{"dns":false,"tls":false,"scope":"firewall_only"}',
                'quality' => $quality, 'quality_reason' => BehaviorAnalyzer::windowQualityReason(...$facts)];
            $this->storeWindow($record);
            if ($quality === 'complete') { $this->evaluate($record, array_keys($dest[$ip] ?? []), array_keys($ports[$ip] ?? [])); }
        }
        $this->source($partial ? 'partial' : 'ready', $partial ? '分組達上限、位址衝突或必要欄位缺值；受影響區間不會用於判斷' : '', $window);
        $this->trainPending();
    }

    private function storeWindow(array $r): void
    {
        $sql = 'INSERT INTO ioc_behavior_windows (asset_id,source_key,window_start,connections,bytes_total,destinations,ports,
            destinations_json,ports_json,signals_json,quality,quality_reason,observed_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
            connections=VALUES(connections),bytes_total=VALUES(bytes_total),destinations=VALUES(destinations),ports=VALUES(ports),
            destinations_json=VALUES(destinations_json),ports_json=VALUES(ports_json),signals_json=VALUES(signals_json),
            quality=VALUES(quality),quality_reason=VALUES(quality_reason),observed_at=VALUES(observed_at)';
        $this->db->prepare($sql)->execute([$r['asset_id'], $r['source_key'], $r['window_start'], $r['connections'], $r['bytes_total'],
            $r['destinations'], $r['ports'], $r['destinations_json'], $r['ports_json'], $r['signals_json'], $r['quality'], $r['quality_reason']]);
        $q = $this->db->prepare("UPDATE ioc_behavior_assets SET
            last_seen_at=CASE WHEN ? > 0 THEN UTC_TIMESTAMP() ELSE last_seen_at END,
            quality_status=CASE WHEN ? IN ('partial','missing') THEN ? WHEN baseline_version>0 AND mode<>'learning' THEN 'ready' ELSE 'learning' END
            WHERE id=?");
        $q->execute([$r['connections'], $r['quality'], $r['quality'], $r['asset_id']]);
    }

    private function source(string $status, string $reason, ?string $window): void
    {
        $sql = 'INSERT INTO ioc_behavior_sources (source_key,kind,scope_label,status,coverage,last_seen_at,last_window_at,checked_at,reason)
            VALUES (?,\'fortigate\',\'FortiGate → Graylog\',?,\'僅經過防火牆的對外流量\',CASE WHEN ? IN (\'ready\',\'partial\') THEN UTC_TIMESTAMP() ELSE NULL END,?,UTC_TIMESTAMP(),?)
            ON DUPLICATE KEY UPDATE status=VALUES(status),coverage=VALUES(coverage),
            last_seen_at=CASE WHEN VALUES(status) IN (\'ready\',\'partial\') THEN UTC_TIMESTAMP() ELSE last_seen_at END,
            last_window_at=COALESCE(VALUES(last_window_at),last_window_at),checked_at=UTC_TIMESTAMP(),reason=VALUES(reason)';
        $this->db->prepare($sql)->execute([BehaviorService::SOURCE, $status, $status, $window, $reason]);
    }

    private function evaluate(array $r, array $dest, array $ports): void
    {
        $q = $this->db->prepare('SELECT id,mode,baseline_version,site_name,device_id FROM ioc_behavior_assets WHERE id=?');
        $q->execute([$r['asset_id']]); $asset = $q->fetch(PDO::FETCH_ASSOC);
        if (!$asset || $asset['mode'] === 'retired') { return; }
        if ((int) $asset['baseline_version'] < 1) {
            $this->learnKnown((int) $r['asset_id'], $dest, $ports); return;
        }
        // 重新學習期間不得拿舊版基線判斷；新版就緒後才恢復模擬與正式警示。
        if ($asset['mode'] === 'learning') {
            $this->learnKnown((int) $r['asset_id'], $dest, $ports); return;
        }
        $schedule = $this->assets->scheduleFor($asset);
        $bucket = BehaviorAnalyzer::localBucket($r['window_start'], $schedule);
        $onDuty = $bucket['on_duty'];
        $q = $this->db->prepare('SELECT * FROM ioc_behavior_baselines WHERE asset_id=? AND version=? AND source_key=? AND day_kind=? AND hour_of_day=?');
        $q->execute([$r['asset_id'], $asset['baseline_version'], $r['source_key'], $bucket['day_kind'], $bucket['hour']]);
        $baseline = $q->fetch(PDO::FETCH_ASSOC);
        if (!$baseline) { $this->learnKnown((int) $r['asset_id'], $dest, $ports); return; }
        $newDest = $this->novel((int) $r['asset_id'], 'destination', $dest);
        $newPorts = $this->novel((int) $r['asset_id'], 'port', $ports);
        $findings = BehaviorAnalyzer::evaluate($r, $baseline, !$onDuty, $newDest, $newPorts);
        foreach ($findings as $finding) { $this->recordAlert($asset, $r, $finding); }
        // 首次出現的目的地只算一次；不在後續時段重複宣稱「新出現」。
        $this->learnKnown((int) $r['asset_id'], $dest, $ports);
    }

    private function learnKnown(int $id, array $dest, array $ports): void
    {
        $q = $this->db->prepare('INSERT INTO ioc_behavior_known (asset_id,source_key,kind,value_key,first_seen_at,last_seen_at)
            VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_seen_at=UTC_TIMESTAMP(),seen_count=seen_count+1');
        foreach (['destination' => $dest, 'port' => $ports] as $kind => $items) {
            foreach (array_slice($items, 0, 100) as $value) { $q->execute([$id, BehaviorService::SOURCE, $kind, (string) $value]); }
        }
    }

    private function novel(int $id, string $kind, array $items): array
    {
        if (!$items) { return []; }
        $q = $this->db->prepare('SELECT 1 FROM ioc_behavior_known WHERE asset_id=? AND source_key=? AND kind=? AND value_key=?');
        $new = [];
        foreach (array_slice($items, 0, 100) as $item) {
            $q->execute([$id, BehaviorService::SOURCE, $kind, (string) $item]);
            if (!$q->fetchColumn()) { $new[] = $item; }
        }
        return $new;
    }

    private function recordAlert(array $asset, array $r, array $finding): void
    {
        $preview = $asset['mode'] !== 'monitoring';
        $dedup = BehaviorAnalyzer::dedupKey((int) $asset['id'], $finding['detector'], (int) $asset['baseline_version'], $preview, $r['window_start']);
        $q = $this->db->prepare('INSERT INTO ioc_behavior_alerts (dedup_key,asset_id,detector,window_start,baseline_version,
            severity,status,score,summary,metrics_json,first_seen_at,last_seen_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
            last_seen_at=UTC_TIMESTAMP(),occurrences=occurrences+IF(VALUES(window_start)>window_start,1,0),
            window_start=GREATEST(window_start,VALUES(window_start)),metrics_json=VALUES(metrics_json)');
        $q->execute([$dedup, $asset['id'], $finding['detector'], $r['window_start'], $asset['baseline_version'],
            $finding['severity'], $preview ? 'preview' : 'open', $finding['score'], $finding['summary'],
            json_encode($finding['metrics'], JSON_THROW_ON_ERROR)]);
        if ($preview) { return; }
        $q = $this->db->prepare('SELECT id FROM ioc_behavior_alerts WHERE dedup_key=?'); $q->execute([$dedup]);
        $alert = $q->fetch(PDO::FETCH_ASSOC);
        if (!$alert) { return; }
        $this->ensureCase((int) $alert['id']);
        $this->db->prepare("INSERT IGNORE INTO ioc_behavior_deliveries (alert_id,status,next_attempt_at) VALUES (?,'queued',UTC_TIMESTAMP())")
            ->execute([$alert['id']]);
    }

    /** 事件案若因暫時性錯誤未建立，背景工作會持續補建；dedup_key 保證不重複。 */
    public function repairOpenCases(): void
    {
        $ids = $this->db->query("SELECT id FROM ioc_behavior_alerts WHERE status='open' AND case_id IS NULL ORDER BY id LIMIT 5")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) { $this->ensureCase((int) $id); }
    }

    private function ensureCase(int $alertId): void
    {
        $q = $this->db->prepare('SELECT a.id,a.asset_id,a.dedup_key,a.summary,a.severity,a.case_id,x.name,
            (SELECT address FROM ioc_behavior_addresses WHERE asset_id=a.asset_id ORDER BY id DESC LIMIT 1) address
            FROM ioc_behavior_alerts a JOIN ioc_behavior_assets x ON x.id=a.asset_id WHERE a.id=?');
        $q->execute([$alertId]); $alert = $q->fetch(PDO::FETCH_ASSOC);
        if (!$alert || $alert['case_id'] !== null) { return; }
        try {
            $cases = new CaseService(new PdoCaseRepo($this->db));
            $result = $cases->create(['title' => '裝置行為異常：' . $alert['summary'], 'severity' => $alert['severity'],
                'category' => 'other', 'subject_ip' => $alert['address'], 'subject_host' => mb_substr($alert['name'], 0, 128),
                'summary' => $alert['summary'] . '；來源：FortiGate 防火牆日誌，僅涵蓋經過防火牆的流量。',
                'dedup_key' => 'behavior:' . $alert['dedup_key']], 'automation', Access::HANDLER, 'automation');
            $this->db->prepare('UPDATE ioc_behavior_alerts SET case_id=? WHERE id=? AND case_id IS NULL')
                ->execute([$result['id'], $alertId]);
        } catch (\Throwable $e) { error_log('IOC behavior case creation failed for alert #' . $alertId); }
    }

    private function trainPending(): void
    {
        // 只訓練身分確認過的：LibreNMS 設備與已確認主機。未確認的暫時資產只累積區間（確認之後有歷史可用）
        $row = $this->db->query("SELECT id FROM ioc_behavior_assets WHERE mode='learning'
            AND (device_id IS NOT NULL OR confirmed_at IS NOT NULL)
            AND learning_started_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 28 DAY)
            ORDER BY training_checked_at IS NOT NULL,training_checked_at,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return; }
        $id = (int) $row['id'];
        $this->db->prepare('UPDATE ioc_behavior_assets SET training_checked_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
        $this->train($id);
    }

    private function train(int $id): void
    {
        $q = $this->db->prepare("SELECT window_start,bytes_total,connections,destinations FROM ioc_behavior_windows
            WHERE asset_id=? AND source_key=? AND quality='complete' AND window_start>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 28 DAY)
            ORDER BY window_start");
        $q->execute([$id, BehaviorService::SOURCE]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < BehaviorAnalyzer::TRAIN_MIN_WINDOWS) { return; }     // 先擋掉，省下查排程的兩次查詢
        $schedule = $this->assets->scheduleFor(['device_id' => $this->deviceId($id), 'site_name' => $this->siteName($id)]);
        $groups = BehaviorAnalyzer::trainingGroups($rows, $schedule['timezone']);
        if ($groups === null) { return; }
        $q = $this->db->prepare('SELECT baseline_version FROM ioc_behavior_assets WHERE id=?'); $q->execute([$id]);
        $version = (int) $q->fetchColumn() + 1;
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare('INSERT INTO ioc_behavior_baselines (asset_id,version,source_key,day_kind,hour_of_day,
                median_bytes,p95_bytes,mad_bytes,median_connections,p95_connections,mad_connections,
                median_destinations,p95_destinations,mad_destinations,sample_count,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            foreach ($groups as $key => $bucket) {
                [$kind, $hour] = explode(':', $key);
                $b = BehaviorAnalyzer::stats(array_column($bucket, 'bytes_total'));
                $c = BehaviorAnalyzer::stats(array_column($bucket, 'connections'));
                $d = BehaviorAnalyzer::stats(array_column($bucket, 'destinations'));
                $insert->execute([$id, $version, BehaviorService::SOURCE, $kind, (int) $hour,
                    $b['median'], $b['p95'], $b['mad'], $c['median'], $c['p95'], $c['mad'],
                    $d['median'], $d['p95'], $d['mad'], count($bucket)]);
            }
            $this->db->prepare("UPDATE ioc_behavior_assets SET baseline_version=?,quality_status='ready',mode='ready' WHERE id=?")
                ->execute([$version, $id]);
            $this->db->prepare("INSERT INTO ioc_behavior_history (asset_id,action,actor,occurred_at,detail)
                VALUES (?,'baseline_ready','system',UTC_TIMESTAMP(),'28 天基線完成，等待管理者啟用監控')")->execute([$id]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    private function deviceId(int $id): ?int
    {
        $q = $this->db->prepare('SELECT device_id FROM ioc_behavior_assets WHERE id=?'); $q->execute([$id]);
        $v = $q->fetchColumn(); return $v === false || $v === null ? null : (int) $v;
    }
    private function siteName(int $id): ?string
    {
        $q = $this->db->prepare('SELECT site_name FROM ioc_behavior_assets WHERE id=?'); $q->execute([$id]);
        $v = $q->fetchColumn(); return $v === false || $v === null ? null : (string) $v;
    }
}
