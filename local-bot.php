<?php
declare(strict_types=1);
// Dedicated local entry: never includes bot.php, .env, Civ13, Verifier or legacy routers.
foreach (['Profile', 'VerificationReplica', 'ReadOnlyEndpoint'] as $class) { require __DIR__.'/src/Civ13/Local/'.$class.'.php'; }
use Civ13\Local\Profile;
use Civ13\Local\VerificationReplica;
use Civ13\Local\ReadOnlyEndpoint;
use Discord\Discord;
use Discord\WebSockets\Intents;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Psr\Http\Message\ServerRequestInterface;

if ($argc !== 3 || !in_array($argv[1], ['--check', '--run'], true)) { fwrite(STDERR, "Usage: php local-bot.php --check|--run explicit-profile.json\n"); exit(2); }
try {
    $profile = Profile::load($argv[2]);
    $key = base64_decode(trim(file_get_contents($profile['public_key_file']) ?: ''), true);
    $replica = new VerificationReplica($profile['state_directory'], $key ?: '', $profile['authority'], static fn () => time());
    $endpoint = new ReadOnlyEndpoint($replica, trim(file_get_contents($profile['read_key_file']) ?: ''));
    if ($argv[1] === '--check') { echo "PASS isolated profile. No connections opened; replica freshness is checked per read.\n"; exit(0); }
    if (!$profile['enabled']) { throw new LogicException('Local profile is disabled'); }
    $token = Profile::token($profile);
    require __DIR__.'/vendor/autoload.php';
    $controls = null;
    if (isset($profile['controls'])) {
        $settings = $profile['controls']; $adapters = [];
        if ($settings['enabled']) {
            $execute = new Civ13\Local\AsyncProcess(Loop::get(), $profile['state_directory']);
            foreach ($settings['profiles'] as $name => $path) { $adapters[$name] = new Civ13\Local\OpenDreamControl($profile['servers'][$name], $settings['python'], $settings['controller'], $path, $execute, true, $settings['lifecycle_schema'] ?? null); }
        }
        $controls = new Civ13\Local\ControlEndpoint($adapters, trim(file_get_contents($settings['read_key_file']) ?: ''), $settings['enabled']);
    }
    $discord = new Discord(['token' => $token, 'loop' => Loop::get(), 'intents' => Intents::GUILDS, 'loadAllMembers' => false, 'storeMessages' => false]);
    unset($token);
    // Gateway presence only: no messages, slash registration, roles, control or verification mutation handlers.
    $discord->on('init', function (Discord $discord) use ($profile): void { if ((string) $discord->id !== $profile['application_id']) { $discord->close(); throw new LogicException('Local application identity mismatch'); } });
    $http = new HttpServer(function (ServerRequestInterface $request) use ($endpoint, $controls) {
        if ($controls !== null && str_starts_with($request->getUri()->getPath(), '/v1/local/')) {
            return $controls->handle($request->getMethod(), $request->getUri()->getPath(), $request->getHeaderLine('Authorization'), (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''), (string) $request->getBody())->then(static fn ($reply) => Response::json($reply[1])->withStatus($reply[0])->withHeader('Cache-Control', 'no-store'));
        }
        [$status, $body] = $endpoint->handle($request->getMethod(), $request->getUri()->getPath(), $request->getHeaderLine('Authorization')); return Response::json($body)->withStatus($status)->withHeader('Cache-Control', 'no-store');
    });
    $socket = new SocketServer($profile['bind'].':'.$profile['port']); $http->listen($socket);
    if (function_exists('pcntl_signal')) { foreach ([SIGTERM, SIGINT] as $signal) { Loop::addSignal($signal, function () use ($socket, $discord): void { $socket->close(); $discord->close(); Loop::stop(); }); } }
    $discord->run();
} catch (Throwable) { fwrite(STDERR, "Local startup rejected; review profile, dependencies and private credential files.\n"); exit(1); }
