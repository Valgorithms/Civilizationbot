<?php

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13\Interfaces;

use Discord\Parts\Channel\Message;
use Handler\HandlerInterface;
use React\Promise\PromiseInterface;

/**
 * Contract for the bot's `!command` router: a {@see HandlerInterface}
 * collection that maps a command name to a
 * {@see MessageHandlerCallbackInterface}, together with the required-permission,
 * match-method and description metadata for each entry. `handle()` dispatches
 * an incoming {@see Message} to the matching command and returns its
 * {@see PromiseInterface}.
 *
 * @see \Civ13\MessageHandler The concrete implementation
 * @see \Civ13\MessageServiceManager Registers the commands on the handler
 * @see \Handler\HandlerInterface The base handler-collection contract
 */
interface MessageHandlerInterface extends HandlerInterface
{
    // Item Operations

    /**
     * Removes and returns the command entry at `$index` as
     * `[callback, required_permissions, match_methods, descriptions]`, using the
     * given defaults for any part that was not stored.
     */
    public function pull(int|string $index, ?callable $defaultCallables = null, array $default_required_permissions = null, array $default_match_methods = null, array $default_descriptions = null): array;

    /** Replaces every stored entry with `$values` (keyed by command name). */
    public function fill(array $values): self;

    /** Removes every stored command entry. */
    public function clear(): void;

    // Count and Access

    /** The first command entry, or the one named `$name` when given. */
    public function first(null|int|string $name = null): mixed;

    /** The last command entry, or the one named `$name` when given. */
    public function last(null|int|string $name = null): mixed;

    // Existence Checks

    /** Whether an entry exists at `$offset`. */
    public function isset(int|string $offset): bool;

    /** Whether an entry exists at every one of the given `$offsets`. */
    public function has(array ...$offsets): bool;

    // Search and Filter

    /** Every entry for which `$callback` returns true. */
    public function find(callable $callback): array;

    /** A new handler with `$callback` applied to each entry. */
    public function map(callable $callback): self;

    // Merge and Offset Operations

    /** Merges the entries of another handler into this one. */
    public function merge(object $handler): self;

    /** Whether an entry exists at `$offset` (ArrayAccess). */
    public function offsetExists(int|string $offset): bool;

    /** The callback at `$offset`, or the entry named `$name` when given (ArrayAccess). */
    public function offsetGet(int|string $offset, ?string $name = null): mixed;

    /** Stores `$callback` at `$offset` (ArrayAccess). */
    public function offsetSet(int|string $offset, callable $callback): self;

    /** Stores `$callback` at each of `$offsets`. */
    public function offsetSets(array $offsets, callable $callback): self;

    /** The offset of the first entry matching `$callback`, or false. */
    public function getOffset(callable $callback): int|string|false;

    /** Moves the entry matching `$callback` to `$newOffset`. */
    public function setOffset(int|string $newOffset, callable $callback): self;

    // Handler Operations

    /** The raw callback stored at `$offset`, or null. */
    public function getHandler(int|string $offset): ?callable;

    /** Appends `$callback` (optionally at a specific `$offset`). */
    public function pushHandler(callable $callback, int|string|null $offset = null): self;

    /** Appends many callbacks at once. */
    public function pushHandlers(array $handlers): self;

    /** Removes and returns the callback at `$offset` (or `$default`). */
    public function pullHandler(null|int|string $offset = null, mixed $default = null): mixed;

    /** Replaces every stored callback with `$items`. */
    public function fillHandlers(array $items): self;

    /** Removes every stored callback. */
    public function clearHandlers(): self;

    // Iterator and Conversion

    /** Iterator over the stored entries. */
    public function getIterator(): \Traversable;

    /** The stored entries as a plain array. */
    public function toArray(): array;

    // Debugging

    /** Debug representation for `var_dump()`. */
    public function __debugInfo(): array;

    /** Dispatches `$message` to the matching command and returns its promise (or null when none matched). */
    public function handle(Message $message): ?PromiseInterface;

    /** Wraps `$callback` so it only runs when its stored match-method/permission checks pass. */
    public function validate(callable $callback): callable;
}
