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
        return (file_exists($fp = $this->civ13->gitdir.Civ13::maps) && $file_contents = @file_get_contents($fp))
            ? $message->reply(Civ13::createBuilder()->addFileFromContent('maps.txt', $file_contents))
            : $message->react('🔥');
    }
}
