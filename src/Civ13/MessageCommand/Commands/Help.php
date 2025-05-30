<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Civ13\MessageHandler;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "help" command.

 */
class Help extends Civ13MessageCommand
{
    public function __construct(protected Civ13 &$civ13, protected MessageHandler $messageHandler){}

    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->civ13->reply($message, $this->messageHandler->generateHelp($message->member->roles), 'help.txt', true);
    }
}