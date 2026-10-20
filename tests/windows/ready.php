<?php
declare(strict_types=1);

// The real ready path must not contact a verifier, even if its defaults regress.
namespace Civ13 {
    function proc_open(...$arguments): never { throw new \RuntimeException('Unexpected shell process'); }
    function exec(...$arguments): never { throw new \RuntimeException('Unexpected shell execution'); }
    function shell_exec(...$arguments): never { throw new \RuntimeException('Unexpected shell execution'); }

    function curl_exec($handle): false
    {
        if (\curl_getinfo($handle, CURLINFO_EFFECTIVE_URL) !== '') {
            throw new \RuntimeException('Offline ready test attempted a verifier request');
        }
        return false;
    }
}

namespace {
    // Never load tests/bootstrap.php or .env.
    $loader = require ($argv[1] ?? __DIR__.'/../../vendor/autoload.php');
    $loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
    $loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14/', true);

    final class ReadyConnector implements React\Socket\ConnectorInterface
    {
        public function connect($uri) { throw new RuntimeException('Offline test attempted a connection: '.$uri); }
    }
    final class ReadyInteraction extends Discord\Parts\Interactions\Interaction
    {
        public array $responses = [];
        public function __construct(Discord\Discord $discord, array $attributes = [], bool $created = false) {}
        public function respondWithMessage(Discord\Builders\MessageBuilder|string $builder, bool $ephemeral = false): React\Promise\PromiseInterface {
            $this->responses[] = [is_string($builder) ? $builder : $builder->getContent(), $ephemeral];
            return React\Promise\resolve(null);
        }
    }
    final class ReadyLoop implements React\EventLoop\LoopInterface
    {
        public array $timers = [];
        public function addReadStream($stream, $listener) { throw new RuntimeException('Unexpected read stream'); }
        public function addWriteStream($stream, $listener) { throw new RuntimeException('Unexpected write stream'); }
        public function removeReadStream($stream) {}
        public function removeWriteStream($stream) {}
        public function addTimer($interval, $callback) {
            $timer = new React\EventLoop\Timer\Timer($interval, $callback, false);
            $this->timers[spl_object_id($timer)] = $timer;
            return $timer;
        }
        public function addPeriodicTimer($interval, $callback) {
            $timer = new React\EventLoop\Timer\Timer($interval, $callback, true);
            $this->timers[spl_object_id($timer)] = $timer;
            return $timer;
        }
        public function cancelTimer(React\EventLoop\TimerInterface $timer) { unset($this->timers[spl_object_id($timer)]); }
        public function futureTick($listener) { throw new RuntimeException('Unexpected asynchronous IO'); }
        public function addSignal($signal, $listener) { throw new RuntimeException('Unexpected signal'); }
        public function removeSignal($signal, $listener) {}
        public function run() { throw new RuntimeException('The offline loop must not run'); }
        public function stop() {}
        public function fireBootstrap(): void {
            $matching = array_filter($this->timers, static fn ($timer) => !$timer->isPeriodic() && $timer->getInterval() === 5.0);
            check(count($matching) === 1, 'Expected exactly one delayed bootstrap timer');
            $timer = reset($matching);
            $this->cancelTimer($timer);
            ($timer->getCallback())($timer);
        }
    }

    trait ReadyCommands
    {
        public array $saved = [];
        public int $freshens = 0;
        public function __construct($discord = null, array $vars = []) {}
        public function freshen(array $queryparams = []): React\Promise\PromiseInterface {
            ++$this->freshens;
            return React\Promise\resolve($this);
        }
        public function save(Discord\Parts\Part $part, ?string $reason = null): React\Promise\PromiseInterface {
            $this->saved[$part->name] = $part;
            return React\Promise\resolve($part);
        }
        public function get(string $discrim, $key) { return $discrim === 'name' ? ($this->saved[$key] ?? null) : null; }
        public function &getIterator(): Traversable {
            foreach ($this->saved as $key => &$part) { yield $key => $part; }
        }
        public function delete($part, ?string $reason = null): React\Promise\PromiseInterface {
            unset($this->saved[$part->name]);
            return React\Promise\resolve($part);
        }
    }
    final class ReadyGlobalCommands extends Discord\Repository\Interaction\GlobalCommandRepository { use ReadyCommands; }
    final class ReadyGuildCommands extends Discord\Repository\Guild\GuildCommandRepository { use ReadyCommands; }
    final class ReadyDiscord extends Discord\Discord
    {
        public ReadyGlobalCommands $globalCommands;
        public ReadyGuildCommands $guildCommands;
        private array $offline;
        public int $channelLookups = 0;
        public function __construct(private ReadyLoop $offlineLoop) {
            $this->globalCommands = new ReadyGlobalCommands();
            $this->guildCommands = new ReadyGuildCommands();
            $guild = (object) ['id' => '100000000000000001', 'commands' => $this->guildCommands, 'members' => []];
            $this->offline = [
                'application' => (object) ['commands' => $this->globalCommands],
                'guilds' => new Discord\Helpers\Collection([$guild], 'id'),
                'username' => 'offline-ready', 'discriminator' => '0000', 'user' => 'offline-user',
            ];
            // No Discord constructor, credentials, HTTP transport or gateway connection.
            $this->http = (new ReflectionClass(Discord\Http\Http::class))->newInstanceWithoutConstructor();
            $this->factory = new Discord\Factory\Factory($this);
        }
        public function __get(string $name) { return $this->offline[$name] ?? parent::__get($name); }
        public function getLoop(): React\EventLoop\LoopInterface { return $this->offlineLoop; }
        public function getChannel($channel_id): ?Discord\Parts\Channel\Channel { ++$this->channelLookups; return null; }
        public function commands(): array { return $this->application_commands ?? []; }
    }
    function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }

    $unconfigured = ($argv[2] ?? null) === '--unconfigured';
    $dir = sys_get_temp_dir().'/portable-ready-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $previous = getcwd();
    chdir($dir);
    $blockedWrappers = [];
    foreach (['http', 'https'] as $scheme) {
        if (in_array($scheme, stream_get_wrappers(), true)) { stream_wrapper_unregister($scheme); $blockedWrappers[] = $scheme; }
    }
    $oldProfile = getenv('CIVILIZATIONBOT_PROFILE');
    putenv('CIVILIZATIONBOT_PROFILE=offline-ready-test');
    $rejections = [];
    React\Promise\set_rejection_handler(static function (Throwable $error) use (&$rejections): void { $rejections[] = $error; });
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_WARNING) { throw new ErrorException($message, 0, $severity, $file, $line); }
        return false;
    });
    try {
        $loop = new ReadyLoop();
        $discord = new ReadyDiscord($loop);
        $logger = new Monolog\Logger('offline-ready', [new Monolog\Handler\NullHandler()]);
        $initCalls = $slashCalls = 0;
        $sample = json_decode(file_get_contents(__DIR__.'/../../config/examples/windows-test1714.json'), true, 16, JSON_THROW_ON_ERROR);
        $options = array_merge([
            'discord' => $discord, 'loop' => $loop, 'logger' => $logger,
            'stats' => (new ReflectionClass(Discord\Stats::class))->newInstanceWithoutConstructor(),
            'browser' => new React\Http\Browser(new ReadyConnector()),
            'filesystem' => new React\Filesystem\Fallback\Adapter(), 'verify_url' => '',
            'functions' => [
                'init' => [static function ($bot) use (&$initCalls): void { check($bot->ready, 'Init callback ran before readiness'); ++$initCalls; }],
                'ready_slash' => [static function ($slash, $commands) use (&$slashCalls): void { ++$slashCalls; }],
            ],
            'channel_ids' => ['verifier-status' => '100000000000000002'], 'role_ids' => [],
        ], Civ13\PortableProfile::runtimeOptions(['options' => $sample['options'], 'gitdir' => $dir, 'state_dir' => $dir]));
        $options['channel_ids'] = ['verifier-status' => '100000000000000002'];
        $options['civ13_guild_id'] = '100000000000000001';
        $options['owner_id'] = '100000000000000003';
        $options['technician_id'] = '100000000000000004';
        if ($unconfigured) {
            $options['channel_ids'] = [];
            $options['civ13_guild_id'] = $options['owner_id'] = $options['technician_id'] = '';
        }
        $bot = new Civ13\Civ13($options, [], [], null);
        check(!$bot->ready && !isset($bot->slash, $bot->panels), 'Bootstrap ran before init');
        $discord->emit('init', [$discord]);
        check($bot->ready && $bot->verifier->ready && $bot->moderator->ready && $initCalls === 1, 'Real init path did not finish');
        check(!isset($bot->slash, $bot->panels), 'Delayed bootstrap ran early');
        $loop->fireBootstrap();
        check($bot->slash instanceof Civ13\Slash && $bot->panels instanceof Civ13\Panels, 'Delayed Slash/Panels bootstrap did not finish');
        check($slashCalls === 1 && $discord->globalCommands->freshens === 2 && $discord->guildCommands->freshens === ($unconfigured ? 0 : 1), 'Command repositories were not refreshed through real setup');
        foreach (['ping', 'help', 'staffpanel', 'playerpanel'] as $name) {
            check(isset($discord->globalCommands->saved[$name], $discord->commands()[$name]), 'Missing global command or listener: '.$name);
        }
        foreach (['unban', 'parole', 'permit', 'revoke'] as $name) {
            check(isset($discord->guildCommands->saved[$name]) === !$unconfigured, 'Missing guild command: '.$name);
        }
        check($bot->ss14verifier === null && !isset($discord->commands()['ss14']), 'Unavailable SS14 service registered');
        check(str_contains($bot->embed_footer, 'offline-ready#0000'), 'Ready Discord variables were not initialized');
        check(count($discord->listeners('GUILD_CREATE')) > 0, 'Real guild listener was not installed');
        foreach (['pull', 'update'] as $name) {
            $interaction = new ReadyInteraction($discord);
            $timerCount = count($loop->timers);
            $callback = (new ReflectionProperty(Discord\Helpers\RegisteredCommand::class, 'callback'))->getValue($discord->commands()[$name]);
            $callback($interaction);
            check(count($interaction->responses) === 1 && str_contains($interaction->responses[0][0], 'unavailable') && $interaction->responses[0][1], 'Maintenance command did not fail closed: '.$name);
            check(count($loop->timers) === $timerCount, 'Maintenance command scheduled background work: '.$name);
        }
        (new ReflectionMethod(Civ13\HttpServiceManager::class, '__generateEndpoints'))->invoke($bot->httpServiceManager);
        // Synthetic local sentinel only: no environment file is read or loaded.
        $sentinel = "OFFLINE_TEST_SENTINEL=keep-me\n";
        file_put_contents($dir.'/.env', $sentinel);
        $bot->timers['restart_pending'] = $loop->addTimer(99, static function (): void { throw new RuntimeException('Unexpected restart'); });
        foreach (['/reset', '/githubupdated', '/pull', '/update', '/restart', '/kill', '/clearenv', '/cancelupdaterestart'] as $endpoint) {
            $timersBefore = $loop->timers;
            $botTimersBefore = $bot->timers;
            $lookupsBefore = $discord->channelLookups;
            $handler = $bot->httpServiceManager->httpHandler->offsetGet($endpoint, 'handlers');
            check(is_callable($handler), 'Missing maintenance endpoint: '.$endpoint);
            $response = $handler(new React\Http\Message\ServerRequest('POST', 'http://127.0.0.1'.$endpoint), $endpoint, true);
            check($response->getStatusCode() === 503 && str_contains((string) $response->getBody(), 'unavailable'), 'Maintenance endpoint reported success: '.$endpoint);
            check($loop->timers === $timersBefore && $bot->timers === $botTimersBefore, 'Maintenance endpoint changed timers: '.$endpoint);
            check($discord->channelLookups === $lookupsBefore, 'Maintenance endpoint attempted Discord notification: '.$endpoint);
            check(file_get_contents($dir.'/.env') === $sentinel, 'Maintenance endpoint changed synthetic environment file: '.$endpoint);
        }
        check(!$rejections, 'Bootstrap promise rejected: '.($rejections ? (string) $rejections[0] : ''));
        echo ($unconfigured ? "UNCONFIGURED " : "CONFIGURED ")."PASS real init and delayed Slash/Panels bootstrap; global/guild commands and listeners; eight HTTP maintenance guards; no network or loop run\n";
    } finally {
        putenv($oldProfile === false ? 'CIVILIZATIONBOT_PROFILE' : 'CIVILIZATIONBOT_PROFILE='.$oldProfile);
        restore_error_handler();
        React\Promise\set_rejection_handler(null);
        foreach ($blockedWrappers as $scheme) { stream_wrapper_restore($scheme); }
        chdir($previous);
        $remove = function (string $path) use (&$remove): void {
            foreach (new DirectoryIterator($path) as $entry) {
                if ($entry->isDot()) { continue; }
                $entry->isDir() ? $remove($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($path);
        };
        $remove($dir);
    }
}
