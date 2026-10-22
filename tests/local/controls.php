<?php
declare(strict_types=1);
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../src/Civ13/Local/AsyncProcess.php';
require __DIR__.'/../../src/Civ13/Local/ControlEndpoint.php';
use Civ13\Local\AsyncProcess;
use Civ13\Local\ControlEndpoint;
use React\EventLoop\StreamSelectLoop;
use function React\Promise\resolve;
function verifyControl(bool $ok): void { if (!$ok) { throw new RuntimeException('Control contract failed'); } }
function controlReply($promise): array { $reply = null; $error = null; $promise->then(static function ($value) use (&$reply) { $reply = $value; }, static function ($value) use (&$error) { $error = $value; }); if ($error) { throw $error; } return $reply ?? throw new RuntimeException('Unresolved mock reply'); }
$root = sys_get_temp_dir().'/bot-controls-'.bin2hex(random_bytes(8)); mkdir($root, 0700);
try {
    $loop = new StreamSelectLoop(); $executor = new AsyncProcess($loop, $root); $result = null;
    $executor([PHP_BINARY, '-r', 'echo "synthetic";'], 2)->then(static function ($value) use (&$result) { $result = $value; }); $loop->run();
    verifyControl($result === ['exit_code' => 0, 'stdout' => 'synthetic']);
    foreach (['echo str_repeat("x",10000);', 'sleep(5);'] as $code) {
        $failed = false; $executor([PHP_BINARY, '-r', $code], 1)->then(null, static function () use (&$failed) { $failed = true; }); $loop->run(); verifyControl($failed);
    }
    verifyControl((glob($root.'/*') ?: []) === []);
    $adapter = new class { public int $calls = 0; public function request($action, $map) { $this->calls++; return resolve(['running' => false]); } };
    $key = str_repeat('k', 32); $route = new ControlEndpoint(['nomads' => $adapter], $key, true);
    foreach ([['POST','/v1/local/servers/nomads/start','wrong','127.0.0.1','{}',401], ['POST','/v1/local/servers/nomads/start','Bearer '.$key,'civ13.com','{}',401], ['GET','/v1/local/servers/nomads/status','Bearer '.$key,'127.0.0.1','{}',405], ['POST','/v1/local/servers/official/start','Bearer '.$key,'127.0.0.1','{}',404], ['POST','/v1/local/servers/nomads/start','Bearer '.$key,'127.0.0.1','{"command":"DreamDaemon"}',400]] as $case) {
        $expected = array_pop($case); verifyControl(controlReply($route->handle(...$case))[0] === $expected);
    }
    verifyControl($adapter->calls === 0);
    verifyControl(controlReply((new ControlEndpoint(['nomads' => $adapter], $key))->handle('POST','/v1/local/servers/nomads/start','Bearer '.$key,'127.0.0.1','{}'))[0] === 503); verifyControl($adapter->calls === 0);
    $reply = controlReply($route->handle('POST','/v1/local/servers/nomads/status','Bearer '.$key,'127.0.0.1','{}')); verifyControl($reply[0] === 200 && $reply[1]['view'] === 'process' && $reply[1]['data']['running'] === false);
    verifyControl(controlReply($route->handle('POST','/v1/local/servers/nomads/topic-status','Bearer '.$key,'127.0.0.1','{}'))[1]['view'] === 'topic');
    verifyControl($adapter->calls === 2);
    echo "PASS production argv worker, deadline/output bounds/cleanup; injected auth/local/gate/schema/routes, process versus Topic\n";
} finally { foreach (glob($root.'/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } } rmdir($root); }
