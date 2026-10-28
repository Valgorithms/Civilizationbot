<?php

declare(strict_types=1);

namespace Civ13 {
    function fsockopen(...$arguments): never { throw new \RuntimeException('Status attempted a network probe'); }
    function touch(...$arguments): never { throw new \RuntimeException('Status attempted to touch game data'); }
    function file_get_contents($filename, ...$arguments): string|false {
        if (($GLOBALS['host_read_forbidden'] ?? null) === $filename) { throw new \RuntimeException('Remote host file was read'); }
        if (($GLOBALS['host_read_failure'] ?? null) === $filename) { return false; }
        return \file_get_contents($filename, ...$arguments);
    }
    function is_file($filename): bool {
        if (str_starts_with(str_replace('\\', '/', $filename), '//')) { throw new \RuntimeException('UNC path was probed'); }
        return \is_file($filename);
    }
}

namespace {
    // Explicit dependencies only. tests/bootstrap.php loads .env and is deliberately excluded.
    $loader = require ($argv[1] ?? __DIR__.'/../../vendor/autoload.php');
    $loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
    $loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14/', true);

    function check(bool $ok, string $message): void {
        if (! $ok) { throw new RuntimeException($message); }
    }
    function record(string $time, string $players = '', bool $approved = false): string {
        return '<b>Server Status</b>: Online;<b>Address</b>: byond://127.0.0.1:1715;<b>Map</b>: fixture;'
            .'<b>Gamemode</b>: fixture;<b>Players</b>: '.($players === '' ? '0' : '1').';'
            .($approved ? '<b>Approved only</b>: Enabled;' : '')
            .'realtime='.$time.';world.address=127.0.0.1;round_timer=01:00;map=fixture;epoch=fixture;'
            .'season=fixture;ckey_list='.$players.';allow_vote_restart=0;';
    }
    final class HeartbeatCiv extends Civ13\Civ13 {
        public function createEmbed(?bool $footer = true, int $color = 0xE1452D): Discord\Parts\Embed\Embed {
            $discord = (new ReflectionClass(Discord\Discord::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty($discord, 'collectionClass'))->setValue($discord, Discord\Helpers\Collection::class);
            (new ReflectionProperty($discord, 'http'))->setValue($discord, (new ReflectionClass(Discord\Http\Http::class))->newInstanceWithoutConstructor());
            $factory = new Discord\Factory\Factory($discord);
            (new ReflectionProperty($discord, 'factory'))->setValue($discord, $factory);
            $embed = (new ReflectionClass(Discord\Parts\Embed\Embed::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty($embed, 'discord'))->setValue($embed, $discord);
            (new ReflectionProperty($embed, 'factory'))->setValue($embed, $factory);

            return $embed;
        }
        public function __destruct() {}
    }
    final class HeartbeatChannel extends Discord\Parts\Channel\Channel {
        public array $fixture = [];
        public function __construct(Discord\Discord $discord, array $attributes = [], bool $created = false) { $this->created = true; }
        public function __get(string $name) { return $this->fixture[$name] ?? null; }
        public function __set(string $name, $value): void { $this->fixture[$name] = $value; }
    }
    final class HeartbeatDiscord extends Discord\Discord {
        public HeartbeatChannel $fixture;
        public function __construct() {}
        public function getChannel($channel_id): ?Discord\Parts\Channel\Channel { return $this->fixture; }
        public function __destruct() {}
    }
    function server(string $path, Closure $clock, string $ip = '127.0.0.1'): Civ13\GameServer {
        $bot = (new ReflectionClass(HeartbeatCiv::class))->newInstanceWithoutConstructor();
        $bot->httpServiceManager = (new ReflectionClass(Civ13\HttpServiceManager::class))->newInstanceWithoutConstructor();
        $bot->httpServiceManager->httpHandler = (new ReflectionClass(Civ13\HttpHandler::class))->newInstanceWithoutConstructor();
        $bot->httpServiceManager->httpHandler->external_ip = '127.0.0.1';
        $server = (new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();
        $server->civ13 = $bot;
        foreach (['name' => 'Nomads', 'key' => 'nomads', 'ip' => $ip, 'port' => '1715', 'host' => 'fixture', 'basedir' => dirname($path)] as $property => $value) {
            (new ReflectionProperty($server, $property))->setValue($server, $value);
        }
        (new ReflectionProperty($server, 'serverdata'))->setValue($server, $path);
        (new ReflectionProperty($server, 'heartbeat'))->setValue($server, new Civ13\ServerHeartbeat($path, $clock));
        $server->enabled = true;
        $server->loop = new React\EventLoop\StreamSelectLoop();
        $server->discord = new HeartbeatDiscord();
        $server->logger = new Monolog\Logger('offline', [new Monolog\Handler\NullHandler()]);

        return $server;
    }
    function fields(Discord\Parts\Embed\Embed $embed): array {
        return array_column(json_decode(json_encode($embed, JSON_THROW_ON_ERROR), true)['fields'], 'value', 'name');
    }

    $root = sys_get_temp_dir().'/bot-heartbeat-integration-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $now = 0.0;
    $clock = static function () use (&$now): float { return $now; };
    try {
        $path = $root.'/serverdata.txt';
        mkdir($root.'/config');
        $hostPath = $root.'/config/host.txt';
        file_put_contents($hostPath, "  AlphaHost \r\n");
        mkdir($root.'/other'); mkdir($root.'/other/config');
        $otherHostPath = $root.'/other/config/host.txt';
        file_put_contents($otherHostPath, "BetaHost\n");
        file_put_contents($path, record('100', 'player', true));
        touch($path, 1000000000);
        $server = server($path, $clock);
        $other = server($root.'/other/serverdata.txt', $clock);
        foreach ([[$server, 'AlphaHost'], [$other, 'BetaHost']] as [$fixtureServer, $expectedHost]) {
            check(fields($fixtureServer->generateServerstatusEmbed())['Host'] === $expectedHost, 'Heartbeat used another server or configured host');
            check(fields($fixtureServer->toEmbed())['Host'] === $expectedHost, 'Status embed used another server or configured host');
        }
        check(file_get_contents($hostPath) === "  AlphaHost \r\n" && file_get_contents($otherHostPath) === "BetaHost\n", 'Status rendering modified a host file');
        file_put_contents($hostPath, " ChangedHost \n");
        check(fields($server->toEmbed())['Host'] === 'ChangedHost' && fields($other->toEmbed())['Host'] === 'BetaHost', 'Host file changes did not stay per-server');
        file_put_contents($hostPath, " \xC3\x89lodie\r\n");
        check(fields($server->toEmbed())['Host'] === "\xC3\x89lodie" && fields($server->generateServerstatusEmbed())['Host'] === "\xC3\x89lodie", 'Valid non-ASCII host was not preserved');
        $invalidHost = "Bad\xC3\x28";
        file_put_contents($hostPath, $invalidHost);
        check(fields($server->toEmbed())['Host'] === 'Unavailable' && fields($server->generateServerstatusEmbed())['Host'] === 'Unavailable' && file_get_contents($hostPath) === $invalidHost, 'Invalid UTF-8 was not rejected unchanged');
        $GLOBALS['host_read_failure'] = $hostPath;
        check(fields($server->toEmbed())['Host'] === 'Unavailable' && fields($server->generateServerstatusEmbed())['Host'] === 'Unavailable', 'Unreadable host file used a fallback');
        unset($GLOBALS['host_read_failure']);
        file_put_contents($hostPath, " \r\n\t");
        check(fields($server->toEmbed())['Host'] === 'Unavailable' && fields($server->generateServerstatusEmbed())['Host'] === 'Unavailable', 'Empty host file used a fallback');
        file_put_contents($hostPath, str_repeat('x', 1025));
        check(fields($server->toEmbed())['Host'] === 'Unavailable', 'Oversized host file produced an invalid embed');
        unlink($hostPath);
        check(fields($server->toEmbed())['Host'] === 'Unavailable' && fields($server->generateServerstatusEmbed())['Host'] === 'Unavailable' && fields($other->toEmbed())['Host'] === 'BetaHost', 'Missing host file used another server or placeholder');
        file_put_contents($hostPath, "AlphaHost\n");
        $unc = server('//server/share/serverdata.txt', $clock);
        check(fields($unc->toEmbed())['Host'] === 'Unavailable', 'UNC host path should not be probed');
        $server->players = ['cached'];
        $initial = fields($server->generateServerstatusEmbed());
        check($initial['Nomads'] === 'Unknown' && ! isset($initial['Players (1)']), 'Old literal Online leaked into initial status');
        check($server->players === [], 'Unknown heartbeat retained old player cache');
        clearstatcache(true, $path);
        check(filemtime($path) === 1000000000, 'Renderer mutated game heartbeat timestamp');
        $now = 15;
        file_put_contents($path, record('200', 'player', true));
        $active = fields($server->generateServerstatusEmbed());
        check($active['Nomads'] === 'Heartbeat advancing' && ! array_key_exists('Connectivity', $active), 'Heartbeat or removed connectivity field is incorrect');
        check($active['Players (1)'] === 'player' && $server->localServerPlayerCount() === 1, 'Optional Approved field corrupted player metadata');
        check($server->players === ['player'], 'Confirmed player cache not populated');
        $server->current_round = 'fixture';
        $server->rounds = ['fixture' => ['start' => 'fixture', 'players' => [], 'log' => '/fixture']];
        check(str_contains(json_encode($server->createCurrentRoundEmbedMessageBuilder()), 'Heartbeat advancing'), 'Current-round renderer bypassed health state');
        $timer = $server->heartbeatTimer();
        check($timer === $server->heartbeatTimer() && $timer->getInterval() === 15.0, 'Passive timer duplicated or has wrong interval');
        foreach ([45, 75, 105, 135] as $now) { ($timer->getCallback())(); }
        $stale = fields($server->generateServerstatusEmbed());
        check($stale['Nomads'] === 'Stale heartbeat' && ! isset($stale['Players (1)']) && $server->players === [], 'Frozen game retained current players');
        check(str_contains(json_encode($server->createCurrentRoundEmbedMessageBuilder()), 'Stale heartbeat'), 'Persistent round embed retained stale live data');

        $repository = new class {
            public int $saves = 0;
            public function save($channel): React\Promise\PromiseInterface { $this->saves++; return React\Promise\resolve($channel); }
        };
        $discord = new HeartbeatDiscord();
        $channel = new HeartbeatChannel($discord);
        $channel->fixture = ['name' => 'nomads-1', 'guild' => (object) ['channels' => $repository]];
        $discord->fixture = $channel;
        $server->discord = $discord;
        $server->playercount = 'fixture';
        $server->playercountChannelUpdate();
        check($channel->name === 'nomads-unknown' && $repository->saves === 1, 'Unknown count was rendered as zero/current');
        $server->playercountChannelUpdate();
        check($repository->saves === 1, 'Unchanged unknown count repeatedly saved');

        $now = 150;
        file_put_contents($path, record('300'));
        $server->playercountChannelUpdate();
        check($channel->name === 'nomads-0', 'Healthy zero-player world was not distinguished from unknown');
        check(fields($server->generateServerstatusEmbed())['Nomads'] === 'Heartbeat advancing', 'Recovery not reflected in renderer');
        unlink($path);
        check(fields($server->generateServerstatusEmbed())['Nomads'] === 'Unknown' && ! file_exists($path), 'Missing data failed closed or was recreated');
        check($server->players === [], 'Missing data retained players');
        $remote = server($root.'/never-created', $clock, '192.0.2.1');
        (new ReflectionProperty($remote, 'heartbeat'))->setValue($remote, new Civ13\ServerHeartbeat('unused', $clock, static function (): never { throw new RuntimeException('Remote directory was read'); }));
        $GLOBALS['host_read_forbidden'] = $hostPath;
        check(fields($remote->toEmbed())['Host'] === 'Unavailable', 'Remote status used a local or configured host');
        check(fields($remote->generateServerstatusEmbed())['Health'] === 'Unknown (no local heartbeat evidence)', 'Remote server inferred health');
        unset($GLOBALS['host_read_forbidden']);
        check($remote->localServerPlayerCount() === 0, 'Remote server tried local player read');
        check(! array_key_exists('heartbeat', $server->__toArray()), 'Ephemeral observation leaked into serialization');
        echo "Production heartbeat renderer/cache/timer contracts passed\n";
    } finally {
        unset($GLOBALS['host_read_forbidden'], $GLOBALS['host_read_failure']);
        if (is_file($root.'/serverdata.txt')) { unlink($root.'/serverdata.txt'); }
        if (is_file($root.'/config/host.txt')) { unlink($root.'/config/host.txt'); }
        if (is_file($root.'/other/config/host.txt')) { unlink($root.'/other/config/host.txt'); }
        rmdir($root.'/config'); rmdir($root.'/other/config'); rmdir($root.'/other');
        rmdir($root);
    }
}
