<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
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
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $ckey = self::messageWithoutCommand($command, $message_filtered, true, true)) {
            if (! $item = $this->civ13->verifier->getVerifiedItem($message->author)) return $this->civ13->reply($message, 'No ckey found for your Discord ID. Please verify your account first.');
            if (! $ckey = $item['ss13']) return $this->civ13->reply($message, 'No ckey found for your Discord ID. Please verify your account first.');
        }
        if (! $this->gameserver->recalculateRanking()) return $this->civ13->reply($message, 'There was an error trying to recalculate ranking! The bot may be misconfigured.');
        if (! $msg = $this->gameserver->getRank($ckey)) return $this->civ13->reply($message, 'There was an error trying to get your ranking!');
        return $this->civ13->sendMessage($message->channel, $msg, 'rank.txt');
        // return $this->civ13->reply($message, "Your ranking is too long to display.");
    }
}