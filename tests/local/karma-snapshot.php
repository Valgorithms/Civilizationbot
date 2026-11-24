<?php

declare(strict_types=1);

// No Composer, Discord, game or bot bootstrap: the per-ckey karma snapshot only.
require __DIR__.'/../../src/Civ13/KarmaSnapshot.php';

use Civ13\KarmaSnapshot;

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir().'/civ13-karma-'.bin2hex(random_bytes(4));
mkdir($root.'/'.KarmaSnapshot::DIR, 0777, true);
$group = $root.'/group.json';
file_put_contents($group, json_encode(['root' => $root, 'regions' => [['id' => 'gaia']]]));
$file = $root.'/'.KarmaSnapshot::DIR.'/alice.json';

// Only a DM or the staff bot channel may show karma; with no staff channel configured, only DMs.
check(KarmaSnapshot::allowedPlace(null, '5', '9'), 'A DM was refused');
check(KarmaSnapshot::allowedPlace('1', '9', '9'), 'The staff bot channel was refused');
check(! KarmaSnapshot::allowedPlace('1', '5', '9'), 'A public channel was allowed');
check(! KarmaSnapshot::allowedPlace('1', '5', null) && ! KarmaSnapshot::allowedPlace('1', '', ''), 'A guild channel was allowed without a staff channel');

// Bad ckeys, no group, nothing recorded, broken and mismatched files.
check(KarmaSnapshot::read($group, '../alice', $why) === null && $why === 'that is not a valid ckey', 'A path in the ckey was accepted');
check(KarmaSnapshot::read('', 'alice', $why) === null && $why === 'this server has no region group', 'A server without a group was not reported');
check(KarmaSnapshot::read($group, 'alice', $why) === null && $why === 'no karma recorded for that ckey', 'A missing karma file was not reported');
file_put_contents($file, '{not json');
check(KarmaSnapshot::read($group, 'alice') === null, 'A broken karma file was read');
file_put_contents($file, json_encode(['ckey' => 'bob']));
check(KarmaSnapshot::read($group, 'alice', $why) === null && str_contains($why, 'does not belong'), 'Another ckey\'s karma was shown');

// A full snapshot: bad rows dropped, unknown fields and flags dropped, heaviest givers first, text made safe.
$ratings = [
    ['giver' => 'carol', 'kind' => 'kindly', 'weight' => 0.4, 'giver_standing' => 'good', 'giver_hours' => 40, 'giver_notes' => false, 'flags' => [], 'at' => '2026-11-18', 'note' => 'Helped me <@123> build **a house**'],
    ['giver' => 'dave', 'kind' => 'kindly', 'weight' => 1.2, 'giver_standing' => 'trusted', 'giver_hours' => 300, 'giver_notes' => false, 'flags' => ['made_up'], 'at' => '2026-11-17'],
    ['giver' => 'eve', 'kind' => 'poorly', 'weight' => 0.0, 'giver_standing' => 'bad', 'giver_hours' => 2, 'giver_notes' => true, 'flags' => ['burst', 'giver_banned'], 'at' => '2026-11-19'],
    ['giver' => 'Not A Ckey', 'kind' => 'kindly', 'weight' => 5],
    ['giver' => 'frank', 'kind' => 'meh', 'weight' => 5],
];
for ($i = 0; $i < 12; $i++) {
    $ratings[] = ['giver' => "g$i", 'kind' => 'kindly', 'weight' => 0.01, 'giver_standing' => 'neutral', 'giver_hours' => 1];
}
file_put_contents($file, json_encode([
    'ckey' => 'alice', 'generated' => '2026-11-19 05:00 UTC', 'ip' => '1.2.3.4',
    'standing' => ['value' => -1, 'reason' => 'Griefed @everyone', 'author' => 'valzargaming', 'at' => '2026-11-18'],
    'hours' => 12.345,
    'summary' => ['kindly' => 14, 'poorly' => 1, 'kindly_weighted' => 1.72, 'poorly_weighted' => 0, 'per10h' => 1.39],
    'ratings' => $ratings,
]));
$karma = KarmaSnapshot::read($group, 'alice');
check(is_array($karma) && ! isset($karma['ip']), 'Unexpected fields were kept');
check(count($karma['ratings']) === 15, 'Bad rating rows were kept: '.count($karma['ratings']));
check($karma['ratings'][0]['giver'] === 'dave' && $karma['ratings'][0]['flags'] === [], 'Givers were not sorted by weight or an unknown flag was kept');
check($karma['hours'] === 12.35, 'Hours were not rounded');
$text = KarmaSnapshot::describe($karma);
check(str_contains($text, 'Admin standing: Poor (-1) by valzargaming on 2026-11-18: Griefed everyone'), 'Standing line changed: '.$text);
check(str_contains($text, 'Playtime: 12.35 h'), 'Playtime line missing');
check(str_contains($text, 'Kindly 14 (weighted 1.72), poorly 1 (weighted 0)'), 'Summary line changed');
check(str_contains($text, '- eve (bad, 2 h, weight 0, has admin notes, burst, giver banned) 2026-11-19'), 'Poorly row changed: '.$text);
check(str_contains($text, 'and 4 more'), 'Long lists were not cut');
check(! str_contains($text, '@') && ! str_contains($text, '**a house**') && ! str_contains($text, '<'), 'A mention or markdown reached the message: '.$text);

// No standing set, and an oversized file is refused.
file_put_contents($file, json_encode(['ckey' => 'alice', 'standing' => ['value' => 7]]));
check(str_contains(KarmaSnapshot::describe(KarmaSnapshot::read($group, 'alice')), 'Admin standing: not set.'), 'A bad standing was shown');
file_put_contents($file, str_repeat(' ', 1048600).'{}');
check(KarmaSnapshot::read($group, 'alice') === null, 'An oversized karma file was read');

unlink($file);
rmdir($root.'/'.KarmaSnapshot::DIR);
rmdir($root.'/shared-records');
unlink($group);
rmdir($root);
echo "PASS karma snapshot\n";
