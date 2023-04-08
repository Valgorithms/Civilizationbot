<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */ 

use Civ13\Civ13;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Embed\Embed;
use Discord\Parts\User\Activity;

$status_changer_random = function (Civ13 $civ13): bool
{ //on ready
    if (! $civ13->files['status_path']) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning('status_path is not defined');
        return false;
    }
    if (! $status_array = file($civ13->files['status_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning("unable to open file `{$civ13->files['status_path']}`");
        return false;
    }
    list($status, $type, $state) = explode('; ', $status_array[array_rand($status_array)]);
    if (! $status) return false;
    $activity = new Activity($civ13->discord, [ //Discord status            
        'name' => $status,
        'type' => (int) $type, //0, 1, 2, 3, 4 | Game/Playing, Streaming, Listening, Watching, Custom Status
    ]);
    $civ13->statusChanger($activity, $state);
    return true;
};
$status_changer_timer = function (Civ13 $civ13) use ($status_changer_random): void
{ //on ready
    $civ13->timers['status_changer_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(120, function() use ($civ13, $status_changer_random) { $status_changer_random($civ13); });
};

$host_nomads = function (Civ13 $civ13): void
{
    \execInBackground("python3 {$civ13->files['nomads_updateserverabspaths']}");
    \execInBackground("rm -f {$civ13->files['nomads_serverdata']}");
    \execInBackground("python3 {$civ13->files['nomads_killsudos']}");
    $civ13->discord->getLoop()->addTimer(30, function() use ($civ13) {
        \execInBackground("DreamDaemon {$civ13->files['nomads_dmb']} {$civ13->ports['nomads']} -trusted -webclient -logself &");
    });
};
$kill_nomads = function (Civ13 $civ13): void
{
    \execInBackground("python3 {$civ13->files['nomads_killciv13']}");
};
$restart_nomads = function (Civ13 $civ13) use ($kill_nomads, $host_nomads): void
{
    $kill_nomads($civ13);
    $host_nomads($civ13);
};
$host_tdm = function (Civ13 $civ13): void
{
    \execInBackground("python3 {$civ13->files['tdm_updateserverabspaths']}");
    \execInBackground("rm -f {$civ13->files['tdm_serverdata']}");
    \execInBackground("python3 {$civ13->files['tdm_killsudos']}");
    $civ13->discord->getLoop()->addTimer(30, function() use ($civ13) {
        \execInBackground("DreamDaemon {$civ13->files['tdm_dmb']} {$civ13->ports['tdm']} -trusted -webclient -logself &");
    });
};
$kill_tdm = function (Civ13 $civ13): void
{
    \execInBackground("python3 {$civ13->files['tdm_killciv13']}");
};
$restart_tdm = function (Civ13 $civ13) use ($kill_tdm, $host_tdm): void
{
    $kill_tdm($civ13);
    $host_tdm($civ13);
};
$host_pers = function (Civ13 $civ13): void
{
    \execInBackground("python3 {$civ13->files['pers_updateserverabspaths']}");
    \execInBackground("rm -f {$civ13->files['pers_serverdata']}");
    \execInBackground("python3 {$civ13->files['pers_killsudos']}");
    $civ13->discord->getLoop()->addTimer(30, function() use ($civ13) {
        \execInBackground("DreamDaemon {$civ13->files['pers_dmb']} {$civ13->ports['pers']} -trusted -webclient -logself &");
    });
};
$kill_pers = function (Civ13 $civ13): void
{
    \execInBackground("python3 {$civ13->files['pers_killciv13']}");
};
$restart_pers = function (Civ13 $civ13) use ($kill_pers, $host_pers): void
{
    $kill_pers($civ13);
    $host_pers($civ13);
};
$mapswap_nomads = function (Civ13 $civ13, string $mapto): bool
{
    if (! file_exists($civ13->files['map_defines_path']) || ! ($file = fopen($civ13->files['map_defines_path'], 'r'))) return false;
    
    $maps = array();
    while (($fp = fgets($file, 4096)) !== false) {
        $linesplit = explode(' ', trim(str_replace('"', '', $fp)));
        if (isset($linesplit[2]) && $map = trim($linesplit[2])) $maps[] = $map;
    }
    fclose($file);
    if (! in_array($mapto, $maps)) return false;
    
    \execInBackground("python3 {$civ13->files['mapswap_nomads']} $mapto");
    return true;
};
$mapswap_tdm = function (Civ13 $civ13, string $mapto): bool
{
    if (! file_exists($civ13->files['map_defines_path']) || ! ($file = fopen($civ13->files['map_defines_path'], 'r'))) return false;
    
    $maps = array();
    while (($fp = fgets($file, 4096)) !== false) {
        $linesplit = explode(' ', trim(str_replace('"', '', $fp)));
        if (isset($linesplit[2]) && $map = trim($linesplit[2])) $maps[] = $map;
    }
    fclose($file);
    if (! in_array($mapto, $maps)) return false;
    
    \execInBackground("python3 {$civ13->files['mapswap_tdm']} $mapto");
    return true;
};
$mapswap_pers = function (Civ13 $civ13, string $mapto): bool
{
    if (! file_exists($civ13->files['map_defines_path']) || ! ($file = fopen($civ13->files['map_defines_path'], 'r'))) return false;
    
    $maps = array();
    while (($fp = fgets($file, 4096)) !== false) {
        $linesplit = explode(' ', trim(str_replace('"', '', $fp)));
        if (isset($linesplit[2]) && $map = trim($linesplit[2])) $maps[] = $map;
    }
    fclose($file);
    if (! in_array($mapto, $maps)) return false;
    
    \execInBackground("python3 {$civ13->files['mapswap_pers']} $mapto");
    return true;
};

$log_handler = function (Civ13 $civ13, $message, string $message_content)
{
    $tokens = explode(';', $message_content);
    if (!in_array(trim($tokens[0]), ['nomads', 'tdm'])) return $message->reply('Please use the format `logs nomads;folder;file` or `logs tdm;folder;file`');
    if (trim($tokens[0]) == 'nomads') {
        unset($tokens[0]);
        $results = $civ13->FileNav($civ13->files['nomads_log_basedir'], $tokens);
    } else {
        unset($tokens[0]);
        $results = $civ13->FileNav($civ13->files['tdm_log_basedir'], $tokens);
    }
    if ($results[0]) return $message->reply(MessageBuilder::new()->addFile($results[1], 'log.txt'));
    if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
    if (! isset($results[2]) || ! $results[2]) return $message->reply('Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
    return $message->reply("{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
};
$banlog_handler = function (Civ13 $civ13, $message, string $message_content_lower)
{
    if (!in_array($message_content_lower, ['nomads', 'tdm', 'pers'])) return $message->reply('Please use the format `bans nomads` or `bans tdm');
    switch ($message_content_lower)
    {
        case 'nomads': return $message->reply(MessageBuilder::new()->addFile($civ13->files['nomads_bans'], 'bans.txt'));
        case 'tdm': return $message->reply(MessageBuilder::new()->addFile($civ13->files['tdm_bans'], 'bans.txt'));
        case 'pers': return $message->reply(MessageBuilder::new()->addFile($civ13->files['pers_bans'], 'bans.txt'));
    }
};

$ranking = function (Civ13 $civ13): false|string
{
    $line_array = array();
    if (! file_exists($civ13->files['ranking_path']) || ! ($search = fopen($civ13->files['ranking_path'], 'r'))) return false;
    while (($fp = fgets($search, 4096)) !== false) $line_array[] = $fp;
    fclose($search);

    $topsum = 1;
    $msg = '';
    foreach ($line_array as $line) {
        $sline = explode(';', trim(str_replace(PHP_EOL, '', $line)));
        $msg .= "($topsum): **{$sline[1]}** with **{$sline[0]}** points." . PHP_EOL;
        if (($topsum += 1) > 10) break;
    }
    return $msg;
};
$rankme = function (Civ13 $civ13, string $ckey): false|string
{
    $line_array = array();
    if (! file_exists($civ13->files['ranking_path']) || ! ($search = fopen($civ13->files['ranking_path'], 'r'))) return false;
    while (($fp = fgets($search, 4096)) !== false) $line_array[] = $fp;
    fclose($search);
    
    $found = false;
    $result = '';
    foreach ($line_array as $line) {
        $sline = explode(';', trim(str_replace(PHP_EOL, '', $line)));
        if ($sline[1] == $ckey) {
            $found = true;
            $result .= "**{$sline[1]}** has a total rank of **{$sline[0]}**";
        };
    }
    if (! $found) return "No medals found for ckey `$ckey`.";
    return $result;
};
$medals = function (Civ13 $civ13, string $ckey): false|string
{
    $result = '';
    if (! file_exists($civ13->files['tdm_awards_path']) || ! ($search = fopen($civ13->files['tdm_awards_path'], 'r'))) return false;
    $found = false;
    while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {  # remove '\n' at end of line
        $found = true;
        $duser = explode(';', $line);
        if ($duser[0] == $ckey) {
            switch ($duser[2]) {
                case 'long service medal': $medal_s = '<:long_service:705786458874707978>'; break;
                case 'combat medical badge': $medal_s = '<:combat_medical_badge:706583430141444126>'; break;
                case 'tank destroyer silver badge': $medal_s = '<:tank_silver:705786458882965504>'; break;
                case 'tank destroyer gold badge': $medal_s = '<:tank_gold:705787308926042112>'; break;
                case 'assault badge': $medal_s = '<:assault:705786458581106772>'; break;
                case 'wounded badge': $medal_s = '<:wounded:705786458677706904>'; break;
                case 'wounded silver badge': $medal_s = '<:wounded_silver:705786458916651068>'; break;
                case 'wounded gold badge': $medal_s = '<:wounded_gold:705786458845216848>'; break;
                case 'iron cross 1st class': $medal_s = '<:iron_cross1:705786458572587109>'; break;
                case 'iron cross 2nd class': $medal_s = '<:iron_cross2:705786458849673267>'; break;
                default:  $medal_s = '<:long_service:705786458874707978>';
            }
            $result .= "**{$duser[1]}:** {$medal_s} **{$duser[2]}**, *{$duser[4]}*, {$duser[5]}" . PHP_EOL;
        }
    }
    if ($result != '') return $result;
    if (! $found && ($result == '')) return 'No medals found for this ckey.';
};
$brmedals = function (Civ13 $civ13, string $ckey): string
{
    $result = '';
    if (! file_exists($civ13->files['tdm_awards_br_path']) || ! ($search = fopen($civ13->files['tdm_awards_br_path'], 'r'))) return 'Error getting file.';
    $found = false;
    while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {
        $found = true;
        $duser = explode(';', $line);
        if ($duser[0] == $ckey) $result .= "**{$duser[1]}:** placed *{$duser[2]} of {$duser[5]},* on {$duser[4]} ({$duser[3]})" . PHP_EOL;
    }
    if (! $found) return 'No medals found for this ckey.';
    return $result;
};

$tests = function (Civ13 $civ13, $message, string $message_content)
{
    $tokens = explode(' ', $message_content);
    if (! $tokens[0]) {
        if (empty($civ13->tests)) return $message->reply("No tests have been created yet! Try creating one with `tests test_key add {Your Test's Question}`");
        return $message->reply('Available tests: `' . implode('`, `', array_keys($civ13->tests)) . '`');
    }
    if (! isset($tokens[1]) || (! array_key_exists($test_key = $tokens[0], $civ13->tests) && $tokens[1] != 'add')) return $message->reply("Test `$test_key` hasn't been created yet! Please add a question first.");
    if ($tokens[1] == 'list') return $message->reply(MessageBuilder::new()->addFileFromContent("$test_key.txt", var_export($civ13->tests[$test_key], true)));
    if ($tokens[1] == 'add') {
        unset ($tokens[1], $tokens[0]);
        $civ13->tests[$test_key][] = $question = implode(' ', $tokens);
        $message->reply("Added question to test $test_key: $question");
        return $civ13->VarSave('tests.json', $civ13->tests);
    }
    if ($tokens[1] == 'remove') {
        if (! is_numeric($tokens[2])) return $message->replay("Invalid format! Please use the format `tests test_key remove #`");
        if (! isset($civ13->tests[$test_key][$tokens[2]])) return $message->reply("Question not found in test $test_key! Please use the format `tests test_key remove #`");
        $message->reply("Removed question {$tokens[2]}: {$civ13->tests[$test_key][$tokens[2]]}");
        unset($civ13->tests[$test_key][$tokens[2]]);
        return $civ13->VarSave('tests.json', $civ13->tests);
    }
    if ($tokens[1] == 'post') {
        if (! is_numeric($tokens[2])) return $message->replay("Invalid format! Please use the format `tests test_key post #`");
        if (count($civ13->tests[$test_key])<$tokens[2]) return $message->replay("Can't return more questions than exist in a test!");
        $questions = [];
        while (count($questions)<$tokens[2]) if (! in_array($civ13->tests[$test_key][($rand = array_rand($civ13->tests[$test_key]))], $questions)) $questions[] = $civ13->tests[$test_key][$rand];
        return $message->reply("$test_key test:" . PHP_EOL . implode(PHP_EOL, $questions));
    }
    if ($tokens[1] == 'delete') {
        $message->reply("Deleted test `$test_key`");
        unset($civ13->tests[$test_key]);
        return $civ13->VarSave('tests.json', $civ13->tests);
    }
};

$banlog_update = function (string $banlog, array $playerlogs, $ckey = null): string
{
    $temp = [];
    $oldlist = [];
    foreach (explode('|||', $banlog) as $bsplit) {
        $ban = explode(';', trim($bsplit));
        if (isset($ban[9]))
            if (!isset($ban[9]) || !isset($ban[10]) || $ban[9] == '0' || $ban[10] == '0') {
                if (! $ckey) $temp[$ban[8]][] = $bsplit;
                elseif ($ckey == $ban[8]) $temp[$ban[8]][] = $bsplit;
            } else $oldlist[] = $bsplit;
    }
    foreach ($playerlogs as $playerlog)
    foreach (explode('|', $playerlog) as $lsplit) {
        $log = explode(';', trim($lsplit));
        foreach (array_values($temp) as &$b2) foreach ($b2 as &$arr) {
            $a = explode(';', $arr);
            if($a[8] == $log[0]) {
                $a[9] = $log[2];
                $a[10] = $log[1];
                $arr = implode(';', $a);
            }
        }
    }

    $updated = [];
    foreach (array_values($temp) as $ban)
        if (is_array($ban)) foreach (array_values($ban) as $b) $updated[] = $b;
        else $updated[] = $ban;
    
    if (empty($updated)) return preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", PHP_EOL, trim(implode('|||' . PHP_EOL, $oldlist))) . '|||' . PHP_EOL;
    return trim(preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", PHP_EOL, implode('|||' . PHP_EOL, array_merge($oldlist, $updated)))) . '|||' . PHP_EOL;
};

$rank_check = function (Civ13 $civ13, $message, array $allowed_ranks, $verbose = true): bool
{
    $resolved_ranks = [];
    foreach ($allowed_ranks as $rank) $resolved_ranks[] = $civ13->role_ids[$rank];
    foreach ($message->member->roles as $role) if (in_array($role->id, $resolved_ranks)) return true;
    //$message->reply('Rejected! You need to have at least the [' . ($message->guild->roles ? $message->guild->roles->get('id', $civ13->role_ids[array_pop($resolved_ranks)])->name : array_pop($allowed_ranks)) . '] rank.');
    if ($verbose) $message->reply('Rejected! You need to have at least the <@&' . $civ13->role_ids[array_pop($allowed_ranks)] . '> rank.');
    return false;
};
$guild_message = function (Civ13 $civ13, $message, string $message_content, string $message_content_lower) use ($rank_check, $kill_nomads, $kill_tdm, $kill_pers, $host_nomads, $host_tdm, $host_pers, $restart_nomads, $restart_tdm, $restart_pers, $mapswap_nomads, $mapswap_tdm, $mapswap_pers, $log_handler, $banlog_handler, $ranking, $rankme, $medals, $brmedals, $tests, $banlog_update)
{
    if (! $message->member) return $message->reply('Error! Unable to get Discord Member class.');
    
    if (str_starts_with($message_content_lower, 'approveme')) {
        if ($message->member->roles->has($civ13->role_ids['infantry']) || $message->member->roles->has($civ13->role_ids['veteran'])) return $message->reply('You already have the verification role!');
        if ($item = $civ13->getVerifiedItem($message->member->id)) {
            $message->member->setRoles([$civ13->role_ids['infantry']], "approveme {$item['ss13']}");
            return $message->react("👍");
        }
        if (! $ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 9)))) return $message->reply('Invalid format! Please use the format `approveme ckey`');
        return $message->reply($civ13->verifyProcess($ckey, $message->member->id));
    }
    if (str_starts_with($message_content_lower, 'byondinfo')) {
        $high_staff = $rank_check($civ13, $message, ['admiral', 'captain'], false);
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (is_numeric($id = trim(str_replace(['<@!', '<@', '>', '.', '_', ' '], '', substr($message_content_lower, strlen('byondinfo')))))) {
            if ($item = $civ13->getVerifiedItem($id)) $ckey = $item['ss13'];
            else return $message->reply("No data found for Discord ID `$id`.");
        } elseif (! $ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 9)))) return $message->reply('Invalid format! Please use the format: ckeyinfo `ckey`');
        if (! $collectionsArray = $civ13->getCkeyLogCollections($ckey)) return $message->reply('No data found for that ckey.');
        $civ13->logger->debug('Collections array:', $collectionsArray, PHP_EOL);

        $embed = new Embed($civ13->discord);
        $embed->setTitle($ckey);
        if ($item = $civ13->getVerifiedItem($ckey)) {
            $ckey = $item['ss13'];
            if ($member = $civ13->getVerifiedMember($item))
                $embed->setAuthor("{$member->user->displayname} ({$member->id})", $member->avatar);
        }
        $ckeys = [$ckey];
        $ips = [];
        $cids = [];
        $dates = [];
        foreach ($collectionsArray[0] as $log) { //Get the ckey's primary identifiers
            if (isset($log['ip']) && !in_array($log['ip'], $ips)) $ips[] = $log['ip'];
            if (isset($log['cid']) && !in_array($log['cid'], $cids)) $cids[] = $log['cid'];
            if (isset($log['date']) && !in_array($log['date'], $dates)) $dates[] = $log['date'];
        }
        foreach ($collectionsArray[1] as $log) { //Get the ckey's primary identifiers
            if (isset($log['ip']) && !in_array($log['ip'], $ips)) $ips[] = $log['ip'];
            if (isset($log['cid']) && !in_array($log['cid'], $cids)) $cids[] = $log['cid'];
            if (isset($log['date']) && !in_array($log['date'], $dates)) $dates[] = $log['date'];
        }
        $civ13->logger->debug('Primary identifiers:', $ckeys, $ips, $cids, $dates, PHP_EOL);
        if (!empty($ckeys)) $embed->addFieldValues('Primary Ckeys', implode(', ', $ckeys));
        if ($high_staff) {
            if (!empty($ips)) $embed->addFieldValues('Primary IPs', implode(', ', $ips));
            if (!empty($cids)) $embed->addFieldValues('Primary CIDs', implode(', ', $cids));
        }
        if (!empty($dates)) $embed->addFieldValues('Primary Dates', implode(', ', $dates));

        //Iterate through the playerlogs ban logs to find all known ckeys, ips, and cids
        $playerlogs = $civ13->playerlogsToCollection(); //This is ALL players
        $i = 0;
        $break = false;
        do { //Iterate through playerlogs to find all known ckeys, ips, and cids
            $found = false;
            $found_ckeys = [];
            $found_ips = [];
            $found_cids = [];
            $found_dates = [];
            foreach ($playerlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                $civ13->logger->debug('Found new match:', $log, PHP_EOL);
                if (!in_array($log['ckey'], $ckeys)) { $found_ckeys[] = $log['ckey']; $found = true; }
                if (!in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                if (!in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
                if (!in_array($log['date'], $dates)) { $found_dates[] = $log['date']; }
            }
            $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
            $ips = array_unique(array_merge($ips, $found_ips));
            $cids = array_unique(array_merge($cids, $found_cids));
            $dates = array_unique(array_merge($dates, $found_dates));
            if ($i > 10) $break = true;
            $i++;
        } while ($found && ! $break); //Keep iterating until no new ckeys, ips, or cids are found

        $banlogs = $civ13->bansToCollection();
        $civ13->bancheck($ckey) ? $banned = 'Yes' : $banned = 'No';
        $found = true;
        $i = 0;
        $break = false;
        do { //Iterate through playerlogs to find all known ckeys, ips, and cids
            $found = false;
            $found_ckeys = [];
            $found_ips = [];
            $found_cids = [];
            $found_dates = [];
            foreach ($banlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                $civ13->logger->debug('Found new match: ', $log, PHP_EOL);
                if (!in_array($log['ckey'], $ips)) { $found_ckeys[] = $log['ckey']; $found = true; }
                if (!in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                if (!in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
                if (!in_array($log['date'], $dates)) { $found_dates[] = $log['date']; }
            }
            $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
            $ips = array_unique(array_merge($ips, $found_ips));
            $cids = array_unique(array_merge($cids, $found_cids));
            $dates = array_unique(array_merge($dates, $found_dates));
            if ($i > 10) $break = true;
            $i++;
        } while ($found && ! $break); //Keep iterating until no new ckeys, ips, or cids are found
        $altbanned = 'No';
        foreach ($ckeys as $key) if ($key != $ckey) if ($civ13->bancheck($key)) { $altbanned = 'Yes'; break; }

        $verified = 'No';
        if ($civ13->verified->get('ss13', $ckey)) $verified = 'Yes';
        if (!empty($ckeys)) $embed->addFieldValues('Matched Ckeys', implode(', ', $ckeys));
        if ($high_staff) {
            if (!empty($ips)) $embed->addFieldValues('Matched IPs', implode(', ', $ips));
            if (!empty($cids)) $embed->addFieldValues('Matched CIDs', implode(', ', $cids));
        }
        if (!empty($dates) && strlen($dates_string = implode(', ', $dates)) <= 1024) $embed->addFieldValues('Dates', $dates_string);
        $embed->addfieldValues('Verified', $verified);
        $embed->addfieldValues('Currently Banned', $banned);
        $embed->addfieldValues('Alt Banned', $altbanned);
        $builder = MessageBuilder::new();
        if (! $high_staff) $builder->setContent('IPs and CIDs have been hidden for privacy reasons.');
        $builder->addEmbed($embed);
        $message->reply($builder);
    }
    if (str_starts_with($message_content_lower, 'fullbancheck')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        foreach ($message->guild->members as $member)
            if ($item = $civ13->getVerifiedItem($member->id))
                $civ13->bancheck($item['ss13']);
        return $message->react("👍");
    }
    if (str_starts_with($message_content_lower, 'fullaltcheck')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $ckeys = [];
        $members = $message->guild->members->filter(function ($member) use ($civ13) { return !$member->roles->has($civ13->role_ids['banished']); });
        foreach ($members as $member)
            if ($item = $civ13->getVerifiedItem($member->id)) {
                $array = $civ13->byondinfo($item['ss13']);
                if (count($array[0]) > 1)
                    $ckeys = array_unique(array_merge($ckeys, $array[0]));
            }
        return $message->reply("The following ckeys are alt accounts of unbanned verified players:" . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $ckeys) . '`');
    }
    if ($message_content_lower == 'permitted') {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (empty($civ13->permitted)) return $message->reply('No users have been permitted to bypass the Byond account age requirement.');
        return $message->reply('The following ckeys are now permitted to bypass the Byond account limit and age requirements: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', array_keys($civ13->permitted)) . '`');
    }
    if (str_starts_with($message_content_lower, 'permit')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $civ13->permitCkey($ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 6))));
        return $message->reply("$ckey is now permitted to bypass the Byond account age requirement.");
    }
    if (str_starts_with($message_content_lower, 'unpermit')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $civ13->permitCkey($ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 8))), false);
        return $message->reply("$ckey is no longer permitted to bypass the Byond account age requirement.");
    }    

    if (str_starts_with($message_content_lower, 'tests')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌"); 
        return $tests($civ13, $message, trim(substr($message_content, strlen('tests'))));
    }
    
    if (str_starts_with($message_content_lower, 'promotable')) {
        if (! $promotable_check = $civ13->functions['misc']['promotable_check']) return $message->react("🔥");
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌"); 
        if (! $promotable_check($civ13, trim(substr($message_content, 10)))) return $message->react("👎");
        return $message->react("👍");
    }
    
    if (str_starts_with($message_content_lower, 'mass_promotion_loop')) {
        if (! $mass_promotion_loop = $civ13->functions['misc']['mass_promotion_loop']) return $message->react("🔥");
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌"); 
        if (! $mass_promotion_loop($civ13)) return $message->react("👎");
        return $message->react("👍");
    }
    
    if (str_starts_with($message_content_lower, 'mass_promotion_check')) {
        if (! $mass_promotion_check = $civ13->functions['misc']['mass_promotion_check']) return $message->react("🔥");
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌"); 
        if ($promotables = $mass_promotion_check($civ13)) return $message->reply(MessageBuilder::new()->addFileFromContent('promotables.txt', json_encode($promotables)));
        return $message->react("👎");
    }
    
    if (str_starts_with($message_content_lower, 'refresh')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if ($civ13->getVerified()) return $message->react("👍");
        return $message->react("👎");
    }
    if (str_starts_with($message_content_lower, 'ban ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content = substr($message_content, 4);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        $result = $civ13->ban([$split_message[0], $split_message[1], $split_message[2] . " Appeal at {$civ13->banappeal}"], $message);
        
        if (! $tdm_playerlogs = file_get_contents($civ13->files['tdm_playerlogs'])) return $message->react("🔥");
        if (! $nomads_playerlogs = file_get_contents($civ13->files['nomads_playerlogs'])) return $message->react("🔥");
        $civ13->timers['banlog_update_tdm'] = $civ13->discord->getLoop()->addPeriodicTimer(30, function() use ($civ13, $banlog_update, $nomads_playerlogs, $tdm_playerlogs, $split_message) {
            file_put_contents($civ13->files['tdm_bans'], $banlog_update(file_get_contents($civ13->files['tdm_bans']), [$nomads_playerlogs, $tdm_playerlogs], $split_message[0]));
        });
        $civ13->timers['banlog_update_nomads'] = $civ13->discord->getLoop()->addPeriodicTimer(60, function() use ($civ13, $banlog_update, $nomads_playerlogs, $tdm_playerlogs, $split_message) {
            file_put_contents($civ13->files['nomads_bans'], $banlog_update(file_get_contents($civ13->files['nomads_bans']), [$nomads_playerlogs, $tdm_playerlogs], $split_message[0]));
        });
        
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'nomadsban ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content = substr($message_content, 10);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        $result = $civ13->banNomads([$split_message[0], $split_message[1], $split_message[2] . " Appeal at {$civ13->banappeal}"], $message);
        if ($member = $civ13->getVerifiedMember('id', $split_message[0]))
            if (! $member->roles->has($civ13->role_ids['banished']))
                $member->addRole($civ13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'tdmban ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content = substr($message_content, 7);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        $result = $civ13->banTDM([$split_message[0], $split_message[1], $split_message[2] . " Appeal at {$civ13->banappeal}"], $message);
        if ($member = $civ13->getVerifiedMember('id', $split_message[0])) 
            if (! $member->roles->has($civ13->role_ids['banished']))
                $member->addRole($civ13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'persban ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content = substr($message_content, 7);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        $result = $civ13->banPers([$split_message[0], $split_message[1], $split_message[2] . " Appeal at {$civ13->banappeal}"], $message);
        if ($member = $civ13->getVerifiedMember('id', $split_message[0])) 
            if (! $member->roles->has($civ13->role_ids['banished']))
                $member->addRole($civ13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unban ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (is_numeric($ckey = trim(str_replace(['<@!', '<@', '>', '.', '_', ' '], '', substr($message_content_lower, strlen('unban'))))))
            if (! $item = $civ13->getVerifiedItem($id)) return $message->reply("No data found for Discord ID `$ckey`.");
            else $ckey = $item['ckey'];
        $civ13->unban($ckey, $message->author->displayname);
        return $message->reply("**{$message->author->displayname}** unbanned **$ckey**");
    }
    if (str_starts_with($message_content_lower, 'unbannomads ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (is_numeric($ckey = trim(str_replace(['<@!', '<@', '>', '.', '_', ' '], '', substr($message_content_lower, strlen('unbannomads'))))))
            if (! $item = $civ13->getVerifiedItem($id)) return $message->reply("No data found for Discord ID `$ckey`.");
            else $ckey = $item['ckey'];
        
        $civ13->unbanNomads($ckey, $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$ckey}** from **Nomads**";
        if ($member = $civ13->getVerifiedMember('id', $ckey))
            if ($member->roles->has($civ13->role_ids['banished']))
                $member->removeRole($civ13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unbantdm ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (is_numeric($ckey = trim(str_replace(['<@!', '<@', '>', '.', '_', ' '], '', substr($message_content_lower, strlen('unbantdm'))))))
            if (! $item = $civ13->getVerifiedItem($id)) return $message->reply("No data found for Discord ID `$ckey`.");
            else $ckey = $item['ckey'];
        
        $civ13->unbanTDM($ckey, $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$ckey}** from **TDM**";
        if ($member = $civ13->getVerifiedMember('id', $ckey)) 
            if ($member->roles->has($civ13->role_ids['banished']))
                $member->removeRole($civ13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unbanpers ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (is_numeric($ckey = trim(str_replace(['<@!', '<@', '>', '.', '_', ' '], '', substr($message_content_lower, strlen('unbanpers'))))))
            if (! $item = $civ13->getVerifiedItem($id)) return $message->reply("No data found for Discord ID `$ckey`.");
            else $ckey = $item['ckey'];
        
        $civ13->unbanPers($ckey, $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$ckey}** from **Persistence**";
        if ($member = $civ13->getVerifiedMember('id', $ckey)) 
            if ($member->roles->has($civ13->role_ids['banished']))
                $member->removeRole($civ13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'hostnomads')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $host_nomads($civ13);
        return $message->reply("Attempting to update and bring up Nomads <byond://{$civ13->ips['nomads']}:{$civ13->ports['nomads']}>");
    }
    if (str_starts_with($message_content_lower, 'hosttdm')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $host_tdm($civ13);
        return $message->reply("Attempting to update and bring up TDM <byond://{$civ13->ips['tdm']}:{$civ13->ports['tdm']}>");
    }
    if (str_starts_with($message_content_lower, 'hostpers')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $host_pers($civ13);
        return $message->reply("Attempting to update and bring up Persistence <byond://{$civ13->ips['pers']}:{$civ13->ports['pers']}>");
    }
    if (str_starts_with($message_content_lower, 'restartnomads')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $restart_nomads($civ13);
        return $message->reply("Attempted to kill, update, and bring up Nomads <byond://{$civ13->ips['nomads']}:{$civ13->ports['nomads']}>");
    }
    if (str_starts_with($message_content_lower, 'restarttdm')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $restart_tdm($civ13);
        return $message->reply("Attempted to kill, update, and bring up TDM <byond://{$civ13->ips['tdm']}:{$civ13->ports['tdm']}>");
    }
    if (str_starts_with($message_content_lower, 'restartpers')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $restart_pers($civ13);
        return $message->reply("Attempted to kill, update, and bring up pers <byond://{$civ13->ips['pers']}:{$civ13->ports['pers']}>");
    }
    if (str_starts_with($message_content_lower, 'killnomads')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $kill_nomads($civ13);
        return $message->reply('Attempted to kill the Nomads server.');
    }
    if (str_starts_with($message_content_lower, 'killtdm')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $kill_tdm($civ13);
        return $message->reply('Attempted to kill the TDM server.');
    }
    if (str_starts_with($message_content_lower, 'killpers')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $kill_pers($civ13);
        return $message->reply('Attempted to kill the TDM server.');
    }
    if (str_starts_with($message_content_lower, 'mapswapnomads')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $split_message = explode('mapswapnomads ', $message_content);
        if (count($split_message) < 2 || !($mapto = strtoupper($split_message[1]))) return $message->reply('You need to include the name of the map.');
        if (! $mapswap_nomads($civ13, $mapto, $message)) return $message->reply("$mapto was not found in the map definitions.");
        return $message->reply("Attempting to change map to $mapto");
    }
    if (str_starts_with($message_content_lower, 'mapswaptdm')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $split_message = explode('mapswaptdm ', $message_content);
        if (count($split_message) < 2 || !($mapto = strtoupper($split_message[1]))) return $message->reply('You need to include the name of the map.');
        if (! $mapswap_tdm($civ13, $mapto, $message)) return $message->reply("$mapto was not found in the map definitions.");
        return $message->reply("Attempting to change map to $mapto");
    }
    if (str_starts_with($message_content_lower, 'mapswappers')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $split_message = explode('mapswappers ', $message_content);
        if (count($split_message) < 2 || !($mapto = strtoupper($split_message[1]))) return $message->reply('You need to include the name of the map.');
        if (! $mapswap_pers($civ13, $mapto, $message)) return $message->reply("$mapto was not found in the map definitions.");
        return $message->reply("Attempting to change map to $mapto");
    }
    if (str_starts_with($message_content_lower, 'maplist')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (! file_exists($civ13->files['map_defines_path'])) return $message->react("🔥");
        return $message->reply(MessageBuilder::new()->addFile($civ13->files['map_defines_path'], 'maps.txt'));
    }
    if (str_starts_with($message_content_lower, 'banlist')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (! file_exists($civ13->files['tdm_bans'])) return $message->react("🔥");
        return $message->reply(MessageBuilder::new()->addFile($civ13->files['tdm_bans'], 'bans.txt'));
    }
    if (str_starts_with($message_content_lower, 'adminlist')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (! file_exists($civ13->files['nomads_admins'])) return $message->react("🔥");
        return $message->reply(MessageBuilder::new()->addFile($civ13->files['nomads_admins'], 'nomads_admins.txt')->addFile($civ13->files['tdm_admins'], 'tdm_admins.txt'));
    }
    if (str_starts_with($message_content_lower, 'factionlist')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        
        $builder = MessageBuilder::new()->setContent('Faction Lists');
        if (file_exists($civ13->files['tdm_factionlist'])) $builder->addfile($civ13->files['tdm_factionlist'], 'tdm_factionlist.txt');
        if (file_exists($civ13->files['nomads_factionlist'])) $builder->addfile($civ13->files['nomads_factionlist'], 'nomads_factionlist.txt');
        return $message->reply($builder);
    }
    if (str_starts_with($message_content_lower, 'sportsteams')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (! file_exists($civ13->files['sportsteams'])) return $message->react("🔥");
        return $message->reply(MessageBuilder::new()->addFile($civ13->files['sportsteams'], 'sports_teams.txt'));
    }
    if (str_starts_with($message_content_lower, 'logs')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if ($log_handler($civ13, $message, trim(substr($message_content, 4)))) return;
    }
    if (str_starts_with($message_content_lower, 'playerlogs')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $tokens = explode(';', trim(substr($message_content, 10)));
        if (!in_array(trim($tokens[0]), ['nomads', 'tdm', 'pers'])) return $message->reply('Please use the format `playerslogs nomads` or `playerlogs tdm`');
        switch ($tokens[0])
        {
            case 'nomads':
                if (! is_file($civ13->files['nomads_playerlogs'])) return $message->react("🔥");
                return $message->reply(MessageBuilder::new()->addFile($civ13->files['nomads_playerlogs'], 'playerlogs.txt'));
            case 'tdm':
                if (! is_file($civ13->files['tdm_playerlogs'])) return $message->react("🔥");
                return $message->reply(MessageBuilder::new()->addFile($civ13->files['tdm_playerlogs'], 'playerlogs.txt'));
            case 'pers':
                if (! is_file($civ13->files['pers_playerlogs'])) return $message->react("🔥");
                return $message->reply(MessageBuilder::new()->addFile($civ13->files['pers_playerlogs'], 'playerlogs.txt'));
        }
    }
    if (str_starts_with($message_content_lower, 'bans')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if ($banlog_handler($civ13, $message, trim(substr($message_content_lower, 4)))) return;
    }

    if (str_starts_with($message_content_lower, 'stop')) {
        if ($rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        return $message->react("🛑")->done(function () use ($civ13) { $civ13->stop(); });
    }

    if (str_starts_with($message_content_lower, 'ts')) {
        if (! $state = trim(substr($message_content_lower, strlen('ts')))) return $message->reply('Wrong format. Please try `ts on` or `ts off`.');
        if (! in_array($state, ['on', 'off'])) return $message->reply('Wrong format. Please try `ts on` or `ts off`.');
        if (! $rank_check($civ13, $message, ['admiral'])) return $message->react("❌");
        
        if ($state == 'on') {
            \execInBackground("cd {$civ13->files['typespess_path']}");
            \execInBackground('git pull');
            \execInBackground("sh {$civ13->files['typespess_launch_server_path']}&");
            return $message->reply('Put **TypeSpess Civ13** test server on: http://civ13.com/ts');
        } else {
            \execInBackground('killall index.js');
            return $message->reply('**TypeSpess Civ13** test server down.');
        }
    }

    if (str_starts_with($message_content_lower, 'ranking')) {
        if (! $civ13->recalculateRanking()) return $message->reply('There was an error trying to recalculate ranking! The bot may be misconfigured.');
        if (! $msg = $ranking($civ13)) return $message->reply('There was an error trying to recalculate ranking!');
        if (strlen($msg)<=2000) return $message->reply($msg);
        if (strlen($msg)<=4096) {
            $embed = new Embed($civ13->discord);
            $embed->setDescription($msg);
            return $message->channel->sendEmbed($embed);
        }
        return $message->reply("The ranking is too long to display.");
    }
    if (str_starts_with($message_content_lower, 'rankme')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('rankme'))))) return $message->reply('Wrong format. Please try `rankme [ckey]`.');
        if (! $civ13->recalculateRanking()) return $message->reply('There was an error trying to recalculate ranking! The bot may be misconfigured.');
        if (! $msg = $rankme($civ13, $ckey)) return $message->reply('There was an error trying to get your ranking!');
        if (strlen($msg)<=2000) return $message->reply($msg);
        if (strlen($msg)<=4096) {
            $embed = new Embed($civ13->discord);
            $embed->setAuthor($ckey);
            $embed->setDescription($msg);
            return $message->channel->sendEmbed($embed);
        }
        return $message->reply("Your ranking is too long to display.");
    }
    if (str_starts_with($message_content_lower, 'medals')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('medals'))))) return $message->reply('Wrong format. Please try `medals [ckey]`.');
        if (! $msg = $medals($civ13, $ckey)) return $message->reply('There was an error trying to get your medals!');
        if (strlen($msg)<=2000) return $message->reply($msg); //Try embed description? 4096 characters
        if (strlen($msg)<=4096) {
            $embed = new Embed($civ13->discord);
            $embed->setAuthor($ckey);
            $embed->setDescription($msg);
            return $message->channel->sendEmbed($embed);
        }
        return $message->reply("Too many medals to display.");
    }
    if (str_starts_with($message_content_lower, 'brmedals')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('brmedals'))))) return $message->reply('Wrong format. Please try `brmedals [ckey]`.');
        if (! $msg = $brmedals($civ13, $ckey)) return $message->reply('There was an error trying to get your medals!');
        if (strlen($msg)<=2000) return $message->reply($msg);
        if (strlen($msg)<=4096) {
            $embed = new Embed($civ13->discord);
            $embed->setAuthor($ckey);
            $embed->setDescription($msg);
            return $message->channel->sendEmbed($embed);
        }
        return $message->reply("Too many medals to display.");
    }

    if (str_starts_with($message_content_lower, 'update bans')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌"); 
        if (! $tdm_bans = file_get_contents($civ13->files['tdm_bans'])) return $message->react("🔥");
        if (! $nomads_bans = file_get_contents($civ13->files['nomads_bans'])) return $message->react("🔥");
        if (! $tdm_playerlogs = file_get_contents($civ13->files['tdm_playerlogs'])) return $message->react("🔥");
        if (! $nomads_playerlogs = file_get_contents($civ13->files['nomads_playerlogs'])) return $message->react("🔥");
        $tdm = $banlog_update($tdm_bans, [$nomads_playerlogs, $tdm_playerlogs]);
        $tdm = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $tdm);
        file_put_contents($civ13->files['tdm_bans'], $tdm);
        $nomads = $banlog_update($nomads_bans, [$nomads_playerlogs, $tdm_playerlogs]);
        $nomads = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $nomads);
        file_put_contents($civ13->files['nomads_bans'], $nomads);
        return $message->react("👍");
    }
    if ($message_content_lower == 'panic') {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        return $message->reply('Panic bunker is now ' . (($civ13->panic_bunker = ! $civ13->panic_bunker) ? 'enabled.' : 'disabled.'));
    }
};

$nomads_discord2ooc = function (Civ13 $civ13, $author, $string): bool
{
    if (! file_exists($civ13->files['nomads_discord2ooc']) || ! ($file = fopen($civ13->files['nomads_discord2ooc'], 'a'))) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true; 
};
$tdm_discord2ooc = function (Civ13 $civ13, $author, $string): bool
{
    if (! file_exists($civ13->files['tdm_discord2ooc']) || ! ($file = fopen($civ13->files['tdm_discord2ooc'], 'a'))) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true; 
};
$nomads_discord2admin = function (Civ13 $civ13, $author, $string): bool
{
    if (! file_exists($civ13->files['nomads_discord2admin']) || ! ($file = fopen($civ13->files['nomads_discord2admin'], 'a'))) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$tdm_discord2admin = function (Civ13 $civ13, $author, $string): bool
{
    if (! file_exists($civ13->files['tdm_discord2admin']) || ! $file = fopen($civ13->files['tdm_discord2admin'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$nomads_discord2dm = function (Civ13 $civ13, $author, $string): bool
{
    if (! file_exists($civ13->files['nomads_discord2dm']) || ! $file = fopen($civ13->files['nomads_discord2dm'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$tdm_discord2dm = function (Civ13 $civ13, $author, $string): bool
{
    if (! file_exists($civ13->files['tdm_discord2dm']) || ! $file = fopen($civ13->files['tdm_discord2dm'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$on_message = function (Civ13 $civ13, $message) use ($guild_message, $nomads_discord2ooc, $tdm_discord2ooc, $nomads_discord2admin, $tdm_discord2admin, $nomads_discord2dm, $tdm_discord2dm)
{ // on message
    if ($message->guild->owner_id != $civ13->owner_id) return; //Only process commands from a guild that Taislin owns
    if (! $civ13->command_symbol) $civ13->command_symbol = '!s';
    
    $message_content = '';
    $message_content_lower = '';
    if (str_starts_with($message->content, $civ13->command_symbol . ' ')) { //Add these as slash commands?
        $message_content = substr($message->content, strlen($civ13->command_symbol)+1);
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, "<@!{$civ13->discord->id}>")) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($civ13->discord->id)+4));
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, "<@{$civ13->discord->id}>")) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($civ13->discord->id)+3));
        $message_content_lower = strtolower($message_content);
    }
    if (! $message_content) return;
    
    if (str_starts_with($message_content_lower, 'ping')) return $message->reply('Pong!');
    if (str_starts_with($message_content_lower, 'help')) return $message->reply('**List of Commands**: ckey, bancheck, insult, cpu, ping, (un)whitelistme, rankme, ranking. **Staff only**: ban, logs, hostnomads, killnomads, restartnomads, mapswapnomads, hosttdm, killtdm, restarttdm, mapswaptdm, panic bunker');
    if (str_starts_with($message_content_lower, 'cpu')) {
         if (PHP_OS_FAMILY == "Windows") {
            $p = shell_exec('powershell -command "gwmi Win32_PerfFormattedData_PerfOS_Processor | select PercentProcessorTime"');
            $p = preg_replace('/\s+/', ' ', $p); //reduce spaces
            $p = str_replace('PercentProcessorTime', '', $p);
            $p = str_replace('--------------------', '', $p);
            $p = preg_replace('/\s+/', ' ', $p); //reduce spaces
            $load_array = explode(' ', $p);

            $x=0;
            $load = '';
            foreach ($load_array as $line) if (trim($line) && $x == 0) { $load = "CPU Usage: $line%" . PHP_EOL; break; }
            return $message->reply($load);
        } else { //Linux
            $cpu_load = ($cpu_load_array = sys_getloadavg()) ? $cpu_load = array_sum($cpu_load_array) / count($cpu_load_array) : '-1';
            return $message->reply("CPU Usage: $cpu_load%");
        }
        return $message->reply('Unrecognized operating system!');
    }
    if (str_starts_with($message_content_lower, 'insult')) {
        $split_message = explode(' ', $message_content); //$split_target[1] is the target
        if ((count($split_message) <= 1 ) || ! strlen($split_message[1] === 0)) return;
        if (! file_exists($civ13->files['insults_path']) || ! ($file = @fopen($civ13->files['insults_path'], 'r'))) return $message->react("🔥");
        $insults_array = array();
        while (($fp = fgets($file, 4096)) !== false) $insults_array[] = $fp;
        if (count($insults_array) > 0) return $message->channel->sendMessage(MessageBuilder::new()->setContent($split_message[1] . ', ' . $insults_array[rand(0, count($insults_array)-1)])->setAllowedMentions(['parse'=>[]]));
        return $message->reply('No insults found!');
    }
    if (str_starts_with($message_content_lower, 'ooc ')) {
        $message_filtered = substr($message_content, 4);
        switch (strtolower($message->channel->name)) {
            case 'ooc-nomads':                    
                if (! $nomads_discord2ooc($civ13, $message->author->displayname, $message_filtered)) return $message->react("🔥");
                return $message->react("📧");
            case 'ooc-tdm':
                if (! $tdm_discord2ooc($civ13, $message->author->displayname, $message_filtered)) return $message->react("🔥");
                return $message->react("📧");
            default:
                return $message->reply('You need to be in either the #ooc-nomads or #ooc-tdm channel to use this command.');
        }
    }
    if (str_starts_with($message_content_lower, 'asay ')) {
        $message_filtered = substr($message_content, 5);
        switch (strtolower($message->channel->name)) {
            case 'ahelp-nomads':
                if (! $nomads_discord2admin($civ13, $message->author->displayname, $message_filtered)) return $message->react("🔥");
                return $message->react("📧");
            case 'ahelp-tdm':
                if (! $tdm_discord2admin($civ13, $message->author->displayname, $message_filtered)) return $message->react("🔥");
                return $message->react("📧");
            default:
                return $message->reply('You need to be in either the #ahelp-nomads or #ahelp-tdm channel to use this command.');
        }
    }
    if (str_starts_with($message_content_lower, 'dm ') || str_starts_with($message_content_lower, 'pm ')) {
        $split_message = explode(': ', substr($message_content, 3));
        switch (strtolower($message->channel->name)) {
            case 'ahelp-nomads':
                if (! $nomads_discord2dm($civ13, $message->author->displayname, $split_message)) return $message->react("🔥");
                return $message->react("📧");
            case 'ahelp-tdm':
                if (! $tdm_discord2dm($civ13, $message->author->displayname, $split_message)) return $message->react("🔥");
                return $message->react("📧");
            default:
                return $message->reply('You need to be in either the #ahelp-nomads or #ahelp-tdm channel to use this command.');
        }
    }
    if (str_starts_with($message_content_lower, 'bancheck')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('bancheck'))))) return $message->reply('Wrong format. Please try `bancheck [ckey]`.');
        $reason = 'unknown';
        $found = false;
        if (file_exists($civ13->files['nomads_bans']) && ($filecheck1 = fopen($civ13->files['nomads_bans'], 'r'))) {
            while (($fp = fgets($filecheck1, 4096)) !== false) {
                $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == strtolower($ckey))) {
                    $found = true;
                    $type = $linesplit[0];
                    $reason = $linesplit[3];
                    $admin = $linesplit[4];
                    $date = $linesplit[5];
                    $message->reply("**$ckey** has been **$type** banned from **Nomads** on **$date** for **$reason** by $admin.");
                }
            }
            fclose($filecheck1);
        }
        if (file_exists($civ13->files['tdm_bans']) && ($filecheck2 = fopen($civ13->files['tdm_bans'], 'r'))) {
            while (($fp = fgets($filecheck2, 4096)) !== false) {
                $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == strtolower($ckey))) {
                    $found = true;
                    $type = $linesplit[0];
                    $reason = $linesplit[3];
                    $admin = $linesplit[4];
                    $date = $linesplit[5];
                    $message->reply("**$ckey** has been **$type** banned from **TDM** on **$date** for **$reason** by $admin.");
                }
            }
            fclose($filecheck2);
        }
        if (! $found) return $message->reply("No bans were found for **$ckey**.");
        if ($member = $civ13->getVerifiedMember($ckey))
            if (! $member->roles->has($civ13->role_ids['banished']))
                $member->addRole($civ13->role_ids['banished']);
        return;
    }
    if (str_starts_with($message_content_lower, 'serverstatus')) { //See GitHub Issue #1
        return; //deprecated
        /*
        $embed = new Embed($civ13->discord);
        $_1714 = !\portIsAvailable(1714);
        $server_is_up = $_1714;
        if (! $server_is_up) {
            $embed->setColor(0x00ff00);
            $embed->addFieldValues('TDM Server Status', 'Offline');
        } else {
            if ($_1714) {
                if (! $data = file_get_contents($civ13->files['tdm_serverdata'])) {
                    $embed->setColor(0x00ff00);
                    $embed->addFieldValues('TDM Server Status', 'Starting');
                } else {
                    $data = explode(';', str_replace(['<b>Address</b>: ', '<b>Map</b>: ', '<b>Gamemode</b>: ', '<b>Players</b>: ', '</b>', '<b>'], '', $data));
                    $embed->setColor(0x00ff00);
                    $embed->addFieldValues('TDM Server Status', 'Online');
                    if (isset($data[1])) $embed->addFieldValues('Address', '<'.$data[1].'>');
                    if (isset($data[2])) $embed->addFieldValues('Map', $data[2]);
                    if (isset($data[3])) $embed->addFieldValues('Gamemode', $data[3]);
                    if (isset($data[4])) $embed->addFieldValues('Players', $data[4]);
                }
            } else {
                $embed->setColor(0x00ff00);
                $embed->addFieldValues('TDM Server Status', 'Offline');
            }
        }
        $_1715 = !\portIsAvailable(1715);
        $server_is_up = ($_1715);
        if (! $server_is_up) {
            $embed->setColor(0x00ff00);
            $embed->addFieldValues('Nomads Server Status', 'Offline');
        } else {
            if ($_1714) {
                if (! $data = file_get_contents($civ13->files['nomads_serverdata'])) {
                    $embed->setColor(0x00ff00);
                    $embed->addFieldValues('Nomads Server Status', 'Starting');
                } else {
                    $data = explode(';', str_replace(['<b>Address</b>: ', '<b>Map</b>: ', '<b>Gamemode</b>: ', '<b>Players</b>: ', '</b>', '<b>'], '', $data));
                    $embed->setColor(0x00ff00);
                    $embed->addFieldValues('Nomads Server Status', 'Online');
                    if (isset($data[1])) $embed->addFieldValues('Address', '<'.$data[1].'>');
                    if (isset($data[2])) $embed->addFieldValues('Map', $data[2]);
                    if (isset($data[3])) $embed->addFieldValues('Gamemode', $data[3]);
                    if (isset($data[4])) $embed->addFieldValues('Players', $data[4]);
                }
            } else {
                $embed->setColor(0x00ff00);
                $embed->addFieldValues('Nomads Server Status', 'Offline');
            }
        }
        return $message->channel->sendEmbed($embed);
        */
    }
    if (str_starts_with($message_content_lower, 'discord2ckey')) {
        if (! $item = $civ13->verified->get('discord', $id = trim(str_replace(['<@!', '<@', '>'], '', substr($message_content_lower, strlen('discord2ckey')))))) return $message->reply("`$id` is not registered to any byond username");
        return $message->reply("`$id` is registered to `{$item['ss13']}`");
    }
    if (str_starts_with($message_content_lower, 'ckey2discord')) {
        if (! $item = $civ13->verified->get('ss13', $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('discord2ckey')))))) return $message->reply("`$ckey` is not registered to any discord id");
        return $message->reply("`$ckey` is registered to <@{$item['discord']}>");
    }
    if (str_starts_with($message_content_lower, 'ckey')) {
        if (is_numeric($id = trim(str_replace(['<@!', '<@', '>', '.', '_', ' '], '', substr($message_content_lower, strlen('ckey')))))) {
            if (! $item = $civ13->getVerifiedItem($id)) return $message->reply("`$id` is not registered to any ckey");
            if (! $age = $civ13->getByondAge($item['ss13'])) return $message->reply("`{$item['ss13']}` does not exist");
            return $message->reply("`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
        }
        if (! $age = $civ13->getByondAge($id)) return $message->reply("`$id` does not exist");
        if ($item = $civ13->getVerifiedItem($id)) return $message->reply("`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
        return $message->reply("`$id` is not registered to any discord id ($age)");
    }
    
    if ($message->member && $guild_message($civ13, $message, $message_content, $message_content_lower)) return;
};

$slash_init = function (Civ13 $civ13, $commands) use ($restart_tdm, $restart_nomads, $ranking, $rankme, $medals, $brmedals): void
{ //ready_slash, requires other functions to work
    $civ13->discord->listenCommand('pull', function ($interaction) use ($civ13): void
    {
        $civ13->logger->info('[GIT PULL]');
        \execInBackground('git pull');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating code from GitHub...'));
    });
    
    $civ13->discord->listenCommand('update', function ($interaction) use ($civ13): void
    {
        $civ13->logger->info('[COMPOSER UPDATE]');
        \execInBackground('composer update');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating dependencies...'));
    });
    
    $civ13->discord->listenCommand('restart_nomads', function ($interaction) use ($civ13, $restart_nomads): void
    {
    $interaction->respondWithMessage(MessageBuilder::new()->setContent("Attempted to kill, update, and bring up Nomads <byond://{$civ13->ips['tdm']}:{$civ13->ports['tdm']}>"));
        $restart_nomads($civ13);
    });
    $civ13->discord->listenCommand('restart_tdm', function ($interaction) use ($civ13, $restart_tdm): void
    {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent("Attempted to kill, update, and bring up TDM <byond://{$civ13->ips['tdm']}:{$civ13->ports['tdm']}>"));
        $restart_tdm($civ13);
    });
    
    $civ13->discord->listenCommand('ranking', function ($interaction) use ($civ13, $ranking): void
    {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($ranking($civ13)), true);
    });
    $civ13->discord->listenCommand('rankme', function ($interaction) use ($civ13, $rankme): void
    {
        if (! $item = $civ13->verified->get('discord', $interaction->member->id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        else $interaction->respondWithMessage(MessageBuilder::new()->setContent($rankme($civ13, $item['ss13'])), true);
    });
    $civ13->discord->listenCommand('rank', function ($interaction) use ($civ13, $rankme): void
    {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        else $interaction->respondWithMessage(MessageBuilder::new()->setContent($rankme($civ13, $item['ss13'])), true);
    });
    $civ13->discord->listenCommand('medals', function ($interaction) use ($civ13, $medals): void
    {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        else $interaction->respondWithMessage(MessageBuilder::new()->setContent($medals($civ13, $item['ss13'])), true);
    });
    $civ13->discord->listenCommand('brmedals', function ($interaction) use ($civ13, $brmedals): void
    {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        else $interaction->respondWithMessage(MessageBuilder::new()->setContent($brmedals($civ13, $item['ss13'])), true);
    });

    /*For deferred interactions
    $civ13->discord->listenCommand('',  function (Interaction $interaction) use ($civ13) {
      // code is expected to be slow, defer the interaction
      $interaction->acknowledge()->done(function () use ($interaction, $civ13) { // wait until the bot says "Is thinking..."
        // do heavy code here (up to 15 minutes)
        // ...
        // send follow up (instead of respond)
        $interaction->sendFollowUpMessage(MessageBuilder...);
      });
    }
    */
};
/*$on_ready = function (Civ13 $civ13): void
{    
    //
};*/