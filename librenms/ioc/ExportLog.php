<?php
declare(strict_types=1);

namespace Ioc;

use PDO;

/**
 * 匯出紀錄：日誌查詢與報表每匯出一次 CSV 就記一筆（誰、何時、匯出什麼、幾筆）。只增不改不刪（程式裡沒有更新或刪除它的路徑）。
 * 這是「誰把哪些資料帶出系統」的留痕，內容可能含查詢條件（帳號、位址），所以只有管理者看得到（AuditTrail）。
 * 寫不進去就不能匯出（呼叫端要在給出 CSV 之前記錄，失敗就讓整個請求失敗）。
 */
final class ExportLog
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @param string $kind 'logs' 或 'report:<代碼>' */
    public function record(string $actor, string $kind, string $detail, int $rows): void
    {
        $q = $this->db->prepare('INSERT INTO ioc_export_log (actor,kind,detail,row_count,occurred_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
        $q->execute([mb_substr($actor, 0, 255), mb_substr($kind, 0, 40), mb_substr($detail, 0, 255), max(0, $rows)]);
    }

    /** @return array<int,array{actor:string,kind:string,detail:string,row_count:int,occurred_at:string}> 新到舊 */
    public function recent(int $limit): array
    {
        $q = $this->db->prepare('SELECT actor,kind,detail,row_count,occurred_at FROM ioc_export_log ORDER BY id DESC LIMIT ?');
        $q->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
