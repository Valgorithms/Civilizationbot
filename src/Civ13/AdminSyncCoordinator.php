<?php
declare(strict_types=1);
namespace Civ13;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Injected orchestration only; no default Discord, HTTP, filesystem or runtime wiring. */
final class AdminSyncCoordinator
{
    private ?PromiseInterface $pending = null;
    private ?Deferred $deferred = null;
    private int $generation = 0;
    private int $passes = 0;
    private int $writes = 0;
    private ?int $lastWrittenGeneration = null;
    private bool $dirty = false;
    private \Closure $refresh;
    private \Closure $members;
    private \Closure $project;
    private \Closure $write;

    /**
     * refresh(): array{status:array,records:array}|PromiseInterface
     * members(generation): array|PromiseInterface (complete guild snapshot, never a cache guess)
     * project(snapshot,membership,generation): string (must perform all snapshot/policy gates)
     * write(bytes,generation,canCommit): array{state:string}|PromiseInterface
     *   state must be updated, unchanged or superseded; check canCommit immediately before committing; no await between check and mutation.
     * Each asynchronous adapter must enforce its own deadline. This class bounds concurrent
     * calls and retry passes, not wall time; it cannot cancel/undo an entered file write.
     */
    public function __construct(
        private readonly bool $enabled,
        callable $refresh,
        callable $members,
        callable $project,
        callable $write,
        private readonly int $maxPasses = 3
    ) {
        if ($maxPasses < 1 || $maxPasses > 10) { throw new \InvalidArgumentException('Expected one to ten reconciliation passes'); }
        $this->refresh = \Closure::fromCallable($refresh);
        $this->members = \Closure::fromCallable($members);
        $this->project = \Closure::fromCallable($project);
        $this->write = \Closure::fromCallable($write);
    }

    /** All requests during a burst share the final outcome; each invalidates older work. */
    public function request(): PromiseInterface
    {
        if (!$this->enabled) {
            return resolve(['state'=>'disabled','generation'=>$this->generation,'passes'=>0,'writes'=>0,
                'last_written_generation'=>null,'retry_required'=>false,'integrity_uncertain'=>false,'phase'=>'disabled']);
        }
        ++$this->generation;
        if ($this->pending !== null) { $this->dirty = true; return $this->pending; }
        $this->passes = 0; $this->writes = 0; $this->lastWrittenGeneration = null;
        $this->deferred = new Deferred();
        $this->pending = $this->deferred->promise(); $promise = $this->pending;
        $this->startPass();
        return $promise;
    }

    private function startPass(): void
    {
        ++$this->passes; $this->dirty = false;
        $generation = $this->generation;
        $this->call($this->refresh)->then(function (mixed $snapshot) use ($generation): void {
            if (!$this->current($generation)) { $this->completePass('superseded','refresh'); return; }
            if (!is_array($snapshot) || !is_array($snapshot['records'] ?? null)
                || !is_array($snapshot['status'] ?? null)
                || ($snapshot['status']['available'] ?? null) !== true
                || ($snapshot['status']['stale'] ?? null) !== false
                || ($snapshot['status']['has_valid_list'] ?? null) !== true
                || ($snapshot['status']['refresh_in_progress'] ?? null) !== false) {
                $this->completePass('blocked','refresh'); return;
            }
            $this->call($this->members, $generation)->then(function (mixed $membership) use ($snapshot, $generation): void {
                if (!$this->current($generation)) { $this->completePass('superseded','members'); return; }
                try {
                    // The injected pure projector must validate complete, current and fresh inputs.
                    $bytes = ($this->project)($snapshot, $membership, $generation);
                    if (!is_string($bytes)) { throw new \UnexpectedValueException('Expected projected bytes'); }
                } catch (\Throwable) { $this->completePass('blocked','projection'); return; }
                if (!$this->current($generation)) { $this->completePass('superseded','projection'); return; }
                $canCommit = function () use ($snapshot, $membership, $generation, $bytes): bool {
                    if (!$this->current($generation)) { return false; }
                    try {
                        // Re-run the pure gate with the adapter's current clock, not an old age.
                        $freshBytes = ($this->project)($snapshot, $membership, $generation);
                    } catch (\Throwable) { return false; }
                    return $this->current($generation) && is_string($freshBytes) && $freshBytes === $bytes;
                };
                $this->call($this->write, $bytes, $generation, $canCommit)->then(function (mixed $result) use ($generation): void {
                    $state = is_array($result) ? ($result['state'] ?? null) : null;
                    if (!in_array($state, ['updated','unchanged','superseded'], true)) {
                        $this->finish('failed','write',true); return;
                    }
                    if ($state === 'updated') { ++$this->writes; $this->lastWrittenGeneration = $generation; }
                    $this->completePass($state,'write');
                }, function (\Throwable $error): void {
                    // A writer may have partially changed bytes. Do not silently retry it.
                    $this->finish('failed','write',true);
                });
            }, function (\Throwable $error): void { $this->completePass('blocked','members'); });
        }, function (\Throwable $error): void { $this->completePass('blocked','refresh'); });
    }

    private function current(int $generation): bool
    {
        return $this->pending !== null && $this->generation === $generation && !$this->dirty;
    }

    /** Convert synchronous adapter exceptions and asynchronous rejections to one path. */
    private function call(\Closure $adapter, mixed ...$arguments): PromiseInterface
    {
        try { return resolve($adapter(...$arguments)); }
        catch (\Throwable $error) { return \React\Promise\reject($error); }
    }

    private function completePass(string $state, string $phase): void
    {
        if ($this->dirty) {
            if ($this->passes < $this->maxPasses) { $this->startPass(); return; }
            $this->finish('superseded',$phase); return;
        }
        $this->finish($state,$phase);
    }

    private function finish(string $state, string $phase, bool $integrityUncertain = false): void
    {
        $deferred = $this->deferred;
        $result = ['state'=>$state,'generation'=>$this->generation,'passes'=>$this->passes,'writes'=>$this->writes,
            'last_written_generation'=>$this->lastWrittenGeneration,'retry_required'=>$this->dirty || $state === 'superseded',
            'integrity_uncertain'=>$integrityUncertain,'phase'=>$phase];
        // Clear before resolving: subscribers may synchronously request a new burst.
        $this->pending = null; $this->deferred = null; $this->dirty = false;
        $deferred?->resolve($result);
    }
}
