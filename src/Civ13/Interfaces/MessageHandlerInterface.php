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
    // Item Operations
    //public function pull(int|string $name, mixed $default = null): mixed;
    public function pull(int|string $index, ?callable $defaultCallables = null, array $default_required_permissions = null, array $default_match_methods = null, array $default_descriptions = null): array;
    public function fill(array $values): self;
    //public function clear(): self;
    public function clear(): void;

    // Count and Access
    public function first(null|int|string $name = null): mixed;
    public function last(null|int|string $name = null): mixed;

    // Existence Checks
    public function isset(int|string $offset): bool;
    public function has(array ...$offsets): bool;

    // Search and Filter
    public function find(callable $callback): array;
    public function map(callable $callback): self;

    // Merge and Offset Operations
    public function merge(object $handler): self;
    public function offsetExists(int|string $offset): bool;
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
    //public function getMessageHandler();
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