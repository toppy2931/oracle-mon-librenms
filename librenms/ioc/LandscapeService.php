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
    private ?int $rangeSeconds;

    /** @param int|null $now 固定「現在」（Unix 時間），測試用 */
    public function __construct(?int $now = null, ?int $rangeSeconds = null)
    {
        if ($rangeSeconds !== null && !isset(NetworkVisibilityService::RANGE_LABELS[$rangeSeconds])) {
            throw new ValidationError(['不支援的統計期間']);
        }
        $this->now = $now ?? time();
        $this->rangeSeconds = $rangeSeconds;
    }

    /**
     * @param Fleet|null $fleet LibreNMS 設備；null＝讀取失敗
     * @param array<string,int>|null $caseSummary CaseService::summary()；null＝讀取失敗
     * @param array<string,int>|null $openBySeverity CaseService::openBySeverity()；null＝讀取失敗
     * @param array<int,array<string,mixed>>|null $latestCases 已經過 CaseView::row 的最新事件案；null＝讀取失敗
     * @param array<int,array{created_at:string,severity:string}>|null $trendRows CaseService::createdWithin()；null＝讀取失敗
     * @param array{ok:bool,data?:mixed,error?:string} $coverage OverviewService 算出來的資料涵蓋區塊
     * @param array{low:int,medium:int}|null $riskThresholds SettingsService::riskThresholds()；null＝讀取失敗（只顯示分數、不判定等級）。
     *        刻意沒有預設值：呼叫端漏傳就會是語法錯誤，不會悄悄套用預設門檻
     * @param string|null $sitesWarning 站點設定讀不到時的原因（Fleet 因此只能用 LibreNMS 位置分站點）；畫面要寫出來，不能默默換結果
     * @return array<string,mixed>
     */
    public function build(?Fleet $fleet, ?array $caseSummary, ?array $openBySeverity, ?array $latestCases, ?array $trendRows, array $coverage, ?array $riskThresholds, ?string $sitesWarning = null): array
    {
        return [
            'kpi'          => $this->kpi($fleet, $caseSummary, $openBySeverity, $sitesWarning),
            'risk'         => $this->risk($fleet, $openBySeverity, $coverage, $riskThresholds),
            'trend'        => $trendRows === null ? $this->fail('事件案資料無法讀取') : ['ok' => true, 'data' => $this->trend($trendRows)],
            'sites'        => $this->sites($fleet, $sitesWarning),
            'ranking'      => $this->ranking($fleet, $sitesWarning),
            'latest_cases' => $latestCases === null ? $this->fail('事件案資料無法讀取') : ['ok' => true, 'data' => $this->latest($latestCases, $fleet)],
        ];
    }

    // ── KPI ─────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function kpi(?Fleet $fleet, ?array $caseSummary, ?array $openBySeverity, ?string $sitesWarning): array
    {
        // $na：沒有值時卡片的大字。預設「無法取得」（讀取失敗）；不是失敗而是「還沒設定」時寫得更貼近原因
        $unknown = static fn (string $key, string $label, string $why, ?string $na = null): array =>
            ['key' => $key, 'label' => $label, 'value' => null, 'state' => 'unknown', 'sub' => $why, 'na' => $na];
        $noFleet = 'LibreNMS 的設備資料無法讀取';

        // 1) 站點數以設定頁自訂的站點為依據（設定了站點，站點就只由設定決定，LibreNMS 位置不再當站點；沒設定時才是位置）：
        //    ＝有設備的站點＋設定了卻還沒有設備歸進去的站點，所以等於設定的站點數。後者沒有健康等級可言，
        //    不在分佈與高風險站點的計算裡，在這張卡片與排行（標「尚無設備」）另外交代
        $empty = $fleet === null ? [] : $fleet->emptyConfiguredSites();
        if ($fleet === null) {
            $sites = $unknown('sites', '站點數', $noFleet);
        } elseif ($fleet->total() === 0 || ($fleet->sites() === [] && $empty === [])) {
            $sites = $unknown('sites', '站點數', self::noSitesReason($fleet) . self::warnSuffix($sitesWarning), '尚未設定');
        } elseif ($fleet->sites() === []) {
            // 設定了站點，但沒有任何設備歸進去（多半是網段填錯）：有站點、沒有健康資料，不能是綠色
            $sites = ['key' => 'sites', 'label' => '站點數', 'value' => count($empty), 'state' => 'warn',
                'sub' => self::noSitesReason($fleet) . self::warnSuffix($sitesWarning)];
        } else {
            $un = $fleet->unlocated();
            $cfg = $fleet->hasConfiguredSites();
            $note = $empty === [] ? '' : sprintf('其中 %d 個還沒有設備歸進去（%s）；', count($empty), implode('、', $empty));
            $sites = ['key' => 'sites', 'label' => '站點數', 'value' => count($fleet->sites()) + count($empty), 'state' => $sitesWarning === null ? 'good' : 'warn',
                'sub' => $note . ($un > 0
                    ? ($cfg ? sprintf('另有 %d 台設備沒有歸屬站點（IP 不在任何站點的網段內）', $un)
                            : sprintf('另有 %d 台設備沒有有效位置（未指定，或是 Unknown 這類預設值）', $un))
                    : ($cfg ? '各設備都已歸屬站點' : '各設備都已指定位置')) . self::warnSuffix($sitesWarning)];
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
            $high = $unknown('high_sites', '高風險站點', $fleet === null ? $noFleet : self::noSitesReason($fleet) . self::warnSuffix($sitesWarning), $fleet === null ? null : '尚未設定');
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
    private function risk(?Fleet $fleet, ?array $openBySeverity, array $coverage, ?array $riskThresholds): array
    {
        $devices = $fleet === null ? null : ['total' => $fleet->total(), 'up' => $fleet->up()];
        $r = RiskScore::compute($devices, $openBySeverity, OverviewService::sourceCounts($coverage), $riskThresholds);
        if ($r['score'] === null) {
            return $this->fail('設備、事件案與資料來源都沒有可用的資料，無法計算');
        }
        if ($riskThresholds === null) {
            // 分數算得出來，但等級要看使用者設的門檻；讀不到就不能判定，也不能拿預設值冒充
            $r['warning'] = '風險等級門檻的設定讀取不到，所以只顯示分數、不判定等級';
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
        if ($this->rangeSeconds !== null) {
            return $this->windowTrend($rows, $this->rangeSeconds);
        }
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

    /** 所選區間分成六個連續時段；以建立時間計數，既有案件狀態不影響趨勢。 */
    private function windowTrend(array $rows, int $range): array
    {
        $bucket = intdiv($range, 6);
        $start = $this->now - $range;
        $labels = [];
        for ($i = 0; $i < 6; $i++) {
            $labels[] = Time::toTaipei(gmdate('Y-m-d H:i:s', $start + $i * $bucket), 'H:i');
        }
        $series = ['high' => array_fill(0, 6, 0), 'medium' => array_fill(0, 6, 0), 'low' => array_fill(0, 6, 0)];
        $total = 0;
        foreach ($rows as $row) {
            $ts = Time::parse($row['created_at']);
            if ($ts === null || $ts < $start || $ts > $this->now || !isset($series[$row['severity']])) {
                continue;
            }
            $index = min(5, intdiv($ts - $start, $bucket));
            $series[$row['severity']][$index]++;
            $total++;
        }
        return ['labels' => $labels, 'series' => $series, 'total' => $total,
            'capped' => count($rows) >= CaseRepo::CREATED_SINCE_LIMIT];
    }

    // ── 站點 ────────────────────────────────────────────────────────────

    /**
     * 沒有任何站點可顯示的原因（站點數、高風險站點、站點分佈、排行四處共用同一句，不會各說各話）：
     * 沒有設備 → 沒納管；有自訂站點 → 網段沒對上；沒有自訂站點 → 只談 LibreNMS 位置，並指向「設定」頁（站點可以在那裡新增）。
     */
    private static function noSitesReason(Fleet $fleet): string
    {
        if ($fleet->total() === 0) {
            return '還沒有納管任何設備';
        }
        return $fleet->hasConfiguredSites()
            ? '已經設定了站點，但沒有任何設備的 IP 落在站點的網段內（請檢查「設定」頁的網段有沒有填對）'
            : 'LibreNMS 的設備都還沒有有效的位置（沒指定，或只有 Unknown 這類 SNMP 預設值；到設備設定 → 位置 指定，或到「設定」頁新增站點與網段）';
    }

    private static function warnSuffix(?string $warning): string
    {
        return $warning === null ? '' : '；' . $warning;
    }

    /** @return array<string,mixed> */
    private function sites(?Fleet $fleet, ?string $sitesWarning): array
    {
        if ($fleet === null) {
            return $this->fail('LibreNMS 的設備資料無法讀取');
        }
        $sites = $fleet->sites();
        if ($sites === []) {
            return $this->fail(self::noSitesReason($fleet) . ($fleet->total() === 0 ? '' : '，沒有站點可顯示') . self::warnSuffix($sitesWarning));
        }
        return ['ok' => true, 'data' => [
            'items'         => $sites,
            'counts'        => $fleet->levelCounts(),
            'unlocated'     => $fleet->unlocated(),
            'has_locations' => count(array_filter($sites, static fn ($s) => $s['lat'] !== null && $s['lng'] !== null)) > 0,
            'configured'    => $fleet->hasConfiguredSites(),
            'empty_sites'   => $fleet->emptyConfiguredSites(),
            'unassigned_locations' => $fleet->unassignedLocations(),
            'warning'       => $sitesWarning,
        ]];
    }

    /** @return array<string,mixed> */
    private function ranking(?Fleet $fleet, ?string $sitesWarning): array
    {
        if ($fleet === null) {
            return $this->fail('LibreNMS 的設備資料無法讀取');
        }
        if ($fleet->total() === 0 || ($fleet->sites() === [] && $fleet->emptyConfiguredSites() === [])) {
            return $this->fail('沒有站點資料：' . self::noSitesReason($fleet) . self::warnSuffix($sitesWarning));
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
