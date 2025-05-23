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

use function React\Async\await;

/**
 * Handles the "updatebans" command.
 * 
 */
class BansUpdate extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return array_reduce($this->civ13->enabled_gameservers, function ($carry, $gameserver) {
            return $carry || array_reduce($this->civ13->enabled_gameservers, function ($carry2, $gameserver2) use ($gameserver) {
                return $carry2 || (! await($gameserver->banlog_update(null, file_get_contents($gameserver2->basedir . Civ13::playerlogs))) instanceof \Throwable);
            }, false);
        }, false)
            ? $message->react("👍")
            : $message->react("🔥");
    }
}