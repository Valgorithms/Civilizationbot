<?php

declare(strict_types=1);

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13;

/**
 * Hidden karma for one ckey (Civ13 karma plan). The region group's coordinator writes
 * `<group root>/shared-records/karma/<ckey>.json` whenever that ckey's karma changes. Karma is staff-only, so the
 * command that shows it is for the Admin role and answers only in the staff bot channel or a DM. Read-only: the
 * bot never opens the coordinator database.
 */
final class KarmaSnapshot
{
    public const DIR = 'shared-records/karma';
    public const SHOWN_PER_KIND = 10;
    private const MAX_BYTES = 1048576;
    private const MAX_RATINGS = 2000;
    private const STANDINGS = ['trusted', 'good', 'neutral', 'poor', 'bad', 'unknown'];
    private const FLAGS = ['burst', 'shared_connection', 'low_weight', 'giver_banned'];
    private const STANDING_NAMES = [2 => 'Trusted (+2)', 1 => 'Good (+1)', 0 => 'Neutral (0)', -1 => 'Poor (-1)', -2 => 'Bad (-2)'];

    /** A BYOND ckey as the game stores it. */
    public static function validCkey(string $ckey): bool
    {
        return preg_match('/^[a-z0-9]{1,64}$/D', $ckey) === 1;
    }

    /** Karma is shown only in a DM or in the staff bot channel, never where players can read it. */
    public static function allowedPlace(?string $guildId, ?string $channelId, ?string $staffChannelId): bool
    {
        if ($guildId === null || $guildId === '') {
            return true;
        }

        return is_string($staffChannelId) && $staffChannelId !== '' && $channelId === $staffChannelId;
    }

    /** The karma for this ckey in this shard group file, or null with a reason in $why. */
    public static function read(string $groupFile, string $ckey, ?string &$why = null): ?array
    {
        $why = null;
        if (! self::validCkey($ckey)) {
            $why = 'that is not a valid ckey';

            return null;
        }
        if ($groupFile === '') {
            $why = 'this server has no region group';

            return null;
        }
        $group = self::json($groupFile, 1048576);
        if (! is_array($group) || ! is_string($group['root'] ?? null) || $group['root'] === '') {
            $why = 'the region group file is unreadable';

            return null;
        }
        $raw = self::json(rtrim($group['root'], '/\\').'/'.self::DIR.'/'.$ckey.'.json', self::MAX_BYTES);
        if (! is_array($raw)) {
            $why = 'no karma recorded for that ckey';

            return null;
        }
        $karma = self::clean($raw);
        if ($karma['ckey'] !== $ckey) {
            $why = 'the karma file does not belong to that ckey';

            return null;
        }

        return $karma;
    }

    /** Keep only the expected fields, with safe values. */
    public static function clean(array $raw): array
    {
        $num = static fn (mixed $value): float => is_int($value) || (is_float($value) && is_finite($value)) ? round((float) $value, 2) : 0.0;
        $count = static fn (mixed $value): int => is_int($value) ? max(0, $value) : 0;
        $date = static fn (mixed $value): string => is_string($value) && preg_match('/^[0-9: UTC-]{10,24}$/D', $value) === 1 ? $value : '';
        $ckey = static fn (mixed $value): string => is_string($value) && self::validCkey($value) ? $value : '';

        $standing = null;
        if (is_array($raw['standing'] ?? null) && is_int($raw['standing']['value'] ?? null) && isset(self::STANDING_NAMES[$raw['standing']['value']])) {
            $standing = [
                'value' => $raw['standing']['value'],
                'reason' => self::text($raw['standing']['reason'] ?? '', 300),
                'author' => $ckey($raw['standing']['author'] ?? ''),
                'at' => $date($raw['standing']['at'] ?? ''),
            ];
        }
        $summary = is_array($raw['summary'] ?? null) ? $raw['summary'] : [];
        $ratings = [];
        foreach (is_array($raw['ratings'] ?? null) ? array_slice($raw['ratings'], 0, self::MAX_RATINGS) : [] as $row) {
            if (! is_array($row) || ! in_array($row['kind'] ?? null, ['kindly', 'poorly'], true) || ($giver = $ckey($row['giver'] ?? '')) === '') {
                continue;
            }
            $ratings[] = [
                'giver' => $giver,
                'kind' => $row['kind'],
                'weight' => max(0.0, $num($row['weight'] ?? 0)),
                'giver_standing' => in_array($row['giver_standing'] ?? null, self::STANDINGS, true) ? $row['giver_standing'] : 'unknown',
                'giver_hours' => max(0.0, $num($row['giver_hours'] ?? 0)),
                'giver_notes' => ($row['giver_notes'] ?? false) === true,
                'flags' => array_values(array_intersect(self::FLAGS, is_array($row['flags'] ?? null) ? $row['flags'] : [])),
                'at' => $date($row['at'] ?? ''),
                'note' => self::text($row['note'] ?? '', 1000),
            ];
        }
        // Heaviest first, so the shown rows are the ones that count most.
        usort($ratings, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight'] ?: strcmp($a['giver'], $b['giver']));

        return [
            'ckey' => $ckey($raw['ckey'] ?? ''),
            'generated' => $date($raw['generated'] ?? ''),
            'standing' => $standing,
            'hours' => max(0.0, $num($raw['hours'] ?? 0)),
            'summary' => [
                'kindly' => $count($summary['kindly'] ?? 0),
                'poorly' => $count($summary['poorly'] ?? 0),
                'kindly_weighted' => max(0.0, $num($summary['kindly_weighted'] ?? 0)),
                'poorly_weighted' => max(0.0, $num($summary['poorly_weighted'] ?? 0)),
                'per10h' => $num($summary['per10h'] ?? 0),
            ],
            'ratings' => $ratings,
        ];
    }

    /** A Discord message for staff. */
    public static function describe(array $karma): string
    {
        $s = $karma['summary'];
        $lines = ["**Karma for {$karma['ckey']}**".($karma['generated'] !== '' ? " ({$karma['generated']})" : '')];
        if ($standing = $karma['standing']) {
            $by = $standing['author'] !== '' ? " by {$standing['author']}" : '';
            $on = $standing['at'] !== '' ? " on {$standing['at']}" : '';
            $why = $standing['reason'] !== '' ? ": {$standing['reason']}" : '';
            $lines[] = 'Admin standing: '.self::STANDING_NAMES[$standing['value']].$by.$on.$why;
        } else {
            $lines[] = 'Admin standing: not set.';
        }
        $lines[] = "Playtime: {$karma['hours']} h (alive, awake and connected).";
        $lines[] = "Kindly {$s['kindly']} (weighted {$s['kindly_weighted']}), poorly {$s['poorly']} (weighted {$s['poorly_weighted']}). Net per 10 h played: {$s['per10h']}.";
        foreach (['kindly' => 'Remembered kindly by', 'poorly' => 'Remembered poorly by'] as $kind => $title) {
            $rows = array_values(array_filter($karma['ratings'], static fn (array $row): bool => $row['kind'] === $kind));
            if (! $rows) {
                continue;
            }
            $lines[] = "**$title** (".count($rows).')';
            foreach (array_slice($rows, 0, self::SHOWN_PER_KIND) as $row) {
                $extra = [$row['giver_standing'], $row['giver_hours'].' h', 'weight '.$row['weight']];
                if ($row['giver_notes']) {
                    $extra[] = 'has admin notes';
                }
                foreach ($row['flags'] as $flag) {
                    $extra[] = str_replace('_', ' ', $flag);
                }
                $line = "- {$row['giver']} (".implode(', ', $extra).')'.($row['at'] !== '' ? " {$row['at']}" : '');
                if ($row['note'] !== '') {
                    $line .= ': '.self::text($row['note'], 120);
                }
                $lines[] = $line;
            }
            if (count($rows) > self::SHOWN_PER_KIND) {
                $lines[] = '- and '.(count($rows) - self::SHOWN_PER_KIND).' more (see the in-game Karma panel).';
            }
        }

        return implode("\n", $lines);
    }

    /** Plain text on one line: no mentions, markdown or links survive. */
    private static function text(mixed $value, int $limit): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = str_replace(['@', '`', '*', '_', '~', '|', '>', '<', '[', ']', '(', ')', '#'], ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strlen($value) > $limit ? rtrim(mb_substr($value, 0, $limit - 1)).'…' : $value;
    }

    private static function json(string $path, int $limit): mixed
    {
        clearstatcache(true, $path);
        $bytes = @is_file($path) ? @file_get_contents($path, false, null, 0, $limit + 1) : false;
        if (! is_string($bytes) || strlen($bytes) > $limit) {
            return null;
        }
        try {
            return json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
