<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 入口的可調設定：風險等級門檻、帳號盤點紀錄、內部網段（內網可視性用）、站點與網段（總覽把設備依 IP 歸站點用，
 * 規則在 Sites，一個站點一列 site.<id>）。誰能改（只有 admin）、怎麼驗證、
 * 沒設定時用什麼預設，全在這裡；端點只管登入、CSRF、解析內容。所有方法都先檢查權限，與 CaseService 一致。
 * 內部網段的規則在 IpNets（IPv4 CIDR、整理排序去重）；同樣「壞掉的值不悄悄套用預設」：internalNets() 丟例外，
 * networkSettings() 標 invalid。
 *
 * 風險等級門檻：分數（0–100，越高越好）≥ low 是低風險、≥ medium 是中等風險、其餘高風險。
 * 規則：1 ≤ medium < low ≤ 100，都是整數。沒設定過就用 RiskScore::DEFAULT_THRESHOLDS。
 * 兩個值永遠一起寫、一起讀；資料表裡只剩一個、或內容不合法（有人手動改過資料表），
 * 一律視為壞掉：overviewSettings() 的 thresholds 是 null 並附原因（總覽只顯示分數、不判定等級），riskSettings() 標 invalid，
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
    public const NET_INTERNAL  = 'net.internal_cidrs';
    public const KEY_LABEL     = [
        self::RISK_LOW      => '低風險門檻',
        self::RISK_MEDIUM   => '中等風險門檻',
        self::REVIEW_DATE   => '最近一次帳號盤點日期',
        self::REVIEW_OWNER  => '帳號盤點負責人',
        self::REVIEW_PERIOD => '帳號盤點週期（天）',
        self::NET_INTERNAL  => '內部網段',
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
     * 目前生效的內部網段（給內網可視性用）。資料表裡的值不合法（有人手動改過）時丟例外：呼叫端要標「無法取得」，
     * 不能悄悄改用預設網段，否則畫面上的「內部」會跟管理者設定的不一致。
     *
     * @return string[] 整理過的 CIDR
     * @throws IocError 資料表裡的值不合法
     */
    public function internalNets(?string $level): array
    {
        $this->requireView($level);
        return $this->netsFrom($this->repo->all());
    }

    /**
     * 設定頁「內部網段」要的資料：目前的網段、預設值、誰在什麼時候改的、最近的異動紀錄（時間已轉成台北時間）。檢視者可看。
     *
     * @return array<string,mixed>
     */
    public function networkSettings(?string $level): array
    {
        $this->requireView($level);
        $s = $this->readNetwork($this->repo->all());
        $history = [];
        foreach ($this->repo->recentHistory(self::HISTORY_LIMIT, [self::NET_INTERNAL]) as $h) {
            $history[] = [
                'time'  => Time::toTaipei($h['ts']),
                'actor' => (string) $h['actor'],
                'from'  => (string) $h['old_value'],
                'to'    => (string) $h['new_value'],
            ];
        }
        return [
            'nets'       => $s['nets'],
            'defaults'   => IpNets::DEFAULTS,
            'custom'     => $s['custom'],
            'invalid'    => $s['invalid'],
            'updated_by' => $s['updated_by'],
            'updated_at' => $s['updated_at'] === null ? null : Time::toTaipei($s['updated_at']),
            'limits'     => ['max_nets' => IpNets::MAX_NETS, 'min_bits' => IpNets::MIN_BITS],
            'history'    => $history,
        ];
    }

    /**
     * 儲存內部網段（僅 admin）。網段整理成「網路位址/前綴長度」、排序、去重後存成一個字串；整理後沒有變動就什麼都不寫
     * （也不留紀錄）。資料表裡的值壞了（invalid）時，無論填什麼都會寫入，管理者重新儲存就能修復。
     *
     * @param array<string,mixed> $in cidrs（一行一個網段的文字）
     * @return array<string,mixed> 儲存後的 networkSettings()
     */
    public function saveNetwork(array $in, string $actor, ?string $level): array
    {
        $this->requireHandler($level);
        $text = is_scalar($in['cidrs'] ?? null) ? (string) $in['cidrs'] : '';
        if (strlen($text) > 1024) {
            throw new ValidationError(['內容太長了（網段清單不會超過 1024 個字元）']);
        }
        $r = IpNets::parseInput($text);
        if ($r['errors'] !== []) {
            throw new ValidationError($r['errors']);
        }
        $new = $r['nets'];
        $now = ($this->now)();

        $this->repo->transaction(function () use ($new, $actor, $now): void {
            $rows = $this->repo->all();
            $cur  = $this->readNetwork($rows);
            if (!$cur['invalid'] && $cur['nets'] === $new) {
                return;
            }
            $this->persist([self::NET_INTERNAL => IpNets::toStored($new)], $rows, $actor, $now);
        });
        return $this->networkSettings($level);
    }

    /**
     * 目前生效的站點（給總覽把設備依 IP 歸站點用）。沒設定過站點是空的 Sites（不是錯誤：站點就是 LibreNMS 的位置，與沒有這個功能時一樣）；
     * 資料表裡有壞掉的列（有人手動改過）時丟例外：呼叫端要標明原因，不能默默少算站點。
     *
     * @throws IocError 資料表裡的站點設定不合法
     */
    public function sites(?string $level): Sites
    {
        $this->requireView($level);
        return $this->sitesFrom($this->repo->all());
    }

    /**
     * 總覽一次要用的三組設定（站點、風險門檻、內部網段），只讀一次資料表（總覽每個請求都會載入，不要各讀一次整張表）。
     * 三組各自成敗：壞掉的那組是 null、原因放在 errors，不影響另外兩組，呼叫端要自己把原因帶到畫面，不能套預設值冒充。
     * errors 只有兩種：IocError（那一組的值不合法，訊息是給人看的）；以及整張表讀不到（資料表還沒建立、資料庫連不上）時
     * 三組共用的同一個讀取例外，原樣放進來（訊息可能含連線資訊，只能記 log，不能顯示給使用者）。
     * 讀進來之後的解析只會丟 IocError，其他例外（型別錯誤之類）是程式的 bug，一律往上拋，不歸類成「讀不到」。
     * 權限不夠是 AccessDenied，直接丟出，不算「讀不到」。
     *
     * @return array{sites:?Sites,thresholds:?array{low:int,medium:int},nets:?array<int,string>,errors:array<string,\Throwable>}
     */
    public function overviewSettings(?string $level): array
    {
        $this->requireView($level);
        $out = ['sites' => null, 'thresholds' => null, 'nets' => null, 'errors' => []];
        try {
            $rows = $this->repo->all();
        } catch (\Throwable $e) {
            return ['errors' => ['sites' => $e, 'thresholds' => $e, 'nets' => $e]] + $out;
        }
        $readers = [
            'sites'      => fn (): Sites => $this->sitesFrom($rows),
            'thresholds' => fn (): array => $this->thresholdsFrom($rows),
            'nets'       => fn (): array => $this->netsFrom($rows),
        ];
        foreach ($readers as $part => $read) {
            try {
                $out[$part] = $read();
            } catch (IocError $e) {
                $out['errors'][$part] = $e;
            }
        }
        return $out;
    }

    /**
     * 設定頁「站點與網段」要的資料：站點清單（id、名稱、網段）、有幾列壞掉、限制、最後修改、最近的異動紀錄。檢視者可看。
     *
     * @return array<string,mixed>
     */
    public function siteSettings(?string $level): array
    {
        $this->requireView($level);
        $s = $this->readSites($this->repo->all());
        $sites  = [];
        $latest = null;
        foreach ($s['list'] as $row) {
            $sites[] = ['id' => $row['id'], 'name' => $row['name'], 'nets' => $row['nets']];
            if ($latest === null || strcmp($row['updated_at'], $latest['updated_at']) > 0) {
                $latest = $row;
            }
        }
        $history = [];
        // 站點一列一個設定名稱，刪掉的站點的紀錄也要看得到，沒辦法列舉名稱，所以依前綴篩（在資料庫端，不受其他設定的異動筆數影響）
        foreach ($this->repo->recentHistory(self::HISTORY_LIMIT, null, Sites::KEY_PREFIX) as $h) {
            $history[] = [
                'time'  => Time::toTaipei($h['ts']),
                'actor' => (string) $h['actor'],
                'from'  => self::siteText((string) $h['old_value'], '（新增）'),
                'to'    => self::siteText((string) $h['new_value'], '（刪除）'),
            ];
        }
        return [
            'sites'      => $sites,
            'broken'     => count($s['broken']),
            'invalid'    => $s['broken'] !== [],
            'limits'     => ['max_sites' => Sites::MAX_SITES, 'max_nets' => Sites::MAX_NETS, 'name_max' => Sites::NAME_MAX],
            'updated_by' => $latest === null ? null : $latest['updated_by'],
            'updated_at' => $latest === null ? null : Time::toTaipei($latest['updated_at']),
            'history'    => $history,
        ];
    }

    /**
     * 儲存整份站點清單（僅 admin）：有 id 的是既有站點（更新），沒有的是新增，沒送來的現有站點就是刪除。
     * 只寫實際有變動的站點，每個變動（新增、修改、刪除）各留一筆異動紀錄；整份沒有變動就什麼都不寫。
     * 資料表裡壞掉的列（讀不懂的值）不會出現在清單裡，儲存時一併刪除，管理者重新儲存就能修復。
     *
     * @param array<string,mixed> $in sites：[{id?, name, cidrs}]
     * @return array<string,mixed> 儲存後的 siteSettings()
     */
    public function saveSites(array $in, string $actor, ?string $level): array
    {
        $this->requireHandler($level);
        $items = $in['sites'] ?? null;
        if (!is_array($items)) {
            throw new ValidationError(['沒有收到站點清單']);
        }
        $parsed = Sites::parseSubmitted($items);
        if ($parsed['errors'] !== []) {
            throw new ValidationError($parsed['errors']);
        }
        $now = ($this->now)();

        $this->repo->transaction(function () use ($parsed, $actor, $now): void {
            // 鎖定讀取：兩個管理者同時儲存時，第二個會等第一個做完再讀到最新內容（不然兩邊算出同一個新 id、後寫的蓋掉先寫的）
            $rows = $this->repo->allForUpdate();
            $cur  = $this->readSites($rows);
            // 送來的 id 必須是現有站點：不然是別的管理者已經把它刪了，這時重新建立它會讓刪除白做
            foreach ($parsed['sites'] as $s) {
                if ($s['id'] !== null && !isset($cur['list'][$s['id']])) {
                    throw new ValidationError(['這份站點設定已經被別人更新過（或其中的站點已被刪除），請重新整理頁面再修改']);
                }
            }
            // 新站點的 id 只增不減、永不重用：下一個可用的 id 記在 sites.next_id。只看「現有最大 id＋1」的話，刪掉最大的站點再新增，
            // 新站點會拿到同一個 id，開著舊畫面的人用那個 id 存檔就會蓋掉別人新建的站點（上面的過期檢查擋不住）
            $stored = isset($rows[Sites::NEXT_ID_KEY]) && ctype_digit($rows[Sites::NEXT_ID_KEY]['value']) ? (int) $rows[Sites::NEXT_ID_KEY]['value'] : 0;
            $next   = max(1, $stored);
            foreach (array_keys($rows) as $k) {
                $id = Sites::idOfKey((string) $k);
                if ($id !== null && $id >= $next) {
                    $next = $id + 1;
                }
            }
            $keep = [];
            foreach ($parsed['sites'] as $s) {
                $id = $s['id'] ?? $next++;
                if ($id > Sites::MAX_ID) {
                    throw new ValidationError(['站點編號已經用完（內部計數器超出範圍），請聯絡系統管理員檢查 ioc_settings 的 ' . Sites::NEXT_ID_KEY]);
                }
                $key   = Sites::keyOf($id);
                $value = Sites::toStored($s['name'], $s['nets']);
                $keep[$key] = true;
                if (($rows[$key]['value'] ?? null) !== $value) {
                    $this->persist([$key => $value], $rows, $actor, $now);
                }
            }
            $removed = false;
            foreach ($rows as $key => $row) {
                if (Sites::isSiteKey((string) $key) && !isset($keep[$key])) {
                    $this->repo->delete((string) $key);
                    $this->repo->addHistory($now, $actor, (string) $key, $row['value'], '');
                    $removed = true;
                }
            }
            // 有新增或刪除才需要更新計數器（整份沒有變動時什麼都不寫）；計數器只增不減，所以刪掉的 id 不會再被拿來用
            if (($removed || $next > max(1, $stored)) && $next !== $stored) {
                $this->repo->put(Sites::NEXT_ID_KEY, (string) $next, $actor, $now);       // 內部計數器，不留異動紀錄
            }
        });
        return $this->siteSettings($level);
    }

    /** 設定名稱的中文名（稽核軌跡、異動紀錄用）；站點是一列一個設定名稱，統一叫「站點」。 */
    public static function keyLabel(string $key): string
    {
        return self::KEY_LABEL[$key] ?? (Sites::isSiteKey($key) ? '站點與網段' : $key);
    }

    /**
     * 設定名稱沒設定過時的值（寫異動紀錄的「原值」用）。風險門檻與盤點週期是整數常數，內部網段是 RFC1918 清單，其餘是空字串。
     */
    private static function defaultValue(string $key): string
    {
        if ($key === self::NET_INTERNAL) {
            return IpNets::toStored(IpNets::DEFAULTS);
        }
        return (string) (self::DEFAULTS[$key] ?? '');
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
            $old = $rows[$key]['value'] ?? self::defaultValue($key);
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
     * @param array<string,array{value:string,updated_by:string,updated_at:string}> $rows
     * @return array{nets:string[],custom:bool,invalid:bool,updated_by:?string,updated_at:?string}
     */
    private function readNetwork(array $rows): array
    {
        $none = ['nets' => IpNets::DEFAULTS, 'custom' => false, 'invalid' => false, 'updated_by' => null, 'updated_at' => null];
        $r = $rows[self::NET_INTERNAL] ?? null;
        if ($r === null) {
            return $none;
        }
        $nets = IpNets::fromStored($r['value']);
        if ($nets === null) {
            return ['invalid' => true] + $none;       // 被手動改成不合法的值：壞掉，不悄悄套用預設
        }
        return ['nets' => $nets, 'custom' => true, 'invalid' => false, 'updated_by' => $r['updated_by'], 'updated_at' => $r['updated_at']];
    }

    /**
     * 資料表裡的站點列 → 站點清單與壞掉的列。壞掉＝設定名稱的 id 不合法、值讀不懂，或名稱／網段與前面的站點重複
     * （有人手動改過資料表；重複時兩邊哪個算數是模糊的，所以後面那個算壞）。
     *
     * @param array<string,array{value:string,updated_by:string,updated_at:string}> $rows
     * @return array{list:array<int,array{id:int,name:string,nets:string[],updated_by:string,updated_at:string}>,broken:string[]} list 以 id 為鍵、依 id 排序
     */
    private function readSites(array $rows): array
    {
        $list   = [];
        $broken = [];
        foreach ($rows as $key => $r) {
            if (!Sites::isSiteKey((string) $key)) {
                continue;
            }
            $id = Sites::idOfKey((string) $key);
            $v  = $id === null ? null : Sites::fromStored($r['value']);
            if ($v === null) {
                $broken[] = (string) $key;
                continue;
            }
            $list[$id] = $v + ['id' => $id, 'updated_by' => $r['updated_by'], 'updated_at' => $r['updated_at']];
        }
        ksort($list);
        $names = [];
        $nets  = [];
        foreach ($list as $id => $s) {
            $dup = isset($names[mb_strtolower($s['name'])]);
            foreach ($s['nets'] as $n) {
                $dup = $dup || isset($nets[$n]);
            }
            if ($dup) {
                $broken[] = Sites::keyOf($id);
                unset($list[$id]);
                continue;
            }
            $names[mb_strtolower($s['name'])] = true;
            foreach ($s['nets'] as $n) {
                $nets[$n] = true;
            }
        }
        return ['list' => $list, 'broken' => $broken];
    }

    /** 異動紀錄裡一個站點的值（「名稱|網段,網段」）→ 給人看的文字；空值是新增或刪除，寫 $empty。 */
    private static function siteText(string $stored, string $empty): string
    {
        if ($stored === '') {
            return $empty;
        }
        return str_replace([',', '|'], ['、', '：'], $stored);
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

    /**
     * 資料列 → 生效的風險等級門檻（壞掉就丟例外，不套預設值）。overviewSettings() 用，不檢查權限。
     *
     * @param array<string,array{value:string,updated_by:string,updated_at:string}> $rows
     * @return array{low:int,medium:int}
     * @throws IocError
     */
    private function thresholdsFrom(array $rows): array
    {
        $s = $this->readRisk($rows);
        if ($s['invalid']) {
            throw new IocError('風險等級門檻的設定值不合法（資料表被手動改過？），請管理者到「設定」頁重新儲存');
        }
        return ['low' => $s['low'], 'medium' => $s['medium']];
    }

    /**
     * 資料列 → 生效的內部網段（壞掉就丟例外，不套預設網段）。internalNets() 與 overviewSettings() 共用，不檢查權限。
     *
     * @param array<string,array{value:string,updated_by:string,updated_at:string}> $rows
     * @return string[]
     * @throws IocError
     */
    private function netsFrom(array $rows): array
    {
        $s = $this->readNetwork($rows);
        if ($s['invalid']) {
            throw new IocError('內部網段的設定值不合法（資料表被手動改過？），請管理者到「設定」頁重新儲存');
        }
        return $s['nets'];
    }

    /**
     * 資料列 → 生效的站點（有壞掉的列就丟例外，不默默少算）。sites() 與 overviewSettings() 共用，不檢查權限。
     *
     * @param array<string,array{value:string,updated_by:string,updated_at:string}> $rows
     * @throws IocError
     */
    private function sitesFrom(array $rows): Sites
    {
        $s = $this->readSites($rows);
        if ($s['broken'] !== []) {
            throw new IocError('站點的設定值不合法（資料表被手動改過？），請管理者到「設定」頁重新儲存');
        }
        return new Sites($s['list']);
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
