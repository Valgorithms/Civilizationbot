<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2023-present Valithor Obsidion <valithor@valzargaming.com>
 */

 namespace Civ13\Interfaces;

use Civ13\Handler;
use \ArrayIterator;
use \Traversable;

interface HandlerInterface
{
    public function get(): array;
    public function set(array $handlers): void;
    public function pull($key, $default = null): ?callable;
    public function fill(array $handlers): static;
    public function push(...$handlers): static;
    public function pushHandler(callable $callback): ?static;
    public function count(): int;
    public function first(): ?callable;
    public function last(): ?callable;
    public function isset($offset): bool;
    public function has(...$keys): bool;
    public function filter(callable $callback): static;
    public function find(callable $callback): ?callable;
    public function clear(): void;
    public function map(callable $callback): static;
    public function merge($handler): static;
    public function toArray(): array;
    public function offsetExists($offset): bool;
    public function offsetGet(string $key): ?callable;
    public function offsetSet(string $key, callable $callback): void;
    public function setOffset(int|string $newIndex, callable $callback): bool;
    public function getOffset(callable $callback): int|string|false;
    public function getIterator(): Traversable;
}

namespace Civ13;

use Civ13\Interfaces\HandlerInterface;
use \ArrayIterator;
use \Traversable;

class Handler implements HandlerInterface
{
    protected Civ13 $civ13;
    protected array $handlers = [];
    
    public function __construct(Civ13 &$civ13, array $handlers = [])
    {
        $this->civ13 = $civ13;
        $this->handlers = $handlers;
    }
    
    public function get(): array
    {
        return $this->handlers;
    }
    
    public function set(array $handlers): void
    {
        $this->handlers = $handlers;
    }

    public function pull($key, $default = null): ?callable
    {
        if (isset($this->handlers[$key])) {
            $default = $this->handlers[$key];
            unset($this->handlers[$key]);
        }

        return $default;
    }

    public function fill(array $handlers): static
    {
        foreach ($handlers as $handler) $this->pushHandler($handler);
        return $this;
    }

    public function push(...$handlers): static
    {
        foreach ($handlers as $handler)
            $this->pushHandler($handler);
        return $this;
    }

    public function pushHandler(callable $callback): ?static
    {
        $this->handlers[] = $callback;
        return $this;
    }

    public function count(): int
    {
        return count($this->handlers);
    }

    public function first(): ?callable
    {
        foreach ($this->handlers as $handler) return $handler;
        return null;
    }
    
    public function last(): ?callable
    {
        if ($last = end($this->handlers) !== false) {
            reset($this->handlers);
            return $last;
        }
        return null;
    }

    public function isset($offset): bool
    {
        return $this->offsetExists($offset);
    }
    
    public function has(...$keys): bool
    {
        foreach ($keys as $key)
            if (! isset($this->handlers[$key]))
                return false;

        return true;
    }
    
    public function filter(callable $callback): static
    {
        $static = new static($this->civ13, []);
        foreach ($this->handlers as $handler)
            if ($callback($handler))
                $static->push($handler);
        return $static;
    }
    
    public function find(callable $callback): ?callable
    {
        foreach ($this->handlers as $handler)
            if ($callback($handler))
                return $handler;
        return null;
    }

    public function clear(): void
    {
        $this->handlers = [];
    }

    public function map(callable $callback): static
    {
        return new static($this->civ13, array_combine(array_keys($this->handlers), array_map($callback, array_values($this->handlers))));
    }
    
    public function merge($handler): static
    {
        $this->handlers = array_merge($this->handlers, $handler->toArray());
        return $this;
    }
    
    public function toArray(): array
    {
        return $this->handlers;
    }
    
    public function offsetExists($offset): bool
    {
        return isset($this->handlers[$offset]);
    }

    public function offsetGet(string $key): ?callable
    {
        if (isset($this->handlers[$key])) return $this->handlers[$key];
        return null;
    }
    
    public function offsetSet(string $key, callable $callback): void
    {
        $this->handlers[$key] = $callback;
    }

    public function setOffset(int|string $newIndex, callable $callback): bool
    {
        
        if ($index = $this->getOffset($callback) === false) return false;
        unset($this->handlers[$index]);
        $this->handlers[$newIndex] = $callback;
        return true;
    }
    
    public function getOffset(callable $callback): int|string|false
    {
        return array_search($callback, $this->handlers);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->handlers);
    }

    public function __debugInfo(): array
    {
        return ['civ13' => isset($this->civ13) ? $this->civ13 instanceof Civ13 : false, 'handlers' => array_keys($this->handlers)];
    }
}