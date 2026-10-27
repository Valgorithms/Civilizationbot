<?php
declare(strict_types=1);
// Real bootstrap through final options assembly; only external-effect dependencies are inert fixtures.
namespace Monolog { class Level { public const Debug=100; } class Logger { public function __construct(...$args) {} public function info(...$args):void {} } }
namespace Monolog\Formatter { class LineFormatter { public function __construct(...$args) {} } }
namespace Monolog\Handler { class StreamHandler { public function __construct(...$args) {} public function setFormatter($formatter) { return $this; } } }
namespace Discord { class Discord { public function __construct(array $options) {} } class Stats { public static function new($discord) { return new self(); } } }
namespace Discord\WebSockets { class Intents { public static function getAllIntents() { return 0; } } }
namespace React\Http { class Browser { public function __construct(...$args) {} } }
namespace React\Filesystem { class Factory { public static function create() { return new \stdClass(); } } }
namespace React\EventLoop { class Loop { public static function get() { return new \stdClass(); } public static function addPeriodicTimer(...$args) { return new \stdClass(); } } }
namespace React\Promise { function set_rejection_handler($handler): void {} }
namespace Civ13 {
    function gethostbyname(...$args): never { throw new \RuntimeException('Unexpected bootstrap DNS'); }
    function exec(...$args): never { throw new \RuntimeException('Unexpected bootstrap process'); }
    function proc_open(...$args): never { throw new \RuntimeException('Unexpected bootstrap process'); }
    function shell_exec(...$args): never { throw new \RuntimeException('Unexpected bootstrap shell'); }
}
namespace {
    final class BootstrapReached extends RuntimeException {}
    function check(bool $ok): void { if (!$ok) { throw new RuntimeException('Bootstrap fixture assertion failed'); } }
    $negative=in_array('--negative-control',$argv,true);
    // Disabled admin sync must not require or autoload its optional implementation.
    $adminSyncAutoloads=0;
    $rejectAdminSync=static function(string $class) use (&$adminSyncAutoloads): void {
        if ($class === 'Civ13\\AdminFileSync') {
            ++$adminSyncAutoloads;
            throw new RuntimeException('Disabled admin sync attempted autoload');
        }
    };
    spl_autoload_register($rejectAdminSync);
    $root=sys_get_temp_dir().'/portable-bootstrap-'.bin2hex(random_bytes(8));
    mkdir($root);mkdir($root.'/vendor');mkdir($root.'/src');mkdir($root.'/src/Civ13');
    $previous=getcwd();$previousProfile=getenv('CIVILIZATIONBOT_PROFILE');
    try {
        file_put_contents($root.'/vendor/autoload.php','<?php return 1;');
        foreach(['PortableProfile','PortableProcess'] as $class) { copy(__DIR__.'/../../src/Civ13/'.$class.'.php',$root.'/src/Civ13/'.$class.'.php'); }
        // Git/network admission is covered by default-branch.php; this fixture
        // isolates subsequent bootstrap option assembly with synthetic inputs.
        file_put_contents($root.'/src/Civ13/DefaultBranchGuard.php', '<?php namespace Civ13; final class DefaultBranchGuard { public static function verify(string $root): array { $GLOBALS["bootstrap_guard_seen"] = true; return []; } }');
        file_put_contents($root.'/variable_functions.php','<?php $status_changer_timer = static function () {}; $status_changer_random = static function () {};');
        file_put_contents($root.'/separate.env',"TOKEN=synthetic-bootstrap-only\n");
        $server=['basedir'=>$root,'ip'=>'127.0.0.1','port'=>1714,'name'=>'fixture','host'=>'fixture','enabled'=>false];
        foreach(['discussion','playercount','ooc','lobby','asay','ic','transit','adminlog','debug','garbage','runtime','attack'] as $key){$server[$key]='';}
        $profile=['schema'=>'civilizationbot.portable.v1','root'=>$root,'state_dir'=>$root,'env_file'=>$root.'/separate.env','gitdir'=>$root,'http_port'=>25565,'options'=>['channel_ids'=>[],'role_ids'=>[]],'servers'=>['test'=>$server]];
        file_put_contents($root.'/profile.json',json_encode($profile,JSON_THROW_ON_ERROR));
        putenv('CIVILIZATIONBOT_PROFILE='.$root.'/profile.json');
        // This fresh CLI uses only a synthetic environment; no real credential file is read.
        putenv('TOKEN');putenv('CIV_TOKEN');putenv('WEBAPI_TOKEN');
        $source=file_get_contents(__DIR__.'/../../bot.php');
        if($negative){
            $source=str_replace('if ($portable === null) { // Production server defaults only.','if (true) { // Original unconditional production defaults.',$source,$count);
            check($count===1);
        }
        $boundary='$options = array_merge($options, $hidden_options);';
        $source=str_replace($boundary,$boundary.' $GLOBALS["bootstrap_options"]=$options; $GLOBALS["bootstrap_civ14"]=$civ14_server_settings; throw new \\BootstrapReached();',$source,$count);
        check($count===1);file_put_contents($root.'/bot.php',$source);
        set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
        try { require $root.'/bot.php'; throw new RuntimeException('Bootstrap boundary not reached'); }
        catch(BootstrapReached){
            check(!$negative && ($GLOBALS['bootstrap_guard_seen'] ?? false));$options=$GLOBALS['bootstrap_options'];
            check($adminSyncAutoloads===0 && !class_exists('Civ13\\AdminFileSync',false));
            check($options['admin_file_sync']===['enabled'=>false]);
            check($options['http_whitelist']===['127.0.0.1'] && $options['http_port']===25565);
            check($options['channel_ids']===[] && $options['role_ids']===[] && $options['functions']===[]);
            check($options['http_key']==='' && $options['civ_token']==='' && $options['verify_url']==='');
            check(array_keys($options['civ13_server_settings'])===['test'] && $GLOBALS['bootstrap_civ14']===[]);
            check(!isset($civ13_ip,$vzg_ip,$val_ip));
            echo "PASS real portable bootstrap options with warnings fatal, inert dependencies, no production defaults/DNS/process/socket or live environment\n";
        } catch(ErrorException $error){
            if(!$negative || !str_contains($error->getMessage(),'Undefined variable $civ13_ip')){throw $error;}
            echo "PASS negative control detects original portable production-IP warning before options replacement\n";
        }
    } finally {
        spl_autoload_unregister($rejectAdminSync);
        restore_error_handler();chdir($previous);
        putenv($previousProfile===false?'CIVILIZATIONBOT_PROFILE':'CIVILIZATIONBOT_PROFILE='.$previousProfile);
        if(isset($portableLock)&&is_resource($portableLock)){fclose($portableLock);}
        $resolved=str_replace('\\','/',realpath($root));
        $remove=static function(string $path) use (&$remove,$resolved):void{
            $actual=str_replace('\\','/',realpath($path));
            if($actual!==$resolved&&!str_starts_with($actual,$resolved.'/')){throw new RuntimeException('Fixture cleanup path escaped');}
            foreach(new DirectoryIterator($path) as $entry){if($entry->isDot())continue;$entry->isDir()?$remove($entry->getPathname()):unlink($entry->getPathname());}rmdir($path);
        };$remove($root);
    }
}
