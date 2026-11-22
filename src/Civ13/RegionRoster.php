<?php

declare(strict_types=1);

namespace Civ13;

/**
 * Who is online across every region world of a shard group, from passive heartbeat observations.
 *
 * Each ckey is listed once. A traveller can briefly appear in two regions (the old world has not
 * rewritten serverdata.txt yet) or in none (between leaving one world and joining the next), so:
 * - a ckey listed by two regions goes to the region it was not in before, when that is known;
 * - a ckey that vanished from every running region within GRACE_SECONDS is kept, under TRANSIT,
 *   so a trip never drops a player from the count. A logout also shows there for that short window.
 */
final class RegionRoster
{
    public const TRANSIT = 'Between regions';
    /** The game rewrites serverdata.txt every 10 seconds and the bot samples every 15. */
    public const GRACE_SECONDS = 30.0;
    /** Discord embed field value limit. */
    private const FIELD_LIMIT = 1024;

    /** @var array<string, float> ckey => last time it was listed by a running region */
    private array $lastSeen = [];
    /** @var array<string, string> ckey => region it was last placed in */
    private array $lastRegion = [];
    /** @var array<string, list<string>> region name => ckeys, from the last observation */
    private array $regions = [];

    /**
     * Records one observation of every world and returns the worlds with a TRANSIT world appended
     * when someone is between regions, so {@see PlayercountChannel::label()} counts them too.
     *
     * @param  list<array{name?:string, state:string, reason:string, players:list<string>}> $worlds In display order.
     * @return list<array{name?:string, state:string, reason:string, players:list<string>}>
     */
    public function observe(array $worlds, float $now): array
    {
        $listedBy = [];
        $up = false;
        foreach ($worlds as $index => $world) {
            if ($world['state'] !== 'advancing') {
                continue;
            }
            $up = true;
            $name = self::name($world, $index);
            foreach ($world['players'] as $ckey) {
                if (! in_array($name, $listedBy[$ckey] ?? [], true)) {
                    $listedBy[$ckey][] = $name;
                }
            }
        }
        if (! $up) {
            // Nothing is running: nobody can be travelling, and old sightings must not resurface later.
            $this->lastSeen = $this->lastRegion = $this->regions = [];

            return $worlds;
        }
        $regions = [];
        foreach ($listedBy as $ckey => $names) {
            $ckey = (string) $ckey;
            $region = $names[0];
            if (count($names) > 1 && isset($this->lastRegion[$ckey]) && in_array($this->lastRegion[$ckey], $names, true)) {
                // Still listed by the world it left: it is in the other one now.
                foreach ($names as $candidate) {
                    if ($candidate !== $this->lastRegion[$ckey]) {
                        $region = $candidate;
                        break;
                    }
                }
            }
            $regions[$region][] = $ckey;
            $this->lastSeen[$ckey] = $now;
            $this->lastRegion[$ckey] = $region;
        }
        $transit = [];
        foreach ($this->lastSeen as $ckey => $at) {
            $ckey = (string) $ckey;
            if (isset($listedBy[$ckey])) {
                continue;
            }
            if ($now >= $at && $now - $at < self::GRACE_SECONDS) {
                $transit[] = $ckey;
            } else {
                unset($this->lastSeen[$ckey], $this->lastRegion[$ckey]);
            }
        }
        if ($transit) {
            $regions[self::TRANSIT] = $transit;
            $worlds[] = ['name' => self::TRANSIT, 'state' => 'advancing', 'reason' => 'Recently left a region and not yet listed by another', 'players' => $transit];
        }
        $this->regions = $regions;

        return $worlds;
    }

    /** @return array<string, list<string>> Region name => its ckeys, in world order, TRANSIT last. */
    public function regions(): array
    {
        return $this->regions;
    }

    public function total(): int
    {
        return array_sum(array_map('count', $this->regions));
    }

    /**
     * The embed field for the last observation: a plain list for a single region, otherwise one
     * line per region with players. Falls back to per-region counts when the names do not fit.
     *
     * @return array{0:string, 1:string} Field name and value.
     */
    public function field(bool $grouped): array
    {
        // Leave observations and operational headcounts alone; hide only from the embed.
        $regions = array_filter(array_map(PlayerEmbedKeys::withoutGuests(...), $this->regions));
        $title = 'Online Players ('.array_sum(array_map('count', $regions)).')';
        if (! $regions) {
            return [$title, 'None'];
        }
        if (! $grouped && count($regions) === 1) {
            return [$title, self::fit(implode(', ', reset($regions)))];
        }
        $lines = $counts = [];
        foreach ($regions as $region => $ckeys) {
            $label = $region === self::TRANSIT ? '*'.self::TRANSIT.'*' : '**'.self::escape($region).'**';
            $lines[] = $label.' ('.count($ckeys).'): '.implode(', ', $ckeys);
            $counts[] = $label.' ('.count($ckeys).')';
        }
        $value = implode("\n", $lines);

        return [$title, strlen($value) <= self::FIELD_LIMIT ? $value : self::fit(implode("\n", $counts))];
    }

    private static function name(array $world, int $index): string
    {
        $name = trim((string) ($world['name'] ?? ''));

        return $name === '' ? 'Region '.($index + 1) : $name;
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '*', '_', '~', '`', '|', '>'], '', $text);
    }

    private static function fit(string $value): string
    {
        return strlen($value) <= self::FIELD_LIMIT ? $value : substr($value, 0, self::FIELD_LIMIT - 3).'...';
    }
}
