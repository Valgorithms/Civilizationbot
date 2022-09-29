<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

class Civ13
{
    public $loop;
    public $discord;
    public $browser;
    public $filesystem;
    public $logger;
    
    protected $webapi;
    
    public $timers = [];
    
    public $functions = array(
        'ready' => [],
        'ready_slash' => [],
        'messages' => [],
        'misc' => [],
    );
    
    public $command_symbol = '!s';
    public $owner_id = '196253985072611328';
    public $civ13_guild_id = '468979034571931648';
    
    public $files = [];
    public $ips = [];
    public $ports = [];
    public $channel_ids = [];
    public $role_ids = [];
    
    /**
     * Creates a Civ13 client instance.
     *
     * @param  array           $options Array of options.
     * @throws IntentException
     */
    public function __construct(array $options = [])
    {
        if (php_sapi_name() !== 'cli') {
            trigger_error('DiscordPHP will not run on a webserver. Please use PHP CLI to run a DiscordPHP bot.', E_USER_ERROR);
        }

        // x86 need gmp extension for big integer operation
        if (PHP_INT_SIZE === 4 && ! \Discord\Helpers\BigInt::init()) {
            trigger_error('ext-gmp is not loaded. Permissions will NOT work correctly!', E_USER_WARNING);
        }
        
        $options = $this->resolveOptions($options);
        
        $this->loop = $options['loop'];
        $this->browser = $options['browser'];
        $this->filesystem = $options['filesystem'];
        $this->logger = $options['logger'];
        
        if(isset($options['command_symbol'])) $this->command_symbol = $options['command_symbol'];
        if(isset($options['owner_id'])) $this->owner_id = $options['owner_id'];
        if(isset($options['civ13_guild_id'])) $this->civ13_guild_id = $options['civ13_guild_id'];
                
        if(isset($options['discord'])) $this->discord = $options['discord'];
        elseif(isset($options['discord_options'])) $this->discord = new \Discord\Discord($options['discord_options']);
        
        if (isset($options['functions'])) foreach ($options['functions'] as $key1 => $key2) foreach ($options['functions'][$key1] as $key3 => $func) $this->functions[$key1][$key3] = $func;
        else $this->logger->warning('No functions passed in options!');
        
        if(isset($options['files'])) foreach ($options['files'] as $key => $path) $this->files[$key] = $path;
        else $this->logger->warning('No files passed in options!');
        if(isset($options['ips']) && isset($options['ports'])) {
            foreach ($options['ips'] as $key => $ip) $this->ips[$key] = $ip;
            foreach ($options['ports'] as $key => $port) $this->ports[$key] = $port;
        } else $this->logger->warning('Either ips or ports was not passed in options!');
        if(isset($options['channel_ids'])) foreach ($options['channel_ids'] as $key => $id) $this->channel_ids[$key] = $id;
        else $this->logger->warning('No channel_ids passed in options!');
        if(isset($options['role_ids'])) foreach ($options['role_ids'] as $key => $id) $this->role_ids[$key] = $id;
        else $this->logger->warning('No role_ids passed in options!');
        $this->afterConstruct();
    }
    
    protected function afterConstruct()
    {
        if(isset($this->discord)) {
            $this->discord->once('ready', function () {
                if(! empty($this->functions['ready'])) foreach ($this->functions['ready'] as $func) $func($this);
                else $this->logger->debug('No ready functions found!');
                $this->discord->application->commands->freshen()->done( function ($commands) {
                    if (!empty($this->functions['ready_slash'])) foreach ($this->functions['ready_slash'] as $key => $func) $func($this, $commands);
                    else $this->logger->debug('No ready slash functions found!');
                });
                
                $this->discord->on('message', function ($message) {
                    if(! empty($this->functions['message'])) foreach ($this->functions['message'] as $func) $func($this, $message);
                    else $this->logger->debug('No message functions found!');
                });
                $this->discord->on('GUILD_MEMBER_ADD', function ($guildmember) {
                    if(! empty($this->functions['GUILD_MEMBER_ADD'])) foreach ($this->functions['GUILD_MEMBER_ADD'] as $func) $func($this, $guildmember);
                    else $this->logger->debug('No message functions found!');
                });
            });
        }
    }
    
    /*
    * Attempt to catch errors with the user-provided $options early
    */
    protected function resolveOptions(array $options = []): array
    {
        if (is_null($options['logger'])) {
            $logger = new \Monolog\Logger('Civ13');
            $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::DEBUG));
            $options['logger'] = $logger;
        }
        
        $options['loop'] = $options['loop'] ?? \React\EventLoop\Factory::create();
        $options['browser'] = $options['browser'] ?? new \React\Http\Browser($options['loop']);
        $options['filesystem'] = $options['filesystem'] ?? \React\Filesystem\Factory::create($options['loop']);
        return $options;
    }
    
    public function run(): void
    {
        $this->logger->info('Starting Discord loop');
        if(!(isset($this->discord))) $this->logger->warning('Discord not set!');
        else $this->discord->run();
    }
    
    public function stop(): void
    {
        $this->logger->info('Shutting down');
        if((isset($this->discord))) $this->discord->stop();
    }
}