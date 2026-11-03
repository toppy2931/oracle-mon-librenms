<?php
declare(strict_types=1);

namespace Ioc;

/**
 * CSV 輸出（日誌查詢與報表共用）。UTF-8 加 BOM（Excel 才認得中文）、每列以 CRLF 結尾、字串欄位一律加雙引號。
 *
 * 試算表公式注入：日誌與事件案的內容來自外部，帳號名稱可以是 `=HYPERLINK(...)`、`@SUM(...)`。Excel 開啟 CSV 時會把
 * 以 = + - @ 開頭（或 Tab、CR 開頭）的儲存格當公式執行，所以這些字串前面加一個單引號，讓它變成純文字。
 * 數字（int／float）不是字串，原樣輸出。
 */
final class CsvExport
{
    public const BOM = "\xEF\xBB\xBF";

    /** @param mixed $v */
    public static function cell($v): string
    {
        if ($v === null) {
            return '""';
        }
        if (is_bool($v)) {
            return $v ? '"是"' : '"否"';
        }
        if (is_int($v) || (is_float($v) && is_finite($v))) {
            return (string) $v;
        }
        $s = (string) $v;
        // 控制字元（NUL、退格、逃脫字元等）沒有顯示意義，還可能被某些程式當成控制碼；保留 Tab、換行（在引號裡是合法的）
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
            $s = "'" . $s;
        }
        return '"' . str_replace('"', '""', $s) . '"';
    }

    /**
     * 多個區塊的報表：開頭說明列，之後每個區塊是「區塊標題、欄位標題、資料列、空白列」。區塊讀不到時只寫標題與原因（不輸出假的空表）。
     *
     * @param array<int,array{0:string,1:mixed}> $meta
     * @param array<int,array{title:string,ok:bool,error?:?string,columns?:string[],rows?:array<int,array<int,mixed>>,note?:?string}> $sections
     */
    public static function buildSections(array $meta, array $sections): string
    {
        $lines = [];
        foreach ($meta as [$k, $v]) {
            $lines[] = self::cell($k) . ',' . self::cell($v);
        }
        foreach ($sections as $s) {
            $lines[] = '';
            $lines[] = self::cell('【' . $s['title'] . '】');
            if (!$s['ok']) {
                $lines[] = self::cell('無法取得') . ',' . self::cell((string) ($s['error'] ?? ''));
                continue;
            }
            $lines[] = implode(',', array_map([self::class, 'cell'], $s['columns'] ?? []));
            foreach ($s['rows'] ?? [] as $r) {
                $lines[] = implode(',', array_map([self::class, 'cell'], array_values($r)));
            }
            if (($s['note'] ?? null) !== null && $s['note'] !== '') {
                $lines[] = self::cell('說明') . ',' . self::cell($s['note']);
            }
        }
        return self::BOM . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<int,array{0:string,1:mixed}> $meta 開頭的說明列（報表名稱、資料來源、時間範圍…），每列兩欄；空陣列就不輸出
     * @param string[] $header 欄位標題
     * @param array<int,array<int,mixed>> $rows 資料列
     */
    public static function build(array $meta, array $header, array $rows): string
    {
        $lines = [];
        foreach ($meta as [$k, $v]) {
            $lines[] = self::cell($k) . ',' . self::cell($v);
        }
        if ($meta !== []) {
            $lines[] = '';
        }
        $lines[] = implode(',', array_map([self::class, 'cell'], $header));
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map([self::class, 'cell'], array_values($r)));
        }
        return self::BOM . implode("\r\n", $lines) . "\r\n";
    }
}
