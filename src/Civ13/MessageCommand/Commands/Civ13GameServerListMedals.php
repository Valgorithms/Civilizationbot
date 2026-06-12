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

use Civ13\Civ13;
use Civ13\Exceptions\MissingSystemPermissionException;
use Civ13\MessageCommand\Civ13GameServerMessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/**
 * Handles the "civ13listmedals" command.
 */
class Civ13GameServerListMedals extends Civ13GameServerMessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! @touch($awardsPath = $this->gameserver->basedir . Civ13::awards)) {
            return reject(new MissingSystemPermissionException("Unable to access `{$awardsPath}`"));
        }

        return $message->reply(Civ13::createBuilder()
            ->setContent('Medals')
            ->addFile(
                $awardsPath,
                $this->gameserver->key . '_awards.txt')
            );
    }
}
