<?php
declare(strict_types=1);
// Real owned file locks plus a deterministic elapsed-time hook. No lock contention/child process.
namespace Civ13\Server {
    function flock($stream, int $operation, ?int &$wouldBlock = null): bool
    {
        $locked = \flock($stream, $operation, $wouldBlock);
        if ($locked && $operation === LOCK_EX) {
            ++\AdmissionLockProbe::$locks;
            $hook = \AdmissionLockProbe::$afterLock; \AdmissionLockProbe::$afterLock = null;
            if ($hook !== null) { $hook(); }
        }
        return $locked;
    }
}
namespace {
    $loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
    $loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
    use Civ13\Civ13;
    use Civ13\GameServer;
    use Civ13\Verifier;
    use Civ13\HostedVerificationSource;
    use Civ13\HostedVerificationSnapshot;
    use Civ13\Server\Ipc;
    use Discord\Helpers\Collection;
    use Discord\Parts\User\Member;
    use Discord\Parts\User\User;
    use function React\Promise\resolve;

    final class AdmissionLockProbe { public static int $locks = 0; public static ?Closure $afterLock = null; }
    $checks = 0;
    function admissionCheck(bool $value, string $message): void { global $checks; ++$checks; if (!$value) { throw new RuntimeException($message); } }
    function admissionResult(React\Promise\PromiseInterface $promise): array
    {
        $settled = false; $error = null;
        $promise->then(static function () use (&$settled): void { $settled = true; }, static function (Throwable $failure) use (&$settled, &$error): void { $settled = true; $error = $failure; });
        admissionCheck($settled, 'Fixture promises must settle synchronously');
        return ['error'=>$error];
    }
    final class AdmissionOwner extends Civ13 { public function hasRequiredConfigRoles(array $required_roles = []): bool { return true; } }
    final class AdmissionMember extends Member
    {
        public object $roles;
        public function __construct(?Discord\Discord $discord = null, array $attributes = [], bool $created = false) { $this->roles = new class { public function has(string $role): bool { return $role === '880000000000000001'; } }; }
    }
    final class AdmissionVerifier extends Verifier
    {
        public array $authorityTimes = [];
        public Closure $clock;
        public function __construct() {}
        public function getVerifiedMember(Member|User|array|string|null $input): ?Member { return new AdmissionMember(); }
        public function hostedAccessAuthority(array $records): bool { $this->authorityTimes[] = ($this->clock)(); return parent::hostedAccessAuthority($records); }
    }
    final class AdmissionBrowser extends React\Http\Browser
    {
        public function __construct() {}
        public function withTimeout($value) { return $this; }
        public function withFollowRedirects($value) { return $this; }
        public function withRejectErrorResponse($value) { return $this; }
        public function withResponseBuffer($value) { return $this; }
        public function get($url, array $headers = []) { return resolve(new React\Http\Message\Response(200, [], '[{"ss13":"fakealpha","discord":"990000000000000001"}]')); }
    }
    /** Mint a mocked transport receipt using the actual mapper, snapshot and Verifier authority path. */
    function admissionFixture(string $path, int &$now, bool $readOnly = true): array
    {
        $url = 'https://civ13.com:55555/verified'; $mapped = null;
        (new ReflectionMethod(HostedVerificationSource::class, 'read'))->invoke(null, new AdmissionBrowser(), $url, true)->then(static function ($value) use (&$mapped): void { $mapped = $value; });
        admissionCheck(is_array($mapped), 'Mocked mapper must settle without network');
        $snapshot = new HostedVerificationSnapshot(static function () use (&$now): int { return $now; });
        admissionResult($snapshot->refresh(static fn () => $mapped));
        $owner = (new ReflectionClass(AdmissionOwner::class))->newInstanceWithoutConstructor();
        $owner->role_ids = ['Verified'=>'880000000000000001'];
        $owner->logger = new Monolog\Logger('synthetic-admission', [new Monolog\Handler\NullHandler()]);
        $verifier = new AdmissionVerifier(); $verifier->civ13 = $owner;
        $verifier->clock = static function () use (&$now): int { return $now; };
        (new ReflectionProperty($verifier, 'readOnly'))->setValue($verifier, $readOnly);
        (new ReflectionProperty($verifier, 'verify_url'))->setValue($verifier, $url);
        (new ReflectionProperty(Verifier::class, 'hosted'))->setValue($verifier, $snapshot);
        $verifier->verified = new Collection($snapshot->records(), 'discord'); $owner->verifier = $verifier;
        $server = (new ReflectionClass(GameServer::class))->newInstanceWithoutConstructor(); $server->civ13 = $owner; $server->enabled = true;
        (new ReflectionProperty($server, 'whitelist'))->setValue($server, $path);
        return compact('owner','verifier','snapshot','server');
    }

    $base = realpath($argv[2] ?? sys_get_temp_dir());
    if ($base === false) { throw new RuntimeException('Owned test base missing'); }
    $root = $base.'/access-admission-'.bin2hex(random_bytes(8));
    if (!mkdir($root)) { throw new RuntimeException('Owned test root unavailable'); }
    $known = ['guard.txt','whitelist.txt','first.txt','second.txt'];
    try {
        // Execute the actual lifecycle helper; no app state is read for false/invalid flags.
        $initialize = new ReflectionMethod(Civ13::class, 'initializeAccessFileSync');
        foreach ([false,true,1,0,'true','false',null,[],['nested'=>true]] as $flag) {
            $app = (new ReflectionClass(Civ13::class))->newInstanceWithoutConstructor();
            $handler = new Monolog\Handler\TestHandler(); $app->logger = new Monolog\Logger('synthetic-flags', [$handler]);
            $app->civ13_guild_id = ''; $app->role_ids = []; $app->options = ['admin_file_sync'=>['enabled'=>false]];
            $initialize->invoke($app, ['enabled'=>$flag]); $records = $handler->getRecords();
            admissionCheck(count($records) === ($flag === false ? 0 : 1), 'Invalid access flag must be reported; false alone is inert');
            if ($records !== []) { admissionCheck($records[0]->message === 'Access file synchronization unavailable: configuration or readiness prerequisites missing', 'Diagnostic must be fixed and sanitized'); }
            admissionCheck((new ReflectionProperty($app,'accessFileSync'))->getValue($app) === null, 'Invalid/unbound settings must allocate no service');
        }
        $app = (new ReflectionClass(Civ13::class))->newInstanceWithoutConstructor(); $handler = new Monolog\Handler\TestHandler();
        $app->logger = new Monolog\Logger('synthetic-default', [$handler]); $app->civ13_guild_id = ''; $app->role_ids = []; $app->options = ['admin_file_sync'=>['enabled'=>false]];
        $initialize->invoke($app, []);
        admissionCheck(count($handler->getRecords()) === 1, 'Omitted flag retains enabled intent and reports missing bindings');

        $path = $root.'/guard.txt'; file_put_contents($path, 'known-good'); $held = false; $called = 0;
        AdmissionLockProbe::$afterLock = static function () use (&$held): void { $held = true; };
        $failure = null;
        try { Ipc::replace($path, 'replacement', static function () use (&$held, &$called): void {
            ++$called; admissionCheck($held, 'Final predicate ran before acquiring the lock');
            throw new RuntimeException('synthetic-locked-refusal');
        }); } catch (RuntimeException $error) { $failure = $error; }
        admissionCheck($failure?->getMessage() === 'synthetic-locked-refusal' && $called === 1 && file_get_contents($path) === 'known-good', 'Rejected locked guard must preserve known-good bytes');
        $probe = fopen($path, 'c+b');
        try { admissionCheck(flock($probe, LOCK_EX | LOCK_NB), 'Refused guard must release the owned file lock'); } finally { flock($probe, LOCK_UN); fclose($probe); }
        Ipc::replace($path, 'allowed', static function () use (&$called): void { ++$called; });
        admissionCheck($called === 2 && file_get_contents($path) === 'allowed', 'Passing guard permits the replacement');
        Ipc::replace($path, 'legacy-unguarded');
        admissionCheck(file_get_contents($path) === 'legacy-unguarded', 'Existing callers without a predicate keep their behavior');

        foreach (['expires-after-lock','fresh','mapping-after-lock','local'] as $case) {
            $now = 1700000000; $path = $root.'/whitelist.txt'; file_put_contents($path, 'known-good');
            $fixture = admissionFixture($path, $now, $case !== 'local'); $before = $now; $lockBefore = AdmissionLockProbe::$locks;
            AdmissionLockProbe::$afterLock = static function () use ($case, &$now, $fixture): void {
                if ($case === 'expires-after-lock') { $now += HostedVerificationSnapshot::FRESH_SECONDS; }
                if ($case === 'mapping-after-lock') { $fixture['verifier']->verified = new Collection([['ss13'=>'fakebeta','discord'=>'990000000000000001']], 'discord'); }
                if ($case === 'local') { $now += HostedVerificationSnapshot::FRESH_SECONDS; }
            };
            $result = admissionResult($fixture['server']->whitelistUpdate());
            admissionCheck(AdmissionLockProbe::$locks === $lockBefore + 1 && AdmissionLockProbe::$afterLock === null, 'Causal change must happen after a real acquired file lock');
            if (in_array($case, ['expires-after-lock','mapping-after-lock'], true)) {
                admissionCheck($result['error'] instanceof RuntimeException && $result['error']->getMessage() === 'Role list update unavailable or inaccessible.' && file_get_contents($path) === 'known-good', 'Expired/changed authority after lock must refuse and preserve bytes: '.$case);
            } else {
                admissionCheck($result['error'] === null && file_get_contents($path) === 'fakealpha = 990000000000000001'.PHP_EOL, 'Fresh authenticated or non-read-only legacy update remains compatible: '.$case);
            }
            if ($case === 'expires-after-lock') { admissionCheck($fixture['verifier']->authorityTimes === [$before,$before+HostedVerificationSnapshot::FRESH_SECONDS], 'Actual authority path must see entry time and post-lock expiry'); }
            if ($case === 'fresh') { admissionCheck($fixture['verifier']->authorityTimes === [$before,$before], 'Fresh mapping is checked at entry and immediately before write'); }
            if ($case === 'local') { admissionCheck($fixture['verifier']->authorityTimes === [], 'Non-read-only mappings gain no hosted-authority requirement'); }
        }
        // A separate predicate is applied to every file, preserving the existing per-file semantics.
        $now = 1700000000; $fixture = admissionFixture($root.'/whitelist.txt', $now);
        foreach (['first','second'] as $name) { file_put_contents($root.'/'.$name.'.txt', 'known-good'); }
        $guards = 0;
        $formatter = new \Civ13\Server\GuardedRoleFormatter(static fn () => 'replacement', static function () use (&$guards): void { if (++$guards === 2) { throw new RuntimeException('synthetic-second-refusal'); } });
        $result = admissionResult($fixture['server']->updateFilesFromMemberRoles($formatter, [$root.'/first.txt',$root.'/second.txt'], ['Verified']));
        admissionCheck($guards === 2 && $result['error'] instanceof RuntimeException && file_get_contents($root.'/first.txt') === 'replacement' && file_get_contents($root.'/second.txt') === 'known-good', 'Predicate must run for each replacement; no multi-file atomicity claim');
        echo "PASS $checks access admission controls: strict flags, causal post-lock expiry/mapping, known-good preservation and legacy compatibility (mocked mapping transport)\n";
    } finally {
        AdmissionLockProbe::$afterLock = null;
        foreach ($known as $name) { $path = $root.'/'.$name; if (is_file($path)) { unlink($path); } }
        rmdir($root);
    }
}