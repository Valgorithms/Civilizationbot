<?php
declare(strict_types=1);
namespace Civ13;

/** In-memory last valid remote list; availability and retained data are separate. */
final class HostedVerificationSnapshot
{
    public const FRESH_SECONDS = 1800;
    public const COALESCE_SECONDS = 10;
    private ?array $records = null;
    private ?int $success = null;
    private ?int $attempt = null;
    private bool $failed = true;
    private bool $busy = false;
    private \Closure $clock;

    public function __construct(?callable $clock = null) { $this->clock = \Closure::fromCallable($clock ?? time(...)); }
    public function records(): array { return $this->records ?? []; }

    public function status(): array
    {
        $age = $this->success === null ? null : max(0, ($this->clock)() - $this->success);
        $available = !$this->failed && $age !== null && $age < self::FRESH_SECONDS;
        return ['available' => $available, 'stale' => !$available, 'has_valid_list' => $this->records !== null,
            'last_success_utc' => $this->success === null ? null : gmdate('Y-m-d\TH:i:s\Z', $this->success),
            'age_seconds' => $age, 'records' => $this->records === null ? null : count($this->records),
            'refresh_in_progress' => $this->busy];
    }

    /** One bounded GET per attempt; overlapping and queued bursts share the prior result. */
    public function refresh(callable $fetch): array
    {
        $now = ($this->clock)();
        if ($this->busy || ($this->attempt !== null && $now - $this->attempt < self::COALESCE_SECONDS)) {
            return $this->status() + ['coalesced' => true];
        }
        $this->busy = true; $this->attempt = $now;
        try {
            $response = $fetch();
            if (($response['http_status'] ?? null) !== 200 || !is_string($response['json'] ?? null)) {
                throw new \RuntimeException('Hosted verification unavailable');
            }
            $records = HostedVerificationSource::validate($response['json']);
            $this->records = $records; $this->success = ($this->clock)(); $this->failed = false;
        } catch (\Throwable) {
            // Retain the last valid list for diagnosis; never authorize from this stale view.
            $this->failed = true;
        } finally { $this->busy = false; }
        return $this->status() + ['coalesced' => false];
    }
}
