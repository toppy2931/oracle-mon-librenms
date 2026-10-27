<?php
declare(strict_types=1);

namespace Ioc;

use PDO;

/**
 * 3D 連線視圖（tools/behavior-viz/behavior-3d.html）用的資料匯出。**唯讀**：整個匯出包在 READ ONLY 交易裡，只有 SELECT。
 *
 * 格式 `ioc-behavior-viz/1`：每個資產一份固定格數（hours × 12 個五分鐘區間）的時間序列，缺的格子明確標 missing（不是零）；
 * 目的地只記錄「某區間有沒有連到」（資料表就是這樣存的，沒有目的地與埠的配對，也沒有地理資料）。
 *
 * 預設遮罩（mask）：位址末段改 x、目的地併成 /24（IPv6 是 /48）、主機名改「主機 A／B」、內部編號改流水號、站點座標只留一位小數；
 * 不遮罩要明確要求。遮罩只是降低外流風險，不是匿名化：站點名稱、時間與流量形狀仍在。
 */
final class BehaviorVizExport
{
    public const SCHEMA = 'ioc-behavior-viz/1';
    public const MAX_HOURS = 168;
    public const MAX_ASSETS = 50;
    private const QUALITIES = ['complete', 'partial', 'missing'];
    private PDO $db;
    /** @var array<int,array{label:string,id:int,name:string}> 遮罩時的代號對照（只給呼叫端印在終端機，不進匯出的 JSON） */
    private array $mapping = [];

    public function __construct(PDO $db) { $this->db = $db; }

    /** @return array<int,array{label:string,id:int,name:string}> */
    public function mapping(): array { return $this->mapping; }

    /**
     * @param array{hours?:int,assets?:int[],top?:int,max_assets?:int,mask?:bool,now?:int} $opts
     * @return array<string,mixed>
     */
    public function export(array $opts, ?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以匯出連線行為資料');
        $o = self::normalizeOptions($opts);
        $end = intdiv($o['now'], 300) * 300;           // 現在這一格還沒結束，不算
        $slots = $o['hours'] * 12;
        $since = $end - $slots * 300;
        $this->mapping = [];
        $this->db->exec('START TRANSACTION READ ONLY');
        try {
            $assets = [];
            foreach ($this->assetRows($o['assets'], $o['max_assets']) as $n => $row) {
                $assets[] = $this->exportAsset($row, $n, $since, $slots, $o);
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            try { $this->db->exec('ROLLBACK'); } catch (\Throwable $ignored) { /* 連線已斷就算了 */ }
            throw $e;
        }
        return ['schema' => self::SCHEMA, 'demo' => false, 'masked' => $o['mask'], 'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $o['now']),
            'window_minutes' => 5, 'hours' => $o['hours'], 'assets' => $assets];
    }

    // ---- 選項與純函式（有單元測試） ----

    /**
     * @param array<string,mixed> $o
     * @return array{hours:int,assets:int[],top:int,max_assets:int,mask:bool,now:int}
     */
    public static function normalizeOptions(array $o): array
    {
        $int = static function (string $key, int $def, int $lo, int $hi, string $what) use ($o): int {
            $v = $o[$key] ?? $def;
            if (!is_int($v) || $v < $lo || $v > $hi) { throw new ValidationError([$what . '要是 ' . $lo . ' 到 ' . $hi . ' 的整數']); }
            return $v;
        };
        $ids = $o['assets'] ?? [];
        if (!is_array($ids) || count($ids) > self::MAX_ASSETS) { throw new ValidationError(['主機編號最多 ' . self::MAX_ASSETS . ' 個']); }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 2147483647) { throw new ValidationError(['主機編號必須是正整數']); }
        }
        $now = $o['now'] ?? time();
        if (!is_int($now) || $now < 0) { throw new ValidationError(['時間不正確']); }
        return ['hours' => $int('hours', 24, 1, self::MAX_HOURS, '時數'), 'assets' => array_values(array_unique($ids)), 'top' => $int('top', 8, 1, 40, '目的地數量'),
            'max_assets' => $int('max_assets', 12, 1, self::MAX_ASSETS, '主機數上限'), 'mask' => ($o['mask'] ?? true) !== false, 'now' => $now];
    }

    /** 主機位址的顯示：遮罩時末段（IPv6 是第四組之後）改 x；不是合法位址就是「—」。 */
    public static function address(string $ip, bool $mask): string
    {
        $bin = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : false;
        if ($bin === false) { return '—'; }
        if (!$mask) { return (string) inet_ntop($bin); }
        if (strlen($bin) === 4) { return implode('.', array_slice(array_map('ord', str_split($bin)), 0, 3)) . '.x'; }
        $g = str_split(bin2hex($bin), 4);
        return implode(':', array_slice($g, 0, 3)) . ':x';
    }

    /** 目的地的分組鍵（也是畫面上的名稱）：遮罩時 IPv4 併成 /24、IPv6 併成 /48；不是合法位址回 null（直接略過）。 */
    public static function destination(string $ip, bool $mask): ?string
    {
        $bin = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : false;
        if ($bin === false) { return null; }
        if (!$mask) { return (string) inet_ntop($bin); }
        if (strlen($bin) === 4) { return implode('.', array_slice(array_map('ord', str_split($bin)), 0, 3)) . '.0/24'; }
        return implode(':', array_slice(str_split(bin2hex($bin), 4), 0, 3)) . '::/48';
    }

    /** 0→A、25→Z、26→AA …（遮罩時的主機代號）。 */
    public static function letters(int $n): string
    {
        $s = '';
        do { $s = chr(65 + $n % 26) . $s; $n = intdiv($n, 26) - 1; } while ($n >= 0);
        return $s;
    }

    /** @param array<int,int|float> $v */
    public static function median(array $v): float
    {
        if (!$v) { return 0.0; }
        sort($v, SORT_NUMERIC);
        $n = count($v); $m = intdiv($n, 2);
        return (float) ($n % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2);
    }

    // ---- 資料庫讀取（全是 SELECT） ----

    /** @param int[] $ids @return array<int,array<string,mixed>> */
    private function assetRows(array $ids, int $limit): array
    {
        $where = "a.mode<>'retired'";
        $order = '(a.last_seen_at IS NULL),a.last_seen_at DESC,a.id';
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $where .= ' AND a.id IN (' . $marks . ')';
            $order = 'FIELD(a.id,' . $marks . ')';
        }
        $q = $this->db->prepare('SELECT a.id,a.identity_key,a.device_id,a.name,a.site_name,a.mode,a.baseline_version,a.confirmed_at,a.last_seen_at,
            (SELECT x.address FROM ioc_behavior_addresses x WHERE x.asset_id=a.id ORDER BY x.last_seen_at DESC,x.id DESC LIMIT 1) address
            FROM ioc_behavior_assets a WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit);
        $q->execute(array_merge($ids, $ids));
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $a
     * @param array{top:int,mask:bool} $o
     * @return array<string,mixed>
     */
    private function exportAsset(array $a, int $n, int $since, int $slots, array $o): array
    {
        $id = (int) $a['id'];
        $iso = static fn (int $slot): string => gmdate('Y-m-d\TH:i:s\Z', $since + $slot * 300);
        $windows = [];
        for ($i = 0; $i < $slots; $i++) { $windows[$i] = ['t' => $iso($i), 'conn' => 0, 'bytes' => 0, 'dest_n' => 0, 'port_n' => 0, 'q' => 'missing']; }
        $q = $this->db->prepare('SELECT window_start,connections,bytes_total,destinations,ports,destinations_json,ports_json,quality FROM ioc_behavior_windows
            WHERE asset_id=? AND source_key=? AND window_start>=? AND window_start<? ORDER BY window_start');
        $q->execute([$id, BehaviorService::SOURCE, gmdate('Y-m-d H:i:s', $since), gmdate('Y-m-d H:i:s', $since + $slots * 300)]);
        $groups = []; $portCount = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $slot = intdiv((int) strtotime($r['window_start'] . ' UTC') - $since, 300);
            if ($slot < 0 || $slot >= $slots) { continue; }
            $quality = in_array($r['quality'], self::QUALITIES, true) ? $r['quality'] : 'missing';
            if ($quality === 'missing') { continue; }       // 缺資料的格子不帶任何數字、也沒有目的地可說（不能當成沒有連線）；保留上面預設的 missing
            $windows[$slot] = ['t' => $iso($slot), 'conn' => (int) $r['connections'], 'bytes' => (int) $r['bytes_total'], 'dest_n' => (int) $r['destinations'],
                'port_n' => (int) $r['ports'], 'q' => $quality];
            foreach ((array) json_decode((string) $r['destinations_json'], true) as $ip) {
                $key = is_string($ip) ? self::destination($ip, $o['mask']) : null;
                if ($key !== null) { $groups[$key]['w'][$slot] = true; $groups[$key]['ips'][(string) $ip] = true; }
            }
            foreach ((array) json_decode((string) $r['ports_json'], true) as $p) {
                if (is_int($p) || (is_string($p) && ctype_digit($p))) { $p = (int) $p; if ($p >= 0 && $p <= 65535) { $portCount[$p] = ($portCount[$p] ?? 0) + 1; } }
            }
        }
        $known = $this->knownDestinations($id);
        $dest = [];
        foreach ($groups as $key => $g) {
            $w = array_keys($g['w']); sort($w);
            $first = null;
            foreach (array_keys($g['ips']) as $ip) {
                if (isset($known[$ip]) && ($first === null || $known[$ip] < $first)) { $first = $known[$ip]; }
            }
            $dest[] = ['ip' => $key, 'first_seen' => $first !== null ? gmdate('Y-m-d\TH:i:s\Z', $first) : $iso($w[0]), 'last_seen' => $iso(end($w)),
                'seen_windows' => count($w), 'w' => $w, 'new' => $first !== null && $first >= $since];
        }
        usort($dest, static fn (array $x, array $y): int => [$y['seen_windows'], $x['ip']] <=> [$x['seen_windows'], $y['ip']]);
        $total = count($dest);
        $ports = [];
        arsort($portCount);
        $sum = array_sum($portCount);
        foreach (array_slice($portCount, 0, 12, true) as $port => $c) { $ports[] = ['port' => (int) $port, 'share' => round($c / $sum, 4)]; }
        $alerts = [];
        $q = $this->db->prepare('SELECT detector,severity,window_start,summary FROM ioc_behavior_alerts WHERE asset_id=? AND window_start>=? AND window_start<? ORDER BY window_start,id LIMIT 100');
        $q->execute([$id, gmdate('Y-m-d H:i:s', $since), gmdate('Y-m-d H:i:s', $since + $slots * 300)]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $alerts[] = ['t' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($r['window_start'] . ' UTC')), 'detector' => (string) $r['detector'], 'severity' => (string) $r['severity'], 'summary' => (string) $r['summary']];
        }
        $schedule = (new BehaviorService($this->db))->scheduleFor($a);
        $label = $a['name'];
        $outId = $id;
        if ($o['mask']) {
            $label = '主機 ' . self::letters($n); $outId = $n + 1;
            $this->mapping[] = ['label' => $label, 'id' => $id, 'name' => (string) $a['name']];
        }
        return ['id' => $outId, 'label' => (string) $label, 'address' => $a['address'] === null ? '' : self::address((string) $a['address'], $o['mask']), 'site' => $a['site_name'],
            'geo' => $this->geo($a['device_id'] === null ? null : (int) $a['device_id'], $o['mask']),
            'kind' => $a['device_id'] !== null ? 'device' : ($a['confirmed_at'] !== null ? 'confirmed' : 'observed'), 'mode' => $a['mode'] === 'monitoring' ? 'monitoring' : 'learning',
            'baseline_version' => (int) $a['baseline_version'],
            'schedule' => ['timezone' => $schedule['timezone'], 'workdays' => $schedule['workdays'], 'start' => substr($schedule['start_time'], 0, 5), 'end' => substr($schedule['end_time'], 0, 5)],
            'baseline' => $this->baseline($id, (int) $a['baseline_version'], $schedule),
            'ports' => $ports, 'windows' => $windows, 'destinations' => array_slice($dest, 0, $o['top']), 'destinations_total' => $total, 'alerts' => $alerts];
    }

    /** @return array<string,int> 目的 IP → 第一次看到的時間（Unix 秒）；只有完整區間會登錄成「已知」。 */
    private function knownDestinations(int $assetId): array
    {
        $q = $this->db->prepare("SELECT value_key,first_seen_at FROM ioc_behavior_known WHERE asset_id=? AND source_key=? AND kind='destination'");
        $q->execute([$assetId, BehaviorService::SOURCE]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(string) $r['value_key']] = (int) strtotime($r['first_seen_at'] . ' UTC'); }
        return $out;
    }

    /**
     * 基線的代表值：離峰時段桶（週末，或小時落在上班時段之外）、樣本數夠用的各桶 P95 的中位數。沒有基線或沒有夠用的離峰桶就是 null（畫面不畫基線）。
     *
     * @param array{start_time:string,end_time:string} $schedule
     * @return array{p95_conn:float,p95_bytes:float}|null
     */
    private function baseline(int $assetId, int $version, array $schedule): ?array
    {
        if ($version < 1) { return null; }
        $q = $this->db->prepare('SELECT day_kind,hour_of_day,p95_connections,p95_bytes,sample_count FROM ioc_behavior_baselines WHERE asset_id=? AND version=? AND source_key=?');
        $q->execute([$assetId, $version, BehaviorService::SOURCE]);
        $start = (int) substr($schedule['start_time'], 0, 2);
        $end = (int) substr($schedule['end_time'], 0, 2) + (substr($schedule['end_time'], 3, 2) === '00' ? 0 : 1);
        $conn = []; $bytes = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $hour = (int) $r['hour_of_day'];
            if ((int) $r['sample_count'] < BehaviorAnalyzer::BASELINE_MIN_SAMPLES) { continue; }
            if ($r['day_kind'] === 'weekend' || $hour < $start || $hour >= $end) { $conn[] = (float) $r['p95_connections']; $bytes[] = (float) $r['p95_bytes']; }
        }
        return $conn ? ['p95_conn' => round(self::median($conn), 1), 'p95_bytes' => round(self::median($bytes), 1)] : null;
    }

    /** @return array{lat:float,lng:float}|null LibreNMS 設備的位置座標（0,0 視為未設定）；LibreNMS 的資料表不存在或讀不到就是 null。 */
    private function geo(?int $deviceId, bool $mask): ?array
    {
        if ($deviceId === null) { return null; }
        try {
            $q = $this->db->prepare('SELECT l.lat,l.lng FROM devices d LEFT JOIN locations l ON l.id=d.location_id WHERE d.device_id=?');
            $q->execute([$deviceId]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null;
        }
        if (!$r || $r['lat'] === null || $r['lng'] === null) { return null; }
        $lat = (float) $r['lat']; $lng = (float) $r['lng'];
        if (($lat == 0.0 && $lng == 0.0) || abs($lat) > 90 || abs($lng) > 180) { return null; }
        return $mask ? ['lat' => round($lat, 1), 'lng' => round($lng, 1)] : ['lat' => $lat, 'lng' => $lng];
    }
}
