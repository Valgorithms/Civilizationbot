<?php
declare(strict_types=1);
// Explicit dependency source; never includes tests/bootstrap.php (which loads .env).
$dependency = $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader = require $dependency;
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
$loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14/', true);
final class OfflineDiscord extends Discord\Discord {
    public function commands(): array { return $this->application_commands ?? []; }
    public function __construct(private React\EventLoop\LoopInterface $offlineLoop) {}
    public function getLoop(): React\EventLoop\LoopInterface { return $this->offlineLoop; }
}
$cacheNegative=($argv[2] ?? null)==='--cache-negative-control';
if ($cacheNegative && PHP_OS_FAMILY === 'Windows') { echo "SKIP POSIX permission mutation on Windows\n"; return; }
$dir=sys_get_temp_dir().'/portable-constructor-'.bin2hex(random_bytes(8));mkdir($dir);
$previous=getcwd();chdir($dir);
// Disallow PHP stream HTTP too: disabling React DNS alone does not prevent it.
$blockedWrappers=[];
foreach (['http','https'] as $scheme) { if (in_array($scheme,stream_get_wrappers(),true)) { stream_wrapper_unregister($scheme);$blockedWrappers[]=$scheme; } }
// Runtime warnings must fail this contract instead of passing CI with failed cache writes.
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    if ($severity === E_WARNING) { throw new ErrorException($message, 0, $severity, $file, $line); }
    return false;
});
$negative=($argv[2] ?? null)==='--negative-control';
if ($negative || $cacheNegative) {
    $source=file_get_contents(__DIR__.'/../../src/Civ13/Civ13.php');
    $source=$cacheNegative ? str_replace('mkdir($this->filecache_path, 0755, true);','mkdir($this->filecache_path, 0664, true);',$source,$mutations) : str_replace('if ($this->verifier_server !== null) { new SS14Verifier($this); }','new SS14Verifier($this);',$source,$mutations);
    if ($mutations!==1) { throw new RuntimeException('Negative control not applied'); }
    file_put_contents($dir.'/Civ13.php',$source);
    $loader->addClassMap(['Civ13\\Civ13'=>$dir.'/Civ13.php']);
}
try {
    $loop=new React\EventLoop\StreamSelectLoop();
    $discord=new OfflineDiscord($loop);
    $logger=new Monolog\Logger('offline',[new Monolog\Handler\NullHandler()]);
    $options=['discord'=>$discord,'loop'=>$loop,'logger'=>$logger,'stats'=>(new ReflectionClass(Discord\Stats::class))->newInstanceWithoutConstructor(),'browser'=>new React\Http\Browser(new React\Socket\Connector(['dns'=>false],$loop)),'filesystem'=>new React\Filesystem\Fallback\Adapter(),'verify_url'=>'','functions'=>[],'channel_ids'=>[],'role_ids'=>[]];
    require_once __DIR__.'/../../src/Civ13/PortableProfile.php';
    $sample=json_decode(file_get_contents(__DIR__.'/../../config/examples/windows-test1714.json'), true, 16, JSON_THROW_ON_ERROR);
    $profileOptions=($negative || $cacheNegative) ? [] : $sample['options'];
    $options=array_merge($options, Civ13\PortableProfile::runtimeOptions(['options'=>$profileOptions, 'gitdir'=>$dir, 'state_dir'=>$dir]));
    try { $bot=new Civ13\Civ13($options,[],[],null); }
    catch (ErrorException $error) {
        if ($cacheNegative && str_contains($error->getMessage(),'Permission denied')) { echo "PASS POSIX cache mutation rejects failed production writes\n"; return; }
        throw $error;
    }
    catch (RuntimeException $error) {
        if ($negative && $error->getMessage()==='No SS14 verifier server set.') { echo "PASS negative control detects original unconditional service crash\n"; return; }
        throw $error;
    }
    if ($negative) { throw new RuntimeException('Original service crash was not detected'); }
    if ($bot->owner_id!=='' || $bot->technician_id!=='' || $bot->civ13_guild_id!=='' || $bot->filecache_path!==$dir.'/json/') { throw new RuntimeException('Portable privileged identity or state isolation failed'); }
    if ($bot->ss14verifier!==null || !isset($bot->httpServiceManager,$bot->messageServiceManager)) { throw new RuntimeException('Incomplete no-service construction'); }
    if (PHP_OS_FAMILY !== 'Windows' && (fileperms($bot->filecache_path) & 0100) === 0) { throw new RuntimeException('Cache lacks owner search permission'); }
    $cachePayload=['portable'=>true,'value'=>'cache round trip'];
    if (!Civ13\OSFunctions::VarSave($bot->filecache_path,'portable-contract.json',$cachePayload,$logger) || Civ13\OSFunctions::VarLoad($bot->filecache_path,'portable-contract.json',$logger)!==$cachePayload) { throw new RuntimeException('Actual cache write/read failed'); }
    if ($cacheNegative) { throw new RuntimeException('Broken POSIX directory permissions were not detected'); }
    new Civ13\Slash($bot);
    if (isset($discord->commands()['ss14']) || !isset($discord->commands()['ckey'])) { throw new RuntimeException('Dependent registration unavailable-mode mismatch'); }
    (new ReflectionMethod(Civ13\MessageServiceManager::class,'__generateGlobalMessageCommands'))->invoke($bot->messageServiceManager);
    if ($bot->messageServiceManager->messageHandler->offsetGet('civ13register','handlers')===null) { throw new RuntimeException('Control command was not registered'); }
    foreach (['civ14register','civ14unverify','verifyme'] as $command) {
        if ($bot->messageServiceManager->messageHandler->offsetGet($command,'handlers')!==null) { throw new RuntimeException('Unavailable SS14 message command registered'); }
    }
    (new ReflectionMethod(Civ13\HttpServiceManager::class,'__generateEndpoints'))->invoke($bot->httpServiceManager);
    $callback=$bot->httpServiceManager->httpHandler->offsetGet('/ss14verified','handlers');
    $response=$callback(new React\Http\Message\ServerRequest('GET','http://127.0.0.1/ss14verified'),'/ss14verified',false);
    if ($response->getStatusCode()!==503 || str_contains((string)$response->getBody(),'[]')) { throw new RuntimeException('Unavailable service reported successful empty data'); }
    echo "PASS actual Civ13 afterConstruct and slash registration without SS14 service; no loop run\n";
} finally {
    restore_error_handler();
    foreach ($blockedWrappers as $scheme) { stream_wrapper_restore($scheme); }
    chdir($previous);
    $remove=function($path) use (&$remove) { foreach(new DirectoryIterator($path) as $entry) { if($entry->isDot())continue;$entry->isDir()?$remove($entry->getPathname()):unlink($entry->getPathname()); }rmdir($path); };
    $remove($dir);
}

