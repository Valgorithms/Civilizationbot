<?php
declare(strict_types=1);
// Bypass tests/bootstrap.php, which reads ambient .env.
foreach (['Profile', 'VerificationReplica', 'ReadOnlyEndpoint', 'ExportEndpoint'] as $class) { require __DIR__.'/../../src/Civ13/Local/'.$class.'.php'; }
use Civ13\Local\Profile;
use Civ13\Local\VerificationReplica;
use Civ13\Local\ReadOnlyEndpoint;
use Civ13\Local\ExportEndpoint;
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function rejects(callable $call): void { try { $call(); } catch (Throwable) { return; } throw new RuntimeException('Expected rejection'); }
function process(array $arguments): array {
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Offline worker unavailable'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); return [proc_close($process), $output, $errors];
}
$root = sys_get_temp_dir().'/civilizationbot-local-'.bin2hex(random_bytes(10)); mkdir($root, 0700);
foreach (['state', 'private', 'nomads', 'tdm'] as $dir) { mkdir($root.'/'.$dir, 0700); }
$pair = sodium_crypto_sign_seed_keypair(str_repeat('s', SODIUM_CRYPTO_SIGN_SEEDBYTES)); $public = sodium_crypto_sign_publickey($pair); $secret = sodium_crypto_sign_secretkey($pair);
$now = 1000; $clock = static function () use (&$now): int { return $now; };
$records = [['ss13' => 'testckey', 'discord' => '123']];
$wire = VerificationReplica::export($records, 'official-test', 1, 1000, 1600, $secret);
$next = VerificationReplica::export([['ss13' => 'newckey', 'discord' => '456']], 'official-test', 2, 1000, 1600, $secret);
try {
    $replica = new VerificationReplica($root.'/state', $public, 'official-test', $clock);
    $endpoint = new ReadOnlyEndpoint($replica, str_repeat('r', 32));
    check($endpoint->handle('GET', '/v1/verification-replica', 'Bearer '.str_repeat('r', 32))[0] === 503, 'Missing replica authorized');
    $replica->sync(static fn () => $wire); $published = file_get_contents($root.'/state/snapshot.json');
    check($replica->snapshot()['records'] === $records, 'Official observations changed');
    $replica->import($wire); check(file_get_contents($root.'/state/snapshot.json') === $published, 'Identical replay changed bytes');
    $sameRevision = VerificationReplica::export([], 'official-test', 1, 1000, 1600, $secret); rejects(fn () => $replica->import($sameRevision));
    $body = json_decode(base64_decode(json_decode($wire, true)['body']), true); $pretty = json_encode($body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $resignedDifferentBytes = json_encode(['body' => base64_encode($pretty), 'signature' => base64_encode(sodium_crypto_sign_detached(VerificationReplica::PROTOCOL."\n".$pretty, $secret))]);
    rejects(fn () => $replica->import($resignedDifferentBytes));
    $tampered = json_decode($wire, true); $tampered['body'] = base64_encode(str_replace('testckey', 'evilckey', base64_decode($tampered['body']))); rejects(fn () => $replica->import(json_encode($tampered)));
    $wrong = sodium_crypto_sign_keypair(); rejects(fn () => $replica->import(VerificationReplica::export($records, 'official-test', 2, 1000, 1600, sodium_crypto_sign_secretkey($wrong))));
    rejects(fn () => $replica->import(VerificationReplica::export($records, 'foreign', 2, 1000, 1600, $secret)));
    rejects(fn () => $replica->import(VerificationReplica::export($records, 'official-test', 2, 1006, 1600, $secret)));
    rejects(fn () => $replica->import(VerificationReplica::export([$records[0], $records[0]], 'official-test', 2, 1000, 1600, $secret)));
    foreach (['POST', 'DELETE', 'PUT', 'PATCH'] as $method) { check($endpoint->handle($method, '/v1/verification-replica', 'Bearer '.str_repeat('r', 32))[0] === 405, 'Replica mutation route reached'); }
    check($endpoint->handle('GET', '/v1/verification-replica', 'bad')[0] === 401, 'Unauthorized read accepted');
    check($endpoint->handle('GET', '/restart', 'Bearer '.str_repeat('r', 32))[0] === 404, 'Official control reachable');
    $read = $endpoint->handle('GET', '/v1/verification-replica', 'Bearer '.str_repeat('r', 32)); check($read[0] === 200 && $read[1]['authoritative'] === false, 'Replica represented as authority');
    $now = 1600; check($endpoint->handle('GET', '/v1/verification-replica', 'Bearer '.str_repeat('r', 32))[0] === 503, 'Stale replica accepted'); rejects(fn () => $replica->import($wire)); $now = 1000;
    $calls = 0; $source = static function () use (&$calls, $records): array { $calls++; return ['records' => $records, 'revision' => 1, 'issued_at' => 1000, 'expires_at' => 1600]; };
    $export = new ExportEndpoint($source, 'official-test', $secret, str_repeat('e', 32), $clock);
    check($export->handle('GET', 'Bearer '.str_repeat('e', 32))[0] === 503 && $calls === 0, 'Disabled export read authority');
    $export = new ExportEndpoint($source, 'official-test', $secret, str_repeat('e', 32), $clock, true);
    check($export->handle('GET', 'bad')[0] === 401 && $export->handle('POST', 'Bearer '.str_repeat('e', 32))[0] === 405 && $calls === 0, 'Unauthorized/mutation export touched source');
    $exported = $export->handle('GET', 'Bearer '.str_repeat('e', 32)); check($exported[0] === 200 && json_encode($exported[1]) === $wire, 'Export adapter bytes differ');
    $now = 1001; check($export->handle('GET', 'Bearer '.str_repeat('e', 32))[1] === $exported[1], 'Read refreshed immutable export'); $now = 1000;
    file_put_contents($root.'/input.json', $next);
    [$code] = process([PHP_BINARY, __DIR__.'/replica-worker.php', $root.'/state', $root.'/input.json', base64_encode($public), 'crash']);
    check($code === 17 && file_get_contents($root.'/state/snapshot.json') === $published, 'Crash published incomplete replica');
    [$code, $output, $errors] = process([PHP_BINARY, __DIR__.'/replica-worker.php', $root.'/state', $root.'/input.json', base64_encode($public), 'commit']);
    check($code === 0 && $errors === '' && $replica->snapshot()['revision'] === 2, 'Fresh-process recovery failed'); rejects(fn () => $replica->import($wire));
    $lock = fopen($root.'/state/snapshot.lock', 'c+b'); flock($lock, LOCK_EX); [$code] = process([PHP_BINARY, __DIR__.'/replica-worker.php', $root.'/state', $root.'/input.json', base64_encode($public), 'commit']); check($code !== 0, 'Concurrent writer ignored lock'); flock($lock, LOCK_UN); fclose($lock);
    $good = file_get_contents($root.'/state/snapshot.json'); file_put_contents($root.'/state/snapshot.json', '{cut'); rejects(fn () => $replica->import($next)); check(file_get_contents($root.'/state/snapshot.json') === '{cut', 'Corruption silently reset rollback barrier'); file_put_contents($root.'/state/snapshot.json', $good);
    file_put_contents($root.'/private/token', base64_encode('111').'.synthetic.token'); file_put_contents($root.'/private/read', str_repeat('r', 32)); file_put_contents($root.'/private/public', base64_encode($public));
    $profile = ['enabled' => false, 'guild_id' => '468979034571931648', 'application_id' => '111', 'official_application_id' => '222', 'allowed_channel_ids' => ['333'], 'pc_root' => $root, 'state_directory' => $root.'/state', 'token_file' => $root.'/private/token', 'public_key_file' => $root.'/private/public', 'read_key_file' => $root.'/private/read', 'authority' => 'official-test', 'bind' => '127.0.0.1', 'port' => 18130, 'servers' => ['nomads' => ['directory' => $root.'/nomads', 'host' => '127.0.0.1', 'port' => 18131], 'tdm' => ['directory' => $root.'/tdm', 'host' => '127.0.0.1', 'port' => 18132]]];
    file_put_contents($root.'/profile.json', json_encode($profile)); $loaded = Profile::load($root.'/profile.json'); check(Profile::token($loaded) === base64_encode('111').'.synthetic.token', 'Separate token not validated');
    check(Profile::accepts($loaded, '468979034571931648', '333') && !Profile::accepts($loaded, '468979034571931648', '444') && !Profile::accepts($loaded, 'other', '333'), 'Official/nonlocal destination allowed');
    foreach (['bind' => '0.0.0.0', 'application_id' => '222', 'state_directory' => dirname($root), 'allowed_channel_ids' => []] as $key => $value) { $bad = $profile; $bad[$key] = $value; file_put_contents($root.'/bad.json', json_encode($bad)); rejects(fn () => Profile::load($root.'/bad.json')); }
    $bad = $profile; $bad['servers']['nomads']['host'] = 'civ13.com'; file_put_contents($root.'/bad.json', json_encode($bad)); rejects(fn () => Profile::load($root.'/bad.json'));
    file_put_contents($root.'/private/token', base64_encode('222').'.synthetic.token'); rejects(fn () => Profile::token($loaded));
    [$code, $output, $errors] = process([PHP_BINARY, __DIR__.'/../../local-bot.php', '--check', $root.'/profile.json']); check($code === 0 && $errors === '' && str_contains($output, 'No connections'), 'Offline bootstrap connected or failed');
    [$code] = process([PHP_BINARY, __DIR__.'/../../local-bot.php', '--run', $root.'/profile.json']); check($code !== 0, 'Disabled local bootstrap ran');
    if (PHP_OS_FAMILY !== 'Windows') {
        symlink($root.'/state', $root.'/alias'); rejects(fn () => new VerificationReplica($root.'/alias', $public, 'official-test', $clock)); unlink($root.'/alias');
        unlink($root.'/state/snapshot.json'); symlink($root.'/input.json', $root.'/state/snapshot.json'); rejects(fn () => $replica->snapshot()); rejects(fn () => $replica->import($next)); unlink($root.'/state/snapshot.json');
    }
    echo "PASS exact-byte Ed25519 auth, replay/equivocation/rollback/staleness, disabled read-only export, crash/restart/lock, local profile/destination/token isolation\n";
} finally {
    foreach (['state', 'private', 'nomads', 'tdm'] as $dir) { foreach (glob($root.'/'.$dir.'/*') ?: [] as $file) { unlink($file); } rmdir($root.'/'.$dir); }
    foreach (glob($root.'/*') ?: [] as $file) { unlink($file); } rmdir($root);
}
