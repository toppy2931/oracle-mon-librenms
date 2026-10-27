<?php
declare(strict_types=1);

/**
 * 資料庫層測試（*_dbtest.php）的共用零件。只在 IOC_TEST_DSN 有設定時由 run.php 載入，
 * 而 IOC_TEST_DSN 只有 tests/run-db-tests.sh 會設：它先建好一次性的 ioc_test_* schema 與只能碰它的使用者。
 * 這裡再檢查一次：資料庫名稱不是 ioc_test_* 就直接拒絕，絕不會連到 LibreNMS 的資料庫。
 */

use Ioc\Access;
use Ioc\GraylogUnavailable;
use Ioc\Schema;
use Ioc\WindowedLogSource;

const DBT_H = Access::HANDLER;
const DBT_V = Access::VIEWER;

function dbtPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = (string) getenv('IOC_TEST_DSN');
    if (preg_match('/(?:^|;)dbname=(ioc_test_[a-z0-9]+)(?:;|$)/', $dsn, $m) !== 1) {
        fwrite(STDERR, "拒絕執行：IOC_TEST_DSN 的資料庫名稱必須是 ioc_test_*\n");
        exit(2);
    }
    // 選項與 LibreNMS（Laravel）的 MySQL 連線一致：原生預備陳述式、數字欄位回傳成數字、錯誤丟例外
    $pdo = new PDO($dsn, (string) getenv('IOC_TEST_USER'), (string) getenv('IOC_TEST_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $m[1] || strpos((string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn(), 'ioc_test_') !== 0) {
        fwrite(STDERR, "拒絕執行：連線的資料庫或使用者不是這個腳本建立的 ioc_test_*\n");
        exit(2);
    }
    foreach (Schema::statements() as $sql) {      // 與 system/ioc-db-setup.php 用同一份 DDL
        $pdo->exec($sql);
    }
    return $pdo;
}

/** 每個資料庫測試開始前把所有資料表清空（保留結構）。 */
function dbtClean(PDO $db): void
{
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $db->exec('TRUNCATE TABLE `' . $table . '`');
    }
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
}

/** 註冊一個資料庫層測試：測試前先清空資料表，測試函式收到連線。 */
function dbtest(string $name, callable $fn): void
{
    test('[資料庫] ' . $name, static function () use ($fn): void {
        $db = dbtPdo();
        dbtClean($db);
        $fn($db);
    });
}

/** @return array<string,mixed>|null */
function dbtRow(PDO $db, string $sql, array $params = []): ?array
{
    $q = $db->prepare($sql);
    $q->execute($params);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** @return array<int,array<string,mixed>> */
function dbtAll(PDO $db, string $sql, array $params = []): array
{
    $q = $db->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** @return mixed */
function dbtVal(PDO $db, string $sql, array $params = [])
{
    $q = $db->prepare($sql);
    $q->execute($params);
    $v = $q->fetchColumn();
    return $v === false ? null : $v;
}

/** 直接寫一個行為資產（繞過 BehaviorService，用來快速擺出各種狀態）。 */
function dbtAsset(PDO $db, array $o = []): int
{
    $o += ['identity_key' => 'test:' . bin2hex(random_bytes(4)), 'device_id' => null, 'name' => 'asset', 'site_name' => null,
        'mode' => 'learning', 'learning_started_at' => gmdate('Y-m-d H:i:s'), 'baseline_version' => 0,
        'quality_status' => 'learning', 'last_seen_at' => null, 'training_checked_at' => null,
        'confirmed_at' => null, 'confirmed_by' => null, 'confirm_note' => null];
    $q = $db->prepare('INSERT INTO ioc_behavior_assets (identity_key,device_id,name,site_name,mode,learning_started_at,baseline_version,quality_status,last_seen_at,training_checked_at,confirmed_at,confirmed_by,confirm_note)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$o['identity_key'], $o['device_id'], $o['name'], $o['site_name'], $o['mode'], $o['learning_started_at'],
        $o['baseline_version'], $o['quality_status'], $o['last_seen_at'], $o['training_checked_at'],
        $o['confirmed_at'], $o['confirmed_by'], $o['confirm_note']]);
    return (int) $db->lastInsertId();
}

/** 一個「有基線、監控中、學習起點在 30 天前」的資產（LibreNMS 設備 1），欄位可覆寫。 */
function dbtBaselineAsset(PDO $db, array $o = []): int
{
    return dbtAsset($db, $o + ['device_id' => 1, 'name' => 'fw', 'mode' => 'monitoring', 'baseline_version' => 1, 'quality_status' => 'ready',
        'learning_started_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
}

/** 擺一筆排程（預設：台北、週一到五、09:00–18:00）。 */
function dbtSchedule(PDO $db, string $scope, string $key, string $tz = 'Asia/Taipei', string $workdays = '1,2,3,4,5', string $start = '09:00:00', string $end = '18:00:00'): void
{
    $db->prepare('INSERT INTO ioc_behavior_schedules (scope,scope_key,timezone,workdays,start_time,end_time,updated_by,updated_at)
        VALUES (?,?,?,?,?,?,\'test\',UTC_TIMESTAMP())')->execute([$scope, $key, $tz, $workdays, $start, $end]);
}

/** 某個資產的歷史動作（舊到新）。 */
function dbtHistory(PDO $db, int $assetId): array
{
    return array_column(dbtAll($db, 'SELECT action FROM ioc_behavior_history WHERE asset_id=? ORDER BY id', [$assetId]), 'action');
}

/** 直接寫一個警示（繞過採集器）。 */
function dbtAlert(PDO $db, int $assetId, string $status = 'open', ?int $caseId = null, string $detector = 'after_hours_surge'): int
{
    $db->prepare("INSERT INTO ioc_behavior_alerts (dedup_key,asset_id,detector,window_start,baseline_version,severity,status,score,summary,metrics_json,case_id,first_seen_at,last_seen_at)
        VALUES (?,?,?,UTC_TIMESTAMP(),1,'medium',?,70,'測試警示','{}',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([hash('sha256', bin2hex(random_bytes(8))), $assetId, $detector, $status, $caseId]);
    return (int) $db->lastInsertId();
}

/** 擺防火牆日誌來源的狀態。 */
function dbtSource(PDO $db, string $status, string $checkedAt = ''): void
{
    $db->prepare("INSERT INTO ioc_behavior_sources (source_key,kind,scope_label,status,coverage,checked_at,reason)
        VALUES ('fortigate_graylog','fortigate','FortiGate → Graylog',?,'test',?,'') ON DUPLICATE KEY UPDATE status=VALUES(status),checked_at=VALUES(checked_at)")
        ->execute([$status, $checkedAt !== '' ? $checkedAt : gmdate('Y-m-d H:i:s')]);
}

/**
 * 假的 Graylog：依查詢的分組欄位回傳事先擺好的彙整結果（採集器每個區間會問 5 種彙整）。
 * 記下每次呼叫，測試可以檢查「沒有再查」或「只查了健康檢查」。
 */
final class DbtFakeLogs implements WindowedLogSource
{
    /** @var array<int,array{query:string,from:string,to:string,groupBy:array,metrics:array}> */
    public array $calls = [];
    /** @var callable */
    private $responder;
    public ?string $failWith = null;

    /** @param callable(string,array):array $responder (query, groupBy) → 彙整列 */
    public function __construct(callable $responder)
    {
        $this->responder = $responder;
    }

    /**
     * @param array<string,array{count:int,bytes?:int,with_bytes?:int,dest?:array<string,int>,port?:array<string,int>}> $perIp
     *        每個來源 IP 在這個區間的流量：連線數、位元組、有 net_bytes 的筆數、目的 IP → 筆數、目的埠 → 筆數
     */
    public static function traffic(array $perIp, ?int $events = null): self
    {
        return new self(static function (string $query, array $groupBy) use ($perIp, $events): array {
            if ($groupBy === ['cat']) {
                $n = $events ?? array_sum(array_column($perIp, 'count'));
                return $n > 0 ? [['cat' => 'traffic:forward', 'count' => $n]] : [];
            }
            $rows = [];
            foreach ($perIp as $ip => $t) {
                if ($groupBy === ['src_ip'] && strpos($query, '_exists_:net_bytes') !== false) {
                    $rows[] = ['src_ip' => $ip, 'count' => $t['with_bytes'] ?? $t['count']];
                } elseif ($groupBy === ['src_ip']) {
                    $rows[] = ['src_ip' => $ip, 'count' => $t['count'], 'sum_net_bytes' => $t['bytes'] ?? 0,
                        'card_dst_ip' => count($t['dest'] ?? []), 'card_dstport' => count($t['port'] ?? [])];
                } elseif ($groupBy === ['src_ip', 'dst_ip']) {
                    foreach ($t['dest'] ?? [] as $dst => $n) { $rows[] = ['src_ip' => $ip, 'dst_ip' => $dst, 'count' => $n]; }
                } elseif ($groupBy === ['src_ip', 'dstport']) {
                    foreach ($t['port'] ?? [] as $port => $n) { $rows[] = ['src_ip' => $ip, 'dstport' => (string) $port, 'count' => $n]; }
                }
            }
            return $rows;
        });
    }

    public function aggregateBetween(string $query, string $fromIso, string $toIso, array $groupBy, array $metrics, int $limit = 100): array
    {
        $this->calls[] = ['query' => $query, 'from' => $fromIso, 'to' => $toIso, 'groupBy' => $groupBy, 'metrics' => $metrics];
        if ($this->failWith !== null) {
            throw new GraylogUnavailable($this->failWith);
        }
        return ($this->responder)($query, $groupBy);
    }

    public function messagesBetween(string $query, string $fromIso, string $toIso, array $fields, int $size): array
    {
        throw new LogicException('採集器不該撈原始訊息');
    }

    public function aggregate(string $query, int $rangeSeconds, array $groupBy, array $metrics, int $limit = 100): array
    {
        throw new LogicException('採集器只用絕對時間範圍的彙整');
    }

    public function messages(string $query, int $rangeSeconds, array $fields, int $size): array
    {
        throw new LogicException('採集器不該撈原始訊息');
    }
}
