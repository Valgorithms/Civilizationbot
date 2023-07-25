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
use Discord\Parts\Channel\Channel;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Role;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Promise\Promise;
use React\Socket\SocketServer;
use React\EventLoop\TimerInterface;
use React\Filesystem\Factory as FilesystemFactory;

class Civ13
{

    public Slash $slash;
    public $vzg_ip = '';
    public $civ13_ip = '';
    public $external_ip = '';

    public StreamSelectLoop $loop;
    public Discord $discord;
    public bool $ready = false;
    public Browser $browser;
    public $filesystem;
    public Logger $logger;
    public $stats;

    public $filecache_path = '';
    
    protected HttpServer $webapi;
    protected SocketServer $socket;
    
    public collection $verified; // This probably needs a default value for Collection, maybe make it a Repository instead?
    public collection $pending;
    public array $provisional = []; // Allow provisional registration if the website is down, then try to verify when it comes back up
    public array $paroled = []; // List of ckeys that are no longer banned but have been paroled
    public array $ages = []; // $ckey => $age, temporary cache to avoid spamming the Byond REST API, but we don't want to save it to a file because we also use it to check if the account still exists
    public string $minimum_age = '-21 days'; // Minimum age of a ckey
    public array $permitted = []; // List of ckeys that are permitted to use the verification command even if they don't meet the minimum account age requirement or are banned with another ckey
    public array $blacklisted_regions = ['77.124', '77.125', '77.126', '77.127', '77.137.', '77.138.', '77.139.', '77.238.175', '77.91.69', '77.91.71', '77.91.74', '77.91.79', '77.91.88'];
    public array $blacklisted_countries = ['IL', 'ISR'];

    public array $timers = [];
    public array $serverinfo = []; // Collected automatically by serverinfo_timer
    public array $players = []; // Collected automatically by serverinfo_timer
    public array $seen_players = []; // Collected automatically by serverinfo_timer
    public int $playercount_ticker = 0;

    public array $current_rounds = [];
    public array $rounds = [];

    public array $server_settings = ['TDM' => [], 'Nomads' => []]; // NYI, this will replace most individual variables
    public string $relay_method = 'webhook'; // Method to use for relaying messages to Discord, either 'webhook' or 'file'
    public bool $moderate = true; // Whether or not to moderate the servers using the badwords list
    public array $badwords = [
        /* Format:
            'word' => 'bad word' // Bad word to look for
            'duration' => duration ['1 minute', '1 hour', '1 day', '1 week', '1 month', '999 years'] // Duration of the ban
            'reason' => 'reason' // Reason for the ban
            'category' => rule category ['racism/discrimination', 'toxic', 'advertisement'] // Used to group bad words together by category
            'method' => detection method ['exact', 'contains'] // Exact ignores partial matches, contains matches partial matchesq
            'warnings' => 1 // Number of warnings before a ban
        */
        ['word' => 'badwordtestmessage', 'duration' => '1 minute', 'reason' => 'Violated server rule.', 'category' => 'test', 'method' => 'contains', 'warnings' => 1], // Used to test the system
        
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
        
        ['word' => 'cunt', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 5],
        ['word' => 'fuck you', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 5],
        ['word' => 'retard', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 5],
        ['word' => 'kys', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 1], // This is more severe than the others, so ban after only one warning
        
        ['word' => 'discord.gg', 'duration' => '999 years', 'reason' => 'You must not post unauthorized Discord invitation links in any OOC communication channels.', 'category' => 'advertisement', 'method' => 'contains', 'warnings' => 2],
        ['word' => 'discord.com', 'duration' => '999 years', 'reason' => 'You must not post unauthorized Discord invitation links in any OOC communication channels.', 'category' => 'advertisement', 'method' => 'contains', 'warnings' => 2],
    ];
    public array $badwords_warnings = []; // Array of [$ckey]['category'] => integer] for how many times a user has recently infringed for a specific category
    public bool $legacy = true; // If true, the bot will use the file methods instead of the SQL ones
    
    public $functions = array(
        'ready' => [],
        'ready_slash' => [],
        'messages' => [],
        'misc' => [],
    );
    public $server_funcs_called = []; // List of functions that are called when their respective command is called
    public $server_funcs_uncalled = []; // List of functions that are available for use by other functions, but otherwise not called via a message command
    
    public string $command_symbol = '@Civilizationbot'; // The symbol that the bot will use to identify commands if it is not mentioned
    public string $owner_id = '196253985072611328'; // Taislin's Discord ID
    public string $technician_id = '116927250145869826'; // Valithor Obsidion's Discord ID
    public string $embed_footer = ''; // Footer for embeds, this is set in the ready event
    public string $civ13_guild_id = '468979034571931648'; // Guild ID for the Civ13 server
    public string $verifier_feed_channel_id = '1032411190695055440'; // Channel where the bot will listen for verification notices and then update its verified cache accordingly
    public string $civ_token = ''; // Token for use with $verify_url, this is not the same as the bot token and should be kept secret

    public string $github = 'https://github.com/VZGCoders/Civilizationbot'; // Link to the bot's github page
    public string $banappeal = 'civ13.com slash discord'; // Players can appeal their bans here (cannot contain special characters like / or &, blame the current Python implementation)
    public string $rules = 'civ13.com slash rules'; // Link to the server rules
    public string $verify_url = 'http://valzargaming.com:8080/verified/'; // Where the bot submit verification of a ckey to and where it will retrieve the list of verified ckeys from
    public string $serverinfo_url = ''; // Where the bot will retrieve server information from
    public bool $webserver_online = false;
    
    public array $folders = [];
    public array $files = [];
    public array $ips = [];
    public array $ports = [];
    public array $channel_ids = [];
    public array $role_ids = [];
    public array $permissions = []; // NYI, used to store rank_check array for each command
    
    public array $discord_config = []; // This variable and its related function currently serve no purpose, but I'm keeping it in case I need it later
    public array $tests = []; // Staff application test templates
    public bool $panic_bunker = false; // If true, the bot will server ban anyone who is not verified when they join the server
    public array $panic_bans = []; // List of ckeys that have been banned by the panic bunker in the current runtime

    /**
     * Creates a Civ13 client instance.
     * 
     * @throws E_USER_ERROR
     */
    public function __construct(array $options = [], array $server_options = [])
    {
        if (php_sapi_name() !== 'cli') trigger_error('DiscordPHP will not run on a webserver. Please use PHP CLI to run a DiscordPHP bot.', E_USER_ERROR);

        // x86 need gmp extension for big integer operation
        if (PHP_INT_SIZE === 4 && ! BigInt::init()) trigger_error('ext-gmp is not loaded. Permissions will NOT work correctly!', E_USER_WARNING);
        
        $options = $this->resolveOptions($options);
        
        $this->loop = $options['loop'];
        $this->browser = $options['browser'];
        $this->filesystem = $options['filesystem'];
        $this->stats = $options['stats'];
        
        $this->filecache_path = getcwd() . '/json/';
        if (isset($options['filecache_path']) && is_string($options['filecache_path'])) {
            if (! str_ends_with($options['filecache_path'], '/')) $options['filecache_path'] .= '/';
            $this->filecache_path = $options['filecache_path'];
        }
        if (!file_exists($this->filecache_path)) mkdir($this->filecache_path, 0664, true);
        
        if (isset($options['command_symbol'])) $this->command_symbol = $options['command_symbol'];
        if (isset($options['owner_id'])) $this->owner_id = $options['owner_id'];
        if (isset($options['technician_id'])) $this->technician_id = $options['technician_id'];
        if (isset($options['verify_url'])) $this->verify_url = $options['verify_url'];
        if (isset($options['banappeal'])) $this->banappeal = $options['banappeal'];
        if (isset($options['rules'])) $this->rules = $options['rules'];
        if (isset($options['github'])) $this->github = $options['github'];
        if (isset($options['civ13_guild_id'])) $this->civ13_guild_id = $options['civ13_guild_id'];
        if (isset($options['verifier_feed_channel_id'])) $this->verifier_feed_channel_id = $options['verifier_feed_channel_id'];
        if (isset($options['civ_token'])) $this->civ_token = $options['civ_token'];
        if (isset($options['serverinfo_url'])) $this->serverinfo_url = $options['serverinfo_url'];
        if (isset($options['legacy']) && is_bool($options['legacy'])) $this->legacy = $options['legacy'];
        if (isset($options['relay_method'])) {
            if (is_string($options['relay_method'])) {
                $relay_method = strtolower($options['relay_method']);
                if (in_array($relay_method, ['file', 'webhook']))
                    $this->relay_method = $relay_method;
            }
        }
        if (isset($options['moderate']) && is_bool($options['moderate'])) $this->moderate = $options['moderate'];
        if (isset($options['badwords']) && is_array($options['badwords'])) $this->badwords = $options['badwords'];

        if (isset($options['minimum_age']) && is_string($options['minimum_age'])) $this->minimum_age = $options['minimum_age'];
        if (isset($options['blacklisted_regions']) && is_array($options['blacklisted_regions'])) $this->blacklisted_regions = $options['blacklisted_regions'];
        if (isset($options['blacklsited_countries']) && is_array($options['blacklisted_countries'])) $this->blacklisted_countries = $options['blacklisted_countries'];
                
        if (isset($options['discord']) && ($options['discord'] instanceof Discord)) $this->discord = $options['discord'];
        elseif (isset($options['discord_options']) && is_array($options['discord_options'])) $this->discord = new Discord($options['discord_options']);
        else $this->logger->error('No Discord instance or options passed in options!');
        require 'slash.php';
        $this->slash = new Slash($this);
        
        if (isset($options['functions'])) foreach (array_keys($options['functions']) as $key1) foreach ($options['functions'][$key1] as $key2 => $func) $this->functions[$key1][$key2] = $func;
        else $this->logger->warning('No functions passed in options!');
        
        if (isset($options['files'])) foreach ($options['files'] as $key => $path) $this->files[$key] = $path;
        else $this->logger->warning('No files passed in options!');
        if (isset($options['channel_ids'])) foreach ($options['channel_ids'] as $key => $id) $this->channel_ids[$key] = $id;
        else $this->logger->warning('No channel_ids passed in options!');
        if (isset($options['role_ids'])) foreach ($options['role_ids'] as $key => $id) $this->role_ids[$key] = $id;
        else $this->logger->warning('No role_ids passed in options!');

        if (isset($options['server_settings']) && is_array($options['server_settings'])) $this->server_settings = $options['server_settings'];
        else $this->logger->warning('No server_settings passed in options!');
        $this->generateServerFunctions();
        $this->afterConstruct($options, $server_options);
    }
    
    // Generate a list of functions derived by the keys found in server_settings
    // The key is the name of the command, and the value is the function to call
    protected function generateServerFunctions() {
        $rank_check = function($message, array $allowed_ranks = [], bool $verbose = true): bool
        {
            $resolved_ranks = [];
            foreach ($allowed_ranks as $rank) $resolved_ranks[] = $this->role_ids[$rank];
            foreach ($message->member->roles as $role) if (in_array($role->id, $resolved_ranks)) return true;
            if ($verbose && $message) $message->reply('Rejected! You need to have at least the <@&' . $this->role_ids[array_pop($allowed_ranks)] . '> rank.');
            return false;
        };

        foreach (array_keys($this->server_settings) as $key) {
            $server = strtolower($key);

            foreach (['_updateserverabspaths', '_serverdata', '_killsudos', '_dmb'] as $postfix) {
                if (! $this->getRequiredConfigFiles($postfix, true)) $this->logger->debug("Skipping server function `$server{$postfix}` because the required config files were not found.");
                else {
                    $serverhost = function() use ($server): void
                    {
                        \execInBackground("python3 {$this->files[$server.'_updateserverabspaths']}");
                        \execInBackground("rm -f {$this->files[$server.'_serverdata']}");
                        \execInBackground("python3 {$this->files[$server.'_killsudos']}");
                        $this->discord->getLoop()->addTimer(30, function() use ($server) {
                            \execInBackground("DreamDaemon {$this->files[$server.'_dmb']} {$this->ports[$server]} -trusted -webclient -logself &");
                        });
                    };
                    $this->server_funcs_called[$server.'host'] = $serverhost;
                }
            }
            foreach (['_killciv13'] as $postfix) {
                if (! $this->getRequiredConfigFiles($postfix, true)) $this->logger->debug("Skipping server function `$server{$postfix}` because the required config files were not found.");
                else {
                    $serverkill = function() use ($server): void
                    {
                        \execInBackground("python3 {$this->files[$server.'_killciv13']}");
                    };
                    $this->server_funcs_called[$server.'kill'] = $serverkill;
                }
            }
            if (isset($this->server_funcs_called[$server.'host'], $this->server_funcs_called[$server.'kill'])) {
                $serverrestart = function() use ($server): void
                {
                    $this->server_funcs_called[$server.'kill']();
                    $this->server_funcs_called[$server.'host']();
                };
                $this->server_funcs_called[$server.'restart'] = $serverrestart;
            }


            foreach (['_mapswap'] as $postfix) {
                if (! $this->getRequiredConfigFiles($postfix, true)) $this->logger->debug("Skipping server function `$server{$postfix}` because the required config files were not found.");
                else {
                    $servermapswap = function($message, array $message_filtered) use ($server, $rank_check): Promise
                    {
                        $mapswap = function(string $mapto) use ($server): bool
                        {
                            if (! file_exists($this->files['map_defines_path']) || ! $file = @fopen($this->files['map_defines_path'], 'r')) {
                                $this->logger->error("unable to open `{$this->files['map_defines_path']}` for reading.");
                                return false;
                            }
                        
                            $maps = array();
                            while (($fp = fgets($file, 4096)) !== false) {
                                $linesplit = explode(' ', trim(str_replace('"', '', $fp)));
                                if (isset($linesplit[2]) && $map = trim($linesplit[2])) $maps[] = $map;
                            }
                            fclose($file);
                            if (! in_array($mapto, $maps)) return false;
                            
                            \execInBackground("python3 {$this->files[$server.'_mapswap']} $mapto");
                            return true;
                        };
                        if (! $rank_check($message, ['admiral', 'captain'])) return $message->react("❌");
                        $split_message = explode($server.'mapswap ', $message_filtered['message_content']);
                        if (count($split_message) < 2 || !($mapto = strtoupper($split_message[1]))) return $message->reply('You need to include the name of the map.');
                        if (! $mapswap($mapto)) return $message->reply("`$mapto` was not found in the map definitions.");
                        return $message->reply("Attempting to change `$server` map to `$mapto`");
                    };
                    $this->server_funcs_called[$server.'mapswap'] = $servermapswap;
                }
            }
            
            foreach (['_discord2ooc'] as $postfix) {
                if (! $this->getRequiredConfigFiles($postfix, true)) $this->logger->debug("Skipping server function `$server{$postfix}` because the required config files were not found.");
                else {
                    $serverdiscord2ooc = function(string $author, string $string) use ($server): bool
                    {
                        if (! file_exists($this->files[$server.'_discord2ooc']) || ! $file = @fopen($this->files[$server.'_discord2ooc'], 'a')) {
                            $this->logger->error("unable to open `{$this->files[$server.'_discord2ooc']}` for writing.");
                            return false;
                        }
                        fwrite($file, "$author:::$string" . PHP_EOL);
                        fclose($file);
                        return true; 
                    };
                    $this->server_funcs_uncalled[$server.'_discord2ooc'] = $serverdiscord2ooc;
                }
            }

            foreach (['_discord2admin'] as $postfix) {
                if (! $this->getRequiredConfigFiles($postfix, true)) $this->logger->debug("Skipping server function `$server{$postfix}` because the required config files were not found.");
                else {
                    $serverdiscord2admin = function(string $author, string $string) use ($server): bool
                    {
                        if (! file_exists($this->files[$server.'_discord2admin']) || ! $file = @fopen($this->files[$server.'_discord2admin'], 'a')) {
                            $this->logger->error("unable to open `{$this->files[$server.'_discord2admin']}` for writing.");
                            return false;
                        }
                        fwrite($file, "$author:::$string" . PHP_EOL);
                        fclose($file);
                        return true;
                    };
                    $this->server_funcs_uncalled[$server.'_discord2admin'] = $serverdiscord2admin;
                }
            }

            if (! $this->hasRequiredConfigRoles(['banished'])) $this->logger->debug("Skipping server function `$server ban` because the required config roles were not found.");
            else {
                $serverban = function($message, array $message_filtered) use ($key, $rank_check): Promise
                {
                    if (! $rank_check($this, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
                    $message_content = substr($message_filtered['message_content'], strlen($key.'ban '));
                    $split_message = explode('; ', $message_content); // $split_target[1] is the target
                    if (! $split_message[0]) return $message->reply('Missing ban ckey! Please use the format `ban ckey; duration; reason`');
                    if (! $split_message[1]) return $message->reply('Missing ban duration! Please use the format `ban ckey; duration; reason`');
                    if (! $split_message[2]) return $message->reply('Missing ban reason! Please use the format `ban ckey; duration; reason`');
                    $result = $this->ban(['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->banappeal}"], $this->getVerifiedItem($message->author->id)['ss13'], null, $key);
                    if ($member = $this->getVerifiedMember('id', $split_message[0]))
                        if (! $member->roles->has($this->role_ids['banished']))
                            $member->addRole($this->role_ids['banished'], $result);
                    return $message->reply($result);
                };
                $this->server_funcs_called[$server.'ban '] = $serverban;

                $serverunban = function($message, array $message_filtered) use ($key, $rank_check): Promise
                {
                    if (! $rank_check($this, $message, ['admiral', 'captain', 'knight'])) return $message->react("❌");
                    if (is_numeric($ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($key.'unban'))))) {
                        if (! $item = $this->getVerifiedItem($ckey)) return $message->reply("No data found for Discord ID `$ckey`.");
                        $ckey = $item['ckey'];
                    }
                    
                    $this->unban($ckey, $admin = $this->getVerifiedItem($message->author->id)['ss13'], $key);
                    $result = "**$admin** unbanned **$ckey** from **$key**";
                    if ($member = $this->getVerifiedMember('id', $ckey))
                        if ($member->roles->has($this->role_ids['banished']))
                            $member->removeRole($this->role_ids['banished'], $result);
                    return $message->reply($result);
                };
                $this->server_funcs_called[$server.'unban '] = $serverunban;
            }
        }
    }

    public function filterMessage($message): array
    {
        if (! $message->guild || $message->guild->owner_id != $this->owner_id)  return ['message_content' => '', 'message_content_lower' => '', 'called' => false]; // Only process commands from a guild that Taislin owns

        $message_content = '';
        $prefix = $this->command_symbol ?? '@Civilizationbot';
        $called = false;
        if (str_starts_with($message->content, $call = $prefix . ' ')) { $message_content = trim(substr($message->content, strlen($call))); $called = true; }
        elseif (str_starts_with($message->content, $call = "<@!{$this->discord->id}>")) { $message_content = trim(substr($message->content, strlen($call))); $called = true; }
        elseif (str_starts_with($message->content, $call = "<@{$this->discord->id}>")) { $message_content = trim(substr($message->content, strlen($call))); $called = true; }
        return ['message_content' => $message_content, 'message_content_lower' => strtolower($message_content), 'called' => $called];
    }

    /*
    * This function is called after the constructor is finished.
    * It is used to load the files, start the timers, and start handling events.
    */
    protected function afterConstruct(array $options = [], array $server_options = [])
    {
        $this->vzg_ip = gethostbyname('www.valzargaming.com');
        $this->civ13_ip = gethostbyname('www.civ13.com');
        $this->external_ip = file_get_contents('http://ipecho.net/plain');

        if (isset($this->discord)) {
            $this->discord->once('ready', function () use ($options) {
                $this->ready = true;
                $this->logger->info("logged in as {$this->discord->user->displayname} ({$this->discord->id})");
                $this->logger->info('------');
                if (isset($options['webapi'], $options['socket'])) {
                    $this->logger->info('setting up HttpServer API');
                    $this->webapi = $options['webapi'];
                    $this->socket = $options['socket'];
                    $this->webapi->listen($this->socket);
                }

                $this->logger->info('------');
                if (! $tests = $this->VarLoad('tests.json')) $tests = [];
                $this->tests = $tests;
                if (! $rounds = $this->VarLoad('rounds.json')) {
                    $rounds = [];
                    $this->VarSave('rounds.json', $rounds);
                }
                $this->rounds = $rounds;
                if (! $current_rounds = $this->VarLoad('current_rounds.json')) {
                    $current_rounds = [];
                    $this->VarSave('current_rounds.json', $current_rounds);
                }
                $this->current_rounds = $current_rounds;
                // If the bot was restarted during a round, mark it as interrupted and do not continue tracking the current round
                if ($this->current_rounds) {
                    $updated = false;
                    foreach ($this->current_rounds as $server => $game_id) if (isset($this->rounds[$server]) && isset($this->rounds[$server][$game_id])) {
                        $this->rounds[$server][$game_id]['interrupted'] = true;
                        $this->current_rounds[$server] = '';
                        $updated = true;
                    }
                    if ($updated) {
                        $this->VarSave('current_rounds.json', $this->current_rounds);
                        $this->VarSave('rounds.json', $this->rounds);
                    }
                }
                if (! $paroled = $this->VarLoad('paroled.json')) {
                    $paroled = [];
                    $this->VarSave('paroled.json', $paroled);
                }
                $this->paroled = $paroled;
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
                $this->embed_footer = ($this->github ?  $this->github . PHP_EOL : '') . "{$this->discord->username}#{$this->discord->discriminator} by Valithor#5947";

                $this->getVerified(); // Populate verified property with data from DB
                if (! $provisional = $this->VarLoad('provisional.json')) {
                    $provisional = [];
                    $this->VarSave('provisional.json', $provisional);
                }
                $this->provisional = $provisional;
                if (! $ages = $this->VarLoad('ages.json')) {
                    $ages = [];
                    $this->VarSave('ages.json', $ages);
                }
                $this->ages = $ages;
                foreach ($this->provisional as $ckey => $discord_id) $this->provisionalRegistration($ckey, $discord_id); // Attempt to register all provisional users
                $this->unbanTimer(); // Start the unban timer and remove the role from anyone who has been unbanned
                $this->setIPs();
                $this->serverinfoTimer(); // Start the serverinfo timer and update the serverinfo channel
                $this->pending = new Collection([], 'discord');
                // Initialize configurations
                if (! $discord_config = $this->VarLoad('discord_config.json')) $discord_config = [];
                foreach ($this->discord->guilds as $guild) if (!isset($discord_config[$guild->id])) $this->SetConfigTemplate($guild, $discord_config);
                $this->discord_config = $discord_config; // Declared, but not currently used for anything
                
                if (! empty($this->functions['ready'])) foreach ($this->functions['ready'] as $func) $func($this);
                else $this->logger->debug('No ready functions found!');
                $this->discord->application->commands->freshen()->done( function ($commands): void
                {
                    $this->slash->updateCommands($commands);
                    if (!empty($this->functions['ready_slash'])) foreach (array_values($this->functions['ready_slash']) as $func) $func($this, $commands);
                    else $this->logger->debug('No ready slash functions found!');
                });
                
                $this->discord->on('message', function ($message): void
                {
                    $message_filtered = $this->filterMessage($message);
                    foreach ($this->server_funcs_called as $command => $func) if (str_starts_with($message_filtered['message_content_lower'], $command)) $func($message, $message_filtered); // Server functions
                    if (! empty($this->functions['message'])) foreach ($this->functions['message'] as $func) $func($this, $message); // Variable functions
                    else $this->logger->debug('No message functions found!');
                });
                $this->discord->on('GUILD_MEMBER_ADD', function ($guildmember): void
                {
                    $this->joinRoles($guildmember);
                    if (! empty($this->functions['GUILD_MEMBER_ADD'])) foreach ($this->functions['GUILD_MEMBER_ADD'] as $func) $func($this, $guildmember);
                    else $this->logger->debug('No message functions found!');
                });
                $this->discord->on('GUILD_CREATE', function (Guild $guild): void
                {
                    if (!isset($this->discord_config[$guild->id])) $this->SetConfigTemplate($guild, $this->discord_config);
                });

                if ($guild = $this->discord->guilds->get('id', $this->civ13_guild_id) && (! (isset($this->timers['relay_timer'])) || (! $this->timers['relay_timer'] instanceof TimerInterface))) {
                    $this->logger->info('chat relay timer started');
                    $this->timers['relay_timer'] = $this->discord->getLoop()->addPeriodicTimer(10, function()
                    {
                        if ($this->relay_method !== 'file') return null;
                        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return $this->logger->error("Could not find Guild with ID `{$this->civ13_guild_id}`");
                        foreach (array_keys($this->server_settings) as $key) {
                            $server = strtolower($key);
                            if (isset($this->channel_ids[$server.'_ooc_channel']) && $channel = $guild->channels->get('id', $this->channel_ids[$server.'_ooc_channel'])) $this->gameChatFileRelay($this->files[$server.'_ooc_path'], $channel);  // #ooc-server
                            if (isset($this->channel_ids[$server.'_asay_channel']) && $channel = $guild->channels->get('id', $this->channel_ids[$server.'_asay_channel'])) $this->gameChatFileRelay($this->files[$server.'_admin_path'], $channel);  // #asay-server
                        }
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
            $streamHandler = new StreamHandler('php://stdout', Level::Info);
            $streamHandler->setFormatter(new LineFormatter(null, null, true, true));
            $options['logger'] = new Logger(self::class, [$streamHandler]);
        }
        $this->logger = $options['logger'];

        if (isset($options['folders'])) foreach ($options['folders'] as $key => $value) if (! is_string($value) || ! file_exists($value) || ! is_dir($value)) {
            $this->logger->warning("`$value` is not a valid folder path!");
            unset($options['folders'][$key]);
        }
        if (isset($options['files'])) foreach ($options['files'] as $key => $value) if (! is_string($value) || ! file_exists($value)) {
            $this->logger->warning("`$value` is not a valid file path!");
            unset($options['files'][$key]);
        }
        if (isset($options['channel_ids'])) foreach ($options['channel_ids'] as $key => $value) if (! is_numeric($value)) {
            $this->logger->warning("`$value` is not a valid channel id!");
            unset($options['channel_ids'][$key]);
        }
        if (isset($options['role_ids'])) foreach ($options['role_ids'] as $key => $value) if (! is_numeric($value)) {
            $this->logger->warning("`$value` is not a valid role id!");
            unset($options['role_ids'][$key]);
        }
        if (isset($options['functions'])) foreach ($options['functions'] as $key => $array) {
            if (! is_array($array)) {
                $this->logger->warning("`$key` is not a valid function array!");
                unset($options['functions'][$key]);
                continue;
            }
            foreach ($array as $func) if (! is_callable($func)) {
                $this->logger->warning("`$func` is not a valid function!");
                unset($options['functions'][$key]);
            }
        }
        
        if (! isset($options['loop']) || ! ($options['loop'] instanceof LoopInterface)) $options['loop'] = Loop::get();
        $options['browser'] = $options['browser'] ?? new Browser($options['loop']);
        $options['filesystem'] = $options['filesystem'] ?? FileSystemFactory::create($options['loop']);
        return $options;
    }
    
    public function run(): void
    {
        $this->logger->info('Starting Discord loop');
        if (!(isset($this->discord))) $this->logger->warning('Discord not set!');
        else $this->discord->run();
    }

    public function stop(): void
    {
        $this->logger->info('Shutting down');
        if ((isset($this->discord))) $this->discord->stop();
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
    public function VarLoad(string $filename = ''): ?array
    {
        if ($filename === '') return null;
        if (!file_exists($this->filecache_path . $filename)) return null;
        if (($string = file_get_contents($this->filecache_path . $filename)) === false) return null;
        if (! $assoc_array = json_decode($string, TRUE)) return null;
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
                'verifier' => false, // Verifier is disabled by default in new servers
            ],
            'roles' => [
                'verified' => '', 
                'promoted' => '', // Different servers may have different standards for getting promoted
            ],
        ];
        if ($this->VarSave('discord_config.json', $discord_config)) $this->logger->info("Created new config for guild {$guild->name}");
        else $this->logger->warning("Failed top create new config for guild {$guild->name}");
    }

    /*
    * This function is used to get either sanitize a ckey or a Discord snowflake
    */
    public function sanitizeInput(string $input): string
    {
        return trim(str_replace(['<@!', '<@&', '<@', '>', '.', '_', '-', ' '], '', strtolower($input)));
    }

    public function isVerified(string $input): bool
    {
        return $this->verified->get('ss13', $input) ?? (is_numeric($input) && ($this->verified->get('discord', $input)));
    }
    
    /*
    * This function is used to fetch the bot's cache of verified members that are currently found in the Civ13 Discord server
    * If the bot is not in the Civ13 Discord server, it will return the bot's cache of verified members
    */
    public function getVerifiedMemberItems(): Collection
    {
        if ($guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return $this->verified->filter(function($v) use ($guild) { return $guild->members->has($v['discord']); });
        return $this->verified;
    }

    /*
    * This function is used to get a verified item from a ckey or Discord ID
    * If the user is verified, it will return an array containing the verified item
    * It will return false if the user is not verified
    */
    public function getVerifiedItem(Member|User|array|string $input): array|false
    {
        // Get the verified item
        if (is_string($input)) {
            if (! $input = $this->sanitizeInput($input)) return false;
            if (is_numeric($input) && $item = $this->verified->get('discord', $input)) return $item;
            elseif ($item = $this->verified->get('ss13', $input)) return $item;
        } elseif ($input instanceof Member || $input instanceof User) {
            if ($item = $this->verified->get('discord', $input->id)) return $item;
        } elseif (is_array($input)) {
            if (! isset($input['discord']) && ! isset($input['ss13'])) return false;
            if (isset($input['discord']) && is_numeric($input['discord']) && $item = $this->verified->get('discord', $this->sanitizeInput($input['discord']))) return $item;
            if (isset($input['ss13']) && is_string($input['ss13']) && $item = $this->verified->get('ss13', $this->sanitizeInput($input['ss13']))) return $item;
        } // else return false; // If $input is not a string, array, Member, or User, return false (this should never happen)
        return false;
    }

    /*
    * This function is used to get a Member object from a ckey or Discord ID
    * It will return false if the user is not verified, if the user is not in the Civ13 Discord server, or if the bot is not in the Civ13 Discord server
    */
    public function getVerifiedMember(Member|User|array|string $input): Member|false
    {
        // Get the guild (required to get the member)
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return false;
        // Get Discord ID
        $id = null;
        if ($input instanceof Member || $input instanceof User) { // If $input is a Member or User, get the Discord ID
            $id = $input->id;
        } elseif (is_string($input)) { // If $input is a string, it could be either a ckey or Discord ID
            if (! $input = $this->sanitizeInput($input)) {
                $this->logger->warning("An invalid string was passed to getVerifiedMember()");
                return false;
            } elseif (is_numeric($input)) { // If $input is not a number, it is probably a ckey
                $id = $input;
            } else {
                if (! $item = $this->verified->get('ss13', $input)) return false;
                $id = $item['discord'];
            }
        } elseif (is_array($input)) { // If $input is an array, it could contain either a ckey or a Discord ID
            if (! isset($input['discord']) && ! isset($input['ss13'])) return false;
            elseif (isset($input['discord']) && is_string($input['discord']) && is_numeric($input['discord'] = $this->sanitizeInput($input['discord']))) $id = $input['discord'];
            elseif (isset($input['ss13']) && is_string($input['ss13']) && $item = $this->verified->get('ss13', $this->sanitizeInput($input['ss13']))) $id = $item['discord'];
            else return false; // If $input is an array, but contains invalid data, return false
        } // else return false; // If $input is not a string, array, Member, or User, return false (this should never happen)
        if (! $id || ! $this->isVerified($id)) return false; // Check if Discord ID is in the verified collection
        if ($member = $guild->members->get('id', $id)) return $member; // Get the member from the guild
        return false;
    }

    public function getRole(string $input): ?Role
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return null;
        if (! $input) {
            $this->logger->warning("An invalid string was passed to getRole()");
            return null;
        }
        if (is_numeric($id = $this->sanitizeInput($input)))
            if ($role = $guild->roles->get('id', $id))
                return $role;
        if ($role = $guild->roles->get('name', $input)) return $role;
        $this->logger->warning("Could not find role with id or name `$input`");
        return null;
    }
    
    /*
    * This function is used to refresh the bot's cache of verified users
    * It is called when the bot starts up, and when the bot receives a GUILD_MEMBER_ADD event
    * It is also called when the bot receives a GUILD_MEMBER_REMOVE event
    * It is also called when the bot receives a GUILD_MEMBER_UPDATE event, but only if the user's roles have changed
    */
    public function getVerified(): Collection
    {
        if ($verified_array = json_decode(file_get_contents($this->verify_url), true)) {
            $this->VarSave('verified.json', $verified_array);
            return $this->verified = new Collection($verified_array, 'discord');
        }
        if ($json = $this->VarLoad('verified.json')) return $this->verified = new Collection($json, 'discord');
        return $this->verified = new Collection([], 'discord');
    }

    public function getRoundsCollections(): array // [string $server, collection $rounds]
    {
        $collections_array = [];
        foreach ($this->rounds as $server => $rounds) {
            $r = [];
            foreach (array_keys($rounds) as $game_id) {
                $round = [];
                $round['game_id'] = $game_id;
                $round['start'] = isset($this->rounds[$server][$game_id]['start']) ? $this->rounds[$server][$game_id]['start'] : null;
                $round['end'] = isset($this->rounds[$server][$game_id]['end']) ? $this->rounds[$server][$game_id]['end'] : null;
                $round['players'] = isset($this->rounds[$server][$game_id]['players']) ? $this->rounds[$server][$game_id]['players'] : [];
                $r[] = $round;
            }
            $collections_array[] = [$server => new Collection($r, 'game_id')];
        }
        return $collections_array;
    }
    
    public function logNewRound(string $server, string $game_id, string $time): void
    {
        if (isset($this->current_rounds[$server]) && isset($this->rounds[$server][$this->current_rounds[$server]]) && $this->rounds[$server][$this->current_rounds[$server]] && $game_id !== $this->current_rounds[$server]) // If the round already exists and is not the current round
            $this->rounds[$server][$this->current_rounds[$server]]['end'] = $time; // Set end time of previous round
        $this->current_rounds[$server] = $game_id; // Update current round
        $this->VarSave('current_rounds.json', $this->current_rounds); // Update log of currently running game_ids
        $this->rounds[$server][$game_id] = []; // Initialize round array
        $this->rounds[$server][$game_id]['start'] = $time; // Set start time
        $this->rounds[$server][$game_id]['end'] = null;
        $this->rounds[$server][$game_id]['players'] = [];
        $this->rounds[$server][$game_id]['interrupted'] = false;
        $this->VarSave('rounds.json', $this->rounds); // Update log of rounds
    }
    public function logPlayerLogin(string $server, string $ckey, string $time, string $ip = '', string $cid = ''): void
    {
        if ($game_id = $this->current_rounds[$server]) {
            if (! isset($this->rounds[$server][$game_id]['players'])) $this->rounds[$server][$game_id]['players'] = [];
            if (! isset($this->rounds[$server][$game_id]['players'][$ckey])) $this->rounds[$server][$game_id]['players'][$ckey] = [];
            if (! isset($this->rounds[$server][$game_id]['players'][$ckey]['login'])) $this->rounds[$server][$game_id]['players'][$ckey]['login'] = $time;
            if ($ip && (! isset($this->rounds[$server][$game_id]['players'][$ckey]['ip']) || ! in_array($ip, $this->rounds[$server][$game_id]['players'][$ckey]['ip']))) $this->rounds[$server][$game_id]['players'][$ckey]['ip'][] = $ip; 
            if ($cid && (! isset($this->rounds[$server][$game_id]['players'][$ckey]['cid']) || ! in_array($cid, $this->rounds[$server][$game_id]['players'][$ckey]['cid']))) $this->rounds[$server][$game_id]['players'][$ckey]['cid'][] = $cid;
            $this->VarSave('rounds.json', $this->rounds);
        }
    }
    public function logPlayerLogout(string $server, string $ckey, string $time): void
    {
        if ($game_id = $this->current_rounds[$server]) {
            if (isset($this->rounds[$server][$game_id]['players'])
                && isset($this->rounds[$server][$game_id]['players'][$ckey])
                && isset($this->rounds[$server][$game_id]['players'][$ckey]['login'])
            ) $this->rounds[$server][$game_id]['players'][$ckey]['logout'] = $time;
            $this->VarSave('rounds.json', $this->rounds);
        }
    }

    /*
     * This function is used to generate a token that can be used to verify a BYOND account
     * The token is generated by generating a random string of 50 characters from the set of all alphanumeric characters
     * The token is then stored in the pending collection, which is a collection of arrays with the keys 'discord', 'ss13', and 'token'
     * The token is then returned to the user
     */
    public function generateByondToken(string $ckey, string $discord_id, string $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', int $length = 50): string
    {
        if ($item = $this->pending->get('ss13', $ckey)) return $item['token'];
        $token = '';
        while (strlen($token)<$length) $token .= $charset[(mt_rand(0,(strlen($charset)-1)))];
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
    { // Check if the user set their token
        if (! $item = $this->pending->get('discord', $discord_id)) return false; // User is not in pending collection (This should never happen and is probably a programming error)
        if (! $page = $this->getByondPage($item['ss13'])) return false; // Website could not be retrieved or the description wasn't found
        if ($item['token'] != $this->getByondDesc($page)) return false; // Token does not match the description
        return true; // Token matches
    }
    
    /*
     * This function is used to retrieve the 50 character token from the BYOND website
     */
    public function getByondPage(string $ckey): string|false 
    { // Get the 50 character token from the desc. User will have needed to log into https://secure.byond.com/members/-/account and added the generated token to their description first!
        $url = 'http://www.byond.com/members/'.urlencode($ckey).'?format=text';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // return the page as a string
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
        if ($desc = substr($page, (strpos($page , 'desc')+8), 50)) return $desc; // PHP versions older than 8.0.0 will return false if the desc isn't found, otherwise an empty string will be returned
        return false;
    }
    
    /*
     * This function is used to parse a BYOND account's age
     * */
    public function parseByondAge(string $page): string|false
    {
		if (preg_match("^(19|20)\d\d[- /.](0[1-9]|1[012])[- /.](0[1-9]|[12][0-9]|3[01])^", $age = substr($page, (strpos($page , 'joined')+10), 10))) return $age;
        return false;
    }
    public function getByondAge($ckey): string|false
    {
        if (isset($this->ages[$ckey])) return $this->ages[$ckey];
        if ($age = $this->parseByondAge($this->getByondPage($ckey))) {
            $this->ages[$ckey] = $age;
            $this->VarSave('ages.json', $this->ages);
            return $this->ages[$ckey];
        }
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
        $ckey = $this->sanitizeInput($ckey);
        if ($this->verified->has($discord_id)) { $member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id); if (! $member->roles->has($this->role_ids['infantry'])) $member->setRoles([$this->role_ids['infantry']], "approveme join $ckey"); return 'You are already verified!';}
        if ($this->verified->has($ckey)) return "`$ckey` is already verified! If this is your account, contact {<@{$this->technician_id}>} to delete this entry.";
        if (! $this->pending->get('discord', $discord_id)) {
            if (! $age = $this->getByondAge($ckey)) return "Byond account `$ckey` does not exist!";
            if (! $this->checkByondAge($age) && ! isset($this->permitted[$ckey])) {
                $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => $reason = "Byond account `$ckey` does not meet the requirements to be approved. ($age)"];
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage($this->ban($arr));
                return $reason;
            }
            $found = false;
            $file_contents = '';
            foreach (array_keys($this->server_settings) as $key) {
                $server = strtolower($key);
                if (isset($this->files[$server.'_playerlogs'])) $file_contents .= file_get_contents($this->files[$server.'_playerlogs']);
                else $this->logger->warning("unable to open {$this->files[$server.'_playerlogs']}");
            }
            foreach (explode('|', $file_contents) as $line) if (explode(';', trim($line))[0] == $ckey) { $found = true; break; }
            if (! $found) return "Byond account `$ckey` has never been seen on the server before! You'll need to join one of our servers at least once before verifying."; 
            return 'Login to your profile at https://secure.byond.com/members/-/account and enter this token as your description: `' . $this->generateByondToken($ckey, $discord_id) . PHP_EOL . '`Use the command again once this process has been completed.';
        }
        return $this->verifyNew($discord_id)['error']; // ['success'] will be false if verification cannot proceed or true if succeeded but is only needed if debugging, ['error'] will contain the error/success message and will be messaged to the user
    }

    /*
    * This function is called when a user still needs to set their token in their BYOND description and call the approveme prompt
    * It will check if the token is valid, then add the user to the verified list
    */
    public function verifyNew(string $discord_id): array // ['success' => bool, 'error' => string]
    { // Attempt to verify a user
        if (! $item = $this->pending->get('discord', $discord_id)) return ['success' => false, 'error' => "This error should never happen. If this error persists, contact <@{$this->technician_id}>."];
        if (! $this->checkToken($discord_id)) return ['success' => false, 'error' => "You have not set your description yet! It needs to be set to `{$item['token']}`"];
        $ckeyinfo = $this->ckeyinfo($item['ss13']);
        if (($ckeyinfo['altbanned'] || count($ckeyinfo['discords']) > 1) && ! isset($this->permitted[$item['ss13']])) { // TODO: Add check for permaban
            // TODO: add to pending list?
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $channel->sendMessage("<@&{$this->role_ids['captain']}>, {$item['ss13']} has been flagged as needing additional review. Please `permit` the ckey after reviewing if they should be allowed to complete the verification process.");
            return ['success' => false, 'error' => "Your ckey `{$item['ss13']}` has been flagged as needing additional review. Please wait for a staff member to assist you."];
        }
        return $this->verifyCkey($item['ss13'], $discord_id);
    }
    
    /* 
    * This function is called when a user has set their token in their BYOND description and attempts to verify
    * It is also used to handle errors coming from the webserver
    * If the website is down, it will add the user to the provisional list and set a timer to try to verify them again in 30 minutes
    * If the user is allowed to be granted a provisional role, it will return true
    */
    public function provisionalRegistration(string $ckey, string $discord_id): bool
    {
        $provisionalRegistration = function(string $ckey, string $discord_id) use (&$provisionalRegistration) {
            if ($this->verified->get('discord', $discord_id)) { // User already verified, this function shouldn't be called (may happen anyway because of the timer)
                if (isset($this->provisional[$ckey])) unset($this->provisional[$ckey]);
                return false;
            }
            $result = $this->verifyCkey($ckey, $discord_id, true);

            if ($result['success']) {
                unset($this->provisional[$ckey]);
                $this->VarSave('provisional.json', $this->provisional);
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage("Successfully verified Byond account `$ckey` with Discord ID <@$discord_id>.");
                return false;
            }
            
            if ($result['error'] && str_starts_with('The website', $result['error'])) {
                $this->discord->getLoop()->addTimer(1800, function() use ($provisionalRegistration, $ckey, $discord_id) {
                    $provisionalRegistration($ckey, $discord_id);
                });
                if ($member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id))
                    if (! $member->roles->has($this->role_ids['infantry']))
                        $member->setRoles([$this->role_ids['infantry']], "Provisional verification `$ckey`");
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage("Failed to verify Byond account `$ckey` with Discord ID <@$discord_id> Providing provisional verification role and trying again in 30 minutes... " . $result['error']);
                return true;
            }
            if ($result['error'] && str_starts_with('Either Byond account', $result['error'])) {
                if ($member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id))
                    if ($member->roles->has($this->role_ids['infantry']))
                        $member->setRoles([], 'Provisional verification failed');
                unset($this->provisional[$ckey]);
                $this->VarSave('provisional.json', $this->provisional);
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage("Failed to verify Byond account `$ckey` with Discord ID <@$discord_id>. " . $result['error']);
                return false;
            }
            if ($result['error']) {
                if ($member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id))
                    if ($member->roles->has($this->role_ids['infantry']))
                        $member->setRoles([], 'Provisional verification failed');
                unset($this->provisional[$ckey]);
                $this->VarSave('provisional.json', $this->provisional);
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage("Failed to verify Byond account `$ckey` with Discord ID <@$discord_id>: {$result['error']}");
                return false;
            }
            // The code should only get this far if $result['error'] wasn't set correctly. This should never happen and is probably a programming error.
            $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage("Something went wrong trying to process the provisional registration for Byond account `$ckey` with Discord ID <@$discord_id>. If this error persists, contact <@{$this->technician_id}>.");
            return false;
        };
        return $provisionalRegistration($ckey, $discord_id);
    }
    /*
    * This function is called when a user has already set their token in their BYOND description and called the approveme prompt
    * If the Discord ID or ckey is already in the SQL database, it will return an error message stating that the ckey is already verified
    * otherwise it will add the user to the SQL database and the verified list, remove them from the pending list, and give them the verified role
    */
    public function verifyCkey(string $ckey, string $discord_id, $provisional = false): array // ['success' => bool, 'error' => string]
    { // Send $_POST information to the website. Only call this function after the getByondDesc() verification process has been completed!
        $success = false;
        $error = '';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->verify_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type' => 'application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // return the transfer as a string    
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['token' => $this->civ_token, 'ckey' => $ckey, 'discord' => $discord_id]));
        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Validate the website's HTTP response! 200 = success, 403 = ckey already registered, anything else is an error
        switch ($http_status) {
            case 200: // Verified
                $success = true;
                $error = "`$ckey` - (" . $this->ages[$ckey] . ") has been verified and registered to $discord_id";
                $this->pending->offsetUnset($discord_id);
                $this->getVerified();
                if (isset($this->channel_ids['staff_bot'])) $channel = $this->discord->getChannel($this->channel_ids['staff_bot']);
                if (! $member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id)) return ['success' => false, 'error' => "$ckey - {$this->ages[$ckey]}) was verified but the member couldn't be found. If this error persists, contact <@{$this->technician_id}>."];
                if (isset($this->panic_bans[$ckey])) {
                    $this->__panicUnban($ckey);
                    $error .= ' and the panic bunker ban removed.';
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
            case 403: // Already registered
                $error = "Either Byond account `$ckey` or <@$discord_id> has already been verified."; // This should have been caught above. Need to run getVerified() again?
                $this->getVerified();
                break;
            case 404:
                $error = 'The website could not be found or is misconfigured. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
            case 503: // Database unavailable
                $error = 'The website timed out while attempting to process the request because the database is currently unreachable. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
            case 504: // Gateway timeout
                $error = 'The website timed out while attempting to process the request. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
            case 0: // The website is down, so allow provisional registration, then try to verify when it comes back up
                $error = 'The website could not be reached. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";    
                if (! $provisional) { // 
                    if (! isset($this->provisional[$ckey])) {
                        $this->provisional[$ckey] = $discord_id;
                        $this->VarSave('provisional.json', $this->provisional);
                    }
                    if ($this->provisionalRegistration($ckey, $discord_id)) $error = "The website could not be reached. Provisionally registered `$ckey` with Discord ID <@$discord_id>.";
                    else $error .= 'Provisional registration is already pending and a new provisional role will not be provided at this time.' . PHP_EOL . $error;
                }
                break;
            default: 
                $error = "There was an error attempting to process the request: [$http_status] $result" . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
        }
        curl_close($ch);
        return ['success' => $success, 'error' => $error];
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
        if (! $ckey = $this->sanitizeInput($ckey)) return false;
        $banned = ($this->legacy ? $this->legacyBancheck($ckey) : $this->sqlBancheck($ckey));
        if (! $bypass && $member = $this->getVerifiedMember($ckey))
            if ($banned && ! $member->roles->has($this->role_ids['banished'])) $member->addRole($this->role_ids['banished'], "bancheck ($ckey)");
            elseif (! $banned && $member->roles->has($this->role_ids['banished'])) $member->removeRole($this->role_ids['banished'], "bancheck ($ckey)");
        return $banned;
    }
    public function legacyBancheck(string $ckey): bool
    {
        foreach (array_keys($this->server_settings) as $key) {
            $server = strtolower($key);
            if (file_exists($this->files[$server.'_bans']) && $file = @fopen($this->files[$server.'_bans'], 'r')) {
                while (($fp = fgets($file, 4096)) !== false) {
                    // str_replace(PHP_EOL, '', $fp); // Is this necessary?
                    $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                    if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
                        fclose($file);
                        return true;
                    }
                }
                fclose($file);
            } else $this->logger->debug("unable to open `{$this->files[$server.'_bans']}`");
        }
        return false;
    }
    public function sqlBancheck(string $ckey): bool
    {
        // TODO
        return false;
    }

    public function paroleCkey(string $ckey, string $admin, bool $state = true): array
    {
        if ($state) $this->paroled[$ckey] = $admin;
        else unset($this->paroled[$ckey]);
        $this->VarSave('paroled.json', $this->paroled);
        return $this->paroled;
    }

    /*
    * This function allows a ckey to bypass the verification process entirely
    * NOTE: This function is only authorized to be used by the database administrator
    */
    public function registerCkey(string $ckey, string $discord_id): array // ['success' => bool, 'error' => string]
    {
        $this->permitCkey($ckey, true);
        return $this->verifyCkey($ckey, $discord_id);
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
    public function __panicBan(string $ckey): void
    {
        if (! $this->bancheck($ckey, true)) {
            ($this->legacy ? $this->legacyBan(['ckey' => $ckey, 'duration' => '1 hour', 'reason' => "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->banappeal}"], null, 'nomads') : $this->sqlBan(['ckey' => $ckey, 'reason' => '1 hour', 'duration' => "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->banappeal}"], null, 'nomads') );
            $this->panic_bans[$ckey] = true;
            $this->VarSave('panic_bans.json', $this->panic_bans);
        }
    }
    public function __panicUnban(string $ckey): void
    {
        ($this->legacy ? $this->legacyUnban($ckey, null, 'Nomads') : $this->sqlUnban($ckey, null, 'Nomads'));
        unset($this->panic_bans[$ckey]);
        $this->VarSave('panic_bans.json', $this->panic_bans);
    }

    /*
    * These Legacy and SQL functions should not be called directly
    * Define $legacy = true/false and use ban/unban methods instead
    */
    public function sqlUnban($array, $admin = null, ?string $key = ''): string
    {
        return "SQL methods are not yet implemented!" . PHP_EOL;
    }
    public function legacyUnban(string $ckey, ?string $admin = null, ?string $key = ''): void
    {
        $admin = $admin ?? $this->discord->user->username;
        $legacyUnban = function(string $ckey, string $admin, string $key)
        {
            $server = strtolower($key);
            if (file_exists($this->files[$server.'_discord2unban']) && $file = @fopen($this->files[$server.'_discord2unban'], 'a')) {
                fwrite($file, $admin . ":::$ckey");
                fclose($file);
            } else $this->logger->warning("unable to open {$this->files[$server.'_discord2unban']}");
        };
        if ($key) $legacyUnban($ckey, $admin, $key);
        else foreach (array_keys($this->server_settings) as $key) $legacyUnban($ckey, $admin, $key);
    }
    public function sqlpersunban(string $ckey, ?string $admin = null): void
    {
        // TODO
    }
    public function legacyBan(array $array, $admin = null, ?string $key = ''): string
    {
        $admin = $admin ?? $this->discord->user->username;
        $legacyBan = function(array $array, string $admin, string $key): string
        {
            $server = strtolower($key);
            if (str_starts_with(strtolower($array['duration']), 'perm')) $array['duration'] = '999 years';
            if (file_exists($this->files[$server.'_discord2ban']) && $file = @fopen($this->files[$server.'_discord2ban'], 'a')) {
                fwrite($file, "$admin:::{$array['ckey']}:::{$array['duration']}:::{$array['reason']}" . PHP_EOL);
                fclose($file);
                return "**$admin** banned **{$array['ckey']}** from **{$key}** for **{$array['duration']}** with the reason **{$array['reason']}**" . PHP_EOL;
            } else {
                $this->logger->warning("unable to open {$this->files[$server.'_discord2ban']}");
                return "unable to open `{$this->files[$server.'_discord2ban']}`" . PHP_EOL;
            }
        };
        if ($key) return $legacyBan($array, $admin, $key);
        $result = '';
        foreach (array_keys($this->server_settings) as $key) $result .= $legacyBan($array, $admin, $key);
        return $result;
    }
    public function sqlBan(array $array, $admin = null, ?string $key = ''): string
    {
        return "SQL methods are not yet implemented!" . PHP_EOL;
    }

    /*
    * These functions determine which of the above methods should be used to process a ban or unban
    * Ban functions will return a string containing the results of the ban
    * Unban functions will return nothing, but may contain error-handling messages that can be passed to $logger->warning()
    */
    public function ban(array &$array /* = ['ckey' => '', 'duration' => '', 'reason' => ''] */, ?string $admin = null, ?string $key = ''): string
    {
        if (! isset($array['ckey'])) return "You must specify a ckey to ban.";
        if (! is_numeric($array['ckey']) && ! is_string($array['ckey'])) return "The ckey must be a Byond username or Discord ID.";
        if (! isset($array['duration'])) return "You must specify a duration to ban for.";
        if (! isset($array['reason'])) return "You must specify a reason for the ban.";
        $array['ckey'] = $this->sanitizeInput($array['ckey']);
        if (is_numeric($array['ckey'])) {
            if (! $item = $this->verified->get('discord', $array['ckey'])) return "Unable to find a ckey for <@{$array['ckey']}>. Please use the ckey instead of the Discord ID.";
            $array['ckey'] = $item['ss13'];
        }
        if ($member = $this->getVerifiedMember($array['ckey']))
            if (! $member->roles->has($this->role_ids['banished']))
                $member->addRole($this->role_ids['banished'], "Banned for {$array['duration']} with the reason {$array['reason']}");
        if ($this->legacy) return $this->legacyBan($array, $admin, $key);
        return $this->sqlBan($array, $admin, $key);
    }
    public function unban(string $ckey, ?string $admin = null, ?string $key = ''): void
    {
        $admin ??= $this->discord->user->displayname;
        if ($this->legacy) $this->legacyUnban($ckey, $admin, $key);
        else $this->sqlUnban($ckey, $admin, $key);
        if ( $member = $this->getVerifiedMember($ckey))
            if ($member->roles->has($this->role_ids['banished']))
                $member->removeRole($this->role_ids['banished'], "Unbanned by $admin");
    }
    
    public function DirectMessage(string $recipient, string $message, string $sender, ?string $key = ''): bool
    {
        $directmessage = function(string $recipient, string $message, string $sender, string $key): bool
        {
            $server = strtolower($key);
            if (file_exists($this->files[$server.'_discord2dm']) && $file = @fopen($this->files[$server.'_discord2dm'], 'a')) {
                fwrite($file, "$sender:::$recipient:::$message" . PHP_EOL);
                fclose($file);
                return true;
            } else {
                $this->logger->debug("unable to open `{$this->files[$server.'_discord2dm']}`");
                return false;
            }
        };
        
        $sent = false;
        if ($key) $sent = $directmessage($recipient, $message, $sender, $key);
        else foreach (array_keys($this->server_settings) as $key) if ($directmessage($recipient, $message, $sender, $key)) $sent = true;
        return $sent;
    }

    /*
    * This function defines the IPs and ports of the servers
    * It is called on ready
    * TODO: Move definitions into config/constructor?
    */
    public function setIPs(): void
    {
        $this->ips = [
            'nomads' => $this->external_ip,
            'tdm' => $this->external_ip,
            'pers' => $this->vzg_ip,
            'vzg' => $this->vzg_ip,
        ];
        $this->ports = [
            'nomads' => '1715',
            'tdm' => '1714',
            'pers' => '1716',
            'bc' => '7777', 
            'ps13' => '7778',
        ];
        if (! $this->serverinfo_url) $this->serverinfo_url = 'http://' . (isset($this->ips['vzg']) ? $this->ips['vzg'] : $this->vzg_ip) . '/servers/serverinfo.json'; // Default to VZG unless passed manually in config
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
                if (isset($p[1]) && is_numeric($p[1])) $this->players[] = $this->sanitizeInput(urldecode($server[$key]));
            }
        }
        return $this->players;
    }
    public function webserverStatusChannelUpdate(bool $status)
    {
        if (! $channel = $this->discord->getChannel($this->channel_ids['webserver-status'])) return null;
        [$webserver_name, $reported_status] = explode('-', $channel->name);
        if ($this->webserver_online) $status = 'online';
        else $status = 'offline';
        if ($reported_status != $status) {
            $msg = "Webserver is now **{$status}**.";
            if ($status == 'offline') $msg .= " Webserver technician <@{$this->technician_id}> has been notified.";
            $channel->sendMessage($msg);
            $channel->name = "{$webserver_name}-{$status}";
            $channel->guild->channels->save($channel);
        }
    }
    public function serverinfoFetch(): array
    {
        if (! $data_json = json_decode(file_get_contents($this->serverinfo_url, false, stream_context_create(array('http'=>array('timeout' => 5, )))),  true)) {
            $this->webserverStatusChannelUpdate($this->webserver_online = false);
            return [];
        }
        $this->webserverStatusChannelUpdate($this->webserver_online = true);
        return $this->serverinfo = $data_json;
    }
    public function bansToCollection(): Collection
    {
        // Get the contents of the file
        $file_contents = '';
        foreach (array_keys($this->server_settings) as $key) {
            $server = strtolower($key);
            if (isset($this->files[$server.'_bans']) && file_exists($this->files[$server.'_bans'])) $file_contents .= file_get_contents($this->files[$server.'_bans']);
        }
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
        foreach (array_keys($this->server_settings) as $key) {
            $server = strtolower($key);
            if (isset($this->files[$server.'_playerlogs']) && file_exists($this->files[$server.'_playerlogs'])) $file_contents .= file_get_contents($this->files[$server.'_playerlogs']);
        }
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
    public function ckeyinfo(string $ckey): array
    {
        if (! $ckey = $this->sanitizeInput($ckey)) return [null, null, null, false, false];
        if (! $collectionsArray = $this->getCkeyLogCollections($ckey)) return [null, null, null, false, false];
        if ($item = $this->getVerifiedItem($ckey)) $ckey = $item['ss13'];
        // var_dump('Ckey Collections Array: ', $collectionsArray, PHP_EOL);
        
        $ckeys = [$ckey];
        $ips = [];
        $cids = [];
        foreach ($collectionsArray[0] as $log) { // Get the ckey's primary identifiers
            if (isset($log['ip'])) $ips[] = $log['ip'];
            if (isset($log['cid'])) $cids[] = $log['cid'];
        }
        foreach ($collectionsArray[1] as $log) { // Get the ckey's primary identifiers
            if (isset($log['ip']) && ! in_array($log['ip'], $ips)) $ips[] = $log['ip'];
            if (isset($log['cid']) && ! in_array($log['cid'], $ips)) $cids[] = $log['cid'];
        }
        // var_dump('Searchable: ',  $ckeys, $ips, $cids, PHP_EOL);
        // Iterate through the playerlogs ban logs to find all known ckeys, ips, and cids
        $playerlogs = $this->playerlogsToCollection();
        $i = 0;
        $break = false;
        do { // Iterate through playerlogs to find all known ckeys, ips, and cids
            $found = false;
            $found_ckeys = [];
            $found_ips = [];
            $found_cids = [];
            foreach ($playerlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                // $this->logger->debug('Found new match: ', $log, PHP_EOL);
                if (! in_array($log['ckey'], $ckeys)) { $found_ckeys[] = $log['ckey']; $found = true; }
                if (! in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                if (! in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
            }
            $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
            $ips = array_unique(array_merge($ips, $found_ips));
            $cids = array_unique(array_merge($cids, $found_cids));
            if ($i > 10) $break = true;
            $i++;
        } while ($found && ! $break); // Keep iterating until no new ckeys, ips, or cids are found
    
        $banlogs = $this->bansToCollection();        
        $found = true;
        $break = false;
        $i = 0;
        do { // Iterate through playerlogs to find all known ckeys, ips, and cids
            $found = false;
            $found_ckeys = [];
            $found_ips = [];
            $found_cids = [];
            foreach ($banlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                if (! in_array($log['ckey'], $ips)) { $found_ckeys[] = $log['ckey']; $found = true; }
                if (! in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                if (! in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
            }
            $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
            $ips = array_unique(array_merge($ips, $found_ips));
            $cids = array_unique(array_merge($cids, $found_cids));
            $i++;
            if ($i > 10) $break = true;
        } while ($found && ! $break); // Keep iterating until no new ckeys, ips, or cids are found

        $verified = false;
        $altbanned = false;
        $discords = [];
        foreach ($ckeys as $key) {
            if ($item = $this->verified->get('ss13', $key)) {
                $discords[] = $item['discord'];
                $verified = true;
            }
            if ($key != $ckey && $this->bancheck($key)) $altbanned = true;
        }

        return [
            'ckeys' => $ckeys,
            'ips' => $ips,
            'cids' => $cids,
            'banned' => $this->bancheck($ckey),
            'altbanned' => $altbanned,
            'verified' => $verified,
            'discords' => $discords
        ];
    }
    /*
    * This function is used to get the country code of an IP address using the ip-api API
    * The site will return a JSON object with the country code, region, and city of the IP address
    * The site will return a status of 429 if the request limit is exceeded (45 requests per minute)
    * Returns a string in the format of 'CC->REGION->CITY'
    */
    function __IP2Country(string $ip): string
    {
        // TODO: Add caching and error handling for 429s
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_URL, "http://ip-api.com/json/$ip"); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // The site is usually really fast, so we don't want to wait too long
        $response = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($response, true);
        if (! $json) return ''; // If the request timed out or if the service 429'd us
        if ($json['status'] == 'success') return $json['countryCode'] . '->' . $json['region'] . '->' . $json['city'];
    }
    function IP2Country(string $ip): string
    {
        $numbers = explode('.', $ip);
        if (! include('ip_files/'.$numbers[0].'.php')) return 'unknown'; // $ranges is defined in the included file
        $code = ($numbers[0] * 16777216) + ($numbers[1] * 65536) + ($numbers[2] * 256) + ($numbers[3]);    
        $country = '';
        foreach (array_keys($ranges) as $key) if ($key<=$code) if ($ranges[$key][0]>=$code) {
            $country = $ranges[$key][1];
            break;
        }
        if ($country == '') $country = 'unknown';
        return $country;
    }
    public function serverinfoTimer(): void
    {
        $serverinfoTimer = function() {
            $this->serverinfoFetch(); 
            $this->serverinfoParsePlayers();
            foreach ($this->serverinfoPlayers() as $ckey) {
                if (! in_array($ckey, $this->seen_players) && ! isset($this->permitted[$ckey])) {
                    $this->seen_players[] = $ckey;
                    $ckeyinfo = $this->ckeyinfo($ckey);
                    if ($ckeyinfo['altbanned']) {
                        $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->banappeal}"];
                        $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage(($this->ban($arr))); // Automatically ban evaders
                    } else foreach ($ckeyinfo['ips'] as $ip) {
                        if (in_array($this->IP2Country($ip), $this->blacklisted_countries)) {
                            $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->banappeal}"];
                            $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage(($this->ban($arr)));
                            break;
                        } else foreach ($this->blacklisted_regions as $region) if (str_starts_with($ip, $region)) {
                            $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->banappeal}"];
                            $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage(($this->ban($arr)));
                            break 2;
                        }
                    }
                }
                if ($this->verified->get('ss13', $ckey)) continue;
                if ($this->panic_bunker || (isset($this->serverinfo[1]['admins']) && $this->serverinfo[1]['admins'] == 0 && isset($this->serverinfo[1]['vote']) && $this->serverinfo[1]['vote'] == 0)) return $this->__panicBan($ckey);
                if (isset($this->ages[$ckey])) continue;
                if (! $this->checkByondAge($age = $this->getByondAge($ckey)) && ! isset($this->permitted[$ckey])) {
                    $arr = ['ckey' => $ckey, 'reason' => '999 years', 'duration' => "Byond account `$ckey` does not meet the requirements to be approved. ($age)"];
                    $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage($this->ban($arr));
                }
            }
        };
        $serverinfoTimer();
        $this->timers['serverinfo_timer'] = $this->discord->getLoop()->addPeriodicTimer(60, function() use ($serverinfoTimer) { $serverinfoTimer(); });
    }
    /*
    * This function parses the serverinfo data and updates the relevant Discord channel name with the current player counts
    * Prefix is used to differentiate between two different servers, however it cannot be used with more due to ratelimits on Discord
    * It is called on ready and every 5 minutes
    */
    private function playercountChannelUpdate(int $count = 0, string $prefix = ''): bool
    {
        if (! $channel = $this->discord->getChannel($this->channel_ids[$prefix . 'playercount'])) {
            $this->logger->warning("Channel {$prefix}playercount doesn't exist!");
            return false;
        }
        if (! $channel->created) {
            $this->logger->warning("Channel {$channel->name} hasn't been created!");
            return false;
        }
        [$channelPrefix, $existingCount] = explode('-', $channel->name);
        if ($this->playercount_ticker % 10 !== 0) return false;
        if ((int)$existingCount !== $count) {
            $channel->name = "{$channelPrefix}-{$count}";
            $channel->guild->channels->save($channel);
        }
        return true;
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
                    return strtolower($this->sanitizeInput(urldecode($server[$key])));
                }, $players);
                $playerCount = count($players);
            }
            elseif (isset($server['players'])) $playerCount = $server['players'];
            else $playerCount = '?';
    
            $return[$index]['Players (' . $playerCount . ')'] = [true => empty($players) ? 'N/A' : implode(', ', $players)];
    
            if (isset($server['season'])) $return[$index]['Season'] = [true => urldecode($server['season'])];
    
            if ($index <= 2) {
                $p1 = (isset($server['players']) ? $server['players'] : count($players) ?? 0);
                $p2 = $si['prefix'];
                $this->playercountChannelUpdate($p1, $p2);
            }
        }
        $this->playercount_ticker++;
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
        // $relevant_servers = array_filter($this->serverinfo, fn($server) => in_array($server['stationname'], ['TDM', 'Nomads', 'Persistence'])); // We need to declare stationname in world.dm first

        $index = 0;
        // foreach ($relevant_servers as $server) // TODO: We need to declare stationname in world.dm first
        foreach ($this->serverinfo as $server) {
            if (array_key_exists('ERROR', $server) || $index > 2) { // We only care about Nomads, TDM, and Persistence
                $index++; // TODO: Remove this once we have stationname in world.dm
                continue;
            }
            $p1 = (isset($server['players']) ? $server['players'] : count(array_map(fn($player) => $this->sanitizeInput(urldecode($player)), array_filter($server, function($key) { return str_starts_with($key, 'player') && !str_starts_with($key, 'players'); }, ARRAY_FILTER_USE_KEY))));
            $p2 = $server_info[$index]['prefix'];
            $this->playercountChannelUpdate($p1, $p2);
            $index++; // TODO: Remove this once we have stationname in world.dm
        }
        $this->playercount_ticker++;
    }

    /*
    * This function takes a member and checks if they have previously been verified
    * If they have, it will assign them the appropriate roles
    */
    public function joinRoles($member): void
    {
        if ($member->guild_id == $this->civ13_guild_id && $item = $this->verified->get('discord', $member->id)) {
            if (! isset($item['ss13'])) $this->logger->warning("Verified member `{$member->id}` does not have an SS13 ckey assigned to them.");
            else {
                $banned = $this->bancheck($item['ss13'], true);
                $paroled = isset($this->paroled[$item['ss13']]);
                if ($banned && $paroled) $member->setroles([$this->role_ids['infantry'], $this->role_ids['banished'], $this->role_ids['paroled']], "bancheck join {$item['ss13']}");
                elseif ($banned) $member->setroles([$this->role_ids['infantry'], $this->role_ids['banished']], "bancheck join {$item['ss13']}");
                elseif ($paroled) $member->setroles([$this->role_ids['infantry'], $this->role_ids['paroled']], "parole join {$item['ss13']}");
                else $member->setroles([$this->role_ids['infantry']], "verified join {$item['ss13']}");
            }
        }
    }
    /*
    * This function checks all Discord member's ckeys against the banlist
    * If they are no longer banned, it will remove the banished role from them
    */
    public function unbanTimer(): bool
    {
        // We don't want the persistence server to do this function
        foreach (array_keys($this->server_settings) as $key) {
            $server = strtolower($key);
            if (! file_exists($this->files[$server.'_bans']) || ! $file = @fopen($this->files[$server.'_bans'], 'r')) return false;
            fclose($file);
        }

        $unbanTimer = function() {
            if (isset($this->role_ids['banished']) && $guild = $this->discord->guilds->get('id', $this->civ13_guild_id))
                if ($members = $guild->members->filter(fn ($member) => $member->roles->has($this->role_ids['banished'])))
                    foreach ($members as $member) if ($item = $this->getVerifiedMemberItems()->get('discord', $member->id))
                        if (! $this->bancheck($item['ss13'], true)) {
                            $member->removeRole($this->role_ids['banished'], 'unban timer');
                            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $channel->sendMessage("Removed the banished role from $member.");
                        }
         };
         $unbanTimer();
         $this->timers['unban_timer'] = $this->discord->getLoop()->addPeriodicTimer(43200, function() use ($unbanTimer) { $unbanTimer(); });
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
    public function gameChatFileRelay(string $file_path, string $channel_id, ?bool $moderate = false): bool
    { // The file function needs to be replaced with the new Webhook system
        if ($this->relay_method !== 'file') return false;
        if (! file_exists($file_path) || ! $file = @fopen($file_path, 'r+')) {
            $this->relay_method = 'webhook'; // Failsafe to prevent the bot from calling this function again. This should be a safe alternative to disabling relaying entirely.
            $this->logger->warning("gameChatFileRelay() was called with an invalid file path: `$file_path`, falling back to using webhooks for relaying instead.");
            return false;
        }
        if (! $channel = $this->discord->getChannel($channel_id)) {
            $this->logger->warning("gameChatFileRelay() was unable to retrieve the channel with ID `$channel_id`");
            return false;
        }

        $relay_array = [];
        while (($fp = fgets($file, 4096)) !== false) {
            $fp = html_entity_decode(str_replace(PHP_EOL, '', $fp));
            $string = substr($fp, strpos($fp, '/')+1);
            if ($string && $ckey = $this->sanitizeInput(substr($string, 0, strpos($string, ':'))))
                $relay_array[] = ['ckey' => $ckey, 'message' => $fp, 'server' => explode('-', $channel->name)[0]];
        }
        ftruncate($file, 0);
        fclose($file);
        return $this->__gameChatRelay($relay_array, $channel, $moderate); // Disabled moderation as it is now done quicker using the Webhook system
    }
    public function gameChatWebhookRelay(string $ckey, string $message, string $channel_id, ?bool $moderate = true): bool
    {
        if ($this->relay_method !== 'webhook') return false;
        if (! $ckey || ! $message || ! is_string($channel_id) || ! is_numeric($channel_id)) {
            $this->logger->warning('gameChatWebhookRelay() was called with invalid parameters: ' . json_encode(['ckey' => $ckey, 'message' => $message, 'channel_id' => $channel_id]));
            return false;
        }
        if (! $channel = $this->discord->getChannel($channel_id)) {
            $this->logger->warning("gameChatWebhookRelay() was unable to retrieve the channel with ID `$channel_id`");
            return false;
        }
        
        if (! $this->ready) {
            $this->logger->warning('gameChatWebhookRelay() was called before the bot was ready');
            $listener = function() use ($ckey, $message, $channel_id, $moderate, &$listener) {
                $this->gameChatWebhookRelay($ckey, $message, $channel_id, $moderate);
                $this->discord->removeListener('ready', $listener);
            };
            $this->discord->on('ready', $listener);
            return true; // Assume that the function will succeed when the bot is ready
        }
        
        return $this->__gameChatRelay(['ckey' => $ckey, 'message' => $message, 'server' => explode('-', $channel->name)[1]], $channel, $moderate);
    }
    private function __gameChatRelay(array $array, $channel, $moderate = true): bool
    {
        if (! $array || ! isset($array['ckey']) || ! isset($array['message']) || ! isset($array['server']) || ! $array['ckey'] || ! $array['message'] || ! $array['server']) {
            $this->logger->warning('__gameChatRelay() was called with an empty array or invalid content.');
            return false;
        }
        if ($moderate && $this->moderate) $this->__gameChatModerate($array['ckey'], $array['message'], $array['server']);
        if (! $item = $this->verified->get('ss13', $this->sanitizeInput($array['ckey']))) {
            $builder = \Discord\Builders\MessageBuilder::new()
                ->setContent($array['message'])
                ->setAllowedMentions(['parse'=>[]]);
            $channel->sendMessage($builder);
        } else {
            $embed = new Embed($this->discord);
            if ($user = $this->discord->users->get('id', $item['discord'])) $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
            // else $this->discord->users->fetch('id', $item['discord']); // disabled to prevent rate limiting
            $embed->setDescription($array['message']);
            $channel->sendEmbed($embed);
        }
        return true;
    }
    private function __gameChatModerate(string $ckey, string $string, string $server = 'nomads'): string
    {
        foreach ($this->badwords as $badwords_array) switch ($badwords_array['method']) {
            case 'exact': // ban ckey if $string contains a blacklisted phrase exactly as it is defined
                if (preg_match('/\b' . $badwords_array['word'] . '\b/', $string)) $this->__relayViolation($server, $ckey, $badwords_array);
                break;
            case 'contains': // ban ckey if $string contains a blacklisted word
            default: // default to 'contains'
                if (str_contains(strtolower($string), $badwords_array['word'])) $this->__relayViolation($server, $ckey, $badwords_array);
        }
        return $string;
    }
    // This function is called from the game's chat hook if a player says something that contains a blacklisted word
    private function __relayViolation(string $server, string $ckey, array $badwords_array)
    {
        $filtered = substr($badwords_array['word'], 0, 1) . str_repeat('%', strlen($badwords_array['word'])-2) . substr($badwords_array['word'], -1, 1);
        if (! $this->__relayWarningCounter($ckey, $badwords_array)) {
            $arr = ['ckey' => $ckey, 'duration' => $badwords_array['duration'], 'reason' => "Blacklisted phrase ($filtered). Review the rules at {$this->rules}. Appeal at {$this->banappeal}"];
            return $this->ban($arr);
        }
        $warning = "You are currently violating a server rule. Further violations will result in an automatic ban that will need to be appealed on our Discord. Review the rules at {$this->rules}. Reason: {$badwords_array['reason']} ({$badwords_array['category']} => $filtered)";
        if ($channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $channel->sendMessage("`$ckey` is" . substr($warning, 7));
        return $this->DirectMessage('AUTOMOD', $warning, $ckey, $server);
    }
    /*
    * This function determines if a player has been warned too many times for a specific category of bad words
    * If they have, it will return false to indicate they should be banned
    * If they have not, it will return true to indicate they should be warned
    */
   private function __relayWarningCounter(string $ckey, array $badwords_array): bool
   {
       if (!isset($this->badwords_warnings[$ckey][$badwords_array['category']])) $this->badwords_warnings[$ckey][$badwords_array['category']] = 1;
       else ++$this->badwords_warnings[$ckey][$badwords_array['category']];
       $this->VarSave('badwords_warnings.json', $this->badwords_warnings);
       if ($this->badwords_warnings[$ckey][$badwords_array['category']] > $badwords_array['warnings']) return false;
       return true;
   }

    /*
    * This function calculates the player's ranking based on their medals
    * Returns true if the required files are successfully read, false otherwise
    */
    public function recalculateRanking(): bool
    {
        if (! isset($this->files['tdm_awards_path']) || ! isset($this->files['ranking_path'])) return false;
        if (! file_exists($this->files['tdm_awards_path']) || ! file_exists($this->files['ranking_path'])) return false;
        if (! $file = @fopen($this->files['tdm_awards_path'], 'r')) return false;
        $result = array();
        while (! feof($file)) {
            $medal_s = 0;
            $duser = explode(';', trim(str_replace(PHP_EOL, '', fgets($file))));
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
            if (!isset($result[$duser[0]])) $result[$duser[0]] = 0;
            $result[$duser[0]] += $medal_s;
        }
        fclose ($file);
        arsort($result);
        if (! $file = @fopen($this->files['ranking_path'], 'w')) return false;
        foreach ($result as $ckey => $score) fwrite($file, "$score;$ckey" . PHP_EOL); // Is this the proper behavior, or should we truncate the file first?
        fclose ($file);
        return true;
    }

    // Check that all required roles are properly declared in the bot's config and exist in the guild
    public function hasRequiredConfigRoles(array $required_roles = []): bool
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) { $this->logger->error('Guild ' . $this->civ13_guild_id . ' is missing from the bot'); return false; }
        if ($diff = array_diff($required_roles, array_keys($this->role_ids))) { $this->logger->error('Required roles are missing from the `role_ids` config', $diff); return false; }
        foreach ($required_roles as $role) if (!isset($this->role_ids[$role]) || ! $guild->roles->get('id', $this->role_ids[$role])) { $this->logger->error("$role role is missing from the guild"); return false; }
        return true;
    }
    
    // Check that all required files are properly declared in the bot's config and exist in the guild
    public function getRequiredConfigFiles(string $postfix = '', bool $defaults = true, array $lists = []): array|false
    {
        $l = [];
        if ($defaults) {
            $defaultLists = [];
            foreach (array_keys($this->server_settings) as $key) $defaultLists[] = strtolower($key) . $postfix;
            foreach ($defaultLists as $file_path) if (isset($this->files[$file_path]) && ! in_array($file_path, $l)) array_unshift($l, $file_path);
            else $this->logger->warning("Default `$postfix` file `$file_path` was either missing from the `files` config or already included in the list");
            if (empty($l)) $this->logger->debug("No default `$postfix` files were found in the `files` config");
        }
        if ($lists) foreach ($lists as $file_path) if (isset($this->files[$file_path]) && ! in_array($file_path, $l)) array_unshift($l, $file_path);
        if (empty($l)) {
            $this->logger->warning("No `$postfix` files were found");
            return false;
        }
        return $l;
    }

    /*
    * This function is used to update the contents of files based on the roles of verified members
    * The callback function is used to determine what to write to the file
    */
    public function updateFilesFromMemberRoles(callable $callback, array $file_paths, array $required_roles): void
    {
        foreach ($file_paths as $file_path) {
            if (!file_exists($this->files[$file_path]) || ! $file = @fopen($this->files[$file_path], 'a')) continue;
            ftruncate($file, 0);
            $file_contents = '';
            foreach ($this->verified as $item) {
                if (!$member = $this->getVerifiedMember($item)) continue;
                $file_contents .= $callback($member, $item, $required_roles);
            }
            fwrite($file, $file_contents);
            fclose($file);
        }
    }

    // This function is used to update the whitelist files
    public function whitelistUpdate(array $lists = [], bool $defaults = true, string $postfix = '_whistlist'): bool
    {
        $required_roles = ['veteran'];
        if (! $this->hasRequiredConfigRoles($required_roles)) return false;
        if (! $file_paths = $this->getRequiredConfigFiles($postfix, $defaults, $lists)) return false;

        $callback = function($member, array $item, array $required_roles): string
        {
            $string = '';
            foreach ($required_roles as $role)
                if ($member->roles->has($this->role_ids[$role]))
                    $string .= "{$item['ss13']} = {$item['discord']}" . PHP_EOL;
            return $string;
        };
        $this->updateFilesFromMemberRoles($callback, $file_paths, $required_roles);
        return true;
    }

    // This function is used to update the campaign whitelist files
    public function factionlistUpdate(array $lists = [], bool $defaults = true, string $postfix = '_factionlist'): bool
    {
        $required_roles = ['red', 'blue', 'organizer'];
        if (! $this->hasRequiredConfigRoles($required_roles)) return false;
        if (! $file_paths = $this->getRequiredConfigFiles($postfix, $defaults, $lists)) return false;

        $callback = function($member, array $item, array $required_roles): string
        {
            $string = '';
            foreach ($required_roles as $role)
                if ($member->roles->has($this->role_ids[$role]))
                    $string .= "{$item['ss13']};{$role}" . PHP_EOL;
            return $string;
        };
        $this->updateFilesFromMemberRoles($callback, $file_paths, $required_roles);
        return true;
    }

    // This function is used to update the adminlist files
    public function adminlistUpdate(array $lists = [], $defaults = true, string $postfix = '_admins'): bool
    {
        $required_roles = [
            'admiral' => ['Host', '65535'],
            'bishop' => ['Bishop', '65535'],
            'host' => ['Host', '65535'], // Default Host permission, only used if another role is not found first
            'grandmaster' => ['GrandMaster', '16382'],
            'marshall' => ['Marshall', '16382'],
            'knightcommander' => ['KnightCommander', '16382'],
            'captain' => ['Captain', '16382'], // Default High Staff permission, only used if another role is not found first
            'storyteller' => ['StoryTeller', '16254'],
            'squire' => ['Squire', '8708'], // Squires will also have the Knight role, but it takes priority
            'knight' => ['Knight', '12158'],
            'mentor' => ['Mentor', '16384'],
        ];
        if (! $this->hasRequiredConfigRoles(array_keys($required_roles))) return false;
        if (! $file_paths = $this->getRequiredConfigFiles($postfix, $defaults, $lists)) return false;

        $callback = function($member, array $item, array $required_roles): string
        {
            $string = '';
            $checked_ids = [];
            foreach (array_keys($required_roles) as $role)
                if ($member->roles->has($this->role_ids[$role]))
                    if (! in_array($member->id, $checked_ids)) {
                        $string .= $item['ss13'] . ';' . $required_roles[$role][0] . ';' . $required_roles[$role][1] . '|||' . PHP_EOL;
                        $checked_ids[] = $member->id;
                    }
            return $string;
        };
        $this->updateFilesFromMemberRoles($callback, $file_paths, $required_roles);
        return true;
    }
}