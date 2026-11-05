<?php

declare(strict_types=1);

// No Composer, Discord, game or bot bootstrap: player-count channel naming only.
require __DIR__.'/../../src/Civ13/ServerHeartbeat.php';
require __DIR__.'/../../src/Civ13/PlayercountChannel.php';

use Civ13\PlayercountChannel;
use Civ13\ServerHeartbeat;

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

$up = fn (string ...$players): array => ['state' => 'advancing', 'reason' => 'Heartbeat advanced between recent observations', 'players' => $players];
$stale = ['state' => 'stale', 'reason' => 'No heartbeat progress observed for at least 120 seconds', 'players' => []];
$missing = ['state' => 'unknown', 'reason' => ServerHeartbeat::MISSING, 'players' => []];
$waiting = ['state' => 'unknown', 'reason' => 'Waiting to observe heartbeat progress', 'players' => []];

check(PlayercountChannel::label([]) === null, 'An unobserved (remote or disabled) server must keep its name');
check(PlayercountChannel::label([$stale]) === 'offline', 'A stale heartbeat must read offline, not unknown');
check(PlayercountChannel::label([$missing]) === 'offline', 'A missing serverdata.txt must read offline');
check(PlayercountChannel::label([$waiting]) === null, 'A baseline read must not rename the channel');
check(PlayercountChannel::label([$up()]) === '0', 'A running empty world must read 0');
check(PlayercountChannel::label([$up('a', 'b')]) === '2', 'A running world must read its player count');
check(PlayercountChannel::label([$up('a'), $up('b', 'c'), $stale]) === '3', 'Shard regions must add up');
check(PlayercountChannel::label([$up('a'), $up('a', 'b')]) === '2', 'A ckey seen in two regions mid-travel counts once');
check(PlayercountChannel::label([$stale, $up('b')]) === '1', 'A down main world must not hide players in a running region');
check(PlayercountChannel::label([$stale, $missing]) === 'offline', 'Every world down must read offline');
check(PlayercountChannel::label([$stale, $waiting]) === null, 'A region still being baselined must not flip the name to offline');

check(PlayercountChannel::rename('pers-unknown', 'offline') === 'pers-offline', 'Old unknown names must be replaced');
check(PlayercountChannel::rename('pers-offline', 'offline') === null, 'An unchanged name must not be saved again');
check(PlayercountChannel::rename('pers-offline', '4') === 'pers-4', 'Coming back up must show the count');
check(PlayercountChannel::rename('nomads-eu-12', '13') === 'nomads-eu-13', 'A hyphenated prefix must survive');
check(PlayercountChannel::rename('pers', '2') === 'pers-2', 'A bare prefix gains a count');

echo "Player-count channel contracts passed\n";
