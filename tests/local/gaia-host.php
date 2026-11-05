<?php
declare(strict_types=1);
// GaiaHostControl with the real AsyncProcess worker and a stand-in host script
// that answers like Civ13's scripts/gaia-host/gaia_host.py. No game, no Discord.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../src/Civ13/Local/AsyncProcess.php';
require_once __DIR__.'/../../src/Civ13/Local/GaiaHostControl.php';
use Civ13\Local\AsyncProcess;
use Civ13\Local\GaiaHostControl;
use React\EventLoop\StreamSelectLoop;
function gaiaCheck(bool $ok, string $what): void { if (!$ok) { throw new RuntimeException('gaia_host contract failed: '.$what); } }
$root = sys_get_temp_dir().'/bot-gaia-host-'.bin2hex(random_bytes(8)); mkdir($root, 0700); mkdir($root.'/tmp', 0700);
try {
    // The stand-in reads the action from argv and the scripted answer from the config file.
    $script = $root.'/gaia_host.php';
    file_put_contents($script, '<?php $a = $argv[count($argv) - 1]; $c = json_decode(file_get_contents($argv[count($argv) - 2]), true);'
        .' file_put_contents($argv[count($argv) - 2].".log", implode(" ", array_slice($argv, 1)).PHP_EOL, FILE_APPEND);'
        .' echo $c[$a] ?? "{\"ok\":false,\"refused\":\"no answer\"}"; exit(str_contains($c[$a] ?? "false", "\"ok\":false") ? 1 : 0);');
    $config = $root.'/gaia-host.json';
    $answers = [
        'status' => json_encode(['ok' => true, 'port' => 1714, 'listening' => true, 'busy' => false, 'lifecycle_token' => true,
            'world' => ['ready' => true, 'clients' => 3, 'error' => null], 'operation' => ['action' => 'stop', 'phase' => 'done', 'error' => null]]),
        'start' => json_encode(['ok' => false, 'refused' => 'the server is already running']),
        'stop' => json_encode(['ok' => true, 'accepted' => true, 'action' => 'stop', 'operation' => str_repeat('a', 32)]),
        'restart' => json_encode(['ok' => true, 'accepted' => true, 'action' => 'stop', 'operation' => str_repeat('a', 32)]),
        'update' => 'not json',
    ];
    file_put_contents($config, json_encode($answers));
    $loop = new StreamSelectLoop();
    // python -I -B <script> --config <file> <action>: PHP takes the same argv shape after a '--'.
    $launcher = $root.'/python.php';
    file_put_contents($launcher, '<?php ');
    $executor = new AsyncProcess($loop, $root.'/tmp', static fn ($argv, $descriptors, $env) => proc_open(
        array_merge([PHP_BINARY, $argv[3], '--'], array_slice($argv, 4)), $descriptors, $pipes, null, $env, ['bypass_shell' => true]));
    $control = new GaiaHostControl($launcher, $script, $config, $executor);
    $run = static function (string $action) use ($control, $loop): array {
        $value = null; $error = null;
        $control->request($action)->then(static function ($v) use (&$value) { $value = $v; }, static function ($e) use (&$error) { $error = $e; });
        $loop->run();
        return [$value, $error];
    };
    [$status, $error] = $run('status');
    gaiaCheck($error === null && $status['world']['clients'] === 3, 'status answer');
    gaiaCheck(GaiaHostControl::describe($status) === 'Server port is open. World ready. 3 players. Last action: stop (done).', 'status line: '.GaiaHostControl::describe($status));
    $region = ['listening' => true, 'state' => 'awake', 'world' => ['ready' => true, 'clients' => 2],
        'regions' => [['id' => 'gaia', 'name' => 'Gaia', 'state' => 'awake', 'players' => 2], ['id' => 'deep', 'name' => 'The Deep <@&1>', 'state' => 'asleep', 'players' => null], 'junk']];
    gaiaCheck(GaiaHostControl::describe($region) === "Server port is open. World ready. 2 players.\nRegions: Gaia: awake, 2 players; The Deep 1: asleep.", 'region line: '.GaiaHostControl::describe($region));
    gaiaCheck(str_starts_with(GaiaHostControl::describe(['state' => 'cold', 'listening' => false]), 'Server is asleep (stopped'), 'cold line');
    [$value, $error] = $run('start');
    gaiaCheck($value === null && $error instanceof RuntimeException && $error->getMessage() === 'Refused: the server is already running', 'refusal text');
    [$value, $error] = $run('stop');
    gaiaCheck($error === null && $value['accepted'] === true && $value['operation'] === str_repeat('a', 32), 'stop accepted');
    [$value, $error] = $run('restart');
    gaiaCheck($error instanceof UnexpectedValueException, 'an answer for another action is not acceptance');
    [$value, $error] = $run('update');
    gaiaCheck($error instanceof UnexpectedValueException && str_contains($error->getMessage(), 'no JSON'), 'garbage output');
    $log = file($config.'.log', FILE_IGNORE_NEW_LINES);
    gaiaCheck($log[0] === '-- --config '.$config.' status', 'only the action reaches the script: '.$log[0]);
    $refused = false;
    try { $control->request('mapswap'); } catch (InvalidArgumentException) { $refused = true; }
    gaiaCheck($refused, 'unsupported action');
    foreach ([['python', $script, $config], [$launcher, $root.'/missing.py', $config]] as $paths) {
        $refused = false;
        try { new GaiaHostControl($paths[0], $paths[1], $paths[2], $executor); } catch (InvalidArgumentException) { $refused = true; }
        gaiaCheck($refused, 'relative or missing paths');
    }
    gaiaCheck(GaiaHostControl::describe(['listening' => false, 'operation' => ['action' => 'update', 'phase' => 'failed', 'error' => 'compile failed']])
        === 'Server is not running. Last action: update (failed): compile failed.', 'failure line');
    echo "PASS gaia_host adapter: status line, refusal text, acceptance binding, garbage output, argv shape, path checks\n";
} finally {
    foreach (array_merge(glob($root.'/tmp/*/*') ?: [], glob($root.'/tmp/*') ?: [], glob($root.'/*') ?: []) as $path) { is_dir($path) ? @rmdir($path) : @unlink($path); }
    @rmdir($root.'/tmp'); @rmdir($root);
}
