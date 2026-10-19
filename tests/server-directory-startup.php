<?php

declare(strict_types=1);

// Real GameServer construction and HTTP route registration, with I/O boundaries
// bypassed. No .env, bot startup, Discord initialization, DNS, sockets or files.
require $argv[1] ?? __DIR__.'/../vendor/autoload.php';

use Civ13\Civ13;
use Civ13\GameServer;
use Civ13\HttpHandler;
use Civ13\HttpServiceManager;
use Civ13\ServerDirectory\Directory;
use Discord\Discord;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use React\EventLoop\StreamSelectLoop;
use React\Http\Message\ServerRequest;

use function React\Promise\resolve;

function startupCheck(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

final class DirectoryStartupBot extends Civ13
{
    public array $deferred = [];
    public function __construct()
    {
        $this->loop = new StreamSelectLoop();
        $this->logger = new Logger('offline-directory', [new NullHandler()]);
        $this->discord = (new ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    }
    public function VarLoad(string $filename = ''): ?array { return []; }
    public function VarSave(string $filename, array $assoc_array = []): bool { return true; }
    public function deferUntilReady(callable $callback, ?string $function = null): void { $this->deferred[] = $callback; }
}

$bot = new DirectoryStartupBot();
$base = ['basedir' => __DIR__, 'name' => 'Fixture', 'ip' => '127.0.0.1', 'port' => '12345', 'host' => 'Fixture host', 'enabled' => true];
foreach (['discussion', 'playercount', 'ooc', 'lobby', 'asay', 'ic', 'transit', 'adminlog', 'debug', 'garbage', 'runtime', 'attack'] as $channel) { $base[$channel] = ''; }
foreach (['one', 'two', 'disabled', 'missing_directory'] as $index => $key) {
    $options = array_replace($base, ['key' => $key, 'port' => (string) (12345 + $index), 'enabled' => $key !== 'disabled']);
    if ($key === 'missing_directory') { $options['basedir'] = __DIR__.'/missing-'.bin2hex(random_bytes(8)); }
    new GameServer($bot, $options);
}
startupCheck(!$bot->ready && !isset($bot->httpServiceManager), 'Fixture started the bot or HTTP manager');
startupCheck(array_keys($bot->enabled_gameservers) === ['one', 'two'], 'Enabled registry was not populated synchronously, or disabled server leaked');
startupCheck(count($bot->gameservers) === 4 && count($bot->deferred) === 2, 'Registration was deferred with server timers');
foreach ($bot->enabled_gameservers as $server) {
    startupCheck($server instanceof GameServer && $server->enabled === true && $server->ready, 'Actual GameServer was not registered ready');
    startupCheck(is_string($server->ip) && is_string($server->port), 'GameServer target types differ');
}

// Guard the actual production orchestrator's ordering without starting its
// moderator/verifier/Discord side effects. Dynamic tests above use its real
// GameServer constructor; the following route call is the real manager method.
$method = new ReflectionMethod(Civ13::class, 'afterConstruct');
$lines = file($method->getFileName());
$source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
$serversAt = strpos($source, 'new GameServer($this, $gameserver_options)');
$httpAt = strpos($source, 'new HttpServiceManager($this)');
startupCheck($serversAt !== false && $httpAt !== false && $serversAt < $httpAt, 'Production HTTP construction moved before server registration');

$bot->options['launcher_directory'] = ['civ13.com' => ['one', 'two']];
$manager = (new ReflectionClass(HttpServiceManager::class))->newInstanceWithoutConstructor();
$manager->civ13 = $bot;
$manager->logger = $bot->logger;
// Skip HttpHandler's external-IP HTTP lookup but retain its production callback
// validation, route storage and rate-limit registration.
$manager->httpHandler = (new ReflectionClass(HttpHandler::class))->newInstanceWithoutConstructor();
(new ReflectionMethod(HttpServiceManager::class, '__generateLauncherDirectoryEndpoint'))->invoke($manager);
$directory = (new ReflectionProperty(HttpServiceManager::class, 'launcherDirectory'))->getValue($manager);
startupCheck($directory instanceof Directory && $directory->hasProvider('civ13.com'), 'Valid pre-init registry disabled directory registration');
$handlers = $manager->httpHandler->get('handlers');
startupCheck(isset($handlers[Directory::PATH]), 'Directory route was not registered');
$response = $handlers[Directory::PATH](new ServerRequest('GET', 'https://civ13.com'.Directory::PATH), Directory::PATH, false);
startupCheck($response->getStatusCode() === 503, 'Registered directory should warm up without probing during construction');
startupCheck((new ReflectionProperty(HttpServiceManager::class, 'launcherDirectoryTimer'))->getValue($manager) === null, 'Directory timer started before HTTP readiness');

// The directory must consume real readonly-string GameServer ports and publish
// JSON integers. Only the status transport/clock are injected for this check.
$probe = static fn () => resolve(['players' => 0, 'map_name' => 'Gaia', 'map_gamemode' => 'Nomads', 'round_duration_in_ticks' => 0]);
$directory = new Directory($bot->options['launcher_directory'], $bot->enabled_gameservers, $probe, static fn () => 1800000000);
$directory->refresh();
startupCheck(array_column($directory->snapshot('civ13.com')['servers'], 'port') === [12345, 12346], 'Real GameServer string ports were not published as integers');

echo "PASS directory startup: synchronous real GameServer registration, disabled/bad-directory exclusion, production constructor order, real pre-init HTTP registration, deferred probing and string-port normalization.\n";
