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

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;

/**
 * Handles the "fullaltcheck" command.
 */
class FullAltCheck extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        $ckeys = [];
        $members = $message->guild->members->filter(fn (Member $member) => ! $member->roles->has($this->civ13->role_ids['Banished']));
        foreach ($members as $member) {
            if ($item = $this->civ13->verifier->getVerifiedItem($member->id)) {
                if (! isset($item['ss13'])) {
                    continue;
                }
                $ckeyinfo = $this->civ13->ckeyinfo($item['ss13']);
                if (count($ckeyinfo['ckeys']) > 1) {
                    $ckeys = array_unique(array_merge($ckeys, $ckeyinfo['ckeys']));
                }
            }
        }
        if ($ckeys) {
            return $message->reply(
                Civ13::createBuilder()
                ->addFileFromContent('alts.txt', '`'.implode('`'.PHP_EOL.'`', $ckeys))
                ->setContent('The following ckeys are alt accounts of unbanned verified players.')
            );
        }

        return $this->civ13->reply($message, 'No alts found.');
    }
}
