<?php

declare(strict_types=1);
// Offline: real React HTTP parser and promises, injected transport, no bot bootstrap or credentials.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);

use Civ13\HttpErrorBoundary;
use Evenement\EventEmitter;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use React\EventLoop\StreamSelectLoop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
final class OfflineLogger extends AbstractLogger {
    public array $messages = [];
    public bool $fail = false;
    public function log($level, string|Stringable $message, array $context = []): void {
        if ($this->fail) { throw new RuntimeException('offline sink unavailable'); }
        $this->messages[] = (string) $message;
    }
}
final class OfflineSocket extends EventEmitter implements ServerInterface {
    public bool $closed = false;
    public function getAddress() { return 'tcp://127.0.0.1:0'; }
    public function pause() {}
    public function resume() {}
    public function close() { $this->closed = true; $this->emit('close'); }
}
final class OfflineConnection extends EventEmitter implements ConnectionInterface {
    public string $output = '';
    private bool $closed = false;
    public function isReadable() { return !$this->closed; }
    public function isWritable() { return !$this->closed; }
    public function pause() {}
    public function resume() {}
    public function pipe(WritableStreamInterface $dest, array $options = []) { return Util::pipe($this, $dest, $options); }
    public function write($data) { $this->output .= $data; return !$this->closed; }
    public function getRemoteAddress() { return 'tcp://127.0.0.1:1234'; }
    public function getLocalAddress() { return 'tcp://127.0.0.1:0'; }
    public function end($data = null) { if ($data !== null) { $this->write($data); } $this->close(); }
    public function close() { if (!$this->closed) { $this->closed = true; $this->emit('close'); $this->removeAllListeners(); } }
}
function send(OfflineSocket $socket, string $raw): OfflineConnection {
    check(!$socket->closed, 'Listener was closed by a request error');
    $connection = new OfflineConnection();
    $socket->emit('connection', [$connection]);
    $connection->emit('data', [$raw]);
    return $connection;
}
function settled(mixed $value): mixed {
    $result = null;
    $error = null;
    resolve($value)->then(function ($value) use (&$result) { $result = $value; }, function ($failure) use (&$error) { $error = $failure; });
    check($error === null, 'Request escaped as a rejected promise');
    return $result;
}
$logger = new OfflineLogger();
$secret = 'fixture-key+/';
$boundary = new HttpErrorBoundary($logger, [$secret]);
$request = new ServerRequest('GET', 'http://bot.invalid/webhook/test1714?key='.rawurlencode($secret).'&data=private-chat', ['Authorization' => 'Bearer header-secret'], 'private-body');
$ok = new Response(204);
check($boundary($request, fn () => $ok) === $ok, 'Synchronous response changed');
check(settled($boundary($request, fn () => resolve($ok))) === $ok, 'Promise response changed');
foreach ([
    fn () => throw new Exception('plain failure'),
    fn () => throw new TypeError('type failure'),
    fn () => reject(new Exception('rejected failure')),
    fn () => null,
    fn () => resolve('invalid response'),
] as $handler) {
    $response = settled($boundary($request, $handler));
    check($response->getStatusCode() === 500 && (string) $response->getBody() === 'Internal Server Error', 'Failure was not contained as a generic 500');
}
$deferred = new Deferred();
$pending = $boundary($request, fn () => $deferred->promise());
$deferred->reject(new Exception('late failure'));
check(settled($pending)->getStatusCode() === 500, 'Delayed rejection escaped');
check(str_contains(implode("\n", $logger->messages), 'GET /webhook/test1714'), 'Request context missing');

// Trace arguments may include secrets even when zend.exception_ignore_args is disabled.
ini_set('zend.exception_ignore_args', '0');
function privateArgument(string $argument): Throwable { return new Exception('original failure'); }
$cause = privateArgument('trace-secret');
$outer = new RuntimeException('wrapper /webhook/test1714?key=unknown-query-secret&data=private-chat token=plain-token '.$secret.' '.rawurlencode($secret), 0, $cause);
$boundary->report($outer, $request);
$last = end($logger->messages);
check(str_contains($last, 'RuntimeException: wrapper') && str_contains($last, 'Caused by: Exception: original failure') && str_contains($last, 'privateArgument()'), 'Exception chain or source frames missing');
foreach (['trace-secret', 'unknown-query-secret', 'plain-token', $secret, rawurlencode($secret), 'private-chat', 'private-body', 'header-secret'] as $private) {
    check(!str_contains(implode("\n", $logger->messages), $private), 'Private data entered diagnostic: '.$private);
}
$boundary->report(new Exception('payload {"token":"json-token", "password":"password with spaces", "client_secret":"json-client"} API_KEY=\'single quoted secret\' https://username:credential@service.invalid/path'));
foreach (['json-token', 'password with spaces', 'json-client', 'single quoted secret', 'username:credential'] as $private) {
    check(!str_contains(end($logger->messages), $private), 'Quoted/URL credential entered diagnostic');
}
$before = count($logger->messages);
$boundary->report(new RuntimeException('Received request with invalid protocol version'));
check(count($logger->messages) === $before, 'Ignored protocol error became noisy');
$logger->fail = true;
check(settled($boundary($request, fn () => throw new Exception('sink failure')))->getStatusCode() === 500, 'Logging failure escaped containment');
$logger->fail = false;

// The router's own catch must use the same safe chain logging instead of dumping trace arguments.
$records = new Monolog\Handler\TestHandler();
$router = (new ReflectionClass(Civ13\HttpHandler::class))->newInstanceWithoutConstructor();
$router->logger = new Monolog\Logger('offline-router', [$records]);
(new ReflectionProperty(Civ13\HttpHandler::class, 'key'))->setValue($router, $secret);
(new ReflectionProperty(Civ13\HttpHandler::class, 'attributes'))->setValue($router, ['handlers' => [], 'whitelisted' => [], 'match_methods' => [], 'descriptions' => [], 'whitelist' => ['127.0.0.1'], 'ratelimits' => []]);
$router->offsetSet('/router-fail', static function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use ($secret): Response {
    throw new RuntimeException('endpoint '.$secret, 0, new Exception('original endpoint cause'));
});
$routerRequest = new ServerRequest('GET', 'http://offline.invalid/router-fail?key='.rawurlencode($secret), [], '', '1.1', ['REMOTE_ADDR' => '127.0.0.1']);
check($router->handle($routerRequest)->getStatusCode() === 500, 'Router exception escaped');
$routerLog = implode("\n", array_map(static fn ($record) => $record['message'], $records->getRecords()));
check(str_contains($routerLog, 'GET /router-fail') && str_contains($routerLog, 'Caused by: Exception: original endpoint cause') && !str_contains($routerLog, $secret), 'Router lost safe context or original exception');

// Exercise real server integration with no network sockets. The same listener must serve the next request.
$loop = new StreamSelectLoop();
$socket = new OfflineSocket();
$server = new HttpServer($loop, $boundary, React\Async\async(static function (ServerRequestInterface $request): Response {
    if ($request->getUri()->getPath() === '/fail') { throw new Exception('application failure'); }
    return new Response(200, [], 'still alive');
}));
$server->on('error', $boundary->report(...));
$server->listen($socket);
$connection = send($socket, "GET /fail?key=fixture HTTP/1.1\r\nHost: offline.invalid\r\n\r\n");
check(str_starts_with($connection->output, 'HTTP/1.1 500'), 'Real async request did not get 500');
$connection = send($socket, "GET /ok HTTP/1.1\r\nHost: offline.invalid\r\n\r\n");
check(str_starts_with($connection->output, 'HTTP/1.1 200') && str_contains($connection->output, 'still alive'), 'Next request failed');

// React's implicit body-buffer middleware precedes ours. Its failure reaches only the one-argument error event.
$before = count($logger->messages);
$connection = send($socket, "POST /bad-body HTTP/1.1\r\nHost: offline.invalid\r\nTransfer-Encoding: chunked\r\n\r\n1\r\naXX");
check(str_starts_with($connection->output, 'HTTP/1.1 500'), 'Body-framing failure did not get an error response');
check(count($logger->messages) === $before + 1 && str_contains(end($logger->messages), 'Caused by:'), 'React wrapper lost its underlying error');
$connection = send($socket, "GET /ok HTTP/1.1\r\nHost: offline.invalid\r\n\r\n");
check(str_starts_with($connection->output, 'HTTP/1.1 200') && !$socket->closed, 'Middleware rejection stopped listener');
$socket->close();

// Static bootstrap assertion: never execute the credential-loading production entrypoint in tests.
$bootstrap = file_get_contents(__DIR__.'/../../bot.php');
check(str_contains($bootstrap, 'new HttpServer(Loop::get(), $httpErrors,'), 'Bootstrap omitted the request boundary');
check(str_contains($bootstrap, '$webapi->on(\'error\', $httpErrors->report(...));') && !str_contains($bootstrap, '[WEBAPI] ERROR - RESTART'), 'Bootstrap retained the crash-on-request-error listener');
echo "PASS WebAPI errors: sync/async/delayed/invalid responses, cause chain, redaction, broken logger, actual React parser and next-request survival\n";
