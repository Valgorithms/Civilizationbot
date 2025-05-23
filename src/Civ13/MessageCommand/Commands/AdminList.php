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
 * Handles the "adminlist" command.
 */
class AdminList extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $message->reply(
            array_reduce($this->civ13->enabled_gameservers, static fn($builder, $gameserver) =>
                file_exists($path = $gameserver->basedir . Civ13::admins)
                    ? $builder->addFile($path, $gameserver->key . '_adminlist.txt')
                    : $builder,
                Civ13::createBuilder()->setContent('Admin Lists')));
    }
}