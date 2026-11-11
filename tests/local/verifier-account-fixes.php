<?php
declare(strict_types=1);
// No bootstrap, .env, sockets, cURL or Discord client. The real pinned verifier endpoint runs in-process on temporary storage.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);
$loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14', true);
use Civ13\Civ13;
use Civ13\Verifier;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use React\Promise\PromiseInterface;
use VerifierServer\Endpoints\VerifiedEndpoint;
use VerifierServer\PersistentState;
use VerifierServer\Server;
use function React\Promise\resolve;
// Deprecations raised while loading third-party packages are not this test's subject.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { throw new ErrorException($message, 0, $severity, $file, $line); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function inert(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
final class AccountServer extends Server {
    public function __construct(VerifiedEndpoint $endpoint, PersistentState $state) { $this->state = $state; $this->endpoints['/verified'] = $endpoint; }
}
final class AccountRoles { public function __construct(public array $ids) {} public function has(mixed $id): bool { return in_array($id, $this->ids, true); } }
final class AccountMember extends Member {
    public string $fixtureId = '';
    public AccountRoles $fixtureRoles;
    public array $roleCalls = [];
    public function __get(string $key) { return match ($key) { 'id' => $this->fixtureId, 'roles' => $this->fixtureRoles, default => null }; }
    public function setRoles($roles, ?string $reason = null): PromiseInterface { $this->roleCalls[] = [$roles, $reason]; $this->fixtureRoles = new AccountRoles($roles); return resolve(null); }
}
final class AccountLookup { public function __construct(public array $items) {} public function get(string $key, mixed $value): mixed { return $this->items[$value] ?? null; } }
final class AccountGuild { public function __construct(public AccountLookup $members) {} }
final class AccountDiscord extends \Discord\Discord {
    public ?AccountGuild $guild = null;
    public array $cachedUsers = [];
    public function __construct() {}
    public function __get(string $name) { return match ($name) { 'guilds' => new AccountLookup($this->guild ? ['guild-1' => $this->guild] : []), 'users' => new AccountLookup($this->cachedUsers), default => null }; }
    public function getChannel($channel_id): ?Channel { return null; }
    public function __destruct() {}
}
final class AccountBot extends Civ13 {
    public array $files = [];
    public function VarSave(string $filename, array $assoc_array = []): bool { $this->files[$filename] = $assoc_array; return true; }
    public function VarLoad(string $filename = ''): ?array { return $this->files[$filename] ?? null; }
    public function permabancheck(string $ckey, bool $bypass = false): bool { return false; }
    public function sendMessage(Channel|Thread|string $channel, MessageBuilder|string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): PromiseInterface { return resolve(null); }
}
function member(string $id, array $roles): AccountMember { $member = inert(AccountMember::class); $member->fixtureId = $id; $member->fixtureRoles = new AccountRoles($roles); return $member; }
check(str_replace('\\', '/', (new ReflectionClass(Verifier::class))->getFileName()) === str_replace('\\', '/', realpath(__DIR__.'/../../src/Civ13/Verifier.php')), 'Test loaded another checkout');

$path = tempnam(sys_get_temp_dir(), 'civ-accounts-');
check($path !== false, 'Cannot create isolated verification storage');
$records = [
    ['ss13' => 'alpha', 'discord' => '111111111111111111', 'create_time' => 'fixture'],
    ['ss13' => 'bravo', 'discord' => '222222222222222222', 'create_time' => 'fixture'],
];
file_put_contents($path, json_encode($records));
$originalDirectory = getcwd();
chdir(dirname($path)); // PersistentState resolves storage relative to cwd
try {
    $state = new PersistentState('offline-token', 'filesystem', basename($path));
    $discord = new AccountDiscord();
    $alpha = member('111111111111111111', ['verified-role', 'veteran-role']);
    $bravo = member('222222222222222222', ['verified-role']);
    $discord->guild = new AccountGuild(new AccountLookup(['111111111111111111' => $alpha, '222222222222222222' => $bravo]));
    $bot = inert(AccountBot::class);
    $bot->discord = $discord;
    $bot->logger = $logger = new Monolog\Logger('offline-accounts', [new Monolog\Handler\NullHandler()]);
    $bot->civ_token = 'offline-token';
    $bot->civ13_guild_id = 'guild-1';
    $bot->role_ids = ['Verified' => 'verified-role'];
    $bot->channel_ids = [];
    $bot->verifier_server = new AccountServer(new VerifiedEndpoint($state), $state);
    $bot->files['verified.json'] = $records;
    $verifier = inert(Verifier::class);
    $verifier->civ13 = $bot;
    $verifier->discord = $discord;
    $verifier->logger = $logger;
    (new ReflectionProperty(Verifier::class, 'verify_url'))->setValue($verifier, 'http://offline.invalid/verified');
    (new ReflectionProperty(Verifier::class, 'readOnly'))->setValue($verifier, false);
    $verifier->verified = new Collection($records, 'discord');
    $verifier->provisional = new Collection([], 'discord');
    $verifier->pending = new Collection([], 'discord');
    $bot->verifier = $verifier;

    // Records are matched by `ss13`: a ckey that belongs to someone else is refused before any token step.
    $reply = $verifier->process('Alpha', '333333333333333333');
    check(str_contains($reply, '`alpha` is already verified') && str_contains($reply, "<@{$bot->technician_id}>") && ! str_contains($reply, '{<@') && ! str_contains($reply, '>}'), 'Taken ckey was not refused cleanly: '.$reply);

    // Lookups from a stored record work with either field, and unknown input yields null rather than the string "null".
    check($verifier->getVerifiedMember(['discord' => '111111111111111111']) === $alpha, 'Record with only a Discord id was not resolved');
    check($verifier->getVerifiedMember(['ss13' => 'bravo']) === $bravo, 'Record with only a ckey was not resolved');
    check($verifier->getVerifiedMember(['ss13' => 'bravo', 'discord' => '222222222222222222']) === $bravo, 'Full record was not resolved');
    check($verifier->getVerifiedMember(['ss13' => 'nobody']) === null && $verifier->getVerifiedMember(['other' => 'x']) === null, 'Unknown record resolved to a member');
    $user = inert(User::class);
    $discord->cachedUsers = ['111111111111111111' => $user];
    check($verifier->getVerifiedUser('alpha') === $user && $verifier->getVerifiedUser(['discord' => '111111111111111111']) === $user, 'Cached user was not returned');
    check($verifier->getVerifiedUser('bravo') === null && $verifier->getVerifiedUser(['ss13' => 'nobody']) === null, 'Uncached or unknown user did not return null');

    // Unverifying by ckey strips the member's roles even though the record has already left the verified list.
    $result = $verifier->unverify('alpha');
    check($result['success'] === true, 'Unverify failed: '.$result['message']);
    check($alpha->roleCalls === [[[], 'unverified (alpha)']], 'Unverified member kept their roles');
    check($bravo->roleCalls === [], 'Another member lost their roles');
    check(substr_count($result['message'], 'Removed from the verified list') === 1 && substr_count($result['message'], '"ss13": "alpha"') === 1, 'Unverify message was duplicated: '.$result['message']);
    check(! str_contains($result['message'], "couldn't be found"), 'Present member reported as missing');
    check($state->getVerifyList() === [$records[1]] && $bot->files['verified.json'] === [$records[1]] && $verifier->get('ss13', 'alpha') === null, 'Record was not removed everywhere');
    // By Discord id, for a member who already left the server: reported once, nothing thrown.
    $discord->guild = new AccountGuild(new AccountLookup([]));
    $result = $verifier->unverify('222222222222222222');
    check($result['success'] === true && substr_count($result['message'], "couldn't be found in the server") === 1 && substr_count($result['message'], 'Removed from the verified list') === 1, 'Missing member not reported once: '.$result['message']);
    check($bravo->roleCalls === [] && $state->getVerifyList() === [], 'Departed member handling changed roles or kept the record');
    check($verifier->unverify('alpha')['success'] === false, 'Unknown id reported success');
    echo "PASS verifier accounts: taken-ckey refusal, record lookups, cached user lookup, unverify role removal and single message\n";
} finally {
    chdir($originalDirectory);
    @unlink($path);
}
