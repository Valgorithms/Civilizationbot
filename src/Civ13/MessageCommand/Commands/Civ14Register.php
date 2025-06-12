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
 * Handles the "civ14register" command.
 * 
 * This function is only authorized to be used by the database administrator
 */
class Civ14Register extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
            if (! $split_message = explode(';', self::messageWithoutCommand($command, $message_filtered, false, true))) return $this->civ13->reply($message, 'Invalid format! Please use the format `register <byond username>; <discord id>`.');
            if (! isset($split_message[1]) || ! $split_message[1]) return $this->civ13->reply($message, 'Invalid format! Please use the format `register <byond username>; <discord id>`.');
            if (! $name = $split_message[0]) return $this->civ13->reply($message, 'Invalid format! Please use the format `register <byond username>; <discord id>`.');
            if (! is_numeric($discord_id = $split_message[1])) return $this->civ13->reply($message, "Discord id `$discord_id` must be numeric.");
            return $this->civ13->ss14verifier->verify($discord_id, $name)->then(
                fn(string $ss14) => $this->civ13->reply($message, "Successfully registered `$ss14` to <@$discord_id>."),
                fn(\Throwable $e) => $this->civ13->reply($message, $e->getMessage())
            );
    }
}