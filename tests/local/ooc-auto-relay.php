<?php
declare(strict_types=1);
// Plain #ooc messages relay to the game: eligibility, formatting and IPC framing, offline.
require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../src/Civ13/Moderator.php';
require_once __DIR__.'/../../src/Civ13/OocAutoRelay.php';
require_once __DIR__.'/../../src/Civ13/Server/Ipc.php';
require_once __DIR__.'/../../src/Civ13/Civ13.php';
require_once __DIR__.'/../../src/Civ13/GameServer.php';
require_once __DIR__.'/../../src/Civ13/Verifier.php';
require_once __DIR__.'/../../src/Civ13/MessageServiceManager.php';
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use Civ13\OocAutoRelay;
use Civ13\Server\Ipc;
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

// Eligibility: home guild, human, not a command, verified, not banished.
check(OocAutoRelay::eligible(true, false, false, 'player', false), 'Verified member was not relayed');
check(!OocAutoRelay::eligible(false, false, false, 'player', false), 'Foreign guild relayed');
check(!OocAutoRelay::eligible(true, true, false, 'player', false), 'Bot or webhook relayed (echo loop)');
check(!OocAutoRelay::eligible(true, false, true, 'player', false), 'Command relayed twice');
check(!OocAutoRelay::eligible(true, false, false, null, false), 'Unverified member relayed');
check(!OocAutoRelay::eligible(true, false, false, '', false), 'Empty ckey relayed');
check(!OocAutoRelay::eligible(true, false, false, 'player', true), 'Banished member relayed');

// Formatting.
check(OocAutoRelay::format('  hello   world ') === 'hello world', 'Whitespace not collapsed');
check(OocAutoRelay::format("line one\nline two\r\nthree") === 'line one line two three', 'Newlines not flattened');
check(OocAutoRelay::format("tab\there\x00nul") === 'tab here nul', 'Control characters kept');
check(OocAutoRelay::format('a:::b') === 'a: : :b', 'IPC separator kept');
check(OocAutoRelay::format('hi <@123> and <@!456>', ['123' => 'Val', '456' => 'Taislin']) === 'hi @Val and @Taislin', 'User mentions not resolved');
check(OocAutoRelay::format('<@789>') === '@unknown-user', 'Unknown mention not neutralised');
check(OocAutoRelay::format('ping <@&42>', [], ['42' => 'Admin']) === 'ping @Admin', 'Role mention not resolved');
check(OocAutoRelay::format('see <#77>', [], [], ['77' => 'rules']) === 'see #rules', 'Channel mention not resolved');
check(OocAutoRelay::format('nice <:pog:1234> <a:dance:5678>') === 'nice :pog: :dance:', 'Custom emoji not shortened');
check(OocAutoRelay::format('0') === '0', 'Message "0" dropped');
check(OocAutoRelay::format('') === null && OocAutoRelay::format(" \n ") === null, 'Empty message relayed');
check(OocAutoRelay::format('', [], [], [], 1) === '[attachment]', 'Attachment-only message dropped');
check(OocAutoRelay::format('look', [], [], [], 3) === 'look [3 attachments]', 'Attachment count missing');
$long = OocAutoRelay::format(str_repeat('é', 1000));
check(mb_strlen($long) === OocAutoRelay::MAX_LENGTH && str_ends_with($long, '…') && mb_check_encoding($long, 'UTF-8'), 'Long message not cut on a character boundary');
check(OocAutoRelay::format('héllo wörld 日本') === 'héllo wörld 日本', 'Unicode text changed');

// Badwords use the same rules as in-game OOC moderation.
$rules = [
    ['word' => 'badword', 'method' => 'exact', 'category' => 'toxic'],
    ['word' => 'discord.gg', 'method' => 'str_contains', 'category' => 'advertisement'],
];
check(OocAutoRelay::blocked('you BADWORD', $rules), 'Exact badword not blocked');
check(OocAutoRelay::blocked('join discord.gg/xyz', $rules), 'Advertisement not blocked');
check(!OocAutoRelay::blocked('badwords are fine as a substring', $rules), 'Exact rule matched a substring');
check(!OocAutoRelay::blocked('anything', []), 'Empty rule list blocked');
check(OocAutoRelay::blocked('no method word', [['word' => 'word', 'category' => 'x']]), 'Missing method did not fall back to str_contains');
check(OocAutoRelay::blocked('odd method word', [['word' => 'word', 'method' => 'nonsense', 'category' => 'x']]), 'Unknown method did not fall back to str_contains');

// Every formatted line is a valid discord2ooc IPC record the game splits into exactly two fields.
$dir = sys_get_temp_dir().'/civilizationbot-ooc-'.bin2hex(random_bytes(6)); mkdir($dir, 0700);
try {
    $path = $dir.'/discord2ooc.txt';
    foreach (["multi\nline:::attack", 'a:::b:::c', str_repeat('x', 5000), "x\r\n\x00y"] as $input) {
        check(Ipc::append($path, ['player', OocAutoRelay::format($input)]), 'Formatted text rejected by IPC');
    }
    $lines = array_filter(explode("\n", file_get_contents($path)));
    check(count($lines) === 4, 'IPC record split across lines');
    foreach ($lines as $line) { check(count(explode(':::', $line)) === 2, 'IPC record has extra fields'); }
} finally {
    @unlink($dir.'/discord2ooc.txt'); @rmdir($dir);
}
// Listener wiring: channel routing, skip rules and the IPC write, with injected Discord parts.
final class RelayLookup { public function __construct(private array $items) {} public function get(string $by, $id) { return $this->items[$id] ?? null; } public function has($id): bool { return in_array($id, $this->items, true); } }
final class RelayMessage extends \Discord\Parts\Channel\Message {
    public array $reactions = []; public array $fixture = [];
    public static function with(array $fixture): self { $message = (new ReflectionClass(self::class))->newInstanceWithoutConstructor(); $message->fixture = $fixture; return $message; }
    public function __get(string $key): mixed { return $this->fixture[$key] ?? null; }
    public function __isset(string $key): bool { return isset($this->fixture[$key]); }
    public function react($emoticon): PromiseInterface { $this->reactions[] = $emoticon; return resolve(null); }
}
final class RelayUser extends \Discord\Parts\User\User {
    public array $fixture = [];
    public static function with(string $id, bool $bot = false): self { $user = (new ReflectionClass(self::class))->newInstanceWithoutConstructor(); $user->fixture = ['id' => $id, 'bot' => $bot, 'username' => "user$id"]; return $user; }
    public function __get(string $key): mixed { return $this->fixture[$key] ?? null; }
    public function __isset(string $key): bool { return isset($this->fixture[$key]); }
}
final class RelayCiv extends \Civ13\Civ13 {
    public array $bans = [];
    public function filterMessage(\Discord\Parts\Channel\Message $message): array { $called = str_starts_with($message->content, '!'); return ['message_content' => '', 'message_content_lower' => '', 'called' => $called]; }
    public function bancheck(string $ckey, bool $bypass = false, bool $use_cache = false): bool { return in_array($ckey, $this->bans, true); }
}
final class RelayVerifier extends \Civ13\Verifier {
    public function getVerifiedItem(\Discord\Parts\User\Member|\Discord\Parts\User\User|array|string $input): ?array { return ['10' => ['ss13' => 'valckey'], '11' => ['ss13' => 'bannedckey'], '12' => ['ss13' => 'banishedckey']][$input->id] ?? null; }
}
final class RelayServer extends \Civ13\GameServer {
    public array $sent = []; public bool $fail = false;
    public function OOCMessage(string $message, string $sender): bool { if ($this->fail) { return false; } $this->sent[] = [$sender, $message]; return true; }
}
$civ = (new ReflectionClass(RelayCiv::class))->newInstanceWithoutConstructor();
$civ->owner_id = 'owner'; $civ->role_ids = ['Banished' => 'banished-role']; $civ->bans = ['bannedckey'];
$civ->ooc_badwords = [['word' => 'badword', 'method' => 'exact', 'category' => 'toxic']];
$civ->verifier = (new ReflectionClass(RelayVerifier::class))->newInstanceWithoutConstructor();
$nomads = (new ReflectionClass(RelayServer::class))->newInstanceWithoutConstructor(); $nomads->ooc = 'ooc-nomads';
$tdm = (new ReflectionClass(RelayServer::class))->newInstanceWithoutConstructor(); $tdm->ooc = 'ooc-tdm';
// A secondary shard region (the TDM battle world) shares the Barracks' OOC channel and is listed first on purpose.
$battle = (new ReflectionClass(RelayServer::class))->newInstanceWithoutConstructor(); $battle->ooc = 'ooc-tdm'; $battle->shardOf = 'tdm';
$civ->enabled_gameservers = ['nomads' => $nomads, 'tdmbattle' => $battle, 'tdm' => $tdm];
$manager = (new ReflectionClass(\Civ13\MessageServiceManager::class))->newInstanceWithoutConstructor(); $manager->civ13 = $civ;
$guild = (object) ['owner_id' => 'owner', 'members' => new RelayLookup(['20' => (object) ['nick' => 'Taislin']]), 'channels' => new RelayLookup(['77' => (object) ['name' => 'rules']])];
$foreign = (object) ['owner_id' => 'someone-else', 'members' => new RelayLookup([]), 'channels' => new RelayLookup([])];
$message = static function (array $over = []) use ($guild): RelayMessage {
    $author = $over['author'] ?? RelayUser::with('10', false);
    return RelayMessage::with($over + ['channel_id' => 'ooc-nomads', 'guild' => $guild, 'author' => $author, 'member' => (object) ['roles' => new RelayLookup([])], 'webhook_id' => null, 'content' => 'hello', 'mentions' => [], 'mention_roles' => [], 'attachments' => [], 'sticker_items' => []]);
};
$manager->relayOocMessage($message(['content' => "hi <@20>\nsee <#77>", 'mentions' => [RelayUser::with('20')]]));
check($nomads->sent[0] === ['valckey', 'hi @Taislin see #rules'] && $tdm->sent === [], 'Plain #ooc message not relayed to its own server');
$manager->relayOocMessage($message(['content' => '0']));
check(end($nomads->sent) === ['valckey', '0'], 'Listener dropped a message of "0"');
$manager->relayOocMessage($message(['channel_id' => 'ooc-tdm', 'content' => 'tdm chat']));
check($tdm->sent === [['valckey', 'tdm chat']], 'Second server channel not routed');
check($battle->sent === [], 'Discord OOC reached a secondary shard region instead of its parent');
$nomads->sent = [];
foreach ([
    'other channel' => ['channel_id' => 'general'],
    'foreign guild' => ['guild' => $foreign],
    'bot author' => ['author' => RelayUser::with('10', true)],
    'webhook echo' => ['webhook_id' => 'hook'],
    'command' => ['content' => '!ooc hello'],
    'unverified' => ['author' => RelayUser::with('99', false)],
    'banned ckey' => ['author' => RelayUser::with('11', false)],
    'banished role' => ['author' => RelayUser::with('12', false), 'member' => (object) ['roles' => new RelayLookup(['banished-role'])]],
    'badword' => ['content' => 'you badword'],
    'empty' => ['content' => '   '],
] as $case => $over) {
    $relayed = $message($over); $manager->relayOocMessage($relayed);
    check($nomads->sent === [] && $relayed->reactions === [], "Relayed or reacted to: $case");
}
$nomads->fail = true; $failed = $message(); $manager->relayOocMessage($failed);
check($failed->reactions === ['🔥'], 'Failed IPC write not reported');

echo "OOC auto-relay checks passed\n";
