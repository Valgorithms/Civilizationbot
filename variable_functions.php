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
use React\EventLoop\Timer\Timer;
use React\Promise\ExtendedPromiseInterface;

$set_ips = function (Civ13 $civ13): void
{ //on ready, move into class
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
        'bc' => '1717', 
        'ps13' => '7778',
    ];
};

$status_changer = function ($discord, $activity, $state = 'online'): void
{
    $discord->updatePresence($activity, false, $state);
};
$status_changer_random = function (Civ13 $civ13) use ($status_changer): bool
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
    if ($status) {
        $activity = new Activity($civ13->discord, [ //Discord status            
            'name' => $status,
            'type' => (int) $type, //0, 1, 2, 3, 4 | Game/Playing, Streaming, Listening, Watching, Custom Status
        ]);
        $status_changer($civ13->discord, $activity, $state);
    }
    return true;
};
$status_changer_timer = function (Civ13 $civ13) use ($status_changer_random): void
{ //on ready
    $civ13->timers['status_changer_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(120, function() use ($civ13, $status_changer_random) { $status_changer_random($civ13); });
};

$browser_call = function (Civ13 $civ13, string $url, string $method = 'GET', array $headers = [], array|string $data = [], $curl = true): false|string|ExtendedPromiseInterface
{
    if (! is_string($data)) $data = http_build_query($data);
    if ( ! $curl && $browser = $civ13->browser) return $browser->{$method}($url, $headers, $data);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //return the transfer as a string
    switch ($method) {
        case 'GET':
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            break;
        default:
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    $result = curl_exec($ch);
    return $result;
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
$mapswap_nomads = function (Civ13 $civ13, string $mapto): bool
{
    if (! $file = fopen($civ13->files['map_defines_path'], 'r')) return false;
    
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
    if (! $file = fopen($civ13->files['map_defines_path'], 'r')) return false;
    
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

$filenav = function (Civ13 $civ13, string $basedir, array $subdirs) use (&$filenav): array
{
    $scandir = scandir($basedir);
    unset($scandir[1], $scandir[0]);
    if (! $subdir = trim(array_shift($subdirs))) return [false, $scandir];
    if (! in_array($subdir, $scandir)) return [false, $scandir, $subdir];
    if (is_file("$basedir/$subdir")) return [true, "$basedir/$subdir"];
    return $filenav($civ13, "$basedir/$subdir", $subdirs);
};
$log_handler = function (Civ13 $civ13, $message, string $message_content) use ($filenav)
{
    $tokens = explode(';', $message_content);
    if (!in_array(trim($tokens[0]), ['nomads', 'tdm'])) return $message->reply('Please use the format `logs nomads;folder;file` or `logs tdm;folder;file`');
    if (trim($tokens[0]) == 'nomads') {
        unset($tokens[0]);
        $results = $filenav($civ13, $civ13->files['nomads_log_basedir'], $tokens);
    } else {
        unset($tokens[0]);
        $results = $filenav($civ13, $civ13->files['tdm_log_basedir'], $tokens);
    }
    if ($results[0]) return $message->reply(MessageBuilder::new()->addFile($results[1], 'log.txt'));
    if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
    if (! isset($results[2]) || ! $results[2]) return $message->reply('Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
    return $message->reply("{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
};
$banlog_handler = function (Civ13 $civ13, $message, string $message_content_lower)
{
    if (!in_array($message_content_lower, ['nomads', 'tdm'])) return $message->reply('Please use the format `bans nomads` or `bans tdm');
    if ($message_content_lower == 'nomads') return $message->reply(MessageBuilder::new()->addFile($civ13->files['nomads_bans'], 'bans.txt'));
    return $message->reply(MessageBuilder::new()->addFile($civ13->files['tdm_bans'], 'bans.txt'));
};

$recalculate_ranking = function (Civ13 $civ13): bool
{
    if (! $search = fopen($civ13->files['tdm_awards_path'], 'r')) return false;
    $result = array();
    while (! feof($search)) {
        $medal_s = 0;
        $duser = explode(';', trim(str_replace(PHP_EOL, '', fgets($search))));
        switch ($duser[2]) {
            case 'long service medal':
            case 'wounded badge':
                $medal_s += 0.5;
                break;
            case 'tank destroyer silver badge':
            case 'wounded silver badge':
                $medal_s += 0.75;
                break;
            case 'wounded gold badge':
                $medal_s += 1;
                break;
            case 'assault badge':
            case 'tank destroyer gold badge':
                $medal_s += 1.5;
                break;
            case 'combat medical badge':
                $medal_s += 2;
                break;
            case 'iron cross 1st class':
                $medal_s += 3;
                break;
            case 'iron cross 2nd class':
                $medal_s += 5;
                break;
        }
        $result[$duser[0]] += $medal_s;
    }
    fclose ($search);
    arsort($result);
    if (! $search = fopen($civ13->files['ranking_path'], 'w')) return false;
    foreach ($result as $ckey => $score) fwrite($search, "$score;$ckey" . PHP_EOL);
    fclose ($search);
    return true;
};
$ranking = function (Civ13 $civ13): false|string
{
    $line_array = array();
    if (! $search = fopen($civ13->files['ranking_path'], 'r')) return false;
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
    if (! $search = fopen($civ13->files['ranking_path'], 'r')) return false;
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
    if (! $search = fopen($civ13->files['tdm_awards_path'], 'r')) return false;
    $found = false;
    while (! feof($search)) {
        $line = fgets($search);
        $line = trim(str_replace(PHP_EOL, '', $line)); # remove '\n' at end of line
        if (str_contains($line, $ckey)) {
            $found = true;
            $duser = explode(';', $line);
            if ($duser[0] == $ckey) {
                switch ($duser[2]) {
                    case 'long service medal':
                        $medal_s = '<:long_service:705786458874707978>';
                        break;
                    case 'combat medical badge':
                        $medal_s = '<:combat_medical_badge:706583430141444126>';
                        break;
                    case 'tank destroyer silver badge':
                        $medal_s = '<:tank_silver:705786458882965504>';
                        break;
                    case 'tank destroyer gold badge':
                        $medal_s = '<:tank_gold:705787308926042112>';
                        break;
                    case 'assault badge':
                        $medal_s = '<:assault:705786458581106772>';
                        break;
                    case 'wounded badge':
                        $medal_s = '<:wounded:705786458677706904>';
                        break;
                    case 'wounded silver badge':
                        $medal_s = '<:wounded_silver:705786458916651068>';
                        break;
                    case 'wounded gold badge':
                        $medal_s = '<:wounded_gold:705786458845216848>';
                        break;
                    case 'iron cross 1st class':
                        $medal_s = '<:iron_cross1:705786458572587109>';
                        break;
                    case 'iron cross 2nd class':
                        $medal_s = '<:iron_cross2:705786458849673267>';
                        break;
                    default: 
                        $medal_s = '<:long_service:705786458874707978>';
                }
                $result .= "**{$duser[1]}:** {$medal_s} **{$duser[2]}**, *{$duser[4]}*, {$duser[5]}" . PHP_EOL;
            }
        }
    }
    if ($result != '') return $result;
    if (! $found && ($result == '')) return 'No medals found for this ckey.';
};
$brmedals = function (Civ13 $civ13, string $ckey): string
{
    $result = '';
    $search = fopen($civ13->files['tdm_awards_br_path'], 'r');
    $found = false;
    while (! feof($search)) {
        if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {
            $found = true;
            $duser = explode(';', $line);
            if ($duser[0] == $ckey) $result .= "**{$duser[1]}:** placed *{$duser[2]} of {$duser[5]},* on {$duser[4]} ({$duser[3]})" . PHP_EOL;
        }
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
            if($log[0] == $a[8]) {
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

$rank_check = function (Civ13 $civ13, $message, array $allowed_ranks): bool
{
    $resolved_ranks = [];
    foreach ($allowed_ranks as $rank) $resolved_ranks[] = $civ13->role_ids[$rank];
    foreach ($message->member->roles as $role) if (in_array($role->id, $resolved_ranks)) return true;
    $message->reply('Rejected! You need to have at least the [' . ($message->guild->roles ? $message->guild->roles->get('id', $civ13->role_ids[array_pop($resolved_ranks)])->name : array_pop($allowed_ranks)) . '] rank.');
    return false;
};
$guild_message = function (Civ13 $civ13, $message, string $message_content, string $message_content_lower) use ($rank_check, $kill_nomads, $kill_tdm, $host_nomads, $host_tdm, $restart_nomads, $restart_tdm, $mapswap_nomads, $mapswap_tdm, $log_handler, $banlog_handler, $recalculate_ranking, $ranking, $rankme, $medals, $brmedals, $tests, $banlog_update)
{
    if (! $message->member) return $message->reply('Error! Unable to get Discord Member class.');
    
    if (str_starts_with($message_content_lower, 'approveme')) {
        if ($message->member->roles->has($civ13->role_ids['infantry']) || $message->member->roles->has($civ13->role_ids['veteran'])) return $message->reply('You already have the verification role!');
        if ($item = $civ13->verified->get('discord', $message->member->id)) {
            $message->react("👍");
            return $message->member->setRoles([$civ13->role_ids['infantry']], "approveme {$item['ss13']}");
        }
        if (! $ckey = str_replace(['.', '_', ' '], '', trim(substr($message_content_lower, 9)))) return $message->reply('Invalid format! Please use the format `approveme ckey`');
        return $message->reply($civ13->verifyProcess($ckey, $message->member->id));
    }
    if ($message_content_lower == 'permitted') {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if (empty($civ13->permitted)) return $message->reply('No users have been permitted to bypass the Byond account age requirement.');
        return $message->reply('The following ckeys are permitted to bypass the Byond account age requirement:' . PHP_EOL . '`' . implode('`' . PHP_EOL, array_keys($civ13->permitted)) . '`');
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
        if ($promotables = $mass_promotion_check($civ13, $message)) return $message->reply(MessageBuilder::new()->addFileFromContent('promotables.txt', json_encode($promotables)));;
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
        $result = $civ13->ban([$split_message[0], $split_message[1], $split_message[2] . "Appeal at {$civ13->banappeal}"], $message);
        if ($id = $civ13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)->members->get('id', $id)) 
                $member->addRole($civ13->role_ids['banished'], $result);
        
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
        $result = $civ13->banNomads([$split_message[0], $split_message[1], $split_message[2] . "Appeal at {$civ13->banappeal}"], $message);
        if ($id = $civ13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)->members->get('id', $id)) 
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
        $result = $civ13->banTDM([$split_message[0], $split_message[1], $split_message[2] . "Appeal at {$civ13->banappeal}"], $message);
        if ($id = $civ13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)->members->get('id', $id)) 
                $member->addRole($civ13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unban ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content_lower = substr($message_content_lower, 6);
        $split_message = explode('; ', $message_content_lower);
        
        $civ13->unban($split_message[0], $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$split_message[0]}**";
        if ($id = $civ13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)->members->get('id', $id)) 
                $member->removeRole($civ13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unbannomads ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content_lower = substr($message_content_lower, 6);
        $split_message = explode('; ', $message_content_lower);
        
        $civ13->unbanNomads($split_message[0], $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$split_message[0]}** from **Nomads**";
        if ($id = $civ13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)->members->get('id', $id)) 
                $member->removeRole($civ13->role_ids['banished'], $result);
        return $message->reply($result);
    }
    if (str_starts_with($message_content_lower, 'unbantdm ')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $message_content_lower = substr($message_content_lower, 6);
        $split_message = explode('; ', $message_content_lower);
        
        $civ13->unbanTDM($split_message[0], $message->author->displayname);
        $result = "**{$message->author->displayname}** unbanned **{$split_message[0]}** from **TDM**";
        if ($id = $civ13->verified->get('ss13', $split_message[0])['discord'])
            if ($member = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)->members->get('id', $id)) 
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
    if (str_starts_with($message_content_lower, 'restartciv')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $restart_nomads($civ13);
        return $message->reply("Attempted to kill, update, and bring up Nomads <byond://{$civ13->ips['nomads']}:{$civ13->ports['nomads']}>");
    }
    if (str_starts_with($message_content_lower, 'restarttdm')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        $restart_tdm($civ13);
        return $message->reply("Attempted to kill, update, and bring up TDM <byond://{$civ13->ips['tdm']}:{$civ13->ports['tdm']}>");
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
    if (str_starts_with($message_content_lower, 'maplist')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain'])) return $message->react("❌");
        return $message->channel->sendFile($civ13->files['map_defines_path'], 'maps.txt');
    }
    if (str_starts_with($message_content_lower, 'banlist')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        return $message->reply(MessageBuilder::new()->addFile($civ13->files['tdm_bans'], 'bans.txt'));
    }
    if (str_starts_with($message_content_lower, 'logs')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        if ($log_handler($civ13, $message, trim(substr($message_content, 4)))) return;
    }
    if (str_starts_with($message_content_lower, 'playerlogs')) {
        if (! $rank_check($civ13, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
        $tokens = explode(';', trim(substr($message_content, 10)));
        if (!in_array(trim($tokens[0]), ['nomads', 'tdm'])) return $message->reply('Please use the format `playerslogs nomads` or `playerlogs tdm`');
        if ($tokens[0] == 'tdm') {
            if (! is_file($civ13->files['tdm_playerlogs'])) return $message->react("🔥");
            return $message->reply(MessageBuilder::new()->addFile($civ13->files['tdm_playerlogs'], 'playerlogs.txt'));
        }
        if (! is_file($civ13->files['nomads_playerlogs'])) return $message->react("🔥");
        return $message->reply(MessageBuilder::new()->addFile($civ13->files['nomads_playerlogs'], 'playerlogs.txt'));
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
        if (! $recalculate_ranking($civ13)) return $message->reply('There was an error trying to recalculate ranking!');
        if (! $msg = $ranking($civ13)) return $message->reply('There was an error trying to recalculate ranking!');
        return $message->reply($msg);
    }
    if (str_starts_with($message_content_lower, 'rankme')) {
        if (! $ckey = trim(str_replace(['.', '_', ' '], '', substr($message_content_lower, strlen('rankme'))))) return $message->reply('Wrong format. Please try `rankme [ckey]`.');
        if (! $recalculate_ranking($civ13)) return $message->reply('There was an error trying to recalculate ranking!');
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
    if (! $file = fopen($civ13->files['nomads_discord2ooc'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true; 
};
$tdm_discord2ooc = function (Civ13 $civ13, $author, $string): bool
{
    if (! $file = fopen($civ13->files['tdm_discord2ooc'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true; 
};
$nomads_discord2admin = function (Civ13 $civ13, $author, $string): bool
{
    if (! $file = fopen($civ13->files['nomads_discord2admin'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$tdm_discord2admin = function (Civ13 $civ13, $author, $string): bool
{
    if (! $file = fopen($civ13->files['tdm_discord2admin'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$nomads_discord2dm = function (Civ13 $civ13, $author, $string): bool
{
    if (! $file = fopen($civ13->files['nomads_discord2dm'], 'a')) return false;
    fwrite($file, "$author:::$string" . PHP_EOL);
    fclose($file);
    return true;
};
$tdm_discord2dm = function (Civ13 $civ13, $author, $string): bool
{
    if (! $file = fopen($civ13->files['tdm_discord2dm'], 'a')) return false;
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
            foreach ($load_array as $line) {
                if (trim($line)) {
                    if ($x==0) {
                        $load = "CPU Usage: $line%" . PHP_EOL;
                        break;
                    } else {
                        //$load = $load . "Core $x: $line%" . PHP_EOL; //No need to report individual cores right now
                    }
                    $x++;
                }
            }
            return $message->reply($load);
        } else { //Linux
            $cpu_load = '-1';
            if ($cpu_load_array = sys_getloadavg()) $cpu_load = array_sum($cpu_load_array) / count($cpu_load_array);
            return $message->reply("CPU Usage: $cpu_load%");
        }
        return $message->reply('Unrecognized operating system!');
    }
    if (str_starts_with($message_content_lower, 'insult')) {
        $split_message = explode(' ', $message_content); //$split_target[1] is the target
        if ((count($split_message) > 1 ) && strlen($split_message[1] > 0)) {
            $incel = $split_message[1];
            $insults_array = array();
            
            if (! $file = fopen($civ13->files['insults_path'], 'r')) return $message->react("🔥");
            while (($fp = fgets($file, 4096)) !== false) $insults_array[] = $fp;
            if (count($insults_array) > 0) {
                $insult = $insults_array[rand(0, count($insults_array)-1)];
                return $message->channel->sendMessage(MessageBuilder::new()->setContent("$incel, $insult")->setAllowedMentions(['parse'=>[]]));
            }
        }
        return;
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
        $reason = "unknown";
        $found = false;
        if ($filecheck1 = fopen($civ13->files['nomads_bans'], 'r')) {
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
        if ($filecheck2 = fopen($civ13->files['tdm_bans'], 'r')) {
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
            if (! $item = $civ13->verified->get('discord', $id)) return $message->reply("`$id` is not registered to any ckey");
            if (! $age = $civ13->getByondAge($item['ss13'])) return $message->reply("`{$item['ss13']}` does not exist");
            return $message->reply("`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
        }
        if (! $age = $civ13->getByondAge($id)) return $message->reply("`$id` does not exist");
        if ($item = $civ13->verified->get('ss13', $id)) return $message->reply("`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
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

$ooc_relay = function (Civ13 $civ13, string $file_path, $channel): bool
{     
    if (! $file = fopen($file_path, 'r+')) return false;
    while (($fp = fgets($file, 4096)) !== false) {
        $fp = str_replace(PHP_EOL, '', $fp);
        //ban ckey if $fp contains a blacklisted word
        $string = substr($fp, strpos($fp, '/')+1);
        $badwords = ['beaner', 'chink', 'chink', 'coon', 'fag', 'gook', 'kike', 'nigg', 'nlgg', 'tranny'];
        $ckey = substr($string, 0, strpos($string, ':'));
        foreach ($badwords as $badword) {
            if (str_contains(strtolower($string), $badword)) {
                $filtered = substr($badword, 0, 1);
                for ($x=1;$x<strlen($badword)-2; $x++) $filtered .= '%';
                $filtered  .= substr($badword, -1, 1);
                $civ13->ban([$ckey, '999 years', "Blacklisted word ($filtered). Appeal at {$civ13->banappeal}"]);
            }
        }
        if (! $item = $civ13->verified->get('ss13', strtolower(str_replace(['.', '_', ' '], '', $ckey)))) $channel->sendMessage($fp);
        else {
            $embed = new Embed($civ13->discord);
            if ($user = $civ13->discord->users->get('id', $item['discord'])) {
                $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
                $embed->setDescription($fp);
            } else $civ13->discord->users->fetch('id', $item['discord']);
            $channel->sendEmbed($embed);
        }
    }
    ftruncate($file, 0); //clear the file
    fclose($file);
    return true;
};
$timer_function = function (Civ13 $civ13) use ($ooc_relay): void
{
        if ($guild = $civ13->discord->guilds->get('id', $civ13->civ13_guild_id)) { 
        if ($channel = $guild->channels->get('id', $civ13->channel_ids['nomads_ooc_channel']))$ooc_relay($civ13, $civ13->files['nomads_ooc_path'], $channel);  // #ooc-nomads
        if ($channel = $guild->channels->get('id', $civ13->channel_ids['nomads_admin_channel'])) $ooc_relay($civ13, $civ13->files['nomads_admin_path'], $channel);  // #ahelp-nomads
        if ($channel = $guild->channels->get('id', $civ13->channel_ids['tdm_ooc_channel'])) $ooc_relay($civ13, $civ13->files['tdm_ooc_path'], $channel);  // #ooc-tdm
        if ($channel = $guild->channels->get('id', $civ13->channel_ids['tdm_admin_channel'])) $ooc_relay($civ13, $civ13->files['tdm_admin_path'], $channel);  // #ahelp-tdm
    }
};
$on_ready = function (Civ13 $civ13) use ($timer_function): void
{//on ready
    $civ13->logger->info("logged in as {$civ13->discord->user->displayname} ({$civ13->discord->id})");
    $civ13->logger->info('------');
    
    if (! (isset($civ13->timers['relay_timer'])) || (! $civ13->timers['relay_timer'] instanceof Timer) ) {
        $civ13->logger->info('chat relay timer started');
        $civ13->timers['relay_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(10, function() use ($timer_function, $civ13) { $timer_function($civ13); });
    }
};