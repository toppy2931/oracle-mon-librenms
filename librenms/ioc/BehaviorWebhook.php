<?php
declare(strict_types=1);

namespace Ioc;

use PDO;

/** 受限 VM 設定檔持有密鑰；HTTP 回應及錯誤不得寫入稽核或頁面。 */
final class BehaviorWebhook
{
    private PDO $db;
    private string $path;

    public function __construct(PDO $db, string $path = '/etc/oracle-mon/ioc-behavior-webhook.conf')
    { $this->db = $db; $this->path = $path; }

    /** @return array{url:string,secret:string}|null */
    public function config(): ?array
    {
        if (!is_file($this->path) || !is_readable($this->path)) { return null; }
        $raw = file_get_contents($this->path);
        if ($raw === false || strlen($raw) > 8192) { return null; }
        return self::parseConfig($raw);
    }

    /** 供設定檔驗證與離線測試使用；呼叫端不得記錄回傳的密鑰。 */
    public static function parseConfig(string $raw): ?array
    {
        $c = GraylogClient::parseConf($raw);
        $url = (string) ($c['WEBHOOK_URL'] ?? '');
        $secretHex = (string) ($c['WEBHOOK_SECRET_HEX'] ?? '');
        $u = parse_url($url);
        if (($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['pass'])
            || isset($u['fragment']) || !preg_match('/\A[0-9a-f]{64}\z/D', $secretHex)) { return null; }
        return ['url' => $url, 'secret' => hex2bin($secretHex)];
    }

    public function deliverDue(): void
    {
        $config = $this->config();
        if (!$config) { $this->source('unavailable', 'Webhook 尚未設定或格式不正確'); return; }
        $this->source('ready', '');
        $rows = $this->db->query("SELECT d.alert_id,d.attempts,a.detector,a.severity,a.score,a.summary,a.case_id,a.window_start,a.asset_id
            FROM ioc_behavior_deliveries d JOIN ioc_behavior_alerts a ON a.id=d.alert_id
            WHERE d.status='queued' AND d.next_attempt_at<=UTC_TIMESTAMP() AND a.status='open'
            ORDER BY d.next_attempt_at,d.alert_id LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $payload = ['schema_version' => 1, 'event_id' => 'ioc-behavior-' . $row['alert_id'],
                'asset_ref' => 'asset-' . $row['asset_id'], 'detector' => $row['detector'],
                'severity' => $row['severity'], 'score' => (int) $row['score'], 'summary' => $row['summary'],
                'window_start_utc' => $row['window_start'] . 'Z', 'case_id' => $row['case_id'] === null ? null : (int) $row['case_id']];
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $timestamp = (string) time();
            $signature = hash_hmac('sha256', $timestamp . '.' . $body, $config['secret']);
            $ch = curl_init($config['url']);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int => strlen($chunk),
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-IOC-Timestamp: ' . $timestamp,
                    'X-IOC-Event-ID: ioc-behavior-' . $row['alert_id'], 'X-IOC-Signature: sha256=' . $signature]]);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErr = curl_errno($ch);
            curl_close($ch);
            $ok = $response !== false && $curlErr === 0 && $code >= 200 && $code < 300;
            $attempt = (int) $row['attempts'] + 1;
            $status = $ok ? 'delivered' : ($attempt >= 5 ? 'failed' : 'queued');
            $delay = min(3600, 60 * (1 << min(5, $attempt - 1)));
            $q = $this->db->prepare('UPDATE ioc_behavior_deliveries SET status=?,attempts=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),
                last_http_status=?,error_code=?,delivered_at=CASE WHEN ?=\'delivered\' THEN UTC_TIMESTAMP() ELSE delivered_at END
                WHERE alert_id=? AND status=\'queued\'');
            $q->execute([$status, $attempt, $delay, $code ?: null, $ok ? '' : ($curlErr ? 'transport_error' : 'http_error'), $status, $row['alert_id']]);
        }
    }

    private function source(string $status, string $reason): void
    {
        $q = $this->db->prepare('INSERT INTO ioc_behavior_sources (source_key,kind,scope_label,status,coverage,checked_at,reason)
            VALUES (\'webhook\',\'webhook\',\'管理員通知\',?,\'已簽章 HTTPS 告警\',UTC_TIMESTAMP(),?)
            ON DUPLICATE KEY UPDATE status=VALUES(status),checked_at=UTC_TIMESTAMP(),reason=VALUES(reason)');
        $q->execute([$status, $reason]);
    }
}
