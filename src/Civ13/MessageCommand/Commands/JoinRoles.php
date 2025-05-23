<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "joinroles" command.
 */
class JoinRoles extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        $this->civ13->verifier->getVerified();
        foreach ($this->civ13->verifier->provisional as $item) $this->civ13->verifier->provisionalRegistration($item['ss13'], $item['discord']); // Attempt to register all provisional user 
        if ($guild = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)) foreach ($guild->members as $member)
            /** @var Member $member */
            if (! $member->user->bot && ! $member->roles->has($this->civ13->role_ids['Verified'] && ! $member->roles->has($this->civ13->role_ids['SS14 Verified'])))
                $this->civ13->verifier->joinRoles($member, false);
        return $message->react("👍");
    }
}