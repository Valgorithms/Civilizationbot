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
 * Handles the "ckey2discord" command.
 */
class CkeyToDiscord extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->civ13->reply(
            $message,
            ($item = $this->civ13->verifier->get('ss13', $ckey = self::messageWithoutCommand($command, $message_filtered, true, true)))
                ? "`$ckey` is registered to <@{$item['discord']}>"
                : "`$ckey` is not registered to any discord id"
        );
    }
}