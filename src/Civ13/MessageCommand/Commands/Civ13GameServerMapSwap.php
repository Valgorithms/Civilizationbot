<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Exceptions\FileNotFoundException;
use Civ13\MessageCommand\Civ13GameServerMessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "MapSwap" command.
 */
class Civ13GameServerMapSwap extends Civ13GameServerMessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $map = self::messageWithoutCommand($command, $message_filtered)) return $message->react("❌")->then(fn() => $this->civ13->reply($message, 'You need to include the name of the map.'));
        return $this->gameserver->MapSwap($map, (isset($this->civ13->verifier)) ? ($this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $this->civ13->discord->username) : $this->civ13->discord->username)->then(
            fn($result) => $message->react("👍")->then(fn() => $this->civ13->reply($message, $result)),
            fn(\Throwable $error) => $message->react($error instanceof FileNotFoundException ? "🔥" : "👎")->then(fn() => $this->civ13->reply($message, $error->getMessage()))
        );
    }
}