<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */ 

/*
 *
 * Ready Event
 *
*/

$set_ips = function (\Civ13\Civ13 $civ13)
{
    $vzg_ip = gethostbyname('www.valzargaming.com');
    $external_ip = file_get_contents('http://ipecho.net/plain');
    $civ13->ips = [
        'nomads' => $external_ip,
        'tdm' => $external_ip,
        'vzg' => $vzg_ip,
    ];
    $civ13->ports = [
        'nomads' => '1715',
        'tdm' => '1714',
        'persistence' => '7777',
        'bc' => '1717', 
        'kepler' => '1718',
    ];
};

$status_changer = function ($discord, $activity, $state = 'online') 
{
    $discord->updatePresence($activity, false, $state);
};
$status_changer_random = function (\Civ13\Civ13 $civ13) use ($status_changer)
{
    if ($civ13->files['status_path']) {
        if ($status_array = file($civ13->files['status_path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
            list($status, $type, $state) = explode('; ', $status_array[array_rand($status_array)]);
            $type = (int) $type;
        } else $civ13->logger->warning('unable to open file ' . $civ13->files['status_path']);
    } else $civ13->logger->warning('status_path is not defined');
    
    if ($status) {
        $activity = new \Discord\Parts\User\Activity($civ13->discord, [ //Discord status            
            'name' => $status,
            'type' => $type, //0, 1, 2, 3, 4 | Game/Playing, Streaming, Listening, Watching, Custom Status
        ]);
        $status_changer($civ13->discord, $activity, $state);
    }
};
$status_changer_timer = function (\Civ13\Civ13 $civ13) use ($status_changer_random)
{
    $civ13->timers['status_changer_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(120, function() use ($civ13, $status_changer_random) { $status_changer_random($civ13); });
};

/*
 *
 * Message Event
 *
 */

$nomads_ban = function (\Civ13\Civ13 $civ13, $array, $message = null)
{
    $admin = ($message ? $civ13->discord->user->username : $message->author->displayname);
    $txt = $admin.':::'.$array[0].':::'.$array[1].':::'.$array[2].PHP_EOL;
    $result = '';
    if ($file = fopen($civ13->files['nomads_discord2ban'], 'a')) {
        fwrite($file, $txt);
        fclose($file);
    } else {
        $civ13->logger->warning('unable to open ' . $civ13->files['nomads_discord2ban']);
        $result .= 'unable to open ' . $civ13->files['nomads_discord2ban'] . PHP_EOL;
    }
    $result .= '**' . $admin . '** banned **' . $array[0] . '** from **Nomads** for **' . $array[1] . '** with the reason **' . $array[2] . '**.' . PHP_EOL;
    return $result;
};
$tdm_ban = function (\Civ13\Civ13 $civ13, $array, $message = null)
{
    $admin = ($message ? $civ13->discord->user->username : $message->author->displayname);
    $txt = $admin.':::'.$array[0].':::'.$array[1].':::'.$array[2].PHP_EOL;
    $result = '';
    if ($file = fopen($civ13->files['tdm_discord2ban'], 'a')) {
        fwrite($file, $txt);
        fclose($file);
    } else {
        $civ13->logger->warning('unable to open ' . $civ13->files['tdm_discord2ban']);
        $result .= 'unable to open ' . $civ13->files['tdm_discord2ban'] . PHP_EOL;
    }
    $result .= '**' . $admin . '** banned **' . $array[0] . '** from **TDM** for **' . $array[1] . '** with the reason **' . $array[2] . '**.' . PHP_EOL;
    return $result;
};
$ban = function (\Civ13\Civ13 $civ13, $array, $message = null) use ($nomads_ban, $tdm_ban)
{
    $result = '';
    $result .= $nomads_ban($civ13, $array, $message);
    $result .= $tdm_ban($civ13, $array, $message);
    return $result;
};
$unban = function (\Civ13\Civ13 $civ13, string $ckey, ?string $admin = null)
{
    if (!$admin) $admin = $civ13->discord->user->displayname;
    if ($file = fopen($civ13->files['nomads_discord2unban'], 'a')) {
        fwrite($file, "$admin:::$ckey");
        fclose($file);
    }
    if ($file = fopen($civ13->files['tdm_discord2unban'], 'a')) {
        fwrite($file, "$admin:::$ckey");
        fclose($file);
    }
};

$browser_get = function (\Civ13\Civ13 $civ13, string $url, array $headers = [], $curl = false)
{
    if ( ! $curl && $browser = $civ13->browser) return $browser->get($url, $headers);
    
    $ch = curl_init(); //create curl resource
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //return the transfer as a string
    $result = curl_exec($ch);
    return $result; //string
};
$browser_post = function (\Civ13\Civ13 $civ13, string $url, array $headers = ['Content-Type' => 'application/x-www-form-urlencoded'], array $data = [], $curl = false)
{
    //Send a POST request to valzargaming.com:8081/discord2ckey/ with POST['id'] = $id
    if ( ! $curl && $browser = $civ13->browser) return $browser->post($url, $headers, http_build_query($data));

    $ch = curl_init(); //create curl resource
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //return the transfer as a string
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $result = curl_exec($ch);
    return $result;
};

$restart_nomads = function (\Civ13\Civ13 $civ13, $message = null)
{
    \execInBackground('python3 ' . $civ13->files['nomads_killciv13']);
    if ($message !== null) $message->channel->sendMessage('Attempted to kill Civilization 13 Server.');
    \execInBackground('python3 ' . $civ13->files['nomads_updateserverabspaths']);
    if ($message !== null) $message->channel->sendMessage('Updated the code.');
    \execInBackground('rm -f ' . $civ13->files['nomads_serverdata']);
    \execInBackground('DreamDaemon ' . $civ13->files['nomads_dmb'] . ' ' . $civ13->ports['nomads'] . ' -trusted -webclient -logself &');
    if ($message !== null) $message->channel->sendMessage('Attempted to bring up Civilization 13 (Nomads Server) <byond://' . $civ13->ips['nomads'] . ':' . $civ13->ports['nomads'] . '>');
    $civ13->discord->getLoop()->addTimer(10, function() use ($civ13) { # ditto
        \execInBackground('python3 ' . $civ13->files['nomads_killsudos']);
    });
};
$restart_tdm = function (\Civ13\Civ13 $civ13, $message = null)
{
    \execInBackground('python3 ' . $civ13->files['tdm_killciv13']);
    if ($message !== null) $message->channel->sendMessage('Attempted to kill Civilization 13 TDM Server.');
    \execInBackground('python3 ' . $civ13->files['tdm_updateserverabspaths']);
    if ($message !== null) $message->channel->sendMessage('Updated the code.');
    \execInBackground('rm -f ' . $civ13->files['tdm_serverdata']);
    \execInBackground('DreamDaemon ' . $civ13->files['tdm_dmb'] . ' ' . $civ13->ports['tdm'] . ' -trusted -webclient -logself &');
    if ($message !== null) $message->channel->sendMessage('Attempted to bring up Civilization 13 (TDM Server) <byond://' . $civ13->ips['tdm'] . ':' . $civ13->ports['tdm'] . '>');
    $civ13->discord->getLoop()->addTimer(10, function() use ($civ13, $message) { # ditto
        \execInBackground('python3 ' . $civ13->files['tdm_killsudos']);
    });
};
$nomads_mapswap = function (\Civ13\Civ13 $civ13, string $mapto, $message = null)
{
    \execInBackground('python3 ' . $civ13->files['nomads_mapswap'] . " $mapto");
    if ($message !== null) $message->channel->sendMessage("Attempting to change map to $mapto");
};
$tdm_mapswap = function (\Civ13\Civ13 $civ13, string $mapto, $message = null)
{
    \execInBackground('python3 ' . $civ13->files['tdm_mapswap'] . " $mapto");
    if ($message !== null) $message->channel->sendMessage("Attempting to change map to $mapto");
};
$mapswap = function (\Civ13\Civ13 $civ13, string $path, string $mapto)
{
    /*$process = spawnChildProcess("python3 $path $mapto");
    $process->on('exit', function($exitCode, $termSignal) use ($civ13) {
        if ($termSignal === null) $civ13->logger->info('Mapswap exited with code ' . $exitCode);
        $civ13->logger->info('Mapswap terminated with signal ' . $termSignal);
    });
    return $process;*/
};


$filenav = function (\Civ13\Civ13 $civ13, string $basedir, array $subdirs) use (&$filenav): array
{
    //$civ13->logger->debug("[FILENAV] [$basedir][`" . implode('`, `', $subdirs) . '`]');
    $scandir = scandir($basedir);
    unset($scandir[1], $scandir[0]);
    if (! $subdir = trim(array_shift($subdirs))) return [false, $scandir];
    if (! in_array($subdir, $scandir)) return [false, $scandir, $subdir];
    if (is_file("$basedir/$subdir")) return [true, "$basedir/$subdir"];
    return $filenav($civ13, "$basedir/$subdir", $subdirs);
};
$log_handler = function (\Civ13\Civ13 $civ13, $message, string $message_content) use ($filenav)
{
    $tokens = explode(';', $message_content);
    //$civ13->logger->info('[LOG HANDLER] `' . implode('`, `', $tokens) . '`');
    if (!in_array($tokens[0], ['nomads', 'tdm'])) return $message->reply('Please use the format `logs nomads;folder;file` or `logs tdm;folder;file`');
    if (trim(strtolower($tokens[0])) == 'nomads') {
        unset($tokens[0]);
        $results = $filenav($civ13, $civ13->files['nomads_log_basedir'], $tokens);
        if ($results[0]) return $message->reply(\Discord\Builders\MessageBuilder::new()->addFile($results[1], 'log.txt'));
        if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
        if (! isset($results[2]) || ! $results[2]) return $message->reply('Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
        return $message->reply($results[2] . 'is not an available option! Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
    }
    if (trim(strtolower($tokens[0])) == 'tdm') {
        unset($tokens[0]);
        $results = $filenav($civ13, $civ13->files['tdm_log_basedir'], $tokens);
        if ($results[0]) return $message->reply(\Discord\Builders\MessageBuilder::new()->addFile($results[1], 'log.txt'));
        if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
        if (! isset($results[2]) || ! $results[2]) return $message->reply('Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
        return $message->reply($results[2] . 'is not an available option! Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
    }
};
$banlog_handler = function (\Civ13\Civ13 $civ13, $message, string $message_content_lower) use ($filenav)
{
    if (!in_array($message_content_lower, ['nomads', 'tdm'])) return $message->reply('Please use the format `bans nomads` or `bans tdm');
    if ($message_content_lower == 'nomads') return $message->reply(\Discord\Builders\MessageBuilder::new()->addFile($civ13->files['nomads_bans'], 'bans.txt'));
    if ($message_content_lower == 'tdm') return $message->reply(\Discord\Builders\MessageBuilder::new()->addFile($civ13->files['tdm_bans'], 'bans.txt'));
};

$recalculate_ranking = function (\Civ13\Civ13 $civ13)
{
    $ranking = array();
    $ckeylist = array();
    $result = array();
    
    if (! $search = fopen($civ13->files['tdm_awards_path'], 'r')) return $civ13->logger->warning('Unable to access `' . $civ13->files['tdm_awards_path'] . '`');
    while (! feof($search)) {
        $medal_s = 0;
        $line = fgets($search);
        $line = trim(str_replace(PHP_EOL, '', $line)); # remove '\n' at end of line
        $duser = explode(';', $line);
        if ($duser[2] == "long service medal") $medal_s += 0.5;
        if ($duser[2] == "combat medical badge") $medal_s += 2;
        if ($duser[2] == "tank destroyer silver badge") $medal_s += 0.75;
        if ($duser[2] == "tank destroyer gold badge") $medal_s += 1.5;
        if ($duser[2] == "assault badge") $medal_s += 1.5;
        if ($duser[2] == "wounded badge") $medal_s += 0.5;
        if ($duser[2] == "wounded silver badge") $medal_s += 0.75;
        if ($duser[2] == "wounded gold badge") $medal_s += 1;
        if ($duser[2] == "iron cross 1st class") $medal_s += 3;
        if ($duser[2] == "iron cross 2nd class") $medal_s += 5;
        $result[] = $medal_s . ';' . $duser[0];
        if (!in_array($duser[0], $ckeylist)) $ckeylist[] = $duser[0];
    }
    
    foreach ($ckeylist as $i) {
        $sumc = 0;
        foreach ($result as $j) {
            $sj = explode(';', $j);
            if ($sj[1] == $i) $sumc += (float) $sj[0];
        }
        $ranking[] = [$sumc,$i];
    }
    usort($ranking, function($a, $b) { return $a[0] <=> $b[0]; });
    $sorted_list = array_reverse($ranking);
    if (! $search = fopen($civ13->files['ranking_path'], 'w')) return $civ13->logger->warning('Unable to access `' . $civ13->files['ranking_path'] . '`');
    foreach ($sorted_list as $i) fwrite($search, $i[0] . ';' . $i[1] . PHP_EOL);
    return fclose ($search);
};
$ranking = function (\Civ13\Civ13 $civ13)
{
    $line_array = array();
    if (! $search = fopen($civ13->files['ranking_path'], 'r')) return 'Unable to access `' . $civ13->files['ranking_path'] . '`';
    while (($fp = fgets($search, 4096)) !== false) $line_array[] = $fp;
    fclose($search);

    $topsum = 1;
    $msg = '';
    for ($x=0;$x<count($line_array);$x++) {
        if ($topsum > 10) break;
        $line = trim(str_replace(PHP_EOL, '', $line_array[$x]));
        $topsum += 1;
        $sline = explode(';', $line);
        $msg .= '('. ($topsum - 1) ."): **".$sline[1].'** with **'.$sline[0].'** points.' . PHP_EOL;
    }
    return $msg;
};
$rankme = function (\Civ13\Civ13 $civ13, string $ckey)
{
    $line_array = array();
    if (! $search = fopen($civ13->files['ranking_path'], 'r')) return 'Unable to access `' . $civ13->files['ranking_path'] . '`';
    while (($fp = fgets($search, 4096)) !== false) $line_array[] = $fp;
    fclose($search);
    
    $found = 0;
    $result = '';
    for ($x=0;$x<count($line_array);$x++) {
        $sline = explode(';', trim(str_replace(PHP_EOL, '', $line_array[$x])));
        if ($sline[1] == $ckey) {
            $found = 1;
            $result .= "**" . $sline[1] . "**" . " has a total rank of **" . $sline[0] . "**.";
        };
    }
    if (!$found) return "No medals found for ckey `$ckey`.";
    return $result;
};
$medals = function (\Civ13\Civ13 $civ13, string $ckey)
{
    $result = '';
    if (!$search = fopen($civ13->files['tdm_awards_path'], 'r')) return 'Unable to access `' . $civ13->files['tdm_awards_path'] . '`';
    $found = false;
    while (! feof($search)) {
        $line = fgets($search);
        $line = trim(str_replace(PHP_EOL, '', $line)); # remove '\n' at end of line
        if (str_contains($line, $ckey)) {
            $found = true;
            $duser = explode(';', $line);
            if ($duser[0] == $ckey) {
                $medal_s = "<:long_service:705786458874707978>";
                if ($duser[2] == "long service medal")
                    $medal_s = "<:long_service:705786458874707978>";
                if ($duser[2] == "combat medical badge")
                    $medal_s = "<:combat_medical_badge:706583430141444126>";
                if ($duser[2] == "tank destroyer silver badge")
                    $medal_s = "<:tank_silver:705786458882965504>";
                if ($duser[2] == "tank destroyer gold badge")
                    $medal_s = "<:tank_gold:705787308926042112>";
                if ($duser[2] == "assault badge")
                    $medal_s = "<:assault:705786458581106772>";
                if ($duser[2] == "wounded badge")
                    $medal_s = "<:wounded:705786458677706904>";
                if ($duser[2] == "wounded silver badge")
                    $medal_s = "<:wounded_silver:705786458916651068>";
                if ($duser[2] == "wounded gold badge")
                    $medal_s = "<:wounded_gold:705786458845216848>";
                if ($duser[2] == "iron cross 1st class")
                    $medal_s = "<:iron_cross1:705786458572587109>";
                if ($duser[2] == "iron cross 2nd class")
                    $medal_s = "<:iron_cross2:705786458849673267>";
                $result .= "**" . $duser[1] . ":**" . ' ' . $medal_s . " **" . $duser[2] . "**, *" . $duser[4] . "*, " . $duser[5] . PHP_EOL;
            }
        }
    }
    if ($result != '') return $result;
    if (!$found && ($result == '')) return 'No medals found for this ckey.';
};
$brmedals = function (\Civ13\Civ13 $civ13, string $ckey)
{
    $result = '';
    $search = fopen($civ13->files['tdm_awards_br_path'], 'r');
    $found = false;
    while (! feof($search)) {
        $line = trim(str_replace(PHP_EOL, '', fgets($search))); # remove '\n' at end of line
        if (str_contains($line, $ckey)) {
            $found = true;
            $duser = explode(';', $line);
            if ($duser[0] == $ckey) $result .= '**' . $duser[1] . ':** placed *' . $duser[2] . ' of  '. $duser[5] . ',* on ' . $duser[4] . ' (' . $duser[3] . ')' . PHP_EOL;
        }
    }
    if ($result != '') return $result;
    if (!$found && ($result == '')) return 'No medals found for this ckey.';
};

$rank_check = function (\Civ13\Civ13 $civ13, $message, array $allowed_ranks)
{
    $resolved_ranks = [];
    foreach ($allowed_ranks as $rank) $resolved_ranks[] = $civ13->role_ids[$rank];
    foreach ($message->member->roles as $role) if (in_array($role->id, $resolved_ranks)) return true;
    $message->reply('Rejected! You need to have at least the [' . $message->guild->roles ? $message->guild->roles->get('id', $civ13->role_ids[array_pop($resolved_ranks)])->name : array_pop($allowed_ranks) . '] rank.');
};
$guild_message = function (\Civ13\Civ13 $civ13, $message, string $message_content, string $message_content_lower) use ($rank_check, $ban, $nomads_ban, $tdm_ban, $unban, $restart_nomads, $restart_tdm, $nomads_mapswap, $tdm_mapswap, $log_handler, $banlog_handler, $recalculate_ranking, $ranking, $rankme, $medals, $brmedals)
{
    if (! $message->member) return $message->reply('Error! Unable to get Discord Member class.');
    
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
        if (! $mass_promotion_loop = $civ13->functions['misc']['mass_promotion_check']) return $message->react("🔥");
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌"); 
        if ($promotables = $mass_promotion_loop($civ13, $message)) return $message->reply(\Discord\Builders\MessageBuilder::new()->addFileFromContent('promotables.txt', json_encode($promotables)));;
        return $message->react("👎");
    }
    
    if (str_starts_with($message_content_lower, 'whitelistme')) {
        $ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 11)));
        if (! $ckey) return $message->channel->sendMessage('Wrong format. Please try `!s whitelistme [ckey]`.'); // if len($split_message) > 1 and len($split_message[1]) > 0:
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight', 'veteran'])) return $message->react("❌");         
        $found = false;
        $whitelist1 = fopen($civ13->files['nomads_whitelist'], 'r');
        if ($whitelist1) {
            while (($fp = fgets($whitelist1, 4096)) !== false) {
                $line = trim(str_replace(PHP_EOL, '', $fp));
                $linesplit = explode(';', $line);
                foreach ($linesplit as $split)
                    if ($split == $ckey)
                        $found = true;
            }
            fclose($whitelist1);
        }
        $whitelist2 = fopen($civ13->files['tdm_whitelist'], 'r');
        if ($whitelist2) {
            while (($fp = fgets($whitelist2, 4096)) !== false) {
                $line = trim(str_replace(PHP_EOL, '', $fp));
                $linesplit = explode(';', $line);
                foreach ($linesplit as $split)
                    if ($split == $ckey)
                        $found = true;
            }
            fclose($whitelist2);
        }
        if ($found) return $message->channel->sendMessage("$ckey is already in the whitelist!");
        
        $found2 = false;
        if ($whitelist1 = fopen($civ13->files['nomads_whitelist'], 'r')) {
            while (($fp = fgets($whitelist1, 4096)) !== false) {
                $line = trim(str_replace(PHP_EOL, '', $fp));
                $linesplit = explode(';', $line);
                foreach ($linesplit as $split) {
                    if ($split == $message->member->username)
                        $found2 = true;
                }
            }
            fclose($whitelist1);
        }
        if ($found2) return $message->channel->sendMessage("$ckey is already in the whitelist!");
        
        $txt = $ckey."=".$message->member->username.PHP_EOL;
        if ($whitelist1 = fopen($civ13->files['nomads_whitelist'], 'a')) {
            fwrite($whitelist1, $txt);
            fclose($whitelist1);
        }
        if ($whitelist2 = fopen($civ13->files['tdm_whitelist'], 'a')) {
            fwrite($whitelist2, $txt);
            fclose($whitelist2);
        }
        return $message->channel->sendMessage("$ckey has been added to the whitelist.");
    }
    if (str_starts_with($message_content_lower, 'unwhitelistme')) {
        if (! $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight', 'veteran', 'infantry'])) return $message->react("❌");
        
        $removed = "N/A";
        $lines_array = array();
        if (! $wlist = fopen($civ13->files['nomads_whitelist'], 'r')) return $message->channel->sendMessage('Unable to access `' . $civ13->files['nomads_whitelist'] . '`');  
        while (($fp = fgets($wlist, 4096)) !== false) $lines_array[] = $fp;
        fclose($wlist);
        
        if (count($lines_array) > 0) {
            if (! $wlist = fopen($civ13->files['nomads_whitelist'], 'w')) return $message->channel->sendMessage('Unable to access `' . $civ13->files['nomads_whitelist'] . '`');
            foreach ($lines_array as $line)
                if (!str_contains($line, $message->member->username)) {
                    fwrite($wlist, $line);
                } else {
                    $removed = explode('=', $line);
                    $removed = $removed[0];
                }
            fclose($wlist);
        }
        
        $lines_array = array();
        if (! $wlist = fopen($civ13->files['tdm_whitelist'], 'r')) return $message->channel->sendMessage('Unable to access `' . $civ13->files['tdm_whitelist'] . '`');
        while (($fp = fgets($wlist, 4096)) !== false) $lines_array[] = $fp;
        fclose($wlist);
        
        if (count($lines_array) > 0) {
            if (! $wlist = fopen($civ13->files['tdm_whitelist'], 'w')) return $message->channel->sendMessage('Unable to access `' . $civ13->files['tdm_whitelist'] . '`');
            foreach ($lines_array as $line)
                if (!str_contains($line, $message->member->username)) {
                    fwrite($wlist, $line);
                } else {
                    $removed = explode('=', $line);
                    $removed = $removed[0];
                }
            fclose($wlist);
        }
        return $message->channel->sendMessage("Ckey $removed has been removed from the whitelist.");
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
        if ($result = $ban($civ13, $split_message, $message)) return $message->channel->sendMessage($result);
    }
    if (str_starts_with($message_content_lower, 'nomadsban ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content = substr($message_content, 10);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        if ($result = $nomads_ban($civ13, $split_message, $message))
            return $message->channel->sendMessage($result);
    }
    if (str_starts_with($message_content_lower, 'tdmban ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content = substr($message_content, 7);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        if ($result = $tdm_ban($civ13, $split_message, $message))
            return $message->channel->sendMessage($result);
    }
    if (str_starts_with($message_content_lower, 'unban ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content_lower = substr($message_content_lower, 6);
        $split_message = explode('; ', $message_content_lower);
        
        /*
        if ($file = fopen($civ13->files['nomads_discord2unban'], 'a')) {
            $txt = $message->author->username . "#" . $message->author->discriminator . ':::'.$split_message[0];
            fwrite($file, $txt);
            fclose($file);
        }
        if ($file = fopen($civ13->files['tdm_discord2unban'], 'a')) {
            $txt = $message->author->username . "#" . $message->author->discriminator . ':::'.$split_message[0];
            fwrite($file, $txt);
            fclose($file);
        }
        return $message->channel->sendMessage('**' . $message->author->username . '** unbanned **' . $split_message[0] . '**.');
        */
        $unban($civ13, $split_message[0], $message->author->displayname);
        return $message->channel->sendMessage('**' . $message->author->displayname . '** unbanned **' . $split_message[0] . '**.');
    }
    if (str_starts_with($message_content_lower, 'hostciv')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $message->channel->sendMessage('Please wait, updating the code...');
        \execInBackground('python3 ' . $civ13->files['nomads_updateserverabspaths']);
        $message->channel->sendMessage('Updated the code.');
        \execInBackground('rm -f ' . $civ13->files['nomads_serverdata']);
        \execInBackground('DreamDaemon ' . $civ13->files['nomads_dmb'] . ' ' . $civ13->ports['nomads'] . ' -trusted -webclient -logself &');
        $message->channel->sendMessage('Attempted to bring up Civilization 13 (Main Server) <byond://' . $civ13->ips['nomads'] . ':' . $civ13->ports['nomads'] . '>');
        return $civ13->discord->getLoop()->addTimer(10, function() use ($civ13) { # ditto
            \execInBackground('python3 ' . $civ13->files['nomads_killsudos']);
        });
    }
    if (str_starts_with($message_content_lower, 'killciv')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        \execInBackground('python3 ' . $civ13->files['nomads_killciv13']);
        return $message->channel->sendMessage('Attempted to kill Civilization 13 Server.');
    }
    if (str_starts_with($message_content_lower, 'restartciv')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        return $restart_nomads($civ13, $message);
    }
    if (str_starts_with($message_content_lower, 'restarttdm')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        return $restart_tdm($civ13, $message);
    }
    if (str_starts_with($message_content_lower, 'mapswap')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        
        $split_message = explode('mapswap ', $message_content);
        if (!((count($split_message) > 1) && (strlen($split_message[1]) > 0))) return $message->channel->sendMessage('You need to include the name of the map.');
        $mapto = $split_message[1];
        $mapto = strtoupper($mapto);
        $civ13->logger->info("[MAPTO] $mapto".PHP_EOL);
        
        $maps = array();
        if ($filecheck1 = fopen($civ13->files['map_defines_path'], 'r')) {
            while (($fp = fgets($filecheck1, 4096)) !== false) {
                $filter = '"';
                $line = trim(str_replace($filter, '', $fp));
                $linesplit = explode(' ', $line); //$split_ckey[0] is the ckey
                if ($map = trim($linesplit[2])) {
                    $maps[] = $map;
                }
            }
            fclose($filecheck1);
        } else $civ13->logger->warning('unable to find file ' . $civ13->files['map_defines_path'] . PHP_EOL);
        
        if (! in_array($mapto, $maps)) return $message->channel->sendMessage("$mapto was not found in the map definitions.");
        return $nomads_mapswap($civ13, $mapto, $message);
        /*
        $message->channel->sendMessage('Calling mapswap...');
        $process = $mapswap($civ13, $civ13->files['nomads_mapswap'], $mapto);
        $process->stdout->on('end', function () use ($message, $mapto) {
            $message->channel->sendMessage("Attempting to change map to $mapto");
        });
        $process->stdout->on('error', function (Exception $e) use ($message, $mapto) {
            $message->channel->sendMessage("Error changing map to $mapto: " . $e->getMessage());
        });
        $process->start();
        */
    }
    if (str_starts_with($message_content_lower, 'maplist')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        
        $split_message = explode('mapswap ', $message_content);
        $mapto = $split_message[1];
        $mapto = strtoupper($mapto);
        $civ13->logger->info("[MAPTO] $mapto".PHP_EOL);
        $maps = array();
        if ($filecheck1 = fopen($civ13->files['map_defines_path'], 'r')) {
            fclose($filecheck1);
            return $message->channel->sendFile($civ13->files['map_defines_path'], 'maps.txt');
        }
        return $civ13->logger->warning('unable to find file ' . $civ13->files['map_defines_path'] . PHP_EOL);
    }
    if (str_starts_with($message_content_lower, 'hosttdm')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        
        $message->channel->sendMessage('Please wait, updating the code...');
        \execInBackground('python3 ' . $civ13->files['tdm_updateserverabspaths']);
        $message->channel->sendMessage('Updated the code.');
        \execInBackground('rm -f ' . $civ13->files['tdm_serverdata']);
        \execInBackground('DreamDaemon ' . $civ13->files['tdm_dmb'] . ' ' . $civ13->ports['tdm'] . ' -trusted -webclient -logself &'); //
        $message->channel->sendMessage('Attempted to bring up Civilization 13 (TDM Server) <byond://' . $civ13->ips['tdm'] . ':' . $civ13->ports['tdm'] . '>');
        return $civ13->discord->getLoop()->addTimer(10, function() use ($civ13) { # ditto
            \execInBackground('python3 ' . $civ13->files['tdm_killsudos']);
        });
    }
    if (str_starts_with($message_content_lower, 'killtdm')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        \execInBackground('python3 ' . $civ13->files['tdm_killciv13']);
        return $message->channel->sendMessage('Attempted to kill Civilization 13 (TDM Server).');
    }
    if (str_starts_with($message_content_lower, 'tdmmapswap')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        
        $split_message = explode('tdmmapswap ', $message_content);
        if (!((count($split_message) > 1) && (strlen($split_message[1]) > 0))) return;
        $mapto = $split_message[1];
        $mapto = strtoupper($mapto);
        $civ13->logger->info("[MAPTO] $mapto".PHP_EOL);
        
        $maps = array();
        if ($filecheck1 = fopen($civ13->files['map_defines_path'], 'r')) {
            while (($fp = fgets($filecheck1, 4096)) !== false) {
                $filter = '"';
                $line = trim(str_replace($filter, '', $fp));
                $linesplit = explode(' ', $line); //$split_ckey[0] is the ckey
                if (isset($linesplit[2]) && $map = trim($linesplit[2])) $maps[] = $map;
            }
            fclose($filecheck1);
        } else $civ13->logger->warning('unable to find file ' . $civ13->files['map_defines_path']);
        
        if (! in_array($mapto, $maps)) return $message->channel->sendMessage("$mapto was not found in the map definitions.");
        return $tdm_mapswap($civ13, $mapto, $message);
        /*
        $message->channel->sendMessage('Calling mapswap...');
        $process = $mapswap($civ13, $civ13->files['nomads_mapswap'], $mapto);
        $process->stdout->on('end', function () use ($message, $mapto) {
            $message->channel->sendMessage("Attempting to change map to $mapto");
        });
        $process->stdout->on('error', function (Exception $e) use ($message, $mapto) {
            $message->channel->sendMessage("Error changing map to $mapto: " . $e->getMessage());
        });
        $process->start();
        */
    }
    if (str_starts_with($message_content_lower, 'banlist')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        return $message->channel->sendMessage(\Discord\Builders\MessageBuilder::new()->addFile($civ13->files['tdm_bans'], 'bans.txt'));
    }
    if (str_starts_with($message_content_lower, 'logs')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if ($log_handler($civ13, $message, trim(substr($message_content, 4)))) return;
    }
    if (str_starts_with($message_content_lower, 'bans')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if ($banlog_handler($civ13, $message, trim(substr($message_content_lower, 4)))) return;
    }

    if (str_starts_with($message_content_lower, 'ts')) {
        if (! $state = trim(substr($message_content_lower, strlen('ts')))) return $message->reply('Wrong format. Please try `ts on` or `ts off`.');
        if (! in_array($state, ['on', 'off'])) return $message->reply('Wrong format. Please try `ts on` or `ts off`.');
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        if (! $rank_check($civ13, $message, ['admiral'])) return $message->react("❌");
        
        if ($state == 'on') {
            \execInBackground('cd ' . $civ13->files['typespess_path']);
            \execInBackground('git pull');
            \execInBackground('sh ' . $civ13->files['typespess_launch_server_path'] . '&');
            return $message->channel->sendMessage('Put **TypeSpess Civ13** test server on: http://civ13.com/ts');
        } else {
            \execInBackground('killall index.js');
            return $message->channel->sendMessage('**TypeSpess Civ13** test server down.');
        }
    }

    if (str_starts_with($message_content_lower, 'ranking')) {
        if (!$recalculate_ranking($civ13)) return $message->reply('There was an error trying to recalculate ranking!');
        if (!$msg = $ranking($civ13)) return $message->reply('There was an error trying to recalculate ranking!');
        return $message->channel->sendMessage($msg);
    }
    if (str_starts_with($message_content_lower, 'rankme')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('rankme'))))) return $message->reply('Wrong format. Please try `rankme [ckey]`.');
        $recalculate_ranking($civ13);
        if (! $msg = $rankme($civ13, $ckey)) return $message->reply('There was an error trying to get your ranking!');
        return $message->reply($msg);
    }
    if (str_starts_with($message_content_lower, 'medals')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('medals'))))) return $message->reply('Wrong format. Please try `medals [ckey]`.');
        if (! $msg = $medals($civ13, $ckey)) return $message->reply('There was an error trying to get your medals!');
        return $message->reply($msg);
    }
    if (str_starts_with($message_content_lower, 'brmedals')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('brmedals'))))) return $message->reply('Wrong format. Please try `brmedals [ckey]`.');
        if (! $msg = $brmedals($civ13, $ckey)) return $message->reply('There was an error trying to get your medals!');
        return $msg;
    }
    
};
$on_message = function (\Civ13\Civ13 $civ13, $message) use ($guild_message)
{
    if ($message->guild->owner_id != $civ13->owner_id) return; //Only process commands from a guild that Taislin owns
    if (!$civ13->command_symbol) $civ13->command_symbol = '!s';
    
    $message_content = '';
    $message_content_lower = '';
    if (str_starts_with($message->content, $civ13->command_symbol . ' ')) { //Add these as slash commands?
        $message_content = substr($message->content, strlen($civ13->command_symbol)+1);
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, '<@!' . $civ13->discord->id . '>')) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($civ13->discord->id)+4));
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, '<@' . $civ13->discord->id . '>')) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($civ13->discord->id)+3));
        $message_content_lower = strtolower($message_content);
    }
    if (! $message_content) return;
    
    //$author_user = $message->author;
    if (/*$author_member =*/ $message->member) {
        //$author_perms = $author_member->getPermissions($message->channel); //Populate permissions granted by roles
        //$author_guild = $message->guild ?? $civ13->discord->guilds->get('id', $message->guild_id);
        if ($guild_message($civ13, $message, $message_content, $message_content_lower)) return;
    }
    
    if (str_starts_with($message_content_lower, 'ping')) return $message->reply('Pong!');
    if (str_starts_with($message_content_lower, 'help')) return $message->reply('**List of Commands**: bancheck, insult, cpu, ping, (un)whitelistme, rankme, ranking. **Staff only**: ban, hostciv, killciv, restartciv, mapswap, hosttdm, killtdm, restarttdm, tdmmapswap');
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
            foreach ($load_array as $line) {
                if ($line != ' ' && $line != '') {
                    if ($x==0) {
                        $load = "CPU Usage: $line%" . PHP_EOL;
                        break;
                    }
                    if ($x!=0) {
                        //$load = $load . "Core $x: $line%" . PHP_EOL; //No need to report individual cores right now
                    }
                    $x++;
                }
            }
            return $message->channel->sendMessage($load);
        } else { //Linux
            $cpu_load = '-1';
            if ($cpu_load_array = sys_getloadavg())
                $cpu_load = array_sum($cpu_load_array) / count($cpu_load_array);
            return $message->channel->sendMessage('CPU Usage: ' . $cpu_load . "%");
        }
        return $message->channel->sendMessage('Unrecognized operating system!');
    }
    if (str_starts_with($message_content_lower, 'insult')) {
        $split_message = explode(' ', $message_content); //$split_target[1] is the target
        if ((count($split_message) > 1 ) && strlen($split_message[1] > 0)) {
            $incel = $split_message[1];
            $insults_array = array();
            
            if (! $file = fopen($civ13->files['insults_path'], 'r')) return $message->channel->sendMessage('Unable to access `' . $civ13->files['insults_path'] . '`');
            while (($fp = fgets($file, 4096)) !== false) $insults_array[] = $fp;
            if (count($insults_array) > 0) {
                $insult = $insults_array[rand(0, count($insults_array)-1)];
                return $message->channel->sendMessage("$incel, $insult");
            }
        }
        return;
    }
    if (str_starts_with($message_content_lower, 'ooc ')) {
        $message_filtered = substr($message_content, 4);
        switch (strtolower($message->channel->name)) {
            case 'ooc-nomads':                    
                if ($file = fopen($civ13->files['nomads_discord2ooc'], 'a')) {
                    fwrite($file, $message->author->username . ":::$message_filtered" . PHP_EOL);
                    fclose($file);
                }
                break;
            case 'ooc-tdm':
                if ($file = fopen($civ13->files['tdm_discord2ooc'], 'a')) {
                    fwrite($file, $message->author->username . ":::$message_filtered" . PHP_EOL);
                    fclose($file);
                }
                break;
            default:
                return $message->reply('You need to be in either the #ooc-nomads or #ooc-tdm channel to use this command.');
        }
        return;
    }
    if (str_starts_with($message_content_lower, 'asay ')) {
        $message_filtered = substr($message_content, 5);
        switch (strtolower($message->channel->name)) {
            case 'ahelp-nomads':
                if ($file = fopen($civ13->files['nomads_discord2admin'], 'a')) {                    
                    fwrite($file, $message->author->username . ":::$message_filtered" . PHP_EOL);
                    fclose($file);
                }
                break;
            case 'ahelp-tdm':
                if ($file = fopen($civ13->files['tdm_discord2admin'], 'a')) {
                    fwrite($file, $message->author->username . ":::$message_filtered" . PHP_EOL);
                    fclose($file);
                }
                break;
            default:
                return $message->reply('You need to be in either the #ahelp-nomads or #ahelp-tdm channel to use this command.');
        }
        return;
    }
    if (str_starts_with($message_content_lower, 'dm ') || str_starts_with($message_content_lower, 'pm ')) {
        $split_message = explode(': ', substr($message_content, 3));
        switch (strtolower($message->channel->name)) {
            case 'ahelp-nomads':
                if ($file = fopen($civ13->files['nomads_discord2dm'], 'a')) {
                    fwrite($file, $message->author->username.':::'.$split_message[0].':::'.$split_message[1].PHP_EOL);
                    fclose($file);
                }
                break;
            case 'ahelp-tdm':
                if ($file = fopen($civ13->files['tdm_discord2dm'], 'a')) {
                    fwrite($file, $message->author->username.':::'.$split_message[0].':::'.$split_message[1].PHP_EOL);
                    fclose($file);
                }
                break;
            default:
                $message->reply('You need to be in either the #ahelp-nomads or #ahelp-tdm channel to use this command.');
        }
        return;
    }
    if (str_starts_with($message_content_lower, 'bancheck')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('bancheck'))))) return $message->reply('Wrong format. Please try `bancheck [ckey]`.');
        $banreason = "unknown";
        $found = false;
        if ($filecheck1 = fopen($civ13->files['nomads_bans'], 'r')) {
            while (($fp = fgets($filecheck1, 4096)) !== false) {
                str_replace(PHP_EOL, '', $fp);
                $filter = '|||';
                $line = trim(str_replace($filter, '', $fp));
                $linesplit = explode(';', $line); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == strtolower($ckey))) {
                    $found = true;
                    $banreason = $linesplit[3];
                    $bandate = $linesplit[5];
                    $banner = $linesplit[4];
                    $message->channel->sendMessage("**$ckey** has been banned from **Nomads** on **$bandate** for **$banreason** by $banner.");
                }
            }
            fclose($filecheck1);
        }
        if ($filecheck2 = fopen($civ13->files['tdm_bans'], 'r')) {
            while (($fp = fgets($filecheck2, 4096)) !== false) {
                $line = trim(str_replace([PHP_EOL, '|||'], '', $fp));
                $linesplit = explode(';', $line); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == strtolower($ckey))) {
                    $found = true;
                    $banreason = $linesplit[3];
                    $bandate = $linesplit[5];
                    $banner = $linesplit[4];
                    $message->channel->sendMessage("**$ckey** has been banned from **TDM** on **$bandate** for **$banreason** by $banner.");
                }
            }
            fclose($filecheck2);
        }
        if (!$found) return $message->channel->sendMessage("No bans were found for **$ckey**.");
        return;
    }
    if (str_starts_with($message_content_lower, 'serverstatus')) { //See GitHub Issue #1
        $embed = new \Discord\Parts\Embed\Embed($civ13->discord);
        $_1714 = !\portIsAvailable(1714);
        $server_is_up = $_1714;
        if (!$server_is_up) {
            $embed->setColor(0x00ff00);
            $embed->addFieldValues('TDM Server Status', 'Offline');
        } else {
            if ($_1714) {
                if (!$data = file_get_contents($civ13->files['tdm_serverdata'])) {
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
        if (!$server_is_up) {
            $embed->setColor(0x00ff00);
            $embed->addFieldValues('Nomads Server Status', 'Offline');
        } else {
            if ($_1714) {
                if (!$data = file_get_contents($civ13->files['nomads_serverdata'])) {
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
    }
    if (str_starts_with($message_content_lower, 'discord2ckey')) {
        $id = trim(str_replace(['<@!', '<@', '>'], '', substr($message_content_lower, strlen('discord2ckey'))));
        if (! $item = $civ13->verified->get('discord', $id)) return $message->reply("`$id` is not registered to any byond username");
        return $message->reply("`$id` is registered to " . $item['ss13']);
    }
    if (str_starts_with($message_content_lower, 'ckey2discord')) {
        $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content, strlen('discord2ckey'))));
        if (! $item = $civ13->verified->get('ss13', $ckey)) return $message->reply("`$ckey` is not registered to any discord id");
        return $message->reply("`$ckey` is registered to <@" . $item['discord'] . '>');
    }
    if (str_starts_with($message_content_lower, 'ckey')) {
        $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content, strlen('ckey'))));
        if (! $item = $civ13->verified->get('ss13', $ckey)) return $message->reply("`$ckey` is not registered to any discord id");
        return $message->reply("`$ckey` is registered to <@" . $item['discord'] . '>');
    }
};


/*
 *
 * Misc functions
 *
 */

$bancheck = function (\Civ13\Civ13 $civ13, string $ckey)
{
    $return = false;
    if ($filecheck1 = fopen($civ13->files['nomads_bans'], 'r')) {
        while (($fp = fgets($filecheck1, 4096)) !== false) {
            str_replace(PHP_EOL, '', $fp);
            $filter = '|||';
            $line = trim(str_replace($filter, '', $fp));
            $linesplit = explode(';', $line); //$split_ckey[0] is the ckey
            if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) $return = true;
        }
        fclose($filecheck1);
    } else $civ13->logger->warning('unable to open ' . $civ13->files['nomads_bans']);
    if ($filecheck2 = fopen($civ13->files['tdm_bans'], 'r')) {
        while (($fp = fgets($filecheck2, 4096)) !== false) {
            str_replace(PHP_EOL, '', $fp);
            $filter = '|||';
            $line = trim(str_replace($filter, '', $fp));
            $linesplit = explode(';', $line); //$split_ckey[0] is the ckey
            if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) $return = true;
        }
        fclose($filecheck2);
    } else $civ13->logger->warning('unable to open ' . $civ13->files['tdm_bans']);
    return $return;
};
$bancheck_join = function (\Civ13\Civ13 $civ13, $member) use ($bancheck)
{
    if ($member->guild_id != $civ13->civ13_guild_id) return;    
    if ($item = $civ13->verified->get('discord', $member->id)) if ($bancheck($civ13, $item['ss13'])) {
        $civ13->discord->getLoop()->addTimer(10, function() use ($civ13, $member, $item) {
            $member->setRoles([$civ13['role_ids']['banished']], 'bancheck join ' . $item['ss13']);
        });
    }
};
$slash_init = function (\Civ13\Civ13 $civ13, $commands) use ($bancheck, $unban, $restart_tdm, $restart_nomads, $nomads_mapswap, $tdm_mapswap, $ranking, $rankme, $medals, $brmedals)
{
    //Declare commands
    
    //if ($command = $commands->get('name', 'ping')) $commands->delete($command->id);
    if (!$commands->get('name', 'ping')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
            'name' => 'ping',
            'description' => 'Replies with Pong!',
    ]));
    
    //if ($command = $commands->get('name', 'restart')) $commands->delete($command->id);
    if (!$commands->get('name', 'restart')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
            'name' => 'restart',
            'description' => 'Restart the bot',
            'dm_permission' => false,
            'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['view_audit_log' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'pull')) $commands->delete($command->id);
    if (!$commands->get('name', 'pull')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
            'name' => 'pull',
            'description' => 'Update the bot\'s code',
            'dm_permission' => false,
            'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['view_audit_log' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'update')) $commands->delete($command->id);
    if (!$commands->get('name', 'update')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
            'name' => 'update',
            'description' => 'Update the bot\'s dependencies',
            'dm_permission' => false,
            'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['view_audit_log' => true]),
    ]));

    //if ($command = $commands->get('name', 'stats')) $commands->delete($command->id);
    if (!$commands->get('name', 'stats')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
        'name' => 'stats',
        'description' => 'Get runtime information about the bot',
        'dm_permission' => false,
        'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['moderate_members' => true]),
]));
    
    //if ($command = $commands->get('name', 'invite')) $commands->delete($command->id);
    if (!$commands->get('name', 'invite')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
            'name' => 'invite',
            'description' => 'Bot invite link',
            'dm_permission' => false,
            'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['manage_guild' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'players')) $commands->delete($command->id);
    if (! $commands->get('name', 'players')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
        'name' => 'players',
        'description' => 'Show Space Station 13 server information'
    ]));
    
    //if ($command = $commands->get('name', 'ckey')) $commands->delete($command->id);
    if (!$commands->get('name', 'ckey')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
        'type' => \Discord\Parts\Interactions\Command\Command::USER,
        'name' => 'ckey',
        'dm_permission' => false,
        'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['moderate_members' => true]),
    ]));
    
     //if ($command = $commands->get('name', 'ckey')) $commands->delete($command->id);
    if (!$commands->get('name', 'bancheck')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
        'type' => \Discord\Parts\Interactions\Command\Command::USER,
        'name' => 'bancheck',
        'dm_permission' => false,
        'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['moderate_members' => true]),
    ]));
    
    //if ($command = $commands->get('name', 'ranking')) $commands->delete($command->id);
    if (! $commands->get('name', 'ranking')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
        'name' => 'ranking',
        'description' => 'See the ranks of the top players on the Civ13 server'
    ]));
    
    //if ($command = $commands->get('name', 'ranking')) $commands->delete($command->id);
    if (! $commands->get('name', 'rankme')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
        'name' => 'rankme',
        'description' => 'See your ranking on the Civ13 server'
    ]));
    
    //if ($command = $commands->get('name', 'rank')) $commands->delete($command->id);
    if (!$commands->get('name', 'rank')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
        'type' => \Discord\Parts\Interactions\Command\Command::USER,
        'name' => 'rank',
        'dm_permission' => false,
    ]));
    
    //if ($command = $commands->get('name', 'medals')) $commands->delete($command->id);
    if (!$commands->get('name', 'medals')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
        'type' => \Discord\Parts\Interactions\Command\Command::USER,
        'name' => 'medals',
        'dm_permission' => false,
    ]));
    
    //if ($command = $commands->get('name', 'brmedals')) $commands->delete($command->id);
    if (!$commands->get('name', 'brmedals')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
        'type' => \Discord\Parts\Interactions\Command\Command::USER,
        'name' => 'brmedals',
        'dm_permission' => false,
    ]));
    
    $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)->commands->freshen()->done( function ($commands) use ($civ13) {
        //if ($command = $commands->get('name', 'unban')) $commands->delete($command->id);
        if (!$commands->get('name', 'unban')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
            'type' => \Discord\Parts\Interactions\Command\Command::USER,
            'name' => 'unban',
            'dm_permission' => false,
            'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['moderate_members' => true]),
        ]));
        
        //if ($command = $commands->get('name', 'restart_nomads')) $commands->delete($command->id);
        if (!$commands->get('name', 'restart_nomads')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
            'type' => \Discord\Parts\Interactions\Command\Command::CHAT_INPUT,
            'name' => 'restart_nomads',
            'description' => 'Restart the Nomads server',
            'dm_permission' => false,
            'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['view_audit_log' => true]),
        ]));
        
        //if ($command = $commands->get('name', 'restart tdm')) $commands->delete($command->id);
        if (!$commands->get('name', 'restart_tdm')) $commands->save(new \Discord\Parts\Interactions\Command\Command($civ13->discord, [
            'type' => \Discord\Parts\Interactions\Command\Command::CHAT_INPUT,
            'name' => 'restart_tdm',
            'description' => 'Restart the TDM server',
            'dm_permission' => false,
            'default_member_permissions' => (string) new \Discord\Parts\Permissions\RolePermission($civ13->discord, ['view_audit_log' => true]),
        ]));
    });
    
    $civ13->discord->listenCommand('ping', function ($interaction) use ($civ13) {
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('Pong!'));
    });
    
    $civ13->discord->listenCommand('restart', function ($interaction) use ($civ13) {
        $civ13->logger->info('[RESTART]');
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('Restarting...'));
        $civ13->discord->getLoop()->addTimer(5, function () use ($civ13) {
            \restart();
            $civ13->discord->close();
        });
    });
    
    $civ13->discord->listenCommand('pull', function ($interaction) use ($civ13) {
        $civ13->logger->info('[GIT PULL]');
        \execInBackground('git pull');
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('Updating code from GitHub...'));
    });
    
    $civ13->discord->listenCommand('update', function ($interaction) use ($civ13) {
        $civ13->logger->info('[COMPOSER UPDATE]');
        \execInBackground('composer update');
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('Updating dependencies...'));
    });
    
    $civ13->discord->listenCommand('stats', function ($interaction) use ($civ13) {
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('Civ13 Stats')->addEmbed($civ13->stats->handleInteraction($interaction)));
    });
    
    $civ13->discord->listenCommand('invite', function ($interaction) use ($civ13) {
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($civ13->discord->application->getInviteURLAttribute('8')), true);
    });
    
    $civ13->discord->listenCommand('players', function ($interaction) use ($civ13) {
        if (!$data_json = json_decode(file_get_contents('http://' . $civ13->ips['vzg']. '/servers/serverinfo.json'),  true)) return $interaction->respondWithMessage('Unable to fetch serverinfo.json, webserver might be down', true);
        $server_info[0] = ['name' => 'TDM', 'host' => 'Taislin', 'link' => '<byond://' . $civ13->ips['tdm'] . ':' . $civ13->ports['tdm'] . '>'];
        $server_info[1] = ['name' => 'Nomads', 'host' => 'Taislin', 'link' => '<byond://' . $civ13->ips['nomads'] . ':' . $civ13->ports['nomads'] . '>'];
        $server_info[2] = ['name' => 'Persistence', 'host' => 'ValZarGaming', 'link' => '<byond://' . $civ13->ips['vzg'] . ':' . $civ13->ports['persistence'] . '>'];
        $server_info[3] = ['name' => 'Blue Colony', 'host' => 'ValZarGaming', 'link' => '<byond://' . $civ13->ips['vzg'] . ':' . $civ13->ports['bc'] . '>'];
        
        $embed = new \Discord\Parts\Embed\Embed($civ13->discord);
        foreach ($data_json as $server) {
            $server_info_hard = array_shift($server_info);
            if (array_key_exists('ERROR', $server)) continue;
            if (isset($server_info_hard['name'])) $embed->addFieldValues('Server', $server_info_hard['name'] . PHP_EOL . $server_info_hard['link'], false);
            if (isset($server_info_hard['host'])) $embed->addFieldValues('Host', $server_info_hard['host'], true);
            //Round time
            if (isset($server['roundduration'])) {
                $rd = explode(":", urldecode($server['roundduration']));
                $remainder = ($rd[0] % 24);
                $rd[0] = floor($rd[0] / 24);
                if ($rd[0] != 0 || $remainder != 0 || $rd[1] != 0) $rt = $rd[0] . "d " . $remainder . "h " . $rd[1] . "m";
                else $rt = "STARTING";
                $embed->addFieldValues('Round Timer', $rt, true);
            }
            if (isset($server['map'])) $embed->addFieldValues('Map', urldecode($server['map']), true);
            if (isset($server['age'])) $embed->addFieldValues('Epoch', $server['age'], true);
            //Players
            $players = [];
            foreach (array_keys($server) as $key) {
                $p = explode('player', $key); 
                if (isset($p[1]) && is_numeric($p[1])) $players[] = $server[$key];
            }
            if (! empty($players)) $embed->addFieldValues('Players (' . count($players) . ')', implode(', ', $players), true);
            if (isset($server['season'])) $embed->addFieldValues('Season', $server['season'], true);
        }
        $embed->setFooter(($civ13->github ?  "{$civ13->github}" . PHP_EOL : '') . "{$civ13->discord->username} by Valithor#5947");
        $embed->setColor(0xe1452d);
        $embed->setTimestamp();
        $embed->setURL('');
        return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->addEmbed($embed));
    });
    
    $civ13->discord->listenCommand('ckey', function ($interaction) use ($civ13) {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("`{$interaction->data->target_id}` is registered to " . $item['ss13']), true);
    });
    $civ13->discord->listenCommand('bancheck', function ($interaction) use ($civ13, $bancheck) {
    if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        if ($bancheck($civ13, $item['ss13'])) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("`{$item['ss13']}` is currently banned on one of the Civ13.com servers."), true);
        return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("`{$item['ss13']}` is not currently banned on one of the Civ13.com servers."), true);
    });
    
    $civ13->discord->listenCommand('unban', function ($interaction) use ($civ13, $unban) {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $admin = $interaction->user->displayname;
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("**$admin** unbanned **`{$item['ss13']}`**."));
        $unban($civ13, $item['ss13'], $admin);
    });
    
    $civ13->discord->listenCommand('restart_nomads', function ($interaction) use ($civ13, $restart_nomads) {
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('Attempted to bring up Civilization 13 (TDM Server) <byond://' . $civ13->ips['tdm'] . ':' . $civ13->ports['tdm'] . '>'));
        $restart_nomads($civ13);
    });
    $civ13->discord->listenCommand('restart_tdm', function ($interaction) use ($civ13, $restart_tdm) {
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('Attempted to bring up Civilization 13 (TDM Server) <byond://' . $civ13->ips['tdm'] . ':' . $civ13->ports['tdm'] . '>'));
        $restart_tdm($civ13);
    });
    
    $civ13->discord->listenCommand('ranking', function ($interaction) use ($civ13, $ranking) {
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($ranking($civ13)), true);
    });
    $civ13->discord->listenCommand('rankme', function ($interaction) use ($civ13, $rankme) {
        if (! $item = $civ13->verified->get('discord', $interaction->member->id)) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($rankme($civ13, $item['ss13'])), true);
    });
    $civ13->discord->listenCommand('rank', function ($interaction) use ($civ13, $rankme) {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($rankme($civ13, $item['ss13'])), true);
    });
    $civ13->discord->listenCommand('medals', function ($interaction) use ($civ13, $medals) {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($medals($civ13, $item['ss13'])), true);
    });
    $civ13->discord->listenCommand('brmedals', function ($interaction) use ($civ13, $brmedals) {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($brmedals($civ13, $item['ss13'])), true);
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

$ooc_relay = function (\Civ13\Civ13 $civ13, $guild, string $file_path, string $channel_id) use ($ban)
{     
    if (! $file = fopen($file_path, 'r+')) return $civ13->logger->warning("unable to open `$file_path`");
    while (($fp = fgets($file, 4096)) !== false) {
        $fp = str_replace(PHP_EOL, '', $fp);
        //ban ckey if $fp contains a blacklisted word
        $string = substr($fp, strpos($fp, '/')+1);
        $badwords = ['beaner', 'chink', 'chink', 'coon', 'fag', 'faggot', 'gook', 'kike', 'nigga', 'nigger', 'tranny'];
        $ckey = substr($string, 0, strpos($string, ':'));
        foreach ($badwords as $badword) {
            if (str_contains(strtolower($string), $badword)) {
                $filtered = substr($badword, 0, 1);
                for ($x=1;$x<strlen($badword)-2; $x++) $filtered .= '%';
                $filtered  .= substr($badword, -1, 1);
                $ban($civ13, [$ckey, '999 years', "Blacklisted word ($filtered), please appeal on our discord"]);
            }
        }
        if ($target_channel = $guild->channels->get('id', $channel_id)) $target_channel->sendMessage($fp);
        else $civ13->logger->warning("unable to find channel `$channel_id`");
    }
    ftruncate($file, 0); //clear the file
    return fclose($file);

    /*
    echo '[RELAY - PATH] ' . $file_path . PHP_EOL;
    if ($target_channel = $guild->channels->get('id', $channel_id)) {
        if ($file = $civ13->filesystem->file($file_path)) {
            $file->getContents()->then(
            function (string $contents) use ($file, $target_channel) {
                $promise = React\Async\async(function () use ($contents, $file, $target_channel) {
                    if ($contents) echo '[RELAY - CONTENTS] ' . $contents . PHP_EOL;
                    $lines = explode(PHP_EOL, $contents);
                    $promise2 = React\Async\async(function () use ($lines, $target_channel) {
                        foreach ($lines as $line) {
                            if ($line) {
                                echo '[RELAY - LINE] ' . $line . PHP_EOL;
                                $target_channel->sendMessage($line);
                            }
                        }
                        return;
                    })();
                    React\Async\await($promise2);
                })();
                $promise->then(function () use ($file) {
                    echo '[RELAY - TRUNCATE]' . PHP_EOL;
                    $file->putContents('');
                }, function (Exception $e) {
                    echo '[RELAY - ERROR] ' . $e->getMessage() . PHP_EOL;
                });
                React\Async\await($promise);
            })->then(function () use ($file) {
                echo '[RELAY - getContents]' . PHP_EOL;
            }, function (Exception $e) {
                echo '[RELAY - ERROR] ' . $e->getMessage() . PHP_EOL;
            });
        } else echo "[RELAY - ERROR] Unable to open $file_path" . PHP_EOL;
    } else echo "[RELAY - ERROR] Unable to get channel $channel_id" . PHP_EOL;
    */
    
    /*
    if ($target_channel = $guild->channels->get('id', $channel_id)) {
        if ($file = $civ13->filesystem->file($file_path)) {
            $file->getContents()->then(function (string $contents) use ($file, $target_channel) {
                var_dump($contents);
                $contents = explode(PHP_EOL, $contents);
                foreach ($contents as $line) {
                    $target_channel->sendMessage($line);
                }
            })->then(
                function () use ($file) {
                    $file->putContents('');
                }, function (Exception $e) {
                    echo '[RELAY - getContents Error] ' . $e->getMessage() . PHP_EOL;
                }
            )->done();
        } else echo "[RELAY - ERROR] Unable to open $file_path" . PHP_EOL;
    } else echo "[RELAY - ERROR] Unable to get channel $channel_id" . PHP_EOL;
    */
};
$timer_function = function (\Civ13\Civ13 $civ13) use ($ooc_relay)
{
    if (! $guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) return $civ13->logger->warning('unable to get guild ' . $civ13->civ13_guild_id);
    $ooc_relay($civ13, $guild, $civ13->files['nomads_ooc_path'], $civ13->channel_ids['nomads_ooc_channel']);  // #ooc-nomads
    $ooc_relay($civ13, $guild, $civ13->files['nomads_admin_path'], $civ13->channel_ids['nomads_admin_channel']);  // #ahelp-nomads
    $ooc_relay($civ13, $guild, $civ13->files['tdm_ooc_path'], $civ13->channel_ids['tdm_ooc_channel']);  // #ooc-tdm
    $ooc_relay($civ13, $guild, $civ13->files['tdm_admin_path'], $civ13->channel_ids['tdm_admin_channel']);  // #ahelp-tdm
};
$on_ready = function (\Civ13\Civ13 $civ13) use ($timer_function)
{
    $civ13->logger->info('logged in as ' . $civ13->discord->user->displayname . ' (' . $civ13->discord->id . ')');
    $civ13->logger->info('------');
    
    if (! (isset($civ13->timers['relay_timer'])) || (! $civ13->timers['relay_timer'] instanceof React\EventLoop\Timer\Timer) ) {
        $civ13->logger->info('chat relay timer started');
        $civ13->timers['relay_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(10, function() use ($timer_function, $civ13) { $timer_function($civ13); });
    }
};