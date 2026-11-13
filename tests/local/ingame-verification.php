<?php
declare(strict_types=1);
// In-game verification contracts. No bootstrap, .env, sockets, Discord client or verifier website.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);

use Civ13\Civ13;
use Civ13\HttpServiceManager;
use Civ13\InGameVerification;
use Civ13\Verifier;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use React\Http\Message\ServerRequest;

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { if (! (error_reporting() & $severity)) { return true; } throw new ErrorException($message, 0, $severity, $file, $line); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$checks = 0;
function check(bool $ok, string $why): void { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($why); } }
function inert(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
function setProp(object $object, string $name, mixed $value, ?string $class = null): void { $p = new ReflectionProperty($class ?? $object, $name); $p->setValue($object, $value); }

const ALICE = '123456789012345678';
const BOB = '223456789012345678';

// Codes: format, normalisation, single use, replacement, expiry, lockout.
$now = 1000;
$codes = new InGameVerification(static function () use (&$now): int { return $now; });
$code = $codes->issue(ALICE);
check(preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/D', $code) === 1, 'code shape '.$code);
check(InGameVerification::normalize(strtolower(str_replace('-', ' ', $code))) === str_replace('-', '', $code), 'players may type spaces and lower case');
check(InGameVerification::normalize('ABCD-EFG0') === null && InGameVerification::normalize('short') === null && InGameVerification::normalize(['x']) === null, 'bad codes rejected');
check($codes->consume('alice', $code) === ALICE, 'code redeems to its Discord user');
check($codes->consume('alice', $code) === null, 'single use');
$first = $codes->issue(ALICE);
$second = $codes->issue(ALICE);
check($codes->count() === 1 && $codes->consume('alice', $first) === null && $codes->consume('alice', $second) === ALICE, 'a new code replaces the older one');
$late = $codes->issue(BOB);
$now += InGameVerification::CODE_LIFETIME;
check($codes->consume('bob', $late) === null && $codes->count() === 0, 'codes expire');
$codes = new InGameVerification(static function () use (&$now): int { return $now; });
$good = $codes->issue(BOB);
for ($i = 0; $i < InGameVerification::MAX_FAILURES; $i++) { $codes->consume('mallory', 'ZZZZ-ZZZZ'); }
check($codes->locked('mallory') && $codes->consume('mallory', $good) === null, 'wrong guesses lock the ckey out');
check(! $codes->locked('bob') && $codes->consume('bob', $good) === BOB, 'a lockout does not burn other players\' codes');
$now += InGameVerification::FAILURE_WINDOW + 1;
check(! $codes->locked('mallory'), 'lockout lifts after the window');
try { $codes->issue('not-an-id'); check(false, 'non-Discord id accepted'); } catch (InvalidArgumentException) { check(true, ''); }
check(str_contains(InGameVerification::codeMessage('ABCD-EFGH'), 'Verify Discord') && str_contains(InGameVerification::codeMessage('ABCD-EFGH'), '`ABCD-EFGH`'), 'code message explains the verb');

// Relay destination only beside the hosted verified list.
check(InGameVerification::relayUrl('http://civ13.com:55555/verified') === 'http://civ13.com:55555/verified/ingame', 'relay url beside hosted list');
check(InGameVerification::relayUrl('https://civ13.com/verified') === 'https://civ13.com/verified/ingame', 'https relay url');
check(InGameVerification::relayUrl('http://evil.example/verified') === null && InGameVerification::relayUrl('') === null, 'no relay elsewhere');

// Verifier: redeeming in game.
final class FakeVerifier extends Verifier
{
    public array $processed = [];
    public array $result = ['success' => true, 'error' => ''];
    public function processInGame(string $ckey, string $discord_id): array { $this->processed[] = [$ckey, $discord_id]; return $this->result; }
    protected function discordName(string $discord_id): string { return 'Alice'; }
}
function verifier(bool $readOnly): FakeVerifier
{
    $v = inert(FakeVerifier::class);
    setProp($v, 'readOnly', $readOnly, Verifier::class);
    setProp($v, 'verify_url', $readOnly ? 'http://civ13.com:55555/verified' : 'http://127.0.0.1:8080/verified/', Verifier::class);
    $v->inGame = new InGameVerification();
    return $v;
}
$v = verifier(false);
$code = $v->inGame->issue(ALICE);
$ok = $v->redeemInGame('alice', $code);
check($ok['ok'] === true && str_contains($ok['message'], 'alice is now linked to Alice'), 'success message names the Discord account');
check($v->processed === [['alice', ALICE]], 'ckey and the code owner reach the verifier');
check($v->redeemInGame('alice', $code)['ok'] === false, 'used code fails');
$v->result = ['success' => false, 'error' => "`alice` is already verified! If this is your account, contact <@123456789012345678> to delete this entry."];
$code = $v->inGame->issue(BOB);
$fail = $v->redeemInGame('alice', $code);
check($fail['ok'] === false && ! str_contains($fail['message'], '<@') && ! str_contains($fail['message'], '`') && str_contains($fail['message'], 'contact staff'), 'verifier errors are shown without Discord markup: '.$fail['message']);
check(verifier(true)->redeemInGame('alice', 'ABCD-EFGH')['ok'] === false, 'read-only verifier never registers');
check(Verifier::plainMessage("a\n**b** <@&1234567890>") === 'a b staff', 'plain message');

// HTTP: request validation, relay authentication.
check(HttpServiceManager::inGameVerifyRequest(['ckey' => 'alice', 'code' => 'ABCD-EFGH']) === ['ckey' => 'alice', 'code' => 'ABCD-EFGH'], 'valid request');
foreach ([['ckey' => 'Alice', 'code' => 'x'], ['ckey' => 'alice'], ['ckey' => 'alice', 'code' => ''], ['ckey' => 'alice', 'code' => str_repeat('A', 33)], ['ckey' => 'a b', 'code' => 'x'], ['ckey' => ['alice'], 'code' => 'x']] as $bad) {
    check(HttpServiceManager::inGameVerifyRequest($bad) === null, 'invalid request rejected: '.json_encode($bad));
}
function manager(Verifier $verifier, string $relayKey): HttpServiceManager
{
    $civ13 = inert(Civ13::class);
    $civ13->options = ['http_key' => 'webhook-key', 'ingame_verify_key' => $relayKey];
    $civ13->verifier = $verifier;
    $manager = inert(HttpServiceManager::class);
    setProp($manager, 'civ13', $civ13);
    $manager->logger = new Logger('test', [new NullHandler()]);
    return $manager;
}
function relayRequest(string $key, string $body, string $method = 'POST', ?int $time = null): ServerRequest
{
    $time = (string) ($time ?? time());
    return new ServerRequest($method, 'http://civ13.com:55555/verified/ingame', $key === '' ? [] : ['X-Ingame-Verify-Time' => $time, 'X-Ingame-Verify-Signature' => HttpServiceManager::inGameRelaySignature($key, $time, $body)], $body);
}
$v = verifier(false);
$code = $v->inGame->issue(ALICE);
$m = manager($v, 'relay-secret');
$body = json_encode(['ckey' => 'alice', 'code' => $code]);
check($m->handleInGameVerifyRelay(relayRequest('', $body))->getStatusCode() === 403, 'relay needs the key');
check($m->handleInGameVerifyRelay(relayRequest('wrong', $body))->getStatusCode() === 403, 'relay rejects a wrong key');
check($m->handleInGameVerifyRelay(relayRequest('relay-secret', $body, 'POST', time() - 120))->getStatusCode() === 403, 'stale signatures are refused');
$tampered = relayRequest('relay-secret', $body);
check($m->handleInGameVerifyRelay(new ServerRequest('POST', 'http://civ13.com:55555/verified/ingame', ['X-Ingame-Verify-Time' => $tampered->getHeaderLine('X-Ingame-Verify-Time'), 'X-Ingame-Verify-Signature' => $tampered->getHeaderLine('X-Ingame-Verify-Signature')], json_encode(['ckey' => 'admin', 'code' => $code])))->getStatusCode() === 403, 'a signature does not cover another ckey');
check(! str_contains(json_encode($tampered->getHeaders()), 'relay-secret'), 'the secret itself is never sent');
check(manager($v, '')->handleInGameVerifyRelay(relayRequest('', $body))->getStatusCode() === 403, 'relay off without INGAME_VERIFY_TOKEN');
check(manager(verifier(true), 'relay-secret')->handleInGameVerifyRelay(relayRequest('relay-secret', $body))->getStatusCode() === 403, 'a read-only bot never accepts relays');
check($m->handleInGameVerifyRelay(relayRequest('relay-secret', $body, 'GET'))->getStatusCode() === 405, 'relay is POST only');
check($m->handleInGameVerifyRelay(relayRequest('relay-secret', '{"ckey":"alice"}'))->getStatusCode() === 400, 'relay validates the body');
$response = $m->handleInGameVerifyRelay(relayRequest('relay-secret', $body));
$answer = json_decode((string) $response->getBody(), true);
check($response->getStatusCode() === 200 && $answer['ok'] === true && $v->processed === [['alice', ALICE]], 'relay redeems on the primary bot');

// A desktop bot without a relay key answers the game without any network call.
$relayed = null;
manager(verifier(true), '')->relayInGameVerification('alice', 'ABCD-EFGH')->then(static function (array $r) use (&$relayed): void { $relayed = $r; });
check(is_array($relayed) && $relayed['ok'] === false && str_contains($relayed['message'], 'not set up'), 'unconfigured relay answers plainly');

echo "ingame-verification: $checks checks passed\n";
