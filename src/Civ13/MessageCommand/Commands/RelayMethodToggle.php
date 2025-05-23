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
 * Handles the "togglerelaymethod" command.
 *
 * Changes the relay method between 'file' and 'webhook' and sends a message to confirm the change.
 */
class RelayMethodToggle extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! ($key = trim(substr($message_filtered['message_content'], strlen($command)))) || ! isset($this->civ13->enabled_gameservers[$key]) || ! $gameserver = $this->civ13->enabled_gameservers[$key]) return $this->civ13->reply($message, 'Invalid format! Please use the format `togglerelaymethod ['.implode('`, `', array_keys($this->civ13->enabled_gameservers)).']`.');
        return $this->civ13->reply($message, 'Relay method changed to `' . (($gameserver->legacy_relay = ! $gameserver->legacy_relay) ? 'file' : 'webhook') . '`.');
    }
}