<?php

declare(strict_types=1);

// Offline only: no .env, bot boot, DNS, real sockets, Discord or production servers.
require $argv[1] ?? __DIR__.'/../vendor/autoload.php';

use Civ13\ServerDirectory\ByondStatusProbe;
use Civ13\ServerDirectory\Directory;
use Civ13\ServerDirectory\Endpoint;
use Evenement\EventEmitter;
use React\EventLoop\StreamSelectLoop;
use React\Http\Message\ServerRequest;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

function directoryCheck(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

function directoryThrows(callable $callback): void
{
    try { $callback(); } catch (Throwable) { return; }
    throw new RuntimeException('Expected invalid configuration/status to be rejected');
}

function statusPacket(string $json): string
{
    $body = "\x06".$json."\0";
    return "\0\x83".pack('n', strlen($body)).$body;
}

final class DirectoryConnection extends EventEmitter implements ConnectionInterface
{
    public string $request = '';
    public bool $closed = false;
    public function isReadable() { return !$this->closed; }
    public function isWritable() { return !$this->closed; }
    public function pause() {}
    public function resume() {}
    public function pipe(WritableStreamInterface $dest, array $options = []) { return Util::pipe($this, $dest, $options); }
    public function write($data) { $this->request .= $data; return !$this->closed; }
    public function getRemoteAddress() { return 'tcp://127.0.0.1:1'; }
    public function getLocalAddress() { return 'tcp://127.0.0.1:2'; }
    public function end($data = null) { if ($data !== null) { $this->write($data); } $this->close(); }
    public function close() { if (!$this->closed) { $this->closed = true; $this->emit('close'); $this->removeAllListeners(); } }
}

$status = ['game_id' => 'round-42', 'players' => 0, 'map_name' => 'Gaia', 'map_gamemode' => 'Nomads', 'map_age' => 1200, 'round_duration_in_ticks' => 12345,
    'round_duration_in_second' => 123450, 'admins' => ['private-admin'], 'ckeys' => ['private-player'], 'ip' => 'private-address', 'cid' => 'private-cid', 'secret' => 'private-secret'];
$packet = statusPacket(json_encode($status, JSON_THROW_ON_ERROR));
directoryCheck(ByondStatusProbe::decodePacket($packet) === $status, 'Valid status packet changed');
for ($i = 0; $i < strlen($packet); $i++) {
    directoryCheck(ByondStatusProbe::decodePacket(substr($packet, 0, $i)) === null, 'Fragmented frame did not wait');
}
foreach (["\0\x00\0\x04xxxx", "\0\x83\xff\xff", "\0\x83\0\x01x", $packet.'trailing', substr($packet, 0, -1).'x', str_replace("\x06", "\x2a", $packet), statusPacket('false'), statusPacket('[]'), statusPacket('{broken'), statusPacket('{"x":"\ud800"}'), statusPacket('{"x":"\udc00"}'), statusPacket('{"x":"'."\xed\xa0\x80".'"}'), statusPacket('{"x":"'.str_repeat('x', 65536).'"}') ] as $bad) {
    directoryThrows(static fn () => ByondStatusProbe::decodePacket($bad));
}

// Real production probe with injected connections: bytes, fragmentation, cleanup, IPv6.
$loop = new StreamSelectLoop();
$connection = new DirectoryConnection();
$connector = new class($connection) implements ConnectorInterface {
    public array $uris = [];
    public function __construct(private ConnectionInterface $connection) {}
    public function connect($uri) { $this->uris[] = $uri; return resolve($this->connection); }
};
$probe = new ByondStatusProbe($loop, $connector);
$value = null; $error = null;
$probe('::1', 12345)->then(static function ($result) use (&$value) { $value = $result; }, static function ($failure) use (&$error) { $error = $failure; });
$query = "?status=1&format=json\0";
directoryCheck($connection->request === "\0\x83".pack('n', strlen($query) + 5).str_repeat("\0", 5).$query, 'Read-only Topic wire request differs');
directoryCheck($connector->uris === ['tcp://[::1]:12345'], 'Configured IPv6 target not preserved');
foreach (str_split($packet, 3) as $chunk) { $connection->emit('data', [$chunk]); }
directoryCheck($value === $status && $error === null && $connection->closed, 'Probe did not parse/close valid response');
$loop->run(); // No leftover deadline should keep this loop alive.

foreach (['close', 'error', 'oversized', 'bad_json'] as $failure) {
    $loop = new StreamSelectLoop(); $connection = new DirectoryConnection();
    $connector = new class($connection) implements ConnectorInterface {
        public function __construct(private ConnectionInterface $connection) {}
        public function connect($uri) { return resolve($this->connection); }
    };
    $failed = false;
    (new ByondStatusProbe($loop, $connector))('127.0.0.1', 1)->then(null, static function () use (&$failed) { $failed = true; });
    match ($failure) {
        'close' => $connection->close(),
        'error' => $connection->emit('error', [new RuntimeException('Offline fixture')]),
        'oversized' => $connection->emit('data', [str_repeat('x', 65537)]),
        'bad_json' => $connection->emit('data', [statusPacket('{')]),
    };
    directoryCheck($failed && $connection->closed, 'Invalid/closed transport did not fail closed');
    $loop->run();
}

// A single real deadline bounds DNS/connect and established-stream stalls alike.
$loop = new StreamSelectLoop(); $cancelled = false; $connectFailed = false; $streamFailed = false;
$hanging = new Deferred(static function () use (&$cancelled) { $cancelled = true; });
$connector = new class($hanging) implements ConnectorInterface {
    public function __construct(private Deferred $pending) {}
    public function connect($uri) { return $this->pending->promise(); }
};
(new ByondStatusProbe($loop, $connector))('offline.invalid', 1)->then(null, static function () use (&$connectFailed) { $connectFailed = true; });
$connection = new DirectoryConnection();
$connector = new class($connection) implements ConnectorInterface {
    public function __construct(private ConnectionInterface $connection) {}
    public function connect($uri) { return resolve($this->connection); }
};
(new ByondStatusProbe($loop, $connector))('127.0.0.1', 1)->then(null, static function () use (&$streamFailed) { $streamFailed = true; });
$started = microtime(true); $loop->run();
directoryCheck($cancelled && $connectFailed && $streamFailed && $connection->closed && microtime(true) - $started < 5, 'Deadline did not cancel connect and close stalled stream');

$now = 1800000000;
$registry = [];
foreach (['zero', 'offline', 'bad', 'other'] as $i => $key) {
    $registry[$key] = (object) ['enabled' => true, 'ip' => '127.0.0.1', 'port' => (string) (12000 + $i), 'name' => 'Fixture '.$key];
}
$results = [12000 => $status, 12001 => null, 12002 => ['players' => 5], 12003 => array_replace($status, ['players' => 7])];
$calls = [];
$probe = static function ($address, $port) use (&$results, &$calls) {
    $calls[] = [$address, $port];
    return $results[$port] === null ? reject(new RuntimeException('Offline')) : resolve($results[$port]);
};
$clock = static function () use (&$now): int { return $now; };
$directory = new Directory(['civ13.com' => ['zero', 'offline', 'bad'], 'valzargaming.com' => ['other']], $registry, $probe, $clock);
$endpoint = new Endpoint($directory);
$request = static fn ($method = 'GET', $host = 'civ13.com', $suffix = '') => new ServerRequest($method, 'https://'.$host.Directory::PATH.$suffix);
directoryCheck($directory->snapshot('civ13.com') === null && $endpoint->handle($request())->getStatusCode() === 503 && $calls === [], 'Warmup triggered a probe or reported online');
$directory->refresh();
$snapshot = $directory->snapshot('civ13.com');
directoryCheck(count($snapshot['servers']) === 1 && $snapshot['servers'][0]['players'] === 0, 'Zero-player server lost, or failed/partial probe included');
directoryCheck($snapshot['servers'][0]['round'] === ['id' => 'round-42', 'duration_seconds' => 1234], 'Round ticks conversion is incorrect');
directoryCheck($snapshot['servers'][0]['epoch'] === '1200', 'Public epoch was not projected');
directoryCheck($directory->snapshot('valzargaming.com')['servers'][0]['players'] === 7, 'Provider isolation failed');
directoryCheck(count($calls) === 4 && array_column($calls, 1) === [12000, 12001, 12002, 12003], 'Probe scanned or used unconfigured ports');
$response = $endpoint->handle($request()); $json = (string) $response->getBody();
directoryCheck($response->getStatusCode() === 200 && $response->getHeaderLine('Cache-Control') === 'no-store' && $response->getHeaderLine('X-Content-Type-Options') === 'nosniff', 'Endpoint cache/content contract differs');
directoryCheck(!str_contains($json, 'private-') && !str_contains($json, '127.0.0.1') && !str_contains($json, '_checked'), 'Private status or internal fields leaked');
directoryCheck(json_decode($json, true, 16, JSON_THROW_ON_ERROR) === $snapshot && strlen($json) < 65536, 'Invalid/oversized public JSON');
directoryCheck($snapshot['generated_at'] === gmdate('Y-m-d\TH:i:s\Z', $now) && strtotime($snapshot['expires_at']) === $now + 60, 'Timestamp contract differs');
foreach ([['POST', 'civ13.com', '', 405], ['GET', 'evil.invalid', '', 404], ['GET', 'civ13.com', '?host=evil.invalid&port=1', 400], ['GET', 'civ13.com', '/extra', 404]] as [$method, $host, $suffix, $expected]) {
    directoryCheck($endpoint->handle($request($method, $host, $suffix))->getStatusCode() === $expected, 'Request allowlist failed');
}
directoryCheck((string) $endpoint->handle($request('HEAD'))->getBody() === '' && count($calls) === 4, 'HEAD/request handlers performed work or returned a body');
$now += 59;
directoryCheck(strtotime($directory->snapshot('civ13.com')['expires_at']) === $now + 1, 'Reading cache extended observation lifetime');
$now++;
directoryCheck($endpoint->handle($request())->getStatusCode() === 503, 'Expired snapshot remained available');
$results[12000] = null; $directory->refresh();
directoryCheck($directory->snapshot('civ13.com')['servers'] === [], 'Last known good server survived a failed probe');
directoryCheck($directory->snapshot('valzargaming.com')['servers'][0]['players'] === 7, 'Partial failure lost healthy provider');

// Every malformed mandatory value drops only its server, never invents counts or rounds.
foreach (['players' => [-1, 1.5, '2', true, 10001, []], 'map_name' => ['', "bad\nmap", "bad\u{202e}map", "bad\u{2028}map", str_repeat('x', 129), "\xff", "\xed\xa0\x80", str_repeat('x', 125).'🌍'], 'map_gamemode' => [null, [], str_repeat('x', 81)], 'round_duration_in_ticks' => [-1, INF, NAN, '100', null, 2147483648], 'game_id' => [[], false, str_repeat('x', 81)]] as $field => $values) {
    foreach ($values as $value) {
        $results[12000] = array_replace($status, [$field => $value]);
        $directory->refresh();
        directoryCheck($directory->snapshot('civ13.com')['servers'] === [], 'Malformed '.$field.' was published');
    }
}
$results[12000] = $status; unset($results[12000]['game_id']); $directory->refresh();
directoryCheck($directory->snapshot('civ13.com')['servers'][0]['round']['id'] === '', 'Missing round ID should remain explicitly unavailable');
$results[12000]['game_id'] = 42; $directory->refresh();
directoryCheck($directory->snapshot('civ13.com')['servers'][0]['round']['id'] === '42', 'Numeric round ID not normalized');
$results[12000]['round_duration_in_ticks'] = 12345.5; $directory->refresh();
directoryCheck($directory->snapshot('civ13.com')['servers'][0]['round']['duration_seconds'] === 1234, 'Fractional deciseconds were not converted to whole seconds');
$results[12000]['map_name'] = str_repeat('x', 124).'🌍'; $directory->refresh();
directoryCheck($directory->snapshot('civ13.com')['servers'][0]['map'] === str_repeat('x', 124).'🌍', 'Valid supplementary Unicode at UTF-8 byte limit was rejected');

// A timer cannot launch overlapping sweeps, even while clients hammer the endpoint.
$waiting = new Deferred(); $startedProbes = 0;
$slow = new Directory(['civ13.com' => ['zero']], $registry, static function () use ($waiting, &$startedProbes) { $startedProbes++; return $waiting->promise(); }, $clock);
$pending = $slow->refresh();
directoryCheck($slow->refresh() === $pending && $startedProbes === 1 && $slow->snapshot('civ13.com') === null, 'Overlapping sweep was launched');
$now += 2; $waiting->resolve($status);
directoryCheck($slow->snapshot('civ13.com')['servers'][0]['checked_at'] === gmdate('Y-m-d\TH:i:s\Z', $now), 'Probe completion time was not used');
$now -= 3;
directoryCheck($slow->snapshot('civ13.com') === null, 'Backwards wall clock made future status look fresh');

foreach ([['evil.invalid' => ['zero']], ['civ13.com' => ['missing']], ['civ13.com' => ['zero', 'zero']], ['civ13.com' => ['zero'], 'valzargaming.com' => ['zero']], ['civ13.com' => 'zero']] as $config) {
    directoryThrows(static fn () => new Directory($config, $registry, $probe, $clock));
}
foreach (['enabled' => false, 'port' => '65536', 'ip' => 'https://evil.invalid/path', 'name' => "name\0secret"] as $field => $value) {
    $invalid = clone $registry['zero']; $invalid->$field = $value;
    directoryThrows(static fn () => new Directory(['civ13.com' => ['zero']], ['zero' => $invalid], $probe, $clock));
}
$empty = new Directory(['valzargaming.com' => []], [], $probe, $clock); $empty->refresh();
directoryCheck($empty->snapshot('valzargaming.com')['servers'] === [], 'Explicitly empty provider did not report zero online servers');
$large = [];
for ($i = 0; $i < 33; $i++) { $large['server'.$i] = clone $registry['zero']; $large['server'.$i]->port = (string) (13000 + $i); }
directoryThrows(static fn () => new Directory(['civ13.com' => array_keys($large)], $large, $probe, $clock));
$samePort = clone $registry['zero'];
directoryThrows(static fn () => new Directory(['civ13.com' => ['one', 'two']], ['one' => $samePort, 'two' => $samePort], $probe, $clock));

// Shared launcher/backend compatibility artifact, produced by this exact projection.
$fixture = json_decode(file_get_contents(__DIR__.'/fixtures/server-directory.json'), true, 16, JSON_THROW_ON_ERROR);
$fixtureServer = (object) ['enabled' => true, 'ip' => '127.0.0.1', 'port' => '1715', 'name' => 'Nomads'];
$fixtureDirectory = new Directory(['civ13.com' => ['nomads']], ['nomads' => $fixtureServer], static fn () => resolve($status), static fn () => strtotime('2026-10-19T17:00:00Z'));
$fixtureDirectory->refresh();
directoryCheck($fixtureDirectory->snapshot('civ13.com') === $fixture, 'Cross-language compatibility fixture differs from backend output');
$unicodeFixture = json_decode(file_get_contents(__DIR__.'/fixtures/server-directory-unicode.json'), true, 16, JSON_THROW_ON_ERROR);
$fixtureServer->name = 'Nomads 🌍';
$unicodeDirectory = new Directory(['civ13.com' => ['nomads']], ['nomads' => $fixtureServer], static fn () => resolve($status), static fn () => strtotime('2026-10-19T17:00:00Z'));
$unicodeDirectory->refresh();
directoryCheck($unicodeDirectory->snapshot('civ13.com') === $unicodeFixture, 'Supplementary Unicode fixture differs from backend output');
$fixtureServer->name = str_repeat('x', 116).'🌍';
new Directory(['civ13.com' => ['nomads']], ['nomads' => $fixtureServer], $probe, $clock);
$fixtureServer->name = str_repeat('x', 117).'🌍';
directoryThrows(static fn () => new Directory(['civ13.com' => ['nomads']], ['nomads' => $fixtureServer], $probe, $clock));

echo "PASS directory: configured targets, online/zero-player/offline, partial failure, malformed JSON/status, privacy allowlist, freshness/clock, atomic coalesced sweeps, provider/method/query isolation, fixed BYOND wire protocol, frame bounds and asynchronous deadlines.\n";
