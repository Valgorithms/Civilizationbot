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
 * Handles the "softban" command.
 */
class UnSoftBan extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        $this->civ13->softban($id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))));
        return $this->civ13->reply($message, "`$id` is allowed to get verified again.");
    }
}