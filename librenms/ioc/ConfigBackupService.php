<?php
declare(strict_types=1);

namespace Ioc;

/** 管理者組態備份領域邊界；設備位址一律取自可信 LibreNMS 目錄。 */
final class ConfigBackupService
{
    private const HISTORY_PAGE_SIZE = 10;

    /** @var callable(array):array */
    private $transport;
    /** @var array<int,array{device_id:int,name:string,host:string}> */
    private array $devices;

    public function __construct(callable $transport, array $devices)
    {
        $this->transport = $transport;
        $this->devices = [];
        foreach ($devices as $device) {
            if (isset($device['device_id'], $device['name'], $device['host'])) {
                $this->devices[(int) $device['device_id']] = $device;
            }
        }
    }

    public function status(?string $level): array
    {
        $this->authorize($level);
        return $this->request(['action' => 'status', 'actor' => '']);
    }

    public function save(array $settings, string $actor, ?string $level): array
    {
        $this->authorize($level);
        $this->actor($actor);
        if (!is_bool($settings['enabled'] ?? null)
            || !is_int($settings['interval_hours'] ?? null)
            || $settings['interval_hours'] < 1 || $settings['interval_hours'] > 168
            || !is_array($settings['devices'] ?? null)
            || !array_is_list($settings['devices']) || count($settings['devices']) > 50) {
            throw new ValidationError(['請確認啟用狀態、1 至 168 小時的備份間隔，以及最多 50 台設備']);
        }
        $clean = [];
        $seen = [];
        foreach ($settings['devices'] as $row) {
            if (!is_array($row) || !is_int($row['device_id'] ?? null)
                || !isset($this->devices[$row['device_id']]) || isset($seen[$row['device_id']])) {
                throw new ValidationError(['請選擇現有設備，且每台設備只能設定一次']);
            }
            $id = $row['device_id'];
            $trusted = $this->devices[$id];
            $seen[$id] = true;
            $backend = $row['backup_backend'] ?? 'oxidized';
            if (!in_array($row['model'] ?? null, ['ios', 'fortios', 'junos', 'routeros'], true)
                || !in_array($backend, ['oxidized', 'fortigate_api'], true)
                || ($backend === 'fortigate_api' && ($row['model'] ?? null) !== 'fortios')
                || ($row['port'] ?? null) !== 22 || !is_bool($row['enabled'] ?? null)) {
                throw new ValidationError(['請選擇支援的設備類型、備份方式、SSH 連接埠 22 與設備啟用狀態']);
            }
            $username = $row['username'] ?? null;
            $password = $row['password'] ?? '';
            $apiToken = $row['api_token'] ?? '';
            if (!is_string($username) || ($backend === 'oxidized' && trim($username) === '') || mb_strlen($username, 'UTF-8') > 128
                || ($username !== '' && preg_match('/[\x00-\x20\x7f]/', $username))
                || !is_string($password) || mb_strlen($password, 'UTF-8') > 512
                || preg_match('/[\x00-\x1f\x7f]/', $password)
                || !is_string($apiToken) || mb_strlen($apiToken, 'UTF-8') > 1024
                || preg_match('/[\x00-\x1f\x7f]/', $apiToken)) {
                throw new ValidationError(['請輸入有效的備份帳號、密碼與 REST API Token；留空表示沿用']);
            }
            $host = (string) $trusted['host'];
            $name = mb_substr((string) $trusted['name'], 0, 128, 'UTF-8');
            if (strlen($host) > 253 || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.:-]*\z/D', $host)) {
                throw new ValidationError(['設備位址不符合備份工具格式，請先確認 LibreNMS 設備資料']);
            }
            // Oxidized CSV 來源會解讀分隔符及 Ruby 插值，不允許這些字元進入任何欄位。
            foreach ([$username, $password, $apiToken, $name] as $value) {
                if (strpos($value, '|') !== false || strpos($value, '#{') !== false
                    || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new ValidationError(['設備名稱、帳號或密碼包含備份工具不支援的字元']);
                }
            }
            $clean[] = [
                'device_id' => $id,
                'name' => $name,
                'host' => $host,
                'model' => $row['model'],
                'username' => $username,
                'password' => $password,
                'api_token' => $apiToken,
                'backup_backend' => $backend,
                'port' => 22,
                'enabled' => $row['enabled'],
            ];
        }
        return $this->request([
            'action' => 'save',
            'actor' => $actor,
            'settings' => [
                'enabled' => $settings['enabled'],
                'interval_hours' => $settings['interval_hours'],
                'devices' => $clean,
            ],
        ]);
    }

    public function test(int $deviceId, string $actor, ?string $level): array
    {
        $this->authorize($level);
        return $this->deviceAction('test', $deviceId, $actor);
    }

    public function run(int $deviceId, string $actor, ?string $level): array
    {
        $this->authorize($level);
        return $this->deviceAction('run', $deviceId, $actor);
    }

    /** 不傳送設備指令，只檢查 SSH transport、PTY 與 shell 的建立狀態。 */
    public function diagnose(int $deviceId, string $actor, ?string $level): array
    {
        $this->authorize($level);
        return $this->deviceAction('diagnose', $deviceId, $actor);
    }

    /** 取得候選值不代表信任；仍須由管理者比對設備端指紋後明確確認。 */
    public function scanHostKey(int $deviceId, string $actor, ?string $level): array
    {
        $this->authorize($level);
        return $this->deviceAction('host_key_scan', $deviceId, $actor);
    }

    public function confirmHostKey(int $deviceId, string $token, string $fingerprint, string $actor, ?string $level): array
    {
        $this->authorize($level);
        $this->actor($actor);
        if (strlen($token) > 128 || strlen($fingerprint) > 128
            || !preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $fingerprint)) {
            throw new ValidationError(['SSH 身分指紋確認資料格式不正確']);
        }
        if (!isset($this->devices[$deviceId])) {
            throw new ValidationError(['請選擇現有設備']);
        }
        return $this->request(['action' => 'host_key_confirm', 'actor' => $actor,
            'device_id' => $deviceId, 'token' => $token, 'fingerprint' => $fingerprint]);
    }

    public function history(int $deviceId, int $offset, ?string $level): array
    {
        $this->authorize($level);
        $this->identifier($deviceId);
        $this->identifier($offset, true);
        return $this->request(['action' => 'history', 'actor' => '', 'device_id' => $deviceId, 'offset' => $offset]);
    }

    public function compare(int $deviceId, int $from, int $to, ?string $level): array
    {
        $this->authorize($level);
        foreach ([$deviceId, $from, $to] as $id) {
            $this->identifier($id);
        }
        return $this->request(['action' => 'compare', 'actor' => '', 'device_id' => $deviceId,
            'from_version_id' => $from, 'to_version_id' => $to]);
    }

    /** 原始組態只供受保護的下載端點串流，絕不混入一般 JSON API 回應。 */
    public function downloadVersion(int $deviceId, int $versionId, string $actor, ?string $level): array
    {
        $this->authorize($level);
        $this->actor($actor);
        $this->identifier($deviceId);
        $this->identifier($versionId);
        $data = $this->rawRequest(['action' => 'download', 'actor' => $actor,
            'device_id' => $deviceId, 'version_id' => $versionId]);
        $download = $data['download'] ?? null;
        if (!is_array($download) || (int) ($download['device_id'] ?? 0) !== $deviceId
            || (int) ($download['version_id'] ?? 0) !== $versionId || !is_string($download['content'] ?? null)
            || strlen($download['content']) > 2 * 1024 * 1024) {
            throw new IocError('備份版本下載資料不完整，請稍後再試');
        }
        return ['content' => $download['content'], 'filename' => 'config-backup-device-' . $deviceId . '-version-' . $versionId . '.conf'];
    }

    private function identifier(int $id, bool $allowZero = false): void
    {
        if ($id < ($allowZero ? 0 : 1) || $id > 2147483647) {
            throw new ValidationError(['識別碼或分頁位置不正確']);
        }
    }

    private function authorize(?string $level): void
    {
        Access::requireHandler($level, '只有管理者可以管理設備組態備份');
    }

    private function actor(string $actor): void
    {
        if ($actor === '' || mb_strlen($actor, 'UTF-8') > 255 || preg_match('/[\x00-\x1f\x7f]/', $actor)) {
            throw new ValidationError(['無法確認操作者，請重新登入']);
        }
    }

    private function deviceAction(string $action, int $id, string $actor): array
    {
        $this->actor($actor);
        if (!isset($this->devices[$id])) {
            throw new ValidationError(['請選擇現有設備']);
        }
        return $this->request(['action' => $action, 'actor' => $actor, 'device_id' => $id]);
    }

    private function request(array $request): array
    {
        return $this->sanitize($this->rawRequest($request));
    }

    private function rawRequest(array $request): array
    {
        try {
            $response = ($this->transport)($request);
        } catch (\Throwable $e) {
            throw new IocError('無法連線至設備備份服務，請稍後再試');
        }
        if (!is_array($response) || ($response['ok'] ?? null) !== true || !is_array($response['data'] ?? null)) {
            $code = is_array($response) ? ($response['error']['code'] ?? '') : '';
            if (!is_string($code)) {
                $code = '';
            }
            $messages = [
                'validation' => '備份設定不完整，請重新確認',
                'password_required' => '首次設定 Oxidized SSH 備份時請輸入備份密碼',
                'api_token_required' => '首次設定 FortiGate REST API 時請輸入 API Token',
                'api_request_failed' => 'FortiGate REST API 備份要求失敗，請確認 Token 權限與 TLS 憑證信任',
                'busy' => '備份工作正在執行，請稍後再試',
                'unavailable' => '備份工具尚未就緒，請聯絡管理者',
                'unsafe_state' => '備份設定儲存位置不安全，請聯絡管理者',
                'invalid_response' => '備份工具回應不完整，請稍後再試',
                'invalid_backup' => '未取得完整設備設定，請確認備份權限',
                'incomplete_backup' => '偵測到設備分頁提示，該版本不完整；請重新備份',
                'host_key_scan_failed' => '無法取得設備的 SSH 身分指紋，請確認設備連線與 SSH 服務',
                'host_key_expired' => 'SSH 身分指紋確認已逾時或設備資料已變更，請重新取得',
                'host_key_mismatch' => 'SSH 身分指紋確認不符，未變更信任設定',
                'host_key_conflict' => '此設備已有不同的 SSH 身分指紋，為安全起見未覆寫',
                'host_key_untrusted' => '尚未核對並信任 SSH 主機身分，請先完成主機金鑰確認',
                'state_error' => '無法讀取備份紀錄，請聯絡管理者',
            ];
            throw new IocError($messages[$code] ?? '設備備份服務無法完成要求，請確認設定與服務狀態');
        }
        return $response['data'];
    }

    /** 後端也採欄位白名單；原始設定、例外及工具訊息絕不回傳瀏覽器。 */
    private function sanitize(array $data): array
    {
        $settings = $data['settings'] ?? null;
        $engine = $data['engine'] ?? null;
        if (!is_array($settings) || !is_bool($settings['enabled'] ?? null)
            || !is_int($settings['interval_hours'] ?? null) || !is_array($settings['devices'] ?? null)
            || !is_array($engine) || !is_array($data['runs'] ?? null) || !is_array($data['changes'] ?? null)) {
            throw new IocError('設備備份服務回傳格式不完整，請稍後再試');
        }
        $rows = [];
        foreach (array_slice($settings['devices'], 0, 50) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'device_id' => (int) ($row['device_id'] ?? 0),
                'name' => $this->text($row['name'] ?? ''),
                'host' => $this->text($row['host'] ?? ''),
                'model' => $this->text($row['model'] ?? ''),
                'username' => $this->text($row['username'] ?? ''),
                'port' => (int) ($row['port'] ?? 22),
                'enabled' => ($row['enabled'] ?? false) === true,
                'password_set' => ($row['password_set'] ?? false) === true,
                'api_token_set' => ($row['api_token_set'] ?? false) === true,
                'backup_backend' => in_array($row['backup_backend'] ?? 'oxidized', ['oxidized', 'fortigate_api'], true) ? ($row['backup_backend'] ?? 'oxidized') : 'oxidized',
                'host_key_trusted' => ($row['host_key_trusted'] ?? false) === true,
            ];
        }
        $state = $engine['state'] ?? 'unavailable';
        $states = [
            'ready' => '備份服務已就緒',
            'unavailable' => '備份工具尚未就緒，請聯絡管理者',
            'pending_apply' => '設定已儲存，等待套用',
        ];
        if (!is_string($state) || !isset($states[$state])) {
            $state = 'unavailable';
        }
        $runs = [];
        $changes = [];
        $statuses = [
            'queued' => '等待備份',
            'success' => '備份成功',
            'unchanged' => '備份成功，設定沒有變更',
            'failed' => '備份失敗，請確認設備連線與權限',
            'timeout' => '備份逾時',
        ];
        foreach (array_slice($data['runs'], 0, 50) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = $row['status'] ?? 'failed';
            if (!is_string($status) || !isset($statuses[$status])) {
                $status = 'failed';
            }
            $kind = $row['kind'] ?? 'manual';
            if (!in_array($kind, ['test', 'manual', 'scheduled'], true)) {
                $kind = 'manual';
            }
            $runs[] = [
                'id' => $this->text($row['id'] ?? ''),
                'device_id' => (int) ($row['device_id'] ?? 0),
                'device_name' => $this->text($row['device_name'] ?? ''),
                'kind' => $kind,
                'status' => $status,
                'requested_at' => $this->text($row['requested_at'] ?? ''),
                'completed_at' => isset($row['completed_at']) ? $this->text($row['completed_at']) : null,
                'message' => $statuses[$status],
                'version_id' => $this->text($row['version_id'] ?? ''),
            ];
        }
        foreach (array_slice($data['changes'], 0, 50) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $changes[] = [
                'id' => $this->text($row['id'] ?? ''),
                'device_id' => (int) ($row['device_id'] ?? 0),
                'device_name' => $this->text($row['device_name'] ?? ''),
                'detected_at' => $this->text($row['detected_at'] ?? ''),
                'previous_version_id' => $this->text($row['previous_version_id'] ?? ''),
                'version_id' => $this->text($row['version_id'] ?? ''),
                'added' => max(0, (int) ($row['added'] ?? 0)),
                'removed' => max(0, (int) ($row['removed'] ?? 0)),
                'diff' => $this->redactDiff($row['diff'] ?? ''),
            ];
        }
        $audit = [];
        $actions = [
            'save' => '儲存備份政策',
            'test' => '要求測試備份',
            'run' => '要求立即備份',
            'scheduled' => '排程備份',
            'host_key_scan' => '取得 SSH 身分指紋',
            'host_key_confirm' => '確認 SSH 身分指紋',
            'diagnose' => '診斷 SSH 連線',
        ];
        foreach (array_slice(is_array($data['audit'] ?? null) ? $data['audit'] : [], 0, 50) as $row) {
            if (!is_array($row) || !is_string($row['action'] ?? null) || !isset($actions[$row['action']])) {
                continue;
            }
            $audit[] = [
                'id' => $this->text($row['id'] ?? ''),
                'occurred_at' => $this->text($row['occurred_at'] ?? ''),
                'actor' => $this->text($row['actor'] ?? ''),
                'action' => $row['action'],
                'detail' => $actions[$row['action']],
            ];
        }
        $result = [
            'settings' => [
                'enabled' => $settings['enabled'],
                'interval_hours' => $settings['interval_hours'],
                'devices' => $rows,
            ],
            'engine' => [
                'state' => $state,
                'message' => $states[$state],
                'backend' => 'oxidized',
                'applied' => $state === 'ready' && ($engine['applied'] ?? false) === true,
            ],
            'runs' => $runs,
            'changes' => $changes,
            'audit' => $audit,
        ];
        if (isset($data['host_key']) && is_array($data['host_key'])) {
            $hostKey = $data['host_key'];
            $fingerprint = $hostKey['fingerprint'] ?? '';
            if (!is_string($fingerprint) || !preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $fingerprint)) {
                throw new IocError('SSH 身分指紋回應格式不正確，請稍後再試');
            }
            if (($hostKey['algorithm'] ?? '') !== 'ssh-ed25519') {
                throw new IocError('SSH 身分指紋演算法不受支援，請重新取得');
            }
            $result['host_key'] = [
                'device_id' => (int) ($hostKey['device_id'] ?? 0),
                'host' => $this->text($hostKey['host'] ?? ''),
                'algorithm' => 'ssh-ed25519',
                'fingerprint' => $fingerprint,
                'trusted' => ($hostKey['trusted'] ?? false) === true,
                'already_trusted' => ($hostKey['already_trusted'] ?? false) === true,
            ];
            if (isset($hostKey['token'])) {
                if (!is_string($hostKey['token']) || !preg_match('/\A[A-Za-z0-9_-]{20,128}\z/D', $hostKey['token'])) {
                    throw new IocError('SSH 身分指紋確認資料格式不正確，請重新取得');
                }
                $result['host_key']['token'] = $hostKey['token'];
                $result['host_key']['expires_at'] = $this->text($hostKey['expires_at'] ?? '');
            }
        }
        if (isset($data['diagnosis']) && is_array($data['diagnosis'])) {
            $diagnosis = $data['diagnosis'];
            $stages = ['complete', 'prompt_not_detected', 'host_key_untrusted', 'authentication_failed', 'timeout',
                'connection_failed', 'pty_rejected', 'pty_timeout', 'shell_rejected', 'shell_timeout', 'shell_failed', 'probe_failed'];
            $stage = $diagnosis['stage'] ?? 'probe_failed';
            if (!is_string($stage) || !in_array($stage, $stages, true)) {
                $stage = 'probe_failed';
            }
            $terminator = $diagnosis['prompt_terminator'] ?? 'unknown';
            if (!is_string($terminator) || !in_array($terminator, ['#', '$', '>', 'unknown'], true)) {
                $terminator = 'unknown';
            }
            $result['diagnosis'] = ['device_id' => (int) ($diagnosis['device_id'] ?? 0),
                'outcome' => ($diagnosis['outcome'] ?? '') === 'success' ? 'success' : 'failed', 'stage' => $stage,
                'transport' => ($diagnosis['transport'] ?? false) === true, 'pty' => ($diagnosis['pty'] ?? false) === true,
                'shell' => ($diagnosis['shell'] ?? false) === true,
                'prompt_detected' => ($diagnosis['prompt_detected'] ?? false) === true,
                'prompt_terminator' => $terminator, 'ha_role_format' => ($diagnosis['ha_role_format'] ?? false) === true];
        }
        $result['history_devices'] = [];
        foreach (is_array($data['history_devices'] ?? null) ? $data['history_devices'] : [] as $row) {
            if (is_array($row)) {
                $result['history_devices'][] = ['device_id' => (int) ($row['device_id'] ?? 0),
                    'name' => $this->text($row['name'] ?? ''), 'configured' => ($row['configured'] ?? false) === true];
            }
        }
        if (isset($data['history']) && is_array($data['history'])) {
            $history = $data['history'];
            // Reuse the existing run whitelist and local status messages.
            $runData = $data;
            unset($runData['history'], $runData['comparison']);
            $runData['runs'] = array_slice(is_array($history['runs'] ?? null) ? $history['runs'] : [], 0, self::HISTORY_PAGE_SIZE);
            $versions = [];
            foreach (array_slice(is_array($history['versions'] ?? null) ? $history['versions'] : [], 0, self::HISTORY_PAGE_SIZE) as $row) {
                if (is_array($row)) {
                    $versions[] = ['id' => (int) ($row['id'] ?? 0), 'device_id' => (int) ($row['device_id'] ?? 0),
                        'created_at' => $this->text($row['created_at'] ?? ''),
                        'digest' => preg_match('/\A[a-f0-9]{64}\z/', (string) ($row['digest'] ?? '')) ? $row['digest'] : '',
                        'complete' => ($row['complete'] ?? true) === true || ($row['complete'] ?? 1) === 1];
                }
            }
            $result['history'] = ['device_id' => (int) ($history['device_id'] ?? 0),
                'offset' => max(0, (int) ($history['offset'] ?? 0)), 'limit' => self::HISTORY_PAGE_SIZE,
                'total_runs' => max(0, (int) ($history['total_runs'] ?? 0)),
                'total_versions' => max(0, (int) ($history['total_versions'] ?? 0)),
                'runs' => $this->sanitize($runData)['runs'], 'versions' => $versions];
        }
        if (isset($data['comparison']) && is_array($data['comparison'])) {
            $comparison = $data['comparison'];
            $result['comparison'] = ['device_id' => (int) ($comparison['device_id'] ?? 0),
                'from_version_id' => (int) ($comparison['from_version_id'] ?? 0),
                'to_version_id' => (int) ($comparison['to_version_id'] ?? 0),
                'from_created_at' => $this->text($comparison['from_created_at'] ?? ''),
                'to_created_at' => $this->text($comparison['to_created_at'] ?? ''),
                'diff' => $this->redactDiff($comparison['diff'] ?? ''),
                'added' => max(0, (int) ($comparison['added'] ?? 0)), 'removed' => max(0, (int) ($comparison['removed'] ?? 0)),
                'changed' => ($comparison['changed'] ?? false) === true, 'truncated' => ($comparison['truncated'] ?? false) === true];
        }
        return $result;
    }

    private function text($value): string
    {
        return is_scalar($value)
            ? mb_strcut(preg_replace('/[\x00-\x1f\x7f]/', ' ', (string) $value), 0, 1024, 'UTF-8')
            : '';
    }

    private function redactDiff($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $value);
        // 工具端已遮罩；此處再次攔截常見秘密行，以免錯誤版本洩漏。
        $value = preg_replace('/-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?(?:-----END [^-]*PRIVATE KEY-----|\z)/', '[私密金鑰已遮罩]', $value);
        $value = preg_replace('/^.*(?:password|passwd|passphrase|\bpsk\b|\bkey\b|secret|community|private.?key|pre.?shared.key|token|credential|auth.*key|privacy.*key).*$/mi', '[敏感設定已遮罩]', $value);
        // 先遮罩整個區塊再截斷，避免長私鑰或跨截斷點的秘密片段外洩。
        return mb_strcut($value, 0, 16000, 'UTF-8');
    }
}
