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
 * Handles the 'campaignveterans' command.
 *
 * Replies with a list of campaign veterans, if found.
 * Replies with an error message if no campaign veterans are found.
 */
class CampaignVeterans extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        $guild = $message->guild;
        $role_names = ['Campaign Veteran', 'Season 3 Campaign Veteran'];

        $ckeys = [];

        /** @var Member $member */
        foreach ($guild->members as $member) {
            if ($member->user->bot) {
                continue;
            }

            $has_role = false;
            foreach ($member->roles as $role) {
                if (in_array($role->name, $role_names, true)) {
                    $has_role = true;
                    break;
                }
            }

            if (! $has_role) {
                continue;
            }

            $item = $this->civ13->verifier->getVerifiedItem($member);
            if ($item && isset($item['ss13']) && is_string($item['ss13'])) {
                $ckeys[] = $item['ss13'];
            }
        }

        $ckeys = array_values(array_unique($ckeys));
        $content = implode(PHP_EOL, $ckeys);

        if (empty($content)) {
            return $message->reply('No campaign veterans found.');
        }

        return $message->reply(
            Civ13::createBuilder()
                ->setContent('Campaign Veterans list')
                ->addFileFromContent('campaign_veterans.txt', $content)
        );
    }
}
