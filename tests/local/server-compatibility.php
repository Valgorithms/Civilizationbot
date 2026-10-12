<?php
declare(strict_types=1);
require $argv[1];
require __DIR__.'/../../src/Civ13/Civ13.php';
require __DIR__.'/../../src/Civ13/GameServer.php';
require __DIR__.'/../../src/Civ13/Server/Ipc.php';
require __DIR__.'/../../src/Civ13/Server/FileRelay.php';
use Civ13\Server\Ipc;
use Civ13\Server\FileRelay;
use React\Promise\Deferred;
use function React\Promise\resolve;
use function React\Promise\reject;
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
final class StatusCivFixture extends \Civ13\Civ13 {
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
