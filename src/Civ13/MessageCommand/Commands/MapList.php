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
 * Handles the "maplist" command.
 */
class MapList extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return (file_exists($fp = $this->civ13->gitdir . Civ13::maps) && $file_contents = @file_get_contents($fp))
            ? $message->reply(Civ13::createBuilder()->addFileFromContent('maps.txt', $file_contents))
            : $message->react("🔥");
    }
}