<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2023-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

interface MessageHandlerInterface extends HandlerInterface
{
    public function handle(Message $message): ?PromiseInterface;
}

namespace Civ13;

use Civ13\Interfaces\messageHandlerInterface;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

class MessageHandler extends Handler implements MessageHandlerInterface
{
    protected array $required_permissions;
    protected array $methods;

    public function __construct(Civ13 &$civ13, array $handlers = [], array $required_permissions = [], array $methods = [])
    {
        parent::__construct($civ13, $handlers);
        $this->required_permissions = $required_permissions;
        $this->methods = $methods;
    }

    public function get(): array
    {
        return [$this->handlers, $this->required_permissions, $this->methods];
    }

    public function set(array $handlers, array $required_permissions = [], array $methods = []): self
    {
        parent::set($handlers);
        $this->required_permissions = $required_permissions;
        $this->methods = $methods;

        return $this;
    }

    public function pull(int|string $index, ?callable $defaultCallables = null, array $defaultrequired_permissions = null, array $defaultMethods = null): array
    {
        $return = [];
        $return[] = parent::pull($index, $defaultCallables);

        if (isset($this->required_permissions[$index])) {
            $defaultrequired_permissions = $this->required_permissions[$index];
            unset($this->required_permissions[$index]);
        }
        $return[] = $defaultrequired_permissions;

        if (isset($this->methods[$index])) {
            $defaultMethods = $this->methods[$index];
            unset($this->methods[$index]);
        }
        $return[] = $defaultMethods;

        return $return;
    }

    public function fill(array $commands, array $handlers, array $required_permissions = [], array $methods = []): self
    {
        if (count($commands) !== count($handlers)) {
            throw new \Exception('Commands and Handlers must be the same length.');
            return $this;
        }
        foreach($commands as $command) {
            parent::pushHandler(array_shift($handlers), $command);
            $this->pushPermission(array_shift($required_permissions), $command);
            $this->pushMethod($methods, $command);
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
        if ($command) $this->methods[$command] = $method;
        else $this->methods[] = $method;
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
                return [$handler, $this->required_permissions[$index] ?? [], $this->methods[$index] ?? 'str_starts_with'];
        return [];
    }

    public function clear(): self
    {
        parent::clear();
        $this->required_permissions = [];
        $this->methods = [];
        return $this;
    }
    
    // TODO: Review this method
    public function map(callable $callback): static
    {
        $arr = array_combine(array_keys($this->handlers), array_map($callback, array_values($this->toArray())));
        return new static($this->civ13, array_shift($arr) ?? [], array_shift($arr) ?? [], array_shift($arr) ?? []);
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
        $this->required_permissions = array_merge($this->required_permissions, array_shift($toArray));
        $this->methods = array_merge($this->methods, array_shift($toArray));
        return $this;
    }

    public function toArray(): array
    {
        $toArray = parent::toArray();
        $toArray[] = $this->required_permissions;
        $toArray[] = $this->methods;
        return $toArray;
    }

    public function offsetGet(int|string $index): array
    {
        $return = parent::offsetGet($index);
        $return[] = $this->required_permissions[$index] ?? null;
        $return[] = $this->methods[$index] ?? null;
        return $return;
    }
    
    public function offsetSet(int|string $index, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with'): self
    {
        parent::offsetSet($index, $callback);
        $this->required_permissions[$index] = $required_permissions;
        $this->methods[$index] = $method;
        return $this;
    }
    
    public function setOffset(int|string $newOffset, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with'): self
    {
        parent::setOffset($newOffset, $callback);
        if ($offset = $this->getOffset($callback) === false) $offset = $newOffset;
        unset($this->required_permissions[$offset]);
        unset($this->methods[$offset]);
        $this->required_permissions[$newOffset] = $required_permissions;
        $this->methods[$newOffset] = $method;
        return $this;
    }

    public function __debugInfo(): array
    {
        return ['civ13' => isset($this->civ13) ? $this->civ13 instanceof Civ13 : false, 'handlers' => array_keys($this->handlers)];
    }

    //Unique to MessageHandler
    
    public function handle(Message $message): ?PromiseInterface
    {
        // if (! $message->member) return $message->reply('Unable to get Discord Member class. Commands are only available in guilds.');
        $message_filtered = $this->civ13->filterMessage($message);
        foreach ($this->handlers as $command => $callback) {
            switch ($this->methods[$command]) {
                case 'exact':
                $method_func = function () use ($message, $message_filtered, $command, $callback): ?PromiseInterface
                {
                    if ($message_filtered['message_content_lower'] == $command)
                        return $callback($message, $message_filtered, $command); // This is where the magic happens
                    return null;
                };
                break;
                case 'str_contains':
                    $method_func = function () use ($message, $message_filtered, $command, $callback): ?PromiseInterface
                    {
                        if (str_contains($message_filtered['message_content_lower'], $command)) 
                            return $callback($message, $message_filtered, $command); // This is where the magic happens
                        return null;
                    };
                    break;
                case 'str_starts_with':
                default:
                    $method_func = function () use ($message, $message_filtered, $command, $callback): ?PromiseInterface
                    {
                        if (str_starts_with($message_filtered['message_content_lower'], $command)) 
                            return $callback($message, $message_filtered, $command); // This is where the magic happens
                        return null;
                    };
            }
            $required_permissions = $this->required_permissions['command'] ?? [];
            if (! $message->member || ! $this->checkRank($message->member, $required_permissions)) return $message->reply('Rejected! You need to have at least the <@&' . $this->civ13->role_ids[array_pop($required_permissions)] . '> rank.');
            if ($PromiseInterface = $method_func()) return $PromiseInterface;
        }
        if (empty($this->handlers)) $this->civ13->logger->info('No message handlers found!');
        return null;
    }
}