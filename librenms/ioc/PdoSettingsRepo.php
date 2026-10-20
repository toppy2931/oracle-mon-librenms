<?php
declare(strict_types=1);

namespace Ioc;

/**
 * SettingsRepo 的 MariaDB 實作。只用預先準備的陳述式，設定名稱與值都走參數，不拼進 SQL。
 * PDO 必須是 ERRMODE_EXCEPTION（LibreNMS／Laravel 預設如此）。
 */
final class PdoSettingsRepo implements SettingsRepo
{
    private \PDO $pdo;
    private int $depth = 0;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function all(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT skey, svalue, updated_by, updated_at FROM ioc_settings') as $r) {
            $out[(string) $r['skey']] = ['value' => (string) $r['svalue'], 'updated_by' => (string) $r['updated_by'], 'updated_at' => (string) $r['updated_at']];
        }
        return $out;
    }

    public function put(string $key, string $value, string $actor, string $utc): void
    {
        // 同一個參數名稱不能在陳述式裡出現兩次（未開模擬預備時 PDO 會拒絕），所以更新那段用另一組名稱
        $this->pdo->prepare(
            'INSERT INTO ioc_settings (skey, svalue, updated_by, updated_at) VALUES (:k, :v, :u, :t)'
            . ' ON DUPLICATE KEY UPDATE svalue = :v2, updated_by = :u2, updated_at = :t2'
        )->execute(['k' => $key, 'v' => $value, 'u' => $actor, 't' => $utc, 'v2' => $value, 'u2' => $actor, 't2' => $utc]);
    }

    public function addHistory(string $utc, string $actor, string $key, string $old, string $new): void
    {
        $this->pdo->prepare('INSERT INTO ioc_settings_history (ts, actor, skey, old_value, new_value) VALUES (:t, :a, :k, :o, :n)')
            ->execute(['t' => $utc, 'a' => $actor, 'k' => $key, 'o' => $old, 'n' => $new]);
    }

    public function recentHistory(int $limit): array
    {
        $st = $this->pdo->prepare('SELECT ts, actor, skey, old_value, new_value FROM ioc_settings_history ORDER BY id DESC LIMIT :lim');
        $st->bindValue(':lim', max(1, $limit), \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function transaction(callable $fn)
    {
        // 已在交易內（本物件已開啟，或呼叫端先對同一個 PDO 開了交易，例如自我測試要整批回復）就直接執行，
        // 由最外層負責 commit／rollback；不然 beginTransaction() 會丟「已經有進行中的交易」
        if ($this->depth > 0 || $this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        $this->depth++;
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth--;
        }
    }
}
