<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

/**
 * Handles the "ckeyinfo" command.
 * 
 *             
 * This method retrieves information about a ckey, including primary identifiers, IPs, CIDs, and dates.
 * It also iterates through playerlogs ban logs to find all known ckeys, IPs, and CIDs.
 * If the user has Ambassador privileges, it also displays primary IPs and CIDs.
 */
class CkeyInfo extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $id = Civ13::sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->civ13->reply($message, 'Invalid format! Please use the format: ckeyinfo `ckey`');
        if (! ($item = $this->civ13->verifier->getVerifiedItem($id) ?? []) && is_numeric($id)) return $this->civ13->reply($message, "No data found for Discord ID `$id`.");
        if (! $ckey = $item['ss13'] ?? $id) return $this->civ13->reply($message, "Invalid ckey `$ckey`.");
        if (! $collectionsArray = $this->civ13->getCkeyLogCollections($ckey)) return $this->civ13->reply($message, "No data found for ckey `$ckey`.");

        /** @var string[] */
        $ckeys = [$ckey];
        $ips = [];
        $cids = [];
        $dates = [];
        $ckey_age = [];
        // Get the ckey's primary identifiers and fill in any blanks
        foreach ($collectionsArray as $item) foreach ($item as $log) {
            if (isset($log['ip'])   && ! isset($ips[$log['ip']]))     $ips[$log['ip']]     = $log['ip'];
            if (isset($log['cid'])  && ! isset($cids[$log['cid']]))   $cids[$log['cid']]   = $log['cid'];
            if (isset($log['date']) && ! isset($dates[$log['date']])) $dates[$log['date']] = $log['date'];
        }

        $builder = Civ13::createBuilder();
        $embed = $this->civ13->createEmbed()->setTitle($ckey);
        if ($ckeys) {
            foreach ($ckeys as $c) ($age = $this->civ13->getByondAge($c)) ? $ckey_age[$c] = $age : $ckey_age[$c] = "N/A";
            $ckey_age_string = implode(', ', array_map(fn($key, $value) => "$key ($value)", array_keys($ckey_age), $ckey_age));
            if (strlen($ckey_age_string) > 1 && strlen($ckey_age_string) <= 1024) $embed->addFieldValues('Primary Ckeys', $ckey_age_string);
            elseif (strlen($ckey_age_string) > 1024) $builder->addFileFromContent('primary_ckeys.txt', $ckey_age_string);
        }
        if ($item && isset($item['ss13']) && $user = $this->civ13->verifier->getVerifiedUser($item['ss13']))
            $embed->setAuthor("{$user->username} ({$user->id})", $user->avatar);
        if ($high_staff = $this->civ13->hasRank($message->member, ['Owner', 'Chief Technical Officer', 'Ambassador'])) {
            $ips_string = implode(', ', $ips);
            $cids_string = implode(', ', $cids);
            if (strlen($ips_string) > 1 && strlen($ips_string) <= 1024) $embed->addFieldValues('Primary IPs', $ips_string, true);
            elseif (strlen($ips_string) > 1024) $builder->addFileFromContent('primary_ips.txt', $ips_string);
            if (strlen($cids_string) > 1 && strlen($cids_string) <= 1024) $embed->addFieldValues('Primary CIDs', $cids_string, true);
            elseif (strlen($cids_string) > 1024) $builder->addFileFromContent('primary_cids.txt', $cids_string);
        }
        if ($dates && strlen($dates_string = implode(', ', $dates)) <= 1024) $embed->addFieldValues('First Seen Dates', $dates_string);

        $found_ckeys = [];
        $found_ips = [];
        $found_cids = [];
        $found_dates = [];
        Civ13::updateCkeyinfoVariables($this->civ13->playerlogsToCollection(), $ckeys, $ips, $cids, $dates, $found_ckeys, $found_ips, $found_cids, $found_dates, true);
        Civ13::updateCkeyinfoVariables($this->civ13->bansToCollection(), $ckeys, $ips, $cids, $dates, $found_ckeys, $found_ips, $found_cids, $found_dates, false);

        if ($ckeys) {
            if ($ckey_age_string = implode(', ', array_map(fn($c) => "$c (" . ($ckey_age[$c] ?? ($this->civ13->getByondAge($c) !== false ? $this->civ13->getByondAge($c) : "N/A")) . ")", $ckeys))) {
                if (strlen($ckey_age_string) > 1 && strlen($ckey_age_string) <= 1024) $embed->addFieldValues('Matched Ckeys', trim($ckey_age_string));
                elseif (strlen($ckey_age_string) > 1025) $builder->addFileFromContent('matched_ckeys.txt', $ckey_age_string);
            }
        }
        if ($high_staff) {
            if ($ips && ($matched_ips_string = implode(', ', $ips)) !== $ips_string) {
                if (strlen($matched_ips_string) > 1 && strlen($matched_ips_string) <= 1024) $embed->addFieldValues('Matched IPs', $matched_ips_string, true);
                elseif (strlen($matched_ips_string) > 1024) $builder->addFileFromContent('matched_ips.txt', $matched_ips_string);
            }
            if ($cids && ($matched_cids_string = implode(', ', $cids)) !== $cids_string) {
                if (strlen($matched_cids_string) > 1 && strlen($cids_string) <= 1024) $embed->addFieldValues('Matched CIDs', $cids_string, true);
                elseif (strlen($matched_cids_string) > 1024) $builder->addFileFromContent('matched_cids.txt', $cids_string);
            }
        } else $builder->setContent('IPs and CIDs have been hidden for privacy reasons.');
        if ($ips && $regions_string = implode(', ', array_unique(array_map(fn($ip) => $this->civ13->getIpData($ip)['countryCode'] ?? 'unknown', $ips)))) {
            if (strlen($regions_string) > 1 && strlen($regions_string) <= 1024) $embed->addFieldValues('Regions', $regions_string, true);
            elseif (strlen($regions_string) > 1024) $builder->addFileFromContent('regions.txt', $regions_string);
        }
        if ($dates && ($matched_dates_string = implode(', ', $dates)) !== $dates_string) {
            if (strlen($matched_dates_string) > 1 && strlen($matched_dates_string) <= 1024) $embed->addFieldValues('Matched Dates', $matched_dates_string, true);
            elseif (strlen($matched_dates_string) > 1024) $builder->addFileFromContent('matched_dates.txt', $matched_dates_string);
        }
        $embed->addfieldValues('Verified', $this->civ13->verifier->get('ss13', $ckey) ? 'Yes' : 'No', true);
        if ($discord_string = implode(', ', array_filter(array_map(fn(string $c) => ($result = $this->civ13->verifier->get('ss13', $c)) ? "<@{$result['discord']}>" : null, $ckeys)))) {
            if (strlen($discord_string) > 1 && strlen($discord_string) <= 1024) $embed->addFieldValues('Discord', $discord_string, true);
            elseif (strlen($discord_string) > 1024) $builder->addFileFromContent('discord.txt', $discord_string);                
        }
        $embed->addfieldValues('Currently Banned', $this->civ13->bancheck($ckey) ? 'Yes' : 'No', true);
        $embed->addfieldValues('Alt Banned', $this->civ13->altbancheck($found_ckeys, $ckey) ? 'Yes' : 'No', true);
        $embed->addfieldValues('Ignoring banned alts or new account age', isset($this->civ13->permitted[$ckey]) ? 'Yes' : 'No', true);
        return $message->reply($builder->addEmbed($embed));
    }
}