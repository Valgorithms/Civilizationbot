<?php

declare(strict_types=1);

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
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
    /**
     * @inheritDoc
     */
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->logHandler($message, self::messageWithoutCommand($command, $message_filtered));
    }

    /**
     * Navigates a game server's log directory from a `;`-delimited path and replies with a file or the available options.
     *
     * @param Message $message         The triggering message.
     * @param string  $message_content The command arguments: `{server};{path};{segments...}`.
     */
    public function logHandler(Message $message, string $message_content): PromiseInterface
    {
        $result = $this->civ13->logNavigation($message_content);
        if (isset($result['error'])) {
            $this->logger->warning($result['error']);

            return $message->react('🔥');
        }
        if (isset($result['file'])) {
            return $message->reply(Civ13::createBuilder()->addFile($result['file'], 'log.txt'));
        }

        return $this->civ13->reply($message, $result['text']);
    }
}
