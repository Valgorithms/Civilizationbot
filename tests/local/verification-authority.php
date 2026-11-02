<?php
declare(strict_types=1);
// Inert HTTP response fixtures and inspection only. No sockets, .env, real feed or profile.
$authorityLoader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$authorityLoader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
$authorityChecks=0;
function authorityCheck(bool $ok,string $message):void{global $authorityChecks;++$authorityChecks;if(!$ok){throw new RuntimeException($message);}}
function authorityResult(React\Promise\PromiseInterface $promise):array{$out=null;$promise->then(static function($v)use(&$out){$out=$v;});if(!is_array($out)){throw new RuntimeException('Expected synchronous fixture completion');}return $out;}
final class AuthorityBrowser extends React\Http\Browser {
    public array $settings=[];public array $urls=[];public int $status=200;public ?Throwable $failure=null;public ?React\Promise\Deferred $pending=null;
    public string $body='[{"ss13":"fakealpha","discord":"990000000000000001","authenticated":true}]';
    public function __construct(){}
    public function withTimeout($v){$this->settings['timeout']=$v;return $this;}
    public function withFollowRedirects($v){$this->settings['redirects']=$v;return $this;}
    public function withRejectErrorResponse($v){$this->settings['reject_errors']=$v;return $this;}
    public function withResponseBuffer($v){$this->settings['buffer']=$v;return $this;}
    public function get($url,array $headers=[]){
        $this->urls[]=$url;authorityCheck($headers===['Accept'=>'application/json'],'Only JSON accept header, no credentials');
        return $this->pending?->promise() ?? ($this->failure!==null?React\Promise\reject($this->failure):React\Promise\resolve(new React\Http\Message\Response($this->status,['Location'=>'http://civ13.com:55555/verified'],$this->body)));
    }
}
use Civ13\HostedVerificationSource as Source;
use Civ13\HostedVerificationSnapshot as Snapshot;
use Civ13\PortableProfile as Profile;
$loop=new React\EventLoop\StreamSelectLoop();
$profile=['options'=>['role_ids'=>[],'channel_ids'=>[]],'gitdir'=>__DIR__,'state_dir'=>__DIR__,
    'verification_source'=>['url'=>'https://civ13.com:55555/verified','mode'=>'read-only']];
$mapped=Profile::runtimeOptions($profile);
authorityCheck($mapped['verification_read_only']===true && $mapped['verify_url']==='https://civ13.com:55555/verified','Explicit HTTPS profile maps without endpoint discovery or writes');

$secure='https://civ13.com:55555/verified'; // Fixture-selected example only; not contacted or assumed available.
foreach(['https://civ13.com/verified',$secure,'https://civ13.com:65535/verified'] as $url){authorityCheck(Profile::hostedVerificationUrl($url,true),'Explicit canonical hosted HTTPS accepted');}
foreach([null,false,'http://civ13.com:55555/verified','http://civ13.com/verified','https://civ13.com.evil.invalid/verified',
    'https://other.invalid/verified','https://user:secret@civ13.com/verified','https://civ13.com/verified?x=1',
    'https://civ13.com/verified#x','https://civ13.com/%76erified','https://CIV13.com/verified',
    'https://civ13.com:0/verified','https://civ13.com:65536/verified','https://civ13.com:0443/verified',
    "https://civ13.com/verified\n",'https://127.0.0.1/verified','file:///verified'] as $url){
    authorityCheck(!Profile::hostedVerificationUrl($url,true),'Insecure/ambiguous URL rejected');
}
foreach(['http://civ13.com:55555/verified','https://other.invalid/verified'] as $url){$denied=false;try{Source::fetchAuthenticated($loop,$url);}catch(InvalidArgumentException){$denied=true;}authorityCheck($denied,'Reject before constructing/requesting transport');}
// Pure production factory policy; constructing Connector on Windows may shell out for DNS discovery.
// Review verifies authenticatedBrowser passes these options into the installed Connector/Browser API.
// This test performs no DNS discovery or actual TLS handshake.
$options=(new ReflectionMethod(Source::class,'authenticatedConnectorOptions'))->invoke(null);
authorityCheck($options['unix']===false,'Unix transport is unavailable');
authorityCheck($options['tls']===['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false],'Peer and hostname validation cannot inherit insecure shared options');
authorityCheck($options['timeout']===10,'Connection timeout bounded');
// Reflect private mapper to inject response fixtures. This does not prove a real TLS handshake.
$read=new ReflectionMethod(Source::class,'read');
$browser=new AuthorityBrowser();
$http=authorityResult(Source::fetch($browser));
authorityCheck(($http['authority']??null)===null,'HTTP plus remote authenticated flag cannot mint receipt');
authorityCheck($browser->settings===['timeout'=>10,'redirects'=>false,'reject_errors'=>false,'buffer'=>4194304],'Bounded no-redirect read policy');
$https=authorityResult($read->invoke(null,$browser,$secure,true));
$records=Source::validate($https['json']);$receipt=$https['authority'];
authorityCheck($receipt instanceof Source && $receipt->matches($secure,$records),'Mapper fixture receipt bound to selected source and canonical records');
$changed=$records;$changed[0]['ss13']='fakeintruder';
authorityCheck(!$receipt->matches($secure,$changed),'Changed ckey with same Discord ID fails receipt');
authorityCheck(!$receipt->matches('https://civ13.com/verified',$records) && !$receipt->matches(Source::URL,$records),'Receipt cannot migrate origin or downgrade');
foreach([301,302,303,307,308,401,500] as $status){
    $browser->status=$status;$browser->urls=[];
    $result=authorityResult($read->invoke(null,$browser,$secure,true));
    authorityCheck($result['http_status']===0 && !isset($result['authority']) && $browser->urls===[$secure],'Redirect/error cannot mint receipt or cause follow/fallback');
}
$browser->status=200;$browser->failure=new RuntimeException('Synthetic certificate/hostname verification failure');$browser->urls=[];
$result=authorityResult($read->invoke(null,$browser,$secure,true));
authorityCheck($result['http_status']===0 && !isset($result['authority']) && $browser->urls===[$secure,$secure],'TLS failure retries same HTTPS once, never HTTP');
$browser->failure=new OverflowException('Synthetic oversized response');$browser->urls=[];
$result=authorityResult($read->invoke(null,$browser,$secure,true));
authorityCheck($result['http_status']===0 && $browser->urls===[$secure],'Overflow never retried or authoritative');
$browser->failure=null;
$now=1700000000;$snapshot=new Snapshot(static function()use(&$now):int{return $now;});
authorityResult($snapshot->refresh(fn()=>$https));
authorityCheck($snapshot->accessAuthority($records,$secure),'Exact fresh authenticated snapshot fixture accepted');
authorityCheck(!$snapshot->accessAuthority($changed,$secure),'Snapshot rejects changed ckey/same ID');
authorityCheck(!$snapshot->accessAuthority($records,Source::URL),'Snapshot denies insecure source');
$now+=11;$d=new React\Promise\Deferred();$pending=$snapshot->refresh(fn()=>$d->promise());
authorityCheck(!$snapshot->accessAuthority($records,$secure),'In-flight refresh fences retained authority');
$d->resolve(['http_status'=>0]);
authorityCheck(!$snapshot->accessAuthority($records,$secure) && $snapshot->records()===$records,'Failed refresh retains data but refuses authority');
$now+=11;authorityResult($snapshot->refresh(fn()=>$https));$now+=Snapshot::FRESH_SECONDS;
authorityCheck(!$snapshot->accessAuthority($records,$secure),'Stale authenticated receipt refused');
$now+=11;authorityResult($snapshot->refresh(fn()=>$http+['authenticated'=>true]));
authorityCheck($snapshot->status()['available'] && !$snapshot->accessAuthority($records,$secure),'Fresh HTTP does not inherit old HTTPS authority or trust scalar flags');
$now+=11;authorityResult($snapshot->refresh(fn()=>['http_status'=>200,'json'=>$https['json'],'authority'=>['authenticated'=>true]]));
authorityCheck(!$snapshot->accessAuthority($records,$secure),'Array/JSON provenance cannot mint receipt');
$empty=new Snapshot(static fn():int=>1700000000);$browser->body='[]';
$emptyResponse=authorityResult($read->invoke(null,$browser,$secure,true));authorityResult($empty->refresh(fn()=>$emptyResponse));
authorityCheck($empty->accessAuthority([],$secure),'Authenticated authoritative empty preserves revocation semantics');
$reflection=new ReflectionClass(Civ13\Verifier::class);$verifier=$reflection->newInstanceWithoutConstructor();
(new ReflectionProperty($verifier,'readOnly'))->setValue($verifier,true);
(new ReflectionProperty($verifier,'verify_url'))->setValue($verifier,Source::URL);
(new ReflectionProperty($verifier,'hosted'))->setValue($verifier,$empty);
authorityCheck(!$verifier->hostedAccessAuthority([]),'Actual verifier cannot bind HTTPS receipt to HTTP-configured runtime');
$secureVerifier=$reflection->newInstanceWithoutConstructor();
(new ReflectionProperty($secureVerifier,'readOnly'))->setValue($secureVerifier,true);
(new ReflectionProperty($secureVerifier,'verify_url'))->setValue($secureVerifier,$secure);
(new ReflectionProperty($secureVerifier,'hosted'))->setValue($secureVerifier,$empty);
authorityCheck($secureVerifier->hostedAccessAuthority([]),'Actual verifier accepts current source-bound authenticated empty fixture');
$denied=false;try{$read->invoke(null,$browser,Source::URL,true);}catch(InvalidArgumentException){$denied=true;}
authorityCheck($denied,'Internal authenticated mapper also rejects insecure downgrade before GET');
// Preserve the real Verifier's Collection copy contract for nonempty records.
$positive=new Snapshot(static fn():int=>1700000000);authorityResult($positive->refresh(fn()=>$https));
(new ReflectionProperty($secureVerifier,'hosted'))->setValue($secureVerifier,$positive);
$secureVerifier->verified=new Discord\Helpers\Collection($positive->records(), 'discord');
$copied=iterator_to_array($secureVerifier->verified,false);
authorityCheck($copied===$records && $secureVerifier->hostedAccessAuthority($copied),'Actual nonempty Collection copy retains exact authenticated records');
$changedCopy=$copied;$changedCopy[0]['ss13']='fakeintruder';
authorityCheck(!$secureVerifier->hostedAccessAuthority($changedCopy),'Mutated public collection cannot borrow private snapshot authority');
// Legacy public whitelist entrypoint must reject before even reading/touching its destination.
final class AuthorityOwner extends Civ13\Civ13 { public function hasRequiredConfigRoles(array $required_roles=[]):bool{return true;} }
$legacyOwner=(new ReflectionClass(AuthorityOwner::class))->newInstanceWithoutConstructor();$legacyOwner->verifier=$verifier;
$verifier->verified=new Discord\Helpers\Collection([], 'discord');
$legacy=(new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();$legacy->civ13=$legacyOwner;$legacy->enabled=true;
$legacyDenied=false;$legacy->whitelistUpdate()->then(null,static function(Throwable $error)use(&$legacyDenied){$legacyDenied=$error instanceof RuntimeException && $error->getMessage()==='Role list update unavailable or inaccessible.';});
authorityCheck($legacyDenied,'Legacy read-only HTTP whitelist refuses before uninitialized destination is accessed');
echo "PASS $authorityChecks mapping authority controls (mocked responses; no native TLS/endpoint proof)\n";
