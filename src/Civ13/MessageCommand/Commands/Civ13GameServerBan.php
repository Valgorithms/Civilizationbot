<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\MessageCommand\Civ13GameServerMessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "Ban" command.
 */
class Civ13GameServerBan extends Civ13GameServerMessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $this->civ13->hasRequiredConfigRoles(['Banished'])) {
            $this->logger->warning("Skipping server function `{$this->gameserver->key}ban` because the required config roles were not found.");
            return $message->react("🔥");
        }
        if (! $message_content = self::messageWithoutCommand($command, $message_filtered)) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `{server}ban ckey; duration; reason`');
        if (! $split_message = explode('; ', $message_content)) return $this->civ13->reply($message, 'Invalid format! Please use the format `{server}ban ckey; duration; reason`');
        if (! isset($split_message[0])) return $this->civ13->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! isset($split_message[1])) return $this->civ13->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! isset($split_message[2])) return $this->civ13->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
        if (! str_ends_with($split_message[2], '.')) $split_message[2] .= '.';
        if (strlen($split_message[2]) > $maxlen = 150 - strlen(" Appeal at {$this->civ13->discord_formatted}")) return $this->civ13->reply($message, "Ban reason is too long! Please limit it to `$maxlen` characters.");
        $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->civ13->discord_formatted}"];
        $result = $this->civ13->ban($arr, $this->civ13->verifier->getVerifiedItem($message->author)['ss13'], $this->gameserver->key);
        if ($member = $this->civ13->verifier->getVerifiedMember('id', $split_message[0]))
            if (! $member->roles->has($this->civ13->role_ids['Banished']))
                $member->addRole($this->civ13->role_ids['Banished'], $result);
        return $this->civ13->reply($message, $result);
    }
}