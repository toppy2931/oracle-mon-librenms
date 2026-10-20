<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 事件詳情的「證據時間軸」與「關聯指標」：用事件案記下的 Graylog 查詢條件（evidence_query），
 * 在事件建立時間的前後一段時間內撈日誌（唯讀）。時間範圍與筆數上限都是常數，可依現場調整。
 *
 * 鐵則同總覽（OPS-006、EVT-007）：取不到就回 ['ok'=>false,'error'=>…,'reason'=>…]，
 * 畫面顯示「無法取得」與原因，不能變成空的時間軸。沒填查詢條件是另一回事（reason=no_query），
 * 要說清楚是「沒有可查的條件」，不是「這段時間沒有事件」。
 * 範例資料（ioc_demo=true）與正式資料互相排除，與總覽一致（IS-054～056）。
 */
final class EvidenceService
{
    public const BEFORE = 86400;   // 事件建立前 24 小時（入口的建案捷徑用的是「最近 24 小時」的統計）
    public const AFTER  = 3600;    // 事件建立後 1 小時（不會超過現在）
    public const LIMIT  = 30;      // 時間軸最多列幾筆（最新的 30 筆）

    private const FIELDS = ['timestamp', 'event_action', 'user_name', 'src_ip', 'dst_ip', 'host_name', 'severity', 'log_type'];

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
     * @param array<string,mixed> $case 資料庫的事件案列（要有 evidence_query、created_at）
     * @return array<string,mixed> ['ok'=>true,'data'=>…] 或 ['ok'=>false,'reason'=>…,'error'=>…]
     */
    public function build(array $case): array
    {
        $q = trim((string) ($case['evidence_query'] ?? ''));
        if ($q === '') {
            return $this->fail('no_query', '這件事件案沒有填 Graylog 查詢條件，沒有可查的日誌（建立事件案時填「Graylog 查詢條件」才查得到）');
        }
        $created = Time::parse(isset($case['created_at']) ? (string) $case['created_at'] : null);
        if ($created === null) {
            return $this->fail('bad_time', '事件案的建立時間讀不出來，無法決定查詢的時間範圍');
        }
        $from = $created - self::BEFORE;
        $to   = max(min($created + self::AFTER, $this->now), $created);
        $query = '(' . $q . ') AND ' . ($this->demo ? 'ioc_demo:true' : 'NOT ioc_demo:true');

        try {
            $rows   = $this->logs->messagesBetween($query, $this->iso($from), $this->iso($to), self::FIELDS, self::LIMIT);
            $counts = $this->logs->aggregateBetween($query, $this->iso($from), $this->iso($to), ['log_type'], ['count'], 20);
        } catch (GraylogUnavailable $e) {
            return $this->fail('unavailable', $e->getMessage());
        }

        $by = [];
        $total = 0;
        foreach ($counts as $r) {
            $n = (int) ($r['count'] ?? 0);
            $type = isset($r['log_type']) ? (string) $r['log_type'] : '';
            $by[$type] = ($by[$type] ?? 0) + $n;
            $total += $n;
        }

        $items = [];
        foreach (array_reverse($rows) as $r) {          // Graylog 回新到舊，時間軸要由舊到新
            $items[] = $this->item($r);
        }

        return ['ok' => true, 'data' => [
            'window'     => ['from' => Time::toTaipei(gmdate('Y-m-d H:i:s', $from)), 'to' => Time::toTaipei(gmdate('Y-m-d H:i:s', $to))],
            'items'      => $items,
            'total'      => $total,
            'truncated'  => count($rows) >= self::LIMIT && $total > count($rows),
            'indicators' => $this->indicators($by, $total),
        ]];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,string>
     */
    private function item(array $r): array
    {
        $action = (string) ($r['event_action'] ?? '');
        $parts  = [];
        foreach ([['user_name', '帳號'], ['src_ip', '來源'], ['dst_ip', '目的'], ['host_name', '設備']] as [$field, $label]) {
            $v = isset($r[$field]) ? trim((string) $r[$field]) : '';
            if ($v !== '') {
                $parts[] = $label . ' ' . $v;
            }
        }
        $sev = (string) ($r['severity'] ?? '');
        return [
            'time'       => Time::toTaipei(isset($r['timestamp']) ? (string) $r['timestamp'] : null, 'm-d H:i:s'),
            'type'       => Labels::logType((string) ($r['log_type'] ?? '')),
            // 沒有 event_action 的日誌是還沒被標準化 pipeline 認得的；不放原始訊息（可能含不該擴散的內容）
            'action'     => $action === '' ? '（尚未分類的日誌）' : Labels::action($action),
            'detail'     => implode(' · ', $parts),
            'level'      => $sev === '' ? '' : Labels::severity($sev),
            'level_code' => $sev,
        ];
    }

    /**
     * @param array<string,int> $by 各來源類型的筆數
     * @return array<int,array{label:string,value:?int,na:?string}>
     */
    private function indicators(array $by, int $total): array
    {
        $out = [['label' => '相關日誌總筆數', 'value' => $total, 'na' => null]];
        $known = 0;
        foreach (Labels::LOG_TYPES as $code => $label) {
            $n = $by[$code] ?? 0;
            $known += $n;
            if ($n > 0) {
                $out[] = ['label' => $label . '日誌', 'value' => $n, 'na' => null];
            }
        }
        if ($total - $known > 0) {
            $out[] = ['label' => '其他來源日誌', 'value' => $total - $known, 'na' => null];
        }
        // 簡報有「相關弱點」，但沒有弱點掃描來源：明確標示，不放假數字
        $out[] = ['label' => '相關弱點', 'value' => null, 'na' => '尚未接入'];
        return $out;
    }

    private function iso(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', $ts);
    }

    /** @return array{ok:false,reason:string,error:string} */
    private function fail(string $reason, string $why): array
    {
        return ['ok' => false, 'reason' => $reason, 'error' => $why];
    }
}
