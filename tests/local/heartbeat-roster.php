<?php

declare(strict_types=1);

// No Composer, Discord, game or bot bootstrap: group-wide player roster only.
require __DIR__.'/../../src/Civ13/ServerHeartbeat.php';
require __DIR__.'/../../src/Civ13/PlayercountChannel.php';
require __DIR__.'/../../src/Civ13/RegionRoster.php';
require __DIR__.'/../../src/Civ13/PlayerEmbedKeys.php';

use Civ13\PlayercountChannel;
use Civ13\RegionRoster;

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

$up = fn (string $name, string ...$players): array => ['name' => $name, 'state' => 'advancing', 'reason' => 'Heartbeat advanced between recent observations', 'players' => $players];
$stale = fn (string $name, string ...$players): array => ['name' => $name, 'state' => 'stale', 'reason' => 'No heartbeat progress observed for at least 120 seconds', 'players' => $players];

// Every running region adds its players, grouped in world order.
$roster = new RegionRoster();
$worlds = $roster->observe([$up('North America'), $up('Gaia', 'alice', 'bob'), $up('Europe', 'carol'), $stale('Asia', 'dave')], 0.0);
check($roster->regions() === ['Gaia' => ['alice', 'bob'], 'Europe' => ['carol']], 'Regions were not grouped in world order');
check($roster->total() === 3 && PlayercountChannel::label($worlds) === '3', 'A stale region must not add its last players');
[$title, $value] = $roster->field(true);
check($title === 'Online Players (3)' && $value === "**Gaia** (2): alice, bob\n**Europe** (1): carol", 'Grouped field text changed: '.$value);

// A traveller listed by both worlds counts once, under the world it moved to.
$roster->observe([$up('North America', 'alice'), $up('Gaia', 'alice', 'bob'), $up('Europe', 'carol')], 15.0);
check($roster->regions() === ['North America' => ['alice'], 'Gaia' => ['bob'], 'Europe' => ['carol']] && $roster->total() === 3, 'A traveller still listed by the old region was double counted or left behind');

// Between worlds (listed by neither) the traveller stays counted, then appears at the destination.
$worlds = $roster->observe([$up('North America'), $up('Gaia', 'bob'), $up('Europe', 'carol')], 30.0);
check($roster->regions()[RegionRoster::TRANSIT] === ['alice'] && $roster->total() === 3, 'A player between regions was lost');
check(PlayercountChannel::label($worlds) === '3', 'The channel count dropped a player between regions');
check(str_contains($roster->field(true)[1], '*Between regions* (1): alice'), 'Travellers were not shown between regions');
$roster->observe([$up('North America'), $up('Gaia', 'bob'), $up('Europe', 'carol', 'alice')], 45.0);
check($roster->regions() === ['Gaia' => ['bob'], 'Europe' => ['alice', 'carol']] || $roster->regions() === ['Gaia' => ['bob'], 'Europe' => ['carol', 'alice']], 'The traveller did not arrive');

// Someone who logged out drops off once the short grace window passes.
$roster->observe([$up('Gaia'), $up('Europe', 'carol')], 60.0);
check(isset($roster->regions()[RegionRoster::TRANSIT]), 'A fresh disappearance should still be in the grace window');
$roster->observe([$up('Gaia'), $up('Europe', 'carol')], 60.0 + RegionRoster::GRACE_SECONDS);
check($roster->regions() === ['Europe' => ['carol']], 'A logged-out player lingered past the grace window');

// Every world down: nobody listed, nobody in transit, offline label.
$worlds = $roster->observe([$stale('Gaia', 'carol'), $stale('Europe')], 200.0);
check($roster->total() === 0 && PlayercountChannel::label($worlds) === 'offline', 'Down group still listed players');
check($roster->field(true) === ['Online Players (0)', 'None'], 'Empty field changed');
$roster->observe([$up('Gaia')], 205.0);
check($roster->regions() === [], 'Old sightings resurfaced after the group came back');

// A single world keeps its plain list; a name full of markdown is escaped.
$single = new RegionRoster();
$single->observe([$up('Persistence', 'a', 'b')], 0.0);
check($single->field(false) === ['Online Players (2)', 'a, b'], 'Single world field changed');
$single->observe([$up('**Evil_|Name**', 'a', 'b')], 1.0);
check($single->field(true)[1] === '**EvilName** (2): a, b', 'Region names were not escaped');

// Too many names for one field: per-region counts instead of a cut-off list.
$many = new RegionRoster();
$names = array_map(fn (int $i): string => 'player'.str_pad((string) $i, 20, '0', STR_PAD_LEFT), range(1, 80));
$many->observe([$up('Gaia', ...array_slice($names, 0, 40)), $up('Europe', ...array_slice($names, 40))], 0.0);
check($many->field(true) === ['Online Players (80)', "**Gaia** (40)\n**Europe** (40)"], 'Oversized roster was not reduced to counts');

// Guest sessions contribute to operational headcounts but never to an embed field.
$guests = new RegionRoster();
$worlds = $guests->observe([$up('Gaia', 'guest3830181121', 'guestbuilder'), $up('Europe', 'Guest-W42')], 0.0);
check($guests->field(true) === ['Online Players (1)', '**Gaia** (1): guestbuilder'], 'Group guest keys leaked or inflated the embed count');
check(PlayercountChannel::label($worlds) === '3' && $guests->total() === 3, 'Guest filtering changed operational counts');
$guests->observe([$up('Gaia', 'Guest-123')], 5.0); // Previous real player still in transit.
check($guests->field(true) === ['Online Players (1)', '*Between regions* (1): guestbuilder'], 'Guest keys leaked from the transit roster');
$guests->observe([$up('Gaia', 'Guest-123')], 45.0);
check($guests->field(false) === ['Online Players (0)', 'None'], 'A guests-only field was not empty');

echo "Region roster contracts passed\n";
