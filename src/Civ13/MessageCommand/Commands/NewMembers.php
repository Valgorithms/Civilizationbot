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
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;

/**
 * Handles the "newmembers" command.
 */
class NewMembers extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        return $message->reply(Civ13::createBuilder()
            ->addFileFromContent('new_members.json', $message->guild->members
                ->sort(static fn(Member $a, Member $b) =>
                    $b->joined_at->getTimestamp() <=> $a->joined_at->getTimestamp())
                ->slice(0, 10)
                ->map(static fn(Member $member) => [
                    'username' => $member->user->username,
                    'id' => $member->id,
                    'join_date' => $member->joined_at->format('Y-m-d H:i:s')
                ])
                ->serialize(JSON_PRETTY_PRINT)
            )
        );
    }
}