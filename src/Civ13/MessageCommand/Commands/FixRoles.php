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
 * Handles the "fixroles" command.
 */
class FixRoles extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $guild = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)) {
            return $message->react('🔥');
        }
        if ($unverified_members = $guild->members->filter(function (Member $member) {
            return ! $member->roles->has($this->civ13->role_ids['Verified'])
                && ! $member->roles->has($this->civ13->role_ids['Banished'])
                && ! $member->roles->has($this->civ13->role_ids['Permabanished']);
        })) {
            foreach ($unverified_members as $member) {
                if ($this->civ13->verifier->getVerifiedItem($member)) {
                    $member->addRole($this->civ13->role_ids['Verified'], 'fixroles');
                }
            }
        }
        if (
            $verified_members = $guild->members->filter(fn (Member $member) => $member->roles->has($this->civ13->role_ids['Verified']))
        ) {
            foreach ($verified_members as $member) {
                if (! $this->civ13->verifier->getVerifiedItem($member)) {
                    $member->removeRole($this->civ13->role_ids['Verified'], 'fixroles');
                }
            }
        }

        return $message->react('👍');
    }
}
