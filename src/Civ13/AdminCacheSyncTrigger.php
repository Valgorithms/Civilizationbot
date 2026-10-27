<?php
declare(strict_types=1);
namespace Civ13;

use React\Promise\PromiseInterface;

/** Inactive cache-trusting trigger: no registration, Discord construction, HTTP or file defaults. */
final class AdminCacheSyncTrigger
{
    private \Closure $request;
    private \Closure $cacheAvailable;
    private \Closure $refreshCache;
    private \Closure $schedule;
    private \Closure $report;
    private \Closure $invalidateConfiguration;
    private bool $ready = false;
    private bool $initialized = false;
    private bool $cacheTrusted = false;
    private bool $configurationValid = true;
    private bool $stopped = false;
    private int $cacheEpoch = 0;
    private int $contextVersion = 0;
    private ?object $debounce = null;
    private ?object $refresh = null;
    private bool $pendingReconnect = false;
    private int $requestDepth = 0;
    private int $pendingCount = 0;
    private \WeakMap $observed;
    private ?array $listeners = null;

    /**
     * request(): dedicated coordinator promise; all triggers must enter this adapter.
     * cacheAvailable(): bool; verifies the configured guild and required caches exist.
     * refreshCache(): PromiseInterface; refresh both member and role caches, never a cache count guess.
     * schedule(delay,callback): callable one-shot timer cancellation.
     * report(array): observer, not a retry hook. invalidateConfiguration(): synchronous trusted config update.
     * Projector must compare captured cache_epoch with freshly read state() at every precommit.
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $guildId,
        callable $request,
        callable $cacheAvailable,
        callable $refreshCache,
        callable $schedule,
        callable $report,
        callable $invalidateConfiguration,
        private readonly float $debounceSeconds,
        private readonly float $refreshTimeoutSeconds
    ) {
        if (preg_match('/^[1-9][0-9]{16,19}$/D',$guildId)!==1
            || !is_finite($debounceSeconds) || $debounceSeconds<=0 || $debounceSeconds>5
            || !is_finite($refreshTimeoutSeconds) || $refreshTimeoutSeconds<=0 || $refreshTimeoutSeconds>300) {
            throw new \InvalidArgumentException('Explicit guild and bounded timers required');
        }
        $this->request=\Closure::fromCallable($request);
        $this->cacheAvailable=\Closure::fromCallable($cacheAvailable);
        $this->refreshCache=\Closure::fromCallable($refreshCache);
        $this->schedule=\Closure::fromCallable($schedule);
        $this->report=\Closure::fromCallable($report);
        $this->invalidateConfiguration=\Closure::fromCallable($invalidateConfiguration);
        $this->observed=new \WeakMap();
    }

    public function isReady(): bool { return $this->enabled && !$this->stopped && $this->configurationValid && $this->ready; }
    public function epoch(): int { return $this->cacheEpoch; }
    public function state(): array { return ['ready'=>$this->isReady(),'epoch'=>$this->cacheEpoch]; }

    /** Exact stable closures for caller-owned registration/removal. Cached prior roles never suppress events. */
    public function listeners(): array
    {
        if ($this->listeners!==null) { return $this->listeners; }
        $guildEvent=function (mixed $part,mixed $discord=null,mixed $old=null): void {
            if (!$this->enabled || $this->stopped) { return; }
            try { $guild=is_object($part)?($part->guild_id??null):null; }
            catch (\Throwable) { $this->status('event-shape'); return; }
            if ($guild!==$this->guildId) { return; }
            ++$this->cacheEpoch;
            $this->signal();
        };
        return $this->listeners=[
            'init'=>function (mixed $discord=null): void { $this->bootstrapReady(); },
            'reconnected'=>function (mixed $discord=null): void { $this->reconnect(); },
            'GUILD_MEMBER_ADD'=>$guildEvent,'GUILD_MEMBER_UPDATE'=>$guildEvent,'GUILD_MEMBER_REMOVE'=>$guildEvent,
            'GUILD_ROLE_CREATE'=>$guildEvent,'GUILD_ROLE_UPDATE'=>$guildEvent,'GUILD_ROLE_DELETE'=>$guildEvent,
        ];
    }

    /** Call explicitly only when normal Discord init/loadAllMembers completion is already known. */
    public function bootstrapReady(): void
    {
        if (!$this->enabled || $this->stopped || $this->initialized) { return; }
        $this->initialized=true;
        $epoch=++$this->cacheEpoch;
        $context=$this->contextVersion;
        $available=$this->available();
        $this->ready=$this->cacheTrusted=$available && !$this->stopped && $this->refresh===null
            && $epoch===$this->cacheEpoch && $context===$this->contextVersion;
        if (!$this->ready) { $this->status('initial-cache'); }
        $this->signal();
    }

    public function configurationChanged(): void
    {
        if (!$this->enabled || $this->stopped) { return; }
        $wasReady=$this->isReady();
        $this->ready=false; $this->configurationValid=false;
        $epoch=++$this->cacheEpoch; $context=++$this->contextVersion;
        $this->cancelDebounce();
        // Failure leaves readiness false and propagates to the explicit configuration owner.
        ($this->invalidateConfiguration)();
        // A nested configuration update/stop must not be overwritten by this older callback.
        if ($context===$this->contextVersion && !$this->stopped) { $this->configurationValid=true; }
        $available=$wasReady && $this->cacheTrusted && $this->refresh===null && $this->available();
        $this->ready=$available && !$this->stopped && $this->refresh===null
            && $epoch===$this->cacheEpoch && $context===$this->contextVersion;
        $this->signal();
    }

    public function stop(): void
    {
        if (!$this->enabled || $this->stopped) { return; }
        $this->stopped=true; $this->pendingReconnect=false; $this->ready=false; $this->cacheTrusted=false; ++$this->cacheEpoch; ++$this->contextVersion;
        $this->cancelDebounce();
        if ($this->refresh!==null) {
            $this->refresh->done=true;
            $this->cancelTimer($this->refresh);
        }
        // No transport cancellation claim. In-flight results cannot restore readiness.
    }

    private function reconnect(): void
    {
        if (!$this->enabled || $this->stopped) { return; }
        $this->initialized=true; // A later init callback cannot bypass a reconnect barrier.
        $this->ready=false; $this->cacheTrusted=false; ++$this->cacheEpoch; ++$this->contextVersion;
        if ($this->refresh!==null) {
            $this->pendingReconnect=true;
            $this->cancelDebounce(); $this->signal(); return; // One explicit reconnect waits for drain.
        }
        $this->beginRefresh();
    }

    private function beginRefresh(): void
    {
        if ($this->stopped || $this->refresh!==null) { return; }
        $state=(object)['done'=>false,'cancel'=>null,'outstanding'=>false,'context'=>$this->contextVersion];
        $this->refresh=$state; // Reserve before any injected callback can reenter.
        $this->cancelDebounce();
        $this->signal(); // Immediately dirties already-active coordinator work while the gate is closed.
        $fail=function (string $phase) use ($state): void {
            if ($state->done) { return; }
            $state->done=true; $this->ready=false;
            $this->cancelTimer($state);
            $this->status($phase);
            if (!$state->outstanding) { $this->releaseRefresh($state); }
        };
        try {
            if ($state->done || $this->stopped) { return; }
            $cancel=($this->schedule)($this->refreshTimeoutSeconds,fn()=>$fail('cache-refresh-deadline'));
            if (!is_callable($cancel)) { throw new \RuntimeException('Timer cancellation required'); }
            $state->cancel=\Closure::fromCallable($cancel);
            if ($state->done || $this->stopped) { $this->cancelTimer($state); return; }
            $state->outstanding=true;
            $promise=($this->refreshCache)();
            if (!$promise instanceof PromiseInterface) { throw new \RuntimeException('Refresh promise required'); }
            $promise->then(function (mixed $unused) use ($state,$fail): void {
                $state->outstanding=false;
                if ($state->done || $this->stopped || $state->context!==$this->contextVersion) {
                    $obsolete=!$state->done && !$this->stopped && $state->context!==$this->contextVersion;
                    $state->done=true; $this->cancelTimer($state);
                    if ($obsolete) { $this->status('cache-refresh-obsolete'); }
                    $this->releaseRefresh($state);
                    return;
                }
                $available=$this->available();
                if ($state->done || $this->stopped || $state->context!==$this->contextVersion) {
                    $obsolete=!$state->done && !$this->stopped && $state->context!==$this->contextVersion;
                    $state->done=true; $this->cancelTimer($state);
                    if ($obsolete) { $this->status('cache-refresh-obsolete'); }
                    $this->releaseRefresh($state);
                    return;
                }
                if (!$available) { $fail('refreshed-cache-unavailable'); return; }
                $state->done=true; $this->cancelTimer($state);
                if ($this->stopped || $state->context!==$this->contextVersion) {
                    if (!$this->stopped) { $this->status('cache-refresh-obsolete'); }
                    $this->releaseRefresh($state);
                    return;
                }
                $this->releaseRefresh($state);
                $this->initialized=true; $this->ready=true; $this->cacheTrusted=true; ++$this->cacheEpoch;
                $this->signal();
            },function (\Throwable $error) use ($state,$fail): void {
                $state->outstanding=false;
                if ($state->done) { $this->releaseRefresh($state); return; }
                $fail('cache-refresh-failed');
            });
        } catch (\Throwable) {
            $state->outstanding=false;
            if ($state->done) { $this->releaseRefresh($state); }
            $fail('cache-refresh-start');
        }
    }

    private function releaseRefresh(object $state): void
    {
        if ($this->refresh!==$state) { return; }
        $this->refresh=null;
        if ($this->pendingReconnect && !$this->stopped) {
            $this->pendingReconnect=false;
            $this->beginRefresh(); // Services one actually received reconnect, never a failure retry.
        }
    }

    private function available(): bool
    {
        if (!$this->configurationValid) { return false; }
        try { return ($this->cacheAvailable)()===true && $this->configurationValid; } catch (\Throwable) { return false; }
    }

    private function signal(): void
    {
        if (!$this->enabled || $this->stopped) { return; }
        if ($this->requestDepth>0 || $this->pendingCount>0) { $this->dispatch(); return; }
        if (!$this->isReady() || $this->debounce!==null) { return; }
        $slot=(object)['cancel'=>null]; $this->debounce=$slot;
        try {
            $cancel=($this->schedule)($this->debounceSeconds,function () use ($slot): void {
                if ($this->debounce!==$slot) { return; }
                $this->debounce=null;
                if ($this->isReady()) { $this->dispatch(); }
            });
            if (!is_callable($cancel)) { throw new \RuntimeException('Timer cancellation required'); }
            $slot->cancel=\Closure::fromCallable($cancel);
            if ($this->debounce!==$slot) { $this->cancelTimer($slot); }
        } catch (\Throwable) {
            if ($this->debounce===$slot) { $this->debounce=null; }
            $this->status('debounce-timer');
        }
    }

    private function dispatch(): void
    {
        if ($this->stopped) { return; }
        ++$this->requestDepth;
        try {
            $promise=($this->request)();
            if (!$promise instanceof PromiseInterface) { throw new \RuntimeException('Coordinator promise required'); }
        } catch (\Throwable) { $this->status('coordinator-request'); return; }
        finally { --$this->requestDepth; }
        if (isset($this->observed[$promise])) { return; }
        $this->observed[$promise]=true; ++$this->pendingCount;
        $promise->then(function (mixed $outcome): void {
            --$this->pendingCount;
            if (is_array($outcome)) { $this->publish($outcome); } else { $this->status('coordinator-outcome'); }
        },function (\Throwable $error): void { --$this->pendingCount; $this->status('coordinator-request'); });
    }

    private function cancelDebounce(): void
    {
        $slot=$this->debounce; $this->debounce=null;
        if ($slot!==null) { $this->cancelTimer($slot); }
    }

    private function cancelTimer(object $slot): void
    {
        $cancel=$slot->cancel; $slot->cancel=null;
        if ($cancel!==null) { try { $cancel(); } catch (\Throwable) {} }
    }

    private function status(string $phase): void { $this->publish(['state'=>'blocked','phase'=>$phase,'retry_required'=>true]); }
    private function publish(array $outcome): void { try { ($this->report)($outcome); } catch (\Throwable) {} }
}
