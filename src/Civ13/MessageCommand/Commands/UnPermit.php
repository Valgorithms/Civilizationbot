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
 * Handles the "unpermit" command.
 */
class UnPermit extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! ($ckey = self::messageWithoutCommand($command, $message_filtered, true, true))) {
            return $this->civ13->reply($message, 'Invalid format! Please use the format `unpermit [ckey]`.');
        }
        $this->civ13->permitCkey($ckey, false);

        return $this->civ13->reply($message, "Byond username `$ckey` is no longer permitted to bypass the Byond account restrictions.");
    }
}
