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
 * Handles the "ages" command.
 *
 * If available, it replies with the JSON-encoded ages data as an attachment named 'ages.json'.
 * If not available, it replies with an error message indicating that Byond account ages could not be located.
 */
class Ages extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->civ13->reply($message,
            ($ages = $this->civ13->ages)
                ?  json_encode($ages)
                : "Unable to locate Byond account ages",
            'ages.json'
        );
    }
}