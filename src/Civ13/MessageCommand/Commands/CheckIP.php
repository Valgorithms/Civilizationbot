<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "checkip" command.
 * 
 * Replies with the public IP address of the server.
 */
class CheckIP extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->civ13->reply(
            $message,
            @file_get_contents(
                'http://ipecho.net/plain',
                false,
                stream_context_create(['http' => ['connect_timeout' => 5]])
            )
        );
    }    
}