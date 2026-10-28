<?php
declare(strict_types=1);
// Inert facade, cache, timers and memory writer; owned reload marker only. No clients/admin files/bootstrap.
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

// An external verifier completion consumes its already-replaced cache, not another refresh.
[$app,$f,$writer]=syncFixture(true); $sync=syncAttach($app,$f,$writer); syncTick($f);
$refreshes=$app->verifier->refreshes;
$app->verifier->verified=[]; $f->status['records']=0;
$sync->verificationChanged(); syncTick($f);
compositionCheck($f->file==='' && $app->verifier->refreshes===$refreshes,'Verifier-only removal refetched or retained old authority');
$app->verifier->verified=[['discord'=>$f->user,'ss13'=>'newidentity']]; $f->status['records']=1;
$sync->verificationChanged(); $sync->verificationChanged(); syncTick($f);
compositionCheck($f->file==="newidentity;Admin;16254|||\n" && $app->verifier->refreshes===$refreshes,'Verifier-only addition refetched or failed to coalesce');
compositionCheck(count(array_filter($f->timers,static fn($timer)=>$timer->active))===0,'Verifier projection scheduled another burst');
$sync->stop(); $effects=[$f->timerCalls,$app->verifier->refreshes,count($f->writes)];
$sync->verificationChanged();
compositionCheck([$f->timerCalls,$app->verifier->refreshes,count($f->writes)]===$effects,'Stopped verifier hook caused effects');

// Recovery from unavailable verification serves the current cache with no extra source call.
[$app,$f,$writer]=syncFixture(true); $f->status['available']=false; $f->status['stale']=true;
$sync=syncAttach($app,$f,$writer); syncTick($f); $refreshes=$app->verifier->refreshes;
$f->status['available']=true; $f->status['stale']=false; $sync->verificationChanged(); syncTick($f);
compositionCheck($f->file===$expected && $app->verifier->refreshes===$refreshes,'Verifier recovery refetched or remained blocked'); $sync->stop();

// Coordinator-owned completion is already consumed, including synchronous callback reentrancy.
foreach (['synchronous','pending'] as $completion) {
    [$app,$f,$writer]=syncFixture(true); $sync=syncAttach($app,$f,$writer); $pending=new Deferred();
    $replace=static function () use ($app,$f,$sync): void {
        $app->verifier->verified=[]; $f->status['records']=0; $sync->verificationChanged();
    };
    $app->verifier->refreshHook=static function () use ($completion,$replace,$pending): PromiseInterface {
        if ($completion==='synchronous') { $replace(); return resolve(null); }
        return $pending->promise();
    };
    syncTick($f);
    if ($completion==='pending') { $replace(); $pending->resolve(null); }
    compositionCheck($f->file==='' && $app->verifier->refreshes===1 && count($f->writes)===1,
        'Already-consumed verifier completion self-dirtied or refetched');
    compositionCheck(count(array_filter($f->timers,static fn($timer)=>$timer->active))===0,'Already-consumed completion scheduled itself');
    $sync->stop();
}

// External completion fences a delayed writer even when only the success timestamp changes.
foreach (['removed','unchanged'] as $replacement) {
    [$app,$f,$writer]=syncFixture(true); $pending=new Deferred(); $guard=null;
    $f->writerHook=static function (string $bytes,int $generation,callable $canCommit) use ($pending,&$guard): PromiseInterface {
        $guard=$canCommit; return $pending->promise();
    };
    $sync=syncAttach($app,$f,$writer); syncTick($f); $refreshes=$app->verifier->refreshes;
    compositionCheck($guard(),'Pending initial verifier projection had no authority');
    if ($replacement==='removed') { $app->verifier->verified=[]; $f->status['records']=0; }
    ++$f->now; $f->status['last_success_utc']=gmdate('Y-m-d\\TH:i:s\\Z',$f->now);
    $sync->verificationChanged();
    compositionCheck(!$guard(),'Verifier replacement left a stale writer authorized');
    $f->writerHook=null; $pending->resolve(['state'=>'superseded']);
    compositionCheck($f->file===($replacement==='removed'?'':$expected)
        && $app->verifier->refreshes===$refreshes && count($f->writes)===1,
        'Dirty verifier-only continuation refetched or lost latest cache'); $sync->stop();
}

// The real owner bridge rejects retired verifiers and leaves stopped owners inert.
[$app,$f,$writer]=syncFixture(true); $sync=syncAttach($app,$f,$writer); syncTick($f);
$owner=(new ReflectionClass(\Civ13\Civ13::class))->newInstanceWithoutConstructor();
$currentVerifier=(new ReflectionClass(\Civ13\Verifier::class))->newInstanceWithoutConstructor();
$retiredVerifier=(new ReflectionClass(\Civ13\Verifier::class))->newInstanceWithoutConstructor();
$owner->verifier=$currentVerifier;
(new ReflectionProperty(\Civ13\Civ13::class,'adminFileSync'))->setValue($owner,$sync);
$effects=[$f->timerCalls,$app->verifier->refreshes,count($f->writes)];
$owner->hostedVerificationRefreshed($retiredVerifier);
compositionCheck([$f->timerCalls,$app->verifier->refreshes,count($f->writes)]===$effects,'Retired verifier notified current composition');
$owner->hostedVerificationRefreshed($currentVerifier); syncTick($f);
compositionCheck($app->verifier->refreshes===$effects[1] && count($f->writes)===$effects[2]+1,'Current owner bridge did not serve the cache-only projection');
$sync->stop(); $effects=[$f->timerCalls,$app->verifier->refreshes,count($f->writes)];
$owner->hostedVerificationRefreshed($currentVerifier);
compositionCheck([$f->timerCalls,$app->verifier->refreshes,count($f->writes)]===$effects,'Stopped owner completion caused effects');
unset($owner,$currentVerifier,$retiredVerifier);

// A completion during reconnect remains behind the existing cache-readiness barrier.
[$app,$f,$writer]=syncFixture(true); $sync=syncAttach($app,$f,$writer); syncTick($f); $refreshes=$app->verifier->refreshes;
$guild=$app->discord->guilds->get('id',$f->guild); $pending=new Deferred(); $guild->members->hook=static fn()=>$pending->promise();
$app->discord->emit('reconnected'); $app->verifier->verified=[]; $f->status['records']=0; $sync->verificationChanged();
compositionCheck($f->file===$expected,'Verifier completion bypassed reconnect barrier');
$pending->resolve(null); syncTick($f);
compositionCheck($f->file==='' && $app->verifier->refreshes===$refreshes,'Reconnect discarded cache-only projection or refetched'); $sync->stop();

// Reload command -> real Civ13::stop -> replacement composition, while an old write waits.
// Constructorless owner and existing inert facade only; no Discord client, loop run,
// supervisor child, profile load/edit or real admin file. Only an owned temporary marker.
final class SyncReloadOwner extends \Civ13\Civ13 {
    public Deferred $replyPending;
    public array $replyTexts=[];
    public function reply(\Discord\Parts\Channel\Message|\Discord\Parts\Thread\Thread $message,
        string $content,string $file_name='message.txt',bool $prevent_mentions=false): PromiseInterface {
        $this->replyTexts[]=$content; return $this->replyPending->promise();
    }
}
final class SyncShutdownLogger extends \Psr\Log\AbstractLogger {
    public array $guardAtShutdown=[];
    public function __construct(private \Closure $guard) {}
    public function log($level,string|\Stringable $message,array $context=[]): void {
        if ((string)$message==='Shutting down') { $this->guardAtShutdown[]=($this->guard)(); }
    }
}
foreach (['same-target','changed-target'] as $targetChange) {
    foreach (['resolved','rejected'] as $replyOutcome) {
        $state=sys_get_temp_dir().'/admin-reload-fence-'.bin2hex(random_bytes(8));
        if (!mkdir($state)) { throw new RuntimeException('Could not create isolated reload marker directory'); }
        $oldSync=null; $newSync=null; $owner=null;
        try {
            [$oldApp,$oldF,$unusedWriter]=syncFixture(true);
            $oldTarget='/inert/old-admins.txt';
            $newTarget=$targetChange==='same-target'?$oldTarget:'/inert/new-admins.txt';
            $files=[$oldTarget=>'previous old file'];
            if ($newTarget!==$oldTarget) { $files[$newTarget]='previous new file'; }
            $commits=[]; $pendingWrite=null; $oldWrite=new Deferred();
            $oldWriter=static function (string $bytes,int $generation,callable $guard) use (&$pendingWrite,$oldWrite): PromiseInterface {
                $pendingWrite=['bytes'=>$bytes,'guard'=>$guard]; return $oldWrite->promise();
            };
            $oldSync=AdminFileSync::attach($oldApp,syncSettings(),$oldTarget,$oldWriter,static fn()=>$oldF->now);
            syncTick($oldF);
            compositionCheck($pendingWrite!==null && ($pendingWrite['guard'])(),'Old write did not reach a live precommit barrier');

            // Keep actual stop() inherited: an override would hide the integration under test.
            $owner=(new ReflectionClass(SyncReloadOwner::class))->newInstanceWithoutConstructor();
            $owner->portable_state_dir=$state; $owner->replyPending=new Deferred();
            $owner->logger=new SyncShutdownLogger(\Closure::fromCallable($pendingWrite['guard']));
            $owner->loop=new \React\EventLoop\StreamSelectLoop(); // never run
            (new ReflectionProperty(\Civ13\Civ13::class,'adminFileSync'))->setValue($owner,$oldSync);
            $command=new \Civ13\MessageCommand\Commands\ReloadConfig($owner);
            $message=(new ReflectionClass(\Discord\Parts\Channel\Message::class))->newInstanceWithoutConstructor();
            $replyState='pending';
            $command($message,'reloadconfig',[])->then(
                static function () use (&$replyState): void { $replyState='resolved'; },
                static function (\Throwable $error) use (&$replyState): void { $replyState='rejected'; });
            compositionCheck(is_file($state.'/reload.request') && count($owner->replyTexts)===1,'Actual reload command did not request one relaunch');
            compositionCheck($replyState==='pending' && ($pendingWrite['guard'])(),'Fixture confused reload acknowledgement with completed shutdown');
            if ($replyOutcome==='resolved') { $owner->replyPending->resolve(null); }
            else { $owner->replyPending->reject(new RuntimeException('Synthetic reload reply failure')); }
            compositionCheck($replyState===$replyOutcome,'Reload reply outcome lost');
            compositionCheck($owner->logger->guardAtShutdown===[false],'Pending old write remained valid at first shutdown side effect');
            compositionCheck(!($pendingWrite['guard'])() && $oldApp->discord->removes===8,'Actual shutdown did not revoke old write/listeners');
            compositionCheck(\Civ13\PortableProcess::consumeReload($state) && !\Civ13\PortableProcess::consumeReload($state),'Reload marker was not consumed once');

            [$newApp,$newF,$unusedWriter]=syncFixture(true);
            $guild=$newApp->discord->guilds->get('id',$newF->guild);
            $newF->guild='770000000000000002'; $newApp->civ13_guild_id=$newF->guild;
            $newApp->discord->guilds->items=[$newF->guild=>$guild];
            foreach ($newApp->role_ids as $name=>$role) { $newApp->role_ids[$name]='89'.substr($role,2); }
            $guild->roles->items=array_fill_keys(array_values($newApp->role_ids),(object)['present'=>true]);
            $guild->members->items[$newF->user]->roles=new SyncRepository([$newApp->role_ids['Owner']=>true]);
            $newApp->verifier->verified=[['discord'=>$newF->user,'ss13'=>'Reloaded.Admin']];
            $newWriter=static function (string $bytes,int $generation,callable $guard) use (&$files,&$commits,$newTarget): array {
                if (!$guard()) { return ['state'=>'superseded']; }
                $files[$newTarget]=$bytes; $commits[]=$newTarget; return ['state'=>'updated'];
            };
            $newSync=AdminFileSync::attach($newApp,syncSettings(),$newTarget,$newWriter,static fn()=>$newF->now);
            syncTick($newF);
            compositionCheck($newApp->verifier!==$oldApp->verifier && $newApp->role_ids!==$oldApp->role_ids,'Replacement did not change source and role authority');
            compositionCheck($files[$newTarget]==="reloadedadmin;Host;65535|||\n" && $commits===[$newTarget],'Replacement failed to commit its new source/role/target projection');
            $afterReplacement=$files;
            // Drain the real old coordinator's delayed adapter only after the new write.
            // The adapter honors its final guard immediately before the memory mutation.
            $oldResult='superseded';
            if (($pendingWrite['guard'])()) {
                $files[$oldTarget]=$pendingWrite['bytes']; $commits[]=$oldTarget; $oldResult='updated';
            }
            $oldWrite->resolve(['state'=>$oldResult]);
            compositionCheck($oldResult==='superseded' && $files===$afterReplacement && $commits===[$newTarget],'Late old write overwrote replacement or retired target');
            compositionCheck($newTarget===$oldTarget || $files[$oldTarget]==='previous old file','Changed target incorrectly cleared or rewrote old destination');
            $oldEffects=[$oldF->timerCalls,$oldApp->verifier->refreshes,count($commits)];
            $oldApp->discord->emit('init'); $oldApp->discord->emit('reconnected'); syncEvent($oldApp,$oldF);
            compositionCheck([$oldF->timerCalls,$oldApp->verifier->refreshes,count($commits)]===$oldEffects,'Old callbacks regained authority after replacement');
        } finally {
            $oldSync?->stop(); $newSync?->stop(); unset($owner);
            if (is_file($state.'/reload.request')) { unlink($state.'/reload.request'); }
            rmdir($state);
        }
    }
}

echo "PASS portable admin composition ({$checks} assertions): disabled effects, cached join, existing refresh, paired identities, owned cleanup and reload fencing\n";
