<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Byond\Byond;
use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "permit" command.
 */
class Permit extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! ($ckey = self::messageWithoutCommand($command, $message_filtered, true, true))) return $this->civ13->reply($message, "Invalid format! Please use the format `unpermit [ckey]`.");
        if (! isset($this->civ13->ages[$ckey]) && Byond::isValidCkey($ckey)) return $this->civ13->reply($message, "Byond username `$ckey` does not exist.");
        $this->civ13->permitCkey($ckey);
        return $this->civ13->reply($message, "Byond username `$ckey` is now permitted to bypass the Byond account restrictions.");
    }
}