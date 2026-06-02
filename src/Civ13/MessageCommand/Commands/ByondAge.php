<?php

declare(strict_types=1);

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13\MessageCommand\Commands;

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
        return ($ckey = self::messageWithoutCommand($command, $message_filtered, true, true)) && ($age = $this->civ13->getByondAge($ckey))
            ? $this->civ13->reply($message, "`$ckey` (`$age`)")
            : $this->civ13->reply($message, "Unable to locate `$ckey`");
    }
}
