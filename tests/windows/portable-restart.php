<?php
declare(strict_types=1);
// Actual restart dispatch, temporary marker files, fake shutdown; no bootstrap or network.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
use Civ13\Civ13;
use Civ13\PortableProcess;
use React\Promise\PromiseInterface;
function checkRestart(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function settledRestart(PromiseInterface $promise, bool $reject = false): void {
    $settled = false;
    $promise->then(
        static function ($value) use (&$settled, $reject): void { $settled = !$reject && $value === null; },
        static function (Throwable $error) use (&$settled, $reject): void { $settled = $reject && $error instanceof RuntimeException; }
    );
    checkRestart($settled, 'Unexpected restart promise result');
}
class OfflinePortableRestart extends Civ13 {
    public int $stops = 0;
    public bool $failStop = false;
    public function stop(bool $closeLoop = true): void {
        checkRestart(is_file($this->portable_state_dir.'/reload.request'), 'Shutdown happened before reload intent');
        if ($this->failStop) { throw new RuntimeException('Injected shutdown failure'); }
        $this->stops++;
    }
    public function __destruct() {}
}
function restartProbe(string $state): OfflinePortableRestart {
    // Policy and process launcher deliberately uninitialized: any legacy dispatch fails.
    $bot = (new ReflectionClass(OfflinePortableRestart::class))->newInstanceWithoutConstructor();
    $bot->portable_state_dir = $state;
    return $bot;
}
$dir = sys_get_temp_dir().'/portable-restart-'.bin2hex(random_bytes(8));
mkdir($dir);
$oldProfile = getenv('CIVILIZATIONBOT_PROFILE');
putenv('CIVILIZATIONBOT_PROFILE=offline-portable-restart');
try {
    $bot = restartProbe($dir);
    settledRestart($bot->restart());
    settledRestart($bot->restart());
    checkRestart($bot->stops === 1, 'Duplicate restart stopped twice');
    checkRestart(PortableProcess::consumeReload($dir) && !PortableProcess::consumeReload($dir), 'Supervisor intent was not one-use');
    settledRestart($bot->restart());
    checkRestart($bot->stops === 1 && !is_file($dir.'/reload.request'), 'Duplicate restart recreated consumed intent');
    try { $bot->restart('arbitrary.php'); throw new LogicException('Portable entry override accepted'); }
    catch (InvalidArgumentException) {}

    // A prior reloadconfig request and restart share one marker.
    PortableProcess::requestReload($dir);
    $existing = restartProbe($dir);
    settledRestart($existing->restart());
    checkRestart($existing->stops === 1 && PortableProcess::consumeReload($dir), 'Existing intent was not reusable');

    PortableProcess::requestStop($dir);
    $stopping = restartProbe($dir);
    settledRestart($stopping->restart());
    checkRestart($stopping->stops === 0 && !is_file($dir.'/reload.request') && is_file($dir.'/stop.request'), 'Pending stop lost precedence');
    // Simulate the supervisor decision for a stop arriving after restart intent.
    PortableProcess::requestReload($dir);
    settledRestart($stopping->restart());
    $reload = PortableProcess::consumeReload($dir) && !is_file($dir.'/stop.request');
    checkRestart(!$reload && $stopping->stops === 0, 'Stop plus reload caused relaunch');
    unlink($dir.'/stop.request');

    foreach (['', 'relative-state', $dir.'/missing'] as $state) {
        $invalid = restartProbe($state);
        settledRestart($invalid->restart(), true);
        checkRestart($invalid->stops === 0, 'Invalid state stopped the bot');
    }
    // Deterministic Windows/POSIX write failure without changing system permissions.
    mkdir($dir.'/reload.request');
    $failed = restartProbe($dir);
    settledRestart($failed->restart(), true);
    checkRestart($failed->stops === 0, 'Marker write failure stopped the bot');
    rmdir($dir.'/reload.request');
    settledRestart($failed->restart());
    checkRestart($failed->stops === 1 && PortableProcess::consumeReload($dir), 'Marker failure prevented retry');

    $retry = restartProbe($dir);
    $retry->failStop = true;
    try { $retry->restart(); throw new LogicException('Shutdown failure swallowed'); }
    catch (RuntimeException $error) { checkRestart($error->getMessage() === 'Injected shutdown failure', 'Unexpected shutdown error'); }
    $retry->failStop = false;
    settledRestart($retry->restart());
    checkRestart($retry->stops === 1 && PortableProcess::consumeReload($dir), 'Shutdown failure prevented retry');
    echo "PASS portable restart: intent before shutdown, one-use supervisor marker, duplicate suppression, stop precedence, no entry override/spawn, state failure and retry\n";
} finally {
    $oldProfile === false ? putenv('CIVILIZATIONBOT_PROFILE') : putenv('CIVILIZATIONBOT_PROFILE='.$oldProfile);
    foreach (glob($dir.'/*') as $path) { is_dir($path) ? rmdir($path) : unlink($path); }
    rmdir($dir);
}
