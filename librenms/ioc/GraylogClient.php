<?php
declare(strict_types=1);

namespace Ioc;

/**
 * Graylog Search Scripting API 的用戶端（唯讀）。
 *
 * 安全設計：
 *  - 用專用的唯讀帳號 Token（system/graylog-reader.py 建立，存在 root:librenms 640 的設定檔），不是 admin
 *  - 一律驗證憑證，沒有關閉驗證的選項（IS-043）；自簽或內部 CA 用設定檔的 GRAYLOG_CA_FILE
 *  - 不跟隨重導向（避免帶著憑證被導到別的主機）
 *  - 錯誤訊息不含網址與 Token（IS-047）
 *  - 連線 5 秒、整體 15 秒的逾時上界；失敗不重試，避免無限制負載（INT-067、INT-068）
 */
final class GraylogClient implements LogSource
{
    private string $base;
    private string $auth;
    private ?string $caFile;
    private int $timeout;
    /** @var string[] */
    private array $streams;
    private int $budget;
    private ?float $deadline = null;

    /**
     * @param string[] $streams 要查的 stream ID；空陣列＝帳號有權限讀的全部
     * @param int $budget 這個物件（一次畫面載入）花在 Graylog 上的總秒數上限；用完後的查詢直接回報逾時，
     *                    免得 Graylog 卡住時 N 個區塊各等 $timeout 秒，加起來超過 nginx 的 60 秒而變成 504
     */
    public function __construct(string $baseUrl, string $token, array $streams = [], ?string $caFile = null, int $timeout = 15, int $budget = 25)
    {
        $url    = parse_url($baseUrl);
        $scheme = strtolower((string) ($url['scheme'] ?? ''));
        $host   = strtolower((string) ($url['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($url['user'])) {
            throw new \InvalidArgumentException('GRAYLOG_URL 格式不正確');
        }
        // Token 放在每個請求的標頭裡：http 只允許連本機，其他一律要 https（IS-043）
        if ($scheme === 'http' && !self::isLoopback($host)) {
            throw new \InvalidArgumentException('GRAYLOG_URL 必須是 https（只有本機位址可以用 http）');
        }
        $this->base    = rtrim($baseUrl, '/');
        $this->auth    = 'Basic ' . base64_encode($token . ':token');   // Graylog 的 Token 驗證：帳號放 Token，密碼固定 token
        $this->streams = $streams;
        $this->caFile  = $caFile;
        $this->timeout = $timeout;
        $this->budget  = $budget;
    }

    private static function isLoopback(string $host): bool
    {
        // 只有「真的是 IPv4」才看 127. 開頭；否則 127.0.0.1.evil.example 這種網域名稱也會被當成本機
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return strpos($host, '127.') === 0;
        }
        return $host === 'localhost' || $host === '[::1]';
    }

    /** @throws GraylogUnavailable 設定檔不存在或不完整時（入口顯示「尚未設定」，不是「沒有事件」） */
    public static function fromConf(string $path): self
    {
        if (!is_readable($path)) {
            throw new GraylogUnavailable('尚未設定 Graylog 唯讀帳號');
        }
        $conf = self::parseConf((string) file_get_contents($path));
        if (empty($conf['GRAYLOG_URL']) || empty($conf['GRAYLOG_TOKEN'])) {
            throw new GraylogUnavailable('Graylog 唯讀帳號的設定不完整');
        }
        $streams = array_values(array_filter(array_map('trim', explode(',', $conf['GRAYLOG_STREAMS'] ?? ''))));
        try {
            return new self($conf['GRAYLOG_URL'], $conf['GRAYLOG_TOKEN'], $streams, $conf['GRAYLOG_CA_FILE'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new GraylogUnavailable('Graylog 唯讀帳號的設定不正確');
        }
    }

    /** @return array<string,string> KEY=VALUE；只拿掉成對的外層引號（與 graylog-pipelines.py 同一規則） */
    public static function parseConf(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && $v[0] === $v[strlen($v) - 1] && ($v[0] === '"' || $v[0] === "'")) {
                $v = substr($v, 1, -1);
            }
            $out[trim($k)] = $v;
        }
        return $out;
    }

    public function aggregate(string $query, int $rangeSeconds, array $groupBy, array $metrics, int $limit = 100): array
    {
        if ($groupBy === []) {
            throw new \InvalidArgumentException('Graylog 的彙整查詢一定要有 group_by');
        }
        $body = [
            'query'     => $query,
            'timerange' => ['type' => 'relative', 'range' => $rangeSeconds],
            'group_by'  => array_map(static fn ($f) => ['field' => $f, 'limit' => $limit], $groupBy),
            'metrics'   => array_map(static function ($m) {
                [$fn, $field] = array_pad(explode(':', $m, 2), 2, null);
                return $field === null ? ['function' => $fn] : ['function' => $fn, 'field' => $field];
            }, $metrics),
        ];
        if ($this->streams !== []) {
            $body['streams'] = $this->streams;
        }
        return $this->rows($this->post('/api/search/aggregate', $body));
    }

    public function messages(string $query, int $rangeSeconds, array $fields, int $size): array
    {
        $body = [
            'query'      => $query,
            'timerange'  => ['type' => 'relative', 'range' => $rangeSeconds],
            'fields'     => $fields,
            'size'       => $size,
            'sort'       => 'timestamp',
            'sort_order' => 'Descending',
        ];
        if ($this->streams !== []) {
            $body['streams'] = $this->streams;
        }
        return $this->rows($this->post('/api/search/messages', $body));
    }

    /**
     * @param array<string,mixed> $body
     * @return array{schema:array<int,array<string,mixed>>,datarows:array<int,array<int,mixed>>}
     */
    private function post(string $path, array $body): array
    {
        // 整次畫面載入共用同一個時間預算：前面的查詢已經把預算用完，後面的直接回報逾時，不再各等一輪
        $now = microtime(true);
        $this->deadline ??= $now + $this->budget;
        $remaining = $this->deadline - $now;
        if ($remaining < 0.5) {
            throw new GraylogUnavailable('Graylog 回應逾時');
        }
        $wait = min((float) $this->timeout, $remaining);

        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min(5.0, $wait) * 1000),
            CURLOPT_TIMEOUT_MS     => (int) ($wait * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->auth,
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Requested-By: ioc-portal',
            ],
        ]);
        if ($this->caFile !== null && $this->caFile !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caFile);
        }
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($raw === false) {
            throw new GraylogUnavailable($errno === CURLE_OPERATION_TIMEDOUT ? 'Graylog 回應逾時' : 'Graylog 連線失敗');
        }
        if ($code < 200 || $code >= 300) {
            throw new GraylogUnavailable('Graylog 回應 HTTP ' . $code . $this->hint($code, (string) $raw));
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !isset($data['schema'], $data['datarows']) || !is_array($data['schema']) || !is_array($data['datarows'])) {
            throw new GraylogUnavailable('Graylog 回應格式不符');
        }
        return $data;
    }

    private function hint(int $code, string $raw): string
    {
        if ($code === 401 || $code === 403) {
            return '（唯讀帳號沒有權限，或 Token 已失效）';
        }
        if ($code >= 300 && $code < 400) {
            return '（被重導向，請檢查 GRAYLOG_URL）';
        }
        if ($code === 400) {
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['message']) && is_string($j['message'])) {
                return '：' . mb_substr($j['message'], 0, 120);
            }
        }
        return '';
    }

    /**
     * 把 schema＋datarows 轉成以欄位名稱為鍵的列。
     * 分組欄位用欄位名稱；指標用「函式」或「函式_欄位」（count、latest_timestamp）。
     *
     * @param array{schema:array<int,array<string,mixed>>,datarows:array<int,array<int,mixed>>} $data
     * @return array<int,array<string,mixed>>
     */
    private function rows(array $data): array
    {
        $keys = [];
        foreach ($data['schema'] as $i => $col) {
            if (($col['column_type'] ?? '') === 'metric') {
                $fn = (string) ($col['function'] ?? 'metric');
                $keys[$i] = (isset($col['field']) && $col['field'] !== '') ? $fn . '_' . $col['field'] : $fn;
            } else {
                $keys[$i] = (string) ($col['field'] ?? $col['name'] ?? $i);
            }
        }
        $out = [];
        foreach ($data['datarows'] as $row) {
            $assoc = [];
            foreach ($row as $i => $v) {
                $assoc[$keys[$i] ?? (string) $i] = $v;
            }
            $out[] = $assoc;
        }
        return $out;
    }
}
