<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2023-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Civ13\Interfaces\MessageHandlerCallbackInterface;
use Discord\Parts\Channel\Message;
use Discord\Helpers\Collection;
use React\Promise\PromiseInterface;

final class MessageHandlerCallback implements MessageHandlerCallbackInterface
{
    const array PARAMETER_TYPES = [Message::class, 'string', 'array'];

    private \Closure $callback;

    /**
     * @param callable $callback The callback function to be executed.
     * @throws \InvalidArgumentException If the callback does not have the expected number of parameters, if any parameter does not have a type hint, or a type hint is of the wrong type.
     */
    public function __construct(callable $callback)
    {
        $reflection = new \ReflectionFunction($callback);
        $parameters = $reflection->getParameters();
        if (count($parameters) !== $count = count(self::PARAMETER_TYPES)) throw new \InvalidArgumentException("The callback must take exactly $count parameters: " . implode(', ', self::PARAMETER_TYPES));

        foreach ($parameters as $index => $parameter) {
            if (! $parameter->hasType()) throw new \InvalidArgumentException("Parameter $index must have a type hint.");
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType) $type = $type->getName();
            if ($type !== self::PARAMETER_TYPES[$index]) throw new \InvalidArgumentException("Parameter $index must be of type " . self::PARAMETER_TYPES[$index] . '.');
        }

        $this->callback = $callback;
    }

    /**
     * Invokes the Message handler.
     *
     * @param Message $message The original message object.
     * @param string $endpoint The endpoint string.
     * @param array $message_filtered The filtered message array.
     * @return PromiseInterface|null The result of the callback function.
     */
    public function __invoke(Message $message, string $endpoint = '', array $message_filtered = []): ?PromiseInterface
    {
        return call_user_func($this->callback, $message, $endpoint, $message_filtered);
    }
}

use Civ13\Interfaces\MessageHandlerInterface;

class MessageHandler extends CivHandler implements MessageHandlerInterface
{
    protected array $fillable = [
        'handlers',
        'required_permissions',
        'match_methods',
        'descriptions',
    ];
    protected array $attributes = [
        'handlers' => [], // array of callables
        'required_permissions' => [],
        'match_methods' => [], // array of strings or callables
        'descriptions' => [],
    ];

    public function __construct(Civ13 &$civ13, array $handlers = [], array $required_permissions = [], array $match_methods = [], array $descriptions = [])
    {
        parent::__construct($civ13, $handlers);
        $this->attributes['required_permissions'] = $required_permissions;
        $this->attributes['match_methods'] = $match_methods;
        $this->attributes['descriptions'] = $descriptions;
        $this->afterConstruct();
    }
    private function afterConstruct(): void
    {
        $this->__setDefaultRatelimits();
    }
    private function __setDefaultRatelimits(): void
    {
        //TOOD
    }

    /**
     * Handles the incoming message and processes the callback.
     *
     * @param Message $message The incoming message object.
     * @return PromiseInterface|null A PromiseInterface object or null.
     */
    public function handle(Message $message): ?PromiseInterface
    {
        try {
            if (! $array = $this->__getCallback($message)) return null;
            return $this->__processCallback($array['callback'], $array['message'], $array['endpoint'], $array['message_filtered']);
        } catch (\Throwable $e) {
            $this->logger->error("Message Handler error: An endpoint for `$message->content` failed with error `{$e->getMessage()}`");
            return $message->react('🔥');
        }
    }
    /**
     * Validates a callback function and returns a new instance of MessageHandlerCallback.
     *
     * @param callable $callback The callable function to be validated.
     * @return callable New instance of MessageHandlerCallback, which can be invoked as the callable.
     */
    public function validate(callable $callback): callable
    {
        return new MessageHandlerCallback($callback);
    }
    /**
     * Retrieves the callback information for a given message.
     *
     * @param Message $message The message object.
     * @return array|null The callback information array if a match is found, otherwise null.
     */
    private function __getCallback(Message $message): ?array
    {
        // if (! $message->member) return $message->reply('Unable to get Discord Member class. endpoints are only available in guilds.');
        if (! $message->member) return null;
        //if (empty($this->attributes['handlers'])) $this->logger->debug('No message handlers found!');
        $message_filtered = $this->civ13->filterMessage($message);
        if (
            (isset($message_filtered['message_content_lower']) && $endpoint = $message_filtered['message_content_lower'])
            && (isset($this->attributes['handlers'][$endpoint]) && $callback = $this->attributes['handlers'][$endpoint])
            && (isset($this->attributes['match_methods'][$endpoint]) && $matchMethod = $this->attributes['match_methods'][$endpoint])
            && ($matchMethod === 'exact')
        ) return ['message' => $message, 'message_filtered' => $message_filtered, 'endpoint' => $endpoint, 'callback' => $callback, ];
        
        foreach ($this->attributes['handlers'] as $endpoint => $callback) if (isset($this->attributes['match_methods'][$endpoint])) {
            $matchMethod = $this->attributes['match_methods'][$endpoint] ?? 'str_starts_with';
            if ($matchMethod === 'exact') continue; // We've reached the end of the relevant array and there were no exact matches
            if (is_callable($matchMethod) && call_user_func($matchMethod, $message_filtered['message_content_lower'], $endpoint))
                return ['message' => $message, 'message_filtered' => $message_filtered, 'endpoint' => $endpoint, 'callback' => $callback];
            if (! is_callable($matchMethod) && str_starts_with($message_filtered['message_content_lower'], $endpoint)) // Default to str_starts_with if no valid match method is provided
                return ['message' => $message, 'message_filtered' => $message_filtered, 'endpoint' => $endpoint, 'callback' => $callback];
        }
        return null;
    }
    /**
     * Executes the Message handler.
     *
     * @param Message $message The original message object.
     * @param array $message_filtered The filtered message content.
     * @param string $endpoint The endpoint being processed.
     * @param callable $callback The callback function to be executed.
     * @return PromiseInterface|null Returns a PromiseInterface if the callback is asynchronous, otherwise returns null.
     * @throws \Exception Throws an exception if the role ID for the lowest rank is not found.
     */
    private function __processCallback(callable $callback, Message $message, string $endpoint, array $message_filtered): ?PromiseInterface
    {
        $required_permissions = $this->attributes['required_permissions'][$endpoint] ?? [];
        if ($lowest_rank = array_pop($required_permissions)) {
            if (! isset($this->civ13->role_ids[$lowest_rank])) {
                $this->logger->warning("Unable to find role ID for rank `$lowest_rank`");
                throw new \Exception("Unable to find role ID for rank `$lowest_rank`");
            } elseif (! $this->checkRank($message->member->roles, $this->attributes['required_permissions'][$endpoint] ?? [])) return $this->civ13->reply($message, 'Rejected! You need to have at least the <@&' . $this->civ13->role_ids[$lowest_rank] . '> rank.');
        }
        $this->logger->debug("Endpoint '$endpoint' triggered");
        try {
            return $callback($message, $endpoint, $message_filtered);
        } catch (\Exception $e) {
            $this->logger->error("Message Handler error: `A callback for `$endpoint` failed with error `{$e->getMessage()}`");
            return $message->react('🔥');
        }
    }

    public function pull(int|string $index, ?callable $defaultCallables = null, array $default_required_permissions = null, array $default_match_methods = null, array $default_descriptions = null): array
    {
        $return = [
            'handlers' => $this->attributes['handlers'][$index] ?? null,
            'required_permissions' => $this->attributes['required_permissions'][$index] ?? null,
            'match_methods' => $this->attributes['match_methods'][$index] ?? null,
            'descriptions' => $this->attributes['descriptions'][$index] ?? null,
        ];
        unset(
            $this->attributes['handlers'][$index],
            $this->attributes['required_permissions'][$index],
            $this->attributes['match_methods'][$index],
            $this->attributes['descriptions'][$index]
        );
        return $return;
    }

    public function push(callable $callback, int|string|null $offset = null): self
    {
        return $this;
    }

    public function fill(array $handlers, array $required_permissions = [], array $match_methods = [], array $descriptions = []): self
    { // TODO: This should overwrite the existing handlers, not append to them
        if (! array_is_list($handlers)) foreach ($handlers as $command => $handler) {
            $this->pushHandler($handler, $command);
            $this->pushPermission(array_shift($required_permissions), $command);
            $this->pushMethod(array_shift($match_methods), $command);
            $this->pushDescription(array_shift($descriptions), $command);
        }
        else foreach ($handlers as $name => $handler) {
            $this->pushHandler($name, $handler);
            $this->pushPermission(array_shift($required_permissions));
            $this->pushMethod(array_shift($match_methods));
            $this->pushDescription(array_shift($descriptions));
        }
        return $this;
    }

    public function clear(): void
    {
        parent::__clear();
    }
    
    public function getHandler(int|string $offset): ?callable
    {
        return $this->attributes['handlers'][$offset] ?? null;
    }

    public function pushHandlers(array $handlers): self
    {
        foreach ($handlers as $handler) $this->pushHandler($handler);
        return $this;
    }

    public function pushHandler(callable $handler, int|string|null $command = null): self
    {
        if ($command) $this->attributes['handlers'][$command] = $handler;
        else $this->attributes['handlers'][] = $handler;
        return $this;
    }

    public function pullHandler(null|int|string $offset = null, mixed $default = null): mixed
    {
        if (isset($this->attributes['handlers'][$offset])) {
            $item = $this->attributes['handlers'][$offset];
            unset($this->attributes['handlers'][$offset]);
            return $item;
        }
        return $default;
    }

    public function fillHandlers(array $items): self
    {
        foreach ($items as $command => $handler) $this->pushHandler($handler, $command);
        return $this;
    }

    public function clearHandlers(): self
    {
        $this->attributes['handlers'] = [];
        
        return $this;
    }

    public function pushPermission(array $required_permissions, int|string|null $command = null): self
    {
        if ($command) $this->attributes['required_permissions'][$command] = $required_permissions;
        else $this->attributes['required_permissions'][] = $required_permissions;
        return $this;
    }

    public function pushMethod(string $method, int|string|null $command = null): self
    {
        if ($command) $this->attributes['match_methods'][$command] = $method;
        else $this->attributes['match_methods'][] = $method;
        return $this;
    }

    public function pushDescription(string $description, int|string|null $command = null): self
    {
        if ($command) $this->attributes['descriptions'][$command] = $description;
        else $this->attributes['descriptions'][] = $description;
        return $this;
    }

    public function first(null|int|string $name = null): mixed
    {
        return array_map(fn($array) => array_shift($array) ?? null, $this->toArray());
    }
    
    public function last(null|int|string $name = null): mixed
    {
        return array_map(fn($array) => array_pop($array) ?? null, $this->toArray());
    }

    public function find(callable $callback): array
    {
        foreach ($this->attributes['handlers'] as $index => $handler)
            if ($callback($handler))
                return [$handler, $this->attributes['required_permissions'][$index] ?? [], $this->attributes['match_methods'][$index] ?? 'str_starts_with', $this->attributes['descriptions'][$index] ?? ''];
        return [];
    }

    public function isset(int|string $offset): bool
    {
        return isset($this->attributes['handlers'][$offset]);
    }

    public function has(array ...$offsets): bool
    {
        foreach ($offsets as $offset)
            if (! isset($this->attributes['handlers'][$offset]))
                return false;
        return true;
    }
    
    // TODO: Review this method
    public function map(callable $callback): static
    {
        $this->attributes = array_map($callback, $this->attributes);
        return $this;
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
        $this->attributes = array_map(fn($key) => [...$this->attributes[$key], ...array_shift($toArray[$key] ?? [])], array_keys($this->fillable));
        $this->__reorderHandlers();
        return $this;
    }

    public function offsetExists(int|string $offset, ?string $name = null): bool
    {
        if ($name) {
            if (! $attribute = $this->__offsetGet($name)) return false;
            return isset($attribute[$name][$offset]);
        }
        return isset($this->attributes['handlers'][$offset]);
    }

    public function offsetGet(int|string $offset, ?string $name = null): mixed
    {
        if ($name) {
            if (! $attribute = $this->__offsetGet($name)) return null;
            return $attribute[$offset] ?? null;
        }
        if ($return = array_filter(array_map(fn($attribute) => $attribute[$offset] ?? null, $this->attributes))) return $return;
        return null;
    }
    
    /**
     * @throws \InvalidArgumentException If the callback does not have the expected number of parameters, if any parameter does not have a type hint, or a type hint is of the wrong type.
     */    
    public function offsetSet(int|string $offset, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with', ?string $description = ''): self
    {
        $this->attributes = array_merge([
            'handlers' => [$offset => $this->validate($callback)], // @throws InvalidArgumentException
            'required_permissions' => [$offset => $required_permissions],
            'match_methods' => [$offset => $method],
            'descriptions' => [$offset => $description]
        ], $this->attributes);
        if ($method === 'exact') $this->__reorderHandlers();
        return $this;
    }

    public function offsetSets(array $offsets, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with', ?string $description = ''): self
    {
        array_map(fn($offset) => $this->attributes = array_merge([
            'handlers' => [$offset => $this->validate($callback)], // @throws InvalidArgumentException
            'required_permissions' => [$offset => $required_permissions],
            'match_methods' => [$offset => $method],
            'descriptions' => [$offset => $description]
        ], $this->attributes), $offsets);
        if ($method === 'exact') $this->__reorderHandlers();
        return $this;
    }

    public function getOffset(callable $callback): int|string|false
    {
        return parent::__getOffset('handlers', $callback);
    }

    /**
     * Reorders the handlers based on the match methods.
     *
     * This method separates the handlers into two arrays: $exactHandlers and $otherHandlers.
     * Handlers with a match method of 'exact' are stored in $exactHandlers, while the rest are stored in $otherHandlers.
     * The two arrays are then merged and assigned back to the $handlers property, ensuring that exact matches are checked last.
     *
     * @return void
     */
    private function __reorderHandlers(): void
    {
        $exactHandlers = [];
        $otherHandlers = [];
        $commands = array_keys($this->attributes['handlers']);
        usort($commands, fn($a, $b) => strlen($b) <=> strlen($a)); // Prioritize longer commands to avoid improper matching
        foreach ($commands as $command) {
            if ($this->attributes['match_methods'][$command] === 'exact') $exactHandlers[$command] = $this->attributes['handlers'][$command];
            else $otherHandlers[$command] = $this->attributes['handlers'][$command];
        }
        $this->attributes['handlers'] = array_filter(array_merge($otherHandlers, $exactHandlers));
    }
    
    public function setOffset(int|string $newOffset, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with', ?string $description = ''): self
    {
        while ($offset = $this->getOffset($callback) !== false) {
            unset(
                $this->attributes['handlers'][$offset],
                $this->attributes['required_permissions'][$offset],
                $this->attributes['match_methods'][$offset],
                $this->attributes['descriptions'][$offset]
            );
        }
        $this->attributes = array_merge([
            'handlers' => [$newOffset => $callback],
            'required_permissions' => [$newOffset => $required_permissions],
            'match_methods' => [$newOffset => $method],
            'descriptions' => [$newOffset => $description]
        ], $this->attributes);
        return $this;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->attributes['handlers']);
    }
    
    public function toArray(): array
    {
        return [
            'handlers' => $this->attributes['handlers'] ?? [],
            'required_permissions' => $this->attributes['required_permissions'] ?? [],
            'match_methods' => $this->attributes['match_methods'] ?? [],
            'descriptions' => $this->attributes['descriptions'] ?? []
        ];
    }

    public function __debugInfo(): array
    {
        return ['civ13' => isset($this->civ13) ? $this->civ13 instanceof Civ13 : false, 'handlers' => array_keys($this->attributes['handlers'])];
    }

    // Don't forget to use ->setAllowedMentions(['parse'=>[]]) on the MessageBuilder object to prevent all roles being pinged
    public function generateHelp(?Collection $roles = null): string
    {
        $ranks = array_keys($this->civ13->role_ids);
        $ranks[] = 'everyone';
        
        $array = [];
        foreach (array_keys($this->attributes['handlers']) as $command) {
            $required_permissions = $this->attributes['required_permissions'][$command] ?? [];
            $lowest_rank = array_pop($required_permissions) ?? 'everyone';
            if (! $roles) $array[$lowest_rank][] = $command;
            elseif ($lowest_rank == 'everyone' || $this->checkRank($roles, $this->attributes['required_permissions'][$command])) $array[$lowest_rank][] = $command;
        }
        $string = '';
        foreach ($ranks as $rank) {
            if (! isset($array[$rank]) || ! $array[$rank]) continue;
            if (is_numeric($rank)) $string .= '<@&' . $this->civ13->role_ids[$rank] . '>: `';
            else $string .= '@' . $rank . ': `'; // everyone
            asort($array[$rank]);
            $string .= implode('`, `', $array[$rank]);
            $string .= '`' . PHP_EOL;
        }
        return $string;
    }

    // Don't forget to use ->setAllowedMentions(['parse'=>[]]) on the MessageBuilder object to prevent all roles being pinged
    public function __toString(): string
    {
        return $this->generateHelp();
    }
}