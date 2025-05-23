<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;

/**
 * Handles the "bancheckfull" command.
 */
class BanCheckFull extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $message->guild->members->map(fn(Member $member) =>
            ($item = $this->civ13->verifier->getVerifiedItem($member)) ? $this->civ13->bancheck($item['ss13']) : null)
                ? $message->react("👍")
                : $message->react("👎");
    }
}