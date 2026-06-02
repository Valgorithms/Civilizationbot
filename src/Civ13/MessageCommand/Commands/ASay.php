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

use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "asay" command.
 */
class ASay extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $msg = self::messageWithoutCommand($command, $message_filtered)) {
            return $this->civ13->reply($message, 'Invalid format! Please use the format `asay [message]`.');
        }
        foreach ($this->civ13->enabled_gameservers as $server) {
            switch (strtolower($message->channel->name)) {
                case "asay-{$server->key}":
                    if ($this->civ13->AdminMessage($msg, $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? $message->author->username, $server->key)) {
                        return $message->react('📧');
                    }

                    return $message->react('🔥');
            }
        }

        return $this->civ13->reply($message, 'You need to be in any of the #asay channels to use this command.');
    }
}
