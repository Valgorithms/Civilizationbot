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
 * Handles the "unverify" command.
 * 
 * This function is only authorized to be used by the database administrator
 */
class Civ13UnVerify extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if ($message->user_id != $this->civ13->technician_id) return $message->react("❌");
        if (! $id = self::messageWithoutCommand($command, $message_filtered, true, true)) return $this->civ13->reply($message, 'Invalid format! Please use the format `unverify <byond username|discord id>`.');
        return $this->civ13->reply($message, $this->civ13->verifier->unverify($id)['message']);
    }
}