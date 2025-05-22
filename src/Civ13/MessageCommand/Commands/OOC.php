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

class OOC extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        foreach ($this->civ13->enabled_gameservers as &$gameserver) switch (strtolower($message->channel->name)) {
            case "ooc-{$gameserver->key}":                    
                if ($gameserver->OOCMessage(
                    self::messageWithoutCommand($command, $message_filtered),
                    $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username
                )) return $message->react("📧");
                return $message->react("🔥");
        }
        return $this->civ13->reply($message, 'You need to be in any of the #ooc channels to use this command.');
    }
}