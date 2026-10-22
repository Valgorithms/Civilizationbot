<?php
declare(strict_types=1);
namespace Civ13\Safehouse;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/** Inject repeat(interval, callback) -> cancellation callable; ReactTimer supplies
 * the production implementation. Finite polling never blocks Discord's loop. */
final class AsyncDriver implements AsyncGateway
{
    private array $active = [];
    public function __construct(private AsyncSpool $spool, private \Closure $repeat, private int $maxPolls = 40, private int $capacity = 64)
    {
        if ($maxPolls < 1 || $maxPolls > 240 || $capacity < 1 || $capacity > 1000) { throw new \InvalidArgumentException('Invalid async polling bounds'); }
    }

    public function submit(array $request): PromiseInterface
    {
        $identity = $request['request_id'];
        $bytes = json_encode($request, JSON_THROW_ON_ERROR);
        if (isset($this->active[$identity])) {
            if ($this->active[$identity]['bytes'] !== $bytes) { throw new \LogicException('Active request collision'); }
            return $this->active[$identity]['promise'];
        }
        if (count($this->active) >= $this->capacity) { throw new \RuntimeException('Async request capacity reached'); }
        $id = $this->spool->enqueue($request);
        $deferred = new Deferred();
        $promise = $deferred->promise();
        $this->active[$identity] = ['bytes' => $bytes, 'promise' => $promise];
        $polls = 0;
        $cancel = null;
        $tick = function () use ($id, $identity, $deferred, &$polls, &$cancel): void {
            try {
                $outcome = $this->spool->poll($id);
                if ($outcome['status'] === 'pending' && ++$polls < $this->maxPolls) { return; }
                if ($cancel !== null) { $cancel(); }
                unset($this->active[$identity]);
                $deferred->resolve($outcome);
            } catch (\Throwable $error) {
                if ($cancel !== null) { $cancel(); }
                unset($this->active[$identity]);
                $deferred->reject($error);
            }
        };
        try {
            $cancel = ($this->repeat)(0.25, $tick);
            if (!is_callable($cancel)) { throw new \LogicException('Timer cancellation callable required'); }
        } catch (\Throwable $error) {
            unset($this->active[$identity]);
            $deferred->reject($error);
        }
        return $promise;
    }
}
