<?php

declare(strict_types=1);

namespace Civ13\AdminConsole;

/**
 * Read-only display projection over already collected, normalized observations.
 * No routing, collection, sessions, game authority or control effects.
 */
final class AdminServerProjection
{
    public const MAX_AGE_SECONDS = 120.0;

    /**
     * All closures are application-owned, not browser input. Authorization must
     * return literal true for this actor/target on every render. The observation
     * source must not refresh its timestamp when this projection reads it.
     *
     * @param \Closure(string, string): bool $authorize
     * @param \Closure(string): ?array $observations
     * @param \Closure(): float $clock Monotonic seconds in $clockScope.
     */
    public function __construct(
        private \Closure $authorize,
        private \Closure $observations,
        private \Closure $clock,
        private string $clockScope,
    ) {
        if ($clockScope === '') {
            throw new \InvalidArgumentException('Clock scope required');
        }
    }

    /**
     * Target ID is selected from a trusted application registry before calling.
     * No observation or DTO returned here can authorize an operation.
     */
    public function project(string $actor, string $target): array
    {
        try {
            $allowed = ($this->authorize)($actor, $target) === true;
        } catch (\Throwable) {
            $allowed = false;
        }
        if (! $allowed) {
            return ['schema' => 'civ13.admin-status.v1', 'access' => 'denied'];
        }

        $view = [
            'schema' => 'civ13.admin-status.v1',
            'access' => 'allowed',
            'server_id' => $target,
            'state' => 'unknown',
            'reason' => 'observation_unavailable',
            'age_seconds' => null,
            'ready' => null,
            'metrics' => ['players' => null, 'map' => null, 'mode' => null,
                'round_id' => null, 'round_duration_seconds' => null,
                'generation' => null, 'save_load_phase' => null],
            'capabilities' => ['view_status' => true, 'save' => false,
                'restart' => false, 'verbs' => false, 'control_reason' => 'read_only'],
        ];
        try {
            $sample = ($this->observations)($target);
            $now = ($this->clock)();
        } catch (\Throwable) {
            return $view;
        }
        if (! is_array($sample) || ($sample['server_id'] ?? null) !== $target
            || ($sample['clock_scope'] ?? null) !== $this->clockScope) {
            return $view;
        }
        $observed = $sample['observed_at'] ?? null;
        $expires = $sample['expires_at'] ?? null;
        if (! self::number($now) || ! self::number($observed) || ! self::number($expires)
            || $observed > $now || $expires <= $observed
            || $expires - $observed > self::MAX_AGE_SECONDS) {
            $view['reason'] = 'invalid_observation_time';
            return $view;
        }
        $view['age_seconds'] = (float) ($now - $observed);
        if ($now >= $expires) {
            $view['state'] = 'stale';
            $view['reason'] = 'observation_expired';
            return $view;
        }
        $state = $sample['state'] ?? null;
        if (! in_array($state, ['unknown', 'stale', 'offline', 'online', 'busy', 'recovery_hold'], true)) {
            $view['reason'] = 'invalid_observation_state';
            return $view;
        }
        $view['state'] = $state;
        $view['reason'] = match ($state) {
            'unknown' => 'source_unknown',
            'stale' => 'source_stale',
            // Offline means observed unreachable; it does NOT establish stopped.
            'offline' => 'source_unreachable',
            'busy' => 'operation_in_progress',
            'recovery_hold' => 'recovery_hold',
            default => 'fresh_observation',
        };
        if (in_array($state, ['unknown', 'stale', 'offline'], true)) {
            return $view;
        }
        $view['ready'] = in_array($state, ['busy', 'recovery_hold'], true)
            ? false : (is_bool($sample['ready'] ?? null) ? $sample['ready'] : null);
        $data = is_array($sample['metrics'] ?? null) ? $sample['metrics'] : [];
        foreach (['players' => 10000, 'round_duration_seconds' => 2147483647] as $key => $maximum) {
            $value = $data[$key] ?? null;
            if (is_int($value) && $value >= 0 && $value <= $maximum) {
                $view['metrics'][$key] = $value;
            }
        }
        foreach (['map' => 128, 'mode' => 80, 'round_id' => 80, 'generation' => 160] as $key => $limit) {
            $value = $data[$key] ?? null;
            if (is_string($value) && strlen($value) <= $limit
                && preg_match('//u', $value) === 1
                && preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $value) === 0) {
                $view['metrics'][$key] = $value;
            }
        }
        $phase = $data['save_load_phase'] ?? null;
        if (in_array($phase, ['idle', 'saving', 'loading', 'restoring_players'], true)) {
            $view['metrics']['save_load_phase'] = $phase;
        }
        return $view;
    }

    private static function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
