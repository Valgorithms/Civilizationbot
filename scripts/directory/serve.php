<?php

declare(strict_types=1);

// Run a pinned copy, never the live bot entrypoint. No .env or secret bootstrap.
use Civ13\ServerDirectory\ByondStatusProbe;
use Civ13\ServerDirectory\Directory;
use Civ13\ServerDirectory\Standalone;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/directory/serve.php inventory.json /verified/vendor/autoload.php\n");
    exit(2);
}
$phase = 'inventory';
try {
    if (!is_file($argv[1]) || !is_readable($argv[1]) || filesize($argv[1]) > 32768) {
        throw new RuntimeException('Inventory unavailable');
    }
    $json = file_get_contents($argv[1], false, null, 0, 32769);
    if ($json === false || strlen($json) > 32768) { throw new RuntimeException('Inventory over bound'); }
    $config = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($config)) { throw new RuntimeException('Inventory must be an object'); }
    require_once __DIR__.'/../../src/Civ13/ServerDirectory/Directory.php';
    require_once __DIR__.'/../../src/Civ13/ServerDirectory/Standalone.php';
    Standalone::validateShape($config);
    new Directory([], [], static fn () => throw new LogicException('Validation cannot probe'), statusTargets: $config['targets']);
    if ($config['targets'] === []) { fwrite(STDERR, "Directory disabled: inventory is empty.\n"); exit(0); }
    $phase = 'autoload';
    $autoload = realpath($argv[2]);
    if ($autoload === false || !is_file($autoload) || basename($autoload) !== 'autoload.php') {
        throw new RuntimeException('Verified dependency autoloader unavailable');
    }
    $loader = require $autoload;
    if (!$loader instanceof Composer\Autoload\ClassLoader) { throw new RuntimeException('Composer loader required'); }
    // Prefer the pinned directory source even when reusing an older bot's vendor tree.
    $loader->addPsr4('Civ13\\ServerDirectory\\', __DIR__.'/../../src/Civ13/ServerDirectory', true);
    $phase = 'runtime';
    $loop = Loop::get();
    $connector = ByondStatusProbe::createConnector($loop, publicOnly: true);
    $service = new Standalone($config, new ByondStatusProbe($loop, $connector, $connector));
    $phase = 'bind';
    $socket = new SocketServer($service->address(), [], $loop);
    $server = new HttpServer($loop, $service->handle(...));
    $server->on('error', static fn () => fwrite(STDERR, "Directory HTTP error.\n"));
    $server->listen($socket);
    $phase = 'runtime';
    $refresh = static fn () => $service->directory->refresh()->then(null, static fn () => fwrite(STDERR, "Directory refresh failed.\n"));
    $refresh();
    $timer = $loop->addPeriodicTimer(Directory::REFRESH_SECONDS, $refresh);
    if (function_exists('pcntl_signal')) {
        $stop = static function () use ($loop, $socket, $timer): void {
            $socket->close();
            $loop->cancelTimer($timer);
            $loop->stop();
        };
        $loop->addSignal(SIGTERM, $stop);
        $loop->addSignal(SIGINT, $stop);
    }
    fwrite(STDERR, "Read-only directory listening on ".$service->address()."\n");
    $loop->run();
} catch (Throwable) {
    // Never dump config, dependency internals or raw game status into service logs.
    fwrite(STDERR, "Directory startup failed (phase: ".$phase."); check the reviewed deployment prerequisites.\n");
    exit(1);
}
