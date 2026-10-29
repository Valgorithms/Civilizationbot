<?php
declare(strict_types=1);

// Exercise real Verifier::refreshHosted and HostedVerificationSnapshot with a fake source.
// This process never loads the production HTTP source, constructs a client, or bootstraps a bot.
namespace Civ13 {
    final class HostedVerificationSource
    {
        public static \Closure $fetch;
        public static function fetch(mixed $unused): \React\Promise\PromiseInterface { return (self::$fetch)(); }
        public static function validate(string $json): array { return json_decode($json,true,32,JSON_THROW_ON_ERROR); }
    }
}
namespace {
    require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
    require_once __DIR__.'/../../src/Civ13/HostedVerificationSnapshot.php';
    require_once __DIR__.'/../../src/Civ13/Verifier.php';

    use Civ13\Civ13;
    use Civ13\HostedVerificationSnapshot;
    use Civ13\HostedVerificationSource;
    use Civ13\Verifier;
    use Discord\Helpers\Collection;
    use React\Promise\Deferred;
    use React\Promise\PromiseInterface;
    use function React\Promise\resolve;

    $checks=0;
    function refreshCheck(bool $ok,string $message): void {
        global $checks; ++$checks;
        if (!$ok) { throw new \RuntimeException($message); }
    }
    function refreshResult(PromiseInterface $promise): array {
        $result=null;
        $promise->then(static function (array $status) use (&$result): void { $result=$status; });
        if ($result===null) { throw new \RuntimeException('Expected completed fake refresh'); }
        return $result;
    }
    final class RefreshObserver extends Civ13
    {
        public array $notifications=[];
        public ?\Closure $notificationHook=null;
        public function hostedVerificationRefreshed(Verifier $verifier): void {
            $this->notifications[]=['verifier'=>$verifier,'records'=>iterator_to_array($verifier->verified,false),
                'available'=>$this->verifier_online];
            if ($this->notificationHook!==null) { ($this->notificationHook)(); }
        }
    }
    final class RefreshLogger extends \Monolog\Logger
    {
        public array $infos=[];
        public array $warnings=[];
        public bool $throwWarning=false;
        public function info(string|\Stringable $message,array $context=[]): void {
            $this->infos[]=['message'=>(string)$message,'context'=>$context];
        }
        public function warning(string|\Stringable $message,array $context=[]): void {
            $this->warnings[]=['message'=>(string)$message,'context'=>$context];
            if ($this->throwWarning) { throw new \RuntimeException('Synthetic optional warning failure'); }
        }
    }

    $f=(object)['now'=>1800000000,'calls'=>0,'reply'=>['http_status'=>200,'json'=>'[]'],'pending'=>null];
    HostedVerificationSource::$fetch=static function () use ($f): PromiseInterface {
        ++$f->calls;
        return $f->pending?->promise() ?? resolve($f->reply);
    };
    $owner=(new \ReflectionClass(RefreshObserver::class))->newInstanceWithoutConstructor();
    $owner->browser=(new \ReflectionClass(\React\Http\Browser::class))->newInstanceWithoutConstructor();
    $verifier=(new \ReflectionClass(Verifier::class))->newInstanceWithoutConstructor();
    $verifier->civ13=$owner; $owner->verifier=$verifier;
    $verifier->logger=$logger=new RefreshLogger('offline-verifier');
    $verifier->verified=new Collection([], 'discord');
    $snapshot=new HostedVerificationSnapshot(static fn()=>$f->now);
    (new \ReflectionProperty(Verifier::class,'hosted'))->setValue($verifier,$snapshot);
    $records=[['discord'=>'990000000000000001','ss13'=>'fakealpha']];
    $f->reply=['http_status'=>200,'json'=>json_encode($records,JSON_THROW_ON_ERROR)];
    refreshResult($verifier->refreshHosted());
    refreshCheck($f->calls===1 && count($owner->notifications)===1,'Initial successful recovery did not notify once');
    refreshCheck($owner->notifications[0]['records']===$records && $owner->notifications[0]['available']===true,
        'Notification preceded successful verification-cache replacement');

    refreshResult($verifier->refreshHosted());
    refreshCheck($f->calls===1 && count($owner->notifications)===1,'Coalesced refresh fetched or notified again');
    $f->now+=20; refreshResult($verifier->refreshHosted());
    refreshCheck($f->calls===2 && count($owner->notifications)===2,'Unchanged successful replacement lost its new snapshot timestamp notification');

    $f->now+=20; $records[0]['ss13']='fakebeta'; $f->reply['json']=json_encode($records,JSON_THROW_ON_ERROR);
    refreshResult($verifier->refreshHosted());
    refreshCheck($f->calls===3 && count($owner->notifications)===3
        && end($owner->notifications)['records']===$records,'Changed identity cache did not notify once');

    $f->now+=20; $f->reply=['http_status'=>503,'json'=>'[]'];
    $status=refreshResult($verifier->refreshHosted());
    refreshCheck($status['available']===false && count($owner->notifications)===3
        && iterator_to_array($verifier->verified,false)===$records,'Failed refresh notified or discarded retained identities');
    $f->now+=20; $f->reply=['http_status'=>200,'json'=>json_encode($records,JSON_THROW_ON_ERROR)];
    refreshResult($verifier->refreshHosted());
    refreshCheck($f->calls===5 && count($owner->notifications)===4,'Recovery with unchanged records did not notify');

    $f->now+=20; $f->pending=new Deferred();
    $first=$verifier->refreshHosted(); $second=$verifier->refreshHosted();
    refreshCheck($f->calls===6 && count($owner->notifications)===4,'Pending refresh notified prematurely or overlapped fetches');
    $f->pending->resolve(['http_status'=>200,'json'=>'[]']); $f->pending=null;
    refreshResult($first); $secondStatus=refreshResult($second);
    refreshCheck($secondStatus['coalesced']===true && count($owner->notifications)===5
        && end($owner->notifications)['records']===[],'Overlapping completion duplicated notification or lost valid empty list');

    $f->now+=20; $f->pending=new Deferred(); $failed=$verifier->refreshHosted();
    $f->pending->reject(new \RuntimeException('Synthetic source failure')); $f->pending=null;
    refreshCheck(refreshResult($failed)['available']===false && count($owner->notifications)===5,
        'Rejected source notified synchronization');

    // Optional notification failure, including its sanitized warning logger, cannot reject core success.
    $owner->notificationHook=static function (): never { throw new \RuntimeException('Synthetic private identity/path detail'); };
    foreach ([false,true] as $throwWarning) {
        $logger->throwWarning=$throwWarning; $f->now+=20;
        $f->reply=['http_status'=>200,'json'=>json_encode($records,JSON_THROW_ON_ERROR)];
        $before=[$f->calls,count($owner->notifications),count($logger->infos),count($logger->warnings)];
        $status=refreshResult($verifier->refreshHosted());
        refreshCheck($status['available']===true && $owner->verifier_online===true
            && iterator_to_array($verifier->verified,false)===$records && $snapshot->status()['available']===true,
            'Optional synchronization failure invalidated successful verifier cache/status');
        refreshCheck($f->calls===$before[0]+1 && count($owner->notifications)===$before[1]+1,
            'Optional notification failure retried the source or notification');
        refreshCheck(count($logger->infos)===$before[2]+1
            && end($logger->infos)===['message'=>'Hosted verification refresh','context'=>$status],
            'Optional notification/warning failure suppressed normal core logging');
        refreshCheck(count($logger->warnings)===$before[3]+1
            && end($logger->warnings)===['message'=>'Admin file synchronization notification failed','context'=>[]],
            'Optional failure warning exposed exception details or lacked sanitized feature signal');
    }
    // Actual source failures remain unavailable; the optional catch is not around the source path.
    $logger->throwWarning=false; $f->now+=20; $f->reply=['http_status'=>503,'json'=>'[]'];
    $before=[$f->calls,count($owner->notifications),count($logger->warnings),count($logger->infos)];
    $status=refreshResult($verifier->refreshHosted());
    refreshCheck($status['available']===false && $owner->verifier_online===false
        && iterator_to_array($verifier->verified,false)===$records,'Optional failure isolation masked a later source failure');
    refreshCheck($f->calls===$before[0]+1 && count($owner->notifications)===$before[1]
        && count($logger->warnings)===$before[2] && count($logger->infos)===$before[3]+1,
        'Failed source invoked optional synchronization, retried, or lost its ordinary status log');
    $owner->notificationHook=null;

    // Real owner bridge is inert when default-disabled/unattached and does not autoload the feature.
    $plain=(new \ReflectionClass(Civ13::class))->newInstanceWithoutConstructor();
    $plain->verifier=$verifier; $autoloads=0;
    $trap=static function (string $class) use (&$autoloads): void {
        if ($class==='Civ13\\AdminFileSync') { ++$autoloads; throw new \RuntimeException('Disabled feature autoload'); }
    };
    spl_autoload_register($trap,true,true);
    try { $plain->hostedVerificationRefreshed($verifier); }
    finally { spl_autoload_unregister($trap); }
    refreshCheck($autoloads===0 && !class_exists('Civ13\\AdminFileSync',false),'Disabled completion loaded optional implementation');

    echo "PASS verifier completion hook ({$checks} assertions): actual replacement, changed/recovered success, failures, coalescing and disabled bridge\n";
}
