<?php
declare(strict_types=1);
// Inert facade, maintained cache, timers and memory writer. No real clients/files/bootstrap.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
foreach (['HostedVerificationSource','HostedVerificationSnapshot','AdminListRenderer','AdminSyncSnapshotGate','AdminSyncCoordinator','AdminCacheSyncTrigger','AdminFileSync'] as $class) {
    require_once __DIR__.'/../../src/Civ13/'.$class.'.php';
}
use Civ13\AdminFileSync;
use Civ13\GameServer;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

$checks=0;
function compositionCheck(bool $condition,string $message): void { global $checks; ++$checks; if (!$condition) { throw new RuntimeException($message); } }
final class SyncEmitter {
    public array $listeners=[]; public int $adds=0; public int $removes=0; public mixed $onHook=null; public mixed $removeHook=null;
    public function __construct(public object $guilds) {}
    public function on(string $event,callable $listener): void { ++$this->adds; $this->listeners[$event][]=$listener; if ($this->onHook!==null) { ($this->onHook)(); } }
    public function removeListener(string $event,callable $listener): void {
        ++$this->removes;
        if ($this->removeHook!==null) { ($this->removeHook)(); }
        $this->listeners[$event]=array_values(array_filter($this->listeners[$event]??[],static fn($existing)=>$existing!==$listener));
    }
    public function emit(string $event,array $args=[]): void { foreach ($this->listeners[$event]??[] as $listener) { $listener(...$args); } }
}
final class SyncRepository {
    public int $refreshes=0; public mixed $hook=null;
    public function __construct(public array $items=[]) {}
    public function get(string $field,string $id): mixed { return $this->items[$id]??null; }
    public function has(string $id): bool { return array_key_exists($id,$this->items); }
    public function freshen(): PromiseInterface { ++$this->refreshes; return $this->hook!==null?($this->hook)():resolve($this); }
}
final class SyncVerifier {
    public bool $readOnly=true; public array $verified=[]; public int $refreshes=0; public int $lookups=0; public int $statusReads=0;
    public mixed $statusHook=null; public mixed $refreshHook=null;
    public function __construct(public object $fixture,public SyncRepository $members) {}
    public function hostedStatus(): array {
        ++$this->statusReads;
        if ($this->statusHook!==null) { return ($this->statusHook)(); }
        return $this->fixture->status;
    }
    public function refreshHosted(): PromiseInterface {
        ++$this->refreshes;
        return $this->refreshHook!==null?($this->refreshHook)():resolve($this->hostedStatus());
    }
    public function getVerifiedMember(string $id): mixed { ++$this->lookups; return $this->members->get('id',$id); }
}
function syncFixture(bool $alreadyReady=false): array {
    $f=(object)['now'=>1800000000,'timers'=>[],'timerCalls'=>0,'cancelCalls'=>0,'writes'=>[],
        'file'=>'previous file','reports'=>[],'writerHook'=>null,'guild'=>'770000000000000001','user'=>'990000000000000001'];
    $f->status=['available'=>true,'stale'=>false,'has_valid_list'=>true,'refresh_in_progress'=>false,'records'=>1,
        'age_seconds'=>0,'last_success_utc'=>gmdate('Y-m-d\TH:i:s\Z',$f->now)];
    $roles=[]; $i=0;
    foreach (GameServer::ADMIN_PERMISSIONS as $name=>$_) { $roles[$name]='88000000000000000'.(++$i); }
    $guildRoles=new SyncRepository(array_fill_keys(array_values($roles),(object)['present'=>true]));
    $held=new SyncRepository(array_fill_keys([$roles['Admin']],true));
    $members=new SyncRepository([$f->user=>(object)['id'=>$f->user,'roles'=>$held]]);
    $guild=(object)['members'=>$members,'roles'=>$guildRoles];
    $discord=new SyncEmitter(new SyncRepository([$f->guild=>$guild]));
    $verifier=new SyncVerifier($f,$members); $verifier->verified=[['discord'=>$f->user,'ss13'=>'Fake.Admin']];
    $loop=new class($f) {
        public function __construct(private object $f) {}
        public function addTimer(float $delay,callable $callback): object { ++$this->f->timerCalls; $timer=(object)['delay'=>$delay,'callback'=>$callback,'active'=>true]; $this->f->timers[]=$timer; return $timer; }
        public function cancelTimer(object $timer): void { if ($timer->active) { $timer->active=false; ++$this->f->cancelCalls; } }
    };
    $logger=new class($f) { public function __construct(private object $f) {} public function info(string $message,array $context=[]): void { $this->f->reports[]=$context; } };
    $app=(object)['verifier'=>$verifier,'civ13_guild_id'=>$f->guild,'role_ids'=>$roles,'discord'=>$discord,'loop'=>$loop,'logger'=>$logger,'ready'=>$alreadyReady];
    $writer=static function (string $bytes,int $generation,callable $guard) use ($f): mixed {
        if ($f->writerHook!==null) { return ($f->writerHook)($bytes,$generation,$guard); }
        if (!$guard()) { return ['state'=>'superseded']; }
        $f->file=$bytes; $f->writes[]=$bytes; return ['state'=>'updated'];
    };
    return [$app,$f,$writer];
}
function syncSettings(): array { return ['enabled'=>true,'max_bytes'=>4096,'debounce_seconds'=>0.25,'refresh_timeout_seconds'=>5.0]; }
function syncAttach(object $app,object $f,callable $writer): AdminFileSync {
    $sync=AdminFileSync::attach($app,syncSettings(),'/inert/admins.txt',$writer,static fn()=>$f->now);
    compositionCheck($sync instanceof AdminFileSync,'Enabled composition missing'); return $sync;
}
function syncTick(object $f,float $delay=0.25): void {
    foreach ($f->timers as $timer) { if ($timer->active && $timer->delay===$delay) { $timer->active=false; ($timer->callback)(); return; } }
    throw new RuntimeException('Expected owned timer');
}
function syncEvent(object $app,object $f,string $event='GUILD_MEMBER_UPDATE'): void { $app->discord->emit($event,[(object)['guild_id'=>$f->guild],$app->discord,null]); }

$untouchable=new class { public function __get(string $key): never { throw new RuntimeException('Disabled app read'); } };
$effect=static function (): never { throw new RuntimeException('Disabled effect'); };
compositionCheck(AdminFileSync::attach($untouchable,[],'',$effect,$effect)===null,'Omitted option had effects');
compositionCheck(AdminFileSync::attach($untouchable,['enabled'=>false],'',$effect,$effect)===null,'Disabled option had effects');
// Shared pure preflight rejects complete-map errors before any bootstrap/client effects.
[$app,$f,$writer]=syncFixture();
AdminFileSync::validateConfiguration(syncSettings(),'/inert/admins.txt',$f->guild,$app->role_ids);
compositionCheck($app->discord->adds===0 && $f->timerCalls===0 && $app->verifier->refreshes===0,'Configuration preflight had runtime effects');
foreach (array_keys(GameServer::ADMIN_PERMISSIONS) as $name) {
    $roles=$app->role_ids; unset($roles[$name]);
    try { AdminFileSync::validateConfiguration(syncSettings(),'/inert/admins.txt',$f->guild,$roles); throw new RuntimeException('Missing full-map role accepted'); }
    catch (InvalidArgumentException $expectedError) { compositionCheck(true,'Missing role rejected before init'); }
}
foreach (['duplicate','malformed'] as $bad) {
    $roles=$app->role_ids; $roles['Admin']=$bad==='duplicate'?$roles['Owner']:'not-a-role';
    try { AdminFileSync::validateConfiguration(syncSettings(),'/inert/admins.txt',$f->guild,$roles); throw new RuntimeException('Invalid role map accepted'); }
    catch (InvalidArgumentException $expectedError) { compositionCheck(true,'Invalid role map rejected before init'); }
}
$bootstrap=file_get_contents(__DIR__.'/../../bot.php');
$preflight=strpos($bootstrap,'\\Civ13\\AdminFileSync::validateConfiguration(');
compositionCheck($preflight!==false && $preflight<strpos($bootstrap,'$loadedNames = loadEnv(')
    && $preflight<strpos($bootstrap,'$discord = new Discord('),'Portable full-role validation is not before credentials/client construction');

// Owner-selected existing feed requires no new validator or trust flag.
[$app,$f,$writer]=syncFixture(); $sync=syncAttach($app,$f,$writer);
compositionCheck($app->discord->adds===8 && $f->timerCalls===0 && $app->verifier->refreshes===0 && $f->writes===[],'Construction fetched or wrote before init');
$app->discord->emit('init',[$app->discord]); syncTick($f);
$expected="fakeadmin;Admin;16254|||\n";
compositionCheck($f->file===$expected && $app->verifier->lookups===1,'Existing cached lookup/join failed');
$guild=$app->discord->guilds->get('id',$f->guild);
compositionCheck($guild->members->refreshes===0 && $guild->roles->refreshes===0,'Init needlessly refreshed cache');
$guild->members->items=[]; syncEvent($app,$f,'GUILD_MEMBER_REMOVE'); syncTick($f);
compositionCheck($f->file==='' && $guild->members->refreshes===0,'Cached departure did not revoke authoritatively');
$sync->stop(); compositionCheck($app->discord->removes===8,'Owned listener cleanup missing');
$before=$f->timerCalls; $app->discord->emit('init'); compositionCheck($f->timerCalls===$before,'Stopped listener remained active');
// Retain unrelated listeners; stop cancels only owned scheduled work.
[$app,$f,$writer]=syncFixture(); $other=0; $app->discord->on('init',static function () use (&$other): void { ++$other; });
$sync=syncAttach($app,$f,$writer); $app->discord->emit('init'); $sync->stop(); $app->discord->emit('init');
compositionCheck($other===2 && count($app->discord->listeners['init'])===1 && $f->writes===[] && $f->cancelCalls===1,'Cleanup affected unrelated listener or wrote cancelled work');
// Known late attachment schedules initial cached projection without waiting for init again.
[$app,$f,$writer]=syncFixture(true); $sync=syncAttach($app,$f,$writer); syncTick($f);
compositionCheck($f->file===$expected,'Late known-ready binding never synchronized'); $sync->stop();
// Required roles/read-only verification are explicit; missing values produce no registration.
foreach (['readonly','roles','guild'] as $bad) {
    [$app,$f,$writer]=syncFixture();
    if ($bad==='readonly') { $app->verifier->readOnly=false; }
    elseif ($bad==='roles') { $app->role_ids=[]; } else { $app->civ13_guild_id=''; }
    try { syncAttach($app,$f,$writer); throw new LogicException('Bad configuration accepted'); }
    catch (LogicException $error) { compositionCheck($error->getMessage()!=='Bad configuration accepted','Bad configuration accepted'); }
    compositionCheck($app->discord->adds===0 && $app->verifier->refreshes===0 && $f->writes===[],'Invalid configuration had effects');
}
// Reconnect uses the existing library repositories, and no member event starts HTTP.
[$app,$f,$writer]=syncFixture(true); $sync=syncAttach($app,$f,$writer); syncTick($f);
$guild=$app->discord->guilds->get('id',$f->guild); $pending=new Deferred(); $guild->members->hook=static fn()=>$pending->promise();
$app->discord->emit('reconnected'); $guild->members->items=[]; syncEvent($app,$f,'GUILD_MEMBER_REMOVE');
compositionCheck($guild->members->refreshes===1 && $guild->roles->refreshes===1 && $f->file===$expected,'Refresh barrier or repository reuse failed');
$pending->resolve(null); syncTick($f); compositionCheck($f->file==='' && $guild->members->refreshes===1,'Refresh completion did not use updated cache'); $sync->stop();
// One repository failure must not release ownership while the other refresh is pending.
foreach (['reject','throw'] as $failure) {
    [$app,$f,$writer]=syncFixture(true); $sync=syncAttach($app,$f,$writer); syncTick($f);
    $guild=$app->discord->guilds->get('id',$f->guild); $memberPending=new Deferred(); $rolePending=new Deferred();
    $guild->members->hook=static function () use ($failure,$memberPending): PromiseInterface { if ($failure==='throw') { throw new RuntimeException('synthetic member refresh'); } return $memberPending->promise(); };
    $guild->roles->hook=static fn()=>$rolePending->promise(); $app->discord->emit('reconnected');
    if ($failure==='reject') { $memberPending->reject(new RuntimeException('synthetic member rejection')); }
    $app->discord->emit('reconnected');
    compositionCheck($guild->members->refreshes===1 && $guild->roles->refreshes===1 && $f->file===$expected,'Early repository failure overlapped an outstanding refresh');
    $guild->members->hook=null; $guild->roles->hook=null; $guild->members->items=[]; $rolePending->resolve(null);
    compositionCheck($guild->members->refreshes===2 && $guild->roles->refreshes===2,'Received reconnect was not serviced after both repositories drained');
    syncTick($f); compositionCheck($f->file==='','Coalesced refresh failed to project latest cache'); $sync->stop();
}
// Stop during the first repository call prevents starting the second repository effect.
[$app,$f,$writer]=syncFixture(true); $sync=syncAttach($app,$f,$writer); syncTick($f);
$guild=$app->discord->guilds->get('id',$f->guild);
$guild->members->hook=static function () use ($sync): PromiseInterface { $sync->stop(); return resolve(null); };
$app->discord->emit('reconnected');
compositionCheck($guild->members->refreshes===1 && $guild->roles->refreshes===0 && $f->file===$expected,'Reentrant stop still started role refresh');
// Writer precommit rejects identity changes even when record count and success timestamp match.
foreach (['records','status','verifier','config','stop'] as $change) {
    [$app,$f,$writer]=syncFixture(true); $pending=new Deferred(); $guard=null;
    $f->writerHook=static function (string $bytes,int $generation,callable $canCommit) use (&$guard,$pending): PromiseInterface { $guard=$canCommit; return $pending->promise(); };
    $sync=syncAttach($app,$f,$writer); syncTick($f);
    compositionCheck(is_callable($guard) && $guard(),'Initial paired precommit failed');
    if ($change==='records') { $app->verifier->verified[0]['ss13']='differentidentity'; }
    elseif ($change==='status') { $f->status['refresh_in_progress']=true; }
    elseif ($change==='verifier') { $app->verifier=clone $app->verifier; }
    elseif ($change==='config') { $app->role_ids['Admin']='880000000000000099'; }
    else { $sync->stop(); }
    compositionCheck(!$guard() && $f->file==='previous file','Obsolete identity/config/stop passed commit guard');
    $pending->resolve(['state'=>'superseded']); $sync->stop();
}
// Failure status and mismatched pairing never mean a valid empty authoritative list.
foreach (['unavailable','count','capture'] as $bad) {
    [$app,$f,$writer]=syncFixture(true);
    if ($bad==='unavailable') { $f->status['available']=false; $f->status['stale']=true; }
    elseif ($bad==='count') { $f->status['records']=2; }
    else { $app->verifier->statusHook=static function () use ($app,$f): array { $s=$f->status; $s['age_seconds']=$app->verifier->statusReads; return $s; }; }
    $sync=syncAttach($app,$f,$writer); syncTick($f);
    compositionCheck($f->file==='previous file' && $f->writes===[] && end($f->reports)['state']==='blocked','Invalid verification was written'); $sync->stop();
}
// Existing feed normalization/ambiguity protections remain at the projection boundary.
foreach (['duplicate-id','duplicate-ckey','unsafe-admin'] as $bad) {
    [$app,$f,$writer]=syncFixture(true);
    if ($bad==='duplicate-id') { $app->verifier->verified[]=['discord'=>$f->user,'ss13'=>'otheridentity']; }
    elseif ($bad==='duplicate-ckey') { $app->verifier->verified[]=['discord'=>'990000000000000099','ss13'=>'fakeadmin']; }
    else { $app->verifier->verified[0]['ss13']='unsafe;admin'; }
    $f->status['records']=count($app->verifier->verified);
    $sync=syncAttach($app,$f,$writer); syncTick($f);
    compositionCheck($f->file==='previous file' && $f->writes===[] && end($f->reports)['state']==='blocked','Ambiguous or unsafe feed identity was written'); $sync->stop();
}
[$app,$f,$writer]=syncFixture(true); $app->verifier->verified=[]; $f->status['records']=0;
$sync=syncAttach($app,$f,$writer); syncTick($f);
compositionCheck($f->file==='' && count($f->writes)===1,'Valid successful empty identity snapshot was not authoritative'); $sync->stop();

// Changed configuration is rejected before starting source or cache refresh effects.
[$app,$f,$writer]=syncFixture(true); $sync=syncAttach($app,$f,$writer); $app->role_ids['Admin']='880000000000000099'; syncTick($f);
$guild=$app->discord->guilds->get('id',$f->guild); $app->discord->emit('reconnected');
compositionCheck($app->verifier->refreshes===0 && $guild->members->refreshes===0 && $guild->roles->refreshes===0 && $f->writes===[],'Changed configuration started refresh effects'); $sync->stop();
// Preserve the original registration error even when owned cleanup also fails.
[$app,$f,$writer]=syncFixture();
$app->discord->onHook=static function (): void { throw new RuntimeException('original registration failure'); };
$app->discord->removeHook=static function (): void { throw new RuntimeException('cleanup failure'); };
try { syncAttach($app,$f,$writer); throw new LogicException('Registration failure swallowed'); }
catch (RuntimeException $error) { compositionCheck($error->getMessage()==='original registration failure','Cleanup masked original registration failure'); }
// Cold Civ13 ordering: verifier is supplied by an earlier init handler before composition attaches.
[$app,$f,$writer]=syncFixture(); $verifier=$app->verifier; unset($app->verifier); $sync=null;
$app->discord->on('init',static function () use ($app,$verifier): void { $app->verifier=$verifier; });
$app->discord->on('init',static function () use ($app,$f,$writer,&$sync): void { $app->ready=true; $sync=syncAttach($app,$f,$writer); });
$app->discord->emit('init'); syncTick($f);
compositionCheck($sync instanceof AdminFileSync && $f->file===$expected,'Cold init source/setup ordering failed'); $sync->stop();
// Runtime attachment unavailability fences this feature, not unrelated init work.
[$app,$f,$writer]=syncFixture(); $app->verifier->readOnly=false; $service=null; $continued=false; $reported=false;
$app->discord->on('init',static function () use ($app,$f,$writer,&$service,&$continued,&$reported): void {
    $app->ready=true;
    try { $service=syncAttach($app,$f,$writer); }
    catch (Throwable) { $service=null; $reported=true; }
    $continued=true;
});
$app->discord->emit('init');
compositionCheck($service===null && $reported && $continued && $f->writes===[],'Runtime attachment failure aborted unrelated init or granted writes');
$core=file_get_contents(__DIR__.'/../../src/Civ13/Civ13.php');
compositionCheck(str_contains($core,'Admin file synchronization inactive: runtime attachment failed')
    && str_contains($core,'catch (\\Throwable)'), 'Core feature-only attachment failure guard missing');

echo "PASS portable admin composition ({$checks} assertions): disabled effects, cached join, existing refresh, paired identities and owned cleanup\n";
