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
    public function pull(int|string $offset, ?callable $default = null): array;
    public function push(callable $callback, int|string|null $offset = null): self;
    public function fill(array $handlers): self;

    //public function get(string $name): mixed;
    //public function set(string $name, mixed $value): self;
    //public function push(null|int|string $name, mixed $value): self;
    //public function pushItems(null|int|string $name, mixed ...$items): self;
    //public function pull(int|string $name, mixed $default = null): mixed;
    //public function fill(array $values): self;
    //public function clear(): self;

    // Count and Access
    //public function count(null|int|string $name): int;
    public function first(null|int|string $name = null): mixed;
    public function last(null|int|string $name = null): mixed;

    // Existence Checks
    public function isset(int|string $offset): bool;
    public function has(array ...$offsets): bool;

    // Search and Filter
    public function find(callable $callback): array;
    //public function filter(callable $callback): self;
    public function map(callable $callback): self;

    // Merge and Offset Operations
    public function merge(object $handler): self;
    //public function offsetExists(int|string $offset): bool;
    public function offsetGet(int|string $offset, ?string $name = null): mixed;
    public function offsetSet(int|string $offset, callable $callback): self;
    public function offsetSets(array $offsets, callable $callback): self;
    public function getOffset(callable $callback): int|string|false;
    public function setOffset(int|string $newOffset, callable $callback): self;

    // Handler Operations
    public function getHandler(int|string $offset): ?callable;
    public function pushHandler(callable $callback, int|string|null $offset = null): self;
    public function pushHandlers(array $handlers): self;
    public function pullHandler(null|int|string $offset = null, mixed $default = null): mixed;
    public function fillHandlers(array $items): self;
    public function clearHandlers(): self;
    
    // Message Handler Operations
    //public function getmessageHandler();
    //public function pushMessageHandler();
    //public function pullMessageHandler();
    //public function fillMessageHandlers();
    //public function clearMessageHandlers();
    
    // Iterator and Conversion
    public function getIterator(): \Traversable;
    public function toArray(): array;

    // Debugging
    public function __debugInfo(): array;
    
    public function handle(Message $message): ?PromiseInterface;
    public function validate(callable $callback): callable;
}