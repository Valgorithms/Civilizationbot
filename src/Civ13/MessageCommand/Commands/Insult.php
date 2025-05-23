<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "insult" command.
 */
class Insult extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $insults_array = file(Civ13::insults_path, FILE_IGNORE_NEW_LINES)) return $this->civ13->reply($message, 'No insults found!');
        if (! ($split_message = explode(' ', $message_filtered['message_content'])) || count($split_message) <= 1 || strlen($split_message[1]) === 0) $split_message[1] = "<@{$message->user_id}>"; // $split_target[1] is the target of the insult
        return $message->channel->sendMessage(Civ13::createBuilder(true)->setContent($split_message[1] . ', ' . $insults_array[array_rand($insults_array)]));
    }
}