<?php
declare(strict_types=1);

namespace Ioc;

/**
 * 「內部網段」：一組 IPv4 CIDR。內網可視性要把日誌分成「內部對內部」（東西向）與其他，所以要能
 *  1) 驗證、整理管理者填的網段（設定頁），
 *  2) 判斷一個位址在不在裡面，
 *  3) 轉成 Graylog 查詢條件，讓「內部對內部」的篩選在伺服器端完成（總量與趨勢才準，不必把每一對位址都撈回來再數）。
 *
 * Graylog 的查詢沒有 CIDR 比對（欄位不是 IP 型別），所以轉成「以八位元組對齊的前綴」：
 * 10.0.0.0/8 → 10.*；172.16.0.0/12 → 172.16.* … 172.31.*（16 個）；192.168.1.0/25 → 128 個 192.168.1.x。
 * 前綴總數超過 MAX_PATTERNS 的網段在設定時就擋下，免得查詢字串長到失控。
 * 只支援 IPv4：IPv6 位址一律不算內部（呼叫端要在畫面註明），不能悄悄猜。
 */
final class IpNets
{
    /** 沒設定過時的內部網段：RFC1918 私有位址。設定頁的預設值與總覽以外的判斷都用這一份。 */
    public const DEFAULTS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
    public const MAX_NETS = 10;
    public const MIN_BITS = 8;           // 比 /8 更大的網段幾乎一定是填錯（/0 會把整個網際網路當內部）
    public const MAX_PATTERNS = 256;

    /**
     * 把一個網段整理成「網路位址/前綴長度」。沒寫前綴長度當 /32；主機位元不是 0（192.168.1.5/24）就換成網路位址。
     * 不是合法的 IPv4 網段、或前綴長度不在 MIN_BITS～32 回 null。
     */
    public static function normalize(string $cidr): ?string
    {
        $p = self::parse($cidr);
        return $p === null ? null : long2ip($p[0]) . '/' . $p[1];
    }

    /**
     * @return array{0:int,1:int}|null [網路位址, 前綴長度]
     */
    private static function parse(string $cidr): ?array
    {
        $cidr = trim($cidr);
        if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?:\/(\d{1,2}))?$/D', $cidr, $m)) {
            return null;
        }
        $ip = filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if ($ip === false) {
            return null;                                    // 256.1.1.1、01.2.3.4 這類
        }
        $bits = isset($m[2]) ? (int) $m[2] : 32;
        if ($bits < self::MIN_BITS || $bits > 32) {
            return null;
        }
        $mask = $bits === 32 ? 0xFFFFFFFF : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
        return [((int) ip2long($ip)) & $mask, $bits];
    }

    /**
     * 管理者在設定頁填的文字（一行一個，也容許逗號或空白分隔）→ 整理過的網段清單，或中文的錯誤清單。
     * 排序（依位址）並去除重複，所以同一組網段不管怎麼排、怎麼寫，存進去的字串都一樣（沒有變動就不留異動紀錄）。
     *
     * @return array{nets:string[],errors:string[]}
     */
    public static function parseInput(string $text): array
    {
        ['nets' => $nets, 'errors' => $errors] = self::parseList($text);
        if ($errors === [] && $nets === []) {
            $errors[] = '至少要有一個內部網段';
        }
        if ($errors === [] && count($nets) > self::MAX_NETS) {
            $errors[] = '最多 ' . self::MAX_NETS . ' 個網段（目前 ' . count($nets) . ' 個）；範圍接近的可以合併成一個較大的網段';
        }
        if ($errors === [] && count(self::patterns($nets)) > self::MAX_PATTERNS) {
            $errors[] = '這些網段切得太細，轉成查詢條件會超過 ' . self::MAX_PATTERNS . ' 項；請改用較大的網段（例如 /16、/24）';
        }
        if ($errors !== []) {
            return ['nets' => [], 'errors' => $errors];
        }
        return ['nets' => $nets, 'errors' => []];
    }

    /**
     * 管理者填的文字（一行一個，也容許逗號、分號或空白分隔）→ 逐個整理過的網段（依位址排序、去重），
     * 與每個不合法網段的中文錯誤。只做格式整理，不檢查數量與空白：上限因用途不同（內部網段最多 10 個且展開後 ≤ 256 項，
     * 站點每個最多 8 個），由呼叫端各自檢查。內部網段（parseInput）與站點的網段（Sites::parseNets）共用這一份。
     *
     * @return array{nets:string[],errors:string[]} 有錯誤時 nets 只含合法的那幾個，呼叫端不能拿來用
     */
    public static function parseList(string $text): array
    {
        $errors = [];
        $nets   = [];
        $tokens = preg_split('/[\s,;，；]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $t) {
            $n = self::normalize($t);
            if ($n === null) {
                $errors[] = '「' . mb_substr($t, 0, 40) . '」不是合法的 IPv4 網段（寫成 10.0.0.0/8 這樣，前綴長度 ' . self::MIN_BITS . '～32）';
                continue;
            }
            $nets[$n] = true;
        }
        $nets = array_keys($nets);
        usort($nets, static fn ($a, $b) => self::parse($a)[0] <=> self::parse($b)[0] ?: self::parse($a)[1] <=> self::parse($b)[1]);
        return ['nets' => $nets, 'errors' => $errors];
    }

    /**
     * 資料庫裡存的字串（逗號分隔）→ 清單。只要有一項不合法、或整理後和存的不一樣（有人手動改過資料表）就回 null：
     * 呼叫端要把它當成「設定壞掉」，不能悄悄改用預設值。
     *
     * @return string[]|null
     */
    public static function fromStored(string $value): ?array
    {
        $r = self::parseInput($value);
        if ($r['errors'] !== [] || implode(',', $r['nets']) !== $value) {
            return null;
        }
        return $r['nets'];
    }

    /** @param string[] $nets */
    public static function toStored(array $nets): string
    {
        return implode(',', $nets);
    }

    /**
     * 位址在不在這些網段裡。不是 IPv4（含 IPv6、亂碼、空字串）一律 false。
     *
     * @param string[] $nets 已整理過的網段
     */
    public static function contains(array $nets, string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $n = (int) ip2long($ip);
        foreach ($nets as $cidr) {
            $p = self::parse($cidr);
            if ($p === null) {
                continue;
            }
            $mask = $p[1] === 32 ? 0xFFFFFFFF : ((0xFFFFFFFF << (32 - $p[1])) & 0xFFFFFFFF);
            if (($n & $mask) === $p[0]) {
                return true;
            }
        }
        return false;
    }

    /**
     * Graylog 查詢條件：欄位的值在這些網段裡。例如 src_ip:(10.* OR 172.16.* OR 172.17.*)。
     * 內容只會有數字與點，不含任何使用者可控的特殊字元。
     *
     * @param string[] $nets 已整理過的網段（不是空的）
     */
    public static function lucene(string $field, array $nets): string
    {
        if ($nets === []) {
            throw new \InvalidArgumentException('沒有內部網段，不能組查詢條件');
        }
        return $field . ':(' . implode(' OR ', self::patterns($nets)) . ')';
    }

    /**
     * 以八位元組對齊的前綴（"10.*"、"172.16.1.*"、"172.16.1.5"）。前綴長度不是 8／16／24／32 就往下展開成多個。
     *
     * @param string[] $nets
     * @return string[]
     */
    public static function patterns(array $nets): array
    {
        $out = [];
        foreach ($nets as $cidr) {
            $p = self::parse($cidr);
            if ($p === null) {
                continue;
            }
            [$base, $bits] = $p;
            $g     = $bits <= 8 ? 8 : ($bits <= 16 ? 16 : ($bits <= 24 ? 24 : 32));    // 展開到哪一層
            $count = 1 << ($g - $bits);
            $step  = 1 << (32 - $g);
            for ($i = 0; $i < $count; $i++) {
                $octets = explode('.', long2ip($base + $i * $step));
                $out[implode('.', array_slice($octets, 0, $g / 8)) . ($g === 32 ? '' : '.*')] = true;
            }
        }
        return array_keys($out);
    }
}
