<?php
declare(strict_types=1);
namespace Civ13;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Explicit supervision contract. A supervisor, never a second PHP, owns replacement. */
final class RestartPolicy
{
    private bool $requested = false;
    public function __construct(private string $mode = 'legacy') { if (!in_array($mode, ['legacy', 'supervised'], true)) { throw new \InvalidArgumentException('Invalid restart mode'); } }
    public function restart(?string $file, callable $stop, callable $spawn): PromiseInterface
    {
        if ($this->mode === 'supervised') {
            if ($file !== null) { throw new \InvalidArgumentException('Supervised restart cannot select an entry point'); }
            if (!$this->requested) { $stop(); $this->requested = true; }
            return resolve(null);
        }
        $stop(); return $spawn($file);
    }
}
