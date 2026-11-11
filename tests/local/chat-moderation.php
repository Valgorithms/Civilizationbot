<?php
declare(strict_types=1);
// No bootstrap, .env, sockets, Discord client, or real game files. Storage is a temporary directory.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);
$loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14', true);
use Civ13\Civ13;
use Civ13\GameServer;
use Civ13\MessageHandler;
use Civ13\Moderator;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\Collection;
use Discord\Helpers\CollectionInterface;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
// Deprecations raised while loading third-party packages are not this test's subject.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { throw new ErrorException($message, 0, $severity, $file, $line); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function inert(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
final class ModerationGuilds { public function get(string $key, mixed $value): mixed { return null; } }
final class ModerationDiscord extends \Discord\Discord {
    public function __construct() {}
    public function __get(string $name) { return match ($name) { 'username' => 'CivBot', 'guilds' => new ModerationGuilds(), default => null }; }
    public function __destruct() {}
}
final class ModerationVerifier extends \Civ13\Verifier {
    public array $discords = [];
    public function getVerifiedItem(Member|User|array|string $input): ?array { return null; }
    public function get(string $discrim, $key): mixed { return isset($this->discords[$key]) ? ['ss13' => $key, 'discord' => $this->discords[$key]] : null; }
}
final class ModerationBot extends Civ13 {
    public array $sent = [];
    public array $banned = [];
    public array $players = [];
    public function sendMessage(Channel|Thread|string $channel, MessageBuilder|string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): PromiseInterface { $this->sent[] = $content; return resolve(null); }
    public function ban(array $array, ?string $admin = null, string|int|null $server = null, bool $permanent = false): string { $this->banned[] = $array; return 'banned'; }
    public function playerlogsToCollection(&$log_collection = new Collection([], 'increment'), int &$increment = 0): CollectionInterface { return new Collection($this->players, null); }
    public function bansToCollection($log_collection = new Collection([], 'increment'), int $increment = 0): CollectionInterface { return new Collection([], null); }
    public function bancheck(string $ckey, bool $bypass = false, bool $use_cache = false): bool { return false; }
    public function filterMessage(Message $message): array { return ['message_content' => 'boom', 'message_content_lower' => 'boom', 'called' => true]; }
}
final class ModerationServer extends GameServer {
    public array $direct = [];
    public function DirectMessage(string $message, string $sender, string $recipient): bool { $this->direct[] = [$recipient, $message]; return true; }
}
final class ModerationChannel extends Channel {}
final class ModerationMessage extends Message {
    public array $reactions = [];
    public function __get(string $name) { return match ($name) { 'member' => inert(Member::class), 'content' => 'boom', default => null }; }
    public function react($emoticon): PromiseInterface { $this->reactions[] = $emoticon; return resolve(null); }
}
check(str_replace('\\', '/', (new ReflectionClass(Moderator::class))->getFileName()) === str_replace('\\', '/', realpath(__DIR__.'/../../src/Civ13/Moderator.php')), 'Test loaded another checkout');

$storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'civbot-moderation-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR;
check(mkdir($storage, 0700, true), 'Unable to create temporary storage');
try {
    $logger = new Monolog\Logger('offline-moderation', [$log = new Monolog\Handler\TestHandler()]);
    $discord = new ModerationDiscord();
    $bot = inert(ModerationBot::class);
    $bot->discord = $discord;
    $bot->logger = $logger;
    $bot->filecache_path = $storage;
    $bot->verifier = inert(ModerationVerifier::class);
    $bot->channel_ids = [];
    $bot->role_ids = [];
    $bot->moderator = inert(Moderator::class);
    $bot->moderator->civ13 = $bot;
    $bot->moderator->discord = $discord;
    $bot->moderator->logger = $logger;
    $rule = ['word' => 'badword', 'category' => 'test', 'method' => 'str_contains', 'warnings' => 2, 'duration' => '1 minute', 'reason' => 'fixture'];
    $bot->ooc_badwords = [$rule];
    $bot->ic_badwords = [['word' => 'x', 'category' => 'short', 'method' => 'exact', 'warnings' => 1, 'duration' => '1 minute', 'reason' => 'fixture']];
    $server = inert(ModerationServer::class);
    $server->civ13 = $bot;
    $server->discord = $discord;
    $server->logger = $logger;
    $server->moderate = true;
    $relay = new ReflectionMethod(GameServer::class, '__gameChatRelay');
    $say = static fn (string $ckey, string $text, bool $ooc) => $relay->invoke($server, inert(ModerationChannel::class), ['ckey' => $ckey, 'message' => $text, 'server' => 'fixture'], $ooc, true);

    // Repeat violations accumulate on the bot, persist to disk, and escalate from warnings to a ban.
    $say('offender', 'this is a BadWord', true);
    check($bot->ooc_badwords_warnings === ['offender' => ['test' => 1]], 'First OOC violation was not counted on the bot');
    check(json_decode((string) file_get_contents($storage.'ooc_badwords_warnings.json'), true) === ['offender' => ['test' => 1]], 'First OOC violation was not saved');
    $say('offender', 'badword again', true);
    $say('innocent', 'a perfectly fine message', true);
    check(count($server->direct) === 2 && $bot->banned === [], 'Player was not warned exactly twice before a ban');
    check(str_contains($server->direct[0][1], 'b%%%%%d') && ! str_contains($server->direct[0][1], 'badword'), 'Warning did not mask the phrase');
    $say('offender', 'badword a third time', true);
    check(count($bot->banned) === 1 && $bot->banned[0]['ckey'] === 'offender' && $bot->banned[0]['duration'] === '1 minute', 'Third violation did not ban');
    check(count($server->direct) === 2, 'Banned player was also warned');
    check($bot->ooc_badwords_warnings === ['offender' => ['test' => 3]] && $bot->ic_badwords_warnings === [], 'OOC counters leaked or were lost');
    check(! file_exists($storage.'ic_badwords_warnings.json'), 'OOC violation wrote the IC file');
    check(count($bot->sent) === 4, 'Clean chat was not relayed for every message');

    // IC rules keep their own counters and file, and a one-character word must not throw.
    $say('offender', 'x', false);
    check($bot->ic_badwords_warnings === ['offender' => ['short' => 1]], 'IC violation was not counted');
    check(json_decode((string) file_get_contents($storage.'ic_badwords_warnings.json'), true) === ['offender' => ['short' => 1]], 'IC violation was not saved');
    check(json_decode((string) file_get_contents($storage.'ooc_badwords_warnings.json'), true) === ['offender' => ['test' => 3]], 'IC violation changed the OOC file');
    // The bot's own relayed text is never counted.
    $say('CivBot', 'badword', true);
    check(! isset($bot->ooc_badwords_warnings['civbot']) && ! isset($bot->ooc_badwords_warnings['CivBot']), 'Bot was moderated');
    // Without a file the counters still update in memory (callers outside the relay).
    $memory = [];
    check($bot->moderator->moderate($server, 'other', 'badword', [$rule], $memory) && $memory === ['other' => ['test' => 1]], 'In-memory counter failed');
    check(! $bot->moderator->moderate($server, 'other', 'nothing wrong', [$rule], $memory), 'Clean text reported as an infraction');
    foreach (['' => '', 'a' => 'a', 'ab' => 'ab', 'abc' => 'a%c', 'badword' => 'b%%%%%d'] as $word => $masked) {
        check(Moderator::filterWord((string) $word) === $masked, "Unexpected mask for `$word`");
    }

    // ckeyinfo() always returns the keyed shape its callers read, including when nothing is known.
    $keys = ['ckeys', 'ips', 'cids', 'banned', 'altbanned', 'discords', 'verified'];
    foreach (['', '<@>'] as $blank) {
        $empty = $bot->ckeyinfo($blank);
        check(array_keys($empty) === $keys && $empty['ckeys'] === [] && $empty['ips'] === [] && $empty['discords'] === [] && $empty['altbanned'] === false && $empty['verified'] === false, 'Empty ckeyinfo lost its shape');
    }
    $bot->players = [['ckey' => 'target', 'ip' => '203.0.113.5', 'cid' => 'cid1', 'date' => '2026-01-01']];
    check($bot->ckeyinfo('target')['verified'] === false, 'Unverified ckey reported as verified');
    $bot->verifier->discords = ['target' => '123'];
    $info = $bot->ckeyinfo('target');
    check($info['verified'] === true && array_values($info['discords']) === ['123'], 'Verified ckey reported as unverified');

    // A command that throws anything is logged and answered with a reaction, never rethrown into Discord's event loop.
    foreach ([new RuntimeException('fixture failure'), new TypeError('fixture type error')] as $failure) {
        $handler = inert(MessageHandler::class);
        $handler->civ13 = $bot;
        $handler->logger = $logger;
        (new ReflectionProperty(MessageHandler::class, 'attributes'))->setValue($handler, [
            'handlers' => ['boom' => static function (Message $message, string $endpoint, array $message_filtered) use ($failure): never { throw $failure; }],
            'required_permissions' => [], 'match_methods' => ['boom' => 'exact'], 'descriptions' => [],
        ]);
        $message = inert(ModerationMessage::class);
        $result = $handler->handle($message);
        check($result instanceof PromiseInterface && $message->reactions === ['🔥'], 'Throwing command was not contained: '.$failure::class);
        check($log->hasErrorThatContains($failure->getMessage()), 'Throwing command was not logged: '.$failure::class);
    }
    echo "PASS chat moderation: persistent warning counters, warn-then-ban escalation, IC/OOC separation, masks, ckeyinfo shape, contained command failures\n";
} finally {
    foreach (glob($storage.'*') ?: [] as $file) { @unlink($file); }
    @rmdir($storage);
}
