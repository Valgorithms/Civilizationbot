<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand;

use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

interface MessageCommandInterface
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface;
}