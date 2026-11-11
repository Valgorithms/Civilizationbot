<?php
declare(strict_types=1);
// No bootstrap, .env, sockets, Discord client or outbound lookups: requests are synthetic objects.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);
$loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14', true);
use Civ13\Civ13;
use Civ13\HttpHandler;
use Civ13\IPToCountryResolver;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
// Deprecations raised while loading third-party packages are not this test's subject.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { throw new ErrorException($message, 0, $severity, $file, $line); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function inert(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
final class HttpSafetyBot extends Civ13 {
    public array $lookups = [];
    public function VarSave(string $filename, array $assoc_array = []): bool { $this->lookups[] = $filename; return true; }
}
function handler(string $key = ''): HttpHandler {
    $handler = inert(HttpHandler::class);
    $handler->civ13 = inert(HttpSafetyBot::class);
    $handler->logger = new Monolog\Logger('offline-http', [new Monolog\Handler\NullHandler()]);
    (new ReflectionProperty(HttpHandler::class, 'attributes'))->setValue($handler, ['handlers' => [], 'whitelisted' => [], 'match_methods' => [], 'descriptions' => [], 'whitelist' => [], 'ratelimits' => []]);
    (new ReflectionProperty(HttpHandler::class, 'key'))->setValue($handler, $key);

    return $handler;
}
function attributes(HttpHandler $handler): array { return (new ReflectionProperty(HttpHandler::class, 'attributes'))->getValue($handler); }
function request(string $path, string $ip, array $query = []): ServerRequest {
    return (new ServerRequest('GET', 'http://bot.invalid'.$path, [], '', '1.1', ['REMOTE_ADDR' => $ip]))->withQueryParams($query);
}
check(str_replace('\\', '/', (new ReflectionClass(HttpHandler::class))->getFileName()) === str_replace('\\', '/', realpath(__DIR__.'/../../src/Civ13/HttpHandler.php')), 'Test loaded another checkout');

// Requests that left the window are forgotten, both when checking and when recording.
$handler = handler();
$handler->setRateLimit('global10minutes', 3, 600);
$now = time();
$attributes = attributes($handler);
for ($i = 0; $i < 500; $i++) {
    $attributes['ratelimits']['global10minutes']['requests'][] = ['ip' => '203.0.113.9', 'time' => $now - 601 - $i, 'status' => null];
}
$attributes['ratelimits']['global10minutes']['requests'][] = ['ip' => '203.0.113.9', 'time' => $now - 5, 'status' => null];
(new ReflectionProperty(HttpHandler::class, 'attributes'))->setValue($handler, $attributes);
check($handler->__getRateLimitExpiration('global10minutes', '203.0.113.9') === null, 'Expired requests still counted against the caller');
check(count(attributes($handler)['ratelimits']['global10minutes']['requests']) === 1, 'Expired requests were not pruned on check');
check($handler->isRateLimited('global10minutes', '203.0.113.9') === null, 'Second request was limited');
check($handler->isRateLimited('global10minutes', '203.0.113.9') === null, 'Third request was limited');
$retry = $handler->isRateLimited('global10minutes', '203.0.113.9');
check(is_int($retry) && $retry > 0 && $retry <= 600, 'Fourth request inside the window was not limited');
check($handler->__getRateLimitExpiration('global10minutes', '198.51.100.7') === null, 'Another address inherited the limit');
check(count(attributes($handler)['ratelimits']['global10minutes']['requests']) === 4, 'Live requests were lost while pruning');
check($handler->__getRateLimitExpiration('undefined', '203.0.113.9') === null, 'Undefined limit reported a block');

// A prefix endpoint matches paths beneath it, and never a shorter path that merely prefixes the endpoint.
$handler = handler();
$handler->setRateLimit('global10minutes', 10000, 600)->setRateLimit('invalid', 1000, 300)->setRateLimit('abuse', 1000, 86400);
$hits = [];
$handler->offsetSet('/files/', static function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$hits): Response { $hits[] = $endpoint; return new Response(200); }, false, 'str_starts_with');
$handler->offsetSet('/ping', static function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$hits): Response { $hits[] = $endpoint; return new Response(200); }, false, 'exact');
check($handler->handle(request('/files/readme.txt', '203.0.113.9'))->getStatusCode() === 200 && $hits === ['/files/'], 'Path beneath a prefix endpoint did not match');
check($handler->handle(request('/', '203.0.113.9'))->getStatusCode() === 404 && $hits === ['/files/'], 'Root path reached a prefix endpoint');
check($handler->handle(request('/fil', '203.0.113.9'))->getStatusCode() === 404, 'Partial path reached a prefix endpoint');
check($handler->handle(request('/ping', '203.0.113.9'))->getStatusCode() === 200 && $hits === ['/files/', '/ping'], 'Exact endpoint stopped matching');
check($handler->handle(request('/ping/extra', '203.0.113.9'))->getStatusCode() === 404, 'Exact endpoint matched a longer path');

// The shared secret authenticates a public caller, is compared as a string only, and is never dumped.
$handler = handler('fixture-secret');
$public = '203.0.113.9';
check($handler->__isWhitelisted(request('/x', $public, ['data' => json_encode(['key' => 'fixture-secret'])]), $public), 'Correct key was refused');
foreach ([['key' => 'wrong'], ['key' => ''], ['key' => true], ['key' => 0], ['key' => ['fixture-secret']], ['other' => 'fixture-secret']] as $data) {
    check(! $handler->__isWhitelisted(request('/x', $public, ['data' => json_encode($data)]), $public), 'Bad key accepted: '.json_encode($data));
}
foreach (['"fixture-secret"', 'not json', '7'] as $raw) {
    check(! $handler->__isWhitelisted(request('/x', $public, ['data' => $raw]), $public), 'Non-object data accepted: '.$raw);
}
check($handler->__isWhitelisted(request('/x', '127.0.0.1'), '127.0.0.1'), 'Loopback caller lost access');
check(! str_contains(print_r($handler->__debugInfo(), true), 'fixture-secret') && $handler->__debugInfo()['key'] === '[redacted]', 'Debug output exposed the key');
check(handler()->__debugInfo()['key'] === '', 'Unset key was not reported as unset');

// The discovered external address is trusted as local, so only a clean IP is accepted.
foreach (["198.51.100.7\n" => '198.51.100.7', ' 2001:db8::1 ' => '2001:db8::1'] as $reply => $expected) {
    check(HttpHandler::parseExternalIp($reply) === $expected, 'External IP not normalised: '.json_encode($reply));
}
foreach (['', '<html>blocked</html>', '198.51.100.7, 203.0.113.9', '999.1.1.1', false, null] as $reply) {
    check(HttpHandler::parseExternalIp($reply) === null, 'Bad external IP accepted: '.json_encode($reply));
}

// IP lookups: only JSON is decoded (a serialized payload is inert), and non-addresses never reach a URL, a file path or the cache.
$ok = '{"status":"success","country":"Canada","countryCode":"CA","proxy":false}';
check(IPToCountryResolver::parseResponse($ok) === ['status' => 'success', 'country' => 'Canada', 'countryCode' => 'CA', 'proxy' => false], 'Successful lookup not decoded');
foreach (['{"status":"fail","message":"reserved range"}', '', 'Too Many Requests', 'O:8:"stdClass":0:{}', serialize(['status' => 'success', 'countryCode' => 'CA']), '"success"', false, null, true] as $reply) {
    check(IPToCountryResolver::parseResponse($reply) === [], 'Unusable reply accepted: '.json_encode($reply));
}
foreach (['', 'HOST', '../../etc/passwd', '1.2.3', '1.2.3.4/../x', "8.8.8.8\n", '8.8.8.8?fields=1', 'example.com'] as $bad) {
    check(IPToCountryResolver::Online($bad) === [], 'Non-address reached the online lookup: '.json_encode($bad));
    check(IPToCountryResolver::Offline($bad) === 'unknown', 'Non-address reached the offline lookup: '.json_encode($bad));
}
check(IPToCountryResolver::Offline('2001:db8::1') === 'unknown', 'IPv6 reached the IPv4 offline database');
$bot = inert(HttpSafetyBot::class);
foreach (['', 'HOST', 'not-an-ip'] as $bad) {
    check($bot->getIpData($bad) === [] && $bot->ip_data === [] && $bot->lookups === [], 'Non-address was looked up or cached: '.json_encode($bad));
}
$bot->ip_data = ['203.0.113.5' => ['countryCode' => 'ZZ', 'timestamp' => time()]];
check(($bot->getIpData('203.0.113.5')['countryCode'] ?? null) === 'ZZ' && $bot->lookups === [], 'Fresh cache entry was not served');
echo "PASS http handler safety: rate-limit pruning, prefix matching, key comparison and redaction, external IP, JSON-only IP lookups\n";
