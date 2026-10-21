<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 主機的遠端登入（SSH／RDP）：從 Graylog 取 Linux 與 Windows 的登入成功、登入失敗、無效帳號（簡報「遠端維運稽核軌跡」）。
 *
 * 鐵則：Linux 與 Windows 最近 24 小時都沒有收到日誌時，要說「沒有收到日誌（還沒接入，或已中斷）」，不能顯示成「沒有人登入」或空的表格（OPS-006、EVT-007）；
 * Graylog 取不到是 ['ok'=>false,'error'=>…]。範例資料（ioc_demo=true）預設排除，demo 模式只看範例資料，與總覽一致（IS-054～056）。
 * 只有 admin 能用（登入位址與失敗嘗試比較敏感）。
 */
final class HostLoginService
{
    public const RANGE = OverviewService::RANGE;     // 最近 24 小時
    public const LIMIT = 50;

    /**
     * 查登入的來源類型 → 連線方式的說明。Linux 的登入事件來自 sshd 規則，所以是 SSH；Windows 的登入成功／失敗
     * 還包含網路登入與服務登入，不能說成 RDP 或主控台，只寫「Windows 登入」。
     */
    public const SOURCE_LABEL = ['linux' => 'SSH（Linux）', 'windows' => 'Windows 登入'];
    private const ACTIONS = ['login_success', 'login_failed', 'invalid_user'];

    private LogSource $logs;
    private bool $demo;

    public function __construct(LogSource $logs, bool $demo = false)
    {
        $this->logs = $logs;
        $this->demo = $demo;
    }

    /** @return array<string,mixed> ['ok'=>true,'data'=>…] 或 ['ok'=>false,'error'=>…] */
    public function build(?string $level): array
    {
        Access::requireHandler($level, '只有管理者可以看身分與稽核');
        try {
            $present = [];
            foreach ($this->logs->aggregate($this->q('_exists_:log_type'), self::RANGE, ['log_type'], ['count'], 20) as $r) {
                $present[(string) ($r['log_type'] ?? '')] = true;
            }
            $missing = [];
            foreach (self::SOURCE_LABEL as $type => $label) {
                if (!isset($present[$type])) {
                    $missing[] = Labels::logType($type);
                }
            }
            if (count($missing) === count(self::SOURCE_LABEL)) {
                // 兩種都沒收到日誌（沒接入或已中斷，這裡分不出來）：沒有資料不等於沒人登入
                return ['ok' => true, 'data' => ['connected' => false, 'missing' => $missing, 'items' => [], 'counts' => [], 'capped' => false]];
            }

            $q    = $this->q('log_type:(' . implode(' OR ', array_keys(self::SOURCE_LABEL)) . ') AND event_action:(' . implode(' OR ', self::ACTIONS) . ')');
            $rows = $this->logs->messages($q, self::RANGE, ['timestamp', 'user_name', 'event_action', 'src_ip', 'host_name', 'log_type'], self::LIMIT);
            $by   = $this->logs->aggregate($q, self::RANGE, ['event_action'], ['count'], 10);
        } catch (GraylogUnavailable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $counts = array_fill_keys(self::ACTIONS, 0);
        foreach ($by as $r) {
            $code = (string) ($r['event_action'] ?? '');
            if (isset($counts[$code])) {
                $counts[$code] += (int) ($r['count'] ?? 0);
            }
        }
        $items = [];
        foreach ($rows as $r) {
            $action = (string) ($r['event_action'] ?? '');
            $items[] = [
                'time'    => Time::toTaipei(isset($r['timestamp']) ? (string) $r['timestamp'] : null, 'm-d H:i:s'),
                'user'    => (string) ($r['user_name'] ?? ''),
                'ip'      => (string) ($r['src_ip'] ?? ''),
                'host'    => (string) ($r['host_name'] ?? ''),
                'channel' => self::SOURCE_LABEL[(string) ($r['log_type'] ?? '')] ?? Labels::logType((string) ($r['log_type'] ?? '')),
                'action'  => Labels::action($action),
                'result'  => $action === 'login_success' ? 'ok' : 'fail',
            ];
        }
        return ['ok' => true, 'data' => [
            'connected' => true,
            'missing'   => $missing,          // 其中一種沒接入：畫面要提醒「不含 X 的登入」
            'items'     => $items,
            'counts'    => $counts,
            'capped'    => count($rows) >= self::LIMIT,
        ]];
    }

    private function q(string $query): string
    {
        return OverviewService::demoFilter($query, $this->demo);
    }
}
