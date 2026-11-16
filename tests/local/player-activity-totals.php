<?php

declare(strict_types=1);

// No Composer, Discord, game or bot bootstrap: the weekly player activity totals file only.
require __DIR__.'/../../src/Civ13/PlayerActivityTotals.php';

use Civ13\PlayerActivityTotals;

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir().'/civ13-activity-'.bin2hex(random_bytes(4));
mkdir($root);
$group = $root.'/group.json';
file_put_contents($group, json_encode(['root' => $root, 'regions' => [['id' => 'gaia']]]));

// Nothing written yet, and no group at all.
check(PlayerActivityTotals::read($group, $why) === null && str_contains($why, 'no activity totals yet'), 'A missing totals file was not reported');
check(PlayerActivityTotals::read('', $why) === null && $why === 'this server has no region group', 'A server without a group was not reported');
file_put_contents($root.'/'.PlayerActivityTotals::FILE, '{not json');
check(PlayerActivityTotals::read($group) === null, 'A broken totals file was read');

// Totals only: unexpected fields (names, ids) are dropped and bad values become zero.
file_put_contents($root.'/'.PlayerActivityTotals::FILE, json_encode([
    'generated' => '2026-11-16 00:15 UTC',
    'active' => ['day' => 3, 'week' => 9, 'month' => 20],
    'new_this_week' => 2, 'sessions_this_week' => 14, 'session_minutes_median' => 37.25,
    'deaths_this_week' => -5, 'trips_this_week' => 'many',
    'hours_by_region' => [['region' => 'gaia', 'hours' => 12.5], ['region' => '<@everyone>', 'hours' => 1], ['region' => 'europe', 'hours' => 2]],
    'churn' => [['account' => 'alice']],
]));
$totals = PlayerActivityTotals::read($group);
check(is_array($totals) && ! isset($totals['churn']), 'Unexpected fields were kept');
check($totals['deaths_this_week'] === 0 && $totals['trips_this_week'] === 0, 'Bad numbers were kept');
check($totals['hours_by_region'] === ['gaia' => 12.5, 'europe' => 2.0], 'Region names were not filtered');
$text = PlayerActivityTotals::describe($totals);
check(str_contains($text, 'Active players: 3 today, 9 this week, 20 this month. New this week: 2.'), 'Totals line changed: '.$text);
check(str_contains($text, 'median 37.3 minutes') && str_contains($text, 'Gaia 12.5 h, Europe 2 h'), 'Session or hours line changed: '.$text);
check(! str_contains($text, 'alice') && ! str_contains($text, '@'), 'A player name or mention reached the message');

// An oversized file is refused.
file_put_contents($root.'/'.PlayerActivityTotals::FILE, str_repeat(' ', 70000).'{}');
check(PlayerActivityTotals::read($group) === null, 'An oversized totals file was read');

array_map('unlink', glob($root.'/*'));
rmdir($root);
echo "PASS player activity totals\n";
