<?php

declare(strict_types=1);

namespace Civ13\AdminConsole;

/** Pure normalization of application-owned captures. Never collects or probes. */
final class CachedObservationAdapter
{
    public function __construct(private string $clockScope)
    {
        if ($clockScope === '') { throw new \InvalidArgumentException('Clock scope required'); }
    }

    /**
     * Target is from the trusted registry: exact id and provider, not request input.
     * Directory capture: {clock_scope,captured_at,wall_time,snapshot}; snapshot is
     * Directory::snapshot(provider) output, captured once by an existing collector.
     * Heartbeat capture: {server_id,clock_scope,sampled_at,observation}; observation
     * is ServerHeartbeat::poll() output, captured at that poll, never at rendering.
     * $now is the caller's injected monotonic clock in this same clock scope.
     */
    public function normalize(array $target, ?array $directory, ?array $heartbeat, float $now): array
    {
        if (!is_string($target['id'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $target['id'])
            || !in_array($target['provider'] ?? null, ['civ13.com', 'valzargaming.com'], true)
            || !is_finite($now)) {
            throw new \InvalidArgumentException('Explicit target and finite clock required');
        }
        // Missing evidence has no invented observation time or expiry.
        $view = ['server_id' => $target['id'], 'clock_scope' => $this->clockScope,
            'observed_at' => null, 'collected_at' => null, 'expires_at' => null,
            'state' => 'unknown', 'ready' => null, 'metrics' => [], 'evidence' => []];
        $heartbeatEvidence = $this->heartbeat($target['id'], $heartbeat, $now);
        if ($heartbeatEvidence !== null) { $view['evidence']['heartbeat'] = $heartbeatEvidence; }
        $row = $this->directory($target, $directory, $now);
        if ($row === null) { return $view; }
        [$entry, $observed, $expires] = $row;
        $view['observed_at'] = $observed;
        $view['collected_at'] = $directory['captured_at'];
        $view['expires_at'] = $expires;
        $view['state'] = 'online'; // Only a complete directory row proves reachability.
        $view['evidence']['reachability'] = ['state' => 'reachable', 'observed_at' => $observed, 'collected_at' => $directory['captured_at'], 'expires_at' => $expires];
        $view['metrics'] = ['players' => $entry['players'], 'map' => $entry['map'], 'mode' => $entry['mode'],
            'round_id' => $entry['round']['id'], 'round_duration_seconds' => $entry['round']['duration_seconds']];
        // No generation, save phase, lifecycle readiness, identity secrets or raw data.
        return $view;
    }

    private function directory(array $target, ?array $capture, float $now): ?array
    {
        if ($capture === null || ($capture['clock_scope'] ?? null) !== $this->clockScope
            || !self::number($capture['captured_at'] ?? null) || $capture['captured_at'] > $now
            || !is_int($capture['wall_time'] ?? null)) { return null; }
        $snapshot = $capture['snapshot'] ?? null;
        if (!is_array($snapshot) || ($snapshot['schema'] ?? null) !== 1 || !is_array($snapshot['servers'] ?? null)
            || !array_is_list($snapshot['servers']) || count($snapshot['servers']) > 32) { return null; }
        $generated = self::timestamp($snapshot['generated_at'] ?? null);
        $expires = self::timestamp($snapshot['expires_at'] ?? null);
        if ($generated === null || $expires === null || $generated > $capture['wall_time']
            || $expires <= $generated || $expires - $generated > 60) { return null; }
        $entry = null;
        foreach ($snapshot['servers'] as $candidate) {
            if (!is_array($candidate)) { return null; }
            if (($candidate['id'] ?? null) === $target['id']) {
                if ($entry !== null) { return null; }
                $entry = $candidate;
            }
        }
        if ($entry === null || ($entry['host'] ?? null) !== $target['provider']
            || ($entry['online'] ?? null) !== true || ($entry['engine'] ?? null) !== 'BYOND'
            || !is_int($entry['players'] ?? null) || $entry['players'] < 0 || $entry['players'] > 10000
            || !self::text($entry['map'] ?? null, 128) || !self::text($entry['mode'] ?? null, 80)
            || !is_array($entry['round'] ?? null) || !self::text($entry['round']['id'] ?? null, 80, true)
            || !is_int($entry['round']['duration_seconds'] ?? null)
            || $entry['round']['duration_seconds'] < 0 || $entry['round']['duration_seconds'] > 2147483647) { return null; }
        $checked = self::timestamp($entry['checked_at'] ?? null);
        if ($checked === null || $checked > $generated || $expires <= $checked || $expires - $checked > 60) { return null; }
        // Fixed clock-pair conversion; generated_at is never used as observation time.
        $observedMono = $capture['captured_at'] - ($capture['wall_time'] - $checked);
        $expiresMono = $capture['captured_at'] - ($capture['wall_time'] - $expires);
        return [$entry, $observedMono, $expiresMono];
    }

    private function heartbeat(string $target, ?array $capture, float $now): ?array
    {
        if ($capture === null || ($capture['server_id'] ?? null) !== $target
            || ($capture['clock_scope'] ?? null) !== $this->clockScope
            || !self::number($capture['sampled_at'] ?? null) || $capture['sampled_at'] > $now) { return null; }
        $source = $capture['observation'] ?? null;
        if (!is_array($source) || !in_array($source['state'] ?? null, ['advancing', 'stale', 'unknown'], true)) { return null; }
        $sampled = (float) $capture['sampled_at'];
        if ($source['state'] === 'advancing') {
            $age = $source['age'] ?? null;
            if (!self::number($age) || $age < 0 || $age >= 120) { return null; }
            // Last observed writer progress, not file mtime or the latest read time.
            return ['state' => 'advancing', 'observed_at' => $sampled - $age, 'collected_at' => $sampled, 'expires_at' => $sampled - $age + 120.0];
        }
        // Stale/unknown are observations, not evidence of current writer progress.
        return ['state' => $source['state'], 'observed_at' => $sampled, 'collected_at' => $sampled, 'expires_at' => $sampled + 120.0];
    }

    private static function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function text(mixed $value, int $limit, bool $empty = false): bool
    {
        return is_string($value) && ($empty || trim($value) !== '') && strlen($value) <= $limit
            && preg_match('//u', $value) === 1 && preg_match('/[\p{Cc}\p{Cf}\p{Cs}\p{Zl}\p{Zp}]/u', $value) === 0;
    }

    private static function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value) !== 1) { return null; }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value ? $time->getTimestamp() : null;
    }
}
