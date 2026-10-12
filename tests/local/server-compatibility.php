<?php
declare(strict_types=1);
require $argv[1];
require __DIR__.'/../../src/Civ13/GameServer.php';
require __DIR__.'/../../src/Civ13/Server/Ipc.php';
require __DIR__.'/../../src/Civ13/Server/FileRelay.php';
use Civ13\Server\Ipc;
use Civ13\Server\FileRelay;
use React\Promise\Deferred;
use function React\Promise\resolve;
use function React\Promise\reject;
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$root = sys_get_temp_dir().'/bot-v5-'.bin2hex(random_bytes(6)); mkdir($root);
try {
 $server=(new ReflectionClass(\Civ13\GameServer::class))->newInstanceWithoutConstructor();
 $server->enabled=true; (new ReflectionProperty($server, 'basedir'))->setValue($server,$root.'/'); mkdir($root.'/SQL');
 check($server->OOCMessage('hello','admin'),'production OOC');
 check($server->AdminMessage('notice','admin') === true,'production admin');
 check($server->DirectMessage('hello','admin','player'),'production DM');
 check(!$server->DirectMessage("bad\nrecord",'admin','player'),'production IPC injection');
 $server->enabled=false; check(!$server->OOCMessage('hello','admin'),'disabled server write');
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
