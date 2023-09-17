<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Civ13\Slash;
use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\BigInt;
use Discord\Helpers\Collection;
//use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Role;
use Discord\Parts\User\Activity;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use Discord\Repository\Interaction\GlobalCommandRepository;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use React\EventLoop\TimerInterface;
use React\Filesystem\Factory as FilesystemFactory;

class Civ13
{
    public bool $sharding = false;
    public bool $shard = false;
    public string $welcome_message = '';
    
    public MessageHandler $messageHandler;

    public Slash $slash;
    
    public string $webserver_url = 'www.valzargaming.com'; // The URL of the webserver that the bot pulls server information from

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
    public array $softbanned = []; // List of ckeys and discord IDs that are not allowed to go through the verification process
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

    public array $server_settings = [];
    public array $enabled_servers = [];
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
        
        if (isset($options['command_symbol']) && $options['command_symbol']) $this->command_symbol = $options['command_symbol'];
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
        if (isset($options['webserver_url'])) $this->webserver_url = $options['webserver_url'];
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

        $this->enabled_servers = array_keys(array_filter($this->server_settings, function($settings) {
            return isset($settings['enabled']) && $settings['enabled'];
        }));
        
        $this->afterConstruct($options, $server_options);
    }
    
    /**
     * This method generates server functions based on the server settings.
     * It loops through the server settings and generates server functions for each enabled server.
     * For each server, it generates the following message-related functions, prefixed with the server name:
     * - configexists: checks if the server configuration exists.
     * - host: starts the server host process.
     * - kill: kills the server process.
     * - restart: restarts the server process by killing and starting it again.
     * - mapswap: swaps the current map of the server with a new one.
     * - ban: bans a player from the server.
     * - unban: unbans a player from the server.
     * Also, for each server, it generates the following functions:
     * - discord2ooc: relays message to the server's OOC channel.
     * - discord2admin: relays messages to the server's admin channel.
     * 
     * @return void
     */
    protected function generateServerFunctions(): void
    {    
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $server = strtolower($key);

            foreach (['_playernotes_basedir'] as $postfix) {
                if (! $this->getRequiredConfigFiles($postfix, true)) $this->logger->debug("Skipping server function `$server{$postfix}` because the required config files were not found.");
                else {
                    $servernotes = function (Message $message, array $message_filtered) use ($server): PromiseInterface
                    {
                        if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content'], strlen($server.'notes')))) return $this->reply($message, 'Missing ckey! Please use the format `notes ckey`');
                        $first_letter_lower = strtolower(substr($ckey, 0, 1));
                        $first_letter_upper = strtoupper(substr($ckey, 0, 1));
                        
                        $letter_dir = '';
                        if (is_dir($this->files[$server.'_playernotes_basedir'] . "/$first_letter_lower")) $letter_dir = $this->files[$server.'_playernotes_basedir'] . "/$first_letter_lower";
                        else if (is_dir($this->files[$server.'_playernotes_basedir'] . "/$first_letter_upper")) $letter_dir = $this->files[$server.'_playernotes_basedir'] . "/$first_letter_upper";
                        else return $this->reply($message, "No notes found for any ckey starting with `$first_letter_upper`.");

                        $player_dir = '';
                        $dirs = [];
                        $scandir = scandir($letter_dir);
                        if ($scandir) $dirs = array_filter($scandir, function($dir) use ($ckey) {
                            return strtolower($dir) === strtolower($ckey)/* && is_dir($letter_dir . "/$dir")*/;
                        });
                        if (count($dirs) > 0) $player_dir = $letter_dir . "/" . reset($dirs);
                        else return $this->reply($message, "No notes found for `$ckey`.");

                        if (file_exists($player_dir . "/info.sav")) $file_path = $player_dir . "/info.sav";
                        else return $this->reply($message, "A notes folder was found for `$ckey`, however no notes were found in it.");

                        $result = '';
                        if ($contents = file_get_contents($file_path)) $result = $contents;
                        else return $this->reply($message, "A notes file with path `$file_path` was found for `$ckey`, however the file could not be read.");
                        
                        return $this->reply($message, $result, 'info.sav', true);
                    };
                    $this->messageHandler->offsetSet($server.'notes', $servernotes, ['Owner', 'High Staff', 'Admin']);
                }
            }
            
            $serverconfigexists = function (?Message $message = null) use ($key): PromiseInterface|bool
            {
                if (isset($this->server_settings[$key])) {
                    if ($message) return $message->react("👍");
                    return true;
                }
                if ($message) return $message->react("👎");
                return false;
            };
            $this->logger->info("Generating {$server}configexists command.");
            $this->messageHandler->offsetSet($server.'configexists', $serverconfigexists, ['Owner', 'High Staff']);

            foreach (['_updateserverabspaths', '_serverdata', '_killsudos', '_dmb'] as $postfix) {
                if (! $this->getRequiredConfigFiles($postfix, true)) $this->logger->debug("Skipping server function `$server{$postfix}` because the required config files were not found.");
                else {
                    $serverhost = function (?Message $message = null) use ($server, $settings): void
                    {
                        \execInBackground("python3 {$this->files[$server.'_updateserverabspaths']}");
                        \execInBackground("rm -f {$this->files[$server.'_serverdata']}");
                        \execInBackground("python3 {$this->files[$server.'_killsudos']}");
                        if (! isset($this->timers[$server.'host'])) $this->timers[$server.'host'] = $this->discord->getLoop()->addTimer(30, function () use ($server, $settings) {
                            \execInBackground("DreamDaemon {$this->files[$server.'_dmb']} {$settings['port']} -trusted -webclient -logself &");
                        });
                        if ($message) $message->react("👍");
                    };
                    $this->messageHandler->offsetSet($server.'host', $serverhost, ['Owner', 'High Staff']);
                }
            }
            foreach (['_killciv13'] as $postfix) {
                if (! $this->getRequiredConfigFiles($postfix, true)) $this->logger->debug("Skipping server function `$server{$postfix}` because the required config files were not found.");
                else {
                    $serverkill = function (?Message $message = null) use ($server): void
                    {
                        \execInBackground("python3 {$this->files[$server.'_killciv13']}");
                        if ($message) $message->react("👍");
                    };
                    $this->messageHandler->offsetSet($server.'kill', $serverkill, ['Owner', 'High Staff']);
                }
            }
            if ($this->messageHandler->offsetExists($server.'host') && $this->messageHandler->offsetExists($server.'kill')) {
                $serverrestart = function (?Message $message = null) use ($server): ?PromiseInterface
                {
                    $kill = $this->messageHandler->offsetGet($server.'kill') ?? [];
                    $host = $this->messageHandler->offsetGet($server.'host') ?? [];
                    if (
                        ($kill = array_shift($kill))
                        && ($host = array_shift($host))
                    ) {
                        $kill();
                        $host();
                        if ($message) return $message->react("👍");
                    }
                    if ($message) return $message->react("🔥");
                };
                $this->messageHandler->offsetSet($server.'restart', $serverrestart, ['Owner', 'High Staff']);
            }

            foreach (['_mapswap'] as $postfix) {
                if (! $this->getRequiredConfigFiles($postfix, true)) $this->logger->debug("Skipping server function `$server{$postfix}` because the required config files were not found.");
                else {
                    $servermapswap = function (?Message $message = null, array $message_filtered = ['message_content' => '', 'message_content_lower' => '', 'called' => false]) use ($server): PromiseInterface|bool
                    {
                        $mapswap = function (string $mapto, ?Message $message = null, ) use ($server): ?PromiseInterface
                        {
                            if (! file_exists($this->files['map_defines_path']) || ! $file = @fopen($this->files['map_defines_path'], 'r')) {
                                $this->logger->error("unable to open `{$this->files['map_defines_path']}` for reading.");
                                if ($message) return $this->reply($message, "unable to open `{$this->files['map_defines_path']}` for reading.");
                            }
                        
                            $maps = array();
                            while (($fp = fgets($file, 4096)) !== false) {
                                $linesplit = explode(' ', trim(str_replace('"', '', $fp)));
                                if (isset($linesplit[2]) && $map = trim($linesplit[2])) $maps[] = $map;
                            }
                            fclose($file);
                            if (! in_array($mapto, $maps)) return $this->reply($message, "`$mapto` was not found in the map definitions.");
                            
                            \execInBackground("python3 {$this->files[$server.'_mapswap']} $mapto");
                            if ($message) return $this->reply($message, "Attempting to change `$server` map to `$mapto`");
                        };
                        $split_message = explode($server.'mapswap ', $message_filtered['message_content']);
                        if (count($split_message) < 2 || !($mapto = strtoupper($split_message[1]))) return $this->reply($message, 'You need to include the name of the map.');
                        return $mapswap($mapto, $message);
                    };
                    $this->messageHandler->offsetSet($server.'mapswap', $servermapswap, ['Owner', 'High Staff']);
                }
            }

            $serverban = function (Message $message, array $message_filtered) use ($server, $key): PromiseInterface
            {
                if (! $this->hasRequiredConfigRoles(['banished'])) $this->logger->debug("Skipping server function `$server ban` because the required config roles were not found.");
                if (! $message_content = substr($message_filtered['message_content'], strlen($key.'ban'))) return $this->reply($message, 'Missing ban ckey! Please use the format `{server}ban ckey; duration; reason`');
                $split_message = explode('; ', $message_content); // $split_target[1] is the target
                if (! $split_message[0]) return $this->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
                if (! $split_message[1]) return $this->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
                if (! $split_message[2]) return $this->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
                $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->banappeal}"];
                $result = $this->ban($arr, $this->getVerifiedItem($message->author)['ss13'], null, $key);
                if ($member = $this->getVerifiedMember('id', $split_message[0]))
                    if (! $member->roles->has($this->role_ids['banished']))
                        $member->addRole($this->role_ids['banished'], $result);
                return $this->reply($message, $result);
            };
            $this->messageHandler->offsetSet($server.'ban', $serverban, ['Owner', 'High Staff', 'Admin']);

            $serverunban = function (Message $message, array $message_filtered) use ($key): PromiseInterface
            {
                if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($key.'unban')))) return $this->reply($message, 'Missing unban ckey! Please use the format `{server}unban ckey`');
                if (is_numeric($ckey)) {
                    if (! $item = $this->getVerifiedItem($ckey)) return $this->reply($message, "No data found for Discord ID `$ckey`.");
                    $ckey = $item['ckey'];
                }
                
                $this->unban($ckey, $admin = $this->getVerifiedItem($message->author)['ss13'], $key);
                $result = "**$admin** unbanned **$ckey** from **$key**";
                if (! $this->sharding)
                    if ($member = $this->getVerifiedMember('id', $ckey))
                        if ($member->roles->has($this->role_ids['banished']))
                            $member->removeRole($this->role_ids['banished'], $result);
                return $this->reply($message, $result);
            };
            $this->messageHandler->offsetSet($server.'unban',  $serverunban, ['Owner', 'High Staff', 'Admin']);
        }
    }

    /*
     * The generated functions include `ping`, `help`, `cpu`, `approveme`, and `insult`.
     * The `ping` function replies with "Pong!" when called.
     * The `help` function generates a list of available commands based on the user's roles.
     * The `cpu` function returns the CPU usage of the system.
     * The `approveme` function verifies a user's identity and assigns them the `infantry` role.
     * And more! (see the code for more details)
     */
    protected function generateGlobalFunctions(): void
    { // TODO: add infantry and veteran roles to all non-staff command parameters except for `approveme`
        $this->messageHandler->offsetSet('ping', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            return $this->reply($message, 'Pong!');
        }));

        $help = new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            return $this->reply($message, $this->messageHandler->generateHelp($message->member->roles), 'help.txt', true);
        });
        $this->messageHandler->offsetSet('help', $help);
        $this->messageHandler->offsetSet('commands', $help);

        $this->messageHandler->offsetSet('cpu', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            if (PHP_OS_FAMILY == "Windows") {
                $p = shell_exec('powershell -command "gwmi Win32_PerfFormattedData_PerfOS_Processor | select PercentProcessorTime"');
                $p = preg_replace('/\s+/', ' ', $p); // reduce spaces
                $p = str_replace('PercentProcessorTime', '', $p);
                $p = str_replace('--------------------', '', $p);
                $p = preg_replace('/\s+/', ' ', $p); // reduce spaces
                $load_array = explode(' ', $p);

                $x=0;
                $load = '';
                foreach ($load_array as $line) if (trim($line) && $x == 0) { $load = "CPU Usage: $line%" . PHP_EOL; break; }
                return $this->reply($message, $load);
            } else { // Linux
                $cpu_load = ($cpu_load_array = sys_getloadavg())
                    ? $cpu_load = array_sum($cpu_load_array) / count($cpu_load_array)
                    : '-1';
                return $this->reply($message, "CPU Usage: $cpu_load%");
            }
            return $this->reply($message, 'Unrecognized operating system!');
        }));
        $this->messageHandler->offsetSet('checkip', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            return $message->reply(file_get_contents('http://ipecho.net/plain'));
        }));
        /**
         * This method retrieves information about a ckey, including primary identifiers, IPs, CIDs, and dates.
         * It also iterates through playerlogs ban logs to find all known ckeys, IPs, and CIDs.
         * If the user has high staff privileges, it also displays primary IPs and CIDs.
         * @param Message $message The message object.
         * @param array $message_filtered The filtered message content.
         * @param string $command The command used to trigger this method.
         * @return PromiseInterface
         */
        $this->messageHandler->offsetSet('ckeyinfo', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $high_rank_check = function (Message $message, array $allowed_ranks = []): bool
            {
                $resolved_ranks = [];
                foreach ($allowed_ranks as $rank) if (isset($this->role_ids[$rank])) $resolved_ranks[] = $this->role_ids[$rank];
                foreach ($message->member->roles as $role) if (in_array($role->id, $resolved_ranks)) return true;
                return false;
            };
            $high_staff = $high_rank_check($message, ['Owner', 'High Staff']);
            if (! $id = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Invalid format! Please use the format: ckeyinfo `ckey`');
            if (is_numeric($id)) {
                if (! $item = $this->getVerifiedItem($id)) return $this->reply($message, "No data found for Discord ID `$id`.");
                $ckey = $item['ss13'];
            } else $ckey = $id;
            if (! $collectionsArray = $this->getCkeyLogCollections($ckey)) return $this->reply($message, 'No data found for that ckey.');

            $embed = new Embed($this->discord);
            $embed->setTitle($ckey);
            if ($item = $this->getVerifiedItem($ckey)) {
                $ckey = $item['ss13'];
                if ($member = $this->getVerifiedMember($item))
                    $embed->setAuthor("{$member->user->displayname} ({$member->id})", $member->avatar);
            }
            $ckeys = [$ckey];
            $ips = [];
            $cids = [];
            $dates = [];
            // Get the ckey's primary identifiers
            foreach ($collectionsArray[0] as $log) {
                if (isset($log['ip']) && ! in_array($log['ip'], $ips)) $ips[] = $log['ip'];
                if (isset($log['cid']) && ! in_array($log['cid'], $cids)) $cids[] = $log['cid'];
                if (isset($log['date']) && ! in_array($log['date'], $dates)) $dates[] = $log['date'];
            }
            foreach ($collectionsArray[1] as $log) {
                if (isset($log['ip']) && ! in_array($log['ip'], $ips)) $ips[] = $log['ip'];
                if (isset($log['cid']) && ! in_array($log['cid'], $cids)) $cids[] = $log['cid'];
                if (isset($log['date']) && ! in_array($log['date'], $dates)) $dates[] = $log['date'];
            }
            $ckey_age = [];
            if (! empty($ckeys)) {
                foreach ($ckeys as $c) ($age = $this->getByondAge($c)) ? $ckey_age[$c] = $age : $ckey_age[$c] = "N/A";
                $ckey_age_string = '';
                foreach ($ckey_age as $key => $value) $ckey_age_string .= " $key ($value) ";
                if ($ckey_age_string) $embed->addFieldValues('Primary Ckeys', trim($ckey_age_string));
            }
            if ($high_staff) {
                if (! empty($ips) && $ips) $embed->addFieldValues('Primary IPs', implode(', ', $ips), true);
                if (! empty($cids) && $cids) $embed->addFieldValues('Primary CIDs', implode(', ', $cids), true);
            }
            if (! empty($dates) && $dates) $embed->addFieldValues('Primary Dates', implode(', ', $dates));

            // Iterate through the playerlogs ban logs to find all known ckeys, ips, and cids
            $playerlogs = $this->playerlogsToCollection(); // This is ALL players
            $i = 0;
            $break = false;
            do { // Iterate through playerlogs to find all known ckeys, ips, and cids
                $found = false;
                $found_ckeys = [];
                $found_ips = [];
                $found_cids = [];
                $found_dates = [];
                foreach ($playerlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                    if (! in_array($log['ckey'], $ckeys)) { $found_ckeys[] = $log['ckey']; $found = true; }
                    if (! in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                    if (! in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
                    if (! in_array($log['date'], $dates)) { $found_dates[] = $log['date']; }
                }
                $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
                $ips = array_unique(array_merge($ips, $found_ips));
                $cids = array_unique(array_merge($cids, $found_cids));
                $dates = array_unique(array_merge($dates, $found_dates));
                if ($i > 10) $break = true;
                $i++;
            } while ($found && ! $break); // Keep iterating until no new ckeys, ips, or cids are found

            $banlogs = $this->bansToCollection();
            $this->bancheck($ckey)
                ? $banned = 'Yes'
                : $banned = 'No';
            $found = true;
            $i = 0;
            $break = false;
            do { // Iterate through playerlogs to find all known ckeys, ips, and cids
                $found = false;
                $found_ckeys = [];
                $found_ips = [];
                $found_cids = [];
                $found_dates = [];
                foreach ($banlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                    if (! in_array($log['ckey'], $ips)) { $found_ckeys[] = $log['ckey']; $found = true; }
                    if (! in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                    if (! in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
                    if (! in_array($log['date'], $dates)) { $found_dates[] = $log['date']; }
                }
                $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
                $ips = array_unique(array_merge($ips, $found_ips));
                $cids = array_unique(array_merge($cids, $found_cids));
                $dates = array_unique(array_merge($dates, $found_dates));
                if ($i > 10) $break = true;
                $i++;
            } while ($found && ! $break); // Keep iterating until no new ckeys, ips, or cids are found
            $altbanned = 'No';
            foreach ($ckeys as $key) if ($key != $ckey) if ($this->bancheck($key)) { $altbanned = 'Yes'; break; }

            $verified = 'No';
            if ($this->verified->get('ss13', $ckey)) $verified = 'Yes';
            if (! empty($ckeys) && $ckeys) {
                foreach ($ckeys as $c) if (! isset($ckey_age[$c])) ($age = $this->getByondAge($c)) ? $ckey_age[$c] = $age : $ckey_age[$c] = "N/A";
                $ckey_age_string = '';
                foreach ($ckey_age as $key => $value) $ckey_age_string .= "$key ($value) ";
                if ($ckey_age_string) $embed->addFieldValues('Matched Ckeys', trim($ckey_age_string));
            }
            if ($high_staff) {
                if (! empty($ips) && $ips) $embed->addFieldValues('Matched IPs', implode(', ', $ips), true);
                if (! empty($cids) && $cids) $embed->addFieldValues('Matched CIDs', implode(', ', $cids), true);
            }
            if (! empty($ips) && $ips) {
                $regions = [];
                foreach ($ips as $ip) if (! in_array($region = $this->IP2Country($ip), $regions)) $regions[] = $region;
                if ($regions) $embed->addFieldValues('Regions', implode(', ', $regions));
            }
            if (! empty($dates) && $dates && strlen($dates_string = implode(', ', $dates)) <= 1024) $embed->addFieldValues('Dates', $dates_string);
            if ($verified) $embed->addfieldValues('Verified', $verified, true);
            $discords = [];
            if ($ckeys) foreach ($ckeys as $c) if ($item = $this->verified->get('ss13', $c)) $discords[] = $item['discord'];
            if ($discords) {
                foreach ($discords as &$id) $id = "<@{$id}>";
                $embed->addfieldValues('Discord', implode(', ', $discords));
            }
            if ($banned) $embed->addfieldValues('Currently Banned', $banned, true);
            if ($altbanned) $embed->addfieldValues('Alt Banned', $altbanned, true);
            $embed->addfieldValues('Ignoring banned alts or new account age', isset($this->permitted[$ckey]) ? 'Yes' : 'No', true);
            $builder = MessageBuilder::new();
            if (! $high_staff) $builder->setContent('IPs and CIDs have been hidden for privacy reasons.');
            $builder->addEmbed($embed);
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);
        
        if (! $this->shard) {
            if (isset($this->role_ids['infantry']))
            $this->messageHandler->offsetSet('approveme', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
            {
                if ($message->member->roles->has($this->role_ids['infantry']) || (isset($this->role_ids['veteran']) && $message->member->roles->has($this->role_ids['veteran']))) return $this->reply($message, 'You already have the verification role!');
                if ($item = $this->getVerifiedItem($message->author)) {
                    $message->member->setRoles([$this->role_ids['infantry']], "approveme {$item['ss13']}");
                    return $message->react("👍");
                }
                if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Invalid format! Please use the format `approveme ckey`');
                return $this->reply($message, $this->verifyProcess($ckey, $message->user_id));
            }));

            if (file_exists($this->files['insults_path']))
            $this->messageHandler->offsetSet('insult', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
            {
                $split_message = explode(' ', $message_filtered['message_content']); // $split_target[1] is the target
                if ((count($split_message) <= 1 ) || ! strlen($split_message[1] === 0)) return null;
                if (! ($file = @fopen($this->files['insults_path'], 'r'))) return $message->react("🔥");
                $insults_array = array();
                while (($fp = fgets($file, 4096)) !== false) $insults_array[] = $fp;
                if (count($insults_array) > 0) return $message->channel->sendMessage(MessageBuilder::new()->setContent($split_message[1] . ', ' . $insults_array[rand(0, count($insults_array)-1)])->setAllowedMentions(['parse'=>[]]));
                return $this->reply($message, 'No insults found!');
            }));

            $this->messageHandler->offsetSet('discord2ckey', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) {
                if (! $item = $this->verified->get('discord', $id = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->reply($message, "`$id` is not registered to any byond username");
                return $this->reply($message, "`$id` is registered to `{$item['ss13']}`");
            }));

            $this->messageHandler->offsetSet('ckey2discord', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) {
                if (! $item = $this->verified->get('ss13', $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->reply($message, "`$ckey` is not registered to any discord id");
                return $this->reply($message, "`$ckey` is registered to <@{$item['discord']}>");
            }));

            $this->messageHandler->offsetSet('ckey', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
            {
                //if (str_starts_with($message_filtered['message_content_lower'], 'ckeyinfo')) return null; // This shouldn't happen, but just in case...
                if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) {
                    if (! $item = $this->getVerifiedItem($ckey = $message->user_id)) return $this->reply($message, "You are not registered to any byond username");
                    return $this->reply($message, "You are registered to `{$item['ss13']}`");
                }
                if (is_numeric($ckey)) {
                    if (! $item = $this->getVerifiedItem($ckey)) return $this->reply($message, "`$ckey` is not registered to any ckey");
                    if (! $age = $this->getByondAge($item['ss13'])) return $this->reply($message, "`{$item['ss13']}` does not exist");
                    return $this->reply($message, "`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
                }
                if (! $age = $this->getByondAge($ckey)) return $this->reply($message, "`$ckey` does not exist");
                if ($item = $this->getVerifiedItem($ckey)) return $this->reply($message, "`{$item['ss13']}` is registered to <@{$item['discord']}> ($age)");
                return $this->reply($message, "`$ckey` is not registered to any discord id ($age)");
            }));

            $this->messageHandler->offsetSet('fullbancheck', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
            {
                foreach ($message->guild->members as $member)
                    if ($item = $this->getVerifiedItem($member->id))
                        $this->bancheck($item['ss13']);
                return $message->react("👍");
            }), ['Owner', 'High Staff']);
            
            $this->messageHandler->offsetSet('register', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
            { // This function is only authorized to be used by the database administrator
                if ($this->shard) return null;
                if ($message->user_id != $this->technician_id) return $message->react("❌");
                $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))));
                if (! $ckey = $this->sanitizeInput($split_message[0])) return $this->reply($message, 'Byond username was not passed. Please use the format `register <byond username>; <discord id>`.');
                if (! is_numeric($discord_id = $this->sanitizeInput($split_message[1]))) return $this->reply($message, "Discord id `$discord_id` must be numeric.");
                return $this->reply($message, $this->registerCkey($ckey, $discord_id)['error']);
            }), ['Chief Technical Officer']);

            $this->messageHandler->offsetSet('discard', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
            {
                if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Byond username was not passed. Please use the format `discard <byond username>`.');
                $string = "`$ckey` will no longer attempt to be automatically registered.";
                if (isset($this->provisional[$ckey])) {
                    if ($member = $message->guild->members->get($this->provisional[$ckey])) {
                        $member->removeRole($this->role_ids['infantry']);
                        $string .= " The <@&{$this->role_ids['infantry']}> role has been removed from $member.";
                    }
                    unset($this->provisional[$ckey]);
                    $this->VarSave('provisional.json', $this->provisional);
                }
                return $this->reply($message, $string);
            }), ['Owner', 'High Staff', 'Admin']);
            
            if (isset($this->role_ids['paroled'], $this->channel_ids['parole_logs'])) {
                $release = function (Message $message, array $message_filtered, string $command): ?PromiseInterface
                {
                    if (! $item = $this->getVerifiedItem($id = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->reply($message, "<@{$id}> is not currently verified with a byond username or it does not exist in the cache yet");
                    $this->paroleCkey($ckey = $item['ss13'], $message->user_id, false);
                    $admin = $this->getVerifiedItem($message->author)['ss13'];
                    if ($member = $this->getVerifiedMember($item))
                        if ($member->roles->has($this->role_ids['paroled']))
                            $member->removeRole($this->role_ids['paroled'], "`$admin` ({$message->member->displayname}) released `$ckey`");
                    if ($channel = $this->discord->getChannel($this->channel_ids['parole_logs'])) $this->sendMessage($channel, "`$ckey` (<@{$item['discord']}>) has been released from parole by `$admin` (<@{$message->user_id}>).");
                    return $message->react("👍");
                };
                $this->messageHandler->offsetSet('release', new MessageHandlerCallback($release), ['Owner', 'High Staff', 'Admin']);
            }

            $this->messageHandler->offsetSet('tests', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
            {
                $tokens = explode(' ', trim(substr($message_filtered['message_content'], strlen($command))));
                if (! isset($tokens[0]) || ! $tokens[0]) {
                    if (empty($this->tests)) return $this->reply($message, "No tests have been created yet! Try creating one with `tests add {test_key} {question}`");
                    if (array_keys($this->tests)) $reply = 'Available tests: `' . implode('`, `', array_keys($this->tests)) . '`';
                    $reply .= PHP_EOL . 'Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`';
                    return $this->reply($message, 'Available tests: `' . implode('`, `', array_keys($this->tests)) . '`');
                }
                if (! isset($tokens[1]) || ! $tokens[1] || ! $test_key = $tokens[1]) return $this->reply($message, 'Invalid format! You must include the name of the test, e.g. `tests list {test_key}.');
                if (! isset($this->tests[$test_key])) return $this->reply($message, "Test `$test_key` hasn't been created yet! Please add a question first.");
                if ($tokens[0] == 'list') return $message->reply(MessageBuilder::new()->addFileFromContent("$test_key.txt", var_export($this->tests[$test_key], true))->setContent('Number of questions: ' . count(array_keys($this->tests[$test_key]))));
                if ($tokens[0] == 'delete') {
                    if (isset($tokens[2])) return $this->reply($message, "Invalid format! Please use the format `tests delete {test_key}`"); // Prevents accidental deletion of tests
                    unset($this->tests[$test_key]);
                    $this->VarSave('tests.json', $this->tests);
                    return $this->reply($message, "Deleted test `$test_key`");
                }
                if ($tokens[0] == 'add') {
                    unset($tokens[1], $tokens[0]);
                    if (! $question = implode(' ', $tokens)) return $this->reply($message, 'Invalid format! Please use the format `tests add {test_key} {question}`');
                    $this->tests[$test_key][] = $question;
                    $this->VarSave('tests.json', $this->tests);
                    return $this->reply($message, "Added question to test `$test_key`: `$question`");
                }
                if ($tokens[0] == 'remove') {
                    if (! isset($tokens[2]) || ! is_numeric($tokens[2])) return $this->reply($message, "Invalid format! Please use the format `tests remove {test_key} {question #}`");
                    if (! isset($this->tests[$test_key][$tokens[2]])) return $this->reply($message, "Question not found in test `$test_key`! Please use the format `tests {test_key} remove {question #}`");
                    $question = $this->tests[$test_key][$tokens[2]];
                    unset($this->tests[$test_key][$tokens[2]]);
                    $this->VarSave('tests.json', $this->tests);
                    return $this->reply($message, "Removed question `{$tokens[2]}`: `$question`");
                }
                if ($tokens[0] == 'post') {
                    if (! isset($tokens[2]) || ! is_numeric($tokens[2])) return $this->reply($message, "Invalid format! Please use the format `tests post {test_key} {# of questions}`");
                    if (count($this->tests[$test_key])<$tokens[2]) return $this->reply($message, "Can't return more questions than exist in a test!");
                    $questions = [];
                    $picked = [];
                    while (count($questions)<$tokens[2]) if (! in_array($this->tests[$test_key][$rand = array_rand($this->tests[$test_key])], $questions)) if (! in_array($rand, $picked)) {
                        $picked[] = $rand;
                        $questions[] = $this->tests[$test_key][$rand];
                    }
                    return $this->reply($message, implode(PHP_EOL, $questions));
                }
                return $this->reply($message, 'Invalid format! Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`');
            }), ['Owner', 'High Staff']);

            if (isset($this->functions['misc']['promotable_check']) && $promotable_check = $this->functions['misc']['promotable_check']) {
                $promotable = new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($promotable_check): PromiseInterface
                {
                    if (! $promotable_check($this, $this->sanitizeInput(substr($message_filtered['message_content'], strlen($command))))) return $message->react("👎");
                    return $message->react("👍");
                });
                $this->messageHandler->offsetSet('promotable', $promotable, ['Owner', 'High Staff']);
            }

            if (isset($this->functions['misc']['mass_promotion_loop']) && $mass_promotion_loop = $this->functions['misc']['mass_promotion_loop'])
            $this->messageHandler->offsetSet('mass_promotion_loop', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($mass_promotion_loop): PromiseInterface
            {
                if (! $mass_promotion_loop($this)) return $message->react("👎");
                return $message->react("👍");
            }), ['Owner', 'High Staff']);

            if (isset($this->functions['misc']['mass_promotion_check']) && $mass_promotion_check = $this->functions['misc']['mass_promotion_check'])
            $this->messageHandler->offsetSet('mass_promotion_check', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($mass_promotion_check): PromiseInterface
            {
                if ($promotables = $mass_promotion_check($this)) return $message->reply(MessageBuilder::new()->addFileFromContent('promotables.txt', json_encode($promotables)));
                return $message->react("👎");
            }), ['Owner', 'High Staff']);
            //
        }

        $this->messageHandler->offsetSet('ooc', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
        {
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $server = strtolower($key);
                switch (strtolower($message->channel->name)) {
                    case "ooc-{$server}":                    
                        if ($this->OOCMessage($message_filtered['message_content'], $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname, $key)) return $message->react("📧");
                        return $message->react("🔥");
                }
            }
            if ($this->sharding) return null;
            return $this->reply($message, 'You need to be in any of the #ooc channels to use this command.');
        }));

        $this->messageHandler->offsetSet('asay', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $server = strtolower($key);
                switch (strtolower($message->channel->name)) {
                    case "asay-{$server}":
                        if ($this->AdminMessage($message_filtered['message_content'], $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname, $key)) return $message->react("📧");
                        return $message->react("🔥");
                }
            }
            if ($this->sharding) return null;
            return $this->reply($message, 'You need to be in any of the #asay channels to use this command.');
        }));

        $this->messageHandler->offsetSet('globalooc', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
        {
            if ($this->OOCMessage($message_filtered['message_content'], $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname)) return $message->react("📧");
            if ($this->sharding) return null;
            return $message->react("🔥");
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('globalasay', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
        {
            if ($this->AdminMessage($message_filtered['message_content'], $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname)) return $message->react("📧");
            if ($this->sharding) return null;
            return $message->react("🔥");
        }), ['Owner', 'High Staff', 'Admin']);

        $directmessage = new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $explode = explode(';', $message_filtered['message_content']);
            $recipient = $this->sanitizeInput(substr(array_shift($explode), strlen($command)));
            $msg = implode(' ', $explode);
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $server = strtolower($key);
                switch (strtolower($message->channel->name)) {
                    case "asay-{$server}":
                    case "ic-{$server}":
                    case "ooc-{$server}":
                        if ($this->DirectMessage($recipient, $msg, $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname, $key)) return $message->react("📧");
                        return $message->react("🔥");
                }
            }
            if ($this->sharding) return null;
            return $this->reply($message, 'You need to be in any of the #ic, #asay, or #ooc channels to use this command.');
        }, ['Owner', 'High Staff', 'Admin']);
        $this->messageHandler->offsetSet('dm', $directmessage);
        $this->messageHandler->offsetSet('pm', $directmessage);

        $this->messageHandler->offsetSet('bancheck', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) {
            if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Wrong format. Please try `bancheck [ckey]`.');
            if (is_numeric($ckey)) {
                if (! $item = $this->verified->get('discord', $ckey)) return $this->reply($message, "No ckey found for Discord ID `$ckey`.");
                $ckey = $item['ss13'];
            }
            $reason = 'unknown';
            $found = false;
            $content = '';
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $file_path = strtolower($key) . '_bans';
                if (! isset($this->files[$file_path]) || ! file_exists($this->files[$file_path]) || ! ($file = @fopen($this->files[$file_path], 'r'))) {
                    $this->logger->warning("Could not open `$file_path` for reading.");
                    continue;
                }
                while (($fp = fgets($file, 4096)) !== false) {
                    $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                    if ((count($linesplit)>=8) && ($linesplit[8] == strtolower($ckey))) {
                        $found = true;
                        $type = $linesplit[0];
                        $reason = $linesplit[3];
                        $admin = $linesplit[4];
                        $date = $linesplit[5];
                        $content .= "**$ckey** has been **$type** banned from **$key** on **$date** for **$reason** by $admin." . PHP_EOL;
                    }
                }
                fclose($file);
            }
            if (! $found) $content .= "No bans were found for **$ckey**." . PHP_EOL;
            elseif (isset($this->role_ids['banished']) && $member = $this->getVerifiedMember($ckey))
                if (! $member->roles->has($this->role_ids['banished']))
                    $member->addRole($this->role_ids['banished']);
            return $this->reply($message, $content, 'bancheck.txt');
        }));
        
        /**
         * Changes the relay method between 'file' and 'webhook' and sends a message to confirm the change.
         *
         * @param Message $message The message object received from the user.
         * @param array $message_filtered An array of filtered message content.
         * @param string $command The command string.
         *
         * @return PromiseInterface
         */
        $this->messageHandler->offsetSet('ckeyrelayinfo', new MessageHandlerCallback(function (Message $message, array $message_filtered = [], string $command = 'ckeyrelayinfo'): PromiseInterface
        {
            $this->relay_method === 'file'
                ? $method = 'webhook'
                : $method = 'file';
            $this->relay_method = $method;
            return $this->reply($message, "Relay method changed to `$method`.");
        }), ['Owner', 'High Staff']);    

        $this->messageHandler->offsetSet('fullaltcheck', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $ckeys = [];
            $members = $message->guild->members->filter(function (Member $member) { return !$member->roles->has($this->role_ids['banished']); });
            foreach ($members as $member)
                if ($item = $this->getVerifiedItem($member->id)) {
                    $ckeyinfo = $this->ckeyinfo($item['ss13']);
                    if (count($ckeyinfo['ckeys']) > 1)
                        $ckeys = array_unique(array_merge($ckeys, $ckeyinfo['ckeys']));
                }
            if ($ckeys) {
                $builder = MessageBuilder::new();
                $builder->addFileFromContent('alts.txt', '`'.implode('`' . PHP_EOL . '`', $ckeys));
                $builder->setContent('The following ckeys are alt accounts of unbanned verified players.');
                return $message->reply($builder);
            }
            return $this->reply($message, 'No alts found.');
        }), ['Owner', 'High Staff']);

        $this->messageHandler->offsetSet('permitted', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            if (empty($this->permitted)) return $this->reply($message, 'No users have been permitted to bypass the Byond account restrictions.');
            return $this->reply($message, 'The following ckeys are now permitted to bypass the Byond account limit and restrictions: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', array_keys($this->permitted)) . '`');
        }), ['Owner', 'High Staff', 'Admin'], 'exact');

        $this->messageHandler->offsetSet('permit', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $this->permitCkey($ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))));
            return $this->reply($message, "$ckey is now permitted to bypass the Byond account restrictions.");
        }), ['Owner', 'High Staff', 'Admin']);

        $revoke = new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $this->permitCkey($ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))), false);
            return $this->reply($message, "$ckey is no longer permitted to bypass the Byond account restrictions.");
        });
        $this->messageHandler->offsetSet('revoke', $revoke, ['Owner', 'High Staff', 'Admin']);
        $this->messageHandler->offsetSet('unpermit', $revoke, ['Owner', 'High Staff', 'Admin']); // Alias for revoke
        
        if (isset($this->role_ids['paroled'], $this->channel_ids['parole_logs'])) {
            $parole = function (Message $message, array $message_filtered, string $command): PromiseInterface
            {
                if (! $item = $this->getVerifiedItem($id = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))))) return $this->reply($message, "<@{$id}> is not currently verified with a byond username or it does not exist in the cache yet");
                $this->paroleCkey($ckey = $item['ss13'], $message->user_id, true);
                $admin = $this->getVerifiedItem($message->author)['ss13'];
                if ($member = $this->getVerifiedMember($item))
                    if (! $member->roles->has($this->role_ids['paroled']))
                        $member->addRole($this->role_ids['paroled'], "`$admin` ({$message->member->displayname}) paroled `$ckey`");
                if ($channel = $this->discord->getChannel($this->channel_ids['parole_logs'])) $this->sendMessage($channel, "`$ckey` (<@{$item['discord']}>) has been placed on parole by `$admin` (<@{$message->user_id}>).");
                return $message->react("👍");
            };
            $this->messageHandler->offsetSet('parole', new MessageHandlerCallback($parole), ['Owner', 'High Staff', 'Admin']);
        }

        $this->messageHandler->offsetSet('refresh', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            if ($this->getVerified()) return $message->react("👍");
            return $message->react("👎");
        }), ['Owner', 'High Staff', 'Admin']);

        $banlog_update = function (string $banlog, array $playerlogs, ?string $ckey = null): string
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
                    if ($a[8] == $log[0]) {
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
        
        $this->messageHandler->offsetSet('listbans', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            return $this->banlogHandler($message, trim(substr($message_filtered['message_content_lower'], strlen($command))));
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('softban', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $this->softban($id = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))));
            return $this->reply($message, "`$id` is no longer allowed to get verified.");
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('unsoftban', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $this->softban($id = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command))), false);
            return $this->reply($message, "`$id` is allowed to get verified again.");
        }), ['Owner', 'High Staff', 'Admin']);
        
        $this->messageHandler->offsetSet('ban', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($banlog_update): PromiseInterface
        {
            $message_filtered['message_content'] = substr($message_filtered['message_content'], trim(strlen($command)));
            $split_message = explode('; ', $message_filtered['message_content']);
            if (! $split_message[0] = $this->sanitizeInput($split_message[0])) return $this->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
            if (! isset($split_message[1]) || ! $split_message[1]) return $this->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
            if (! isset($split_message[2]) || ! $split_message[2]) return $this->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
            $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->banappeal}"];
    
            foreach ($this->server_settings as $key => $settings) { // TODO: Review this for performance and redundancy
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $server = strtolower($key);
                if (! isset($this->timers['banlog_update_'.$server])) $this->timers['banlog_update_'.$server] = $this->discord->getLoop()->addTimer(30, function () use ($banlog_update, $arr) {
                    $playerlogs = [];
                    foreach ($this->server_settings as $k => $s) {
                        if (! isset($s['enabled']) || ! $s['enabled']) continue;
                        $s = strtolower($k);
                        if (! isset($this->files[$s.'_playerlogs']) || ! file_exists($this->files[$s.'_playerlogs'])) continue;
                        if ($playerlog = @file_get_contents($this->files[$s.'_playerlogs'])) $playerlogs[] = $playerlog;
                    }
                    if ($playerlogs) foreach ($this->server_settings as $k => $s) {
                        if (! isset($s['enabled']) || ! $s['enabled']) continue;
                        $s = strtolower($k);
                        if (! isset($this->files[$s.'_bans']) || ! file_exists($this->files[$s.'_bans'])) continue;
                        file_put_contents($this->files[$s.'_bans'], $banlog_update(file_get_contents($this->files[$s.'_bans']), $playerlogs, $arr['ckey']));
                    }
                });
            }
            return $this->reply($message, $this->ban($arr, $this->getVerifiedItem($message->author)['ss13']));
        }), ['Owner', 'High Staff', 'Admin']);
        
        $this->messageHandler->offsetSet('unban', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            if (is_numeric($ckey = $this->sanitizeInput($message_filtered['message_content_lower'] = substr($message_filtered['message_content_lower'], trim(strlen($command))))))
                if (! $item = $this->getVerifiedItem($ckey)) return $this->reply($message, "No data found for Discord ID `$ckey`.");
                else $ckey = $item['ss13'];
            $this->unban($ckey, $admin = $this->getVerifiedItem($message->author)['ss13']);
            return $this->reply($message, "**$admin** unbanned **$ckey**");
        }), ['Owner', 'High Staff', 'Admin']);

        if (isset($this->files['map_defines_path']) && file_exists($this->files['map_defines_path']))
        $this->messageHandler->offsetSet('maplist', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            if (! $file_contents = @file_get_contents($this->files['map_defines_path'])) return $message->react("🔥");
            return $message->reply(MessageBuilder::new()->addFileFromContent('maps.txt', $file_contents));
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('adminlist', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {            
            $builder = MessageBuilder::new();
            $found = false;
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $server = strtolower($key);
                if (! file_exists($this->files[$server.'_admins']) || ! $file_contents = @file_get_contents($this->files[$server.'_admins'])) {
                    $this->logger->debug("`{$server}_admins` is not a valid file path!");
                    continue;
                }
                $builder->addFileFromContent($server.'_admins.txt', $file_contents);
                $found = true;
            }
            if (! $found) return $message->react("🔥");
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('factionlist', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {            
            $builder = MessageBuilder::new()->setContent('Faction Lists');
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $server = strtolower($key);
                if (file_exists($this->files[$server.'_factionlist'])) $builder->addfile($this->files[$server.'_factionlist'], $server.'_factionlist.txt');
                else $this->logger->warning("`{$server}_factionlist` is not a valid file path!");
            }
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);

        if (isset($this->files['tdm_sportsteams']) && file_exists($this->files['tdm_sportsteams']))
        $this->messageHandler->offsetSet('sportsteams', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {            
            if (! $file_contents = @file_get_contents($this->files['tdm_sportsteams'])) return $message->react("🔥");
            return $message->reply(MessageBuilder::new()->addFileFromContent('sports_teams.txt', $file_contents));
        }), ['Owner', 'High Staff', 'Admin']);

        $log_handler = function (Message $message, string $message_content): PromiseInterface
        {
            $tokens = explode(';', $message_content);
            $keys = [];
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $keys[] = $server = strtolower($key);
                if (! trim($tokens[0]) == $server) continue; // Check if server is valid
                if (! isset($this->files[$server.'_log_basedir']) || ! file_exists($this->files[$server.'_log_basedir'])) {
                    $this->logger->warning("`{$server}_log_basedir` is not defined or does not exist");
                    return $message->react("🔥");
                }
                unset($tokens[0]);
                $results = $this->FileNav($this->files[$server.'_log_basedir'], $tokens);
                if ($results[0]) return $message->reply(MessageBuilder::new()->addFile($results[1], 'log.txt'));
                if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
                if (! isset($results[2]) || ! $results[2]) return $this->reply($message, 'Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
                return $this->reply($message, "{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`');
            }
            return $this->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys) . '`');
        };

        $this->messageHandler->offsetSet('logs', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($log_handler): PromiseInterface
        {
            return $log_handler($message, trim(substr($message_filtered['message_content'], strlen($command))));
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('playerlogs', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $tokens = explode(';', trim(substr($message_filtered['message_content'], strlen($command))));
            $keys = [];
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $keys[] = $server = strtolower($key);
                if (trim($tokens[0]) != $key) continue;
                if (! isset($this->files[$server.'_playerlogs']) || ! file_exists($this->files[$server.'_playerlogs']) || ! $file_contents = @file_get_contents($this->files[$server.'_playerlogs'])) return $message->react("🔥");
                return $message->reply(MessageBuilder::new()->addFileFromContent('playerlogs.txt', $file_contents));
            }
            return $this->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys). '`' );
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('stop', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command)//: PromiseInterface
        {
            $promise = $message->react("🛑");
            $promise->done(function () { $this->stop(); });
            //return $promise; // Pending PromiseInterfaces v3
            return null;
        }), ['Owner', 'High Staff']);

        if (isset($this->folders['typespess_path'], $this->files['typespess_launch_server_path']))
        $this->messageHandler->offsetSet('ts', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            if (! $state = trim(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
            if (! in_array($state, ['on', 'off'])) return $this->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
            if ($state == 'on') {
                \execInBackground("cd {$this->folders['typespess_path']}");
                \execInBackground('git pull');
                \execInBackground("sh {$this->files['typespess_launch_server_path']}&");
                return $this->reply($message, 'Put **TypeSpess Civ13** test server on: http://civ13.com/ts');
            } else {
                \execInBackground('killall index.js');
                return $this->reply($message, '**TypeSpess Civ13** test server down.');
            }
        }), ['Owner']);

        if (isset($this->files['ranking_path']) && file_exists($this->files['ranking_path'])) {
            $ranking = function (): false|string
            {
                $line_array = array();
                if (! $search = @fopen($this->files['ranking_path'], 'r')) return false;
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
            $this->messageHandler->offsetSet('ranking', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($ranking): PromiseInterface
            {
                if (! $this->recalculateRanking()) return $this->reply($message, 'There was an error trying to recalculate ranking! The bot may be misconfigured.');
                if (! $msg = $ranking()) return $this->reply($message, 'There was an error trying to recalculate ranking!');
                return $this->reply($message, $msg, 'ranking.txt');
            }));

            $rankme = function (string $ckey): false|string
            {
                $line_array = array();
                if (! $search = @fopen($this->files['ranking_path'], 'r')) return false;
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
            $this->messageHandler->offsetSet('rankme', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($rankme): PromiseInterface
            {
                if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Wrong format. Please try `rankme [ckey]`.');
                if (! $this->recalculateRanking()) return $this->reply($message, 'There was an error trying to recalculate ranking! The bot may be misconfigured.');
                if (! $msg = $rankme($ckey)) return $this->reply($message, 'There was an error trying to get your ranking!');
                return $this->sendMessage($message->channel, $msg, 'rank.txt');
                // return $this->reply($message, "Your ranking is too long to display.");
            }));
        }
        if (isset($this->files['tdm_awards_path']) && file_exists($this->files['tdm_awards_path'])) {
            $medals = function (string $ckey): false|string
            {
                $result = '';
                if (! $search = @fopen($this->files['tdm_awards_path'], 'r')) return false;
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
            $this->messageHandler->offsetSet('medals', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($medals): PromiseInterface
            {
                if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Wrong format. Please try `medals [ckey]`.');
                if (! $msg = $medals($ckey)) return $this->reply($message, 'There was an error trying to get your medals!');
                return $this->reply($message, $msg, 'medals.txt');
            }));
        }
        if (isset($this->files['tdm_awards_br_path']) && file_exists($this->files['tdm_awards_br_path'])) {
            $brmedals = function (string $ckey): string
            {
                $result = '';
                if (! $search = @fopen($this->files['tdm_awards_br_path'], 'r')) return "Error opening {$this->files['tdm_awards_br_path']}.";
                $found = false;
                while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {
                    $found = true;
                    $duser = explode(';', $line);
                    if ($duser[0] == $ckey) $result .= "**{$duser[1]}:** placed *{$duser[2]} of {$duser[5]},* on {$duser[4]} ({$duser[3]})" . PHP_EOL;
                }
                if (! $found) return 'No medals found for this ckey.';
                return $result;
            };
            $this->messageHandler->offsetSet('brmedals', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($brmedals): PromiseInterface
            {
                if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Wrong format. Please try `brmedals [ckey]`.');
                if (! $msg = $brmedals($ckey)) return $this->reply($message, 'There was an error trying to get your medals!');
                return $this->reply($message, $msg, 'brmedals.txt');
                // return $this->reply($message, "Too many medals to display.");
            }));
        }

        $this->messageHandler->offsetSet('updatebans', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($banlog_update): PromiseInterface
        {   
            $server_playerlogs = [];
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $server = strtolower($key);
                if (! $playerlogs = @file_get_contents($this->files[$server.'_playerlogs'])) {
                    $this->logger->warning("`{$server}_playerlogs` is not a valid file path!");
                    continue;
                }
                $server_playerlogs[] = $playerlogs;
            }
            if (! $server_playerlogs) return $message->react("🔥");
            
            $updated = false;
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $server = strtolower($key);
                if (! $bans = @file_get_contents($this->files[$server.'_bans'])) {
                    $this->logger->warning("`{$server}_bans` is not a valid file path!");
                    continue;
                }
                if (! @file_put_contents($this->files[$server.'_bans'], preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $banlog_update($bans, $server_playerlogs)))) {
                    $this->logger->warning("Error updating bans for {$server}!");
                    continue;
                }
                $updated = true;
            }
            if ($updated) return $message->react("👍");
            return $message->react("🔥");
        }), ['Owner', 'High Staff']);

        $this->messageHandler->offsetSet('panic', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            return $this->reply($message, 'Panic bunker is now ' . (($this->panic_bunker = ! $this->panic_bunker) ? 'enabled.' : 'disabled.'));
        }), ['Owner', 'High Staff']);
    }

    public function filterMessage($message): array
    {
        if (! $message->guild || $message->guild->owner_id != $this->owner_id)  return ['message_content' => '', 'message_content_lower' => '', 'called' => false]; // Only process commands from a guild that Taislin owns
        $message_content = '';
        $prefix = $this->command_symbol;
        $called = false;
        if (str_starts_with($message->content, $call = $prefix . ' ')) { $message_content = trim(substr($message->content, strlen($call))); $called = true; }
        elseif (str_starts_with($message->content, $call = "<@!{$this->discord->id}>")) { $message_content = trim(substr($message->content, strlen($call))); $called = true; }
        elseif (str_starts_with($message->content, $call = "<@{$this->discord->id}>")) { $message_content = trim(substr($message->content, strlen($call))); $called = true; }
        return ['message_content' => $message_content, 'message_content_lower' => strtolower($message_content), 'called' => $called];
    }

    public function sendMessage($channel, string $content, string $file_name = 'message.txt', $prevent_mentions = false, $announce_shard = true): ?PromiseInterface
    {
        // $this->logger->debug("Sending message to {$channel->name} ({$channel->id}): {$message}");
        if ($announce_shard && $this->sharding && $this->enabled_servers) {
            if (! $enabled_servers_string = implode(', ', $this->enabled_servers)) $enabled_servers_string = 'None';
            if ($this->shard) $content .= '**SHARD FOR [' . $enabled_servers_string . ']**' . PHP_EOL;
            else $content = '**MAIN PROCESS FOR [' . $enabled_servers_string . ']**' . PHP_EOL . $content;
        }
        $builder = MessageBuilder::new();
        if ($prevent_mentions) $builder->setAllowedMentions(['parse'=>[]]);
        if (strlen($content)<=2000) return $channel->sendMessage($builder->setContent($content));
        if (strlen($content)<=4096) {
            $embed = new Embed($this->discord);
            $embed->setDescription($content);
            $builder->addEmbed($embed);
            return $channel->sendMessage($builder);
        }
        return $channel->sendMessage($builder->addFileFromContent($file_name, $content));
    }

    public function reply(Message $message, string $content, string $file_name = 'message.txt', $prevent_mentions = false, $announce_shard = true): ?PromiseInterface
    {
        // $this->logger->debug("Sending message to {$channel->name} ({$channel->id}): {$message}");
        if ($announce_shard && $this->sharding && $this->enabled_servers) {
            if (! $enabled_servers_string = implode(', ', $this->enabled_servers)) $enabled_servers_string = 'None';
            if ($this->shard) $content .= '**SHARD FOR [' . $enabled_servers_string . ']**' . PHP_EOL;
            else $content = '**MAIN PROCESS FOR [' . $enabled_servers_string . ']**' . PHP_EOL . $content;
        }
        $builder = MessageBuilder::new();
        if ($prevent_mentions) $builder->setAllowedMentions(['parse'=>[]]);
        if (strlen($content)<=2000) return $message->reply($builder->setContent($content));
        if (strlen($content)<=4096) {
            $embed = new Embed($this->discord);
            $embed->setDescription($content);
            $builder->addEmbed($embed);
            return $message->reply($builder);
        }
        return $message->reply($builder->addFileFromContent($file_name, $content));
    }

    /**
     * This method is called after the object is constructed.
     * It initializes various properties, starts timers, and starts handling events.
     *
     * @param array $options An array of options.
     * @param array $server_options An array of server options.
     * @return void
     */
    protected function afterConstruct(array $options = [], array $server_options = []): void
    {
        $this->messageHandler = new MessageHandler($this);
        $this->generateServerFunctions();
        $this->generateGlobalFunctions();
        $this->logger->debug('[COMMAND LIST] ' . $this->messageHandler->generateHelp());
        
        if (! $this->serverinfo_url) $this->serverinfo_url = "http://{$this->webserver_url}/servers/serverinfo.json"; // Default to VZG unless passed manually in config

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
                if (! $softbanned = $this->VarLoad('softbanned.json')) {
                    $softbanned = [];
                    $this->VarSave('softbanned.json', $softbanned);
                }
                $this->softbanned = $softbanned;
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
                $this->embed_footer = $this->github 
                    ? $this->github . PHP_EOL
                    : '';
                $this->embed_footer .= "{$this->discord->username}#{$this->discord->discriminator} by valithor" . PHP_EOL;

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
                //$this->setIPs();
                $this->serverinfo_url = "http://{$this->webserver_url}/servers/serverinfo.json";
                $this->serverinfoTimer(); // Start the serverinfo timer and update the serverinfo channel
                $this->pending = new Collection([], 'discord');
                // Initialize configurations
                if (! $discord_config = $this->VarLoad('discord_config.json')) $discord_config = [];
                foreach ($this->discord->guilds as $guild) if (!isset($discord_config[$guild->id])) $this->SetConfigTemplate($guild, $discord_config);
                $this->discord_config = $discord_config; // Declared, but not currently used for anything
                
                if (! empty($this->functions['ready'])) foreach ($this->functions['ready'] as $func) $func($this);
                else $this->logger->debug('No ready functions found!');
                $this->discord->application->commands->freshen()->done(function (GlobalCommandRepository $commands): void
                {
                    $this->slash->updateCommands($commands);
                    if (! empty($this->functions['ready_slash'])) foreach (array_values($this->functions['ready_slash']) as $func) $func($this, $commands);
                    else $this->logger->debug('No ready slash functions found!');
                });
                
                $this->discord->on('message', function (Message $message): void
                {
                    $message_filtered = $this->filterMessage($message);
                    if (! $this->messageHandler->handle($message, $message_filtered)) { // This section will be deprecated in the future
                        if (! empty($this->functions['message'])) foreach ($this->functions['message'] as $func) $func($this, $message, $message_filtered); // Variable functions
                        else $this->logger->debug('No message variable functions found!');
                    }
                });
                $this->discord->on('GUILD_MEMBER_ADD', function (Member $guildmember): void
                {
                    if ($this->shard) return;                    
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
                    if (! isset($this->timers['relay_timer'])) $this->timers['relay_timer'] = $this->discord->getLoop()->addPeriodicTimer(10, function ()
                    {
                        if ($this->relay_method !== 'file') return null;
                        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return $this->logger->error("Could not find Guild with ID `{$this->civ13_guild_id}`");
                        foreach ($this->server_settings as $key => $settings) {
                            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
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
     * Resolves the given options array by validating and setting default values for each option.
     *
     * @param array $options An array of options to be resolved.
     * @return array The resolved options array.
     */
    protected function resolveOptions(array $options = []): array
    {
        if (! isset($options['sharding']) || ! is_bool($options['sharding'])) {
            $options['sharding'] = false;
        }
        $this->sharding = $options['sharding'];
        
        if (! isset($options['shard']) || ! is_bool($options['shard'])) {
            $options['shard'] = false;
        }
        $this->shard = $options['shard'];

        if (! isset($options['welcome_message']) || ! is_string($options['welcome_message'])) {
            $options['welcome_message'] = '';
        }
        $this->welcome_message = $options['welcome_message'];
        
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
        if (isset($options['files'])) foreach ($options['files'] as $key => $value) if (! is_string($value) || (! file_exists($value) && ! @touch($value))) {
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
    
    /**
     * Runs the Discord loop.
     *
     * @return void
     *
     * @throws \Discord\Exceptions\IntentException
     * @throws \Discord\Exceptions\SocketException
     */
    public function run(): void
    {
        $this->logger->info('Starting Discord loop');
        if (!(isset($this->discord))) $this->logger->warning('Discord not set!');
        else $this->discord->run();
    }

    /**
     * Stops the bot and logs the shutdown message.
     *
     * @return void
     */
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
    
     /**
     * Saves an associative array to a file in JSON format.
     *
     * @param string $filename The name of the file to save the data to.
     * @param array $assoc_array The associative array to be saved.
     * @return bool Returns true if the data was successfully saved, false otherwise.
     */
    public function VarSave(string $filename = '', array $assoc_array = []): bool
    {
        if ($filename === '') return false;
        if (file_put_contents($this->filecache_path . $filename, json_encode($assoc_array)) === false) return false;
        return true;
    }
    /**
     * Loads a variable from a file in the file cache.
     *
     * @param string $filename The name of the file to load.
     * @return array|null Returns an associative array of the loaded variable, or null if the file does not exist or could not be loaded.
     */
    public function VarLoad(string $filename = ''): ?array
    {
        if ($filename === '') return null;
        if (!file_exists($this->filecache_path . $filename)) return null;
        if (($string = @file_get_contents($this->filecache_path . $filename) ?? false) === false) return null;
        if (! $assoc_array = json_decode($string, TRUE)) return null;
        return $assoc_array;
    }

    /**
     * This function is used to navigate a file tree and find a file
     *
     * @param string $basedir The directory to start in
     * @param array $subdirs An array of subdirectories to navigate
     * @return array Returns an array with the first element being a boolean indicating if the file was found, and the second element being either an array of files in the directory or the path to the file if it was found
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

    /**
     * This function is used to set the default configuration for a guild if it does not already exist.
     *
     * @param Guild $guild The guild for which the configuration is being set.
     * @param array &$discord_config The Discord configuration array.
     *
     * @return void
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

    /**
     * Sends a message containing the list of bans for all servers.
     *
     * @param Message $message The message object.
     * @param string $message_content_lower The message content in lowercase.
     * @return PromiseInterface
     */
    public function banlogHandler(Message $message, string $message_content_lower): PromiseInterface 
    { // I'm not sure if I want this function to be here, in the server functions, as a variable function, or as a slash command
        $fc = [];
        $keys = [];
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $keys[] = $server = strtolower($key);
            if ($message_content_lower !== $server) continue;
            if (! isset($this->files[$server.'_bans']) || ! file_exists($this->files[$server.'_bans']) || ! $file_contents = @file_get_contents($this->files[$server.'_bans'])) return $message->react("🔥");
            $fc[$server] = $file_contents;
        }
        if ($fc) {
            $builder = MessageBuilder::new();
            foreach ($fc as $file_contents) $builder->addFileFromContent($server.'_bans.txt', $file_contents);
            return $message->reply($builder);
        }
        return $this->reply($message, 'Please use the format `listbans {server}`. Valid servers: `' . implode(', ', $keys) . '`');
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
    public function getVerifiedItem(Member|User|array|string $input): ?array
    {
        if (is_string($input)) {
            if (! $input = $this->sanitizeInput($input)) return null;
            if (is_numeric($input) && $item = $this->verified->get('discord', $input)) return $item;
            elseif ($item = $this->verified->get('ss13', $input)) return $item;
        } elseif ($input instanceof Member || $input instanceof User) {
            if ($item = $this->verified->get('discord', $input->id)) return $item;
        } elseif (is_array($input)) {
            if (! isset($input['discord']) && ! isset($input['ss13'])) return null;
            if (isset($input['discord']) && is_numeric($input['discord']) && $item = $this->verified->get('discord', $this->sanitizeInput($input['discord']))) return $item;
            if (isset($input['ss13']) && is_string($input['ss13']) && $item = $this->verified->get('ss13', $this->sanitizeInput($input['ss13']))) return $item;
        }
        return null; // If $input is not a string, array, Member, or User, return false (this should never happen)
    }

    /*
    * This function is used to get a Member object from a ckey or Discord ID
    * It will return false if the user is not verified, if the user is not in the Civ13 Discord server, or if the bot is not in the Civ13 Discord server
    */
    public function getVerifiedMember(Member|User|array|string|null $input): Member|false
    {
        if (! $input) return false;
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
    public function getByondAge(string $ckey): string|false
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
        return strtotime($age) <= strtotime($this->minimum_age);
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
        if ($this->permabancheck($ckey)) return 'This account is already verified, but needs to appeal an existing ban first.';
        if (isset($this->softbanned[$ckey]) || isset($this->softbanned[$discord_id])) return 'This account is currently under investigation.';
        if ($this->verified->has($discord_id)) { $member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id); if (! $member->roles->has($this->role_ids['infantry'])) $member->setRoles([$this->role_ids['infantry']], "approveme join $ckey"); return 'You are already verified!';}
        if ($this->verified->has($ckey)) return "`$ckey` is already verified! If this is your account, contact {<@{$this->technician_id}>} to delete this entry.";
        if (! $this->pending->get('discord', $discord_id)) {
            if (! $age = $this->getByondAge($ckey)) return "Byond account `$ckey` does not exist!";
            if (! $this->checkByondAge($age) && ! isset($this->permitted[$ckey])) {
                $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => $reason = "Byond account `$ckey` does not meet the requirements to be approved. ($age)"];
                $msg = $this->ban($arr, null, '', true);
                if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                return $reason;
            }
            $found = false;
            $file_contents = '';
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $server = strtolower($key);
                if (isset($this->files[$server.'_playerlogs']) && file_exists($this->files[$server.'_playerlogs']) && $fc = @file_get_contents($this->files[$server.'_playerlogs'])) $file_contents .= $fc;
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
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "<@&{$this->role_ids['High Staff']}>, {$item['ss13']} has been flagged as needing additional review. Please `permit` the ckey after reviewing if they should be allowed to complete the verification process.");
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
        $provisionalRegistration = function (string $ckey, string $discord_id) use (&$provisionalRegistration) {
            if ($this->verified->get('discord', $discord_id)) { // User already verified, this function shouldn't be called (may happen anyway because of the timer)
                unset($this->provisional[$ckey]);
                return false;
            }

            $result = [];

            if (isset($this->verify_url) && $this->verify_url) $result = $this->verifyCkey($ckey, $discord_id, true);

            if (isset($result['success']) && $result['success']) {
                unset($this->provisional[$ckey]);
                $this->VarSave('provisional.json', $this->provisional);
                if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Successfully verified Byond account `$ckey` with Discord ID <@$discord_id>.");
                return false;
            }
            
            if (
                (! isset($this->verify_url) || ! $this->verify_url) // The website URL is not configured
                || (isset($result['error']) && $result['error'] && str_starts_with('The website', $result['error'])) // The website is down
            ) {
                if (
                    (isset($this->verify_url) && $this->verify_url) // Only bother with the timer if the website URL is configured
                    && ! isset($this->timers['provisional_registration'])
                ) $this->timers['provisional_registration'] = $this->discord->getLoop()->addTimer(1800, function () use ($provisionalRegistration, $ckey, $discord_id) { $provisionalRegistration($ckey, $discord_id); });
                if ($member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id))
                    if (! $member->roles->has($this->role_ids['infantry']))
                        $member->setRoles([$this->role_ids['infantry']], "Provisional verification `$ckey`");
                if (
                    (isset($this->verify_url) && $this->verify_url) // Only send a warning if the website URL is configured
                    && isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])
                ) $this->sendMessage($channel, "Failed to verify Byond account `$ckey` with Discord ID <@$discord_id> Providing provisional verification role and trying again in 30 minutes... " . $result['error']);
                return true;
            }
            if ($result['error'] && str_starts_with('Either Byond account', $result['error'])) {
                if ($member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id))
                    if ($member->roles->has($this->role_ids['infantry']))
                        $member->setRoles([], 'Provisional verification failed');
                unset($this->provisional[$ckey]);
                $this->VarSave('provisional.json', $this->provisional);
                if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Failed to verify Byond account `$ckey` with Discord ID <@$discord_id>. " . $result['error']);
                return false;
            }
            if ($result['error']) {
                if ($member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id))
                    if ($member->roles->has($this->role_ids['infantry']))
                        $member->setRoles([], 'Provisional verification failed');
                unset($this->provisional[$ckey]);
                $this->VarSave('provisional.json', $this->provisional);
                if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Failed to verify Byond account `$ckey` with Discord ID <@$discord_id>: {$result['error']}");
                return false;
            }
            // The code should only get this far if $result['error'] wasn't set correctly. This should never happen and is probably a programming error.
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Something went wrong trying to process the provisional registration for Byond account `$ckey` with Discord ID <@$discord_id>. If this error persists, contact <@{$this->technician_id}>.");
            return false;
        };
        return $provisionalRegistration($ckey, $discord_id);
    }
    /*
    * This function is called when a user has already set their token in their BYOND description and called the approveme prompt
    * If the Discord ID or ckey is already in the SQL database, it will return an error message stating that the ckey is already verified
    * otherwise it will add the user to the SQL database and the verified list, remove them from the pending list, and give them the verified role
    */
    public function verifyCkey(string $ckey, string $discord_id, bool $provisional = false): array // ['success' => bool, 'error' => string]
    { // Send $_POST information to the website. Only call this function after the getByondDesc() verification process has been completed!
        $success = false;
        $error = '';

        // Bypass remote registration and skip straight to provisional if the remote webserver is not configured
        if (
            (! isset($this->verify_url) || ! $this->verify_url) // The website URL is not configured
            && ! $provisional // This is not revisiting a previous provisional registration
        ) {
            if (! isset($this->provisional[$ckey])) {
                $this->provisional[$ckey] = $discord_id;
                $this->VarSave('provisional.json', $this->provisional);
            }
            if ($this->provisionalRegistration($ckey, $discord_id)) $error = "Provisionally registered `$ckey` with Discord ID <@$discord_id>.";
            return ['success' => $success, 'error' => $error];
        }
       
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->verify_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type' => 'application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // return the transfer as a string
        curl_setopt($ch, CURLOPT_USERAGENT, 'Civ13');
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
                    if ($channel) $this->sendMessage($channel, "Verified and removed the panic bunker ban from $member ($ckey - {$this->ages[$ckey]}).");
                } elseif ($this->bancheck($ckey, true)) {
                    if (! $member->roles->has($this->role_ids['infantry'])) $member->setroles([$this->role_ids['infantry'], $this->role_ids['banished']], "approveme verified ($ckey)");
                    if ($channel) $this->sendMessage($channel, "Added the banished role to $member ($ckey - {$this->ages[$ckey]}).");
                } else {
                    if (! $member->roles->has($this->role_ids['infantry'])) $member->addRole($this->role_ids['infantry'], "approveme verified ($ckey)");
                    if ($channel) $this->sendMessage($channel, "Verified $member. ($ckey - {$this->ages[$ckey]})");
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
    public function bancheck(string $ckey, bool $bypass = false): bool
    {
        if (! $ckey = $this->sanitizeInput($ckey)) return false;
        $banned = ($this->legacy ? $this->legacyBancheck($ckey) : $this->sqlBancheck($ckey));
        if (! $this->shard)
            if (! $bypass && $member = $this->getVerifiedMember($ckey))
                if ($banned && ! $member->roles->has($this->role_ids['banished'])) $member->addRole($this->role_ids['banished'], "bancheck ($ckey)");
                elseif (! $banned && $member->roles->has($this->role_ids['banished'])) $member->removeRole($this->role_ids['banished'], "bancheck ($ckey)");
        return $banned;
    }
    public function legacyBancheck(string $ckey): bool
    {
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
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
    public function legacyPermabancheck(string $ckey): bool
    {
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $server = strtolower($key);
            if (file_exists($this->files[$server.'_bans']) && $file = @fopen($this->files[$server.'_bans'], 'r')) {
                while (($fp = fgets($file, 4096)) !== false) {
                    // str_replace(PHP_EOL, '', $fp); // Is this necessary?
                    $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                    if ((count($linesplit)>=8) && ($linesplit[8] == $ckey) && ($linesplit[0] == 'Server') && (str_ends_with($linesplit[7], '999 years'))) {
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
    public function sqlPermabancheck(string $ckey): bool
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
        if (! $this->bancheck($ckey, true)) foreach ($this->server_settings as $server => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['panic']) || ! $settings['panic']) continue;
            $settings['legacy']
                ? $this->legacyBan(['ckey' => $ckey, 'duration' => '1 hour', 'reason' => "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->banappeal}"], null, $server)
                : $this->sqlBan(['ckey' => $ckey, 'reason' => '1 hour', 'duration' => "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->banappeal}"], null, $server);
            $this->panic_bans[$ckey] = true;
            $this->VarSave('panic_bans.json', $this->panic_bans);
        }
    }
    public function __panicUnban(string $ckey): void
    {
        foreach ($this->server_settings as $server => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['panic']) || ! $settings['panic']) continue;
            $settings['legacy']
                ? $this->legacyUnban($ckey, null, $server)
                : $this->sqlUnban($ckey, null, $server);
            unset($this->panic_bans[$ckey]);
            $this->VarSave('panic_bans.json', $this->panic_bans);
        }
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
        $legacyUnban = function (string $ckey, string $admin, string $key)
        {
            $server = strtolower($key);
            if (file_exists($this->files[$server.'_discord2unban']) && $file = @fopen($this->files[$server.'_discord2unban'], 'a')) {
                fwrite($file, $admin . ":::$ckey");
                fclose($file);
            } else $this->logger->warning("unable to open {$this->files[$server.'_discord2unban']}");
        };
        if ($key) $legacyUnban($ckey, $admin, $key);
        else foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $legacyUnban($ckey, $admin, $key);
        }
    }
    public function sqlpersunban(string $ckey, ?string $admin = null): void
    {
        // TODO
    }
    public function legacyBan(array $array, $admin = null, ?string $key = ''): string
    {
        $admin = $admin ?? $this->discord->user->username;
        $legacyBan = function (array $array, string $admin, string $key): string
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
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $result .= $legacyBan($array, $admin, $key);
        }
        return $result;
    }
    public function sqlBan(array $array, $admin = null, ?string $key = ''): string
    {
        return "SQL methods are not yet implemented!" . PHP_EOL;
    }

    /**
     * Soft bans a user by adding their ckey to the softbanned array or removes them from it if $allow is false.
     * 
     * @param string $ckey The key of the user to be soft banned.
     * @param bool $allow Whether to add or remove the user from the softbanned array.
     * @return array The updated softbanned array.
     */
    public function softban($id, $allow = true): array
    {
        if ($allow) $this->softbanned[$id] = true;
        else unset($this->softbanned[$id]);
        $this->VarSave('softbanned.json', $this->softbanned);
        return $this->softbanned;
    }

    public function permabancheck(string $id, bool $bypass = false): bool
    {
        if (! $id = $this->sanitizeInput($id)) return false;
        $permabanned = ($this->legacy ? $this->legacyPermabancheck($id) : $this->sqlPermabancheck($id));
        if (! $this->shard)
            if (! $bypass && $member = $this->getVerifiedMember($id))
                if ($permabanned && ! $member->roles->has($this->role_ids['permabanished'])) {
                    if (! $member->roles->has($this->role_ids['Admin'])) $member->setRoles([$this->role_ids['banished'], $this->role_ids['permabanished']], "permabancheck ($id)");
                } elseif (! $permabanned && $member->roles->has($this->role_ids['permabanished'])) $member->removeRole($this->role_ids['permabanished'], "permabancheck ($id)");
        return $permabanned;
    }
    
    /*
    * These functions determine which of the above methods should be used to process a ban or unban
    * Ban functions will return a string containing the results of the ban
    * Unban functions will return nothing, but may contain error-handling messages that can be passed to $logger->warning()
    */
    public function ban(array &$array /* = ['ckey' => '', 'duration' => '', 'reason' => ''] */, ?string $admin = null, ?string $key = '', $permanent = false): string
    {
        if (! isset($array['ckey'])) return "You must specify a ckey to ban.";
        if (! is_numeric($array['ckey']) && ! is_string($array['ckey'])) return "The ckey must be a Byond username or Discord ID.";
        if (! isset($array['duration'])) return "You must specify a duration to ban for.";
        if ($array['duration'] == '999 years') $permanent = true;
        if (! isset($array['reason'])) return "You must specify a reason for the ban.";
        $array['ckey'] = $this->sanitizeInput($array['ckey']);
        if (is_numeric($array['ckey'])) {
            if (! $item = $this->verified->get('discord', $array['ckey'])) return "Unable to find a ckey for <@{$array['ckey']}>. Please use the ckey instead of the Discord ID.";
            $array['ckey'] = $item['ss13'];
        }
        if (! $this->shard)
            if ($member = $this->getVerifiedMember($array['ckey']))
                if (! $member->roles->has($this->role_ids['banished'])) {
                    if (! $permanent) $member->addRole($this->role_ids['banished'], "Banned for {$array['duration']} with the reason {$array['reason']}");
                    else $member->setRoles([$this->role_ids['banished'], $this->role_ids['permabanished']], "Banned for {$array['duration']} with the reason {$array['reason']}");
                }
        if ($this->legacy) return $this->legacyBan($array, $admin, $key, $permanent);
        return $this->sqlBan($array, $admin, $key, $permanent);
    }
    public function unban(string $ckey, ?string $admin = null, ?string $key = ''): void
    {
        $admin ??= $this->discord->user->displayname;
        if ($this->legacy) $this->legacyUnban($ckey, $admin, $key);
        else $this->sqlUnban($ckey, $admin, $key);
        if (! $this->shard)
            if ($member = $this->getVerifiedMember($ckey))
                if ($member->roles->has($this->role_ids['banished']))
                    $member->removeRole($this->role_ids['banished'], "Unbanned by $admin");
    }
    
    public function DirectMessage(string $recipient, string $message, string $sender, ?string $server = ''): bool
    {
        $directmessage = function (string $recipient, string $message, string $sender, string $server): bool
        {
            $server = strtolower($server);
            if (file_exists($this->files[$server.'_discord2dm']) && $file = @fopen($this->files[$server.'_discord2dm'], 'a')) {
                fwrite($file, "$sender:::$recipient:::$message" . PHP_EOL);
                fclose($file);
                return true;
            }
            $this->logger->debug("unable to open `{$this->files[$server.'_discord2dm']}` for writing");
            return false;
        };
        if ($server) {
            if (! $this->server_settings[$server]['enabled'] ?? false) return false;
            return $directmessage($recipient, $message, $sender, $server);
        }
        $sent = false;
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $sent = $directmessage($recipient, $message, $sender, $key);
        }
        return $sent;
    }

    public function OOCMessage(string $message, string $sender, ?string $server = ''): bool
    {
        $oocmessage = function (string $message, string $sender, string $server): bool
        {
            $server = strtolower($server);
            if (file_exists($this->files[$server.'_discord2ooc']) && $file = @fopen($this->files[$server.'_discord2ooc'], 'a')) {
                fwrite($file, "$sender:::$message" . PHP_EOL);
                fclose($file);
                return true; 
            }
            $this->logger->error("unable to open `{$this->files[$server.'_discord2ooc']}` for writing");
            return false;
        };
        if ($server) {
            if (! $this->server_settings[$server]['enabled'] ?? false) return false;
            return $oocmessage($message, $sender, $server);
        }
        $sent = false;
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $sent = $oocmessage($message, $sender, $key);
        }
        return $sent;
    }

    public function AdminMessage(string $message, string $sender, ?string $server = ''): bool
    {
        $adminmessage = function (string $message, string $sender, string $server): bool
        {
            $server = strtolower($server);
            if (file_exists($this->files[$server.'_discord2admin']) && $file = @fopen($this->files[$server.'_discord2admin'], 'a')) {
                fwrite($file, "$sender:::$message" . PHP_EOL);
                fclose($file);
                return true;
            }
            $this->logger->error("unable to open `{$this->files[$server.'_discord2admin']}` for writing");
            return false;
        };
        if ($server) {
            if (! $this->server_settings[$server]['enabled'] ?? false) return false;
            return $adminmessage($message, $sender, $server);
        }
        $sent = false;
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $sent = $adminmessage($message, $sender, $key);
        }
        return $sent;
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
    public function webserverStatusChannelUpdate(bool $status): ?PromiseInterface
    {
        if (! $channel = $this->discord->getChannel($this->channel_ids['webserver-status'])) return null;
        [$webserver_name, $reported_status] = explode('-', $channel->name);
        $status = $this->webserver_online
            ? 'online'
            : 'offline';
        if ($reported_status != $status) {
            $msg = "Webserver is now **{$status}**.";
            if ($status == 'offline') $msg .= " Webserver technician <@{$this->technician_id}> has been notified.";
            $this->sendMessage($channel, $msg);
            $channel->name = "{$webserver_name}-{$status}";
            return $channel->guild->channels->save($channel);
        }
        return null;
    }
    public function serverinfoFetch(): array
    {
        if (! $data_json = @json_decode(@file_get_contents($this->serverinfo_url, false, stream_context_create(array('http'=>array('timeout' => 5, )))),  true)) {
            $this->webserverStatusChannelUpdate($this->webserver_online = false);
            return [];
        }
        $this->webserverStatusChannelUpdate($this->webserver_online = true);
        return $this->serverinfo = $data_json;
    }
    public function bansToCollection(): Collection
    {
        $ban_collection = new Collection([], 'uid');
        // Get the contents of the file
        $file_contents = '';
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $server = strtolower($key);
            if (isset($this->files[$server.'_bans']) && file_exists($this->files[$server.'_bans']) && $fc = @file_get_contents($this->files[$server.'_bans'])) $file_contents .= $fc;
            else $this->logger->warning("unable to open `{$this->files[$server.'_bans']}`");
        }
        
        // Create a new collection
        $ban_collection = new Collection([], 'uid');
        if (! $file_contents) return $ban_collection;
        $file_contents = str_replace(PHP_EOL, '', $file_contents);
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
    public function banArrayToAssoc(array $item): ?array
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
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $server = strtolower($key);
            if (isset($this->files[$server.'_playerlogs']) && file_exists($this->files[$server.'_playerlogs']) && $fc = @file_get_contents($this->files[$server.'_playerlogs'])) $file_contents .= $fc;
            else $this->logger->warning("unable to open `{$this->files[$server.'_playerlogs']}`");
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
    public function playerlogArrayToAssoc(array $item): ?array
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
        if ($playerlog = $this->playerlogsToCollection()->filter(function (array $item) use ($ckey) { return $item['ckey'] === $ckey; }))
            if ($bans = $this->bansToCollection()->filter(function(array $item) use ($playerlog) { return $playerlog->get('ckey', $item['ckey']) || $playerlog->get('ip', $item['ip']) || $playerlog->get('cid', $item['cid']); }));
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
    public function serverinfoTimer(): TimerInterface
    {
        $serverinfoTimer = function () {
            $this->serverinfoFetch(); 
            $this->serverinfoParsePlayers();
            foreach ($this->serverinfoPlayers() as $ckey) {
                if (! in_array($ckey, $this->seen_players) && ! isset($this->permitted[$ckey])) { // Suspicious user ban rules
                    $this->seen_players[] = $ckey;
                    $ckeyinfo = $this->ckeyinfo($ckey);
                    if ($ckeyinfo['altbanned']) { // Banned with a different ckey
                        $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->banappeal}"];
                        $msg = $this->ban($arr, null, '', true);
                        if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                    } else foreach ($ckeyinfo['ips'] as $ip) {
                        if (in_array($this->IP2Country($ip), $this->blacklisted_countries)) { // Country code
                            $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->banappeal}"];
                            $msg = $this->ban($arr, null, '', true);
                            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                            break;
                        } else foreach ($this->blacklisted_regions as $region) if (str_starts_with($ip, $region)) { //IP Segments
                            $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->banappeal}"];
                            $msg = $this->ban($arr, null, '', true);
                            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                            break 2;
                        }
                    }
                }
                if ($this->verified->get('ss13', $ckey)) continue;
                if ($this->panic_bunker || (isset($this->serverinfo[1]['admins']) && $this->serverinfo[1]['admins'] == 0 && isset($this->serverinfo[1]['vote']) && $this->serverinfo[1]['vote'] == 0)) return $this->__panicBan($ckey); // Require verification for Persistence rounds
                if (! isset($this->ages[$ckey]) && ! $this->checkByondAge($age = $this->getByondAge($ckey)) && ! isset($this->permitted[$ckey])) { //Ban new accounts
                    $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Byond account `$ckey` does not meet the requirements to be approved. ($age)"];
                    $msg = $this->ban($arr, null, '', true);
                    if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                }
            }
        };
        $serverinfoTimer();
        if (! isset($this->timers['serverinfo_timer'])) $this->timers['serverinfo_timer'] = $this->discord->getLoop()->addPeriodicTimer(60, function () use ($serverinfoTimer) { $serverinfoTimer(); });
        return $this->timers['serverinfo_timer']; // Check players every minute
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
        if (empty($this->serverinfo) || ! $serverinfo = $this->serverinfo) {
            return []; // No data to parse
            $this->logger->warning('No serverinfo data to parse!');
        }
        $index = 0; // We need to keep track of the index we're looking at, as the array may not be sequential
        $return = []; // This is the array we'll return
        foreach ($this->server_settings as $k => $settings) {            
            if (! $server = array_shift($serverinfo)) continue; // No data for this server
            if (! isset($settings['supported']) || ! $settings['supported']) { 
                $this->logger->debug("Server {$settings['name']} is not supported by the remote webserver!");
                $index++; continue;
            } // Server is not supported by the remote webserver and won't appear in data
            if (! isset($settings['name'], $settings['ip'], $settings['port'], $settings['Host'])) { 
                $this->logger->warning("Server {$settings['name']} is missing required settings in config!");
                $index++; continue;
            } // Server is missing required settings in config 
            if (array_key_exists('ERROR', $server)) {
                $this->logger->debug("Server {$settings['name']} is not responding!");
                $return[$index] = []; $index++; continue;
            } // Remote webserver reports server is not responding
            $return[$index]['Server'] = [false => $settings['name'] . PHP_EOL . "<byond://{$settings['ip']}:{$settings['port']}>"];
            $return[$index]['Host'] = [true => $settings['Host']];
           
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
            $players = array_filter(array_keys($server), function (string $key) {
                return strpos($key, 'player') === 0 && is_numeric(substr($key, 6));
            });
            if (! empty($players)) {
                $players = array_map(function (int|string $key) use ($server) {
                    return strtolower($this->sanitizeInput(urldecode($server[$key])));
                }, $players);
                $playerCount = count($players);
            }
            elseif (isset($server['players'])) $playerCount = $server['players'];
            else $playerCount = '?';
    
            $return[$index]['Players (' . $playerCount . ')'] = [true => empty($players) ? 'N/A' : implode(', ', $players)];
    
            if (isset($server['season'])) $return[$index]['Season'] = [true => urldecode($server['season'])];
    
            if ($settings['enabled']) {
                $p1 = (isset($server['players'])
                    ? $server['players']
                    : count($players) ?? 0);
                $p2 = strtolower($k) . '-';
                $this->playercountChannelUpdate($p1, $p2);
            }
            $index++;
        }
        $this->playercount_ticker++;
        return $return;
    }

    // This is a simplified version of serverinfoParse() that only updates the player counter
    public function serverinfoParsePlayers(): void
    {
        if (empty($this->serverinfo) || ! $serverinfo = $this->serverinfo) {
            $this->logger->warning('No serverinfo players data to parse!');
            return; // No data to parse
        }

        // $relevant_servers = array_filter($this->serverinfo, fn($server) => in_array($server['stationname'], ['TDM', 'Nomads', 'Persistence'])); // We need to declare stationname in world.dm first

        $index = 0; // We need to keep track of the index we're looking at, as the array may not be sequential
        foreach ($this->server_settings as $k => $settings) {            
            if (! $server = array_shift($serverinfo)) continue; // No data for this server
            if (! isset($settings['supported']) || ! $settings['supported']) { $index++; continue; } // Server is not supported by the remote webserver and won't appear in data
            if (! isset($settings['name'])) { 
                $this->logger->warning("Server {$k} is missing a name in config!");
                $index++; continue;
            } // Server is missing required settings in config 
            if (array_key_exists('ERROR', $server)) { $index++; continue; } // Remote webserver reports server is not responding

            $p1 = (isset($server['players'])
                ? $server['players']
                : count(array_map(fn($player) => $this->sanitizeInput(urldecode($player)), array_filter($server, function (string $key) { return str_starts_with($key, 'player') && !str_starts_with($key, 'players'); }, ARRAY_FILTER_USE_KEY)))
            );
            $p2 = strtolower($k) . '-';
            $this->playercountChannelUpdate($p1, $p2);
            $index++; // TODO: Remove this once we have stationname in world.dm
        }
        $this->playercount_ticker++;
    }

    /**
     * This function takes a member and checks if they have previously been verified
     * If they have, it will assign them the appropriate roles
     * If they have not, it will send them a message indicating that they need to verify if the 'welcome_message' is set
     *
     * @param Member $member The member to check and assign roles to
     * @return PromiseInterface|null Returns null if the member is softbanned, otherwise returns a PromiseInterface
     */
    public function joinRoles(Member $member): ?PromiseInterface
    {
        if ($member->guild_id == $this->civ13_guild_id && $item = $this->verified->get('discord', $member->id)) {
            if (! isset($item['ss13'])) $this->logger->warning("Verified member `{$member->id}` does not have an SS13 ckey assigned to them.");
            else {
                if (($item['ss13'] && isset($this->softbanned[$item['ss13']])) || isset($this->softbanned[$member->id])) return null;
                $banned = $this->bancheck($item['ss13'], true);
                $paroled = isset($this->paroled[$item['ss13']]);
                if ($banned && $paroled) return $member->setroles([$this->role_ids['infantry'], $this->role_ids['banished'], $this->role_ids['paroled']], "bancheck join {$item['ss13']}");
                if ($banned) return $member->setroles([$this->role_ids['infantry'], $this->role_ids['banished']], "bancheck join {$item['ss13']}");
                if ($paroled) return $member->setroles([$this->role_ids['infantry'], $this->role_ids['paroled']], "parole join {$item['ss13']}");
                return $member->setroles([$this->role_ids['infantry']], "verified join {$item['ss13']}");
            }
        }
        if (isset($this->welcome_message, $this->channel_ids['get-approved']) && $this->welcome_message && $member->guild_id == $this->civ13_guild_id)
            if ($channel = $this->discord->getChannel($this->channel_ids['get-approved']))
                return $this->sendMessage($channel, "<@{$member->id}>, " . $this->welcome_message);
        return null;
    }

    /**
     * Every 12 hours, this function checks if a user is banned and removes the banished role from them if they are not.
     * It loops through all the members in the guild and checks if they have the banished role.
     * If they are not been banned, it removes the banished role from them.
     * If the staff_bot channel exists, it sends a message to the channel indicating that the banished role has been removed from the member.
     *
     * @return bool Returns true if the function executes successfully, false otherwise.
     */
    public function unbanTimer(): bool
    {
        // We don't want the persistence server to do this function
        foreach ($this->server_settings as $key => $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $server = strtolower($key);
            if (! isset($this->files[$server.'_bans']) || ! file_exists($this->files[$server.'_bans']) || ! $file = @fopen($this->files[$server.'_bans'], 'r')) return false;
            fclose($file);
        }

        $unbanTimer = function () {
            if ($this->shard) return;
            if (isset($this->role_ids['banished']) && $guild = $this->discord->guilds->get('id', $this->civ13_guild_id))
                if ($members = $guild->members->filter(fn ($member) => $member->roles->has($this->role_ids['banished'])))
                    foreach ($members as $member) if ($item = $this->getVerifiedMemberItems()->get('discord', $member->id))
                        if (! $this->bancheck($item['ss13'], true)) {
                            $member->removeRole($this->role_ids['banished'], 'unban timer');
                            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Removed the banished role from $member.");
                        }
         };
         $unbanTimer();
         if (! isset($this->timers['unban_timer'])) $this->timers['unban_timer'] = $this->discord->getLoop()->addPeriodicTimer(43200, function () use ($unbanTimer) { $unbanTimer(); });
         return true;
    }

    /*
    * This function is used to change the bot's status on Discord
    */
    public function statusChanger(Activity $activity, $state = 'online'): void
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
            $listener = function () use ($ckey, $message, $channel_id, $moderate, &$listener) {
                $this->gameChatWebhookRelay($ckey, $message, $channel_id, $moderate);
                $this->discord->removeListener('ready', $listener);
            };
            $this->discord->on('ready', $listener);
            return true; // Assume that the function will succeed when the bot is ready
        }
        
        return $this->__gameChatRelay(['ckey' => $ckey, 'message' => $message, 'server' => explode('-', $channel->name)[1]], $channel, $moderate);
    }
    private function __gameChatRelay(array $array, $channel, bool $moderate = true): bool
    {
        if (! $array || ! isset($array['ckey']) || ! isset($array['message']) || ! isset($array['server']) || ! $array['ckey'] || ! $array['message'] || ! $array['server']) {
            $this->logger->warning('__gameChatRelay() was called with an empty array or invalid content.');
            return false;
        }
        if ($moderate && $this->moderate) $this->__gameChatModerate($array['ckey'], $array['message'], $array['server']);
        if (! $item = $this->verified->get('ss13', $this->sanitizeInput($array['ckey']))) $this->sendMessage($channel, $array['message'], 'relay.txt', false, false);
        else {
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
        $lower = strtolower($string);
        foreach ($this->badwords as $badwords_array) switch ($badwords_array['method']) {
            case 'exact': // ban ckey if $string contains a blacklisted phrase exactly as it is defined
                if (preg_match('/\b' . $badwords_array['word'] . '\b/', $string)) {
                    $this->__relayViolation($server, $ckey, $badwords_array);
                    break 2;
                }
                continue 2;
            case 'cyrillic': // ban ckey if $string contains a cyrillic character
                if (preg_match('/\p{Cyrillic}/ui', $string)) {
                    $this->__relayViolation($server, $ckey, $badwords_array);
                    break 2;
                }
                continue 2;
            case 'str_starts_with':
                if (str_starts_with($lower, $badwords_array['word'])) {
                    $this->__relayViolation($server, $ckey, $badwords_array);
                    break 2;
                }
                continue 2;
            case 'str_ends_with':
                if (str_ends_with($lower, $badwords_array['word'])) {
                    $this->__relayViolation($server, $ckey, $badwords_array);
                    break 2;
                }
                continue 2;
            case 'str_contains': // ban ckey if $string contains a blacklisted word
            default: // default to 'contains'
                if (str_contains($lower, $badwords_array['word'])) {
                    $this->__relayViolation($server, $ckey, $badwords_array);
                    break 2;
                }
                continue 2;
        }
        return $string;
    }
    // This function is called from the game's chat hook if a player says something that contains a blacklisted word
    private function __relayViolation(string $server, string $ckey, array $badwords_array): string|bool // TODO: return type needs to be decided
    {
        if ($this->sanitizeInput($ckey) == $this->sanitizeInput($this->discord->user->displayname)) return false; // Don't ban the bot
        $filtered = substr($badwords_array['word'], 0, 1) . str_repeat('%', strlen($badwords_array['word'])-2) . substr($badwords_array['word'], -1, 1);
        if (! $this->__relayWarningCounter($ckey, $badwords_array)) {
            $arr = ['ckey' => $ckey, 'duration' => $badwords_array['duration'], 'reason' => "Blacklisted phrase ($filtered). Review the rules at {$this->rules}. Appeal at {$this->banappeal}"];
            return $this->ban($arr);
        }
        $warning = "You are currently violating a server rule. Further violations will result in an automatic ban that will need to be appealed on our Discord. Review the rules at {$this->rules}. Reason: {$badwords_array['reason']} ({$badwords_array['category']} => $filtered)";
        if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "`$ckey` is" . substr($warning, 7));
        foreach (array_keys($this->server_settings) as $key) if (strtolower($server) == strtolower($key)) return $this->DirectMessage($ckey, $warning, $this->discord->user->displayname, $key);
        return false;
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
            foreach ($this->server_settings as $key => $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $defaultLists[] = strtolower($key) . $postfix;
            }
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
    public function whitelistUpdate(array $lists = [], bool $defaults = true, string $postfix = '_whitelist'): bool
    {
        $required_roles = ['veteran'];
        if (! $this->hasRequiredConfigRoles($required_roles)) return false;
        if (! $file_paths = $this->getRequiredConfigFiles($postfix, $defaults, $lists)) return false;

        $callback = function (Member $member, array $item, array $required_roles): string
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

        $callback = function (Member $member, array $item, array $required_roles): string
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

    /**
     * Updates admin lists with required roles and permissions.
     *
     * @param array $lists An array of lists to update.
     * @param bool $defaults Whether to use default permissions if another role is not found first.
     * @param string $postfix The postfix to use for the file names.
     * @return bool Returns true if the update was successful, false otherwise.
     */
    public function adminlistUpdate(array $lists = [], bool $defaults = true, string $postfix = '_admins'): bool
    {
        $required_roles = [
            'Owner' => ['Host', '65535'],
            'Chief Technical Officer' => ['Chief Technical Officer', '65535'],
            'Host' => ['Host', '65535'], // Default Host permission, only used if another role is not found first
            'Head Admin' => ['Head Admin', '16382'],
            'Manager' => ['Manager', '16382'],
            'Supervisor' => ['Supervisor', '16382'],
            'High Staff' => ['High Staff', '16382'], // Default High Staff permission, only used if another role is not found first
            'Event Admin' => ['Event Admin', '16254'],
            'Moderator' => ['Moderator', '8708'], // Moderators will also have the Admin role, but it takes priority
            'Admin' => ['Admin', '12158'],
            'Mentor' => ['Mentor', '16384'],
        ];
        if (! $this->hasRequiredConfigRoles(array_keys($required_roles))) return false;
        if (! $file_paths = $this->getRequiredConfigFiles($postfix, $defaults, $lists)) return false;

        $callback = function (Member $member, array $item, array $required_roles): string
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