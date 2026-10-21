<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 事件類型目錄（簡報「事件詳情」的攻擊技術與建議處置）。【提案版，內容都可以調】
 *
 * 事件案選一個類型，詳情頁就依類型顯示 MITRE ATT&CK 技術與內建的建議處置。
 * 這兩樣不存進事件案（只存類型代碼）：調整這份目錄，所有同類型的案件（含舊案）立刻跟著更新。
 * 建議處置是依類型寫好的範本，不是針對這一件事的即時分析，也不是 AI 產生的；請依單位的處理流程調整。
 * 沒有選類型（含舊案）一律當成 'other'：沒有對應的攻擊技術，只有通用的處置步驟。
 *
 * 類型對應 Labels::ACTIONS 的事件代碼，與入口的建案捷徑：「資安」頁的可疑登入、防火牆持續阻擋，
 * 以及「內網可視性」異常連線清單的橫向探測（lateral_probe）與被擋下的內部連線（fw_internal_block）。
 * 攻擊技術編號與英文名稱取自 MITRE ATT&CK Enterprise；入口不連到外部網站（離線環境）。
 */
final class CaseCatalog
{
    public const OTHER = 'other';

    public const CATEGORIES = [
        'suspicious_login' => [
            'label'  => '可疑登入',
            'attack' => ['id' => 'T1110', 'name' => '暴力破解（Brute Force）'],
            'steps'  => [
                '先確認帳號持有人：是不是本人在登入、來源位址是不是平常使用的設備。',
                '看證據時間軸：失敗是否集中在短時間、同一個來源是否嘗試了多個帳號。',
                '疑似被猜密碼時，暫時鎖定或重設該帳號的密碼，必要時在防火牆封鎖來源位址。',
                '如果後面出現成功登入，把等級調高，並檢查該帳號之後做了什麼。',
            ],
        ],
        'fw_internal_block' => [
            'label'  => '內部來源被防火牆持續阻擋',
            'attack' => ['id' => 'T1046', 'name' => '網路服務探索（Network Service Discovery）'],
            'steps'  => [
                '找出這個位址是哪一台設備、誰在使用，確認是不是已知設備。',
                '看被阻擋的目的位址與連接埠：是固定連同一個目標，還是依序掃描很多位址或連接埠。',
                '如果是掃描或不明程式，先把該設備隔離（拔網路線或關掉交換器連接埠），再檢查是否中毒。',
                '如果是設定錯誤（例如舊的連線設定），修正後觀察是否不再被阻擋。',
            ],
        ],
        'lateral_probe' => [
            'label'  => '內網橫向探測',
            'attack' => ['id' => 'T1046', 'name' => '網路服務探索（Network Service Discovery）'],
            'steps'  => [
                '找出這個內部位址是哪一台設備、誰在使用，確認是不是掃描器、備份或盤點軟體等已知用途。',
                '看連到哪些目的主機與連接埠：短時間內對很多台主機連同一個埠（例如 445、3389），多半是探測或蠕蟲式擴散。',
                '不是已知用途的話，先把該設備隔離（拔網路線或關掉交換器連接埠），再檢查是否中毒或帳號被盜用。',
                '檢查被連到的主機有沒有異常登入（可看「身分與稽核」與事件的證據時間軸），必要時一併處理。',
            ],
        ],
        'privilege_change' => [
            'label'  => '權限或群組異動',
            'attack' => ['id' => 'T1098', 'name' => '帳號操控（Account Manipulation）'],
            'steps'  => [
                '確認這次異動有沒有對應的申請或維運工單。',
                '確認操作的人與被異動的帳號，是否符合他們的職務。',
                '沒有依據的異動立即還原，並重設相關帳號的密碼。',
                '在處理歷程留下備註：誰核准、何時還原。',
            ],
        ],
        'account_change' => [
            'label'  => '帳號建立、停用或刪除',
            'attack' => ['id' => 'T1136', 'name' => '建立帳號（Create Account）'],
            'steps'  => [
                '確認有沒有人事或維運的申請。',
                '新建的帳號要檢查所屬群組與權限是否過大。',
                '來路不明的帳號先停用，不要直接刪除，保留證據。',
                '在處理歷程留下備註。',
            ],
        ],
        'audit_log_cleared' => [
            'label'  => '稽核日誌被清除',
            'attack' => ['id' => 'T1070.001', 'name' => '清除 Windows 事件日誌（Indicator Removal: Clear Windows Event Logs）'],
            'steps'  => [
                '這類事件通常很嚴重：先確認是不是例行維護（有沒有工單）。',
                '找出操作的帳號與來源設備，並檢查這個帳號前後的活動。',
                '日誌已集中保存在 Graylog 與歸檔，到那裡查看被清除之前的內容。',
                '沒有依據時升級處理並通知主管，必要時隔離該設備。',
            ],
        ],
        'audit_policy_change' => [
            'label'  => '稽核原則被修改',
            'attack' => ['id' => 'T1562.002', 'name' => '關閉 Windows 事件記錄（Impair Defenses: Disable Windows Event Logging）'],
            'steps'  => [
                '確認這次調整有沒有對應的申請或維運工單（例如例行的群組原則更新）。',
                '比對修改前後的稽核原則，看有哪些項目被關掉或降低了層級。',
                '找出操作的帳號與來源設備，並檢查這個帳號前後的活動。',
                '沒有依據的修改立即還原，並通知主管；必要時隔離該設備。',
            ],
        ],
        'service_install' => [
            'label'  => '安裝新服務',
            'attack' => ['id' => 'T1543.003', 'name' => '建立或修改系統程序：Windows 服務（Create or Modify System Process: Windows Service）'],
            'steps'  => [
                '確認服務名稱與執行檔路徑是不是已知的軟體。',
                '查安裝的帳號與時間，有沒有對應的變更申請。',
                '來路不明的服務先停用並保留執行檔，交給資安人員分析。',
                '在處理歷程留下備註。',
            ],
        ],
        'privileged_command' => [
            'label'  => '異常提權指令',
            'attack' => ['id' => 'T1548.003', 'name' => '濫用提升控制機制：sudo（Abuse Elevation Control Mechanism: Sudo and Sudo Caching）'],
            'steps'  => [
                '確認執行指令的帳號與指令內容是否合理。',
                '檢查同一個帳號的登入來源與時間是否正常。',
                '不合理的提權：收回 sudo 權限、重設密碼，並檢查該主機有沒有被改動。',
                '在處理歷程留下備註。',
            ],
        ],
        self::OTHER => [
            'label'  => '其他（未分類）',
            'attack' => null,
            'steps'  => [
                '先看事件說明與查詢條件，到證據時間軸確認發生了什麼事。',
                '判斷影響範圍後，視需要調整等級。',
                '處理完在處理歷程留下備註，再結案。',
            ],
        ],
    ];

    /** @return array<string,string> 類型代碼 → 中文名稱（建立事件案的下拉選單用） */
    public static function labels(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $key => $c) {
            $out[$key] = $c['label'];
        }
        return $out;
    }

    public static function isValid(string $key): bool
    {
        return isset(self::CATEGORIES[$key]);
    }

    /**
     * 沒有類型（NULL、空字串、不認得的代碼，例如目錄之後移除了某個類型）一律回 'other'。
     *
     * @return array{key:string,label:string,attack:?array{id:string,name:string},steps:string[]}
     */
    public static function get(?string $key): array
    {
        $key = ($key !== null && self::isValid($key)) ? $key : self::OTHER;
        return ['key' => $key] + self::CATEGORIES[$key];
    }
}
