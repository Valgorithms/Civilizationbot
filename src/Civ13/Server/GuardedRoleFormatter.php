<?php
declare(strict_types=1);
namespace Civ13\Server;

/** Per-call formatter and final predicate; preserves the existing callable-only writer API. */
final class GuardedRoleFormatter
{
    private readonly \Closure $format;
    private readonly \Closure $guard;

    public function __construct(callable $format, callable $guard)
    {
        $this->format = \Closure::fromCallable($format);
        $this->guard = \Closure::fromCallable($guard);
    }

    public function __invoke(...$args): string { return ($this->format)(...$args); }
    public function beforeWrite(): void { ($this->guard)(); }
}
