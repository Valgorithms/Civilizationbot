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
use Discord\Helpers\Collection;
use React\Promise\PromiseInterface;

class MessageHandler extends Handler implements MessageHandlerInterface
{
    protected array $required_permissions;
    protected array $match_methods;

    public function __construct(Civ13 &$civ13, array $handlers = [], array $required_permissions = [], array $match_methods = [])
    {
        parent::__construct($civ13, $handlers);
        $this->required_permissions = $required_permissions;
        $this->match_methods = $match_methods;
    }

    public function get(): array
    {
        return [$this->handlers, $this->required_permissions, $this->match_methods];
    }

    public function set(array $handlers, array $required_permissions = [], array $match_methods = []): self
    {
        parent::set($handlers);
        $this->required_permissions = $required_permissions;
        $this->match_methods = $match_methods;

        return $this;
    }

    public function pull(int|string $index, ?callable $defaultCallables = null, array $default_required_permissions = null, array $default_match_methods = null): array
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

        return $return;
    }

    public function fill(array $commands, array $handlers, array $required_permissions = [], array $match_methods = []): self
    {
        if (count($commands) !== count($handlers)) {
            throw new \Exception('Commands and Handlers must be the same length.');
            return $this;
        }
        foreach($commands as $command) {
            parent::pushHandler(array_shift($handlers), $command);
            $this->pushPermission(array_shift($required_permissions), $command);
            $this->pushMethod($match_methods, $command);
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
                return [$handler, $this->required_permissions[$index] ?? [], $this->match_methods[$index] ?? 'str_starts_with'];
        return [];
    }

    public function clear(): self
    {
        parent::clear();
        $this->required_permissions = [];
        $this->match_methods = [];
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
        $this->match_methods = array_merge($this->match_methods, array_shift($toArray));
        return $this;
    }

    public function toArray(): array
    {
        $toArray = parent::toArray();
        $toArray[] = $this->required_permissions;
        $toArray[] = $this->match_methods;
        return $toArray;
    }

    public function offsetGet(int|string $index): array
    {
        $return = parent::offsetGet($index);
        $return[] = $this->required_permissions[$index] ?? null;
        $return[] = $this->match_methods[$index] ?? null;
        return $return;
    }
    
    public function offsetSet(int|string $index, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with'): self
    {
        parent::offsetSet($index, $callback);
        $this->required_permissions[$index] = $required_permissions;
        $this->match_methods[$index] = $method;
        return $this;
    }
    
    public function setOffset(int|string $newOffset, callable $callback, ?array $required_permissions = [], ?string $method = 'str_starts_with'): self
    {
        parent::setOffset($newOffset, $callback);
        if ($offset = $this->getOffset($callback) === false) $offset = $newOffset;
        unset($this->required_permissions[$offset]);
        unset($this->match_methods[$offset]);
        $this->required_permissions[$newOffset] = $required_permissions;
        $this->match_methods[$newOffset] = $method;
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
            switch ($this->match_methods[$command]) {
                case 'exact':
                $method_func = function () use ($message_filtered, $command, $callback): ?callable
                {
                    if ($message_filtered['message_content_lower'] == $command)
                        return $callback; // This is where the magic happens
                    return null;
                };
                break;
                case 'str_contains':
                    $method_func = function () use ($message_filtered, $command, $callback): ?callable
                    {
                        if (str_contains($message_filtered['message_content_lower'], $command)) 
                            return $callback; // This is where the magic happens
                        return null;
                    };
                    break;
                case 'str_starts_with':
                default:
                    $method_func = function () use ($message_filtered, $command, $callback): ?callable
                    {
                        if (str_starts_with($message_filtered['message_content_lower'], $command)) 
                            return $callback; // This is where the magic happens
                        return null;
                    };
            }
            if (! $message->member) return null;
            if ($callback = $method_func()) { // Command triggered
                $required_permissions = $this->required_permissions[$command] ?? [];
                if ($lowest_rank = array_pop($required_permissions)) {
                    if (! isset($this->civ13->role_ids[$lowest_rank])) {
                        $this->civ13->logger->warning("Unable to find role ID for rank `$lowest_rank`");
                        throw new \Exception("Unable to find role ID for rank `$lowest_rank`");
                    } elseif (! $this->checkRank($message->member->roles, $this->required_permissions[$command] ?? [])) return $this->civ13->reply($message, 'Rejected! You need to have at least the <@&' . $this->civ13->role_ids[$lowest_rank] . '> rank.');
                }
                return $callback($message, $message_filtered, $command);
            }
        }
        if (empty($this->handlers)) $this->civ13->logger->info('No message handlers found!');
        return null;
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
            if (is_numeric($rank)) $string .= '<@&' . $rank . '>: `';
            else $string .= $rank . ': `'; // everyone
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