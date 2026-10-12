<?php
declare(strict_types=1);
namespace Civ13\Safehouse;
use React\EventLoop\LoopInterface;
final class ReactTimer
{
    public static function repeat(LoopInterface $loop): \Closure
    {
        return static function (float $interval, callable $callback) use ($loop): \Closure {
            $timer = $loop->addPeriodicTimer($interval, $callback);
            return static fn () => $loop->cancelTimer($timer);
        };
    }
}
