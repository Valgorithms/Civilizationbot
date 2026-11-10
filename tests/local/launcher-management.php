<?php
declare(strict_types=1);
// Offline protocol/ACL tests only. No real host, bot bootstrap or credentials.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
use Civ13\Local\LauncherManagement;
use React\Promise\Deferred;
use function React\Promise\resolve;
use function React\Promise\reject;
function check(bool $value, string $name): void { if (!$value) { throw new RuntimeException($name); } }
function line($promise): string { $line = null; $promise->then(function ($result) use (&$line) { $line = $result; }); check(is_string($line), 'promise must resolve'); return $line; }
function confirm(LauncherManagement $manager, string $action): string {
    $preview = line($manager->handle('11','22','33',$action));
    check((bool) preg_match('/confirm '.$action.' ([a-f0-9]{24})/', $preview, $m), 'preview nonce '.$action);
    return 'confirm '.$action.' '.$m[1];
}
$policy = ['enabled'=>true,'guild_id'=>'22','operator_ids'=>['11','12'],'channel_ids'=>['33','34'],'actions'=>LauncherManagement::ACTIONS];
$now = 100; $calls = []; $response = ['ok'=>true,'regions'=>['gaia'=>['state'=>'awake','world'=>['clients'=>2], 'operation'=>['phase'=>'done']]]];
$executor = function ($argv,$timeout) use (&$calls,&$response) { $calls[] = [$argv,$timeout]; return resolve(['exit_code'=>0,'stdout'=>json_encode($response)]); };
$make = function ($execute = null) use ($policy, $executor, &$now) { return new LauncherManagement($policy, PHP_BINARY, __FILE__, __FILE__, 'gaia', $execute ?? $executor, function () use (&$now) { return $now; }); };
$m = $make();
foreach ([['99','22','33',false],['11','99','33',false],['11','22','99',false],['11','','33',false],['11','22','33',true]] as [$user,$guild,$channel,$bot]) {
    check(str_contains(line($m->handle($user,$guild,$channel,'status',$bot)), 'not authorized'), 'ACL denial');
}
check(count($calls)===0,'denial launches no processes');
check(str_contains(line($m->handle('11','22','33','status')), 'players 2'), 'sanitized status');
check($calls[0][0] === [PHP_BINARY,'-I','-B',__FILE__,'--config',__FILE__,'status','--region=gaia'], 'exact shell-free region argv');
foreach (['fresh-world','config-apply','epoch 9','force-unlock','help-start','stage;whoami','start --region=other','start extra'] as $invalid) {
    check(str_contains(line($m->handle('11','22','33',$invalid)), 'Unsupported'), 'reject '.$invalid);
}
foreach (array_diff(LauncherManagement::ACTIONS,['status']) as $action) {
    $m = $make(); $confirmation = confirm($m,$action); $before = count($calls);
    check(str_contains(line($m->handle('12','22','33',$confirmation)), 'invalid'), 'actor binding');
    check(str_contains(line($m->handle('11','22','34',$confirmation)), 'invalid'), 'channel binding');
    check(count($calls)===$before,'preview/invalid do not execute');
    $own = match($action) { 'kill'=>['killed'=>true], 'freeze'=>['frozen'=>true], 'thaw'=>['thawed'=>true], default=>['accepted'=>true,'action'=>$action==='force-restart'?'start':$action] };
    $response = ['ok'=>true,(in_array($action,['kill','freeze','thaw'])?'done':'accepted')=>['gaia'=>$own]];
    $text = line($m->handle('11','22','33',$confirmation));
    check(str_contains($text, in_array($action,['kill','freeze','thaw'])?'reports':'accepted, not completed'), 'receipt '.$action);
    check(count($calls)===$before+1,'one dispatch');
    check($calls[array_key_last($calls)][1]===($action==='force-restart'?210:60),'bounded timeout');
    check(str_contains(line($m->handle('11','22','33',$confirmation)),'invalid'),'no replay');
}
$m = $make(); $c = confirm($m,'thaw'); $response=['ok'=>true,'done'=>['gaia'=>['accepted'=>true,'action'=>'start','cold_start'=>true]]];
check(str_contains(line($m->handle('11','22','33',$c)),'accepted, not completed'),'cold thaw is asynchronous');
$m=$make(); $c=confirm($m,'stop'); $now+=60;
check(str_contains(line($m->handle('11','22','33',$c)),'expired'),'expiry');
$c=confirm($m,'stop'); line($m->handle('11','22','33','cancel'));
check(str_contains(line($m->handle('11','22','33',$c)),'invalid'),'cancel');
$c=confirm($m,'stop'); confirm($m,'restart');
check(str_contains(line($m->handle('11','22','33',$c)),'invalid'),'new preview supersedes old');
foreach ([['ok'=>true,'accepted'=>['other'=>['accepted'=>true,'action'=>'start']]], ['ok'=>true,'accepted'=>['gaia'=>['accepted'=>true,'action'=>'stop']]], ['accepted'=>['gaia'=>['accepted'=>true,'action'=>'start']]], ['ok'=>false,'error'=>'SECRET <@123> /private/path']] as $bad) {
    $m=$make(); $c=confirm($m,'start'); $response=$bad;
    $text=line($m->handle('11','22','33',$c)); check(!str_contains($text,'SECRET')&&!str_contains($text,'/private/path'),'raw output not leaked');
    check(str_contains(line($m->handle('11','22','33','start')),'unknown'),'uncertain/refused mutation latched');
}
$m=$make(fn()=>reject(new RuntimeException('PRIVATE /path'))); $c=confirm($m,'start');
check(!str_contains(line($m->handle('11','22','33',$c)), 'PRIVATE'),'transport redacted');
$d=new Deferred(); $m=$make(fn()=>$d->promise()); $c=confirm($m,'start'); $m->handle('11','22','33',$c);
check(str_contains(line($m->handle('11','22','33','status')),'busy'),'concurrent request rejected');
$d->resolve(['exit_code'=>0,'stdout'=>json_encode(['ok'=>true,'accepted'=>['gaia'=>['accepted'=>true,'action'=>'start']]])]);
foreach (['-other','other --foo','../gaia','GAIA',''] as $region) {
    try { new LauncherManagement($policy,PHP_BINARY,__FILE__,__FILE__,$region,$executor); throw new LogicException('accepted invalid region'); } catch (InvalidArgumentException $expected) {}
}
$badPolicy=$policy; $badPolicy['actions'][]='fresh-world';
try { new LauncherManagement($badPolicy,PHP_BINARY,__FILE__,__FILE__,'gaia',$executor); throw new LogicException('accepted unsupported capability'); } catch (InvalidArgumentException $expected) {}
echo "launcher management ACL/confirmation/protocol contracts passed\n";

// Production command wiring + legacy entry guards without starting a bot/host.
final class ManageBotFixture extends \Civ13\Civ13
{
    public array $captured = [];
    public function reply(\Discord\Parts\Channel\Message|\Discord\Parts\Thread\Thread $message, string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): \React\Promise\PromiseInterface
    { $this->captured[] = $content; return resolve(null); }
}
final class ManageMessageFixture extends \Discord\Parts\Channel\Message
{
    public array $fixture = ['author'=>null, 'guild_id'=>'22', 'channel_id'=>'33', 'webhook_id'=>null];
    public function __get(string $name) { return $this->fixture[$name] ?? null; }
    public function __isset(string $name): bool { return isset($this->fixture[$name]); }
}
$bot=(new ReflectionClass(ManageBotFixture::class))->newInstanceWithoutConstructor();
$server=(new ReflectionClass(\Civ13\GameServer::class))->newInstanceWithoutConstructor();
$message=(new ReflectionClass(ManageMessageFixture::class))->newInstanceWithoutConstructor();
$message->fixture['author']=(object)['id'=>'11','bot'=>false];
(new ReflectionProperty(\Civ13\GameServer::class,'key'))->setValue($server,'fixture');
$command=new \Civ13\MessageCommand\Commands\Civ13GameServerManage($bot,$server);
$command($message,'fixturemanage',['message_content'=>'fixturemanage status']);
check(str_contains(end($bot->captured),'disabled'),'disabled command');
$server->launcherManagement=$make();
$response=['ok'=>true,'regions'=>['gaia'=>['state'=>'awake','world'=>['clients'=>0],'busy'=>true,'operation'=>['phase'=>'done'],'stage'=>['phase'=>'compiling','pending'=>false]]]];
$command($message,'fixturemanage',['message_content'=>'fixturemanage status']);
check(str_contains(end($bot->captured),'stage phase compiling') && str_contains(end($bot->captured),'busy yes'),'command extracts text and stage status');
$message->fixture['webhook_id']='44';
$command($message,'fixturemanage',['message_content'=>'fixturemanage status']);
check(str_contains(end($bot->captured),'not authorized'),'webhook denied by production command');
$message->fixture['webhook_id']=null; $message->fixture['author']->bot=true;
$command($message,'fixturemanage',['message_content'=>'fixturemanage status']);
check(str_contains(end($bot->captured),'not authorized'),'bot denied by production command');
foreach (['Host','Kill','Restart','Update'] as $method) {
    $error=null; $server->$method()->then(null,function($e)use(&$error){$error=$e;});
    check($error instanceof RuntimeException && str_contains($error->getMessage(),'legacy lifecycle'),'legacy '.$method.' fails before side effects');
}
$server->launcherManagement=null;
(new ReflectionProperty(\Civ13\GameServer::class,'launcherManagementRequired'))->setValue($server,true);
foreach (['Host','Kill','Restart','Update'] as $method) {
    $error=null; $server->$method()->then(null,function($e)use(&$error){$error=$e;});
    check($error instanceof RuntimeException,'invalid configured management fails closed '.$method);
}
echo "launcher management production command and legacy guards passed\n";
