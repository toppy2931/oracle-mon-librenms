<?php
declare(strict_types=1);

test('稽核控制項匯入接受 UTF-8 BOM 並固定代碼排序', static function (): void {
    $csv = "\xEF\xBB\xBFcode,domain,title,requirement,description\nB,網路,第二項,須記錄,說明\nA,身分,第一項,須驗證,\n";
    $rows = \Ioc\AuditEvidenceService::parseCsv($csv);
    assertSame(2, count($rows));
    assertSame('A', $rows[0]['code']);
    assertSame('網路', $rows[1]['domain']);
});

test('稽核控制項匯入拒絕重複代碼與空白要求', static function (): void {
    assertThrows(\Ioc\ValidationError::class, static fn () => \Ioc\AuditEvidenceService::parseCsv(
        "code,domain,title,requirement,description\nA,網路,一,要求,\nA,網路,二,要求,\n"), '重複');
    assertThrows(\Ioc\ValidationError::class, static fn () => \Ioc\AuditEvidenceService::parseCsv(
        "code,domain,title,requirement,description\nA,網路,一,,\n"), '要求');
});

test('私有證據快照下載前驗證大小與雜湊，不能用路徑跳脫', static function (): void {
    $root = sys_get_temp_dir() . '/ioc-audit-test-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    try {
        $store = new \Ioc\AuditSnapshotStore($root);
        assertThrows(\Ioc\IocError::class, static fn () => $store->put(''), '快照超過');
        assertThrows(\Ioc\IocError::class, static fn () => $store->put(str_repeat('x', 2 * 1024 * 1024 + 1)), '快照超過');
        $saved = $store->put('{"items":[]}');
        assertSame('{"items":[]}', $store->get($saved['name'], $saved['sha256'], $saved['bytes']));
        assertThrows(\Ioc\IocError::class, static fn () => $store->get('../config.php', $saved['sha256'], $saved['bytes']));
        file_put_contents($root . '/' . $saved['name'], '{"items":{}}');
        assertThrows(\Ioc\IocError::class, static fn () => $store->get($saved['name'], $saved['sha256'], $saved['bytes']), '驗證失敗');
        chmod($root . '/' . $saved['name'], 0644);
        assertThrows(\Ioc\IocError::class, static fn () => $store->get($saved['name'], $saved['sha256'], $saved['bytes']), '遭更動');
        $store->discard($saved['name']);
    } finally {
        rmdir($root);
    }
});

test('LibreNMS 的 Linux 發行版與未確認伺服器都納入涵蓋清單', static function (): void {
    $device = (object) ['device_id' => 22, 'hostname' => '192.0.2.22', 'sysName' => '',
        'display' => '', 'type' => 'server', 'os' => 'ubuntu', 'overwrite_ip' => null, 'ip' => null];
    $known = \Ioc\AuditAssetDiscovery::fromLibreNms($device);
    assertSame('linux', $known['kind']);
    assertSame(true, $known['os_confirmed']);
    $device->os = 'generic';
    $pending = \Ioc\AuditAssetDiscovery::fromLibreNms($device);
    assertSame('linux', $pending['kind']);
    assertSame(false, $pending['os_confirmed']);
    $device->type = 'firewall';
    assertSame('network', \Ioc\AuditAssetDiscovery::fromLibreNms($device)['kind']);
});
