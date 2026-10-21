<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 入口的可調設定（目前只有「風險等級門檻」）。誰能改（只有 admin）、怎麼驗證、沒設定時用什麼預設，
 * 全在這裡；端點只管登入、CSRF、解析內容。所有方法都先檢查權限，與 CaseService 一致。
 *
 * 風險等級門檻：分數（0–100，越高越好）≥ low 是低風險、≥ medium 是中等風險、其餘高風險。
 * 規則：1 ≤ medium < low ≤ 100，都是整數。沒設定過就用 RiskScore::DEFAULT_THRESHOLDS。
 * 兩個值永遠一起寫、一起讀；資料表裡只剩一個、或內容不合法（有人手動改過資料表），
 * 一律視為壞掉：riskThresholds() 丟例外（總覽只顯示分數、不判定等級），riskSettings() 標 invalid，
 * 讓管理者在設定頁重新儲存就能修復。絕不悄悄套用預設值，否則畫面上的等級會跟管理者設定的不一致。
 */
final class SettingsService
{
    public const RISK_LOW      = 'risk.low_from';
    public const RISK_MEDIUM   = 'risk.medium_from';
    public const REVIEW_DATE   = 'review.last_date';
    public const REVIEW_OWNER  = 'review.owner';
    public const REVIEW_PERIOD = 'review.period_days';
    public const REVIEW_PERIOD_DEFAULT = 90;
    public const KEY_LABEL     = [
        self::RISK_LOW      => '低風險門檻',
        self::RISK_MEDIUM   => '中等風險門檻',
        self::REVIEW_DATE   => '最近一次帳號盤點日期',
        self::REVIEW_OWNER  => '帳號盤點負責人',
        self::REVIEW_PERIOD => '帳號盤點週期（天）',
    ];
    public const HISTORY_LIMIT = 10;
    /** 沒設定過時各個設定名稱的預設值（風險門檻的來源是 RiskScore::DEFAULT_THRESHOLDS，這裡只是依設定名稱重排）。 */
    private const DEFAULTS = [
        self::RISK_LOW      => RiskScore::DEFAULT_THRESHOLDS['low'],
        self::RISK_MEDIUM   => RiskScore::DEFAULT_THRESHOLDS['medium'],
        self::REVIEW_PERIOD => self::REVIEW_PERIOD_DEFAULT,
    ];

    private SettingsRepo $repo;
    /** @var callable():string */
    private $now;

    /** @param callable():string|null $now 回傳 UTC 的 'Y-m-d H:i:s'，測試用 */
    public function __construct(SettingsRepo $repo, ?callable $now = null)
    {
        $this->repo = $repo;
        $this->now  = $now ?? static fn (): string => gmdate('Y-m-d H:i:s');
    }

    /**
     * 目前生效的風險等級門檻（給總覽用）。
     *
     * @return array{low:int,medium:int}
     * @throws IocError 資料表裡的值不合法
     */
    public function riskThresholds(?string $level): array
    {
        $this->requireView($level);
        $s = $this->readRisk($this->repo->all());
        if ($s['invalid']) {
            throw new IocError('風險等級門檻的設定值不合法（資料表被手動改過？），請管理者到「設定」頁重新儲存');
        }
        return ['low' => $s['low'], 'medium' => $s['medium']];
    }

    /**
     * 設定頁要的資料：目前值、預設值、誰在什麼時候改的、最近的異動紀錄。時間已轉成台北時間。
     *
     * @return array<string,mixed>
     */
    public function riskSettings(?string $level): array
    {
        $this->requireView($level);
        $s = $this->readRisk($this->repo->all());
        $history = [];
        // 只列風險門檻自己的異動：這一頁檢視者也看得到，帳號盤點那幾筆是 admin 才看得到的（身分與稽核頁）
        foreach ($this->repo->recentHistory(self::HISTORY_LIMIT, [self::RISK_LOW, self::RISK_MEDIUM]) as $h) {
            $history[] = [
                'time'  => Time::toTaipei($h['ts']),
                'actor' => (string) $h['actor'],
                'label' => self::KEY_LABEL[$h['skey']] ?? (string) $h['skey'],
                'from'  => (string) $h['old_value'],
                'to'    => (string) $h['new_value'],
            ];
        }
        return [
            'low'        => $s['low'],
            'medium'     => $s['medium'],
            'defaults'   => RiskScore::DEFAULT_THRESHOLDS,
            'custom'     => $s['custom'],
            'invalid'    => $s['invalid'],
            'updated_by' => $s['updated_by'],
            'updated_at' => $s['updated_at'] === null ? null : Time::toTaipei($s['updated_at']),
            'history'    => $history,
        ];
    }

    /**
     * 儲存風險等級門檻（僅 admin）。值沒有變動就什麼都不寫（也不留紀錄）；有變動則兩個值一起寫，
     * 每個實際變動的值各留一筆異動紀錄（誰、何時、舊值、新值）。
     *
     * @param array<string,mixed> $in risk_low、risk_medium
     * @return array<string,mixed> 儲存後的 riskSettings()
     */
    public function saveRisk(array $in, string $actor, ?string $level): array
    {
        $this->requireHandler($level);
        $new = $this->validateRisk($in);
        $now = ($this->now)();

        $this->repo->transaction(function () use ($new, $actor, $now): void {
            $rows = $this->repo->all();
            $cur  = $this->readRisk($rows);
            if (!$cur['invalid'] && $cur['low'] === $new['low'] && $cur['medium'] === $new['medium']) {
                return;
            }
            $this->persist([self::RISK_LOW => (string) $new['low'], self::RISK_MEDIUM => (string) $new['medium']], $rows, $actor, $now);
        });
        return $this->riskSettings($level);
    }

    /**
     * 帳號盤點紀錄（僅 admin，讀和寫都是）：最近一次盤點日期、負責人、盤點週期。身分與稽核頁用，那一頁只有 admin 看得到。
     * 沒記錄過是 recorded=false；資料表裡只剩一部分或內容不合法一律當成壞掉（invalid=true），讓管理者重新儲存即修復。
     *
     * @return array{last_date:?string,owner:?string,period_days:int,recorded:bool,invalid:bool,updated_by:?string,updated_at:?string}
     */
    public function accountReview(?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以看身分與稽核');
        return $this->readReview($this->repo->all());
    }

    /**
     * 儲存帳號盤點紀錄。三個值一起寫；沒有變動就不寫（也不留異動紀錄）。
     *
     * @param array<string,mixed> $in last_date（YYYY-MM-DD，不能是未來）、owner、period_days（7～365）
     * @return array{last_date:?string,owner:?string,period_days:int,recorded:bool,invalid:bool,updated_by:?string,updated_at:?string}
     */
    public function saveAccountReview(array $in, string $actor, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以修改帳號盤點紀錄');
        $now = ($this->now)();
        $new = $this->validateReview($in, $now);

        $this->repo->transaction(function () use ($new, $actor, $now): void {
            $rows = $this->repo->all();
            $cur  = $this->readReview($rows);
            if ($cur['recorded'] && !$cur['invalid'] && $cur['last_date'] === $new[self::REVIEW_DATE] && $cur['owner'] === $new[self::REVIEW_OWNER] && (string) $cur['period_days'] === $new[self::REVIEW_PERIOD]) {
                return;
            }
            $this->persist($new, $rows, $actor, $now);
        });
        return $this->accountReview($level);
    }

    /**
     * 把一組設定值寫進去：每個值都寫（「最後修改」才會一致），實際有變動的才留異動紀錄。
     * 舊值是資料表裡現有的值；沒設定過的用預設值（沒有預設值的是空字串）。在交易內呼叫。
     *
     * @param array<string,string> $values 設定名稱 → 新值
     * @param array<string,array{value:string,updated_by:string,updated_at:string}> $rows 目前資料表裡的設定
     */
    private function persist(array $values, array $rows, string $actor, string $now): void
    {
        foreach ($values as $key => $value) {
            $old = $rows[$key]['value'] ?? (string) (self::DEFAULTS[$key] ?? '');
            $this->repo->put($key, $value, $actor, $now);
            if ($old !== $value) {
                $this->repo->addHistory($now, $actor, $key, $old, $value);
            }
        }
    }

    // ── 內部 ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $in
     * @return array{low:int,medium:int}
     */
    private function validateRisk(array $in): array
    {
        $errors = [];
        $low    = $this->wholeNumber($in['risk_low'] ?? null);
        $medium = $this->wholeNumber($in['risk_medium'] ?? null);
        if ($low === null || $low < 1 || $low > 100) {
            $errors[] = '「低風險」的門檻要填 1～100 的整數';
        }
        if ($medium === null || $medium < 1 || $medium > 100) {
            $errors[] = '「中等風險」的門檻要填 1～100 的整數';
        }
        if ($errors === [] && !self::validPair((int) $low, (int) $medium)) {
            $errors[] = sprintf('「中等風險」的門檻（%d）要比「低風險」的門檻（%d）小', $medium, $low);
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return ['low' => (int) $low, 'medium' => (int) $medium];
    }

    /** 一組門檻合不合法：1 ≤ 中等 < 低風險 ≤ 100。儲存前的驗證與讀回資料表時的檢查都用這一條，規則改了不會只改到一邊。 */
    private static function validPair(int $low, int $medium): bool
    {
        return $medium >= 1 && $medium < $low && $low <= 100;
    }

    /** 只收 1～3 位的半形整數字串（前後空白可以），其他（小數、負數、空字串、陣列）都是 null。 */
    private function wholeNumber($v): ?int
    {
        if (!is_scalar($v) || !preg_match('/^\d{1,3}$/', trim((string) $v))) {
            return null;
        }
        return (int) trim((string) $v);
    }

    /**
     * @param array<string,array{value:string,updated_by:string,updated_at:string}> $rows
     * @return array{low:int,medium:int,custom:bool,invalid:bool,updated_by:?string,updated_at:?string}
     */
    private function readRisk(array $rows): array
    {
        $d    = RiskScore::DEFAULT_THRESHOLDS;
        $none = ['low' => $d['low'], 'medium' => $d['medium'], 'custom' => false, 'invalid' => false, 'updated_by' => null, 'updated_at' => null];
        $lo   = $rows[self::RISK_LOW] ?? null;
        $me   = $rows[self::RISK_MEDIUM] ?? null;
        if ($lo === null && $me === null) {
            return $none;
        }
        $low    = $lo === null ? null : $this->wholeNumber($lo['value']);
        $medium = $me === null ? null : $this->wholeNumber($me['value']);
        if ($low === null || $medium === null || !self::validPair($low, $medium)) {
            return ['invalid' => true] + $none;
        }
        // 兩個值是一起寫的，時間與操作者相同；萬一不同（手動改過）以較晚的為準
        $last = strcmp($lo['updated_at'], $me['updated_at']) >= 0 ? $lo : $me;
        return ['low' => $low, 'medium' => $medium, 'custom' => true, 'invalid' => false, 'updated_by' => $last['updated_by'], 'updated_at' => $last['updated_at']];
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,string> 設定名稱 → 整理過的值
     */
    private function validateReview(array $in, string $nowUtc): array
    {
        $errors = [];
        $date   = is_scalar($in['last_date'] ?? null) ? trim((string) $in['last_date']) : '';
        if (!$this->isRealDate($date)) {
            $errors[] = '盤點日期要填成 YYYY-MM-DD 的真實日期';
        } elseif ($date > $this->todayTaipei($nowUtc)) {
            $errors[] = '盤點日期不能是未來的日期';
        }
        $owner = is_scalar($in['owner'] ?? null) ? trim((string) $in['owner']) : '';
        if ($owner === '' || mb_strlen($owner) > 64 || preg_match('/[\x00-\x1F\x7F]/', $owner)) {
            $errors[] = '盤點負責人要填，且不能超過 64 個字';
        }
        $period = $this->wholeNumber($in['period_days'] ?? null);
        if ($period === null || $period < 7 || $period > 365) {
            $errors[] = '盤點週期要填 7～365 的整數（天）';
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return [self::REVIEW_DATE => $date, self::REVIEW_OWNER => $owner, self::REVIEW_PERIOD => (string) $period];
    }

    /**
     * @param array<string,array{value:string,updated_by:string,updated_at:string}> $rows
     * @return array{last_date:?string,owner:?string,period_days:int,recorded:bool,invalid:bool,updated_by:?string,updated_at:?string}
     */
    private function readReview(array $rows): array
    {
        $none = ['last_date' => null, 'owner' => null, 'period_days' => self::REVIEW_PERIOD_DEFAULT, 'recorded' => false, 'invalid' => false, 'updated_by' => null, 'updated_at' => null];
        $d = $rows[self::REVIEW_DATE] ?? null;
        $o = $rows[self::REVIEW_OWNER] ?? null;
        $p = $rows[self::REVIEW_PERIOD] ?? null;
        if ($d === null && $o === null && $p === null) {
            return $none;
        }
        $period = $p === null ? null : $this->wholeNumber($p['value']);
        if ($d === null || $o === null || $p === null || !$this->isRealDate($d['value']) || $o['value'] === '' || $period === null || $period < 7 || $period > 365) {
            return ['invalid' => true] + $none;       // 只剩一部分、或被手動改成不合法的值：壞掉，不悄悄補預設
        }
        $last = $d;
        foreach ([$o, $p] as $row) {
            if (strcmp($row['updated_at'], $last['updated_at']) > 0) {
                $last = $row;                         // 三個值是一起寫的；萬一不同以最晚的為準
            }
        }
        return ['last_date' => $d['value'], 'owner' => $o['value'], 'period_days' => $period, 'recorded' => true, 'invalid' => false,
                'updated_by' => $last['updated_by'], 'updated_at' => Time::toTaipei($last['updated_at'])];
    }

    private function isRealDate(string $s): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function todayTaipei(string $nowUtc): string
    {
        return Time::toTaipei($nowUtc, 'Y-m-d');
    }

    private function requireView(?string $level): void
    {
        if (!Access::canView($level)) {
            throw new AccessDenied('沒有檢視權限');
        }
    }

    private function requireHandler(?string $level): void
    {
        if (!Access::canHandle($level)) {
            throw new AccessDenied('沒有修改設定的權限');
        }
    }
}
