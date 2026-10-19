<?php

declare(strict_types=1);

namespace Civ13\ServerDirectory;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/** Small, online-only public projection of configured servers. Never exposes raw status. */
final class Directory
{
    public const PATH = '/.well-known/civ13/servers.json';
    public const PROVIDERS = ['civ13.com', 'valzargaming.com'];
    public const MAX_SERVERS = 32;
    public const MAX_AGE_SECONDS = 60;
    public const REFRESH_SECONDS = 15;

    private array $targets = [];
    private array $snapshots = [];
    private ?int $completedAt = null;
    private ?PromiseInterface $pending = null;

    /**
     * @param array<string, list<string>> $providers Explicit provider => configured server keys.
     * @param array<string, object> $registry The bot's enabled_gameservers, not request data.
     * @param callable(string, int): PromiseInterface $probe
     * @param callable(): int|null $clock Test seam; production uses UTC wall-clock seconds.
     */
    public function __construct(array $providers, array $registry, private $probe, private $clock = null)
    {
        $this->clock ??= time(...);
        $count = 0;
        $seen = [];
        foreach ($providers as $host => $keys) {
            if (!in_array($host, self::PROVIDERS, true) || !is_array($keys) || !array_is_list($keys)) {
                throw new \InvalidArgumentException('Invalid launcher directory provider configuration');
            }
            $this->targets[$host] = [];
            $ports = [];
            foreach ($keys as $key) {
                if (!is_string($key) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $key) || isset($seen[$key]) || !isset($registry[$key])) {
                    throw new \InvalidArgumentException('Directory keys must uniquely name configured enabled servers');
                }
                $seen[$key] = true;
                $server = $registry[$key];
                if (($server->enabled ?? false) !== true) {
                    throw new \InvalidArgumentException('Directory server is disabled');
                }
                $port = filter_var($server->port ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
                $address = $server->ip ?? null;
                if (!is_string($address) || strlen($address) > 253 || (!filter_var($address, FILTER_VALIDATE_IP) && !preg_match('/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\z/D', $address))) {
                    throw new \InvalidArgumentException('Invalid configured probe address');
                }
                if ($port === false || isset($ports[$port]) || self::text($server->name ?? null, 120) === null || ++$count > self::MAX_SERVERS) {
                    throw new \InvalidArgumentException('Invalid directory server or too many servers');
                }
                $ports[$port] = true;
                $this->targets[$host][] = ['id' => $key, 'name' => $server->name, 'host' => $host, 'port' => $port, 'address' => $address];
            }
        }
    }

    /** One bounded sweep; overlapping timers share it and HTTP requests never trigger probes. */
    public function refresh(): PromiseInterface
    {
        if ($this->pending !== null) {
            return $this->pending;
        }
        $deferred = new Deferred();
        $this->pending = $deferred->promise();
        $pending = $this->pending;
        $requests = [];
        foreach ($this->targets as $targets) {
            foreach ($targets as $target) {
                try {
                    $request = ($this->probe)($target['address'], $target['port']);
                    $requests[] = $request->then(function ($status) use ($target): ?array {
                        return is_array($status) ? self::entry($target, $status, ($this->clock)()) : null;
                    }, static fn () => null);
                } catch (\Throwable) {
                    $requests[] = resolve(null);
                }
            }
        }
        all($requests)->then(function (array $entries) use ($deferred): void {
            $snapshots = array_fill_keys(array_keys($this->targets), []);
            foreach ($entries as $entry) {
                if ($entry !== null) {
                    $snapshots[$entry['host']][] = $entry;
                }
            }
            // Atomic replacement drops formerly online servers after any failed probe.
            $this->snapshots = $snapshots;
            $this->completedAt = ($this->clock)();
            $this->pending = null;
            $deferred->resolve(null);
        }, function (\Throwable $error) use ($deferred): void {
            $this->pending = null;
            $deferred->reject($error);
        });
        return $pending;
    }

    /** Null means unconfigured, warming up or stale, never an invented online result. */
    public function snapshot(string $host): ?array
    {
        $now = ($this->clock)();
        if (!isset($this->targets[$host]) || $this->completedAt === null || $now < $this->completedAt || $now - $this->completedAt >= self::MAX_AGE_SECONDS) {
            return null;
        }
        $servers = [];
        $expires = $now + self::MAX_AGE_SECONDS;
        foreach ($this->snapshots[$host] as $entry) {
            $checked = $entry['_checked'];
            if ($checked > $now || $now - $checked >= self::MAX_AGE_SECONDS) {
                continue;
            }
            $expires = min($expires, $checked + self::MAX_AGE_SECONDS);
            unset($entry['_checked']);
            $servers[] = $entry;
        }
        // An empty result still expires relative to its last real sweep.
        $expires = min($expires, $this->completedAt + self::MAX_AGE_SECONDS);
        return ['schema' => 1, 'generated_at' => self::timestamp($now), 'expires_at' => self::timestamp($expires), 'servers' => $servers];
    }

    public function hasProvider(string $host): bool
    {
        return isset($this->targets[$host]);
    }

    private static function entry(array $target, array $status, int $now): ?array
    {
        $players = $status['players'] ?? null;
        $ticks = $status['round_duration_in_ticks'] ?? null;
        $id = $status['game_id'] ?? '';
        $id = is_int($id) ? (string) $id : $id;
        $map = self::text($status['map_name'] ?? null, 128);
        $mode = self::text($status['map_gamemode'] ?? null, 80);
        if (!is_int($players) || $players < 0 || $players > 10000 || (!is_int($ticks) && !is_float($ticks)) || !is_finite((float) $ticks) || $ticks < 0 || $ticks > 2147483647 || $map === null || $mode === null || self::text($id, 80, true) === null) {
            return null;
        }
        $entry = [
            'id' => $target['id'], 'name' => $target['name'], 'host' => $target['host'], 'port' => $target['port'],
            'engine' => 'BYOND', 'online' => true, 'checked_at' => self::timestamp($now), 'players' => $players,
            'map' => $map, 'mode' => $mode, 'round' => ['id' => $id, 'duration_seconds' => (int) floor($ticks / 10)], '_checked' => $now,
        ];
        // Explicit allowlist only: never pass through ckeys, IPs, CIDs, admins, or account links.
        $epoch = $status['map_age'] ?? null;
        $epoch = is_int($epoch) ? (string) $epoch : $epoch;
        if (($epoch = self::text($epoch, 32)) !== null) {
            $entry['epoch'] = $epoch;
        }
        return $entry;
    }

    private static function text(mixed $value, int $maxBytes, bool $emptyAllowed = false): ?string
    {
        if (!is_string($value) || (!$emptyAllowed && trim($value) === '') || strlen($value) > $maxBytes || !preg_match('//u', $value) || preg_match('/[\p{Cc}\p{Cf}\p{Cs}\p{Zl}\p{Zp}]/u', $value)) {
            return null;
        }
        return $value;
    }

    private static function timestamp(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }
}
