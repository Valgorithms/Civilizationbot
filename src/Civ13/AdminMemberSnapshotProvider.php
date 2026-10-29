<?php
declare(strict_types=1);
namespace Civ13;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

/** Completed bounded pagination, not an atomic Discord snapshot. No real adapters. */
final class AdminMemberSnapshotProvider
{
    private bool $busy = false;
    private \Closure $fetchRoles;
    private \Closure $fetchPage;
    private \Closure $observe;
    private \Closure $clock;
    private \Closure $deadline;

    /**
     * fetchRoles(guild): list<string>|PromiseInterface (normalized role IDs).
     * fetchPage(guild,after,limit): list<array{id:string,roles:list<string>}>|PromiseInterface.
     * observe(guild): {guild_id:string,connected:bool,epoch:int,generation:int}.
     * clock(): monotonic finite seconds; deadline(delay,expire): callable cancellation.
     * Deadline cancellation cancels only the injected timer, NEVER the transport.
     */
    public function __construct(
        callable $fetchRoles,
        callable $fetchPage,
        callable $observe,
        callable $clock,
        callable $deadline,
        private readonly int $maxPages,
        private readonly int $maxMembers,
        private readonly float $timeoutSeconds
    ) {
        if ($maxPages < 1 || $maxPages > 1000 || $maxMembers < 0 || $maxMembers > 1000000
            || !is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds > 300) {
            throw new \InvalidArgumentException('Invalid explicit snapshot limits');
        }
        $this->fetchRoles = \Closure::fromCallable($fetchRoles);
        $this->fetchPage = \Closure::fromCallable($fetchPage);
        $this->observe = \Closure::fromCallable($observe);
        $this->clock = \Closure::fromCallable($clock);
        $this->deadline = \Closure::fromCallable($deadline);
    }

    public function snapshot(string $guildId, int $generation): PromiseInterface
    {
        if ($this->busy) { return reject(new \RuntimeException('Membership provider is busy or draining')); }
        if (!self::id($guildId) || $generation < 0) { return reject(new \InvalidArgumentException('Invalid snapshot identity')); }
        $this->busy = true;
        $state = (object)['done'=>false,'outstanding'=>false,'cancel'=>null,'started'=>null,'lastTime'=>null,
            'epoch'=>null,'roles'=>[],'members'=>[],'pages'=>0,'after'=>'0'];
        $deferred = new Deferred(); $promise = $deferred->promise();
        $finish = function (?array $result, string $reason = '') use ($state, $deferred): void {
            if ($state->done) { return; }
            $state->done = true;
            if ($state->cancel !== null) { try { ($state->cancel)(); } catch (\Throwable) {} }
            // A timed-out request may still run in its transport. Do not overlap it.
            if (!$state->outstanding) { $this->busy = false; }
            $result === null ? $deferred->reject(new \RuntimeException($reason)) : $deferred->resolve($result);
        };
        $check = function () use ($state, $guildId, $generation): float {
            $now = ($this->clock)();
            if ((!is_int($now) && !is_float($now)) || !is_finite((float)$now)
                || ($state->lastTime !== null && $now < $state->lastTime)) {
                throw new \RuntimeException('Invalid monotonic snapshot clock');
            }
            $state->lastTime = (float)$now;
            if ($state->started !== null && $now - $state->started >= $this->timeoutSeconds) {
                throw new \RuntimeException('Membership snapshot deadline exceeded');
            }
            $life = ($this->observe)($guildId);
            if (!is_array($life) || ($life['guild_id'] ?? null) !== $guildId
                || ($life['connected'] ?? null) !== true || ($life['generation'] ?? null) !== $generation
                || !is_int($life['epoch'] ?? null) || $life['epoch'] < 0
                || ($state->epoch !== null && $life['epoch'] !== $state->epoch)) {
                throw new \RuntimeException('Membership lifecycle changed or unavailable');
            }
            $state->epoch = $life['epoch'];
            return (float)$now;
        };
        // Every callback is converted to a promise; late results only release the busy gate.
        $invoke = function (\Closure $operation, callable $accept) use ($state, $finish, $check): void {
            if ($state->done) { return; }
            try { $check(); } catch (\Throwable) { $finish(null,'Membership snapshot no longer current'); return; }
            if ($state->done) { return; }
            $state->outstanding = true;
            try { $request = resolve($operation()); }
            catch (\Throwable $error) { $request = reject($error); }
            $request->then(function (mixed $value) use ($state, $finish, $check, $accept): void {
                $state->outstanding = false;
                if ($state->done) { $this->busy = false; return; }
                try { $check(); if (!$state->done) { $accept($value); } }
                catch (\Throwable) { $finish(null,'Invalid or obsolete membership response'); }
            }, function (\Throwable $error) use ($state, $finish): void {
                $state->outstanding = false;
                if ($state->done) { $this->busy = false; return; }
                $finish(null,'Membership request failed');
            });
        };
        $page = null;
        $page = function () use (&$page, $invoke, $state, $finish, $check, $guildId, $generation): void {
            if ($state->done) { return; }
            if ($state->pages >= $this->maxPages) { $finish(null,'Membership page limit reached before terminal empty page'); return; }
            ++$state->pages;
            $invoke(fn () => ($this->fetchPage)($guildId,$state->after,1000),
                function (mixed $rows) use (&$page, $invoke, $state, $finish, $check, $guildId, $generation): void {
                    if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1000) { throw new \RuntimeException('Expected bounded member page'); }
                    if ($rows === []) {
                        $invoke(fn () => ($this->fetchRoles)($guildId), function (mixed $roles) use ($state, $finish, $check, $guildId, $generation): void {
                            if (self::roles($roles) !== $state->roles) { throw new \RuntimeException('Guild roles changed during pagination'); }
                            $completed = $check();
                            $members = array_values($state->members);
                            usort($members, static fn (array $a, array $b): int => self::compare($a['id'],$b['id']));
                            $finish(['guild_id'=>$guildId,'connected'=>true,'complete'=>true,'generation'=>$generation,
                                'epoch'=>$state->epoch,'roles'=>$state->roles,'members'=>$members,'pages'=>$state->pages,
                                'started_at'=>$state->started,'completed_at'=>$completed]);
                        });
                        return;
                    }
                    if (count($state->members) + count($rows) > $this->maxMembers) { throw new \RuntimeException('Membership size limit exceeded'); }
                    $next = $state->after;
                    foreach ($rows as $row) {
                        if (!is_array($row) || !self::id($row['id'] ?? null)
                            || self::compare($row['id'],$state->after) <= 0 || isset($state->members['id:'.$row['id']])) {
                            throw new \RuntimeException('Invalid, duplicate or nonprogressing member');
                        }
                        $roles = self::roles($row['roles'] ?? null);
                        if (array_diff($roles,$state->roles) !== []) { throw new \RuntimeException('Member refers to unknown guild role'); }
                        $state->members['id:'.$row['id']] = ['id'=>$row['id'],'roles'=>$roles];
                        if (self::compare($row['id'],$next) > 0) { $next = $row['id']; }
                    }
                    $state->after = $next;
                    $page();
                });
        };
        try {
            $state->started = $check();
            $cancel = ($this->deadline)($this->timeoutSeconds, fn () => $finish(null,'Membership snapshot deadline exceeded'));
            if (!is_callable($cancel)) { throw new \RuntimeException('Deadline cancellation callback required'); }
            $state->cancel = \Closure::fromCallable($cancel);
            if ($state->done) { ($state->cancel)(); return $promise; }
            $invoke(fn () => ($this->fetchRoles)($guildId), function (mixed $roles) use ($state, &$page): void {
                $state->roles = self::roles($roles);
                $page();
            });
        } catch (\Throwable) { $finish(null,'Membership snapshot initialization failed'); }
        return $promise;
    }

    private static function roles(mixed $roles): array
    {
        if (!is_array($roles) || !array_is_list($roles) || count($roles) > 1000) { throw new \RuntimeException('Invalid role snapshot'); }
        $seen = [];
        foreach ($roles as $role) {
            if (!self::id($role) || isset($seen['id:'.$role])) { throw new \RuntimeException('Invalid or duplicate role'); }
            $seen['id:'.$role] = true;
        }
        sort($roles,SORT_STRING);
        return $roles;
    }

    private static function id(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{16,19}$/D',$value) === 1;
    }

    private static function compare(string $left, string $right): int
    {
        return (strlen($left) <=> strlen($right)) ?: strcmp($left,$right);
    }
}
