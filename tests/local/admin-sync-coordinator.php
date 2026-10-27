<?php
declare(strict_types=1);
// Existing Composer dependency only; no bot bootstrap, env, Discord, HTTP or real writer.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../src/Civ13/HostedVerificationSource.php';
require_once __DIR__.'/../../src/Civ13/HostedVerificationSnapshot.php';
require_once __DIR__.'/../../src/Civ13/AdminListRenderer.php';
require_once __DIR__.'/../../src/Civ13/AdminSyncSnapshotGate.php';
require_once __DIR__.'/../../src/Civ13/AdminSyncCoordinator.php';

use Civ13\AdminSyncCoordinator;
use Civ13\AdminSyncSnapshotGate;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;

function coordinatorCheck(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function coordinatorOutcome(PromiseInterface $promise): array {
    $result = null;
    $promise->then(static function (array $value) use (&$result): void { $result = $value; });
    coordinatorCheck($result !== null, 'Expected settled result');
    return $result;
}
function coordinatorPending(PromiseInterface $promise): bool {
    $settled = false;
    $promise->then(static function () use (&$settled): void { $settled = true; }, static function () use (&$settled): void { $settled = true; });
    return !$settled;
}
function coordinatorFixture(bool $enabled = true, int $maxPasses = 3): array {
    $f = (object)['now'=>1800000000,'calls'=>['refresh'=>0,'members'=>0,'project'=>0,'write'=>0],
        'refreshHook'=>null,'membersHook'=>null,'projectHook'=>null,'writeHook'=>null,'bytes'=>[],'guards'=>[]];
    $f->snapshot = ['status'=>['available'=>true,'stale'=>false,'has_valid_list'=>true,'refresh_in_progress'=>false,
        'records'=>1,'age_seconds'=>0,'last_success_utc'=>gmdate('Y-m-d\TH:i:s\Z',$f->now)],
        'records'=>[['discord'=>'990000000000000001','ss13'=>'fakealpha']]];
    $f->membership = ['guild_id'=>'770000000000000001','complete'=>true,'connected'=>true,
        'roles'=>['880000000000000001'],'members'=>[['id'=>'990000000000000001','roles'=>['880000000000000001']]]];
    $c = new AdminSyncCoordinator($enabled,
        static function () use ($f): mixed {
            ++$f->calls['refresh'];
            return $f->refreshHook !== null ? ($f->refreshHook)() : $f->snapshot;
        },
        static function (int $generation) use ($f): mixed {
            ++$f->calls['members'];
            return $f->membersHook !== null ? ($f->membersHook)($generation) : $f->membership + ['generation'=>$generation];
        },
        static function (array $snapshot, array $membership, int $generation) use ($f): mixed {
            ++$f->calls['project'];
            if ($f->projectHook !== null) { return ($f->projectHook)($snapshot,$membership,$generation); }
            return AdminSyncSnapshotGate::render($snapshot['status'],$snapshot['records'],$membership,
                '770000000000000001',$generation,$f->now,['FixtureAdmin'=>'880000000000000001'],['FixtureAdmin'=>['FixtureAdmin','3']]);
        },
        static function (string $bytes, int $generation, Closure $current) use ($f): mixed {
            ++$f->calls['write']; $f->guards[] = $current;
            if ($f->writeHook !== null) { return ($f->writeHook)($bytes,$generation,$current); }
            coordinatorCheck($current(), 'Default fake writer received stale work');
            $f->bytes[] = [$generation,$bytes];
            return ['state'=>'updated'];
        }, $maxPasses);
    return [$c,$f];
}

[$c,$f] = coordinatorFixture(false);
$r = coordinatorOutcome($c->request());
coordinatorCheck($r['state']==='disabled' && $r['passes']===0 && $r['writes']===0 && array_sum($f->calls)===0,'Disabled gate had effects or claimed success');
[$c,$f] = coordinatorFixture();
$r = coordinatorOutcome($c->request());
coordinatorCheck($r['state']==='updated' && $r['writes']===1 && $f->bytes===[[1,"fakealpha;FixtureAdmin;3|||\n"]],'Successful projection/writer outcome lost');
coordinatorCheck(!($f->guards[0])(),'Finished generation remained writable');
[$c,$f] = coordinatorFixture(); $f->writeHook = static fn()=>['state'=>'unchanged'];
$r = coordinatorOutcome($c->request());
coordinatorCheck($r['state']==='unchanged' && $r['writes']===0 && $f->calls['write']===1,'Unchanged confused with update');
[$c,$f] = coordinatorFixture(); $f->snapshot['records']=[]; $f->snapshot['status']['records']=0;
coordinatorCheck(coordinatorOutcome($c->request())['state']==='updated' && $f->bytes===[[1,'']],'Valid empty lost authoritative write');
foreach (['throw','reject','unavailable','stale','missing'] as $mode) {
    [$c,$f] = coordinatorFixture();
    $f->refreshHook = static function () use ($f,$mode): mixed {
        if ($mode==='throw') { throw new RuntimeException('synthetic'); }
        if ($mode==='reject') { return reject(new RuntimeException('synthetic')); }
        if ($mode==='missing') { return []; }
        $snapshot=$f->snapshot; $snapshot['records']=[]; $snapshot['status']['records']=0;
        $snapshot['status'][$mode==='stale'?'stale':'available']=$mode==='stale';
        return $snapshot;
    };
    $r=coordinatorOutcome($c->request());
    coordinatorCheck($r['state']==='blocked' && $r['phase']==='refresh' && $f->calls['members']===0 && $f->calls['write']===0,'Bad refresh reached members/writer');
    $f->refreshHook=null;
    coordinatorCheck(coordinatorOutcome($c->request())['state']==='updated','Refresh failure left busy state stuck');
}
foreach (['throw','reject','incomplete','wrong-generation'] as $mode) {
    [$c,$f] = coordinatorFixture();
    $f->membersHook = static function (int $generation) use ($f,$mode): mixed {
        if ($mode==='throw') { throw new RuntimeException('synthetic'); }
        if ($mode==='reject') { return reject(new RuntimeException('synthetic')); }
        return array_replace($f->membership,['generation'=>$mode==='wrong-generation'?$generation+1:$generation,'complete'=>$mode!=='incomplete']);
    };
    coordinatorCheck(coordinatorOutcome($c->request())['state']==='blocked' && $f->calls['write']===0,'Invalid members reached writer');
}
// Stale completions never reach a later stage. Three requests coalesce to two refreshes.
[$c,$f] = coordinatorFixture(); $refresh = new Deferred();
$f->refreshHook = static fn()=>$f->calls['refresh']===1?$refresh->promise():$f->snapshot;
$p=$c->request(); coordinatorCheck(coordinatorPending($p),'Refresh unexpectedly settled');
coordinatorCheck($c->request()===$p && $c->request()===$p,'Burst did not share pending promise');
$refresh->resolve($f->snapshot); $r=coordinatorOutcome($p);
coordinatorCheck($r['generation']===3 && $r['passes']===2 && $f->calls['refresh']===2 && $f->calls['members']===1 && $f->bytes[0][0]===3,'Stale refresh was used or dirty triggers lost');
[$c,$f] = coordinatorFixture(); $members=new Deferred();
$f->membersHook=static fn(int $g)=>$f->calls['members']===1?$members->promise():$f->membership+['generation'=>$g];
$p=$c->request(); $c->request(); $members->resolve($f->membership+['generation'=>1]);
coordinatorCheck(coordinatorOutcome($p)['state']==='updated' && $f->calls['project']===2 && $f->bytes[0][0]===2,'Stale membership reached projection');
// Recheck freshness after asynchronous membership finishes.
[$c,$f] = coordinatorFixture(); $members=new Deferred(); $f->membersHook=static fn()=>$members->promise();
$p=$c->request(); $f->now+=1800; $members->resolve($f->membership+['generation'=>1]);
coordinatorCheck(coordinatorOutcome($p)['state']==='blocked' && $f->calls['write']===0,'Expired verification reached writer');
// A synchronous event from the projector invalidates before writer entry.
[$c,$f] = coordinatorFixture();
$f->projectHook=static function () use ($c,$f): string { if ($f->calls['project']===1) { $c->request(); } return 'synthetic'; };
coordinatorCheck(coordinatorOutcome($c->request())['writes']===1 && $f->bytes[0][0]===2,'Projection-time event allowed stale writer');
// Do not overlap writers; delayed writer can observe invalidation before committing.
[$c,$f] = coordinatorFixture(); $write=new Deferred();
$f->writeHook=static fn()=> $f->calls['write']===1?$write->promise():['state'=>'updated'];
$p=$c->request(); $c->request();
coordinatorCheck($f->calls['refresh']===1 && !($f->guards[0])() && coordinatorPending($p),'Overlapped work or stale guard');
$write->resolve(['state'=>'superseded']); $r=coordinatorOutcome($p);
coordinatorCheck($r['state']==='updated' && $r['passes']===2 && $r['writes']===1,'Dirty event during write was lost');
// A delayed writer must also recheck freshness, not only generation.
[$c,$f] = coordinatorFixture(); $write=new Deferred(); $f->writeHook=static fn()=>$write->promise();
$p=$c->request(); $f->now+=1800;
coordinatorCheck(!($f->guards[0])(), 'Expired snapshot still allowed commit');
$write->resolve(['state'=>'superseded']); $r=coordinatorOutcome($p);
coordinatorCheck($r['state']==='superseded' && $r['retry_required'] && $r['writes']===0,'Expired pending writer claimed success');
// Already-committed old generation must be reported, not pretended rolled back.
[$c,$f] = coordinatorFixture(); $write=new Deferred();
$f->writeHook=static fn()=> $f->calls['write']===1?$write->promise():['state'=>'updated'];
$p=$c->request(); $c->request(); $write->resolve(['state'=>'updated']); $r=coordinatorOutcome($p);
coordinatorCheck($r['writes']===2 && $r['last_written_generation']===2,'Committed older write was hidden');
// A later blocked pass must expose an earlier committed update in the same burst.
[$c,$f] = coordinatorFixture(); $write=new Deferred(); $f->writeHook=static fn()=>$write->promise();
$f->refreshHook=static function () use ($f): array {
    $snapshot=$f->snapshot;
    if ($f->calls['refresh']>1) { $snapshot['status']['available']=false; }
    return $snapshot;
};
$p=$c->request(); $c->request(); $write->resolve(['state'=>'updated']); $r=coordinatorOutcome($p);
coordinatorCheck($r['state']==='blocked' && $r['writes']===1 && $r['last_written_generation']===1 && $r['generation']===2,'Earlier committed write hidden by later block');
// Precommit gate refuses changed bytes and exceptions, not only expiry.
foreach (['changed','throw'] as $mode) {
    [$c,$f] = coordinatorFixture(); $write=new Deferred(); $f->writeHook=static fn()=>$write->promise();
    $p=$c->request();
    $f->projectHook=static function () use ($mode): string {
        if ($mode==='throw') { throw new RuntimeException('synthetic precommit gate failure'); }
        return 'changed';
    };
    coordinatorCheck(!($f->guards[0])(),'Changed/failed projection allowed commit');
    $write->resolve(['state'=>'superseded']);
    coordinatorCheck(coordinatorOutcome($p)['writes']===0,'Precommit rejection reported a write');
}
[$c,$f] = coordinatorFixture(); $write=new Deferred();
$f->writeHook=static fn()=>$f->calls['write']===1?$write->promise():['state'=>'updated'];
$p=$c->request();
$f->projectHook=static function () use ($c,$f): string {
    $f->projectHook=null; $c->request(); return "fakealpha;FixtureAdmin;3|||\n";
};
coordinatorCheck(!($f->guards[0])(),'Precommit-time invalidation allowed commit');
$write->resolve(['state'=>'superseded']);
coordinatorCheck(coordinatorOutcome($p)['last_written_generation']===2,'Precommit-time invalidation lost dirty pass');
foreach (['throw','reject','invalid'] as $mode) {
    [$c,$f] = coordinatorFixture();
    $f->writeHook=static function () use ($mode): mixed {
        if ($mode==='throw') { throw new RuntimeException('synthetic partial write'); }
        return $mode==='reject'?reject(new RuntimeException('synthetic partial write')):null;
    };
    $r=coordinatorOutcome($c->request());
    coordinatorCheck($r['state']==='failed' && $r['integrity_uncertain'] && $r['writes']===0 && $r['passes']===1,'Writer failure misreported/retried');
}
[$c,$f] = coordinatorFixture(); $write=new Deferred(); $f->writeHook=static fn()=>$write->promise();
$p=$c->request(); $c->request(); $write->reject(new RuntimeException('synthetic partial write')); $r=coordinatorOutcome($p);
coordinatorCheck($r['state']==='failed' && $r['retry_required'] && $r['integrity_uncertain'] && $f->calls['refresh']===1,'Dirty writer failure was automatically retried');
// Continuous churn is bounded and explicitly asks the owner for another reconciliation.
[$c,$f] = coordinatorFixture(true,2);
$f->refreshHook=static function () use ($c,$f): array { $c->request(); return $f->snapshot; };
$r=coordinatorOutcome($c->request());
coordinatorCheck($r['state']==='superseded' && $r['retry_required'] && $r['passes']===2 && $f->calls['write']===0,'Churn was unbounded or reported success');
$f->refreshHook=null;
coordinatorCheck(coordinatorOutcome($c->request())['state']==='updated','Budget exhaustion kept busy state');
// Terminal subscribers may synchronously start a new independent request.
[$c,$f] = coordinatorFixture(); $refresh=new Deferred(); $f->refreshHook=static fn()=>$f->calls['refresh']===1?$refresh->promise():$f->snapshot;
$p=$c->request(); $next=null;
$p->then(static function () use ($c,&$next): void { $next=$c->request(); });
$refresh->resolve($f->snapshot);
coordinatorCheck($next instanceof PromiseInterface && coordinatorOutcome($next)['generation']===2 && $f->calls['write']===2,'Terminal reentrancy lost new request');
echo "PASS injected coordinator: disabled, valid empty, failure, stale suppression, shared promise, bounded dirty passes, writer outcomes and reentrancy\n";
