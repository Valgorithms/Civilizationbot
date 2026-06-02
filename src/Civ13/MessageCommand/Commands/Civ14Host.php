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
use Civ13\OSFunctions;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "ss14" command.
 */
class Civ14Host extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $state = trim(substr($message_filtered['message_content_lower'], strlen($command)))) {
            return $this->civ13->reply($message, 'Wrong format. Please try `ss14 on` or `ss14 off`.');
        }
        if (! in_array($state, ['on', 'off'])) {
            return $this->civ13->reply($message, 'Wrong format. Please try `ss14 on` or `ss14 off`.');
        }
        if ($state === 'on') {
            OSFunctions::execInBackground("{$this->civ13->folders['ss14_basedir']}/bin/Content.Server/Content.Server --config-file {$this->civ13->folders['ss14_basedir']}/server_config.toml");

            return $this->civ13->reply($message, '**Civ14** test server is now **online**: ss14://civ13.com');
        }
        OSFunctions::execInBackground('killall Content.Server');

        return $this->civ13->reply($message, '**Civ14** test server is now **offline**.');
    }
}
