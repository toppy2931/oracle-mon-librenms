<?php
declare(strict_types=1);

/**
 * 原始碼本身的檢查（不是功能測試）：掃 librenms/、system/、docs/、tests/php/ 的 PHP，包含沒有單元測試的網頁端點。
 * 用 PHP 自己的 tokenizer，註解、單引號字串、HTML 區塊裡的文字不會被誤判。
 */

/** @return string[] 要掃描的 .php 絕對路徑 */
function srcPhpFiles(): array
{
    $root = dirname(__DIR__, 2);
    $out  = [];
    foreach (['librenms', 'system', 'docs', 'tests/php'] as $sub) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $sub, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
                $out[] = $f->getPathname();
            }
        }
    }
    sort($out);
    return $out;
}

/**
 * 名稱含非 ASCII 字元的變數與屬性：字串插值（"$x」"、"$o->name，"）會把後面的全形字元當成名稱的一部分，
 * 整句訊息變成未定義的變數或屬性。簡單插值的變數是 T_VARIABLE，`->` 後面的屬性名稱是 T_STRING。
 *
 * @return string[] 「L行號 token」
 */
function srcNonAsciiNames(string $code): array
{
    $bad        = [];
    $afterArrow = false;
    foreach (token_get_all($code) as $t) {
        if (!is_array($t)) {
            $afterArrow = false;
            continue;
        }
        [$id, $text, $line] = $t;
        if ($id === T_WHITESPACE) {
            continue;
        }
        $isName = $id === T_VARIABLE || $id === T_STRING_VARNAME || ($id === T_STRING && $afterArrow);
        if ($isName && preg_match('/[^\x00-\x7F]/', $text) === 1) {
            $bad[] = 'L' . $line . ' ' . $text;
        }
        $afterArrow = $id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR;
    }
    return $bad;
}

test('原始碼檢查：偵測器抓得到變數與屬性吃掉全形標點的寫法，也不誤判正常寫法', function () {
    $bad = [
        '$m = "別名「$alias」已存在";',
        '$m = "$alias，";',
        '$m = "$row->alias，";',
        '$m = "$row?->alias」";',
        '$m = "${alias，}";',
    ];
    foreach ($bad as $code) {
        assertTrue(srcNonAsciiNames('<?php ' . $code) !== [], '應該被抓到：' . $code);
    }
    $good = [
        '$m = "別名「{$alias}」已存在";',
        '$m = "{$row->alias}，";',
        '$m = "$row->alias ，";',
        '// $alias」註解裡的變數不算' . "\n" . '$x = 1;',
        '$m = \'$alias」單引號字串不插值\';',
        '$m = $a . "，" . $b->name . "」";',
    ];
    foreach ($good as $code) {
        assertSame([], srcNonAsciiNames('<?php ' . $code), '不該誤判：' . $code);
    }
});

test('原始碼：沒有變數或屬性名稱把後面的全形標點吃進去（"$x」" 要寫 "{$x}"）', function () {
    $files = srcPhpFiles();
    assertTrue(count($files) > 20, '掃描不到檔案，路徑錯了？');
    $root = dirname(__DIR__, 2);
    $bad  = [];
    foreach ($files as $file) {
        foreach (srcNonAsciiNames((string) file_get_contents($file)) as $hit) {
            $bad[] = str_replace('\\', '/', substr($file, strlen($root) + 1)) . ' ' . $hit;
        }
    }
    assertSame([], $bad, '變數或屬性後面緊接全形字元，請改寫成 {$變數}');
});
