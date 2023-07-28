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
    public function handle(Message $message): ?Promise
    {
        $message_filtered = $this->civ13->filterMessage($message);
        foreach ($this->handlers as $command => $callback)
            if (str_starts_with($message_filtered['message_content_lower'], $command))
                return $callback($message, $message_filtered); // This is where the magic happens
        if (empty($this->handlers)) $this->civ13->logger->info('No message handlers found!');
        return null;
    }
}