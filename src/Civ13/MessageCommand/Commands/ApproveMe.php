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
 * Handles the "approveme" command.
 */
class ApproveMe extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (isset($this->civ13->role_ids['Verified']) && $message->member->roles->has($this->civ13->role_ids['Verified']))
            return $this->civ13->reply($message, 'You already have the verification role!');
        if ($item = $this->civ13->verifier->getVerifiedItem($message->author))
            return $message->member->setRoles([$this->civ13->role_ids['Verified']], "approveme {$item['ss13']}")
                ->then(static fn() => $message->react("👍"));
        if (! $ckey = self::messageWithoutCommand($command, $message_filtered, true, true))
            return $this->civ13->reply($message, 'Invalid format! Please use the format `approveme ckey`');
        return $this->civ13->reply($message, $this->civ13->verifier->process($ckey, $message->user_id, $message->member));
    }
}