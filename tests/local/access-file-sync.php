<?php
declare(strict_types=1);
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
foreach (['HostedVerificationSource','HostedVerificationSnapshot','PortableProfile','AdminSyncCoordinator','AdminCacheSyncTrigger','AccessListProjection','AccessFileSync'] as $class) { require_once __DIR__.'/../../src/Civ13/'.$class.'.php'; }
require_once __DIR__.'/../../src/Civ13/Server/AccessListWriter.php';
use Civ13\AccessFileSync;
use Civ13\AccessListProjection;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
$checks=0;function check(bool $ok,string $message):void{global $checks;++$checks;if(!$ok){throw new RuntimeException($message);}}
final class AccessRepo implements IteratorAggregate {
    public int $refreshes=0; public mixed $refreshHook=null;
    public function __construct(public array $items=[]){}
    public function get(string $field,string $id):mixed{return $this->items[$id]??null;}
    public function has(string $id):bool{return isset($this->items[$id]);}
    public function getIterator():Traversable{return new ArrayIterator($this->items);}
    public function freshen():PromiseInterface{++$this->refreshes;return $this->refreshHook!==null?($this->refreshHook)():resolve($this);}
}
final class AccessEmitter {
    public array $listeners=[];
    public function __construct(public AccessRepo $guilds){}
    public function on(string $event,callable $listener):void{$this->listeners[$event][]=$listener;}
    public function removeListener(string $event,callable $listener):void{$this->listeners[$event]=array_values(array_filter($this->listeners[$event]??[],fn($v)=>$v!==$listener));}
    public function emit(string $event,array $args=[]):void{foreach($this->listeners[$event]??[] as $listener){$listener(...$args);}}
}
function fixture(string $mode='publish',bool $ready=true):array{
    $f=(object)['now'=>1700000000,'timers'=>[],'writes'=>[],'reports'=>[],'refreshes'=>0,'writerHook'=>null];
    $gid='770000000000000001';$rid='880000000000000001';$uid='990000000000000001';
    $base=PHP_OS_FAMILY==='Windows'?'D:/synthetic-access-never-opened':'/synthetic-access-never-opened';
    $targets=[];foreach(['approved','whitelist'] as $kind){$targets[$kind]=['path'=>$base.'/SQL/'.$kind.'.txt','required_roles'=>['Verified'],'allow_creation'=>false,'allow_empty'=>true,'metadata_policy'=>'inherit-parent'];}
    $settings=['enabled'=>true,'mode'=>$mode,'max_bytes'=>4096,'debounce_seconds'=>0.05,'refresh_timeout_seconds'=>2.0,'targets'=>$targets,'server_key'=>'test1714','server_basedir'=>$base];
    $member=(object)['id'=>$uid,'roles'=>new AccessRepo([$rid=>true])];
    $f->guild=(object)['id'=>$gid,'member_count'=>1,'roles'=>new AccessRepo([$rid=>(object)[]]),'members'=>new AccessRepo([$uid=>$member])];
    $discord=new AccessEmitter(new AccessRepo([$gid=>$f->guild]));
    $f->status=['available'=>true,'stale'=>false,'has_valid_list'=>true,'refresh_in_progress'=>false,'records'=>1,'last_success_utc'=>gmdate('Y-m-d\TH:i:s\Z',$f->now),'age_seconds'=>0];
    $verifier=new class($f,$uid){
        public bool $readOnly=true;public array $verified;public mixed $hook=null;
        public function __construct(public object $f,string $uid){$this->verified=[['ss13'=>'Fake.Test','discord'=>$uid]];}
        public function hostedStatus():array{return $this->f->status;}
        public function refreshHosted():PromiseInterface{++$this->f->refreshes;return $this->hook!==null?($this->hook)():resolve($this->f->status);}
    };
    $loop=new class($f){
        public function __construct(public object $f){}
        public function addTimer(float $delay,callable $callback):object{$timer=(object)['delay'=>$delay,'callback'=>$callback,'active'=>true];$this->f->timers[]=$timer;return $timer;}
        public function cancelTimer(object $timer):void{$timer->active=false;}
    };
    $logger=new class($f){public function __construct(public object $f){}public function info(string $message,array $context=[]):void{$this->f->reports[]=$context;}};
    $app=(object)['options'=>['access_file_sync'=>$settings,'admin_file_sync'=>['enabled'=>false]],'civ13_guild_id'=>$gid,'role_ids'=>['Verified'=>$rid],'verifier'=>$verifier,'discord'=>$discord,'loop'=>$loop,'logger'=>$logger,'ready'=>$ready,'enabled_gameservers'=>['test1714'=>(object)['enabled'=>true,'basedir'=>$base]]];
    $writer=function(string $wire,int $generation,callable $guard)use($f):array{if($f->writerHook!==null){return ($f->writerHook)($wire,$generation,$guard);}if(!$guard()){return ['state'=>'superseded'];}$f->writes[]=AccessListProjection::decode($wire)['outputs'];return ['state'=>'updated'];};
    return [$app,$settings,$f,$writer];
}
function fire(object $f,float $delay=0.05):void{foreach($f->timers as $timer){if($timer->active && $timer->delay===$delay){$timer->active=false;($timer->callback)();return;}}}
check(AccessFileSync::attach(new stdClass(),['enabled'=>false])===null,'Disabled has no app/cache/file access');
[$app,$settings,$f,$write]=fixture();$service=AccessFileSync::attach($app,$settings,$write,fn()=>$f->now);fire($f);
check(count($f->writes)===1 && $f->writes[0]['approved']===$f->writes[0]['whitelist'],'Initial cache-ready projects both without legacy listeners');
check($f->refreshes===1 && $f->guild->members->refreshes===0,'No initial member fetch/second client');
check($service->lastPreview()['targets']['approved']['records']===1,'Sanitized preview available');
$rid=$app->role_ids['Verified'];$uid='990000000000000001';$gid=$app->civ13_guild_id;
$f->guild->members->items[$uid]->roles->items=[];$app->discord->emit('GUILD_MEMBER_UPDATE',[(object)['guild_id'=>$gid]]);fire($f);
check(count($f->writes)===2 && $f->writes[1]===['approved'=>"\n",'whitelist'=>"\n"],'Role removal projects authoritative empty to both');
$f->guild->member_count=2;$app->discord->emit('GUILD_MEMBER_UPDATE',[(object)['guild_id'=>$gid]]);fire($f);check(count($f->writes)===2,'Partial member cache preserves old output');
$f->guild->member_count=1;$f->guild->members->items[$uid]->roles->items=[$rid=>true];$service->verificationChanged();fire($f);check(count($f->writes)===3 && $f->refreshes===3,'Successful cache replacement notification does not refetch source');
$f->status['stale']=true;$service->verificationChanged();fire($f);check(count($f->writes)===3 && $service->lastPreview()['state']==='unavailable','Stale verification cannot publish or expose a current preview');$f->status['stale']=false;
$d=new Deferred();$f->guild->members->refreshHook=fn()=>$d->promise();$app->discord->emit('reconnected');fire($f);check(count($f->writes)===3 && $f->guild->members->refreshes===1 && $f->guild->roles->refreshes===1,'Reconnect fences write, owns one paired refresh');
$app->discord->emit('GUILD_MEMBER_UPDATE',[(object)['guild_id'=>$gid]]);fire($f);check(count($f->writes)===3,'Event cannot bypass pending reconnect');
$d->resolve(null);fire($f);check(count($f->writes)===4,'Successful reconnect publishes current cache');
$app->options['access_file_sync']['mode']='preview';$service->verificationChanged();fire($f);check(count($f->writes)===4,'Changed configuration fences old owner');$service->stop();
$app->discord->emit('GUILD_MEMBER_UPDATE',[(object)['guild_id'=>$gid]]);fire($f);check(count($f->writes)===4,'Stopped owner inert');
[$app,$settings,$f,$write]=fixture('preview');$service=AccessFileSync::attach($app,$settings,$write,fn()=>$f->now);fire($f);check($f->writes===[] && $service->lastPreview()['targets']['approved']['records']===1,'Preview never enters injected writer');$service->stop();
[$app,$settings,$f,$write]=fixture('publish',false);$service=AccessFileSync::attach($app,$settings,$write,fn()=>$f->now);fire($f);check($f->writes===[],'Before init no writes');$app->discord->emit('init');fire($f);check(count($f->writes)===1,'Actual init requests initial projection');$service->stop();
[$app,$settings,$f,$write]=fixture();$app->options['admin_file_sync']['enabled']=true;$refused=false;try{AccessFileSync::attach($app,$settings,$write);}catch(InvalidArgumentException){$refused=true;}check($refused && $app->discord->listeners===[],'No duplicate cache owner');
[$app,$settings,$f,$write]=fixture();$f->writerHook=function($wire,$generation,$guard)use($app,$f){$app->discord->emit('GUILD_MEMBER_UPDATE',[(object)['guild_id'=>$app->civ13_guild_id]]);return $guard()?['state'=>'updated']:['state'=>'superseded'];};$service=AccessFileSync::attach($app,$settings,$write,fn()=>$f->now);fire($f);check($f->writes===[],'Event before commit invalidates old projection');$service->stop();
// Pure portable mapping has no live profile or credential reads.
[$app,$settings,$f,$write]=fixture();$profileSettings=$settings;unset($profileSettings['server_key'],$profileSettings['server_basedir']);
$profile=['root'=>$settings['server_basedir'],'state_dir'=>$settings['server_basedir'].'/state','gitdir'=>$settings['server_basedir'],'servers'=>['test1714'=>['enabled'=>true,'basedir'=>$settings['server_basedir']]],'verification_source'=>['mode'=>'read-only','url'=>'http://civ13.com:55555/verified'],'options'=>['civ13_guild_id'=>$app->civ13_guild_id,'role_ids'=>$app->role_ids],'access_file_sync'=>$profileSettings];
$mapped=Civ13\PortableProfile::runtimeOptions($profile);check($mapped['access_file_sync']['server_key']==='test1714' && $mapped['verification_read_only']===true,'Explicit portable read-only mapping');
$reordered=$profile;foreach($reordered['access_file_sync']['targets'] as &$target){$target=array_reverse($target,true);}unset($target);$reordered['access_file_sync']['targets']=array_reverse($reordered['access_file_sync']['targets'],true);check(Civ13\PortableProfile::runtimeOptions($reordered)['access_file_sync']['mode']==='publish','JSON configuration field ordering is immaterial');
$bad=$profile;$bad['access_file_sync']['targets']['approved']['path']=$settings['server_basedir'].'/SQL/admins.txt';try{Civ13\PortableProfile::runtimeOptions($bad);check(false,'Wrong target accepted');}catch(InvalidArgumentException){check(true,'Independent destination binding');}
check(!str_contains(json_encode($f->reports),'Fake.Test'),'Reports contain no selected identity');
[$app,$settings,$f,$write]=fixture('preview');$settings['max_bytes']=1;$app->options['access_file_sync']=$settings;$service=AccessFileSync::attach($app,$settings,$write,fn()=>$f->now);fire($f);check($service->lastPreview()['state']==='unavailable' && $f->writes===[],'Oversize proposal is not a current preview');$service->stop();
// Default-on migration is intent only until explicit targets/source/roles are valid.
$omitted=$profile;unset($omitted['access_file_sync']);$omittedOptions=Civ13\PortableProfile::runtimeOptions($omitted);
check($omittedOptions['access_file_sync']===['enabled'=>true] && $omittedOptions['verification_read_only']===true && $omittedOptions['admin_file_sync']===['enabled'=>false],'Omitted section defaults on without unrelated authority');
$optout=$profile;$optout['access_file_sync']['enabled']=false;$optout['access_file_sync']['targets']='retained-invalid-inert';
check(Civ13\PortableProfile::runtimeOptions($optout)['access_file_sync']===['enabled'=>false],'Explicit false preserves inert opt-out even with retained settings');
$bound=$profile;unset($bound['access_file_sync']['enabled']);$boundOptions=Civ13\PortableProfile::runtimeOptions($bound);
check($boundOptions['access_file_sync']['enabled']===true && $boundOptions['access_file_sync']['targets']===$profile['access_file_sync']['targets'],'Configured targets need no redundant enable flag');
[$app,$settings,$f,$write]=fixture();unset($settings['enabled']);$app->options['access_file_sync']=$settings;$service=AccessFileSync::attach($app,$settings,$write,fn()=>$f->now);fire($f);check(count($f->writes)===1,'Bound runtime settings default on');$service->stop();
[$app,$settings,$f,$write]=fixture();$unconfigured=false;try{AccessFileSync::attach($app,['enabled'=>true],$write);}catch(InvalidArgumentException){$unconfigured=true;}check($unconfigured && $app->discord->listeners===[] && $f->writes===[],'Default-on intent cannot guess targets or install a partial owner');
check(AccessFileSync::attach(new stdClass(),['enabled'=>false,'targets'=>'ignored'])===null,'Explicit opt-out has no prerequisite reads');
foreach([null,'true',0] as $invalid){$bad=$profile;$bad['access_file_sync']['enabled']=$invalid;try{Civ13\PortableProfile::runtimeOptions($bad);check(false,'Invalid flag accepted');}catch(InvalidArgumentException){check(true,'Invalid flag not coerced into enablement');}}
$emptyOwner=(new ReflectionClass(Civ13\Civ13::class))->newInstanceWithoutConstructor();$emptyOwner->options=['access_file_sync'=>['enabled'=>true]];
check($emptyOwner->accessFileSyncPreview()===['state'=>'unavailable','reason'=>'not-configured-or-not-ready','enabled'=>true],'Default-on unconfigured status is honest');
$emptyOwner->options=['access_file_sync'=>['enabled'=>false]];check($emptyOwner->accessFileSyncPreview()===['state'=>'disabled','enabled'=>false],'Explicit opt-out status is inert');
// Actual application notification method, still with a fake service owner/cache.
[$app,$settings,$f,$write]=fixture();$service=AccessFileSync::attach($app,$settings,$write,fn()=>$f->now);fire($f);
$owner=(new ReflectionClass(Civ13\Civ13::class))->newInstanceWithoutConstructor();
$current=(new ReflectionClass(Civ13\Verifier::class))->newInstanceWithoutConstructor();
$retired=(new ReflectionClass(Civ13\Verifier::class))->newInstanceWithoutConstructor();
$owner->verifier=$current;(new ReflectionProperty($owner,'accessFileSync'))->setValue($owner,$service);
$owner->hostedVerificationRefreshed($retired);fire($f);check(count($f->writes)===1,'Retired verifier cannot notify access owner');
$owner->hostedVerificationRefreshed($current);fire($f);check(count($f->writes)===2,'Real read-only refresh hook reaches access owner');
$f->now+=1800;check($owner->accessFileSyncPreview()['state']==='unavailable','Preview expires even if status age is stale');
$service->stop();(new ReflectionProperty($owner,'accessFileSync'))->setValue($owner,null);
// End-to-end composition uses the real publisher only in this owned synthetic directory.
[$app,$settings,$f,$write]=fixture();$base=realpath($argv[2]??sys_get_temp_dir());if($base===false){throw new RuntimeException('Owned temp base unavailable');}$tmp=$base.'/access-composition-'.bin2hex(random_bytes(8));mkdir($tmp);$tmp=realpath($tmp);if($tmp===false){throw new RuntimeException('Owned temp root unavailable');}mkdir($tmp.'/SQL');
$settings['server_basedir']=$tmp;$app->enabled_gameservers['test1714']->basedir=$tmp;
foreach($settings['targets'] as $kind=>&$target){$target['path']=$tmp.'/SQL/'.$kind.'.txt';$target['allow_creation']=true;}unset($target);
$app->options['access_file_sync']=$settings;$service=null;$reader=null;
try{
    $service=AccessFileSync::attach($app,$settings,null,fn()=>$f->now);fire($f);
    $initial="faketest=990000000000000001\n";
    check(file_get_contents($tmp.'/SQL/approved.txt')===$initial && file_get_contents($tmp.'/SQL/whitelist.txt')===$initial,'Real composition publishes both explicit files');
    $reader=fopen($tmp.'/SQL/approved.txt','rb');
    $app->verifier->verified=[];$f->status['records']=0;$f->guild->members->items=[];$f->guild->member_count=0;
    $app->discord->emit('GUILD_MEMBER_REMOVE',[(object)['guild_id'=>$app->civ13_guild_id]]);fire($f);
    $after=file_get_contents($tmp.'/SQL/approved.txt');
    check(($after==="\n" && file_get_contents($tmp.'/SQL/whitelist.txt')==="\n") || ($after===$initial && file_get_contents($tmp.'/SQL/whitelist.txt')===$initial),'Held reader yields complete replacement or retained known-good pair');
    check(stream_get_contents($reader)===$initial,'Held old handle never sees an in-place truncation');
    echo 'OBSERVED held-reader publication '.($after==="\n"?'updated':'refused-preserving-old')."\n";
    fclose($reader);$reader=null;
    $service->verificationChanged();fire($f); // A new explicit notification, never an automatic I/O retry.
    check(file_get_contents($tmp.'/SQL/approved.txt')==="\n" && file_get_contents($tmp.'/SQL/whitelist.txt')==="\n",'New accepted projection revokes after reader closes');
}finally{
    if(is_resource($reader)){fclose($reader);} $service?->stop();
    foreach(glob($tmp.'/SQL/*')?:[] as $file){unlink($file);}foreach(glob($tmp.'/SQL/.*')?:[] as $file){if(is_file($file)){unlink($file);}}rmdir($tmp.'/SQL');rmdir($tmp);
}
echo "PASS $checks access lifecycle/profile controls\n";
