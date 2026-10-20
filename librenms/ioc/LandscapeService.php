<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 總覽（簡報「地政資訊環境總覽」）的資料：站點、資產、可用率、風險分數、事件趨勢、最新事件。
 * 資料來自 LibreNMS 設備（Fleet）與事件案；Graylog 那一半是 OverviewService 的事。
 *
 * 鐵則同 OverviewService：資料取不到就是 ['ok'=>false,'error'=>…] 或 value=null 的「無法取得」卡片，
 * 絕不能變成 0 或正常。每個區塊各自成敗。
 */
final class LandscapeService
{
    public const TREND_DAYS    = 30;
    public const LATEST_CASES  = 8;
    public const RANKING_LIMIT = 5;

    private int $now;

    /** @param int|null $now 固定「現在」（Unix 時間），測試用 */
    public function __construct(?int $now = null)
    {
        $this->now = $now ?? time();
    }

    /**
     * @param Fleet|null $fleet LibreNMS 設備；null＝讀取失敗
     * @param array<string,int>|null $caseSummary CaseService::summary()；null＝讀取失敗
     * @param array<string,int>|null $openBySeverity CaseService::openBySeverity()；null＝讀取失敗
     * @param array<int,array<string,mixed>>|null $latestCases 已經過 CaseView::row 的最新事件案；null＝讀取失敗
     * @param array<int,array{created_at:string,severity:string}>|null $trendRows CaseService::createdWithin()；null＝讀取失敗
     * @param array{ok:bool,data?:mixed,error?:string} $coverage OverviewService 算出來的資料涵蓋區塊
     * @return array<string,mixed>
     */
    public function build(?Fleet $fleet, ?array $caseSummary, ?array $openBySeverity, ?array $latestCases, ?array $trendRows, array $coverage): array
    {
        return [
            'kpi'          => $this->kpi($fleet, $caseSummary, $openBySeverity),
            'risk'         => $this->risk($fleet, $openBySeverity, $coverage),
            'trend'        => $trendRows === null ? $this->fail('事件案資料無法讀取') : ['ok' => true, 'data' => $this->trend($trendRows)],
            'sites'        => $this->sites($fleet),
            'ranking'      => $this->ranking($fleet),
            'latest_cases' => $latestCases === null ? $this->fail('事件案資料無法讀取') : ['ok' => true, 'data' => $this->latest($latestCases, $fleet)],
        ];
    }

    // ── KPI ─────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function kpi(?Fleet $fleet, ?array $caseSummary, ?array $openBySeverity): array
    {
        // $na：沒有值時卡片的大字。預設「無法取得」（讀取失敗）；不是失敗而是「還沒設定」時寫得更貼近原因
        $unknown = static fn (string $key, string $label, string $why, ?string $na = null): array =>
            ['key' => $key, 'label' => $label, 'value' => null, 'state' => 'unknown', 'sub' => $why, 'na' => $na];
        $noFleet = 'LibreNMS 的設備資料無法讀取';

        // 1) 站點數
        if ($fleet === null) {
            $sites = $unknown('sites', '站點數', $noFleet);
        } elseif ($fleet->sites() === []) {
            $sites = $unknown('sites', '站點數', $fleet->total() === 0
                ? '還沒有納管任何設備'
                : 'LibreNMS 的設備都還沒有有效的位置（沒指定，或只有 Unknown 這類 SNMP 預設值；到設備設定 → 位置 指定）', '尚未設定');
        } else {
            $un = $fleet->unlocated();
            $sites = ['key' => 'sites', 'label' => '站點數', 'value' => count($fleet->sites()), 'state' => 'good',
                'sub' => $un > 0 ? sprintf('另有 %d 台設備沒有有效位置（未指定，或是 Unknown 這類預設值）', $un) : '各設備都已指定位置'];
        }

        // 2) 監控資產數
        if ($fleet === null) {
            $assets = $unknown('assets', '監控資產數', $noFleet);
        } else {
            // 離線台數一定要在畫面上看得到：沒有指定位置的設備不屬於任何站點，站點相關的卡片都看不出它們離線
            $g    = $fleet->byGroup();
            $down = $fleet->total() - $fleet->up();
            $assets = ['key' => 'assets', 'label' => '監控資產數', 'value' => $fleet->total(),
                'state' => $fleet->total() === 0 ? 'warn' : ($down === 0 ? 'good' : ($down >= max(1, (int) ($fleet->total() * 0.1)) ? 'bad' : 'warn')),
                'sub' => $fleet->total() === 0
                    ? '還沒有納管任何設備'
                    : sprintf('%s %d · %s %d · %s %d', Fleet::GROUP_LABEL['server'], $g['server'], Fleet::GROUP_LABEL['network'], $g['network'], Fleet::GROUP_LABEL['other'], $g['other'])
                        . ($down > 0 ? sprintf(' · 離線 %d 台', $down) : '')];
        }

        // 3) 待處理事件
        if ($caseSummary === null || $openBySeverity === null) {
            $pending = $unknown('cases', '待處理事件', '事件案資料無法讀取');
        } else {
            $pending = ['key' => 'cases', 'label' => '待處理事件', 'value' => $caseSummary['open'],
                'state' => $caseSummary['open'] === 0 ? 'good' : ($caseSummary['new'] > 0 ? 'bad' : 'warn'),
                'sub' => sprintf('高風險 %d · 中風險 %d · 低風險 %d', $openBySeverity['high'] ?? 0, $openBySeverity['medium'] ?? 0, $openBySeverity['low'] ?? 0)];
        }

        // 4) 高風險站點（簡報有「較昨日」，要有每日快照才算得出來，目前沒有，所以不寫）
        if ($fleet === null || $fleet->sites() === []) {
            $high = $unknown('high_sites', '高風險站點', $fleet === null ? $noFleet : '尚無站點資料（設備還沒有有效的位置）', $fleet === null ? null : '尚未設定');
        } else {
            $c = $fleet->levelCounts();
            $high = ['key' => 'high_sites', 'label' => '高風險站點', 'value' => $c['severe'],
                'state' => $c['severe'] > 0 ? 'bad' : ($c['moderate'] > 0 || $c['minor'] > 0 ? 'warn' : 'good'),   // 只有輕微異常也不能是綠色
                'sub' => sprintf('中度異常 %d · 輕微異常 %d', $c['moderate'], $c['minor'])];
        }

        // 5) 整體可用率
        $a = $fleet === null ? null : $fleet->availability();
        if ($fleet === null) {
            $avail = $unknown('availability', '整體可用率', $noFleet);
        } elseif ($a['d1'] === null) {
            $avail = $unknown('availability', '整體可用率', 'LibreNMS 沒有可用率資料（設備剛納管或尚未累積）', '尚無資料');
        } else {
            $avail = ['key' => 'availability', 'label' => '整體可用率', 'value' => sprintf('%.2f%%', $a['d1']),
                'state' => $a['d1'] >= 99.0 ? 'good' : ($a['d1'] >= 95.0 ? 'warn' : 'bad'),
                'sub' => ($a['d7'] === null ? '' : sprintf('近 7 日 %.2f%% · ', $a['d7'])) . sprintf('最近 24 小時，%d 台設備平均', $a['n1'])];
        }

        // 6) 核心業務服務：簡報有，但「哪些算核心服務」要由使用者定義，現在沒有清單
        $core = $unknown('core_services', '核心業務服務', '尚未定義核心服務清單，無法計算', '尚未定義');

        return [$sites, $assets, $pending, $high, $avail, $core];
    }

    // ── 風險儀表 ────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function risk(?Fleet $fleet, ?array $openBySeverity, array $coverage): array
    {
        $devices = $fleet === null ? null : ['total' => $fleet->total(), 'up' => $fleet->up()];
        $r = RiskScore::compute($devices, $openBySeverity, OverviewService::sourceCounts($coverage));
        if ($r['score'] === null) {
            return $this->fail('設備、事件案與資料來源都沒有可用的資料，無法計算');
        }
        return ['ok' => true, 'data' => $r];
    }

    // ── 趨勢 ────────────────────────────────────────────────────────────

    /**
     * 近 30 日新建事件案數，依嚴重性分三條線（日期以台北時間分）。
     *
     * @param array<int,array{created_at:string,severity:string}> $rows
     * @return array{days:string[],series:array<string,int[]>,total:int,capped:bool}
     */
    private function trend(array $rows): array
    {
        $tz   = new \DateTimeZone('Asia/Taipei');
        $days = [];
        $idx  = [];
        for ($i = self::TREND_DAYS - 1; $i >= 0; $i--) {
            $d = (new \DateTimeImmutable('@' . $this->now))->setTimezone($tz)->modify("-{$i} day")->format('Y-m-d');
            $idx[$d] = count($days);
            $days[]  = $d;
        }
        $series = ['high' => array_fill(0, self::TREND_DAYS, 0), 'medium' => array_fill(0, self::TREND_DAYS, 0), 'low' => array_fill(0, self::TREND_DAYS, 0)];
        $total  = 0;
        foreach ($rows as $r) {
            $ts = Time::parse($r['created_at']);
            if ($ts === null || !isset($series[$r['severity']])) {
                continue;
            }
            $day = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
            if (!isset($idx[$day])) {
                continue;                 // 多抓的那一天，不在顯示範圍內
            }
            $series[$r['severity']][$idx[$day]]++;
            $total++;
        }
        // 資料層有筆數上限（保留最新的）。碰到上限就標示：較早的日期可能少算，不能默默當成「那幾天比較少」
        return ['days' => $days, 'series' => $series, 'total' => $total, 'capped' => count($rows) >= CaseRepo::CREATED_SINCE_LIMIT];
    }

    // ── 站點 ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function sites(?Fleet $fleet): array
    {
        if ($fleet === null) {
            return $this->fail('LibreNMS 的設備資料無法讀取');
        }
        $sites = $fleet->sites();
        if ($sites === []) {
            return $this->fail($fleet->total() === 0 ? '還沒有納管任何設備' : 'LibreNMS 的設備都還沒有有效的位置（沒指定，或只有 Unknown 這類預設值），沒有站點可顯示');
        }
        return ['ok' => true, 'data' => [
            'items'         => $sites,
            'counts'        => $fleet->levelCounts(),
            'unlocated'     => $fleet->unlocated(),
            'has_locations' => count(array_filter($sites, static fn ($s) => $s['lat'] !== null && $s['lng'] !== null)) > 0,
        ]];
    }

    /** @return array<string,mixed> */
    private function ranking(?Fleet $fleet): array
    {
        if ($fleet === null) {
            return $this->fail('LibreNMS 的設備資料無法讀取');
        }
        if ($fleet->sites() === []) {
            return $this->fail('沒有站點資料（設備還沒有有效的位置）');
        }
        return ['ok' => true, 'data' => $fleet->ranking(self::RANKING_LIMIT)];
    }

    // ── 最新事件 ────────────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $cases CaseView::row 的結果
     * @return array<int,array<string,string>>
     */
    private function latest(array $cases, ?Fleet $fleet): array
    {
        $out = [];
        foreach (array_slice($cases, 0, self::LATEST_CASES) as $c) {
            $site = $fleet === null ? null : $fleet->siteOfHost((string) ($c['subject_host'] ?? ''));
            $out[] = [
                'id'             => (string) $c['id'],
                'time'           => (string) $c['created_at'],
                'title'          => (string) $c['title'],
                'site'           => $site ?? '',
                'severity'       => (string) $c['severity'],
                'severity_label' => (string) $c['severity_label'],
                'status'         => (string) $c['status'],
                'status_label'   => (string) $c['status_label'],
                'assignee'       => (string) $c['assignee'],
            ];
        }
        return $out;
    }

    /** @return array{ok:false,error:string} */
    private function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why];
    }
}
