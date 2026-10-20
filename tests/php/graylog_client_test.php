<?php
declare(strict_types=1);

use Ioc\GraylogClient;
use Ioc\GraylogUnavailable;

/** 啟動一次假的 Graylog（php -S），整個測試過程共用；結束時關掉。 */
function fakeGraylog(): array
{
    static $info = null;
    if ($info !== null) {
        return $info;
    }
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int) explode(':', stream_socket_get_name($probe, false))[1];
    fclose($probe);

    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake_graylog_router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/ioc_fake_graylog.out', 'w'], 2 => ['file', sys_get_temp_dir() . '/ioc_fake_graylog.out', 'w']],
        $pipes
    );
    register_shutdown_function(static function () use ($proc, $port): void {
        proc_terminate($proc);
        @unlink(sys_get_temp_dir() . '/ioc_fake_last_' . $port . '.json');
    });
    for ($i = 0; $i < 100; $i++) {          // 最多等 5 秒
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($s) {
            fclose($s);
            return $info = ['port' => $port, 'url' => "http://127.0.0.1:$port"];
        }
        usleep(50000);
    }
    fail('假的 Graylog 沒有起來');
}

function lastRequest(): array
{
    $json = file_get_contents(fakeGraylog()['url'] . '/_last');
    return json_decode((string) $json, true);
}

function client(array $streams = [], int $timeout = 5, string $token = 'tok123'): GraylogClient
{
    return new GraylogClient(fakeGraylog()['url'], $token, $streams, null, $timeout);
}

test('Graylog：設定檔解析只拿掉成對的外層引號、略過註解與空行', function () {
    $c = GraylogClient::parseConf("# 註解\n\nGRAYLOG_URL=http://x:9000\nGRAYLOG_TOKEN=\"abc'\"\nGRAYLOG_CA_FILE='/a b/c.pem'\nGRAYLOG_STREAMS=a, b\nPASS=ends\"\nbroken line\n");
    assertSame('http://x:9000', $c['GRAYLOG_URL']);
    assertSame("abc'", $c['GRAYLOG_TOKEN']);
    assertSame('/a b/c.pem', $c['GRAYLOG_CA_FILE']);
    assertSame('a, b', $c['GRAYLOG_STREAMS']);
    assertSame('ends"', $c['PASS'], '結尾的單一引號不能被吃掉');
    assertTrue(!isset($c['broken line']));
});

test('Graylog：設定檔不存在或不完整時，是「無法取得」而不是丟出一般錯誤', function () {
    assertThrows(GraylogUnavailable::class, fn () => GraylogClient::fromConf('/nonexistent/graylog-reader.conf'), '尚未設定');
    $f = tempnam(sys_get_temp_dir(), 'ioc');
    file_put_contents($f, "GRAYLOG_URL=http://x\n");
    assertThrows(GraylogUnavailable::class, fn () => GraylogClient::fromConf($f), '不完整');
    file_put_contents($f, "GRAYLOG_URL=ftp://x\nGRAYLOG_TOKEN=t\n");
    assertThrows(GraylogUnavailable::class, fn () => GraylogClient::fromConf($f), '不正確');
    file_put_contents($f, "GRAYLOG_URL=http://127.0.0.1:9\nGRAYLOG_TOKEN=t\nGRAYLOG_STREAMS=s1, s2 ,\n");
    assertTrue(GraylogClient::fromConf($f) instanceof GraylogClient);
    unlink($f);
});

test('Graylog：http 只能連本機，其他主機一定要 https（Token 在每個請求的標頭裡）', function () {
    foreach (['http://127.0.0.1:9000', 'http://localhost:9000', 'http://[::1]:9000', 'https://graylog.example:9000', 'https://10.1.2.3:9000/'] as $ok) {
        assertTrue(new GraylogClient($ok, 't') instanceof GraylogClient, "{$ok} 應該可用");
    }
    $bad = ['http://10.1.2.3:9000', 'http://graylog.example', 'http://127.0.0.1.evil.example/', 'http://127.0.0.1@evil.example/',
            'http://[2001:db8::1]:9000', 'ftp://127.0.0.1', 'https://', '127.0.0.1:9000', ''];
    foreach ($bad as $url) {
        $threw = false;
        try {
            new GraylogClient($url, 't');
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        assertTrue($threw, "{$url} 應該被拒絕");
    }
    $f = tempnam(sys_get_temp_dir(), 'ioc');
    file_put_contents($f, "GRAYLOG_URL=http://10.1.2.3:9000\nGRAYLOG_TOKEN=t\n");
    assertThrows(GraylogUnavailable::class, fn () => GraylogClient::fromConf($f), '不正確');   // 設定檔寫了遠端 http：入口顯示無法取得，不送 Token
    unlink($f);
});

test('Graylog：aggregate 送出的請求內容，與回傳列的鍵名', function () {
    $rows = client(['s1', 's2'])->aggregate('event_action:fw_deny', 3600, ['action'], ['count', 'latest:timestamp'], 50);
    assertSame([['action' => 'deny', 'count' => 5, 'latest_timestamp' => '2026-10-20T05:17:50.406Z'],
                ['action' => 'accept', 'count' => 2, 'latest_timestamp' => '2026-10-20T05:10:00.000Z']], $rows);
    $r = lastRequest();
    assertSame('/api/search/aggregate', $r['path']);
    assertSame('POST', $r['method']);
    assertSame('ioc-portal', $r['headers']['x-requested-by'], 'Graylog 的寫入類請求要 X-Requested-By');
    assertTrue(mb_strpos($r['headers']['content-type'], 'application/json') === 0);
    assertSame('event_action:fw_deny', $r['body']['query']);
    assertSame(['type' => 'relative', 'range' => 3600], $r['body']['timerange']);
    assertSame([['field' => 'action', 'limit' => 50]], $r['body']['group_by']);
    assertSame([['function' => 'count'], ['function' => 'latest', 'field' => 'timestamp']], $r['body']['metrics']);
    assertSame(['s1', 's2'], $r['body']['streams']);
});

test('Graylog：沒指定 stream 時不送 streams 欄位（查帳號有權限的全部）', function () {
    client([])->aggregate('x', 60, ['action'], ['count']);
    assertTrue(!array_key_exists('streams', lastRequest()['body']));
});

test('Graylog：分組欄位缺值時的 "(Empty Value)" 變成 null，指標欄位不受影響', function () {
    $rows = client()->aggregate('EMPTYVALUE', 60, ['user_name', 'src_ip'], ['count']);
    assertSame([
        ['user_name' => 'alice', 'src_ip' => null, 'count' => 1],
        ['user_name' => 'bob', 'src_ip' => '10.0.0.1', 'count' => 2],
        ['user_name' => null, 'src_ip' => null, 'count' => 3],
    ], $rows);
});

test('Graylog：aggregate 沒有 group_by 會被拒絕，因為 Graylog 一定要有', function () {
    assertThrows(InvalidArgumentException::class, fn () => client()->aggregate('x', 60, [], ['count']));
});

test('Graylog：messages 送出的請求內容，與回傳列的鍵名', function () {
    $rows = client(['s1'])->messages('_exists_:event_action', 86400, ['timestamp', 'user_name', 'event_action'], 15);
    assertSame(2, count($rows));
    assertSame(['timestamp' => '2026-10-20T05:17:50.406Z', 'user_name' => 'administrator', 'event_action' => 'login_failed'], $rows[0]);
    $r = lastRequest();
    assertSame('/api/search/messages', $r['path']);
    assertSame(15, $r['body']['size']);
    assertSame(['timestamp', 'user_name', 'event_action'], $r['body']['fields']);
    assertSame('timestamp', $r['body']['sort']);
    assertSame('Descending', $r['body']['sort_order']);
});

test('Graylog：每一種失敗都變成「無法取得」，訊息說得出原因，而且不外洩網址與 Token', function () {
    $cases = [
        'HTML'     => 'Graylog 回應格式不符',
        'BADSCHEMA' => 'Graylog 回應格式不符',
        'HTTP500'  => 'HTTP 500',
        'HTTP403'  => '沒有權限',
        'HTTP400'  => 'must not be empty',
        'REDIRECT' => 'HTTP 302',
    ];
    foreach ($cases as $query => $expect) {
        $e = assertThrows(GraylogUnavailable::class, fn () => client()->aggregate($query, 60, ['a'], ['count']), $expect);
        foreach (['tok123', '127.0.0.1', 'secret'] as $leak) {
            assertTrue(mb_strpos($e->getMessage(), $leak) === false, "{$query} 的錯誤訊息洩漏了 {$leak}：" . $e->getMessage());
        }
    }
});

test('Graylog：Token 錯誤是 401，訊息指向「Token 已失效」', function () {
    $e = assertThrows(GraylogUnavailable::class, fn () => client([], 5, 'wrong')->aggregate('x', 60, ['a'], ['count']), 'HTTP 401');
    assertTrue(mb_strpos($e->getMessage(), 'Token') !== false);
});

test('Graylog：不跟隨重導向（不會連到另一個位址）', function () {
    // 重導向的目的地是關閉的 port；若有跟隨，錯誤會變成「連線失敗」而不是「HTTP 302」
    assertThrows(GraylogUnavailable::class, fn () => client()->aggregate('REDIRECT', 60, ['a'], ['count']), 'HTTP 302');
});

test('Graylog：逾時有上界，回報逾時', function () {
    $t = microtime(true);
    assertThrows(GraylogUnavailable::class, fn () => client([], 1)->aggregate('SLOW', 60, ['a'], ['count']), '逾時');
    assertTrue(microtime(true) - $t < 2.8, '逾時要在設定的秒數內回來，實際花了 ' . round(microtime(true) - $t, 1) . ' 秒');
});

test('Graylog：連不上時回報連線失敗，不洩漏位址', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int) explode(':', stream_socket_get_name($probe, false))[1];
    fclose($probe);                          // 關掉後這個 port 就沒人聽
    $c = new GraylogClient("http://127.0.0.1:$port", 'tok123', [], null, 2);
    $e = assertThrows(GraylogUnavailable::class, fn () => $c->aggregate('x', 60, ['a'], ['count']), '連線失敗');
    assertTrue(mb_strpos($e->getMessage(), '127.0.0.1') === false);
});

// 放在最後：假伺服器（php -S）一次只處理一個請求，SLOW 會讓它忙 3 秒，不要拖慢前面的測試
test('Graylog：整次載入共用時間預算，用完後的查詢直接回報逾時，不再各等一輪', function () {
    $c = new GraylogClient(fakeGraylog()['url'], 'tok123', [], null, 5, 1);   // 單次最多 5 秒，但總預算只有 1 秒
    $t = microtime(true);
    assertThrows(GraylogUnavailable::class, fn () => $c->aggregate('SLOW', 60, ['a'], ['count']), '逾時');
    $first = microtime(true) - $t;
    assertTrue($first < 1.8, '第一次應在預算內回來，實際花了 ' . round($first, 1) . ' 秒');

    $t = microtime(true);
    assertThrows(GraylogUnavailable::class, fn () => $c->aggregate('x', 60, ['a'], ['count']), '逾時');
    assertTrue(microtime(true) - $t < 0.3, '預算用完後不應該再連線等待');
});
