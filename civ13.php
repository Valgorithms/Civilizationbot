<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Discord;
use Discord\Helpers\BigInt;
use Discord\Helpers\Collection;
use Discord\Parts\Guild\Guild;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Filesystem\Factory as FilesystemFactory;

class Civ13
{
    public $loop;
    public $discord;
    public $browser;
    public $filesystem;
    public $logger;
    public $stats;
    
    protected $webapi;
    public $verified; //This probably needs a default value for Collection, maybe make it a Repository instead?
    
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
    public $verifier_feed_channel_id = '1032411190695055440';
    public $civ_token = '';
    
    public $files = [];
    public $ips = [];
    public $ports = [];
    public $channel_ids = [];
    public $role_ids = [];
    
    public $discord_config = [];
    public $tests = [];
    
    /**
     * Creates a Civ13 client instance.
     *
     * @param  array           $options Array of options.
     * @throws IntentException
     */
    public function __construct(array $options = [])
    {
        if (php_sapi_name() !== 'cli') trigger_error('DiscordPHP will not run on a webserver. Please use PHP CLI to run a DiscordPHP bot.', E_USER_ERROR);

        // x86 need gmp extension for big integer operation
        if (PHP_INT_SIZE === 4 && ! BigInt::init()) trigger_error('ext-gmp is not loaded. Permissions will NOT work correctly!', E_USER_WARNING);
        
        $options = $this->resolveOptions($options);
        
        $this->loop = $options['loop'];
        $this->browser = $options['browser'];
        $this->filesystem = $options['filesystem'];
        $this->logger = $options['logger'];
        $this->stats = $options['stats'];
        
        if (isset($options['filecache_path'])) {
            if (is_string($options['filecache_path'])) {
                if (! str_ends_with($options['filecache_path'], '/')) $options['filecache_path'] .= '/';
                $this->filecache_path = $options['filecache_path'];
            } else $this->filecache_path = getcwd() . '/json/';
        } else $this->filecache_path = getcwd() . '/json/';
        if (!file_exists($this->filecache_path)) mkdir($this->filecache_path, 0664, true);
        
        if(isset($options['command_symbol'])) $this->command_symbol = $options['command_symbol'];
        if(isset($options['owner_id'])) $this->owner_id = $options['owner_id'];
        if(isset($options['github'])) $this->github = $options['github'];
        if(isset($options['civ13_guild_id'])) $this->civ13_guild_id = $options['civ13_guild_id'];
        if(isset($options['verifier_feed_channel_id'])) $this->verifier_feed_channel_id = $options['verifier_feed_channel_id'];
        if(isset($options['civ_token'])) $this->civ13_token = $options['civ_token'];
                
        if(isset($options['discord'])) $this->discord = $options['discord'];
        elseif(isset($options['discord_options'])) $this->discord = new Discord($options['discord_options']);
        
        if (isset($options['functions'])) foreach ($options['functions'] as $key1 => $key2) foreach ($options['functions'][$key1] as $key3 => $func) $this->functions[$key1][$key3] = $func;
        else $this->logger->warning('No functions passed in options!');
        
        if(isset($options['files'])) foreach ($options['files'] as $key => $path) $this->files[$key] = $path;
        else $this->logger->warning('No files passed in options!');
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
                $this->getVerified(); //Populate verified property with data from DB
                //Initialize configurations
                if (! $discord_config = $this->VarLoad('discord_config.json')) $discord_config = [];
                foreach ($this->discord->guilds as $guild) if (!isset($discord_config[$guild->id])) $this->SetConfigTemplate($guild, $discord_config);
                $this->discord_config = $discord_config;
                register_shutdown_function([$this, "VarSave"], 'discord_config.json', $this->discord_config);
                
                if (! $tests = $this->VarLoad('tests.json')) $tests = [];
                $this->tests = $tests;
                register_shutdown_function([$this, "VarSave"], 'tests.json', $this->tests);
                
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
                $this->discord->on('GUILD_CREATE', function (Guild $guild)
                {
                    foreach ($this->discord->guilds as $guild) if (!isset($this->discord_config[$guild->id])) $this->SetConfigTemplate($guild, $this->discord_config);
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
            $logger = new Logger('Civ13');
            $logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
            $options['logger'] = $logger;
        }
        
        $options['loop'] = $options['loop'] ?? Loop::get();
        $options['browser'] = $options['browser'] ?? new Browser($options['loop']);
        $options['filesystem'] = $options['filesystem'] ?? FileSystemFactory::create($options['loop']);
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
    
    /*
     * Please maintain a consistent schema for directories and files
     *
     * The bot's $filecache_path should be a folder named json inside of either cwd() or __DIR__
     * getcwd() should be used if there are multiple instances of this bot operating from different source directories or on different shards but share the same bot files (NYI)
     * __DIR__ should be used if the json folder should be expected to always be in the same folder as this file, but only if this bot is not installed inside of /vendor/
     *
     * The recommended schema is to follow DiscordPHP's Redis schema, but replace : with ;
     * dphp:cache:Channel:115233111977099271:1001123612587212820 would become dphp;cache;Channel;115233111977099271;1001123612587212820.json
     * In the above example the first set of numbers represents the guild_id and the second set of numbers represents the channel_id
     * Similarly, Messages might be cached like dphp;cache;Message;11523311197709927;234582138740146176;1014616396270932038.json where the third set of numbers represents the message_id
     * This schema is recommended because the expected max length of the file name will not usually exceed 80 characters, which is far below the NTFS character limit of 255,
     * and is still generic enough to easily automate saving and loading files using data served by Discord
     *
     * Windows users may need to enable long path in Windows depending on whether the length of the installation path would result in subdirectories exceeding 260 characters
     * Click Window key and type gpedit.msc, then press the Enter key. This launches the Local Group Policy Editor
     * Navigate to Local Computer Policy > Computer Configuration > Administrative Templates > System > Filesystem
     * Double click Enable NTFS long paths
     * Select Enabled, then click OK
     *
     * If using Windows 10/11 Home Edition, the following commands need to be used in an elevated command prompt before continuing with gpedit.msc
     * FOR %F IN ("%SystemRoot%\servicing\Packages\Microsoft-Windows-GroupPolicy-ClientTools-Package~*.mum") DO (DISM /Online /NoRestart /Add-Package:"%F")
     * FOR %F IN ("%SystemRoot%\servicing\Packages\Microsoft-Windows-GroupPolicy-ClientExtensions-Package~*.mum") DO (DISM /Online /NoRestart /Add-Package:"%F")
     */
    public function VarSave(string $filename = '', array $assoc_array = []): bool
    {
        if ($filename === '') return false;
        if (file_put_contents($this->filecache_path . $filename, json_encode($assoc_array)) === false) return false;
        return true;
    }

    public function VarLoad(string $filename = ''): false|array
    {
        if ($filename === '') return false;
        if (!file_exists($this->filecache_path . $filename)) return false;
        if (($string = file_get_contents($this->filecache_path . $filename)) === false) return false;
        if ($assoc_array = json_decode($string, TRUE)) return $assoc_array;
        return false;
    }

    public function SetConfigTemplate(Guild $guild, array &$discord_config): void
    {
        $discord_config[$guild->id] = [
            'toggles' => [
                'verifier' => false, //Verifier is disabled by default in new servers
            ],
            'roles' => [
                'verified' => '', 
                'promoted' => '', //Different servers may have different standards for getting promoted
            ],
        ];
        if ($this->VarSave('discord_config.json', $discord_config)) $this->logger->info("Created new config for guild {$guild->name}");
        else $this->logger->warning("Failed top create new config for guild {$guild->name}");
    }

    public function getVerified(): Collection
    {
        if ($verified_array = json_decode(file_get_contents('http://valzargaming.com/verified/'), true)) {
            $this->VarSave('verified.json', $verified_array);
            $collection = new Collection($verified_array, 'discord');
        } elseif ($json = $this->VarLoad('verified.json')) $collection = new Collection($json, 'discord');
        else $collection = new Collection([], 'discord');
        
        if ($guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return $this->verified = $collection->filter(function($v) use ($guild) { return $guild->members->cache->has($v['discord']); });
        return $this->verified = $collection;
    }
}