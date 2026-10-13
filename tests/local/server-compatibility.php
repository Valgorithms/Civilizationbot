<?php
declare(strict_types=1);
require $argv[1];
require __DIR__.'/../../src/Civ13/Civ13.php';
require __DIR__.'/../../src/Civ13/GameServer.php';
require __DIR__.'/../../src/Civ13/Server/Ipc.php';
require __DIR__.'/../../src/Civ13/Server/FileRelay.php';
require_once __DIR__.'/../../src/Civ13/OSFunctions.php';
use Civ13\Server\Ipc;
use Civ13\Server\FileRelay;
use React\Promise\Deferred;
use function React\Promise\resolve;
use function React\Promise\reject;
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
final class StatusCivFixture extends \Civ13\Civ13 {
 public bool $denySaves=false;
 public function VarSave(string $filename,array $assoc_array=[]):bool {return !$this->denySaves&&parent::VarSave($filename,$assoc_array);}
 public function hasRequiredConfigRoles(array $required_roles=[]): bool {return true;}
 public function createEmbed(?bool $footer=true,int $color=0xE1452D): \Discord\Parts\Embed\Embed {
  $discord=(new ReflectionClass(\Discord\Discord::class))->newInstanceWithoutConstructor();
  (new ReflectionProperty($discord,'collectionClass'))->setValue($discord,\Discord\Helpers\Collection::class);
  (new ReflectionProperty($discord,'http'))->setValue($discord,(new ReflectionClass(\Discord\Http\Http::class))->newInstanceWithoutConstructor());
  $factory=new \Discord\Factory\Factory($discord);
  (new ReflectionProperty($discord,'factory'))->setValue($discord,$factory);
  $embed=(new ReflectionClass(\Discord\Parts\Embed\Embed::class))->newInstanceWithoutConstructor();
  (new ReflectionProperty($embed,'discord'))->setValue($embed,$discord);
  (new ReflectionProperty($embed,'factory'))->setValue($embed,$factory);
  return $embed;
 }
}
final class OfflineControlDiscord extends \Discord\Discord {
 public ?\React\EventLoop\LoopInterface $offlineLoop=null;
 public function __get(string $name){return $name==='username'?'synthetic-admin':parent::__get($name);}
 public function getLoop():\React\EventLoop\LoopInterface {return $this->offlineLoop??=new \React\EventLoop\StreamSelectLoop();}
}
final class FailingRoleServer extends \Civ13\GameServer {
 public ?\React\Promise\PromiseInterface $replacement=null;
 public function updateFilesFromMemberRoles(callable $callback,array $file_paths,array $required_roles): \React\Promise\PromiseInterface {return $this->replacement??reject(new RuntimeException('synthetic replacement denied'));}
}
$root = sys_get_temp_dir().'/bot-v5-'.bin2hex(random_bytes(6)); mkdir($root);
try {
 $server=(new ReflectionClass(\Civ13\GameServer::class))->newInstanceWithoutConstructor();
 $server->civ13=(new ReflectionClass(StatusCivFixture::class))->newInstanceWithoutConstructor();
 foreach (['name'=>'Fixture','ip'=>'127.0.0.1','port'=>'18452','host'=>'Local'] as $property=>$value) {(new ReflectionProperty($server,$property))->setValue($server,$value);}
 $server->players=['player'];
 foreach (['byond','opendream'] as $engine) {
  $server->engine=$engine; $embed=json_decode(json_encode($server->toEmbed()),true);
  $field=array_values($embed['fields'])[0];
  check($field['value']===($engine==='byond'?'byond://127.0.0.1:18452':'127.0.0.1:18452'),'engine-specific gameplay endpoint');
  check($field['name']===($engine==='byond'?'Server URL':'OpenDream gameplay endpoint'),'engine-specific endpoint label');
  check(!str_contains($field['value'],'18462'),'Topic port exposed as gameplay');
 }
 $server->enabled=true; (new ReflectionProperty($server, 'basedir'))->setValue($server,$root.'/'); mkdir($root.'/SQL');
 check($server->OOCMessage('hello','admin'),'production OOC');
 check($server->AdminMessage('notice','admin') === true,'production admin');
 check($server->DirectMessage('hello','admin','player'),'production DM');
 check(!$server->DirectMessage("bad\nrecord",'admin','player'),'production IPC injection');
 $server->enabled=false; check(!$server->OOCMessage('hello','admin'),'disabled server write');
 file_put_contents($root.\Civ13\Civ13::awards,"player;date;long service medal\nleader;date;iron cross 2nd class\nplayer;date;wounded badge\n");
 $ranked=false;$server->recalculateRanking()->then(function()use(&$ranked){$ranked=true;});
 check($ranked&&file_get_contents($root.\Civ13\Civ13::ranking_path)==="5;leader".PHP_EOL."1;player",'production medal scoring/sorting');
 file_put_contents($root.\Civ13\Civ13::awards,'');$ranked=false;
 $server->recalculateRanking()->then(function()use(&$ranked){$ranked=true;});
 check($ranked&&file_get_contents($root.\Civ13\Civ13::ranking_path)==='','empty awards preserved stale ranking');
 $emptyRank=null;$server->getRanking()->then(function($value)use(&$emptyRank){$emptyRank=$value;});check($emptyRank==='','empty ranking read failed');
 file_put_contents($root.\Civ13\Civ13::ranking_path,"malformed\nnot-a-score;player\n5;leader\n");$ranking=null;
 $server->getRanking()->then(function($value)use(&$ranking){$ranking=$value;});check($ranking==='(1): `leader` with `5` points.','malformed ranking displayed');
 $denied=(new ReflectionClass(\Civ13\GameServer::class))->newInstanceWithoutConstructor();(new ReflectionProperty($denied,'basedir'))->setValue($denied,$root.'/missing/');$failed=false;
 $denied->recalculateRanking()->then(null,function()use(&$failed){$failed=true;});check($failed,'inaccessible awards acknowledged');
 $server->logger=new \Monolog\Logger('offline');$server->civ13->logger=$server->logger;
 $server->civ13->filecache_path=$root.'/';$server->enabled=true;
 (new ReflectionProperty($server,'key'))->setValue($server,'fixture');
 $server->civ13->permitted=['player'=>true];
 $server->logNewRound('round-1','[10:00:00]');$logged=false;
 $server->logPlayerLogin('player','[10:01:00]','127.0.0.2','cid')->then(function()use(&$logged){$logged=true;});check($logged,'actual login persistence rejected');
 $server->logPlayerLogin('player','[10:01:01]','127.0.0.2','cid');
 $saved=$server->civ13->VarLoad('fixture_rounds.json');
 check(count($saved['round-1']['players']['player']['ip'])===1&&count($server->players)===1,'duplicate login duplicated state');
 $server->logNewRound('round-1','[10:02:00]');check(isset($server->rounds['round-1']['players']['player']),'duplicate round erased players');
 $server->logPlayerLogout('player','[10:03:00]');$saved=$server->civ13->VarLoad('fixture_rounds.json');
 check($server->players===[]&&$saved['round-1']['players']['player']['logout']==='[10:03:00]','logout persistence mismatch');
 $server->logNewRound('round-2','[10:04:00]');check($server->rounds['round-1']['end']!==null,'previous round did not close');
 try{$server->logNewRound('round-1','[10:05:00]');throw new RuntimeException('historical round replay accepted');}catch(LogicException){}
 foreach(['','(NULL)','bad_actor',str_repeat('a',65)] as $bad){$failed=false;$server->logPlayerLogin($bad,'[10:05:00]')->then(null,function()use(&$failed){$failed=true;});check($failed,'invalid actual login accepted');}
 $server->civ13->denySaves=true;$failed=false;$succeeded=false;
 $server->logPlayerLogin('player','[10:06:00]')->then(function()use(&$succeeded){$succeeded=true;},function()use(&$failed){$failed=true;});check($failed&&!$succeeded,'failed login persistence acknowledged');
 $failed=false;$server->logPlayerLogout('player','[10:07:00]')->then(null,function()use(&$failed){$failed=true;});check($failed,'failed logout persistence acknowledged');
 try{$server->logNewRound('round-3','[10:08:00]');throw new RuntimeException('failed round persistence acknowledged');}catch(\Civ13\Exceptions\MissingSystemPermissionException){}
 $server->civ13->denySaves=false;
 $server->discord=(new ReflectionClass(OfflineControlDiscord::class))->newInstanceWithoutConstructor();$server->civ13->discord=$server->discord;$server->civ13->loop=$server->discord->getLoop();$server->legacy=true;
 foreach(['discord2ban','discord2unban'] as $property){(new ReflectionProperty($server,$property))->setValue($server,$root.'/SQL/'.$property);}
 $ban=$server->ban(['ckey'=>'player','duration'=>'2 minutes','reason'=>'synthetic reason'],'synthetic-admin');check(str_contains($ban,'banned'),'actual ban failed');
 check(file_get_contents($root.'/SQL/discord2ban')==="synthetic-admin:::player:::2 minutes:::synthetic reason\n",'actual ban framing');
 $before=file_get_contents($root.'/SQL/discord2ban');
 foreach(["bad\nrecord",'bad:::field',"bad\0field",[]] as $bad){$server->ban(['ckey'=>'player','duration'=>'2 minutes','reason'=>$bad],'synthetic-admin');check(file_get_contents($root.'/SQL/discord2ban')===$before,'ban injection changed file');}
 $server->bancheck_cache='a;b;c;d;e;f;g;h;player';$unbanned=false;$server->unban('player','synthetic-admin')->then(function()use(&$unbanned){$unbanned=true;});
 check($unbanned&&file_get_contents($root.'/SQL/discord2unban')==="synthetic-admin:::player\n"&&$server->bancheck_cache==='','actual unban framing/cache');
 $server->civ13->enabled_gameservers=['fixture'=>$server];$aggregate=false;$server->civ13->unban('player','synthetic-admin','fixture')->then(function()use(&$aggregate){$aggregate=true;});check($aggregate,'aggregate successful unban lost');
 $deniedBan=(new ReflectionClass(\Civ13\GameServer::class))->newInstanceWithoutConstructor();$deniedBan->legacy=true;$deniedBan->discord=$server->discord;$deniedBan->logger=$server->logger;$deniedBan->civ13=$server->civ13;
 (new ReflectionProperty($deniedBan,'discord2unban'))->setValue($deniedBan,$root.'/missing/unban');$server->civ13->enabled_gameservers=['good'=>$server,'denied'=>$deniedBan];$failed=false;$succeeded=false;
 $server->civ13->unban('player','synthetic-admin')->then(function()use(&$succeeded){$succeeded=true;},function()use(&$failed){$failed=true;});check($failed&&!$succeeded,'partial unban hidden');
 $server->panic_bunker=true;$deniedBan->panic_bunker=true;$server->civ13->panic_bans=['player'=>true];$diagnosed=false;
 $server->civ13->onRejectedDefault=function()use(&$diagnosed){$diagnosed=true;};$server->civ13->__panicUnban('player');
 check($diagnosed&&isset($server->civ13->panic_bans['player']),'failed panic unban cleared state');
 $server->civ13->enabled_gameservers=['fixture'=>$server];$server->civ13->__panicUnban('player');
 check(!isset($server->civ13->panic_bans['player'])&&$server->civ13->VarLoad('panic_bans.json')===[],'successful panic unban not persisted');
 $server->civ13->panic_bans=['player'=>true];$server->civ13->denySaves=true;$diagnosed=false;$server->civ13->__panicUnban('player');
 check($diagnosed&&isset($server->civ13->panic_bans['player']),'failed panic persistence cleared memory');$server->civ13->denySaves=false;
 $failed=false;$server->unban('player',"bad\nadmin")->then(null,function()use(&$failed){$failed=true;});check($failed,'unban frame injection accepted');
 // No loop is run; scheduled legacy callbacks cannot execute.
 $failing=(new ReflectionClass(FailingRoleServer::class))->newInstanceWithoutConstructor();
 $failing->enabled=true; $failing->civ13=$server->civ13; $failing->logger=new \Monolog\Logger('offline');
 foreach(['whitelist','factionlist','admins'] as $property){(new ReflectionProperty(\Civ13\GameServer::class,$property))->setValue($failing,$root.'/'.$property);}
 foreach(['whitelistUpdate','factionlistUpdate','adminlistUpdate'] as $method){
  $failed=false;$succeeded=false;
  $roles=$method==='adminlistUpdate'?['Verified'=>['Admin','permissions']]:['Verified'];
  $failing->$method($roles)->then(function()use(&$succeeded){$succeeded=true;},function()use(&$failed){$failed=true;});
  check($failed&&!$succeeded,'production role updater swallowed replacement rejection '.$method);
  $failing->civ13->enabled_gameservers=[$failing];$failed=false;
  $failing->civ13->$method($roles)->then(null,function()use(&$failed){$failed=true;});
  check($failed,'aggregate role updater swallowed replacement rejection '.$method);
  $delayed=new Deferred();$failing->replacement=$delayed->promise();$succeeded=false;$failed=false;
  $failing->civ13->$method($roles)->then(function()use(&$succeeded){$succeeded=true;},function()use(&$failed){$failed=true;});
  check(!$succeeded&&!$failed,'role update acknowledged before replacement settled');
  $delayed->reject(new RuntimeException('delayed write failure'));check($failed&&!$succeeded,'delayed replacement failure lost');
  $failing->replacement=resolve(null);$succeeded=false;
  $failing->civ13->$method($roles)->then(function()use(&$succeeded){$succeeded=true;});check($succeeded,'successful replacement not acknowledged');
  $successful=clone $failing;$failing->replacement=null;$failing->civ13->enabled_gameservers=[$successful,$failing];$succeeded=false;$failed=false;
  $failing->civ13->$method($roles)->then(function()use(&$succeeded){$succeeded=true;},function()use(&$failed){$failed=true;});
  check($failed&&!$succeeded,'partial multi-server success hid rejected replacement');
  $failing->civ13->enabled_gameservers=[$failing];
  $failing->replacement=null;
 }
 foreach ([['ooc',['admin','hello']],['admin',['admin','notice']],['dm',['admin','player','hello']],['ban',['admin','player','2 minutes','reason']],['unban',['admin','player']]] as [$name,$fields]) {
  check(Ipc::append($root.'/'.$name,$fields), 'append '.$name); check(Ipc::append($root.'/'.$name,$fields), 'second '.$name);
  check(file_get_contents($root.'/'.$name) === str_repeat(implode(':::',$fields)."\n",2), 'wire framing '.$name);
  foreach (["bad\nrecord", 'bad:::field', "bad\0field"] as $bad) { check(!Ipc::append($root.'/'.$name,[$bad]), 'injection accepted'); }
 }
 Ipc::replace($root.'/roles','old permission'); Ipc::replace($root.'/roles',''); check(file_get_contents($root.'/roles') === '', 'stale membership');
 $log=$root.'/chat'; file_put_contents($log,"first\npartial"); $relay=new FileRelay(); $sent=[];
 $send=function($line) use (&$sent) { $sent[]=$line; return resolve(null); };
 $relay->relay($log,$send); $relay->relay($log,$send); check($sent === ['first'], 'partial/duplicate relay');
 file_put_contents($log," completion\n",FILE_APPEND); $relay->relay($log,$send); check($sent === ['first','partial completion'], 'partial recovery');
 file_put_contents($log,"retry\n",FILE_APPEND); $failed=false;
 $relay->relay($log,fn()=>reject(new RuntimeException('delivery')))->then(null,function() use (&$failed){$failed=true;}); check($failed,'delivery rejection lost');
 $relay->relay($log,$send); check(end($sent)==='retry','failed delivery cursor advanced');
 file_put_contents($log,"new\n"); $relay->relay($log,$send); check(end($sent)==='new','truncate recovery');
 file_put_contents($log,str_repeat("batch\n",70)); $sent=[]; $relay=new FileRelay(); $relay->relay($log,$send); check(count($sent)===32,'unbounded batch');
 $deferred=new Deferred(); $relay->relay($log,fn()=>$deferred->promise()); $relay->relay($log,$send); check(count($sent)===32,'concurrent relay'); $deferred->resolve(null);
 echo "PASS: five IPC channels, injection rejection, empty membership, relay framing/retry/truncation/bounds/concurrency\n";
} finally { foreach (glob($root.'/SQL/*') as $file) { unlink($file); } if (is_dir($root.'/SQL')) { rmdir($root.'/SQL'); } foreach (glob($root.'/*') as $file) { unlink($file); } rmdir($root); }
