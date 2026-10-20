<?php
declare(strict_types=1);
// Offline: actual source/parser/state/write barriers, injected asynchronous HTTP only.
$loader = require ($argv[1] ?? __DIR__.'/../../vendor/autoload.php');
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
function check(bool $ok): void { if (!$ok) { throw new RuntimeException('Hosted verification contract failed'); } }
function rejects(callable $call): void { try { $call(); } catch (Throwable) { return; } throw new RuntimeException('Expected unavailable/rejection'); }
final class HostedBrowser extends React\Http\Browser {
    public int $calls = 0; public int $status = 200; public bool $timeout = false;
    public string $body = '[{"ss13":"fixtureplayer","discord":"123456789012345678","ignored":"not retained"}]';
    public ?React\Promise\Deferred $pending = null; public array $settings = [];
    public function __construct() {}
    public function withTimeout($value) { $this->settings['timeout']=$value; return $this; }
    public function withFollowRedirects($value) { $this->settings['redirects']=$value; return $this; }
    public function withRejectErrorResponse($value) { $this->settings['reject_errors']=$value; return $this; }
    public function withResponseBuffer($value) { $this->settings['buffer']=$value; return $this; }
    public function get($url, array $headers = []) {
        ++$this->calls; check($url===Civ13\HostedVerificationSource::URL && $headers===['Accept'=>'application/json']);
        if($this->pending) { return $this->pending->promise(); }
        return $this->timeout ? React\Promise\reject(new RuntimeException('Synthetic timeout'))
            : React\Promise\resolve(new React\Http\Message\Response($this->status, [], $this->body));
    }
}
final class HostedDiscord extends Discord\Discord {
    public function __construct(private React\EventLoop\LoopInterface $testLoop) {}
    public function getLoop(): React\EventLoop\LoopInterface { return $this->testLoop; }
}
$bot=(new ReflectionClass(Civ13\Civ13::class))->newInstanceWithoutConstructor();
$bot->loop=new React\EventLoop\StreamSelectLoop(); $bot->discord=new HostedDiscord($bot->loop);
$bot->logger=new Monolog\Logger('offline-hosted',[new Monolog\Handler\NullHandler()]);
$bot->browser=$browser=new HostedBrowser(); $bot->ready=true;
$verifier=new Civ13\Verifier($bot,['verify_url'=>Civ13\HostedVerificationSource::URL,'verification_read_only'=>true]);
$now=1000; $snapshot=new Civ13\HostedVerificationSnapshot(static function() use (&$now): int { return $now; });
(new ReflectionProperty(Civ13\Verifier::class,'hosted'))->setValue($verifier,$snapshot);
$verifier->refreshHosted();
check($verifier->ready && $bot->verifier_online && count($verifier->getVerified())===1);
check($verifier->get('ss13','fixtureplayer')===['ss13'=>'fixtureplayer','discord'=>'123456789012345678']);
foreach(['GUILD_MEMBER_ADD','GUILD_MEMBER_REMOVE','GUILD_MEMBER_UPDATE','THREAD_CREATE'] as $event) { check(count($bot->discord->listeners($event))===0); }
check(count($verifier->provisional)===0 && count($verifier->pending)===0);
$calls=$browser->calls;
foreach(['new'=>['123456789012345678'],'register'=>['x','123456789012345678'],'verify'=>['x','123456789012345678'],'unverify'=>['123456789012345678']] as $method=>$args) { check($verifier->$method(...$args)['success']===false); }
check(str_contains($verifier->process('x','123456789012345678'),'primary bot'));
check(!$verifier->provisionalRegistration('x','123456789012345678') && !$verifier->checkToken('123456789012345678'));
rejects(fn()=>$verifier->generateToken('x','123456789012345678'));
$denied=false;$verifier->provision('x','123456789012345678')->then(null,static function() use (&$denied):void{$denied=true;});check($denied);
check((new ReflectionMethod(Civ13\Verifier::class,'__verifyRequest'))->invoke($verifier,['method'=>'DELETE'])['http_status']===503);
check($browser->calls===$calls && count($verifier->verified)===1 && count($verifier->provisional)===0);
check($browser->settings===['timeout'=>10,'redirects'=>false,'reject_errors'=>false,'buffer'=>4194304]);
foreach([301,401,403,404,500] as $status) {
    $now+=11;$browser->status=$status;$before=$browser->calls;$verifier->refreshHosted();
    check($browser->calls===$before+1 && !$bot->verifier_online && count($verifier->verified)===1);
    check($verifier->hostedStatus()['stale'] && $verifier->hostedStatus()['last_success_utc']!==null);
    rejects(fn()=>$verifier->getVerified());rejects(fn()=>$verifier->isVerified('fixtureplayer'));
    rejects(fn()=>$verifier->getVerifiedMemberItems());rejects(fn()=>$verifier->getIterator());
}
$now+=11;$browser->status=200;$browser->timeout=true;$before=$browser->calls;$verifier->refreshHosted();
check($browser->calls===$before+2 && !$bot->verifier_online);$browser->timeout=false;
foreach(['null','{"error":"unknown"}','invalid','[{}]','[{"ss13":"x","discord":123}]','[{"ss13":"x\\ny","discord":"123456789012345678"}]',
    '[{"ss13":"x","discord":"123456789012345678"},{"ss13":"x","discord":"234567890123456789"}]',str_repeat('x',4194305)] as $bad) {
    $now+=11;$browser->body=$bad;$verifier->refreshHosted();check(!$bot->verifier_online && count($verifier->verified)===1);
}
check(Civ13\HostedVerificationSource::validate('{"123456789012345678":{"ss13":"Fixture_Player","discord":"123456789012345678"}}')===[['ss13'=>'fixtureplayer','discord'=>'123456789012345678']]);
rejects(static fn()=>Civ13\HostedVerificationSource::validate('[{"ss13":"Fixture_Player","discord":"123456789012345678"},{"ss13":"fixtureplayer","discord":"234567890123456789"}]'));
$now+=11;$browser->body='[]';$verifier->refreshHosted();check($bot->verifier_online && count($verifier->verified)===0);
$now+=11;$browser->body='[{"ss13":"newplayer","discord":"234567890123456789"}]';$verifier->refreshHosted();
check($verifier->get('ss13','fixtureplayer')===null && $verifier->isVerified('newplayer'));
$now+=11;$browser->pending=$deferred=new React\Promise\Deferred();$before=$browser->calls;$completed=false;
$verifier->refreshHosted()->then(static function() use (&$completed):void{$completed=true;});
$shared=null;$verifier->refreshHosted()->then(static function(array $status) use (&$shared):void{$shared=$status;});
check(!$completed && $verifier->hostedStatus()['refresh_in_progress'] && $browser->calls===$before+1);
$unrelatedTimer=false;
$bot->loop->addTimer(0,static function() use (&$unrelatedTimer,$verifier,$deferred,$bot):void{
    check($verifier->hostedStatus()['refresh_in_progress']);$unrelatedTimer=true;
    $deferred->resolve(new React\Http\Message\Response(200,[],'[]'));$bot->loop->stop();
});$bot->loop->run();
check($unrelatedTimer && $completed && $shared['coalesced'] && $browser->calls===$before+1);
$browser->pending=null;$now+=1801;check(!$verifier->hostedStatus()['available']);rejects(fn()=>$verifier->getVerifiedMemberItems());
$bot->enabled_gameservers=['synthetic'=>(object)[]];check($bot->bancheckTimer()===false);
$empty=new Civ13\HostedVerificationSnapshot();$state=null;$empty->refresh(static fn()=>['http_status'=>0])->then(static function($value) use (&$state):void{$state=$value;});check(!$state['has_valid_list']);
$profile=['options'=>['role_ids'=>[],'channel_ids'=>[]],'gitdir'=>__DIR__,'state_dir'=>__DIR__,'verification_source'=>['url'=>Civ13\HostedVerificationSource::URL,'mode'=>'read-only']];
check(Civ13\PortableProfile::runtimeOptions($profile)['verification_read_only']);
foreach(['http://localhost/verified','http://civ13.com:55555/verified?token=x','https://other.invalid/verified'] as $url) { $profile['verification_source']['url']=$url;rejects(fn()=>Civ13\PortableProfile::runtimeOptions($profile)); }
echo "PASS asynchronous hosted GET, real setup, legacy write/listener barriers, stale lookup/role-timer denial, concurrent coalescing and unrelated timer progress, bounded retry, schema/size/identity/removal/empty contracts\n";
