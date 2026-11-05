<?php

declare(strict_types=1);

namespace Civ13;

/**
 * Builds the player-count channel name ("<prefix>-<count>") from passive heartbeat observations.
 *
 * Every world of a region group (the main server plus its shard regions) is counted: the
 * count is the number of distinct ckeys across worlds whose heartbeat is advancing.
 */
final class PlayercountChannel
{
    public const OFFLINE = 'offline';

    /**
     * @param  list<array{state:string, reason:string, players:list<string>}> $worlds
     * @return ?string The count or "offline", or null when there is no evidence yet and the name should stay.
     */
    public static function label(array $worlds): ?string
    {
        if (! $worlds) {
            return null; // Not observed locally: never guess.
        }
        $players = [];
        $up = $waiting = false;
        foreach ($worlds as $world) {
            if ($world['state'] === 'advancing') {
                $up = true;
                foreach ($world['players'] as $ckey) {
                    $players[$ckey] = true;
                }
            } elseif ($world['state'] === 'unknown' && $world['reason'] !== ServerHeartbeat::MISSING) {
                $waiting = true; // First reads after a bot or world start are only a baseline.
            }
        }
        if ($up) {
            return (string) count($players);
        }

        return $waiting ? null : self::OFFLINE;
    }

    /**
     * The new channel name, or null when it already shows $label. A trailing count, "offline" or
     * "unknown" is replaced; any other name is kept whole as the prefix.
     */
    public static function rename(string $current, string $label): ?string
    {
        $prefix = preg_match('/^(.*)-(?:[0-9]+|offline|unknown)$/sD', $current, $match) === 1 ? $match[1] : $current;
        $name = "{$prefix}-{$label}";

        return $name === $current ? null : $name;
    }
}
