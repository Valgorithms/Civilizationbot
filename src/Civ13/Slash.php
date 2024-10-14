<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Byond\Byond;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use React\Promise\PromiseInterface;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\User\Member;
use Discord\Parts\Permissions\RolePermission;
use Discord\Repository\Guild\GuildCommandRepository;
use Discord\Repository\Interaction\GlobalCommandRepository;
use Monolog\Logger;

class Slash
{
    public Civ13 $civ13;
    public Discord $discord;
    public Logger $logger;
    private bool $setup = false;

    public function __construct(Civ13 &$civ13) {
        $this->civ13 =& $civ13;
        $this->discord =& $civ13->discord;
        $this->logger =& $civ13->logger;
        $this->afterConstruct();
    }
    /*
    * This function is called after the constructor is finished.
    * It is used to load the files, start the timers, and start handling events.
    */
    private function afterConstruct(): void
    {
        $this->__declareListeners();
        $this->discord->once('init', function() {
            $this->logger->info('Setting up Interaction commands...');
            $this->setup();
            if ($application_commands = $this->discord->__get('application_commands')) {
                $names = [];
                foreach ($application_commands as $command) $names[] = $command->getName();
                $this->logger->debug('[APPLICATION COMMAND LIST] ' . PHP_EOL . '`' . implode('`, `', $names) . '`');
            }
        });
    }
    /**
     * Sets up the bot by updating commands, guild commands, and declaring listeners.
     * This method should be called in the scope of $this->discord->once('init', fn() => $this->setup());
     */
    private function setup(): void
    {
        if ($this->setup) return;
        $this->__updateCommands();
        $this->__updateGuildCommands();
        $this->setup = true;
    }
    /**
     * Saves a command to the specified repository.
     *
     * @param GlobalCommandRepository|GuildCommandRepository $commands The repository to save the command to.
     * @param Command $command The command to save.
     * @return PromiseInterface A promise that resolves when the command is saved.
     */
    private function save(GlobalCommandRepository|GuildCommandRepository $commands, Command $command): PromiseInterface
    {
        return $this->civ13->then($commands->save($command));
    }

    private function __updateCommands(): void
    {
        $this->discord->application->commands->freshen()->then(function (GlobalCommandRepository $commands): void
        {
            $names = [];
            foreach ($commands as $command) if ($command->name) $names[] = $command->name;
            if ($names) $this->logger->debug('[GLOBAL APPLICATION COMMAND LIST]' . PHP_EOL .  '`' . implode('`, `', $names) . '`');

            // if ($command = $commands->get('name', 'ping')) $commands->delete($command);
            if (! $commands->get('name', 'ping')) $this->save($commands, new Command($this->discord, [
                'name'        => 'ping',
                'description' => 'Replies with Pong!',
            ]));

            // if ($command = $commands->get('name', 'help')) $commands->delete($command);
            if (! $commands->get('name', 'help')) $this->save($commands, new Command($this->discord, [
                'name'          => 'help',
                'description'   => 'View a list of available commands',
                'dm_permission' => false,
            ]));

            if ($command = $commands->get('name', 'pull')) $commands->delete($command);
            /*if (! $commands->get('name', 'pull')) $this->save($commands, new Command($this->discord, [
                    'name'                       => 'pull',
                    'description'                => "Update the bot's code",
                    'dm_permission'              => false,
                    'default_member_permissions' => (string) new RolePermission($this->discord, ['view_audit_log' => true]),
            ]));*/

            if ($command = $commands->get('name', 'update')) $commands->delete($command);
            /*if (! $commands->get('name', 'update')) $this->save($commands, new Command($this->discord, [
                    'name'                       => 'update',
                    'description'                => "Update the bot's dependencies",
                    'dm_permission'              => false,
                    'default_member_permissions' => (string) new RolePermission($this->discord, ['view_audit_log' => true]),
            ]));*/

            if ($command = $commands->get('name', 'stats')) $commands->delete($command);
            /*if (! $commands->get('name', 'stats')) $this->save($commands, new Command($this->discord, [
                'name'                       => 'stats',
                'description'                => 'Get runtime information about the bot',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
            ]));*/

            if ($command = $commands->get('name', 'invite')) $commands->delete($command);
            /*if (! $commands->get('name', 'invite')) $this->save($commands, new Command($this->discord, [
                    'name'                       => 'invite',
                    'description'                => 'Bot invite link',
                    'dm_permission'              => false,
                    'default_member_permissions' => (string) new RolePermission($this->discord, ['manage_guild' => true]),
            ]));*/

            // if ($command = $commands->get('name', 'players')) $commands->delete($command);
            if (! $commands->get('name', 'players')) $this->save($commands, new Command($this->discord, [
                'name'        => 'players',
                'description' => 'Show Space Station 13 server information'
            ]));

            // if ($command = $commands->get('name', 'ckey')) $commands->delete($command);
            if (! $commands->get('name', 'ckey')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'ckey',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
            ]));

            // if ($command = $commands->get('name', 'ckeyinfo')) $commands->delete($command);
            if (! $commands->get('name', 'ckeyinfo')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'ckeyinfo',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['view_audit_log' => true]),
            ]));

            // if ($command = $commands->get('name', 'bancheck')) $commands->delete($command);
            if (! $commands->get('name', 'bancheck')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'bancheck',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
            ]));

            // if ($command = $commands->get('name', 'bancheck_ckey')) $commands->delete($command);
            if (! $commands->get('name', 'bancheck_ckey')) $this->save($commands, new Command($this->discord, [
                'name'                       => 'bancheck_ckey',
                'description'                => 'Check if a ckey is banned on the server',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
                'options'                    => [
                    [
                        'name'        => 'ckey',
                        'description' => 'Byond.com username',
                        'type'        => Option::STRING,
                        'required'    => true,
                    ]
                ]
            ]));

            // if ($command = $commands->get('name', 'bansearch')) $commands->delete($command);
            if (! $commands->get('name', 'bansearch_centcom')) $this->save($commands, new Command($this->discord, [
                'name'                       => 'bansearch_centcom',
                'description'                => 'Check if a ckey is banned on centcom.melonmesa.com',
                'dm_permission'              => false,
                'options'                    => [
                    [
                        'name'        => 'ckey',
                        'description' => 'Byond.com username',
                        'type'        => Option::STRING,
                        'required'    => true,
                    ]
                ]
            ]));

            // if ($command = $commands->get('name', 'unverify')) $commands->delete($command);
            if (! $commands->get('name', 'unverify')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'unverify',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['administrator' => true]),
            ]));

            // if ($command = $commands->get('name', 'ban')) $commands->delete($command);
            if (! $commands->get('name', 'ban')) $this->save($commands, new Command($this->discord, [
                'name'			=> 'ban',
                'description'	=> 'Ban a ckey from the Civ13.com servers',
                'dm_permission' => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
                'options'		=> [
                    [
                        'name'			=> 'ckey',
                        'description'	=> 'The byond username being banned',
                        'type'			=> Option::STRING,
                        'required'		=> true,
                    ],
                    [
                        'name'			=> 'duration',
                        'description'	=> 'How long to ban the user for (e.g. 999 years)',
                        'type'			=> Option::STRING,
                        'required'		=> true,
                    ],
                    [
                        'name'			=> 'reason',
                        'description'	=> 'Why the user is being banned',
                        'type'			=> Option::STRING,
                        'required'		=> true,
                    ],
                ]
            ]));

            if ($command = $commands->get('name', 'panic_bunker')) $commands->delete($command);
            /*if (! $commands->get('name', 'panic_bunker')) $this->save($commands, new Command($this->discord, [
                'name'                       => 'panic_bunker',
                'description'                => 'Toggles the panic bunker',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['manage_guild' => true]),
            ]));*/

            // if ($command = $commands->get('name', 'join_campaign')) $commands->delete($command);
            if (! $commands->get('name', 'join_campaign')) $this->save($commands, new Command($this->discord, [
                'name'                       => 'join_campaign',
                'description'                => 'Get a role to join the campaign',
                'dm_permission'              => false,
            ]));

            // if ($command = $commands->get('name', 'assign_faction')) $commands->delete($command);
            $choices = array_map(function($faction) {
                return ['name' => $faction, 'value' => $faction];
            }, Civ13::faction_teams);
            $choices[] = ['name' => 'Random', 'value' => 'random'];
            $choices[] = ['name' => 'None', 'value' => 'none'];
            if (! $commands->get('name', 'assign_faction')) $this->save($commands, new Command($this->discord, [
                'name'                       => 'assign_faction',
                'description'                => 'Assign someone to a faction',
                'dm_permission'              => false,
                'options'		             => [
                    [
                        'name'			=> 'ckey',
                        'description'	=> 'Byond username (or Discord ID)',
                        'type'			=> Option::STRING,
                        'required'		=> true,
                    ],
                    [
                        'name'			=> 'team',
                        'description'	=> 'Team to assign the user to',
                        'type'			=> Option::STRING,
                        'required'		=> true,
                        'choices'       => $choices
                    ]
                ]
            ]));

            /* Deprecated, use the /rankme or chat command instead
            if ($command = $commands->get('name', 'rank')) $commands->delete($command);
            if (! $commands->get('name', 'rank')) $this->save($commands, new Command($this->discord, [
                'type'          => Command::USER,
                'name'          => 'rank',
                'dm_permission' => false,
            ]));*/

            /* Deprecated, use the chat command instead
            if ($command = $commands->get('name', 'medals')) $commands->delete($command);
            if (! $commands->get('name', 'medals')) $this->save($commands, new Command($this->discord, [
                'type'          => Command::USER,
                'name'          => 'medals',
                'dm_permission' => false,
            ]));
            */

            /* Deprecated, use the chat command instead
            if ($command = $commands->get('name', 'brmedals')) $commands->delete($command);
            if (! $commands->get('name', 'brmedals')) $this->save($commands, new Command($this->discord, [
                'type'          => Command::USER,
                'name'          => 'brmedals',
                'dm_permission' => false,
            ]));*/

            if (! empty($this->civ13->functions['ready_slash'])) foreach (array_values($this->civ13->functions['ready_slash']) as $func) $func($this, $commands); // Will be deprecated in the future
            //else $this->logger->debug('No ready slash functions found!');
        });
    }
    private function __updateGuildCommands(): void
    {
        $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)->commands->freshen()->then(function (GuildCommandRepository $commands): void
        {
            $names = [];
            foreach ($commands as $command) if ($command->name) $names[] = $command->name;
            if ($names) $this->logger->debug('[GUILD APPLICATION COMMAND LIST]' . PHP_EOL .  '`' . implode('`, `', $names) . '`');
            
            // if ($command = $commands->get('name', 'unban')) $commands->delete($command);
            if (! $commands->get('name', 'unban')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'unban',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
            ]));

            // if ($command = $commands->get('name', 'parole')) $commands->delete($command);
            if (! $commands->get('name', 'parole')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'parole',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
            ]));
            
            /* Deprecated
            if ($command = $commands->get('name', 'permitted')) $commands->delete($command);
            if (! $commands->get('name', 'permitted')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'permitted',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
            ]));*/

            // if ($command = $commands->get('name', 'permit')) $commands->delete($command);
            if (! $commands->get('name', 'permit')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'permit',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
            ]));

            // if ($command = $commands->get('name', 'revoke')) $commands->delete($command);
            if (! $commands->get('name', 'revoke')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'revoke',
                'dm_permission'              => false,
                'default_member_permissions' => (string) new RolePermission($this->discord, ['moderate_members' => true]),
            ]));

            if ($command = $commands->get('name', 'statistics')) $commands->delete($command);
            /*if (! $commands->get('name', 'statistics')) $this->save($commands, new Command($this->discord, [
                'type'                       => Command::USER,
                'name'                       => 'statistics',
                'dm_permission'              => false,
                // 'default_member_permissions' => (string) new RolePermission($this->discord, ['view_audit_log' => true]),
            ]));*/
            
            $server_choices = [];
            foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                $server_choices[] = [
                    'name' => $gameserver->name,
                    'value' => $gameserver->key
                ];
            };
            if ($server_choices) { // Only add the ranking commands if there are servers to choose from
                // if ($command = $commands->get('name', 'rank')) $commands->delete($command);
                if (! $commands->get('name', 'rank')) $this->save($commands, new Command($this->discord, [
                    'name'                => 'rank',
                    'description'         => 'See your ranking on a Civ13 server',
                    'dm_permission'       => false,
                    'options'             => [
                        [
                            'name'        => 'server',
                            'description' => 'Which server to look up rankings for',
                            'type'        => Option::STRING,
                            'required'    => true,
                            'choices'     => $server_choices
                        ],
                        [
                            'name'        => 'ckey',
                            'description' => 'Byond.com username',
                            'type'        => Option::STRING,
                            'required'    => false
                        ]
                    ]
                ]));

                // if ($command = $commands->get('name', 'ranking')) $commands->delete($command);
                if (! $commands->get('name', 'ranking')) $this->save($commands, new Command($this->discord, [
                    'name'                => 'ranking',
                    'description'         => 'See the ranks of the top players on a Civ13 server',
                    'dm_permission'       => false,
                    'options'             => [
                        [
                            'name'        => 'server',
                            'description' => 'Which server to look up rankings for',
                            'type'        => Option::STRING,
                            'required'    => true,
                            'choices'     => $server_choices
                        ]
                    ]
                ]));

                if (! $commands->get('name', 'restart_server')) $this->save($commands, new Command($this->discord, [
                    'type'                       => Command::CHAT_INPUT,
                    'name'                       => "restart_server",
                    'description'                => "Restart a Civ13 server",
                    'dm_permission'              => false,
                    'default_member_permissions' => (string) new RolePermission($this->discord, ['view_audit_log' => true]),
                    'options'             => [
                        [
                            'name'        => 'server',
                            'description' => 'Which server to restart',
                            'type'        => Option::STRING,
                            'required'    => true,
                            'choices'     => $server_choices
                        ]
                    ]
                ]));
            } else { // Remove the ranking commands if there are no servers to choose from
                //if ($command = $commands->get('name', 'rank')) $commands->delete($command);
                //if ($command = $commands->get('name', 'ranking')) $commands->delete($command);
                //if ($command = $commands->get('name', 'restart_server')) $commands->delete($command);
            }
            
            
            // if ($command = $commands->get('name', 'approveme')) $commands->delete($command);
            if (! $commands->get('name', 'approveme')) $this->save($commands, new Command($this->discord, [
                'name'                       => 'approveme',
                'description'                => 'Verification process',
                'dm_permission'              => false,
                'options'                    => [
                    [
                        'name'        => 'ckey',
                        'description' => 'Byond.com username',
                        'type'        => Option::STRING,
                        'required'    => true,
                    ]
                ]
            ]));
        });
    }
    private function __declareListeners(): void
    {
        $this->discord->listenCommand('pull', function (Interaction $interaction): PromiseInterface
        {
            $this->logger->info('[GIT PULL]');
            execInBackground('git pull');
            $this->civ13->loop->addTimer(5, function () {
                if ($channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, 'Forcefully moving the HEAD back to origin/main... (2/3)');
                execInBackground('git reset --hard origin/main');
            });
            $this->civ13->loop->addTimer(10, function () {
                if ($channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, 'Updating code from GitHub... (3/3)');
                execInBackground('git pull');
            });
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating code from GitHub...'));
        });
        
        $this->discord->listenCommand('update', function (Interaction $interaction): PromiseInterface
        {
            $this->logger->info('[COMPOSER UPDATE]');
            \execInBackground('composer update');
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Updating dependencies...'));
        });
        $this->discord->listenCommand('restart_server', function (Interaction $interaction): PromiseInterface
        {
            if (! isset($interaction->data->options['server'])) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("No server specified"));
            if (! isset($this->civ13->enabled_gameservers[$interaction->data->options['server']->value])) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("No gamserver found for `{$interaction->data->options['server']->value}`"));
            $gameserver = &$this->civ13->enabled_gameservers[$interaction->data->options['server']->value];
            $gameserver->Restart();
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("Attempted to kill, update, and bring up `{$gameserver->name}` <byond://{$gameserver->ip}:{$gameserver->port}>"));
        });
        
        $this->discord->listenCommand('ping', fn(Interaction $interaction): PromiseInterface =>
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Pong!'))
        );

        $this->discord->listenCommand('help', fn(Interaction $interaction): PromiseInterface =>
            $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->civ13->messageServiceManager->generateHelp($interaction->member->roles)), true)
        );

        $this->discord->listenCommand('stats', fn(Interaction $interaction): PromiseInterface =>
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Civ13 Stats')->addEmbed($this->civ13->stats->handle()->setFooter($this->civ13->embed_footer)))
        );
        
        $this->discord->listenCommand('invite', fn(Interaction $interaction): PromiseInterface =>
            $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->discord->application->getInviteURLAttribute('8')), true)
        );

        $this->discord->listenCommand('players', function (Interaction $interaction): PromiseInterface
        {
            $builder = MessageBuilder::new();
            $content = '';
            foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                $content .= "{$gameserver->name}: {$gameserver->ip}:{$gameserver->port}" . PHP_EOL;
                if ($embed = $gameserver->generateServerstatusEmbed()) $builder->addEmbed($embed);
            }
            return $interaction->respondWithMessage($builder->setContent($content));
        });

        $this->discord->listenCommand('ckey', fn(Interaction $interaction): PromiseInterface =>
            ($item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) 
                ? $interaction->respondWithMessage(MessageBuilder::new()->setContent("`{$interaction->data->target_id}` is registered to `{$item['ss13']}`"), true)
                : $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true)    
        );

        $this->discord->listenCommand('bancheck', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            return $interaction->acknowledge()->then(function () use ($interaction, $item) { // wait until the bot says "Is thinking..."
                $response = '';
                $reason = 'unknown';
                $found = false;
                foreach ($this->civ13->enabled_gameservers as &$gameserver) {
                    if (file_exists($gameserver->basedir . $this->civ13::bans) && ($file = @fopen($gameserver->basedir . $this->civ13::bans, 'r'))) {
                        while (($fp = fgets($file, 4096)) !== false) {
                            $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                            if ((count($linesplit)>=8) && ($linesplit[8] == strtolower($item['ss13']))) {
                                $found = true;
                                $type = $linesplit[0];
                                $reason = $linesplit[3];
                                $admin = $linesplit[4];
                                $date = $linesplit[5];
                                $response .= "**{$item['ss13']}** has been **$type** banned from **{$gameserver->name}** on **$date** for **$reason** by $admin." . PHP_EOL;
                            }
                        }
                        fclose($file);
                    }
                }
                if (! $found) $response .= "No bans were found for **{$item['ss13']}**." . PHP_EOL;
                elseif ($member = $this->civ13->verifier->getVerifiedMember($item['ss13']))
                    if (! $member->roles->has($this->civ13->role_ids['Banished']))
                        $member->addRole($this->civ13->role_ids['Banished']);
                if (strlen($response)<=2000) return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent($response), true);
                if (strlen($response)<=4096) return $interaction->sendFollowUpMessage(MessageBuilder::new()->addEmbed($this->civ13->createEmbed()->setDescription($response)));
                return $interaction->respondWithMessage(MessageBuilder::new()->addFileFromContent($item['ss13'].'_bans.json', $response), true);
            });
        });

        $this->discord->listenCommand('bancheck_ckey', function (Interaction $interaction): PromiseInterface
        {
            if (! isset($interaction->data->options['ckey']) || ! $ckey = Civ13::sanitizeInput($interaction->data->options['ckey']->value)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("No ckey specified"), true);
            if ($this->civ13->bancheck($ckey)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`$ckey` is currently banned on one of the Civ13.com servers."), true);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`$ckey` is not currently banned on one of the Civ13.com servers."), true);
        });

        $this->discord->listenCommand('bansearch_centcom', function (Interaction $interaction): PromiseInterface
        {
            if (! isset($interaction->data->options['ckey']) || ! $ckey = Civ13::sanitizeInput($interaction->data->options['ckey']->value)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("No ckey specified"), true);
            if (! $json = Byond::bansearch_centcom($ckey)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("Unable to locate bans for **$ckey** on centcom.melonmesa.com."), true);
            if ($json === '[]') return $interaction->respondWithMessage(MessageBuilder::new()->setContent("No bans were found for **$ckey** on centcom.melonmesa.com."), true);
            return $interaction->respondWithMessage(MessageBuilder::new()->addFileFromContent($ckey.'_bans.json', $json), true);
        });

        $this->discord->listenCommand('ban', function (Interaction $interaction): PromiseInterface
        {
            if (! isset($interaction->data->options['ckey'], $interaction->data->options['duration'], $interaction->data->options['reason'])) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("Missing required parameters"), true);
            $arr = ['ckey' => Civ13::sanitizeInput($interaction->data->options['ckey']->value), 'duration' => $interaction->data->options['duration']->value, 'reason' => $interaction->data->options['reason']->value . " Appeal at {$this->civ13->discord_formatted}"];
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->civ13->ban($arr, $this->civ13->verifier->getVerifiedItem($interaction->user)['ss13'])));
        });
        
        $this->discord->listenCommand('unverify', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            return $interaction->acknowledge()->then(function () use ($interaction, $item) { // wait until the bot says "Is thinking..."
                if ($interaction->user->id !== $this->civ13->technician_id) return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent("You do not have permission to unverify <@{$interaction->data->target_id}>"), true);
                return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent("Unverifying `{$item['ss13']}`..."))->then(function ($message) use ($interaction, $item) {
                    $response = $this->civ13->verifier->unverify($item['ss13']);
                    $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("Processed request to unverify `{$item['ss13']}`."));
                    if (! $response['success']) return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent($response['message']), true);
                    return $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent($response['message']), true);
                });
            });
        });
        
        $this->discord->listenCommand('unban', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $this->civ13->unban($item['ss13'], $admin = $this->civ13->verifier->getVerifiedItem($interaction->user->id)['ss13'] ?? $interaction->user->username);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("**`$admin`** unbanned **`{$item['ss13']}`**."));
        });

        $this->discord->listenCommand('parole', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $this->civ13->paroleCkey($ckey = $item['ss13'], $interaction->user->id, true);
            $admin = $this->civ13->verifier->getVerifiedItem($interaction->user->id)['ss13'];
            if ($member = $this->civ13->verifier->getVerifiedMember($item))
                if (! $member->roles->has($this->civ13->role_ids['Paroled']))
                    $member->addRole($this->civ13->role_ids['Paroled'], "`$admin` ({$interaction->user->username}) paroled `$ckey`");
            if ($channel = $this->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $channel->sendMessage("`$ckey` (<@{$item['discord']}>) has been placed on parole by `$admin` (<@{$interaction->user->id}>).");
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`$ckey` (<@{$item['discord']}>) has been placed on parole."), true);
        });

        $this->discord->listenCommand('release', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verifier->getVerifiedItem($interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $this->civ13->paroleCkey($ckey = $item['ss13'], $interaction->user->id, false);
            $admin = $this->civ13->verifier->getVerifiedItem($interaction->user->id)['ss13'];
            if ($member = $this->civ13->verifier->getVerifiedMember($item))
                if ($member->roles->has($this->civ13->role_ids['Paroled']))
                    $member->removeRole($this->civ13->role_ids['Paroled'], "`$admin` ({$interaction->user->username}) released `$ckey`");
            if ($channel = $this->discord->getChannel($this->civ13->channel_ids['parole_logs'])) $channel->sendMessage("`$ckey` (<@{$item['discord']}>) has been released from parole by `$admin` (<@{$interaction->user->id}>).");
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("`$ckey` (<@{$item['discord']}>) has been released on parole."), true);
        });

        /* Deprecated
        $this->discord->listenCommand('permitted', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $response = "**`{$item['ss13']}`** is not currently permitted to bypass Byond account restrictions.";
            if (in_array($item['ss13'], $this->civ13->permitted)) $response = "**`{$item['ss13']}`** is currently permitted to bypass Byond account restrictions.";
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent($response));
        });
        */
        
        $this->discord->listenCommand('permit', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $this->civ13->permitCkey($item['ss13']);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("**`{$interaction->user->username}`** has permitted **`{$item['ss13']}`** to bypass Byond account restrictions."));
        });

        $this->discord->listenCommand('revoke', function (Interaction $interaction): PromiseInterface
        {
            if (! $item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            $this->civ13->permitCkey($item['ss13'], false);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("**`{$interaction->user->username}`** has removed permission from **`{$item['ss13']}`** to bypass Byond account restrictions."));
        });

        $this->discord->listenCommand('ckeyinfo', fn(Interaction $interaction): PromiseInterface =>
            ($item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) 
            ? $interaction->acknowledge()->then(fn(): PromiseInterface  => // wait until the bot says "Is thinking..."
                $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent("Generating ckeyinfo for `{$item['ss13']}`..."), true)->then(fn(Message $message): PromiseInterface =>
                    $interaction->sendFollowUpMessage(MessageBuilder::new()->addEmbed($this->civ13->ckeyinfoEmbed($item['ss13'])), true)->then(fn(Message $message): PromiseInterface =>
                        $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("Generated ckeyinfo for `{$item['ss13']}`."))
                    )
                )
            )
            : $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true)
        );

        $this->discord->listenCommand('statistics', function (Interaction $interaction): PromiseInterface
        { // TODO: Review this and make it actually useful
            if (! $item = $this->civ13->verifier->get('discord', $interaction->data->target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->data->target_id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Currently disabled'), true);
            
            $game_ids = [];
            $ips = [];
            $regions = [];
            // $cids = [];
            $players = [];
            $embed = $this->civ13->createEmbed()->setTitle($item['ss13']);
            if ($user = $this->civ13->verifier->getVerifiedUser($item)) $embed->setAuthor("{$user->username} ({$user->id})", $user->avatar);
            foreach ($this->civ13->enabled_gameservers as &$server) {
                $collection = $server->getRoundsCollection();
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
            foreach ($ips as $ip) if (! in_array($region = $this->civ13->getIpData($ip)['region'], $regions)) $regions[] = $region;
            if (! empty($regions)) $embed->addFieldValues('Region Codes', implode(', ', $regions), true);
            // $embed->addFieldValues('Known IP addresses', count($ips));
            // $embed->addFieldValues('Known Computer IDs', count($cids));
            $embed
                ->addFieldValues('Games Played', count($game_ids), true)
                ->addFieldValues('Unique Players Played With', count($players), true);

            $messagebuilder = MessageBuilder::new();
            $messagebuilder
                ->setContent("Statistics for `{$item['ss13']}` starting from <t:1688464620:D>")
                ->addEmbed($embed);
            return $interaction->respondWithMessage($messagebuilder, true);
        });
        
        $this->discord->listenCommand('panic_bunker', fn(Interaction $interaction): PromiseInterface =>
            $interaction->respondWithMessage(MessageBuilder::new()->setContent('Panic bunker is now ' . (($this->civ13->panic_bunker = ! $this->civ13->panic_bunker) ? 'enabled.' : 'disabled.')))
        );

        $this->discord->listenCommand('join_campaign', function (Interaction $interaction): PromiseInterface
        {
            //return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Factions are not ready to be assigned yet'), true);
            if (! $this->civ13->verifier->getVerifiedItem($interaction->member->id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('You are either not currently verified with a byond username or do not exist in the cache yet'), true);
            foreach ($interaction->member->roles as $role) if (in_array($role->id, $this->civ13->faction_ids)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('You are already in a faction!'), true);
            $roleCounts = [];
            foreach ($this->civ13->faction_ids as $role_id) $roleCounts[$role_id] = $interaction->guild->members->filter(fn($member) => $member->roles->has($role_id))->count();
            if (! $roleCounts) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('No factions are currently available'), true);
            $selectedRoles = array_keys($roleCounts, min($roleCounts)); // Get the role(s) with the lowest member count
            $interaction->member->addRole($selectedRole = $selectedRoles[array_rand($selectedRoles)]);
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("You've been assigned to <@&$selectedRole>")->setAllowedMentions(['parse'=>[]]), true);
        });

        $this->discord->listenCommand('assign_faction', function (Interaction $interaction): PromiseInterface
        {
            if (! $interaction->member->roles->has($this->civ13->role_ids['Faction Organizer'])) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('You do not have permission to assign factions!'), true);
            if (! isset($interaction->data->options['team']) || ! $target_team = $interaction->data->options['team']->value) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Invalid team.'), true);
            if (! isset($interaction->data->options['ckey']) || ! $target_id = Civ13::sanitizeInput($interaction->data->options['ckey']->value)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Invalid ckey or Discord ID.'), true);
            if (! $target_member = $this->civ13->verifier->getVerifiedMember($target_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('The member is either not currently verified with a byond username or do not exist in the cache yet'), true);
            if ($target_team === 'random') $target_team = array_rand(Civ13::faction_teams); 
            if ($target_team === 'none') {
                $this->civ13->removeRoles($target_member, $this->civ13->faction_ids, true); // Multiple roles COULD be removed so we should PATCH
                return $interaction->respondWithMessage(MessageBuilder::new()->setContent("The faction roles have been removed from <@{$target_member->id}>"), true);
            }
            if (! in_array($target_team, Civ13::faction_teams) || ! isset($this->civ13->role_ids[$target_team]) || ! $role_id = $this->civ13->role_ids[$target_team] ?? null) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("Invalid or unconfigured team: `$target_team`."), true);
            if ($target_member->roles->has($role_id)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('The member is already in this faction!'), true);
            $this->civ13->removeRoles($target_member, $this->civ13->faction_ids, true)->then(fn(Member $member) => $this->civ13->addRoles($member, $role_id)); // Only one role is being added so we don't need to PATCH
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("The <@&$role_id> role has been assigned to <@{$target_member->id}>")->setAllowedMentions(['parse'=>['users']]), true);
        });

        $this->discord->listenCommand('rank', function (Interaction $interaction): PromiseInterface
        {
            if (! isset($interaction->data->options['ckey']) || ! $ckey = $interaction->data->options['ckey']->value ?? $this->civ13->verifier->get('discord', $interaction->member->id)['ss13'] ?? null) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("<@{$interaction->member->id}> is not currently verified with a byond username or it does not exist in the cache yet"), true);
            if (is_numeric($ckey = Civ13::sanitizeInput($ckey)) && ! $ckey = $this->civ13->verifier->get('discord', $ckey)['ss13']) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("The Byond username or Discord ID `{$interaction->data->options['ckey']->value}` is not currently verified with a Byond username or it does not exist in the cache yet"), true);
            return $interaction->acknowledge()->then(fn(): PromiseInterface => // wait until the bot says "Is thinking..."
                $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent("Generating rank for `$ckey`..."), true)->then(fn($message): PromiseInterface =>
                    $interaction->updateOriginalResponse(MessageBuilder::new()->setContent($this->civ13->enabled_gameservers[$interaction->data->options['server']->value]->getRank($ckey), true))
                )
            );
        });
        
        $this->discord->listenCommand('ranking', function (Interaction $interaction): PromiseInterface
        {
            if (! isset($interaction->data->options['server']) || ! $server = $interaction->data->options['server']->value) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("No server specified"), true);
            if (! $gameserver = $this->civ13->enabled_gameservers[$server]) return $interaction->respondWithMessage(MessageBuilder::new()->setContent("No enabled server found for `{$server}`"), true);
            return $interaction->acknowledge()->then(fn(): PromiseInterface => // wait until the bot says "Is thinking..."
                ($ranking = $gameserver->getRanking())
                    ? $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent($ranking), true)
                    : $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent("Ranking for the `{$gameserver->name}` server are not currently available."), true)
            );
        });

        /**
         * 1. Checks if the member already has the 'Verified' role and responds accordingly.
         * 2. Validates the provided 'ckey' option and responds with an error message if invalid.
         * 3. Checks if the member or 'ckey' is softbanned and responds with an investigation message if true.
         * 4. If the member is not already verified, acknowledges the interaction and processes the verification.
         * 5. If the member is already verified, responds with a welcome message and sets the 'Verified' role.
         */
        $this->discord->listenCommand('approveme', function (Interaction $interaction): PromiseInterface
        {
            if ($interaction->member->roles->has($this->civ13->role_ids['Verified'])) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('You already have the verification role!'), true);
            if (! isset($interaction->data->options['ckey']) || ! $ckey = Civ13::sanitizeInput($interaction->data->options['ckey']->value)) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('Invalid ckey.'), true);
            if (isset($this->civ13->softbanned[$interaction->member->id]) || isset($this->civ13->softbanned[$ckey])) return $interaction->respondWithMessage(MessageBuilder::new()->setContent('This account is currently under investigation.'));
            if (! $item = $this->civ13->verifier->get('discord', $interaction->member->id))
                return $interaction->acknowledge()->then(fn(): PromiseInterface => // wait until the bot says "Is thinking..."
                    $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent('Working...'))->then(fn(Message $message): PromiseInterface =>
                        $interaction->sendFollowUpMessage(MessageBuilder::new()->setContent($this->civ13->verifier->process($ckey, $interaction->member->id, $interaction->member)), true)->then(fn(Message $message): PromiseInterface =>
                            $interaction->updateOriginalResponse(MessageBuilder::new()->setContent("Verified request received. Please check my response for further instructions."))
                        )
                    )
                );
            return $interaction->respondWithMessage(MessageBuilder::new()->setContent("Welcome to {$interaction->member->guild->name}}! Your roles have been set and you should now have access to the rest of the server."), true)->then(fn (): PromiseInterface =>
                $interaction->member->setRoles([$this->civ13->role_ids['Verified']], "approveme {$item['ss13']}")
            );
        });
    }
}