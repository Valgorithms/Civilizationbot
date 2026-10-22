<?php

declare(strict_types=1);

// Injected DNS/TCP, registries and clocks only. No external I/O or bot activation.
require $argv[1] ?? __DIR__.'/../vendor/autoload.php';

use Civ13\ServerDirectory\ByondStatusProbe;
use Civ13\ServerDirectory\Directory;
use Civ13\ServerDirectory\PublicResolver;
use Evenement\EventEmitter;
use React\Dns\Model\Message;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

use function React\Promise\resolve;
use function React\Promise\reject;

function remoteCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function remoteThrows(callable $callback): void { try { $callback(); } catch (Throwable) { return; } throw new RuntimeException('Expected config rejection'); }

$status = ['players' => 0, 'map_name' => 'Gaia', 'map_gamemode' => 'Nomads', 'game_id' => 'round-42', 'round_duration_in_ticks' => 12345, 'map_age' => 1200];
$now = 1800000000;
$clock = static function () use (&$now): int { return $now; };
$registry = ['managed' => (object) ['enabled' => true, 'ip' => '127.0.0.1', 'port' => '24017', 'name' => 'Managed fixture']];
$target = ['id' => 'vzg-fixture', 'name' => 'VZG fixture', 'host' => 'valzargaming.com', 'port' => 24017, 'engine' => 'BYOND'];
$calls = []; $remoteHealthy = true;
$probe = static function ($address, $port, $publicOnly) use (&$calls, &$remoteHealthy, $status) {
    $calls[] = [$address, $port, $publicOnly];
    return $publicOnly && !$remoteHealthy ? reject(new RuntimeException('Remote offline')) : resolve($status);
};
$directory = new Directory(['civ13.com' => ['managed']], $registry, $probe, $clock, [$target]);
$directory->refresh();
remoteCheck($calls === [['127.0.0.1', 24017, false], ['valzargaming.com', 24017, true]], 'Probe destinations/public-only mode differ from explicit inventory');
remoteCheck(count($directory->snapshot('civ13.com')['servers']) === 1 && count($directory->snapshot('valzargaming.com')['servers']) === 1, 'Same port on different approved hosts should coexist');
$remoteHealthy = false; $directory->refresh();
remoteCheck($directory->snapshot('valzargaming.com')['servers'] === [] && count($directory->snapshot('civ13.com')['servers']) === 1, 'Remote failure retained stale entry or removed managed server');

foreach (['ip' => '127.0.0.1', 'url' => 'https://example.invalid', 'basedir' => '/tmp', 'enabled' => true, 'token' => 'secret'] as $field => $value) {
    remoteThrows(static fn () => new Directory([], [], $probe, $clock, [$target + [$field => $value]]));
}
foreach (['host' => ['evil.invalid', 'localhost', '127.0.0.1', 'https://valzargaming.com', 'valzargaming.com:1714', 'VALZARGAMING.COM', 'valzargaming.com.', []], 'port' => [0, 65536, '24017', true, 24017.0], 'engine' => ['OpenDream', 'byond', ''], 'name' => ['', "bad\nname"], 'id' => ['', '../bad']] as $field => $values) {
    foreach ($values as $value) {
        remoteThrows(static fn () => new Directory([], [], $probe, $clock, [array_replace($target, [$field => $value])]));
    }
}
$missing = $target; unset($missing['engine']);
remoteThrows(static fn () => new Directory([], [], $probe, $clock, [$missing]));
remoteThrows(static fn () => new Directory([], [], $probe, $clock, ['named' => $target]));
remoteThrows(static fn () => new Directory([], [], $probe, $clock, [$target, $target]));
remoteThrows(static fn () => new Directory(['civ13.com' => ['managed']], $registry, $probe, $clock, [array_replace($target, ['id' => 'managed'])]));
remoteThrows(static fn () => new Directory(['civ13.com' => ['managed']], $registry, $probe, $clock, [array_replace($target, ['host' => 'civ13.com'])]));
$many = [];
for ($i = 0; $i < 31; $i++) { $many[] = array_replace($target, ['id' => 'remote'.$i, 'port' => 25000 + $i]); }
new Directory(['civ13.com' => ['managed']], $registry, $probe, $clock, $many);
$many[] = array_replace($target, ['id' => 'remote31', 'port' => 25031]);
remoteThrows(static fn () => new Directory(['civ13.com' => ['managed']], $registry, $probe, $clock, $many));

// An early local result must never acquire the later completion time of a peer.
$late = new Deferred(); $sweeps = 0;
$slow = new Directory(['civ13.com' => ['managed']], $registry, static function ($address) use ($late, $status, &$sweeps) { $sweeps++; return $address === '127.0.0.1' ? resolve($status) : $late->promise(); }, $clock, [$target]);
$pending = $slow->refresh(); remoteCheck($pending === $slow->refresh() && $sweeps === 2, 'Overlapping mixed-source sweep launched');
$early = $now; $now += 59; $late->resolve($status);
remoteCheck(strtotime($slow->snapshot('civ13.com')['expires_at']) === $early + 60, 'Batch completion extended earlier probe lifetime');
remoteCheck(strtotime($slow->snapshot('valzargaming.com')['servers'][0]['checked_at']) === $now, 'Remote completion timestamp was lost');
$now += 2;
remoteCheck($slow->snapshot('civ13.com')['servers'] === [] && count($slow->snapshot('valzargaming.com')['servers']) === 1, 'Old entry survived peer freshness');

final class ChangingDirectoryResolver implements ResolverInterface
{
    public array $addresses = ['93.184.215.14'];
    public int $calls = 0;
    public function resolve($domain) { $this->calls++; return resolve($this->addresses[0]); }
    public function resolveAll($domain, $type) { $this->calls++; return $type === Message::TYPE_A ? resolve($this->addresses) : reject(new RuntimeException('No fixture AAAA record')); }
}
$raw = new ChangingDirectoryResolver(); $public = new PublicResolver($raw);
foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.0.1', '169.254.0.1', '0.0.0.0', '100.64.0.1', '192.0.0.9', '192.88.99.1', '192.31.196.1', '192.52.193.1', '192.175.48.1', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '240.0.0.1', '255.255.255.255', '::', '::1', 'fc00::1', 'fe80::1', 'ff02::1', '2001:db8::1', '2001::1', '2002:7f00:1::1', '3fff::1', '4000::1', '64:ff9b::7f00:1', '::ffff:127.0.0.1', '2606:4700:4700::1111'] as $invalid) {
    $raw->addresses = [$invalid]; $failed = false;
    $public->resolve('valzargaming.com')->then(null, static function () use (&$failed) { $failed = true; });
    remoteCheck($failed, 'Non-global address accepted: '.$invalid);
    $raw->addresses = ['93.184.215.14', $invalid]; $failed = false;
    $public->resolveAll('valzargaming.com', Message::TYPE_A)->then(null, static function () use (&$failed) { $failed = true; });
    remoteCheck($failed, 'Mixed public/non-global answer was accepted');
}

foreach (['93.184.215.14', '93.184.215.15'] as $valid) {
    $raw->addresses = [$valid]; $actual = null;
    $public->resolve('valzargaming.com')->then(static function ($address) use (&$actual) { $actual = $address; });
    remoteCheck($actual === $valid, 'Ordinary public IPv4 rejected');
}

foreach ([[], array_fill(0, 33, '93.184.215.14'), ['not-a-list' => '93.184.215.14']] as $invalidAnswers) {
    $raw->addresses = $invalidAnswers; $failed = false;
    $public->resolve('valzargaming.com')->then(null, static function () use (&$failed) { $failed = true; });
    remoteCheck($failed, 'Unbounded/malformed DNS answers accepted');
}

foreach (['evil.invalid', '127.0.0.1', 'VALZARGAMING.COM'] as $domain) {
    $before = $raw->calls; $failed = false;
    $public->resolve($domain)->then(null, static function () use (&$failed) { $failed = true; });
    remoteCheck($failed && $raw->calls === $before, 'Unapproved hostname reached DNS');
}

final class RemoteDirectoryConnection extends EventEmitter implements ConnectionInterface
{
    public bool $closed = false;
    public function __construct(private StreamSelectLoop $loop, private string $reply) {}
    public function isReadable() { return !$this->closed; }
    public function isWritable() { return !$this->closed; }
    public function pause() {}
    public function resume() {}
    public function pipe(WritableStreamInterface $dest, array $options = []) { return Util::pipe($this, $dest, $options); }
    public function write($data) { $this->loop->futureTick(fn () => $this->emit('data', [$this->reply])); return !$this->closed; }
    public function getRemoteAddress() { return 'tcp://93.184.215.14:24017'; }
    public function getLocalAddress() { return 'tcp://127.0.0.1:1'; }
    public function end($data = null) { $this->close(); }
    public function close() { if (!$this->closed) { $this->closed = true; $this->emit('close'); $this->removeAllListeners(); } }
}
$loop = new StreamSelectLoop();
$body = "\x06".json_encode($status, JSON_THROW_ON_ERROR)."\0";
$wire = "\0\x83".pack('n', strlen($body)).$body;
$tcp = new class($loop, $wire) implements ConnectorInterface {
    public array $uris = [];
    public function __construct(private StreamSelectLoop $loop, private string $reply) {}
    public function connect($uri) { $this->uris[] = $uri; return resolve(new RemoteDirectoryConnection($this->loop, $this->reply)); }
};
$resolver = new ChangingDirectoryResolver();
$connector = ByondStatusProbe::createConnector($loop, $resolver, $tcp, true);
$probe = new ByondStatusProbe($loop, $tcp, $connector);
foreach (['93.184.215.14', '93.184.215.15'] as $address) {
    $resolver->addresses = [$address]; $before = $resolver->calls; $value = null; $error = null;
    $probe('valzargaming.com', 24017, true)->then(static function ($result) use (&$value) { $value = $result; }, static function ($failure) use (&$error) { $error = $failure; });
    $loop->run();
    remoteCheck($value === $status && $error === null && $resolver->calls > $before, 'DNS was pinned/cached across probes or valid reply failed');
    remoteCheck(parse_url(end($tcp->uris), PHP_URL_HOST) === $address, 'TCP did not use the validated address');
}
$resolver->addresses = ['127.0.0.1']; $count = count($tcp->uris); $failed = false;
$probe('valzargaming.com', 24017, true)->then(null, static function () use (&$failed) { $failed = true; }); $loop->run();
remoteCheck($failed && count($tcp->uris) === $count, 'Private DNS reached TCP');
$failed = false; $probe('127.0.0.1', 24017, true)->then(null, static function () use (&$failed) { $failed = true; });
remoteCheck($failed && count($tcp->uris) === $count, 'Public-only probe accepted a literal address');

$fixture = json_decode(file_get_contents(__DIR__.'/fixtures/server-directory-vzg.json'), true, 16, JSON_THROW_ON_ERROR);
$directory = new Directory([], [], static fn () => resolve($status), static fn () => strtotime('2026-10-19T17:00:00Z'), [$target]);
$directory->refresh(); remoteCheck($directory->snapshot('valzargaming.com') === $fixture, 'Shared VZG fixture differs from status-only projection');
echo "PASS remote directory: canonical configured inventory, no GameServer activation, cross-source uniqueness/bounds, partial failures, per-probe freshness, dynamic public DNS and validated TCP binding.\n";
