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
 * Handles the "dm" command.
 */
class DM extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! str_contains($message_filtered['message_content'], ';')) return $this->civ13->reply($message, 'Invalid format! Please use the format `dm [ckey]; [message]`.');
        if (! $explode = explode(';', $message_filtered['message_content'])) return $this->civ13->reply($message, 'Invalid format! Please use the format `dm [ckey]; [message]`.');
        if (! $recipient = Civ13::sanitizeInput(substr(trim(array_shift($explode)), strlen($command)))) return $this->civ13->reply($message, 'Invalid format! Please use the format `dm [ckey]; [message]`.'); 
        if (! $msg = implode(' ', $explode)) return $this->civ13->reply($message, 'Invalid format! Please use the format `dm [ckey]; [message]`.');
        foreach ($this->civ13->enabled_gameservers as $server) {
            switch (strtolower($message->channel->name)) {
                case "asay-{$server->key}":
                case "ic-{$server->key}":
                case "ooc-{$server->key}":
                    if ($this->civ13->DirectMessage($msg, $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username, $recipient, $server->key)) return $message->react("📧");
                    return $message->react("🔥");
            }
        }
        return $this->civ13->reply($message, 'You need to be in any of the #ic, #asay, or #ooc channels to use this command.');
    }
}