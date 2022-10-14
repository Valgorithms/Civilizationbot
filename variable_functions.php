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

$ban = function (\Civ13\Civ13 $civ13, $array, $message = null)
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
    
    if ($file = fopen($civ13->files['tdm_discord2ban'], 'a')) {
        fwrite($file, $txt);
        fclose($file);
    } else {
        $civ13->logger->warning('unable to open ' . $civ13->files['tdm_discord2ban']);
        $result .= 'unable to open `' . $civ13->files['tdm_discord2ban'] . '`' . PHP_EOL;
    }
    $result .= '**' . $admin . '** banned **' . $array[0] . '** for **' . $array[1] . '** with the reason **' . $array[2] . '**.';
    return $result;
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
    $result .= '**' . $admin . '** banned **' . $array[0] . '** for **' . $array[1] . '** with the reason **' . $array[2] . '**.';
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
    $result .= '**' . $admin . '** banned **' . $array[0] . '** for **' . $array[1] . '** with the reason **' . $array[2] . '**.';
    return $result;
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

$discord2ckey_slash = function (\Civ13\Civ13 $civ13, $id) use ($browser_post) : \React\Promise\Promise|array
{
    if (!$result = $browser_post($civ13, 'http://civ13.valzargaming.com/discord2ckey/', ['Content-Type' => 'application/x-www-form-urlencoded'], ['discord' => $id], true)) return "<@$id> is either not registered to any ckey or the server did not return a response";
    if (is_array($result)) $result = json_decode(json_encode($result), true); //curl returns string
    elseif (is_string($result)) $result = json_decode($result); //$browser->post returns React\Promise\Promise
    
    $response = null;
    if (is_object($result) && !str_contains(get_class($result), 'React\Promise')) { //json_decoded object
        if ($ckey = $result->ckey)  $response = ["<@$id> is registered to $ckey", $ckey];
        else $response = ["<@$id> is not registered to any ckey", null];
    }
    if (is_array($result)) { //json_decoded array
        if ($ckey = $result['ckey']) $response = ["<@$id> is registered to ckey $ckey", $ckey];
        else $response = ["<@$id> is not registered to any ckey", null];
    }
    if (is_string($result)) {
        if ($result) $response = ["<@$id> is registered to $result", $result];
        else $response = ["<@$id> is not registered to any ckey", null];
    }
    
    //React\Promise\Promise from $browser->post
    return $response ?? $result->then(function ($response) use ($civ13, $id) {
        $result = json_decode((string)$response->getBody(), true);
        if ($ckey = $result['ckey']) return ["<@$id> is registered to ckey $ckey", $ckey];
        return ["<@$id> is not registered to any ckey", null];
    }, function (Exception $e) use ($civ13) {
        $civ13->logger->warning('BROWSER POST error: ' . $e->getMessage());
    });
};

$discord2ckey = function (\Civ13\Civ13 $civ13, $id) use ($browser_post)
{
    $result = $browser_post($civ13, 'http://civ13.valzargaming.com/discord2ckey/', ['Content-Type' => 'application/x-www-form-urlencoded'], ['discord' => $id], true);
    if (is_array($result)) return json_decode(json_encode($result), true); //curl returns string
    elseif (is_string($result)) return json_decode($result); //$browser->post returns React\Promise\Promise
};

$ckey2discord = function (\Civ13\Civ13 $civ13, $ckey) use ($browser_post)
{
    $result = $browser_post($civ13, 'http://civ13.valzargaming.com/ckey2discord/', ['Content-Type' => 'application/x-www-form-urlencoded'], ['ckey' => $ckey], true);
    if (is_array($result)) return json_decode(json_encode($result), true);  //curl returns string
    return json_decode($result); //$browser->post returns React\Promise\Promise
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

$filenav = function (\Civ13\Civ13 $civ13, string $basedir, array $subdirs) use (&$filenav): array
{
    $civ13->logger->debug("[FILENAV] [$basedir][`" . implode('`, `', $subdirs) . '`]');
    $scandir = scandir($basedir);
    unset($scandir[1], $scandir[0]);
    if (! $subdir = trim(array_shift($subdirs))) return [false, $scandir];
    if (! in_array($subdir, $scandir)) return [false, $scandir, $subdir];
    if (is_file("$basedir/$subdir")) return [true, "$basedir/$subdir"];
    return $filenav($civ13, "$basedir/$subdir", $subdirs);
};

$log_handler = function (\Civ13\Civ13 $civ13, $message, string $message_content_lower) use ($filenav)
{
    $tokens = explode(';', strtolower($message_content_lower));
    $civ13->logger->info('[LOG HANDLER] `' . implode('`, `', $tokens) . '`');
    if (!in_array($tokens[0], ['nomads', 'tdm'])) return $message->reply('Please use the format `logs nomads;folder;file` or `logs tdm;folder;file`');
    if (trim($tokens[0]) == 'nomads') {
        unset($tokens[0]);
        $results = $filenav($civ13, $civ13->files['nomads_log_basedir'], $tokens);
        echo '[RESULTS]'; var_dump($results);
        if ($results[0]) return $message->reply(\Discord\Builders\MessageBuilder::new()->addFile($results[1], 'log.txt'));
        if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
        echo '[MODIFIED]'; var_dump($results);
        if (! isset($results[2]) || ! $results[2]) return $message->reply('Available options: `' . PHP_EOL . implode('`' . PHP_EOL . '`', $results[1]) . '`');
        return $message->reply($results[2] . 'is not an available option! Available options: `' . PHP_EOL . implode('`' . PHP_EOL . '`', $results[1]) . '`');
    }
    if (trim($tokens[0]) == 'tdm') {
        unset($tokens[0]);
        $results = $filenav($civ13, $civ13->files['tdm_log_basedir'], $tokens);
        echo '[RESULTS]'; var_dump($results);
        if ($results[0]) return $message->reply(\Discord\Builders\MessageBuilder::new()->addFile($results[1], 'log.txt'));
        if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
        echo '[MODIFIED]'; var_dump($results[1]);
        if (! isset($results[2]) || ! $results[2]) return $message->reply('Available options: `' . PHP_EOL . implode('`' . PHP_EOL . '`', $results[1]) . '`');
        return $message->reply($results[2] . 'is not an available option! Available options: `' . PHP_EOL . implode('`' . PHP_EOL . '`', $results[1]) . '`');
    }
    return;
};

$on_message = function (\Civ13\Civ13 $civ13, $message) use ($ban, $nomads_ban, $tdm_ban, $discord2ckey, $ckey2discord, $restart_nomads, $restart_tdm, $nomads_mapswap, $tdm_mapswap, $unban, $log_handler)
{
    if ($message->guild->owner_id != $civ13->owner_id) return; //Only process commands from a guild that Taislin owns
    if (!$civ13->command_symbol) $civ13->command_symbol = '!s';
    
    $author_user = $message->author; //This will need to be updated in a future release of DiscordPHP
    if ($author_member = $message->member) {
        $author_perms = $author_member->getPermissions($message->channel); //Populate permissions granted by roles
        $author_guild = $message->guild ?? $civ13->discord->guilds->get('id', $message->guild_id);
    }
    
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
    if (str_starts_with($message_content_lower, 'ping')) return $message->reply('Pong!');
    if (str_starts_with($message_content_lower, 'help')) return $message->reply('**List of Commands**: bancheck, insult, cpu, ping, (un)whitelistme, rankme, ranking. **Staff only**: ban, hostciv, killciv, restartciv, mapswap, hosttdm, killtdm, restarttdm, tdmmapswap');
    
    if (str_starts_with($message_content_lower, 'logs')) {
        $accepted = false;
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                case $civ13->role_ids['knight']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles ? $author_guild->roles->get('id', $civ13->role_ids['knight'])->name : 'Knight' . '] rank.');
        if ($log_handler($civ13, $message, trim(substr($message_content_lower, 4)))) return;
    }
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
    if (str_starts_with($message_content_lower, 'ban ')) {
        $message_content = substr($message_content, 4);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        if ($result = $ban($civ13, $split_message, $message))
            return $message->channel->sendMessage($result);
    }
    if (str_starts_with($message_content_lower, 'nomadsban ')) {
        $message_content = substr($message_content, 10);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        if ($result = $nomads_ban($civ13, $split_message, $message))
            return $message->channel->sendMessage($result);
    }
    if (str_starts_with($message_content_lower, 'tdmban ')) {
        $message_content = substr($message_content, 7);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
        if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
        if ($result = $tdm_ban($civ13, $split_message, $message))
            return $message->channel->sendMessage($result);
    }
    if (str_starts_with($message_content_lower, 'unban ')) {
        $message_content = substr($message_content, 6);
        $split_message = explode('; ', $message_content);
        
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
    if (str_starts_with($message_content_lower, 'whitelistme')) {
        $split_message = trim(substr($message_content, 11));
        if (! strlen($split_message) > 0) return $message->channel->sendMessage('Wrong format. Please try `!s whitelistme [ckey]`.'); // if len($split_message) > 1 and len($split_message[1]) > 0:
        
        $ckey = $split_message;
        $ckey = strtolower($ckey);
        $ckey = str_replace('_', '', $ckey);
        $ckey = str_replace(' ', '', $ckey);
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                case $civ13->role_ids['knight']:
                case $civ13->role_ids['veteran']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles ? $author_guild->roles->get('id', $civ13->role_ids['veteran'])->name : 'Veteran' . '] rank.');
        
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
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                case $civ13->role_ids['knight']:
                case $civ13->role_ids['veteran']:
                case $civ13->role_ids['infantry']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles ? $author_guild->roles->get('id', $civ13->role_ids['veteran'])->name : "Veteran" . '] rank.');
        
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
    if (str_starts_with($message_content_lower, 'hostciv')) {
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles ? $author_guild->roles->get('id', $civ13->role_ids['captain'])->name : "Captain" . '] rank.');
        
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
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.'); 
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Denied!');
        
        \execInBackground('python3 ' . $civ13->files['nomads_killciv13']);
        return $message->channel->sendMessage('Attempted to kill Civilization 13 Server.');
    }
    if (str_starts_with($message_content_lower, 'restartciv')) {
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles ? $author_guild->roles->get('id', $civ13->role_ids['captain'])->name : "Captain" . '] rank.');
        return $restart_nomads($civ13, $message);
    }
    if (str_starts_with($message_content_lower, 'restarttdm')) {
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles->get('id', $civ13->role_ids['captain'])->name . '] rank.');
        return $restart_tdm($civ13, $message);
    }
    if (str_starts_with($message_content_lower, 'mapswap')) {
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles->get('id', $civ13->role_ids['captain'])->name . '] rank.');
        
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
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles->get('id', $civ13->role_ids['captain'])->name . '] rank.');
        
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
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles->get('id', $civ13->role_ids['captain'])->name . '] rank.');
        
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
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Denied!');
        
        \execInBackground('python3 ' . $civ13->files['tdm_killciv13']);
        return $message->channel->sendMessage('Attempted to kill Civilization 13 (TDM Server).');
    }
    if (str_starts_with($message_content_lower, 'tdmmapswap')) {
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                case $civ13->role_ids['knight']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles->get('id', $civ13->role_ids['knight'])->name . '] rank.');
        
        $split_message = explode('tdmmapswap ', $message_content);
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
        $accepted = false;
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        foreach ($author_member->roles as $role) {
            switch ($role->id) {
                case $civ13->role_ids['admiral']:
                case $civ13->role_ids['captain']:
                case $civ13->role_ids['knight']:
                    $accepted = true;
            }
        }
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles->get('id', $civ13->role_ids['knight'])->name . '] rank.');
        return $message->channel->sendMessage(\Discord\Builders\MessageBuilder::new()->addFile($civ13->files['tdm_bans'], 'bans.txt'));
    }
    if (str_starts_with($message_content_lower, 'bancheck')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content, strlen('bancheck'))))) return $message->reply('Wrong format. Please try `bancheck [ckey]`.');
        $banreason = "unknown";
        $found = false;
        if ($filecheck1 = fopen($civ13->files['nomads_bans'], 'r')) {
            while (($fp = fgets($filecheck1, 4096)) !== false) {
                str_replace(PHP_EOL, '', $fp);
                $filter = '|||';
                $line = trim(str_replace($filter, '', $fp));
                $linesplit = explode(';', $line); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
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
                if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
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
        $message_content = trim(substr($message_content, strlen('discord2ckey')));
        $message_content_lower = strtolower($message_content);
        preg_match('/<#([0-9]*)>/', $message_content_lower, $matches);
        if (! is_numeric($id = $matches[1])) return $message->reply("`$message_content` does not contain a discord snowflake");
        
        $civ13->logger->info("DISCORD2CKEY id $id");
        $result = $discord2ckey($civ13, $id);
        echo '[DISCORD2CKEY]'; var_dump($result);
        if (is_object($result) && !str_contains(get_class($result), 'React\Promise')) { //json_decoded object
            if ($result = $result->ckey) return $message->reply("<@$id> is registered to $result");
            return $message->reply("<@$id> is not registered to any ckey");
        }
        if (is_array($result)) { //json_decoded array
            if ($result = $result['ckey']) return $message->reply("<@$id> is registered to ckey $result");
            return $message->reply("<@$id> is not registered to any ckey");
        }
        if (is_string($result)) {
            if ($result) return $message->reply("<@$id> is registered to $result");
            return $message->reply("<@$id> is not registered to any ckey");
        }
        //React\Promise\Promise from $browser->post
        $result->then(function ($response) use ($civ13, $message, $id) {
            $result = json_decode((string)$response->getBody(), true);
            if ($ckey = $result['ckey']) return $message->reply("<@$id> is registered to ckey $ckey");
            return $message->reply("<@$id> is not registered to any ckey");
        }, function (Exception $e) use ($civ13) {
            $civ13->logger->warning('BROWSER POST error: ' . $e->getMessage());
        });
        return;
    }
    if (str_starts_with($message_content_lower, 'ckey2discord')) {
        $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content, strlen('discord2ckey'))));
        
        $civ13->logger->info("CKEY2DISCORD ckey $ckey");
        $result = $ckey2discord($civ13, $ckey);
        if (is_object($result) && !str_contains(get_class($result), 'React\Promise')) { //json_decoded object
            if ($result = $result->discord) return $message->reply("$ckey is registered to <@$result>");
            return $message->reply("$ckey is not registered to any discord account");
        }
        if (is_array($result)) { //curl json_decoded array
            if ($result = $result['id']) return $message->reply("$ckey is registered to <@$result>");
            return $message->reply("$ckey is not registered to any discord account");
        }
        if (is_string($result)) {
            if ($result) return $message->reply("$ckey is registered to <@$result>");
            return $message->reply("$ckey is not registered to any discord account");
        } //React\Promise\Promise from $browser->post
        $result->done(function ($response) use ($civ13, $message, $ckey) {
            $result = json_decode((string)$response->getBody(), true);
            if ($id = $result['discord']) return $message->reply("$ckey is registered to <@$result>");
            return $message->reply("$ckey is not registered to any discord account");
        }, function (Exception $e) use ($civ13) {
            $civ13->logger->warning('BROWSER POST error: ' . $e->getMessage());
        });
        return;
    }
    if (str_starts_with($message_content_lower, 'ckey')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content, strlen('ckey'))))) return $message->reply('Wrong format. Please try `ckey [ckey]` or `ckey [<@mention>].');
        $id = trim(str_replace(['<@', '!', '>'], '', $ckey));
        
        if (is_numeric($id)) {
            $civ13->logger->info("CKEY id $id");
            $result = $discord2ckey($civ13, $id);
        } else {
            $civ13->logger->info("CKEY ckey $ckey");
            $result = $ckey2discord($civ13, $ckey);
        }
        if (is_array($result)) { //curl json_decoded array
            if ($result_ckey = $result['ckey']) {
                $civ13->logger->info("CKEY ckey $result_ckey");
                return $message->reply("<@$id> is registered to ckey $result_ckey");
            }
            if ($result_id = $result['discord']) {
                $civ13->logger->info("CKEY id $result_id");
                return $message->reply("$ckey is registered to <@$result_id>");
            }
        } else { //React\Promise\Promise from $browser->post
            $result->then(function ($response) use ($civ13, $message, $id, $ckey) {
                $result = json_decode((string)$response->getBody(), true);
                if ($result_ckey = $result['ckey']) {
                    $civ13->logger->info("CKEY ckey $result_ckey");
                    return $message->reply("<@$id> is registered to ckey $result_ckey");
                }
                if ($result_id = $result['discord']) {
                    $civ13->logger->info("CKEY id $result_id");
                    return $message->reply("$ckey is registered to <@$result_id>");
                }
            }, function (Exception $e) use ($civ13) {
                $civ13->logger->warning('BROWSER POST error: ' . $e->getMessage());
            });
        }
        return;
    }
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

$on_message2 = function (\Civ13\Civ13 $civ13, $message) use ($recalculate_ranking, $ranking, $rankme, $medals, $brmedals)
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
    
    if (str_starts_with($message_content_lower, 'ranking')) {
        if (!$recalculate_ranking($civ13)) return $message->reply('There was an error trying to recalculate ranking!');
        if (!$msg = $ranking($civ13)) return $message->reply('There was an error trying to recalculate ranking!');
        return $message->channel->sendMessage($msg);
    }
    if (str_starts_with($message_content_lower, 'rankme')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content, strlen('rankme'))))) return $message->reply('Wrong format. Please try `rankme [ckey]`.');
        $recalculate_ranking($civ13);
        if (! $msg = $rankme($civ13, $ckey)) return $message->reply('There was an error trying to get your ranking!');
        return $message->reply($msg);
    }
    if (str_starts_with($message_content_lower, 'medals')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content, strlen('medals'))))) return $message->reply('Wrong format. Please try `medals [ckey]`.');
        if (! $msg = $medals($civ13, $ckey)) return $message->reply('There was an error trying to get your medals!');
        return $message->reply($msg);
    }
    if (str_starts_with($message_content_lower, 'brmedals')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content, strlen('brmedals'))))) return $message->reply('Wrong format. Please try `brmedals [ckey]`.');
        if (! $msg = $brmedals($civ13, $ckey)) return $message->reply('There was an error trying to get your medals!');
        return $msg;
    }
    if (str_starts_with($message_content_lower, 'ts')) {
        if (! $state = trim(substr($message_content_lower, strlen('ts')))) return $message->reply('Wrong format. Please try `ts on` or `ts off`.');
        if (! in_array($state, ['on', 'off'])) return $message->reply('Wrong format. Please try `ts on` or `ts off`.');
        $accepted = false;        
        if (! $author_member = $message->member) return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        foreach ($author_member->roles as $role) if ($role->id == $civ13->role_ids['admiral']) $accepted = true;
        if (! $accepted) return $message->channel->sendMessage('Rejected! You need to have at least the [' . $message->guild->roles ? $message->guild->roles->get('id', $civ13->role_ids['admiral'])->name : "admiral" . '] rank.');
        
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
};

/*
 *
 * Misc functions
 *
 */

$mapswap = function (\Civ13\Civ13 $civ13, $path, $mapto)
{
    $process = spawnChildProcess("python3 $path $mapto");
    $process->on('exit', function($exitCode, $termSignal) use ($civ13) {
        if ($termSignal === null) $civ13->logger->info('Mapswap exited with code ' . $exitCode);
        $civ13->logger->info('Mapswap terminated with signal ' . $termSignal);
    });
    return $process;
};

$bancheck = function (\Civ13\Civ13 $civ13, string $ckey)
{
    $return = false;
    if ($filecheck1 = fopen($civ13->files['nomads_bans'], 'r')) {
        while (($fp = fgets($filecheck1, 4096)) !== false) {
            str_replace(PHP_EOL, '', $fp);
            $filter = '|||';
            $line = trim(str_replace($filter, '', $fp));
            $linesplit = explode(';', $line); //$split_ckey[0] is the ckey
            if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
                $return = true;
            }
        }
        fclose($filecheck1);
    } else $civ13->logger->warning('unable to open ' . $civ13->files['nomads_bans']);
    if ($filecheck2 = fopen($civ13->files['tdm_bans'], 'r')) {
        while (($fp = fgets($filecheck2, 4096)) !== false) {
            str_replace(PHP_EOL, '', $fp);
            $filter = '|||';
            $line = trim(str_replace($filter, '', $fp));
            $linesplit = explode(';', $line); //$split_ckey[0] is the ckey
            if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
                $return = true;
            }
        }
        fclose($filecheck2);
    } else $civ13->logger->warning('unable to open ' . $civ13->files['tdm_bans']);
    return $return;
};

$bancheck_join = function (\Civ13\Civ13 $civ13, $guildmember) use ($discord2ckey)
{
    if ($guildmember->guild_id != $civ13->civ13_guild_id) return;
    
    if (is_array($result = $discord2ckey($civ13, $guildmember->id))) { //curl json_decoded array
        if ($ckey = $result['ckey']) {
            $bancheck = $civ13['misc']['bancheck'];
            if ($bancheck($civ13, $ckey)) {
                $civ13->discord->getLoop()->addTimer(10, function() use ($civ13, $guildmember, $ckey) {
                    $guildmember->setRoles([$civ13['role_ids']['banished']], "bancheck join $ckey");
                });
            }
        }
    } else { //React\Promise\Promise from $browser->post
        $result->then(function ($response) use ($civ13, $guildmember) {
            $result = json_decode((string)$response->getBody(), true);
            if ($ckey = $result['ckey']) {
                $bancheck = $civ13['misc']['bancheck'];
                if ($bancheck($civ13, $ckey)) {
                    $civ13->discord->getLoop()->addTimer(10, function() use ($civ13, $guildmember, $ckey) {
                        $guildmember->setRoles([$civ13['role_ids']['banished']], "bancheck join $ckey");
                    });
                }
            }
        }, function (Exception $e) use ($civ13) {
            $civ13->logger->warning('BROWSER POST error: ' . $e->getMessage());
        });
    }
};

$slash_init = function (\Civ13\Civ13 $civ13, $commands) use ($discord2ckey_slash, $bancheck, $unban, $restart_tdm, $restart_nomads, $nomads_mapswap, $tdm_mapswap, $ranking, $rankme, $medals, $brmedals)
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
        if (!$serverinfo = file_get_contents('http://' . $civ13->ips['vzg']. '/servers/serverinfo.json')) return $interaction->respondWithMessage('Unable to fetch serverinfo.json, webserver might be down');
        $data_json = json_decode($serverinfo);
        
        $desc_string_array = array();
        $desc_string = "";
        $server_state = array();
        foreach ($data_json as $varname => $varvalue){ //individual servers
            $varvalue = json_encode($varvalue);
            $server_state["$varname"] = $varvalue;
            
            $desc_string = $desc_string . $varname . ": " . urldecode($varvalue) . "\n";
            $desc_string_array[] = $desc_string ?? "null";
            $desc_string = "";
        }
        
        $servers = [
            'TDM' => 'byond://' . $civ13->ips['tdm'] . ':' . $civ13->ports['tdm'],
            'Nomads' => 'byond://' . $civ13->ips['nomads'] . ':' . $civ13->ports['nomads'],
            'Persistence' => 'byond://' . $civ13->ips['vzg'] . ':' . $civ13->ports['persistence'],
            'Blue Colony' => 'byond://' . $civ13->ips['vzg'] . ':' . $civ13->ports['bc'],
        ];
        $server_index[0] = 'TDM';
        $server_url[0] = $servers['TDM'];
        $server_index[1] = 'Nomads';
        $server_url[1] = $servers['Nomads'];
        $server_index[2] = 'Persistence';
        $server_url[2] = $servers['Persistence'];
        $server_index[3] = 'Blue Colony';
        $server_url[3] = $servers['Blue Colony'];
        //$server_index[4] = "Kepler Station CC13" . PHP_EOL;
        //$server_url[4] = "byond://69.244.83.231:7778";
        
        $server_state_dump = array(); // new assoc array for use with the embed
        foreach ($server_index as $index => $servername){ //This is stupid. The arrays above need to be rewritten as assoc $servers and the methods below need to change as such.
            $assocArray = json_decode($server_state[$index], true);
            foreach ($assocArray as $key => $value){
                if ($value) $value = urldecode($value);
                else $value = null;
                $playerlist = '';
                if ($key/* && $value && ($value != "unknown")*/) switch($key){
                    case 'version': //First key if online
                        $server_state_dump[$index]['Server'] = '<' . $server_url[$index] . '> '. PHP_EOL . $server_index[$index];
                        break;
                    case 'ERROR': //First key if offline
                        $server_state_dump[$index]['Server'] = $server_url[$index] . PHP_EOL . $server_index[$index] . PHP_EOL . '(Offline)'; //Don't show offline
                        break;
                    case 'host':
                        if ($value == NULL || $value == '') $server_state_dump[$index]['Host'] = 'Taislin'; //Taislin didn't configure the host file
                        elseif (strpos($value, 'Guest')!==false) $server_state_dump[$index]['Host'] = 'ValZarGaming'; //Byond wasn't logged in at server start
                        else $server_state_dump[$index]['Host'] = $value;
                        break;
                    /*case "players":
                        $server_state_dump[$index]["Player Count"] = $value;
                        break;*/
                    case 'age':
                        //"Epoch", urldecode($serverinfo[0]["Epoch"])
                        $server_state_dump[$index]['Epoch'] = $value;
                        break;
                    case 'season':
                        //"Season", urldecode($serverinfo[0]["Season"])
                        $server_state_dump[$index]["Season"] = $value;
                        break;
                    case 'map':
                        //"Map", urldecode($serverinfo[0]["Map"]);
                        $server_state_dump[$index]["Map"] = $value;
                        break;
                    case 'roundduration':
                        $rd = explode (":", $value);
                        $remainder = ($rd[0] % 24);
                        $rd[0] = floor($rd[0] / 24);
                        if ($rd[0] != 0 || $remainder != 0 || $rd[1] != 0) $rt = $rd[0] . "d " . $remainder . "h " . $rd[1] . "m";
                        else $rt = null; //"STARTING"; //Round is starting
                        $server_state_dump[$index]["Round Time"] = $rt;
                        break;
                    case 'stationtime':
                        $rd = explode (":", $value);
                        $remainder = ($rd[0] % 24);
                        $rd[0] = floor($rd[0] / 24);
                        if ($rd[0] != 0 || $remainder != 0 || $rd[1] != 0) $rt = $rd[0] . "d " . $remainder . "h " . $rd[1] . "m";
                        else $rt = null; //"STARTING"; //Round is starting
                        //$server_state_dump[$index]["Station Time"] = $rt;
                        break;
                    case 'cachetime':
                        //$server_state_dump[$index]["Cache Time"] = gmdate("F j, Y, g:i a", $value) . " GMT";
                        break;
                    default:
                        if ((substr($key, 0, 6) == "player") && ($key != "players") ){
                            $server_state_dump[$index]["Players"][] = $value;
                            //$playerlist = $playerlist . "$varvalue, ";
                            //"Players", urldecode($serverinfo[0]["players"])
                        }
                        break;
                }
            }
        }
        
        $embed = new \Discord\Parts\Embed\Embed($civ13->discord);
        foreach ($server_index as $x => $temp){
            if (is_array($server_state_dump[$x]))
            foreach ($server_state_dump[$x] as $key => $value){ //Status / Byond / Host / Player Count / Epoch / Season / Map / Round Time / Station Time / Players
                if (!($key && $value)) continue;
                if (is_array($value)){
                    $output_string = implode(', ', $value);
                    $embed->addFieldValues($key . " (" . count($value) . ")", $output_string, true);
                }elseif ($key == "Host"){
                    if (strpos($value, "(Offline") == false)
                    $embed->addFieldValues($key, $value, true);
                }elseif ($key == "Server"){
                    $embed->addFieldValues($key, $value, false);
                }else{
                    $embed->addFieldValues($key, $value, true);
                }
            }
        }
        //Finalize the embed
        if (isset($civ13->owner_id) && $owner = $civ13->discord->users->get('id', $civ13->owner_id)) $embed->setFooter(($civ13->github ?  "{$civ13->github}" . PHP_EOL : '') . "{$civ13->discord->username} by {$owner->displayname}");
        $embed
            ->setColor(0xe1452d)
            ->setTimestamp()
            ->setURL("");
        
        $message = \Discord\Builders\MessageBuilder::new()
            ->setContent('Players')
            ->addEmbed($embed);
        $interaction->respondWithMessage($message)->done(
        function ($success){
            //
        }, function ($error) use ($civ13) {
             $civ13->logger->warning('Error responding to interaction with message: ' . $error->getMessage());
        });
    });
    
    $civ13->discord->listenCommand('ckey', function ($interaction) use ($civ13, $discord2ckey_slash) {
        if (!$response = $discord2ckey_slash($civ13, $interaction->data->target_id)[0]) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('There was an error retrieving data'), true);
        if ($response instanceof \React\Promise\Promise) return $response->done(function ($response) use ($interaction) { $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($response), true); });
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($response), true);
    });
    $civ13->discord->listenCommand('bancheck', function ($interaction) use ($civ13, $discord2ckey_slash, $bancheck) {
        if (!$ckey = $discord2ckey_slash($civ13, $interaction->data->target_id)[1]) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('There was an error retrieving data'), true);
        if ($ckey instanceof \React\Promise\Promise) return $ckey->done(
            function ($ckey) use ($civ13, $interaction, $bancheck) {
                if ($bancheck($civ13, $ckey)) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("$ckey is currently banned on one of the Civ13.com servers."), true);
                return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("$ckey is not currently banned on one of the Civ13.com servers."), true);
            }
        );
        if ($bancheck($civ13, $ckey)) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("$ckey is currently banned on one of the Civ13.com servers."), true);
        return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("$ckey is not currently banned on one of the Civ13.com servers."), true);
    });
    
    $civ13->discord->listenCommand('unban', function ($interaction) use ($civ13, $discord2ckey_slash, $unban) {
        $admin = $interaction->user->displayname;
        if (!$ckey = $discord2ckey_slash($civ13, $interaction->data->target_id)[1]) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('There was an error retrieving data'), true);
        if ($ckey instanceof \React\Promise\Promise) return $ckey->done( function ($ckey) use ($civ13, $interaction, $unban, $admin) {
            $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("**$admin** unbanned **$ckey**."));
            $unban($civ13, $ckey, $admin);
        });
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent("**$admin** unbanned **$ckey**."));
        $unban($civ13, $ckey, $admin);
    });
    
    $civ13->discord->listenCommand('restart_nomads', function ($interaction) use ($civ13, $restart_nomads) {
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('Attempted to bring up Civilization 13 (TDM Server) <byond://' . $civ13->ips['tdm'] . ':' . $civ13->ports['tdm'] . '>'));
        $restart_nomads($civ13);
    });
    $civ13->discord->listenCommand('restart_tdm', function ($interaction) use ($civ13, $restart_tdm) {
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('Attempted to bring up Civilization 13 (TDM Server) <byond://' . $civ13->ips['tdm'] . ':' . $civ13->ports['tdm'] . '>'));
        $restart_tdm($civ13);
    });
    
    $civ13->discord->listenCommand('ranking', function ($interaction) use ($civ13, $discord2ckey_slash, $ranking) {
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($ranking($civ13)), true);
    });
    $civ13->discord->listenCommand('rankme', function ($interaction) use ($civ13, $discord2ckey_slash, $rankme) {
        if (!$response = $discord2ckey_slash($civ13, $interaction->member->id)[1]) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('There was an error retrieving data'), true);
        if ($response instanceof \React\Promise\Promise) return $response->done(function ($response) use ($civ13, $interaction, $rankme) { $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($rankme($civ13, $response)), true); });
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($rankme($civ13, $response)), true);
    });
    $civ13->discord->listenCommand('rank', function ($interaction) use ($civ13, $discord2ckey_slash, $rankme) {
        if (!$response = $discord2ckey_slash($civ13, $interaction->data->target_id)[1]) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('There was an error retrieving data'), true);
        if ($response instanceof \React\Promise\Promise) return $response->done(function ($response) use ($civ13, $interaction, $rankme, ) { $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($rankme($civ13, $response)), true); });
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($rankme($civ13, $response)), true);
    });
    $civ13->discord->listenCommand('medals', function ($interaction) use ($civ13, $discord2ckey_slash, $medals) {
        if (!$response = $discord2ckey_slash($civ13, $interaction->data->target_id)[1]) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('There was an error retrieving data'), true);
        if ($response instanceof \React\Promise\Promise) return $response->done(function ($response) use ($civ13, $interaction, $medals) { $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($medals($civ13, $response)), true); });
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($medals($civ13, $response)), true);
    });
    $civ13->discord->listenCommand('brmedals', function ($interaction) use ($civ13, $discord2ckey_slash, $brmedals) {
        if (!$response = $discord2ckey_slash($civ13, $interaction->data->target_id)[1]) return $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent('There was an error retrieving data'), true);
        if ($response instanceof \React\Promise\Promise) return $response->done(function ($response) use ($civ13, $interaction, $brmedals) { $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($brmedals($civ13, $response)), true); });
        $interaction->respondWithMessage(\Discord\Builders\MessageBuilder::new()->setContent($brmedals($civ13, $response)), true);
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