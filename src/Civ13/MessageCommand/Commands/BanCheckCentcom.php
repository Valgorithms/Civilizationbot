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

use Byond\Byond;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "bancheck_centcom" command.
 */
class BanCheckCentcom extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $ckey = self::messageWithoutCommand($command, $message_filtered, true, true)) {
            if (! $ckey = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? null) {
                return $this->civ13->reply($message, 'Wrong format. Please try `bancheck_centcom [ckey]`.');
            }
        }
        if (is_numeric($ckey)) {
            if (! $item = $this->civ13->verifier->get('discord', $ckey)) {
                return $this->civ13->reply($message, "No ckey found for Discord ID `$ckey`.");
            }
            $ckey = $item['ss13'];
        }
        if (! $json = Byond::bansearch_centcom($ckey)) {
            return $this->civ13->reply($message, "Unable to locate bans for `$ckey` on centcom.melonmesa.com.");
        }
        if ($json === '[]') {
            return $this->civ13->reply($message, 'No bans were found for `ckey` on centcom.melonmesa.com.');
        }

        return $this->civ13->reply($message, $json, $ckey.'_bans.json', true);
    }
}
