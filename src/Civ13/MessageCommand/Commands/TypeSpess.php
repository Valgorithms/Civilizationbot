<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\MessageCommand\Civ13MessageCommand;
use Civ13\OSFunctions;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "ts" command.
 */
class TypeSpess extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $state = trim(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
        if (! in_array($state, ['on', 'off'])) return $this->civ13->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
        if ($state === 'on') {
            OSFunctions::execInBackground("cd {$this->civ13->folders['typespess_path']}");
            OSFunctions::execInBackground('git pull');
            OSFunctions::execInBackground("sh {$this->civ13->files['typespess_launch_server_path']}&");
            return $this->civ13->reply($message, '**TypeSpess Civ13** test server is now **on**: http://civ13.com/ts');
        }
        OSFunctions::execInBackground('killall index.js');
        return $this->civ13->reply($message, '**TypeSpess Civ13** test server is now **offline**.');
    }
}