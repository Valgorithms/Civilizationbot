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
 * Handles the "playerlist" command.
 */
class Civ13PlayerList extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return (($message->user_id === $this->civ13->technician_id) && $playerlist = array_unique(array_merge(...array_map(fn($gameserver) => $gameserver->players, $this->civ13->enabled_gameservers))))
            ? $this->civ13->reply($message, implode(', ', $playerlist))
            : $message->react("❌");
    }
}