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
 * Handles the "civ13playerlogs" command.
 */
class Civ13PlayerLogs extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        $tokens = explode(';', self::messageWithoutCommand($command, $message_filtered));
        $keys = [];
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            $keys[] = $gameserver->key;
            if (trim($tokens[0]) !== $gameserver->key) continue;
            if (! isset($gameserver->basedir) || ! file_exists($gameserver->basedir . Civ13::playerlogs) || ! $file_contents = @file_get_contents($gameserver->basedir . Civ13::playerlogs)) return $message->react("🔥");
            return $message->reply(Civ13::createBuilder()->addFileFromContent('playerlogs.txt', $file_contents));
        }
        return $this->civ13->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys). '`' );
    }
}