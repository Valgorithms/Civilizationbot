<?php
declare(strict_types=1);
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../src/Civ13/Civ13.php';
require __DIR__.'/../../src/Civ13/GameServer.php';
require __DIR__.'/../../src/Civ13/HttpServiceManager.php';
require_once __DIR__.'/../../src/Civ13/CivHandler.php';
require_once __DIR__.'/../../src/Civ13/HttpHandler.php';
use React\Promise\PromiseInterface;
use React\Http\Message\ServerRequest;
use function React\Promise\resolve;
final class OocDiscord extends \Discord\Discord {
    public ?\Discord\Parts\Channel\Channel $channel;
    public function getChannel($channel_id): ?\Discord\Parts\Channel\Channel { return $this->channel; }
    public function __get(string $name) { return $name==='guilds' ? new \Discord\Helpers\Collection() : parent::__get($name); }
}
final class OocServer extends \Civ13\GameServer {
    public array $calls=[];
    public function gameChatWebhookRelay(string $message,string $channel_id,?string $ckey=null,?bool $ooc=true,?bool $moderate=true): PromiseInterface {
        $this->calls[]=[$message,$channel_id,$ckey,$ooc,$moderate]; return resolve(null);
    }
}
$civ=(new ReflectionClass(\Civ13\Civ13::class))->newInstanceWithoutConstructor();
$civ->options=['http_key'=>'synthetic-ooc-key'];
$manager=(new ReflectionClass(\Civ13\HttpServiceManager::class))->newInstanceWithoutConstructor();
$manager->civ13=$civ;
$manager->discord=(new ReflectionClass(OocDiscord::class))->newInstanceWithoutConstructor();
$manager->discord->channel=(new ReflectionClass(\Discord\Parts\Channel\Channel::class))->newInstanceWithoutConstructor();
$server=(new ReflectionClass(OocServer::class))->newInstanceWithoutConstructor();
$server->legacy_relay=false; $server->ooc='123456789012345678';
$valid=['key'=>'synthetic-ooc-key','ckey'=>'Real_Player','message'=>'Impersonator/otherkey: harmless %41 + é display'];
$request=function($data,string $ip='127.0.0.1') {
    $query=['method'=>'oocmessage','data'=>json_encode(array_diff_key($data,['key'=>true]))];
    if(array_key_exists('key',$data)){$query['key']=$data['key'];}
    return new ServerRequest('GET','http://localhost/webhook/test?'.http_build_query($query),[], '', '1.1',['REMOTE_ADDR'=>$ip]);
};
$check=function($data,int $expected)use($manager,$server,$request){
    $count=count($server->calls); $status=$manager->handleOocWebhook($request($data),$server)->getStatusCode();
    if($status!==$expected || ($expected!==202 && count($server->calls)!==$count)){throw new RuntimeException('OOC boundary failure '.$status);}
};
$check($valid,202);
$handler=(new ReflectionClass(\Civ13\HttpHandler::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($handler,'key'))->setValue($handler,'synthetic-ooc-key');
(new ReflectionProperty($handler,'attributes'))->setValue($handler,['whitelist'=>[]]);
if(!$handler->__isWhitelisted($request($valid),'8.8.8.8')
    || $handler->__isWhitelisted($request(array_replace($valid,['key'=>'wrong'])),'8.8.8.8')) {
    throw new RuntimeException('Actual dispatch whitelist rejected native outer key or accepted forged key');
}
$nested=$request($valid)->withQueryParams(['method'=>'oocmessage','data'=>urlencode(json_encode($valid))]);
if($manager->handleOocWebhook($nested,$server)->getStatusCode()!==403){throw new RuntimeException('Nested-only key accepted');}
$handler->logger=new \Monolog\Logger('offline-ooc',[new \Monolog\Handler\NullHandler()]);
$manager->httpHandler=$handler; $manager->logger=$handler->logger;
(new ReflectionProperty($server,'key'))->setValue($server,'test'); $civ->enabled_gameservers=['test'=>$server];
(new ReflectionProperty($handler,'attributes'))->setValue($handler,['handlers'=>[],'whitelisted'=>[],'match_methods'=>[],'descriptions'=>[],'whitelist'=>[],'ratelimits'=>[]]);
(new ReflectionMethod($handler,'afterConstruct'))->invoke($handler);
(new ReflectionMethod($manager,'__generateServerEndpoints'))->invoke($manager);
$server->asay='123456789012345679';
$asay=$request($valid)->withQueryParams(['key'=>'synthetic-ooc-key','method'=>'asaymessage','data'=>json_encode(['ckey'=>'Admin (display)','message'=>'BotName literal %41 + é'])]);
if($handler->handle($asay)->getStatusCode()!==200 || end($server->calls)[2]!==null || end($server->calls)[4]!==false){throw new RuntimeException('Asay display identity reached account moderation');}
if(!str_contains(end($server->calls)[0],'BotName literal %41 + é')){throw new RuntimeException('Asay display content lost');}
if($handler->handle($asay->withQueryParams(['key'=>'wrong','method'=>'asaymessage','data'=>json_encode(['message'=>'forged'])]))->getStatusCode()!==403){throw new RuntimeException('Trusted IP forged asay accepted');}
foreach([[$valid,'8.8.8.8',202],[array_replace($valid,['key'=>'wrong']),'8.8.8.8',403],[array_replace($valid,['key'=>'wrong']),'127.0.0.1',403]] as [$payload,$ip,$status]) {
    $full=$request($payload,$ip);
    if($handler->handle($full)->getStatusCode()!==$status){throw new RuntimeException('Actual registered native dispatcher contract failed');}
}
if($server->calls[0]!==[$valid['message'],$server->ooc,'realplayer',true,true]){throw new RuntimeException('Display text altered trusted actor/moderation');}
foreach(['wrong',null,[],123] as $key){$check(array_replace($valid,['key'=>$key]),403);}
$without=$valid; unset($without['key']); $check($without,403);
foreach(['',null,[],123,'!!!',str_repeat('a',65)] as $actor){$check(array_replace($valid,['ckey'=>$actor]),400);}
foreach(['',null,[],123,"bad\0message",str_repeat('é',8193)] as $body){$check(array_replace($valid,['message'=>$body]),400);}
foreach(['','missing'] as $key){$civ->options=$key===''?['http_key'=>'']:[]; $check($valid,403);}
$civ->options=['http_key'=>'synthetic-ooc-key'];
$server->legacy_relay=true; $check($valid,403); $server->legacy_relay=false;
$manager->discord->channel=null; $check($valid,503);
foreach(['{invalid','null','123','[]',str_repeat('x',65537)] as $raw){
    $bad=$request($valid)->withQueryParams(['data'=>$raw]);
    if($manager->handleOocWebhook($bad,$server)->getStatusCode()!==403){throw new RuntimeException('Malformed raw JSON accepted');}
}
echo "PASS actual structured OOC handler: trusted identity separate from display, wrong/missing key on loopback, malformed actor/body, UTF8 byte bounds, disabled/missing destination\n";
