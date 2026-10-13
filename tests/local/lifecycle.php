<?php
declare(strict_types=1);
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../src/Civ13/Local/OpenDreamControl.php';
require __DIR__.'/../../src/Civ13/Local/ControlEndpoint.php';
use Civ13\Local\OpenDreamControl;
use function React\Promise\resolve;
use function React\Promise\reject;
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function failed(callable $call): bool { try { $p=$call(); } catch(Throwable) { return true; } $error=null; $p->then(null, static function($e) use (&$error) { $error=$e; }); return $error !== null; }
function value($promise): array { $value=null; $error=null; $promise->then(static function($v) use (&$value) { $value=$v; }, static function($e) use (&$error) { $error=$e; }); if ($error) { throw $error; } if (!is_array($value)) { throw new RuntimeException('Expected settled array'); } return $value; }
$root=sys_get_temp_dir().'/bot-lifecycle-'.bin2hex(random_bytes(8)); mkdir($root,0700);
foreach(['python','control.py','map.dmm'] as $file) { file_put_contents($root.'/'.$file,'synthetic'); }
$server=['engine'=>'opendream','host'=>'127.0.0.1','runtime_dir'=>$root,'gameplay_port'=>18131,'topic_port'=>18141];
$native=['engine'=>'opendream','runtime_dir'=>$root,'game_port'=>18131,'topic_port'=>18141,'maps'=>['TEST'=>'map.dmm']];
file_put_contents($root.'/profile.json',json_encode($native));
$digest=hash_file('sha256',$root.'/profile.json');
$process=['ok'=>true,'engine'=>'opendream','running'=>false,'game_port'=>18131,'topic_port'=>18141,'runtime_dir'=>$root,'server_pid'=>null];
$body=['action'=>'restart','map_id'=>null,'origin'=>'cli','profile_digest'=>$digest];
$receipt=['schema'=>'civ13.lifecycle.v1','operation_id'=>'restart-1','binding_digest'=>hash('sha256',json_encode($body)),'request'=>$body,'outcome'=>'committed','result'=>$process,'launch_identity'=>['schema'=>'civ13.launch.v1','instance_id'=>'instance-1','profile_digest'=>$digest,'build_id'=>null,'map_id'=>null,'ready'=>false,'server_pid'=>null]];
$response=$process+['id'=>'restart-1','receipt'=>$receipt]; $exit=0;$calls=[];
$execute=static function($argv,$deadline) use (&$calls,&$response,&$exit) { $calls[]=[$argv,$deadline]; return resolve(['exit_code'=>$exit,'stdout'=>json_encode($response)]); };
$make=static fn($execute,$enabled=true,$schema='civ13.lifecycle.v1') => new OpenDreamControl($server,$root.'/python',$root.'/control.py',$root.'/profile.json',$execute,$enabled,$schema);
try {
    $adapter=$make($execute);
    check(value($adapter->request('restart',null,'restart-1'))===$response,'Bound terminal response changed');
    check($calls[0][0]===[$root.'/python',$root.'/control.py','--profile',$root.'/profile.json','restart','--operation-id','restart-1'],'Immutable ID absent from argv');
    check($calls[0][1]===210,'Unbounded mutation deadline');
    check($response['receipt']['launch_identity']['ready']===false,'Process upgraded to ready');
    foreach(['profile','actor','id','action','digest','ready','port','projection','extra','schema','result'] as $case) {
        $response=$process+['id'=>'restart-1','receipt'=>$receipt];
        switch($case) {
            case 'profile':$response['receipt']['request']['profile_digest']=str_repeat('b',64);break;
            case 'actor':$response['receipt']['request']['origin']='game';break;
            case 'id':$response['id']='another';break;
            case 'action':$response['receipt']['request']['action']='stop';break;
            case 'digest':$response['receipt']['binding_digest']=str_repeat('0',64);break;
            case 'ready':$response['receipt']['launch_identity']['ready']=true;break;
            case 'port':$response['receipt']['result']['topic_port']=18131;break;
            case 'projection':$response['running']=true;break;
            case 'extra':$response['receipt']['extra']=true;break;
            case 'schema':$response['receipt']['schema']='v2';break;
            case 'result':$response['receipt']['result']['running']='false';break;
        }
        check(failed(fn()=>$adapter->request('restart',null,'restart-1')),'Accepted tampered '.$case);
    }
    $pending=$receipt;unset($pending['launch_identity']);$pending['outcome']='pending';$pending['result']=null;
    $response=['ok'=>false,'error'=>'operation_pending','id'=>'restart-1','receipt'=>$pending];$exit=1;
    check(failed(fn()=>$adapter->request('restart',null,'restart-1')),'Pending mutation acknowledged');
    foreach(['pending'=>$pending,'committed'=>$receipt,'not_found'=>null] as $status=>$item) {
        $response=['ok'=>true,'schema'=>'civ13.lifecycle.v1','operation_status'=>$status,'receipt'=>$item];$exit=0;
        check(value($adapter->recover('restart-1','restart'))===$response,'Recovery outcome changed');
        check(end($calls)[0]===[$root.'/python',$root.'/control.py','--profile',$root.'/profile.json','operation-status','--operation-id','restart-1'],'Recovery issued economic/process mutation');
        check(end($calls)[1]===10,'Recovery unbounded');
    }
    $response['operation_status']='committed';check(failed(fn()=>$adapter->recover('restart-1','restart')),'Missing receipt accepted');
    check(failed(fn()=>$adapter->request('restart',null,'../escape')),'Path operation accepted');
    check(failed(fn()=>$adapter->request('byond-start',null,'restart-1')),'Native fallback accepted');
    $before=count($calls);check(failed(fn()=>$make($execute,false)->request('start')),'Disabled control accepted');check(count($calls)===$before,'Disabled execution');
    $attempts=0;$uncertain=$make(static function() use (&$attempts) { $attempts++;return reject(new RuntimeException('deadline/lost reply')); });
    check(failed(fn()=>$uncertain->request('restart',null,'lost-1')),'Lost reply success');check($attempts===1,'Lost mutation replayed');
    $response=$process+['id'=>'restart-1','receipt'=>$receipt];check(failed(fn()=>$make($execute,true,null)->request('restart')),'Legacy mode accepted new DTO');
    check(failed(fn()=>$make($execute,true,'unknown')),'Unknown version accepted');
    $response=['ok'=>true,'schema'=>'civ13.lifecycle.v1','operation_status'=>'committed','receipt'=>$receipt];
    check(failed(fn()=>$adapter->recover('restart-1','mapswap','TEST')),'Recovery changed action accepted');
    $response=['ok'=>true,'schema'=>'civ13.lifecycle.v1','operation_status'=>'committed','receipt'=>$receipt];
    $endpoint=new Civ13\Local\ControlEndpoint(['nomads'=>$adapter],str_repeat('k',32),true);
    $route='/v1/local/servers/nomads/operation-status'; $auth='Bearer '.str_repeat('k',32);
    $payload=json_encode(['operation_id'=>'restart-1','action'=>'restart']);
    $before=count($calls);
    check(value($endpoint->handle('POST',$route,'wrong','127.0.0.1',$payload))[0]===401,'Unauthenticated receipt access');
    check(value($endpoint->handle('POST',$route,$auth,'10.0.0.1',$payload))[0]===401,'Remote receipt access');
    check(count($calls)===$before,'Unauthorized executor called');
    check(value($endpoint->handle('POST','/v1/local/servers/nomads/restart',$auth,'127.0.0.1','{}'))[0]===400,'Mutation accepted without recoverable caller identity');
    check(count($calls)===$before,'Missing identity executed mutation');
    $reply=value($endpoint->handle('POST',$route,$auth,'127.0.0.1',$payload));
    check($reply[0]===200 && $reply[1]['view']==='lifecycle-receipt','Read-only receipt route missing');
    check(end($calls)[0][4]==='operation-status','Recovery route mutated');
    check(value($endpoint->handle('POST',$route,$auth,'127.0.0.1',json_encode(['operation_id'=>'restart-1','action'=>'restart','map'=>null])))[0]===400,'Null sentinel accepted');
    check(value($endpoint->handle('POST',$route,$auth,'127.0.0.1',json_encode(['operation_id'=>'restart-1','action'=>'restart','extra'=>true])))[0]===400,'Unknown recovery fields');
    check(value($endpoint->handle('POST','/v1/local/servers/official/operation-status',$auth,'127.0.0.1',$payload))[0]===404,'Official destination accepted');
    file_put_contents($root.'/profile.json',json_encode($native+['changed'=>true]));
    $before=count($calls);check(failed(fn()=>$adapter->recover('restart-1','restart')),'Changed profile recovery accepted');check(count($calls)===$before,'Changed profile executed');
    echo "PASS lifecycle v1 exact binding, legacy compatibility, pending/not-found recovery, lost reply no replay, deadlines, wrong profile/action/server and ready=false negatives\n";
} finally { foreach(glob($root.'/*')?:[] as $file) { unlink($file); } rmdir($root); }
