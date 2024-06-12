<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2023-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use Discord\Helpers\Collection;
use \ArrayIterator;
use \Traversable;

interface HandlerInterface
{
    public function get(): array;
    public function set(array $handlers): self;
    public function pull(int|string $index, ?callable $default = null): array;
    public function fill(array $commands, array $handlers): self;
    public function pushHandler(callable $callback, int|string|null $command = null): self;
    public function count(): int;
    public function first(): array;
    public function last(): array;
    public function isset(int|string $offset): bool;
    public function has(array ...$indexes): bool;
    public function filter(callable $callback): self;
    public function find(callable $callback): array;
    public function clear(): self;
    public function map(callable $callback): self;
    public function merge(object $handler): self;
    public function toArray(): array;
    public function offsetExists(int|string $offset): bool;
    public function offsetGet(int|string $offset): array;
    public function offsetSet(int|string $offset, callable $callback): self;
    public function getIterator(): Traversable;
    public function __debugInfo(): array;

    public function checkRank(?Collection $roles = null, array $allowed_ranks = []): bool;
}

namespace Civ13;

use Civ13\Interfaces\HandlerInterface;
use Discord\Discord;
use Discord\Helpers\Collection;
use Monolog\Logger;
use \ArrayIterator;
use \Traversable;

class Handler implements HandlerInterface
{
    public Civ13 $civ13;
    public Discord $discord;
    public Logger $logger;
    /**
      * @var callable[]
      */
    public array $handlers = [];
    
    public function __construct(Civ13 &$civ13, array $handlers = [])
    {
        $this->civ13 =& $civ13;
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
        $this->handlers = $handlers;
    }
    
    public function get(): array
    {
        return [$this->handlers];
    }
    
    public function set(array $handlers): self
    {
        $this->handlers = $handlers;
        return $this;
    }

    public function pull(int|string $index, ?callable $default = null): array
    {
        if (isset($this->handlers[$index])) {
            $default = $this->handlers[$index];
            unset($this->handlers[$index]);
        }

        return [$default];
    }

    public function fill(array $commands, array $handlers): self
    {
        if (count($commands) !== count($handlers)) {
            throw new \Exception('Commands and Handlers must be the same length.');
            return $this;
        }
        foreach ($handlers as $handler) $this->pushHandler($handler);
        return $this;
    }

    public function pushHandler(callable $callback, int|string|null $command = null): self
    {
        if ($command) $this->handlers[$command] = $callback;
        else $this->handlers[] = $callback;
        return $this;
    }

    public function count(): int
    {
        return count($this->handlers);
    }

    public function first(): array
    {
        return [array_shift(array_shift($this->toArray()) ?? [])];
    }
    
    public function last(): array
    {
        return [array_pop(array_shift($this->toArray()) ?? [])];
    }

    public function isset(int|string $offset): bool
    {
        return $this->offsetExists($offset);
    }
    
    public function has(array ...$indexes): bool
    {
        foreach ($indexes as $index)
            if (! isset($this->handlers[$index]))
                return false;
        return true;
    }
    
    public function filter(callable $callback): static
    {
        $static = new static($this->civ13, []);
        foreach ($this->handlers as $command => $handler)
            if ($callback($handler))
                $static->pushHandler($handler, $command);
        return $static;
    }
    
    public function find(callable $callback): array
    {
        foreach ($this->handlers as $handler)
            if ($callback($handler))
                return [$handler];
        return [];
    }

    public function clear(): self
    {
        $this->handlers = [];
        return $this;
    }

    public function map(callable $callback): static
    {
        return new static($this->civ13, array_combine(array_keys($this->handlers), array_map($callback, array_values($this->handlers))));
    }
    
    /**
     * @throws Exception if toArray property does not exist
     */
    public function merge(object $handler): self
    {
        if (! property_exists($handler, 'toArray')) {
            throw new \Exception('Handler::merge() expects parameter 1 to be an object with a method named "toArray", ' . gettype($handler) . ' given');
            return $this;
        }
        $toArray = $handler->toArray();
        $this->handlers = array_merge($this->handlers, array_shift($toArray));
        return $this;
    }
    
    public function toArray(): array
    {
        return [$this->handlers];
    }
    
    public function offsetExists(int|string $offset): bool
    {
        return isset($this->handlers[$offset]);
    }

    public function offsetGet(int|string $offset): array
    {
        return [$this->handlers[$offset] ?? null];
    }
    
    public function offsetSet(int|string $offset, callable $callback): self
    {
        $this->handlers[$offset] = $callback;
        return $this;
    }

    public function setOffset(int|string $newOffset, callable $callback): self
    {
        if ($offset = $this->getOffset($callback) === false) $offset = $newOffset;
        unset($this->handlers[$offset]);
        $this->handlers[$newOffset] = $callback;
        return $this;
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
        return ['handlers' => array_keys($this->handlers)];
    }

    public function checkRank(?Collection $roles = null, array $allowed_ranks = []): bool
    {
        if (empty($allowed_ranks)) return true;
        $resolved_ranks = [];
        foreach ($allowed_ranks as $rank) if (isset($this->civ13->role_ids[$rank])) $resolved_ranks[] = $this->civ13->role_ids[$rank];
        foreach ($roles as $role) if (in_array($role->id, $resolved_ranks)) return true;
        return false;
    }
}