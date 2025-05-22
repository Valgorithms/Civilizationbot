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
 * Handles the 'byondage' command.
 * 
 * Replies with a user's BYOND account age, if found.
 * Replies with an error message if the ckey cannot be located or the age cannot be determined.
 */
class ByondAge extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return ($ckey = Civ13::sanitizeInput(self::getMessageWithoutCommand($command, $message_filtered))) && ($age = $this->civ13->getByondAge($ckey))
            ? $this->civ13->reply($message, "`$ckey` (`$age`)")
            : $this->civ13->reply($message, "Unable to locate `$ckey`");
    }
}