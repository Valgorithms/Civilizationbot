<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "listrounds" command.
 */
class ListRounds extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->civ13->reply(
            $message,
            ($rounds = array_reduce($this->civ13->enabled_gameservers, function ($carry, $gameserver) {
                if ($r = $gameserver->getRounds()) $carry[$gameserver->name] = $r;
                return $carry;
            }, []))
                ?  "Rounds: " . json_encode($rounds)
                : 'No data found.'
        );
    }
}