<?php

declare(strict_types=1);

// Offline entry point: do not include tests/bootstrap.php, bot.php or any .env loader.
require __DIR__.'/../vendor/autoload.php';

use Civ13\Civ13;
use Civ13\Verifier;
use Composer\InstalledVersions;
use React\Http\Message\ServerRequest;
use VerifierServer\Endpoints\VerifiedEndpoint;
use VerifierServer\PersistentState;
use VerifierServer\Server;

function verifyCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Keep the real Server::handleEndpoint route; inject only storage/endpoint setup.
// No Server constructor, init(), start(), sockets or Discord client are invoked.
final class OfflineVerifierServer extends Server
{
    public function __construct(VerifiedEndpoint $endpoint)
    {
        $this->endpoints['/verified'] = $endpoint;
    }
}

$manifest = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
$expected = explode('#', $manifest['require']['vzgcoders/verifier-server'])[1] ?? '';
verifyCheck($expected !== '' && InstalledVersions::getReference('vzgcoders/verifier-server') === $expected, 'Installed verifier does not match the reviewed manifest pin.');

$path = tempnam(sys_get_temp_dir(), 'civ-verifier-');
if ($path === false) {
    throw new RuntimeException('Cannot create isolated verification storage.');
}
file_put_contents($path, '[]');
$originalDirectory = getcwd();
// PersistentState resolves storage paths relative to cwd, including on Windows.
chdir(dirname($path));
try {
    $token = 'offline-token';
    $state = new PersistentState($token, 'filesystem', basename($path));
    $endpoint = new VerifiedEndpoint($state);
    $server = new OfflineVerifierServer($endpoint);
    $bot = (new ReflectionClass(Civ13::class))->newInstanceWithoutConstructor();
    $bot->civ_token = $token;
    $bot->verifier_server = $server;
    $verifier = (new ReflectionClass(Verifier::class))->newInstanceWithoutConstructor();
    $verifier->civ13 = $bot;
    $request = new ReflectionMethod(Verifier::class, '__verifyRequest');
    $register = new ReflectionMethod(Verifier::class, '__verify');
    $unregister = new ReflectionMethod(Verifier::class, '__unverify');

    // Exercise the bot's real legacy internal serialization and route.
    $result = $register->invoke($verifier, 'fixtureplayer', '123456789012345678');
    verifyCheck($result['http_status'] === 200, 'Bot registration status failed.');
    $records = json_decode($result['response'], true, 512, JSON_THROW_ON_ERROR);
    verifyCheck($records[0]['ss13'] === 'fixtureplayer', 'Bot registration payload failed.');
    verifyCheck($endpoint->getIndex('123456789012345678', '') === 0, 'Discord-only lookup missed index zero.');
    verifyCheck($register->invoke($verifier, 'fixtureplayer', '123456789012345678')['http_status'] === 403, 'Duplicate registration accepted.');

    $bot->civ_token = 'incorrect';
    verifyCheck($request->invoke($verifier, ['method' => 'DELETE', 'discord' => '123456789012345678'])['http_status'] === 401, 'Unauthorized deletion accepted.');
    verifyCheck(count($state->getVerifyList()) === 1, 'Unauthorized deletion mutated state.');
    $bot->civ_token = $token;
    verifyCheck($unregister->invoke($verifier, '123456789012345678')['http_status'] === 200, 'Bot Discord-ID unverify failed.');
    verifyCheck($endpoint->getIndex('123456789012345678', '') === false, 'Lookup cache retained deleted account.');

    // The remote bot path uses this same form encoding. Deliver a PSR request
    // directly to the real endpoint instead of making a network/cURL call.
    $payload = http_build_query(['ckey' => 'secondplayer', 'discord' => '234567890123456789', 'token' => $token]);
    $form = new ServerRequest('POST', 'http://offline.invalid/verified', ['Content-Type' => 'application/x-www-form-urlencoded'], $payload);
    $status = 599;
    $headers = [];
    $body = '';
    $server->handleEndpoint('/verified', 'POST', $form, $status, $headers, $body);
    verifyCheck($status === 200, 'Form registration retained stale status.');
    verifyCheck(json_decode($body, true, 512, JSON_THROW_ON_ERROR)[0]['ss13'] === 'secondplayer', 'Form body did not register expected account.');
    verifyCheck($endpoint->getIndex('234567890123456789', '') === 0, 'Lookup did not detect addition.');
    $deleted = $request->invoke($verifier, ['method' => 'DELETE', 'discord' => '234567890123456789']);
    verifyCheck($deleted['http_status'] === 200 && $state->getVerifyList() === [], 'Discord-only deletion failed.');
    verifyCheck($request->invoke($verifier, ['method' => 'DELETE', 'discord' => '234567890123456789'])['http_status'] === 404, 'Missing account deletion status failed.');

    // Changing state through its public API must invalidate a primed lookup.
    verifyCheck($endpoint->getIndex('345678901234567890', '') === false, 'Unexpected fixture record.');
    $state->setVerifyList([['ss13' => 'restoredplayer', 'discord' => '345678901234567890', 'create_time' => 'fixture']]);
    verifyCheck($endpoint->getIndex('345678901234567890', '') === 0, 'State replacement did not invalidate lookup.');
    PersistentState::writeJson($path, $state->getVerifyList());
    $reopened = new PersistentState($token, 'filesystem', basename($path));
    verifyCheck($reopened->getVerifyList()[0]['ss13'] === 'restoredplayer', 'Restart persistence failed.');
    echo "Verifier integration passed: pinned commit, bot registration/unverify, forms/status, authorization, duplicates, Discord-only deletion, cache invalidation, restart.\n";
} finally {
    chdir($originalDirectory);
    unlink($path);
}
