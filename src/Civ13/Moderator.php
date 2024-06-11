<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Discord;
use Monolog\Logger;

class Moderator
{
    public Civ13 $civ13;
    public Discord $discord;
    public Logger $logger;
    public array $timers = [];
    public string $status = 'status.txt';

    public function __construct(Civ13 $civ13)
    {
        $this->civ13 =& $civ13;
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
        $this->afterConstruct();
    }
    private function afterConstruct(): void
    {
        $this->discord->once('ready', function () {
            //
        });
    }

    /**
     * Scrutinizes the given ckey and applies ban rules if necessary.
     *
     * @param string $ckey The ckey to be scrutinized.
     * @return void
     */
    public function scrutinizeCkey(string $ckey): void
    { // Suspicious user ban rules
        if (! isset($this->civ13->permitted[$ckey]) && ! in_array($ckey, $this->civ13->seen_players)) {
            $this->civ13->seen_players[] = $ckey;
            $ckeyinfo = $this->civ13->ckeyinfo($ckey);
            $ban = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->civ13->discord_formatted}"];
            if ($ckeyinfo['altbanned']) { // Banned with a different ckey
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Alt Banned)');
            } else foreach ($ckeyinfo['ips'] as $ip) {
                if (in_array($this->civ13->IP2Country($ip), $this->civ13->blacklisted_countries)) { // Country code
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Blacklisted Country)');
                    break;
                } else foreach ($this->civ13->blacklisted_regions as $region) if (str_starts_with($ip, $region)) { // IP Segments
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true) . ' (Blacklisted Region)');
                    break 2;
                }
            }
        }
        if ($this->civ13->verifier->verified->get('ss13', $ckey)) return; // Verified users are exempt from further checks
        if ($this->civ13->panic_bunker || (isset($this->civ13->serverinfo[1]['admins']) && $this->civ13->serverinfo[1]['admins'] == 0 && isset($this->civ13->serverinfo[1]['vote']) && $this->civ13->serverinfo[1]['vote'] == 0)) {
            $this->civ13->__panicBan($ckey); // Require verification for Persistence rounds
            return;
        }
        if (! isset($this->civ13->permitted[$ckey]) && ! isset($this->civ13->ages[$ckey]) && ! $this->civ13->checkByondAge($age = $this->civ13->getByondAge($ckey))) { // Force new accounts to register in Discord
            $ban = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Byond account `$ckey` must register on Discord and be manually approved to play. ($age)"];
            if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $this->civ13->ban($ban, null, null, true));
        }
    }


}