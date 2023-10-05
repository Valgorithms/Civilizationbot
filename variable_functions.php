<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */ 

use Civ13\Civ13;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
//use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Activity;
use React\Promise\PromiseInterface;

$status_changer_random = function (Civ13 $civ13): bool
{ // on ready
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
    $activity = new Activity($civ13->discord, [ // Discord status            
        'name' => $status,
        'type' => (int) $type, // 0, 1, 2, 3, 4 | Game/Playing, Streaming, Listening, Watching, Custom Status
    ]);
    $civ13->statusChanger($activity, $state);
    return true;
};
$status_changer_timer = function (Civ13 $civ13) use ($status_changer_random): void
{ // on ready
    if (! isset($civ13->timers['status_changer_timer'])) $civ13->timers['status_changer_timer'] = $civ13->discord->getLoop()->addPeriodicTimer(120, function () use ($civ13, $status_changer_random) { $status_changer_random($civ13); });
};

$ranking = function (Civ13 $civ13): false|string
{
    $line_array = array();
    if (! isset($civ13->files['ranking_path']) || ! file_exists($civ13->files['ranking_path']) || ! $search = @fopen($civ13->files['ranking_path'], 'r')) return false;
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
    if (! file_exists($civ13->files['ranking_path']) || ! $search = @fopen($civ13->files['ranking_path'], 'r')) return false;
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

/*
$medals = function (Civ13 $civ13, string $ckey): false|string
{
    $result = '';
    if (! file_exists($civ13->files['tdm_awards_path']) || ! $search = @fopen($civ13->files['tdm_awards_path'], 'r')) return false;
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
    if (! file_exists($civ13->files['tdm_awards_br_path']) || ! $search = @fopen($civ13->files['tdm_awards_br_path'], 'r')) return 'Error getting file.';
    $found = false;
    while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {
        $found = true;
        $duser = explode(';', $line);
        if ($duser[0] == $ckey) $result .= "**{$duser[1]}:** placed *{$duser[2]} of {$duser[5]},* on {$duser[4]} ({$duser[3]})" . PHP_EOL;
    }
    if (! $found) return 'No medals found for this ckey.';
    return $result;
};
*/

$on_message = function (Civ13 $civ13, Message $message, ?array $message_filtered = null): ?PromiseInterface
{ // on message
    $message_array = $message_filtered ?? $civ13->filterMessage($message);
    if (! $message_array['called']) return null; // Not a command
    if (! $message_array['message_content']) { // No command given
        $random_responses = ['You can see a full list of commands by using the `help` command.'];
        if (count($random_responses) > 0) return $civ13->sendMessage($message->channel, "<@{$message->author->id}>, " . $random_responses[rand(0, count($random_responses)-1)]);
    }
    if ($message_array['message_content'] == 'dev')
        if (isset($civ13->technician_id) && isset($civ13->role_ids['Chief Technical Officer']))
            if ($message->user_id === $civ13->technician_id)
                return $message->member->addRole($civ13->role_ids['Chief Technical Officer']);
    return null;
};

$slash_init = function (Civ13 $civ13, $commands) use ($ranking, $rankme): void
{ // ready_slash, requires other functions to work
    $civ13->discord->listenCommand('pull', function (Interaction $interaction) use ($civ13): void
    {
        $civ13->logger->info('[GIT PULL]');
        \execInBackground('git pull');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating code from GitHub...'));
    });
    
    $civ13->discord->listenCommand('update', function (Interaction $interaction) use ($civ13): void
    {
        $civ13->logger->info('[COMPOSER UPDATE]');
        \execInBackground('composer update');
        $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating dependencies...'));
    });
    
    $civ13->discord->listenCommand('ranking', function (Interaction $interaction) use ($civ13, $ranking): void
    {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($ranking($civ13)), true);
    });
    $civ13->discord->listenCommand('rankme', function (Interaction $interaction) use ($civ13, $rankme): void
    {
        if (! $item = $civ13->verified->get('discord', $interaction->member->id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        else $interaction->respondWithMessage(MessageBuilder::new()->setContent($rankme($civ13, $item['ss13'])), true);
    });

    foreach (array_keys($this->server_settings) as $key => $settings) {
        $server = strtolower($key);

        if (isset($settings['ip'], $settings['port'])) $civ13->discord->listenCommand($server.'_restart', function (Interaction $interaction) use ($civ13, $server, $key, $settings): void
        {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent("Attempted to kill, update, and bring up $key <byond://{$settings['ip']}:{$settings['port']}>"));
            if ($serverrestart = array_shift($this->messageHandler->offsetGet($server.'restart'))) $serverrestart();
        });
    }

    /* Deprecated
    $civ13->discord->listenCommand('rank', function (Interaction $interaction) use ($civ13, $rankme): void
    {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        else $interaction->respondWithMessage(MessageBuilder::new()->setContent($rankme($civ13, $item['ss13'])), true);
    });*/
    /* Deprecated
    $civ13->discord->listenCommand('medals', function (Interaction $interaction) use ($civ13, $medals): void
    {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        else $interaction->respondWithMessage(MessageBuilder::new()->setContent($medals($civ13, $item['ss13'])), true);
    });*/
    /* Deprecated
    $civ13->discord->listenCommand('brmedals', function (Interaction $interaction) use ($civ13, $brmedals): void
    {
        if (! $item = $civ13->verified->get('discord', $interaction->data->target_id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
        else $interaction->respondWithMessage(MessageBuilder::new()->setContent($brmedals($civ13, $item['ss13'])), true);
    });*/

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