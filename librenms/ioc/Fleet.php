<?php
declare(strict_types=1);

namespace Ioc;

/**
 * LibreNMS 設備清單的彙整：資產數、站點、可用率。
 *
 * 「站點」決定的順序：1) 設備的 IP 落在設定頁自訂站點（Sites）的網段裡 → 該站點（重疊取最長前綴）；
 * 2) 否則是 LibreNMS 設備的「位置」（Location，設備設定裡指定；與設定好的站點同名就合併，不分大小寫）。
 * 兩者都沒有的設備不屬於任何站點，另外計數並在畫面提醒，不能假裝成某個站點（缺資料就說缺，OPS-006）。
 * 沒設定任何站點時（$sites 是 null 或空的），行為與只有 LibreNMS 位置時完全一樣。
 * 站點的異常等級只看設備：該站點離線設備的比例（0＝正常，<25%＝輕微，<50%＝中度，其餘＝嚴重）。
 */
final class Fleet
{
    public const LEVELS = [
        'normal'   => '正常',
        'minor'    => '輕微異常',
        'moderate' => '中度異常',
        'severe'   => '嚴重異常',
    ];
    /** 等級由輕到重的順序，排序用。 */
    public const LEVEL_RANK = ['normal' => 0, 'minor' => 1, 'moderate' => 2, 'severe' => 3];

    public const MINOR_BELOW    = 0.25;
    public const MODERATE_BELOW = 0.5;

    public const GROUP_LABEL = ['server' => '伺服器', 'network' => '網路設備', 'other' => '其他'];
    /** LibreNMS devices.type 之中算「網路設備」的值。 */
    private const NETWORK_TYPES = ['network', 'firewall', 'wireless', 'loadbalancer'];

    /**
     * 設備自己用 SNMP 回報的預設／範本位置（不分大小寫）。LibreNMS 會原樣建成「位置」，但這些不是人設定的站點
     * （monitor-vm 實測：Unknown、End User Premise.、Rack, Room, Building, City, Country [Lat, Lon]），
     * 當成站點會變成一堆名字很怪的假站點，所以視為「沒有指定位置」。
     */
    private const PLACEHOLDER_LOCATIONS = ['unknown', 'end user premise', 'end user premise.', 'rack, room, building, city, country [lat, lon]'];
    /** net-snmp 的預設是「Unknown (edit /etc/snmp/snmpd.local.conf)」。 */
    private const PLACEHOLDER_PREFIXES = ['unknown ('];

    /** @var array<int,array<string,mixed>> */
    private array $devices = [];
    /** @var array<int,array<string,mixed>>|null */
    private ?array $sitesCache = null;
    private Sites $sites;

    /**
     * @param array<int,array<string,mixed>> $rows 每台設備：name、aliases（hostname、sysName…，用來對應事件案的主機）、
     *        type、status（1＝正常）、reason、last_polled、location（沒有則 null 或空字串）、lat、lng、avail_1d、avail_7d、
     *        ip（IPv4 字串，算不出來是 null；見 deviceIp()）
     * @param string $sourceTz last_polled 這類沒帶時區的時間屬於哪個時區（LibreNMS 是主機本地時間）
     * @param Sites|null $sites 設定頁自訂的站點；null＝沒有（只用 LibreNMS 位置）
     */
    public function __construct(array $rows, string $sourceTz = 'UTC', ?Sites $sites = null)
    {
        $this->sites = $sites ?? new Sites();
        foreach ($rows as $r) {
            $lat = isset($r['lat']) && is_numeric($r['lat']) ? (float) $r['lat'] : null;
            $lng = isset($r['lng']) && is_numeric($r['lng']) ? (float) $r['lng'] : null;
            if ($lat === 0.0 && $lng === 0.0) {         // 0,0 是「沒設定」，不是真的在幾內亞灣
                $lat = $lng = null;
            }
            $loc = self::realLocation((string) ($r['location'] ?? ''));
            $ip  = self::ipv4($r['ip'] ?? null);
            // 網段優先；沒符合才用 LibreNMS 的位置，位置與設定好的站點同名時用設定的寫法（兩邊才會合併成同一個站點）
            $site = ($ip === null ? null : $this->sites->siteOf($ip)) ?? ($loc === null ? null : ($this->sites->canonicalName($loc) ?? $loc));
            $this->devices[] = [
                'site'        => $site,
                'name'        => (string) ($r['name'] ?? ''),
                'aliases'     => array_values(array_filter(array_map(static fn ($a) => strtolower(trim((string) $a)), (array) ($r['aliases'] ?? [])))),
                'group'       => self::group((string) ($r['type'] ?? '')),
                'up'          => (int) ($r['status'] ?? 0) === 1,
                'reason'      => (string) ($r['reason'] ?? ''),
                'last_polled' => Time::toTaipei(isset($r['last_polled']) ? (string) $r['last_polled'] : null, 'Y-m-d H:i:s', $sourceTz),
                'lat'         => $lat,
                'lng'         => $lng,
                'avail_1d'    => isset($r['avail_1d']) && is_numeric($r['avail_1d']) ? (float) $r['avail_1d'] : null,
                'avail_7d'    => isset($r['avail_7d']) && is_numeric($r['avail_7d']) ? (float) $r['avail_7d'] : null,
            ];
        }
    }

    /** 設備 IP 當成 IPv4 字串；不是合法的 IPv4（含 IPv6、空字串、null）回傳 null。 */
    private static function ipv4($ip): ?string
    {
        $s = is_string($ip) ? trim($ip) : '';
        return $s !== '' && filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $s : null;
    }

    /**
     * LibreNMS 設備的 IPv4 位址，依序：使用者指定的 overwrite_ip → devices.ip（resolver 解析的結果，資料庫存的是
     * inet_pton 的二進位，用查詢建構器讀到的是原始位元組：4 位元組是 IPv4，16 位元組是 IPv6 略過，因為站點網段只認 IPv4）→
     * hostname 本身若是 IPv4。都沒有（IPv6、沒解析過、hostname 是網域名稱）回傳 null，這台設備就只能靠 LibreNMS 位置歸站點。
     *
     * @param mixed $packedIp devices.ip 的原始值
     */
    public static function deviceIp(?string $overwriteIp, $packedIp, ?string $hostname): ?string
    {
        $fromPacked = is_string($packedIp) && strlen($packedIp) === 4 ? inet_ntop($packedIp) : null;
        foreach ([$overwriteIp, $fromPacked === false ? null : $fromPacked, $hostname] as $candidate) {
            $ip = self::ipv4($candidate);
            if ($ip !== null) {
                return $ip;
            }
        }
        return null;
    }

    /** 有效的站點名稱；空白或 SNMP 預設值回傳 null（視為沒有指定位置）。 */
    public static function realLocation(string $location): ?string
    {
        $loc = trim($location);
        $key = strtolower($loc);
        if ($key === '' || in_array($key, self::PLACEHOLDER_LOCATIONS, true)) {
            return null;
        }
        foreach (self::PLACEHOLDER_PREFIXES as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return null;
            }
        }
        return $loc;
    }

    public static function group(string $type): string
    {
        $t = strtolower(trim($type));
        if ($t === 'server') {
            return 'server';
        }
        return in_array($t, self::NETWORK_TYPES, true) ? 'network' : 'other';
    }

    public static function level(int $down, int $total): string
    {
        if ($total <= 0 || $down <= 0) {
            return 'normal';
        }
        $ratio = $down / $total;
        if ($ratio < self::MINOR_BELOW) {
            return 'minor';
        }
        return $ratio < self::MODERATE_BELOW ? 'moderate' : 'severe';
    }

    public function total(): int
    {
        return count($this->devices);
    }

    public function up(): int
    {
        return count(array_filter($this->devices, static fn ($d) => $d['up']));
    }

    /** @return array{server:int,network:int,other:int} */
    public function byGroup(): array
    {
        $out = ['server' => 0, 'network' => 0, 'other' => 0];
        foreach ($this->devices as $d) {
            $out[$d['group']]++;
        }
        return $out;
    }

    /** 舊版總覽用的形狀（OverviewService::build 的第一個參數）。 */
    public function legacy(int $limit = 20): array
    {
        $down = [];
        foreach ($this->devices as $d) {
            if (!$d['up'] && count($down) < $limit) {
                $down[] = ['name' => $d['name'], 'reason' => $d['reason'], 'last_polled' => $d['last_polled']];
            }
        }
        return ['total' => $this->total(), 'up' => $this->up(), 'down_list' => $down];
    }

    /** 沒有歸屬站點的設備台數（IP 不在任何站點的網段內，也沒有有效的 LibreNMS 位置）。 */
    public function unlocated(): int
    {
        return count(array_filter($this->devices, static fn ($d) => $d['site'] === null));
    }

    /** 設定頁有沒有自訂站點（畫面的說明文字要跟著換：有自訂站點時，「站點」不再只是 LibreNMS 的位置）。 */
    public function hasConfiguredSites(): bool
    {
        return !$this->sites->isEmpty();
    }

    /**
     * 設定好、卻沒有任何設備歸進去的站點名稱（網段可能填錯，或那個網段裡沒有被監控的設備）。
     *
     * @return string[]
     */
    public function emptyConfiguredSites(): array
    {
        $used = [];
        foreach ($this->devices as $d) {
            if ($d['site'] !== null) {
                $used[$d['site']] = true;
            }
        }
        $out = [];
        foreach ($this->sites->all() as $s) {
            if (!isset($used[$s['name']])) {
                $out[] = $s['name'];
            }
        }
        sort($out);
        return $out;
    }

    /**
     * 站點（有歸屬的設備，依站點名稱分組；沒有設備歸進去的站點不列），依名稱排序。
     *
     * @return array<int,array{name:string,devices:int,up:int,down:int,level:string,level_label:string,lat:?float,lng:?float,issues:string}>
     */
    public function sites(): array
    {
        // 設備清單建立後不會變，算一次就好（KPI、計數、排行、站點區塊每個請求會呼叫好幾次）
        return $this->sitesCache ??= $this->computeSites();
    }

    /** @return array<int,array<string,mixed>> */
    private function computeSites(): array
    {
        $by = [];
        foreach ($this->devices as $d) {
            if ($d['site'] === null) {
                continue;
            }
            $s = &$by[$d['site']];
            $s ??= ['name' => $d['site'], 'devices' => 0, 'up' => 0, 'lat' => null, 'lng' => null];
            $s['devices']++;
            $s['up'] += $d['up'] ? 1 : 0;
            $s['lat'] ??= $d['lat'];
            $s['lng'] ??= $d['lng'];
            unset($s);
        }
        $out = [];
        foreach ($by as $s) {
            $down  = $s['devices'] - $s['up'];
            $level = self::level($down, $s['devices']);
            $out[] = $s + [
                'down'        => $down,
                'level'       => $level,
                'level_label' => self::LEVELS[$level],
                'issues'      => $down === 0 ? '運作正常' : sprintf('%d 項異常', $down),
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /** @return array{normal:int,minor:int,moderate:int,severe:int} 各等級有幾個站點 */
    public function levelCounts(): array
    {
        $out = array_fill_keys(array_keys(self::LEVELS), 0);
        foreach ($this->sites() as $s) {
            $out[$s['level']]++;
        }
        return $out;
    }

    /**
     * 站點健康度排行：最糟的在前面（等級、離線台數、名稱）。
     *
     * @return array<int,array<string,mixed>>
     */
    public function ranking(int $limit = 5): array
    {
        $sites = $this->sites();
        usort($sites, static function ($a, $b) {
            return [self::LEVEL_RANK[$b['level']], $b['down'], $a['name']] <=> [self::LEVEL_RANK[$a['level']], $a['down'], $b['name']];
        });
        $out = [];
        foreach (array_slice($sites, 0, $limit) as $i => $s) {
            $out[] = ['rank' => $i + 1] + $s;
        }
        return $out;
    }

    /**
     * 設備平均可用率（%，兩位小數）。只算有資料的設備；沒有任何資料回傳 null（不是 0%）。
     *
     * @return array{d1:?float,d7:?float,n1:int,n7:int}
     */
    public function availability(): array
    {
        $mean = static function (array $vals): ?float {
            return $vals === [] ? null : round(array_sum($vals) / count($vals), 2);
        };
        $d1 = array_values(array_filter(array_column($this->devices, 'avail_1d'), static fn ($v) => $v !== null));
        $d7 = array_values(array_filter(array_column($this->devices, 'avail_7d'), static fn ($v) => $v !== null));
        return ['d1' => $mean($d1), 'd7' => $mean($d7), 'n1' => count($d1), 'n7' => count($d7)];
    }

    /** 事件案的主機名稱（設備名稱、hostname、sysName 任一個，不分大小寫）對應到的站點；找不到回傳 null。 */
    public function siteOfHost(string $host): ?string
    {
        $h = strtolower(trim($host));
        if ($h === '') {
            return null;
        }
        foreach ($this->devices as $d) {
            if ($d['site'] !== null && ($h === strtolower($d['name']) || in_array($h, $d['aliases'], true))) {
                return $d['site'];
            }
        }
        return null;
    }
}
