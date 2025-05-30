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
 * Handles the "civ13unverify" command.
 * 
 * This function is only authorized to be used by the database administrator
 */
class Civ14UnVerify extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
        if (! $id = self::messageWithoutCommand($command, $message_filtered, false, true)) return $this->civ13->reply($message, 'Invalid format! Please use the format `unverify <byond username|discord id>`.');
        return $this->civ13->ss14verifier->unverify(
            is_numeric($id) ? $id : '',
            is_numeric($id) ? '' : $id
        )->then(
            fn (array $result) => $this->civ13->reply($message, 'Unverified SS14: ' . json_encode($result)),
            fn (\Throwable $e) => $this->civ13->reply($message, $e->getMessage())
        );
    }
}