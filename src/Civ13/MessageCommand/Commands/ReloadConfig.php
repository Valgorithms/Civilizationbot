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
use Civ13\PortableProcess;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "reloadconfig" command.
 *
 * Portable profiles only: asks the foreground control.php supervisor to relaunch
 * the bot, which rereads the profile and environment file, then stops gracefully.
 */
class ReloadConfig extends Civ13MessageCommand
{
    /**
     * @inheritDoc
     */
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if ($this->civ13->portable_state_dir === '') {
            return $this->civ13->reply($message, 'Configuration reload is only available for portable profiles.');
        }
        try {
            PortableProcess::requestReload($this->civ13->portable_state_dir);
        } catch (\Throwable $error) {
            return $this->civ13->reply($message, 'Configuration reload could not be requested: '.$error->getMessage());
        }

        return $this->civ13->reply($message, 'Reloading configuration; the bot will reconnect shortly.')
            ->then(fn () => $this->civ13->stop());
    }
}
