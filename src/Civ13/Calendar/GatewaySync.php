<?php
declare(strict_types=1);
namespace Civ13\Calendar;

use React\Promise\PromiseInterface;
use function React\Promise\all;
use function React\Promise\resolve;

/** Existing-client consumer. No token loading, second Gateway, member fetch or polling loop. */
final class GatewaySync
{
    private int $epoch = 0;
    private bool $busy = false;
    private bool $dirty = false;
    private bool $stopped = false;
    private bool $suspended = false;
    private bool $trusted = false;
    private mixed $timer = null;
    private mixed $queued = null;
    private array $listeners = [];
    private \Closure $refresh;
    private \Closure $clock;
    private \Closure $current;
    public function __construct(private object $discord, private object $loop, private Ledger $ledger,
        private string $guild, private array $onboarding, callable $refresh, callable $current, callable $clock)
    {
        $this->refresh = \Closure::fromCallable($refresh); $this->current = \Closure::fromCallable($current); $this->clock = \Closure::fromCallable($clock);
        $this->ledger->invalidate(($this->clock)()); // Never resurrect a previous process's lease.
        $this->listeners = [
            'init' => fn () => $this->ready(), 'reconnected' => fn () => $this->ready(),
            'closed' => fn () => $this->suspend(),
            'raw' => fn (object $payload) => $this->raw($payload),
            'heartbeat-ack' => fn () => $this->ack(),
            'GUILD_CREATE' => function (object $guild): void { if (($guild->id ?? null) === $this->guild) { $this->ready(); } },
        ];
        foreach ($this->listeners as $event => $listener) { $this->discord->on($event, $listener); }
    }
    public function ready(): void { if (!$this->stopped) { $this->suspended = false; $this->signal(); } }
    private function clear(): void
    {
        ++$this->epoch; $this->trusted = false;
        try { $this->ledger->invalidate(($this->clock)()); } catch (\Throwable) { /* Ledger's read gate remains closed after write failure. */ }
    }
    private function suspend(): void { $this->suspended = true; $this->clear(); }
    private function raw(object $payload): void
    {
        if ($this->stopped) { return; }
        if (in_array($payload->op ?? null, [7, 9], true)) { $this->suspend(); return; }
        if (($payload->op ?? null) !== 0 || !is_string($payload->t ?? null)) { return; }
        $type = $payload->t; $data = $payload->d ?? null;
        if ($type === 'GUILD_DELETE' && ($data->id ?? null) === $this->guild) { $this->suspend(); return; }
        if ($type === 'GUILD_UPDATE' && ($data->id ?? null) === $this->guild) { $this->signal(); return; }
        $affected = ['CHANNEL_CREATE', 'CHANNEL_UPDATE', 'CHANNEL_DELETE', 'GUILD_ROLE_CREATE', 'GUILD_ROLE_UPDATE', 'GUILD_ROLE_DELETE',
            'GUILD_SCHEDULED_EVENT_CREATE', 'GUILD_SCHEDULED_EVENT_UPDATE', 'GUILD_SCHEDULED_EVENT_DELETE',
            'GUILD_SCHEDULED_EVENT_EXCEPTION_CREATE', 'GUILD_SCHEDULED_EVENT_EXCEPTION_UPDATE', 'GUILD_SCHEDULED_EVENT_EXCEPTION_DELETE'];
        if (in_array($type, $affected, true) && (($data->guild_id ?? null) === $this->guild || !isset($data->guild_id))) { $this->signal(); }
    }
    private function signal(): void
    {
        if ($this->stopped) { return; }
        $this->clear(); $this->dirty = true;
        if ($this->busy || $this->queued !== null || $this->suspended) { return; }
        $this->queued = $this->loop->addTimer(0.2, function (): void { $this->queued = null; $this->begin(); });
    }
    private function ack(): void
    {
        if ($this->stopped || $this->suspended) { return; }
        if (!(($this->current)())) { $this->suspend(); return; }
        if ($this->trusted && !$this->busy && !$this->dirty) {
            if (($this->ledger->snapshot(($this->clock)())['state'] ?? 'stale') === 'stale') { $this->signal(); return; }
            try { $this->ledger->renew(($this->clock)()); } catch (\Throwable) { $this->suspend(); }
        }
    }
    private function begin(): void
    {
        if ($this->stopped || $this->busy || $this->suspended || !(($this->current)())) { $this->clear(); return; }
        $this->busy = true; $this->dirty = false; $epoch = $this->epoch;
        $timedOut = false;
        $this->timer = $this->loop->addTimer(30, function () use (&$timedOut): void { $timedOut = true; $this->timer = null; $this->clear(); });
        try { $promise = ($this->refresh)(); if (!$promise instanceof PromiseInterface) { throw new \RuntimeException('Refresh promise required'); } }
        catch (\Throwable) { $this->finish($epoch, true, null); return; }
        $promise->then(function (array $data) use ($epoch, &$timedOut): void { $this->finish($epoch, $timedOut, $data); },
            function () use ($epoch): void { $this->finish($epoch, true, null); });
    }
    private function finish(int $epoch, bool $failed, ?array $data): void
    {
        if ($this->timer !== null) { $this->loop->cancelTimer($this->timer); $this->timer = null; }
        $this->busy = false;
        if (!$this->stopped && !$this->suspended && !$failed && $epoch === $this->epoch && ($this->current)()) {
            try {
                $projection = Projection::events($this->guild, $data['roles'], $data['channels'], $data['events'], $this->onboarding);
                $this->ledger->replace($projection, ($this->clock)()); $this->trusted = true;
            } catch (\Throwable) { $this->clear(); }
        }
        if ($this->dirty && !$this->suspended && !$this->stopped) { $this->signal(); }
    }
    public function stop(): void
    {
        if ($this->stopped) { return; }
        $this->stopped = true; $this->clear();
        foreach ($this->listeners as $event => $listener) { $this->discord->removeListener($event, $listener); }
        foreach ([$this->timer, $this->queued] as $timer) { if ($timer !== null) { $this->loop->cancelTimer($timer); } }
        $this->timer = $this->queued = null;
    }

    /** Freshen through the existing repositories, wait for every branch to drain, then use strong cache Parts. */
    public static function refresh(object $guild): PromiseInterface
    {
        $pending = [];
        foreach (['roles' => 'roles', 'channels' => 'channels', 'events' => 'guild_scheduled_events'] as $key => $name) {
            try {
            $repository = $guild->{$name};
            $pending[$key] = $repository->freshen($key === 'events' ? ['with_user_count' => false] : [])
                ->then(fn () => $repository->cache->getMultiple($repository->keys()))
                ->then(static function (array $parts): array {
                    return ['ok' => true, 'rows' => array_values(array_map(static fn (object $part): array => $part->getRawAttributes(), $parts))];
                })->then(null, static fn (): array => ['ok' => false]);
            } catch (\Throwable) { $pending[$key] = resolve(['ok' => false]); }
        }
        return all($pending)->then(static function (array $results): array {
            $data = [];
            foreach ($results as $key => $result) { if (!$result['ok']) { throw new \RuntimeException('Calendar inventory unavailable'); } $data[$key] = $result['rows']; }
            return $data;
        });
    }
}
