<?php
declare(strict_types=1);

namespace Civ13 {
    // Tripwires: a regression must fail without ever reaching the operating system.
    function proc_open(...$args): never { throw new \LogicException('Unexpected legacy proc_open'); }
    function popen(...$args): never { throw new \LogicException('Unexpected legacy popen'); }
    function exec(...$args): never { throw new \LogicException('Unexpected legacy exec'); }
    function proc_get_status(...$args): never { throw new \LogicException('Unexpected legacy process inspection'); }
}
namespace {
    require __DIR__.'/../../vendor/autoload.php';
    use Civ13\OSFunctions;
    final class OfflineMaintenanceMessage extends Discord\Parts\Channel\Message {
        public array $replies = [];
        public function __construct(?Discord\Discord $discord = null, array $attributes = [], bool $created = false) {}
        public function reply($message): React\Promise\PromiseInterface { $this->replies[] = $message; return React\Promise\resolve(null); }
        public function react($emoticon): React\Promise\PromiseInterface { throw new LogicException('Maintenance reported success with a reaction'); }
    }
    $previous = getenv('CIVILIZATIONBOT_PROFILE');
    $expected = 'Legacy shell operations are disabled in Windows/portable mode';
    try {
        // Native Windows is protected even without a profile; Unix default remains enabled.
        putenv('CIVILIZATIONBOT_PROFILE');
        if (OSFunctions::legacyShellAvailable() !== (PHP_OS_FAMILY !== 'Windows')) { throw new RuntimeException('Default platform policy changed'); }
        foreach (PHP_OS_FAMILY === 'Windows' ? ['', 'synthetic-profile'] : ['synthetic-profile'] as $profile) {
            putenv('CIVILIZATIONBOT_PROFILE='.$profile);
            foreach (['spawnChildProcess', 'execInBackground', 'restart'] as $method) {
                $rejection = null;
                OSFunctions::$method('never execute this')->then(
                    static function (): void { throw new LogicException('Legacy operation unexpectedly resolved'); },
                    static function (Throwable $error) use (&$rejection): void { $rejection = $error; }
                );
                if (!$rejection instanceof RuntimeException || $rejection->getMessage() !== $expected) { throw new RuntimeException('Missing legacy promise rejection: '.$method); }
            }
            $bot = (new ReflectionClass(Civ13\Civ13::class))->newInstanceWithoutConstructor();
            foreach (['UpdateDependencies', 'PullCivRepository', 'TypeSpess', 'Civ14Host'] as $name) {
                $class = 'Civ13\\MessageCommand\\Commands\\'.$name;
                $message = new OfflineMaintenanceMessage();
                (new $class($bot))($message, 'fixture', []);
                if ($message->replies !== ['This maintenance command is unavailable in Windows/portable mode.']) { throw new RuntimeException('Maintenance did not report unavailable: '.$name); }
            }
            foreach ([['killProcessOnPort', 1714], ['disown', null]] as [$method, $argument]) {
                try { OSFunctions::$method($argument); throw new LogicException('Legacy operation unexpectedly returned'); }
                catch (RuntimeException $error) { if ($error->getMessage() !== $expected) { throw $error; } }
            }
        }
        echo "PASS Windows/portable shell guards reject before spawn, restart, process inspection or port-based termination\n";
    } finally {
        $previous === false ? putenv('CIVILIZATIONBOT_PROFILE') : putenv('CIVILIZATIONBOT_PROFILE='.$previous);
    }
}
