<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 維運總覽的資料。把 Graylog（日誌）、LibreNMS（設備）與事件案的資料整理成畫面要的樣子。
 *
 * 鐵則：資料取不到就明確標示「無法取得」，絕不能變成 0 或「正常」（OPS-006、MON-006、RPT-007）。
 * 每個區塊各自成敗：Graylog 掛了，設備與事件案那幾塊照樣顯示。
 * 區塊的格式是 ['ok'=>true,'data'=>…] 或 ['ok'=>false,'error'=>'中文原因']。
 *
 * 範例／測試資料（ioc_demo=true）預設一律排除；demo 模式只看範例資料，兩者互不混雜（IS-054～056）。
 */
final class OverviewService
{
    public const RANGE        = 86400;   // 最近 24 小時
    public const FRESH_OK     = 600;     // 10 分鐘內有資料 → 正常接收
    public const FRESH_LATE   = 3600;    // 1 小時內 → 延遲；超過 → 中斷
    public const GROUP_LIMIT  = 200;     // 分組查詢的上限；碰到上限要標示「可能不只」
    public const ATTN_HIGH    = 10;      // 同一帳號＋來源的可疑事件次數 ≥ 此值 → 高
    public const ATTN_MEDIUM  = 3;       // ≥ 此值 → 中

    /** 算「可疑」的 event_action（登入失敗、帳號鎖定、高權限操作、權限異動、稽核被動過）。 */
    public const SUSPICIOUS = [
        'login_failed', 'invalid_user', 'account_locked', 'privileged_logon', 'privileged_command',
        'group_member_added', 'audit_log_cleared', 'audit_policy_changed',
    ];

    public const COVERAGE_LABEL = [
        'ok'      => '正常接收',
        'delayed' => '延遲',
        'stale'   => '中斷',
        'none'    => '待接入',
        'unknown' => '無法判斷',
    ];

    private LogSource $logs;
    private bool $demo;
    private int $now;

    public function __construct(LogSource $logs, bool $demo = false, ?int $now = null)
    {
        $this->logs = $logs;
        $this->demo = $demo;
        $this->now  = $now ?? time();
    }

    /**
     * @param array{total:int,up:int,down_list:array<int,array<string,mixed>>}|null $devices LibreNMS 設備；null＝讀取失敗
     * @param array<string,int>|null $cases CaseService::summary() 的結果；null＝讀取失敗
     * @return array<string,mixed>
     */
    public function build(?array $devices, ?array $cases): array
    {
        $coverage   = $this->guard(fn () => $this->coverage());
        $suspicious = $this->guard(fn () => $this->suspicious());

        return [
            'meta' => [
                'generated_at' => Time::toTaipei(gmdate('Y-m-d H:i:s', $this->now)),
                'range_label'  => '最近 24 小時',
                'demo'         => $this->demo,
            ],
            'kpi'      => $this->kpi($devices, $cases, $coverage, $suspicious),
            'coverage' => $coverage,
            'activity' => $this->guard(fn () => $this->activity()),
            'attention' => [
                'accounts'       => $this->guard(fn () => $this->accounts()),
                'denied_sources' => $this->guard(fn () => $this->deniedSources()),
            ],
            'devices' => $devices === null
                ? ['ok' => false, 'error' => '無法讀取 LibreNMS 的設備資料']
                : ['ok' => true, 'data' => ['total' => $devices['total'], 'up' => $devices['up'], 'down_list' => $devices['down_list']]],
        ];
    }

    // ── 各區塊 ──────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function coverage(): array
    {
        $rows = $this->logs->aggregate($this->q('_exists_:log_type'), self::RANGE, ['log_type'], ['count', 'latest:timestamp'], 20);
        $by   = [];
        foreach ($rows as $r) {
            $by[(string) ($r['log_type'] ?? '')] = $r;
        }
        $out = [];
        foreach (Labels::LOG_TYPES as $code => $label) {
            $r = $by[$code] ?? null;
            if ($r === null) {
                $out[] = ['type' => $code, 'label' => $label, 'status' => 'none', 'count' => 0, 'last' => '—', 'age_seconds' => null];
                continue;
            }
            $ts  = Time::parse(isset($r['latest_timestamp']) ? (string) $r['latest_timestamp'] : null);
            $age = $ts === null ? null : max(0, $this->now - $ts);
            if ($age === null) {
                $status = 'unknown';          // 有資料但算不出多久以前：不能當成正常
            } elseif ($age <= self::FRESH_OK) {
                $status = 'ok';
            } elseif ($age <= self::FRESH_LATE) {
                $status = 'delayed';
            } else {
                $status = 'stale';
            }
            $out[] = [
                'type' => $code, 'label' => $label, 'status' => $status, 'count' => (int) ($r['count'] ?? 0),
                'last' => $ts === null ? '—' : Time::toTaipei(gmdate('Y-m-d H:i:s', $ts)), 'age_seconds' => $age,
            ];
        }
        return $out;
    }

    /** @return array{events:int,accounts:int,accounts_capped:bool} */
    private function suspicious(): array
    {
        $q       = $this->q('event_action:(' . implode(' OR ', self::SUSPICIOUS) . ')');
        $byType  = $this->logs->aggregate($q, self::RANGE, ['log_type'], ['count'], 20);
        $events  = 0;
        foreach ($byType as $r) {
            $events += (int) ($r['count'] ?? 0);
        }
        $users = $this->logs->aggregate($q . ' AND _exists_:user_name', self::RANGE, ['user_name'], ['count'], self::GROUP_LIMIT);
        return ['events' => $events, 'accounts' => count($users), 'accounts_capped' => count($users) >= self::GROUP_LIMIT];
    }

    /** @return array<int,array<string,string>> */
    private function activity(): array
    {
        $rows = $this->logs->messages(
            $this->q('_exists_:event_action AND NOT log_type:firewall'),
            self::RANGE,
            ['timestamp', 'user_name', 'event_action', 'src_ip', 'host_name', 'severity', 'log_type'],
            15
        );
        $out = [];
        foreach ($rows as $r) {
            $action = (string) ($r['event_action'] ?? '');
            $sev    = (string) ($r['severity'] ?? '');
            $out[] = [
                'time'        => Time::toTaipei(isset($r['timestamp']) ? (string) $r['timestamp'] : null, 'm-d H:i:s'),
                'user'        => (string) ($r['user_name'] ?? ''),
                'action'      => Labels::action($action),
                'action_code' => $action,
                'src_ip'      => (string) ($r['src_ip'] ?? ''),
                'host'        => (string) ($r['host_name'] ?? ''),
                'type'        => Labels::logType((string) ($r['log_type'] ?? '')),
                'level'       => Labels::severity($sev),
                'level_code'  => $sev,
            ];
        }
        return $out;
    }

    /** @return array{items:array<int,array<string,mixed>>,capped:bool} */
    private function accounts(): array
    {
        $q    = $this->q('event_action:(' . implode(' OR ', self::SUSPICIOUS) . ') AND _exists_:user_name');
        $rows = $this->logs->aggregate($q, self::RANGE, ['user_name', 'src_ip'], ['count'], self::GROUP_LIMIT);
        usort($rows, static fn ($a, $b) => (int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0));
        $items = [];
        foreach (array_slice($rows, 0, 10) as $r) {
            $n = (int) ($r['count'] ?? 0);
            $items[] = [
                'user'   => (string) ($r['user_name'] ?? ''),
                'src_ip' => (string) ($r['src_ip'] ?? ''),
                'count'  => $n,
                'level'  => $n >= self::ATTN_HIGH ? 'high' : ($n >= self::ATTN_MEDIUM ? 'medium' : 'low'),
            ];
        }
        return ['items' => $items, 'capped' => count($rows) >= self::GROUP_LIMIT];
    }

    /**
     * 防火牆阻擋的來源。外部（網際網路）的掃描雜訊量很大，只彙總成一個數字；
     * 內部來源才逐一列出，因為內部主機被擋才代表需要看。廣播與多播（255.255.255.255、224.*、239.*）排除。
     *
     * @return array{internal:array<int,array<string,mixed>>,internal_events:int,external_events:int,capped:bool}
     */
    private function deniedSources(): array
    {
        $q = $this->q('event_action:fw_deny AND _exists_:src_ip AND NOT dst_ip:"255.255.255.255" AND NOT dst_ip:224.* AND NOT dst_ip:239.*');
        $rows = $this->logs->aggregate($q, self::RANGE, ['src_ip'], ['count'], self::GROUP_LIMIT);
        $internal = [];
        $internalEvents = 0;
        $externalEvents = 0;
        foreach ($rows as $r) {
            $ip = (string) ($r['src_ip'] ?? '');
            $n  = (int) ($r['count'] ?? 0);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;                    // 空白或格式不對的來源不算內部也不算外部
            }
            if (self::isInternal($ip)) {
                $internal[] = ['src_ip' => $ip, 'count' => $n];
                $internalEvents += $n;
            } else {
                $externalEvents += $n;
            }
        }
        usort($internal, static fn ($a, $b) => $b['count'] <=> $a['count']);
        return [
            'internal'        => array_slice($internal, 0, 10),
            'internal_events' => $internalEvents,
            'external_events' => $externalEvents,
            'capped'          => count($rows) >= self::GROUP_LIMIT,
        ];
    }

    /**
     * @param array<string,mixed>|null $devices
     * @param array<string,int>|null $cases
     * @param array<string,mixed> $coverage
     * @param array<string,mixed> $suspicious
     * @return array<int,array<string,mixed>>
     */
    private function kpi(?array $devices, ?array $cases, array $coverage, array $suspicious): array
    {
        $unknown = static fn (string $key, string $label, string $why): array =>
            ['key' => $key, 'label' => $label, 'value' => null, 'state' => 'unknown', 'sub' => $why];

        // 1) 整體運作：受監控設備＋「有資料的」日誌來源類型，正常的比例。尚未接入的來源不算在分母
        $present = $okSrc = 0;
        if ($coverage['ok']) {
            foreach ($coverage['data'] as $c) {
                if ($c['status'] !== 'none') {
                    $present++;
                    $okSrc += $c['status'] === 'ok' ? 1 : 0;
                }
            }
        }
        if ($devices === null || !$coverage['ok']) {
            $ops = $unknown('ops', '今日整體運作', '設備或日誌來源的資料無法取得，無法計算');
        } else {
            $items = $devices['total'] + $present;
            $good  = $devices['up'] + $okSrc;
            $pct   = $items > 0 ? (int) round(100 * $good / $items) : null;
            $ops   = $pct === null
                ? $unknown('ops', '今日整體運作', '還沒有納管的設備或日誌來源')
                : ['key' => 'ops', 'label' => '今日整體運作', 'value' => $pct . '%',
                   'state' => $pct >= 95 ? 'good' : ($pct >= 80 ? 'warn' : 'bad'),
                   'sub' => sprintf('%d 項核心項目中 %d 項正常', $items, $good)];
        }

        // 2) 待處理事件
        if ($cases === null) {
            $pending = $unknown('cases', '待處理事件', '事件案資料無法讀取');
        } else {
            $pending = ['key' => 'cases', 'label' => '待處理事件', 'value' => $cases['open'],
                'state' => $cases['open'] === 0 ? 'good' : ($cases['new'] > 0 ? 'bad' : 'warn'),
                'sub' => sprintf('%d 件待確認 · %d 件已確認 · %d 件處理中', $cases['new'], $cases['ack'], $cases['in_progress'])];
        }

        // 3) 可疑登入事件
        if (!$suspicious['ok']) {
            $susp = $unknown('suspicious', '可疑登入事件', $suspicious['error']);
        } else {
            $d = $suspicious['data'];
            $susp = ['key' => 'suspicious', 'label' => '可疑登入事件', 'value' => $d['events'],
                'state' => $d['events'] === 0 ? 'good' : ($d['events'] < 10 ? 'warn' : 'bad'),
                'sub' => sprintf('登入失敗／帳號鎖定／高權限操作 · 涉及 %s%d 個帳號', $d['accounts_capped'] ? '至少 ' : '', $d['accounts'])];
        }

        // 4) 資料來源連線
        if (!$coverage['ok']) {
            $src = $unknown('sources', '資料來源連線', $coverage['error']);
        } else {
            $total = count($coverage['data']);
            $none  = $total - $present;
            $late  = $present - $okSrc;      // 有資料、但不是「正常接收」（延遲、中斷、無法判斷）
            $notes = [];
            if ($late > 0) {
                $notes[] = sprintf('%d 類來源不是正常接收', $late);
            }
            if ($none > 0) {
                $notes[] = sprintf('%d 類來源尚未接入', $none);
            }
            $src = ['key' => 'sources', 'label' => '資料來源連線', 'value' => $okSrc . '/' . $total,
                'state' => $okSrc === $total ? 'good' : ($okSrc === 0 ? 'bad' : 'warn'),
                'sub' => $notes === [] ? '各類來源都有資料' : implode(' · ', $notes)];   // 0/4 旁不能寫「都有資料」
        }

        // 5) 設備運作狀況
        if ($devices === null) {
            $dev = $unknown('devices', '設備運作狀況', '無法讀取 LibreNMS 的設備資料');
        } else {
            $down = $devices['total'] - $devices['up'];
            $dev = ['key' => 'devices', 'label' => '設備運作狀況', 'value' => $devices['up'] . '/' . $devices['total'],
                'state' => $down === 0 ? 'good' : ($down >= max(1, (int) ($devices['total'] * 0.1)) ? 'bad' : 'warn'),
                'sub' => $down === 0 ? '全部正常' : sprintf('%d 台設備狀態異常', $down)];
        }

        return [$ops, $pending, $susp, $src, $dev];
    }

    // ── 工具 ────────────────────────────────────────────────────────────

    /** 加上範例資料的條件：一般模式排除，demo 模式只看範例。 */
    private function q(string $query): string
    {
        return '(' . $query . ') AND ' . ($this->demo ? 'ioc_demo:true' : 'NOT ioc_demo:true');
    }

    /** @return array{ok:bool,data?:mixed,error?:string} */
    private function guard(callable $fn): array
    {
        try {
            return ['ok' => true, 'data' => $fn()];
        } catch (GraylogUnavailable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** 私有位址（RFC1918）、迴路、連結本地都算內部。 */
    public static function isInternal(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
