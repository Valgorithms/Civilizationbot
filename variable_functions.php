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
    if (! $civ13::status) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning('status is not defined');
        return false;
    }
    if (! $status_array = file($civ13::status, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) {
        unset($civ13->timers['status_changer_timer']);
        $civ13->logger->warning('unable to open file `' . $civ13::status . '`');
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

$on_message = function (Civ13 $civ13, Message $message, ?array $message_filtered = null): ?PromiseInterface
{ // on message
    $message_array = $message_filtered ?? $civ13->filterMessage($message);
    if (! $message_array['called']) return null; // Not a command
    if (! $message_array['message_content_lower']) { // No command given
        $random_responses = ['You can see a full list of commands by using the `help` command.'];
        if (count($random_responses) > 0) return $civ13->sendMessage($message->channel, "<@{$message->author->id}>, " . $random_responses[rand(0, count($random_responses)-1)]);
    }
    if ($message_array['message_content_lower'] === 'dev')
        if (isset($civ13->technician_id) && isset($civ13->role_ids['Chief Technical Officer']))
            if ($message->user_id === $civ13->technician_id)
                return $message->member->addRole($civ13->role_ids['Chief Technical Officer']);
    return null;
};

$slash_init = function (Civ13 $civ13, $commands): void
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

    foreach (array_keys($this->server_settings) as $key => $settings) {
        $server = strtolower($key);

        if (isset($settings['ip'], $settings['port'])) $civ13->discord->listenCommand($server.'_restart', function (Interaction $interaction) use ($server, $key, $settings): void
        {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent("Attempted to kill, update, and bring up `$key` <byond://{$settings['ip']}:{$settings['port']}>"));
            if ($serverrestart = array_shift($this->messageHandler->offsetGet($server.'restart'))) $serverrestart();
        });
    }

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
      $interaction->acknowledge()->then(function () use ($interaction, $civ13) { // wait until the bot says "Is thinking..."
        // do heavy code here (up to 15 minutes)
        // ...
        // send follow up (instead of respond)
        $interaction->sendFollowUpMessage(MessageBuilder...);
      });
    });
    */
};
/*$on_ready = function (Civ13 $civ13): void
{    
    // 
};*/