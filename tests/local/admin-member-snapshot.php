<?php
declare(strict_types=1);
// Inert callbacks and React promises only. No bootstrap, environment, network or files.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../src/Civ13/AdminMemberSnapshotProvider.php';

use Civ13\AdminMemberSnapshotProvider;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;

function memberCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function memberOutcome(PromiseInterface $promise): array {
    $outcome=['state'=>'pending'];
    $promise->then(static function (array $result) use (&$outcome): void { $outcome=['state'=>'resolved','result'=>$result]; },
        static function (Throwable $error) use (&$outcome): void { $outcome=['state'=>'rejected']; });
    return $outcome;
}
function memberFixture(int $maxPages=5, int $maxMembers=100, float $timeout=10): array {
    $f=(object)['now'=>0.0,'roles'=>['880000000000000001'],'pages'=>[],
        'life'=>['guild_id'=>'770000000000000001','connected'=>true,'epoch'=>1,'generation'=>7],
        'roleHook'=>null,'pageHook'=>null,'deadlineHook'=>null,'observeHook'=>null,
        'roleCalls'=>0,'pageCalls'=>[],'timerCalls'=>0,'cancelCalls'=>0,'expire'=>null];
    $p=new AdminMemberSnapshotProvider(
        static function (string $guild) use ($f): mixed {
            memberCheck($guild==='770000000000000001','Wrong roles guild'); ++$f->roleCalls;
            return $f->roleHook!==null?($f->roleHook)():$f->roles;
        },
        static function (string $guild,string $after,int $limit) use ($f): mixed {
            memberCheck($guild==='770000000000000001' && $limit===1000,'Wrong member endpoint arguments');
            $f->pageCalls[]=$after;
            return $f->pageHook!==null?($f->pageHook)($after):array_shift($f->pages);
        },
        static fn(string $guild)=>$f->observeHook!==null?($f->observeHook)($guild):$f->life,
        static fn()=>$f->now,
        static function (float $delay,callable $expire) use ($f): mixed {
            memberCheck($delay>0,'Invalid deadline duration'); ++$f->timerCalls; $f->expire=$expire;
            if ($f->deadlineHook!==null) { return ($f->deadlineHook)($expire); }
            return static function () use ($f): void { ++$f->cancelCalls; };
        }, $maxPages,$maxMembers,$timeout);
    return [$p,$f];
}
function memberRow(string $id): array { return ['id'=>$id,'roles'=>['880000000000000001']]; }
$guild='770000000000000001';
[$p,$f]=memberFixture(); $f->pages=[[memberRow('990000000000000001')],[]];
$o=memberOutcome($p->snapshot($guild,7));
memberCheck($o['state']==='resolved' && $o['result']['complete']===true && count($o['result']['members'])===1
    && $o['result']['pages']===2 && $f->pageCalls===['0','990000000000000001'] && $f->roleCalls===2 && $f->cancelCalls===1,'Short nonempty page did not require terminal empty');
[$p,$f]=memberFixture(1,0); $f->pages=[[]]; $o=memberOutcome($p->snapshot($guild,7));
memberCheck($o['state']==='resolved' && $o['result']['members']===[] && $o['result']['pages']===1,'Valid empty first page rejected');
// Decimal-string cursor uses greatest ID even in an unsorted page, without integer casts.
[$p,$f]=memberFixture(); $f->pages=[[memberRow('99999999999999999999'),memberRow('99999999999999999')],[]];
$o=memberOutcome($p->snapshot($guild,7));
memberCheck($o['state']==='resolved' && $f->pageCalls===['0','99999999999999999999'] && $o['result']['members'][0]['id']==='99999999999999999','Snowflake overflow/order error');
foreach ([null,false,['error'=>'denied'],[null],[['id'=>'00000000000000001','roles'=>[]]],
    [['id'=>990000000000000001,'roles'=>[]]],[['id'=>'990000000000000001','roles'=>['880000000000000002']]],
    [['id'=>'990000000000000001','roles'=>['880000000000000001','880000000000000001']]],
    [memberRow('990000000000000001'),memberRow('990000000000000001')]] as $bad) {
    [$p,$f]=memberFixture(); $f->pages=[$bad,[]];
    memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && count($f->pageCalls)===1 && $f->roleCalls===1,'Invalid page gave partial authority or continued');
}
[$p,$f]=memberFixture(); $f->pages=[[memberRow('990000000000000002')],[memberRow('990000000000000001')],[]];
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && count($f->pageCalls)===2,'Backward cursor accepted');
[$p,$f]=memberFixture(); $f->pages=[[memberRow('990000000000000001')],[memberRow('990000000000000001')],[]];
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && count($f->pageCalls)===2,'Repeated cursor accepted');
[$p,$f]=memberFixture(1); $f->pages=[[memberRow('990000000000000001')],[]];
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && count($f->pageCalls)===1,'Page ceiling treated as completion');
[$p,$f]=memberFixture(3,0); $f->pages=[[memberRow('990000000000000001')],[]];
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected','Member ceiling treated as completion');
[$p,$f]=memberFixture(); $f->pages=[array_fill(0,1001,memberRow('990000000000000001'))];
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected','Oversized page accepted');
foreach ([null,['bad'],['880000000000000001','880000000000000001']] as $roles) {
    [$p,$f]=memberFixture(); $f->roles=$roles; $f->pages=[[]];
    memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && $f->pageCalls===[],'Invalid initial role snapshot reached pagination');
}
[$p,$f]=memberFixture(); $f->pages=[[]];
$f->roleHook=static fn()=>$f->roleCalls===1?$f->roles:['880000000000000002'];
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected','Changed final role set accepted');
// Synchronous throws and promise rejections never manufacture empty snapshots.
foreach (['roles','page'] as $stage) {
    foreach (['throw','reject'] as $mode) {
        [$p,$f]=memberFixture(); $f->pages=[[]];
        $hook=static function () use ($mode): mixed { if ($mode==='throw') { throw new RuntimeException('synthetic'); } return reject(new RuntimeException('synthetic')); };
        if ($stage==='roles') { $f->roleHook=$hook; } else { $f->pageHook=$hook; }
        memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected','Adapter failure accepted');
        $f->roleHook=null; $f->pageHook=null; $f->pages=[[]];
        memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='resolved','Settled failure left provider busy');
    }
}
foreach ([['connected'=>false],['epoch'=>2],['generation'=>8],['guild_id'=>'770000000000000002']] as $change) {
    [$p,$f]=memberFixture(); $wait=new Deferred(); $f->pageHook=static fn()=>$wait->promise();
    $promise=$p->snapshot($guild,7); $f->life=array_replace($f->life,$change); $wait->resolve([]);
    memberCheck(memberOutcome($promise)['state']==='rejected' && $f->roleCalls===1,'Changed lifecycle gave authority');
}
[$p,$f]=memberFixture(); $f->life['connected']=false;
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && $f->roleCalls===0 && $f->timerCalls===0,'Initially disconnected provider made requests');
// Deadline rejects without cancelling or overlapping the outstanding fake transport.
[$p,$f]=memberFixture(); $wait=new Deferred(); $f->pageHook=static fn()=>$wait->promise();
$promise=$p->snapshot($guild,7);
memberCheck(memberOutcome($promise)['state']==='pending','Deferred page settled early');
$f->now=10; ($f->expire)();
memberCheck(memberOutcome($promise)['state']==='rejected' && memberOutcome($wait->promise())['state']==='pending','Timeout pretended to cancel underlying request');
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && $f->roleCalls===1,'Timeout allowed overlap while transport pending');
$wait->resolve([memberRow('990000000000000001')]);
memberCheck(count($f->pageCalls)===1 && $f->roleCalls===1,'Late response issued more requests');
$f->pageHook=null; $f->pages=[[]];
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='resolved','Drained timeout did not release busy gate');
// Clock checks enforce the deadline even if a delayed timer has not fired.
foreach ([10.0,-1.0,INF,NAN] as $time) {
    [$p,$f]=memberFixture(); $wait=new Deferred(); $f->pageHook=static fn()=>$wait->promise();
    $promise=$p->snapshot($guild,7); $f->now=$time; $wait->resolve([]);
    memberCheck(memberOutcome($promise)['state']==='rejected','Expired/nonmonotonic clock accepted');
}
// Deadline registration must succeed; a synchronous expiry must cause zero fetches.
[$p,$f]=memberFixture(); $f->deadlineHook=static fn()=>null;
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && $f->roleCalls===0,'Missing deadline registration accepted');
[$p,$f]=memberFixture();
$f->deadlineHook=static function (callable $expire) use ($f): callable { $expire(); return static function () use ($f): void { ++$f->cancelCalls; }; };
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && $f->roleCalls===0 && $f->cancelCalls===1,'Synchronous deadline expiry fetched data');
// Reentrant expiry inside an injected observer cannot start more transport work.
[$p,$f]=memberFixture();
$f->observeHook=static function () use ($f): array {
    if ($f->timerCalls===1) { $f->observeHook=null; ($f->expire)(); }
    return $f->life;
};
memberCheck(memberOutcome($p->snapshot($guild,7))['state']==='rejected' && $f->roleCalls===0,'Observer expiry still started roles request');
[$p,$f]=memberFixture(); $wait=new Deferred(); $f->pageHook=static fn()=>$wait->promise();
$promise=$p->snapshot($guild,7);
$f->observeHook=static function () use ($f): array { $f->observeHook=null; ($f->expire)(); return $f->life; };
$wait->resolve([]);
memberCheck(memberOutcome($promise)['state']==='rejected' && $f->roleCalls===1,'Observer expiry still accepted terminal page');
// Resolved subscriber can begin another request after busy is released.
[$p,$f]=memberFixture(); $wait=new Deferred();
$f->pageHook=static fn()=>$wait->promise(); $promise=$p->snapshot($guild,7); $next=null;
$promise->then(static function () use ($p,$f,$guild,&$next): void { $f->pageHook=null; $f->pages=[[]]; $next=$p->snapshot($guild,7); });
$wait->resolve([]);
memberCheck($next instanceof PromiseInterface && memberOutcome($next)['state']==='resolved','Resolved reentrancy kept provider busy');
echo "PASS injected member provider: terminal pagination, bounded limits, string cursors, invalidation, deadline/drain and no partial authority\n";
