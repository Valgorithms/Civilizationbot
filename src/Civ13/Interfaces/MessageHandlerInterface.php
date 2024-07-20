<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Interfaces;

use Discord\Parts\Channel\Message;
use Handler\HandlerInterface;
use React\Promise\PromiseInterface;

interface MessageHandlerInterface extends HandlerInterface
{
    public function validate(callable $callback): callable;
    public function handle(Message $message): ?PromiseInterface;
}