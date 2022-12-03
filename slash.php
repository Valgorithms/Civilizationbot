<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Builders\MessageBuilder;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Permissions\RolePermission;

class Slash
{
    public Civ13 $civ13;

    public function __construct(Civ13 &$civ13) {
        $this->civ13 = $civ13;
        $this->afterConstruct();
    }

    /*
    * This function is called after the constructor is finished.
    * It is used to load the files, start the timers, and start handling events.
    */
    protected function afterConstruct()
    {
        //
    }
    public function updateCommands($commands) //declareListeners
    {
        //if ($command = $commands->get('name', 'ping')) $commands->delete($command->id);
        if (! $commands->get('name', 'ping')) $commands->save(new Command($this->civ13->discord, [
            'name' => 'ping',
            'description' => 'Replies with Pong!',
        ]));

        //if ($command = $commands->get('name', 'pull')) $commands->delete($command->id);
        if (! $commands->get('name', 'pull')) $commands->save(new Command($this->civ13->discord, [
                'name' => 'pull',
                'description' => "Update the bot's code",
                'dm_permission' => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['view_audit_log' => true]),
        ]));

        //if ($command = $commands->get('name', 'update')) $commands->delete($command->id);
        if (! $commands->get('name', 'update')) $commands->save(new Command($this->civ13->discord, [
                'name' => 'update',
                'description' => "Update the bot's dependencies",
                'dm_permission' => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['view_audit_log' => true]),
        ]));

        //if ($command = $commands->get('name', 'stats')) $commands->delete($command->id);
        if (! $commands->get('name', 'stats')) $commands->save(new Command($this->civ13->discord, [
            'name' => 'stats',
            'description' => 'Get runtime information about the bot',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
        ]));

        //if ($command = $commands->get('name', 'invite')) $commands->delete($command->id);
        if (! $commands->get('name', 'invite')) $commands->save(new Command($this->civ13->discord, [
                'name' => 'invite',
                'description' => 'Bot invite link',
                'dm_permission' => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['manage_guild' => true]),
        ]));

        //if ($command = $commands->get('name', 'players')) $commands->delete($command->id);
        if (! $commands->get('name', 'players')) $commands->save(new Command($this->civ13->discord, [
            'name' => 'players',
            'description' => 'Show Space Station 13 server information'
        ]));

        //if ($command = $commands->get('name', 'ckey')) $commands->delete($command->id);
        if (! $commands->get('name', 'ckey')) $commands->save(new Command($this->civ13->discord, [
            'type' => Command::USER,
            'name' => 'ckey',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
        ]));

        //if ($command = $commands->get('name', 'bancheck')) $commands->delete($command->id);
        if (! $commands->get('name', 'bancheck')) $commands->save(new Command($this->civ13->discord, [
            'type' => Command::USER,
            'name' => 'bancheck',
            'dm_permission' => false,
            'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
        ]));

        //if ($command = $commands->get('name', 'ranking')) $commands->delete($command->id);
        if (! $commands->get('name', 'ranking')) $commands->save(new Command($this->civ13->discord, [
            'name' => 'ranking',
            'description' => 'See the ranks of the top players on the Civ13 server'
        ]));

        //if ($command = $commands->get('name', 'ranking')) $commands->delete($command->id);
        if (! $commands->get('name', 'rankme')) $commands->save(new Command($this->civ13->discord, [
            'name' => 'rankme',
            'description' => 'See your ranking on the Civ13 server'
        ]));

        //if ($command = $commands->get('name', 'rank')) $commands->delete($command->id);
        if (! $commands->get('name', 'rank')) $commands->save(new Command($this->civ13->discord, [
            'type' => Command::USER,
            'name' => 'rank',
            'dm_permission' => false,
        ]));

        //if ($command = $commands->get('name', 'medals')) $commands->delete($command->id);
        if (! $commands->get('name', 'medals')) $commands->save(new Command($this->civ13->discord, [
            'type' => Command::USER,
            'name' => 'medals',
            'dm_permission' => false,
        ]));

        //if ($command = $commands->get('name', 'brmedals')) $commands->delete($command->id);
        if (! $commands->get('name', 'brmedals')) $commands->save(new Command($this->civ13->discord, [
            'type' => Command::USER,
            'name' => 'brmedals',
            'dm_permission' => false,
        ]));

        $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)->commands->freshen()->done( function ($commands) {
            //if ($command = $commands->get('name', 'unban')) $commands->delete($command->id);
            if (! $commands->get('name', 'unban')) $commands->save(new Command($this->civ13->discord, [
                'type' => Command::USER,
                'name' => 'unban',
                'dm_permission' => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
            ]));
            
            //if ($command = $commands->get('name', 'restart_nomads')) $commands->delete($command->id);
            if (! $commands->get('name', 'restart_nomads')) $commands->save(new Command($this->civ13->discord, [
                'type' => Command::CHAT_INPUT,
                'name' => 'restart_nomads',
                'description' => 'Restart the Nomads server',
                'dm_permission' => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['view_audit_log' => true]),
            ]));
            
            //if ($command = $commands->get('name', 'restart tdm')) $commands->delete($command->id);
            if (! $commands->get('name', 'restart_tdm')) $commands->save(new Command($this->civ13->discord, [
                'type' => Command::CHAT_INPUT,
                'name' => 'restart_tdm',
                'description' => 'Restart the TDM server',
                'dm_permission' => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['view_audit_log' => true]),
            ]));
        });

        $this->declareListeners();
    }
    public function declareListeners()
    {
        $this->civ13->discord->listenCommand('ping', function ($interaction): void
        {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Pong!'));
        });

        $this->civ13->discord->listenCommand('stats', function ($interaction): void
        {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Civ13 Stats')->addEmbed($this->civ13->stats->handle()));
        });
        
        $this->civ13->discord->listenCommand('invite', function ($interaction): void
        {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->civ13->discord->application->getInviteURLAttribute('8')), true);
        });

        $this->civ13->discord->listenCommand('players', function ($interaction): void
        {
            if (empty($data = $this->civ13->serverinfoParse())) $interaction->respondWithMessage(MessageBuilder::new()->setContent('Unable to fetch serverinfo.json, webserver might be down'), true);
            else {
                $embed = new Embed($this->civ13->discord);
                foreach ($data as $server)
                    foreach ($server as $key => $array)
                        foreach ($array as $inline => $value)
                            $embed->addFieldValues($key, $value, $inline);
                $embed->setFooter(($this->civ13->github ?  $this->civ13->github . PHP_EOL : '') . "{$this->civ13->discord->username} by Valithor#5947");
                $embed->setColor(0xe1452d);
                $embed->setTimestamp();
                $embed->setURL('');
                $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($embed));
            }
        });

        $this->civ13->discord->listenCommand('ckey', function ($interaction): void
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            else $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$interaction->data->target_id}` is registered to `{$item['ss13']}`"), true);
        });

        $this->civ13->discord->listenCommand('bancheck', function ($interaction): void
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            elseif ($this->civ13->bancheck($item['ss13'])) $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$item['ss13']}` is currently banned on one of the Civ13.com servers."), true);
            else $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$item['ss13']}` is not currently banned on one of the Civ13.com servers."), true);
        });
        
        $this->civ13->discord->listenCommand('unban', function ($interaction): void
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            else {
                $interaction->respondWithMessage(MessageBuilder::new()->setContent("**`{$interaction->user->displayname}`** unbanned **`{$item['ss13']}`**."));
                $this->civ13->unban($item['ss13'], $interaction->user->displayname);
            }
        });
    }
}