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
    protected array $required_permissions;
    /** @var array<string|callable> */
    protected array $match_methods;
    protected array $descriptions;
    /** @inheritdoc */
    public array $handlers = [];

    public function __construct(Civ13 &$civ13, array $handlers = [], array $required_permissions = [], array $match_methods = [], array $descriptions = [])
    {
        parent::__construct($civ13, $handlers);
        $this->required_permissions = $required_permissions;
        $this->match_methods = $match_methods;
        $this->descriptions = $descriptions;
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
        //if (empty($this->handlers)) $this->logger->debug('No message handlers found!');
        $message_filtered = $this->civ13->filterMessage($message);
        if (
            (isset($message_filtered['message_content_lower']) && $endpoint = $message_filtered['message_content_lower'])
            && (isset($this->handlers[$endpoint]) && $callback = $this->handlers[$endpoint])
            && (isset($this->match_methods[$endpoint]) && $matchMethod = $this->match_methods[$endpoint])
            && ($matchMethod === 'exact')
        ) return ['message' => $message, 'message_filtered' => $message_filtered, 'endpoint' => $endpoint, 'callback' => $callback, ];
        
        foreach ($this->handlers as $endpoint => $callback) if (isset($this->match_methods[$endpoint])) {
            $matchMethod = $this->match_methods[$endpoint] ?? 'str_starts_with';
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
        $required_permissions = $this->required_permissions[$endpoint] ?? [];
        if ($lowest_rank = array_pop($required_permissions)) {
            if (! isset($this->civ13->role_ids[$lowest_rank])) {
                $this->logger->warning("Unable to find role ID for rank `$lowest_rank`");
                throw new \Exception("Unable to find role ID for rank `$lowest_rank`");
            } elseif (! $this->checkRank($message->member->roles, $this->required_permissions[$endpoint] ?? [])) return $this->civ13->reply($message, 'Rejected! You need to have at least the <@&' . $this->civ13->role_ids[$lowest_rank] . '> rank.');
        }
        $this->logger->debug("Endpoint '$endpoint' triggered");
        try {
            return $callback($message, $endpoint, $message_filtered);
        } catch (\Exception $e) {
            $this->logger->error("Message Handler error: `A callback for `$endpoint` failed with error `{$e->getMessage()}`");
            return $message->react('🔥');
        }
    }

    public function get(): array
    {
        return [$this->handlers, $this->required_permissions, $this->match_methods, $this->descriptions];
    }

    public function set(array $handlers, array $required_permissions = [], array $match_methods = [], array $descriptions = []): self
    {
        parent::set($handlers);
        $this->required_permissions = $required_permissions;
        $this->match_methods = $match_methods;
        $this->descriptions = $descriptions;
        return $this;
    }

    public function pull(int|string $index, ?callable $defaultCallables = null, array $default_required_permissions = null, array $default_match_methods = null, array $default_descriptions = null): array
    {
        $return = [];
        $return[] = parent::pull($index, $defaultCallables);

        if (isset($this->required_permissions[$index])) {
            $default_required_permissions = $this->required_permissions[$index];
            unset($this->required_permissions[$index]);
        }
        $return[] = $default_required_permissions;

        if (isset($this->match_methods[$index])) {
            $default_match_methods = $this->match_methods[$index];
            unset($this->match_methods[$index]);
        }
        $return[] = $default_match_methods;

        if (isset($this->descriptions[$index])) {
            $default_descriptions = $this->descriptions[$index];
            unset($this->descriptions[$index]);
        }
        $return[] = $default_descriptions;

        return $return;
    }

    public function fill(array $handlers, array $required_permissions = [], array $match_methods = [], array $descriptions = []): self
    {
        if (! array_is_list($handlers)) foreach ($handlers as $command => $handler) {
            parent::push(array_shift($handlers), $command);
            $this->pushPermission(array_shift($required_permissions), $command);
            $this->pushMethod(array_shift($match_methods), $command);
            $this->pushDescription(array_shift($descriptions), $command);
        }
        else foreach ($handlers as $handler) {
            parent::push(array_shift($handler));
            $this->pushPermission(array_shift($required_permissions));
            $this->pushMethod(array_shift($match_methods));
            $this->pushDescription(array_shift($descriptions));
        }
        return $this;
    }
    
    public function pushPermission(array $required_permissions, int|string|null $command = null): ?self
    {
        if ($command) $this->required_permissions[$command] = $required_permissions;
        else $this->required_permissions[] = $required_permissions;
        return $this;
    }

    public function pushMethod(string $method, int|string|null $command = null): ?self
    {
        if ($command) $this->match_methods[$command] = $method;
        else $this->match_methods[] = $method;
        return $this;
    }

    public function pushDescription(string $description, int|string|null $command = null): ?self
    {
        if ($command) $this->descriptions[$command] = $description;
        else $this->descriptions[] = $description;
        return $this;
    }

    public function first(): array
    {
        $toArray = $this->toArray();
        $return = [];
        $return[] = array_shift(array_shift($toArray) ?? []);
        $return[] = array_shift(array_shift($toArray) ?? []);
        $return[] = array_shift(array_shift($toArray) ?? []);
        return $return;
    }
    
    public function last(): array
    {
        $toArray = $this->toArray();
        $return = [];
        $return[] = array_pop(array_shift($toArray) ?? []);
        $return[] = array_pop(array_shift($toArray) ?? []);
        $return[] = array_pop(array_shift($toArray) ?? []);
        return $return;
    }

    public function find(callable $callback): array
    {
        foreach ($this->handlers as $index => $handler)
            if ($callback($handler))
                return [$handler, $this->required_permissions[$index] ?? [], $this->match_methods[$index] ?? 'str_starts_with', $this->descriptions[$index] ?? ''];
        return [];
    }

    public function clear(): self
    {
        parent::clear();
        $this->required_permissions = [];
        $this->match_methods = [];
        $this->descriptions = [];
        return $this;
    }
    
    // TODO: Review this method
    public function map(callable $callback): static
    {
        $arr = array_combine(array_keys($this->handlers), array_map($callback, array_values($this->toArray())));
        return new static($this->civ13, array_shift($arr) ?? [], array_shift($arr) ?? [], array_shift($arr) ?? [], array_shift($arr) ?? []);
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
        $this->handlers = array_merge($this->handlers, array_shift($toArray) ?? []);
        $this->required_permissions = array_merge($this->required_permissions, array_shift($toArray) ?? []);
        $this->match_methods = array_merge($this->match_methods, array_shift($toArray) ?? []);
        $this->descriptions = array_merge($this->descriptions, array_shift($toArray) ?? []);
        return $this;
    }

    public function offsetGet(int|string $offset): array
    {
        $return = parent::offsetGet($offset);
        $return[] = $this->required_permissions[$offset] ?? null;
        $return[] = $this->match_methods[$offset] ?? null;
        $return[] = $this->descriptions[$offset] ?? null;
        return $return;
    }
    
    /**
     * @throws \InvalidArgumentException If the callback does not have the expected number of parameters, if any parameter does not have a type hint, or a type hint is of the wrong type.
     */    
    public function offsetSet(int|string $offset, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with', ?string $description = ''): self
    {
        $callback = $this->validate($callback); // @throws InvalidArgumentException
        parent::offsetSet($offset, $callback);
        $this->required_permissions[$offset] = $required_permissions;
        $this->match_methods[$offset] = $method;
        $this->descriptions[$offset] = $description;
        if ($method === 'exact') $this->__reorderHandlers();
        return $this;
    }

    public function offsetSets(array $offsets, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with', ?string $description = ''): self
    {
        parent::offsetSets($offsets, $callback);
        foreach ($offsets as $offset) {
            $this->required_permissions[$offset] = $required_permissions;
            $this->match_methods[$offset] = $method;
            $this->descriptions[$offset] = $description;
        }
        if ($method === 'exact') $this->__reorderHandlers();
        return $this;
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
        $commands = array_keys($this->handlers);
        usort($commands, fn($a, $b) => strlen($b) <=> strlen($a)); // Prioritize longer commands to avoid improper matching
        foreach ($commands as $command) {
            if ($this->match_methods[$command] === 'exact') $exactHandlers[$command] = $this->handlers[$command];
            else $otherHandlers[$command] = $this->handlers[$command];
        }
        $this->handlers = array_filter(array_merge($otherHandlers, $exactHandlers));
    }
    
    public function setOffset(int|string $newOffset, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with', ?string $description = ''): self
    {
        parent::setOffset($newOffset, $callback);
        if ($offset = $this->getOffset($callback) === false) $offset = $newOffset;
        unset($this->required_permissions[$offset]);
        unset($this->match_methods[$offset]);
        unset($this->descriptions[$offset]);
        $this->required_permissions[$newOffset] = $required_permissions;
        $this->match_methods[$newOffset] = $method;
        $this->descriptions[$newOffset] = $description;
        return $this;
    }

    public function toArray(): array
    {
        $toArray = parent::toArray();
        $toArray[] = $this->required_permissions ?? [];
        $toArray[] = $this->match_methods ?? [];
        $toArray[] = $this->descriptions ?? [];
        return $toArray;
    }

    public function __debugInfo(): array
    {
        return ['civ13' => isset($this->civ13) ? $this->civ13 instanceof Civ13 : false, 'handlers' => array_keys($this->handlers)];
    }

    // Don't forget to use ->setAllowedMentions(['parse'=>[]]) on the MessageBuilder object to prevent all roles being pinged
    public function generateHelp(?Collection $roles = null): string
    {
        $ranks = array_keys($this->civ13->role_ids);
        $ranks[] = 'everyone';
        
        $array = [];
        foreach (array_keys($this->handlers) as $command) {
            $required_permissions = $this->required_permissions[$command] ?? [];
            $lowest_rank = array_pop($required_permissions) ?? 'everyone';
            if (! $roles) $array[$lowest_rank][] = $command;
            elseif ($lowest_rank == 'everyone' || $this->checkRank($roles, $this->required_permissions[$command])) $array[$lowest_rank][] = $command;
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