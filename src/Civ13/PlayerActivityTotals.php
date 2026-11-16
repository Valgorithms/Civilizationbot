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
 * Weekly player activity totals for a Civ13 region group (Civ13 docs/player-activity.md). The game host writes
 * `<group root>/player-activity-weekly.json` beside its activity database; it holds totals only (no account
 * names, research or recipe ids), so it is safe to show in Discord. Read-only: the bot never opens the database.
 */
final class PlayerActivityTotals
{
    public const FILE = 'player-activity-weekly.json';
    private const MAX_BYTES = 65536;

    /** The totals for the group in this shard group file, or null with a reason in $why. */
    public static function read(string $groupFile, ?string &$why = null): ?array
    {
        $why = null;
        if ($groupFile === '') {
            $why = 'this server has no region group';

            return null;
        }
        $group = self::json($groupFile, 1048576);
        if (! is_array($group) || ! is_string($group['root'] ?? null) || $group['root'] === '') {
            $why = 'the region group file is unreadable';

            return null;
        }
        $totals = self::json(rtrim($group['root'], '/\\').'/'.self::FILE, self::MAX_BYTES);
        if (! is_array($totals)) {
            $why = 'no activity totals yet (the recorder is off or the host has not read any yet)';

            return null;
        }

        return self::clean($totals);
    }

    /** Keep only the expected totals, as whole numbers and plain region names. */
    public static function clean(array $raw): array
    {
        $int = static fn (mixed $value): int => is_int($value) || (is_float($value) && is_finite($value)) ? max(0, (int) $value) : 0;
        $num = static fn (mixed $value): float => is_int($value) || (is_float($value) && is_finite($value)) ? max(0.0, round((float) $value, 1)) : 0.0;
        $active = is_array($raw['active'] ?? null) ? $raw['active'] : [];
        $regions = [];
        foreach (is_array($raw['hours_by_region'] ?? null) ? array_slice($raw['hours_by_region'], 0, 24) : [] as $row) {
            if (is_array($row) && is_string($row['region'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $row['region']) === 1) {
                $regions[$row['region']] = $num($row['hours'] ?? 0);
            }
        }
        $generated = is_string($raw['generated'] ?? null) && preg_match('/^[0-9: UTC-]{10,24}$/D', $raw['generated']) === 1 ? $raw['generated'] : '';

        return [
            'generated' => $generated,
            'active' => ['day' => $int($active['day'] ?? 0), 'week' => $int($active['week'] ?? 0), 'month' => $int($active['month'] ?? 0)],
            'new_this_week' => $int($raw['new_this_week'] ?? 0),
            'sessions_this_week' => $int($raw['sessions_this_week'] ?? 0),
            'session_minutes_median' => $num($raw['session_minutes_median'] ?? 0),
            'deaths_this_week' => $int($raw['deaths_this_week'] ?? 0),
            'trips_this_week' => $int($raw['trips_this_week'] ?? 0),
            'hours_by_region' => $regions,
        ];
    }

    /** A short Discord message: totals only. */
    public static function describe(array $totals): string
    {
        $a = $totals['active'];
        $lines = [
            '**Player activity**'.($totals['generated'] !== '' ? " ({$totals['generated']})" : ''),
            "Active players: {$a['day']} today, {$a['week']} this week, {$a['month']} this month. New this week: {$totals['new_this_week']}.",
            "Play sessions this week: {$totals['sessions_this_week']}, median {$totals['session_minutes_median']} minutes.",
            "Deaths this week: {$totals['deaths_this_week']}. Trips between lands: {$totals['trips_this_week']}.",
        ];
        if ($totals['hours_by_region']) {
            $parts = [];
            foreach ($totals['hours_by_region'] as $region => $hours) {
                $parts[] = ucfirst($region).' '.$hours.' h';
            }
            $lines[] = 'Hours played this week: '.implode(', ', $parts).'.';
        }

        return implode("\n", $lines);
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
