<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 日誌查詢頁（規格 LOG）：依時間與標準化欄位、關鍵字查 Graylog 的日誌，唯讀。
 *
 * - 查詢條件是結構化的欄位（來源類型、發生狀況、等級、帳號、位址、設備、關鍵字），伺服器端組成 Lucene 查詢：
 *   每個值都包在引號裡並跳脫，**不接受使用者自己寫的查詢語法**（不然 `x OR ioc_demo:true` 可以跳出「排除範例資料」的包裝）。
 * - 一律包 OverviewService::demoFilter：正式模式排除範例資料，demo 模式只看範例資料（IS-054～056）。
 * - 只取標準化欄位，**不取原始訊息**：原始訊息可能含密碼、權杖等不該擴散的內容（同事件詳情的證據時間軸）；要看原始日誌請到 Graylog。
 * - 鐵則同總覽（OPS-006、EVT-007、INT-017）：Graylog 取不到就回 ok=false 與原因，畫面顯示「無法取得」，不能變成空表。
 */
final class LogQuery
{
    /** 預設時間範圍（秒）。與保存期限無關：Graylog 的索引保存多久就查得到多久，查不到不代表沒發生 */
    public const RANGES = [3600 => '最近 1 小時', 21600 => '最近 6 小時', 86400 => '最近 24 小時', 604800 => '最近 7 日'];
    public const DEFAULT_RANGE = 86400;
    /** 自訂時間範圍最長 7 日：更長的區間查詢量太大，會拖垮 Graylog（整次請求只有一個時間預算） */
    public const MAX_SPAN = 604800;
    public const SHOW_LIMIT = 200;
    public const EXPORT_LIMIT = 5000;
    public const TEXT_MAX = 100;

    private const FIELDS = ['timestamp', 'log_type', 'event_action', 'severity', 'user_name', 'src_ip', 'dst_ip', 'host_name', 'target_name'];
    private const TEXT_FIELDS = ['user_name' => '帳號', 'host_name' => '設備', 'keyword' => '關鍵字'];
    private const IP_FIELDS = ['src_ip' => '來源位址', 'dst_ip' => '目的位址'];

    private WindowedLogSource $logs;
    private bool $demo;
    private int $now;
    private bool $allowKeyword;

    /**
     * @param bool $allowKeyword 關鍵字查詢（比對原始訊息的內容）只給管理者：原始訊息可能含密碼，頁面不給看，
     *        但允許任何人用「有沒有結果」逐詞探測它的內容，就等於給看了。預設不允許；使用者要放寬再改端點的傳入值。
     */
    public function __construct(WindowedLogSource $logs, bool $demo = false, ?int $now = null, bool $allowKeyword = false)
    {
        $this->logs = $logs;
        $this->demo = $demo;
        $this->now  = $now ?? time();
        $this->allowKeyword = $allowKeyword;
    }

    /**
     * 驗證並整理查詢條件。任何不合法的輸入都是 ValidationError（錯誤一次回報）。
     *
     * @param array<string,mixed> $in
     * @return array{from:int,to:int,filters:array<string,string>,query:string,summary:string}
     */
    public function parse(array $in): array
    {
        $errors = [];
        $from = $to = 0;
        $hasCustom = isset($in['from']) || isset($in['to']);
        if ($hasCustom) {
            $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?Z$/D';
            foreach (['from', 'to'] as $k) {
                if (!is_string($in[$k] ?? null) || preg_match($iso, $in[$k]) !== 1 || strtotime($in[$k]) === false) {
                    $errors[] = '自訂時間範圍的「' . ($k === 'from' ? '開始' : '結束') . '」要是 UTC 的 ISO 8601（例如 2026-10-20T14:00:00.000Z）';
                }
            }
            if ($errors === []) {
                $from = (int) strtotime($in['from']);
                $to   = (int) strtotime($in['to']);
                if ($to <= $from) {
                    $errors[] = '結束時間要晚於開始時間';
                } elseif ($to - $from > self::MAX_SPAN) {
                    $errors[] = '自訂時間範圍最長 7 日';
                } elseif ($to > $this->now + 300) {
                    $errors[] = '結束時間不能在未來';
                }
            }
        } else {
            $range = $in['range_seconds'] ?? self::DEFAULT_RANGE;
            if (!is_int($range) || !isset(self::RANGES[$range])) {
                $errors[] = '不支援的時間範圍';
            } else {
                $to   = $this->now;
                $from = $this->now - $range;
            }
        }

        $filters = [];
        $parts = [];
        foreach (['log_type' => Labels::LOG_TYPES, 'event_action' => Labels::ACTIONS, 'severity' => Labels::SEVERITY] as $k => $allowed) {
            $v = $in[$k] ?? '';
            if (!is_string($v)) {
                $errors[] = '欄位「' . $k . '」格式不正確';
            } elseif ($v !== '') {
                if (!isset($allowed[$v])) {
                    $errors[] = '欄位「' . $k . '」不是認得的選項';
                } else {
                    $filters[$k] = $v;
                    $parts[] = $k . ':' . self::quote($v);
                }
            }
        }
        foreach (self::IP_FIELDS as $k => $label) {
            $v = $in[$k] ?? '';
            if (!is_string($v)) {
                $errors[] = '「' . $label . '」格式不正確';
            } elseif (trim($v) !== '') {
                $v = trim($v);
                if (filter_var($v, FILTER_VALIDATE_IP) === false) {
                    $errors[] = '「' . $label . '」要是完整的 IP 位址';
                } else {
                    $filters[$k] = $v;
                    $parts[] = $k . ':' . self::quote($v);
                }
            }
        }
        foreach (self::TEXT_FIELDS as $k => $label) {
            $v = $in[$k] ?? '';
            if (!is_string($v)) {
                $errors[] = '「' . $label . '」格式不正確';
            } elseif ($k === 'keyword' && trim($v) !== '' && !$this->allowKeyword) {
                $errors[] = '關鍵字查詢只有管理者可以用（它會比對原始訊息的內容）';
            } elseif (trim($v) !== '') {
                $v = trim($v);
                if (preg_match('//u', $v) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
                    $errors[] = '「' . $label . '」含有不合法的字元';
                } elseif (mb_strlen($v) > self::TEXT_MAX) {
                    $errors[] = '「' . $label . '」最多 ' . self::TEXT_MAX . ' 個字';
                } else {
                    $filters[$k] = $v;
                    $parts[] = ($k === 'keyword' ? 'message' : $k) . ':' . self::quote($v);
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationError($errors);
        }
        return ['from' => $from, 'to' => $to, 'filters' => $filters, 'query' => $parts === [] ? '*' : implode(' AND ', $parts), 'summary' => $this->summary($filters)];
    }

    /** Lucene 的引號字串：值只當成文字，裡面的引號與反斜線都跳脫，萬用字元在引號裡不展開。 */
    private static function quote(string $v): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }

    /** @param array<string,string> $filters */
    private function summary(array $filters): string
    {
        if ($filters === []) {
            return '（沒有篩選條件）';
        }
        $names = ['log_type' => '來源類型', 'event_action' => '發生狀況', 'severity' => '等級', 'user_name' => '帳號', 'src_ip' => '來源位址',
                  'dst_ip' => '目的位址', 'host_name' => '設備', 'keyword' => '關鍵字'];
        $out = [];
        foreach ($filters as $k => $v) {
            $shown = $k === 'log_type' ? Labels::logType($v) : ($k === 'event_action' ? Labels::action($v) : ($k === 'severity' ? Labels::severity($v) : $v));
            $out[] = $names[$k] . '＝' . $shown;
        }
        return implode('；', $out);
    }

    /**
     * 查詢。回傳 ['ok'=>true,'data'=>…]；Graylog 取不到是 ['ok'=>false,'error'=>…]（畫面顯示「無法取得」與原因）。
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public function search(array $in, int $limit = self::SHOW_LIMIT): array
    {
        $p = $this->parse($in);
        $query = OverviewService::demoFilter($p['query'], $this->demo);
        $fromIso = gmdate('Y-m-d\TH:i:s.000\Z', $p['from']);
        $toIso   = gmdate('Y-m-d\TH:i:s.000\Z', $p['to']);
        try {
            $rows   = $this->logs->messagesBetween($query, $fromIso, $toIso, self::FIELDS, $limit);
            $counts = $this->logs->aggregateBetween($query, $fromIso, $toIso, ['log_type'], ['count'], 20);
        } catch (GraylogUnavailable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $total = 0;
        foreach ($counts as $r) {
            $total += (int) ($r['count'] ?? 0);
        }
        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->item($r);
        }
        return ['ok' => true, 'data' => [
            'window'    => ['from' => Time::toTaipei(gmdate('Y-m-d H:i:s', $p['from'])), 'to' => Time::toTaipei(gmdate('Y-m-d H:i:s', $p['to']))],
            'summary'   => $p['summary'],
            'filters'   => $p['filters'],
            'query'     => $query,
            'items'     => $items,
            'total'     => $total,
            'limit'     => $limit,
            'truncated' => $total > count($items),
        ]];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,string>
     */
    private function item(array $r): array
    {
        $s = static fn (string $k): string => isset($r[$k]) ? trim((string) $r[$k]) : '';
        $action = $s('event_action');
        $sev = $s('severity');
        return [
            'time'           => Time::toTaipei(isset($r['timestamp']) ? (string) $r['timestamp'] : null, 'Y-m-d H:i:s'),
            'log_type'       => $s('log_type'),
            'log_type_label' => Labels::logType($s('log_type')),
            'event_action'   => $action,
            // 沒有 event_action 的日誌是還沒被標準化 pipeline 認得的
            'action_label'   => $action === '' ? '（尚未分類的日誌）' : Labels::action($action),
            'severity'       => $sev,
            'severity_label' => $sev === '' ? '' : Labels::severity($sev),
            'user_name'      => $s('user_name'),
            'src_ip'         => $s('src_ip'),
            'dst_ip'         => $s('dst_ip'),
            'host_name'      => $s('host_name'),
            'target_name'    => $s('target_name'),
        ];
    }

    /**
     * 匯出成 CSV（上限 EXPORT_LIMIT 筆）。開頭幾列寫明資料來源、時間範圍、條件與筆數（RPT-005）。
     *
     * @param array<string,mixed> $in
     * @return array{ok:bool,csv?:string,rows?:int,error?:string,summary?:string}
     */
    public function export(array $in): array
    {
        $r = $this->search($in, self::EXPORT_LIMIT);
        if (!$r['ok']) {
            return $r;
        }
        $d = $r['data'];
        $rows = [];
        foreach ($d['items'] as $i) {
            $rows[] = [$i['time'], $i['log_type_label'], $i['action_label'], $i['severity_label'], $i['user_name'], $i['src_ip'], $i['dst_ip'], $i['host_name'], $i['target_name']];
        }
        $meta = [
            ['報表', '日誌查詢'],
            ['資料來源', 'Graylog（IOC 唯讀帳號）；只含標準化欄位，不含原始訊息'],
            ['時間範圍（台北時間）', $d['window']['from'] . ' ～ ' . $d['window']['to']],
            ['篩選條件', $d['summary']],
            ['筆數', count($rows) . ($d['truncated'] ? '（符合條件共 ' . $d['total'] . ' 筆，只匯出最新的 ' . count($rows) . ' 筆）' : '')],
        ];
        $csv = CsvExport::build($meta, ['時間（台北）', '來源類型', '發生狀況', '等級', '帳號', '來源位址', '目的位址', '設備', '目標'], $rows);
        return ['ok' => true, 'csv' => $csv, 'rows' => count($rows), 'summary' => $d['window']['from'] . ' ～ ' . $d['window']['to'] . '；' . $d['summary']];   // 匯出紀錄的欄位只有 255 字：時間範圍放前面，過長被截的是篩選條件
    }
}
