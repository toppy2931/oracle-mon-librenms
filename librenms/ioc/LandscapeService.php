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
    /**
     * 事件趨勢自己的視窗（秒）→ 標籤。簡報畫的是「近 30 日」，所以預設 30 日；
     * 與 Graylog 區塊用的統計期間（NetworkVisibilityService::RANGE_LABELS，最長 24 小時）各自獨立：
     * 趨勢本身只讀事件案資料表，不打 Graylog，選 7／30 日不會讓單次載入多打 Graylog 查詢。
     * 但總覽快取（ioc-data.php）是整份結果、鍵含趨勢視窗，所以每個（統計區間 × 趨勢視窗）組合各有一份 20 秒快取：
     * 只切換趨勢下拉選單，快取沒命中時也會重跑那一輪 Graylog 查詢（最壞是三種視窗各一次）。
     */
    public const TREND_OPTIONS = [86400 => '24 小時', 604800 => '7 日', 2592000 => '30 日'];
    public const TREND_DEFAULT = 2592000;
    public const LATEST_CASES  = 8;
    public const RANKING_LIMIT = 5;
    /** 還沒設定服務水準（規格沒有預設值）：目標、服務時段是 null、核心設備是空的。build() 的預設值；明確傳 null 才是「設定讀不到」。 */
    public const NO_SLA        = ['target' => null, 'window' => null, 'core' => []];

    private int $now;
    private ?int $trendSeconds;

    /**
     * @param int|null $now 固定「現在」（Unix 時間），測試用
     * @param int|null $trendSeconds 趨勢視窗；null＝預設（30 日）。只收 TREND_OPTIONS，
     *        或舊的短期間（30 分鐘～24 小時，同 NetworkVisibilityService::RANGE_LABELS）；其他一律拒絕
     */
    public function __construct(?int $now = null, ?int $trendSeconds = null)
    {
        if ($trendSeconds !== null && !isset(self::TREND_OPTIONS[$trendSeconds]) && !isset(NetworkVisibilityService::RANGE_LABELS[$trendSeconds])) {
            throw new ValidationError(['不支援的趨勢期間']);
        }
        $this->now = $now ?? time();
        $this->trendSeconds = $trendSeconds;
    }

    /**
     * @param Fleet|null $fleet LibreNMS 設備；null＝讀取失敗
     * @param array<string,int>|null $caseSummary CaseService::summary()；null＝讀取失敗
     * @param array<string,int>|null $openBySeverity CaseService::openBySeverity()；null＝讀取失敗
     * @param array<int,array<string,mixed>>|null $latestCases 已經過 CaseView::row 的最新事件案；null＝讀取失敗
     * @param array<int,array{created_at:string,severity:string}>|null $trendRows CaseService::createdWithin()；null＝讀取失敗
     * @param array{ok:bool,data?:mixed,error?:string} $coverage OverviewService 算出來的資料涵蓋區塊
     * @param array{low:int,medium:int}|null $riskThresholds SettingsService::overviewSettings() 的 thresholds；null＝讀取失敗（只顯示分數、不判定等級）。
     *        刻意沒有預設值：呼叫端漏傳就會是語法錯誤，不會悄悄套用預設門檻
     * @param string|null $sitesWarning 站點設定讀不到時的原因（Fleet 因此只能用 LibreNMS 位置分站點）；畫面要寫出來，不能默默換結果
     * @param array{target:?float,window:?string,core:int[]}|null $serviceLevel SettingsService::overviewSettings() 的 sla；預設 NO_SLA＝尚未設定；
     *        明確傳 null＝設定讀不到（核心業務服務與 SLA 達成率標「無法取得」，不套預設）
     * @param string|null $slaWarning 服務水準設定讀不到的原因
     * @param array{today:int,yesterday:int}|null $dayCounts dayCounts() 的結果；null＝讀不到或不可靠，就不寫
     * @param array<string,mixed>|null $audit AuditEvidenceService::summary() 的結果（只有計數，檢視者也能看）；null＝讀不到（合規覆蓋率標「無法取得」）
     * @return array<string,mixed> kpi＝第一排六張（簡報上排）、kpi2＝第二排（SLA 達成率、網路可用性、合規覆蓋率）
     */
    public function build(?Fleet $fleet, ?array $caseSummary, ?array $openBySeverity, ?array $latestCases, ?array $trendRows, array $coverage, ?array $riskThresholds, ?string $sitesWarning = null,
                          ?array $serviceLevel = self::NO_SLA, ?string $slaWarning = null, ?array $dayCounts = null, ?array $audit = null): array
    {
        return [
            'kpi'          => $this->kpi($fleet, $caseSummary, $openBySeverity, $sitesWarning, $serviceLevel, $slaWarning, $dayCounts),
            'kpi2'         => $this->kpi2($fleet, $serviceLevel, $slaWarning, $audit),
            'risk'         => $this->risk($fleet, $openBySeverity, $coverage, $riskThresholds),
            'trend'        => $trendRows === null ? $this->fail('事件案資料無法讀取') : ['ok' => true, 'data' => $this->trend($trendRows)],
            'sites'        => $this->sites($fleet, $sitesWarning),
            'ranking'      => $this->ranking($fleet, $sitesWarning),
            'latest_cases' => $latestCases === null ? $this->fail('事件案資料無法讀取') : ['ok' => true, 'data' => $this->latest($latestCases, $fleet)],
        ];
    }

    // ── KPI ─────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function kpi(?Fleet $fleet, ?array $caseSummary, ?array $openBySeverity, ?string $sitesWarning, ?array $serviceLevel, ?string $slaWarning, ?array $dayCounts): array
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
            $un = $fleet->unassigned();
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
                'sub' => sprintf('高風險 %d · 中風險 %d · 低風險 %d', $openBySeverity['high'] ?? 0, $openBySeverity['medium'] ?? 0, $openBySeverity['low'] ?? 0)
                    // 簡報的「較昨日」要每日快照才算得準；事件案有建立時間，今日與昨日新建幾件可以直接算
                    . ($dayCounts === null ? '' : sprintf('；今日新建 %d 件（昨日 %d 件）', $dayCounts['today'], $dayCounts['yesterday']))];
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

        // 6) 核心業務服務：規格沒有核心服務的定義，由管理者在設定頁選核心設備；沒選就是「尚未定義」
        $core = $this->coreCard($fleet, $serviceLevel, $slaWarning);

        return [$sites, $assets, $pending, $high, $avail, $core];
    }

    /**
     * 第二排 KPI（簡報的 SLA 達成率、網路可用性）。只放檢視者也能看的聚合數字：總覽快取不分角色，
     * 管理者專屬的資料（例如組態備份狀態）不能放在這裡。
     *
     * @param array{target:?float,window:?string,core:int[]}|null $serviceLevel
     * @return array<int,array<string,mixed>>
     */
    private function kpi2(?Fleet $fleet, ?array $serviceLevel, ?string $slaWarning, ?array $audit): array
    {
        return [$this->slaCard($fleet, $serviceLevel, $slaWarning), $this->networkCard($fleet), $this->complianceCard($audit)];
    }

    /**
     * 合規覆蓋率：目前評估週期裡，已評估（符合、缺口或不適用）的控制項占全部的比例。這是「評估到哪裡」，不是「符合了多少」，
     * 符合與缺口的件數寫在說明裡。尚未評估完（或還有缺口）不是綠色；比例無條件捨去到小數一位，免得差一項卻顯示 100%。
     * 還沒匯入評估基準或沒有評估週期是「尚未建立」、基準裡沒有控制項是「無控制項」，都不是 0%；稽核資料讀不到才是「無法取得」。
     *
     * @param array<string,mixed>|null $audit AuditEvidenceService::summary() 的結果
     */
    private function complianceCard(?array $audit): array
    {
        $u = static fn (string $why, ?string $na = null): array => self::unknownCard('compliance', '合規覆蓋率', $why, $na);
        if ($audit === null) {
            return $u('稽核資料讀不到');
        }
        if (empty($audit['ready'])) {
            return $u(((string) ($audit['message'] ?? '尚未建立評估基準')) . '（到「稽核與證據」頁匯入評估基準、建立評估週期）', '尚未建立');
        }
        $c = $audit['counts'];
        if ((int) $c['total'] === 0) {
            return $u('評估基準裡沒有任何控制項（到「稽核與證據」頁匯入）', '無控制項');
        }
        $total = (int) $c['total'];
        $assessed = (int) $c['assessed'];
        $overdue = (int) ($c['overdue'] ?? 0);
        return ['key' => 'compliance', 'label' => '合規覆蓋率', 'value' => sprintf('%.1f%%', intdiv($assessed * 1000, $total) / 10),
            'state' => $overdue > 0 ? 'bad' : (((int) $c['gap'] > 0 || (int) $c['unreviewed'] > 0) ? 'warn' : 'good'),
            'sub' => sprintf('週期「%s」：已評估 %d／%d 個控制項 · 符合 %d · 缺口 %d · 不適用 %d · 未評估 %d', (string) ($audit['cycle']['title'] ?? ''),
                    $assessed, $total, (int) $c['compliant'], (int) $c['gap'], (int) $c['not_applicable'], (int) $c['unreviewed'])
                . ($overdue > 0 ? sprintf('；改善逾期 %d 件', $overdue) : '')];
    }

    /** @return array<string,mixed> 沒有值的卡片：$na 是大字（預設「無法取得」＝讀取失敗；不是失敗而是還沒設定時寫得更貼近原因） */
    private static function unknownCard(string $key, string $label, string $why, ?string $na = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => null, 'state' => 'unknown', 'sub' => $why, 'na' => $na];
    }

    /** 核心設備清單（設定頁）目前正常幾台：離線的列名、LibreNMS 裡找不到的（被刪除或停用）另外交代，不能算成正常。 */
    private function coreCard(?Fleet $fleet, ?array $sla, ?string $slaWarning): array
    {
        $u = static fn (string $why, ?string $na = null): array => self::unknownCard('core_services', '核心業務服務', $why, $na);
        if ($sla === null) {
            return $u($slaWarning ?? '服務水準的設定讀不到');
        }
        if ($sla['core'] === []) {
            return $u('尚未定義核心設備清單（規格沒有預設的核心服務，到「設定」頁的「服務水準」選擇）', '尚未定義');
        }
        if ($fleet === null) {
            return $u('LibreNMS 的設備資料無法讀取');
        }
        $c = $fleet->core($sla['core']);
        if ($c['found'] === 0) {
            return $u(sprintf('清單裡的 %d 台設備都不在 LibreNMS（已刪除或停用），請到「設定」頁更新核心設備清單', $c['listed']), '設備不在 LibreNMS');
        }
        $sub = sprintf('核心設備目前正常 %d 台、共 %d 台', $c['up'], $c['listed'])
            . ($c['down'] > 0 ? '；離線：' . implode('、', $c['down_names']) . ($c['down'] > count($c['down_names']) ? sprintf('等 %d 台', $c['down']) : '') : '')
            . ($c['missing'] > 0 ? sprintf('；另有 %d 台不在 LibreNMS（已刪除或停用）', $c['missing']) : '');
        return ['key' => 'core_services', 'label' => '核心業務服務', 'value' => $c['up'] . '/' . $c['listed'],
            'state' => $c['down'] > 0 ? 'bad' : ($c['missing'] > 0 ? 'warn' : 'good'), 'sub' => $sub];
    }

    /**
     * SLA 達成率（規格 SLA-002／8.1）：（F-25 服務時段總時數 − 可歸責不可用時間）÷ 總時數。LibreNMS 的可用率是全天算的，
     * 所以只有 F-25 是 7×24 時才能直接用核心設備的 30 日平均可用率；其他時段無法計算，不能硬算。
     * 目標（F-20）、服務時段（F-25）、核心設備三項缺一就說缺哪一項；計畫性維護沒有排除（規格 SLA-004 得依契約排除），畫面寫明。
     */
    private function slaCard(?Fleet $fleet, ?array $sla, ?string $slaWarning): array
    {
        $u = static fn (string $why, ?string $na = null): array => self::unknownCard('sla', 'SLA 達成率', $why, $na);
        if ($sla === null) {
            return $u($slaWarning ?? '服務水準的設定讀不到');
        }
        if ($fleet === null) {
            return $u('LibreNMS 的設備資料無法讀取');
        }
        if ($sla['target'] === null) {
            return $u('尚未設定可用率目標（規格附表 A 的 F-20，由機關填列；到「設定」頁的「服務水準」填）', '尚未設定');
        }
        if ($sla['window'] === null) {
            return $u('尚未設定服務時段（規格附表 A 的 F-25，由機關填列；到「設定」頁的「服務水準」選）', '尚未設定');
        }
        if ($sla['window'] !== '24x7') {
            return $u('服務時段不是 7×24：LibreNMS 的可用率是全天計算的，無法依服務時段計算 SLA', '無法計算');
        }
        if ($sla['core'] === []) {
            return $u('尚未選擇核心設備（到「設定」頁的「服務水準」選擇）', '尚未設定');
        }
        $c = $fleet->core($sla['core']);
        if ($c['found'] === 0) {
            return $u(sprintf('清單裡的 %d 台核心設備都不在 LibreNMS（已刪除或停用）', $c['listed']), '設備不在 LibreNMS');
        }
        if ($c['avail30'] === null) {
            return $u('核心設備還沒有 30 日的可用率資料（剛納管或尚未累積）', '尚無資料');
        }
        $t = $sla['target'];
        // 達標與否用沒捨入的平均比；顯示無條件捨去到三位小數（目標最多三位小數，所以「顯示的值 ≥ 目標」與「達標」一定一致，
        // 不會出現顯示 100.00% 卻判未達 99.999% 目標，或反過來）。第三位是 0 就只寫兩位
        $shown = sprintf('%.3f', floor($c['avail30_exact'] * 1000 + 1e-9) / 1000);
        if (substr($shown, -1) === '0') {
            $shown = substr($shown, 0, -1);
        }
        $met = $c['avail30_exact'] >= $t - 1e-9;
        // 核心清單裡已不在 LibreNMS 的設備（被刪除或停用）不在平均裡：不能因此默默顯示達標，要寫出來、卡片不給綠色
        return ['key' => 'sla', 'label' => 'SLA 達成率', 'value' => $shown . '%', 'state' => $met ? ($c['missing'] > 0 ? 'warn' : 'good') : 'bad',
            'sub' => sprintf('目標 %s%%（F-20）· 最近 30 日 · %d 台核心設備平均', SettingsService::targetText($t), $c['n30'])
                . ($c['n30'] < $c['found'] ? sprintf('（另有 %d 台沒有資料，不計入）', $c['found'] - $c['n30']) : '')
                . ($c['missing'] > 0 ? sprintf('（清單裡另有 %d 台不在 LibreNMS，已刪除或停用，不計入）', $c['missing']) : '')
                . '；未排除計畫性維護'];
    }

    /** 網路可用性：LibreNMS 網路類設備（network、firewall、wireless、loadbalancer）最近 7 日的平均可用率。 */
    private function networkCard(?Fleet $fleet): array
    {
        $u = static fn (string $why, ?string $na = null): array => self::unknownCard('network_availability', '網路可用性', $why, $na);
        if ($fleet === null) {
            return $u('LibreNMS 的設備資料無法讀取');
        }
        $g = $fleet->groupAvailability('network', 'avail_7d');
        if ($g['devices'] === 0) {
            return $u('沒有納管任何網路設備（LibreNMS 設備類型 network、firewall、wireless、loadbalancer）', '無網路設備');
        }
        if ($g['value'] === null) {
            return $u('LibreNMS 沒有網路設備的可用率資料（剛納管或尚未累積）', '尚無資料');
        }
        return ['key' => 'network_availability', 'label' => '網路可用性', 'value' => sprintf('%.2f%%', $g['value']),
            'state' => $g['value'] >= 99.0 ? 'good' : ($g['value'] >= 95.0 ? 'warn' : 'bad'),
            'sub' => sprintf('最近 7 日，%d 台網路設備平均', $g['n']) . ($g['n'] < $g['devices'] ? sprintf('（另有 %d 台沒有資料，不計入）', $g['devices'] - $g['n']) : '')];
    }

    /**
     * 今日、昨日（台北時間）新建的事件案數；$rows 是 CaseService::createdWithin(2) 的結果。碰到查詢上限（只保留最新的）
     * 時較早的一天可能少算，不能寫一個偏低的數字，回傳 null（畫面就不寫這一句）。
     *
     * @param array<int,array{created_at:string}> $rows
     * @return array{today:int,yesterday:int}|null
     */
    public function dayCounts(array $rows): ?array
    {
        if (count($rows) >= CaseRepo::CREATED_SINCE_LIMIT) {
            return null;
        }
        $tz    = new \DateTimeZone('Asia/Taipei');
        $now   = (new \DateTimeImmutable('@' . $this->now))->setTimezone($tz);
        $today = $now->format('Y-m-d');
        $yest  = $now->modify('-1 day')->format('Y-m-d');
        $out   = ['today' => 0, 'yesterday' => 0];
        foreach ($rows as $r) {
            $ts = Time::parse($r['created_at']);
            if ($ts === null) {
                continue;
            }
            $day = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
            if ($day === $today) {
                $out['today']++;
            } elseif ($day === $yest) {
                $out['yesterday']++;
            }
        }
        return $out;
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
     * 新建事件案數，依嚴重性分三條線。視窗是 24 小時以內（含舊的短期間）→ 分六個時段；7 日、30 日 → 逐日（日期以台北時間分）。
     * 回傳的 `label`（「近 30 日」「最近 24 小時」）直接給畫面用，畫面不必自己從期間推算。
     *
     * @param array<int,array{created_at:string,severity:string}> $rows
     * @return array{days?:string[],labels?:string[],label:string,series:array<string,int[]>,total:int,capped:bool}
     */
    private function trend(array $rows): array
    {
        $window = $this->trendSeconds ?? self::TREND_DEFAULT;
        return $window <= 86400 ? $this->windowTrend($rows, $window) : $this->dailyTrend($rows, intdiv($window, 86400));
    }

    /** 逐日趨勢：最近 $nDays 天（含今天），以台北時間分日。 */
    private function dailyTrend(array $rows, int $nDays): array
    {
        $tz   = new \DateTimeZone('Asia/Taipei');
        $days = [];
        $idx  = [];
        for ($i = $nDays - 1; $i >= 0; $i--) {
            $d = (new \DateTimeImmutable('@' . $this->now))->setTimezone($tz)->modify("-{$i} day")->format('Y-m-d');
            $idx[$d] = count($days);
            $days[]  = $d;
        }
        $series = ['high' => array_fill(0, $nDays, 0), 'medium' => array_fill(0, $nDays, 0), 'low' => array_fill(0, $nDays, 0)];
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
        return ['days' => $days, 'label' => '近 ' . self::TREND_OPTIONS[$nDays * 86400], 'series' => $series, 'total' => $total,
            'capped' => count($rows) >= CaseRepo::CREATED_SINCE_LIMIT];
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
        return ['labels' => $labels, 'label' => '最近 ' . NetworkVisibilityService::RANGE_LABELS[$range], 'series' => $series, 'total' => $total,
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
            'unassigned'    => $fleet->unassigned(),
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
