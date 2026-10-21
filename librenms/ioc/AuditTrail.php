<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 稽核軌跡（簡報「遠端維運稽核軌跡」與「治理行動時間軸」）：把幾個來源的「誰在什麼時候做了什麼」合成一張時間表。
 *
 *   login     LibreNMS 的登入、登出與登入失敗（authlog；有來源位址）
 *   admin_op  管理主控台的操作（/var/log/oracle-admin.log；有來源位址）
 *   case      事件案的建立、狀態變更與留言（ioc_case_history；沒有來源位址）
 *   settings  入口設定的異動（ioc_settings_history；沒有來源位址）
 *
 * 時間：authlog 與 oracle-admin.log 是伺服器本地時間，ioc_* 資料表是 UTC；各自轉成明確的時間點再排序，顯示才轉台北時間（INT-072）。
 * 鐵則：某個來源讀不到，就在 sources 標出「無法取得」與原因，其他來源照常列出；不能讓那個來源的事件默默消失，
 * 讓人以為那段時間沒有人做過事（RPT-007）。
 *
 * 只有 admin 能用：這張表列出所有人的登入位址與失敗嘗試（IS-013）。
 */
final class AuditTrail
{
    public const CATEGORY_LABEL = [
        'login'    => 'LibreNMS 登入',
        'admin_op' => '管理操作',
        'case'     => '事件案處理',
        'settings' => '設定異動',
    ];
    public const RESULT_LABEL = ['ok' => '成功', 'fail' => '失敗', 'info' => '—'];
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT     = 200;

    /** oracle-admin.log 的動作代碼 → 中文。NAS_／FW_／JTIPAM_ 後面接的是動作名稱，另外處理。 */
    private const ADMIN_ACTION = [
        'SAVE'        => '儲存 Oracle 連線設定',
        'ADD_DB'      => '新增資料庫',
        'TOGGLE'      => '啟用／停用資料庫',
        'DELETE'      => '刪除資料庫',
        'DELETE_FAIL' => '刪除資料庫失敗',
        'CUSTOM_MAP'  => 'Custom Map 刷新秒數',
        'IP_UPDATE'   => '變更監控主機 IP',
        'IP_NETPLAN'  => '寫入主機網路設定',
        'IP_SCAN'     => '掃描舊 IP 殘留',
        'DASH_LAYOUT' => '戰情室版面',
        'APPLY'       => '套用管理操作（系統）',
    ];
    private const ADMIN_PREFIX = ['NAS_' => 'NAS 備份：', 'FW_' => '防火牆管理網段：', 'JTIPAM_' => 'jt-ipam IP：'];
    /** 只把這些欄位帶進畫面：其他（例如失敗時的指令輸出）是任意文字，不放。 */
    private const ADMIN_DETAIL_KEYS = ['alias', 'host', 'action', 'cidr', 'rule', 'new_ip', 'old_ip', 'ip', 'gw', 'enabled'];
    private const DETAIL_MAX = 120;

    private string $tz;

    /** @param string|null $tz authlog 與 oracle-admin.log 這種沒帶時區的時間屬於哪個時區（預設 PHP 的預設時區） */
    public function __construct(?string $tz = null)
    {
        $this->tz = $tz ?? date_default_timezone_get();
    }

    /**
     * @param array{login:?array,admin_op:?array,case:?array,settings:?array} $sources 各來源的原始資料；null＝讀不到
     *        login：authlog 的列（datetime、user、address、result）；admin_op：oracle-admin.log 的行（字串）；
     *        case：ioc_case_history 的列；settings：ioc_settings_history 的列
     * @param array{category?:string,result?:string,limit?:int} $filter
     * @return array<string,mixed>
     */
    public function build(array $sources, array $filter, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以看身分與稽核');
        $category = (string) ($filter['category'] ?? '');
        $result   = (string) ($filter['result'] ?? '');
        if (($category !== '' && !isset(self::CATEGORY_LABEL[$category])) || ($result !== '' && !isset(self::RESULT_LABEL[$result]))) {
            throw new ValidationError(['篩選條件不正確']);
        }
        $limit = max(1, min(self::MAX_LIMIT, (int) ($filter['limit'] ?? self::DEFAULT_LIMIT)));

        $parsers = [
            'login'    => fn (array $rows) => $this->fromAuthlog($rows),
            'admin_op' => fn (array $rows) => $this->fromAdminLog($rows),
            'case'     => fn (array $rows) => $this->fromCaseHistory($rows),
            'settings' => fn (array $rows) => $this->fromSettingsHistory($rows),
        ];
        $events = [];
        $status = [];
        foreach ($parsers as $key => $parse) {
            $raw = $sources[$key] ?? null;
            if ($raw === null) {
                $status[$key] = ['label' => self::CATEGORY_LABEL[$key], 'ok' => false, 'error' => $this->readError($key), 'count' => 0, 'oldest' => null];
                continue;
            }
            $parsed = $parse($raw);
            $oldest = null;
            foreach ($parsed as $e) {
                $oldest = $oldest === null ? $e['ts'] : min($oldest, $e['ts']);
            }
            $status[$key] = ['label' => self::CATEGORY_LABEL[$key], 'ok' => true, 'count' => count($parsed), 'oldest' => $oldest === null ? null : Time::toTaipei(gmdate('Y-m-d H:i:s', $oldest))];
            $events = array_merge($events, $parsed);
        }

        $events = array_values(array_filter($events, static fn ($e) => ($category === '' || $e['category'] === $category) && ($result === '' || $e['result'] === $result)));
        usort($events, static fn ($a, $b) => [$b['ts'], $a['actor']] <=> [$a['ts'], $b['actor']]);
        $total = count($events);
        $items = [];
        foreach (array_slice($events, 0, $limit) as $e) {
            $items[] = [
                'time'           => Time::toTaipei(gmdate('Y-m-d H:i:s', $e['ts'])),
                'actor'          => $e['actor'],
                'ip'             => $e['ip'],
                'category'       => $e['category'],
                'category_label' => self::CATEGORY_LABEL[$e['category']],
                'action'         => $e['action'],
                'result'         => $e['result'],
                'result_label'   => self::RESULT_LABEL[$e['result']],
                'detail'         => $e['detail'],
            ];
        }
        return ['items' => $items, 'total' => $total, 'truncated' => $total > $limit, 'sources' => $status];
    }

    private function readError(string $key): string
    {
        return $key === 'admin_op' ? '讀不到管理操作紀錄檔（/var/log/oracle-admin.log）' : '資料無法讀取';
    }

    // ── 各來源轉成統一的事件 ────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $rows authlog 的列
     * @return array<int,array<string,mixed>>
     */
    public function fromAuthlog(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ts = Time::parse(isset($r['datetime']) ? (string) $r['datetime'] : null, $this->tz);
            if ($ts === null) {
                continue;                 // 時間壞掉的列沒辦法排進時間表，也不能亂放一個時間
            }
            $raw = trim((string) ($r['result'] ?? ''));
            if (stripos($raw, 'logged in') !== false) {
                [$action, $result, $detail] = ['登入', 'ok', ''];
            } elseif (stripos($raw, 'logged out') !== false) {
                [$action, $result, $detail] = ['登出', 'ok', ''];
            } else {
                [$action, $result, $detail] = ['登入失敗', 'fail', $this->cut($raw)];
            }
            $out[] = $this->event($ts, (string) ($r['user'] ?? ''), (string) ($r['address'] ?? ''), 'login', $action, $result, $detail);
        }
        return $out;
    }

    /**
     * @param string[] $lines oracle-admin.log 的行
     * @return array<int,array<string,mixed>>
     */
    public function fromAdminLog(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([A-Za-z0-9_]+)\]\s*(.*)$/u', (string) $line, $m)) {
                continue;
            }
            $ts = Time::parse($m[1], $this->tz);
            if ($ts === null) {
                continue;
            }
            $code = $m[2];
            preg_match_all('/(?<![\w])(\w+)=(\S*)/u', $m[3], $kv);
            $pairs = array_combine($kv[1], $kv[2]) ?: [];

            $action = self::ADMIN_ACTION[$code] ?? null;
            if ($action === null) {
                $action = $code;
                foreach (self::ADMIN_PREFIX as $prefix => $label) {
                    if (strpos($code, $prefix) === 0) {
                        $action = $label . substr($code, strlen($prefix));
                    }
                }
            }
            if ($code === 'APPLY') {
                $detail = $this->cut(trim($m[3]));
                $result = 'info';
            } else {
                $detail = [];
                foreach (self::ADMIN_DETAIL_KEYS as $key) {
                    if (isset($pairs[$key]) && $pairs[$key] !== '') {
                        $detail[] = $key . '=' . $pairs[$key];
                    }
                }
                $detail = $this->cut(implode(' · ', $detail));
                $result = $code === 'DELETE_FAIL' ? 'fail' : (isset($pairs['rc']) ? ($pairs['rc'] === '0' ? 'ok' : 'fail') : 'info');
            }
            $out[] = $this->event($ts, $code === 'APPLY' ? '系統（套用器）' : ($pairs['user'] ?? ''), $pairs['from'] ?? '', 'admin_op', $action, $result, $detail);
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows ioc_case_history 的列（時間是 UTC）
     * @return array<int,array<string,mixed>>
     */
    public function fromCaseHistory(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ts = Time::parse(isset($r['ts']) ? (string) $r['ts'] : null);
            if ($ts === null) {
                continue;
            }
            $n = '事件案 #' . (int) ($r['case_id'] ?? 0);
            switch ((string) ($r['action'] ?? '')) {
                case 'created':
                    $action = '建立' . $n;
                    $detail = '';
                    break;
                case 'status_changed':
                    $action = '變更' . $n . '的狀態';
                    $detail = Labels::caseStatus((string) ($r['from_status'] ?? '')) . ' → ' . Labels::caseStatus((string) ($r['to_status'] ?? ''))
                        . (($r['resolution'] ?? null) ? '（' . (Labels::RESOLUTION[(string) $r['resolution']] ?? (string) $r['resolution']) . '）' : '');
                    break;
                case 'comment':
                    $action = '在' . $n . '留言';
                    $detail = '';
                    break;
                default:
                    $action = (string) ($r['action'] ?? '') . '（' . $n . '）';
                    $detail = '';
            }
            $out[] = $this->event($ts, (string) ($r['actor'] ?? ''), '', 'case', $action, 'ok', $detail);
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows ioc_settings_history 的列（時間是 UTC）
     * @return array<int,array<string,mixed>>
     */
    public function fromSettingsHistory(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ts = Time::parse(isset($r['ts']) ? (string) $r['ts'] : null);
            if ($ts === null) {
                continue;
            }
            $label = SettingsService::KEY_LABEL[(string) ($r['skey'] ?? '')] ?? (string) ($r['skey'] ?? '');
            $out[] = $this->event($ts, (string) ($r['actor'] ?? ''), '', 'settings', '修改設定：' . $label,
                'ok', $this->cut((string) ($r['old_value'] ?? '') . ' → ' . (string) ($r['new_value'] ?? '')));
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function event(int $ts, string $actor, string $ip, string $category, string $action, string $result, string $detail): array
    {
        return ['ts' => $ts, 'actor' => $actor, 'ip' => $ip, 'category' => $category, 'action' => $action, 'result' => $result, 'detail' => $detail];
    }

    private function cut(string $s): string
    {
        return mb_strlen($s) > self::DETAIL_MAX ? mb_substr($s, 0, self::DETAIL_MAX) . '…' : $s;
    }
}
