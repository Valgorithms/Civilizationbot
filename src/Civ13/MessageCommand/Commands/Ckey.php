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
 * Handles the "ckey" command.
 */
class Ckey extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        //if (str_starts_with($message_filtered['message_content_lower'], 'ckeyinfo')) return null; // This shouldn't happen, but just in case...
        if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) {
            if (! $item = $this->civ13->verifier->getVerifiedItem($ckey = $message->user_id)) return $this->civ13->reply($message, "You are not registered to any byond username");
            return $this->civ13->reply($message, "You are registered to `{$item['ss13']}`");
        }
        if (is_numeric($ckey)) {
            if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "`$ckey` is not registered to any ckey");
            if (! isset($item['ss13'])) return $this->civ13->reply($message, "ss13 key is not set! " . PHP_EOL . '`' . json_encode($item) . '`');
            if (! $age = $this->civ13->getByondAge($item['ss13'])) return $this->civ13->reply($message, "`{$item['ss13']}` does not exist");
            return $this->civ13->reply($message, "`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
        }
        if (! $age = $this->civ13->getByondAge($ckey)) return $this->civ13->reply($message, "`$ckey` does not exist");
        if ($item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
        return $this->civ13->reply($message, "`$ckey` is not registered to any discord id ($age)");
    }
}