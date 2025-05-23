<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Byond\Byond;
use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "ban" command.
 */
class Ban extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        $split_message = explode('; ', self::messageWithoutCommand($command, $message_filtered));
        if (! $split_message[0] = Civ13::sanitizeInput($split_message[0])) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! isset($this->civ13->ages[$split_message[0]]) && ! Byond::isValidCkey($split_message[0])) return $this->civ13->reply($message, "Byond username `{$split_message[0]}` does not exist.");
        if (! isset($split_message[1]) || ! $split_message[1]) return $this->civ13->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! isset($split_message[2]) || ! $split_message[2]) return $this->civ13->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
        $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->civ13->discord_formatted}"];
        return $this->civ13->reply($message, $this->civ13->ban($arr, $this->civ13->verifier->getVerifiedItem($message->author)['ss13']));
    }
}