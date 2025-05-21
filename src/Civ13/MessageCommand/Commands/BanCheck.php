<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\GameServer;
use Civ13\MessageCommand\Civ13MessageCommand;
use Clue\Redis\Protocol\Parser\MessageBuffer;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "bancheck" command.
 * 
 * If no ckey is provided, attempts to use the verified SS13 ckey of the message author.
 * 
 * Searches through enabled gameservers for ban records and responds with the results.
 * 
 * If the user is found to be banned and does not already have the "Banished" role, assigns it.
 */
class BanCheck extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $ckey = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))
            if (! $ckey = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? null)
                return $this->civ13->reply($message, 'Wrong format. Please try `bancheck [ckey]`.');
        if (is_numeric($ckey)) {
            if (! $item = $this->civ13->verifier->get('discord', $ckey)) return $this->civ13->reply($message, "No ckey found for Discord ID `$ckey`.");
            $ckey = $item['ss13'];
        }        
        return ($content = $this->createContent($ckey))
            ? $this->civ13->reply($message, $content, 'bancheck.txt')
            : $message->react("🔥");
    }

    protected function createContent(string $ckey): string|false
    {
        $content = '';
        $found = false;
        foreach ($this->civ13->enabled_gameservers as &$gameserver)
            if (! $this->fillContent($gameserver, $content, $found, $ckey))
                return false;
        if (! $found) $content .= "No bans were found for `$ckey`." . PHP_EOL;
        $this->updateBanished($found, $ckey);
        return $content;
    }

    protected function fillContent(GameServer &$gameserver, string &$content, bool &$found, string $ckey): bool
    {
        if (! touch ($gameserver->basedir . Civ13::bans) || ! $file = @fopen($gameserver->basedir . Civ13::bans, 'r')) {
            $this->logger->warning('Could not open `' . $gameserver->basedir . Civ13::bans . "` for reading.");
            return false;
        }
        while (($fp = fgets($file, 4096)) !== false) {
            $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
            if ((count($linesplit)>=8) && ($linesplit[8] === strtolower($ckey))) {
                $found = true;
                $type = $linesplit[0];
                $reason = $linesplit[3];
                $admin = $linesplit[4];
                $date = $linesplit[5];
                $duration = $linesplit[7];
                $content .= "`$date`: `$admin` `$type` banned `$ckey` from `{$gameserver->name}` for `{$duration}` with the reason `$reason`" . PHP_EOL;
            }
        }
        fclose($file);
        return true;
    }

    protected function updateBanished(bool $found = true, string $ckey): ?PromiseInterface
    {
        if (! isset($this->civ13->role_ids['Banished'])) return null;
        if (! $member = $this->civ13->verifier->getVerifiedMember($ckey)) return null;
        if ($found && ! $member->roles->has($this->civ13->role_ids['Banished'])) return $member->addRole($this->civ13->role_ids['Banished']);
        //if (! $found && $member->roles->has($this->civ13->role_ids['Banished'])) return $member->removeRole($this->civ13->role_ids['Banished']); // They might be banned on a disabled server
        return null;
    }
}