<?php

declare(strict_types=1);

// No bot.php, .env, game process, supervisor process or network. Real temporary spool files.
namespace Civ13 {
    function proc_open(...$args): never { throw new \LogicException('Native adapter attempted process execution'); }
    function exec(...$args): never { throw new \LogicException('Native adapter attempted shell execution'); }
    function shell_exec(...$args): never { throw new \LogicException('Native adapter attempted shell execution'); }
}

namespace {
    $loader = require ($argv[1] ?? __DIR__.'/../../vendor/autoload.php');
    $loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
    use Civ13\NativeLifecycle;
    use React\EventLoop\LoopInterface;
    use React\EventLoop\Timer\Timer;
    use React\EventLoop\TimerInterface;

    function check(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
    function refuses(callable $call): void {
        try { $call(); } catch (RuntimeException|InvalidArgumentException|UnexpectedValueException|LogicException $error) { return; }
        throw new LogicException('Expected fail-closed rejection');
    }
    function writeRecord(string $path, array $data): void { file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)); }

    final class NativeLoop implements LoopInterface
    {
        public array $timers = [];
        public bool $failRegistration = false;
        public function addReadStream($stream, $listener) { throw new LogicException('Unexpected IO'); }
        public function addWriteStream($stream, $listener) { throw new LogicException('Unexpected IO'); }
        public function removeReadStream($stream) {}
        public function removeWriteStream($stream) {}
        public function addTimer($interval, $callback) { throw new LogicException('Unexpected legacy timer'); }
        public function addPeriodicTimer($interval, $callback) {
            if ($this->failRegistration) { throw new RuntimeException('Simulated interruption after publication'); }
            $timer = new Timer($interval, $callback, true); $this->timers[spl_object_id($timer)] = $timer; return $timer;
        }
        public function cancelTimer(TimerInterface $timer) { unset($this->timers[spl_object_id($timer)]); }
        public function futureTick($listener) { throw new LogicException('Unexpected future tick'); }
        public function addSignal($signal, $listener) { throw new LogicException('Unexpected signal'); }
        public function removeSignal($signal, $listener) {}
        public function run() { throw new LogicException('Offline loop must not run'); }
        public function stop() {}
        public function tick(): void { foreach (array_values($this->timers) as $timer) { ($timer->getCallback())($timer); } }
    }

    final class NativeMessage extends Discord\Parts\Channel\Message
    {
        public array $reactions = [];
        public function __construct(?Discord\Discord $discord = null, array $attributes = [], bool $created = false) {}
        public function react($emoticon): React\Promise\PromiseInterface { $this->reactions[] = $emoticon; return React\Promise\resolve(null); }
    }

    $root = sys_get_temp_dir().'/native lifecycle & fixture-'.bin2hex(random_bytes(8));
    mkdir($root); $caseNumber = 0; $cases = 0;
    $fixture = static function () use ($root, &$caseNumber): array {
        $dir = $root.'/case-'.++$caseNumber;
        mkdir($dir); mkdir($dir.'/software'); mkdir($dir.'/repository'); mkdir($dir.'/runs'); mkdir($dir.'/runs/owned'); mkdir($dir.'/bot-control');
        $dir = realpath($dir); $runtime = realpath($dir.'/runs/owned'); $repository = realpath($dir.'/repository');
        foreach (['software/supervisor.py', 'software/master_guard.py', 'dd.exe', 'runs/owned/civ13.dmb', 'runs/owned/civ13.rsc'] as $name) { file_put_contents($dir.'/'.$name, 'inert fixture '.$name); }
        $daemon = realpath($dir.'/dd.exe');
        $config = ['root'=>$dir, 'repository'=>$repository, 'branch'=>'master', 'bind'=>'127.0.0.1', 'port'=>1714, 'map'=>'NOMADS_GAIA', 'daemon'=>$daemon, 'bot_control_protocol'=>NativeLifecycle::PROTOCOL];
        writeRecord($dir.'/supervisor.json', $config);
        $settings = ['enabled'=>true, 'supervisor_root'=>$dir, 'daemon'=>$daemon, 'daemon_sha256'=>hash_file('sha256',$daemon),
            'supervisor_sha256'=>hash_file('sha256',$dir.'/software/supervisor.py'), 'guard_sha256'=>hash_file('sha256',$dir.'/software/master_guard.py'), 'config_sha256'=>hash_file('sha256',$dir.'/supervisor.json'),
            'expected'=>['revision'=>str_repeat('a',40),'map'=>'NOMADS_GAIA','generation'=>'round-v2:'.str_repeat('b',32),'dmb_sha256'=>hash_file('sha256',$runtime.'/civ13.dmb'),'rsc_sha256'=>hash_file('sha256',$runtime.'/civ13.rsc')]];
        $server = ['enabled'=>true,'ip'=>'127.0.0.1','port'=>'1714','basedir'=>$runtime];
        $loop = new NativeLoop();
        $clock = (object)['now'=>0];
        $wall = (object)['now'=>time()];
        $adapter = new NativeLifecycle($settings, $server, $repository, $loop, static fn ()=>$clock->now, static fn ()=>$wall->now);
        $capability = ['protocol'=>NativeLifecycle::PROTOCOL, 'binding_sha256'=>$adapter->description()['binding_sha256'], 'heartbeat_unix'=>$wall->now, 'epoch'=>str_repeat('c',32),'sequence'=>1,'master_verified'=>true,'busy'=>false,'process'=>null];
        writeRecord($dir.'/bot-control/capability.json',$capability);
        $process = ['pid'=>1234, 'creation_filetime'=>'134367709910115498','executable'=>$daemon,'working_directory'=>$runtime,'dmb_sha256'=>$settings['expected']['dmb_sha256'],'rsc_sha256'=>$settings['expected']['rsc_sha256']];
        return compact('dir','runtime','repository','settings','server','loop','clock','wall','adapter','capability','process','config');
    };
    $canonicalFixture = static function () use ($root, &$caseNumber): array {
        $repository = $root.'/canonical-'.++$caseNumber;
        mkdir($repository); mkdir($repository.'/config'); mkdir($repository.'/data');
        mkdir($repository.'/data/desktop-supervisor');
        $repository = realpath($repository); $dir = realpath($repository.'/data/desktop-supervisor');
        mkdir($dir.'/software'); mkdir($dir.'/bot-control'); mkdir($dir.'/runs');
        foreach (['software/supervisor.py','software/master_guard.py','dd.exe'] as $name) { file_put_contents($dir.'/'.$name, 'inert '.$name); }
        foreach (['civ13.dmb','civ13.rsc'] as $name) { file_put_contents($repository.'/'.$name, 'inert '.$name); }
        $daemon = realpath($dir.'/dd.exe');
        $config = ['root'=>$dir,'repository'=>$repository,'canonical_game_directory'=>$repository,
            'config_directory'=>realpath($repository.'/config'),'canonical_update_enabled'=>true,'canonical_bot_enabled'=>true,
            'branch'=>'master','bind'=>'127.0.0.1','port'=>1714,'map'=>'NOMADS_GAIA','daemon'=>$daemon,'bot_control_protocol'=>NativeLifecycle::PROTOCOL];
        writeRecord($dir.'/supervisor.json',$config);
        $settings = ['enabled'=>true,'target_type'=>'canonical','supervisor_root'=>$dir,'daemon'=>$daemon,'daemon_sha256'=>hash_file('sha256',$daemon),
            'supervisor_sha256'=>hash_file('sha256',$dir.'/software/supervisor.py'),'guard_sha256'=>hash_file('sha256',$dir.'/software/master_guard.py'),
            'config_sha256'=>hash_file('sha256',$dir.'/supervisor.json'),'expected'=>['revision'=>str_repeat('a',40),'map'=>'NOMADS_GAIA',
            'generation'=>'round-v2:'.str_repeat('b',32),'dmb_sha256'=>hash_file('sha256',$repository.'/civ13.dmb'),'rsc_sha256'=>hash_file('sha256',$repository.'/civ13.rsc')]];
        $server=['enabled'=>true,'ip'=>'127.0.0.1','port'=>'1714','basedir'=>$repository]; $loop=new NativeLoop();
        return compact('dir','repository','settings','server','loop','config');
    };
    $complete = static function (array $f, string $action): array {
        $request = json_decode(file_get_contents($f['dir'].'/bot-control/request.json'),true,16,JSON_THROW_ON_ERROR);
        check($request['action']===$action,'Wrong fixed action');
        check($request['expires_unix']-$request['issued_unix']===NativeLifecycle::REQUEST_LIFETIME_SECONDS,'Wrong request lifetime');
        $result = array_intersect_key($request,array_flip(['protocol','operation','action','epoch','sequence','binding_sha256','before','issued_unix','expires_unix']));
        $after = $action==='save-stop' ? null : $f['process'];
        if ($action==='restart') { $after['pid']=2345; $after['creation_filetime']='134367709910115499'; }
        $result += ['outcome'=>'complete','master_verified'=>true,'ready'=>$action!=='save-stop','after'=>$after];
        if ($action!=='start') { $result += ['exit_acknowledged'=>true,'save_complete'=>true,'saved_generation'=>'round-v2:'.str_repeat('d',32),'save_receipt_sha256'=>str_repeat('e',64)]; }
        if ($action!=='save-stop') { $result['restored_generation']=$action==='restart' ? $result['saved_generation'] : $f['settings']['expected']['generation']; }
        return $result;
    };
    $publishResult = static function (array $f, array $result, bool $consume = true): void {
        if ($consume) { unlink($f['dir'].'/bot-control/request.json'); }
        writeRecord($f['dir'].'/bot-control/result-'.$result['operation'].'.json',$result);
    };
    $await = static function ($promise, array $f, ?array &$success, ?Throwable &$failure): void {
        $promise->then(static function ($value) use (&$success): void {$success=$value;},static function ($error) use (&$failure): void {$failure=$error;});
        $f['loop']->tick();
    };
    try {
        $f=$canonicalFixture(); $adapter=new NativeLifecycle($f['settings'],$f['server'],$f['repository'],$f['loop']);
        check(($adapter->description()['binding']['target_type']??null)==='canonical','Canonical type is not explicitly bound'); ++$cases;
        foreach (['canonical_bot_enabled','canonical_update_enabled'] as $field) {
            $f=$canonicalFixture();$config=$f['config'];$config[$field]=false;writeRecord($f['dir'].'/supervisor.json',$config);
            $settings=$f['settings'];$settings['config_sha256']=hash_file('sha256',$f['dir'].'/supervisor.json');
            refuses(fn()=>new NativeLifecycle($settings,$f['server'],$f['repository'],$f['loop'])); ++$cases;
        }
        $f=$canonicalFixture();$settings=$f['settings'];unset($settings['target_type']);
        refuses(fn()=>new NativeLifecycle($settings,$f['server'],$f['repository'],$f['loop'])); ++$cases;
        $settings['target_type']='arbitrary';refuses(fn()=>new NativeLifecycle($settings,$f['server'],$f['repository'],$f['loop'])); ++$cases;
        $f=$fixture();$config=$f['config'];$config['canonical_game_directory']=$f['repository'];writeRecord($f['dir'].'/supervisor.json',$config);
        $settings=$f['settings'];$settings['config_sha256']=hash_file('sha256',$f['dir'].'/supervisor.json');
        refuses(fn()=>new NativeLifecycle($settings,$f['server'],$f['repository'],$f['loop'])); ++$cases;
        // Default rejection does not need a valid supervisor, credentials or dependencies.
        $f=$fixture(); $disabled=$f['settings'];$disabled['enabled']=false;
        refuses(fn()=>new NativeLifecycle($disabled,$f['server'],$f['repository'],$f['loop'])); ++$cases;
        foreach (['ip'=>'civ13.com','port'=>1715,'enabled'=>false,'basedir'=>$root] as $field=>$bad) {
            $server=$f['server'];$server[$field]=$bad;refuses(fn()=>new NativeLifecycle($f['settings'],$server,$f['repository'],$f['loop'])); ++$cases;
        }
        foreach (['map'=>'NOMADS_EUROPE','bind'=>'0.0.0.0','branch'=>'pr-test','bot_control_protocol'=>'old','repository'=>$root] as $field=>$bad) {
            $f=$fixture();$config=$f['config'];$config[$field]=$bad;writeRecord($f['dir'].'/supervisor.json',$config);$settings=$f['settings'];$settings['config_sha256']=hash_file('sha256',$f['dir'].'/supervisor.json');
            refuses(fn()=>new NativeLifecycle($settings,$f['server'],$f['repository'],$f['loop'])); ++$cases;
        }
        foreach (['software/supervisor.py','software/master_guard.py','supervisor.json','dd.exe','runs/owned/civ13.dmb','runs/owned/civ13.rsc'] as $file) {
            $f=$fixture();file_put_contents($f['dir'].'/'.$file,'changed');refuses(fn()=>$f['adapter']->request('start'));check(!file_exists($f['dir'].'/bot-control/pending.json'),'Changed source created a request');++$cases;
        }
        foreach (['protocol'=>'old','heartbeat_unix'=>time()-30,'busy'=>true,'master_verified'=>false,'binding_sha256'=>str_repeat('f',64)] as $field=>$bad) {
            $f=$fixture();$cap=$f['capability'];$cap[$field]=$bad;writeRecord($f['dir'].'/bot-control/capability.json',$cap);refuses(fn()=>$f['adapter']->request('start'));++$cases;
        }
        $f=$fixture();file_put_contents($f['dir'].'/HALTED.json','preserve');refuses(fn()=>$f['adapter']->request('start'));check(file_get_contents($f['dir'].'/HALTED.json')==='preserve','Halt latch changed');++$cases;
        $f=$fixture();unlink($f['dir'].'/bot-control/capability.json');refuses(fn()=>$f['adapter']->request('start'));++$cases;
        $f=$fixture();refuses(fn()=>$f['adapter']->request('mapswap'));refuses(fn()=>$f['adapter']->request('update'));refuses(fn()=>$f['adapter']->request('start & arbitrary'));refuses(fn()=>$f['adapter']->request('save-stop'));++$cases;

        foreach (['start','save-stop','restart'] as $action) {
            $f=$fixture();if($action!=='start'){$cap=$f['capability'];$cap['process']=$f['process'];writeRecord($f['dir'].'/bot-control/capability.json',$cap);}
            $success=null;$failure=null;$promise=$f['adapter']->request($action);$promise->then(static function($v)use(&$success){$success=$v;},static function($e)use(&$failure){$failure=$e;});
            check($success===null&&$failure===null,'Queued request reported completion');
            $transition=fopen($f['dir'].'/bot-control/adapter.lock','c+b');check(flock($transition,LOCK_EX|LOCK_NB),'Publication lock remained held during polling');flock($transition,LOCK_UN);fclose($transition);
            // Both an in-flight instance and a simulated bot restart must refuse duplicate submission.
            refuses(fn()=>$f['adapter']->request($action));
            $other=new NativeLifecycle($f['settings'],$f['server'],$f['repository'],$f['loop']);refuses(fn()=>$other->request($action));
            $result=$complete($f,$action);$publishResult($f,$result);$f['loop']->tick();
            check($failure===null&&$success===$result,'Bound completion not accepted');check(!file_exists($f['dir'].'/bot-control/pending.json'),'Successful pending not acknowledged');check(file_exists($f['dir'].'/bot-control/result-'.$result['operation'].'.json'),'Result evidence discarded');++$cases;
            // A fresh bot instance cannot replay a successful action against stale capability state.
            refuses(fn()=>$other->request($action));++$cases;
            if($action==='save-stop') {
                $cap=$f['capability'];$cap['sequence']=2;writeRecord($f['dir'].'/bot-control/capability.json',$cap);
                refuses(fn()=>$other->request('start'));check(!file_exists($f['dir'].'/bot-control/request.json'),'Save-stop allowed a stale-generation start');++$cases;
                $updated=$f['settings'];$updated['expected']['generation']=$result['saved_generation'];
                $reviewed=new NativeLifecycle($updated,$f['server'],$f['repository'],$f['loop']);$cap['binding_sha256']=$reviewed->description()['binding_sha256'];writeRecord($f['dir'].'/bot-control/capability.json',$cap);
                $promise=$reviewed->request('start');$f['settings']=$updated;$result=$complete($f,'start');$publishResult($f,$result);$success=null;$failure=null;$await($promise,$f,$success,$failure);
                check($failure===null&&$success['restored_generation']===$updated['expected']['generation'],'Reconciled start did not require newly saved generation');++$cases;
            }
        }
        foreach (['operation'=>str_repeat('0',32),'epoch'=>str_repeat('0',32),'binding_sha256'=>str_repeat('0',64),'issued_unix'=>0,'expires_unix'=>0,'outcome'=>'unknown','master_verified'=>false,'restored_generation'=>'round-v2:'.str_repeat('0',32),'ready'=>false] as $field=>$bad) {
            $f=$fixture();$promise=$f['adapter']->request('start');$result=$complete($f,'start');$original=$result['operation'];$result[$field]=$bad;
            unlink($f['dir'].'/bot-control/request.json');writeRecord($f['dir'].'/bot-control/result-'.$original.'.json',$result);$success=null;$failure=null;$await($promise,$f,$success,$failure);
            check($success===null&&$failure!==null,'Unbound/unknown result was accepted');check(is_file($f['dir'].'/bot-control/pending.json'),'Unknown outcome lost persistent latch');refuses(fn()=>$f['adapter']->request('start'));++$cases;
        }
        foreach (['pid'=>0,'creation_filetime'=>'reused','executable'=>__FILE__,'working_directory'=>$root,'dmb_sha256'=>str_repeat('f',64)] as $field=>$bad) {
            $f=$fixture();$promise=$f['adapter']->request('start');$result=$complete($f,'start');$result['after'][$field]=$bad;$publishResult($f,$result);$success=null;$failure=null;$await($promise,$f,$success,$failure);check($success===null&&$failure!==null,'Wrong process identity accepted');++$cases;
        }
        foreach (['exit_acknowledged'=>false,'save_complete'=>false,'saved_generation'=>'old','save_receipt_sha256'=>'missing'] as $field=>$bad) {
            $f=$fixture();$cap=$f['capability'];$cap['process']=$f['process'];writeRecord($f['dir'].'/bot-control/capability.json',$cap);
            $promise=$f['adapter']->request('save-stop');$result=$complete($f,'save-stop');$result[$field]=$bad;$publishResult($f,$result);$success=null;$failure=null;$await($promise,$f,$success,$failure);check($success===null&&$failure!==null,'Unsafe stop accepted');++$cases;
        }
        $f=$fixture();$cap=$f['capability'];$cap['process']=$f['process'];writeRecord($f['dir'].'/bot-control/capability.json',$cap);$promise=$f['adapter']->request('restart');$result=$complete($f,'restart');$result['after']=$f['process'];$publishResult($f,$result);$success=null;$failure=null;$await($promise,$f,$success,$failure);check($failure!==null,'Same-process restart accepted');++$cases;
        $f=$fixture();$promise=$f['adapter']->request('start');$result=$complete($f,'start');$publishResult($f,$result,false);$success=null;$failure=null;$await($promise,$f,$success,$failure);check($failure!==null,'Unconsumed request accepted');++$cases;
        $f=$fixture();$promise=$f['adapter']->request('start');$f['clock']->now=601*1000000000;$success=null;$failure=null;$await($promise,$f,$success,$failure);check($failure!==null&&is_file($f['dir'].'/bot-control/pending.json')&&is_file($f['dir'].'/bot-control/request.json'),'Timeout retried, killed, or lost evidence');++$cases;
        // This synthetic matching receipt checks only the producer's response policy;
        // it does not establish when a real consumer admitted the operation.
        $f=$fixture();$promise=$f['adapter']->request('start');$result=$complete($f,'start');$f['wall']->now+=301;$publishResult($f,$result);$success=null;$failure=null;$await($promise,$f,$success,$failure);check($failure===null&&$success===$result,'Matching late receipt rejected as expired');++$cases;
        foreach ([-1, 'invalid', PHP_INT_MAX] as $badClock) {
            $f=$fixture();$f['wall']->now=$badClock;refuses(fn()=>$f['adapter']->request('start'));
            check(!file_exists($f['dir'].'/bot-control/request.json'),'Invalid wall clock published request');++$cases;
        }
        $f=$fixture();$reads=0;$issued=$f['wall']->now;
        $delayed=new NativeLifecycle($f['settings'],$f['server'],$f['repository'],$f['loop'],null,
            static function()use(&$reads,$issued){return $issued+(++$reads>2?11:0);});
        refuses(fn()=>$delayed->request('start'));
        check(!file_exists($f['dir'].'/bot-control/pending.json'),'Expired capability published a packet');++$cases;
        // The clock advances only after both durable records are written,
        // modeling delayed file IO before the exclusive hard link.
        $f=$fixture();$reads=0;$issued=$f['wall']->now;
        $delayed=new NativeLifecycle($f['settings'],$f['server'],$f['repository'],$f['loop'],null,
            static function()use(&$reads,$issued){return $issued+(++$reads>3?11:0);});
        refuses(fn()=>$delayed->request('start'));
        check(is_file($f['dir'].'/bot-control/pending.json')&&!file_exists($f['dir'].'/bot-control/request.json')&&count(glob($f['dir'].'/bot-control/request-*.tmp'))===1,'Delayed publication lost evidence or linked stale request');++$cases;
        // Even if the capability refreshed during IO, the original request's
        // own timestamp must still be checked immediately before publication.
        $f=$fixture();$reads=0;$issued=$f['wall']->now;$cap=$f['capability'];
        $delayed=new NativeLifecycle($f['settings'],$f['server'],$f['repository'],$f['loop'],null,
            static function()use(&$reads,$issued,$cap,$f){
                ++$reads;
                if($reads===3){$cap['heartbeat_unix']=$issued+301;writeRecord($f['dir'].'/bot-control/capability.json',$cap);}
                return $issued+($reads>3?301:0);
            });
        refuses(fn()=>$delayed->request('start'));
        check(is_file($f['dir'].'/bot-control/pending.json')&&!file_exists($f['dir'].'/bot-control/request.json')&&count(glob($f['dir'].'/bot-control/request-*.tmp'))===1,'Expired request was published or lost evidence');++$cases;
        $f=$fixture();$reads=0;$issued=$f['wall']->now;$cap=$f['capability'];
        $delayed=new NativeLifecycle($f['settings'],$f['server'],$f['repository'],$f['loop'],null,
            static function()use(&$reads,$issued,$cap,$f){
                if(++$reads===3){$cap['epoch']=str_repeat('f',32);writeRecord($f['dir'].'/bot-control/capability.json',$cap);}
                return $issued;
            });
        refuses(fn()=>$delayed->request('start'));
        check(is_file($f['dir'].'/bot-control/pending.json')&&!file_exists($f['dir'].'/bot-control/request.json'),'Changed capability was published or lost evidence');++$cases;
        $f=$fixture();$promise=$f['adapter']->request('start');$result=$complete($f,'start');$publishResult($f,$result);file_put_contents($f['dir'].'/runs/owned/civ13.dmb','changed after request');$success=null;$failure=null;$await($promise,$f,$success,$failure);check($failure!==null,'Build replacement during operation accepted');++$cases;
        $f=$fixture();$promise=$f['adapter']->request('start');$request=json_decode(file_get_contents($f['dir'].'/bot-control/request.json'),true);unlink($f['dir'].'/bot-control/request.json');file_put_contents($f['dir'].'/bot-control/result-'.$request['operation'].'.json','malformed');$success=null;$failure=null;$await($promise,$f,$success,$failure);check($failure!==null,'Malformed result accepted');++$cases;
        $f=$fixture();file_put_contents($f['dir'].'/bot-control/pending.json','interrupted-before-publication');refuses(fn()=>$f['adapter']->request('start'));check(!file_exists($f['dir'].'/bot-control/request.json'),'Interrupted publication was replayed');++$cases;
        $f=$fixture();$f['loop']->failRegistration=true;refuses(fn()=>$f['adapter']->request('start'));check(is_file($f['dir'].'/bot-control/pending.json')&&is_file($f['dir'].'/bot-control/request.json'),'Post-publication interruption lost evidence');$other=new NativeLifecycle($f['settings'],$f['server'],$f['repository'],new NativeLoop());refuses(fn()=>$other->request('start'));++$cases;
        // Genuine concurrent PHP writers: exactly one durable packet, the loser cannot replace it.
        $f=$fixture();writeRecord($f['dir'].'/writer-fixture.json',array_intersect_key($f,array_flip(['settings','server','repository'])));$children=[];
        try {
            foreach(['a','b'] as $id) {
                $environment=array_filter(['SystemRoot'=>getenv('SystemRoot'),'LD_LIBRARY_PATH'=>getenv('LD_LIBRARY_PATH'),'PHPRC'=>getenv('PHPRC'),'PHP_INI_SCAN_DIR'=>getenv('PHP_INI_SCAN_DIR'),'TEMP'=>sys_get_temp_dir(),'TMP'=>sys_get_temp_dir()],static fn($value)=>$value!==false);
                $child=proc_open([PHP_BINARY,__DIR__.'/native-lifecycle-writer.php',realpath($argv[1]??__DIR__.'/../../vendor/autoload.php'),$f['dir'],$id],
                    [0=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','r'],1=>['file',$f['dir'].'/writer-'.$id.'.out','w'],2=>['file',$f['dir'].'/writer-'.$id.'.err','w']],$pipes,$f['dir'],$environment,['bypass_shell'=>true,'create_new_console'=>false]);
                check(is_resource($child),'Inert writer child unavailable');$children[]=$child;
            }
            $deadline=microtime(true)+5;while(!is_file($f['dir'].'/ready-a')||!is_file($f['dir'].'/ready-b')){if(microtime(true)>$deadline){throw new RuntimeException('Inert writers not ready');}usleep(1000);clearstatcache();}
            file_put_contents($f['dir'].'/writers-go','go');$codes=[];foreach($children as $i=>$child){$codes[]=proc_close($child);unset($children[$i]);}sort($codes);check($codes===[0,75],'Concurrent publication did not admit exactly one writer');
            $pending=file_get_contents($f['dir'].'/bot-control/pending.json');check($pending===file_get_contents($f['dir'].'/bot-control/request.json'),'Concurrent packet overwritten or corrupted');
            $transition=fopen($f['dir'].'/bot-control/adapter.lock','c+b');check(flock($transition,LOCK_EX|LOCK_NB),'Exited writer retained transition lock');flock($transition,LOCK_UN);fclose($transition);++$cases;
        } finally {foreach($children as $child){if(is_resource($child)){proc_terminate($child);proc_close($child);}}}

        // Exercise actual GameServer entry points before any legacy file, shell, timer or OOC access.
        $old=getenv('CIVILIZATIONBOT_PROFILE');putenv('CIVILIZATIONBOT_PROFILE=offline-fixture');
        try {
            foreach (['Host'=>'start','Kill'=>'save-stop','Restart'=>'restart'] as $method=>$action) {
                $f=$fixture();if($action!=='start'){$cap=$f['capability'];$cap['process']=$f['process'];writeRecord($f['dir'].'/bot-control/capability.json',$cap);}
                $server=(new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();(new ReflectionProperty($server,'nativeLifecycle'))->setValue($server,$f['adapter']);
                $message=new NativeMessage();$promise=$server->$method($message);check($message->reactions===[],'GameServer announced queued success');$result=$complete($f,$action);$publishResult($f,$result);$success=null;$failure=null;$await($promise,$f,$success,$failure);check($failure===null&&$success===$result&&$message->reactions===['👍'],'GameServer failed bound native route');++$cases;
            }
            $server=(new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();
            foreach(['Host','Kill','Restart'] as $method){refuses(fn()=>$server->$method());}++$cases;
        } finally {$old===false?putenv('CIVILIZATIONBOT_PROFILE'):putenv('CIVILIZATIONBOT_PROFILE='.$old);}
        echo 'PASS '.$cases." native lifecycle controls: fixed spool, source/profile/build pins, fail-closed routing, serialization, owned identity, save-safe receipts and persistent unknown outcomes; no native process\n";
    } finally {
        // Only this test's resolved temporary root is eligible for recursive cleanup.
        $resolved=realpath($root);$temporary=realpath(sys_get_temp_dir());
        if ($resolved!==false&&str_starts_with($resolved,$temporary.DIRECTORY_SEPARATOR)&&basename($resolved)===basename($root)) {
            $remove=static function(string $directory)use(&$remove):void{foreach(new DirectoryIterator($directory)as$file){if($file->isDot()){continue;}$file->isDir()&&!$file->isLink()?$remove($file->getPathname()):unlink($file->getPathname());}rmdir($directory);};$remove($resolved);
        }
    }
}
