<?php
declare(strict_types=1);
use Ioc\Access;
use Ioc\AccessDenied;
use Ioc\ConfigBackupService;
use Ioc\IocError;
use Ioc\ValidationError;

function cbSettings(): array {
    return ['enabled'=>true,'interval_hours'=>24,'devices'=>[['device_id'=>7,'name'=>'偽造','host'=>'attacker.example','model'=>'ios','username'=>'backup','password'=>'secret-123','port'=>22,'enabled'=>true]]];
}
function cbService(callable $transport): ConfigBackupService {
    return new ConfigBackupService($transport, [['device_id'=>7,'name'=>'交換器','host'=>'192.0.2.7']]);
}
function cbReply(): array {
    $settings=cbSettings();
    $settings['devices'][0]['password_set']=true;
    return ['ok'=>true,'data'=>['settings'=>$settings,'engine'=>['state'=>'ready','message'=>'secret-123'],'runs'=>[],'changes'=>[]]];
}

test('組態備份：所有動作先拒絕非管理者，完全不呼叫工具', function () {
    $calls=0;
    $svc=cbService(function () use (&$calls) { $calls++; return cbReply(); });
    foreach ([null,Access::VIEWER] as $level) {
        assertThrows(AccessDenied::class,fn()=>$svc->status($level));
        assertThrows(AccessDenied::class,fn()=>$svc->save([], '',$level));
        assertThrows(AccessDenied::class,fn()=>$svc->test(-1,'',$level));
        assertThrows(AccessDenied::class,fn()=>$svc->run(-1,'',$level));
    }
    assertSame(0,$calls);
});
test('組態備份：瀏覽器不能改寫設備IP，密碼只送工具不回傳',function () {
    $request=null;
    $svc=cbService(function ($r) use (&$request) { $request=$r; return cbReply(); });
    $data=$svc->save(cbSettings(),'alice',Access::HANDLER);
    assertSame('192.0.2.7',$request['settings']['devices'][0]['host']);
    assertSame('交換器',$request['settings']['devices'][0]['name']);
    assertSame('secret-123',$request['settings']['devices'][0]['password']);
    assertSame('alice',$request['actor']);
    assertTrue(strpos(json_encode($data),'secret-123')===false);
    assertSame(true,$data['settings']['devices'][0]['password_set']);
});
test('組態備份：拒絕未知設備與重複設備且不呼叫工具',function () {
    $calls=0;$svc=cbService(function()use(&$calls){$calls++;return cbReply();});
    $s=cbSettings();$s['devices'][0]['device_id']=8;
    assertThrows(ValidationError::class,fn()=>$svc->save($s,'a',Access::HANDLER));
    $s=cbSettings();$s['devices'][]=$s['devices'][0];
    assertThrows(ValidationError::class,fn()=>$svc->save($s,'a',Access::HANDLER));
    assertSame(0,$calls);
});
test('組態備份：排程、品牌、連接埠、布林及憑證嚴格驗證',function(){
    $svc=cbService(fn()=>cbReply());
    foreach ([0,169,'24',null] as $value) {$s=cbSettings();$s['interval_hours']=$value;assertThrows(ValidationError::class,fn()=>$svc->save($s,'a',Access::HANDLER));}
    foreach (['model'=>'unsupported','port'=>23,'enabled'=>'true','username'=>"a\nb",'password'=>"a\0b"] as $key=>$value) {$s=cbSettings();$s['devices'][0][$key]=$value;assertThrows(ValidationError::class,fn()=>$svc->save($s,'a',Access::HANDLER));}
    $s=cbSettings();$s['enabled']='false';assertThrows(ValidationError::class,fn()=>$svc->save($s,'a',Access::HANDLER));
    $s=cbSettings();$s['devices']=array_fill(0,51,$s['devices'][0]);assertThrows(ValidationError::class,fn()=>$svc->save($s,'a',Access::HANDLER));
});
test('組態備份：空密碼代表沿用，測試與備份只送設備編號',function(){
    $requests=[];$svc=cbService(function($r)use(&$requests){$requests[]=$r;return cbReply();});
    $s=cbSettings();$s['devices'][0]['password']='';$svc->save($s,'a',Access::HANDLER);
    assertSame('',$requests[0]['settings']['devices'][0]['password']);
    $svc->test(7,'a',Access::HANDLER);$svc->run(7,'a',Access::HANDLER);
    assertSame('test',$requests[1]['action']);assertSame(7,$requests[1]['device_id']);assertSame('run',$requests[2]['action']);
    assertTrue(!isset($requests[1]['settings']));
});
test('組態備份：工具失敗與例外一律安全回覆',function(){
    foreach ([fn()=>['ok'=>false,'error'=>['code'=>'failure','message'=>'secret-123']],fn()=>['ok'=>true],function(){throw new RuntimeException('secret-123');}] as $transport) {
        $e=assertThrows(IocError::class,fn()=>cbService($transport)->status(Access::HANDLER));
        assertTrue(strpos($e->getMessage(),'secret-123')===false);
    }
});
test('組態備份：工具回傳只保留白名單與安全摘要',function(){
    $reply=cbReply();$reply['data']['secret']='secret-123';
    $reply['data']['runs']=[['id'=>1,'device_id'=>7,'device_name'=>'交換器','kind'=>'manual','status'=>'failed','requested_at'=>'2026-10-22 00:00:00','completed_at'=>null,'message'=>'secret-123','password'=>'secret-123']];
    $reply['data']['changes']=[['id'=>2,'device_id'=>7,'device_name'=>'交換器','detected_at'=>'2026-10-22 00:00:00','added'=>2,'removed'=>1,'diff'=>'secret-123']];
    $data=cbService(fn()=>$reply)->status(Access::HANDLER);
    assertTrue(strpos(json_encode($data),'secret-123')===false);
    assertSame(2,$data['changes'][0]['added']);
    assertSame('failed',$data['runs'][0]['status']);
});

test('組態備份：差異保留一般修改行、遮罩秘密與整段私鑰',function(){
    $reply=cbReply();
    $reply['data']['changes']=[['diff'=>"+hostname branch-switch\n+username admin secret real-password\n-----BEGIN RSA PRIVATE KEY-----\nVERY-SENSITIVE-KEY-CONTENT\n-----END RSA PRIVATE KEY-----\n-interface old\n+interface new"]];
    $data=cbService(fn()=>$reply)->status(Access::HANDLER);
    $diff=$data['changes'][0]['diff'];
    assertTrue(strpos($diff,'+hostname branch-switch')!==false);
    assertTrue(strpos($diff,'+interface new')!==false);
    assertTrue(strpos($diff,'real-password')===false);
    assertTrue(strpos($diff,'VERY-SENSITIVE-KEY-CONTENT')===false);
});

test('組態備份：接受 255 字元操作者，拒絕超長或控制字元', function () {
    $svc = cbService(fn () => cbReply());
    $svc->save(cbSettings(), str_repeat('管', 255), Access::HANDLER);
    foreach ([str_repeat('a', 256), "a\nb", ''] as $actor) {
        assertThrows(ValidationError::class, fn () => $svc->save(cbSettings(), $actor, Access::HANDLER));
    }
});

test('組態備份：憑證長度以字元計算，CSV 插值與分隔符不可通過', function () {
    $svc = cbService(fn () => cbReply());
    $settings = cbSettings();
    $settings['devices'][0]['username'] = str_repeat('管', 128);
    $settings['devices'][0]['password'] = str_repeat('密', 512);
    $svc->save($settings, 'admin', Access::HANDLER);
    foreach ([['username', str_repeat('a', 129)], ['password', str_repeat('密', 513)], ['password', 'abc|def'], ['password', '#{danger}'], ['username', '#{danger}']] as [$key, $value]) {
        $settings = cbSettings();
        $settings['devices'][0][$key] = $value;
        assertThrows(ValidationError::class, fn () => $svc->save($settings, 'admin', Access::HANDLER));
    }
});

test('組態備份：後端可控錯誤使用本地安全中文，不透傳工具訊息', function () {
    $svc = cbService(fn () => ['ok' => false, 'error' => ['code' => 'password_required', 'message' => 'SECRET']]);
    assertThrows(IocError::class, fn () => $svc->save(cbSettings(), 'admin', Access::HANDLER), '首次設定');
});

test('組態備份：常見金鑰指令與過期引擎狀態不洩密、不誤判已套用', function () {
    $reply = cbReply();
    $reply['data']['engine'] = ['state' => 'stale', 'applied' => true];
    $reply['data']['changes'] = [['diff' => "+set key MY-KEY\n+set psk MY-PSK\n+set passphrase MY-PHRASE\n+set passwd MY-PASSWORD\n+hostname new-switch"]];
    $data = cbService(fn () => $reply)->status(Access::HANDLER);
    assertSame('unavailable', $data['engine']['state']);
    assertSame(false, $data['engine']['applied']);
    foreach (['MY-KEY', 'MY-PSK', 'MY-PHRASE', 'MY-PASSWORD'] as $secret) {
        assertTrue(strpos($data['changes'][0]['diff'], $secret) === false);
    }
    assertTrue(strpos($data['changes'][0]['diff'], '+hostname new-switch') !== false);
});

test('組態備份：超長私鑰先遮罩再截斷，長中文差異保持有效 UTF-8', function () {
    $reply = cbReply();
    $reply['data']['changes'] = [['diff' => "-----BEGIN OPENSSH PRIVATE KEY-----\n" . str_repeat('SENSITIVE', 3000) . "\n-----END OPENSSH PRIVATE KEY-----\n+hostname after-backup"]];
    $data = cbService(fn () => $reply)->status(Access::HANDLER);
    assertTrue(strpos($data['changes'][0]['diff'], 'SENSITIVE') === false);
    assertTrue(strpos($data['changes'][0]['diff'], '+hostname after-backup') !== false);
    $reply['data']['changes'][0]['diff'] = str_repeat('設定差異', 3000);
    $data = cbService(fn () => $reply)->status(Access::HANDLER);
    assertTrue(strlen($data['changes'][0]['diff']) <= 16000);
    assertTrue(mb_check_encoding($data['changes'][0]['diff'], 'UTF-8'));
});

test('組態備份：操作歷程只接受白名單動作，不回傳自由描述與秘密', function () {
    $reply = cbReply();
    $reply['data']['audit'] = [
        ['id' => 1, 'occurred_at' => '2026-10-22 00:00:00', 'actor' => "admin\n", 'action' => 'save', 'detail' => 'SECRET-PASSWORD'],
        ['id' => 2, 'actor' => 'admin', 'action' => 'unsupported', 'detail' => 'SECRET-PASSWORD'],
    ];
    $data = cbService(fn () => $reply)->status(Access::HANDLER);
    assertSame(1, count($data['audit']));
    assertSame('儲存備份政策', $data['audit'][0]['detail']);
    assertSame('admin ', $data['audit'][0]['actor']);
    assertTrue(strpos(json_encode($data), 'SECRET-PASSWORD') === false);
    assertSame([], cbService(fn () => cbReply())->status(Access::HANDLER)['audit']);
});

test('組態備份：合法多位元組帳號與操作者回傳不截斷', function () {
    $reply = cbReply();
    $reply['data']['settings']['devices'][0]['username'] = str_repeat('管', 128);
    $reply['data']['audit'] = [['id' => 1, 'actor' => str_repeat('管', 255), 'action' => 'save']];
    $data = cbService(fn () => $reply)->status(Access::HANDLER);
    assertSame(str_repeat('管', 128), $data['settings']['devices'][0]['username']);
    assertSame(str_repeat('管', 255), $data['audit'][0]['actor']);
});

test('組態備份：五十台設備的多位元組合法上限可完整傳遞', function () {
    $devices = [];
    $settings = ['enabled' => true, 'interval_hours' => 24, 'devices' => []];
    for ($id = 1; $id <= 50; $id++) {
        $devices[] = ['device_id' => $id, 'name' => str_repeat('設', 200), 'host' => '192.0.2.' . $id];
        $settings['devices'][] = [
            'device_id' => $id, 'model' => 'ios', 'username' => str_repeat('😀', 128),
            'password' => str_repeat('😀', 512), 'port' => 22, 'enabled' => true,
        ];
    }
    $captured = null;
    $svc = new ConfigBackupService(function ($request) use (&$captured) {
        $captured = $request;
        return cbReply();
    }, $devices);
    $svc->save($settings, str_repeat('😀', 255), Access::HANDLER);
    $encoded = json_encode($captured, JSON_UNESCAPED_UNICODE);
    assertTrue(strlen($encoded) > 131072);
    assertTrue(strlen($encoded) < 262144);
    assertSame(50, count($captured['settings']['devices']));
    assertSame(128, mb_strlen($captured['settings']['devices'][0]['name'], 'UTF-8'));
    assertSame(str_repeat('😀', 512), $captured['settings']['devices'][49]['password']);
});


test('組態備份：歷史與比較先拒絕非管理者及無效識別碼', function () {
    $calls = 0;
    $svc = cbService(function () use (&$calls) { $calls++; return cbReply(); });
    assertThrows(AccessDenied::class, fn() => $svc->history(7, 0, Access::VIEWER));
    assertThrows(AccessDenied::class, fn() => $svc->compare(7, 1, 2, null));
    foreach ([0, -1, 2147483648] as $id) {
        assertThrows(ValidationError::class, fn() => $svc->history($id, 0, Access::HANDLER));
        assertThrows(ValidationError::class, fn() => $svc->compare(7, $id, 2, Access::HANDLER));
    }
    assertThrows(ValidationError::class, fn() => $svc->history(7, -1, Access::HANDLER));
    assertSame(0, $calls);
});

test('組態備份：歷史允許已移除設備且不回傳原始內容', function () {
    $requests = [];
    $reply = cbReply();
    $reply['data']['history_devices'] = [['device_id'=>99,'name'=>'舊設備','configured'=>false,'password'=>'hidden']];
    $reply['data']['history'] = ['device_id'=>99,'offset'=>20,'total_runs'=>25,'total_versions'=>21,
        'runs'=>[['id'=>1,'status'=>'failed','message'=>'hidden']],
        'versions'=>[['id'=>1,'device_id'=>99,'created_at'=>'now','digest'=>str_repeat('a',64),'complete'=>false,'content'=>'hidden']]];
    $svc=cbService(function($r) use (&$requests,$reply) { $requests[]=$r; return $reply; });
    $result=$svc->history(99,20,Access::HANDLER);
    assertSame(99,$requests[0]['device_id']);
    assertSame(20,$result['history']['offset']);
    assertSame(10,$result['history']['limit']);
    assertSame(25,$result['history']['total_runs']);
    assertSame(false,$result['history']['versions'][0]['complete']);
    assertTrue(strpos(json_encode($result),'hidden')===false);
});

test('組態備份：比較傳遞任意版本並遮罩敏感差異', function () {
    $request=null;$reply=cbReply();
    $reply['data']['comparison']=['device_id'=>7,'from_version_id'=>2,'to_version_id'=>5,
        'diff'=>"+hostname branch\n+password hidden",'changed'=>true,'truncated'=>false,'content'=>'hidden'];
    $svc=cbService(function($r) use (&$request,$reply) {$request=$r;return $reply;});
    $result=$svc->compare(7,2,5,Access::HANDLER);
    assertSame(2,$request['from_version_id']);assertSame(5,$request['to_version_id']);
    assertTrue(strpos($result['comparison']['diff'],'+hostname branch')!==false);
    assertTrue(strpos(json_encode($result),'hidden')===false);
});

test('組態備份：下載版本只經受保護服務回傳原始內容', function () {
    $request = null; $reply = cbReply();
    $reply['data']['download'] = ['device_id' => 99, 'version_id' => 5, 'content' => "hostname restore-target\npassword secret"];
    $svc = cbService(function ($r) use (&$request, $reply) { $request = $r; return $reply; });
    $result = $svc->downloadVersion(99, 5, 'admin', Access::HANDLER);
    assertSame('download', $request['action']); assertSame('admin', $request['actor']);
    assertSame("hostname restore-target\npassword secret", $result['content']);
    assertSame('config-backup-device-99-version-5.conf', $result['filename']);
    assertThrows(AccessDenied::class, fn () => $svc->downloadVersion(99, 5, 'admin', Access::VIEWER));
});
