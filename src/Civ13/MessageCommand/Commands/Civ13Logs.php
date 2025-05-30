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
 * Handles the "civ13logs" command.
 */
class Civ13Logs extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->logHandler($message, self::messageWithoutCommand($command, $message_filtered));
    }

    public function logHandler(Message $message, string $message_content): PromiseInterface
    {
        $tokens = explode(';', $message_content);
        $keys = [];
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            $keys[] = $gameserver->key;
            if (trim($tokens[0]) !== $gameserver->key) continue; // Check if server is valid
            if (! isset($gameserver->basedir) || ! file_exists($fp = $gameserver->basedir . Civ13::log_basedir)) {
                $this->logger->warning("`$fp` is either not set or does not exist");
                return $message->react("🔥");
            }

            unset($tokens[0]);
            $results = $this->civ13->FileNav($gameserver->basedir . Civ13::log_basedir, $tokens);
            if ($results[0]) return $message->reply(Civ13::createBuilder()->addFile($results[1], 'log.txt'));
            if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
            if (! isset($results[2]) || ! $results[2]) return $this->civ13->reply($message, 'Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
            return $this->civ13->reply($message, "{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
        }
        return $this->civ13->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys) . '`');
    }
}