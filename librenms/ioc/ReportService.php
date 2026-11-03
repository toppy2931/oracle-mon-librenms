<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 報表中心（規格 RPT）：把既有的資料整理成「可列印的 HTML 報表」與「CSV」兩種輸出。報表本身只是資料結構
 * （標題、產生時間、時間範圍、篩選條件、資料來源與是否取得、若干區塊），畫面與 CSV 都由同一份結構產生，兩邊的內容一定一致。
 *
 * - 每份報表標明資料來源與時間範圍（RPT-005）；某個來源取不到，該區塊寫「無法取得」與原因，其他區塊照常（RPT-007：不能變成空表或 0）。
 * - 資料由呼叫端注入（callable），所以這個類別不碰 LibreNMS、Graylog 或資料庫，可以單元測試：
 *   overview（總覽整份結果）、fleet（Fleet 或 null）、cases（自某時間起的事件案列）、network（內網可視性）、audit（稽核摘要與明細）。
 * - 稽核證據清單與改善追蹤表只有管理者能產生（含稽核明細與負責人）；其他報表檢視者也能產生，內容與他們在畫面上看得到的一致，
 *   不含裝置行為的目的位址。
 * - 篩選條件：期間（事件彙整）、嚴重度、事件類型、設備名稱。不適用的篩選對該報表不生效，報表上會寫明沒套用。
 */
final class ReportService
{
    public const DAYS = [1 => '最近 1 日', 7 => '最近 7 日', 30 => '最近 30 日'];
    public const DEFAULT_DAYS = 7;
    public const CASE_LIMIT = 500;
    public const DEVICE_MAX = 100;

    /** @var array<string,array{title:string,admin:bool,filters:string[],about:string}> */
    public const REPORTS = [
        'ops'          => ['title' => '維運摘要', 'admin' => false, 'filters' => ['device'], 'about' => '站點、資產、可用率、核心服務與資料來源涵蓋，以及需要關注的設備'],
        'cases'        => ['title' => '事件彙整', 'admin' => false, 'filters' => ['days', 'severity', 'event', 'device'], 'about' => '期間內新建的事件案：件數統計與明細'],
        'security'     => ['title' => '資安與內網可視性摘要', 'admin' => false, 'filters' => [], 'about' => '可疑登入、防火牆阻擋、東西向流量、Top Talkers 與異常連線'],
        'availability' => ['title' => '可用率與 SLA', 'admin' => false, 'filters' => ['device'], 'about' => '每台設備的 1／7／30 日可用率、依類型的平均，以及 SLA 達成率'],
        'audit'        => ['title' => '稽核證據清單', 'admin' => true, 'filters' => [], 'about' => '目前評估週期的控制項評估結果與證據清單（管理者）'],
        'remediation'  => ['title' => '改善追蹤表', 'admin' => true, 'filters' => [], 'about' => '稽核改善事項：負責人、期限、狀態與逾期（管理者）'],
    ];

    /** AuditEvidenceService::detail() 的 SQL 上限（證據、改善事項各 500 筆，控制項 2000 筆）：碰到上限報表要寫明，不能默默少列 */
    public const AUDIT_EVIDENCE_LIMIT = 500;
    public const AUDIT_CONTROL_LIMIT = 2000;

    private const COVERAGE_STATUS = ['ok' => '正常接收', 'delayed' => '延遲', 'stale' => '中斷', 'none' => '期間無資料', 'unknown' => '無法判斷'];
    private const CONTROL_STATUS = ['compliant' => '符合', 'gap' => '缺口', 'not_applicable' => '不適用', 'unreviewed' => '未評估'];
    private const LEVEL = ['high' => '高', 'medium' => '中', 'low' => '低'];

    /** @var array<string,callable> */
    private array $p;
    private int $now;
    /** @var array<int,array{name:string,ok:bool,error:?string}> */
    private array $sources = [];
    /** @var array<string,mixed> 這一份報表內，沒有參數的提供者只呼叫一次（總覽一次要查好幾次 Graylog，不能每個區塊各查一次） */
    private array $memo = [];

    /** @param array<string,callable> $providers */
    public function __construct(array $providers, ?int $now = null)
    {
        $this->p = $providers;
        $this->now = $now ?? time();
    }

    /** @return array<string,array{title:string,about:string,filters:string[]}> 這個身分能產生的報表清單 */
    public static function catalog(?string $level): array
    {
        $out = [];
        foreach (self::REPORTS as $key => $r) {
            if (!$r['admin'] || Access::canHandle($level)) {
                $out[$key] = ['title' => $r['title'], 'about' => $r['about'], 'filters' => $r['filters']];
            }
        }
        return $out;
    }

    /**
     * 驗證篩選條件。任何不合法的值都是 ValidationError；不適用於這份報表的篩選被忽略（畫面上不會出現，不算錯）。
     *
     * @param array<string,mixed> $in
     * @return array{days:int,severity:string,event:string,device:string}
     */
    public static function parseFilters(array $in): array
    {
        $errors = [];
        $days = $in['days'] ?? self::DEFAULT_DAYS;
        if (!is_int($days) || !isset(self::DAYS[$days])) {
            $errors[] = '不支援的期間';
            $days = self::DEFAULT_DAYS;
        }
        $sev = $in['severity'] ?? '';
        if (!is_string($sev) || ($sev !== '' && !isset(Labels::CASE_SEVERITY[$sev]))) {
            $errors[] = '嚴重度不是認得的選項';
            $sev = '';
        }
        $event = $in['event'] ?? '';
        if (!is_string($event) || ($event !== '' && !CaseCatalog::isValid($event))) {
            $errors[] = '事件類型不是認得的選項';
            $event = '';
        }
        $device = $in['device'] ?? '';
        if (!is_string($device)) {
            $errors[] = '設備名稱格式不正確';
            $device = '';
        } else {
            $device = trim($device);
            if (preg_match('//u', $device) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $device) === 1) {
                $errors[] = '設備名稱含有不合法的字元';
                $device = '';
            } elseif (mb_strlen($device) > self::DEVICE_MAX) {
                $errors[] = '設備名稱最多 ' . self::DEVICE_MAX . ' 個字';
                $device = '';
            }
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return ['days' => $days, 'severity' => $sev, 'event' => $event, 'device' => $device];
    }

    /**
     * 產生一份報表。
     *
     * @param array<string,mixed> $in 篩選條件（原樣，型別由 parseFilters 嚴格檢查）
     * @return array<string,mixed>
     */
    public function build(string $key, array $in, ?string $level): array
    {
        if (!isset(self::REPORTS[$key])) {
            throw new ValidationError(['不認得的報表']);
        }
        $def = self::REPORTS[$key];
        if ($def['admin']) {
            Access::requireHandler($level, '只有管理者可以產生這份報表');
        } elseif (!Access::canView($level)) {
            throw new AccessDenied('沒有檢視權限');
        }
        $f = self::parseFilters($in);
        $this->sources = [];
        $this->memo = [];
        $range = '現況（報表產生當下）';
        $sections = [];
        switch ($key) {
            case 'ops':
                $sections = $this->ops($f);
                $range = $this->overviewRange() ?? $range;
                break;
            case 'cases':
                $sections = $this->cases($f);
                $range = self::DAYS[$f['days']];
                break;
            case 'security':
                $sections = $this->security();
                $range = $this->overviewRange() ?? $range;
                break;
            case 'availability':
                $sections = $this->availability($f);
                $range = '1／7／30 日可用率（LibreNMS 的累計值）；SLA 為最近 30 日';
                break;
            case 'audit':
                $sections = $this->audit();
                $range = '目前評估週期';
                break;
            case 'remediation':
                $sections = $this->remediation();
                $range = '目前評估週期';
                break;
        }
        $applied = [];
        foreach ($def['filters'] as $name) {
            if ($name === 'days') {
                $applied[] = '期間＝' . self::DAYS[$f['days']];
            } elseif ($name === 'severity' && $f['severity'] !== '') {
                $applied[] = '嚴重度＝' . Labels::CASE_SEVERITY[$f['severity']];
            } elseif ($name === 'event' && $f['event'] !== '') {
                $applied[] = '事件類型＝' . CaseCatalog::labels()[$f['event']];
            } elseif ($name === 'device' && $f['device'] !== '') {
                $applied[] = '設備名稱包含「' . $f['device'] . '」';
            }
        }
        return [
            'key'          => $key,
            'title'        => $def['title'],
            'generated_at' => Time::toTaipei(gmdate('Y-m-d H:i:s', $this->now)),
            'range'        => $range,
            'filters'      => $applied === [] ? ['（沒有篩選條件）'] : $applied,
            'sources'      => $this->sources,
            'sections'     => $sections,
        ];
    }

    /**
     * 同一份結構輸出成 CSV：開頭寫明報表名稱、產生時間、時間範圍、篩選條件與資料來源（取不到的來源也寫），之後每個區塊一段。
     *
     * @param array<string,mixed> $report
     */
    public static function csv(array $report): string
    {
        $src = array_map(static fn (array $s): string => $s['name'] . ($s['ok'] ? '' : '（無法取得：' . ($s['error'] ?? '') . '）'), $report['sources']);
        $meta = [
            ['報表', $report['title']],
            ['產生時間（台北時間）', $report['generated_at']],
            ['時間範圍', $report['range']],
            ['篩選條件', implode('；', $report['filters'])],
            ['資料來源', implode('；', $src)],
        ];
        return CsvExport::buildSections($meta, $report['sections']);
    }

    /** 報表有幾列資料（匯出紀錄用）。 */
    public static function rowCount(array $report): int
    {
        $n = 0;
        foreach ($report['sections'] as $s) {
            $n += $s['ok'] ? count($s['rows'] ?? []) : 0;
        }
        return $n;
    }

    /**
     * 自某時間起的事件案：一頁一頁讀到夠舊為止。事件案列表是依「更新時間」由新到舊排的（留言與狀態變更都會刷新更新時間），
     * 所以**停止條件要看更新時間，不能看建立時間**：一件很久以前建立、昨天剛結案的案件會排在前面，
     * 用它的建立時間判斷「後面都太舊了」會漏掉後面期間內建立的案件。更新時間一定不早於建立時間，
     * 所以最後一筆的更新時間早於 $since，後面的建立時間也一定早於 $since。
     *
     * @param callable $page 收 offset，回 ['items'=>rows（依更新時間新到舊）, 'has_more'=>bool]
     * @return array{rows:array<int,array<string,mixed>>,capped:bool} capped＝筆數碰到上限，更早的案件沒有讀進來
     */
    public static function collectCases(callable $page, int $since, int $limit = self::CASE_LIMIT): array
    {
        $rows = [];
        for ($offset = 0; ; $offset += 100) {
            $p = $page($offset);
            foreach ($p['items'] as $c) {
                $rows[] = $c;
            }
            if ($p['items'] === [] || !$p['has_more']) {
                return ['rows' => $rows, 'capped' => false];
            }
            $last = Time::parse((string) ($p['items'][count($p['items']) - 1]['updated_at'] ?? ''));
            if ($last !== null && $last < $since) {
                return ['rows' => $rows, 'capped' => false];
            }
            if (count($rows) >= $limit) {
                return ['rows' => $rows, 'capped' => true];
            }
        }
    }

    // ── 區塊小工具 ───────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function sec(string $title, array $columns, array $rows, ?string $note = null): array
    {
        return ['title' => $title, 'ok' => true, 'columns' => $columns, 'rows' => $rows, 'note' => $note];
    }

    /** @return array<string,mixed> */
    private function fail(string $title, string $why): array
    {
        return ['title' => $title, 'ok' => false, 'error' => $why];
    }

    private function source(string $name, bool $ok, ?string $error = null): void
    {
        foreach ($this->sources as $s) {
            if ($s['name'] === $name) {
                return;
            }
        }
        $this->sources[] = ['name' => $name, 'ok' => $ok, 'error' => $ok ? null : $error];
    }

    /** 呼叫提供者；任何 GraylogUnavailable 或提供者回 null 都當成取不到（回 null）。其他例外是 bug，往上丟。 */
    private function get(string $name, ...$args)
    {
        if ($args === [] && array_key_exists($name, $this->memo)) {
            return $this->memo[$name];
        }
        try {
            $v = ($this->p[$name])(...$args);
        } catch (GraylogUnavailable $e) {
            $v = null;
        }
        if ($args === []) {
            $this->memo[$name] = $v;
        }
        return $v;
    }

    private function overviewRange(): ?string
    {
        $ov = $this->get('overview');
        return is_array($ov) && isset($ov['meta']['range_label']) ? '日誌類為' . $ov['meta']['range_label'] . '；設備與事件案為報表產生當下的狀態' : null;
    }

    private static function matches(string $needle, string ...$hay): bool
    {
        if ($needle === '') {
            return true;
        }
        foreach ($hay as $h) {
            if (mb_stripos($h, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function pct(?float $v): string
    {
        return $v === null ? '—（沒有資料）' : number_format($v, 2) . '%';
    }

    // ── 各份報表 ─────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function ops(array $f): array
    {
        $ov = $this->get('overview');
        $out = [];
        if (!is_array($ov) || !isset($ov['landscape'])) {
            $this->source('LibreNMS 設備與可用率、IOC 事件案', false, '總覽資料無法讀取');
            return [$this->fail('關鍵指標', '總覽資料無法讀取')];
        }
        $L = $ov['landscape'];
        $rows = [];
        foreach (array_merge($L['kpi'] ?? [], $L['kpi2'] ?? []) as $k) {
            $rows[] = [$k['label'], $k['value'] ?? ($k['na'] ?? '無法取得'), $k['sub'] ?? ''];
        }
        $out[] = $this->sec('關鍵指標', ['指標', '數值', '說明'], $rows);

        $cov = $ov['coverage'] ?? null;
        if (is_array($cov) && ($cov['ok'] ?? false)) {
            $out[] = $this->sec('資料來源涵蓋', ['系統類型', '目前狀態', '最後收到資料', '期間筆數'], array_map(
                static fn (array $c): array => [$c['label'], self::COVERAGE_STATUS[$c['status']] ?? (string) $c['status'], $c['last'], $c['status'] === 'none' ? '—' : $c['count']], $cov['data']));
            $this->source('Graylog 日誌涵蓋', true);
        } else {
            $why = is_array($cov) ? (string) ($cov['error'] ?? '無法取得') : '無法取得';
            $out[] = $this->fail('資料來源涵蓋', $why);
            $this->source('Graylog 日誌涵蓋', false, $why);
        }

        $dev = $ov['devices'] ?? null;
        if (is_array($dev) && ($dev['ok'] ?? false)) {
            $d = $dev['data'];
            $rows = [];
            foreach ($d['down_list'] as $x) {
                if (self::matches($f['device'], (string) $x['name'])) {
                    $rows[] = [$x['name'], $x['last_polled'] ?? '—', $x['reason'] ?? ''];
                }
            }
            $out[] = $this->sec('需要關注的設備（離線）', ['設備', '最後輪詢', '原因'], $rows,
                $d['total'] . ' 台設備中有 ' . ($d['total'] - $d['up']) . ' 台離線' . ($f['device'] !== '' ? '（名單已依設備名稱篩選）' : '') . ($d['total'] - $d['up'] > count($d['down_list']) ? '；只列出前 ' . count($d['down_list']) . ' 台' : '') . '。');
            $this->source('LibreNMS 設備與可用率、IOC 事件案', true);
        } else {
            $why = is_array($dev) ? (string) ($dev['error'] ?? '無法取得') : '無法取得';
            $out[] = $this->fail('需要關注的設備（離線）', $why);
            $this->source('LibreNMS 設備與可用率、IOC 事件案', false, '設備清單：' . $why);
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function cases(array $f): array
    {
        $since = $this->now - $f['days'] * 86400;
        $res = $this->get('cases', $since);
        if (!is_array($res)) {
            $this->source('IOC 事件案資料表', false, '事件案資料無法讀取');
            return [$this->fail('事件彙整', '事件案資料無法讀取')];
        }
        $this->source('IOC 事件案資料表', true);
        $rows = [];
        foreach ($res['rows'] as $c) {
            $ts = Time::parse((string) $c['created_at']);
            if ($ts === null || $ts < $since) {
                continue;
            }
            $cat = CaseCatalog::get($c['category'] ?? null)['key'];
            $hosts = [(string) ($c['subject_host'] ?? ''), (string) ($c['target_host'] ?? ''), (string) ($c['related_host'] ?? '')];
            if (($f['severity'] !== '' && $c['severity'] !== $f['severity']) || ($f['event'] !== '' && $cat !== $f['event']) || !self::matches($f['device'], ...$hosts)) {
                continue;
            }
            $rows[] = ['c' => $c, 'cat' => $cat];
        }
        $count = static function (array $items, callable $key): array {
            $n = [];
            foreach ($items as $i) {
                $n[$key($i)] = ($n[$key($i)] ?? 0) + 1;
            }
            return $n;
        };
        $bySev = $count($rows, static fn ($r) => $r['c']['severity']);
        $byStatus = $count($rows, static fn ($r) => $r['c']['status']);
        $byCat = $count($rows, static fn ($r) => $r['cat']);
        $tbl = static function (array $labels, array $n): array {
            $o = [];
            foreach ($labels as $k => $label) {
                if (isset($n[$k])) {
                    $o[] = [$label, $n[$k]];
                }
            }
            return $o;
        };
        $note = $res['capped'] ? '事件案很多，只讀取到最新的 ' . self::CASE_LIMIT . ' 件，期間較早的案件可能沒有列入，統計可能偏低。' : null;
        $detail = [];
        foreach ($rows as $r) {
            $c = $r['c'];
            $detail[] = [$c['id'], Time::toTaipei((string) $c['created_at']), $c['title'], Labels::CASE_SEVERITY[$c['severity']] ?? (string) $c['severity'],
                         Labels::caseStatus((string) $c['status']), CaseCatalog::labels()[$r['cat']], $c['subject_host'] ?? '', $c['subject_ip'] ?? '', $c['assignee'] ?? ''];
        }
        return [
            $this->sec('件數統計', ['項目', '件數'], array_merge([['事件案總數', count($rows)]], $tbl(Labels::CASE_SEVERITY, $bySev), $tbl(Labels::CASE_STATUS, $byStatus), $tbl(CaseCatalog::labels(), $byCat)), $note),
            $this->sec('事件案明細', ['編號', '建立時間（台北）', '標題', '嚴重度', '狀態', '事件類型', '設備', '來源位址', '負責人'], $detail),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function security(): array
    {
        $out = [];
        $n = $this->get('network');
        if (!is_array($n) || !isset($n['kpi'])) {
            $this->source('Graylog 防火牆日誌（內網可視性）', false, '內網可視性資料無法讀取');
            $out[] = $this->fail('內網可視性', '內網可視性資料無法讀取');
        } else {
            $this->source('Graylog 防火牆日誌（內網可視性）', true);
            $out[] = $this->sec('內網可視性指標', ['指標', '數值', '說明'], array_map(
                static fn (array $k): array => [$k['label'], $k['value'] ?? ($k['na'] ?? '無法取得'), $k['sub'] ?? ''], $n['kpi']), '只看得到經過防火牆的流量。');
            $a = $n['anomalies'] ?? ['ok' => false, 'error' => '無法取得'];
            if ($a['ok'] ?? false) {
                $rows = [];
                foreach ($a['data']['items'] as $x) {
                    $ips = $x['dst_ips'] ?? [];
                    $dst = $ips !== [] ? implode('、', $ips) . (($x['distinct'] ?? 0) > count($ips) ? ' 等 ' . $x['distinct'] . ' 個' : '') : (($x['distinct'] ?? 0) > 0 ? $x['distinct'] . ' 個內部主機' : '內部主機');
                    $rows[] = [$x['time'], $x['src_ip'], $dst, $x['port'] ?? '', $x['behavior'], self::LEVEL[$x['level']] ?? (string) $x['level']];
                }
                $out[] = $this->sec('異常連線', ['時間', '來源', '目的', '埠', '行為', '風險'], $rows);
            } else {
                $out[] = $this->fail('異常連線', (string) ($a['error'] ?? '無法取得'));
            }
            $t = $n['talkers'] ?? ['ok' => false, 'error' => '無法取得'];
            if ($t['ok'] ?? false) {
                $out[] = $this->sec('Top Talkers（內部來源流量）', ['來源', '位元組數', '日誌筆數'], array_map(
                    static fn (array $x): array => [$x['ip'], $x['bytes_label'] ?? $x['bytes'], $x['sessions']], $t['data']['items']));
            } else {
                $out[] = $this->fail('Top Talkers（內部來源流量）', (string) ($t['error'] ?? '無法取得'));
            }
        }
        $ov = $this->get('overview');
        if (is_array($ov) && isset($ov['attention'])) {
            $acc = $ov['attention']['accounts'];
            $out[] = ($acc['ok'] ?? false)
                ? $this->sec('可疑登入的帳號與來源', ['帳號', '來源位址', '次數', '等級'], array_map(
                    static fn (array $x): array => [$x['user'] ?? '', $x['src_ip'] ?? '', $x['count'], self::LEVEL[$x['level']] ?? (string) $x['level']], $acc['data']['items']))
                : $this->fail('可疑登入的帳號與來源', (string) ($acc['error'] ?? '無法取得'));
            $den = $ov['attention']['denied_sources'];
            $out[] = ($den['ok'] ?? false)
                ? $this->sec('被防火牆阻擋的內部來源', ['來源位址', '阻擋次數'], array_map(static fn (array $x): array => [$x['src_ip'], $x['count']], $den['data']['internal']),
                    '外部來源共 ' . $den['data']['external_events'] . ' 筆阻擋（網際網路的掃描雜訊，已彙總、不逐一列出）。')
                : $this->fail('被防火牆阻擋的內部來源', (string) ($den['error'] ?? '無法取得'));
            // 來源是否取得，由實際用它的兩個區塊決定：任何一個失敗就標失敗並帶原因（RPT-005／007）
            $whys = array_filter([($acc['ok'] ?? false) ? null : '可疑登入：' . ($acc['error'] ?? '無法取得'), ($den['ok'] ?? false) ? null : '阻擋來源：' . ($den['error'] ?? '無法取得')]);
            $this->source('Graylog 登入與防火牆日誌（可疑登入、阻擋來源）', $whys === [], implode('；', $whys));
        } else {
            $this->source('Graylog 登入與防火牆日誌（可疑登入、阻擋來源）', false, '總覽資料無法讀取');
            $out[] = $this->fail('可疑登入的帳號與來源', '總覽資料無法讀取');
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function availability(array $f): array
    {
        $fleet = $this->get('fleet');
        $out = [];
        if (!($fleet instanceof Fleet)) {
            $this->source('LibreNMS 設備與可用率', false, 'LibreNMS 的設備資料無法讀取');
            $out[] = $this->fail('設備可用率', 'LibreNMS 的設備資料無法讀取');
        } else {
            $this->source('LibreNMS 設備與可用率', true);
            $devs = array_values(array_filter($fleet->rows(), fn (array $d): bool => self::matches($f['device'], $d['name'])));
            usort($devs, static fn (array $a, array $b): int => ($a['avail_30d'] ?? 101.0) <=> ($b['avail_30d'] ?? 101.0) ?: strcmp($a['name'], $b['name']));
            $groups = ['server' => '伺服器', 'network' => '網路設備', 'other' => '其他'];
            $siteWarn = isset($this->p['sites_warning']) ? $this->get('sites_warning') : null;
            $out[] = $this->sec('設備可用率（30 日由低到高）', ['設備', '類型', '站點', '狀態', '1 日', '7 日', '30 日', '最後輪詢'], array_map(
                fn (array $d): array => [$d['name'], $groups[$d['group']] ?? $d['group'], $d['site'] ?? '未歸屬', $d['up'] ? '正常' : '離線',
                                        self::pct($d['avail_1d']), self::pct($d['avail_7d']), self::pct($d['avail_30d']), $d['last_polled']], $devs),
                '沒有資料的設備（剛納管或尚未累積）顯示「—」，不是 0%。' . ($f['device'] !== '' ? '已依設備名稱篩選。' : '')
                . (is_string($siteWarn) && $siteWarn !== '' ? ' 注意：' . $siteWarn : ''));
            $avg = [];
            foreach ($groups as $g => $label) {
                $a7 = $fleet->groupAvailability($g, 'avail_7d');
                $a30 = $fleet->groupAvailability($g, 'avail_30d');
                $avg[] = [$label, $a7['devices'], self::pct($a7['value']), self::pct($a30['value'])];
            }
            $out[] = $this->sec('依類型的平均可用率', ['類型', '設備數', '7 日平均', '30 日平均'], $avg, '平均只算有資料的設備。');
        }
        $ov = $this->get('overview');
        if (is_array($ov) && isset($ov['landscape'])) {
            $rows = [];
            foreach (array_merge($ov['landscape']['kpi'] ?? [], $ov['landscape']['kpi2'] ?? []) as $k) {
                if (in_array($k['key'] ?? '', ['sla', 'core_services', 'availability', 'network_availability'], true)) {
                    $rows[] = [$k['label'], $k['value'] ?? ($k['na'] ?? '無法取得'), $k['sub'] ?? ''];
                }
            }
            $out[] = $this->sec('SLA 與核心服務', ['指標', '數值', '說明'], $rows, 'SLA 的可用率目標與服務時段由機關在設定頁填列；LibreNMS 的可用率是全天計算的，未排除計畫性維護。');
            $this->source('IOC 服務水準設定', true);
        } else {
            $out[] = $this->fail('SLA 與核心服務', '總覽資料無法讀取');
            $this->source('IOC 服務水準設定', false, '總覽資料無法讀取');
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function audit(): array
    {
        $a = $this->get('audit');
        if (!is_array($a)) {
            $this->source('IOC 稽核與證據資料表', false, '稽核資料無法讀取');
            return [$this->fail('控制項評估', '稽核資料無法讀取')];
        }
        $this->source('IOC 稽核與證據資料表', true);
        $s = $a['summary'];
        if (!($s['ready'] ?? false)) {
            return [$this->sec('評估週期', ['項目', '內容'], [['狀態', (string) ($s['message'] ?? '尚未建立評估基準')]], '到「稽核與證據」頁匯入評估基準並建立評估週期後，才有控制項與證據可列。')];
        }
        $c = $s['counts'];
        $out = [$this->sec('評估週期', ['評估基準版本', '評估週期', '控制項總數', '已評估', '符合', '缺口', '不適用', '未評估'],
            [[$s['catalog'], $s['cycle']['title'] ?? '', $c['total'], $c['assessed'], $c['compliant'], $c['gap'], $c['not_applicable'], $c['unreviewed']]])];
        $d = $a['detail'];
        $code = [];
        $rows = [];
        foreach ($d['controls'] as $x) {
            $code[(int) $x['id']] = (string) $x['code'];
            $rows[] = [$x['code'], $x['domain'], $x['title'], self::CONTROL_STATUS[$x['status']] ?? (string) $x['status'], $x['assessed_by'] ?? '', isset($x['assessed_at']) ? Time::toTaipei((string) $x['assessed_at']) : ''];
        }
        $out[] = $this->sec('控制項評估', ['代碼', '領域', '標題', '評估結果', '評估者', '評估時間（台北）'], $rows,
            count($rows) >= self::AUDIT_CONTROL_LIMIT ? '控制項很多，只列了前 ' . count($rows) . ' 個，後面的沒有列入。' : null);
        $ev = [];
        foreach ($d['evidence'] as $x) {
            $ev[] = [$x['id'], $code[(int) $x['control_id']] ?? '', $x['kind'], $x['captured_by'] ?? '', isset($x['captured_at']) ? Time::toTaipei((string) $x['captured_at']) : '', substr((string) $x['sha256'], 0, 12), $x['bytes']];
        }
        $out[] = $this->sec('證據清單', ['編號', '控制項', '類型', '擷取者', '擷取時間（台北）', 'SHA-256（前 12 碼）', '位元組數'], $ev,
            '證據內容本身要到「稽核與證據」頁下載（會重新驗證雜湊）。' . (count($ev) >= self::AUDIT_EVIDENCE_LIMIT ? ' 證據很多，只列了最新的 ' . count($ev) . ' 筆，更早的沒有列入。' : ''));
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function remediation(): array
    {
        $a = $this->get('audit');
        if (!is_array($a)) {
            $this->source('IOC 稽核與證據資料表', false, '稽核資料無法讀取');
            return [$this->fail('改善事項', '稽核資料無法讀取')];
        }
        $this->source('IOC 稽核與證據資料表', true);
        if (!($a['summary']['ready'] ?? false)) {
            return [$this->sec('改善事項', ['項目', '內容'], [['狀態', (string) ($a['summary']['message'] ?? '尚未建立評估基準')]])];
        }
        $d = $a['detail'];
        $code = [];
        foreach ($d['controls'] as $x) {
            $code[(int) $x['id']] = (string) $x['code'];
        }
        $today = Time::toTaipei(gmdate('Y-m-d H:i:s', $this->now), 'Y-m-d');
        $rows = [];
        $overdue = 0;
        foreach ($d['remediation'] as $r) {
            $late = ($r['status'] ?? '') !== 'done' && (string) $r['due_on'] < $today;
            $overdue += $late ? 1 : 0;
            $rows[] = [$r['id'], $code[(int) $r['control_id']] ?? '', $r['owner'], $r['due_on'], $r['status'], $late ? '逾期' : '', mb_substr((string) ($r['note'] ?? ''), 0, 120), $r['created_by'] ?? '', isset($r['updated_at']) ? Time::toTaipei((string) $r['updated_at']) : ''];
        }
        $cut = count($rows) >= self::AUDIT_EVIDENCE_LIMIT;
        return [$this->sec('改善事項', ['編號', '控制項', '負責人', '期限', '狀態', '逾期', '備註', '建立者', '最後更新（台北）'], $rows,
            '共 ' . count($rows) . ' 件，逾期 ' . $overdue . ' 件（狀態不是 done 且期限早於今日）。'
            . ($cut ? ' 改善事項很多，只列了最新的 ' . count($rows) . ' 件，更早的沒有列入，逾期件數只含列出的這些。' : ''))];
    }
}
