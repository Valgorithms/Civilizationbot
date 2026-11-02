<?php
declare(strict_types=1);
// Real HTTP parsers over injected memory streams. No sockets, DNS, bot bootstrap or native game.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
foreach (['Civ13','GameServer','CivHandler','HttpHandler','HttpServiceManager','GameReady'] as $class) { require_once __DIR__.'/../../src/Civ13/'.$class.'.php'; }

use Evenement\EventEmitter;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\StreamSelectLoop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\ServerInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

$checks = 0;
function wireCheck(bool $condition, string $message): void { global $checks; ++$checks; if (!$condition) { throw new RuntimeException($message); } }

/** Deterministic duplex stream; all deliveries are scheduled to avoid parser reentrancy. */
final class ReadyWireConnection extends EventEmitter implements ConnectionInterface
{
    public ?self $peer = null;
    private bool $open = true;
    private bool $paused = false;
    private array $buffer = [];
    public function __construct(public readonly string $side, private ReadyWireHub $hub) {}
    public function isReadable() { return $this->open; }
    public function isWritable() { return $this->open; }
    public function getRemoteAddress() { return 'tcp://127.0.0.1:17141'; }
    public function getLocalAddress() { return 'tcp://127.0.0.1:17142'; }
    public function pause() { $this->paused = true; }
    public function resume() { $this->paused = false; while ($this->buffer && $this->open && !$this->paused) { $this->emit('data', [array_shift($this->buffer)]); } }
    public function pipe(WritableStreamInterface $dest, array $options = []) { return Util::pipe($this, $dest, $options); }
    public function deliver(string $data): void
    {
        if (!$this->open) { return; }
        if ($this->paused) { if (count($this->buffer) >= 16) { throw new OverflowException('Fixture stream buffer cap'); } $this->buffer[] = $data; }
        else { $this->emit('data', [$data]); }
    }
    public function write($data)
    {
        if (!$this->open || !$this->peer?->isReadable()) { return false; }
        $this->hub->record($this->side, $data);
        if ($this->side === 'server' && $this->hub->dropReply) { $this->hub->dropReply = false; $this->close(); return false; }
        if ($this->side === 'server' && $this->hub->muteReply) { return true; }
        $peer = $this->peer;
        $this->hub->schedule(static fn () => $peer->deliver($data));
        return true;
    }
    public function end($data = null)
    {
        if ($data !== null) { $this->write($data); }
        if ($this->side === 'server' && $this->hub->muteReply) { return; }
        $this->hub->schedule(fn () => $this->close());
    }
    public function close()
    {
        if (!$this->open) { return; }
        $this->open = false; $this->buffer = [];
        $peer = $this->peer; $this->peer = null;
        $this->emit('close'); $this->removeAllListeners();
        if ($peer !== null && $peer->isReadable()) { $peer->emit('end'); $peer->close(); }
    }
}

/** In-memory acceptor and exact-address connector; never delegates to an OS connector. */
final class ReadyWireHub extends EventEmitter implements ServerInterface, ConnectorInterface
{
    public bool $dropReply = false;
    public bool $muteReply = false;
    public int $bytes = 0;
    public int $connections = 0;
    public int $ticks = 0;
    public string $requestBytes = '';
    private bool $closed = false;
    private bool $paused = false;
    private array $owned = [];
    public function __construct(public readonly StreamSelectLoop $loop) {}
    public function getAddress() { return $this->closed ? null : 'tcp://127.0.0.1:17141'; }
    public function pause() { $this->paused = true; }
    public function resume() { $this->paused = false; }
    public function close() { $this->closed = true; $this->closeConnections(); $this->removeAllListeners(); }
    public function connect($uri)
    {
        if ($uri !== '127.0.0.1:17141' && $uri !== 'tcp://127.0.0.1:17141') { return reject(new RuntimeException('Fixture address refusal')); }
        if ($this->closed || $this->paused || $this->active() !== 0 || ++$this->connections > 40) { return reject(new RuntimeException('Fixture connection admission refusal')); }
        $client = new ReadyWireConnection('client', $this); $server = new ReadyWireConnection('server', $this);
        $client->peer = $server; $server->peer = $client; $this->owned = [$client, $server]; $this->requestBytes = '';
        $this->schedule(function () use ($server): void { if (!$this->closed && $server->isReadable()) { $this->emit('connection', [$server]); } });
        return resolve($client);
    }
    public function schedule(callable $callback): void
    {
        if (++$this->ticks > 64) { throw new OverflowException('Fixture pending delivery cap'); }
        $this->loop->futureTick(function () use ($callback): void { --$this->ticks; $callback(); });
    }
    public function record(string $side, string $data): void
    {
        $this->bytes += strlen($data);
        if ($this->bytes > 131072 || strlen($data) > 4096) { throw new OverflowException('Fixture wire byte cap'); }
        if ($side === 'client') { $this->requestBytes .= $data; if (strlen($this->requestBytes) > 4096) { throw new OverflowException('Fixture request cap'); } }
    }
    public function active(): int { return count(array_filter($this->owned, static fn ($c) => $c->isReadable())); }
    public function closeConnections(): void { foreach ($this->owned as $connection) { $connection->close(); } $this->owned = []; }
    public function drain(): void
    {
        for ($i = 0; $this->ticks > 0 && $i < 64; ++$i) { $this->loop->futureTick(fn () => $this->loop->stop()); $this->loop->run(); }
        if ($this->ticks !== 0) { throw new RuntimeException('Fixture delivery cleanup incomplete'); }
    }
}

/** Isolated real dispatcher; no Civ13/Discord constructors or ambient profile are executed. */
function readyHttpFixture(?Civ13\GameReady $ledger = null): array
{
    $civ = (new ReflectionClass(Civ13\Civ13::class))->newInstanceWithoutConstructor();
    $server = (new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($server, 'key'))->setValue($server, 'test'); $server->enabled = true; $server->legacy_relay = false;
    $civ->options = ['http_key'=>'synthetic-wire-key','game_ready_events'=>['enabled'=>true]]; $civ->enabled_gameservers = ['test'=>$server];
    $manager = (new ReflectionClass(Civ13\HttpServiceManager::class))->newInstanceWithoutConstructor(); $manager->civ13 = $civ;
    $manager->logger = new Monolog\Logger('synthetic-ready-wire', [new Monolog\Handler\NullHandler()]);
    if ($ledger !== null) { (new ReflectionProperty($manager, 'gameReady'))->setValue($manager, $ledger); }
    $handler = (new ReflectionClass(Civ13\HttpHandler::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($handler, 'key'))->setValue($handler, 'synthetic-wire-key');
    (new ReflectionProperty($handler, 'attributes'))->setValue($handler, ['handlers'=>[],'whitelisted'=>[],'match_methods'=>[],'descriptions'=>[],'whitelist'=>['127.0.0.1'],'ratelimits'=>[]]);
    $handler->logger = $manager->logger; $manager->httpHandler = $handler;
    (new ReflectionMethod($handler, 'afterConstruct'))->invoke($handler);
    (new ReflectionMethod($manager, '__generateServerEndpoints'))->invoke($manager);
    return compact('civ','server','manager','handler');
}
function readyLedger(array $fixture): ?Civ13\GameReady { return (new ReflectionProperty($fixture['manager'], 'gameReady'))->getValue($fixture['manager']); }
/** Independent representative producer serialization; does not call consumer decode/canonical code. */
function readyWireAck(array $event): string { return md5(implode("\n", [$event['schema'],$event['event'],$event['server'],$event['boot'],$event['round'],$event['generation_kind'],$event['generation']])); }

$loop = new StreamSelectLoop(); $wire = new ReadyWireHub($loop); $fixture = readyHttpFixture();
$replyFault = null; $serverStatuses = []; $pending = null; $exchanges = 0; $maxPending = 0; $fatal = null;
$http = new HttpServer($loop, function ($request) use (&$fixture, &$replyFault, &$serverStatuses): ResponseInterface {
    $response = $fixture['handler']->handle($request); $serverStatuses[] = $response->getStatusCode();
    if (count($serverStatuses) > 40) { throw new OverflowException('Fixture request count cap'); }
    $fault = $replyFault; $replyFault = null;
    if ($fault === 'ack') { return $response->withHeader('X-Civ13-Game-Ready', str_repeat('0', 32)); }
    if ($fault === 'body') { return new Response($response->getStatusCode(), $response->getHeaders(), str_repeat('x', 2048)); }
    return $response;
});
$http->on('error', static function (Throwable $error) use (&$fatal, $loop): void { $fatal = $error; $loop->stop(); });
$http->listen($wire);
$browser = (new Browser($wire, $loop))->withFollowRedirects(false)->withRejectErrorResponse(false)->withTimeout(0.1)->withResponseBuffer(512);
$deadline = $loop->addTimer(5.0, static function () use (&$fatal, &$pending, $wire, $loop): void {
    $fatal = new RuntimeException('Private fixture deadline exceeded'); $pending?->cancel(); $wire->closeConnections(); $loop->stop();
});
$exchange = function (array $event, string $path = '/webhook/test', string $method = 'GET', string $key = 'synthetic-wire-key') use ($loop, $wire, $browser, &$pending, &$exchanges, &$maxPending, &$fatal): array {
    wireCheck($pending === null && $wire->active() === 0, 'Only one owned exchange may be pending');
    if (++$exchanges > 40) { throw new OverflowException('Fixture exchange cap'); }
    $url = 'http://127.0.0.1:17141'.$path.'?'.http_build_query(['key'=>$key,'method'=>'game_ready','data'=>json_encode($event, JSON_THROW_ON_ERROR)], '', '&', PHP_QUERY_RFC3986);
    $response = null; $error = null; $settled = false;
    try {
        $pending = $browser->request($method, $url, ['Connection'=>'close']); $maxPending = max($maxPending, 1);
        $pending->then(static function ($value) use (&$response, &$settled, $loop): void { $response = $value; $settled = true; $loop->stop(); },
            static function (Throwable $value) use (&$error, &$settled, $loop): void { $error = $value; $settled = true; $loop->stop(); });
        $loop->run();
        if ($fatal !== null) { throw $fatal; }
        wireCheck($settled, 'HTTP exchange must settle before proceeding');
        return ['response'=>$response,'error'=>$error];
    } finally {
        $pending?->cancel(); $pending = null; $wire->dropReply = false; $wire->muteReply = false;
        $wire->closeConnections(); $wire->drain();
        wireCheck($wire->active() === 0 && $wire->ticks === 0, 'Owned streams and scheduled deliveries must close between requests');
    }
};
/** Finite settled-attempt model only; not the actual DM worker or its world-time behavior. */
$producer = function (array $event, ?callable $clock = null) use ($exchange): array {
    $clock ??= static fn () => 0; $start = $clock(); $expected = readyWireAck($event);
    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        if ($clock() - $start >= 120) { return ['state'=>'expired','attempts'=>$attempt - 1]; }
        $result = $exchange($event); $response = $result['response'];
        if ($response !== null) {
            $status = $response->getStatusCode();
            if (in_array($status, [200,202], true) && hash_equals($expected, $response->getHeaderLine('X-Civ13-Game-Ready'))) { return ['state'=>'acknowledged','attempts'=>$attempt,'status'=>$status]; }
            if (in_array($status, [400,403,404,405,409], true)) { return ['state'=>'refused','attempts'=>$attempt,'status'=>$status]; }
        }
    }
    return ['state'=>'exhausted','attempts'=>3];
};
$event = ['schema'=>'game_ready.v1','event'=>'game_ready','server'=>'test','boot'=>str_repeat('a',32),'round'=>'SYNTHETIC_ROUND_1','generation_kind'=>'restored','generation'=>'round-v2:'.str_repeat('b',32)];
try {
    $first = $exchange($event)['response'];
    wireCheck($first?->getStatusCode() === 202 && $first->getHeaderLine('X-Civ13-Game-Ready') === '6507e6f19da8d2fbd693073ad59f102f', 'Canonical producer vector must survive actual HTTP encoding/parsing');
    $body = json_decode((string) $first->getBody(), true, 4, JSON_THROW_ON_ERROR);
    wireCheck($body['event_id'] === '25400882f031d8046b93c16ada06c8723ce5efb9358f381e115812bcd180bc64' && $body['state'] === 'queued' && $body['delivery'] === 'unconfigured', 'Independent canonical receipt vector');
    wireCheck(str_starts_with($wire->requestBytes, 'GET /webhook/test?') && str_contains($wire->requestBytes, '%3A') && str_contains($wire->requestBytes, 'HTTP/1.1'), 'Real Browser serializes the keyed query, not a prebuilt PSR request');
    wireCheck($exchange(array_reverse($event, true), '/webhook/test/game_ready')['response']?->getStatusCode() === 200, 'Direct alias and field-order normalization share the ledger');

    foreach (['lost','ack','body','timeout'] as $fault) {
        $fixture = readyHttpFixture(); $before = count($serverStatuses);
        if ($fault === 'lost') { $wire->dropReply = true; }
        elseif ($fault === 'timeout') { $wire->muteReply = true; }
        else { $replyFault = $fault; }
        $result = $producer($event);
        wireCheck($result === ['state'=>'acknowledged','attempts'=>2,'status'=>200], 'Settled lost/invalid/oversized/silent acknowledgement retries as a duplicate: '.$fault);
        wireCheck(array_slice($serverStatuses, $before) === [202,200] && readyLedger($fixture)?->count() === 1, 'Failed receipt delivery must not duplicate the queued observation: '.$fault);
    }
    $conflict = $event; $conflict['round'] = 'OTHER_ROUND';
    wireCheck($producer($conflict) === ['state'=>'refused','attempts'=>1,'status'=>409], 'Conflicting same-boot identity is permanent and never retried');

    $fixture = readyHttpFixture(); $owner = $fixture['server']; $replacement = (new ReflectionClass(Civ13\GameServer::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty($replacement, 'key'))->setValue($replacement, 'test'); $replacement->enabled = true;
    $fixture['civ']->enabled_gameservers = ['test'=>$replacement];
    wireCheck($producer($event) === ['state'=>'exhausted','attempts'=>3] && readyLedger($fixture) === null, 'Stale captured enabled instance refuses all three bounded attempts without reservation');
    $fixture['civ']->enabled_gameservers = ['test'=>$owner];
    wireCheck($producer($event) === ['state'=>'acknowledged','attempts'=>1,'status'=>202], 'Current owner can reserve after stale requests settle');

    $fixture = readyHttpFixture(); $fixture['civ']->options['game_ready_events'] = ['enabled'=>false];
    wireCheck($exchange($event)['response']?->getStatusCode() === 503 && readyLedger($fixture) === null, 'Disabled receiver refuses over real HTTP without allocating a ledger');
    $fixture['civ']->options['game_ready_events'] = ['enabled'=>true];
    wireCheck($exchange($event, '/webhook/test', 'GET', 'wrong')['response']?->getStatusCode() === 403, 'HTTP loopback identity cannot replace the outer key');
    wireCheck($exchange($event, '/webhook/other')['response']?->getStatusCode() === 404, 'Wrong route is refused by actual dispatcher');
    wireCheck($exchange($event, '/webhook/test', 'POST')['response']?->getStatusCode() === 405, 'HTTP verb is preserved and refused');

    $fixture = readyHttpFixture(); $old = $exchange($event)['response']; $fixture = readyHttpFixture(); $new = $exchange($event)['response'];
    wireCheck($old?->getStatusCode() === 202 && $new?->getStatusCode() === 202 && $old->getHeaderLine('X-Civ13-Game-Ready') === $new->getHeaderLine('X-Civ13-Game-Ready') && readyLedger($fixture)?->count() === 1, 'Receiver replacement loses the ledger and permits replay; no restart durability claim');

    $now = 1700000000; $ledger = new Civ13\GameReady(static function () use (&$now): int { return $now; }, 2, 120); $fixture = readyHttpFixture($ledger);
    $second = $event; $second['boot'] = str_repeat('c',32); $third = $event; $third['boot'] = str_repeat('d',32);
    wireCheck($exchange($event)['response']?->getStatusCode() === 202 && $exchange($second)['response']?->getStatusCode() === 202, 'Finite ledger fills with distinct boots');
    wireCheck($exchange($event)['response']?->getStatusCode() === 200, 'Capacity still acknowledges existing duplicate');
    wireCheck($exchange($third)['response']?->getStatusCode() === 503 && $ledger->count() === 2, 'Capacity refuses a new boot without eviction');
    wireCheck($exchange($conflict)['response']?->getStatusCode() === 409, 'Conflict remains a conflict when full');
    $now += 120;
    wireCheck($exchange($event)['response']?->getStatusCode() === 202 && $ledger->count() === 1, 'Retention expiry permits replay, not durable deduplication');
    --$now;
    wireCheck($exchange($event)['response']?->getStatusCode() === 503 && $ledger->count() === 1, 'Clock rollback refuses acceptance without losing known state');

    $defaultNow = 1700000000; $defaultLedger = new Civ13\GameReady(static function () use (&$defaultNow): int { return $defaultNow; }); $allAdmitted = true;
    for ($i = 0; $i < 256; ++$i) { $prefill = $event; $prefill['boot'] = sprintf('%032x', $i); $allAdmitted = $allAdmitted && $defaultLedger->receive(json_encode($prefill, JSON_THROW_ON_ERROR), 'test')['status'] === 202; }
    wireCheck($allAdmitted && $defaultLedger->count() === 256, 'Actual default ledger holds exactly 256 finite synthetic records');
    $fixture = readyHttpFixture($defaultLedger); $existing = $event; $existing['boot'] = str_repeat('0',32);
    wireCheck($exchange($event)['response']?->getStatusCode() === 503 && $exchange($existing)['response']?->getStatusCode() === 200 && $defaultLedger->count() === 256, 'Actual default full ledger refuses new HTTP identity while preserving duplicate');
    $defaultNow += 86400;
    wireCheck($exchange($event)['response']?->getStatusCode() === 202 && $defaultLedger->count() === 1, 'Actual 24-hour default retention permits replay at expiry');

    $fixture = readyHttpFixture(); $fresh = $event; $fresh['generation_kind'] = 'fresh'; $fresh['generation'] = 'fresh:'.$fresh['boot'];
    $freshResponse = $exchange($fresh)['response'];
    wireCheck($freshResponse?->getStatusCode() === 202 && $freshResponse->getHeaderLine('X-Civ13-Game-Ready') === readyWireAck($fresh), 'Fresh-start identity survives the same HTTP/correlation contract');
    $fresh['generation'] = 'fresh:'.str_repeat('e',32);
    wireCheck($exchange($fresh)['response']?->getStatusCode() === 400, 'Fresh generation cannot claim a different boot');

    $fixture = readyHttpFixture(); $fixture['civ']->options['game_ready_events'] = ['enabled'=>false]; $times = [0,0,121];
    wireCheck($producer($event, static function () use (&$times): int { return array_shift($times); }) === ['state'=>'expired','attempts'=>1], 'Admission expiry prevents the next retry; no native hard timeout claim');
    $refused = false; $wire->connect('tcp://example.invalid:80')->then(null, static function () use (&$refused): void { $refused = true; });
    wireCheck($refused && $wire->active() === 0, 'Transport cannot delegate any alternate address to a real connector');
    wireCheck($maxPending === 1 && $exchanges <= 40 && $wire->bytes <= 131072, 'Finite one-outstanding, request-count and byte budgets');
} finally {
    $pending?->cancel(); $wire->close(); $wire->drain(); $loop->cancelTimer($deadline);
    wireCheck($wire->active() === 0 && $wire->ticks === 0 && $wire->getAddress() === null, 'Final owned transport cleanup is confirmed');
}
echo json_encode(['result'=>'passed','checks'=>$checks,'http_exchanges'=>$exchanges,'wire_bytes'=>$wire->bytes,'max_pending'=>$maxPending,'cleanup'=>'closed','transport'=>'in-memory real ReactPHP HTTP parsers','native_producer'=>false,'external_delivery'=>false], JSON_THROW_ON_ERROR)."\n";