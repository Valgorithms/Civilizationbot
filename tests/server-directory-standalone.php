<?php

declare(strict_types=1);

require $argv[1] ?? __DIR__.'/../vendor/autoload.php';

use Civ13\ServerDirectory\Directory;
use Civ13\ServerDirectory\Standalone;
use React\Http\Message\ServerRequest;
use function React\Promise\resolve;

function standaloneCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function standaloneReject(callable $callback): void { try { $callback(); } catch (Throwable) { return; } throw new RuntimeException('Unsafe standalone config accepted'); }

$now = 1800000000; $calls = [];
$probe = static function ($host, $port, $publicOnly) use (&$calls) {
    $calls[] = [$host, $port, $publicOnly];
    return resolve(['players' => 0, 'map_name' => 'Gaia', 'map_gamemode' => 'Nomads', 'round_duration_in_ticks' => 0]);
};
$config = ['listen_port' => 25113, 'targets' => [['id' => 'fixture', 'name' => 'Fixture', 'host' => 'civ13.com', 'port' => 24017, 'engine' => 'BYOND']]];
$service = new Standalone($config, $probe, static function () use (&$now) { return $now; });
$request = static fn ($method = 'GET', $suffix = '?provider=civ13.com') => new ServerRequest($method, 'https://civ13.com'.Directory::PATH.$suffix);
standaloneCheck($service->enabled && $service->address() === '127.0.0.1:25113', 'Listener is not fixed loopback');
standaloneCheck($service->handle($request())->getStatusCode() === 503 && $calls === [], 'Construction/request triggered probes');
$service->directory->refresh();
standaloneCheck($calls === [['civ13.com', 24017, true]], 'Standalone bypassed public-only status target policy');
standaloneCheck($service->handle($request())->getStatusCode() === 200, 'Fresh standalone snapshot unavailable');
standaloneCheck($service->handle($request('GET', '?provider=valzargaming.com'))->getStatusCode() === 404, 'Unconfigured provider invented');
standaloneCheck($service->handle($request('POST'))->getStatusCode() === 405, 'Mutation method accepted');
standaloneCheck($service->handle(new ServerRequest('GET', 'http://civ13.com/restart'))->getStatusCode() === 404, 'Bot control route exposed');
for ($i = 5; $i < 600; $i++) { $service->handle($request()); }
standaloneCheck($service->handle($request('HEAD'))->getStatusCode() === 429 && (string) $service->handle($request('HEAD'))->getBody() === '', 'Bounded request budget/HEAD response failed');
$now += 60;
standaloneCheck($service->handle($request())->getStatusCode() === 503, 'Rate reset renewed stale observation');
$empty = new Standalone(array_replace($config, ['targets' => []]), $probe);
standaloneCheck(!$empty->enabled, 'Empty default inventory enabled listener');
foreach (['listen_host' => '0.0.0.0', 'autoload' => '/tmp/evil', 'token' => 'secret', 'managed' => ['pers']] as $field => $value) {
    standaloneReject(static fn () => new Standalone($config + [$field => $value], $probe));
}
foreach ([0, 80, 65536, '25113', false, 25113.0] as $port) { standaloneReject(static fn () => new Standalone(array_replace($config, ['listen_port' => $port]), $probe)); }
standaloneCheck(!class_exists(Civ13\Civ13::class, false) && !class_exists(Civ13\GameServer::class, false) && !class_exists(Discord\Discord::class, false), 'Directory-only composition loaded bot/game classes');
echo "PASS standalone directory: loopback-only, default-empty, public-only probes, no bot bootstrap/control routes, bounded requests and stale fail-closed responses.\n";

// The CLI rejects bad inventory and exits for empty inventory before executing
// even a supplied autoloader. Only synthetic local files/processes are involved.
$temp = sys_get_temp_dir().'/civ13-directory-test-'.bin2hex(random_bytes(8));
mkdir($temp);
try {
    $marker = $temp.'/loaded';
    $autoload = $temp.'/autoload.php';
    file_put_contents($autoload, '<?php file_put_contents('.var_export($marker, true).', "unexpected");');
    foreach ([['listen_port' => 80, 'targets' => []], ['listen_port' => 25113, 'targets' => [['host' => 'evil.invalid']]], ['listen_port' => 25113, 'targets' => []]] as $index => $inventory) {
        $file = $temp.'/inventory.json';
        file_put_contents($file, json_encode($inventory, JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __DIR__.'/../scripts/directory/serve.php', $file, $autoload], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        standaloneCheck(is_resource($process), 'Synthetic CLI process unavailable');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        standaloneCheck(!file_exists($marker), 'Disabled/invalid inventory executed dependency autoload');
        standaloneCheck($exit === ($index === 2 ? 0 : 1), 'CLI inventory exit status differs');
        standaloneCheck(str_contains($output, $index === 2 ? 'inventory is empty' : 'phase: inventory'), 'Controlled startup phase/disabled diagnostic missing');
    }
} finally {
    foreach (glob($temp.'/*') as $file) { unlink($file); }
    rmdir($temp);
}
echo "PASS standalone CLI: invalid/default-empty inventory checked before autoload, controlled diagnostics, no external I/O.\n";
