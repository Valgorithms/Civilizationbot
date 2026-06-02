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

use function React\Async\await;

/**
 * Handles the "updatebans" command.
 */
class BansUpdate extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $message->react(array_reduce(
            $this->civ13->enabled_gameservers,
            fn ($carry, $gameserver) => $carry || array_reduce(
                $this->civ13->enabled_gameservers,
                fn ($carry2, $gameserver2) => $carry2 || (! await($gameserver->banlog_update(null, file_get_contents($gameserver2->basedir.Civ13::playerlogs))) instanceof \Throwable),
                false
            ),
            false
        )
            ? '👍'
            : '🔥');
    }
}
