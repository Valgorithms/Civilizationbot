<?php
declare(strict_types=1);
// No bootstrap, .env, sockets, Discord client, or real log files.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13', true);
$loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14', true);
use Civ13\Civ13;
use Discord\Helpers\Collection;
use Discord\Helpers\CollectionInterface;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });
function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function inert(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
function row(string $ckey, string $ip, string $cid = ''): array { return compact('ckey', 'ip', 'cid') + ['date' => '2026-01-01']; }
final class FixtureVerifier extends \Civ13\Verifier {
    public function getVerifiedItem(Member|User|array|string $input): ?array { return null; }
    public function get(string $discrim, $key): mixed { return null; }
}
final class FixtureEmbed extends Embed {
    public array $captured = [];
    public function setTitle(string $title): self { $this->attributes['title'] = $title; return $this; }
    public function addFieldValues(string $name, string $value, ?bool $inline = null): self {
        $this->captured[] = compact('name', 'value', 'inline');
        $this->attributes['fields'] = $this->captured;
        return $this;
    }
}
final class FixtureBot extends Civ13 {
    public array $players = [];
    public array $bans = [];
    public array $regionLookups = [];
    public Embed $resultEmbed;
    public function playerlogsToCollection(&$log_collection = new Collection([], 'increment'), int &$increment = 0): CollectionInterface { return new Collection($this->players, null); }
    public function bansToCollection($log_collection = new Collection([], 'increment'), int $increment = 0): CollectionInterface { return new Collection($this->bans, null); }
    public function bancheck(string $ckey, bool $bypass = false, bool $use_cache = false): bool { return in_array($ckey, array_column($this->bans, 'ckey'), true); }
    public function altbancheck(array $ckeys, ?string $exclude = null): bool { return false; }
    public function getByondAge(string $ckey): string|false { return false; }
    public function getIpData(string $ip): array { $this->regionLookups[] = $ip; return ['countryCode' => 'fixture']; }
    public function hasRank(Member $member, array $allowed_ranks = ['Owner', 'Chief Technical Officer', 'Ambassador']): bool { return true; }
    public function createEmbed(?bool $footer = true, int $color = 0xE1452D): Embed { return $this->resultEmbed = inert(FixtureEmbed::class); }
}
final class FixtureMessage extends Message {
    public int $replies = 0;
    public function __get(string $name) { if ($name === 'member') { return inert(Member::class); } throw new RuntimeException('Unexpected message read: '.$name); }
    public function reply($message): PromiseInterface { $this->replies++; return resolve(null); }
}
$bot = inert(FixtureBot::class);
$bot->verifier = inert(FixtureVerifier::class);
$bot->permitted = [];
check(str_replace('\\', '/', (new ReflectionClass(Civ13::class))->getFileName()) === str_replace('\\', '/', realpath(__DIR__.'/../../src/Civ13/Civ13.php')), 'Test loaded another checkout');
check(str_replace('\\', '/', (new ReflectionClass(\Civ13\MessageCommand\Commands\CkeyInfo::class))->getFileName()) === str_replace('\\', '/', realpath(__DIR__.'/../../src/Civ13/MessageCommand/Commands/CkeyInfo.php')), 'Test loaded command from another checkout');
$loopbacks = ['127.0.0.1', '127.12.34.56', '127.255.255.255', '::1', '0:0:0:0:0:0:0:1', '::ffff:127.0.0.1', '::ffff:7f01:0203', '0:0:0:0:0:ffff:7fff:ffff'];
foreach (array_merge($loopbacks, ['', 'HOST', 'not-an-ip']) as $ip) {
    check(!Civ13::isCkeyinfoIp($ip), 'Unsafe IP allowed: '.$ip);
    $bot->players = [row('target', $ip), row('unrelated', $ip, 'othercid')];
    $bot->bans = [row('bannedstranger', $ip, 'bannedcid')];
    check(count($bot->getCkeyLogCollections('target')['bans']) === 0, 'Initial ban seeding linked excluded IP: '.$ip);
    $result = $bot->ckeyinfo('target');
    check($result['ckeys'] === ['target'] && $result['ips'] === [] && $result['cids'] === [] && !$result['altbanned'], 'API false identity link: '.$ip);
    $message = inert(FixtureMessage::class);
    $bot->regionLookups = [];
    (new \Civ13\MessageCommand\Commands\CkeyInfo($bot))($message, 'ckeyinfo', ['message_content_lower' => 'ckeyinfo target']);
    $fields = $bot->resultEmbed->captured;
    $rendered = json_encode($fields, JSON_THROW_ON_ERROR);
    check($message->replies === 1 && !str_contains($rendered, 'unrelated') && !str_contains($rendered, 'bannedstranger'), 'Command false identity link: '.$ip);
    check($bot->regionLookups === [], 'Command retained excluded IP: '.$ip);
}
foreach (['203.0.113.5', '10.2.3.4', '192.168.1.2', '172.16.1.2', '::ffff:192.168.1.2', '2001:db8::1', 'fc00::1', '126.255.255.255', '128.0.0.1'] as $ip) {
    check(Civ13::isCkeyinfoIp($ip), 'Legitimate IP excluded: '.$ip);
    $bot->players = [row('target', $ip, 'sharedcid'), row('iplinked', $ip, 'newcid'), row('cidlinked', '127.0.0.1', 'sharedcid'), row('stranger', '127.0.0.1', 'strangercid'), row('transitive', '10.5.6.7', 'newcid')];
    $bot->bans = [row('bannedlinked', $ip, 'bancid')];
    check(count($bot->getCkeyLogCollections('target')['bans']) === 1, 'Legitimate ban seed missing');
    $result = $bot->ckeyinfo('target');
    foreach (['target', 'iplinked', 'cidlinked', 'transitive', 'bannedlinked'] as $expected) { check(in_array($expected, $result['ckeys'], true), 'API legitimate link lost: '.$expected); }
    check(!in_array('stranger', $result['ckeys'], true) && !in_array('127.0.0.1', $result['ips'], true) && $result['altbanned'], 'API transitive loopback leak');
    $message = inert(FixtureMessage::class);
    (new \Civ13\MessageCommand\Commands\CkeyInfo($bot))($message, 'ckeyinfo', ['message_content_lower' => 'ckeyinfo target']);
    $rendered = json_encode($bot->resultEmbed->captured, JSON_THROW_ON_ERROR);
    foreach (['iplinked', 'cidlinked', 'transitive'] as $expected) { check(str_contains($rendered, $expected), 'Command legitimate link lost: '.$expected); }
    check(!str_contains($rendered, 'stranger') && !str_contains($rendered, '127.0.0.1'), 'Command transitive loopback leak');
}
// Ban-only transitive expansion must not introduce a loopback bridge.
$bot->players = [row('target', '10.1.2.3', 'seedcid'), row('stranger', '::1', 'strangercid')];
$bot->bans = [row('firstban', '10.1.2.3', 'nextcid'), row('secondban', '::1', 'nextcid'), row('unrelatedban', '::1', 'othercid')];
$result = $bot->ckeyinfo('target');
check(in_array('secondban', $result['ckeys'], true) && !in_array('stranger', $result['ckeys'], true) && !in_array('unrelatedban', $result['ckeys'], true) && !in_array('::1', $result['ips'], true), 'API transitive ban loopback leak');
$ckeys = ['target']; $ips = ['10.1.2.3' => '10.1.2.3']; $cids = []; $dates = []; $fc = []; $fi = []; $fci = []; $fd = [];
Civ13::updateCkeyinfoVariables(new ArrayIterator($bot->bans), $ckeys, $ips, $cids, $dates, $fc, $fi, $fci, $fd, false);
check(isset($cids['nextcid']) && !isset($cids['othercid']) && !in_array('::1', $ips, true), 'Command helper transitive ban loopback leak');
// Direct ckey matches must work even with no usable IP/CID. Blank CIDs cannot join rows.
$bot->players = [row('target', '127.0.0.1'), row('target', '10.9.8.7'), row('stranger', '192.168.9.8')];
$bot->bans = [row('target', '::1')];
check(count($bot->getCkeyLogCollections('target')['bans']) === 1, 'Direct ckey ban discarded');
check($bot->ckeyinfo('target')['ckeys'] === ['target'], 'Blank CID linked unrelated player');
$ckeys = ['target']; $ips = ['::1' => '::1']; $cids = []; $dates = []; $fc = []; $fi = []; $fci = []; $fd = [];
Civ13::updateCkeyinfoVariables(new ArrayIterator($bot->players), $ckeys, $ips, $cids, $dates, $fc, $fi, $fci, $fd);
check(in_array('10.9.8.7', $ips, true) && !in_array('::1', $ips, true) && !in_array('stranger', $ckeys, true), 'Command helper lost direct ckey match or retained unsafe seed');
echo "PASS ckeyinfo: API, actual command, ban seeds, loopback variants, valid CID/IP/ckey links, transitive links, blank sentinels\n";
