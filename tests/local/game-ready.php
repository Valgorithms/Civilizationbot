<?php
declare(strict_types=1);
// Inert requests and memory only. No bot.php, .env, listener, client, filesystem state or sends.
$loader=require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\',__DIR__.'/../../src/Civ13/',true);
foreach(['Civ13','GameServer','CivHandler','HttpHandler','HttpServiceManager','GameReady'] as $class){require_once __DIR__.'/../../src/Civ13/'.$class.'.php';}
use Civ13\GameReady;
use React\Http\Message\ServerRequest;
$checks=0;
function readyCheck(bool $ok,string $why):void{global $checks;++$checks;if(!$ok){throw new RuntimeException($why);}}
function readyReject(callable $call,string $why):void{try{$call();}catch(Throwable){readyCheck(true,$why);return;}readyCheck(false,$why);}
$boot=str_repeat('a',32);$generation='round-v2:'.str_repeat('b',32);
$event=['schema'=>GameReady::SCHEMA,'event'=>'game_ready','server'=>'test','boot'=>$boot,'round'=>'SYNTHETIC_ROUND_1','generation_kind'=>'restored','generation'=>$generation];
$raw=json_encode($event,JSON_THROW_ON_ERROR);$now=1700000000;
$ledger=new GameReady(static function()use(&$now):int{return $now;},2,120);
$a=$ledger->receive($raw,'test');
readyCheck($a['status']===202&&$ledger->count()===1&&$a['body']['state']==='queued'&&$a['body']['delivery']==='unconfigured','First observation is reserved, not externally delivered');
$now+=3;$b=$ledger->receive(json_encode(array_reverse($event,true),JSON_PRETTY_PRINT),'test');
readyCheck($b['status']===200&&$a['ack']===$b['ack']&&$a['body']['event_id']===$b['body']['event_id']&&$a['body']['observed_at']===$b['body']['observed_at'],'Semantic duplicate does not replace observation time');
$conflict=$event;$conflict['generation']='round-v2:'.str_repeat('c',32);
readyCheck($ledger->receive(json_encode($conflict),'test')===['status'=>409]&&$ledger->count()===1,'One immutable startup generation per server/boot');
$other=$event;$other['server']='other';readyCheck($ledger->receive(json_encode($other),'other')['status']===202,'Configured servers have independent identities');
$next=$event;$next['boot']=str_repeat('d',32);readyCheck($ledger->receive(json_encode($next),'test')===['status'=>503]&&$ledger->count()===2,'Capacity is closed and bounded');
$now+=120;readyCheck($ledger->receive(json_encode($next),'test')['status']===202&&$ledger->count()===1,'Only settled observation retention expires');
$now--;readyReject(fn()=>$ledger->receive($raw,'test'),'Clock rollback retains state and refuses observation');
$fresh=$event;$fresh['generation_kind']='fresh';$fresh['generation']='fresh:'.$boot;
readyCheck(GameReady::decode(json_encode($fresh),'test')===$fresh,'Fresh event explicitly binds boot and never claims a restored generation');
$legacy=$event;$legacy['generation']='round-v1:'.str_repeat('e',32);readyReject(fn()=>GameReady::decode(json_encode($legacy),'test'),'Legacy generation without the agreed validated identity is suppressed');
$bad=[];
foreach(['schema'=>['wrong',null,[]],'event'=>['round_start',null,[]],'round'=>['',str_repeat('a',129),"bad\nround",null,[]],'server'=>['other',12,[]],'boot'=>['',str_repeat('A',32),str_repeat('a',33),null,[]],'generation_kind'=>['manual',null,[]],'generation'=>['round-v3:'.str_repeat('b',32),'round-v2:'.str_repeat('B',32),"round-v2:\n".str_repeat('b',32),null,[]]] as $field=>$values){
    foreach($values as $value){$v=$event;$v[$field]=$value;$bad[]=json_encode($v);}
}
foreach(['players','message','key','save_data'] as $extra){$v=$event;$v[$extra]='unexpected';$bad[]=json_encode($v);}
$bad[]='null';$bad[]='[]';$bad[]='{}';$bad[]='invalid';$bad[]=str_repeat(' ',1025).$raw;
$bad[]=substr($raw,0,-1).',"server":"test"}';
$bad[]=str_replace('"server"','"ser\u0076er"',$raw);
$bad[]=substr($raw,0,-1).',"ser\u0076er":"test"}';
$bad[]=urlencode($raw);$wrongFresh=$fresh;$wrongFresh['generation']='fresh:'.str_repeat('f',32);$bad[]=json_encode($wrongFresh);
foreach($bad as $value){readyReject(fn()=>GameReady::decode($value,'test'),'Malformed/unknown/ambiguous data refused');}
readyReject(fn()=>new GameReady(null,257),'Capacity policy is bounded');
readyReject(fn()=>new GameReady(null,2,119),'Retention is not shorter than sender admission window');

$civ=(new ReflectionClass(Civ13\Civ13::class))->newInstanceWithoutConstructor();
$civ->options=['http_key'=>'synthetic-ready-key'];$civ->enabled_gameservers=[];
$server=(new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($server,'key'))->setValue($server,'test');$server->enabled=true;$server->legacy_relay=false;
$civ->enabled_gameservers=['test'=>$server];
$manager=(new ReflectionClass(Civ13\HttpServiceManager::class))->newInstanceWithoutConstructor();$manager->civ13=$civ;
$manager->logger=new Monolog\Logger('offline-ready',[new Monolog\Handler\NullHandler()]);
$request=static function(array $params=[],string $path='/webhook/test',string $method='GET')use($raw):ServerRequest{
    return (new ServerRequest($method,'http://localhost'.$path,[], '', '1.1',['REMOTE_ADDR'=>'127.0.0.1']))->withQueryParams($params+['key'=>'synthetic-ready-key','method'=>'game_ready','data'=>$raw]);
};
readyCheck($manager->handleGameReadyWebhook($request(),$server)->getStatusCode()===503&&(new ReflectionProperty($manager,'gameReady'))->getValue($manager)===null,'Default disabled creates no ledger or effects');
$civ->options['game_ready_events']=['enabled'=>true];
foreach(['wrong','',null,[],123] as $key){readyCheck($manager->handleGameReadyWebhook($request(['key'=>$key]),$server)->getStatusCode()===403,'Outer authentication cannot fall back to network trust');}
readyCheck($manager->handleGameReadyWebhook($request([], '/webhook/test','POST'),$server)->getStatusCode()===405,'Unsupported method refused');
foreach(['/webhook/testevil','/webhook/test/game_ready/extra','/webhook/other'] as $path){readyCheck($manager->handleGameReadyWebhook($request([],$path),$server)->getStatusCode()===400,'Exact configured path binding');}
readyCheck($manager->handleGameReadyWebhook($request(['method'=>'round_start']),$server)->getStatusCode()===400,'Method cannot alias earlier round start');
readyCheck($manager->handleGameReadyWebhook($request(['data'=>['wrong']]),$server)->getStatusCode()===400,'Nonstring body refused');
readyCheck($manager->handleGameReadyWebhook($request(['data'=>str_repeat('x',1025)]),$server)->getStatusCode()===400,'Raw size cap before JSON decode');
$civ->options['game_ready_events']=['enabled'=>'true'];readyCheck($manager->handleGameReadyWebhook($request(),$server)->getStatusCode()===503,'Flags never coerced');
$civ->options['game_ready_events']=['enabled'=>true];$server->enabled=false;readyCheck($manager->handleGameReadyWebhook($request(),$server)->getStatusCode()===503,'Disabled server refuses observations');$server->enabled=true;
$civ->enabled_gameservers=[];readyCheck($manager->handleGameReadyWebhook($request(),$server)->getStatusCode()===503,'Retired server owner refuses observations');$civ->enabled_gameservers=['test'=>$server];
$receipt=$manager->handleGameReadyWebhook($request(),$server);
readyCheck($receipt->getStatusCode()===202&&preg_match('/^[a-f0-9]{32}$/D',$receipt->getHeaderLine('X-Civ13-Game-Ready'))===1&&$receipt->getHeaderLine('Cache-Control')==='no-store','Bounded acknowledgement needs no native response-body read');
readyCheck($manager->handleGameReadyWebhook($request([],'/webhook/test/game_ready'),$server)->getStatusCode()===200,'Direct route shares the same ledger');
readyCheck($manager->handleGameReadyWebhook($request(['data'=>json_encode($conflict)]),$server)->getStatusCode()===409,'Changed body cannot reuse boot reservation');
// Actual dispatcher, including existing shared-key gate and root method dispatch.
$handler=(new ReflectionClass(Civ13\HttpHandler::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($handler,'key'))->setValue($handler,'synthetic-ready-key');
(new ReflectionProperty($handler,'attributes'))->setValue($handler,['handlers'=>[],'whitelisted'=>[],'match_methods'=>[],'descriptions'=>[],'whitelist'=>['127.0.0.1'],'ratelimits'=>[]]);
$handler->logger=$manager->logger;$manager->httpHandler=$handler;
(new ReflectionMethod($handler,'afterConstruct'))->invoke($handler);
(new ReflectionMethod($manager,'__generateServerEndpoints'))->invoke($manager);
readyCheck($handler->handle($request())->getStatusCode()===200,'Registered native method dispatch accepts exact duplicate');
readyCheck($handler->handle($request(['key'=>'wrong']))->getStatusCode()===403,'Whitelisted loopback still cannot forge event');
readyCheck($handler->handle($request([],'/webhook/testevil'))->getStatusCode()===404,'Unknown longer route is rejected before observation');
readyCheck($handler->handle($request([],'/webhook/t'))->getStatusCode()===404,'Unknown shorter route is rejected by exact dispatcher');
$server->legacy_relay=true;readyCheck($handler->handle($request())->getStatusCode()===200,'Readiness observation has independent explicit enablement from chat relay');
readyCheck($receipt->getBody()->getSize()<512&&!str_contains((string)$receipt->getBody(),'synthetic-ready-key'),'Receipt has no credentials/world content');
final class ReadyThrowingLogger extends Monolog\Logger { public function info(string|Stringable $message,array $context=[]):void{throw new RuntimeException('Synthetic logger failure');} }
$manager->logger=new ReadyThrowingLogger('offline-throw');
$another=$event;$another['boot']=str_repeat('f',32);
readyCheck($manager->handleGameReadyWebhook($request(['data'=>json_encode($another)]),$server)->getStatusCode()===202,'Logging failure cannot invalidate a reserved observation');
readyCheck($manager->handleGameReadyWebhook($request(['data'=>json_encode($another)]),$server)->getStatusCode()===200,'Logging failure cannot duplicate a prior reservation');
echo "PASS $checks game-ready observation/authentication/dedup contracts (memory only; no native completion proof)\n";
