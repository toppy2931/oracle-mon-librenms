<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 跨來源關聯建議（規格 EVT-005）：把不同來源（防火牆、Windows／Linux 登入）的日誌用同一個來源位址或目的位址串起來，
 * 找出單看某一個來源看不出來、合起來才可疑的情況，並建議建立事件案。
 *
 * - **只給建議，不自動建案**：由管理者在畫面上按「建立事件案」（帶入查詢條件與說明）；沒有任何自動處置。
 * - **規則式，門檻是提案版常數**（下面的 *_MIN），之後依現場調整；不是機器學習，也沒有基線。
 * - 每條規則各自成敗：某條取不到（Graylog 逾時、沒有內部網段設定）只讓那條標「無法取得」與原因，其他照常。
 *   **沒有建議不代表沒事**：來源沒接入、規則沒涵蓋的情況都看不到；畫面要寫出來。
 * - 一律用 OverviewService::demoFilter 排除（或只取）範例資料；位址與帳號都來自日誌欄位（來源位址、帳號），不含原始訊息。
 * - 規則只比較「同一段時間內」有沒有同時出現，**不檢查先後順序**（例如「失敗之後才成功」），建議的說明會寫出這一點。
 */
final class CorrelationService
{
    public const RANGE = 86400;
    public const RANGE_LABEL = '最近 24 小時';
    /** 規則一：同一來源在防火牆被阻擋 ≥ DENY_MIN 次，且登入失敗 ≥ FAIL_MIN 次 */
    public const DENY_MIN = 10;
    public const FAIL_MIN = 3;
    public const FAIL_HIGH = 10;
    /** 規則二：同一來源登入失敗 ≥ MANY_FAIL_MIN 次、涉及 ≥ MANY_USERS_MIN 個不同帳號，同一段時間內還有登入成功 */
    public const MANY_FAIL_MIN = 5;
    public const MANY_USERS_MIN = 3;
    /** 規則三：同一目的位址被 ≥ SOURCES_MIN 個不同的內部來源嘗試連線卻被擋下（≥ SOURCES_HIGH 個為高風險），且被擋次數 ≥ TARGET_DENY_MIN */
    public const SOURCES_MIN = 3;
    public const SOURCES_HIGH = 10;
    public const TARGET_DENY_MIN = 10;
    public const LIMIT = 100;
    public const MAX_SUGGESTIONS = 30;

    public const RULES = [
        'deny_and_login_failed'      => '同一來源既被防火牆阻擋、也有登入失敗',
        'many_accounts_then_success' => '同一來源嘗試多個帳號、同時段有登入成功',
        'many_sources_one_target'    => '同一目的被多個內部來源嘗試連線卻被擋下',
    ];

    private const FAILED = '(event_action:"login_failed" OR event_action:"invalid_user")';

    private LogSource $logs;
    private bool $demo;
    /** 這條規則的第一份查詢是否碰到列數上限（還有沒列進來的來源）；每條規則開始時清掉 */
    private bool $partial = false;
    /** @var string[]|null 內部網段；null＝讀不到（規則三標無法取得，不套預設） */
    private ?array $nets;
    private string $netsWhy;

    /**
     * @param string[]|null $internalNets SettingsService::internalNets()；null＝讀不到
     */
    public function __construct(LogSource $logs, bool $demo = false, ?array $internalNets = null, string $internalNetsWhy = '內部網段的設定讀不到')
    {
        $this->logs = $logs;
        $this->demo = $demo;
        $this->nets = $internalNets;
        $this->netsWhy = $internalNetsWhy;
    }

    /**
     * @return array<string,mixed> ['range_label','rules'=>[代碼=>['label','ok','error?','count']],'items'=>[建議…]]；建議依風險由高到低
     */
    public function build(): array
    {
        $items = [];
        $rules = [];
        foreach (self::RULES as $code => $label) {
            try {
                $this->partial = false;
                $found = $this->run($code);
                // partial：第一份查詢碰到上限（來源太多，只分析了最多的前 LIMIT 個）：「沒有建議」不能當成「沒有可疑」，畫面要寫出來
                $rules[$code] = ['label' => $label, 'ok' => true, 'count' => count($found), 'partial' => $this->partial];
                foreach ($found as $x) {
                    $items[] = $x + ['rule' => $code, 'rule_label' => $label];
                }
            } catch (GraylogUnavailable $e) {
                $rules[$code] = ['label' => $label, 'ok' => false, 'error' => $e->getMessage(), 'count' => 0];
            }
        }
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($items, static fn (array $a, array $b): int => $rank[$a['severity']] <=> $rank[$b['severity']] ?: $b['hits'] <=> $a['hits'] ?: strcmp($a['title'], $b['title']));
        $total = count($items);
        return ['range_label' => self::RANGE_LABEL, 'rules' => $rules, 'items' => array_slice($items, 0, self::MAX_SUGGESTIONS), 'total' => $total];
    }

    /** @return array<int,array<string,mixed>> */
    private function run(string $code): array
    {
        switch ($code) {
            case 'deny_and_login_failed':
                return $this->ruleDenyAndLoginFailed();
            case 'many_accounts_then_success':
                return $this->ruleManyAccountsThenSuccess();
            case 'many_sources_one_target':
                return $this->ruleManySourcesOneTarget();
        }
        throw new \LogicException('不認得的規則：' . $code);
    }

    private function q(string $query): string
    {
        return OverviewService::demoFilter($query, $this->demo);
    }

    private static function quote(string $v): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }

    /**
     * 只看這些來源位址的查詢條件。位址都來自 counts()（已驗證是合法 IP），最多 LIMIT 個。
     *
     * @param string[] $ips
     */
    private static function only(array $ips): string
    {
        return 'src_ip:(' . implode(' OR ', array_map([self::class, 'quote'], array_slice($ips, 0, self::LIMIT))) . ')';
    }

    /** 有效的位址：聚合結果沒有這個欄位（或 Graylog 的「空值」）是 null；不是合法 IP 的也丟掉，不拿去組查詢。 */
    private static function ip($v): ?string
    {
        return is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false ? $v : null;
    }

    /** @return array<string,mixed> */
    private function item(string $title, string $severity, string $summary, int $hits, string $category, string $evidence, ?string $subjectIp, ?string $targetHost): array
    {
        return ['title' => $title, 'severity' => $severity, 'summary' => $summary, 'hits' => $hits, 'category' => $category, 'evidence_query' => $evidence,
                'subject_ip' => $subjectIp, 'target_host' => $targetHost];
    }

    /** @return array<int,array<string,mixed>> */
    private function ruleDenyAndLoginFailed(): array
    {
        // 先取被擋最多的來源（達門檻的才是候選），再「只針對這些候選」查登入失敗：
        // 不能拿兩份各自的前 N 名做交集，不然網際網路掃描來源占滿被擋名單、內部被入侵的主機（只被擋十幾次）就不在名單裡，永遠不報
        $deny = $this->counts($this->q('event_action:"fw_deny"'), ['src_ip'], ['count']);
        $cand = array_keys(array_filter($deny, static fn (array $r): bool => (int) ($r['count'] ?? 0) >= self::DENY_MIN));
        if ($cand === []) {
            return [];
        }
        $fail = $this->counts($this->q(self::FAILED . ' AND ' . self::only($cand)), ['src_ip'], ['count']);
        $out = [];
        foreach ($cand as $ip) {
            $d = (int) ($deny[$ip]['count'] ?? 0);
            $f = (int) (($fail[$ip]['count'] ?? 0));
            if ($f < self::FAIL_MIN) {
                continue;
            }
            $out[] = $this->item($ip . ' 同時被防火牆阻擋與登入失敗', $f >= self::FAIL_HIGH ? 'high' : 'medium',
                $this->rangeText() . '，這個來源位址在防火牆被阻擋 ' . $d . ' 次，同時在主機登入失敗 ' . $f . ' 次。可能是掃描後接著猜密碼，也可能是設定錯誤的服務；請先確認這個位址是哪一台設備。',
                $d + $f, 'suspicious_login',
                'src_ip:' . self::quote($ip) . ' AND (event_action:"fw_deny" OR event_action:"login_failed" OR event_action:"invalid_user")', $ip, null);
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function ruleManyAccountsThenSuccess(): array
    {
        $fail = $this->counts($this->q(self::FAILED), ['src_ip'], ['count', 'card:user_name']);
        $cand = array_keys(array_filter($fail, static fn (array $r): bool => (int) ($r['count'] ?? 0) >= self::MANY_FAIL_MIN && (int) ($r['card_user_name'] ?? 0) >= self::MANY_USERS_MIN));
        if ($cand === []) {
            return [];
        }
        // 成功登入只針對候選來源查（登入成功次數最多的前 N 名多半是正常工作站，攻擊來源的那一兩次成功不會在裡面）
        $ok = $this->counts($this->q('event_action:"login_success" AND ' . self::only($cand)), ['src_ip'], ['count']);
        $out = [];
        foreach ($cand as $ip) {
            $f = (int) ($fail[$ip]['count'] ?? 0);
            $users = (int) ($fail[$ip]['card_user_name'] ?? 0);
            $s = (int) ($ok[$ip]['count'] ?? 0);
            if ($s < 1) {
                continue;
            }
            $out[] = $this->item($ip . ' 嘗試多個帳號後有登入成功', 'high',
                $this->rangeText() . '，這個來源位址登入失敗 ' . $f . ' 次、涉及 ' . $users . ' 個不同帳號，同一段時間內也有 ' . $s . ' 次登入成功。只比較同一段時間內有沒有同時出現，沒有檢查先後順序；請看證據時間軸確認成功是不是發生在失敗之後。',
                $f + $s, 'suspicious_login',
                'src_ip:' . self::quote($ip) . ' AND (event_action:"login_failed" OR event_action:"invalid_user" OR event_action:"login_success")', $ip, null);
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function ruleManySourcesOneTarget(): array
    {
        if ($this->nets === null) {
            throw new GraylogUnavailable($this->netsWhy);      // 沒有內部網段的設定就分不出「內部來源」：標無法取得，不套預設網段
        }
        // 一定要限定 traffic:forward 並排除廣播與探索埠（與內網可視性同一個定義）：FortiGate 自己收發的 traffic:local 也是 deny
        //（送到網段廣播位址、連到防火牆本身），那天天有、沒人要處理，不限定的話這條規則每天都會誤報（monitor-vm 實測）
        $rows = $this->counts($this->q(NetworkVisibilityService::forwardQuery() . ' AND event_action:"fw_deny" AND ' . IpNets::lucene('src_ip', $this->nets)
            . ' AND ' . NetworkVisibilityService::quietPortsQuery()), ['dst_ip'], ['card:src_ip', 'count']);
        $out = [];
        foreach ($rows as $ip => $r) {
            $n = (int) ($r['card_src_ip'] ?? 0);
            $c = (int) ($r['count'] ?? 0);
            if ($n < self::SOURCES_MIN || $c < self::TARGET_DENY_MIN) {
                continue;
            }
            $out[] = $this->item($ip . ' 被 ' . $n . ' 個內部來源嘗試連線但被擋下', $n >= self::SOURCES_HIGH ? 'high' : 'medium',
                $this->rangeText() . '，有 ' . $n . ' 個不同的內部來源嘗試連到 ' . $ip . '，共被防火牆擋下 ' . $c . ' 次。可能是這個目的位址被設定成不該連的服務，也可能是內部多台設備被同一個程式驅動。',
                $c, 'fw_internal_block',
                'dst_ip:' . self::quote($ip) . ' AND event_action:"fw_deny"', null, $ip);   // 內部網段的前綴清單可能很長（查詢條件上限 1000 字），證據只用目的位址
        }
        return $out;
    }

    private function rangeText(): string
    {
        return self::RANGE_LABEL;
    }

    /**
     * 彙整成「分組值 → 該列」的對照；沒有分組值或不是合法 IP 的列丟掉。
     *
     * @param string[] $groupBy
     * @param string[] $metrics
     * @return array<string,array<string,mixed>>
     */
    private function counts(string $query, array $groupBy, array $metrics): array
    {
        $out = [];
        $rows = $this->logs->aggregate($query, self::RANGE, $groupBy, $metrics, self::LIMIT);
        if (count($rows) >= self::LIMIT) {
            $this->partial = true;
        }
        foreach ($rows as $r) {
            $ip = self::ip($r[$groupBy[0]] ?? null);
            if ($ip !== null) {
                $out[$ip] = $r;
            }
        }
        return $out;
    }
}
