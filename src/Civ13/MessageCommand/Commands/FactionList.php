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
 * Handles the "factionlist" command.
 */
class FactionList extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $message->reply(
            array_reduce(
                $this->civ13->enabled_gameservers,
                static fn ($builder, $gameserver) => file_exists($path = $gameserver->basedir.Civ13::factionlist)
                    ? $builder->addfile($path, $gameserver->key.'_factionlist.txt')
                    : $builder,
                Civ13::createBuilder()->setContent('Faction Lists')
            )
        );
    }
}
