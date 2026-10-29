<?php

declare(strict_types=1);

namespace Civ13\AdminConsole;

/** Fixed inventory + per-request policy + cached captures. Never boots or collects. */
final class ReadOnlyStatusService
{
    private array $targets = [];

    /**
     * Inventory is operator configuration: list<{id,name,provider}>, not a request.
     * authorize(discordId,targetId): bool, or null/throw when cache policy unavailable.
     * It must read the maintained member cache/readiness barrier; never fetch members.
     * captures(targetId): {directory:?array,heartbeat:?array} retained original captures.
     * Callbacks are synchronous and application-owned. No ambient auth fallback.
     */
    public function __construct(
        array $inventory,
        private string $clockScope,
        private \Closure $clock,
        private \Closure $captures,
        private ?\Closure $authorize = null,
    ) {
        if ($clockScope === '' || !array_is_list($inventory) || count($inventory) > 32) {
            throw new \InvalidArgumentException('Bounded explicit inventory required');
        }
        foreach ($inventory as $target) {
            if (!is_array($target) || count($target) !== 3 || array_diff(array_keys($target), ['id', 'name', 'provider'])
                || !is_string($target['id'] ?? null) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $target['id']) !== 1
                || isset($this->targets['server:'.$target['id']]) || !in_array($target['provider'] ?? null, ['civ13.com', 'valzargaming.com'], true)
                || !is_string($target['name'] ?? null) || trim($target['name']) === '' || strlen($target['name']) > 120
                || preg_match('//u', $target['name']) !== 1 || preg_match('/[\p{Cc}\p{Cf}\p{Cs}\p{Zl}\p{Zp}]/u', $target['name']) !== 0) {
                throw new \InvalidArgumentException('Invalid trusted target inventory');
            }
            $this->targets['server:'.$target['id']] = $target;
        }
    }

    /** Actor comes only from an independently authenticated server-side session. */
    public function read(string $actor): array
    {
        if (!self::actor($actor)) { return self::error(401, 'authentication_required'); }
        if ($this->authorize === null) { return self::error(503, 'authorization_unavailable'); }
        try {
            $allowed = [];
            foreach ($this->targets as $target) {
                $id = $target['id'];
                $decision = ($this->authorize)($actor, $id);
                if (!is_bool($decision)) { return self::error(503, 'authorization_unavailable'); }
                if ($decision) { $allowed[] = $target; }
            }
            // Do not disclose an empty inventory to an actor with no authorized target.
            if ($allowed === []) { return self::error(403, 'access_denied'); }
            $now = ($this->clock)();
            if ((!is_int($now) && !is_float($now)) || !is_finite((float) $now)) {
                return self::error(503, 'observations_unavailable');
            }
            $normalizer = new CachedObservationAdapter($this->clockScope);
            $servers = [];
            foreach ($allowed as $target) {
                $id = $target['id'];
                $capture = ($this->captures)($id);
                if (!is_array($capture) || !array_key_exists('directory', $capture) || !array_key_exists('heartbeat', $capture)
                    || ($capture['directory'] !== null && !is_array($capture['directory']))
                    || ($capture['heartbeat'] !== null && !is_array($capture['heartbeat']))) {
                    return self::error(503, 'observations_unavailable');
                }
                $observation = $normalizer->normalize($target, $capture['directory'], $capture['heartbeat'], (float) $now);
                $projection = new AdminServerProjection(
                    static fn (string $candidate, string $targetId): bool => $candidate === $actor && $targetId === $id,
                    static fn (): array => $observation,
                    static fn (): float => (float) $now,
                    $this->clockScope,
                );
                $servers[] = ['name' => $target['name'], 'projection' => $projection->project($actor, $id)];
            }
            // Re-read policy immediately before releasing any snapshot. Never cache a grant.
            foreach ($allowed as $target) {
                $id = $target['id'];
                $decision = ($this->authorize)($actor, $id);
                if (!is_bool($decision)) { return self::error(503, 'authorization_unavailable'); }
                if (!$decision) { return self::error(403, 'access_denied'); }
            }
            return ['status' => 200, 'body' => ['schema' => 'civ13.admin-overview.v1', 'servers' => $servers]];
        } catch (\Throwable) {
            return self::error(503, 'status_unavailable');
        }
    }

    public static function actor(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[1-9][0-9]{16,19}\z/D', $id) === 1;
    }

    private static function error(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['error' => $code]];
    }
}
