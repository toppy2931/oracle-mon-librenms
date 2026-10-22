<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 「站點」：管理者在設定頁自訂的「名稱＋一到多個 IPv4 網段」。設備的 IP 落在某站點的網段裡，就歸到那個站點
 * （重疊時最長前綴優先）；沒有符合的設備再回頭用 LibreNMS 的位置（Fleet 負責）。
 *
 * 這個類別只管：驗證管理者送來的清單、資料表裡存的格式、用 IP 找站點。不碰資料庫與權限（SettingsService 負責）。
 *
 * 儲存：ioc_settings 裡一個站點一列，skey＝site.<id>（id 是遞增整數、建立後不變），svalue＝「名稱|cidr,cidr」
 * （名稱不含 |；網段是整理過、依位址排序、去重的 CIDR）。名稱最多 32 字、網段最多 8 個，最長約 184 個字元，放得進 VARCHAR(255)。
 * 資料表裡的值被手動改壞（格式不對、名稱或網段重複）時 fromStored() 回 null：呼叫端要當成「設定壞掉」，不能悄悄略過或套預設。
 */
final class Sites
{
    public const KEY_PREFIX = 'site.';
    public const MAX_SITES  = 50;
    public const MAX_NETS   = 8;       // 每個站點
    public const NAME_MAX   = 32;
    public const CIDRS_MAX_CHARS = 1024;
    public const MAX_ID = 999999;      // site.<id> 的 id 上限（idOfKey 只認 1～999999）
    /** 下一個可用的站點 id（只增不減）。名稱不是 site. 開頭，所以不會被當成站點的一列。 */
    public const NEXT_ID_KEY = 'sites.next_id';

    /** @var array<int,array{id:int,name:string,nets:string[]}> 依 id 排序 */
    private array $list;
    /** @var array<int,array{0:int,1:int,2:int,3:string}> [網路位址, 遮罩, 前綴長度, 站點名稱]，前綴長的在前（找到第一個符合的就是最長前綴） */
    private array $lookup = [];

    /** @param array<int,array{id:int,name:string,nets:string[]}> $list 已驗證過的站點（網段是整理過的 a.b.c.d/N） */
    public function __construct(array $list = [])
    {
        usort($list, static fn ($a, $b) => $a['id'] <=> $b['id']);
        $this->list = array_values($list);
        // 網段先解析好：一次總覽要為每台設備找站點，每次都重新解析（設備數 × 網段數次）太浪費
        foreach ($this->list as $s) {
            foreach ($s['nets'] as $net) {
                [$addr, $bits] = explode('/', $net);
                $bits = (int) $bits;
                $mask = $bits === 32 ? 0xFFFFFFFF : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
                $this->lookup[] = [((int) ip2long($addr)) & $mask, $mask, $bits, $s['name']];
            }
        }
        usort($this->lookup, static fn ($a, $b) => $b[2] <=> $a[2]);
    }

    public function isEmpty(): bool
    {
        return $this->list === [];
    }

    /** @return array<int,array{id:int,name:string,nets:string[]}> */
    public function all(): array
    {
        return $this->list;
    }

    /**
     * IP 屬於哪個站點（網段最長前綴優先）；不是 IPv4、或沒有任何站點的網段包含它回傳 null。
     */
    public function siteOf(string $ip): ?string
    {
        if ($this->lookup === [] || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $n = (int) ip2long($ip);
        foreach ($this->lookup as [$base, $mask, , $name]) {
            if (($n & $mask) === $base) {
                return $name;
            }
        }
        return null;
    }

    /** 設定好的站點裡，名稱和 $name 相同（不分大小寫）的那個站點的名稱；沒有回傳 null。LibreNMS 位置要合併進同名站點時用。 */
    public function canonicalName(string $name): ?string
    {
        $key = mb_strtolower($name);
        foreach ($this->list as $s) {
            if (mb_strtolower($s['name']) === $key) {
                return $s['name'];
            }
        }
        return null;
    }

    public static function keyOf(int $id): string
    {
        return self::KEY_PREFIX . $id;
    }

    /** 'site.12' → 12；不是站點的設定名稱，或 id 不是 1～999999 的整數（site.abc、site.007）回傳 null。 */
    public static function idOfKey(string $key): ?int
    {
        return preg_match('/^site\.([1-9]\d{0,5})$/D', $key, $m) === 1 ? (int) $m[1] : null;
    }

    public static function isSiteKey(string $key): bool
    {
        return strncmp($key, self::KEY_PREFIX, strlen(self::KEY_PREFIX)) === 0;
    }

    /**
     * 去掉名稱前後的空白：不只 ASCII 空白，也包含全形空白（U+3000）、不換行空白（U+00A0）等 Unicode 空白與零寬字元，
     * 不然只有這類字元的名稱會被當成「有名稱」，畫面上是個看不見名字的站點。
     */
    public static function cleanName(string $name): string
    {
        return preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $name) ?? $name;
    }

    /** 站點名稱有什麼問題（中文說明）；沒問題回傳 null。呼叫前請先 cleanName。 */
    public static function nameError(string $name): ?string
    {
        if ($name === '') {
            return '站點名稱不能是空的';
        }
        if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > self::NAME_MAX) {
            return '站點名稱不能超過 ' . self::NAME_MAX . ' 個字';
        }
        if (preg_match('/[\p{Cc}\p{Cf}|]/u', $name)) {
            return '站點名稱不能包含控制字元、零寬字元或「|」';
        }
        if (Fleet::realLocation($name) === null) {
            return '「' . $name . '」是設備預設的位置名稱（不是人設定的站點），請換一個名稱';
        }
        return null;
    }

    /**
     * 管理者填的網段文字（一行一個，也容許逗號、分號或空白分隔）→ 整理過的網段（依位址排序、去重），或中文錯誤。
     * 每個網段先經過 IpNets::normalize（IPv4、前綴長度 8～32、主機位元換成網路位址）。
     *
     * @return array{nets:string[],errors:string[]}
     */
    public static function parseNets(string $text): array
    {
        if (strlen($text) > self::CIDRS_MAX_CHARS) {
            return ['nets' => [], 'errors' => ['網段的文字太長了（不超過 ' . self::CIDRS_MAX_CHARS . ' 個字元）']];
        }
        ['nets' => $nets, 'errors' => $errors] = IpNets::parseList($text);      // 切字、整理、去重、排序與內部網段共用
        if ($errors === [] && $nets === []) {
            $errors[] = '至少要有一個網段';
        }
        if ($errors === [] && count($nets) > self::MAX_NETS) {
            $errors[] = '每個站點最多 ' . self::MAX_NETS . ' 個網段（目前 ' . count($nets) . ' 個）；範圍接近的可以合併成一個較大的網段';
        }
        return $errors === [] ? ['nets' => $nets, 'errors' => []] : ['nets' => [], 'errors' => $errors];
    }

    /**
     * 管理者在設定頁送來的整份站點清單 → 驗證過的清單，或中文錯誤清單（一次回報所有問題）。
     * 規則：名稱不分大小寫不可重複；同一個網段（整理後）只能屬於一個站點；重疊但前綴不同是允許的（最長前綴優先）；
     * 最多 MAX_SITES 個站點。清單可以是空的（＝刪除全部站點）。id 是既有站點的識別碼，新站點沒有（null）。
     *
     * @param array<mixed> $items 每一項是 {id?, name, cidrs}
     * @return array{sites:array<int,array{id:?int,name:string,nets:string[]}>,errors:string[]}
     */
    public static function parseSubmitted(array $items): array
    {
        if (count($items) > self::MAX_SITES) {
            return ['sites' => [], 'errors' => ['最多 ' . self::MAX_SITES . ' 個站點（目前 ' . count($items) . ' 個）']];
        }
        $errors    = [];
        $sites     = [];
        $seenName  = [];
        $seenId    = [];
        $netOwner  = [];
        foreach (array_values($items) as $i => $it) {
            $label = '第 ' . ($i + 1) . ' 個站點';
            if (!is_array($it)) {
                $errors[] = $label . '：格式不對';
                continue;
            }
            $name = is_scalar($it['name'] ?? null) ? self::cleanName((string) $it['name']) : '';
            if ($name !== '') {
                $label = '站點「' . mb_substr($name, 0, self::NAME_MAX) . '」';
            }
            $id  = null;
            $raw = $it['id'] ?? null;
            if ($raw !== null && $raw !== '') {
                if (!is_scalar($raw) || !preg_match('/^[1-9]\d{0,5}$/D', (string) $raw)) {
                    $errors[] = $label . '：識別碼不合法，請重新整理頁面';
                    continue;
                }
                $id = (int) $raw;
                if (isset($seenId[$id])) {
                    $errors[] = $label . '：識別碼重複，請重新整理頁面';
                    continue;
                }
                $seenId[$id] = true;
            }
            $bad = self::nameError($name);
            if ($bad !== null) {
                $errors[] = $label . '：' . $bad;
            } elseif (isset($seenName[mb_strtolower($name)])) {
                $errors[] = $label . '：站點名稱重複了（不分大小寫）';
                $bad = 'dup';
            } else {
                $seenName[mb_strtolower($name)] = true;
            }
            $r = self::parseNets(is_scalar($it['cidrs'] ?? null) ? (string) $it['cidrs'] : '');
            foreach ($r['errors'] as $m) {
                $errors[] = $label . '：' . $m;
            }
            if ($bad !== null || $r['errors'] !== []) {
                continue;
            }
            foreach ($r['nets'] as $n) {
                if (isset($netOwner[$n])) {
                    $errors[] = sprintf('%s：網段 %s 已經屬於站點「%s」，同一個網段只能屬於一個站點', $label, $n, $netOwner[$n]);
                } else {
                    $netOwner[$n] = $name;
                }
            }
            $sites[] = ['id' => $id, 'name' => $name, 'nets' => $r['nets']];
        }
        return $errors === [] ? ['sites' => $sites, 'errors' => []] : ['sites' => [], 'errors' => $errors];
    }

    /** @param string[] $nets 整理過的網段 */
    public static function toStored(string $name, array $nets): string
    {
        return $name . '|' . implode(',', $nets);
    }

    /**
     * 資料庫裡存的字串 → 名稱與網段。格式不對、名稱不合法、網段不是整理過的樣子（有人手動改過資料表）回傳 null。
     *
     * @return array{name:string,nets:string[]}|null
     */
    public static function fromStored(string $value): ?array
    {
        $p = strpos($value, '|');
        if ($p === false) {
            return null;
        }
        $name = substr($value, 0, $p);
        $rest = substr($value, $p + 1);
        if ($name !== self::cleanName($name) || self::nameError($name) !== null) {
            return null;
        }
        $r = self::parseNets($rest);
        if ($r['errors'] !== [] || implode(',', $r['nets']) !== $rest) {
            return null;
        }
        return ['name' => $name, 'nets' => $r['nets']];
    }
}
