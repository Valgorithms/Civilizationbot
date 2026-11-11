<?php
declare(strict_types=1);
// No bootstrap, .env, sockets, Discord client or outbound lookups. BYOND lookups are replaced by the stub below.
namespace Byond {
    /** Offline stand-in for the profile lookup, declared before the autoloader can load the networked one. */
    final class Byond {
        public static array $valid = [];
        public static function isValidCkey(string $ckey, string $page = ''): bool { return in_array($ckey, self::$valid, true); }
        public static function bansearch_centcom(string $ckey, bool $prettyprint = true): string|false { return '[]'; }
    }
}
namespace {
    $loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
    $loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);
    $loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14', true);
    use Civ13\Civ13;
    use Civ13\GameServer;
    use Civ13\MessageCommand\Commands;
    use Discord\Parts\Channel\Message;
    use Discord\Parts\Embed\Embed;
    use Discord\Parts\Guild\Guild;
    use Discord\Parts\Thread\Thread;
    use Discord\Parts\User\Member;
    use Discord\Parts\User\User;
    use React\Promise\PromiseInterface;
    use function React\Promise\resolve;
    // Deprecations raised while loading third-party packages are not this test's subject.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool { if (! (error_reporting() & $severity)) { return true; } throw new ErrorException($message, 0, $severity, $file, $line); }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
    function inert(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
    final class CommandLookup implements IteratorAggregate {
        public function __construct(public array $items) {}
        public function get(string $key, mixed $value): mixed { return $this->items[$value] ?? null; }
        public function has(mixed $id): bool { return isset($this->items[$id]); }
        public function getIterator(): Iterator { return new ArrayIterator($this->items); }
    }
    final class CommandGuild extends Guild {
        public array $fixture = [];
        public function __get(string $key) { return $this->fixture[$key] ?? null; }
    }
    final class CommandMember extends Member {
        public array $fixture = [];
        public function __get(string $key) { return $this->fixture[$key] ?? null; }
    }
    final class CommandEmbed extends Embed {
        public array $captured = [];
        public function setTitle(string $title): self { return $this; }
        public function addFieldValues(string $name, string $value, ?bool $inline = null): self { $this->captured[$name] = $value; return $this; }
    }
    final class CommandMessage extends Message {
        public array $fixture = [];
        public array $reactions = [];
        public function __get(string $name) { return $this->fixture[$name] ?? null; }
        public function react($emoticon): PromiseInterface { $this->reactions[] = $emoticon; return resolve(null); }
    }
    final class CommandVerifier extends \Civ13\Verifier {
        public array $items = [];
        public array $joined = [];
        public function getVerifiedItem(Member|User|array|string $input): ?array { return is_string($input) ? ($this->items[$input] ?? null) : ($input instanceof User ? ($this->items['author'] ?? null) : null); }
        public function getVerified(bool $initialize = true): \Discord\Helpers\CollectionInterface { return $this->verified; }
        public function joinRoles(Member $member, bool $send_welcome_message = true): ?PromiseInterface { $this->joined[] = $member->id; return null; }
    }
    final class CommandBot extends Civ13 {
        public array $replies = [];
        public array $bans = [];
        public array $saved = [];
        public function reply(Message|Thread $message, string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): PromiseInterface { $this->replies[] = $content; return resolve(null); }
        public function ban(array $array, ?string $admin = null, string|int|null $server = null, bool $permanent = false): string { $this->bans[] = [$array, $admin]; return 'banned'; }
        public function VarSave(string $filename, array $assoc_array = []): bool { $this->saved[] = $filename; return true; }
        public function createEmbed(?bool $footer = true, int $color = 0xE1452D): Embed { return inert(CommandEmbed::class); }
    }
    function message(string $content, array $extra = []): CommandMessage {
        $message = inert(CommandMessage::class);
        $message->fixture = $extra + ['author' => inert(User::class), 'content' => $content];

        return $message;
    }
    function run(object $command, string $name, string $content, array $extra = []): CommandMessage {
        $message = message($content, $extra);
        $command($message, $name, ['message_content' => $content, 'message_content_lower' => strtolower($content), 'called' => true]);

        return $message;
    }
    check(str_replace('\\', '/', (new ReflectionClass(Commands\Ban::class))->getFileName()) === str_replace('\\', '/', realpath(__DIR__.'/../../src/Civ13/MessageCommand/Commands/Ban.php')), 'Test loaded another checkout');

    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'civbot-commands-'.bin2hex(random_bytes(6));
    check(mkdir($root.'/SQL', 0700, true) && mkdir($root.'/logs/2026', 0700, true), 'Unable to create temporary storage');
    try {
        $bot = inert(CommandBot::class);
        $bot->logger = new Monolog\Logger('offline-commands', [new Monolog\Handler\NullHandler()]);
        $bot->verifier = $verifier = inert(CommandVerifier::class);
        $verifier->verified = new Discord\Helpers\Collection([], 'discord');
        $verifier->provisional = new Discord\Helpers\Collection([], 'discord');
        $bot->role_ids = ['Verified' => 'verified-role', 'SS14 Verified' => 'ss14-role'];
        $bot->ages = ['knownplayer' => '2020-01-01'];
        $server = inert(GameServer::class);
        (new ReflectionProperty(GameServer::class, 'basedir'))->setValue($server, $root);

        // ban: the ckey is normalised, the duration and reason reach the game exactly as the admin typed them.
        $verifier->items = ['author' => ['ss13' => 'adminckey', 'discord' => '1']];
        run(new Commands\Ban($bot), 'ban', 'ban Known_Player; 3 Days; Griefing the Base; again');
        check($bot->bans === [[['ckey' => 'knownplayer', 'duration' => '3 Days', 'reason' => "Griefing the Base; again Appeal at {$bot->discord_formatted}"], 'adminckey']], 'Ban arguments were altered: '.json_encode($bot->bans));
        $verifier->items = [];
        $bot->bans = [];
        run(new Commands\Ban($bot), 'ban', 'ban knownplayer;1 hour;Short');
        check($bot->bans === [[['ckey' => 'knownplayer', 'duration' => '1 hour', 'reason' => "Short Appeal at {$bot->discord_formatted}"], null]], 'Ban by an unverified admin failed: '.json_encode($bot->bans));
        foreach (['ban' => 'Missing ban ckey', 'ban knownplayer' => 'Missing ban duration', 'ban knownplayer; 1 day' => 'Missing ban reason', 'ban ghostplayer; 1 day; x' => 'does not exist'] as $content => $expected) {
            $bot->replies = [];
            run(new Commands\Ban($bot), 'ban', $content);
            check(count($bot->bans) === 1 && str_contains($bot->replies[0] ?? '', $expected), "Incomplete ban was not refused: $content");
        }

        // permit: a ckey that is neither known locally nor a real BYOND account is refused; real or known ones are permitted.
        \Byond\Byond::$valid = ['realplayer'];
        $bot->replies = [];
        run(new Commands\Permit($bot), 'permit', 'permit ghostplayer');
        check($bot->permitted === [] && str_contains($bot->replies[0], 'does not exist'), 'Nonexistent ckey was permitted');
        run(new Commands\Permit($bot), 'permit', 'permit RealPlayer');
        run(new Commands\Permit($bot), 'permit', 'permit knownplayer');
        check(array_keys($bot->permitted) === ['realplayer', 'knownplayer'] && $bot->saved === ['permitted.json', 'permitted.json'], 'Valid ckeys were not permitted');
        $bot->replies = [];
        run(new Commands\Permit($bot), 'permit', 'permit');
        check(str_contains($bot->replies[0], '`permit [ckey]`'), 'Permit help names another command');
        // bancheck_centcom names the ckey it searched for.
        $bot->replies = [];
        run(new Commands\BanCheckCentcom($bot), 'bancheck_centcom', 'bancheck_centcom realplayer');
        check(str_contains($bot->replies[0], '`realplayer`') && ! str_contains($bot->replies[0], '`ckey`'), 'Centcom reply did not name the ckey');

        // Medals: rows are matched on the ckey column, formatted, and given their own emoji.
        file_put_contents($root.Civ13::awards, implode(PHP_EOL, ['medalist;Sgt Example;Iron Cross 1st Class;x;1943-07-05;for valour', 'medalistother;Pvt Other;Assault Badge;x;1943-07-06;elsewhere', 'broken;row', 'medalist;Sgt Example;Unknown Ribbon;x;1943-07-07;misc', '']));
        $guild = inert(CommandGuild::class);
        $guild->fixture = ['emojis' => new CommandLookup(['iron_cross1' => '<:iron_cross1:1>'])];
        check(class_exists(Commands\Civ13GameServerMedals::class), 'Medals command missing'); // Also loads the medal enum declared beside it
        foreach (Commands\SS13Medal::cases() as $case) {
            check($case->emoji() !== '' && Commands\SS13Medal::fromName($case->value) === $case, 'Medal has no emoji name: '.$case->name);
        }
        $medals = Commands\Civ13GameServerMedals::medals($guild, $root.Civ13::awards, 'medalist');
        check($medals === '`Sgt Example:` <:iron_cross1:1> **Iron Cross 1st Class**, *1943-07-05*, for valour'.PHP_EOL.'`Sgt Example:` <:long_service:705786458874707978> **Unknown Ribbon**, *1943-07-07*, misc'.PHP_EOL, 'Medal list was wrong: '.$medals);
        check(Commands\Civ13GameServerMedals::medals($guild, $root.Civ13::awards, 'nobody') === 'No medals found for `nobody`.' && str_starts_with(Commands\Civ13GameServerMedals::medals($guild, $root.'/missing', 'x'), 'Error opening'), 'Medal edge cases were wrong');
        // With no ckey given, a verified member sees their own medals; an unverified one is told how to ask.
        $verifier->items = ['author' => ['ss13' => 'medalist', 'discord' => '1']];
        $bot->replies = [];
        run(new Commands\Civ13GameServerMedals($bot, $server), 'civ13medals', 'civ13medals', ['guild' => $guild]);
        check(str_contains($bot->replies[0] ?? '', 'Iron Cross 1st Class'), 'Verified member did not get their own medals: '.json_encode($bot->replies));
        $verifier->items = [];
        $bot->replies = [];
        run(new Commands\Civ13GameServerMedals($bot, $server), 'civ13medals', 'civ13medals', ['guild' => $guild]);
        check(str_contains($bot->replies[0] ?? '', '/approveme'), 'Unverified member was not told how to ask');
        file_put_contents($root.Civ13::awards_br, implode(PHP_EOL, ['medalist;Sgt Example;2;TDM;1943-07-05;40', 'medalistother;Pvt Other;1;TDM;1943-07-06;40', 'short;row', '']));
        check(Commands\Civ13GameServerBRMedals::brmedals($root.Civ13::awards_br, 'medalist') === '**Sgt Example:** placed *2 of 40,* on 1943-07-05 (TDM)'.PHP_EOL, 'Battle royale placements were not listed');
        check(Commands\Civ13GameServerBRMedals::brmedals($root.Civ13::awards_br, 'nobody') === 'No medals found for `nobody`.', 'Battle royale edge case was wrong');
        $bot->replies = [];
        run(new Commands\Civ13GameServerBRMedals($bot, $server), 'civ13brmedals', 'civ13brmedals medalist');
        check(str_contains($bot->replies[0] ?? '', 'placed *2 of 40,*'), 'Battle royale command did not read its own server');

        // joinroles only touches members with neither verified role, and never bots.
        $member = static function (string $id, array $roles, bool $bot = false): CommandMember { $m = inert(CommandMember::class); $m->fixture = ['id' => $id, 'user' => (object) ['bot' => $bot], 'roles' => new CommandLookup(array_fill_keys($roles, true))]; return $m; };
        $guild->fixture['members'] = new CommandLookup(['a' => $member('plain', []), 'b' => $member('verified', ['verified-role']), 'c' => $member('ss14', ['ss14-role']), 'd' => $member('both', ['verified-role', 'ss14-role']), 'e' => $member('bot', [], true)]);
        $discord = new class extends \Discord\Discord { public ?Guild $guild = null; public function __construct() {} public function __get(string $name) { return $name === 'guilds' ? new CommandLookup(['guild-1' => $this->guild]) : null; } public function __destruct() {} };
        $discord->guild = $guild;
        $bot->discord = $discord;
        $bot->civ13_guild_id = 'guild-1';
        $reacted = run(new Commands\JoinRoles($bot), 'joinroles', 'joinroles');
        check($verifier->joined === ['plain'] && $reacted->reactions === ['👍'], 'Join roles reached the wrong members: '.json_encode($verifier->joined));

        // getround tolerates a round that was only ever recorded as interrupted, and offers a log button only when a log is known.
        $command = new Commands\GetRound($bot);
        $embed = (new ReflectionMethod($command, 'createEmbed'))->invoke($command, 'Fixture', ['interrupted' => true], true, 'someone');
        check($embed->captured['Players (0)'] === 'Either none or too many to list!' && ! isset($embed->captured['Player Data (someone)']), 'Round without players was not handled');
        $embed = inert(CommandEmbed::class);
        $builder = Civ13::createBuilder(true);
        (new ReflectionMethod($command, 'addStaffDetails'))->invokeArgs($command, [&$embed, &$builder, ['server_key' => 'fixture']]);
        check($embed->captured === ['Bot Logging Interrupted' => 'No', 'Log Command' => 'Unknown'] && $builder->getComponents() === [], 'Round without a log still offered a log button');

        // Log navigation lists real entries only and can never step out of the log directory.
        file_put_contents($root.'/logs/-sorts-before-dots.txt', 'x');
        file_put_contents($root.'/logs/2026/round.log', 'round');
        check($bot->FileNav($root.'/logs', []) === [false, ['-sorts-before-dots.txt', '2026']], 'Directory listing included dot entries: '.json_encode($bot->FileNav($root.'/logs', [])));
        foreach (['..', '.', '../SQL'] as $escape) {
            $result = $bot->FileNav($root.'/logs', [$escape, 'SQL', 'awards.txt']);
            check($result[0] === false && ($result[2] ?? null) === $escape, 'Navigation left the log directory with: '.$escape);
        }
        check($bot->FileNav($root.'/logs', ['2026', 'round.log']) === [true, $root.'/logs/2026/round.log'], 'Log file was not found');
        check($bot->FileNav($root.'/missing', ['x']) === [false, [], 'x'], 'Missing directory was not handled');
        echo "PASS commands: ban arguments, permit validation, centcom reply, medals, battle royale placements, join roles, round details, log navigation\n";
    } finally {
        foreach (['/SQL/awards.txt', '/SQL/awards_br.txt', '/logs/2026/round.log', '/logs/-sorts-before-dots.txt'] as $file) { @unlink($root.$file); }
        foreach (['/logs/2026', '/logs', '/SQL', ''] as $dir) { @rmdir($root.$dir); }
    }
}
