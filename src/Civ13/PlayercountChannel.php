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

    /** The new channel name, or null when it already shows $label. The prefix is everything before the last "-". */
    public static function rename(string $current, string $label): ?string
    {
        $cut = strrpos($current, '-');
        $prefix = $cut === false ? $current : substr($current, 0, $cut);
        $name = "{$prefix}-{$label}";

        return $name === $current ? null : $name;
    }
}
