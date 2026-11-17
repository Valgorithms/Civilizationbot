<?php

declare(strict_types=1);

// No Composer, .env, sockets, Discord, game or bot bootstrap.
require __DIR__.'/../../src/Civ13/ServerHeartbeat.php';

use Civ13\ServerHeartbeat;

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

function record(string $realtime, string $players = '', bool $approved = false): string
{
    return '<b>Server Status</b>: Online;<b>Address</b>: byond://164.132.145.5:1715;'
        .'<b>Map</b>: fixture;<b>Gamemode</b>: fixture;<b>Players</b>: '.($players === '' ? 0 : 1).';'
        .($approved ? '<b>Approved only</b>: Enabled;' : '')
        .'realtime='.$realtime.';world.address=164.132.145.5;round_timer=00:00;'
        .'map=fixture;epoch=fixture;season=fixture;ckey_list='.$players.';allow_vote_restart=0;';
}

$now = 0.0;
$payload = record('100');
$clock = static function () use (&$now): float { return $now; };
$reader = static function () use (&$payload): string|false { return $payload; };
$heartbeat = new ServerHeartbeat('unused', $clock, $reader);
check($heartbeat->poll()['state'] === 'unknown', 'Initial old Online must be unknown');
$now = 15;
$payload = record('200', '', true);
check($heartbeat->poll()['state'] === 'advancing', 'Idle zero-player world with advancing realtime must work');
$now = 30;
$payload = record('200', 'player');
check($heartbeat->poll()['age'] === 15.0, 'Player/content changes must not reset progress');
foreach ([60, 90, 120] as $now) { $heartbeat->poll(); }
$now = 135;
$status = $heartbeat->poll();
check($status['state'] === 'stale' && $status['data'] === null, 'Frozen record must expire during repeated reads');
$now = 150;
$payload = record('201');
check($heartbeat->poll()['state'] === 'advancing', 'Recent advancing heartbeat must recover');
$now = 165;
$payload = record('1');
check($heartbeat->poll()['state'] === 'unknown', 'Backwards writer time must require a new baseline');
$now = 180;
$payload = record('2');
check($heartbeat->poll()['state'] === 'advancing', 'Writer restart must recover after new progress');
$now = 179;
check($heartbeat->poll()['state'] === 'unknown', 'Backwards observer clock must invalidate confirmation');
$now = 194;
$payload = record('3');
check($heartbeat->poll()['state'] === 'advancing', 'Clock reset must recover');
$now = 400;
$payload = record('4');
check($heartbeat->poll()['state'] === 'unknown', 'A long observation gap cannot claim recent progress');
$now = 415;
$payload = record('5');
check($heartbeat->poll()['state'] === 'advancing', 'Bounded observations restore confirmation after a gap');
check((new ServerHeartbeat('unused', $clock, $reader))->poll()['state'] === 'unknown', 'Bot restart must not inherit confirmation');

foreach ([false, '', 'Online', record('bad'), record('1').';realtime=2;', substr(record('1'), 0, -1), record('1').str_repeat('x', 65537)] as $invalid) {
    $payload = $invalid;
    check($heartbeat->poll()['state'] === 'unknown', 'Invalid/missing data must invalidate old confirmation');
    $payload = record('100');
    check($heartbeat->poll()['state'] === 'unknown', 'First valid read after failure is only a baseline');
    $now += 15;
    $payload = record('101');
    check($heartbeat->poll()['state'] === 'advancing', 'Valid progress must recover from invalid reads');
}
$payload = record('9999999999999999999');
$large = new ServerHeartbeat('unused', $clock, $reader);
$large->poll();
$now += 15;
$payload = record('10000000000000000000');
check($large->poll()['state'] === 'advancing', 'Realtime comparison must not overflow integers');

$root = sys_get_temp_dir().'/bot-heartbeat-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $path = $root.'/serverdata.txt';
    $fileHeartbeat = new ServerHeartbeat($path, $clock);
    check($fileHeartbeat->poll()['state'] === 'unknown' && ! file_exists($path), 'Missing file must not be created');
    file_put_contents($path, record('1'));
    touch($path, 1000000000);
    $before = stat($path);
    $fileHeartbeat->poll();
    $now += 15;
    touch($path); // Simulate an old bot/copy tool touching a frozen game record.
    check($fileHeartbeat->poll()['state'] === 'unknown', 'Mtime changes cannot confer health');
    touch($path, 1000000000);
    $now += 15;
    check($fileHeartbeat->poll()['state'] === 'unknown', 'Repeated reads cannot confer health');
    clearstatcache(true, $path);
    check(stat($path)['mtime'] === $before['mtime'], 'Heartbeat reader must preserve mtime');
    file_put_contents($path, record('2'));
    $now += 15;
    check($fileHeartbeat->poll()['state'] === 'advancing', 'Real file writer progress must confer observation');
    unlink($path);
    check($fileHeartbeat->poll()['state'] === 'unknown', 'Deleted file must invalidate confirmation');
    mkdir($path);
    check($fileHeartbeat->poll()['state'] === 'unknown', 'Directory must be rejected');
    rmdir($path);
} finally {
    if (is_file($root.'/serverdata.txt')) { unlink($root.'/serverdata.txt'); }
    rmdir($root);
}
// The game deletes and rewrites serverdata.txt, so the file can vanish between the existence
// check and the read. The bot's global error handler reports even @-silenced warnings, so the
// read must neither raise a warning nor throw, and a vanished file must count as missing.
final class VanishingHeartbeatStream
{
    public static bool $present = true;
    public static int $opens = 0;
    public mixed $context = null;

    public function url_stat(string $path, int $flags): array|false
    {
        return self::$present ? ['mode' => 0100644, 'size' => 10] + array_fill(0, 13, 0) : false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::$opens++;
        self::$present = false; // Deleted by the game's rewrite after the existence checks passed.
        if ($options & STREAM_REPORT_ERRORS) {
            trigger_error('Failed to open stream: No such file or directory', E_USER_WARNING);
        }

        return false;
    }
}
stream_wrapper_register('vanish', VanishingHeartbeatStream::class);
set_error_handler(static function (int $severity, string $message): never {
    throw new RuntimeException('Heartbeat read leaked a warning to the global handler: '.$message);
});
try {
    $now = 0.0;
    $vanishing = new ServerHeartbeat('vanish://region/serverdata.txt', $clock);
    $status = $vanishing->poll();
    check(VanishingHeartbeatStream::$opens === 1, 'Race fixture must reach the read');
    check($status['state'] === 'unknown' && $status['reason'] === ServerHeartbeat::MALFORMED, 'A file that vanishes mid-read starts the missing window without an error');
    $now = ServerHeartbeat::STALE_SECONDS;
    check($vanishing->poll()['reason'] === ServerHeartbeat::MISSING, 'A file that stays gone reads as missing, never as a fatal error');
} finally {
    restore_error_handler();
    stream_wrapper_unregister('vanish');
}

foreach ([[0, '0s'], [45.9, '45s'], [60, '1m'], [25901, '7h 11m'], [3600, '1h 0m'], [93780, '1d 2h 3m'], [86400, '1d 0h 0m'], [-5, '0s']] as [$seconds, $expected]) {
    check(ServerHeartbeat::formatAge($seconds) === $expected, "Heartbeat age {$seconds}s must read {$expected}");
}
echo "Heartbeat offline contracts passed\n";
