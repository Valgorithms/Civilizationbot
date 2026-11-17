<?php
declare(strict_types=1);
// Join-screen relay: lobby chat and arrival notices reach the lobby channel, offline.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../src/Civ13/Civ13.php';
require __DIR__.'/../../src/Civ13/GameServer.php';
require __DIR__.'/../../src/Civ13/HttpServiceManager.php';
require_once __DIR__.'/../../src/Civ13/Verifier.php';
require_once __DIR__.'/../../src/Civ13/Moderator.php';
use React\Http\Message\ServerRequest;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
final class LobbyDiscord extends \Discord\Discord {
    public bool $found = true;
    public function getChannel($channel_id): ?\Discord\Parts\Channel\Channel { return $this->found ? (new ReflectionClass(\Discord\Parts\Channel\Channel::class))->newInstanceWithoutConstructor() : null; }
}
final class LobbyServer extends \Civ13\GameServer {
    public array $calls = [];
    public function gameChatWebhookRelay(string $message, string $channel_id, ?string $ckey = null, ?bool $ooc = true, ?bool $moderate = true): PromiseInterface
    { $this->calls[] = [$message, $channel_id, $ckey, $ooc]; return resolve(null); }
}
$civ = (new ReflectionClass(\Civ13\Civ13::class))->newInstanceWithoutConstructor();
$civ->options = ['http_key' => 'synthetic-lobby-key'];
$manager = (new ReflectionClass(\Civ13\HttpServiceManager::class))->newInstanceWithoutConstructor();
$manager->civ13 = $civ;
$manager->discord = (new ReflectionClass(LobbyDiscord::class))->newInstanceWithoutConstructor();
$manager->logger = new \Monolog\Logger('offline-lobby', [new \Monolog\Handler\NullHandler()]);
$server = (new ReflectionClass(LobbyServer::class))->newInstanceWithoutConstructor();
$server->legacy_relay = false; $server->lobby = '123456789012345678';
$send = static function (array $data, ?string $key = 'synthetic-lobby-key') use ($manager, $server): int {
    $query = ['method' => 'lobbymessage', 'data' => json_encode($data)];
    if ($key !== null) { $query['key'] = $key; }
    return $manager->handleLobbyWebhook(new ServerRequest('GET', 'http://localhost/webhook/test?'.http_build_query($query)), $server)->getStatusCode();
};

// Lobby chat keeps the old LOBBY line, moderated like OOC, posted under the player's ckey.
check($send(['ckey' => 'Real_Player', 'message' => 'hello &amp; <b>welcome</b>']) === 202, 'Lobby chat rejected');
[$line, $channel, $ckey, $ooc] = end($server->calls);
check($channel === '123456789012345678' && $ckey === 'realplayer' && $ooc === true, 'Lobby chat routed wrongly');
check(str_contains($line, 'LOBBY__ realplayer:** hello & welcome'), 'Lobby chat text changed: '.$line);
check($send(['ckey' => 'Real_Player', 'kind' => 'chat', 'message' => 'again']) === 202, 'Explicit chat kind rejected');

// Arrival notices: no ckey identity lookup, markdown in the key escaped.
check($send(['ckey' => 'Real_Player', 'kind' => 'join_screen']) === 202, 'Join-screen notice rejected');
[$line, , $ckey] = end($server->calls);
check($ckey === null && str_contains($line, '**Real\_Player** is on the join screen.'), 'Join-screen notice wrong: '.$line);
check($send(['ckey' => 'Real Player', 'kind' => 'entered']) === 202, 'Entered notice rejected');
[$line, , $ckey] = end($server->calls);
check($ckey === null && str_contains($line, '**Real Player** entered the world.'), 'Entered notice wrong: '.$line);

// Rejections never reach the channel.
$count = count($server->calls);
check($send(['ckey' => 'Real_Player', 'message' => 'x'], 'wrong') === 403, 'Wrong key accepted');
check($send(['ckey' => 'Real_Player', 'message' => 'x'], null) === 403, 'Missing key accepted');
foreach ([
    ['ckey' => 'Real_Player'],
    ['ckey' => 'Real_Player', 'message' => ''],
    ['ckey' => 'Real_Player', 'message' => "bad\0line"],
    ['ckey' => 'Real_Player', 'message' => []],
    ['ckey' => 'Real_Player', 'kind' => 'explode'],
    ['ckey' => 'Real_Player', 'kind' => []],
    ['ckey' => '<@&1>', 'kind' => 'entered'],
    ['ckey' => str_repeat('a', 65), 'kind' => 'entered'],
    ['kind' => 'join_screen'],
] as $bad) {
    check($send($bad) === 400, 'Malformed lobby request accepted: '.json_encode($bad));
}
$server->legacy_relay = true;
check($send(['ckey' => 'Real_Player', 'kind' => 'entered']) === 403, 'Legacy relay posted');
$server->legacy_relay = false; $server->lobby = '';
check($send(['ckey' => 'Real_Player', 'kind' => 'entered']) === 503, 'Missing lobby channel accepted');
$server->lobby = '123456789012345678'; $manager->discord->found = false;
check($send(['ckey' => 'Real_Player', 'kind' => 'entered']) === 503, 'Unknown lobby channel accepted');
check(count($server->calls) === $count, 'A rejected request reached the channel');
check(\Civ13\HttpServiceManager::lobbyMarkdownName('a_b*c~d`e|f>g\\h') === 'a\_b\*c\~d\`e\|f\>g\\\\h', 'Markdown escape wrong');

echo "Lobby webhook checks passed\n";
