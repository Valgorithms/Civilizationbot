<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Builders\MessageBuilder;
use React\Promise\PromiseInterface;
use Discord\Helpers\Repository;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Permissions\RolePermission;
use Discord\Repository\Guild\GuildCommandRepository;

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
    public function updateCommands($commands): void
    {
        if ($this->civ13->shard) return; // Only run on the first shard

        // if ($command = $commands->get('name', 'ping')) $commands->delete($command->id);
        if (! $commands->get('name', 'ping')) $commands->save(new Command($this->civ13->discord, [
            'name'        => 'ping',
            'description' => 'Replies with Pong!',
        ]));

        // if ($command = $commands->get('name', 'ping')) $commands->delete($command->id);
        if (! $commands->get('name', 'help')) $commands->save(new Command($this->civ13->discord, [
            'name'          => 'help',
            'description'   => 'View a list of available commands',
            'dm_permission' => false,
        ]));


        // if ($command = $commands->get('name', 'pull')) $commands->delete($command->id);
        if (! $commands->get('name', 'pull')) $commands->save(new Command($this->civ13->discord, [
                'name'                       => 'pull',
                'description'                => "Update the bot's code",
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['view_audit_log' => true]),
        ]));

        // if ($command = $commands->get('name', 'update')) $commands->delete($command->id);
        if (! $commands->get('name', 'update')) $commands->save(new Command($this->civ13->discord, [
                'name'                       => 'update',
                'description'                => "Update the bot's dependencies",
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['view_audit_log' => true]),
        ]));

        // if ($command = $commands->get('name', 'stats')) $commands->delete($command->id);
        if (! $commands->get('name', 'stats')) $commands->save(new Command($this->civ13->discord, [
            'name'                       => 'stats',
            'description'                => 'Get runtime information about the bot',
            'dm_permission'              => false,
            'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
        ]));

        // if ($command = $commands->get('name', 'invite')) $commands->delete($command->id);
        if (! $commands->get('name', 'invite')) $commands->save(new Command($this->civ13->discord, [
                'name'                       => 'invite',
                'description'                => 'Bot invite link',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['manage_guild' => true]),
        ]));

        // if ($command = $commands->get('name', 'players')) $commands->delete($command->id);
        if (! $commands->get('name', 'players')) $commands->save(new Command($this->civ13->discord, [
            'name'        => 'players',
            'description' => 'Show Space Station 13 server information'
        ]));

        // if ($command = $commands->get('name', 'ckey')) $commands->delete($command->id);
        if (! $commands->get('name', 'ckey')) $commands->save(new Command($this->civ13->discord, [
            'type'                       => Command::USER,
            'name'                       => 'ckey',
            'dm_permission'              => false,
            'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
        ]));

        // if ($command = $commands->get('name', 'bancheck')) $commands->delete($command->id);
        if (! $commands->get('name', 'bancheck')) $commands->save(new Command($this->civ13->discord, [
            'type'                       => Command::USER,
            'name'                       => 'bancheck',
            'dm_permission'              => false,
            'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
        ]));

        // if ($command = $commands->get('name', 'bancheck_ckey')) $commands->delete($command->id);
        if (! $commands->get('name', 'bancheck_ckey')) $commands->save(new Command($this->civ13->discord, [
            'name'                       => 'bancheck_ckey',
            'description'                => 'Check if a ckey is banned on the server',
            'dm_permission'              => false,
            'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
            'options'                    => [
                [
                    'name'        => 'ckey',
                    'description' => 'Byond.com username',
                    'type'        =>  3,
                    'required'    => true,
                ]
            ]
        ]));

        // if ($command = $commands->get('name', 'ban')) $commands->delete($command->id);
        if (! $commands->get('name', 'ban')) {
            $command = new \Discord\Parts\Interactions\Command\Command($this->civ13->discord, [
                'name'			=> 'ban',
                'description'	=> 'Ban a ckey from the Civ13.com servers',
                'dm_permission' => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
                'options'		=> [
                    [
                        'name'			=> 'ckey',
                        'description'	=> 'The byond username being banned',
                        'type'			=>  3,
                        'required'		=> true,
                    ],
                    [
                        'name'			=> 'duration',
                        'description'	=> 'How long to ban the user for (e.g. 999 years)',
                        'type'			=>  3,
                        'required'		=> true,
                    ],
                    [
                        'name'			=> 'reason',
                        'description'	=> 'Why the user is being banned',
                        'type'			=>  3,
                        'required'		=> true,
                    ],
                ]
            ]);
            $commands->save($command);
        }

        // if ($command = $commands->get('name', 'panic')) $commands->delete($command->id);
        if (! $commands->get('name', 'panic')) $commands->save(new Command($this->civ13->discord, [
            'name'                       => 'panic',
            'description'                => 'Toggles the panic bunker',
            'dm_permission'              => false,
            'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['manage_guild' => true]),
        ]));

        if (! $commands->get('name', 'join_campaign')) $commands->save(new Command($this->civ13->discord, [
            'name'                       => 'join_campaign',
            'description'                => 'Get a role to join the campaign',
            'dm_permission'              => false,
        ]));

        // if ($command = $commands->get('name', 'ranking')) $commands->delete($command->id);
        if (! $commands->get('name', 'ranking')) $commands->save(new Command($this->civ13->discord, [
            'name'        => 'ranking',
            'description' => 'See the ranks of the top players on the Civ13 server'
        ]));

        // if ($command = $commands->get('name', 'ranking')) $commands->delete($command->id);
        if (! $commands->get('name', 'rankme')) $commands->save(new Command($this->civ13->discord, [
            'name'        => 'rankme',
            'description' => 'See your ranking on the Civ13 server'
        ]));

        /* Deprecated, use the /rankme or chat command instead
        if ($command = $commands->get('name', 'rank')) $commands->delete($command->id);
        if (! $commands->get('name', 'rank')) $commands->save(new Command($this->civ13->discord, [
            'type'          => Command::USER,
            'name'          => 'rank',
            'dm_permission' => false,
        ]));*/

        /* Deprecated, use the chat command instead
        if ($command = $commands->get('name', 'medals')) $commands->delete($command->id);
        if (! $commands->get('name', 'medals')) $commands->save(new Command($this->civ13->discord, [
            'type'          => Command::USER,
            'name'          => 'medals',
            'dm_permission' => false,
        ]));
        */

        /* Deprecated, use the chat command instead
        if ($command = $commands->get('name', 'brmedals')) $commands->delete($command->id);
        if (! $commands->get('name', 'brmedals')) $commands->save(new Command($this->civ13->discord, [
            'type'          => Command::USER,
            'name'          => 'brmedals',
            'dm_permission' => false,
        ]));*/

        $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)->commands->freshen()->done(function (GuildCommandRepository $commands) {
            // if ($command = $commands->get('name', 'unverify')) $commands->delete($command->id);
            if (! $commands->get('name', 'unverify')) $commands->save(new Command($this->civ13->discord, [
                'type'                       => Command::USER,
                'name'                       => 'unverify',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['administrator' => true]),
            ]));
            
            // if ($command = $commands->get('name', 'unban')) $commands->delete($command->id);
            if (! $commands->get('name', 'unban')) $commands->save(new Command($this->civ13->discord, [
                'type'                       => Command::USER,
                'name'                       => 'unban',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
            ]));

            // if ($command = $commands->get('name', 'parole')) $commands->delete($command->id);
            if (! $commands->get('name', 'parole')) $commands->save(new Command($this->civ13->discord, [
                'type'                       => Command::USER,
                'name'                       => 'permit',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
            ]));
            
            /* Deprecated
            if ($command = $commands->get('name', 'permitted')) $commands->delete($command->id);
            if (! $commands->get('name', 'permitted')) $commands->save(new Command($this->civ13->discord, [
                'type'                       => Command::USER,
                'name'                       => 'permitted',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
            ]));*/

            // if ($command = $commands->get('name', 'permit')) $commands->delete($command->id);
            if (! $commands->get('name', 'permit')) $commands->save(new Command($this->civ13->discord, [
                'type'                       => Command::USER,
                'name'                       => 'permit',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
            ]));

            // if ($command = $commands->get('name', 'revoke')) $commands->delete($command->id);
            if (! $commands->get('name', 'revoke')) $commands->save(new Command($this->civ13->discord, [
                'type'                       => Command::USER,
                'name'                       => 'revoke',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['moderate_members' => true]),
            ]));

            // if ($command = $commands->get('name', 'ckeyinfo')) $commands->delete($command->id);
            if (! $commands->get('name', 'ckeyinfo')) $commands->save(new Command($this->civ13->discord, [
                'type'                       => Command::USER,
                'name'                       => 'ckeyinfo',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['view_audit_log' => true]),
            ]));

            // if ($command = $commands->get('name', 'statistics')) $commands->delete($command->id);
            if (! $commands->get('name', 'statistics')) $commands->save(new Command($this->civ13->discord, [
                'type'                       => Command::USER,
                'name'                       => 'statistics',
                'dm_permission'              => false,
                // 'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['view_audit_log' => true]),
            ]));
            
            
            foreach (array_keys($this->civ13->server_settings) as $key) {
                $server = strtolower($key);
                if ($command = $commands->get('name', $server.'_restart')) $commands->delete($command->id);
                /* if (! $commands->get('name', $server.'_restart')) $commands->save(new Command($this->civ13->discord, [
                    'type'                       => Command::CHAT_INPUT,
                    'name'                       => "{$server}_restart",
                    'description'                => "Restart the {$key} server",
                    'dm_permission'              => false,
                    'default_member_permissions' => (string) new RolePermission($this->civ13->discord, ['view_audit_log' => true]),
                ])); */
            }

            // if ($command = $commands->get('name', 'approveme')) $commands->delete($command->id);
            if (! $commands->get('name', 'approveme')) $commands->save(new Command($this->civ13->discord, [
                'name'                       => 'approveme',
                'description'                => 'Verification process',
                'dm_permission'              => false,
                'options'                    => [
                    [
                        'name'        => 'ckey',
                        'description' => 'Byond.com username',
                        'type'        =>  3,
                        'required'    => true,
                    ]
                ]
            ]));
        });

        $this->declareListeners();
    }
    public function declareListeners(): void
    {
        $this->civ13->discord->listenCommand('ping', function (Interaction $interaction): PromiseInterface
        {
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Pong!'));
        });

        $this->civ13->discord->listenCommand('help', function (Interaction $interaction): PromiseInterface
        {
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->civ13->messageHandler->generateHelp($interaction->member->roles)), true);
        });

        $this->civ13->discord->listenCommand('stats', function (Interaction $interaction): PromiseInterface
        {
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Civ13 Stats')->addEmbed($this->civ13->stats->handle()));
        });
        
        $this->civ13->discord->listenCommand('invite', function (Interaction $interaction): PromiseInterface
        {
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->civ13->discord->application->getInviteURLAttribute('8')), true);
        });

        $this->civ13->discord->listenCommand('players', function (Interaction $interaction): PromiseInterface
        {
            if (! $this->civ13->webserver_online) {
                $content = 'Webserver Status: **Offline**, only showing data for locally hosted servers.' . PHP_EOL;
                foreach ($this->civ13->server_settings as $key => $settings) $content .= "$key: {$settings['ip']}:{$settings['port']}" . PHP_EOL;
                $messagebuilder = MessageBuilder::new();
                $messagebuilder->setContent($content);
                $messagebuilder->addEmbed($this->civ13->generateServerstatusEmbed());
                return $interaction->respondWithMessage($messagebuilder);
            }
            
            if (empty($data = $this->civ13->serverinfoParse())) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Unable to fetch serverinfo.json, webserver might be down'), true);
            $embed = new Embed($this->civ13->discord);
            foreach ($data as $server)
                foreach ($server as $key => $array)
                    foreach ($array as $inline => $value)
                        $embed->addFieldValues($key, $value, $inline);
            $embed->setFooter($this->civ13->embed_footer);
            $embed->setColor(0xe1452d);
            $embed->setTimestamp();
            $embed->setURL('');
            $messagebuilder = MessageBuilder::new();
            if ($this->civ13->webserver_online) $content = 'Webserver Status: **Online**' . PHP_EOL;
            else $content = 'Webserver Status: **Offline**, data is stale.' . PHP_EOL;
            foreach (array_keys($this->civ13->server_settings) as $key => $settings) $content .= "$key: {$settings['ip']}:{$settings['port']}";
            $messagebuilder->setContent($content);
            $messagebuilder->addEmbed($embed);
            return $interaction->respondWithMessage($messagebuilder);
        });

        $this->civ13->discord->listenCommand('ckey', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$interaction->data->target_id}` is registered to `{$item['ss13']}`"), true);
        });

        $this->civ13->discord->listenCommand('bancheck', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $response = '';
            $reason = 'unknown';
            $found = false;

            foreach (array_keys($this->civ13->server_settings) as $key) {
                $file_path = strtolower($key) . '_bans';
                if (isset($this->civ13->files[$file_path]) && file_exists($this->civ13->files[$file_path]) && ($file = @fopen($this->civ13->files[$file_path], 'r'))) {
                    while (($fp = fgets($file, 4096)) !== false) {
                        $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                        if ((count($linesplit)>=8) && ($linesplit[8] == strtolower($item['ss13']))) {
                            $found = true;
                            $type = $linesplit[0];
                            $reason = $linesplit[3];
                            $admin = $linesplit[4];
                            $date = $linesplit[5];
                            $response .= "**{$item['ss13']}** has been **$type** banned from **$key** on **$date** for **$reason** by $admin." . PHP_EOL;
                        }
                    }
                    fclose($file);
                }
            }
            if (! $found) $response .= "No bans were found for **{$item['ss13']}**." . PHP_EOL;
            elseif ($member = $this->civ13->getVerifiedMember($item['ss13']))
                if (! $member->roles->has($this->civ13->role_ids['banished']))
                    $member->addRole($this->civ13->role_ids['banished']);
            if (strlen($response)<=2000) return $interaction->respondWithMessage(MessageBuilder::new()->setContent($response), true);
            elseif (strlen($response)<=4096) {
                $embed = new Embed($this->civ13->discord);
                $embed->setDescription($response);
                return $interaction->respondWithMessage(MessageBuilder::new()->addEmbed($embed));
            } else return $interaction->respondWithMessage(MessageBuilder::new()->setContent("The ranking is too long to display. Please use the chat command instead."), true);
        });

        $this->civ13->discord->listenCommand('bancheck_ckey', function (Interaction $interaction): PromiseInterface
        {
            if ($this->civ13->bancheck($interaction->data->options['ckey']->value)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$interaction->data->options['ckey']->value}` is currently banned on one of the Civ13.com servers."), true);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$interaction->data->options['ckey']->value}` is not currently banned on one of the Civ13.com servers."), true);
        });

        $this->civ13->discord->listenCommand('ban', function (Interaction $interaction): PromiseInterface
        {
            $arr = ['ckey' => $interaction->data->options['ckey']->value, 'duration' => $interaction->data->options['duration']->value, 'reason' => $interaction->data->options['reason']->value . " Appeal at {$this->civ13->banappeal}"];
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->civ13->ban($arr, $this->civ13->getVerifiedItem($interaction->user)['ss13'])));
        });
        
        $this->civ13->discord->listenCommand('unverify', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            if ($interaction->user->id !== $this->civ13->technician_id) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("You do not have permission to unverify <@{$interaction->data->target_id}>"), true);
            $admin = $this->civ13->getVerifiedItem($interaction->user->id)['ss13'];
            $this->civ13->unverify($item['ss13'], null);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('**`' . ($admin ?? $interaction->user->displayname) . "`** unverified **`{$item['ss13']}`**."));
        });
        
        $this->civ13->discord->listenCommand('unban', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $admin = $this->civ13->getVerifiedItem($interaction->user->id)['ss13'];
            $this->civ13->unban($item['ss13'], ($admin ?? $interaction->user->displayname));
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('**`' . ($admin ?? $interaction->user->displayname) . "`** unbanned **`{$item['ss13']}`**."));
        });

        $this->civ13->discord->listenCommand('parole', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $this->civ13->paroleCkey($ckey = $item['ss13'], $interaction->user->id, true);
            $admin = $this->civ13->getVerifiedItem($interaction->user->id)['ss13'];
            if ($member = $this->civ13->getVerifiedMember($item))
                if (! $member->roles->has($this->civ13->role_ids['paroled']))
                    $member->addRole($this->civ13->role_ids['paroled'], "`$admin` ({$interaction->user->displayname}) paroled `$ckey`");
            if ($channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $channel->sendMessage("`$ckey` (<@{$item['discord']}>) has been placed on parole by `$admin` (<@{$interaction->user->id}>).");
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`$ckey` (<@{$item['discord']}>) has been placed on parole."), true);
        });

        $this->civ13->discord->listenCommand('release', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->getVerifiedItem($interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $this->civ13->paroleCkey($ckey = $item['ss13'], $interaction->user->id, false);
            $admin = $this->civ13->getVerifiedItem($interaction->user->id)['ss13'];
            if ($member = $this->civ13->getVerifiedMember($item))
                if ($member->roles->has($this->civ13->role_ids['paroled']))
                    $member->removeRole($this->civ13->role_ids['paroled'], "`$admin` ({$interaction->user->displayname}) released `$ckey`");
            if ($channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $channel->sendMessage("`$ckey` (<@{$item['discord']}>) has been released from parole by `$admin` (<@{$interaction->user->id}>).");
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`$ckey` (<@{$item['discord']}>) has been released on parole."), true);
        });

        /* Deprecated
        $this->civ13->discord->listenCommand('permitted', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $response = "**`{$item['ss13']}`** is not currently permitted to bypass Byond account restrictions.";
            if (in_array($item['ss13'], $this->civ13->permitted)) $response = "**`{$item['ss13']}`** is currently permitted to bypass Byond account restrictions.";
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent($response));
        });
        */
        
        $this->civ13->discord->listenCommand('permit', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $this->civ13->permitCkey($item['ss13']);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("**`{$interaction->user->displayname}`** has permitted **`{$item['ss13']}`** to bypass Byond account restrictions."));
        });

        $this->civ13->discord->listenCommand('revoke', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $this->civ13->permitCkey($item['ss13'], false);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("**`{$interaction->user->displayname}`** has removed permission from **`{$item['ss13']}`** to bypass Byond account restrictions."));
        });

        $this->civ13->discord->listenCommand('ckeyinfo', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $ckeyinfo = $this->civ13->ckeyinfo($item['ss13']);
            $embed = new Embed($this->civ13->discord);
            $embed->setTitle($item['ss13']);
            if ($member = $this->civ13->getVerifiedMember($item)) $embed->setAuthor("{$member->user->displayname} ({$member->id})", $member->avatar);
            if (! empty($ckeyinfo['ckeys'])) {
                foreach ($ckeyinfo['ckeys'] as &$ckey) if (isset($this->civ13->ages[$ckey])) $ckey = "$ckey ({$this->civ13->ages[$ckey]})";
                $embed->addFieldValues('Ckeys', implode(', ', $ckeyinfo['ckeys']));
            }
            if (! empty($ckeyinfo['ips'])) $embed->addFieldValues('IPs', implode(', ', $ckeyinfo['ips']));
            if (! empty($ckeyinfo['cids'])) $embed->addFieldValues('CIDs', implode(', ', $ckeyinfo['cids']));
            if (! empty($ckeyinfo['ips'])) {
                $regions = [];
                foreach ($ckeyinfo['ips'] as $ip) if (! in_array($region = $this->civ13->IP2Country($ip), $regions)) $regions[] = $region;
                $embed->addFieldValues('Regions', implode(', ', $regions));
            }
            $embed->addfieldValues('Verified', $ckeyinfo['verified'] ? 'Yes' : 'No');
            if ($ckeyinfo['discords']) {
                foreach ($ckeyinfo['discords'] as &$id) $id = "<@{$id}>";
                $embed->addfieldValues('Discord', implode(', ', $ckeyinfo['discords']));
            }
            $embed->addfieldValues('Currently Banned', $ckeyinfo['banned'] ? 'Yes' : 'No');
            $embed->addfieldValues('Alt Banned', $ckeyinfo['altbanned'] ? 'Yes' : 'No');
            $embed->addfieldValues('Ignoring banned alts or new account age', isset($this->civ13->permitted[$item['ss13']]) ? 'Yes' : 'No');
            return $interaction->respondWithMessage(MessageBuilder::new()->setEmbeds([$embed]), true);
        });

        $this->civ13->discord->listenCommand('statistics', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verified->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            
            $game_ids = [];
            $servers = [];
            $ips = [];
            $regions = [];
            // $cids = [];
            $players = [];
            $embed = new Embed($this->civ13->discord);
            $embed->setTitle($item['ss13']);
            if ($member = $this->civ13->getVerifiedMember($item)) $embed->setAuthor("{$member->user->displayname} ({$member->id})", $member->avatar);
            foreach ($this->civ13->getRoundsCollections() as $server => $arr) foreach ($arr as $collection) {
                if (! in_array($server, $servers)) $servers[] = $server;
                foreach ($collection as $round) {
                    $game_id = $round['game_id'] ?? null;
                    $p = $round['players'] ?? [];
                    $s = $round['start'] ?? null;
                    $e = $round['end'] ?? null;
                    if (isset($p[$item['ss13']])) {
                        if ($game_id && ! in_array($game_id, $game_ids)) $game_ids[] = $game_id;
                        if (isset($p[$item['ss13']]['ip']) && $p[$item['ss13']]['ip']) foreach ($p[$item['ss13']]['ip'] as $ip) if (! in_array($ip, $ips)) $ips[] = $ip;
                        $start_time = $p[$item['ss13']]['login']; // Formatted as [H:i:s]
                        $end_time = isset($p[$item['ss13']]['logout']) ? $p[$item['ss13']]['logout'] : NULL;
                        // if (isset($p[$item['ss13']]['cid']) && $p[$item['ss13']]['cid']) foreach ($p[$item['ss13']]['cid'] as $cid) if (! in_array($cid, $cids)) $cids[] = $cid;

                        // Get players played with
                        foreach (array_keys($p) as $ckey) {
                            if ($ckey === $item['ss13']) continue 1;
                            $s_t = $p[$ckey]['login'];
                            $e_t = isset($p[$ckey]['logout']) ? $p[$ckey]['logout'] : NULL;
                            // TODO: Only add if the player was online at the same time
                            $p[] = $ckey;
                        }
                    }
                }
            }
            
            if (isset($this->civ13->ages[$item['ss13']])) $embed->addFieldValues('Created', $this->civ13->ages[$item['ss13']], true);
            foreach ($ips as $ip) if (! in_array($region = $this->civ13->IP2Country($ip), $regions)) $regions[] = $region;
            if (! empty($regions)) $embed->addFieldValues('Region Codes', implode(', ', $regions), true);
            // $embed->addFieldValues('Known IP addresses', count($ips));
            // $embed->addFieldValues('Known Computer IDs', count($cids));
            $embed->addFieldValues('Games Played', count($game_ids), true);
            $embed->addFieldValues('Unique Players Played With', count($players), true);

            $embed->setFooter($this->civ13->embed_footer);
            $embed->setColor(0xe1452d);
            $embed->setTimestamp();
            $embed->setURL('');

            $messagebuilder = MessageBuilder::new();
            $messagebuilder->setContent("Statistics for `{$item['ss13']}` starting from <t:1688464620:D>");
            $messagebuilder->addEmbed($embed);
            return $interaction->respondWithMessage($messagebuilder, true);
        });
        
        $this->civ13->discord->listenCommand('panic', function (Interaction $interaction): PromiseInterface
        {
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Panic bunker is now ' . (($this->civ13->panic_bunker = ! $this->civ13->panic_bunker) ? 'enabled.' : 'disabled.')));
        });

        $this->civ13->discord->listenCommand('join_campaign', function (Interaction $interaction): PromiseInterface
        {
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Factions are not ready to be assigned yet'), true);
            //Disabled for now
            if (! $this->civ13->getVerifiedItem($interaction->member->id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("You are either not currently verified with a byond username or do not exist in the cache yet"), true);
            
            foreach ($interaction->member->roles as $role) if ($role->id === $this->civ13->role_ids['red'] || $role->id === $this->civ13->role_ids['blue']) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("You're already in a faction!"), true);

            $redCount = $interaction->guild->members->filter(fn($member) => $member->roles->has($this->civ13->role_ids['red']))->count();
            $blueCount = $interaction->guild->members->filter(fn($member) => $member->roles->has($this->civ13->role_ids['blue']))->count();
            $roleIds = [$this->civ13->role_ids['red'], $this->civ13->role_ids['blue']];
            $interaction->member->addRole($redCount > $blueCount ? $this->civ13->role_ids['blue'] : ($blueCount > $redCount ? $this->civ13->role_ids['red'] : $roleIds[array_rand($roleIds)]));
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('A faction has been assigned'), true);
        });

        $this->civ13->discord->listenCommand('approveme', function (Interaction $interaction): PromiseInterface
        {
            if ($interaction->member->roles->has($this->civ13->role_ids['infantry']) || $interaction->member->roles->has($this->civ13->role_ids['veteran'])) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('You already have the verification role!'), true);
            if (isset($this->civ13->softbanned[$interaction->member->id]) || isset($this->civ13->softbanned[$this->civ13->sanitizeInput($interaction->data->options['ckey']->value)])) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('This account is currently under investigation.'));
            if (! $item = $this->civ13->verified->get('discord', $interaction->member->id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->civ13->verifyProcess($interaction->data->options['ckey']->value, $interaction->member->id)), true);
            $interaction->member->setRoles([$this->civ13->role_ids['infantry']], "approveme {$item['ss13']}");
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Welcome to the Server! Your roles have been set and you should now have access to the rest of the server.'), true);
        });
    }
}