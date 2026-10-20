<?php
/**
 * 假的 Graylog，給 GraylogClient 的測試用：php -S 127.0.0.1:PORT tests/php/fake_graylog_router.php
 * 看請求內容裡 query 欄位決定回什麼；最後一次收到的請求存在暫存檔，GET /_last 取回。
 */
$expected = 'Basic ' . base64_encode('tok123:token');
$path     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$lastFile = sys_get_temp_dir() . '/ioc_fake_last_' . $_SERVER['SERVER_PORT'] . '.json';

if ($path === '/_last') {
    header('Content-Type: application/json');
    echo is_file($lastFile) ? file_get_contents($lastFile) : '{}';
    return;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== $expected) {
    http_response_code(401);
    echo json_encode(['message' => 'unauthorized']);
    return;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
file_put_contents($lastFile, json_encode([
    'path'    => $path,
    'method'  => $_SERVER['REQUEST_METHOD'],
    'body'    => $body,
    'headers' => ['x-requested-by' => $_SERVER['HTTP_X_REQUESTED_BY'] ?? '', 'content-type' => $_SERVER['CONTENT_TYPE'] ?? ''],
]));

header('Content-Type: application/json');
switch ($body['query'] ?? '') {
    case 'HTML':
        header('Content-Type: text/html');
        echo '<html>maintenance</html>';
        return;
    case 'HTTP500':
        http_response_code(500);
        echo json_encode(['message' => 'internal secret detail http://127.0.0.1:9000 tok123']);
        return;
    case 'HTTP403':
        http_response_code(403);
        echo json_encode(['message' => 'forbidden']);
        return;
    case 'HTTP400':
        http_response_code(400);
        echo json_encode(['message' => 'must not be empty']);
        return;
    case 'REDIRECT':
        header('Location: http://127.0.0.1:1/elsewhere', true, 302);
        return;
    case 'SLOW':
        // 睡得比任何合理的「機器忙而延遲」都久：逾時有沒有生效，看的是「有沒有等到伺服器睡醒」，
        // 不是跟一個很緊的秒數比（機器忙時 1 秒逾時偶爾會被拖到 3 秒，緊的門檻會偶發失敗）
        sleep(8);
        return;
    case 'BADSCHEMA':
        echo json_encode(['foo' => 1]);
        return;
    case 'EMPTYVALUE':      // 分組欄位缺值時，真的 Graylog 在該欄放字串 "(Empty Value)"（monitor-vm 畫面實測）
        echo json_encode([
            'schema' => [
                ['column_type' => 'grouping', 'type' => 'string', 'field' => 'user_name', 'name' => 'grouping: user_name'],
                ['column_type' => 'grouping', 'type' => 'string', 'field' => 'src_ip', 'name' => 'grouping: src_ip'],
                ['column_type' => 'metric', 'type' => 'numeric', 'function' => 'count', 'name' => 'metric: count()'],
            ],
            'datarows' => [['alice', '(Empty Value)', 1], ['bob', '10.0.0.1', 2], ['(Empty Value)', '(Empty Value)', 3]],
            'metadata' => [],
        ]);
        return;
}

if ($path === '/api/search/messages') {
    echo json_encode([
        'schema'   => [['type' => 'string', 'field' => 'timestamp'], ['type' => 'string', 'field' => 'user_name'], ['type' => 'string', 'field' => 'event_action']],
        'datarows' => [['2026-10-20T05:17:50.406Z', 'administrator', 'login_failed'], ['2026-10-20T05:17:40.000Z', 'tuser01', 'login_success']],
        'metadata' => [],
    ]);
    return;
}

echo json_encode([
    'schema' => [
        ['column_type' => 'grouping', 'type' => 'string', 'field' => 'action', 'name' => 'grouping: action'],
        ['column_type' => 'metric', 'type' => 'numeric', 'function' => 'count', 'name' => 'metric: count()'],
        ['column_type' => 'metric', 'type' => 'string', 'function' => 'latest', 'field' => 'timestamp', 'name' => 'metric: latest(timestamp)'],
    ],
    'datarows' => [['deny', 5, '2026-10-20T05:17:50.406Z'], ['accept', 2, '2026-10-20T05:10:00.000Z']],
    'metadata' => [],
]);
