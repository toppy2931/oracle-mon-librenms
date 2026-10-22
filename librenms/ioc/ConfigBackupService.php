<?php
declare(strict_types=1);

namespace Ioc;

/** 管理者組態備份領域邊界；設備位址一律取自可信 LibreNMS 目錄。 */
final class ConfigBackupService
{
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
            if (!in_array($row['model'] ?? null, ['ios', 'fortios', 'junos', 'routeros'], true)
                || ($row['port'] ?? null) !== 22 || !is_bool($row['enabled'] ?? null)) {
                throw new ValidationError(['請選擇支援的設備類型、SSH 連接埠 22 與設備啟用狀態']);
            }
            $username = $row['username'] ?? null;
            $password = $row['password'] ?? '';
            if (!is_string($username) || trim($username) === '' || mb_strlen($username, 'UTF-8') > 128
                || preg_match('/[\x00-\x20\x7f]/', $username)
                || !is_string($password) || mb_strlen($password, 'UTF-8') > 512
                || preg_match('/[\x00-\x1f\x7f]/', $password)) {
                throw new ValidationError(['請輸入有效的備份帳號與密碼；密碼留空表示沿用']);
            }
            $host = (string) $trusted['host'];
            $name = mb_substr((string) $trusted['name'], 0, 128, 'UTF-8');
            if (strlen($host) > 253 || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.:-]*\z/D', $host)) {
                throw new ValidationError(['設備位址不符合備份工具格式，請先確認 LibreNMS 設備資料']);
            }
            // Oxidized CSV 來源會解讀分隔符及 Ruby 插值，不允許這些字元進入任何欄位。
            foreach ([$username, $password, $name] as $value) {
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
                'password_required' => '首次設定設備時請輸入備份密碼',
                'busy' => '備份工作正在執行，請稍後再試',
                'unavailable' => '備份工具尚未就緒，請聯絡管理者',
                'unsafe_state' => '備份設定儲存位置不安全，請聯絡管理者',
                'invalid_response' => '備份工具回應不完整，請稍後再試',
                'invalid_backup' => '未取得完整設備設定，請確認備份權限',
                'state_error' => '無法讀取備份紀錄，請聯絡管理者',
            ];
            throw new IocError($messages[$code] ?? '設備備份服務無法完成要求，請確認設定與服務狀態');
        }
        return $this->sanitize($response['data']);
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
        return [
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
