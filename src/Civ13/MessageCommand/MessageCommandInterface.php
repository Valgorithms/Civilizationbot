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

namespace Civ13\MessageCommand;

use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Contract for one message (`!`-prefixed) command implemented as a class rather
 * than a closure: invoking it with the triggering {@see Message}, the matched
 * command name and the filtered message parts runs the command and returns a
 * {@see PromiseInterface} for its async work.
 *
 * @see \Civ13\MessageCommand\MessageCommand The base class implementing this
 * @see \Civ13\Interfaces\MessageHandlerCallbackInterface The closure form of the same contract
 */
interface MessageCommandInterface
{
    /**
     * Runs the command.
     *
     * @param Message $message          The message that triggered the command.
     * @param string  $command          The matched command name.
     * @param array   $message_filtered The pre-parsed message parts.
     */
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface;
}
