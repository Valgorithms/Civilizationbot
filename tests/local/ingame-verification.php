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
final class LogSpy extends Monolog\Handler\AbstractProcessingHandler
{
    public string $text = '';
    protected function write(Monolog\LogRecord $record): void { $this->text .= $record->message.' '.json_encode($record->context).PHP_EOL; }
}
$spy = new LogSpy();
function manager(Verifier $verifier, string $relayKey): HttpServiceManager
{
    global $spy;
    $civ13 = inert(Civ13::class);
    $civ13->options = ['http_key' => 'webhook-key', 'ingame_verify_key' => $relayKey];
    $civ13->verifier = $verifier;
    $manager = inert(HttpServiceManager::class);
    setProp($manager, 'civ13', $civ13);
    $manager->logger = new Logger('test', [$spy]);
    return $manager;
}
function relayRequest(string $body, string $method = 'POST'): ServerRequest
{
    return new ServerRequest($method, 'http://civ13.com:55555/verified/ingame', ['Content-Type' => 'application/json'], $body);
}
$v = verifier(false);
$code = $v->inGame->issue(ALICE);
$m = manager($v, 'relay-secret');
$payload = ['ckey' => 'alice', 'code' => $code];
$sealed = InGameVerification::seal('relay-secret', 'request', $payload);
check(! str_contains($sealed, $code) && ! str_contains($sealed, str_replace('-', '', $code)) && ! str_contains($sealed, 'alice') && ! str_contains($sealed, 'relay-secret'), 'the sealed relay body shows neither the code, the ckey nor the secret');
check(InGameVerification::open('relay-secret', 'request', $sealed)['payload'] === $payload, 'seal round trip');
check(InGameVerification::open('other-secret', 'request', $sealed) === null, 'a wrong secret cannot open it');
check(InGameVerification::open('relay-secret', 'response', $sealed) === null, 'a request cannot pose as an answer');
check(InGameVerification::open('relay-secret', 'request', $sealed, null, time() + 120) === null, 'a stale message is refused');
$outer = json_decode($sealed, true);
$cipher = base64_decode($outer['c']);
$cipher[20] = chr(ord($cipher[20]) ^ 1);
check(InGameVerification::open('relay-secret', 'request', json_encode(['v' => 1, 'n' => $outer['n'], 'c' => base64_encode($cipher)])) === null, 'a changed byte is refused');
check(InGameVerification::open('relay-secret', 'request', '') === null && InGameVerification::open('', 'request', $sealed) === null && InGameVerification::open('relay-secret', 'request', '{"v":1}') === null, 'malformed or keyless input is refused');
check($m->handleInGameVerifyRelay(relayRequest(json_encode($payload)))->getStatusCode() === 403, 'an unsealed relay is refused');
check($m->handleInGameVerifyRelay(relayRequest(InGameVerification::seal('wrong', 'request', $payload)))->getStatusCode() === 403, 'relay rejects a wrong secret');
check($m->handleInGameVerifyRelay(relayRequest(InGameVerification::seal('relay-secret', 'request', $payload, null, time() - 120)))->getStatusCode() === 403, 'stale relays are refused');
check(manager($v, '')->handleInGameVerifyRelay(relayRequest($sealed))->getStatusCode() === 403, 'relay off without INGAME_VERIFY_TOKEN');
check(manager(verifier(true), 'relay-secret')->handleInGameVerifyRelay(relayRequest($sealed))->getStatusCode() === 403, 'a read-only bot never accepts relays');
check($m->handleInGameVerifyRelay(relayRequest($sealed, 'GET'))->getStatusCode() === 405, 'relay is POST only');
check($m->handleInGameVerifyRelay(relayRequest(InGameVerification::seal('relay-secret', 'request', ['ckey' => 'alice'])))->getStatusCode() === 400, 'relay validates the payload');
$response = $m->handleInGameVerifyRelay(relayRequest($sealed));
$answerBody = (string) $response->getBody();
check(! str_contains($answerBody, 'linked'), 'the answer is sealed too');
check(InGameVerification::open('relay-secret', 'response', $answerBody, InGameVerification::nonceOf(InGameVerification::seal('relay-secret', 'request', $payload))) === null, 'an answer only opens for the request it answers');
$answer = InGameVerification::open('relay-secret', 'response', $answerBody, InGameVerification::nonceOf($sealed));
check($response->getStatusCode() === 200 && $answer['payload']['ok'] === true && $v->processed === [['alice', ALICE]], 'relay redeems on the primary bot');

// Game webhook: key only in the X-Webhook-Key header, ckey and code only in a POST body.
$gameserver = inert(\Civ13\GameServer::class);
setProp($gameserver, 'legacy_relay', false);
function gameRequest(array $headers, string $body, string $method = 'POST', string $query = ''): ServerRequest
{
    return new ServerRequest($method, 'http://127.0.0.1:55555/webhook/nomads/discord_verify'.$query, $headers, $body);
}
$v = verifier(false);
$code = $v->inGame->issue(ALICE);
$m = manager($v, '');
$body = json_encode(['ckey' => 'alice', 'code' => $code]);
check($m->handleDiscordVerifyWebhook(gameRequest([], $body), $gameserver)->getStatusCode() === 403, 'game request needs the key header');
check($m->handleDiscordVerifyWebhook(gameRequest(['X-Webhook-Key' => 'wrong'], $body), $gameserver)->getStatusCode() === 403, 'game request rejects a wrong key');
check($m->handleDiscordVerifyWebhook(gameRequest([], $body, 'POST', '?key=webhook-key'), $gameserver)->getStatusCode() === 403, 'a key in the URL is not accepted');
check($m->handleDiscordVerifyWebhook(gameRequest(['X-Webhook-Key' => 'webhook-key'], '', 'GET', '?key=webhook-key&method=discord_verify&data='.urlencode($body)), $gameserver)->getStatusCode() === 405, 'the old query-string request is refused');
check($m->handleDiscordVerifyWebhook(gameRequest(['X-Webhook-Key' => 'webhook-key'], '{"ckey":"alice"}'), $gameserver)->getStatusCode() === 400, 'game request validates the body');
check($v->processed === [], 'nothing redeemed by refused requests');
$response = $m->handleDiscordVerifyWebhook(gameRequest(['X-Webhook-Key' => 'webhook-key'], $body), $gameserver);
check($response->getStatusCode() === 200 && json_decode((string) $response->getBody(), true)['ok'] === true && $v->processed === [['alice', ALICE]], 'game request redeems with header key and JSON body');
$handler = inert(\Civ13\HttpHandler::class);
setProp($handler, 'key', 'webhook-key');
check($handler->__isWhitelisted(gameRequest(['X-Webhook-Key' => 'webhook-key'], $body), '203.0.113.9'), 'the router accepts the header key on webhook paths');
check(! $handler->__isWhitelisted(gameRequest(['X-Webhook-Key' => 'nope'], $body), '203.0.113.9'), 'the router refuses a wrong header key');

// A desktop bot without a relay key answers the game without any network call.
$relayed = null;
manager(verifier(true), '')->relayInGameVerification('alice', 'ABCD-EFGH')->then(static function (array $r) use (&$relayed): void { $relayed = $r; });
check(is_array($relayed) && $relayed['ok'] === false && str_contains($relayed['message'], 'not set up'), 'unconfigured relay answers plainly');

// Logs: nothing above wrote a code, a ckey's code, a key or a relay secret.
foreach (array_merge([$code, str_replace('-', '', $code), 'webhook-key', 'relay-secret'], array_map(static fn (string $c): string => str_replace('-', '', $c), [$first, $second, $late, $good])) as $secret) {
    check(! str_contains($spy->text, $secret), 'secret kept out of the logs: '.$secret);
}

// And no log call in the verification paths names the code, the key, the body or the secret.
$source = file_get_contents(__DIR__.'/../../src/Civ13/HttpServiceManager.php');
$from = strpos($source, 'public function handleDiscordVerifyWebhook');
$section = substr($source, $from, strpos($source, 'public static function inGameVerifyRequest') - $from);
preg_match_all('/logger->\w+\((.*?)\);/s', $section, $calls);
check(count($calls[1]) >= 2, 'log calls found in the verification paths');
foreach ($calls[1] as $call) {
    check(preg_match('/\$(code|key|secret|body|data|payload|request)\b/', $call) !== 1, 'log call carries no secret: '.$call);
}

echo "ingame-verification: $checks checks passed\n";
