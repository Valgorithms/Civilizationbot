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

use Civ13\Exceptions\MissingSystemPermissionException;
use Civ13\MessageCommand\Civ13GameServerMessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "Ranking" command.
 */
class Civ13GameServerRanking extends Civ13GameServerMessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->gameserver->recalculateRanking()->then(
            fn () => $this->gameserver->getRanking()->then(
                fn (string $ranking) => $this->civ13->reply($message, $ranking, 'ranking.txt'),
                function (MissingSystemPermissionException $error) use ($message) {
                    $this->logger->error($err = $error->getMessage());
                    $message->react('🔥')->then(fn () => $this->civ13->reply($message, $err));
                }
            ),
            function (MissingSystemPermissionException $error) use ($message) {
                $this->logger->error($err = $error->getMessage());
                $message->react('🔥')->then(fn () => $this->civ13->reply($message, $err));
            }
        );
    }
}
