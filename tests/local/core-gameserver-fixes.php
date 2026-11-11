<?php
declare(strict_types=1);
// No bootstrap, .env, sockets, Discord client or game processes. Game files live in a temporary directory.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);
$loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14', true);
use Civ13\Civ13;
use Civ13\GameServer;
use Discord\Parts\Guild\Role;
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
// Deprecations raised while loading third-party packages are not this test's subject.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { throw new ErrorException($message, 0, $severity, $file, $line); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function inert(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
final class CoreRoles implements IteratorAggregate {
    public function __construct(public array $ids) {}
    public function has(mixed $id): bool { return in_array((string) $id, $this->ids, true); }
    public function getIterator(): Iterator { return new ArrayIterator(array_map(static fn (string $id): object => (object) ['id' => $id], $this->ids)); }
}
final class CoreMember extends Member {
    public CoreRoles $fixtureRoles;
    public array $calls = [];
    public function __get(string $key) { return match ($key) { 'roles' => $this->fixtureRoles, 'id' => 'member-1', 'guild' => (object) ['members' => new class($this) { public function __construct(private object $member) {} public function get(string $key, mixed $value): object { return $this->member; } }], default => null }; }
    public function setRoles($roles, ?string $reason = null): PromiseInterface { $this->calls[] = ['set', $roles]; return resolve($this); }
    public function addRole($role, ?string $reason = null): PromiseInterface { $this->calls[] = ['add', $role]; return resolve($this); }
    public function removeRole($role, ?string $reason = null): PromiseInterface { $this->calls[] = ['remove', $role]; return resolve($this); }
}
final class CoreRole extends Role {
    public array $fixture = [];
    public function __get(string $key) { return $this->fixture[$key] ?? null; }
}
final class CoreServer extends GameServer {
    public bool $accepts = true;
    public array $said = [];
    public function OOCMessage(string $message, string $sender): bool { $this->said[] = ['ooc', $message, $sender]; return $this->accepts; }
    public function AdminMessage(string $message, string $sender): bool { $this->said[] = ['asay', $message, $sender]; return $this->accepts; }
    public function DirectMessage(string $message, string $sender, string $recipient): bool { $this->said[] = ['dm', $message, $sender, $recipient]; return $this->accepts; }
}
final class CoreDiscord extends \Discord\Discord {
    public mixed $cache = null;
    public function __construct() {}
    public function getCacheConfig($repository_class = \Discord\Repository\AbstractRepository::class) { return $this->cache; }
    public function getChannel($channel_id): ?\Discord\Parts\Channel\Channel { throw new RuntimeException('Unexpected channel lookup'); }
    public function __destruct() {}
}
function member(array $roles): CoreMember { $member = inert(CoreMember::class); $member->fixtureRoles = new CoreRoles($roles); return $member; }
function role(string $id, int $position): CoreRole { $role = inert(CoreRole::class); $role->fixture = ['id' => $id, 'position' => $position]; return $role; }
function server(string $basedir, string $key): CoreServer {
    $server = inert(CoreServer::class);
    foreach (['basedir' => $basedir, 'key' => $key, 'name' => ucfirst($key)] as $property => $value) { (new ReflectionProperty(GameServer::class, $property))->setValue($server, $value); }
    $server->logger = new Monolog\Logger('offline-core', [new Monolog\Handler\NullHandler()]);

    return $server;
}
check(str_replace('\\', '/', (new ReflectionClass(GameServer::class))->getFileName()) === str_replace('\\', '/', realpath(__DIR__.'/../../src/Civ13/GameServer.php')), 'Test loaded another checkout');

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'civbot-core-'.bin2hex(random_bytes(6));
check(mkdir($root.'/a/SQL', 0700, true) && mkdir($root.'/b', 0700, true), 'Unable to create temporary storage');
try {
    $log = new Monolog\Handler\TestHandler();
    $bot = inert(Civ13::class);
    $bot->logger = new Monolog\Logger('offline-core', [$log]);
    $bot->discord = $discord = new CoreDiscord();
    $bot->loop = new React\EventLoop\StreamSelectLoop();

    // Ban rows: an 8-field row has no ckey column and is skipped; 9 or more fields are matched on the ckey column.
    $short = 'Server;;uid;reason;admin;date;timestamp;999 years|||';
    $perma = 'Server;;uid;reason;admin;date;timestamp;999 years;permaplayer;cid;ip|||';
    $timed = 'Server;;uid;reason;admin;date;timestamp;3 days;timedplayer|||';
    $second = 'Server;;uid2;second reason;admin;date;timestamp;9 days;timedplayer|||';
    file_put_contents($root.'/a'.Civ13::bans, implode(PHP_EOL, [$short, $perma, $timed, $second]).PHP_EOL);
    file_put_contents($root.'/a'.Civ13::playerlogs, 'someone;203.0.113.5;cid1;uid;date|'.PHP_EOL);
    $a = server($root.'/a', 'a');
    $b = server($root.'/b', 'b');
    check($a->legacyBancheck('permaplayer') && $a->legacyBancheck('timedplayer') && ! $a->legacyBancheck('nobody') && ! $a->legacyBancheck(''), 'Ban lookup misread the bans file');
    check($a->legacyPermabancheck('permaplayer') && ! $a->legacyPermabancheck('timedplayer') && ! $a->legacyPermabancheck('nobody'), 'Permaban lookup misread the bans file');
    // Log cleanup reports whether both files were handled.
    check($a->cleanupLogs() === true && $b->cleanupLogs() === false, 'Log cleanup did not report its outcome');
    // A ckey with nothing to backfill leaves the bans file untouched.
    $before = file_get_contents($root.'/a'.Civ13::bans);
    $resolved = null;
    $a->banlog_update('nobody', 'someone;203.0.113.5;cid1;uid;date|')->then(function ($value) use (&$resolved) { $resolved = $value; });
    check($resolved === '' && file_get_contents($root.'/a'.Civ13::bans) === $before, 'Backfill for an unknown ckey appended to the bans file');
    // A full rewrite keeps every row, including rows without a ckey column, and fills in what the player log knows.
    $a->banlog_update(null, 'timedplayer;198.51.100.7;cid9;uid;date|');
    $rewritten = (string) file_get_contents($root.'/a'.Civ13::bans);
    check(substr_count($rewritten, rtrim($short, '|')) === 2 && str_contains($rewritten, 'permaplayer;cid;ip') && substr_count($rewritten, 'timedplayer;cid9;198.51.100.7') === 2 && str_contains($rewritten, 'second reason') && substr_count($rewritten, '|||') === 4, 'Full rewrite lost or failed to backfill a row: '.$rewritten);
    check($a->legacyBancheck('permaplayer') && $a->legacyBancheck('timedplayer'), 'Rewritten bans file no longer matches');

    // A message to every server succeeds when any server accepted it, whatever the order.
    foreach ([[true, false], [false, true]] as [$first, $second]) {
        $a->accepts = $first; $b->accepts = $second; $a->said = $b->said = [];
        $bot->enabled_gameservers = ['a' => $a, 'b' => $b];
        check($bot->OOCMessage('hello', 'admin') && $bot->AdminMessage('hello', 'admin') && $bot->DirectMessage('hello', 'admin', 'player'), 'Accepted message reported as failed');
        check(count($a->said) === 3 && count($b->said) === 3, 'A server was skipped');
    }
    $a->accepts = $b->accepts = false;
    check(! $bot->OOCMessage('hello', 'admin') && ! $bot->AdminMessage('hello', 'admin') && ! $bot->DirectMessage('hello', 'admin', 'player'), 'Refused message reported as sent');
    check(! $bot->OOCMessage('hello', 'admin', 'missing'), 'Unknown server reported success');

    // Role helpers: each role is requested exactly once and unchanged members cause no request.
    $member = member(['1', '2']);
    $bot->addRoles($member, ['2', '3', '4'], false);
    check($member->calls === [['add', '3'], ['add', '4']], 'Unpatched add repeated or skipped a role: '.json_encode($member->calls));
    $member = member(['1', '2', '3']);
    $bot->removeRoles($member, ['2', '3', '9'], false);
    check($member->calls === [['remove', '2'], ['remove', '3']], 'Unpatched remove repeated or skipped a role: '.json_encode($member->calls));
    $member = member(['1', '2']);
    $bot->setRoles($member, ['3'], ['2']);
    check($member->calls === [['set', ['1', '3']]], 'Combined update was wrong: '.json_encode($member->calls));
    $member = member(['1', '2']);
    $bot->setRoles($member, ['3']);
    $bot->setRoles($member, [], ['2']);
    check($member->calls === [['set', ['1', '2', '3']], ['set', ['1']]], 'One-sided update was dropped: '.json_encode($member->calls));
    $member = member(['1', '2']);
    $bot->setRoles($member, ['1'], ['9']);
    $bot->setRoles($member);
    $bot->addRoles($member, []);
    $bot->removeRoles($member, []);
    check($member->calls === [], 'No-op update sent a request: '.json_encode($member->calls));
    $high = role('10', 5); $low = role('11', 1); $mid = role('12', 3);
    check($bot->getHighestRole(new Discord\Helpers\Collection([$low, $high, $mid], null)) === $high && $bot->getHighestRole(new Discord\Helpers\Collection([], null)) === null, 'Highest role was not found');

    // Startup helpers.
    $ran = 0;
    $bot->ready = true;
    $bot->deferUntilReady(function () use (&$ran) { $ran++; });
    check($ran === 1 && ! $log->hasInfoThatContains('Deferring'), 'Ready callback was deferred or logged as deferred');
    $initial = new ReflectionMethod(Civ13::class, 'initialLogger');
    $given = new Monolog\Logger('given', [new Monolog\Handler\NullHandler()]);
    check($initial->invoke(null, ['logger' => $given]) === $given && $initial->invoke(null, []) instanceof Psr\Log\LoggerInterface && $initial->invoke(null, ['logger' => null, 'discord' => 'not a client']) instanceof Psr\Log\LoggerInterface, 'Constructor has no logger without a Discord client');
    $options = ['minimum_age' => '-7 days', 'blacklisted_regions' => ['10.']];
    $resolvedOptions = (new ReflectionMethod(Civ13::class, 'resolveOptions'))->invokeArgs($bot, [&$options]);
    check($resolvedOptions['minimum_age'] === '-7 days' && $resolvedOptions['blacklisted_regions'] === ['10.'], 'Moderation options were rejected');
    // The country auto-ban never matched (it compared country names to codes) and was removed.
    check(! property_exists(Civ13::class, 'blacklisted_countries') && ! str_contains((string) file_get_contents(__DIR__.'/../../src/Civ13/Moderator.php'), 'Blacklisted Country'), 'Removed country auto-ban is back');

    // Periodic ban checks switch off cleanly under a Redis-style cache, with no timer to cancel yet.
    $bot->enabled_gameservers = ['a' => $a];
    $bot->verifier = inert(\Civ13\Verifier::class);
    (new ReflectionProperty(\Civ13\Verifier::class, 'readOnly'))->setValue($bot->verifier, false);
    $discord->cache = (object) ['interface' => new React\Cache\ArrayCache()];
    check($bot->bancheckTimer() === false && ! isset($bot->timers['bancheck_timer']), 'Ban checks were scheduled although switched off');
    check($bot->bancheckTimer() === false, 'Second call misbehaved');
    // Banning by Discord id with no verifier explains itself instead of failing.
    unset($bot->verifier);
    check(str_starts_with($bot->ban(['ckey' => '123456789012345678', 'duration' => '1 day', 'reason' => 'fixture']), 'Unable to find a ckey for'), 'Discord-id ban without a verifier was not refused');
    check($bot->statusChannelUpdate('ignored', true) === null, 'Status update without a configured channel did something');
    echo "PASS core and game server: ban rows, log cleanup, backfill, multi-server messages, role helpers, startup helpers, ban-check switch-off\n";
} finally {
    foreach (['/a/SQL/bans.txt', '/a/SQL/playerlogs.txt'] as $file) { @unlink($root.$file); }
    foreach (['/a/SQL', '/a', '/b', ''] as $dir) { @rmdir($root.$dir); }
}
