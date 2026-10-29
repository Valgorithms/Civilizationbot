<?php

declare(strict_types=1);

// Actual installed PSR/React HTTP classes; no bot bootstrap, sockets or .env.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';

use Civ13\AdminConsole\ReadOnlyStatusService;
use Civ13\AdminConsole\StatusEndpoint;
use React\Http\Message\ServerRequest;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks; ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
}
$actor = '12345678901234567'; $sessionReads = 0; $captureReads = 0; $loggedIn = true; $allowed = true;
$service = new ReadOnlyStatusService([['id' => 'fixture', 'name' => '<b>literal fixture</b>', 'provider' => 'civ13.com']], 'clock-1',
    static fn (): float => 100.0,
    static function () use (&$captureReads): array { ++$captureReads; return ['directory' => null, 'heartbeat' => null]; },
    static function () use (&$allowed): bool { return $allowed; });
$session = static function (ServerRequest $request) use (&$sessionReads, &$loggedIn, $actor): ?string {
    ++$sessionReads; return $loggedIn ? $actor : null;
};
$endpoint = new StatusEndpoint($service, $session);
$request = static fn (string $method = 'GET', string $suffix = ''): ServerRequest => new ServerRequest($method, 'https://fixture.invalid'.StatusEndpoint::PATH.$suffix);
$response = $endpoint->handle($request());
check($response->getStatusCode() === 200, 'Actual React HTTP success response');
check($response->getHeaderLine('Content-Type') === 'application/json; charset=utf-8', 'JSON content type');
check($response->getHeaderLine('Cache-Control') === 'private, no-store, max-age=0', 'No shared/authenticated caching');
check($response->getHeaderLine('Pragma') === 'no-cache' && $response->getHeaderLine('Vary') === 'Cookie, Authorization', 'Cache header contract');
check($response->getHeaderLine('X-Content-Type-Options') === 'nosniff' && !$response->hasHeader('Access-Control-Allow-Origin'), 'No sniffing or CORS grant');
$body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
check($body['schema'] === 'civ13.admin-overview.v1' && $body['servers'][0]['projection']['capabilities']['save'] === false, 'Real serialized service envelope');
$response = $endpoint->handle($request('HEAD'));
check($response->getStatusCode() === 200 && (string) $response->getBody() === '', 'HEAD authenticates but has no body');
check($sessionReads === 2, 'Session resolved on every request');
$before = $sessionReads;
foreach ([['POST', '', 405], ['DELETE', '', 405], ['GET', '/extra', 404], ['GET', '?actor='.$actor, 400], ['GET', '?host=evil.invalid', 400]] as [$method, $suffix, $status]) {
    $response = $endpoint->handle($request($method, $suffix));
    check($response->getStatusCode() === $status && $response->getHeaderLine('Cache-Control') === 'private, no-store, max-age=0', 'Method/path/query rejection with cache headers');
    if ($status === 405) { check($response->getHeaderLine('Allow') === 'GET, HEAD', 'Allow header'); }
}
check($sessionReads === $before, 'Rejected route does not consult session or inventory');
$loggedIn = false; $before = $captureReads;
$response = $endpoint->handle($request()->withHeader('Authorization', 'Bearer synthetic-untrusted')->withHeader('Cookie', 'discord_id='.$actor)->withAttribute('discord_id', $actor));
check($response->getStatusCode() === 401 && $captureReads === $before, 'Headers/attributes never bypass absent authenticated session');
$response = $endpoint->handle($request('HEAD'));
check($response->getStatusCode() === 401 && (string) $response->getBody() === '', 'HEAD error body empty');
$loggedIn = true; $allowed = false;
check($endpoint->handle($request())->getStatusCode() === 403 && $captureReads === $before, 'Role denial before capture');
$allowed = true;
check((new StatusEndpoint($service))->handle($request())->getStatusCode() === 503, 'No installed session provider unavailable');
check((new StatusEndpoint($service, static fn (): string => 'invalid'))->handle($request())->getStatusCode() === 503, 'Malformed provider result fails closed');
$failed = new StatusEndpoint($service, static function (): string { throw new RuntimeException('private-session-secret'); });
$response = $failed->handle($request());
check($response->getStatusCode() === 503 && !str_contains((string) $response->getBody(), 'private-session-secret'), 'Provider failure sanitized');
$unready = new ReadOnlyStatusService([['id' => 'fixture', 'name' => 'Fixture', 'provider' => 'civ13.com']], 'clock-1', static fn (): float => 100.0,
    static function (): array { throw new RuntimeException('must not collect'); });
check((new StatusEndpoint($unready, $session))->handle($request())->getStatusCode() === 503, 'Missing role policy refuses HTTP release');
echo "Read-only HTTP boundary contracts passed ($checks checks)\n";
