<?php
declare(strict_types=1);
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../src/Civ13/Civ13.php';
require __DIR__.'/../../src/Civ13/GameServer.php';
require __DIR__.'/../../src/Civ13/HttpServiceManager.php';
require_once __DIR__.'/../../src/Civ13/CivHandler.php';
require_once __DIR__.'/../../src/Civ13/HttpHandler.php';
require_once __DIR__.'/../../src/Civ13/Verifier.php';
require_once __DIR__.'/../../src/Civ13/Moderator.php';
use React\Promise\PromiseInterface;
use React\Http\Message\ServerRequest;
use function React\Promise\resolve;
final class OocDiscord extends \Discord\Discord {
    public ?\Discord\Parts\Channel\Channel $channel;
    public function getChannel($channel_id): ?\Discord\Parts\Channel\Channel { return $this->channel; }
    public $guildFixture=null;
    public function __get(string $name) {
        if($name==='guilds'){return new class($this->guildFixture){public function __construct(private $guild){} public function get(...$args){return $this->guild;}};}
        return parent::__get($name);
    }
}
final class OocCiv extends \Civ13\Civ13 {
    public array $staffMessages=[];
    public function sendMessage(\Discord\Parts\Channel\Channel|\Discord\Parts\Thread\Thread|string $channel,\Discord\Builders\MessageBuilder|string $content,string $file_name='message.txt',bool $prevent_mentions=false): PromiseInterface {
        $this->staffMessages[]=[$channel,$content]; return resolve(null);
    }
}
final class OocMember extends \Discord\Parts\User\Member {
    public function __get(string $key){return $key==='id'?'staff-member':new class{public function has($id){return $id==='admin-role';}};}
}
final class OocVerifier extends \Civ13\Verifier {
    public array $lookups=[];
    public function get(string $discrim,$key):mixed {$this->lookups[]=[$discrim,$key]; return ['ss13'=>'onlineadmin'];}
}
final class OocServer extends \Civ13\GameServer {
    public array $calls=[];
    public array $events=[];
    public function logPlayerLogin(string $ckey,string $time,string $ip='',string $cid=''):PromiseInterface {$this->events[]=['login',$ckey,$ip,$cid]; return resolve(null);}
    public function logPlayerLogout(string $ckey,string $time):PromiseInterface {$this->events[]=['logout',$ckey];$this->players=array_values(array_diff($this->players,[$ckey]));return resolve([]);}
    public function panicCheck(string $ckey):void {$this->events[]=['panic',$ckey];}
    public function gameChatWebhookRelay(string $message,string $channel_id,?string $ckey=null,?bool $ooc=true,?bool $moderate=true): PromiseInterface {
        $this->calls[]=[$message,$channel_id,$ckey,$ooc,$moderate]; return resolve(null);
    }
}
final class OocModerator extends \Civ13\Moderator {
    public array $actors=[];
    public function scrutinizeCkey(string $ckey):void {$this->actors[]=$ckey;}
}
$civ=(new ReflectionClass(OocCiv::class))->newInstanceWithoutConstructor();
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
foreach(['127.0.0.1','::1','10.0.0.2','8.8.8.8'] as $ip){
    foreach([null,[],123,'wrong',''] as $key){if($handler->__isWhitelisted($request(array_replace($valid,['key'=>$key])),$ip)){throw new RuntimeException('Webhook network bypassed service authentication');}}
}
(new ReflectionProperty($handler,'key'))->setValue($handler,'');
if($handler->__isWhitelisted($request($valid),'127.0.0.1')){throw new RuntimeException('Empty configured webhook key accepted');}
(new ReflectionProperty($handler,'key'))->setValue($handler,'synthetic-ooc-key');
$nested=$request($valid)->withQueryParams(['method'=>'oocmessage','data'=>urlencode(json_encode($valid))]);
if($manager->handleOocWebhook($nested,$server)->getStatusCode()!==403){throw new RuntimeException('Nested-only key accepted');}
$handler->logger=new \Monolog\Logger('offline-ooc',[new \Monolog\Handler\NullHandler()]);
$manager->httpHandler=$handler; $manager->logger=$handler->logger;
(new ReflectionProperty($server,'key'))->setValue($server,'test'); $civ->enabled_gameservers=['test'=>$server];
(new ReflectionProperty($handler,'attributes'))->setValue($handler,['handlers'=>[],'whitelisted'=>[],'match_methods'=>[],'descriptions'=>[],'whitelist'=>[],'ratelimits'=>[]]);
(new ReflectionMethod($handler,'afterConstruct'))->invoke($handler);
(new ReflectionMethod($manager,'__generateServerEndpoints'))->invoke($manager);
$server->ic='123456789012345670';$server->transit='123456789012345671';
$civ->moderator=(new ReflectionClass(OocModerator::class))->newInstanceWithoutConstructor();
foreach(['icmessage','memessage','login','logout'] as $method){
    $route=fn($data,$key='synthetic-ooc-key')=>$request($valid)->withQueryParams(['key'=>$key,'method'=>$method,'data'=>json_encode($data)]);
    $before=count($server->calls);$events=count($server->events);$players=$server->players;
    foreach([null,[],123,'(NULL)','',str_repeat('a',65)] as $actor){
        if($handler->handle($route(['ckey'=>$actor,'message'=>'harmless']))->getStatusCode()!==400){throw new RuntimeException('Malformed registered actor accepted '.$method);}
    }
    if($handler->handle($route(['ckey'=>'realplayer','message'=>'harmless'],'wrong'))->getStatusCode()!==403){throw new RuntimeException('Trusted IP bypassed route authentication '.$method);}
    if(count($server->calls)!==$before||count($server->events)!==$events||$server->players!==$players){throw new RuntimeException('Rejected request changed player or relay state '.$method);}
    if(in_array($method,['icmessage','memessage'],true)){
        foreach([null,[],123,"bad\0message",str_repeat('é',8193)] as $body){if($handler->handle($route(['ckey'=>'realplayer','message'=>$body]))->getStatusCode()!==400){throw new RuntimeException('Malformed chat body accepted');}}
        if($handler->handle($route(['ckey'=>'Real_Player','message'=>'literal %41 + é']))->getStatusCode()!==202){throw new RuntimeException('Structured IC/emote failed');}
        $call=end($server->calls);if($call[2]!=='realplayer'||$call[3]!==false||!str_contains($call[0],'literal %41 + é')){throw new RuntimeException('IC actor/channel/display mismatch');}
    }else{
        if($handler->handle($route(['ckey'=>'realplayer','ip'=>[]]))->getStatusCode()!==400){throw new RuntimeException('Malformed transit metadata accepted');}
        if($handler->handle($route(['ckey'=>'Real_Player']))->getStatusCode()!==200){throw new RuntimeException('Transit incorrectly depends on optional parole channel');}
        if(($method==='login')!==in_array('realplayer',$server->players,true)){throw new RuntimeException('Transit player state mismatch');}
    }
}
$server->asay='123456789012345679';
$asay=$request($valid)->withQueryParams(['key'=>'synthetic-ooc-key','method'=>'asaymessage','data'=>json_encode(['ckey'=>'Admin (display)','message'=>'BotName literal %41 + é'])]);
if($handler->handle($asay)->getStatusCode()!==200 || end($server->calls)[2]!==null || end($server->calls)[4]!==false){throw new RuntimeException('Asay display identity reached account moderation');}
if(!str_contains(end($server->calls)[0],'BotName literal %41 + é')){throw new RuntimeException('Asay display content lost');}
if($handler->handle($asay->withQueryParams(['key'=>'wrong','method'=>'asaymessage','data'=>json_encode(['message'=>'forged'])]))->getStatusCode()!==403){throw new RuntimeException('Trusted IP forged asay accepted');}
$civ->channel_ids=['staff_bot'=>'staff-channel']; $civ->role_ids=['Admin'=>'admin-role'];
(new ReflectionProperty($server,'name'))->setValue($server,'Synthetic server');
$members=new class {public array $list=[];public function filter($callback){return array_filter($this->list,$callback);}};
$manager->discord->guildFixture=(object)['members'=>$members];
$handler->handle($asay);
if(count($civ->staffMessages)!==1 || !str_contains($civ->staffMessages[0][1],'<@&admin-role>')){throw new RuntimeException('Absent staff notification lost');}
$civ->verifier=(new ReflectionClass(OocVerifier::class))->newInstanceWithoutConstructor();
$members->list=[(new ReflectionClass(OocMember::class))->newInstanceWithoutConstructor()]; $server->players=['onlineadmin'];
$handler->handle($asay);
if(count($civ->staffMessages)!==1 || $civ->verifier->lookups!==[['discord','staff-member']]){throw new RuntimeException('Online staff suppression or actor isolation lost');}
$manager->discord->guildFixture=null;
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
echo "PASS actual registered OOC/IC/emote/login/logout routes: independent service authentication, bounded canonical actors/body, rejected requests preserve state, optional parole channel, staff display isolation\n";
