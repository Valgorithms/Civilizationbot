<?php
declare(strict_types=1);
// ShardRegionControl with the real AsyncProcess worker and a stand-in script
// that answers like Civ13's scripts/shard-group/shard_group.py. No game, no Discord.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../src/Civ13/Local/AsyncProcess.php';
require_once __DIR__.'/../../src/Civ13/Local/GaiaHostControl.php';
require_once __DIR__.'/../../src/Civ13/Local/ShardRegionControl.php';
use Civ13\Local\AsyncProcess;
use Civ13\Local\ShardRegionControl;
use React\EventLoop\StreamSelectLoop;
function shardCheck(bool $ok, string $what): void { if (!$ok) { throw new RuntimeException('shard_group contract failed: '.$what); } }
$root = sys_get_temp_dir().'/bot-shard-region-'.bin2hex(random_bytes(8)); mkdir($root, 0700); mkdir($root.'/tmp', 0700);
try {
    // The stand-in reads "--config FILE ACTION --region=ID" and answers from FILE, keyed "action:region".
    $script = $root.'/shard_group.php';
    file_put_contents($script, '<?php $args = array_slice($argv, 1); if (($args[0] ?? null) === "--") { array_shift($args); }'
        .' $config = $args[1]; $action = $args[2]; $region = substr($args[3] ?? "", 9); $c = json_decode(file_get_contents($config), true);'
        .' file_put_contents($config.".log", implode(" ", $args).PHP_EOL, FILE_APPEND);'
        .' $answer = $c[$action.":".$region] ?? $c[$action] ?? "{\"ok\":false,\"refused\":\"no answer\"}";'
        .' echo $answer; exit(str_contains($answer, "\"ok\":false") ? 1 : 0);');
    $config = $root.'/tdm.json';
    $operation = str_repeat('b', 32);
    $answers = [
        // One region's status: Barracks awake with 4 players, battle awake in a round with 9.
        'status:barracks' => json_encode(['ok' => true, 'group' => 'tdm', 'regions' => [
            'barracks' => ['port' => 1714, 'listening' => true, 'state' => 'awake', 'busy' => false, 'kind' => 'BARRACKS', 'name' => 'Barracks', 'built' => true,
                'world' => ['ready' => true, 'clients' => 4, 'error' => null], 'operation' => ['action' => 'start', 'phase' => 'done', 'error' => null],
                'region_view' => ['players' => 4, 'map' => 'Barracks', 'peers' => ['battle' => 'UP']]]]]),
        'status:battle' => json_encode(['ok' => true, 'group' => 'tdm', 'regions' => [
            'battle' => ['port' => 1715, 'listening' => true, 'state' => 'awake', 'busy' => false, 'kind' => 'BATTLE', 'name' => 'Battle <@&1>', 'built' => true,
                'world' => ['ready' => true, 'clients' => 9, 'error' => null], 'operation' => null]]]),
        'start:battle' => json_encode(['ok' => false, 'refused' => ['battle' => 'the server is already running']]),
        'stop:battle' => json_encode(['ok' => true, 'accepted' => ['battle' => ['accepted' => true, 'action' => 'stop', 'operation' => $operation]], 'refused' => []]),
        // An answer for another action is not acceptance.
        'restart:battle' => json_encode(['ok' => true, 'accepted' => ['battle' => ['accepted' => true, 'action' => 'stop', 'operation' => $operation]], 'refused' => []]),
        'kill:battle' => json_encode(['ok' => true, 'done' => ['battle' => ['killed' => true, 'pid' => 4242, 'reason' => 'forced stop', 'released_locks' => []]], 'refused' => []]),
        // Accepted for another region only: not this region's acceptance.
        'update:battle' => json_encode(['ok' => true, 'accepted' => ['barracks' => ['accepted' => true, 'action' => 'update', 'operation' => $operation]], 'refused' => []]),
        'restart:barracks' => 'not json',
    ];
    file_put_contents($config, json_encode($answers));
    $loop = new StreamSelectLoop();
    // python -I -B <script> --config <file> <action> ...: PHP takes the same argv shape after a '--'.
    $launcher = $root.'/python.php';
    file_put_contents($launcher, '<?php ');
    $executor = new AsyncProcess($loop, $root.'/tmp', static fn ($argv, $descriptors, $env) => proc_open(
        array_merge([PHP_BINARY, $argv[3], '--'], array_slice($argv, 4)), $descriptors, $pipes, null, $env, ['bypass_shell' => true]));
    $battle = new ShardRegionControl($launcher, $script, $config, 'battle', $executor);
    $barracks = new ShardRegionControl($launcher, $script, $config, 'barracks', $executor);
    $run = static function (ShardRegionControl $control, string $action) use ($loop): array {
        $value = null; $error = null;
        $control->request($action)->then(static function ($v) use (&$value) { $value = $v; }, static function ($e) use (&$error) { $error = $e; });
        $loop->run();
        return [$value, $error];
    };
    [$status, $error] = $run($battle, 'status');
    shardCheck($error === null && $status['world']['clients'] === 9 && $status['port'] === 1715, 'battle status is its own region: '.json_encode($status));
    $line = ShardRegionControl::describe($status);
    shardCheck($line === 'Battle 1: Server port is open. World ready. 9 players.', 'status line: '.$line);
    [$status, $error] = $run($barracks, 'status');
    $line = ShardRegionControl::describe($status);
    shardCheck($error === null && $status['world']['clients'] === 4 && $line === 'Barracks: Server port is open. World ready. 4 players. Last action: start (done). Map: Barracks.', 'barracks status: '.$line);
    [$value, $error] = $run($battle, 'start');
    shardCheck($value === null && $error instanceof RuntimeException && $error->getMessage() === 'Refused: the server is already running', 'refusal text: '.($error?->getMessage() ?? ''));
    [$value, $error] = $run($battle, 'stop');
    shardCheck($error === null && $value['accepted'] === true && $value['action'] === 'stop' && $value['operation'] === $operation && $value['region'] === 'battle', 'stop accepted');
    [$value, $error] = $run($battle, 'restart');
    shardCheck($error instanceof UnexpectedValueException, 'an answer for another action is not acceptance');
    [$value, $error] = $run($battle, 'kill');
    shardCheck($error === null && $value['accepted'] === true && $value['action'] === 'kill' && $value['pid'] === 4242, 'forced stop');
    [$value, $error] = $run($battle, 'update');
    shardCheck($error instanceof UnexpectedValueException, 'acceptance for another region is not this region\'s');
    [$value, $error] = $run($barracks, 'restart');
    shardCheck($error instanceof UnexpectedValueException && str_contains($error->getMessage(), 'no JSON'), 'garbage output');
    $log = file($config.'.log', FILE_IGNORE_NEW_LINES);
    shardCheck($log[0] === '--config '.$config.' status --region=battle', 'status names only this region: '.$log[0]);
    shardCheck($log[2] === '--config '.$config.' start --region=battle', 'actions name only this region: '.$log[2]);
    $refused = false;
    try { $battle->request('epoch'); } catch (InvalidArgumentException) { $refused = true; }
    shardCheck($refused, 'unsupported action');
    foreach ([['python', $script, $config, 'battle'], [$launcher, $root.'/missing.py', $config, 'battle'], [$launcher, $script, $config, 'Battle; rm -rf'], [$launcher, $script, $config, '']] as $args) {
        $refused = false;
        try { new ShardRegionControl($args[0], $args[1], $args[2], $args[3], $executor); } catch (InvalidArgumentException) { $refused = true; }
        shardCheck($refused, 'bad paths or region id: '.json_encode($args));
    }
    // GameServer routing: Kill uses the configured kill action, and an unusable shard_group refuses instead of launching DreamDaemon.
    $calls = [];
    $fake = static function (array $argv, int $seconds) use (&$calls): React\Promise\PromiseInterface {
        $calls[] = $argv[6].' '.$argv[7];
        $action = $argv[6];
        $answer = $action === 'kill' ? ['ok' => true, 'done' => ['battle' => ['killed' => true, 'pid' => 1]], 'refused' => []]
            : ['ok' => true, 'accepted' => ['battle' => ['accepted' => true, 'action' => $action, 'operation' => str_repeat('c', 32)]], 'refused' => []];
        return React\Promise\resolve(['exit_code' => 0, 'stdout' => json_encode($answer)]);
    };
    $server = (new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($server, 'gaiaHost'))->setValue($server, new ShardRegionControl($launcher, $script, $config, 'battle', $fake));
    $outcome = static function (React\Promise\PromiseInterface $promise): array { $value = null; $error = null; $promise->then(static function ($v) use (&$value) { $value = $v; }, static function ($e) use (&$error) { $error = $e; }); return [$value, $error]; };
    [$value] = $outcome($server->Kill(null, false));
    shardCheck(($value['action'] ?? null) === 'stop' && $value['outcome'] === 'accepted', 'Kill saves and stops by default');
    (new ReflectionProperty($server, 'shardKillAction'))->setValue($server, 'kill');
    [$value] = $outcome($server->Kill(null, false));
    shardCheck(($value['action'] ?? null) === 'kill', 'Kill uses the configured forced stop');
    [$value] = $outcome($server->Restart(null, false));
    [$update] = $outcome($server->Update());
    shardCheck(($value['action'] ?? null) === 'restart' && ($update['action'] ?? null) === 'update', 'Restart and Update reach the region');
    shardCheck($calls === ['stop --region=battle', 'kill --region=battle', 'restart --region=battle', 'update --region=battle'], 'GameServer argv: '.json_encode($calls));
    $broken = (new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($broken, 'hostUnavailable'))->setValue($broken, 'shard_group needs existing absolute python, script and config paths');
    foreach (['Host' => [], 'Kill' => [null, false], 'Restart' => [null, false], 'Update' => [], 'HostStatus' => []] as $method => $args) {
        [$value, $error] = $outcome($broken->$method(...$args));
        shardCheck($value === null && $error instanceof RuntimeException && str_starts_with($error->getMessage(), 'shard_group control unavailable'), $method.' did not refuse an unusable shard_group');
    }
    echo "PASS shard_group adapter: region status, refusal text, acceptance binding, forced stop, garbage output, argv shape, path checks, GameServer routing and refusal\n";
} finally {
    foreach (array_merge(glob($root.'/tmp/*/*') ?: [], glob($root.'/tmp/*') ?: [], glob($root.'/*') ?: []) as $path) { is_dir($path) ? @rmdir($path) : @unlink($path); }
    @rmdir($root.'/tmp'); @rmdir($root);
}
