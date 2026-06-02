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
 * Handles the "permitted" command.
 */
class PermitList extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $this->civ13->reply(
            $message,
            empty($this->civ13->permitted)
            ? 'No users have been permitted to bypass the Byond account restrictions.'
            : 'The following ckeys are permitted to bypass the Byond account limit and restrictions: '.PHP_EOL
                .'`'.implode('`'.PHP_EOL.'`', array_keys($this->civ13->permitted)).'`'
        );
    }
}
