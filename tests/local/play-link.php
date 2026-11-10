<?php

declare(strict_types=1);

// Real library serialization, no bot.php/.env, sockets, game probes or Discord traffic.
require ($argv[1] ?? __DIR__.'/../../vendor/autoload.php');
use Civ13\ServerPlayLink;
use React\Http\Message\ServerRequest;
use Discord\Builders\MessageBuilder;

$count = 0;
function check(bool $condition, string $message): void { global $count; $count++; if (!$condition) { throw new RuntimeException($message); } }
$config = ['url' => 'https://join.example.org/pers/play', 'host' => 'play.example.org', 'port' => 1714];
$play = ServerPlayLink::fromConfig($config, 'pers', 'byond');
check($play->joinUri === 'byond://play.example.org:1714', 'Correct public game endpoint');
check(ServerPlayLink::fromConfig(null, 'pers', 'byond') === null, 'Default off');
check(ServerPlayLink::fromConfig([], 'pers', 'byond') === null, 'Empty config off');
$bad = [];
foreach (['byond://play.example.org:1714', 'http://join.example.org/pers/play', 'https://join.example.org/wrong/play', 'https://u@join.example.org/pers/play', 'https://join.example.org/pers/play?target=bad', 'https://join.example.org/pers/play#x', 'https://127.0.0.1/pers/play', 'https://localhost/pers/play', 'https://join.example.org:8443/pers/play', "https://join.example.org/pers/play\n"] as $url) { $bad[] = array_replace($config, ['url' => $url]); }
foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '169.254.1.1', '100.64.1.1', '192.0.2.1', '::1', '[::1]', 'localhost', 'host.local', 'host.internal', 'host.home', 'host.lan', '127.1', '2130706433', 'host/path', 'host:1714', 'a@b.org', "good.org\" onclick=bad"] as $host) { $bad[] = array_replace($config, ['host' => $host]); }
foreach ([0, 65536, '1714', 1.2, null] as $port) { $bad[] = array_replace($config, ['port' => $port]); }
foreach ($bad as $candidate) {
    try { ServerPlayLink::fromConfig($candidate, 'pers', 'byond'); check(false, 'Invalid configuration accepted'); }
    catch (InvalidArgumentException) { check(true, 'Invalid config rejected'); }
}
foreach ([['pers', 'opendream'], ['../pers', 'byond']] as [$key, $engine]) {
    try { ServerPlayLink::fromConfig($config, $key, $engine); check(false, 'Unsupported engine/key accepted'); }
    catch (InvalidArgumentException) { check(true, 'Engine/key rejected'); }
}
$request = new ServerRequest('GET', $play->url);
$response = $play->handle($request, '<script>name</script>');
$body = (string) $response->getBody();
check($response->getStatusCode() === 200 && str_contains($body, 'href="byond://play.example.org:1714"'), 'Manual join link');
check(!str_contains($body, '<script>') && str_contains($body, '&lt;script&gt;'), 'Escaped name');
check(!str_contains($body, 'http-equiv') && !$response->hasHeader('Location'), 'No automatic launch/redirect');
check($response->getHeaderLine('Referrer-Policy') === 'no-referrer', 'No referer');
check($play->handle(new ServerRequest('HEAD', $play->url), 'Pers')->getBody()->getSize() === 0, 'HEAD empty');
check($play->handle(new ServerRequest('POST', $play->url), 'Pers')->getStatusCode() === 405, 'Mutation rejected');
check($play->handle(new ServerRequest('GET', $play->url.'?url=byond://evil.org'), 'Pers')->getStatusCode() === 400, 'No query selected targets');
check($play->handle(new ServerRequest('GET', 'https://evil.org/pers/play'), 'Pers')->getStatusCode() === 404, 'Wrong host');
check($play->handle(new ServerRequest('GET', 'https://join.example.org/other/play'), 'Pers')->getStatusCode() === 404, 'Wrong region');
$server = (new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();
$builder = $server->addPlayButton(MessageBuilder::new());
check($builder->getComponents() === [], 'Unconfigured server adds no button');
$server->playLink = $play;
for ($i = 0; $i < 2; $i++) {
    $wire = json_decode(json_encode($server->addPlayButton(MessageBuilder::new()), JSON_THROW_ON_ERROR), true);
    $button = $wire['components'][0]['components'][0];
    check($button['style'] === 5 && $button['url'] === $play->url && $button['label'] === 'Play', 'Serialized link button');
    check(!isset($button['custom_id']), 'No restart-sensitive listener');
}
$other = ServerPlayLink::fromConfig(['url' => 'https://join.example.org/tdm/play', 'host' => 'tdm.example.org', 'port' => 1715], 'tdm', 'byond');
check(str_contains((string) $other->handle(new ServerRequest('GET', $other->url), 'TDM')->getBody(), 'byond://tdm.example.org:1715'), 'Distinct region destination');
echo "Play link contracts passed: $count assertions\n";
