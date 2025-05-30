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
 * Handles the "ipdata" command.
 */
class IPData extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->civ13->reply($message, ($input = trim(substr($message_filtered['message_content'], strlen($command)))) && ($data = $this->civ13->getIpData($input))
            ? json_encode($data, JSON_PRETTY_PRINT)
            : 'Invalid format or no data found. Please use the format: ip_data `ip address`'
        );
    }
}