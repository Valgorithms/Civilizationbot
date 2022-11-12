<?php
$command_symbol = '!s'; //Command prefix
$owner_id = '196253985072611328'; //Taislin

//File paths
$insults_path = 'insults.txt';
$ranking_path = 'ranking.txt';

$nomads_ooc_path = '/home/1713/civ13-rp/ooc.log';
$nomads_admin_path = '/home/1713/civ13-rp/admin.log';
$nomads_discord2ooc = '/home/1713/civ13-rp/SQL/discord2ooc.txt';
$nomads_discord2admin = '/home/1713/civ13-rp/SQL/discord2admin.txt';
$nomads_discord2dm = '/home/1713/civ13-rp/SQL/discord2dm.txt';
$nomads_discord2ban = '/home/1713/civ13-rp/SQL/discord2ban.txt';
$nomads_discord2unban = '/home/1713/civ13-rp/SQL/discord2unban.txt';
$nomads_whitelist = '/home/1713/civ13-rp/SQL/whitelist.txt';
$nomads_bans = '/home/1713/civ13-rp/SQL/bans.txt';

//Unused
$nomads_playerlogs = '/home/1713/civ13-rp/SQL/playerlogs.txt';

$tdm_ooc_path = '/home/1713/civ13-tdm/ooc.log';
$tdm_admin_path = '/home/1713/civ13-tdm/admin.log';
$tdm_discord2ooc = '/home/1713/civ13-tdm/SQL/discord2ooc.txt';
$tdm_discord2admin = '/home/1713/civ13-tdm/SQL/discord2admin.txt';
$tdm_discord2dm = '/home/1713/civ13-tdm/SQL/discord2dm.txt';
$tdm_discord2ban = '/home/1713/civ13-tdm/SQL/discord2ban.txt';
$tdm_discord2unban = '/home/1713/civ13-tdm/SQL/discord2unban.txt';
$tdm_discord2ban = '/home/1713/civ13-tdm/SQL/discord2ban.txt';
$tdm_whitelist = '/home/1713/civ13-tdm/SQL/whitelist.txt';
$tdm_bans = '/home/1713/civ13-tdm/SQL/bans.txt';
$tdm_awards_path = '/home/1713/civ13-tdm/SQL/awards.txt';
$tdm_awards_br_path = '/home/1713/civ13-tdm/SQL/awards_br.txt';

//Script paths
$nomads_updateserverabspaths = '/home/1713/civ13-rp/scripts/updateserverabspaths.py';
$nomads_serverdata = '/home/1713/civ13-rp/serverdata.txt';
$nomads_dmb = '/home/1713/civ13-rp/civ13.dmb';
$nomads_killsudos = '/home/1713/civ13-rp/scripts/killsudos.py';
$nomads_killciv13 = '/home/1713/civ13-rp/scripts/killciv13.py';
$nomads_mapswap = '/home/1713/civ13-rp/scripts/mapswap.py';

$tdm_updateserverabspaths = '/home/1713/civ13-tdm/scripts/updateserverabspaths.py';
$tdm_serverdata = '/home/1713/civ13-tdm/serverdata.txt';
$tdm_dmb = '/home/1713/civ13-tdm/civ13.dmb';
$tdm_killsudos = '/home/1713/civ13-tdm/scripts/killsudos.py';
$tdm_killciv13 = '/home/1713/civ13-tdm/scripts/killciv13.py';
$mapswap_tdm = '/home/1713/civ13-tdm/scripts/mapswap.py';

$typespess_path = '/home/1713/civ13-typespess';
$typespess_launch_server_path = 'scripts/launch_server.sh';

//IPs
$nomads_ip = '51.254.161.128';
$nomads_port = '1715';
$tdm_ip = $nomads_ip;
$tdm_port = '1714';

//Discord IDs
$civ13_guild_id = '468979034571931648';
$nomads_ooc_channel = '636644156923445269'; //#ooc-nomads
$nomads_admin_channel = '637046890030170126'; //#ahelp-nomads
$tdm_ooc_channel = '636644391095631872'; //#ooc-tdm
$tdm_admin_channel = '637046904575885322'; //#ahelp-tdm

$admiral = '468980650914086913';
$captain = '792826030796308503';
$knight = '468982360659066912';
$veteran = '468983261708681216';
$infantry = '468982790772228127';


/*
/////////////////////
/////////////////////
/////////////////////
*/

set_time_limit(0);
ignore_user_abort(1);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1'); //Unlimited memory usage
define('MAIN_INCLUDED', 1); //Token and SQL credential files may be protected locally and require this to be defined to access
require getcwd(). '/token.php'; //$token
include getcwd() . '/vendor/autoload.php';

if (PHP_OS_FAMILY == "Windows") {
    function execInBackground($cmd) {
        pclose(popen("start ". $cmd, "r")); //pclose(popen("start /B ". $cmd, "r"));;
    };
} else {
    function execInBackground($cmd) {
        exec($cmd . " > /dev/null &");
    };
}

$logger = new Monolog\Logger('New logger');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout'));
$loop = React\EventLoop\Factory::create();
$discord = new \Discord\Discord([
    'token' => "$token",
    /*'socket_options' => [
        'dns' => '8.8.8.8', // can change dns
    ],*/
    'loadAllMembers' => true,
    'storeMessages' => false, //Not needed yet
    'logger' => $logger,
    'loop' => $loop,
    'intents' => \Discord\WebSockets\Intents::getDefaultIntents() | \Discord\WebSockets\Intents::GUILD_MEMBERS, // default intents as well as guild members
]);
$filesystem = \React\Filesystem\Factory::create($loop);
include 'webapi.php';

function portIsAvailable(int $port = 1714): bool
{
    $s = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    try {
        if (var_dump(socket_bind($s, "127.0.0.1", $port))) {
            socket_close($s);
            return true;
        }
    } catch (Exception $e) {
        socket_close($s);
        return false;
    }
    socket_close($s);
    return false;
}

/* Unused functions

function remove_prefix(string $text = '', string $prefix = ''): string
{
    if (str_starts_with($text, $prefix)) # only modify the text if it starts with the prefix
        $text = str_replace($prefix, '', $text);# remove one instance of prefix
    return $text;
}

function my_message($message, $discord): bool
{
    return ($message->author->id == $discord->id);
}

$search_players = function (string $ckey) use ($nomads_playerlogs): string
{
    if ($playerlogs = fopen($nomads_playerlogs, "r")) {
        while (($fp = fgets($playerlogs, 4096)) !== false) {
            if (trim(strtolower($fp)) == trim(strtolower($ckey)))
                return $ckey;
        }
        return 'None';
    } else return 'Unable to access `$nomads_playerlogs`';
};
*/

$ooc_relay = function ($guild, string $file_path, string $channel_id) use ($filesystem)
{
    if ($file = fopen($file_path, "r+")) {
        while (($fp = fgets($file, 4096)) !== false) {
            $fp = str_replace(PHP_EOL, "", $fp);
            if ($target_channel = $guild->channels->get('id', $channel_id)) $target_channel->sendMessage($fp);
            else echo "[RELAY] Unable to find channel $target_channel" . PHP_EOL;
        }
        ftruncate($file, 0); //clear the file
        fclose($file);
    } else echo "[RELAY] Unable to open $file_path" . PHP_EOL;

    /*
    echo '[RELAY - PATH] ' . $file_path . PHP_EOL;
    if ($target_channel = $guild->channels->get('id', $channel_id)) {
        if ($file = $filesystem->file($file_path)) {
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
        if ($file = $filesystem->file($file_path)) {
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

$timer_function = function () use ($discord, $ooc_relay, $civ13_guild_id, $nomads_ooc_path, $nomads_admin_path, $tdm_ooc_path, $tdm_admin_path, $nomads_ooc_channel, $nomads_admin_channel, $tdm_ooc_channel, $tdm_admin_channel)
{
    if ($guild = $discord->guilds->get('id', $civ13_guild_id)) {
        $ooc_relay($guild, $nomads_ooc_path, $nomads_ooc_channel);  // #ooc-nomads
        $ooc_relay($guild, $nomads_admin_path, $nomads_admin_channel);  // #ahelp-nomads
        $ooc_relay($guild, $tdm_ooc_path, $tdm_ooc_channel);  // #ooc-tdm
        $ooc_relay($guild, $tdm_admin_path, $tdm_admin_channel);  // #ahelp-tdm
    } else echo "[TIMER] Unable to get guild $civ13_guild_id" . PHP_EOL;
};

$on_ready = function () use ($discord, $timer_function)
{
    echo 'Logged in as ' . $discord->user->username . "#" . $discord->user->discriminator . ' (' . $discord->id . ')' .  PHP_EOL;
    echo('------' . PHP_EOL);
    
    if (! isset($GLOBALS['relay_timer']) && (! $GLOBALS['relay_timer'] instanceof React\EventLoop\Timer\Timer) ) {
        echo '[READY] Relay timer started!' . PHP_EOL;
        $GLOBALS['relay_timer'] = $discord->getLoop()->addPeriodicTimer(10, function() use ($timer_function) {
            $timer_function();
        });
    }
};

$on_message = function ($message) use ($discord, $loop, $owner_id, $admiral, $captain, $knight, $veteran, $infantry, $insults_path, $nomads_discord2ooc, $tdm_discord2ooc, $nomads_discord2admin, $tdm_discord2admin, $nomads_discord2dm, $tdm_discord2dm, $nomads_discord2ban, $tdm_discord2ban, $nomads_discord2unban, $tdm_discord2unban, $nomads_whitelist, $tdm_whitelist, $nomads_bans, $tdm_bans, $nomads_updateserverabspaths, $nomads_serverdata, $nomads_dmb, $nomads_killsudos, $nomads_killciv13, $nomads_mapswap, $mapswap_tdm, $tdm_updateserverabspaths, $tdm_serverdata, $tdm_dmb, $tdm_killsudos, $tdm_killciv13, $nomads_ip, $nomads_port, $tdm_ip, $tdm_port, $command_symbol)
{
    if ($message->guild->owner_id != $owner_id) return; //Only process commands from a guild that Taislin owns
    if (! $command_symbol) $command_symbol = '!s';
    
    $author_user = $message->author; //This will need to be updated in a future release of DiscordPHP
    if ($author_member = $message->member) {
        $author_perms = $author_member->getPermissions($message->channel); //Populate permissions granted by roles
        $author_guild = $message->guild ?? $discord->guilds->get('id', $message->guild_id);
    }
    
    $message_content = '';
    $message_content_lower = '';
    if (str_starts_with($message->content, $command_symbol . ' ')) { //Add these as slash commands?
        $message_content = substr($message->content, strlen($command_symbol)+1);
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, '<@!' . $discord->id . '>')) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($discord->id)+4));
        $message_content_lower = strtolower($message_content);
    } elseif (str_starts_with($message->content, '<@' . $discord->id . '>')) { //Add these as slash commands?
        $message_content = trim(substr($message->content, strlen($discord->id)+3));
        $message_content_lower = strtolower($message_content);
    }
    if (! $message_content) return;

    if (str_starts_with($message_content_lower, 'ping')) {
        $message->reply('Pong!');
        return;
    }
    if (str_starts_with($message_content_lower, 'help')) {
        $message->reply('**List of Commands**: bancheck, insult, cpu, ping, (un)whitelistme, rankme, ranking. **Staff only**: ban, hostciv, killciv, restartciv, mapswap, hosttdm, killtdm, restarttdm, tdmmapswap');
        return;
    }
    
    if (str_starts_with($message_content_lower,'cpu')) {
        if (substr(php_uname(), 0, 7) == "Windows") {
            $p = shell_exec('powershell -command "gwmi Win32_PerfFormattedData_PerfOS_Processor | select PercentProcessorTime"');
            $p = preg_replace('/\s+/', ' ', $p); //reduce spaces
            $p = str_replace("PercentProcessorTime", "", $p);
            $p = str_replace("--------------------", "", $p);
            $p = preg_replace('/\s+/', ' ', $p); //reduce spaces
            $load_array = explode(" ", $p);

            $load = '';
            $x=0;
            foreach ($load_array as $line) {
                if ($line != " " && $line != "") {
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
            
            if ($file = fopen($insults_path, 'r')) {
                while (($fp = fgets($file, 4096)) !== false) {
                    $insults_array[] = $fp;
                }
                if (count($insults_array) > 0) {
                    $insult = $insults_array[rand(0, count($insults_array)-1)];
                    return $message->channel->sendMessage("$incel, $insult");
                }
            } else return $message->channel->sendMessage("Unable to access `$insults_path`");
        }
        return;
    }
    if (str_starts_with($message_content_lower, 'ooc ')) {
        $message_filtered = substr($message_content, 4);
        switch (strtolower($message->channel->name)) {
            case 'ooc-nomads':                    
                $file = fopen($nomads_discord2ooc, "a");
                $txt = $message->author->username . ":::$message_filtered" . PHP_EOL;
                fwrite($file, $txt);
                fclose($file);
                break;
            case 'ooc-tdm':
                $file = fopen($tdm_discord2ooc, "a");
                $txt = $message->author->username . ":::$message_filtered" . PHP_EOL;
                fwrite($file, $txt);
                fclose($file);
                break;
            default:
                $message->reply('You need to be in either the #ooc-nomads or #ooc-tdm channel to use this command.');
        }
        return;
    }
    if (str_starts_with($message_content_lower, 'asay ')) {
        $message_filtered = substr($message_content, 5);
        switch (strtolower($message->channel->name)) {
            case 'ahelp-nomads':
                $file = fopen($nomads_discord2admin, "a");
                $txt = $message->author->username . ":::$message_filtered" . PHP_EOL;
                fwrite($file, $txt);
                fclose($file);
                break;
            case 'ahelp-tdm':
                $file = fopen($tdm_discord2admin, "a");
                $txt = $message->author->username . ":::$message_filtered" . PHP_EOL;
                fwrite($file, $txt);
                fclose($file);
                break;
            default:
                $message->reply('You need to be in either the #ahelp-nomads or #ahelp-tdm channel to use this command.');
        }
        return;
    }
    if (str_starts_with($message_content_lower, 'dm ') || str_starts_with($message_content_lower, 'pm ')) {
        $message_content = substr($message_content, 3);
        $split_message = explode(": ", $message_content);
        switch (strtolower($message->channel->name)) {
            case 'ahelp-nomads':
                $file = fopen($nomads_discord2dm, "a");
                $txt = $message->author->username.":::".$split_message[0].":::".$split_message[1].PHP_EOL;
                fwrite($file, $txt);
                fclose($file);
                break;
            case 'ahelp-tdm':
                $file = fopen($tdm_discord2dm, "a");
                $txt = $message->author->username.":::".$split_message[0].":::".$split_message[1].PHP_EOL;
                fwrite($file, $txt);
                fclose($file);
                break;
            default:
                $message->reply('You need to be in either the #ahelp-nomads or #ahelp-tdm channel to use this command.');
        }
        return;
    }
    if (str_starts_with($message_content_lower, 'ban ')) {
        $message_content = substr($message_content, 4);
        $split_message = explode('; ', $message_content); //$split_target[1] is the target
        $file = fopen($nomads_discord2ban, "a");
        $txt = $message->author->username.":::".$split_message[0].":::".$split_message[1].":::".$split_message[2].PHP_EOL;
        fwrite($file, $txt);
        fclose($file);
        
        $file = fopen($tdm_discord2ban, "a");
        $txt = $message->author->username.":::".$split_message[0].":::".$split_message[1].":::".$split_message[2].PHP_EOL;
        fwrite($file, $txt);
        fclose($file);
        $result = '**' . $message->member->username . '#' . $message->member->discriminator . '** banned **' . $split_message[0] . '** for **' . $split_message[1] . '** with the reason **' . $split_message[2] . '**.';
        return $message->channel->sendMessage($result);
    }
    if (str_starts_with($message_content_lower, 'unban ')) {
        $message_content = substr($message_content, 6);
        $split_message = explode('; ', $message_content);
        
        $file = fopen($nomads_discord2unban, "a");
        $txt = $message->author->username . "#" . $message->author->discriminator . ":::".$split_message[0];
        fwrite($file, $txt);
        fclose($file);
        
        $file = fopen($tdm_discord2unban, "a");
        $txt = $message->author->username . "#" . $message->author->discriminator . ":::".$split_message[0];
        fwrite($file, $txt);
        fclose($file);

        $result = '**' . $message->author->username . '** unbanned **' . $split_message[0] . '**.';
        return $message->channel->sendMessage($result);
    }
    #whitelist
    if (str_starts_with($message_content_lower, 'whitelistme')) {
        $split_message = trim(substr($message_content, 11));
        if (strlen($split_message) > 0) { // if len($split_message) > 1 and len($split_message[1]) > 0:
            $ckey = $split_message;
            $ckey = strtolower($ckey);
            $ckey = str_replace('_', '', $ckey);
            $ckey = str_replace(' ', '', $ckey);
            $accepted = false;
            if ($author_member = $message->member) {
                foreach ($author_member->roles as $role) {
                    switch ($role->id) {
                        case $admiral:
                        case $captain:
                        case $knight:
                        case $veteran:
                            $accepted = true;
                    }
                }
                if ($accepted) {
                    $found = false;
                    $whitelist1 = fopen($nomads_whitelist, "r") ?? NULL;
                    if ($whitelist1) {
                        while (($fp = fgets($whitelist1, 4096)) !== false) {
                            $line = trim(str_replace(PHP_EOL, "", $fp));
                            $linesplit = explode(";", $line);
                            foreach ($linesplit as $split) {
                                if ($split == $ckey)
                                    $found = true;
                            }
                        }
                        fclose($whitelist1);
                    }
                    $whitelist2 = fopen($tdm_whitelist, "r") ?? NULL;
                    if ($whitelist2) {
                        while (($fp = fgets($whitelist2, 4096)) !== false) {
                            $line = trim(str_replace(PHP_EOL, "", $fp));
                            $linesplit = explode(";", $line);
                            foreach ($linesplit as $split)
                                if ($split == $ckey)
                                    $found = true;
                        }
                        fclose($whitelist2);
                    }
                    
                    if (! $found) {
                        $found2 = false;
                        $whitelist1 = fopen($nomads_whitelist, "r") ?? NULL;
                        if ($whitelist1) {
                            while (($fp = fgets($whitelist1, 4096)) !== false) {
                                $line = trim(str_replace(PHP_EOL, "", $fp));
                                $linesplit = explode(";", $line);
                                foreach ($linesplit as $split) {
                                    if ($split == $message->member->username)
                                        $found2 = true;
                                }
                            }
                        fclose($whitelist1);
                        }
                    } else return $message->channel->sendMessage("$ckey is already in the whitelist!");
                    
                    $txt = $ckey."=".$message->member->username.PHP_EOL;
                    if ($whitelist1 = fopen($nomads_whitelist, "a")) {
                        fwrite($whitelist1, $txt);
                        fclose($whitelist1);
                    }
                    if ($whitelist2 = fopen($tdm_whitelist, "a")) {
                        fwrite($whitelist2, $txt);
                        fclose($whitelist2);
                    }
                    return $message->channel->sendMessage("$ckey has been added to the whitelist.");
                } else return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles->get('id', "$veteran")->name . '] rank.');
            } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        } else return $message->channel->sendMessage("Wrong format. Please try '!s whitelistme [ckey].'");
        return;
    }
    if (str_starts_with($message_content_lower, 'unwhitelistme')) {
        $accepted = false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                    case $knight:
                    case $veteran:
                    case $infantry:
                        $accepted = true;
                }
            }
            if ($accepted) {
                $removed = "N/A";
                $lines_array = array();
                if ($wlist = fopen($nomads_whitelist, "r")) {
                    while (($fp = fgets($wlist, 4096)) !== false) {
                        $lines_array[] = $fp;
                    }
                    fclose($wlist);
                } else return $message->channel->sendMessage("Unable to access `$nomads_whitelist`");
                if (count($lines_array) > 0) {
                    if ($wlist = fopen($nomads_whitelist, "w")) {
                        foreach ($lines_array as $line)
                            if (!str_contains($line, $message->member->username)) {
                                fwrite($wlist, $line);
                            } else {
                                $removed = explode('=', $line);
                                $removed = $removed[0];
                            }
                        fclose($wlist);
                    } else return $message->channel->sendMessage("Unable to access `$nomads_whitelist.txt`");
                }
                
                $lines_array = array();
                if ($wlist = fopen($tdm_whitelist, "r")) {
                    while (($fp = fgets($wlist, 4096)) !== false) {
                        $lines_array[] = $fp;
                    }
                    fclose($wlist);
                } else return $message->channel->sendMessage("Unable to access `$tdm_whitelist`");
                if (count($lines_array) > 0) {
                    if ($wlist = fopen($tdm_whitelist, "w")) {
                        foreach ($lines_array as $line)
                            if (!str_contains($line, $message->member->username)) {
                                fwrite($wlist, $line);
                            } else {
                                $removed = explode('=', $line);
                                $removed = $removed[0];
                            }
                        fclose($wlist);
                    } else return $message->channel->sendMessage("Unable to access `$tdm_whitelist`");
                }
                return $message->channel->sendMessage("Ckey $removed has been removed from the whitelist.");
            } else return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles->get('id', "$veteran")->name . '] rank.');
        } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        return;
    }
    if (str_starts_with($message_content_lower, 'hostciv')) {
        $accepted = false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                        $accepted = true;
                }
            }
            if ($accepted) {
                $message->channel->sendMessage("Please wait, updating the code...");
                execInBackground("sudo python3 $nomads_updateserverabspaths");
                $message->channel->sendMessage("Updated the code.");
                execInBackground("sudo rm -f $nomads_serverdata");
                execInBackground("sudo DreamDaemon $nomads_dmb $nomads_port -trusted -webclient -logself &");
                $message->channel->sendMessage("Attempted to bring up Civilization 13 (Main Server) <byond://$nomads_ip:$nomads_port>");
                $discord->getLoop()->addTimer(10, function() use ($nomads_killsudos) { # ditto
                    execInBackground("sudo python3 $nomads_killsudos");
                });
            } else return $message->channel->sendMessage("Denied!");
        } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        return;
    }
    if (str_starts_with($message_content_lower, 'killciv')) {
        $accepted = false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                        $accepted = true;
                }
            }
            if ($accepted) {
                execInBackground("sudo python3 $nomads_killciv13");
                return $message->channel->sendMessage("Attempted to kill Civilization 13 Server.");
            } else return $message->channel->sendMessage("Denied!");
        } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        return;
    }
    if (str_starts_with($message_content_lower, 'restartciv')) {
        $accepted = false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                        $accepted = true;
                }
            }
            if ($accepted) {
                execInBackground("sudo python3 $nomads_killciv13");
                $message->channel->sendMessage("Attempted to kill Civilization 13 Server.");
                execInBackground("sudo python3 $nomads_updateserverabspaths");
                $message->channel->sendMessage("Updated the code.");
                execInBackground("sudo rm -f $nomads_serverdata");
                execInBackground("sudo DreamDaemon $nomads_dmb $nomads_port -trusted -webclient -logself &");
                $message->channel->sendMessage("Attempted to bring up Civilization 13 (Main Server) <byond://$nomads_ip:$nomads_port>");
                $discord->getLoop()->addTimer(10, function() use ($nomads_killsudos) { # ditto
                    execInBackground("sudo python3 $nomads_killsudos");
                });
            } else return $message->channel->sendMessage("Denied!");
        } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        return;
    }
    if (str_starts_with($message_content_lower, 'restarttdm')) {
        $accepted = false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                        $accepted = true;
                }
            }
            if ($accepted) {
                execInBackground("sudo python3 $tdm_killciv13");
                $message->channel->sendMessage("Attempted to kill Civilization 13 TDM Server.");
                execInBackground("sudo python3 $tdm_updateserverabspaths");
                $message->channel->sendMessage("Updated the code.");
                execInBackground("sudo rm -f $tdm_serverdata");
                execInBackground("sudo DreamDaemon $tdm_dmb $tdm_port -trusted -webclient -logself &");
                $discord->getLoop()->addTimer(10, function() use ($message, $tdm_ip, $tdm_port, $tdm_killsudos) { # ditto
                    $message->channel->sendMessage("Attempted to bring up Civilization 13 (TDM Server) <byond://$tdm_ip:$tdm_port>");
                    execInBackground("sudo python3 $tdm_killsudos");
                });
            } else return $message->channel->sendMessage("Denied!");
        } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        return;
    }
    if (str_starts_with($message_content_lower, 'mapswap')) {
        $accepted = false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                        $accepted = true;
                }
            }
            if ($accepted) {
                $split_message = explode("mapswap ", $message_content);
                if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
                    $mapto = $split_message[1];
                    $mapto = strtoupper($mapto);
                    $message->channel->sendMessage("Changing map to $mapto...");
                    execInBackground("sudo python3 $nomads_mapswap $mapto");
                    $message->channel->sendMessage("Sucessfully changed map to $mapto.");
                }
            } else return $message->channel->sendMessage("Denied!");
        } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        return;
    }
    if (str_starts_with($message_content_lower, 'hosttdm')) {
        $accepted = false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                        $accepted = true;
                }
            }
            if ($accepted) {
                $message->channel->sendMessage("Please wait, updating the code...");
                execInBackground("sudo python3 $tdm_updateserverabspaths");
                $message->channel->sendMessage("Updated the code.");
                execInBackground("sudo rm -f $tdm_serverdata");
                execInBackground("sudo DreamDaemon $tdm_dmb $tdm_port -trusted -webclient -logself &");
                $message->channel->sendMessage("Attempted to bring up Civilization 13 (TDM Server) <byond://$tdm_ip:$tdm_port>");
                $discord->getLoop()->addTimer(10, function() use ($tdm_killsudos) { # ditto
                    execInBackground("sudo python3 $tdm_killsudos");
                });
            } else return $message->channel->sendMessage("Denied!");
        } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        return;
    }
    if (str_starts_with($message_content_lower, 'killtdm')) {
        $accepted = false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                        $accepted = true;
                }
            }
            if ($accepted) {
                execInBackground("sudo python3 $tdm_killciv13");
                return $message->channel->sendMessage("Attempted to kill Civilization 13 (TDM Server).");
            } else return $message->channel->sendMessage("Denied!");
        } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        return;
    }
    if (str_starts_with($message_content_lower, 'tdmmapswap')) {
        $accepted = false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                    case $knight:
                        $accepted = true;
                }
            }
            if ($accepted) {
                $split_message = explode("mapswap ", $message_content);
                if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
                    $mapto = $split_message[1];
                    $mapto = strtoupper($mapto);
                    $message->channel->sendMessage("Changing map to $mapto...");
                    execInBackground("sudo python3 $mapswap_tdm $mapto");
                    return $message->channel->sendMessage("Sucessfully changed map to $mapto.");
                }
            } else return $message->channel->sendMessage("Denied!");
        } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');
        return;
    }
    
    if (str_starts_with($message_content_lower, "banlist")) {
        $accepted=false;
        if ($author_member = $message->member) {
            foreach ($author_member->roles as $role) {
                switch ($role->id) {
                    case $admiral:
                    case $captain:
                    case $knight:
                        $accepted = true;
                }
            }
        }
        if ($accepted) {
            $builder = \Discord\Builders\MessageBuilder::new();
            $builder->addFile($tdm_bans, 'bans.txt');
            return $message->channel->sendMessage($builder);
        } return $message->channel->sendMessage('Rejected! You need to have at least the [' . $author_guild->roles->get('id', "$knight")->name . '] rank.');
    }
    
    if (str_starts_with($message_content_lower, "bancheck")) {
        $split_message = explode('bancheck ', $message_content);
        if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
            $ckey = trim($split_message[1]);
            $ckey = strtolower($ckey);
            $ckey = str_replace('_', '', $ckey);
            $ckey = str_replace(' ', '', $ckey);
            $banreason = "unknown";
            $found = false;
            $filecheck1 = fopen($nomads_bans, "r") ?? NULL;
            if ($filecheck1) {
                while (($fp = fgets($filecheck1, 4096)) !== false) {
                    str_replace(PHP_EOL, "", $fp);
                    $filter = "|||";
                    $line = trim(str_replace($filter, "", $fp));
                    $linesplit = explode(";", $line); //$split_ckey[0] is the ckey
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
            $filecheck2 = fopen($tdm_bans, "r") ?? NULL;
            if ($filecheck2) {
                while (($fp = fgets($filecheck2, 4096)) !== false) {
                    str_replace(PHP_EOL, "", $fp);
                    $filter = "|||";
                    $line = trim(str_replace($filter, "", $fp));
                    $linesplit = explode(";", $line); //$split_ckey[0] is the ckey
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
            if (! $found) return $message->channel->sendMessage("No bans were found for **$ckey**.");
        } else return  $message->channel->sendMessage("Wrong format. Please try '!s bancheck [ckey].'");
        return;
    }
    if (str_starts_with($message_content_lower,'serverstatus')) { //See GitHub Issue #1
        $embed = new \Discord\Parts\Embed\Embed($discord);
        $_1714 = !portIsAvailable(1714);
        $server_is_up = $_1714;
        if (! $server_is_up) {
            $embed->setColor(0x00ff00);
            $embed->addFieldValues("TDM Server Status", "Offline");
            #$message->channel->sendEmbed($embed);
            #return;
        } else {
            $data = "None";
            if ($_1714) {
                if (! $data = file_get_contents($tdm_serverdata))
                    return $message->channel->sendMessage("Unable to access `$tdm_serverdata`");
            } else {
                $embed->setColor(0x00ff00);
                $embed->addFieldValues("TDM Server Status", "Offline");
                #$message->channel->sendEmbed($embed);
                #return;
            }
            $data = str_replace('<b>Address</b>: ', '', $data);
            $data = str_replace('<b>Map</b>: ', '', $data);
            $data = str_replace('<b>Gamemode</b>: ', '', $data);
            $data = str_replace('<b>Players</b>: ', '', $data);
            $data = str_replace('</b>', '', $data);
            $data = str_replace('<b>', '', $data);
            $data = explode(';', $data);
            #embed = discord.Embed(title="**Civ13 Bot**", color=0x00ff00)
            $embed->setColor(0x00ff00);
            $embed->addFieldValues("TDM Server Status", "Online");
            if (isset($data[1])) $embed->addFieldValues("Address", '<'.$data[1].'>');
            if (isset($data[2])) $embed->addFieldValues("Map", $data[2]);
            if (isset($data[3])) $embed->addFieldValues("Gamemode", $data[3]);
            if (isset($data[4])) $embed->addFieldValues("Players", $data[4]);

            #$message->channel->sendEmbed($embed);
            #return;
        }
        $_1715 = !portIsAvailable(1715);
        $server_is_up = ($_1715);
        if (! $server_is_up) {
            $embed->setColor(0x00ff00);
            $embed->addFieldValues("Nomads Server Status", "Offline");
            #$message->channel->sendEmbed($embed);
            #return;
        } else {
            $data = "None";
            if ($_1714) {
                if (! $data = file_get_contents($nomads_serverdata))
                    return $message->channel->sendMessage("Unable to access `$nomads_serverdata`");
            } else {
                $embed->setColor(0x00ff00);
                $embed->addFieldValues("Nomads Server Status", "Offline");
                #$message->channel->sendEmbed($embed);
                #return;
            }
            $data = str_replace('<b>Address</b>: ', '', $data);
            $data = str_replace('<b>Map</b>: ', '', $data);
            $data = str_replace('<b>Gamemode</b>: ', '', $data);
            $data = str_replace('<b>Players</b>: ', '', $data);
            $data = str_replace('</b>', '', $data);
            $data = str_replace('<b>', '', $data);
            $data = explode(';', $data);
            #embed = discord.Embed(title="**Civ13 Bot**", color=0x00ff00)
            $embed->setColor(0x00ff00);
            $embed->addFieldValues("Nomads Server Status", "Online");
            if (isset($data[1])) $embed->addFieldValues("Address", '<'.$data[1].'>');
            if (isset($data[2])) $embed->addFieldValues("Map", $data[2]);
            if (isset($data[3])) $embed->addFieldValues("Gamemode", $data[3]);
            if (isset($data[4])) $embed->addFieldValues("Players", $data[4]);
        }
        $message->channel->sendEmbed($embed);
        return;
    }
};

$recalculate_ranking = function () use ($tdm_awards_path, $ranking_path)
{
    $ranking = array();
    $ckeylist = array();
    $result = array();
    
    if ($search = fopen($tdm_awards_path, "r")) {
        while(! feof($search)) {
            $medal_s = 0;
            $line = fgets($search);
            $line = trim(str_replace(PHP_EOL, "", $line)); # remove '\n' at end of line
            $duser = explode(';', $line);
            if ($duser[2] == "long service medal")
                $medal_s += 0.5;
            if ($duser[2] == "combat medical badge")
                $medal_s += 2;
            if ($duser[2] == "tank destroyer silver badge")
                $medal_s += 0.75;
            if ($duser[2] == "tank destroyer gold badge")
                $medal_s += 1.5;
            if ($duser[2] == "assault badge")
                $medal_s += 1.5;
            if ($duser[2] == "wounded badge")
                $medal_s += 0.5;
            if ($duser[2] == "wounded silver badge")
                $medal_s += 0.75;
            if ($duser[2] == "wounded gold badge")
                $medal_s += 1;
            if ($duser[2] == "iron cross 1st class")
                $medal_s += 3;
            if ($duser[2] == "iron cross 2nd class")
                $medal_s += 5;
            $result[] = $medal_s . ';' . $duser[0];
            if (!in_array($duser[0], $ckeylist))
                $ckeylist[] = $duser[0];
        }
    } else return false; //$message->channel->sendMessage("Unable to access `$tdm_awards_path`");
    
    foreach ($ckeylist as $i) {
        $sumc = 0;
        foreach ($result as $j) {
            $sj = explode(';', $j);
            if ($sj[1] == $i)
                $sumc += (float) $sj[0];
        }
        $ranking[] = [$sumc,$i];
    }
    usort($ranking, function($a, $b) {
        return $a[0] <=> $b[0];
    });
    $sorted_list = array_reverse($ranking);
    if ($search = fopen($ranking_path, 'w')) {
        foreach ($sorted_list as $i)
            fwrite($search, $i[0] . ";" . $i[1] . PHP_EOL);
    } else return false; //$message->channel->sendMessage("Unable to access `$ranking`");
    fclose ($search);
};

$on_message2 = function ($message) use ($discord, $loop, $recalculate_ranking, $owner_id, $ranking_path, $tdm_awards_path, $tdm_awards_br_path, $typespess_path, $typespess_launch_server_path, $command_symbol, $admiral)
{
    if ($message->guild->owner_id != $owner_id) return; //Only process commands from a guild that Taislin owns
    if (! $command_symbol) $command_symbol = '!s';
    
    if (str_starts_with($message->content, $command_symbol . ' ')) { //Add these as slash commands?
        $message_content = substr($message->content, strlen($command_symbol)+1);
        $message_content_lower = strtolower($message_content);
        if (str_starts_with($message_content_lower, 'ranking')) {
            $recalculate_ranking();
            $line_array = array();
            if ($search = fopen($ranking_path, "r")) {
                while (($fp = fgets($search, 4096)) !== false) {
                    $line_array[] = $fp;
                }
                fclose($search);
            } else return $message->channel->sendMessage("Unable to access `$ranking_path`");
            $topsum = 1;
            $msg = '';
            for ($x=0;$x<count($line_array);$x++) {
                $line = $line_array[$x];
                if ($topsum <= 10) {
                    $line = trim(str_replace(PHP_EOL, "", $line));
                    $topsum += 1;
                    $sline = explode(';', $line);
                    $msg .= "(". ($topsum - 1) ."): **".$sline[1]."** with **".$sline[0]."** points." . PHP_EOL;
                } else break;
            }
            if ($msg != '') return $message->channel->sendMessage($msg);
        }
        if (str_starts_with($message_content_lower, 'rankme')) {
            $split_message = explode('rankme ', $message_content);
            $ckey = "";
            $medal_s = 0;
            $result = "";
            if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
                $ckey = $split_message[1];
                $ckey = strtolower($ckey);
                $ckey = str_replace('_', '', $ckey);
                $ckey = str_replace(' ', '', $ckey);
            }
            $recalculate_ranking();
            $line_array = array();
            if ($search = fopen($ranking_path, "r")) {
                while (($fp = fgets($search, 4096)) !== false) {
                    $line_array[] = $fp;
                }
                fclose($search);
            } else return $message->channel->sendMessage("Unable to access `$ranking_path`");
            $found = 0;
            $result = '';
            for ($x=0;$x<count($line_array);$x++) {
                $line = $line_array[$x];
                $line = trim(str_replace(PHP_EOL, "", $line));
                $sline = explode(';', $line);
                if ($sline[1] == $ckey) {
                    $found = 1;
                    $result .= "**" . $sline[1] . "**" . " has a total rank of **" . $sline[0] . "**.";
                };
            }
            if (! $found) return $message->channel->sendMessage("No medals found for this ckey.");
            return $message->channel->sendMessage($result);
        }
        if (str_starts_with($message_content_lower, 'medals')) {
            $split_message = explode('medals ', $message_content);
            $ckey = "";
            if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
                $ckey = $split_message[1];
                $ckey = strtolower($ckey);
                $ckey = str_replace('_', '', $ckey);
                $ckey = str_replace(' ', '', $ckey);
            }
            $result = '';
            $search = fopen($tdm_awards_path, 'r');
            $found = false;
            while(! feof($search)) {
                $line = fgets($search);
                $line = trim(str_replace(PHP_EOL, "", $line)); # remove '\n' at end of line
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
                        $result .= "**" . $duser[1] . ":**" . " " . $medal_s . " **" . $duser[2] . "**, *" . $duser[4] . "*, " . $duser[5] . PHP_EOL;
                    }
                }
            }
            if ($result != '') return $message->channel->sendMessage($result);
            if (! $found && ($result == '')) return $message->channel->sendMessage("No medals found for this ckey.");
        }
        if (str_starts_with($message_content_lower, 'brmedals')) {
            $split_message = explode('brmedals ', $message_content);
            $ckey = "";
            if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
                $ckey = $split_message[1];
                $ckey = strtolower($ckey);
                $ckey = str_replace('_', '', $ckey);
                $ckey = str_replace(' ', '', $ckey);
            }
            $result = '';
            $search = fopen($tdm_awards_br_path, 'r');
            $found = false;
            while(! feof($search)) {
                $line = fgets($search);
                $line = trim(str_replace(PHP_EOL, "", $line)); # remove '\n' at end of line
                if (str_contains($line, $ckey)) {
                    $found = true;
                    $duser = explode(';', $line);
                    if ($duser[0] == $ckey) {
                        $result .= "**" . $duser[1] . ":** placed *" . $duser[2] . " of  ". $duser[5] . ",* on " . $duser[4] . " (" . $duser[3] . ")" . PHP_EOL;
                    }
                }
            }
            if ($result != '') return $message->channel->sendMessage($result);
            if (! $found && ($result == '')) return $message->channel->sendMessage("No medals found for this ckey.");
        }
        if (str_starts_with($message_content_lower, 'ts')) {
            $split_message = explode('ts ', $message_content);
            if ((count($split_message) > 1) && (strlen($split_message[1]) > 0)) {
                $state = $split_message[1];
                $accepted = false;
                
                if ($author_member = $message->member) {
                    foreach ($author_member->roles as $role) {
                        switch ($role->id) {
                            case $admiral:
                                $accepted = true;
                        }
                    }
                } else return $message->channel->sendMessage('Error! Unable to get Discord Member class.');

                if ($accepted) {
                    if ($state == "on") {
                        execInBackground("cd $typespess_path");
                        execInBackground('sudo git pull');
                        execInBackground("sudo sh $typespess_launch_server_path &");
                        return $message->channel->sendMessage("Put **TypeSpess Civ13** test server on: http://civ13.com/ts");
                    } elseif ($state == "off") {
                        execInBackground('sudo killall index.js');
                        return $message->channel->sendMessage("**TypeSpess Civ13** test server down.");
                    }
                }
            }
        }
    }
};

$discord->once('ready', function ($discord) use ($loop, $on_ready, $on_message, $on_message2, $owner_id)
{
    $on_ready();
    
    $discord->on('message', function ($message) use ($owner_id, $on_message, $on_message2)
    {   //Handling of a message
        if ($message->channel->type == 1) return; //Only process commands from a guild
        if ($message->guild->owner_id != $owner_id) return; //Only process commands from a guild that Taislin owns
        $on_message($message);
        $on_message2($message);
    });
});

set_error_handler(function (int $number, string $message, string $filename, int $fileline)
{
    $warn = true;
    
    if ($message != "Undefined variable: suggestion_pending_channel") $warn = false; //Expected to be null
    if ($message != "Trying to access array offset on value of type null") $warn = false; //Expected to be null, part of ;validate*/
    
    $skip_array = array();
    $skip_array[] = "Undefined variable";
    $skip_array[] = "Trying to access array offset on value of type null"; //Expected to be null, part of ;validate
    foreach ($skip_array as $value) {
        if (strpos($value, $message) === false) {
            $warn = false;
        }
    }
    if ($warn) {
        ob_flush();
        ob_start();
        echo PHP_EOL . "Handler captured error $number: '$message' at `$filename:$fileline`" . PHP_EOL;
        file_put_contents("error_main.txt", ob_get_flush());
    }
});

$discord->run();