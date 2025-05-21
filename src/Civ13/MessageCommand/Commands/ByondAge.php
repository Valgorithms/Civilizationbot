<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the 'byondage' command to retrieve and reply with a user's BYOND account age.
 *
 * This command extracts the ckey (BYOND username) from the message,
 * retrieves the account age using the Civ13::getByondAge method,
 * and replies to the message with the ckey and age if found.
 * 
 * If the ckey cannot be located or the age cannot be determined, it replies with an error message.
 */
class ByondAge extends MessageCommand
{
    public function __construct(protected Civ13 &$civ13){}

    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return ($ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) && ($age = $this->civ13->getByondAge($ckey))
            ? $this->civ13->reply($message, "`$ckey` (`$age`)")
            : $this->civ13->reply($message, "Unable to locate `$ckey`");
    }
}