<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Civ13\Slash;
use Discord\Discord;
use Discord\Helpers\BigInt;
use Discord\Helpers\Collection;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Role;
use Discord\Parts\User\Member;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Http\Browser;
use React\Http\Server;
use React\EventLoop\TimerInterface;
use React\Filesystem\Factory as FilesystemFactory;

class Civ13
{
    public Slash $slash;

    public StreamSelectLoop $loop;
    public Discord $discord;
    public Browser $browser;
    public $filesystem;
    public Logger $logger;
    public $stats;

    public $filecache_path = '';
    
    protected Server $webapi;
    
    public collection $verified; //This probably needs a default value for Collection, maybe make it a Repository instead?
    public collection $pending;
    public array $provisional; //TODO:  Allow provisional registration if the website is down, then try to verify when it comes back up
    public array $ages = []; //$ckey => $age, temporary cache to avoid spamming the Byond REST API, but we don't want to save it to a file because we also use it to check if the account still exists
    public string $minimum_age = '-21 days'; //Minimum age of a ckey
    public array $permitted = []; //List of ckeys that are permitted to use the verification command even if they don't meet the minimum age requirement

    public array $timers = [];
    public array $serverinfo = []; //Collected automatically by serverinfo_timer
    public array $players = []; //Collected automatically by serverinfo_timer
    public array $seen_players = []; //Collected automatically by serverinfo_timer
    public int $playercount_ticker = 0;
    public array $badwords = [
        /* Format:
            'word' => 'bad word' //Bad word to look for
            'duration' => duration ['1 minute', '1 hour', '1 day', '1 week', '1 month', '999 years'] //Duration of the ban
            'reason' => 'reason' //Reason for the ban
            'category' => rule category ['racism/discrimination', 'toxic'] //Used to group bad words together by category
            'method' => detection method ['exact', 'contains'] //Exact ignores partial matches, contains matches partial matchesq
            'warnings' => 1 //Number of warnings before a ban
        */
        ['word' => 'badwordtestmessage', 'duration' => '1 minute', 'reason' => 'Violated server rule.', 'category' => 'test', 'method' => 'contains', 'warnings' => 1], //Used to test the system
        
        ['word' => 'beaner', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'contains', 'warnings' => 1],
        ['word' => 'chink', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'contains', 'warnings' => 1],
        ['word' => 'coon', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'exact', 'warnings' => 1],
        ['word' => 'fag', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'contains', 'warnings' => 1],
        ['word' => 'gook', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'contains', 'warnings' => 1],
        ['word' => 'kike', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'contains', 'warnings' => 1],
        ['word' => 'nigg', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'contains', 'warnings' => 1],
        ['word' => 'nlgg', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'contains', 'warnings' => 1],
        ['word' => 'niqq', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'contains', 'warnings' => 1],
        ['word' => 'tranny', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'contains', 'warnings' => 1],
        ['word' => 'cunt', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 1],
        ['word' => 'fuck you', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 1],
        ['word' => 'retard', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 1],
    ];
    public array $badwords_warnings = []; //Collection of $ckey => ['category' => string, 'badword' => string, 'count' => integer] for how many times a user has recently infringed
    public bool $legacy = true;
    
    public $functions = array(
        'ready' => [],
        'ready_slash' => [],
        'messages' => [],
        'misc' => [],
    );
    
    public string $command_symbol = '!s'; //The symbol that the bot will use to identify commands if it is not mentioned
    public string $owner_id = '196253985072611328'; //Taislin's Discord ID
    public string $technician_id = '116927250145869826'; //Valithor Obsidion's Discord ID
    public string $embed_footer = ''; //Footer for embeds, this is set in the ready event
    public string $civ13_guild_id = '468979034571931648'; //Guild ID for the Civ13 server
    public string $verifier_feed_channel_id = '1032411190695055440'; //Channel where the bot will listen for verification notices and then update its verified cache accordingly
    public string $civ_token = ''; //Token for use with $verifyurl, this is not the same as the bot token and should be kept secret

    public string $github = 'https://github.com/VZGCoders/Civilizationbot'; //Link to the bot's github page
    public string $banappeal = 'civ13.com slash discord'; //Players can appeal their bans here
    public string $verifyurl = 'http://valzargaming.com:8080/verified/'; //This is the URL that the bot will use to verify a ckey and where it will retrieve the list of verified ckeys from
    
    public array $files = [];
    public array $ips = [];
    public array $ports = [];
    public array $channel_ids = [];
    public array $role_ids = [];
    public array $permissions = []; //NYI, used to store rank_check array for each command
    
    public array $discord_config = []; //This variable and its related function currently serve no purpose, but I'm keeping it in case I need it later
    public array $tests = []; //Staff application test templates
    public bool $panic_bunker = false; //If true, the bot will server ban anyone who is not verified when they join the server
    public array $panic_bans = []; //List of ckeys that have been banned by the panic bunker in the current runtime

    /**
     * Creates a Civ13 client instance.
     * 
     * @throws E_USER_ERROR
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
        
        $this->filecache_path = getcwd() . '/json/';
        if (isset($options['filecache_path']) && is_string($options['filecache_path'])) {
            if (! str_ends_with($options['filecache_path'], '/')) $options['filecache_path'] .= '/';
            $this->filecache_path = $options['filecache_path'];
        }
        if (!file_exists($this->filecache_path)) mkdir($this->filecache_path, 0664, true);
        
        if(isset($options['command_symbol'])) $this->command_symbol = $options['command_symbol'];
        if(isset($options['owner_id'])) $this->owner_id = $options['owner_id'];
        if(isset($options['technician_id'])) $this->technician_id = $options['technician_id'];
        if(isset($options['banappeal'])) $this->banappeal = $options['banappeal'];
        if(isset($options['github'])) $this->github = $options['github'];
        if(isset($options['civ13_guild_id'])) $this->civ13_guild_id = $options['civ13_guild_id'];
        if(isset($options['verifier_feed_channel_id'])) $this->verifier_feed_channel_id = $options['verifier_feed_channel_id'];
        if(isset($options['civ_token'])) $this->civ_token = $options['civ_token'];
                
        if(isset($options['discord']) && ($options['discord'] instanceof Discord)) $this->discord = $options['discord'];
        elseif(isset($options['discord_options']) && is_array($options['discord_options'])) $this->discord = new Discord($options['discord_options']);
        else $this->logger->error('No Discord instance or options passed in options!');
        require 'slash.php';
        $this->slash = new Slash($this);
        
        if (isset($options['functions'])) foreach (array_keys($options['functions']) as $key1) foreach ($options['functions'][$key1] as $key2 => $func) $this->functions[$key1][$key2] = $func;
        else $this->logger->warning('No functions passed in options!');
        
        if(isset($options['files'])) foreach ($options['files'] as $key => $path) $this->files[$key] = $path;
        else $this->logger->warning('No files passed in options!');
        if(isset($options['channel_ids'])) foreach ($options['channel_ids'] as $key => $id) $this->channel_ids[$key] = $id;
        else $this->logger->warning('No channel_ids passed in options!');
        if(isset($options['role_ids'])) foreach ($options['role_ids'] as $key => $id) $this->role_ids[$key] = $id;
        else $this->logger->warning('No role_ids passed in options!');
        $this->afterConstruct();
    }
    
    /*
    * This function is called after the constructor is finished.
    * It is used to load the files, start the timers, and start handling events.
    */
    protected function afterConstruct()
    {
        if(isset($this->discord)) {
            $this->discord->once('ready', function () {
                $this->logger->info("logged in as {$this->discord->user->displayname} ({$this->discord->id})");
                $this->logger->info('------');
                if (! $tests = $this->VarLoad('tests.json')) $tests = [];
                $this->tests = $tests;
                if (! $permitted = $this->VarLoad('permitted.json')) {
                    $permitted = [];
                    $this->VarSave('permitted.json', $permitted);
                }
                $this->permitted = $permitted;
                if (! $panic_bans = $this->VarLoad('panic_bans.json')) {
                    $panic_bans = [];
                    $this->VarSave('panic_bans.json', $panic_bans);
                }
                $this->panic_bans = $panic_bans;
                if (! $badwords_warnings = $this->VarLoad('badwords_warnings.json')) {
                    $badwords_warnings = [];
                    $this->VarSave('badwords_warnings.json', $badwords_warnings);
                }
                $this->badwords_warnings = $badwords_warnings;
                $this->embed_footer = ($this->github ?  $this->github . PHP_EOL : '') . "{$this->discord->username} by Valithor#5947";

                $this->getVerified(); //Populate verified property with data from DB
                if (! $provisional = $this->VarLoad('provisional.json')) {
                    $provisional = [];
                    $this->VarSave('provisional.json', $provisional);
                }
                $this->provisional = $provisional;
                foreach ($this->provisional as $ckey => $discord_id) $this->provisionalRegistration($ckey, $discord_id); //Attempt to register all provisional users
                $this->unbanTimer(); //Start the unban timer and remove the role from anyone who has been unbanned
                $this->setIPs();
                $this->serverinfoTimer(); //Start the serverinfo timer and update the serverinfo channel
                $this->pending = new Collection([], 'discord');
                //Initialize configurations
                if (! $discord_config = $this->VarLoad('discord_config.json')) $discord_config = [];
                foreach ($this->discord->guilds as $guild) if (!isset($discord_config[$guild->id])) $this->SetConfigTemplate($guild, $discord_config);
                $this->discord_config = $discord_config; //Declared, but not currently used for anything
                
                if(! empty($this->functions['ready'])) foreach ($this->functions['ready'] as $func) $func($this);
                else $this->logger->debug('No ready functions found!');
                $this->discord->application->commands->freshen()->done( function ($commands): void
                {
                    $this->slash->updateCommands($commands);
                    if (!empty($this->functions['ready_slash'])) foreach (array_values($this->functions['ready_slash']) as $func) $func($this, $commands);
                    else $this->logger->debug('No ready slash functions found!');
                });
                
                $this->discord->on('message', function ($message): void
                {
                    if(! empty($this->functions['message'])) foreach ($this->functions['message'] as $func) $func($this, $message);
                    else $this->logger->debug('No message functions found!');
                });
                $this->discord->on('GUILD_MEMBER_ADD', function ($guildmember): void
                {
                    $this->joinRoles($guildmember);
                    if(! empty($this->functions['GUILD_MEMBER_ADD'])) foreach ($this->functions['GUILD_MEMBER_ADD'] as $func) $func($this, $guildmember);
                    else $this->logger->debug('No message functions found!');
                });
                $this->discord->on('GUILD_CREATE', function (Guild $guild): void
                {
                    if (!isset($this->discord_config[$guild->id])) $this->SetConfigTemplate($guild, $this->discord_config);
                });

                if ($guild = $this->discord->guilds->get('id', $this->civ13_guild_id) && (! (isset($this->timers['relay_timer'])) || (! $this->timers['relay_timer'] instanceof TimerInterface))) {
                    $this->logger->info('chat relay timer started');
                    $this->timers['relay_timer'] = $this->discord->getLoop()->addPeriodicTimer(10, function() {
                        $guild = $this->discord->guilds->get('id', $this->civ13_guild_id);
                        if (isset($this->channel_ids['nomads_ooc_channel']) && $channel = $guild->channels->get('id', $this->channel_ids['nomads_ooc_channel'])) $this->gameChatRelay($this->files['nomads_ooc_path'], $channel);  // #ooc-nomads
                        if (isset($this->channel_ids['nomads_admin_channel']) && $channel = $guild->channels->get('id', $this->channel_ids['nomads_admin_channel'])) $this->gameChatRelay($this->files['nomads_admin_path'], $channel);  // #ahelp-nomads
                        if (isset($this->channel_ids['tdm_ooc_channel']) && $channel = $guild->channels->get('id', $this->channel_ids['tdm_ooc_channel'])) $this->gameChatRelay($this->files['tdm_ooc_path'], $channel);  // #ooc-tdm
                        if (isset($this->channel_ids['tdm_admin_channel']) && $channel = $guild->channels->get('id', $this->channel_ids['tdm_admin_channel'])) $this->gameChatRelay($this->files['tdm_admin_path'], $channel);  // #ahelp-tdm
                        if (isset($this->channel_ids['pers_ooc_channel']) && $channel = $guild->channels->get('id', $this->channel_ids['pers_ooc_channel'])) $this->gameChatRelay($this->files['pers_ooc_path'], $channel);  // #ooc-tdm
                        if (isset($this->channel_ids['pers_admin_channel']) && $channel = $guild->channels->get('id', $this->channel_ids['pers_admin_channel'])) $this->gameChatRelay($this->files['pers_admin_path'], $channel);  // #ahelp-tdm
                    });
                }
            });

        }
    }
    
    /**
     * Attempt to catch errors with the user-provided $options early
     */
    protected function resolveOptions(array $options = []): array
    {
        if (! isset($options['logger']) || ! ($options['logger'] instanceof Logger)) {
            $logger = new Logger('Civ13');
            $logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));
            $options['logger'] = $logger;
        }
        
        if (! isset($options['loop']) || ! ($options['loop'] instanceof LoopInterface)) $options['loop'] = Loop::get();
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
    
    /**
     * These functions are used to save and load data to and from files.
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
        if (! $assoc_array = json_decode($string, TRUE)) return false;
        return $assoc_array;
    }

    /*
    * This function is used to navigate a file tree and find a file
    * $basedir is the directory to start in
    * $subdirs is an array of subdirectories to navigate
    * $subdirs should be a 1d array of strings
    * The first string in $subdirs should be the first subdirectory to navigate to, and so on    
    */
    public function FileNav(string $basedir, array $subdirs): array
    {
        $scandir = scandir($basedir);
        unset($scandir[1], $scandir[0]);
        if (! $subdir = array_shift($subdirs)) return [false, $scandir];
        if (! in_array($subdir = trim($subdir), $scandir)) return [false, $scandir, $subdir];
        if (is_file("$basedir/$subdir")) return [true, "$basedir/$subdir"];
        return $this->FileNav("$basedir/$subdir", $subdirs);
    }

    /*
    * This function is used to set the default config for a guild if it does not already exist
    */
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

    /* This function is used to fetch the bot's cache of verified members that are currently found in the Civ13 Discord server
    * If the bot is not in the Civ13 Discord server, it will return the bot's cache of verified members
    */
    public function getVerifiedMemberItems(): Collection
    {
        if ($guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return $this->verified->filter(function($v) use ($guild) { return $guild->members->has($v['discord']); });
        return $this->verified;
    }

    public function getVerifiedItem($id)
    {
        if (is_numeric($id) && $item = $this->verified->get('discord', $id)) return $item;
        if ($item = $this->verified->get('ss13', $id)) return $item;
        preg_match('/<@(\d+)>/', $id, $matches);
        if (isset($matches[1]) && is_numeric($matches[1]) && $item = $this->verified->get('discord', $matches[1])) return $item;
        return false;
    }

    public function getVerifiedMember($item): Member|false
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return false;
        if (is_string($item)) {
            preg_match('/<@(\d+)>/', $item, $matches);
            if (isset($matches[1]) && is_numeric($matches[1]) && $item = $this->verified->get('discord', $matches[1])) return $item; // 
            if (is_string($item = $this->getVerifiedItem($item))) return false;
        }
        if ($item && $member = $guild->members->get('id', $item['discord'])) return $member;
        return false;
    }

    public function getRole($id): Role|false
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return false;
        if ($id && $role = $guild->roles->get('id', $id)) return $role;
        return false;
    }
    
    /*
    * This function is used to refresh the bot's cache of verified users
    * It is called when the bot starts up, and when the bot receives a GUILD_MEMBER_ADD event
    * It is also called when the bot receives a GUILD_MEMBER_REMOVE event
    * It is also called when the bot receives a GUILD_MEMBER_UPDATE event, but only if the user's roles have changed
    */
    public function getVerified(): Collection
    {
        if ($verified_array = json_decode(file_get_contents($this->verifyurl), true)) {
            $this->VarSave('verified.json', $verified_array);
            return $this->verified = new Collection($verified_array, 'discord');
        }
        if ($json = $this->VarLoad('verified.json')) return $this->verified = new Collection($json, 'discord');
        return $this->verified = new Collection([], 'discord');
    }
    
    /*
     * This function is used to generate a token that can be used to verify a BYOND account
     * The token is generated by generating a random string of 50 characters from the set of all alphanumeric characters
     * The token is then stored in the pending collection, which is a collection of arrays with the keys 'discord', 'ss13', and 'token'
     * The token is then returned to the user
     */
    public function generateByondToken(string $ckey, string $discord_id): string
    {
        if ($item = $this->pending->get('ss13', $ckey)) return $item['token'];
        
        $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $token = '';
        while (strlen($token)<50) $token .= $charset[(mt_rand(0,(strlen($charset)-1)))];
        $this->pending->pushItem(['discord' => $discord_id, 'ss13' => $ckey, 'token' => $token]);
        return $token;
    }

    /*
     * This function is used to verify a BYOND account
     * The function first checks if the discord_id is in the pending collection
     * If the discord_id is not in the pending collection, the function returns false
     * The function then attempts to retrieve the 50 character token from the BYOND website
     * If the token found on the BYOND website does not match the token in the pending collection, the function returns false
     * If the token matches, the function returns true
     */
    public function checkToken(string $discord_id): bool
    { //Check if the user set their token
        if (! $item = $this->pending->get('discord', $discord_id)) return false;
        if (! $page = $this->getByondPage($item['ss13'])) return false;
        if ($item['token'] != $this->getByondDesc($page)) return false;
        return true;
    }
    
    /*
     * This function is used to retrieve the 50 character token from the BYOND website
     */
    public function getByondPage(string $ckey): string|false 
    { //Get the 50 character token from the desc. User will have needed to log into https://secure.byond.com/members/-/account and add the generated token to their description first!
        $url = 'http://www.byond.com/members/'.urlencode($ckey).'?format=text';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); //return the page as a string
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $page = curl_exec($ch);
        curl_close($ch);
        if ($page) return $page;
        return false;        
    }
    
    /*
     * This function is used to retrieve the 50 character token from the BYOND website
     */
    public function getByondDesc(string $page): string|false 
    {
        if ($desc = substr($page, (strpos($page , 'desc')+8), 50)) return $desc; //PHP versions older than 8.0.0 will return false if the desc isn't found, otherwise an empty string will be returned
        return false;
    }
    
    /*
     * This function is used to parse a BYOND account's age
     * */
    public function parseByondAge(string $page, ?string $ckey = null): string|false
    {
		if (preg_match("^(19|20)\d\d[- /.](0[1-9]|1[012])[- /.](0[1-9]|[12][0-9]|3[01])^", $age = substr($page, (strpos($page , 'joined')+10), 10))) return $age;
        return false;
    }
    public function getByondAge($ckey): string|false
    {
        if (isset($this->ages[$ckey])) return $this->ages[$ckey];
        if ($age = $this->parseByondAge($this->getByondPage($ckey))) return $this->ages[$ckey] = $age;
        return false;
    }
    /*
     * This function is used determine if a byond account is old enough to play on the server
     * false is returned if the account is too young, true is returned if the account is old enough
     */
    public function checkByondAge(string $age): bool
    {
        return (strtotime($age) > strtotime($this->minimum_age)) ? false : true;
    }

    /*
    * This function is used to check if the user has verified their account
    * If the have not, it checks to see if they have ever played on the server before
    * If they have not, it sends a message stating that they need to join the server first
    * It will send a message to the user with instructions on how to verify
    * If they have, it will check if they have the verified role, and if not, it will add it
    */
    public function verifyProcess(string $ckey, string $discord_id): string
    {
        if ($this->verified->has($discord_id)) { $member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id); if (! $member->roles->has($this->role_ids['infantry'])) $member->setRoles([$this->role_ids['infantry']], "approveme join $ckey"); return 'You are already verified!';}
        if ($this->verified->has($ckey)) return "`$ckey` is already verified! If this is your account, please ask Valithor to delete this entry.";
        if (! $this->pending->get('discord', $discord_id)) {
            if (! $age = $this->getByondAge($ckey)) return "Ckey `$ckey` does not exist!";
            if (! $this->checkByondAge($age) && ! isset($this->permitted[$ckey])) {
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage($this->ban([$ckey, '999 years', "Byond account $ckey does not meet the requirements to be approved. ($age)"]));
                return "Ckey `$ckey` is too new! ($age)";
            }
            $found = false;
            foreach (explode('|', file_get_contents($this->files['tdm_playerlogs']) . file_get_contents($this->files['nomads_playerlogs'])) as $line)
                if (explode(';', trim($line))[0] == $ckey) { $found = true; break; }
            if (! $found) return "Ckey `$ckey` has never been seen on the server before! You'll need to join either Nomads or TDM at least once before verifying."; 
            return 'Login to your profile at https://secure.byond.com/members/-/account and enter this token as your description: `' . $this->generateByondToken($ckey, $discord_id) . PHP_EOL . '`Use the command again once this process has been completed.';
        }
        return $this->verifyNew($discord_id)[1]; //[0] will be false if verification cannot proceed or true if succeeded but is only needed if debugging, [1] will contain the error/success message and will be messaged to the user
    }

    /*
    * This function is called when a user still needs to set their token in their BYOND description and call the approveme prompt
    * It will check if the token is valid, then add the user to the verified list
    */
    public function verifyNew(string $discord_id): array //[bool, string]
    { //Attempt to verify a user
        if(! $item = $this->pending->get('discord', $discord_id)) return [false, 'This error should never happen'];
        if(! $this->checkToken($discord_id)) return [false, "You have not set your description yet! It needs to be set to {$item['token']}"];
        if ($this->byondinfo($item['ss13'])[4] && ! isset($this->permitted[$item['ss13']])) {
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $channel->sendMessage("<@&{$this->role_ids['knight']}>, {$item['ss13']} has been flagged as needing additional review. Please `permit` the ckey after reviewing if they should be allowed to complete the verification process.");
            return [false, "Your ckey `{$item['ss13']}` has been flagged as needing additional review. Please wait for a staff member to assist you."];
        }
        return $this->verifyCkey($item['ss13'], $discord_id);
    }
    
    //TODO: Allow provisional registration if the website is down, then try to verify when it comes back up
    /* 
    * This function is called when a user has set their token in their BYOND description but the website is down
    * It will add the user to the provisional list and set a timer to try to verify them again in 30 minutes
    * If the user is allowed to be granted a provisional role, it will return true
    */
    public function provisionalRegistration(string $ckey, string $discord_id): bool
    {
        $func = function($ckey, $discord_id) use (&$func) {
            if ($item = $this->verified->get('discord', $discord_id)) { //User already verified
                if (isset($this->provisional[$ckey])) unset($this->provisional[$ckey]);
                return false;
            }
            $result = $this->verifyCkey($ckey, $discord_id, true);
            if (! $result[0] || (! $result[0] && isset($result[1]) && str_starts_with('The website', $result[1]))) {
                $this->discord->getLoop()->addTimer(1800, function() use ($func, $ckey, $discord_id) {
                    $func($ckey, $discord_id);
                });
                if ($member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id))
                    if (! $member->roles->has($this->role_ids['infantry']))
                        $member->setRoles([$this->role_ids['infantry']], "Provisional verification `$ckey`");
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage("Failed to verify ckey `$ckey` with Discord ID <@$discord_id> Providing provisional verification role and trying again in 30 minutes... " . $result[1]);
                return true;
            }
            if (! $result[0] && isset($result[1])) {
                unset($this->provisional[$ckey]);
                $this->VarSave('provisional.json', $this->provisional);
                if ($member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id))
                    if ($member->roles->has($this->role_ids['infantry']))
                        $member->setRoles([], 'Provisional verification failed');
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage("Failed to verify ckey `$ckey` with Discord ID <@$discord_id>: {$result[1]}");
                return false;
            }
            if ($result[0]) {
                unset($this->provisional[$ckey]);
                $this->VarSave('provisional.json', $this->provisional);
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage("Successfully verified `$ckey` with Discord ID <@$discord_id>.");
                return false;
            }
            $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage("Something went wrong trying to process the provisional registration for ckey `$ckey` with Discord ID <@$discord_id>. If this error persists, contact <@{$this->technician_id}>.");
            return false;
        };
        return $func($ckey, $discord_id);
    }
    /*
    * This function is called when a user has already set their token in their BYOND description and called the approveme prompt
    * If the Discord ID or ckey is already in the SQL database, it will return an error message stating that the ckey is already verified
    * otherwise it will add the user to the SQL database and the verified list, remove them from the pending list, and give them the verified role
    */
    public function verifyCkey(string $ckey, string $discord_id, $provisional = false): array //[bool, string]
    { //Send $_POST information to the website. Only call this function after the getByondDesc() verification process has been completed!
        $success = false;
        $message = '';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->verifyurl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type' => 'application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //return the transfer as a string    
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['token' => $this->civ_token, 'ckey' => $ckey, 'discord' => $discord_id]));
        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); //Validate the website's HTTP response! 200 = success, 403 = ckey already registered, anything else is an error
        switch ($http_status) {
            case 200: //Verified
                $success = true;
                $message = "`$ckey` - ($this->ages[$ckey]) has been verified and registered to $discord_id";
                $this->pending->offsetUnset($discord_id);
                $this->getVerified();
                if (isset($this->channel_ids['staff_bot'])) $channel = $this->discord->getChannel($this->channel_ids['staff_bot']);
                if (! $member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id)) return [false, "$ckey - {$this->ages[$ckey]}) was verified but the member couldn't be found. This error shouldn't have happened, contact Valithor ASAP!"];
                if (isset($this->panic_bans[$ckey])) {
                    $this->panicUnban($ckey);
                    $message .= ' and the panic bunker ban removed.';
                    if (! $member->roles->has($this->role_ids['infantry'])) $member->addRole($this->role_ids['infantry'], "approveme verified ($ckey)");
                    if ($channel) $channel->sendMessage("Verified and removed the panic bunker ban from $member ($ckey - {$this->ages[$ckey]}).");
                } elseif ($this->bancheck($ckey, true)) {
                    if (! $member->roles->has($this->role_ids['infantry'])) $member->setroles([$this->role_ids['infantry'], $this->role_ids['banished']], "approveme verified ($ckey)");
                    if ($channel) $channel->sendMessage("Added the banished role to $member ($ckey - {$this->ages[$ckey]}).");
                } else {
                    if (! $member->roles->has($this->role_ids['infantry'])) $member->addRole($this->role_ids['infantry'], "approveme verified ($ckey)");
                    if ($channel) $channel->sendMessage("Verified $member. ($ckey - {$this->ages[$ckey]})");
                }
                break;
            case 403: //Already registered
                $message = "Either ckey `$ckey` or <@$discord_id> has already been verified."; //This should have been caught above. Need to run getVerified() again?
                $this->getVerified();
                break;
            case 404:
                $message = 'The website could not be found or is misconfigured. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
            case 504: //Gateway timeout
                $message = 'The website timed out while attempting to process the request. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
            case 0: //TODO: Allow provisional registration if the website is down, then try to verify when it comes back up
                $message = 'The website could not be reached. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";    
                if (! $provisional) { //
                    if (! isset($this->provisional[$ckey])) {
                        $this->provisional[$ckey] = $discord_id;
                        $this->VarSave('provisional.json', $this->provisional);
                    }
                    if ($this->provisionalRegistration($ckey, $discord_id)) {
                        $message = "The website could not be reached. Provisionally registered `$ckey` with Discord ID <@$discord_id>.";
                    } else $message .= 'Provisional registration is already pending and a new provisional role will not be provided at this time.' . PHP_EOL . $message;
                }
                break;
            default: 
                $message = "There was an error attempting to process the request: [$http_status] $result" . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
        }
        curl_close($ch);
        return [$success, $message];
    }
    
    /*
    * This function determines whether a ckey is currently banned from the server
    * It is called when a user is verified to determine whether they should be given the banished role or have it taken away
    * It will check the nomads_bans.txt and tdm_bans.txt files for the ckey
    * If the ckey is found in either file, it will return true
    * Otherwise it will return false
    * If the $bypass parameter is set to true, it will not add or remove the banished role from the user
    */
    public function bancheck(string $ckey, $bypass = false): bool
    {
        $banned = ($this->legacy ? $this->legacyBancheck($ckey) : $this->sqlBancheck($ckey));
        if (! $bypass && $member = $this->getVerifiedMember($ckey))
            if ($banned && ! $member->roles->has($this->role_ids['banished'])) $member->addRole($this->role_ids['banished'], "bancheck ($ckey)");
            elseif (! $banned && $member->roles->has($this->role_ids['banished'])) $member->removeRole($this->role_ids['banished'], "bancheck ($ckey)");
        return $banned;
    }
    public function legacyBancheck(string $ckey): bool
    {
        if (file_exists($this->files['nomads_bans']) && ($filecheck1 = fopen($this->files['nomads_bans'], 'r'))) {
            while (($fp = fgets($filecheck1, 4096)) !== false) {
                //str_replace(PHP_EOL, '', $fp); // Is this necessary?
                $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
                    fclose($filecheck1);
                    return true;
                }
            }
            fclose($filecheck1);
        }// else $this->logger->debug("unable to open `{$this->files['nomads_bans']}`");
        if (file_exists($this->files['tdm_bans']) && ($filecheck2 = fopen($this->files['tdm_bans'], 'r'))) {
            while (($fp = fgets($filecheck2, 4096)) !== false) {
                //str_replace(PHP_EOL, '', $fp); // Is this necessary?
                $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
                    fclose($filecheck2);
                    return true;
                }
            }
            fclose($filecheck2);
        }// else $this->logger->debug("unable to open `{$this->files['tdm_bans']}`");
        if (file_exists($this->files['pers_bans']) && ($filecheck3 = @fopen($this->files['pers_bans'], 'r'))) {
            while (($fp = fgets($filecheck3, 4096)) !== false) {
                //str_replace(PHP_EOL, '', $fp); // Is this necessary?
                $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
                    fclose($filecheck3);
                    return true;
                }
            }
            fclose($filecheck3);
        }// else $this->logger->debug("unable to open `{$this->files['pers_bans']}`");
        return false;
    }
    public function sqlBancheck(string $ckey): bool
    {
        //TODO
        return false;
    }

    /*
    * This function allows a ckey to bypass the panic bunker
    */
    public function permitCkey(string $ckey, bool $allow = true): array
    {
        if ($allow) $this->permitted[$ckey] = true;
        else unset($this->permitted[$ckey]);
        $this->VarSave('permitted.json', $this->permitted);
        return $this->permitted;
    }
    public function panicBan(string $ckey): void
    {
        if (! $this->bancheck($ckey, true)) {
            ($this->legacy ? $this->legacyBanNomads([$ckey, '1 hour', "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->banappeal}"]) : $this->sqlBanNomads([$ckey, '1 hour', "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->banappeal}"]) );
            $this->panic_bans[$ckey] = true;
            $this->VarSave('panic_bans.json', $this->panic_bans);
        }
    }
    public function panicUnban(string $ckey): void
    {
        ($this->legacy ? $this->legacyUnbanNomads($ckey) : $this->sqlUnbanNomads($ckey));
        unset($this->panic_bans[$ckey]);
        $this->VarSave('panic_bans.json', $this->panic_bans);
    }

    /*
    * These Legacy and SQL functions should not be called directly
    * Define $legacy = true/false and use ban/unban methods instead
    */
    public function legacyBanNomads(array $array, $message = null): string
    {
        $admin = ($message ? $message->author->displayname : $this->discord->user->username);
        $result = '';
        if (str_starts_with(strtolower($array[1]), 'perm')) $array[1] = '999 years';
        if (file_exists($this->files['nomads_discord2ban']) && $file = fopen($this->files['nomads_discord2ban'], 'a')) {
            fwrite($file, "$admin:::{$array[0]}:::{$array[1]}:::{$array[2]}" . PHP_EOL);
            fclose($file);
        } else {
            $this->logger->warning("unable to open {$this->files['nomads_discord2ban']}");
            $result .= "unable to open {$this->files['nomads_discord2ban']}" . PHP_EOL;
        }
        $result .= "**$admin** banned **{$array[0]}** from **Nomads** for **{$array[1]}** with the reason **{$array[2]}**" . PHP_EOL;
        return $result;
    }
    public function sqlBanNomads(array $array, $message = null): string
    {
        return "SQL methods are not yet implemented!" . PHP_EOL;
    }
    public function legacyBanTDM(array $array, $message = null): string
    {
        $admin = ($message ? $message->author->displayname : $this->discord->user->username);
        $result = '';
        if (str_starts_with(strtolower($array[1]), 'perm')) $array[1] = '999 years';
        if (file_exists($this->files['tdm_discord2ban']) && $file = fopen($this->files['tdm_discord2ban'], 'a')) {
            fwrite($file, "$admin:::{$array[0]}:::{$array[1]}:::{$array[2]}" . PHP_EOL);
            fclose($file);
        } else {
            $this->logger->warning("unable to open {$this->files['tdm_discord2ban']}");
            return "unable to open {$this->files['tdm_discord2ban']}" . PHP_EOL;
        }
        $result .= "**$admin** banned **{$array[0]}** from **TDM** for **{$array[1]}** with the reason **{$array[2]}**" . PHP_EOL;
        return $result;
    }
    public function sqlBanTDM($array, $message = null): string
    {
        return "SQL methods are not yet implemented!" . PHP_EOL;
    }
    public function legacyBanPers(array $array, $message = null): string
    {
        $admin = ($message ? $message->author->displayname : $this->discord->user->username);
        $result = '';
        if (str_starts_with(strtolower($array[1]), 'perm')) $array[1] = '999 years';
        if (file_exists($this->files['pers_discord2ban']) && $file = fopen($this->files['pers_discord2ban'], 'a')) {
            fwrite($file, "$admin:::{$array[0]}:::{$array[1]}:::{$array[2]}" . PHP_EOL);
            fclose($file);
        } else {
            $this->logger->warning("unable to open {$this->files['pers_discord2ban']}");
            return "unable to open {$this->files['pers_discord2ban']}" . PHP_EOL;
        }
        $result .= "**$admin** banned **{$array[0]}** from **Persistence** for **{$array[1]}** with the reason **{$array[2]}**" . PHP_EOL;
        return $result;
    }
    public function sqlBanPers(array $array, $message = null): string
    {
        return "SQL methods are not yet implemented!" . PHP_EOL;
    }
    public function legacyUnbanNomads(string $ckey, ?string $admin = null): void
    {
        if (file_exists($this->files['nomads_discord2unban']) && $file = fopen($this->files['nomads_discord2unban'], 'a')) {
            fwrite($file, ($admin ? $admin : $this->discord->user->displayname) . ":::$ckey");
            fclose($file);
        }
    }
    public function sqlUnbanNomads(string $ckey, ?string $admin = null): void
    {
        //TODO
    }
    public function legacyUnbanTDM(string $ckey, ?string $admin = null): void
    {
        if (file_exists($this->files['tdm_discord2unban']) && $file = fopen($this->files['tdm_discord2unban'], 'a')) {
            fwrite($file, ($admin ? $admin : $this->discord->user->displayname) . ":::$ckey");
            fclose($file);
        }
    }
    public function sqlUnbanTDM(string $ckey, ?string $admin = null): void
    {
        //TODO
    }
    public function legacyUnbanPers(string $ckey, ?string $admin = null): void
    {
        if (file_exists($this->files['pers_discord2unban']) && $file = fopen($this->files['pers_discord2unban'], 'a')) {
            fwrite($file, ($admin ? $admin : $this->discord->user->displayname) . ":::$ckey");
            fclose($file);
        }
    }
    public function sqlUnbanPers(string $ckey, ?string $admin = null): void
    {
        //TODO
    }
    public function legacyBan(array $array, $message = null): string
    {
        return $this->legacyBanNomads($array, $message) . $this->legacyBanTDM($array, $message);
    }
    public function sqlBan(array $array, $message = null): string
    {
        return $this->sqlBanNomads($array, $message) . $this->sqlBanTDM($array, $message);
    }

    /*
    * These functions determine which of the above methods should be used to process a ban or unban
    * Ban functions will return a string containing the results of the ban
    * Unban functions will return nothing, but may contain error-handling messages that can be passed to $logger->warning()
    */
    public function ban(array $array, $message = null): string
    {
        if ($member = $this->getVerifiedMember($array[0]))
            if (! $member->roles->has($this->role_ids['banished']))
                $member->addRole($this->role_ids['banished'], "Banned for {$array[1]} with the reason {$array[2]}");
        if ($this->legacy) return $this->legacyBan($array, $message);
        return $this->sqlBan($array, $message);
    }
    public function banNomads(array $array, $message = null): string
    {
        if ($this->legacy) return $this->legacyBanNomads($array, $message);
        return $this->sqlBanTDM($array, $message);
    }
    public function banTDM(array $array, $message = null): string
    {
        if ($this->legacy) return $this->legacyBanTDM($array, $message);
        return $this->sqlBanTDM($array, $message);
    }
    public function banPers(array $array, $message = null): string
    {
        if ($this->legacy) return $this->legacyBanPers($array, $message);
        return $this->sqlBanPers($array, $message);
    }
    public function unban(string $ckey, ?string $admin = null): void
    {
        if (! $admin) $admin = $this->discord->user->displayname;
        if ($this->legacy) {
            $this->legacyUnbanNomads($ckey, $admin);
            $this->legacyUnbanTDM($ckey, $admin);
        } else {
            $this->sqlUnbanNomads($ckey, $admin);
            $this->sqlUnbanTDM($ckey, $admin);
        }
        if ( $member = $this->getVerifiedMember($ckey))
            if ($member->roles->has($this->role_ids['banished']))
                $member->removeRole($this->role_ids['banished'], "Unbanned by $admin");
    }
    public function unbanNomads(string $ckey, ?string $admin = null)
    {
        if ($this->legacy) return $this->legacyUnbanNomads($ckey, $admin);
        return $this->sqlUnbanNomads($ckey, $admin);
    }
    public function unbanTDM(string $ckey, ?string $admin = null)
    {
        if ($this->legacy) return $this->legacyUnbanTDM($ckey, $admin);
        return $this->sqlUnbanTDM($ckey, $admin);
    }
    public function unbanPers(string $ckey, ?string $admin = null)
    {
        if ($this->legacy) return $this->legacyUnbanPers($ckey, $admin);
        return $this->sqlUnbanPers($ckey, $admin);
    }
    
    public function DirectMessageNomads($author, $string): bool
    {
        if (! file_exists($this->files['nomads_discord2dm']) || ! $file = fopen($this->files['nomads_discord2dm'], 'a')) return false;
        fwrite($file, "$author:::$string" . PHP_EOL);
        fclose($file);
        return true;
    }
    public function DirectMessageTDM($author, $string): bool
    {
        if (! file_exists($this->files['tdm_discord2dm']) || ! $file = fopen($this->files['tdm_discord2dm'], 'a')) return false;
        fwrite($file, "$author:::$string" . PHP_EOL);
        fclose($file);
        return true;
    }

    /*
    * This function defines the IPs and ports of the servers
    * It is called on ready
    * TODO: Move definitions into config/constructor?
    */
    public function setIPs(): void
    {
        $vzg_ip = gethostbyname('www.valzargaming.com');
        $external_ip = file_get_contents('http://ipecho.net/plain');
        $this->ips = [
            'nomads' => $external_ip,
            'tdm' => $external_ip,
            'pers' => $vzg_ip,
            'vzg' => $vzg_ip,
        ];
        $this->ports = [
            'nomads' => '1715',
            'tdm' => '1714',
            'pers' => '1716',
            'bc' => '7777', 
            'ps13' => '7778',
        ];
    }
    
    /*
    * This function returns the current ckeys playing on the servers as stored in the cache
    * It returns an array of ckeys or an empty array if the cache is empty
    */
    public function serverinfoPlayers(): array
    { 
        if (empty($data_json = $this->serverinfo)) return [];
        $this->players = [];
        foreach ($data_json as $server) {
            if (array_key_exists('ERROR', $server)) continue;
            foreach (array_keys($server) as $key) {
                $p = explode('player', $key); 
                if (isset($p[1]) && is_numeric($p[1])) $this->players[] = str_replace(['.', '_', ' '], '', strtolower(urldecode($server[$key])));
            }
        }
        return $this->players;
    }
    public function serverinfoFetch(): array
    {
        if (! $data_json = json_decode(file_get_contents("http://{$this->ips['vzg']}/servers/serverinfo.json", false, stream_context_create(array('http'=>array('timeout' => 5, )))),  true)) return [];
        return $this->serverinfo = $data_json;
    }
    public function bansToCollection(): Collection
    {
        // Get the contents of the file
        $file_contents = '';
        if (file_exists($this->files['tdm_bans'])) $file_contents .= file_get_contents($this->files['tdm_bans']);
        if (file_exists($this->files['nomads_bans'])) $file_contents .= file_get_contents($this->files['nomads_bans']);
        if (file_exists($this->files['pers_bans'])) $file_contents .= file_get_contents($this->files['pers_bans']);
        $file_contents = str_replace(PHP_EOL, '', $file_contents);
        
        $ban_collection = new Collection([], 'uid');
        foreach (explode('|||', $file_contents) as $item)
            if ($ban = $this->banArrayToAssoc(explode(';', $item)))
                $ban_collection->pushItem($ban);
        return $ban_collection;
    }
    /*
    * Creates a Collection from the bans file
    * Player logs are formatting by the following:
    *   0 => Ban Type
    *   1 => Job
    *   2 => Ban UID
    *   3 => Reason
    *   4 => Banning admin
    *   5 => Date when banned
    *   6 => timestamp?
    *   7 => when expires
    *   8 => banned ckey
    *   9 => banned cid
    *   10 => ip
    */
    public function banArrayToAssoc(array $item)
    {
        // Invalid item format
        if (count($item) !== 11) return null;

        // Create a new ban record
        $ban = [];
        $ban['type'] = $item[0];
        $ban['job'] = $item[1];
        $ban['uid'] = $item[2];
        $ban['reason'] = $item[3];
        $ban['admin'] = $item[4];
        $ban['date'] = $item[5];
        $ban['timestamp'] = $item[6];
        $ban['expires'] = $item[7];
        $ban['ckey'] = $item[8];
        $ban['cid'] = $item[9];
        $ban['ip'] = $item[10];

        // Add the ban record to the collection
        return $ban;
    }
    public function playerlogsToCollection(): Collection
    {
        // Get the contents of the file
        $file_contents = '';
        if (file_exists($this->files['nomads_playerlogs'])) $file_contents .= file_get_contents($this->files['nomads_playerlogs']);
        if (file_exists($this->files['tdm_playerlogs'])) $file_contents .= file_get_contents($this->files['tdm_playerlogs']);
        if (file_exists($this->files['pers_playerlogs'])) $file_contents .= file_get_contents($this->files['pers_playerlogs']);
        $file_contents = str_replace(PHP_EOL, '', $file_contents);

        $arrays = [];
        foreach (explode('|', $file_contents) as $item) {
            if ($log = $this->playerlogArrayToAssoc(explode(';', $item)))
                $arrays[] = $log;
        }
        return new Collection($arrays, 'uid');
    }
   /*
    * Creates a Collection from the playerlogs file
    * Player logs are formatting by the following:
    *   0 => Ckey
    *   1 => IP
    *   2 => CID
    *   3 => UID?
    *   4 => Date
    */
    public function playerlogArrayToAssoc(array $item)
    {
        // Invalid item format
        if (count($item) !== 5) return null;

        // Create a new ban record
        $playerlog = [];
        $playerlog['ckey'] = $item[0];
        $playerlog['ip'] = $item[1];
        $playerlog['cid'] = $item[2];
        $playerlog['uid'] = $item[3];
        $playerlog['date'] = $item[4];

        // Add the ban record to the collection
        return $playerlog;
    }
    public function getCkeyLogCollections(string $ckey): ?array
    {
        if ($playerlog = $this->playerlogsToCollection()->filter( function($item) use ($ckey) { return $item['ckey'] === $ckey; }))
            if ($bans = $this->bansToCollection()->filter(function($item) use ($playerlog) { return $playerlog->get('ckey', $item['ckey']) || $playerlog->get('ip', $item['ip']) || $playerlog->get('cid', $item['cid']); }));
                return [$playerlog, $bans];
    }
    /*
    *
    * @return array[array, array, array, bool, bool, bool]
    */
    public function byondinfo(string $ckey): array
    {
        if (! $ckey = str_replace(['.', '_', ' '], '', trim($ckey))) return [null, null, null, false, false];
        if (! $collectionsArray = $this->getCkeyLogCollections($ckey)) return [null, null, null, false, false];
        if ($item = $this->getVerifiedItem($ckey)) $ckey = $item['ss13'];
        //var_dump('Ckey Collections Array: ', $collectionsArray, PHP_EOL);
        
        $ckeys = [$ckey];
        $ips = [];
        $cids = [];
        foreach ($collectionsArray[0] as $log) { //Get the ckey's primary identifiers
            if (isset($log['ip'])) $ips[] = $log['ip'];
            if (isset($log['cid'])) $cids[] = $log['cid'];
        }
        foreach ($collectionsArray[1] as $log) { //Get the ckey's primary identifiers
            if (isset($log['ip']) && !in_array($log['ip'], $ips)) $ips[] = $log['ip'];
            if (isset($log['cid']) && !in_array($log['cid'], $ips)) $cids[] = $log['cid'];
        }
        //var_dump('Searchable: ',  $ckeys, $ips, $cids, PHP_EOL);
        //Iterate through the playerlogs ban logs to find all known ckeys, ips, and cids
        $playerlogs = $this->playerlogsToCollection();
        $i = 0;
        $break = false;
        do { //Iterate through playerlogs to find all known ckeys, ips, and cids
            $found = false;
            $found_ckeys = [];
            $found_ips = [];
            $found_cids = [];
            foreach ($playerlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                //$this->logger->debug('Found new match: ', $log, PHP_EOL);
                if (!in_array($log['ckey'], $ckeys)) { $found_ckeys[] = $log['ckey']; $found = true; }
                if (!in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                if (!in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
            }
            $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
            $ips = array_unique(array_merge($ips, $found_ips));
            $cids = array_unique(array_merge($cids, $found_cids));
            if ($i > 10) $break = true;
            $i++;
        } while ($found && ! $break); //Keep iterating until no new ckeys, ips, or cids are found
    
        $banlogs = $this->bansToCollection();        
        $found = true;
        $break = false;
        $i = 0;
        do { //Iterate through playerlogs to find all known ckeys, ips, and cids
            $found = false;
            $found_ckeys = [];
            $found_ips = [];
            $found_cids = [];
            foreach ($banlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                if (!in_array($log['ckey'], $ips)) { $found_ckeys[] = $log['ckey']; $found = true; }
                if (!in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                if (!in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
            }
            $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
            $ips = array_unique(array_merge($ips, $found_ips));
            $cids = array_unique(array_merge($cids, $found_cids));
            $i++;
            if ($i > 10) $break = true;
        } while ($found && ! $break); //Keep iterating until no new ckeys, ips, or cids are found

        $altbanned = false;
        foreach ($ckeys as $key) if ($key != $ckey) if ($this->bancheck($key)) { $altbanned = true; break; }
        $verified = false;
        if ($this->verified->get('ss13', $ckey)) $verified = true;
        return [$ckeys, $ips, $cids, $this->bancheck($ckey), $altbanned, $verified];
    }
    public function serverinfoTimer(): void
    {
        $func = function() {
            $this->serverinfoFetch(); 
            $this->serverinfoParsePlayers();
            foreach ($this->serverinfoPlayers() as $ckey) {
                if (!in_array($ckey, $this->seen_players) && ! isset($this->permitted[$ckey])) {
                    $this->seen_players[] = $ckey;
                    $byondinfo = $this->byondinfo($ckey); //Automatically ban evaders
                    if (! $byondinfo[3] && $byondinfo[4])
                        $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage(($this->ban([$ckey, '999 years', 'Account under under investigation. '])));
                }
                if ($this->verified->get('ss13', $ckey)) continue;
                if ($this->panic_bunker || ($this->serverinfo[1]['admins'] == 0 && $this->serverinfo[1]['vote'] == 0)) return $this->panicBan($ckey);
                if (isset($this->ages[$ckey])) continue;
                if (! $this->checkByondAge($age = $this->getByondAge($ckey)) && ! isset($this->permitted[$ckey]))
                    $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage($this->ban([$ckey, '999 years', "Byond account $ckey does not meet the requirements to be approved. ($age)"]));
            }
        };
        $func();
        $this->timers['serverinfo_timer'] = $this->discord->getLoop()->addPeriodicTimer(60, function() use ($func) { $func(); });
    }
    /*
    * This function parses the serverinfo data and updates the relevant Discord channel name with the current player counts
    * Prefix is used to differentiate between two different servers, however it cannot be used with more due to ratelimits on Discord
    * It is called on ready and every 5 minutes
    */
    private function playercountChannelUpdate(int $count = 0, string $prefix = '')
    {
        if ($this->playercount_ticker++ % 10 !== 0) return;
        if (! $channel = $this->discord->getChannel($this->channel_ids[$prefix . 'playercount'])) return;
    
        [$channelPrefix, $existingCount] = explode('-', $channel->name);
    
        if ((int)$existingCount !== $count) {
            $channel->name = "{$channelPrefix}-{$count}";
            $channel->guild->channels->save($channel);
        }
    }
    public function serverinfoParse(): array
    {
        if (empty($this->serverinfo)) return [];
    
        $server_info = [
            ['name' => 'TDM', 'host' => 'Taislin', 'link' => "<byond://{$this->ips['tdm']}:{$this->ports['tdm']}>", 'prefix' => 'tdm-'],
            ['name' => 'Nomads', 'host' => 'Taislin', 'link' => "<byond://{$this->ips['nomads']}:{$this->ports['nomads']}>", 'prefix' => 'nomads-'],
            ['name' => 'Persistence', 'host' => 'ValZarGaming', 'link' => "<byond://{$this->ips['pers']}:{$this->ports['pers']}>", 'prefix' => 'persistence-'],
            ['name' => 'Blue Colony', 'host' => 'ValZarGaming', 'link' => "<byond://{$this->ips['vzg']}:{$this->ports['bc']}>", 'prefix' => 'bc-'],
            ['name' => 'Pocket Stronghold 13', 'host' => 'ValZarGaming', 'link' => "<byond://{$this->ips['vzg']}:{$this->ports['ps13']}>", 'prefix' => 'ps-'],
        ];
    
        $return = [];
        foreach ($this->serverinfo as $index => $server) {
            $si = array_shift($server_info);
            $return[$index]['Server'] = [false => $si['name'] . PHP_EOL . $si['link']];
            $return[$index]['Host'] = [true => $si['host']];
            if (array_key_exists('ERROR', $server)) {
                $return[$index] = [];
                continue;
            }
    
            if (isset($server['roundduration'])) {
                $rd = explode(":", urldecode($server['roundduration']));
                $days = floor($rd[0] / 24);
                $hours = $rd[0] % 24;
                $minutes = $rd[1];
                if ($days > 0) $rt = "{$days}d {$hours}h {$minutes}m";
                else if ($hours > 0) $rt = "{$hours}h {$minutes}m";
                else $rt = "{$minutes}m";
                $return[$index]['Round Timer'] = [true => $rt];
            }
            if (isset($server['map'])) $return[$index]['Map'] = [true => urldecode($server['map'])];
            if (isset($server['age'])) $return[$index]['Epoch'] = [true => urldecode($server['age'])];
            $players = array_filter(array_keys($server), function ($key) {
                return strpos($key, 'player') === 0 && is_numeric(substr($key, 6));
            });
            if (!empty($players)) {
                $players = array_map(function ($key) use ($server) {
                    return strtolower(str_replace(['.', '_', ' '], '', urldecode($server[$key])));
                }, $players);
                $playerCount = count($players);
            }
            elseif (isset($server['players'])) $playerCount = $server['players'];
            else $playerCount = '?';
    
            $return[$index]['Players (' . $playerCount . ')'] = [true => empty($players) ? 'N/A' : implode(', ', $players)];
    
            if (isset($server['season'])) $return[$index]['Season'] = [true => urldecode($server['season'])];
    
            if ($index <= 2) $this->playercountChannelUpdate(isset($server['players']) ? $server['players'] : count($players) ?? 0, $si['prefix']);
        }
    
        return $return;
    }

    public function serverinfoParsePlayers(): void
    {
        $server_info = [
            0 => ['name' => 'TDM', 'host' => 'Taislin', 'link' => "<byond://{$this->ips['tdm']}:{$this->ports['tdm']}>", 'prefix' => 'tdm-'],
            1 => ['name' => 'Nomads', 'host' => 'Taislin', 'link' => "<byond://{$this->ips['nomads']}:{$this->ports['nomads']}>", 'prefix' => 'nomads-'],
            2 => ['name' => 'Persistence', 'host' => 'ValZarGaming', 'link' => "<byond://{$this->ips['pers']}:{$this->ports['pers']}>", 'prefix' => 'persistence-'],
            3 => ['name' => 'Blue Colony', 'host' => 'ValZarGaming', 'link' => "<byond://{$this->ips['vzg']}:{$this->ports['bc']}>", 'prefix' => 'bc-'],
            4 => ['name' => 'Pocket Stronghold 13', 'host' => 'ValZarGaming', 'link' => "<byond://{$this->ips['vzg']}:{$this->ports['ps13']}>", 'prefix' => 'ps-']
        ];
        //$relevant_servers = array_filter($this->serverinfo, fn($server) => in_array($server['stationname'], ['TDM', 'Nomads', 'Persistence'])); //We need to declare stationname in world.dm first

        $index = 0;
        //foreach ($relevant_servers as $server) //TODO: We need to declare stationname in world.dm first
        foreach ($this->serverinfo as $server) {
            if (array_key_exists('ERROR', $server) || $index > 2) { //We only care about Nomads, TDM, and Persistence
                $index++; //TODO: Remove this once we have stationname in world.dm
                continue;
            }
            $this->playercountChannelUpdate(isset($server['players']) ? $server['players'] : count(array_map(fn($player) => str_replace(['.', '_', ' '], '', strtolower(urldecode($player))), array_filter($server, function($key) { return str_starts_with($key, 'player') && !str_starts_with($key, 'players'); }, ARRAY_FILTER_USE_KEY))), $server_info[$index]['prefix']);
            $index++; //TODO: Remove this once we have stationname in world.dm
        }
    }

    /*
    * This function takes a member and checks if they have previously been verified
    * If they have, it will assign them the appropriate roles
    */
    public function joinRoles($member): void
    {
        if ($member->guild_id == $this->civ13_guild_id) 
            if ($item = $this->verified->get('discord', $member->id)) {
                if ($this->bancheck($item['ss13'], true)) $member->setroles([$this->role_ids['infantry'], $this->role_ids['banished']], "bancheck join {$item['ss13']}");
                else $member->setroles([$this->role_ids['infantry']], "verified join {$item['ss13']}");
            }
    }
    /*
    * This function checks all Discord member's ckeys against the banlist
    * If they are no longer banned, it will remove the banished role from them
    */
    public function unbanTimer(): bool
    {
        //We don't want the persistence server to do this function
        if (! file_exists($this->files['nomads_bans']) || ! ($file = fopen($this->files['nomads_bans'], 'r'))) return false;
        fclose($file);
        if (! file_exists($this->files['tdm_bans']) || (! $file2 = fopen($this->files['tdm_bans'], 'r'))) return false;
        fclose($file2);

        $func = function() {
            if (isset($this->role_ids['banished']) && $guild = $this->discord->guilds->get('id', $this->civ13_guild_id))
                if ($members = $guild->members->filter(fn ($member) => $member->roles->has($this->role_ids['banished'])))
                    foreach ($members as $member) if ($item = $this->getVerifiedMemberItems()->get('discord', $member->id))
                        if (! $this->bancheck($item['ss13'], true)) {
                            $member->removeRole($this->role_ids['banished'], 'unban timer');
                            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $channel->sendMessage("Removed the banished role from $member.");
                        }
         };
         $func();
         $this->timers['unban_timer'] = $this->discord->getLoop()->addPeriodicTimer(43200, function() use ($func) { $func(); });
         return true;
    }

    /*
    * This function is used to change the bot's status on Discord
    */
    public function statusChanger($activity, $state = 'online'): void
    {
        $this->discord->updatePresence($activity, false, $state);
    }

    /*
    * These functions handle in-game chat moderation and relay those messages to Discord
    * Players will receive warnings and bans for using blacklisted words
    */
    
    /*
    * This function determines if a player has been warned too many times for a specific category of bad words
    * If they have, it will return false to indicate they should be banned
    * If they have not, it will return true to indicate they should be warned
    */
    private function relayWarningCounter(string $ckey, array $badwords_array): bool
    {
        if (!isset($this->badwords_warnings[$ckey][$badwords_array['category']])) $this->badwords_warnings[$ckey][$badwords_array['category']] = 1;
        else ++$this->badwords_warnings[$ckey][$badwords_array['category']];
        $this->VarSave('badwords_warnings.json', $this->badwords_warnings);
        if ($this->badwords_warnings[$ckey][$badwords_array['category']] > $this->badwords[$badwords_array['warnings']]) return false;
        return true;
    }
    // This function is called from the game's chat hook if a player says something that contains a blacklisted word
    private function relayViolation(string $file_path, string $ckey, array $badwords_array)
    {
        $filtered = substr($badwords_array['word'], 0, 1) . str_repeat('%', strlen($badwords_array['word'])-2) . substr($badwords_array['word'], -1, 1);
        if (! $this->relayWarningCounter($ckey, $badwords_array)) return $this->ban([$ckey, $badwords_array['duration'], "Blacklisted phrase ($filtered). Appeal at {$this->banappeal}"]);
        $warning = "You are currently violating a server rule. Further violations will result in an automatic ban that will need to be appealed on our Discord. Reason: {$badwords_array['reason']} ({$badwords_array['category']} => $filtered)";
        if ($channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $channel->sendMessage("`$ckey` is" . substr($warning, 7));
        if (str_starts_with($file_path, 'nomads')) return $this->DirectMessageNomads($ckey, $warning);
        if (str_starts_with($file_path, 'tdm')) return $this->DirectMessageTDM($ckey, $warning);
    }
    public function gameChatRelay(string $file_path, $channel): bool
    {     
        if (! file_exists($file_path) || ! ($file = @fopen($file_path, 'r+'))) return false;
        while (($fp = fgets($file, 4096)) !== false) {
            $fp = html_entity_decode(str_replace(PHP_EOL, '', $fp));
            $string = substr($fp, strpos($fp, '/')+1);
            $ckey = substr($string, 0, strpos($string, ':'));
            foreach ($this->badwords as $badwords_array) switch ($badwords_array['method']) {
                case 'exact': //ban ckey if $string contains a blacklisted phrase exactly as it is defined
                    if (preg_match("\b{$badwords_array['word']}\b", $string)) $this->relayViolation($file_path, $ckey, $badwords_array);
                    break;
                case 'contains': //ban ckey if $string contains a blacklisted word
                default: //default to 'contains'
                    if (str_contains(strtolower($string), $badwords_array['word'])) $this->relayViolation($file_path, $ckey, $badwords_array);
            }
            if (! $item = $this->verified->get('ss13', strtolower(str_replace(['.', '_', ' '], '', $ckey)))) $channel->sendMessage($fp);
            else {
                $embed = new Embed($this->discord);
                if ($user = $this->discord->users->get('id', $item['discord'])) $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
                //else $this->discord->users->fetch('id', $item['discord']); //disabled to prevent rate limiting
                $embed->setDescription($fp);
                $channel->sendEmbed($embed);
            }
        }
        ftruncate($file, 0); //clear the file
        fclose($file);
        return true;
    }

    /*
    * This function calculates the player's ranking based on their medals
    * Returns true if the required files are successfully read, false otherwise
    */
    public function recalculateRanking(): bool
    {
        if (! isset($this->files['tdm_awards_path'])) return false;
        if (! isset($this->files['ranking_path'])) return false;

        if (! file_exists($this->files['tdm_awards_path']) || ! ($search = fopen($this->files['tdm_awards_path'], 'r'))) return false;
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
        if (! file_exists($this->files['ranking_path']) || ! ($search = fopen($this->files['ranking_path'], 'w'))) return false;
        foreach ($result as $ckey => $score) fwrite($search, "$score;$ckey" . PHP_EOL); //Is this the proper behavior, or should we truncate the file first?
        fclose ($search);
        return true;
    }

    /*
    * This function is used to update the whitelist files
    * Returns true if the whitelist files are successfully updated, false otherwise
    */
    public function whitelistUpdate(array $whitelists = []): bool
    {
        if (! isset($this->role_ids['veteran'])) return false;
        if (isset($this->files['nomads_whitelist']) && !in_array($whitelists, $this->files['nomads_whitelist'])) array_unshift($whitelists, $this->files['nomads_whitelist']);
        if (isset($this->files['tdm_whitelist']) && !in_array($whitelists, $this->files['tdm_whitelist'])) array_unshift($whitelists, $this->files['tdm_whitelist']);
        if (empty($whitelists)) return false;
        foreach ($whitelists as $whitelist) {
            if (! file_exists($whitelist) || ! ($file = fopen($whitelist, 'a'))) return false;
            ftruncate($file, 0);
            foreach ($this->verified as $item) {
                if (! $member = $this->getVerifiedMember($item)) continue;
                if (! $member->roles->has($this->role_ids['veteran'])) continue;
                fwrite($file, "{$item['ss13']} = {$item['discord']}" . PHP_EOL);
            }
            fclose($file);
        }
        return true;
    }
    /*
    * This function is used to update the campaign whitelist files
    * Returns true if the whitelist files are successfully updated, false otherwise
    * If an additional whitelist is provided, it will be added to the list of whitelists to update
    */
    public function factionlistUpdate(array $factionlists = []): bool
    {
        if (! (isset($this->role_ids['red'], $this->role_ids['blue']))) return false;
        if (isset($this->files['tdm_factionlist']) && !in_array($this->files['tdm_factionlist'], $factionlists)) array_unshift($factionlists, $this->files['tdm_factionlist']);
        if (isset($this->files['nomads_factionlist']) && !in_array($this->files['nomads_factionlist'], $factionlists)) array_unshift($factionlists, $this->files['tdm_factionlist']);
        if (empty($factionlists)) return false;
        foreach ($factionlists as $factionlist) {
            if (! file_exists($factionlist) || ! ($file = @fopen($factionlist, 'a'))) continue;
            ftruncate($file, 0);
            foreach ($this->verified as $item) {
                if (! $member = $this->getVerifiedMember($item)) continue;
                if ($member->roles->has($this->role_ids['red'])) fwrite($file, "{$item['ss13']};red" . PHP_EOL);
                if ($member->roles->has($this->role_ids['blue'])) fwrite($file, "{$item['ss13']};blue" . PHP_EOL);
            }
            fclose($file);
        }
        return true;
    }

    /*
    * This function is used to update the adminlist files
    * Returns true if the adminlist files are successfully updated, false otherwise
    * If an additional adminlist is provided, it will be added to the list of adminlists to update
    */
    public function adminlistUpdate(array $adminlists = [], $defaults = true): bool
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) { $this->logger->error('Guild ' . $this->civ13_guild_id . ' is missing from the bot'); return false; }
        //$this->logger->debug('Updating admin lists');
        // Prepend default admin lists if they exist and haven't been added already
        $defaultLists = ['tdm_admins', 'nomads_admins'];
        if ($defaults) foreach ($defaultLists as $adminlist) if (isset($this->files[$adminlist]) && !in_array($adminlist, $adminlists))
            array_unshift($adminlists, $adminlist);

        // Check that all required roles are properly declared in the bot's config and exist in the guild
        $required_roles = [
            'admiral' => ['Host', '65535'],
            'bishop' => ['Bishop', '65535'],
            'host' => ['Host', '65535'], //Default Host permission, only used if another role is not found first
            'grandmaster' => ['GrandMaster', '16382'],
            'marshall' => ['Marshall', '16382'],
            'knightcommander' => ['KnightCommander', '16382'],
            'captain' => ['Captain', '16382'], //Default High Staff permission, only used if another role is not found first
            'storyteller' => ['StoryTeller', '16254'],
            'squire' => ['Squire', '8708'], //Squires will also have the Knight role, but it takes priority
            'knight' => ['Knight', '12158'],
            'mentor' => ['Mentor', '16384'],
        ];
        // If any required roles are missing, return false
        if ($diff = array_diff(array_keys($required_roles), array_keys($this->role_ids))) { $this->logger->error('Required roles are missing from the bot\'s config'); var_dump($diff); return false; }
        foreach (array_keys($required_roles) as $role) if (!isset($this->role_ids[$role]) || ! $guild->roles->get('id', $this->role_ids[$role])) { $this->logger->error("$role role is missing from the guild"); return false; }
        
        // Write each verified member's SS13 ckey and associated role with its bitflag permission to the adminlist file
        foreach ($adminlists as $adminlist) {
            if (! file_exists($this->files[$adminlist]) || ! ($file = fopen($this->files[$adminlist], 'a'))) continue; // If the file cannot be opened, skip to the next admin list
            ftruncate($file, 0);
            $file_contents = '';
            foreach ($this->verified as $item) if ($member = $this->getVerifiedMember($item)) foreach (array_keys($required_roles) as $role) if ($member->roles->has($this->role_ids[$role]))
                { $file_contents .= $item['ss13'] . ';' . $required_roles[$role][0] . ';' . $required_roles[$role][1] . '|||' . PHP_EOL; break 1; }
            fwrite($file, $file_contents);
            fclose($file);
        }
        //$this->logger->debug('Admin lists updated');
        return true;
    }
}