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
use Civ13\Polls;
use Discord\Parts\Channel\Message;
use Discord\Parts\Channel\Poll\Poll as DiscordPoll;
use React\Promise\PromiseInterface;

/**
 * Handles the "poll" command.
 */
class Poll extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return Polls::getPoll($this->civ13->discord, self::messageWithoutCommand($command, $message_filtered))->then(
            static fn (DiscordPoll $poll): PromiseInterface => $message->reply(Civ13::createBuilder()->setPoll($poll)),
            static fn (\Throwable $error): PromiseInterface => $message->react('👎')->then(static fn () => $message->reply($error->getMessage()))
        );
    }
}
