<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "discord2ckey" lookup command.
 *
 * Checks if a given Discord ID is registered to a BYOND username using the verifier.
 * 
 * Replies to the message with the associated BYOND username if found,
 * or a not-registered message otherwise
 */
class DiscordToCkey extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $id = self::messageWithoutCommand($command, $message_filtered, true, true)) return $this->civ13->reply($message, 'Invalid format. Please provide a valid Discord ID.');
        return $this->civ13->reply($message, ($item = $this->civ13->verifier->get('discord', $id))
            ?  "`$id` is registered to `{$item['ss13']}`"
            : "`$id` is not registered to any byond username");
    }
}