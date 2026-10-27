<?php
declare(strict_types=1);

use Ioc\BehaviorVizExport;
use Ioc\ValidationError;

test('連線視圖匯出：主機位址遮罩（IPv4 末段、IPv6 第四組之後）；不是合法位址不輸出任何東西', static function (): void {
    assertSame('172.16.111.x', BehaviorVizExport::address('172.16.111.2', true));
    assertSame('172.16.111.2', BehaviorVizExport::address('172.16.111.2', false));
    assertSame('2001:0db8:0001:x', BehaviorVizExport::address('2001:db8:1:2::5', true));
    assertSame('2001:db8:1:2::5', BehaviorVizExport::address('2001:db8:1:2::5', false));
    foreach (['', 'abc', '999.1.1.1', '10.0.0', '010.1.2.3', "10.0.0.1\n", '10.0.0.1 ', '<img src=x>'] as $bad) {
        assertSame('—', BehaviorVizExport::address($bad, true), '不合法的位址：' . json_encode($bad));
        assertSame('—', BehaviorVizExport::address($bad, false));
    }
});

test('連線視圖匯出：目的地遮罩併成 /24（IPv6 是 /48），不合法的直接略過', static function (): void {
    assertSame('203.0.113.0/24', BehaviorVizExport::destination('203.0.113.77', true));
    assertSame(BehaviorVizExport::destination('203.0.113.5', true), BehaviorVizExport::destination('203.0.113.200', true), '同一個 /24 併成一組');
    assertSame('203.0.113.77', BehaviorVizExport::destination('203.0.113.77', false));
    assertSame('2001:0db8:0001::/48', BehaviorVizExport::destination('2001:db8:1:2::5', true));
    assertSame('2001:db8:1:2::5', BehaviorVizExport::destination('2001:db8:1:2::5', false));
    foreach (['', 'abc', '203.0.113', '203.0.113.256', "203.0.113.5\n", '<script>'] as $bad) {
        assertSame(null, BehaviorVizExport::destination($bad, true));
        assertSame(null, BehaviorVizExport::destination($bad, false));
    }
});

test('連線視圖匯出：主機代號 A…Z、AA…、中位數', static function (): void {
    foreach ([[0, 'A'], [1, 'B'], [25, 'Z'], [26, 'AA'], [27, 'AB'], [51, 'AZ'], [52, 'BA'], [701, 'ZZ'], [702, 'AAA']] as [$n, $s]) {
        assertSame($s, BehaviorVizExport::letters($n), (string) $n);
    }
    assertSame(0.0, BehaviorVizExport::median([]));
    assertSame(5.0, BehaviorVizExport::median([5]));
    assertSame(3.0, BehaviorVizExport::median([9, 1, 3]));
    assertSame(2.5, BehaviorVizExport::median([4, 1, 2, 3]));
});

test('連線視圖匯出：選項有預設與上下限，型別不對一律拒絕（字串數字也不收）', static function (): void {
    $d = BehaviorVizExport::normalizeOptions(['now' => 1000]);
    assertSame([24, [], 8, 12, true, 1000], [$d['hours'], $d['assets'], $d['top'], $d['max_assets'], $d['mask'], $d['now']]);
    assertSame(false, BehaviorVizExport::normalizeOptions(['mask' => false, 'now' => 1])['mask']);
    assertSame(true, BehaviorVizExport::normalizeOptions(['mask' => null, 'now' => 1])['mask'], '只有明確的 false 才不遮罩');
    assertSame([3, 1], BehaviorVizExport::normalizeOptions(['assets' => [3, 1, 3], 'now' => 1])['assets'], '去重、保留順序');
    foreach ([['hours' => 0], ['hours' => 169], ['hours' => '24'], ['hours' => 1.5], ['top' => 0], ['top' => 41], ['max_assets' => 0], ['max_assets' => 51],
        ['assets' => [0]], ['assets' => [-1]], ['assets' => ['1']], ['assets' => 5], ['assets' => range(1, 51)], ['now' => -1], ['now' => '1']] as $bad) {
        assertThrows(ValidationError::class, fn () => BehaviorVizExport::normalizeOptions($bad + ['now' => 1]), '', json_encode($bad));
    }
    foreach ([['hours' => 1], ['hours' => 168], ['top' => 1], ['top' => 40], ['max_assets' => 50]] as $ok) {
        BehaviorVizExport::normalizeOptions($ok + ['now' => 1]);
    }
});
