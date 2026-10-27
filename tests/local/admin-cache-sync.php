<?php
declare(strict_types=1);
// Synthetic already-updated caches, gateway events, timers and writer. No bootstrap/network/files.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
foreach (['HostedVerificationSource','HostedVerificationSnapshot','AdminListRenderer','AdminSyncSnapshotGate','AdminSyncCoordinator','AdminCacheSyncTrigger'] as $class) {
    require_once __DIR__.'/../../src/Civ13/'.$class.'.php';
}
use Civ13\AdminCacheSyncTrigger;
use Civ13\AdminSyncCoordinator;
use Civ13\AdminSyncSnapshotGate;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use function React\Promise\reject;
$checks=0;
function cacheCheck(bool $ok,string $message): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($message); } }
function cacheFixture(bool $enabled=true,int $maxPasses=3): array {
    $f=(object)['available'=>true,'availableHook'=>null,'refreshHook'=>null,'memberHook'=>null,'writeHook'=>null,
        'requestHook'=>null,'scheduleHook'=>null,'reportHook'=>null,'configHook'=>null,'cancelHook'=>null,'now'=>1800000000,
        'refreshes'=>0,'requests'=>0,'memberReads'=>0,'timerCalls'=>0,'timerCancels'=>0,'configChanges'=>0,
        'timers'=>[],'reports'=>[],'file'=>'retained existing admin file','writes'=>[],
        'guild'=>'770000000000000001','role'=>'880000000000000001','user'=>'990000000000000001'];
    $f->roles=[$f->role]; $f->members=[['id'=>$f->user,'roles'=>[$f->role]]];
    $trigger=null;
    $coordinator=new AdminSyncCoordinator(true,
        static function () use ($f): array {
            return ['status'=>['available'=>true,'stale'=>false,'has_valid_list'=>true,'refresh_in_progress'=>false,
                'records'=>1,'age_seconds'=>0,'last_success_utc'=>gmdate('Y-m-d\TH:i:s\Z',$f->now)],
                'records'=>[['discord'=>$f->user,'ss13'=>'fakeadmin']]];
        },
        static function (int $generation) use ($f,&$trigger): mixed {
            ++$f->memberReads;
            if ($f->memberHook!==null) { return ($f->memberHook)($generation); }
            if (!$f->available) { throw new RuntimeException('Guild/cache missing'); }
            return ['guild_id'=>$f->guild,'generation'=>$generation,'cache_epoch'=>$trigger->epoch(),'roles'=>$f->roles,'members'=>$f->members];
        },
        static function (array $verification,array $membership,int $generation) use ($f,&$trigger): string {
            return AdminSyncSnapshotGate::renderCached($verification['status'],$verification['records'],$membership,
                $f->guild,$generation,$f->now,['Admin'=>$f->role],['Admin'=>['Admin','16254']],$trigger->state());
        },
        static function (string $bytes,int $generation,callable $guard) use ($f): mixed {
            if ($f->writeHook!==null) { return ($f->writeHook)($bytes,$generation,$guard); }
            if (!$guard()) { return ['state'=>'superseded']; }
            $f->file=$bytes; $f->writes[]=$bytes; return ['state'=>'updated'];
        },$maxPasses);
    $trigger=new AdminCacheSyncTrigger($enabled,$f->guild,
        static function () use ($f,$coordinator): mixed { ++$f->requests; return $f->requestHook!==null?($f->requestHook)($coordinator):$coordinator->request(); },
        static function () use ($f): mixed { return $f->availableHook!==null?($f->availableHook)():$f->available; },
        static function () use ($f): mixed { ++$f->refreshes; return $f->refreshHook!==null?($f->refreshHook)():resolve(null); },
        static function (float $delay,callable $callback) use ($f): mixed {
            ++$f->timerCalls;
            if ($f->scheduleHook!==null) { return ($f->scheduleHook)($delay,$callback); }
            $slot=(object)['delay'=>$delay,'callback'=>$callback,'active'=>true]; $f->timers[]=$slot;
            return static function () use ($f,$slot): void { if ($slot->active) { $slot->active=false; ++$f->timerCancels; if ($f->cancelHook!==null) { ($f->cancelHook)(); } } };
        },
        static function (array $outcome) use ($f): void { $f->reports[]=$outcome; if ($f->reportHook!==null) { ($f->reportHook)(); } },
        static function () use ($f): void { ++$f->configChanges; if ($f->configHook!==null) { ($f->configHook)(); } },0.25,5.0);
    return [$trigger,$f];
}
function cacheTick(object $f,float $delay=0.25): void {
    foreach ($f->timers as $slot) { if ($slot->active && $slot->delay===$delay) { $slot->active=false; ($slot->callback)(); return; } }
    throw new RuntimeException('Expected synthetic timer');
}
function cacheEvent(AdminCacheSyncTrigger $t,object $f,string $name='GUILD_MEMBER_UPDATE'): void { ($t->listeners()[$name])((object)['guild_id'=>$f->guild],null,null); }
$admin="fakeadmin;Admin;16254|||\n";
[$t,$f]=cacheFixture(); cacheEvent($t,$f);
cacheCheck(!$t->isReady() && $f->requests===0 && $f->refreshes===0 && $f->writes===[],'Before-init event obtained authority');
($t->listeners()['init'])(); cacheTick($f);
cacheCheck($t->isReady() && $f->file===$admin && $f->refreshes===0,'Init did not trust initialized cache directly');
cacheCheck($t->listeners()===$t->listeners(),'Listener closure identities changed');
($t->listeners()['init'])(); cacheCheck($f->requests===1,'Duplicate init restarted work');
// The library has already mutated its cache before these event callbacks run.
$f->members[0]['roles']=[]; cacheEvent($t,$f); cacheEvent($t,$f); cacheTick($f);
cacheCheck($f->file==='' && $f->refreshes===0 && $f->requests===2,'Cached role removal not authoritative or caused HTTP refresh');
$f->members=[['id'=>$f->user,'roles'=>[$f->role]]]; cacheEvent($t,$f,'GUILD_MEMBER_ADD'); cacheTick($f);
cacheCheck($f->file===$admin,'Cached member addition not projected');
$f->members=[]; cacheEvent($t,$f,'GUILD_MEMBER_REMOVE'); cacheTick($f);
cacheCheck($f->file==='' && $f->refreshes===0,'Cached admin departure not authoritative');
// No artificial membership TTL: later events use current cache, with fresh verifier status.
$f->now+=86400; $f->members=[['id'=>$f->user,'roles'=>[$f->role]]]; cacheEvent($t,$f); cacheTick($f);
cacheCheck($f->file===$admin && $t->isReady(),'Cache was made stale by elapsed wall time');
$f->roles=[]; cacheEvent($t,$f,'GUILD_ROLE_DELETE'); cacheTick($f);
cacheCheck($f->file===$admin && end($f->reports)['state']==='blocked','Missing configured role cleared file');
[$t,$f]=cacheFixture(); $f->available=false; ($t->listeners()['init'])();
cacheCheck(!$t->isReady() && $f->requests===0 && $f->file==='retained existing admin file','Missing initial guild/cache cleared file');
[$t,$f]=cacheFixture(); $t->bootstrapReady(); cacheTick($f);
cacheCheck($f->file===$admin && $f->refreshes===0,'Explicit known-ready bootstrap did not use cache');
// Reconnect exclusively refreshes caches; events during refresh do not refresh again.
$pending=new Deferred(); $f->refreshHook=static fn()=>$pending->promise(); ($t->listeners()['reconnected'])();
cacheCheck(!$t->isReady() && $f->refreshes===1 && $f->file===$admin,'Reconnect barrier not closed');
$f->members=[]; cacheEvent($t,$f); cacheEvent($t,$f,'GUILD_ROLE_UPDATE');
cacheCheck($f->refreshes===1 && $f->requests===1 && !$t->isReady(),'Refresh-time events started HTTP/reconciliation prematurely');
$pending->resolve(null); cacheTick($f);
cacheCheck($t->isReady() && $f->file==='' && $f->refreshes===1,'Refresh completion did not project latest cache');
// Timeout is a result barrier, not cancellation; never-settling requests remain closed/draining.
[$t,$f]=cacheFixture(); $t->bootstrapReady(); cacheTick($f); $pending=new Deferred(); $f->refreshHook=static fn()=>$pending->promise();
($t->listeners()['reconnected'])(); cacheTick($f,5.0); cacheEvent($t,$f); $f->members=[];
$t->configurationChanged();
cacheCheck(!$t->isReady() && $f->refreshes===1 && $f->file===$admin && $f->requests===1,'Timeout retried or retained partial cache authority');
$pending->resolve(null);
cacheCheck(!$t->isReady() && $f->file===$admin,'Late refresh restored authority after timeout');
$f->refreshHook=null; ($t->listeners()['reconnected'])(); cacheTick($f);
cacheCheck($t->isReady() && $f->refreshes===2 && $f->file==='','Later explicit reconnect failed to recover');
foreach (['reject','throw','nonpromise','unavailable'] as $mode) {
    [$t,$f]=cacheFixture(); $t->bootstrapReady(); cacheTick($f);
    $f->refreshHook=static function () use ($mode,$f): mixed {
        if ($mode==='throw') { throw new RuntimeException('synthetic refresh failure'); }
        if ($mode==='unavailable') { $f->available=false; return resolve(null); }
        return $mode==='nonpromise'?null:reject(new RuntimeException('synthetic refresh failure'));
    };
    ($t->listeners()['reconnected'])(); cacheEvent($t,$f); $t->configurationChanged();
    cacheCheck(!$t->isReady() && $f->file===$admin && $f->refreshes===1,'Failed refresh cleared or reauthorized partial cache');
}
// Cache epoch invalidates delayed writer immediately, even before debounce or another read.
[$t,$f]=cacheFixture(); $write=new Deferred(); $guard=null;
$f->writeHook=static function (string $bytes,int $generation,callable $canCommit) use ($f,$write,&$guard): mixed {
    if ($guard===null) { $guard=$canCommit; return $write->promise(); }
    if (!$canCommit()) { return ['state'=>'superseded']; }
    $f->file=$bytes; $f->writes[]=$bytes; return ['state'=>'updated'];
};
$t->bootstrapReady(); cacheTick($f); $f->members=[]; cacheEvent($t,$f);
cacheCheck(!$guard() && $f->requests===2 && $f->refreshes===0,'Event did not immediately invalidate entered cache writer');
$write->resolve(['state'=>'superseded']);
cacheCheck($f->file==='' && count($f->reports)===1,'Dirty event lost current cached projection');
// Stop suppresses queued callbacks and late refresh success.
[$t,$f]=cacheFixture(); $t->bootstrapReady(); $late=$f->timers[0]->callback; $epoch=$t->epoch(); $t->stop(); $late(); cacheEvent($t,$f);
cacheCheck(!$t->isReady() && $t->epoch()>$epoch && $f->requests===0 && $f->timerCancels===1,'Stop failed to fence queued callback');
[$t,$f]=cacheFixture(); $pending=new Deferred(); $f->refreshHook=static fn()=>$pending->promise(); ($t->listeners()['reconnected'])(); $t->stop(); $pending->resolve(null);
cacheCheck(!$t->isReady() && $f->requests===0 && $f->writes===[],'Stopped refresh restored authority');
[$t,$f]=cacheFixture(); $t->bootstrapReady(); $epoch=$t->epoch(); $t->configurationChanged(); cacheTick($f);
cacheCheck($f->configChanges===1 && $t->epoch()>$epoch && $f->requests===1,'Configuration did not invalidate/coalesce current cache');
[$t,$f]=cacheFixture(false); $t->bootstrapReady(); $t->configurationChanged(); $t->stop();
foreach ($t->listeners() as $listener) { $listener((object)['guild_id'=>$f->guild]); }
cacheCheck(!$t->isReady() && $f->requests===0 && $f->refreshes===0 && $f->timerCalls===0 && $f->configChanges===0,'Disabled cache trigger had effects');
[$t,$f]=cacheFixture(); ($t->listeners()['GUILD_MEMBER_UPDATE'])((object)['guild_id'=>'770000000000000002']);
cacheCheck($t->epoch()===0 && $f->timerCalls===0,'Other guild invalidated target cache');
// An expiry during cache-availability checking cannot restore readiness afterward.
[$t,$f]=cacheFixture(); $pending=new Deferred(); $f->refreshHook=static fn()=>$pending->promise(); ($t->listeners()['reconnected'])();
$f->availableHook=static function () use ($f): bool { cacheTick($f,5.0); return true; }; $pending->resolve(null);
cacheCheck(!$t->isReady() && $f->requests===0,'Reentrant refresh expiry restored readiness');
foreach ([static fn()=>null,static function (): mixed { throw new RuntimeException('timer failed'); }] as $hook) {
    [$t,$f]=cacheFixture(); $f->scheduleHook=$hook; ($t->listeners()['reconnected'])();
    cacheCheck(!$t->isReady() && $f->refreshes===0 && $f->writes===[],'Broken deadline scheduler started refresh');
}
[$t,$f]=cacheFixture(); $f->scheduleHook=static function (float $delay,callable $callback): callable { $callback(); return static function (): void {}; };
($t->listeners()['reconnected'])(); cacheCheck(!$t->isReady() && $f->refreshes===0,'Synchronous deadline started expired refresh');
// Late init cannot bless an outstanding or failed reconnect cache.
[$t,$f]=cacheFixture(); $pending=new Deferred(); $f->refreshHook=static fn()=>$pending->promise();
($t->listeners()['reconnected'])(); ($t->listeners()['init'])(); $t->bootstrapReady();
cacheCheck(!$t->isReady() && $f->requests===0,'Late init bypassed reconnect barrier');
$pending->reject(new RuntimeException('synthetic')); ($t->listeners()['init'])(); $t->configurationChanged();
cacheCheck(!$t->isReady() && $f->requests===0,'Late init/config blessed failed cache');
// Reconnect bursts coalesce ONE pending refresh after the obsolete attempt drains.
[$t,$f]=cacheFixture(); $first=new Deferred(); $second=new Deferred();
$f->refreshHook=static function () use ($f,$first,$second): PromiseInterface { return $f->refreshes===1?$first->promise():$second->promise(); };
($t->listeners()['reconnected'])(); ($t->listeners()['reconnected'])(); ($t->listeners()['reconnected'])();
cacheCheck($f->refreshes===1 && !$t->isReady(),'Reconnect burst overlapped refreshes');
$first->resolve(null);
cacheCheck(!$t->isReady() && $f->refreshes===2 && $f->requests===0
    && end($f->reports)['phase']==='cache-refresh-obsolete','Obsolete reconnect lost pending refresh or restored authority');
$second->resolve(null); cacheTick($f);
cacheCheck($t->isReady() && $f->refreshes===2 && $f->file===$admin,'Coalesced reconnect did not restore trusted cache');
// A received reconnect waits through timeout/drain; deadline alone never loops.
[$t,$f]=cacheFixture(); $first=new Deferred(); $second=new Deferred();
$f->refreshHook=static function () use ($f,$first,$second): PromiseInterface { return $f->refreshes===1?$first->promise():$second->promise(); };
($t->listeners()['reconnected'])(); cacheTick($f,5.0); ($t->listeners()['reconnected'])();
cacheCheck($f->refreshes===1 && !$t->isReady(),'Timed-out outstanding refresh overlapped');
$first->resolve(null); cacheCheck($f->refreshes===2 && !$t->isReady(),'Pending reconnect not serviced after timeout drain');
$second->resolve(null); cacheTick($f); cacheCheck($t->isReady(),'Pending reconnect completion did not restore cache');
// Configuration failure during a refresh must fence that attempt permanently.
[$t,$f]=cacheFixture(); $pending=new Deferred(); $f->refreshHook=static fn()=>$pending->promise();
($t->listeners()['reconnected'])(); $f->configHook=static function (): void { throw new RuntimeException('synthetic configuration failure'); };
try { $t->configurationChanged(); throw new LogicException('Configuration failure swallowed'); } catch (RuntimeException $expected) {}
$pending->resolve(null);
cacheCheck(!$t->isReady() && $f->requests===0,'Failed configuration was overwritten by refresh completion');
// A reconnect queued before failed config cannot bless that failed config after drain.
[$t,$f]=cacheFixture(); $first=new Deferred(); $second=new Deferred();
$f->refreshHook=static function () use ($f,$first,$second): PromiseInterface { return $f->refreshes===1?$first->promise():$second->promise(); };
($t->listeners()['reconnected'])(); ($t->listeners()['reconnected'])();
$f->configHook=static function (): void { throw new RuntimeException('synthetic queued configuration failure'); };
try { $t->configurationChanged(); throw new LogicException('Configuration failure swallowed'); } catch (RuntimeException $expected) {}
$first->resolve(null); $second->resolve(null);
cacheCheck(!$t->isReady() && $f->refreshes===2 && $f->requests===0 && $f->file==='retained existing admin file',
    'Queued refresh blessed failed configuration');
$f->configHook=null; $t->configurationChanged(); $f->refreshHook=null; ($t->listeners()['reconnected'])(); cacheTick($f);
cacheCheck($t->isReady() && $f->file===$admin,'Successful explicit config update and reconnect could not recover');
// An older successful callback cannot bless a nested failed configuration update.
[$t,$f]=cacheFixture(); $t->bootstrapReady(); cacheTick($f);
$f->configHook=static function () use ($t,$f): void {
    $f->configHook=static function (): void { throw new RuntimeException('nested failed config'); };
    try { $t->configurationChanged(); } catch (RuntimeException $expected) {}
};
$t->configurationChanged();
cacheCheck(!$t->isReady() && $f->configChanges===2 && $f->file===$admin,'Older callback blessed nested invalid configuration');
// Deadline during refresh construction followed by a throw releases only drained ownership.
[$t,$f]=cacheFixture(); $f->refreshHook=static function () use ($f): mixed { cacheTick($f,5.0); throw new RuntimeException('synthetic after deadline'); };
($t->listeners()['reconnected'])(); cacheCheck(!$t->isReady() && $f->refreshes===1,'Expired throwing refresh obtained authority');
$f->refreshHook=null; ($t->listeners()['reconnected'])(); cacheTick($f);
cacheCheck($t->isReady() && $f->refreshes===2,'Expired throwing refresh kept a false outstanding gate');
// Timer cancellation and availability callbacks cannot reopen stopped/obsolete context.
[$t,$f]=cacheFixture(); $pending=new Deferred(); $f->refreshHook=static fn()=>$pending->promise(); ($t->listeners()['reconnected'])();
$f->cancelHook=static function () use ($t,$f): void { $f->cancelHook=null; $t->stop(); }; $pending->resolve(null);
cacheCheck(!$t->isReady() && $f->requests===0,'Timer-cancel reentrancy reopened stopped readiness');
[$t,$f]=cacheFixture(); $first=new Deferred(); $second=new Deferred();
$f->refreshHook=static function () use ($f,$first,$second): PromiseInterface { return $f->refreshes===1?$first->promise():$second->promise(); };
($t->listeners()['reconnected'])();
$f->availableHook=static function () use ($t,$f): bool { $f->availableHook=null; ($t->listeners()['reconnected'])(); return true; }; $first->resolve(null);
cacheCheck(!$t->isReady() && $f->refreshes===2 && $f->requests===0,'Availability reentrancy blessed obsolete reconnect');
$second->resolve(null); cacheTick($f); cacheCheck($t->isReady(),'Availability-reentrant pending reconnect lost');
echo "PASS cache sync ({$checks} assertions): initialized cache, event projection, refresh barrier, epochs and retained-file failures\n";
