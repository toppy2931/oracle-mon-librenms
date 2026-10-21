<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 身分與角色權限治理（簡報第 9 張）：帳號與角色統計、雙因子覆蓋、最小權限、久未登入的帳號、帳號盤點。
 * 輸入都是 endpoint.php 從 LibreNMS 讀出來的純陣列（這個類別不碰資料庫）；只有 admin 能用（IS-013）。
 *
 * 鐵則同總覽（OPS-006、RPT-007）：每個區塊各自成敗，資料取不到（輸入是 null）就是
 * ['ok'=>false,'error'=>原因]，不能變成 0 個帳號或「全部都有雙因子」。
 *
 * 安全：雙因子只看「有沒有這一筆」（呼叫端只傳 user_id），絕不讀 users_prefs.value，那裡面是密鑰。
 * 統計的是「啟用中」的帳號：停用的帳號登不進來，算進去會讓比例失真。
 */
final class IdentityService
{
    /** LibreNMS 的登入紀錄（authlog）預設只保留約 30 天，所以「沒登入」最多只能往前看這麼久。 */
    public const DORMANT_DAYS  = 30;
    public const DORMANT_LIMIT = 50;
    public const NO_ROLE       = 'none';

    public const ROLE_LABEL = [
        'admin'       => '管理者（admin）',
        'global-read' => '唯讀（global-read）',
        'user'        => '一般使用者（user）',
        self::NO_ROLE => '沒有角色',
    ];

    private int $now;
    private string $tz;

    /** @param string|null $tz 沒帶時區的時間字串（authlog）屬於哪個時區；預設是 PHP 的預設時區（LibreNMS 要求與資料庫一致） */
    public function __construct(?int $now = null, ?string $tz = null)
    {
        $this->now = $now ?? time();
        $this->tz  = $tz ?? date_default_timezone_get();
    }

    /**
     * @param array<int,array{id:int,username:string,enabled:bool,local?:bool}>|null $users local＝本機帳號（users.auth_type 是 mysql）；沒給當成本機
     * @param array<int,string[]>|null $roles user_id → 角色名稱
     * @param int[]|null $twofactor 有啟用雙因子的 user_id（只看有沒有，不讀密鑰）
     * @param array<string,string>|null $lastLogins 帳號 → 最近一次登入成功的時間（伺服器本地時間）
     * @param string|null $authlogOldest 登入紀錄最早一筆的時間；$lastLogins 讀得到但這裡是 null＝登入紀錄是空的
     * @param array{last_date:?string,owner:?string,period_days:int,recorded:bool,invalid:bool}|null $review SettingsService::accountReview()
     * @return array<string,array<string,mixed>> 各區塊：['ok'=>true,'data'=>…] 或 ['ok'=>false,'error'=>…]
     */
    public function build(?array $users, ?array $roles, ?array $twofactor, ?array $lastLogins, ?string $authlogOldest, ?array $review, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以看身分與稽核');
        $noUsers = 'LibreNMS 的帳號資料無法讀取';

        if ($users === null) {
            $fail = ['ok' => false, 'error' => $noUsers];
            return ['summary' => $fail, 'roles' => $fail, 'two_factor' => $fail, 'least_privilege' => $fail, 'dormant' => $fail, 'review' => $this->review($review)];
        }
        $active = array_values(array_filter($users, static fn ($u) => $u['enabled']));

        return [
            'summary'         => ['ok' => true, 'data' => ['all' => count($users), 'enabled' => count($active), 'disabled' => count($users) - count($active)]],
            'roles'           => $roles === null ? $this->fail('LibreNMS 的角色資料無法讀取') : ['ok' => true, 'data' => $this->roleCounts($active, $roles)],
            'two_factor'      => $this->twoFactor($active, $roles, $twofactor),
            'least_privilege' => $roles === null ? $this->fail('LibreNMS 的角色資料無法讀取') : ['ok' => true, 'data' => $this->leastPrivilege($active, $roles)],
            'dormant'         => $this->dormant($active, $lastLogins, $authlogOldest),
            'review'          => $this->review($review),
        ];
    }

    /**
     * @param array<int,array{id:int,username:string,enabled:bool}> $active
     * @param array<int,string[]> $roles
     * @return array<int,array{key:string,label:string,count:int}>
     */
    private function roleCounts(array $active, array $roles): array
    {
        $by = [];
        foreach ($active as $u) {
            foreach ($this->rolesOf($u['id'], $roles) as $r) {
                $by[$r] = ($by[$r] ?? 0) + 1;       // 一個帳號有多個角色就各算一次
            }
        }
        $out = [];
        foreach (array_merge(array_keys(self::ROLE_LABEL), array_diff(array_keys($by), array_keys(self::ROLE_LABEL))) as $key) {
            if (($by[$key] ?? 0) > 0) {
                $out[] = ['key' => $key, 'label' => self::ROLE_LABEL[$key] ?? $key, 'count' => $by[$key]];
            }
        }
        return $out;
    }

    /**
     * @param array<int,array{id:int,username:string,enabled:bool}> $active
     * @param array<int,string[]>|null $roles
     * @param int[]|null $twofactor
     * @return array<string,mixed>
     */
    private function twoFactor(array $active, ?array $roles, ?array $twofactor): array
    {
        if ($twofactor === null) {
            return $this->fail('LibreNMS 的雙因子設定無法讀取');
        }
        if ($roles === null) {
            return $this->fail('LibreNMS 的角色資料無法讀取');
        }
        // 走外部驗證的帳號（LDAP、SSO…，users.auth_type 不是 mysql）在 LibreNMS 裡本來就不會有雙因子這一筆，
        // 雙因子是在那邊管的：算進來會把它們全報成「沒有雙因子」，管理者也會被列進「沒有雙因子的管理者」。
        // 所以只統計本機帳號，外部驗證的另外回報有幾個，畫面說明這裡不算
        $local = array_values(array_filter($active, static fn ($u) => $u['local'] ?? true));
        $has = array_flip(array_map('intval', $twofactor));
        $on = 0;
        $adminsWithout = [];
        foreach ($local as $u) {
            if (isset($has[$u['id']])) {
                $on++;
            } elseif (in_array('admin', $this->rolesOf($u['id'], $roles), true)) {
                $adminsWithout[] = $u['username'];
            }
        }
        sort($adminsWithout, SORT_STRING);
        return ['ok' => true, 'data' => ['enabled' => $on, 'total' => count($local), 'external' => count($active) - count($local), 'admins_without' => $adminsWithout]];
    }

    /**
     * 最小權限：只能給事實（管理者有幾位、是誰、佔多少），不替單位判斷「幾位算太多」。
     *
     * @param array<int,array{id:int,username:string,enabled:bool}> $active
     * @param array<int,string[]> $roles
     * @return array{admins:int,admin_names:string[],total:int}
     */
    private function leastPrivilege(array $active, array $roles): array
    {
        $names = [];
        foreach ($active as $u) {
            if (in_array('admin', $this->rolesOf($u['id'], $roles), true)) {
                $names[] = $u['username'];
            }
        }
        sort($names, SORT_STRING);
        return ['admins' => count($names), 'admin_names' => $names, 'total' => count($active)];
    }

    /**
     * 啟用中、但最近沒有登入紀錄的帳號。登入紀錄只保留一段時間，所以回報實際看的天數與紀錄最早的時間，
     * 畫面要明講「只看得到這麼久」，不能讓人以為是一年內都沒登入過。
     *
     * @param array<int,array{id:int,username:string,enabled:bool}> $active
     * @param array<string,string>|null $lastLogins
     * @return array<string,mixed>
     */
    private function dormant(array $active, ?array $lastLogins, ?string $oldest): array
    {
        if ($lastLogins === null) {
            return $this->fail('LibreNMS 的登入紀錄無法讀取');
        }
        $oldestTs = $oldest === null ? null : Time::parse($oldest, $this->tz);
        if ($oldestTs === null) {
            return $this->fail('LibreNMS 沒有任何登入紀錄（或最早一筆的時間讀不出來），無法判斷哪些帳號久未登入');
        }
        $covered = max(0, intdiv($this->now - $oldestTs, 86400));
        $window  = min(self::DORMANT_DAYS, max(1, $covered));

        $map = [];
        foreach ($lastLogins as $name => $at) {
            $map[mb_strtolower((string) $name)] = $at;       // authlog 的帳號是登入時打的，大小寫可能和 users 不同
        }
        $items = [];
        foreach ($active as $u) {
            $at = $map[mb_strtolower($u['username'])] ?? null;
            $ts = $at === null ? null : Time::parse($at, $this->tz);
            if ($ts === null || $this->now - $ts > $window * 86400) {
                $items[] = ['username' => $u['username'], 'last_login' => $ts === null ? '—' : Time::toTaipei($at, 'Y-m-d H:i:s', $this->tz), '_ts' => $ts ?? 0];
            }
        }
        usort($items, static fn ($a, $b) => [$a['_ts'], $a['username']] <=> [$b['_ts'], $b['username']]);   // 從沒登入的在最前面
        $count = count($items);
        $items = array_map(static fn ($i) => ['username' => $i['username'], 'last_login' => $i['last_login']], array_slice($items, 0, self::DORMANT_LIMIT));
        return ['ok' => true, 'data' => [
            'window_days'  => $window,
            'covered_days' => $covered,
            'oldest'       => Time::toTaipei($oldest, 'Y-m-d H:i:s', $this->tz),
            'count'        => $count,
            'items'        => $items,
            'capped'       => $count > self::DORMANT_LIMIT,
        ]];
    }

    /**
     * @param array{last_date:?string,owner:?string,period_days:int,recorded:bool,invalid:bool}|null $review
     * @return array<string,mixed>
     */
    private function review(?array $review): array
    {
        if ($review === null) {
            return $this->fail('帳號盤點的紀錄無法讀取');
        }
        $days  = null;
        $state = 'none';
        if ($review['recorded'] && $review['last_date'] !== null) {
            $today = (new \DateTimeImmutable('@' . $this->now))->setTimezone(new \DateTimeZone('Asia/Taipei'))->setTime(0, 0);
            $last  = new \DateTimeImmutable($review['last_date'] . ' 00:00:00', new \DateTimeZone('Asia/Taipei'));
            $days  = max(0, (int) $last->diff($today)->format('%a'));
            $state = $days > $review['period_days'] ? 'overdue' : 'ok';
        }
        return ['ok' => true, 'data' => $review + ['days_since' => $days, 'state' => $state]];
    }

    /**
     * @param array<int,string[]> $roles
     * @return string[] 沒有任何角色時是 [NO_ROLE]
     */
    private function rolesOf(int $id, array $roles): array
    {
        return $roles[$id] ?? [] ?: [self::NO_ROLE];
    }

    /** @return array{ok:false,error:string} */
    private function fail(string $why): array
    {
        return ['ok' => false, 'error' => $why];
    }
}
