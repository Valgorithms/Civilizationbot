<?php

declare(strict_types=1);

// Dependency-free: no Composer, credentials, files, sockets, game or bot startup.
require __DIR__.'/../../src/Civ13/AdminConsole/AdminServerProjection.php';

use Civ13\AdminConsole\AdminServerProjection;

$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (! $condition) { throw new RuntimeException($message); }
}

$now = 20.0;
$allowed = true;
$reads = 0;
$fixture = ['server_id' => 'fixture', 'clock_scope' => 'collector-1',
    'observed_at' => 10.0, 'expires_at' => 40.0, 'state' => 'online',
    'metrics' => ['players' => 0, 'map' => 'Gaia', 'mode' => 'Nomads',
        'round_duration_seconds' => 0, 'generation' => 'round-v2:fixture']];
$sample = $fixture;
$projection = new AdminServerProjection(
    static function (string $actor, string $target) use (&$allowed): bool {
        return $allowed && $actor === 'reader' && $target === 'fixture';
    },
    static function (string $target) use (&$sample, &$reads): ?array { ++$reads; return $sample; },
    static function () use (&$now): float { return $now; },
    'collector-1',
);
$view = $projection->project('reader', 'fixture');
check($view['state'] === 'online' && $view['metrics']['players'] === 0, 'Zero players remains online');
check($view['ready'] === null, 'Connectivity alone cannot establish readiness');
check($view['metrics']['round_duration_seconds'] === 0, 'Zero duration is valid');
check($view['capabilities']['restart'] === false && $view['capabilities']['save'] === false
    && $view['capabilities']['verbs'] === false, 'No controls become available');
check(! array_key_exists('cpu', $view['metrics']), 'Unsupported metrics are not invented');

$allowed = false;
$before = $reads;
check($projection->project('reader', 'fixture') === ['schema' => 'civ13.admin-status.v1', 'access' => 'denied'], 'Deny without data');
check($reads === $before, 'Authorization precedes source read');
$allowed = true;
check($projection->project('other', 'fixture')['access'] === 'denied', 'Actor binding');
check($projection->project('reader', 'other')['access'] === 'denied', 'Target authorization');

foreach (['unknown', 'stale', 'offline', 'busy', 'recovery_hold'] as $state) {
    $sample = $fixture;
    $sample['state'] = $state;
    $sample['ready'] = true;
    $view = $projection->project('reader', 'fixture');
    check($view['state'] === $state, 'Preserve explicit source state '.$state);
    if (in_array($state, ['busy', 'recovery_hold'], true)) {
        check($view['ready'] === false, 'Busy/hold cannot be ready');
    } else {
        check($view['metrics']['players'] === null && $view['ready'] === null, 'Suppress unverified current metrics');
    }
}
$sample = $fixture;
$now = 39.0;
check($projection->project('reader', 'fixture')['age_seconds'] === 29.0, 'Observation age');
$now = 40.0;
$view = $projection->project('reader', 'fixture');
check($view['state'] === 'stale' && $view['metrics']['players'] === null, 'Exact expiry cannot be refreshed by rendering');
$now = 20.0;
foreach ([['server_id', 'another'], ['clock_scope', 'old-collector'], ['observed_at', 21.0],
    ['observed_at', NAN], ['expires_at', INF], ['expires_at', 10.0], ['expires_at', 131.0],
    ['state', 'invented']] as [$key, $value]) {
    $sample = $fixture;
    $sample[$key] = $value;
    check($projection->project('reader', 'fixture')['state'] === 'unknown', 'Reject invalid identity/time/state: '.$key);
}
$sample = null;
check($projection->project('reader', 'fixture')['state'] === 'unknown', 'Missing is not offline');
$sample = $fixture;
$sample['metrics'] = ['players' => '0', 'map' => "bad\nmap", 'mode' => "\xff", 'round_id' => str_repeat('x', 81),
    'round_duration_seconds' => -1, 'save_load_phase' => '99%', 'token' => 'never-project', 'cpu' => 99];
$view = $projection->project('reader', 'fixture');
check(count(array_filter($view['metrics'], static fn ($v) => $v !== null)) === 0, 'Invalid and unknown metrics suppressed');
check(! str_contains(json_encode($view, JSON_THROW_ON_ERROR), 'never-project'), 'No raw payload leakage');
$sample = $fixture;
$sample['ready'] = true;
$sample['capabilities'] = ['save' => true, 'restart' => true, 'verbs' => true];
$sample['metrics']['save_load_phase'] = 'idle';
check($projection->project('reader', 'fixture')['capabilities']['save'] === false, 'Source cannot grant capabilities');
check($projection->project('reader', 'fixture')['ready'] === true, 'Explicit fresh readiness retained for display only');

$denied = new AdminServerProjection(static function (): bool { throw new RuntimeException('private'); },
    static function (): array { throw new RuntimeException('must not read'); }, static fn (): float => 20.0, 'scope');
check($denied->project('reader', 'fixture')['access'] === 'denied', 'Authorization failure denies');
$missing = new AdminServerProjection(static fn (): bool => true,
    static function (): array { throw new RuntimeException('private'); }, static fn (): float => 20.0, 'scope');
check($missing->project('reader', 'fixture')['state'] === 'unknown', 'Source failure hides details');
$clockFailure = new AdminServerProjection(static fn (): bool => true, static fn (): array => $fixture,
    static function (): float { throw new RuntimeException('private'); }, 'collector-1');
check($clockFailure->project('reader', 'fixture')['state'] === 'unknown', 'Clock failure is unknown');
echo "Admin console projection offline contracts passed ($checks checks)\n";
