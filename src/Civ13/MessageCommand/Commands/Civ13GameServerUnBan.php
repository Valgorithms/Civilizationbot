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
 * Handles the "UnBan" command.
 */
class Civ13GameServerUnBan extends Civ13GameServerMessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $ckey = self::messageWithoutCommand($command, $message_filtered, true, true)) return $this->civ13->reply($message, 'Missing unban ckey! Please use the format `{server}unban ckey`');
        if (is_numeric($ckey)) {
            if (! $item = $this->civ13->verifier->getVerifiedItem($ckey)) return $this->civ13->reply($message, "No data found for Discord ID `$ckey`.");
            if (! $ckey = $item['ckey'] ?? null) return $this->civ13->reply($message, "No ckey found for Discord ID `$ckey`.");
        }

        $this->civ13->unban($ckey, $admin = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'], $this->gameserver->key);
        $result = "`$admin` unbanned `$ckey` from `{$this->gameserver->name}`";
        if ($member = $this->civ13->verifier->getVerifiedMember('id', $ckey))
            if ($member->roles->has($this->civ13->role_ids['Banished']))
                $member->removeRole($this->civ13->role_ids['Banished'], $result);
        return $this->civ13->reply($message, $result);
    }
}