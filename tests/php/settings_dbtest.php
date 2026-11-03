<?php
declare(strict_types=1);

/**
 * 服務水準設定（SettingsService::saveServiceLevel／serviceLevelSettings／overviewSettings）對真的 MariaDB 的行為：
 * 三個設定值在同一個交易裡寫入、整理後沒變動不留紀錄、異動紀錄依設定名稱篩選（SQL 的 IN）、資料表裡的值壞了時的處理。
 * 一次性的 ioc_test_* schema（tests/run-db-tests.sh）；單元測試用的是記憶體版資料存取，驗證不到這些 SQL。
 */

use Ioc\AccessDenied;
use Ioc\PdoSettingsRepo;
use Ioc\SettingsService;
use Ioc\ValidationError;

function sdbService(PDO $db): SettingsService
{
    return new SettingsService(new PdoSettingsRepo($db));
}

dbtest('服務水準：儲存、讀回（核心設備整理成不重複、由小到大）、清空；三個值各留一筆異動紀錄', static function (PDO $db): void {
    $svc = sdbService($db);
    $r = $svc->saveServiceLevel(['target' => '99.50', 'window' => '24x7', 'core' => '9, 3, 3, 12'], 'alice', DBT_H);
    assertSame([99.5, '24x7', [3, 9, 12], true, false], [$r['target'], $r['window'], $r['core'], $r['custom'], $r['invalid']]);
    assertSame('alice', $r['updated_by']);
    assertSame(['99.5', '24x7', '3,9,12'], [dbtRow($db, "SELECT svalue FROM ioc_settings WHERE skey = 'sla.target'")['svalue'], dbtRow($db, "SELECT svalue FROM ioc_settings WHERE skey = 'sla.window'")['svalue'], dbtRow($db, "SELECT svalue FROM ioc_settings WHERE skey = 'sla.core_devices'")['svalue']], '存的是整理後的值（99.50 → 99.5）');
    assertSame(3, count($r['history']), '三個值各一筆異動紀錄');
    assertTrue(in_array('SLA 可用率目標（F-20）', array_column($r['history'], 'label'), true), '異動紀錄的項目名稱要是中文標籤');
    $r =$svc->saveServiceLevel(['target' => '', 'window' => '', 'core' => ''], 'bob', DBT_H);
    assertSame([null, null, []], [$r['target'], $r['window'], $r['core']], '清空＝尚未設定');
    assertSame(6, count($r['history']), '清空又是三筆');
    assertSame('', $r['history'][0]['to']);
});

dbtest('服務水準：整理後沒有變動就什麼都不寫；只改一個值只多一筆紀錄', static function (PDO $db): void {
    $svc = sdbService($db);
    $svc->saveServiceLevel(['target' => '99.5', 'window' => '24x7', 'core' => '3,9,12'], 'alice', DBT_H);
    $count = fn () => (int) dbtRow($db, 'SELECT COUNT(*) AS n FROM ioc_settings_history')['n'];
    $n = $count();
    $svc->saveServiceLevel(['target' => '99.500', 'window' => '24x7', 'core' => '12, 9,3'], 'alice', DBT_H);
    assertSame($n, $count(), '整理後完全一樣：不寫、不留紀錄');
    $svc->saveServiceLevel(['target' => '99.9', 'window' => '24x7', 'core' => '3,9,12'], 'alice', DBT_H);
    assertSame($n + 1, $count(), '只改目標：只多一筆');
});

dbtest('服務水準：不合法的值整組被擋下（三個欄位的錯誤一起回報），資料與異動紀錄都不變', static function (PDO $db): void {
    $svc = sdbService($db);
    $svc->saveServiceLevel(['target' => '99.5', 'window' => '24x7', 'core' => '3'], 'alice', DBT_H);
    $n = (int) dbtRow($db, 'SELECT COUNT(*) AS n FROM ioc_settings_history')['n'];
    foreach ([['target' => '101'], ['target' => '0'], ['target' => '99.9999'], ['window' => 'always'], ['core' => '0'], ['core' => '1,x'], ['core' => implode(',', range(1, SettingsService::SLA_MAX_CORE + 1))]] as $bad) {
        try {
            $svc->saveServiceLevel($bad + ['target' => '99', 'window' => '24x7', 'core' => '1'], 'alice', DBT_H);
        } catch (ValidationError $e) {
            continue;
        }
        fail('應該被拒絕：' . substr(json_encode($bad), 0, 60));
    }
    try {
        $svc->saveServiceLevel(['target' => 'a', 'window' => 'b', 'core' => 'c'], 'alice', DBT_H);
        fail('應該被拒絕');
    } catch (ValidationError $e) {
        assertSame(3, count($e->errors()), '三個欄位的錯誤一起回報');
    }
    assertSame([99.5, '24x7', [3]], [$svc->serviceLevelSettings(DBT_H)['target'], $svc->serviceLevelSettings(DBT_H)['window'], $svc->serviceLevelSettings(DBT_H)['core']], '被拒絕的儲存不能改到資料');
    assertSame($n, (int) dbtRow($db, 'SELECT COUNT(*) AS n FROM ioc_settings_history')['n']);
});

dbtest('服務水準：檢視者讀得到、不能存；異動紀錄只含服務水準的三個設定（其他設定的異動不混進來）', static function (PDO $db): void {
    $svc = sdbService($db);
    $svc->saveRisk(['risk_low' => '90', 'risk_medium' => '70'], 'alice', DBT_H);
    $svc->saveServiceLevel(['target' => '99', 'window' => '24x7', 'core' => '5'], 'alice', DBT_H);
    $v = $svc->serviceLevelSettings(DBT_V);
    assertSame([99.0, '24x7', [5]], [$v['target'], $v['window'], $v['core']]);
    assertSame(3, count($v['history']), '風險門檻的兩筆紀錄不能混進服務水準的紀錄（SQL 的 IN 篩選）');
    assertThrows(AccessDenied::class, fn () => $svc->saveServiceLevel(['target' => '98', 'window' => '24x7', 'core' => '5'], 'v', DBT_V));
    assertThrows(AccessDenied::class, fn () => $svc->serviceLevelSettings(null));
    assertSame(99.0, $svc->serviceLevelSettings(DBT_H)['target'], '被拒絕的儲存不能改到資料');
});

dbtest('服務水準：資料表裡的值壞了（手動改過）＝整組 invalid，總覽的設定讀取是 null 加原因；重新儲存就修復', static function (PDO $db): void {
    $svc = sdbService($db);
    $svc->saveServiceLevel(['target' => '99', 'window' => '24x7', 'core' => '5'], 'alice', DBT_H);
    $db->exec("UPDATE ioc_settings SET svalue = 'abc' WHERE skey = 'sla.target'");
    $s = $svc->serviceLevelSettings(DBT_H);
    assertSame([true, null, null, []], [$s['invalid'], $s['target'], $s['window'], $s['core']], '只用讀得懂的那幾個也不行：整組標壞、都當尚未設定顯示');
    $o = $svc->overviewSettings(DBT_V);
    assertSame(null, $o['sla'], '總覽拿到的是 null（無法取得），不是預設值');
    assertTrue(isset($o['errors']['sla']), '帶著原因');
    $r = $svc->saveServiceLevel(['target' => '99', 'window' => '24x7', 'core' => '5'], 'alice', DBT_H);
    assertSame([false, 99.0], [$r['invalid'], $r['target']], '壞掉時就算填的跟正常值一樣也要寫入，重新儲存就修復');
    assertSame([99.0, '24x7', [5]], [$svc->overviewSettings(DBT_V)['sla']['target'], $svc->overviewSettings(DBT_V)['sla']['window'], $svc->overviewSettings(DBT_V)['sla']['core']]);
});

dbtest('服務水準：中文與 emoji 不相關，但異動紀錄的時間是 UTC、顯示轉成台北時間', static function (PDO $db): void {
    $svc = sdbService($db);
    $svc->saveServiceLevel(['target' => '99', 'window' => '', 'core' => ''], 'alice', DBT_H);
    $utc = dbtRow($db, "SELECT ts FROM ioc_settings_history WHERE skey = 'sla.target'")['ts'];
    $shown = $svc->serviceLevelSettings(DBT_H)['history'][0]['time'];
    assertSame(gmdate('Y-m-d H:i:s', strtotime($utc . ' UTC') + 8 * 3600), $shown, '顯示時間要比資料庫的 UTC 晚 8 小時');
});

dbtest('服務水準：核心設備清單放得進欄位（VARCHAR(255)）——台數上限與字元上限都能存、讀回完全一樣；超過的在驗證就被擋，不會被資料庫截斷', static function (PDO $db): void {
    $svc = sdbService($db);
    $db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
    $forty = range(10, 9 + SettingsService::SLA_MAX_CORE);
    $r = $svc->saveServiceLevel(['target' => '99', 'window' => '24x7', 'core' => implode(',', $forty)], 'alice', DBT_H);
    assertSame($forty, $r['core'], '台數上限：存得進、讀回一樣');
    $nine = array_map(fn ($i) => 100000000 + $i, range(1, 25));       // 25 個九位數編號＝249 個字元
    $r = $svc->saveServiceLevel(['target' => '99', 'window' => '24x7', 'core' => implode(',', $nine)], 'alice', DBT_H);
    assertSame($nine, $r['core'], '接近 255 個字元：存得進、讀回一樣（沒有被截斷）');
    assertSame(strlen(implode(',', $nine)), strlen((string) dbtRow($db, "SELECT svalue FROM ioc_settings WHERE skey = 'sla.core_devices'")['svalue']));
    $tooLong = array_map(fn ($i) => 100000000 + $i, range(1, 26));    // 259 個字元
    try {
        $svc->saveServiceLevel(['target' => '99', 'window' => '24x7', 'core' => implode(',', $tooLong)], 'alice', DBT_H);
        fail('超過欄位寬度要在驗證就被擋下');
    } catch (ValidationError $e) {
    }
    assertSame($nine, $svc->serviceLevelSettings(DBT_H)['core'], '被擋下的儲存不能改到資料');
});
