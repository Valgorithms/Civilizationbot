<?php

declare(strict_types=1);

// Real library serialization; no bot.php/.env, game probes or Discord traffic.
require ($argv[1] ?? __DIR__.'/../../vendor/autoload.php');
use Civ13\ServerPlayLink;
use Discord\Builders\MessageBuilder;

$count = 0;
function check(bool $condition, string $message): void { global $count; $count++; if (!$condition) { throw new RuntimeException($message); } }
$config = ['url' => 'https://civ13.com/persistence/'];
$play = ServerPlayLink::fromConfig($config, 'pers', 'byond');
check($play->url === $config['url'], 'Explicit Persistence landing page');
check(ServerPlayLink::fromConfig(null, 'pers', 'byond') === null, 'Default off');
check(ServerPlayLink::fromConfig([], 'pers', 'byond') === null, 'Empty config off');
$bad = [];
foreach (['byond://play.valzargaming.com:1714', 'http://civ13.com/persistence/', 'https://u@civ13.com/persistence/', 'https://civ13.com/persistence/?target=bad', 'https://civ13.com/persistence/#x', 'https://civ13.com:8443/persistence/', "https://civ13.com/persistence/\n", "https://civ13.com\\@evil.org/persistence/"] as $url) { $bad[] = ['url' => $url]; }
foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '169.254.1.1', '100.64.1.1', '192.0.2.1', '[::1]', 'localhost', 'host.local', 'host.internal', 'host.home', 'host.lan', '127.1', '2130706433', str_repeat('a', 64).'.org'] as $host) { $bad[] = ['url' => 'https://'.$host.'/play/']; }
$bad[] = $config + ['host' => 'play.valzargaming.com', 'port' => 1715];
foreach ($bad as $candidate) {
    try { ServerPlayLink::fromConfig($candidate, 'pers', 'byond'); check(false, 'Invalid configuration accepted'); }
    catch (InvalidArgumentException) { check(true, 'Invalid config rejected'); }
}
try { ServerPlayLink::fromConfig($config, 'pers', 'opendream'); check(false, 'Unsupported engine accepted'); }
catch (InvalidArgumentException) { check(true, 'Unsupported engine rejected'); }
$server = (new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();
check($server->addPlayButton(MessageBuilder::new())->getComponents() === [], 'Unconfigured server adds no button');
$server->playLink = $play;
for ($i = 0; $i < 2; $i++) {
    $wire = json_decode(json_encode($server->addPlayButton(MessageBuilder::new()), JSON_THROW_ON_ERROR), true);
    $button = $wire['components'][0]['components'][0];
    check($button['style'] === 5 && $button['url'] === $play->url && $button['label'] === 'Play', 'Serialized link button');
    check(!isset($button['custom_id']), 'No restart-sensitive listener');
}
$other = ServerPlayLink::fromConfig(['url' => 'https://civ13.com/tdm/'], 'tdm', 'byond');
check($other->url !== $play->url, 'Distinct mode page');
echo "Play link contracts passed: $count assertions\n";
