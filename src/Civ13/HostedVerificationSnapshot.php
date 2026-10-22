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
    private ?\React\Promise\PromiseInterface $pending = null;
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
    public function refresh(callable $fetch): \React\Promise\PromiseInterface
    {
        $now = ($this->clock)();
        if ($this->pending !== null) { return $this->pending->then(static fn (array $status): array => array_replace($status, ['coalesced' => true])); }
        if ($this->attempt !== null && $now - $this->attempt < self::COALESCE_SECONDS) {
            return \React\Promise\resolve($this->status() + ['coalesced' => true]);
        }
        $this->busy = true; $this->attempt = $now;
        $deferred = new \React\Promise\Deferred(); $this->pending = $deferred->promise(); $pending = $this->pending;
        $finish = function (bool $valid, ?array $records = null) use ($deferred): void {
            if ($valid) { $this->records = $records; $this->success = ($this->clock)(); }
            $this->failed = !$valid; $this->busy = false; $this->pending = null;
            $deferred->resolve($this->status() + ['coalesced' => false]);
        };
        try { $response = \React\Promise\resolve($fetch()); }
        catch (\Throwable) { $finish(false); return $pending; }
        $response->then(function (array $response) use ($finish): void {
            try {
                if (($response['http_status'] ?? null) !== 200 || !is_string($response['json'] ?? null)) { throw new \RuntimeException('Unavailable'); }
                $finish(true, HostedVerificationSource::validate($response['json']));
            } catch (\Throwable) { $finish(false); }
        }, static function () use ($finish): void { $finish(false); });
        return $pending;
    }
}
