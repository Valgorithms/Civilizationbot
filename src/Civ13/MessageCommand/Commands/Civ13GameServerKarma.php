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

use Civ13\KarmaSnapshot;
use Civ13\MessageCommand\Civ13GameServerMessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "karma" command: a ckey's hidden karma for staff (admin standing, playtime, who rated them and how
 * those givers stand). Admin only, and only in the staff bot channel or a DM.
 */
class Civ13GameServerKarma extends Civ13GameServerMessageCommand
{
    /**
     * @inheritDoc
     */
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! KarmaSnapshot::allowedPlace($message->guild_id, $message->channel_id, $this->civ13->channel_ids['staff_bot'] ?? null)) {
            return $this->civ13->reply($message, 'Karma is staff-only: ask in the staff bot channel or in a DM.');
        }
        if (! $id = self::messageWithoutCommand($command, $message_filtered, true, true)) {
            return $this->civ13->reply($message, 'Invalid format! Please use the format: '.$command.' `ckey`');
        }
        // A Discord ID means its verified ckey, like ckeyinfo.
        $ckey = is_numeric($id) ? ($this->civ13->verifier->getVerifiedItem($id)['ss13'] ?? '') : $id;
        if (! is_string($ckey) || $ckey === '') {
            return $this->civ13->reply($message, "No verified ckey for Discord ID `$id`.");
        }
        $karma = KarmaSnapshot::read($this->gameserver->regionGroupFile(), $ckey, $why);

        return $this->civ13->reply($message, $karma === null ? "No karma for `$ckey`: $why." : KarmaSnapshot::describe($karma), "karma-$ckey.txt", true);
    }
}
