<?php
declare(strict_types=1);
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../src/Civ13/Local/OpenDreamControl.php';
use Civ13\Local\OpenDreamControl;
use function React\Promise\resolve;
use function React\Promise\reject;
function expectReject(callable $call): void { try { $call(); } catch (Throwable) { return; } throw new RuntimeException('Expected rejection'); }
$root = sys_get_temp_dir().'/bot-od-'.bin2hex(random_bytes(8)); mkdir($root, 0700);
foreach (['python', 'control.py', 'map.dmm'] as $file) { file_put_contents($root.'/'.$file, 'synthetic'); }
$server = ['engine' => 'opendream', 'host' => '127.0.0.1', 'runtime_dir' => $root, 'gameplay_port' => 18131, 'topic_port' => 18141];
$native = ['engine' => 'opendream', 'runtime_dir' => $root, 'game_port' => 18131, 'topic_port' => 18141, 'maps' => ['TEST' => 'map.dmm']];
file_put_contents($root.'/profile.json', json_encode($native));
$calls = []; $response = ['ok' => true, 'engine' => 'opendream', 'running' => false, 'server_pid' => null, 'runtime_dir' => $root, 'game_port' => 18131, 'topic_port' => 18141];
$executor = static function ($argv, $timeout) use (&$calls, &$response) { $calls[] = [$argv, $timeout]; return resolve(['exit_code' => 0, 'stdout' => json_encode($response)]); };
$make = static fn ($executor, $enabled = true) => new OpenDreamControl($server, $root.'/python', $root.'/control.py', $root.'/profile.json', $executor, $enabled);
try {
    expectReject(fn () => $make($executor, false)->request('start'));
    if ($calls) { throw new RuntimeException('Disabled request executed'); }
    $adapter = $make($executor);
    foreach (['start', 'stop', 'restart', 'status'] as $action) { $adapter->request($action)->then(static function ($data) { if ($data['running'] !== false) { throw new RuntimeException('Process status upgraded to readiness'); } }); }
    $adapter->request('mapswap', 'TEST');
    if ($calls[4][0] !== [$root.'/python', $root.'/control.py', '--profile', $root.'/profile.json', 'mapswap', 'TEST']) { throw new RuntimeException('CLI contract differs'); }
    expectReject(fn () => $adapter->request('supervise'));
    expectReject(fn () => $adapter->request('mapswap', '../outside'));
    expectReject(fn () => $adapter->request('status', 'TEST'));
    $response['topic_port'] = 9999; $rejected = false;
    $adapter->request('status')->then(null, static function () use (&$rejected) { $rejected = true; });
    if (!$rejected) { throw new RuntimeException('Mismatched response accepted'); }
    $response = ['ok' => true, 'topic_port' => 18141, 'status' => ['name' => 'synthetic']];
    $topic = null; $adapter->request('topic-status')->then(static function ($data) use (&$topic) { $topic = $data; });
    if ($topic !== $response || isset($topic['running'])) { throw new RuntimeException('Topic and process observations confused'); }
    $response['topic_port'] = 18131; $rejected = false;
    $adapter->request('topic-status')->then(null, static function () use (&$rejected) { $rejected = true; });
    if (!$rejected) { throw new RuntimeException('Gameplay port accepted as Topic'); }
    $attempts = 0; $unknown = $make(static function () use (&$attempts) { $attempts++; return reject(new RuntimeException('Timeout unknown outcome')); });
    $unknown->request('restart')->then(null, static function () {});
    if ($attempts !== 1) { throw new RuntimeException('Uncertain mutation replayed'); }
    file_put_contents($root.'/profile.json', json_encode($native + ['changed' => true]));
    expectReject(fn () => $adapter->request('status'));
    echo "PASS OpenDream fixed argv, disabled/unsupported controls, map allowlist, response bindings, timeout no replay, changed profile\n";
} finally { foreach (glob($root.'/*') ?: [] as $file) { unlink($file); } rmdir($root); }
