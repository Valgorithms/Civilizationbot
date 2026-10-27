<?php
declare(strict_types=1);
// Explicit existing dependencies; no bot bootstrap, .env, Discord or game process.
$loader = require $argv[1] ?? __DIR__.'/../../vendor/autoload.php';
$loader->addPsr4('Civ13\\', __DIR__.'/../../src/Civ13/', true);
$loader->addPsr4('Civ14\\', __DIR__.'/../../src/Civ14/', true);

use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use React\Promise\PromiseInterface;

function fileCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function fileOutcome(PromiseInterface $promise): string {
    $state = 'pending';
    $promise->then(static function () use (&$state) { $state = 'success'; }, static function (Throwable $e) use (&$state) { $state = 'failure'; });
    fileCheck($state !== 'pending', 'Unexpected pending file operation');
    return $state;
}
final class AdminFileRoles {
    public function __construct(private array $ids) {}
    public function has(string $id): bool { return in_array($id, $this->ids, true); }
}
final class AdminFileMember extends Member {
    public string $fakeId;
    public array $fakeRoles = [];
    public function __get($key): mixed { return match ($key) { 'id' => $this->fakeId, 'roles' => new AdminFileRoles($this->fakeRoles), default => throw new RuntimeException('Unexpected member access') }; }
}
final class AdminFileVerifier extends \Civ13\Verifier {
    public array $members = [];
    public function getVerifiedMember(Member|User|array|string|null $input): ?Member { return is_array($input) ? ($this->members[$input['discord']] ?? null) : null; }
}
final class AdminFileBot extends \Civ13\Civ13 {
    public function hasRequiredConfigRoles(array $roles = []): bool { return array_diff($roles, array_keys($this->role_ids)) === []; }
}
final class AdminFileDiscord extends \Discord\Discord { public function __construct() {} }
final class AdminFileServer extends \Civ13\GameServer {
    // Exercise actual path construction; never register timers or Discord effects.
    protected function afterConstruct(): void {}
}
/** Model the inspected DM load_admins cleanup: literal LF framing, three fields. */
function dmAdminRecords(string $bytes): array {
    $records = [];
    foreach (explode("|||\n", $bytes) as $record) {
        $fields = explode(';', $record);
        if (count($fields) === 3) { $records[$fields[0]] = [$fields[1], $fields[2]]; }
    }
    return $records;
}

$root = str_replace('\\', '/', sys_get_temp_dir()).'/admin-file-contract-'.bin2hex(random_bytes(8));
mkdir($root); mkdir($root.'/game'); mkdir($root.'/game/SQL'); mkdir($root.'/shared'); mkdir($root.'/elsewhere');
$previous = getcwd(); $reader = null;
try {
    $bot = (new ReflectionClass(AdminFileBot::class))->newInstanceWithoutConstructor();
    $bot->logger = new \Monolog\Logger('offline-admin-files', [new \Monolog\Handler\NullHandler()]);
    $bot->loop = new \React\EventLoop\StreamSelectLoop();
    $bot->discord = new AdminFileDiscord();
    $bot->role_ids = ['FixtureOwner' => 'owner-fixture', 'FixtureAdmin' => 'admin-fixture'];
    $verifier = (new ReflectionClass(AdminFileVerifier::class))->newInstanceWithoutConstructor(); $bot->verifier = $verifier;
    $rows = [['discord' => '990000000000000001', 'ss13' => 'fakealpha'], ['discord' => '990000000000000002', 'ss13' => 'fakebeta']];
    foreach ($rows as $i => $row) {
        $member = (new ReflectionClass(AdminFileMember::class))->newInstanceWithoutConstructor();
        $member->fakeId = $row['discord']; $member->fakeRoles = $i === 0 ? ['owner-fixture', 'admin-fixture'] : ['admin-fixture'];
        $verifier->members[$row['discord']] = $member;
    }
    $verifier->verified = new \Discord\Helpers\Collection($rows, 'discord');
    $permissions = ['FixtureOwner' => ['FixtureHost', '7'], 'FixtureAdmin' => ['FixtureAdmin', '3']];
    $make = static function (array $overrides = []) use ($bot, $root): AdminFileServer {
        $options = array_replace(['basedir' => $root.'/game', 'key' => 'fixture', 'name' => 'fixture', 'ip' => '127.0.0.1', 'port' => '1714', 'host' => 'fixture', 'enabled' => false], array_fill_keys(['discussion','playercount','ooc','lobby','asay','ic','transit','adminlog','debug','garbage','runtime','attack'], ''), $overrides);
        return new AdminFileServer($bot, $options);
    };
    $default = $root.'/game/SQL/admins.txt'; $external = $root.'/shared/admins.txt';
    file_put_contents($default, 'default-stays-here'); file_put_contents($external, str_repeat('stale-tail', 80));
    chdir($root.'/elsewhere');
    $server = $make(['admin_file' => $external]); $server->enabled = true;
    $reader = fopen($external, 'rb');
    fileCheck(fileOutcome($server->adminlistUpdate($permissions)) === 'success', 'Explicit file update rejected');
    $expected = "fakealpha;FixtureHost;7|||\nfakebeta;FixtureAdmin;3|||\n";
    fileCheck(file_get_contents($external) === $expected && $server->listadmins() === $expected, 'Selected writer/reader paths or LF bytes differ');
    fileCheck(file_get_contents($default) === 'default-stays-here', 'Override wrote the default file');
    rewind($reader); fileCheck(stream_get_contents($reader) === $expected, 'Replacement lost existing reader/file identity');
    $parsed = ['fakealpha' => ['FixtureHost', '7'], 'fakebeta' => ['FixtureAdmin', '3']];
    fileCheck(dmAdminRecords($expected) === $parsed, 'LF records do not survive DM cleanup model');
    fileCheck(dmAdminRecords(str_replace("\n", "\r\n", $expected)) === [], 'CRLF negative control did not reproduce lost cleanup records');

    $legacy = $make(); $legacy->enabled = true;
    fileCheck(fileOutcome($legacy->adminlistUpdate($permissions)) === 'success' && file_get_contents($default) === $expected && $legacy->listadmins() === $expected, 'Default Debian-style path changed');
    $verifier->members[$rows[0]['discord']]->fakeRoles = [];
    fileCheck(fileOutcome($server->adminlistUpdate($permissions)) === 'success' && $server->listadmins() === "fakebeta;FixtureAdmin;3|||\n", 'Role removal retained entry');
    $verifier->verified = new \Discord\Helpers\Collection([], 'discord');
    fileCheck(fileOutcome($server->adminlistUpdate($permissions)) === 'success' && $server->listadmins() === '', 'Authoritative empty fixture did not revoke/truncate');
    rewind($reader); fileCheck(stream_get_contents($reader) === '', 'Empty revocation lost existing file identity');
    fclose($reader); $reader = null;

    file_put_contents($external, 'preserve-baseline'); $server->enabled = false;
    fileCheck(fileOutcome($server->adminlistUpdate($permissions)) === 'failure' && file_get_contents($external) === 'preserve-baseline', 'Disabled writer changed baseline');
    $server->enabled = true; $roles = $bot->role_ids; $bot->role_ids = [];
    fileCheck(fileOutcome($server->adminlistUpdate($permissions)) === 'failure' && file_get_contents($external) === 'preserve-baseline', 'Incomplete role map changed baseline'); $bot->role_ids = $roles;
    $missing = $root.'/missing/admins.txt'; $bad = $make(['admin_file' => $missing]); $bad->enabled = true;
    fileCheck($bad->listadmins() === false && !file_exists($missing), 'Listing created a missing file');
    fileCheck(fileOutcome($bad->adminlistUpdate($permissions)) === 'failure' && file_get_contents($external) === 'preserve-baseline', 'Missing parent failure lost preservation');
    foreach (['relative/admins.txt','D:admins.txt','https://example.invalid/admins.txt',"D:/admin\0.txt",'',null,17] as $invalid) {
        try { $make(['admin_file' => $invalid]); throw new LogicException('Invalid override accepted'); }
        catch (\Civ13\Exceptions\InvalidConfigException $error) {}
    }
    echo "PASS configured/default admin paths from arbitrary cwd; LF/DM-cleanup regression and CRLF negative control; role/removal/empty; file identity; refused writes preserve baseline; listing has no creation effect\n";
} finally {
    if (is_resource($reader)) { fclose($reader); }
    chdir($previous);
    foreach ([$root.'/game/SQL', $root.'/shared', $root.'/elsewhere', $root.'/game', $root] as $directory) {
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) { continue; }
            fileCheck(!$entry->isLink() && $entry->isFile() && dirname(realpath($entry->getPathname())) === realpath($directory), 'Fixture cleanup boundary refused');
            unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
