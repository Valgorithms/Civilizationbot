<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use Discord\Parts\Channel\Message;
use Handler\HandlerInterface;
use React\Promise\PromiseInterface;

interface MessageHandlerInterface extends HandlerInterface
{
    // Basic CRUD Operations
    public function get(): array;
    public function set(array $handlers): self;
    public function pull(int|string $offset, ?callable $default = null): array;
    public function push(callable $callback, int|string|null $offset = null): self;
    public function fill(array $handlers): self;
    public function clear(): self;

    // Count and Access
    public function count(): int;
    public function first(): array;
    public function last(): array;

    // Existence Checks
    public function isset(int|string $offset): bool;
    public function has(array ...$offsets): bool;

    // Search and Filter
    public function find(callable $callback): array;
    public function filter(callable $callback): self;
    public function map(callable $callback): self;

    // Merge and Offset Operations
    public function merge(object $handler): self;
    public function offsetExists(int|string $offset): bool;
    public function offsetGet(int|string $offset): array;
    public function offsetSet(int|string $offset, callable $callback): self;
    public function offsetSets(array $offsets, callable $callback): self;
    public function getOffset(callable $callback): int|string|false;
    public function setOffset(int|string $newOffset, callable $callback): self;

    // Iterator and Conversion
    public function getIterator(): \Traversable;
    public function toArray(): array;

    // Debugging
    public function __debugInfo(): array;
    
    public function handle(Message $message): ?PromiseInterface;
    public function validate(callable $callback): callable;

}