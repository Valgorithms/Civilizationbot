<?php

declare(strict_types=1);

// No Composer/bot bootstrap, .env, sockets, HTTP, Discord, or production storage.
foreach (['Ledger', 'Config', 'AuthoritativeAccounts', 'Service'] as $class) {
    require __DIR__.'/../src/Civ13/Economy/'.$class.'.php';
}

use Civ13\Economy\AuthoritativeAccounts;
use Civ13\Economy\Config;
use Civ13\Economy\Ledger;
use Civ13\Economy\Service;

const FIXTURE_DISCORD = '123456789012345678';

function economyCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function economyReject(Closure $action, string $message): void
{
    try {
        $action();
    } catch (RuntimeException | InvalidArgumentException $error) {
        economyCheck($error->getMessage() === $message, 'Unexpected failure: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: '.$message);
}

function fixtureSignature(array $event, string $server = 'fixture-server'): array
{
    return ['server' => $server, 'signature' => hash_hmac('sha256', json_encode($event, JSON_THROW_ON_ERROR), 'offline-only')];
}

function fixtureService(Ledger $ledger, Closure $snapshot, string $policyVersion = 'fixture-v1'): Service
{
    return new Service(
        $ledger,
        new AuthoritativeAccounts($snapshot),
        new Config(true, 'fixture-realm', ['fixture-server'], $policyVersion),
        static function (array $event, mixed $context): ?string {
            return is_array($context) && is_string($context['server'] ?? null) && is_string($context['signature'] ?? null)
                && hash_equals(fixtureSignature($event)['signature'], $context['signature']) ? $context['server'] : null;
        },
        // Synthetic test policy only; no earning/spending rules are shipped.
        static fn (string $outcome, string $version) => match ($outcome) {
            'fixture-credit' => 100,
            'fixture-spend' => -70,
            'fixture-loss' => -1,
            default => null,
        },
    );
}

function fixtureEvent(string $run, string $outcome = 'fixture-credit'): array
{
    return ['version' => 1, 'run' => $run, 'account' => 'fixtureplayer', 'outcome' => $outcome];
}

function fixtureSnapshot(): array
{
    return ['http_status' => 200, 'json' => json_encode([['ss13' => 'fixtureplayer', 'discord' => FIXTURE_DISCORD]], JSON_THROW_ON_ERROR)];
}

if (($argv[1] ?? '') === 'worker') {
    $event = fixtureEvent($argv[3], $argv[4]);
    try {
        fixtureService(new Ledger($argv[2]), fixtureSnapshot(...))->process($event, fixtureSignature($event));
        exit(0);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Insufficient balance.') {
            exit(3);
        }
        throw $error;
    }
}

function simultaneous(string $path, array $runs, string $outcome): array
{
    $workers = [];
    foreach ($runs as $run) {
        $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, 'worker', $path, $run, $outcome], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        economyCheck(is_resource($process), 'Cannot start isolated worker.');
        $workers[] = $process;
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
    }
    $results = array_map('proc_close', $workers);
    sort($results);
    return $results;
}

$path = tempnam(sys_get_temp_dir(), 'civ-economy-authority-');
$legacyPath = tempnam(sys_get_temp_dir(), 'civ-economy-migrate-');
try {
    $ledger = new Ledger($path);
    $calls = 0;
    $disabled = new Service($ledger, new AuthoritativeAccounts(function () use (&$calls) { ++$calls; return fixtureSnapshot(); }));
    economyReject(fn () => $disabled->read(FIXTURE_DISCORD), 'Economy is disabled.');
    economyReject(fn () => $disabled->process(fixtureEvent('disabled'), null), 'Economy is disabled.');
    economyCheck($calls === 0 && $ledger->history('fixture-realm', 'fixtureplayer') === [], 'Disabled service accessed adapters or wrote an entry.');
    economyReject(fn () => new Config(true), 'Enabled economy requires nonempty realm, policy version and server identifiers of at most 128 bytes.');

    $snapshot = fixtureSnapshot();
    $service = fixtureService($ledger, fn () => $snapshot);
    $event = fixtureEvent('run-1');
    economyReject(fn () => $service->process($event, null), 'Authenticated allowlisted game server required.');
    economyReject(fn () => $service->process($event, fixtureSignature($event, 'outsider')), 'Authenticated allowlisted game server required.');
    economyReject(fn () => $service->process(fixtureEvent('tampered'), fixtureSignature($event)), 'Authenticated allowlisted game server required.');
    economyReject(fn () => $service->process($event + ['delta' => 1000], fixtureSignature($event)), 'Invalid server outcome envelope.');
    $unknown = fixtureEvent('unknown', 'unapproved-outcome');
    economyReject(fn () => $service->process($unknown, fixtureSignature($unknown)), 'No approved accounting policy for this outcome.');
    $unverified = $event;
    $unverified['account'] = 'unverifiedplayer';
    economyReject(fn () => $service->process($unverified, fixtureSignature($unverified)), 'An authoritative verified game account is required.');
    economyReject(fn () => $service->read('234567890123456789'), 'An authoritative verified game account is required.');

    $receipt = $service->process($event, fixtureSignature($event));
    economyCheck($receipt === $service->process(array_reverse($event, true), fixtureSignature(array_reverse($event, true))), 'Reordered replay changed receipt.');
    $audit = json_decode($receipt['evidence'], true, 512, JSON_THROW_ON_ERROR);
    economyCheck($audit['server'] === 'fixture-server' && $audit['policy_version'] === 'fixture-v1' && $audit['run'] === 'run-1', 'Atomic receipt missing outcome provenance.');
    $conflict = fixtureEvent('run-1', 'fixture-loss');
    economyReject(fn () => $service->process($conflict, fixtureSignature($conflict)), 'Event ID reused with a different payload.');
    $newPolicy = fixtureService($ledger, fixtureSnapshot(...), 'fixture-v2');
    economyReject(fn () => $newPolicy->process($event, fixtureSignature($event)), 'Event ID reused with a different payload.');
    economyCheck(count($service->read(FIXTURE_DISCORD)['history']) === 1, 'Rejected event persisted.');

    // Fresh snapshots on every request: no cached or provisional fallback.
    $dynamic = fixtureService($ledger, function () use (&$snapshot) { return $snapshot; });
    $snapshot = ['http_status' => 200, 'json' => '[]'];
    economyReject(fn () => $dynamic->read(FIXTURE_DISCORD), 'An authoritative verified game account is required.');
    economyReject(fn () => $dynamic->process(fixtureEvent('revoked'), fixtureSignature(fixtureEvent('revoked'))), 'An authoritative verified game account is required.');
    $snapshot = ['http_status' => 503, 'json' => fixtureSnapshot()['json']];
    economyReject(fn () => $dynamic->read(FIXTURE_DISCORD), 'Authoritative verification is unavailable.');
    $snapshot = ['http_status' => 200, 'json' => json_encode([['ss13' => 'fixtureplayer', 'discord' => FIXTURE_DISCORD], ['ss13' => 'anotherplayer', 'discord' => FIXTURE_DISCORD]])];
    economyReject(fn () => $dynamic->read(FIXTURE_DISCORD), 'Invalid or ambiguous authoritative verification snapshot.');

    unset($disabled, $service, $dynamic, $newPolicy, $ledger);
    gc_collect_cycles();
    economyCheck(simultaneous($path, ['spend-1', 'spend-2'], 'fixture-spend') === [0, 3], 'Competing spends were not serialized.');
    economyCheck(simultaneous($path, ['duplicate-run', 'duplicate-run'], 'fixture-credit') === [0, 0], 'Simultaneous duplicate replay failed.');
    $ledger = new Ledger($path);
    $service = fixtureService($ledger, fixtureSnapshot(...));
    economyCheck($service->read(FIXTURE_DISCORD)['balance'] === 130, 'Replay/concurrent/restart balance incorrect.');
    economyCheck(count($service->read(FIXTURE_DISCORD)['history']) === 3, 'Simultaneous duplicate credited more than once.');

    // Upgrade the original merged ledger without losing history or replay safety.
    $legacy = new PDO('sqlite:'.$legacyPath);
    $legacy->exec('CREATE TABLE economy_entries (id INTEGER PRIMARY KEY, realm TEXT NOT NULL, event_id TEXT NOT NULL, account TEXT NOT NULL, delta INTEGER NOT NULL, balance INTEGER NOT NULL, reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(realm,event_id))');
    $legacy->exec("INSERT INTO economy_entries (realm,event_id,account,delta,balance,reason) VALUES ('old','legacy','fixtureplayer',10,10,'legacy')");
    unset($legacy);
    $migrated = new Ledger($legacyPath);
    economyCheck($migrated->balance('old', 'fixtureplayer') === 10 && $migrated->apply('old', 'legacy', 'fixtureplayer', 10, 'legacy')['evidence'] === '', 'Legacy migration lost data or replay safety.');
    unset($migrated, $service, $ledger);
    gc_collect_cycles();
    echo "Economy authority checks passed: disabled defaults, authenticated server, verified ownership/revocation, approved policy, atomic audit, conflicts, concurrent replay/spend, restart, migration.\n";
} finally {
    unset($disabled, $service, $dynamic, $newPolicy, $ledger, $migrated, $legacy);
    gc_collect_cycles();
    foreach ([$path, $legacyPath] as $database) {
        foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
