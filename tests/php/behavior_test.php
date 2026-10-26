<?php
declare(strict_types=1);

use Ioc\BehaviorAnalyzer;
use Ioc\BehaviorWebhook;

test('行為基線採中位數、P95 與 MAD', static function (): void {
    $s = BehaviorAnalyzer::stats([1, 2, 3, 4]);
    assertSame(2.5, $s['median']);
    assertSame(4.0, $s['p95']);
    assertSame(1.0, $s['mad']);
    assertSame(['median' => 0.0, 'p95' => 0.0, 'mad' => 0.0], BehaviorAnalyzer::stats([]));
});

test('非上班時段流量突增會給可解釋指標', static function (): void {
    $baseline = ['sample_count' => 10, 'p95_bytes' => 1000000, 'p95_destinations' => 3, 'p95_connections' => 5];
    $window = ['quality' => 'complete', 'bytes_total' => 250000000, 'connections' => 100, 'destinations' => 25, 'ports' => 4];
    $findings = BehaviorAnalyzer::evaluate($window, $baseline, true, ['203.0.113.1'], ['8443']);
    assertSame(2, count($findings));
    assertSame('after_hours_surge', $findings[0]['detector']);
    assertSame(250000000, $findings[0]['metrics']['bytes']);
    assertSame('new_external_pattern', $findings[1]['detector']);
    assertSame(1, $findings[1]['metrics']['new_destination_count']);
});

test('資料品質不足、上班時間及樣本不足不能產生警示', static function (): void {
    $baseline = ['sample_count' => 10, 'p95_bytes' => 1000000, 'p95_destinations' => 3, 'p95_connections' => 5];
    $window = ['quality' => 'complete', 'bytes_total' => 250000000, 'connections' => 100, 'destinations' => 25, 'ports' => 4];
    assertSame([], BehaviorAnalyzer::evaluate($window, $baseline, false, [], []));
    $window['quality'] = 'partial';
    assertSame([], BehaviorAnalyzer::evaluate($window, $baseline, true, [], []));
    $window['quality'] = 'complete';
    $baseline['sample_count'] = 5;
    assertSame([], BehaviorAnalyzer::evaluate($window, $baseline, true, [], []));
});

test('例行離峰維運低於基線門檻時不產生警示', static function (): void {
    $baseline = ['sample_count' => 20, 'p95_bytes' => 30000000, 'p95_destinations' => 4, 'p95_connections' => 12];
    $window = ['quality' => 'complete', 'bytes_total' => 12000000, 'connections' => 15,
        'destinations' => 2, 'ports' => 2];
    assertSame([], BehaviorAnalyzer::evaluate($window, $baseline, true, [], []));
});

test('AI 契約只含統計特徵，不含裝置與目的地識別', static function (): void {
    $baseline = ['p95_bytes' => 100, 'p95_connections' => 2];
    $window = ['quality' => 'complete', 'bytes_total' => 300, 'connections' => 4, 'destinations' => 2,
        'ports' => 1, 'src_ip' => '172.16.1.5', 'dst_ip' => '203.0.113.9', 'user' => 'someone'];
    $features = BehaviorAnalyzer::aiFeatures($window, $baseline);
    assertSame(1, $features['schema_version']);
    assertTrue(!isset($features['src_ip']) && !isset($features['dst_ip']) && !isset($features['user']));
});

test('Webhook 設定只接受不含帳密或片段的 HTTPS 與 32 位元組密鑰', static function (): void {
    $secret = str_repeat('ab', 32);
    $valid = BehaviorWebhook::parseConfig("WEBHOOK_URL=https://notify.example.test/ioc\nWEBHOOK_SECRET_HEX=$secret\n");
    assertSame('https://notify.example.test/ioc', $valid['url']);
    assertSame(32, strlen($valid['secret']));
    foreach (['http://notify.example.test/ioc', 'https://u:p@notify.example.test/ioc',
        'https://notify.example.test/ioc#fragment'] as $url) {
        assertSame(null, BehaviorWebhook::parseConfig("WEBHOOK_URL=$url\nWEBHOOK_SECRET_HEX=$secret\n"));
    }
    assertSame(null, BehaviorWebhook::parseConfig("WEBHOOK_URL=https://notify.example.test/ioc\nWEBHOOK_SECRET_HEX=short\n"));
});
