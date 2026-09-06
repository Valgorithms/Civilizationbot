<?php

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13\Interfaces;

use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * The shape of a single `!command` callback registered on the
 * {@see MessageHandlerInterface}: it receives the triggering {@see Message},
 * the matched command name and the pre-parsed/filtered message parts, and
 * returns a {@see PromiseInterface} for its async work (or null when it does
 * nothing).
 *
 * @see \Civ13\MessageHandler Where these callbacks are stored, matched and invoked
 * @see \Civ13\MessageCommand\MessageCommandInterface The object form of the same contract
 */
interface MessageHandlerCallbackInterface
{
    public function __invoke(Message $message, string $command, array $message_filtered): ?PromiseInterface;
}
