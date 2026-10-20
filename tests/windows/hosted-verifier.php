<?php
declare(strict_types=1);
// Mock only the remote transport; exercise production parser, verifier, timers and write barriers.
namespace Civ13 {
    function curl_setopt_array($handle, array $options): bool { $GLOBALS['hostedOptions'] = $options; return true; }
    function curl_getinfo($handle, int $option): int { return $GLOBALS['hostedStatus']; }
    function curl_errno($handle): int { return $GLOBALS['hostedTimeout'] ? CURLE_OPERATION_TIMEDOUT : 0; }
    function curl_exec($handle): bool {
        ++$GLOBALS['hostedCalls'];
        if ($GLOBALS['hostedTimeout']) { return false; }
        $chunk = $GLOBALS['hostedBody'];
        return ($GLOBALS['hostedOptions'][CURLOPT_WRITEFUNCTION])($handle, $chunk) === strlen($chunk);
    }
}
namespace {
    $loader = require ($argv[1] ?? __DIR__.'/../../vendor/autoload.php');
    $loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
    function check(bool $ok): void { if (!$ok) { throw new RuntimeException('Hosted verification contract failed'); } }
    function rejects(callable $call): void { try { $call(); } catch (Throwable) { return; } throw new RuntimeException('Expected unavailable/rejection'); }
    final class HostedDiscord extends Discord\Discord {
        public function __construct(private React\EventLoop\LoopInterface $testLoop) {}
        public function getLoop(): React\EventLoop\LoopInterface { return $this->testLoop; }
    }
    $GLOBALS['hostedCalls'] = 0; $GLOBALS['hostedStatus'] = 200; $GLOBALS['hostedTimeout'] = false;
    $GLOBALS['hostedBody'] = '[{"ss13":"fixtureplayer","discord":"123456789012345678","ignored":"not retained"}]';
    $bot = (new ReflectionClass(Civ13\Civ13::class))->newInstanceWithoutConstructor();
    $bot->loop = new React\EventLoop\StreamSelectLoop();
    $bot->discord = new HostedDiscord($bot->loop);
    $bot->logger = new Monolog\Logger('offline-hosted', [new Monolog\Handler\NullHandler()]);
    $bot->ready = true;
    $verifier = new Civ13\Verifier($bot, ['verify_url' => Civ13\HostedVerificationSource::URL, 'verification_read_only' => true]);
    $now = 1000;
    $snapshot = new Civ13\HostedVerificationSnapshot(static function () use (&$now): int { return $now; });
    (new ReflectionProperty(Civ13\Verifier::class, 'hosted'))->setValue($verifier, $snapshot);
    $verifier->getVerified();
    check($verifier->ready && $bot->verifier_online && count($verifier->verified) === 1);
    check($verifier->get('ss13', 'fixtureplayer') === ['ss13' => 'fixtureplayer', 'discord' => '123456789012345678']);
    foreach (['GUILD_MEMBER_ADD', 'GUILD_MEMBER_REMOVE', 'GUILD_MEMBER_UPDATE', 'THREAD_CREATE'] as $event) {
        check(count($bot->discord->listeners($event)) === 0);
    }
    check(count($verifier->provisional) === 0 && count($verifier->pending) === 0);
    $calls = $GLOBALS['hostedCalls'];
    check($verifier->process('fixtureplayer', '123456789012345678') !== '');
    foreach (['new' => ['123456789012345678'], 'register' => ['x', '123456789012345678'],
              'verify' => ['x', '123456789012345678'], 'unverify' => ['123456789012345678']] as $method => $arguments) {
        check($verifier->$method(...$arguments)['success'] === false);
    }
    check(!$verifier->provisionalRegistration('x', '123456789012345678') && !$verifier->checkToken('123456789012345678'));
    rejects(fn () => $verifier->generateToken('x', '123456789012345678'));
    $denied = false; $verifier->provision('x', '123456789012345678')->then(null, static function () use (&$denied): void { $denied = true; }); check($denied);
    $private = new ReflectionMethod(Civ13\Verifier::class, '__verifyRequest');
    check($private->invoke($verifier, ['method' => 'DELETE'])['http_status'] === 503);
    check($GLOBALS['hostedCalls'] === $calls && count($verifier->verified) === 1 && count($verifier->provisional) === 0);
    $options = $GLOBALS['hostedOptions'];
    check($options[CURLOPT_HTTPGET] === true && $options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_PROXY] === '');
    check(!isset($options[CURLOPT_POST], $options[CURLOPT_POSTFIELDS], $options[CURLOPT_USERPWD]));
    foreach ([301, 401, 403, 404, 500] as $status) {
        $now += 11;
        $GLOBALS['hostedStatus'] = $status; rejects(fn () => $verifier->getVerified());
        check(!$bot->verifier_online && count($verifier->verified) === 1);
        check($verifier->hostedStatus()['stale'] && $verifier->hostedStatus()['last_success_utc'] !== null);
        rejects(fn () => $verifier->isVerified('fixtureplayer'));
    }
    $now += 11;
    $GLOBALS['hostedStatus'] = 200; $GLOBALS['hostedTimeout'] = true;
    $callsBeforeTimeout = $GLOBALS['hostedCalls'];
    rejects(fn () => $verifier->getVerified()); check(!$bot->verifier_online && $GLOBALS['hostedCalls'] === $callsBeforeTimeout + 2);
    $GLOBALS['hostedTimeout'] = false;
    foreach (['null', '{"error":"unknown"}', 'invalid', '[{}]', '[{"ss13":"x","discord":123}]', '[{"ss13":"x\\ny","discord":"123456789012345678"}]',
              '[{"ss13":"x","discord":"123456789012345678"},{"ss13":"x","discord":"234567890123456789"}]',
              str_repeat('x', Civ13\HostedVerificationSource::MAX_BYTES + 1)] as $bad) {
        $now += 11;
        $GLOBALS['hostedBody'] = $bad; rejects(fn () => $verifier->getVerified()); check(!$bot->verifier_online);
    }
    $now += 11;
    $GLOBALS['hostedBody'] = '[]'; $verifier->getVerified(); check($bot->verifier_online && count($verifier->verified) === 0);
    check(Civ13\HostedVerificationSource::validate('{"123456789012345678":{"ss13":"Fixture_Player","discord":"123456789012345678"}}') === [['ss13'=>'fixtureplayer','discord'=>'123456789012345678']]);
    rejects(static fn () => Civ13\HostedVerificationSource::validate('[{"ss13":"Fixture_Player","discord":"123456789012345678"},{"ss13":"fixtureplayer","discord":"234567890123456789"}]'));
    $now += 11;
    $GLOBALS['hostedBody'] = '[{"ss13":"newplayer","discord":"234567890123456789"}]';
    $verifier->getVerified(); check($verifier->get('ss13', 'fixtureplayer') === null && $verifier->isVerified('newplayer'));
    $now += 11;
    $GLOBALS['hostedTimeout'] = true;
    $timer = $verifier->timers['verifier_status_timer']; ($timer->getCallback())($timer);
    check(!$bot->verifier_online); rejects(fn () => $verifier->getVerifiedItem('newplayer'));
    check($verifier->hostedStatus()['records'] === 1 && $verifier->hostedStatus()['has_valid_list']);
    $empty = new Civ13\HostedVerificationSnapshot(static fn (): int => 1000);
    check(!$empty->refresh(static fn (): array => ['http_status' => 0])['has_valid_list']);
    $concurrent = new Civ13\HostedVerificationSnapshot(static function () use (&$now): int { return $now; });
    $transports = 0;
    $result = $concurrent->refresh(static function () use ($concurrent, &$transports): array {
        ++$transports;
        $shared = $concurrent->refresh(static function (): never { throw new RuntimeException('Duplicate concurrent transport'); });
        check($shared['coalesced'] && $shared['refresh_in_progress']);
        return ['http_status' => 200, 'json' => '[]'];
    });
    check($result['available'] && $transports === 1);
    check($concurrent->refresh(static function (): never { throw new RuntimeException('Duplicate queued transport'); })['coalesced']);
    $now += 1801; check(!$concurrent->status()['available'] && $concurrent->status()['stale']);
    $profile = ['options' => ['role_ids' => [], 'channel_ids' => []], 'gitdir' => __DIR__, 'state_dir' => __DIR__,
                'verification_source' => ['url' => Civ13\HostedVerificationSource::URL, 'mode' => 'read-only']];
    check(Civ13\PortableProfile::runtimeOptions($profile)['verification_read_only']);
    foreach (['http://localhost/verified', 'http://civ13.com:55555/verified?token=x', 'https://other.invalid/verified'] as $url) {
        $profile['verification_source']['url'] = $url; rejects(fn () => Civ13\PortableProfile::runtimeOptions($profile));
    }
    echo "PASS hosted GET contract, real read-only setup, no mutation/listeners/provisional/cache, concurrent coalescing, timeout/status/schema/size/duplicates, retained stale metadata, empty success and revoked lookup\n";
}
