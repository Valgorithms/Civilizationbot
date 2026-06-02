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
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;

/**
 * Handles the "bancheckfull" command.
 */
class BanCheckFull extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $message->guild->members->map(fn (Member $member) => ($item = $this->civ13->verifier->getVerifiedItem($member)) ? $this->civ13->bancheck($item['ss13']) : null)
                ? $message->react('👍')
                : $message->react('👎');
    }
}
