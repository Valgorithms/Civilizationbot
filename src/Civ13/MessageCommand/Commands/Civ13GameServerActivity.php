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

use Civ13\MessageCommand\Civ13GameServerMessageCommand;
use Civ13\PlayerActivityTotals;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "activity" command: the region group's weekly player activity totals (no player names).
 */
class Civ13GameServerActivity extends Civ13GameServerMessageCommand
{
    /**
     * @inheritDoc
     */
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        $totals = PlayerActivityTotals::read($this->gameserver->regionGroupFile(), $why);

        return $this->civ13->reply($message, $totals === null ? 'No player activity: '.$why.'.' : PlayerActivityTotals::describe($totals));
    }
}
