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
 * Handles the "globalooc" command.
 */
class GlobalOOC extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $msg = self::messageWithoutCommand($command, $message_filtered)) return $this->civ13->reply($message, 'Invalid format! Please use the format `globalooc [message]`.');
        return $message->react($this->civ13->OOCMessage($msg, $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username)
            ? "📧"
            : "🔥"
        );
    }
}