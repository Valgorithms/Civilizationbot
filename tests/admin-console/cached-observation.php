<?php

declare(strict_types=1);

// No dependencies, file status reads, wall-clock reads, network, bot or game.
require __DIR__.'/../../src/Civ13/AdminConsole/AdminServerProjection.php';
require __DIR__.'/../../src/Civ13/AdminConsole/CachedObservationAdapter.php';

use Civ13\AdminConsole\AdminServerProjection;
use Civ13\AdminConsole\CachedObservationAdapter;

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks; ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
}
function stamp(int $unix): string { return gmdate('Y-m-d\TH:i:s\Z', $unix); }
$wall = 1793156400;
$target = ['id' => 'fixture', 'provider' => 'civ13.com'];
$now = 110.0;
$entry = ['id' => 'fixture', 'host' => 'civ13.com', 'online' => true, 'engine' => 'BYOND',
    'checked_at' => stamp($wall - 10), 'players' => 0, 'map' => 'Gaia', 'mode' => 'Nomads',
    'round' => ['id' => '', 'duration_seconds' => 0], 'token' => 'directory-secret'];
$directory = ['clock_scope' => 'clock-1', 'captured_at' => 100.0, 'wall_time' => $wall,
    'snapshot' => ['schema' => 1, 'generated_at' => stamp($wall),
        'expires_at' => stamp($wall + 50), 'servers' => [$entry]]];
$heartbeat = ['server_id' => 'fixture', 'clock_scope' => 'clock-1', 'sampled_at' => 100.0,
    'observation' => ['state' => 'advancing', 'age' => 20.0, 'reason' => 'private-path', 'data' => 'ckeys-and-secrets']];
$adapter = new CachedObservationAdapter('clock-1');
$sample = $adapter->normalize($target, $directory, $heartbeat, $now);
$projection = new AdminServerProjection(static fn (): bool => true,
    static function () use (&$sample): array { return $sample; },
    static function () use (&$now): float { return $now; }, 'clock-1');
$view = $projection->project('reader', 'fixture');
check($view['state'] === 'online' && $view['metrics']['players'] === 0, 'Zero player online row');
check($view['metrics']['round_duration_seconds'] === 0, 'Zero round time');
check($view['ready'] === null && $view['evidence']['readiness']['state'] === 'unknown', 'Neither source establishes readiness');
check($view['age_seconds'] === 20.0, 'Row checked_at, not generated_at, determines age');
check($view['evidence']['reachability']['state'] === 'reachable', 'Explicit reachable evidence');
check($view['evidence']['heartbeat']['age_seconds'] === 30.0, 'Heartbeat age refers to last progress');
check($view['evidence']['heartbeat']['state'] === 'advancing', 'Independent heartbeat');
check($view['metrics']['generation'] === null && $view['metrics']['save_load_phase'] === null, 'Unavailable lifecycle telemetry not invented');
check(!str_contains(json_encode($sample), 'secret') && !str_contains(json_encode($sample), 'private-path'), 'No raw secret/reason/data copied');
check($view['capabilities']['restart'] === false, 'Still read-only');

$now = 150.0;
$view = $projection->project('reader', 'fixture');
check($view['state'] === 'stale' && $view['metrics']['players'] === null, 'Directory exact expiry');
check($view['evidence']['reachability']['state'] === 'stale', 'Reachability expires independently');
check($view['evidence']['heartbeat']['state'] === 'advancing', 'Directory expiry does not discard fresh heartbeat');
$now = 200.0;
check($projection->project('reader', 'fixture')['evidence']['heartbeat']['state'] === 'stale', 'Progress expires without any new sample');
$sample = $adapter->normalize($target, $directory, $heartbeat, $now);
check($projection->project('reader', 'fixture')['state'] === 'stale', 'Renormalization cannot renew expired row');
$now = 110.0;
$missing = $directory; $missing['snapshot']['servers'] = [];
$sample = $adapter->normalize($target, $missing, $heartbeat, $now);
$view = $projection->project('reader', 'fixture');
check($view['state'] === 'unknown', 'Missing directory row is not offline/stopped');
check($view['evidence']['reachability']['state'] === 'unknown' && $view['evidence']['heartbeat']['state'] === 'advancing', 'Heartbeat alone does not imply reachability');
$sample = $adapter->normalize($target, null, null, $now);
check($projection->project('reader', 'fixture')['state'] === 'unknown', 'All missing remains unknown');
$now = 1000.0;
check($projection->project('reader', 'fixture')['state'] === 'unknown', 'Never-observed does not become a fabricated stale sample');
$now = 110.0;

$staleHeartbeat = $heartbeat; $staleHeartbeat['observation']['state'] = 'stale';
$sample = $adapter->normalize($target, $directory, $staleHeartbeat, $now);
$view = $projection->project('reader', 'fixture');
check($view['state'] === 'online' && $view['evidence']['heartbeat']['state'] === 'stale', 'Reachable Topic and stale heartbeat remain distinct');
foreach ([['server_id', 'other'], ['clock_scope', 'old'], ['sampled_at', 111.0]] as [$key, $value]) {
    $bad = $heartbeat; $bad[$key] = $value;
    $sample = $adapter->normalize($target, $directory, $bad, $now);
    check($projection->project('reader', 'fixture')['evidence']['heartbeat']['state'] === 'unknown', 'Reject heartbeat binding/time '.$key);
}
foreach ([-1.0, 120.0, NAN, '20'] as $age) {
    $bad = $heartbeat; $bad['observation']['age'] = $age;
    $sample = $adapter->normalize($target, $directory, $bad, $now);
    check($projection->project('reader', 'fixture')['evidence']['heartbeat']['state'] === 'unknown', 'Reject invalid heartbeat progress age');
}
foreach (['other', 'old-scope', 'future-capture', 'duplicate', 'wrong-provider', 'future-generated', 'future-checked', 'invalid-date', 'long-expiry', 'wrong-schema', 'false-online'] as $case) {
    $bad = $directory;
    switch ($case) {
        case 'other': $bad['snapshot']['servers'][0]['id'] = 'other'; break;
        case 'old-scope': $bad['clock_scope'] = 'old'; break;
        case 'future-capture': $bad['captured_at'] = 111.0; break;
        case 'duplicate': $bad['snapshot']['servers'][] = $entry; break;
        case 'wrong-provider': $bad['snapshot']['servers'][0]['host'] = 'valzargaming.com'; break;
        case 'future-generated': $bad['snapshot']['generated_at'] = stamp($wall + 1); break;
        case 'future-checked': $bad['snapshot']['servers'][0]['checked_at'] = stamp($wall + 1); break;
        case 'invalid-date': $bad['snapshot']['servers'][0]['checked_at'] = "2026-02-30T00:00:00Z\0"; break;
        case 'long-expiry': $bad['snapshot']['expires_at'] = stamp($wall + 51); break;
        case 'wrong-schema': $bad['snapshot']['schema'] = 2; break;
        case 'false-online': $bad['snapshot']['servers'][0]['online'] = false; break;
    }
    $sample = $adapter->normalize($target, $bad, null, $now);
    check($projection->project('reader', 'fixture')['state'] === 'unknown', 'Directory invalid case '.$case);
}
$sample = $adapter->normalize($target, $directory, $heartbeat, $now);
$now = 95.0;
$view = $projection->project('reader', 'fixture');
check($view['state'] === 'unknown' && $view['evidence']['heartbeat']['state'] === 'unknown', 'Clock rollback before capture rejects old-looking progress');
$now = 89.0;
$view = $projection->project('reader', 'fixture');
check($view['state'] === 'unknown' && $view['evidence']['reachability']['state'] === 'unknown', 'Clock rollback rejects future row');
$now = 110.0;
$sample['evidence']['readiness'] = ['state' => 'ready', 'observed_at' => 100, 'expires_at' => 120, 'secret' => 'never'];
check($projection->project('reader', 'fixture')['evidence']['readiness']['state'] === 'unknown', 'No readiness passthrough');
$sample['evidence']['heartbeat']['token'] = 'never';
check(!str_contains(json_encode($projection->project('reader', 'fixture')), 'never'), 'Evidence allowlist');
// Match the established producer Unicode policy without treating private-use as control.
$privateUse = $directory;
$privateUse['snapshot']['servers'][0]['map'] = "Gaia \u{E000}";
$sample = $adapter->normalize($target, $privateUse, null, $now);
$view = $projection->project('reader', 'fixture');
check($view['state'] === 'online' && $view['metrics']['map'] === "Gaia \u{E000}", 'Private-use map passes both adapter and projection');
foreach (["bad\nmap", "bad\u{202E}map", "bad\xffmap"] as $badText) {
    $invalidText = $directory;
    $invalidText['snapshot']['servers'][0]['map'] = $badText;
    $sample = $adapter->normalize($target, $invalidText, null, $now);
    check($projection->project('reader', 'fixture')['state'] === 'unknown', 'Adapter rejects control/bidi/invalid UTF-8');
    $sample = $adapter->normalize($target, $directory, null, $now);
    $sample['metrics']['map'] = $badText;
    check($projection->project('reader', 'fixture')['metrics']['map'] === null, 'Projection independently rejects control/bidi/invalid UTF-8');
}

// Existing producer-checked cross-language fixtures, not new adapter-only vectors.
foreach (['server-directory.json', 'server-directory-vzg.json', 'server-directory-unicode.json'] as $filename) {
    $existing = json_decode(file_get_contents(__DIR__.'/../fixtures/'.$filename), true, 16, JSON_THROW_ON_ERROR);
    $row = $existing['servers'][0];
    $registryTarget = ['id' => $row['id'], 'provider' => $row['host']];
    $now = 100.0;
    $capture = ['clock_scope' => 'clock-1', 'captured_at' => $now,
        'wall_time' => strtotime($existing['generated_at']), 'snapshot' => $existing];
    $sample = $adapter->normalize($registryTarget, $capture, null, $now);
    $view = $projection->project('reader', $row['id']);
    check($view['state'] === 'online' && $view['metrics']['players'] === 0, 'Existing producer fixture admitted: '.$filename);
    check($view['metrics']['map'] === $row['map'] && $view['metrics']['round_id'] === $row['round']['id'], 'Existing producer metadata retained');
    check($sample['expires_at'] - $sample['observed_at'] === 60.0, 'Exact producer 60-second lifetime accepted');
    $now = 159.0;
    check($projection->project('reader', $row['id'])['state'] === 'online', 'Producer fixture remains fresh at 59 seconds');
    $now = 160.0;
    check($projection->project('reader', $row['id'])['state'] === 'stale', 'Producer fixture expires exactly at 60 seconds');
}
echo "Cached observation contracts passed ($checks checks)\n";
