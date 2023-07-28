<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2023-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use Discord\Parts\Channel\Message;
use React\Promise\Promise;

interface MessageHandlerInterface extends HandlerInterface
{
    public function handle(Message $message): ?Promise;
}

namespace Civ13;

use Civ13\Interfaces\messageHandlerInterface;
use Discord\Parts\Channel\Message;
use React\Promise\Promise;

class MessageHandler extends Handler implements MessageHandlerInterface
{
    protected Civ13 $civ13;
    
    public function __construct(Civ13 &$civ13, array $handlers = [])
    {
        $this->civ13 = $civ13;
        parent::__construct($handlers);
    }

    public function filter(callable $callback): static
    {
        $static = new static($this->civ13, []);
        foreach ($this->handlers as $handler)
            if ($callback($handler))
                $static->push($handler);
        return $static;
    }

    public function map(callable $callback): static
    {
        return new static($this->civ13, array_combine(array_keys($this->handlers), array_map($callback, array_values($this->handlers))));
    }

    public function handle(Message $message): ?Promise
    {
        $message_filtered = $this->civ13->filterMessage($message);
        foreach ($this->handlers as $command => $callback)
            if (str_starts_with($message_filtered['message_content_lower'], $command))
                return $callback($message, $message_filtered); // This is where the magic happens
        if (empty($this->handlers)) $this->civ13->logger->info('No message handlers found!');
        return null;
    }

    public function __debugInfo(): array
    {
        return ['civ13' => isset($this->civ13) ? $this->civ13 instanceof Civ13 : false, 'handlers' => array_keys($this->handlers)];
    }
}