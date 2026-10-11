<?php

declare(strict_types=1);

// Optional autoloader argument supports a bounded before/after dependency probe.
// No bot bootstrap, .env, DNS, sockets, credentials or production HTTP requests.
require $argv[1] ?? __DIR__.'/../vendor/autoload.php';

use Composer\InstalledVersions;
use Evenement\EventEmitter;
use React\EventLoop\StreamSelectLoop;
use React\Http\Browser;
use React\Http\Io\ChunkedDecoder;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;
use React\Stream\Util;

use function React\Promise\resolve;

function httpCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FixtureHttpConnection extends EventEmitter implements ConnectionInterface
{
    public string $request = '';
    private bool $closed = false;

    public function isReadable() { return !$this->closed; }
    public function isWritable() { return !$this->closed; }
    public function pause() {}
    public function resume() {}
    public function pipe(WritableStreamInterface $dest, array $options = []) { return Util::pipe($this, $dest, $options); }

    public function write($data)
    {
        $this->request .= $data;
        return $this->isWritable();
    }

    public function getRemoteAddress() { return 'tcp://offline.invalid:80'; }
    public function getLocalAddress() { return 'tcp://offline.invalid:1'; }
    public function end($data = null)
    {
        if ($data !== null) { $this->write($data); }
        $this->close();
    }
    public function close()
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->emit('close');
            $this->removeAllListeners();
        }
    }
}

/** Exercise the real Browser client parser, injecting only its transport. */
function httpResponse(array $chunks): array
{
    $connection = new FixtureHttpConnection();
    $connector = new class($connection) implements ConnectorInterface {
        public function __construct(private FixtureHttpConnection $connection) {}
        public function connect($uri) { return resolve($this->connection); }
    };
    $result = ['response' => null, 'error' => null];
    $browser = (new Browser($connector, new StreamSelectLoop()))->withTimeout(false)->withFollowRedirects(false);
    $promise = $browser->get('http://offline.invalid/test')->then(
        function ($response) use (&$result) { $result['response'] = $response; },
        function ($error) use (&$result) { $result['error'] = $error; },
    );
    httpCheck(str_starts_with($connection->request, 'GET /test HTTP/1.1'), 'Injected Browser did not serialize the request.');
    foreach ($chunks as $chunk) {
        $connection->emit('data', [$chunk]);
    }
    // Close unresolved fixture requests too; never run an unbounded event loop.
    $connection->close();
    unset($promise, $browser);
    return $result;
}

// Legitimate body bytes in the same emission must not count as header bytes.
$body = str_repeat('A', 70000);
$result = httpResponse(["HTTP/1.1 200 OK\r\nContent-Length: 70000\r\n\r\n".$body]);
httpCheck($result['error'] === null && (string) $result['response']->getBody() === $body, 'Ordinary response body was rejected by header limit.');

// Bounded 64KiB probes. The vulnerable baseline fails here before chunk probes.
foreach ([
    ["HTTP/1.1 200 OK\r\nX-Fill: ", str_repeat('A', 65536)],
    ["HTTP/1.1 200 OK\r\nX-Fill: ".str_repeat('A', 65536)."\r\n\r\n"],
    ["HTTP/1.1 200 OK\nX-Fill: ".str_repeat('A', 65536)."\n\n"],
] as $chunks) {
    $result = httpResponse($chunks);
    httpCheck($result['error'] instanceof OverflowException, 'Oversized response headers were not rejected with overflow.');
}

// Browser must propagate malformed chunk framing through its rejection path.
$result = httpResponse(["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n1\r\naXX"]);
httpCheck($result['error'] instanceof Exception && $result['response'] === null, 'Malformed chunk delimiter was accepted.');
$result = httpResponse(["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n0001;fixture=yes\r\na\r", "\n0000\r\n\r\n"]);
httpCheck($result['error'] === null && (string) $result['response']->getBody() === 'a', 'Fragmented valid chunked response failed.');

// The same decoder is used for inbound requests and outbound responses.
$input = new ThroughStream();
$decoder = new ChunkedDecoder($input);
$ended = false;
$error = null;
$decoder->on('end', function () use (&$ended) { $ended = true; });
$decoder->on('error', function ($failure) use (&$error) { $error = $failure; });
$input->write("0\r\nX");
httpCheck(!$ended && $error === null && $decoder->isReadable(), 'Incomplete trailer did not wait for more input.');
$input->write("\r\n\r\n");
httpCheck($ended && $error === null, 'Fragmented trailer did not finish normally.');
$input->close();

$input = new ThroughStream();
$decoder = new ChunkedDecoder($input);
$error = null;
$decoder->on('error', function ($failure) use (&$error) { $error = $failure; });
$input->write("0\r\n".str_repeat('X', 1025));
httpCheck($error instanceof Exception && !$decoder->isReadable(), 'Oversized unterminated trailer was accepted.');
$input->close();

httpCheck(version_compare(InstalledVersions::getVersion('react/http'), '1.11.1', '>='), 'HTTP dependency is below the patched floor.');
echo "HTTP dependency checks passed: injected Browser, header bounds/CRLF/LF, legitimate body/chunks, malformed delimiter, fragmented/oversized trailer.\n";
