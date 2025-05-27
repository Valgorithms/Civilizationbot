<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
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
            static fn(DiscordPoll $poll): PromiseInterface => $message->reply(Civ13::createBuilder()->setPoll($poll)),
            static fn(\Throwable $error): PromiseInterface => $message->react('👎')->then(static fn() => $message->reply($error->getMessage()))
        );
    }
}