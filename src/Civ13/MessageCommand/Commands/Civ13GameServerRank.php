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
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "Rank" command.
 */
class Civ13GameServerRank extends Civ13GameServerMessageCommand
{
    /**
     * @inheritDoc
     */
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $ckey = self::messageWithoutCommand($command, $message_filtered, true, true)) {
            if (! $item = $this->civ13->verifier->getVerifiedItem($message->author)) {
                return $this->civ13->reply($message, 'No ckey found for your Discord ID. Please verify your account first.');
            }
            if (! $ckey = $item['ss13']) {
                return $this->civ13->reply($message, 'No ckey found for your Discord ID. Please verify your account first.');
            }
        }
        return $this->gameserver->recalculateRanking()->then(
            fn () => $this->civ13->sendMessage($message->channel, $this->gameserver->getRank($ckey), 'rank.txt'),
            fn () => $this->civ13->reply($message, 'Ranking is unavailable. Check which world owns the awards.')
        );
    }
}
