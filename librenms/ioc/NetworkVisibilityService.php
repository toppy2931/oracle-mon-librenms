<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 內網可視性（簡報第 6 張）：用防火牆日誌看內部網路裡的流量。只讀 Graylog，沒有任何寫入。
 *
 *  - 東西向流量：內部對內部（兩端都在「內部網段」）的位元組數占經過防火牆的轉送流量的比例，加近 24 小時每 4 小時一格的趨勢，
 *    與前一個 24 小時比較。
 *  - Top Talkers：內部來源依位元組數排名（前 5 名）。
 *  - 異常連線與異常 SMB：沒有基線可比，只能用規則。規則（提案版，常數在下面，要調就改常數與測試）：
 *      橫向探測：同一個內部來源、同一個目的埠，在 24 小時內連到 ≥ PROBE_MIN 個不同的內部主機；
 *      被擋下：內部對內部的「轉送流量」（cat 是 traffic:forward）被防火牆擋下（已經算橫向探測的同一來源同一埠不重複列）；
 *        FortiGate 自己收發的 traffic:local（連到防火牆本身、送到網段廣播位址的封包）不算，見 deniedForward()。
 *    異常 SMB ＝ 目的埠 445 的異常；數量是「被標記的內部來源數」，並與前一個 24 小時比較。
 *  - IDS 告警：FortiGate 的 IPS／異常偵測日誌（cat 是 utm:ips、utm:anomaly）。沒有收到就明講「尚未接入」，不放數字。
 *
 * 鐵則同總覽（OPS-006）：資料取不到、沒有防火牆日誌、日誌沒有位元組欄位，都明確寫原因（['ok'=>false,'error'=>…]），
 * 不能變成 0 或「沒有異常」。每個區塊各自成敗。範例資料（ioc_demo=true）預設排除，與總覽一致（IS-054～056）。
 *
 * 限制（畫面也會寫）：只看得到「經過防火牆」的流量；同一個網段內、不經過防火牆的流量看不到（那需要旁路感測器）。
 * 日誌欄位來自這台既有的 FortiGate pipeline（fortigate_normalize 與 fortigate-extract-flow-fields）：
 * cat、dstport 來自那條 pipeline；位元組數是 IOC 自己的防火牆 pipeline 寫的 net_bytes（送出＋收到，規則 ioc-fw-bytes，
 * 從 CEF 的 out／in 取）——既有 pipeline 的 network_bytes 讀的是 sentbyte／rcvdbyte，這台 FortiGate 的 CEF 沒有，全是 0。
 * 換設備廠牌時改下面的 F_* 常數。net_bytes 只有 pipeline 套用之後進來的日誌才有，之前的日誌補不回來。
 */
final class NetworkVisibilityService
{
    public const RANGE         = 86400;   // 最近 24 小時
    public const BUCKET        = 14400;   // 趨勢每一格 4 小時
    public const BUCKETS       = 6;       // 6 格剛好 24 小時
    public const GROUP_LIMIT   = 100;     // 分組查詢每一層的上限；碰到上限要標示「結果可能不完整」
    public const TALKER_LIMIT  = 200;
    public const TALKERS       = 5;
    public const LIST_LIMIT    = 20;
    public const DST_SAMPLE    = 3;       // 異常連線清單每一列最多列出幾個目的 IP（其餘寫「等 N 個」）
    public const PROBE_MIN     = 10;      // 連到這麼多個不同的內部主機才算橫向探測
    public const PROBE_MEDIUM  = 25;
    public const PROBE_HIGH    = 50;
    public const BLOCKED_MEDIUM = 100;    // 被擋下的內部連線累積到這麼多筆算中風險，否則低
    public const SMB_PORT      = 445;
    /** 廣播與探索類的埠（DHCP、NetBIOS 名稱／資料報、SSDP、mDNS、LLMNR）：區網裡天天有，不算異常。 */
    public const NOISE_PORTS   = [67, 68, 137, 138, 1900, 5353, 5355];
    public const PORT_NAME     = [22 => 'SSH', 23 => 'Telnet', 135 => 'RPC', 139 => 'NetBIOS', 445 => 'SMB', 3389 => 'RDP', 5985 => 'WinRM', 5986 => 'WinRM'];

    private const F_BYTES   = 'net_bytes';
    private const F_PORT    = 'dstport';
    private const F_CAT     = 'cat';
    private const FORWARD   = 'traffic:forward';
    private const NO_BYTES  = '轉送流量的日誌裡沒有位元組數（net_bytes 欄位不存在或全是 0），無法計算流量。這個欄位由 IOC 的防火牆 pipeline（規則 ioc-fw-bytes）從 FortiGate 日誌的 out／in 寫入：請確認更新時 pipeline 有套用成功；剛套用時要等新的日誌進來才有值，之前的日誌補不回來';
    private const NO_PORT   = '內部連線的日誌裡沒有目的埠欄位（dstport），無法判斷連接埠與 SMB；請確認 Graylog 的 FortiGate pipeline 有寫入這個欄位';
    private const LEVEL_RANK = ['high' => 3, 'medium' => 2, 'low' => 1];

    private WindowedLogSource $logs;
    private bool $demo;
    private int $now;

    public function __construct(WindowedLogSource $logs, bool $demo = false, ?int $now = null)
    {
        $this->logs = $logs;
        $this->demo = $demo;
        $this->now  = $now ?? time();
    }

    /**
     * @param string[]|null $nets 內部網段（整理過的 CIDR）；null＝設定讀不到，$netsError 是原因，所有區塊都標「無法取得」
     * @return array<string,mixed>
     */
    public function build(?array $nets, string $netsError, ?string $level): array
    {
        if (!Access::canView($level)) {
            throw new AccessDenied('沒有檢視權限');
        }
        $meta = [
            'generated_at'   => Time::toTaipei(gmdate('Y-m-d H:i:s', $this->now)),
            'range_label'    => '最近 24 小時',
            'previous_label' => '前一個 24 小時',
            'demo'           => $this->demo,
            'nets'           => $nets,
            'rules'          => [
                'probe_min' => self::PROBE_MIN, 'probe_medium' => self::PROBE_MEDIUM, 'probe_high' => self::PROBE_HIGH,
                'blocked_medium' => self::BLOCKED_MEDIUM, 'smb_port' => self::SMB_PORT, 'noise_ports' => self::NOISE_PORTS,
                'bucket_hours' => intdiv(self::BUCKET, 3600), 'talkers' => self::TALKERS,
            ],
        ];
        if ($nets === null) {
            $fail   = ['ok' => false, 'error' => $netsError !== '' ? $netsError : '讀不到內部網段的設定'];
            $blocks = ['source' => $fail, 'talkers' => $fail, 'anomalies' => $fail, 'ids' => $fail, 'trend' => $fail];
        } else {
            $blocks = $this->blocks($nets);
        }
        return ['meta' => $meta] + $blocks + ['kpi' => $this->kpi($blocks)];
    }

    // ── 各區塊 ──────────────────────────────────────────────────────────

    /**
     * 查詢的順序就是優先順序：Graylog 的總時間預算用完後，後面的查詢會回報逾時，所以最花查詢次數的趨勢放最後。
     *
     * @param string[] $nets
     * @return array<string,array<string,mixed>>
     */
    private function blocks(array $nets): array
    {
        $source = $this->guard(fn () => $this->source());
        $gate   = $this->gate($source);
        $flow   = fn (callable $fn): array => $gate !== null ? ['ok' => false, 'error' => $gate] : $this->guard($fn);

        $talkers   = $flow(fn () => $this->talkers($nets));
        $anomalies = $flow(fn () => $this->anomalies($nets));
        $ids       = $source['ok'] ? $this->guard(fn () => $this->ids()) : $source;
        $trend     = $flow(fn () => $this->trend($nets));

        // 來源占總流量的比例要等總流量算出來才知道
        if ($talkers['ok']) {
            $total = $trend['ok'] ? $trend['data']['total'] : 0;
            foreach ($talkers['data']['items'] as $i => $t) {
                // 兩次查詢的時間點差幾秒，來源的量理論上不會超過總量，但不能讓畫面出現「占 103%」
                $talkers['data']['items'][$i]['share'] = $total > 0 ? min(100, (int) round(100 * $t['bytes'] / $total)) : null;
            }
        }
        return ['source' => $source, 'talkers' => $talkers, 'anomalies' => $anomalies, 'ids' => $ids, 'trend' => $trend];
    }

    /** 防火牆日誌收到了多少、其中有多少是「轉送流量」。 */
    private function source(): array
    {
        [$from, $to] = $this->window(0);
        $rows = $this->logs->aggregateBetween($this->q('log_type:firewall'), $from, $to, [self::F_CAT], ['count'], 30);
        $firewall = $forward = 0;
        foreach ($rows as $r) {
            $n = (int) ($r['count'] ?? 0);
            $firewall += $n;
            if ((string) ($r[self::F_CAT] ?? '') === self::FORWARD) {
                $forward += $n;
            }
        }
        return ['firewall' => $firewall, 'forward' => $forward];
    }

    /**
     * 要分析流量的區塊能不能往下做；不能時回傳原因（取代每個區塊各查一次、各得到一個誤導的 0）。
     *
     * @param array<string,mixed> $source
     */
    private function gate(array $source): ?string
    {
        if (!$source['ok']) {
            return $source['error'];
        }
        if ($source['data']['firewall'] === 0) {
            return '最近 24 小時沒有收到防火牆的日誌（還沒接入，或已經中斷了），沒有資料可顯示，不代表沒有流量';
        }
        if ($source['data']['forward'] === 0) {
            return '收到了防火牆日誌，但沒有「轉送流量」（cat 是 ' . self::FORWARD . '）的紀錄：防火牆沒有記錄轉送流量，或日誌欄位和預期不同，無法分析內網流量';
        }
        return null;
    }

    /**
     * 東西向流量趨勢：每 4 小時一格的轉送流量總量與內部對內部的量，加前一個 24 小時的總量。
     *
     * @param string[] $nets
     * @return array<string,mixed>
     */
    private function trend(array $nets): array
    {
        $fwd = $this->fwd();
        $ew  = $fwd . ' AND ' . $this->both($nets);
        $start = $this->now - self::RANGE;
        $buckets = [];
        $total = $east = 0;
        for ($i = 0; $i < self::BUCKETS; $i++) {
            $from = $start + $i * self::BUCKET;
            $t = $this->bytes($fwd, $from, $from + self::BUCKET);
            $e = $this->bytes($ew, $from, $from + self::BUCKET);
            $label = Time::toTaipei(gmdate('Y-m-d H:i:s', $from), 'H:i');
            $share = $t > 0 ? (int) round(100 * $e / $t) : null;
            $hours = intdiv(self::BUCKET, 3600);
            $buckets[] = [
                'label' => $label, 'total' => $t, 'east_west' => $e, 'share' => $share,
                // 0 位元組有兩種可能：那段時間真的沒有轉送流量，或位元組欄位還沒開始寫入（剛套用 pipeline）；這裡分不出來，兩種都寫
                'text'  => $t === 0
                    ? sprintf('%s 起 %d 小時：沒有位元組數資料（沒有轉送流量，或位元組欄位還沒開始寫入）', $label, $hours)
                    : sprintf('%s 起 %d 小時：轉送流量 %s，內部對內部 %s（%s）', $label, $hours, self::humanBytes($t), self::humanBytes($e), $share . '%'),
            ];
            $total += $t;
            $east  += $e;
        }
        if ($total === 0) {
            throw new GraylogUnavailable(self::NO_BYTES);
        }
        // 前一個 24 小時只是拿來比較：讀不到不拖垮整個區塊，畫面寫「沒有資料可比較」
        $previous = null;
        try {
            $pStart = $this->now - 2 * self::RANGE;
            $pt = $this->bytes($fwd, $pStart, $pStart + self::RANGE);
            $pe = $this->bytes($ew, $pStart, $pStart + self::RANGE);
            $previous = ['total' => $pt, 'east_west' => $pe, 'share' => $pt > 0 ? (int) round(100 * $pe / $pt) : null];
        } catch (GraylogUnavailable $ex) {
            $previous = null;
        }
        return [
            'buckets'     => $buckets,
            'bucket_hours' => intdiv(self::BUCKET, 3600),
            'total'       => $total,
            'east_west'   => $east,
            'share'       => (int) round(100 * $east / $total),
            'empty_buckets' => count(array_filter($buckets, static fn ($b) => $b['total'] === 0)),
            'total_label' => self::humanBytes($total),
            'east_label'  => self::humanBytes($east),
            'previous'    => $previous,
        ];
    }

    /**
     * 內部來源依位元組數的前幾名。
     *
     * @param string[] $nets
     * @return array<string,mixed>
     */
    private function talkers(array $nets): array
    {
        [$from, $to] = $this->window(0);
        $q = $this->fwd() . ' AND ' . IpNets::lucene('src_ip', $nets)
           . ' AND NOT dst_ip:"255.255.255.255" AND NOT dst_ip:224.* AND NOT dst_ip:239.*';
        $rows = $this->logs->aggregateBetween($this->q($q), $from, $to, ['src_ip'], ['sum:' . self::F_BYTES, 'count'], self::TALKER_LIMIT);
        $items = [];
        foreach ($rows as $r) {
            $ip = (string) ($r['src_ip'] ?? '');
            if (!IpNets::contains($nets, $ip)) {
                continue;                    // 空白或格式不對的來源不列
            }
            $items[] = ['ip' => $ip, 'bytes' => (int) round((float) ($r['sum_' . self::F_BYTES] ?? 0)), 'sessions' => (int) ($r['count'] ?? 0)];
        }
        if ($items !== [] && max(array_column($items, 'bytes')) === 0) {
            throw new GraylogUnavailable(self::NO_BYTES);
        }
        usort($items, static fn ($a, $b) => $b['bytes'] <=> $a['bytes'] ?: strcmp($a['ip'], $b['ip']));
        $top = [];
        foreach (array_slice($items, 0, self::TALKERS) as $t) {
            $top[] = $t + ['bytes_label' => self::humanBytes($t['bytes']), 'share' => null];
        }
        return ['items' => $top, 'sources' => count($items), 'capped' => count($rows) >= self::TALKER_LIMIT];
    }

    /**
     * 異常連線清單與異常 SMB 的數量。
     *
     * @param string[] $nets
     * @return array<string,mixed>
     */
    private function anomalies(array $nets): array
    {
        [$from, $to] = $this->window(0);
        $both = $this->both($nets);
        $metrics = ['card:dst_ip', 'count', 'latest:timestamp'];
        $probe = $this->logs->aggregateBetween($this->q($this->fwd() . ' AND ' . $both . ' AND ' . $this->quiet()),
            $from, $to, ['src_ip', self::F_PORT], $metrics, self::GROUP_LIMIT);
        $deny = $this->logs->aggregateBetween($this->q($this->deniedForward() . ' AND ' . $both . ' AND ' . $this->quiet()),
            $from, $to, ['src_ip', self::F_PORT], $metrics, self::GROUP_LIMIT);
        // 有資料、但每一列的目的埠都是缺值：日誌根本沒有 dstport 欄位。不能照常算下去：每一筆都會歸到「沒有連接埠」，
        // 445 永遠對不上，異常 SMB 會顯示成綠色的 0（OPS-006）
        if (($probe !== [] || $deny !== []) && !self::hasPortValue($probe) && !self::hasPortValue($deny)) {
            throw new GraylogUnavailable(self::NO_PORT);
        }
        $all = $this->findAnomalies($nets, $probe, $deny);

        usort($all, static fn ($a, $b) => self::LEVEL_RANK[$b['level']] <=> self::LEVEL_RANK[$a['level']]
            ?: $b['count'] <=> $a['count'] ?: strcmp($a['src_ip'], $b['src_ip']));
        $levels = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($all as $a) {
            $levels[$a['level']]++;
        }
        [$items, $dstError] = $this->withDestinations($nets, array_slice($all, 0, self::LIST_LIMIT), $from, $to);
        return [
            'items'     => $items,
            'dst_error' => $dstError,
            'total'     => count($all),
            'levels'    => $levels,
            'capped'    => self::truncated($probe) || self::truncated($deny),
            'smb'       => ['sources' => self::smbSources($all), 'previous' => $this->previousSmb($nets)],
        ];
    }

    /**
     * 清單上的每一列補上目的主機 IP（最多 DST_SAMPLE 個，依連線筆數、再依位址排序）。
     * 前面的分組只知道「連到幾個不同的目的」，所以對實際要列出的來源與埠再查一次：橫向探測類看轉送流量、
     * 被擋下類只看被擋下的連線（兩類各最多一次查詢，沒有該類的列就不查）。
     * 這只是補充資訊：補查失敗（逾時、Graylog 不接受三層分組）時 dst_ips 留空，畫面退回「N 個內部主機」，
     * 不能讓原本能看的清單變成「無法取得」；失敗原因另外回傳，畫面要寫出來（不能默默少了 IP）。
     *
     * @param string[] $nets
     * @param array<int,array<string,mixed>> $items
     * @return array{0:array<int,array<string,mixed>>,1:?string} [補上目的 IP 的清單, 第一個失敗原因（沒有失敗是 null）]
     */
    private function withDestinations(array $nets, array $items, string $from, string $to): array
    {
        $error = null;
        $bases = ['probe' => $this->fwd(), 'blocked' => $this->deniedForward()];
        foreach ($bases as $kind => $base) {
            $rows = array_filter($items, static fn ($a) => $a['kind'] === $kind);
            if ($rows === []) {
                continue;
            }
            // 來源位址已經過 IpNets::contains（嚴格 IPv4）、埠是整數，查詢裡沒有可注入的內容
            $srcs  = array_values(array_unique(array_column($rows, 'src_ip')));
            $ports = array_values(array_unique(array_column($rows, 'port')));
            $q = $base . ' AND ' . $this->both($nets) . ' AND ' . $this->quiet()
               . ' AND src_ip:("' . implode('" OR "', $srcs) . '") AND ' . self::F_PORT . ':(' . implode(' OR ', $ports) . ')';
            try {
                $found = $this->logs->aggregateBetween($this->q($q), $from, $to, ['src_ip', self::F_PORT, 'dst_ip'], ['count'], self::LIST_LIMIT);
            } catch (GraylogUnavailable $e) {
                $error ??= $e->getMessage();
                continue;
            }
            $by = [];
            foreach ($found as $r) {
                $dst = (string) ($r['dst_ip'] ?? '');
                if (!IpNets::contains($nets, $dst)) {
                    continue;                // 空白、(Empty Value)、不在內部網段的目的不列
                }
                $key = (string) ($r['src_ip'] ?? '') . '|' . (int) ($r[self::F_PORT] ?? 0);
                $by[$key][$dst] = ($by[$key][$dst] ?? 0) + (int) ($r['count'] ?? 0);
            }
            foreach (array_keys($rows) as $i) {
                $c = $by[$items[$i]['src_ip'] . '|' . $items[$i]['port']] ?? [];
                uksort($c, static fn ($a, $b) => $c[$b] <=> $c[$a] ?: strcmp($a, $b));
                $items[$i]['dst_ips'] = array_slice(array_keys($c), 0, self::DST_SAMPLE);
            }
        }
        if ($error === null) {
            // 查詢成功卻有幾列一個目的也沒對上（例如那幾筆日誌缺目的埠：主查詢把缺值算成埠 0，但補查的 dstport:0 只比對得到真的寫了 0 的日誌）。
            // 不能默默只顯示數量，讓人以為目的 IP 這個功能對那幾列壞了
            $missing = count(array_filter($items, static fn ($a) => $a['distinct'] > 0 && $a['dst_ips'] === []));
            if ($missing > 0) {
                $error = sprintf('有 %d 列沒有查到目的 IP（例如那幾筆日誌缺目的埠欄位）', $missing);
            }
        }
        return [$items, $error];
    }

    /** 分組結果裡有沒有任何一列的目的埠有值（全都是缺值＝日誌沒有這個欄位）。 @param array<int,array<string,mixed>> $rows */
    private static function hasPortValue(array $rows): bool
    {
        foreach ($rows as $r) {
            if (($r[self::F_PORT] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * 「來源 × 埠」兩層分組是不是被每層的上限截斷了：第一層（來源）滿了，或某個來源的第二層（埠）滿了。
     * 不能只看總列數：40 個來源各 3 個埠有 120 列，但沒有任何一層碰到上限。
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private static function truncated(array $rows): bool
    {
        $perSource = [];
        foreach ($rows as $r) {
            $ip = (string) ($r['src_ip'] ?? '');
            $perSource[$ip] = ($perSource[$ip] ?? 0) + 1;
        }
        return count($perSource) >= self::GROUP_LIMIT || ($perSource !== [] && max($perSource) >= self::GROUP_LIMIT);
    }

    /**
     * 前一個 24 小時被標記的 SMB 來源數（同一套規則，只查目的埠 445）。讀不到回傳 null，只影響「較前期」的比較。
     *
     * @param string[] $nets
     */
    private function previousSmb(array $nets): ?int
    {
        try {
            [$from, $to] = $this->window(1);
            $both = $this->both($nets);
            $port = self::F_PORT . ':' . self::SMB_PORT;
            $metrics = ['card:dst_ip', 'count', 'latest:timestamp'];
            $probe = $this->logs->aggregateBetween($this->q($this->fwd() . ' AND ' . $both . ' AND ' . $port),
                $from, $to, ['src_ip', self::F_PORT], $metrics, self::GROUP_LIMIT);
            $deny = $this->logs->aggregateBetween($this->q($this->deniedForward() . ' AND ' . $both . ' AND ' . $port),
                $from, $to, ['src_ip', self::F_PORT], $metrics, self::GROUP_LIMIT);
            return self::smbSources($this->findAnomalies($nets, $probe, $deny));
        } catch (GraylogUnavailable $e) {
            return null;
        }
    }

    /**
     * 兩份「（來源, 埠）」分組結果 → 異常清單（未排序、未截斷）。
     *
     * @param string[] $nets
     * @param array<int,array<string,mixed>> $probe 轉送流量（含允許與擋下）
     * @param array<int,array<string,mixed>> $deny  被擋下的連線
     * @return array<int,array<string,mixed>>
     */
    private function findAnomalies(array $nets, array $probe, array $deny): array
    {
        $found = [];
        foreach ($probe as $r) {
            $distinct = (int) ($r['card_dst_ip'] ?? 0);
            $ip = (string) ($r['src_ip'] ?? '');
            if ($distinct < self::PROBE_MIN || !IpNets::contains($nets, $ip)) {
                continue;
            }
            $port = (int) ($r[self::F_PORT] ?? 0);
            $found[$ip . '|' . $port] = $this->anomaly('probe', $ip, $port, $distinct, (int) ($r['count'] ?? 0), $r['latest_timestamp'] ?? null,
                $distinct >= self::PROBE_HIGH ? 'high' : ($distinct >= self::PROBE_MEDIUM ? 'medium' : 'low'));
        }
        foreach ($deny as $r) {
            $count = (int) ($r['count'] ?? 0);
            $ip = (string) ($r['src_ip'] ?? '');
            $port = (int) ($r[self::F_PORT] ?? 0);
            if ($count <= 0 || !IpNets::contains($nets, $ip) || isset($found[$ip . '|' . $port])) {
                continue;
            }
            $found[$ip . '|' . $port] = $this->anomaly('blocked', $ip, $port, (int) ($r['card_dst_ip'] ?? 0), $count, $r['latest_timestamp'] ?? null,
                $count >= self::BLOCKED_MEDIUM ? 'medium' : 'low');
        }
        return array_values($found);
    }

    /**
     * @param mixed $latest
     * @return array<string,mixed>
     */
    private function anomaly(string $kind, string $ip, int $port, int $distinct, int $count, $latest, string $level): array
    {
        $on = $port === 0 ? '協定沒有連接埠（例如 ICMP）' : '目的埠 ' . self::portLabel($port);
        $behavior = $kind === 'probe'
            ? sprintf('橫向探測：連到 %d 個不同的內部主機，%s（%s 筆連線紀錄）', $distinct, $on, number_format($count))
            : sprintf('內部對內部的連線被防火牆擋下 %s 次，涉及 %d 個目的主機，%s', number_format($count), $distinct, $on);
        return [
            'time'     => Time::toTaipei($latest === null ? null : (string) $latest, 'm-d H:i:s'),
            'src_ip'   => $ip,
            'port'     => $port,
            'distinct' => $distinct,
            'dst_ips'  => [],                    // 清單要列出的列才會補（withDestinations）
            'count'    => $count,
            'kind'     => $kind,
            'level'    => $level,
            'behavior' => $behavior,
            // 建立事件案時帶進去的證據查詢（ip 已確認是內部 IPv4，port 是整數，沒有可注入的內容）
            'evidence_query' => 'src_ip:"' . $ip . '"' . ($port > 0 ? ' AND ' . self::F_PORT . ':' . $port : '') . ($kind === 'blocked' ? ' AND event_action:fw_deny' : ''),
        ];
    }

    /** @param array<int,array<string,mixed>> $items */
    private static function smbSources(array $items): int
    {
        $ips = [];
        foreach ($items as $a) {
            if ($a['port'] === self::SMB_PORT) {
                $ips[$a['src_ip']] = true;
            }
        }
        return count($ips);
    }

    /**
     * IDS 告警：FortiGate 的 IPS／異常偵測日誌。一筆都沒有時，這裡分不出「沒開 IPS 日誌」還是「真的沒有攻擊」，
     * 所以回報 seen=false，畫面寫「尚未接入」，不能顯示 0。
     *
     * @return array{seen:bool,count:int,previous:?int}
     */
    private function ids(): array
    {
        $q = $this->q('cat:("utm:ips" OR "utm:anomaly")');
        [$from, $to] = $this->window(0);
        $n = $this->countByCat($q, $from, $to);
        if ($n === 0) {
            return ['seen' => false, 'count' => 0, 'previous' => null];
        }
        try {
            [$pFrom, $pTo] = $this->window(1);
            $previous = $this->countByCat($q, $pFrom, $pTo);
        } catch (GraylogUnavailable $e) {
            $previous = null;
        }
        return ['seen' => true, 'count' => $n, 'previous' => $previous];
    }

    private function countByCat(string $query, string $from, string $to): int
    {
        $n = 0;
        foreach ($this->logs->aggregateBetween($query, $from, $to, [self::F_CAT], ['count'], 10) as $r) {
            $n += (int) ($r['count'] ?? 0);
        }
        return $n;
    }

    // ── KPI ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,array<string,mixed>> $b blocks()
     * @return array<int,array<string,mixed>>
     */
    private function kpi(array $b): array
    {
        $unknown = static fn (string $key, string $label, string $why, ?string $na = null): array =>
            ['key' => $key, 'label' => $label, 'value' => null, 'state' => 'unknown', 'sub' => $why, 'na' => $na];

        if (!$b['trend']['ok']) {
            $east = $unknown('eastwest', '東西向流量佔比', $b['trend']['error']);
        } else {
            $d = $b['trend']['data'];
            $p = $d['previous'];
            $cmp = ($p === null || $p['share'] === null)
                ? '前一個 24 小時沒有資料可比較'
                : ($d['share'] === $p['share'] ? '與前一個 24 小時相同' : sprintf('較前一個 24 小時%s %d 個百分點', $d['share'] > $p['share'] ? '高了' : '低了', abs($d['share'] - $p['share'])));
            $part = $d['empty_buckets'] > 0 ? sprintf(' · 只有 %d／%d 格有位元組數資料', self::BUCKETS - $d['empty_buckets'], self::BUCKETS) : '';
            $east = ['key' => 'eastwest', 'label' => '東西向流量佔比', 'value' => $d['share'] . '%', 'state' => 'info',
                'sub' => sprintf('內部對內部 %s ／ 轉送流量共 %s · %s%s', $d['east_label'], $d['total_label'], $cmp, $part), 'na' => null];
        }

        if (!$b['anomalies']['ok']) {
            $smb  = $unknown('smb', '異常 SMB 來源', $b['anomalies']['error']);
            $anom = $unknown('anomalies', '異常連線', $b['anomalies']['error']);
        } else {
            $d = $b['anomalies']['data'];
            $n = $d['smb']['sources'];
            $smb = ['key' => 'smb', 'label' => '異常 SMB 來源', 'value' => $n, 'state' => $n === 0 ? 'good' : ($n < 5 ? 'warn' : 'bad'),
                'sub' => sprintf('目的埠 %d 的內部來源 · %s%s', self::SMB_PORT, self::changeText($n, $d['smb']['previous']), $d['capped'] ? ' · 結果可能不完整' : ''), 'na' => null];
            $lv = $d['levels'];
            $anom = ['key' => 'anomalies', 'label' => '異常連線', 'value' => $d['total'], 'state' => $d['total'] === 0 ? 'good' : ($lv['high'] > 0 ? 'bad' : 'warn'),
                'sub' => sprintf('高 %d · 中 %d · 低 %d（每筆是一個來源加一個目的埠）%s', $lv['high'], $lv['medium'], $lv['low'], $d['capped'] ? ' · 結果可能不完整' : ''), 'na' => null];
        }

        if (!$b['ids']['ok']) {
            $ids = $unknown('ids', 'IDS 告警', $b['ids']['error']);
        } elseif (!$b['ids']['data']['seen']) {
            $ids = $unknown('ids', 'IDS 告警', '最近 24 小時沒有收到 IPS 日誌（FortiGate 沒開 IPS 日誌，或這段期間真的沒有攻擊，這裡分不出來）', '尚未接入');
        } else {
            $d = $b['ids']['data'];
            $ids = ['key' => 'ids', 'label' => 'IDS 告警', 'value' => $d['count'], 'state' => 'warn',
                'sub' => 'FortiGate 的 IPS 與異常偵測日誌 · ' . self::changeText($d['count'], $d['previous']), 'na' => null];
        }
        return [$east, $smb, $anom, $ids];
    }

    /** 與前一個 24 小時比較的文字。前期讀不到是 null。 */
    private static function changeText(int $cur, ?int $prev): string
    {
        if ($prev === null) {
            return '前一個 24 小時的資料無法取得';
        }
        if ($prev === 0) {
            return $cur === 0 ? '前一個 24 小時也是 0' : '前一個 24 小時是 0';
        }
        $pct = (int) round(100 * ($cur - $prev) / $prev);
        return $pct === 0 ? '與前一個 24 小時持平' : sprintf('較前一個 24 小時%s %d%%', $pct > 0 ? '增加' : '減少', abs($pct));
    }

    // ── 工具 ────────────────────────────────────────────────────────────

    /** 轉送流量的基本條件（防火牆日誌、cat 是 traffic:forward）。 */
    private function fwd(): string
    {
        return 'log_type:firewall AND ' . self::F_CAT . ':"' . self::FORWARD . '"';
    }

    /**
     * 被防火牆擋下的「轉送流量」。一定要限定 traffic:forward：FortiGate 自己收發的流量（traffic:local）也是 deny，
     * 例如送到網段廣播位址（172.16.1.255:9900）或連到防火牆本身的封包——那不是內部主機之間的橫向連線，
     * 算進來會是天天出現、沒有人要處理的誤報（monitor-vm 實測：兩端都在內部網段的日誌全是 traffic:local，沒有 forward）。
     */
    private function deniedForward(): string
    {
        return $this->fwd() . ' AND event_action:fw_deny';
    }

    /** 兩端都在內部網段。 @param string[] $nets */
    private function both(array $nets): string
    {
        return IpNets::lucene('src_ip', $nets) . ' AND ' . IpNets::lucene('dst_ip', $nets);
    }

    /** 排除區網裡天天有的廣播與探索類埠。 */
    private function quiet(): string
    {
        return 'NOT ' . self::F_PORT . ':(' . implode(' OR ', self::NOISE_PORTS) . ')';
    }

    private function q(string $query): string
    {
        return OverviewService::demoFilter($query, $this->demo);
    }

    /** 往前數第 $back 個 24 小時（0＝最近 24 小時）的 [起, 迄]，UTC 的 ISO 8601。 */
    private function window(int $back): array
    {
        $to = $this->now - $back * self::RANGE;
        return [$this->iso($to - self::RANGE), $this->iso($to)];
    }

    private function iso(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', $ts);
    }

    /** 一段時間內符合條件的日誌的位元組數總和（沒有日誌就是 0）。 */
    private function bytes(string $query, int $from, int $to): int
    {
        $rows = $this->logs->aggregateBetween($this->q($query), $this->iso($from), $this->iso($to), ['log_type'], ['sum:' . self::F_BYTES], 5);
        $n = 0.0;
        foreach ($rows as $r) {
            $n += (float) ($r['sum_' . self::F_BYTES] ?? 0);
        }
        return (int) round($n);
    }

    private static function portLabel(int $port): string
    {
        return isset(self::PORT_NAME[$port]) ? $port . ' ' . self::PORT_NAME[$port] : (string) $port;
    }

    /** 位元組數轉成好讀的字串（1024 進位，和防火牆介面一致）。 */
    public static function humanBytes(int $n): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $v = (float) max(0, $n);
        $i = 0;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $v : number_format($v, $v >= 100 ? 0 : 1, '.', '')) . ' ' . $units[$i];
    }

    /** 區塊各自成敗的包裝與總覽共用同一份（只攔 GraylogUnavailable，其他例外是 bug，要往上拋）。 @return array{ok:bool,data?:mixed,error?:string} */
    private function guard(callable $fn): array
    {
        return OverviewService::guard($fn);
    }
}
