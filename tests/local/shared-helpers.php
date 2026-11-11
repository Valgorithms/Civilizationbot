<?php
declare(strict_types=1);
// No bootstrap, .env, sockets, Discord client or game processes. State and logs live in a temporary directory.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);
$loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14', true);
use Civ13\Civ13;
use Civ13\GameServer;
use Civ13\MessageCommand\Commands;
use Civ13\MessageServiceManager;
use Civ13\OSFunctions;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
// Deprecations raised while loading third-party packages are not this test's subject.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { if (! (error_reporting() & $severity)) { return true; } throw new ErrorException($message, 0, $severity, $file, $line); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function inert(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
function wipe(string $dir): void {
    foreach (array_diff(@scandir($dir) ?: [], ['.', '..']) as $entry) { is_dir("$dir/$entry") ? wipe("$dir/$entry") : @unlink("$dir/$entry"); }
    @rmdir($dir);
}
final class HelperRoles { public function __construct(public array $ids) {} public function has(mixed $id): bool { return in_array($id, $this->ids, true); } }
final class HelperMember extends Member {
    public array $fixture = [];
    public array $calls = [];
    public function __get(string $key) { return $this->fixture[$key] ?? null; }
    public function addRole($role, ?string $reason = null): PromiseInterface { $this->calls[] = ['add', $role, $reason]; return resolve($this); }
    public function removeRole($role, ?string $reason = null): PromiseInterface { $this->calls[] = ['remove', $role, $reason]; return resolve($this); }
}
final class HelperUser extends User {
    public array $fixture = [];
    public function __get(string $key) { return $this->fixture[$key] ?? null; }
}
final class HelperMessage extends Message {
    public array $fixture = [];
    public array $reactions = [];
    public function __get(string $name) { return $this->fixture[$name] ?? null; }
    public function react($emoticon): PromiseInterface { $this->reactions[] = $emoticon; return resolve(null); }
}
final class HelperChannel extends Channel {}
final class HelperDiscord extends \Discord\Discord {
    public function __construct() {}
    public function getChannel($channel_id): ?Channel { return inert(HelperChannel::class); }
    public function __destruct() {}
}
final class HelperVerifier extends \Civ13\Verifier {
    public array $items = [];
    public ?Member $member = null;
    public function getVerifiedItem(Member|User|array|string $input): ?array { return $this->items[$input instanceof User ? $input->id : (is_string($input) ? $input : '')] ?? null; }
    public function getVerifiedMember(Member|User|array|string|null $input): ?Member { return $this->member; }
}
final class HelperBot extends Civ13 {
    public array $replies = [];
    public array $sent = [];
    public function reply(Message|Thread $message, string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): PromiseInterface { $this->replies[] = $content; return resolve(null); }
    public function sendMessage(Channel|Thread|string $channel, MessageBuilder|string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): PromiseInterface { $this->sent[] = $content; return resolve(null); }
}
function user(string $id, string $username): HelperUser { $user = inert(HelperUser::class); $user->fixture = ['id' => $id, 'username' => $username]; return $user; }
check(str_replace('\\', '/', (new ReflectionClass(OSFunctions::class))->getFileName()) === str_replace('\\', '/', realpath(__DIR__.'/../../src/Civ13/OSFunctions.php')), 'Test loaded another checkout');

$root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'civbot-helpers-'.bin2hex(random_bytes(6));
check(mkdir($root.'/cache', 0700, true) && mkdir($root.'/a/data/logs', 0700, true) && mkdir($root.'/b', 0700, true), 'Unable to create temporary storage');
try {
    $cache = $root.'/cache/';
    $logger = new Monolog\Logger('offline-helpers', [$log = new Monolog\Handler\TestHandler()]);

    // Saves replace the file whole, leave no temporary files, and never swap good data for an unencodable value.
    check(OSFunctions::VarSave($cache, 'state.json', ['a' => 1], $logger) && OSFunctions::VarLoad($cache, 'state.json', $logger) === ['a' => 1], 'State did not round-trip');
    check(OSFunctions::VarSave($cache, 'state.json', ['a' => 2, 'b' => ['c']], $logger) && OSFunctions::VarLoad($cache, 'state.json', $logger) === ['a' => 2, 'b' => ['c']], 'State was not replaced');
    check(OSFunctions::VarSave($cache, 'state.json', ['bad' => "\xB1\x31"], $logger) === false && OSFunctions::VarLoad($cache, 'state.json', $logger) === ['a' => 2, 'b' => ['c']], 'Unencodable data damaged the saved state');
    check($log->hasWarningThatContains('Unable to encode data'), 'Encoding failure was not logged');
    check(OSFunctions::VarSave($cache, '', ['a' => 1], $logger) === false, 'Empty file name was accepted');
    check(OSFunctions::writeFileAtomically($root.'/missing-dir/x.json', '{}') === false, 'Write into a missing directory reported success');
    check(array_values(array_diff(scandir($cache), ['.', '..'])) === ['state.json'], 'Temporary files were left behind: '.json_encode(scandir($cache)));
    unlink($cache.'state.json');

    // Startup state: missing files are created empty, existing ones load, and an unreadable one is kept for inspection.
    $bot = inert(HelperBot::class);
    $bot->logger = $logger;
    $bot->filecache_path = $cache;
    $bot->role_ids = [];
    file_put_contents($cache.'permitted.json', json_encode(['someone' => true]));
    file_put_contents($cache.'ages.json', '{"broken": ');
    (new ReflectionMethod(Civ13::class, '__loadOrInitializeVariables'))->invoke($bot);
    check($bot->permitted === ['someone' => true] && $bot->ages === [] && $bot->paroled === [] && $bot->ooc_badwords_warnings === [], 'State was not loaded');
    check(file_get_contents($cache.'ages.json') === '{"broken": ', 'Unreadable state file was overwritten');
    foreach (Civ13::STATE_FILES as $property => $filename) {
        check(property_exists(Civ13::class, $property) && is_array($bot->{$property}) && file_exists($cache.$filename), "State `$property` was not initialised");
    }
    check(file_get_contents($cache.'tests.json') === '[]' && count(Civ13::STATE_FILES) === 9, 'Missing state file was not created empty');

    // Log navigation answers the logs command and the round log button from one place.
    $server = static function (string $key, string $basedir): GameServer { $s = inert(GameServer::class); foreach (['key' => $key, 'basedir' => $basedir] as $p => $v) { (new ReflectionProperty(GameServer::class, $p))->setValue($s, $v); } return $s; };
    $bot->enabled_gameservers = ['a' => $server('a', $root.'/a'), 'b' => $server('b', $root.'/b')];
    mkdir($root.'/a/data/logs/2026');
    file_put_contents($root.'/a/data/logs/2026/round.log', 'round');
    check($bot->logNavigation('') === ['text' => 'Please use the format `logs {server}`. Valid servers: `a, b`'] && $bot->logNavigation('zzz;2026') === $bot->logNavigation(''), 'Unknown server was not explained');
    check($bot->logNavigation('a') === ['text' => 'Available options: '.PHP_EOL.'`2026`'], 'Options were not listed');
    check($bot->logNavigation('a;1999') === ['text' => '1999 is not an available option! Available options: '.PHP_EOL.'`2026`'], 'Bad option was not explained');
    check($bot->logNavigation(' a ; 2026 ; round.log') === ['file' => $root.'/a/data/logs/2026/round.log'], 'Log file was not found');
    check($bot->logNavigation('a;..;..;cache;permitted.json') === ['text' => '.. is not an available option! Available options: '.PHP_EOL.'`2026`'], 'Navigation left the log directory');
    check(isset($bot->logNavigation('b')['error']), 'Missing log directory was not reported');
    for ($day = 1; $day <= 9; $day++) { mkdir($root."/a/data/logs/2026/0$day"); }
    $listed = $bot->logNavigation('a;2026')['text'];
    check($listed === 'Available options: '.PHP_EOL.'`'.implode('`'.PHP_EOL.'`', ['round.log', '09', '08', '07', '06', '05', '04']).'`', 'Long listing was not cut to the newest seven: '.$listed);
    $message = inert(HelperMessage::class);
    $bot->replies = [];
    (new Commands\Civ13Logs($bot))($message, 'logs', ['message_content' => 'logs a;1999', 'message_content_lower' => 'logs a;1999']);
    check($bot->replies === ['1999 is not an available option! Available options: '.PHP_EOL.'`2026`'], 'Logs command did not use the shared answer');
    (new Commands\Civ13Logs($bot))($message, 'logs', ['message_content' => 'logs b', 'message_content_lower' => 'logs b']);
    check($message->reactions === ['🔥'], 'Logs command did not flag a missing directory');

    // Sender names: a verified author is shown by ckey, anyone else by Discord username.
    $bot->verifier = $verifier = inert(HelperVerifier::class);
    $verifier->items = ['111' => ['ss13' => 'adminckey', 'discord' => '111'], 'targetckey' => ['ss13' => 'targetckey', 'discord' => '222'], '333' => ['discord' => '333']];
    $command = new Commands\GlobalOOC($bot);
    $name = new ReflectionMethod($command, 'authorName');
    $ckey = new ReflectionMethod($command, 'authorCkey');
    $verified = inert(HelperMessage::class); $verified->fixture = ['author' => user('111', 'Admin Discord')];
    $plain = inert(HelperMessage::class); $plain->fixture = ['author' => user('999', 'Plain Discord')];
    $partial = inert(HelperMessage::class); $partial->fixture = ['author' => user('333', 'Partial Discord')];
    check($name->invoke($command, $verified) === 'adminckey' && $ckey->invoke($command, $verified) === 'adminckey', 'Verified author was not named by ckey');
    check($name->invoke($command, $plain) === 'Plain Discord' && $ckey->invoke($command, $plain) === null, 'Unverified author was not named by username');
    check($name->invoke($command, $partial) === 'Partial Discord' && $verifier->ckeyOf(null) === null && $verifier->ckeyOf('targetckey') === 'targetckey', 'Record without a ckey was not handled');

    // parole and release are one command with two directions.
    $bot->discord = $discord = new HelperDiscord();
    $bot->role_ids = ['Paroled' => 'paroled-role'];
    $bot->channel_ids = ['parole_logs' => 'log-channel'];
    $manager = inert(MessageServiceManager::class);
    $manager->civ13 = $bot;
    $manager->discord = $discord;
    $manager->logger = $logger;
    $factory = new ReflectionMethod(MessageServiceManager::class, 'paroleCommand');
    $target = inert(HelperMember::class);
    $target->fixture = ['roles' => new HelperRoles([])];
    $verifier->member = $target;
    $staff = inert(HelperMember::class); $staff->fixture = ['displayname' => 'Staff'];
    $say = static function (bool $parole, string $content, HelperUser $author) use ($factory, $manager, $staff): HelperMessage {
        $message = inert(HelperMessage::class);
        $message->fixture = ['author' => $author, 'user_id' => $author->id, 'member' => $staff];
        ($factory->invoke($manager, $parole))($message, $parole ? 'parole' : 'release', ['message_content_lower' => $content]);

        return $message;
    };
    $done = $say(true, 'parole TargetCkey', user('111', 'Admin Discord'));
    check($bot->paroled === ['targetckey' => '111'] && $target->calls === [['add', 'paroled-role', '`adminckey` (Staff) paroled `targetckey`']] && $done->reactions === ['👍'], 'Parole did not apply: '.json_encode($target->calls));
    check($bot->sent === ['`targetckey` (<@222>) has been placed on parole by `adminckey` (<@111>).'], 'Parole was not logged: '.json_encode($bot->sent));
    $target->fixture['roles'] = new HelperRoles(['paroled-role']);
    $say(true, 'parole targetckey', user('111', 'Admin Discord'));
    check(count($target->calls) === 1, 'Role was added twice');
    $bot->sent = [];
    $say(false, 'release targetckey', user('999', 'Plain Discord'));
    check($bot->paroled === [] && $target->calls[1] === ['remove', 'paroled-role', '`Plain Discord` (Staff) released `targetckey`'], 'Release did not apply: '.json_encode($target->calls));
    check($bot->sent === ['`targetckey` (<@222>) has been released from parole by `Plain Discord` (<@999>).'], 'Release was not logged: '.json_encode($bot->sent));
    $bot->replies = [];
    $say(false, 'release nobody', user('111', 'Admin Discord'));
    check($bot->replies === ['`nobody` is not currently verified with a byond username or it does not exist in the cache yet'], 'Unknown target was not explained: '.json_encode($bot->replies));
    echo "PASS shared helpers: atomic state saves, startup state files, log navigation, sender names, parole and release\n";
} finally {
    wipe($root);
}
