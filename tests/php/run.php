<?php
declare(strict_types=1);

/**
 * 極簡測試執行器（不需要 PHPUnit，LibreNMS 主機上也能直接跑）。
 *   php tests/php/run.php            跑 tests/php/*_test.php
 * 任何一項失敗，退出碼為 1。
 */

// 任何 PHP 警告（未定義變數、棄用…）都算測試失敗，不能只是印在輸出裡被忽略
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;            // 用 @ 刻意壓掉的警告（例如等伺服器啟動時的連線被拒）不算
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

require_once __DIR__ . '/../../librenms/ioc/bootstrap.php';
require_once __DIR__ . '/MemoryCaseRepo.php';
require_once __DIR__ . '/MemorySettingsRepo.php';

$GLOBALS['__tests'] = [];
$GLOBALS['__failed'] = 0;

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function fail(string $message): void
{
    throw new AssertionError($message);
}

/** @param mixed $expected @param mixed $actual */
function assertSame($expected, $actual, string $label = ''): void
{
    if ($expected !== $actual) {
        fail(($label !== '' ? $label . '：' : '') . '預期 ' . var_export($expected, true) . '，實際 ' . var_export($actual, true));
    }
}

function assertTrue(bool $cond, string $label = ''): void
{
    if (!$cond) {
        fail($label !== '' ? $label : '條件不成立');
    }
}

/** @param class-string<Throwable> $class */
function assertThrows(string $class, callable $fn, string $contains = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            // 注意：變數後面緊接全形標點時要用 {$var}，否則全形字元的位元組會被當成變數名稱的一部分
            fail("預期丟出 {$class}，實際是 " . get_class($e) . '：' . $e->getMessage());
        }
        if ($contains !== '' && mb_strpos($e->getMessage(), $contains) === false) {
            fail("例外訊息應包含「{$contains}」，實際是「{$e->getMessage()}」");
        }
        return $e;
    }
    fail("預期丟出 {$class}，但沒有例外");
}

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    require $file;
}

$passed = 0;
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    try {
        $fn();
        $passed++;
    } catch (Throwable $e) {
        $GLOBALS['__failed']++;
        echo "FAIL  $name\n      " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

$total = count($GLOBALS['__tests']);
echo "\nPHP tests: $passed/$total passed" . ($GLOBALS['__failed'] ? ", {$GLOBALS['__failed']} FAILED" : '') . "\n";
exit($GLOBALS['__failed'] > 0 ? 1 : 0);
