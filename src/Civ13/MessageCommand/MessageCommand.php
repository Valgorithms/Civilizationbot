<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand;

use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

class MessageCommand implements MessageCommandInterface
{
    /**
     * Handles the invocation of a message command.
     *
     * @param Message $message The message object containing the command.
     * @param string $command The command string to be executed.
     * @param array $message_filtered The filtered message arguments.
     * @return PromiseInterface A promise that is rejected with an exception indicating the command is not implemented.
     */
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return reject(new \Exception("Command not implemented"));
    }

    public static function messageWithoutCommand(string $command, array $message_filtered): string
    {
        return trim(substr($message_filtered['message_content'], strlen($command)));
    }
}