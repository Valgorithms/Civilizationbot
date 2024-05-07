<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Byond;
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
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Http\Browser;
use React\Http\HttpServer;
use React\Http\Message\Response as HttpResponse;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use React\EventLoop\TimerInterface;
use React\Filesystem\Factory as FilesystemFactory;

class Civ13
{
    const log_basedir = '/data/logs';
    const playernotes_basedir = '/data/player_saves';
    
    const discord2ooc = '/SQL/discord2ooc.txt';
    const discord2admin = '/SQL/discord2admin.txt';
    const discord2dm = '/SQL/discord2dm.txt';
    const discord2ban = '/SQL/discord2ban.txt';
    const discord2unban = '/SQL/discord2unban.txt';
    const admins = '/SQL/admins.txt';
    const whitelist = '/SQL/whitelist.txt';
    const bans = '/SQL/bans.txt';
    const playerlogs = '/SQL/playerlogs.txt';
    const factionlist = '/SQL/factionlist.txt';
    const sportsteams = '/SQL/sports_teams.txt';
    const awards_path = '/SQL/awards.txt';
    const awards_br_path = '/SQL/awards_br.txt';

    const updateserverabspaths = '/scripts/updateserverabspaths.py';
    const serverdata = '/serverdata.txt';
    const killsudos = '/scripts/killsudos.py';
    const killciv13 = '/scripts/killciv13.py';
    const mapswap = '/scripts/mapswap.py';

    const dmb = '/civ13.dmb';
    const ooc_path = '/ooc.log';
    const admin_path = '/admin.log';
    const ranking_path = '/ranking.txt';

    const insults_path = 'insults.txt';
    const status = 'status.txt';

    public Byond $byond;

    public bool $sharding = false;
    public bool $shard = false;
    public string $welcome_message = '';
    
    public MessageHandler $messageHandler;
    public HttpHandler $httpHandler;
    public \Closure $onFulfilledDefault;
    public \Closure $onRejectedDefault;

    public Slash $slash;
    
    public string $webserver_url = 'www.valzargaming.com'; // The URL of the webserver that the bot pulls server information from

    public StreamSelectLoop $loop;
    public Discord $discord;
    public bool $ready = false;
    public Browser $browser;
    public $filesystem;
    public Logger $logger;
    public $stats;

    public string $filecache_path = '';
    
    protected HttpServer $webapi;
    protected SocketServer $socket;
    protected string $web_address;
    protected int $http_port;

    protected array $dwa_sessions = [];
    protected array $dwa_timers = [];
    protected array $dwa_discord_ids = [];
    
    public Collection $verified; // This probably needs a default value for Collection, maybe make it a Repository instead?
    public Collection $pending;
    public array $provisional = []; // Allow provisional registration if the website is down, then try to verify when it comes back up
    public array $softbanned = []; // List of ckeys and discord IDs that are not allowed to go through the verification process
    public array $paroled = []; // List of ckeys that are no longer banned but have been paroled
    public array $ages = []; // $ckey => $age, temporary cache to avoid spamming the Byond REST API, but we don't want to save it to a file because we also use it to check if the account still exists
    public string $minimum_age = '-21 days'; // Minimum age of a ckey
    public array $permitted = []; // List of ckeys that are permitted to use the verification command even if they don't meet the minimum account age requirement or are banned with another ckey
    public array $blacklisted_regions =[
    '77.124', '77.125', '77.126', '77.127', '77.137.', '77.138.', '77.139.', '77.238.175', '77.91.69', '77.91.71', '77.91.74', '77.91.79', '77.91.88', // Region
    '77.75.145.', // Known evaders
    ];
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
    public bool $moderate = true; // Whether or not to moderate the servers using the ooc_badwords list
    public array $ooc_badwords = [];
    public array $ooc_badwords_warnings = []; // Array of [$ckey]['category'] => integer] for how many times a user has recently infringed for a specific category
    public array $ic_badwords = [];
    public array $ic_badwords_warnings = []; // Array of [$ckey]['category'] => integer] for how many times a user has recently infringed for a specific category
    public bool $legacy = true; // If true, the bot will use the file methods instead of the SQL ones
    
    public array $functions = array(
        'ready' => [],
        'ready_slash' => [],
        'messages' => [],
        'misc' => [],
    );
    public array $server_funcs_uncalled = []; // List of callable functions that are available for use by other functions, but otherwise not called via a message command
    
    public string $command_symbol = '@Civilizationbot'; // The symbol that the bot will use to identify commands if it is not mentioned
    public string $owner_id = '196253985072611328'; // Taislin's Discord ID
    public string $technician_id = '116927250145869826'; // Valithor Obsidion's Discord ID
    public string $embed_footer = ''; // Footer for embeds, this is set in the ready event
    public string $civ13_guild_id = '468979034571931648'; // Guild ID for the Civ13 server
    public string $civ_token = ''; // Token for use with $verify_url, this is not the same as the bot token and should be kept secret

    public string $github = 'https://github.com/VZGCoders/Civilizationbot'; // Link to the bot's github page
    public string $discord_invite = 'https://civ13.com/discord'; // Link to the Civ13 Discord server
    public string $discord_formatted = 'civ13.com slash discord'; // Formatted for posting in-game (cannot contain html special characters like / or &, blame the current Python implementation)
    public string $rules = 'civ13.com slash rules'; // Link to the server rules
    public string $verify_url = 'http://valzargaming.com:8080/verified/'; // Where the bot submit verification of a ckey to and where it will retrieve the list of verified ckeys from
    public string $serverinfo_url = ''; // Where the bot will retrieve server information from
    public bool $webserver_online = true; // Whether the serverinfo webserver is online (not to be confused with the verification server)
    public bool $verifier_online = true;
    
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
        if (isset($options['discord_formatted'])) $this->discord_formatted = $options['discord_formatted'];
        if (isset($options['rules'])) $this->rules = $options['rules'];
        if (isset($options['github'])) $this->github = $options['github'];
        if (isset($options['discord_invite'])) $this->discord_invite = $options['discord_invite'];
        if (isset($options['civ13_guild_id'])) $this->civ13_guild_id = $options['civ13_guild_id'];
        if (isset($options['civ_token'])) $this->civ_token = $options['civ_token'];
        if (isset($options['serverinfo_url'])) $this->serverinfo_url = $options['serverinfo_url'];
        if (isset($options['webserver_url'])) $this->webserver_url = $options['webserver_url'];
        if (isset($options['legacy']) && is_bool($options['legacy'])) $this->legacy = $options['legacy'];
        if (isset($options['relay_method']) && is_string($options['relay_method']))
            if (in_array($relay_method = strtolower($options['relay_method']), ['file', 'webhook']))
                $this->relay_method = $relay_method;
        if (isset($options['moderate']) && is_bool($options['moderate'])) $this->moderate = $options['moderate'];
        if (isset($options['ooc_badwords']) && is_array($options['ooc_badwords'])) $this->ooc_badwords = $options['ooc_badwords'];
        if (isset($options['ic_badwords']) && is_array($options['ic_badwords'])) $this->ic_badwords = $options['ic_badwords'];

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
        // messageHandler
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! file_exists($settings['basedir'] . self::playernotes_basedir)) $this->logger->debug("Skipping server function `{$settings['key']}notes` because the required config files were not found.");
            else {
                $servernotes = function (Message $message, array $message_filtered) use ($settings): PromiseInterface
                {
                    if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content'], strlen("{$settings['key']}notes")))) return $this->reply($message, 'Missing ckey! Please use the format `notes ckey`');
                    $first_letter_lower = strtolower(substr($ckey, 0, 1));
                    $first_letter_upper = strtoupper(substr($ckey, 0, 1));
                    
                    $letter_dir = '';
                    
                    if (is_dir($basedir = $settings['basedir'] . self::playernotes_basedir. "/$first_letter_lower")) $letter_dir = $basedir . "/$first_letter_lower";
                    if (is_dir($basedir = $settings['basedir'] . self::playernotes_basedir . "/$first_letter_upper")) $letter_dir = $basedir . "/$first_letter_upper";
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
                    if ($contents = @file_get_contents($file_path)) $result = $contents;
                    else return $this->reply($message, "A notes file with path `$file_path` was found for `$ckey`, however the file could not be read.");
                    
                    return $this->reply($message, $result, 'info.sav', true);
                };
                $this->messageHandler->offsetSet("{$settings['key']}notes", $servernotes, ['Owner', 'High Staff', 'Admin']);
            }
            
            $serverconfigexists = function (?Message $message = null) use ($settings): PromiseInterface|bool
            {
                if (isset($settings['key'])) {
                    if ($message) return $message->react("👍");
                    return true;
                }
                if ($message) return $message->react("👎");
                return false;
            };
            $this->logger->info("Generating {$settings['key']}configexists command.");
            $this->messageHandler->offsetSet("{$settings['key']}configexists", $serverconfigexists, ['Owner', 'High Staff']);

            $serverstatus = function (?Message $message = null, array $message_filtered = ['message_content' => '', 'message_content_lower' => '', 'called' => false]): ?PromiseInterface
            {
                $builder = MessageBuilder::new();
                $builder->addEmbed($this->generateServerstatusEmbed());
                return $message->reply($builder);
            };
            $this->messageHandler->offsetSet('serverstatus', $serverstatus, ['Owner', 'High Staff']);
            
            $allRequiredFilesExist = true;
            foreach ([
                //$settings['basedir'] . self::serverdata, // This file is created by the server host process but it doesn't need to exist for the server to be hosted, only deleted
                $settings['basedir'] . self::killsudos,
                $settings['basedir'] . self::dmb,
                $settings['basedir'] . self::updateserverabspaths
            ] as $fp) {
                if (! file_exists($fp)) {
                    $this->logger->debug("Skipping server function `{$settings['key']}host` because the required config file `$fp` was not found.");
                    $allRequiredFilesExist = false;
                    break;
                }
            }
            if ($allRequiredFilesExist) {
                $serverhost = function (?Message $message = null) use ($settings): void
                {
                    \execInBackground('python3 ' . $settings['basedir'] . self::updateserverabspaths);
                    if (file_exists($settings['basedir'] . self::serverdata)) \execInBackground('rm -f ' . $settings['basedir'] . self::serverdata);
                    \execInBackground('python3 ' . $settings['basedir'] . self::killsudos);

                    if (! isset($this->timers["{$settings['key']}host"])) {
                        $this->timers["{$settings['key']}host"] = $this->discord->getLoop()->addTimer(30, function () use ($settings, $message) {
                            \execInBackground('nohup DreamDaemon ' . $settings['basedir'] . self::dmb . ' ' . $settings['port'] . ' -trusted -webclient -logself &');
                            if ($message) $message->react("👍");
                            unset($this->timers["{$settings['key']}host"]);
                        });
                    } else $this->logger->info("Server host timer already exists for {$settings['key']}.");
                    if ($message) $message->react("⏱️");
                };
                $this->messageHandler->offsetSet("{$settings['key']}host", $serverhost, ['Owner', 'High Staff']);
            }
            
            
            if (! file_exists($settings['basedir'] . self::killciv13)) $this->logger->debug("Skipping server function `{$settings['key']}kill` because the required config files were not found.");
            else {
                $serverkill = function (?Message $message = null) use ($settings): void
                {
                    $this->loop->addTimer(10, function () use ($settings, $message): void
                    {
                        \execInBackground('python3 ' . $settings['basedir'] . self::killciv13);
                        if ($message) $message->react("👍");
                    });
                    if ($message) $message->react("⏱️");
                    $this->OOCMessage("Server is shutting down. To get notified when we go live again, please join us on Discord at {$this->discord_formatted}", $this->getVerifiedItem($message->author)['ss13'] ?? $this->discord->user->id ?? $this->discord->user->displayname, $settings);
                };
                $this->messageHandler->offsetSet("{$settings['key']}kill", $serverkill, ['Owner', 'High Staff']);
            }
            if ($this->messageHandler->offsetExists("{$settings['key']}host") && $this->messageHandler->offsetExists("{$settings['key']}kill")) {
                $serverrestart = function (?Message $message = null) use ($settings): ?PromiseInterface
                {
                    $this->loop->addTimer(10, function () use ($settings, $message): void
                    {
                        $kill = $this->messageHandler->offsetGet("{$settings['key']}kill") ?? [];
                        $host = $this->messageHandler->offsetGet("{$settings['key']}host") ?? [];
                        if (
                            ($kill = array_shift($kill))
                            && ($host = array_shift($host))
                        ) {
                            $kill();
                            $this->loop->addTimer(10, function () use ($host, $message): void
                            {
                                $host();
                                if ($message) $message->react("👍");
                            });
                        }
                    });
                    if ($message) $this->OOCMessage("Server is now restarting.", $this->getVerifiedItem($message->author)['ss13'] ?? $this->discord->user->displayname, $settings);
                    else $this->OOCMessage("Server is now restarting.", $this->discord->user->displayname, $settings);
                    if ($message) $message->react("⏱️");
                    return null;
                };
                $this->messageHandler->offsetSet("{$settings['key']}restart", $serverrestart, ['Owner', 'High Staff']);
            }

            if (! file_exists($settings['basedir'] . self::mapswap)) $this->logger->debug("Skipping server function `{$settings['key']}mapswap` because the required config files were not found.");
            else {

                $servermapswap = function (?Message $message = null, array $message_filtered = ['message_content' => '', 'message_content_lower' => '', 'called' => false]) use ($settings): ?PromiseInterface
                {
                    $mapswap = function (string $mapto, ?Message $message = null, ) use ($settings): ?PromiseInterface
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
                        
                        \execInBackground('python3 ' . $settings['basedir'] . self::mapswap . " $mapto");
                        if ($message) return $this->reply($message, "Attempting to change `{$settings['key']}` map to `$mapto`");
                    };
                    $split_message = explode("{$settings['key']}mapswap ", $message_filtered['message_content']);
                    if (count($split_message) < 2 || !($mapto = strtoupper($split_message[1]))) return $this->reply($message, 'You need to include the name of the map.');
                    $this->OOCMessage("Server is now changing map to `$mapto`.", $this->getVerifiedItem($message->author)['ss13'] ?? $this->discord->user->displayname, $settings);
                    if (isset($settings['discussion']) && $channel = $this->discord->getChannel($settings['discussion'])) {
                        $msg = "Server is now changing map to `$mapto`.";
                        if (isset($this->role_ids['mapswap']) && $role = $this->role_ids['mapswap']); $msg = "<@&$role>, $msg";
                        $channel->sendMessage($msg);
                    }
                    $this->loop->addtimer(10, function () use ($mapto, $mapswap, $message): ?PromiseInterface
                    {
                        if ($message) $message->react("👍");
                        return $mapswap($mapto, $message);
                        
                    });
                    if ($message) return $message->react("⏱️");
                };
                $this->messageHandler->offsetSet("{$settings['key']}mapswap", $servermapswap, ['Owner', 'High Staff', 'Admin']);
            }

            $serverban = function (Message $message, array $message_filtered) use ($settings): PromiseInterface
            {
                if (! $this->hasRequiredConfigRoles(['banished'])) $this->logger->debug("Skipping server function `{$settings['key']} ban` because the required config roles were not found.");
                if (! $message_content = substr($message_filtered['message_content'], strlen("{$settings['key']}ban"))) return $this->reply($message, 'Missing ban ckey! Please use the format `{server}ban ckey; duration; reason`');
                $split_message = explode('; ', $message_content); // $split_target[1] is the target
                if (! $split_message[0]) return $this->reply($message, 'Missing ban ckey! Please use the format `ban ckey; duration; reason`');
                if (! $split_message[1]) return $this->reply($message, 'Missing ban duration! Please use the format `ban ckey; duration; reason`');
                if (! $split_message[2]) return $this->reply($message, 'Missing ban reason! Please use the format `ban ckey; duration; reason`');
                if (! str_ends_with($split_message[2], '.')) $split_message[2] .= '.';
                $maxlen = 150 - strlen(" Appeal at {$this->discord_formatted}");
                if (strlen($split_message[2]) > $maxlen) return $this->reply($message, "Ban reason is too long! Please limit it to `$maxlen` characters.");
                $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->discord_formatted}"];
                $result = $this->ban($arr, $this->getVerifiedItem($message->author)['ss13'], $settings);
                if ($member = $this->getVerifiedMember('id', $split_message[0]))
                    if (! $member->roles->has($this->role_ids['banished']))
                        $member->addRole($this->role_ids['banished'], $result);
                return $this->reply($message, $result);
            };
            $this->messageHandler->offsetSet("{$settings['key']}ban", $serverban, ['Owner', 'High Staff', 'Admin']);

            $serverunban = function (Message $message, array $message_filtered) use ($settings): PromiseInterface
            {
                if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen("{$settings['key']}unban")))) return $this->reply($message, 'Missing unban ckey! Please use the format `{server}unban ckey`');
                if (is_numeric($ckey)) {
                    if (! $item = $this->getVerifiedItem($ckey)) return $this->reply($message, "No data found for Discord ID `$ckey`.");
                    $ckey = $item['ckey'];
                }
                
                $this->unban($ckey, $admin = $this->getVerifiedItem($message->author)['ss13'], $settings);
                $result = "**$admin** unbanned **$ckey** from **{$settings['key']}**";
                if (! $this->sharding)
                    if ($member = $this->getVerifiedMember('id', $ckey))
                        if ($member->roles->has($this->role_ids['banished']))
                            $member->removeRole($this->role_ids['banished'], $result);
                return $this->reply($message, $result);
            };
            $this->messageHandler->offsetSet("{$settings['key']}unban",  $serverunban, ['Owner', 'High Staff', 'Admin']);
        }
        // httpHandler
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;

            //TODO
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
        // messageHandler
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

        $httphelp = new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            return $this->reply($message, $this->httpHandler->generateHelp(), 'httphelp.txt', true);
        });
        $this->messageHandler->offsetSet('httphelp', $httphelp, ['Owner', 'High Staff']);

        $this->messageHandler->offsetSet('cpu', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            if (PHP_OS_FAMILY == "Windows") {
                $load_array = explode(' ', trim(shell_exec('powershell -command "gwmi Win32_PerfFormattedData_PerfOS_Processor | select -ExpandProperty PercentProcessorTime"')));
                return $this->reply($message, "CPU Usage: {$load_array[0]}%");
            } else { // Linux
                $cpu_load = sys_getloadavg();
                $cpu_usage = $cpu_load ? array_sum($cpu_load) / count($cpu_load) : -1;
                return $this->reply($message, "CPU Usage: $cpu_usage%");
            }
            return $this->reply($message, 'Unrecognized operating system!');
        }));
        $this->messageHandler->offsetSet('checkip', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $context = stream_context_create(['http' => ['connect_timeout' => 5]]);
            return $this->reply($message, @file_get_contents('http://ipecho.net/plain', false, $context));
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
                $resolved_ranks = array_map(function ($rank) {
                    return isset($this->role_ids[$rank]) ? $this->role_ids[$rank] : null;
                }, $allowed_ranks);

                return count(array_filter($resolved_ranks, function ($rank) use ($message) {
                    return $message->member->roles->has($rank);
                })) > 0;
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
            foreach ($collectionsArray['playerlogs'] as $log) {
                if (isset($log['ip']) && ! in_array($log['ip'], $ips)) $ips[] = $log['ip'];
                if (isset($log['cid']) && ! in_array($log['cid'], $cids)) $cids[] = $log['cid'];
                if (isset($log['date']) && ! in_array($log['date'], $dates)) $dates[] = $log['date'];
            }
            foreach ($collectionsArray['bans'] as $log) {
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
            $approveme = new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
            {
                if ($message->member->roles->has($this->role_ids['infantry']) || (isset($this->role_ids['veteran']) && $message->member->roles->has($this->role_ids['veteran']))) return $this->reply($message, 'You already have the verification role!');
                if ($item = $this->getVerifiedItem($message->author)) {
                    $message->member->setRoles([$this->role_ids['infantry']], "approveme {$item['ss13']}");
                    return $message->react("👍");
                }
                if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Invalid format! Please use the format `approveme ckey`');
                return $this->reply($message, $this->verifyProcess($ckey, $message->user_id, $message->member));
            });
            $this->messageHandler->offsetSet('approveme', $approveme);
            $this->messageHandler->offsetSet('aproveme', $approveme);
            $this->messageHandler->offsetSet('approvme', $approveme);

            if (file_exists(self::insults_path))
            $this->messageHandler->offsetSet('insult', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
            {
                $split_message = explode(' ', $message_filtered['message_content']); // $split_target[1] is the target
                if (count($split_message) <= 1 || strlen($split_message[1]) === 0) $split_message[1] = "<@{$message->user_id}>";
                if (! empty($insults_array = file(self::insults_path, FILE_IGNORE_NEW_LINES))) {
                    $random_insult = $insults_array[array_rand($insults_array)];
                    return $message->channel->sendMessage(MessageBuilder::new()->setContent($split_message[1] . ', ' . $random_insult)->setAllowedMentions(['parse' => []]));
                }
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
            
            
            $this->messageHandler->offsetSet('playerlist', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
            { // This function is only authorized to be used by the database administrator
                if ($this->shard) return null;
                if ($message->user_id != $this->technician_id) return $message->react("❌");
                if ($playerlist = $this->localServerPlayerCount()['playerlist']) return $this->reply($message, implode(', ', $playerlist));
                return $this->reply($message, 'No players found.');
            }), ['Chief Technical Officer']);

            $this->messageHandler->offsetSet('retryregister', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
            { // This function is only authorized to be used by the database administrator
                if ($this->shard) return null;
                if ($message->user_id != $this->technician_id) return $message->react("❌");
                foreach ($this->provisional as $ckey => $discord_id) $this->provisionalRegistration($ckey, $discord_id); // Attempt to register all provisional users
                return $this->reply($message, 'Attempting to register all provisional users.');
            }), ['Chief Technical Officer']);
            
            
            $this->messageHandler->offsetSet('register', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
            { // This function is only authorized to be used by the database administrator
                if ($this->shard) return null;
                if ($message->user_id != $this->technician_id) return $message->react("❌");
                $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))));
                if (! $ckey = $this->sanitizeInput($split_message[0])) return $this->reply($message, 'Byond username was not passed. Please use the format `register <byond username>; <discord id>`.');
                if (! is_numeric($discord_id = $this->sanitizeInput($split_message[1]))) return $this->reply($message, "Discord id `$discord_id` must be numeric.");
                return $this->reply($message, $this->registerCkey($ckey, $discord_id)['error']);
            }), ['Chief Technical Officer']);

            $this->messageHandler->offsetSet('unverify', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
            { // This function is only authorized to be used by the database administrator
                if ($this->shard) return null;
                if ($message->user_id != $this->technician_id) return $message->react("❌");
                $split_message = explode(';', trim(substr($message_filtered['message_content_lower'], strlen($command))));
                if (! $id = $this->sanitizeInput($split_message[0])) return $this->reply($message, 'Byond username or Discord ID was not passed. Please use the format `register <byond username>; <discord id>`.');
                return $this->reply($message, $this->unverifyCkey($id)['message']);
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
                if (empty($tokens[0])) {
                    if (empty($this->tests)) return $this->reply($message, "No tests have been created yet! Try creating one with `tests add {test_key} {question}`");
                    $reply = 'Available tests: `' . implode('`, `', array_keys($this->tests)) . '`';
                    $reply .= PHP_EOL . 'Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`';
                    return $this->reply($message, $reply);
                }
                if (! isset($tokens[1])) return $this->reply($message, 'Invalid format! You must include the name of the test, e.g. `tests list {test_key}.');
                if (! isset($this->tests[$test_key = strtolower($tokens[1])]) && $tokens[0] !== 'add') return $this->reply($message, "Test `$test_key` hasn't been created yet! Please add a question first.");
                switch ($tokens[0]) {
                    case 'list':
                        return $message->reply(MessageBuilder::new()->addFileFromContent("$test_key.txt", var_export($this->tests[$test_key], true))->setContent('Number of questions: ' . count(array_keys($this->tests[$test_key]))));
                    case 'delete':
                        if (isset($tokens[2])) return $this->reply($message, "Invalid format! Please use the format `tests delete {test_key}`"); // Prevents accidental deletion of tests
                        unset($this->tests[$test_key]);
                        $this->VarSave('tests.json', $this->tests);
                        return $this->reply($message, "Deleted test `$test_key`");
                    case 'add':
                        if (! $question = implode(' ', array_slice($tokens, 2))) return $this->reply($message, 'Invalid format! Please use the format `tests add {test_key} {question}`');
                        $this->tests[$test_key][] = $question;
                        $this->VarSave('tests.json', $this->tests);
                        return $this->reply($message, "Added question to test `$test_key`: `$question`");
                    case 'remove':
                        if (!isset($tokens[2]) || !is_numeric($tokens[2])) return $this->reply($message, "Invalid format! Please use the format `tests remove {test_key} {question #}`");
                        if (!isset($this->tests[$test_key][$tokens[2]])) return $this->reply($message, "Question not found in test `$test_key`! Please use the format `tests {test_key} remove {question #}`");
                        $question = $this->tests[$test_key][$tokens[2]];
                        unset($this->tests[$test_key][$tokens[2]]);
                        $this->VarSave('tests.json', $this->tests);
                        return $this->reply($message, "Removed question `{$tokens[2]}`: `$question`");
                    case 'post':
                        if (!isset($tokens[2]) || !is_numeric($tokens[2])) return $this->reply($message, "Invalid format! Please use the format `tests post {test_key} {# of questions}`");
                        if (count($this->tests[$test_key]) < $tokens[2]) return $this->reply($message, "Can't return more questions than exist in a test!");
                        $test = $this->tests[$test_key]; // Copy the array, don't reference it
                        shuffle($test);
                        return $this->reply($message, implode(PHP_EOL, array_slice($test, 0, $tokens[2])));
                    default:
                        return $this->reply($message, 'Invalid format! Available commands: `list {test_key}`, `add {test_key} {question}`, `post {test_key} {question #}`, `remove {test_key} {question #}` `delete {test_key}`');
                }
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
            $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                switch (strtolower($message->channel->name)) {
                    case "ooc-{$settings['key']}":                    
                        if ($this->OOCMessage($message_filtered['message_content'], $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname, $settings)) return $message->react("📧");
                        return $message->react("🔥");
                }
            }
            if ($this->sharding) return null;
            return $this->reply($message, 'You need to be in any of the #ooc channels to use this command.');
        }));

        $this->messageHandler->offsetSet('asay', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                switch (strtolower($message->channel->name)) {
                    case "asay-{$settings['key']}":
                        if ($this->AdminMessage($message_filtered['message_content'], $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname, $settings)) return $message->react("📧");
                        return $message->react("🔥");
                }
            }
            if ($this->sharding) return null;
            return $this->reply($message, 'You need to be in any of the #asay channels to use this command.');
        }));

        $this->messageHandler->offsetSet('globalooc', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
        {
            $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
            if ($this->OOCMessage($message_filtered['message_content'], $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname)) return $message->react("📧");
            if ($this->sharding) return null;
            return $message->react("🔥");
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('globalasay', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): ?PromiseInterface
        {
            $message_filtered['message_content'] = trim(substr($message_filtered['message_content'], trim(strlen($command))));
            if ($this->AdminMessage($message_filtered['message_content'], $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname)) return $message->react("📧");
            if ($this->sharding) return null;
            return $message->react("🔥");
        }), ['Owner', 'High Staff', 'Admin']);

        $directmessage = new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            $explode = explode(';', $message_filtered['message_content']);
            $recipient = $this->sanitizeInput(substr(array_shift($explode), strlen($command)));
            $msg = implode(' ', $explode);
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                switch (strtolower($message->channel->name)) {
                    case "asay-{$settings['key']}":
                    case "ic-{$settings['key']}":
                    case "ooc-{$settings['key']}":
                        if ($this->DirectMessage($recipient, $msg, $this->getVerifiedItem($message->author)['ss13'] ?? $message->author->displayname, $settings)) return $message->react("📧");
                        return $message->react("🔥");
                }
            }
            if ($this->sharding) return null;
            return $this->reply($message, 'You need to be in any of the #ic, #asay, or #ooc channels to use this command.');
        });
        $this->messageHandler->offsetSet('dm', $directmessage, ['Owner', 'High Staff', 'Admin', 'Moderator']);
        $this->messageHandler->offsetSet('pm', $directmessage, ['Owner', 'High Staff', 'Admin', 'Moderator']);

        $this->messageHandler->offsetSet('bancheck', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) {
            if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Wrong format. Please try `bancheck [ckey]`.');
            if (is_numeric($ckey)) {
                if (! $item = $this->verified->get('discord', $ckey)) return $this->reply($message, "No ckey found for Discord ID `$ckey`.");
                $ckey = $item['ss13'];
            }
            $reason = 'unknown';
            $found = false;
            $content = '';
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                if (! isset($settings['basedir']) || ! file_exists($settings['basedir'] . self::bans)) {
                    $this->logger->warning("Either basedir or `" . self::bans . "` is not defined or does not exist");
                    return $message->react("🔥");
                }
                if (! $file = @fopen($settings['basedir'] . self::bans, 'r')) {
                    $this->logger->warning('Could not open `' . $settings['basedir'] . self::bans . "` for reading.");
                    return $message->react("🔥");
                }
                while (($fp = fgets($file, 4096)) !== false) {
                    $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                    if ((count($linesplit)>=8) && ($linesplit[8] === strtolower($ckey))) {
                        $found = true;
                        $type = $linesplit[0];
                        $reason = $linesplit[3];
                        $admin = $linesplit[4];
                        $date = $linesplit[5];
                        $content .= "**$ckey** has been **$type** banned from **{$settings['name']}** on **$date** for **$reason** by $admin." . PHP_EOL;
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
            $members = $message->guild->members->filter(function (Member $member) { return ! $member->roles->has($this->role_ids['banished']); });
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
            if ($this->getVerified(false)) return $message->react("👍");
            return $message->react("👎");
        }), ['Owner', 'High Staff', 'Admin']);

        $banlog_update = function (string $banlog, array $playerlogs, ?string $ckey = null): string
        {
            $temp = [];
            $oldlist = [];
            foreach (explode('|||', $banlog) as $bsplit) {
                $ban = explode(';', trim($bsplit));
                if (isset($ban[8])) {
                    if ($ckey && $ckey != $ban[8]) continue;
                    if (isset($ban[9], $ban[10]) && $ban[9] != '0' && $ban[10] != '0') $oldlist[] = $bsplit;
                } else $temp[$ckey][] = $bsplit;
            }
            foreach ($playerlogs as $playerlog) {
                $logs = explode('|', $playerlog);
                array_map(function ($lsplit) use (&$temp) {
                    $log = explode(';', trim($lsplit));
                    array_walk_recursive($temp, function (&$arr) use ($log) {
                        $a = explode(';', $arr);
                        if (isset($a[8]) && $a[8] === $log[0]) {
                            $a[9] = $log[2];
                            $a[10] = $log[1];
                            $arr = implode(';', $a);
                        }
                    });
                }, $logs);
            }

            $updated = [];
            foreach ($temp as $ban) {
                if (is_array($ban)) $updated = array_merge($updated, $ban);
                else $updated[] = $ban;
            }
            
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
            $arr = ['ckey' => $split_message[0], 'duration' => $split_message[1], 'reason' => $split_message[2] . " Appeal at {$this->discord_formatted}"];
    
            foreach ($this->server_settings as $settings) { // TODO: Review this for performance and redundancy
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                if (! isset($this->timers["banlog_update_{$settings['key']}"])) $this->timers["banlog_update_{$settings['key']}"] = $this->discord->getLoop()->addTimer(30, function () use ($banlog_update, $arr) {
                    $playerlogs = [];
                    foreach ($this->server_settings as $s) {
                        if (! isset($s['enabled']) || ! $s['enabled']) continue;
                        if (! file_exists($fp = $s['basedir'] . self::playerlogs)) continue;
                        if ($playerlog = @file_get_contents($fp)) $playerlogs[] = $playerlog;
                    }
                    if ($playerlogs) foreach ($this->server_settings as $s) {
                        if (! isset($s['enabled']) || ! $s['enabled']) continue;
                        if (! file_exists($fp = $s['basedir'] . self::bans)) continue;
                        file_put_contents($fp, $banlog_update(file_get_contents($fp), $playerlogs, $arr['ckey']), FILE_APPEND);
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
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                if (! file_exists($path = $settings['basedir'] . self::admins) || ! $file_contents = @file_get_contents($path)) {
                    $this->logger->debug("`$path` is not a valid file path!");
                    continue;
                }
                $builder->addFileFromContent($path, $file_contents);
                $found = true;
            }
            if (! $found) return $message->react("🔥");
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('factionlist', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {            
            $builder = MessageBuilder::new()->setContent('Faction Lists');
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled'], $settings['basedir']) || ! $settings['enabled']) continue;
                if (file_exists($path = $settings['basedir'] . self::factionlist)) $builder->addfile($path, $settings['key'] . '_factionlist.txt');
                else $this->logger->warning("`$path is not a valid file path!");
            }
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);

        if (isset($this->files['tdm_sportsteams']) && file_exists($this->files['tdm_sportsteams']))
        $this->messageHandler->offsetSet('sportsteams', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {   
            $builder = MessageBuilder::new()->setContent('Sports Teams');      
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled'], $settings['basedir']) || ! $settings['enabled']) continue;
                if (file_exists($path = $settings['basedir'] . self::sportsteams)) $builder->addfile($path, $settings['key'] . '_sports_teams.txt');
                else $this->logger->warning("`$path is not a valid file path!");
            }
            return $message->reply($builder);
        }), ['Owner', 'High Staff', 'Admin']);

        $log_handler = function (Message $message, string $message_content): PromiseInterface
        {
            $tokens = explode(';', $message_content);
            $keys = [];
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $keys[] = $settings['key'];
                if (trim($tokens[0]) !== $settings['key']) continue; // Check if server is valid
                if (! isset($settings['basedir']) || ! file_exists($settings['basedir'] . self::log_basedir)) {
                    $this->logger->warning("Either basedir or `" . self::log_basedir . "` is not defined or does not exist");
                    return $message->react("🔥");
                }

                unset($tokens[0]);
                $results = $this->FileNav($settings['basedir'] . self::log_basedir, $tokens);
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
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $keys[] = $settings['key'];
                if (trim($tokens[0]) !== $settings['key']) continue;
                if (! isset($settings['basedir']) || ! file_exists($settings['basedir'] . self::playerlogs) || ! $file_contents = @file_get_contents($settings['basedir'] . self::playerlogs)) return $message->react("🔥");
                return $message->reply(MessageBuilder::new()->addFileFromContent('playerlogs.txt', $file_contents));
            }
            return $this->reply($message, 'Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys). '`' );
        }), ['Owner', 'High Staff', 'Admin']);

        $this->messageHandler->offsetSet('stop', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command)//: PromiseInterface
        {
            $promise = $message->react("🛑");
            $promise->then(function () { $this->stop(); });
            //return $promise; // Pending PromiseInterfaces v3
            return null;
        }), ['Owner', 'High Staff']);

        if (isset($this->folders['typespess_path'], $this->files['typespess_launch_server_path']))
        $this->messageHandler->offsetSet('ts', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            if (! $state = trim(substr($message_filtered['message_content_lower'], strlen($command)))) return $this->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
            if (! in_array($state, ['on', 'off'])) return $this->reply($message, 'Wrong format. Please try `ts on` or `ts off`.');
            if ($state === 'on') {
                \execInBackground("cd {$this->folders['typespess_path']}");
                \execInBackground('git pull');
                \execInBackground("sh {$this->files['typespess_launch_server_path']}&");
                return $this->reply($message, 'Put **TypeSpess Civ13** test server on: http://civ13.com/ts');
            } else {
                \execInBackground('killall index.js');
                return $this->reply($message, '**TypeSpess Civ13** test server down.');
            }
        }), ['Owner']);

        
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['name'], $settings['key'])) continue;
            $path = $settings['basedir'].self::ranking_path;
            if ((file_exists($path) || touch($path))) {
                $this->messageHandler->offsetSet($settings['key'].'ranking', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($path): PromiseInterface
                {
                    if (! $this->recalculateRanking()) return $this->reply($message, 'There was an error trying to recalculate ranking! The bot may be misconfigured.');
                    if (! $msg = $this->getRanking($path)) return $this->reply($message, 'There was an error trying to recalculate ranking!');
                    return $this->reply($message, $msg, 'ranking.txt');
                }));
    
                $this->messageHandler->offsetSet($settings['key'].'rank', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($path): PromiseInterface
                {
                    if (! $ckey = $this->sanitizeInput(substr($message_filtered['message_content_lower'], strlen($command)))) {
                        if (! $item = $this->getVerifiedItem($message->author)) return $this->reply($message, 'Wrong format. Please try `rankme [ckey]`.');
                        $ckey = $item['ss13'];
                    }
                    if (! $this->recalculateRanking()) return $this->reply($message, 'There was an error trying to recalculate ranking! The bot may be misconfigured.');
                    if (! $msg = $this->getRank($path, $ckey)) return $this->reply($message, 'There was an error trying to get your ranking!');
                    return $this->sendMessage($message->channel, $msg, 'rank.txt');
                    // return $this->reply($message, "Your ranking is too long to display.");
                }));
            }
        };
        
        if (isset($this->files['tdm_awards_path']) && file_exists($this->files['tdm_awards_path'])) {
            $medals = function (string $ckey): false|string
            {
                $result = '';
                if (! $search = @fopen($this->files['tdm_awards_path'], 'r')) return false;
                $found = false;
                while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {  # remove '\n' at end of line
                    $found = true;
                    $duser = explode(';', $line);
                    if ($duser[0] === $ckey) {
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
                if (! $found && ($result === '')) return 'No medals found for this ckey.';
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
                    if ($duser[0] === $ckey) $result .= "**{$duser[1]}:** placed *{$duser[2]} of {$duser[5]},* on {$duser[4]} ({$duser[3]})" . PHP_EOL;
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

        $this->messageHandler->offsetSet('dumpappcommands', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($banlog_update): PromiseInterface {
            $application_commands = $this->discord->__get('application_commands');
            $names = [];
            foreach ($application_commands as $command) $names[] = $command->getName();
            $namesString = '`' . implode('`, `', $names) . '`';
            return $message->reply('Application commands: ' . $namesString);
        }), ['Owner', 'High Staff']);

        $this->messageHandler->offsetSet('updatebans', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($banlog_update): PromiseInterface {
            $server_playerlogs = array_filter(array_map(function ($settings) {
                if (! isset($settings['enabled']) || !$settings['enabled']) return null;
                if (! $playerlogs = @file_get_contents($fp = $settings['basedir'] . self::playerlogs)) {
                    $this->logger->warning("`$fp` is not a valid file path!");
                    return null;
                }
                return $playerlogs;
            }, $this->server_settings));

            if (! $server_playerlogs) return $message->react("🔥");

            $updated = false;
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled']) || !$settings['enabled']) continue;
                $fp = $settings['basedir'] . self::bans;
                $existingContent = @file_get_contents($fp);
                $newContent = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $banlog_update($existingContent, $server_playerlogs));
                if ($newContent !== $existingContent) if (! file_put_contents($fp, $newContent, FILE_APPEND)) {
                    $this->logger->warning("Error updating bans for {$fp}!");
                    continue;
                }
                $updated = true;
            }
            if ($updated) return $message->react("👍");
            return $message->react("🔥");
        }), ['Owner', 'High Staff']);

        $this->messageHandler->offsetSet('fixroles', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command) use ($banlog_update): PromiseInterface {
            if (! $guild = $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return $message->react("🔥");
            if (! $members = $guild->members->filter(function (Member $member) {
                return ! $member->roles->has($this->role_ids['veteran'])
                    && ! $member->roles->has($this->role_ids['infantry'])
                    && ! $member->roles->has($this->role_ids['banished'])
                    && ! $member->roles->has($this->role_ids['permabanished'])
                    && ! $member->roles->has($this->role_ids['dungeon']);
            })) return $message->react("👎");
            foreach ($members as $member) if ($this->getVerifiedItem($member)) $member->addRole($this->role_ids['infantry'], 'fixroles');
            return $message->react("👍");
        }), ['Owner', 'High Staff']);

        $this->messageHandler->offsetSet('panic', new MessageHandlerCallback(function (Message $message, array $message_filtered, string $command): PromiseInterface
        {
            return $this->reply($message, 'Panic bunker is now ' . (($this->panic_bunker = ! $this->panic_bunker) ? 'enabled.' : 'disabled.'));
        }), ['Owner', 'High Staff']);

        $this->httpHandler->offsetSet('/get-channels', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            $doc = new \DOMDocument();
            $html = $doc->createElement('html');
            $body = $doc->createElement('body');

            // Create input box
            $input = $doc->createElement('input');
            $input->setAttribute('type', 'text');
            $input->setAttribute('placeholder', 'Enter message');
            $input->setAttribute('style', 'margin-left: 10px;');
            $input->setAttribute('id', 'message-input');
            $body->appendChild($input);
            
            $h2 = $doc->createElement('h2', 'Guilds');
            $body->appendChild($h2);
            // CSS for .guild class
            $guildStyle = $doc->createElement('style', '.guild { margin-bottom: 20px; }');
            $html->appendChild($guildStyle);

            foreach ($this->discord->guilds as $guild) {
                $guildDiv = $doc->createElement('div');
                $guildDiv->setAttribute('class', 'guild');
                $guildName = $doc->createElement('h3');
                $a = $doc->createElement('a', $guild->name);
                $a->setAttribute('href', 'https://discord.com/channels/' . $guild->id);
                $a->setAttribute('target', '_blank');
                $guildName->appendChild($a);
                $guildDiv->appendChild($guildName);

                // CSS for .channel class
                $channelStyle = $doc->createElement('style', '.channel { margin-left: 20px; }');
                $guildDiv->appendChild($channelStyle);
                
                $channels = [];
                foreach ($guild->channels as $channel) if ($channel->isTextBased()) $channels[] = $channel;

                usort($channels, function ($a, $b) {
                    return $a->position - $b->position;
                });                

                foreach ($channels as $channel) {
                    $channelDiv = $doc->createElement('div');
                    $channelDiv->setAttribute('class', 'channel');

                    $channelName = $doc->createElement('div');
                    $channelSpan = $doc->createElement('span');
                    $a = $doc->createElement('a', $channel->name);
                    $a->setAttribute('href', 'https://discord.com/channels/' . $guild->id . '/' . $channel->id);
                    $a->setAttribute('target', '_blank');
                    $channelSpan->appendChild($a);
                    $channelName->appendChild($channelSpan);

                    // Create button and input box
                    $button = $doc->createElement('button', 'Send Message');
                    $button->setAttribute('onclick', "sendMessage('{$channel->id}')");
                    $button2 = $doc->createElement('button', 'Send Embed');
                    $button2->setAttribute('onclick', "sendEmbed('{$channel->id}')");
                    $channelName->appendChild($doc->createTextNode(' ')); // Add space here
                    $channelName->appendChild($button);
                    $channelName->appendChild($button2);

                    $channelDiv->appendChild($channelName);
                    $guildDiv->appendChild($channelDiv);
                }

                $body->appendChild($guildDiv);
            }

            // Create javascript function for /send-message
            $script = $doc->createElement('script', '
                function sendMessage(channelId) {
                    var input = document.querySelector(`#message-input`);
                    var message = input.value;
                    input.value = \'\';
                    fetch("/send-message?channel=" + encodeURIComponent(channelId) + "&message=" + encodeURIComponent(message))
                        .then(response => response.json())
                        .then(data => console.log(data))
                        .catch(error => console.error(error));
                }
            ');
            $body->appendChild($script);
            // Create javascript function for /send-embed
            $script = $doc->createElement('script', '
                function sendEmbed(channelId) {
                    var input = document.querySelector(`#message-input`);
                    var message = input.value;
                    input.value = \'\';
                    fetch("/send-embed?channel=" + encodeURIComponent(channelId) + "&message=" + encodeURIComponent(message))
                        .then(response => response.json())
                        .then(data => console.log(data))
                        .catch(error => console.error(error));
                }
            ');
            $body->appendChild($script);
            
            $html->appendChild($body);
            $doc->appendChild($html);
            return HttpResponse::html($doc->saveHTML());
        }), true);

        $this->httpHandler->offsetSet('/send-message', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            $params = $request->getQueryParams();

            isset($params['channel']) ? $channelId = $params['channel'] : $channelId = null;
            if (! $channel = $this->discord->getChannel($channelId)) return HttpResponse::json(['error' => "Channel `$channelId` not found"]);
            if (! $channel->isTextBased()) return HttpResponse::json(['error' => "Cannot send messages to channel `$channelId`"]);

            isset($params['message']) ? $message = $params['message'] : $message = null;
            if (! $message) return HttpResponse::json(['error' => "Message not found"]);

            $channel->sendMessage($message);
            return HttpResponse::json(['success' => true]);
        }), true);

        $this->httpHandler->offsetSet('/send-embed', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            $params = $request->getQueryParams();

            isset($params['channel']) ? $channelId = $params['channel'] : $channelId = null;
            if (! $channel = $this->discord->getChannel($channelId)) return HttpResponse::json(['error' => "Channel `$channelId` not found"]);
            if (! $channel->isTextBased()) return HttpResponse::json(['error' => "Cannot send messages to channel `$channelId`"]);

            isset($params['message']) ? $content = $params['message'] : $content = '';
            if (! $content) return HttpResponse::json(['error' => "Message not found"]);

            $builder = MessageBuilder::new();
            if (isset($this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']]) && $user = $this->discord->users->get('id', $this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']])) { // This will not work if the user didn't login with oauth2 during this runtime session (i.e. the bot was restarted)
                $embed = new Embed($this->discord);
                $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
                $embed->addField('Message', $content);
                $builder->addEmbed($embed);
            } else {
                $builder->setContent($content);
                $this->logger->info("Either the IP was not associated with a user or no user could be found.");
                $this->logger->info("IP: {$request->getServerParams()['REMOTE_ADDR']}");
                if (isset($this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']])) $this->logger->info("Discord ID: {$this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']]}");
            }
            
            $channel->sendMessage($builder); // TODO: Add a built-in function for using MessageBuilder with included embeds
            return HttpResponse::json(['success' => true]);
        }), true);
        
        // httpHandler website endpoints
        $index = new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            if ($whitelisted) {
                $method = $this->httpHandler->offsetGet('/botlog') ?? [];
                if ($method = array_shift($method)) return $method($request, $data, $whitelisted, $endpoint);
            }
            return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => 'https://www.valzargaming.com/?login']);
        });
        $this->httpHandler->offsetSet('/', $index);
        $this->httpHandler->offsetSet('/index.html', $index);
        $this->httpHandler->offsetSet('/index.php', $index);
        $robots = new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            return HttpResponse::plaintext('User-agent: *' . PHP_EOL . 'Disallow: /');
        });
        $this->httpHandler->offsetSet('/robots.txt', $robots);
        $sitemap = new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            return HttpResponse::xml('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
        });
        $this->httpHandler->offsetSet('/sitemap.xml', $sitemap);
        $this->httpHandler->setRateLimit('/sitemap.xml', 1, 10); // 1 request per 10 seconds
        $security = new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            return HttpResponse::plaintext('Contact: mailto:valithor@valzargaming.com' . PHP_EOL . 
            "Contact: {$this->github}}" . PHP_EOL .
            'Preferred-Languages: en' . PHP_EOL . 
            "Canonical: http://{$this->httpHandler->external_ip}:{$this->http_port}/.well-known/security.txt" . PHP_EOL . 
            'Policy: http://valzargaming.com/legal' . PHP_EOL . 
            'Acknowledgments: http://valzargaming.com/partners');
        });
        $this->httpHandler->offsetSet('/.well-known/security.txt', $security);
        $this->httpHandler->setRateLimit('/.well-known/security.txt', 1, 10); // 1 request per 10 seconds
        $this->httpHandler->offsetSet('/ping', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            return HttpResponse::plaintext("Hello wörld!");
        }));
        $this->httpHandler->offsetSet('/favicon.ico', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            if ($favicon = @file_get_contents('favicon.ico')) return new HttpResponse(HttpResponse::STATUS_OK, ['Content-Type' => 'image/x-icon', 'Cache-Control' => 'public, max-age=2592000'], $favicon);
            return new HttpResponse(HttpResponse::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain'], "Unable to access `favicon.ico`");
        }));

        // httpHandler whitelisting with DiscordWebAuth
        if (include('dwa_secrets.php'))
        if ($dwa_client_id = getenv('dwa_client_id'))
        if ($dwa_client_secret = getenv('dwa_client_secret'))
        if (include('DiscordWebAuth.php')) {
            $this->httpHandler->offsetSet('/dwa', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($dwa_client_id, $dwa_client_secret): HttpResponse
            {
                $ip = $request->getServerParams()['REMOTE_ADDR'];
                if (! isset($this->dwa_sessions[$ip])) {
                    $this->dwa_sessions[$ip] = [];
                    $this->dwa_timers[$ip] = $this->discord->getLoop()->addTimer(30 * 60, function () use ($ip) { // Set a timer to unset the session after 30 minutes
                        unset($this->dwa_sessions[$ip]);
                    });
                }

                $DiscordWebAuth = new \DWA($this, $this->dwa_sessions, $dwa_client_id, $dwa_client_secret, $this->web_address, $this->http_port, $request);
                if (isset($params['code']) && isset($params['state']))
                    return $DiscordWebAuth->getToken($params['state']);
                elseif (isset($params['login']))
                    return $DiscordWebAuth->login();
                elseif (isset($params['logout']))
                    return $DiscordWebAuth->logout();
                elseif ($DiscordWebAuth->isAuthed() && isset($params['remove']))
                    return $DiscordWebAuth->removeToken();
                
                $tech_ping = '';
                if (isset($this->technician_id)) $tech_ping = "<@{$this->technician_id}>, ";
                if (isset($DiscordWebAuth->user) && isset($DiscordWebAuth->user->id)) {
                    $this->dwa_discord_ids[$ip] = $DiscordWebAuth->user->id;
                    if (! $this->verified->get('discord', $DiscordWebAuth->user->id)) {
                        if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $tech_ping . "<@&$DiscordWebAuth->user->id> tried to log in with Discord but does not have permission to! Please check the logs.");
                        return new HttpResponse(HttpResponse::STATUS_UNAUTHORIZED);
                    }
                    if ($this->httpHandler->whitelist($ip))
                        if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot']))
                            $this->sendMessage($channel, $tech_ping . "<@{$DiscordWebAuth->user->id}> has logged in with Discord.");
                    $method = $this->httpHandler->offsetGet('/botlog') ?? [];
                    if ($method = array_shift($method))
                        return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => "http://{$this->httpHandler->external_ip}:{$this->http_port}/botlog"]);
                }

                return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => "http://{$this->httpHandler->external_ip}:{$this->http_port}/botlog"]);
            }));
        }

        // httpHandler management endpoints
        $this->httpHandler->offsetSet('/reset', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            execInBackground('git reset --hard origin/main');
            $message = 'Forcefully moving the HEAD back to origin/main...';
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $message);
            return HttpResponse::plaintext("$message");
        }), true);
        $this->httpHandler->offsetSet('/githubupdated', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            if ($signature = $request->getHeaderLine('X-Hub-Signature')) {
                $hash = "sha1=".hash_hmac('sha1', file_get_contents("php://input"), getenv('github_secret')); // GitHub Webhook Secret is the same as the 'Secret' field on the Webhooks / Manage webhook page of the respostory
                if (strcmp($signature, $hash) == 0) {
                    execInBackground('git reset --hard origin/main');
                    $this->loop->addTimer(5, function () { execInBackground('git pull'); });
                }
                if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, 'GitHub push event webhook received, updating code...');
                if (isset($this->timers['update_pending']) && $this->timers['update_pending'] instanceof TimerInterface) {
                    $this->loop->cancelTimer($this->timers['update_pending']);
                    unset($this->timers['update_pending']);
                }
                $this->timers['update_pending'] = $this->loop->addTimer(300, function () {
                    \restart();
                    $this->discord->close();
                    die();
                });
                return new HttpResponse(HttpResponse::STATUS_OK);
            }
            $headers = $request->getHeaders();
            $this->logger->warning("Unauthorized Request Headers on `$endpoint` endpoint: ", $headers);
            $tech_ping = '';
            if (isset($this->technician_id)) $tech_ping = "<@{$this->technician_id}>, ";
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $tech_ping . "Unauthorized Request Headers on `$endpoint` endpoint: " . json_encode($headers));
            return new HttpResponse(HttpResponse::STATUS_UNAUTHORIZED);
        }));
        $this->httpHandler->offsetSet('/pull', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            execInBackground('git pull');
            $message = 'Updating code from GitHub...';
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $message);
            return HttpResponse::plaintext("$message");
        }), true);
        $this->httpHandler->offsetSet('/update', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            execInBackground('composer update');
            $message = 'Updating dependencies...';
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $message);
            return HttpResponse::plaintext("$message");
        }), true);
        $this->httpHandler->offsetSet('/restart', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            $message = 'Restarting...';
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $message);
            $this->socket->close();
            if (! isset($this->timers['restart'])) $this->timers['restart'] = $this->discord->getLoop()->addTimer(5, function () {
                \restart();
                $this->discord->close();
                die();
            });
            return HttpResponse::plaintext("$message");
        }), true);

        // httpHandler redirect endpoints
        if ($this->github)
        $this->httpHandler->offsetSet('/github', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            return new HttpResponse(HttpResponse::STATUS_FOUND,['Location' => $this->github]);
        }));

        if ($this->discord_invite)
        $this->httpHandler->offsetSet('/discord', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint ): HttpResponse
        {
            return new HttpResponse(HttpResponse::STATUS_FOUND,['Location' => $this->discord_invite]);
        }));

        // httpHandler data endpoints
        $this->httpHandler->offsetSet('/verified', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            return HttpResponse::json($this->verified->toArray());
        }), true);


        /*
        $this->httpHandler->offsetSet('/endpoint', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            
            return HttpResponse::plaintext("Hello wörld!\n");
            return HttpResponse::html("<!doctype html><html><body>Hello wörld!</body></html>");
            return new HttpResponse(
                HttpResponse::STATUS_OK,
                ['Content-Type' => 'text/json'],
                json_encode($json ?? '')
            );
        }));
        */

        $relay = function($message, $channel, $ckey = null): ?PromiseInterface
        {
            if (! $ckey || ! $item = $this->verified->get('ss13', $this->sanitizeInput(explode('/', $ckey)[0]))) return $this->sendMessage($channel, $message);
            if (! $user = $this->discord->users->get('id', $item['discord'])) {
                $this->logger->warning("{$item['ss13']}'s Discord ID was not found not in the primary Discord server!");
                $this->discord->users->fetch($item['discord']);
                return $this->sendMessage($channel, $message);
            } 
            $embed = new Embed($this->discord);
            $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
            $embed->setDescription($message);
            return $channel->sendEmbed($embed);
        };
        
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $server_endpoint = '/' . $settings['key'];

            $this->httpHandler->offsetSet($server_endpoint.'/bans', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if (! file_exists($bans = $settings['basedir'] . self::bans)) return HttpResponse::plaintext("Unable to access `$bans`")->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                if (! $return = @file_get_contents($bans)) return HttpResponse::plaintext("Unable to read `$bans`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                return HttpResponse::plaintext($return);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/playerlogs', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if (! file_exists($playerlogs = $settings['basedir'] . self::playerlogs)) return HttpResponse::plaintext("Unable to access `$playerlogs`")->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                if (! $return = @file_get_contents($playerlogs)) return HttpResponse::plaintext("Unable to read `$playerlogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                return HttpResponse::plaintext($return);
            }), true);
        }

        $endpoint = '/webhook';
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $server_endpoint = $endpoint . '/' . $settings['key'];

            // If no parameters are passed to a server_endpoint, try to find it using the query parameters
            $this->httpHandler->offsetSet($server_endpoint, new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                $params = $request->getQueryParams();
                //if ($params['method']) $this->logger->info("[METHOD] `{$params['method']}`");
                $method = $this->httpHandler->offsetGet($endpoint.'/'.($params['method'] ?? '')) ?? [];
                if ($method = array_shift($method)) return $method($request, $data, $whitelisted, $endpoint);
                else {
                    if ($params['method'] ?? '') $this->logger->warning("[NO FUNCTION FOUND FOR METHOD] `{$params['method']}`");
                    return HttpResponse::plaintext('Method not found')->withStatus(HttpResponse::STATUS_NOT_FOUND);
                }
                $this->logger->warning("[UNROUTED ENDPOINT] `$endpoint`");
                return HttpResponse::plaintext('Method not found')->withStatus(HttpResponse::STATUS_NOT_FOUND);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/ahelpmessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['asay'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->discord->getChannel($channel_id = $settings['asay'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} AHELP__ $ckey**: " . $message;

                //$relay($message, $channel, $ckey); //Bypass moderator
                $this->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/asaymessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['asay'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->discord->getChannel($channel_id = $settings['asay'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                //$message = "**__{$time} ASAY__ $ckey**: $message";
                $message = "**__{$time}__** $message";

                if (str_contains($data['message'], $this->discord->user->displayname)) $this->gameChatWebhookRelay($ckey, $message, $channel_id); // Message was probably meant for the bot
                else $relay($message, $channel, $ckey); //Bypass moderator
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/urgentasaymessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['asay'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->discord->getChannel($settings['asay'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                $message = "<@{$this->role_ids['Admin']}>, ";
                isset($data['message']) ? $message .= strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message .= '(NULL)';
                //$message = "**__{$time} ASAY__ $ckey**: $message";
                $message = "**__{$time}__** $message";

                $relay($message, $channel, $ckey);
                //$this->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/lobbymessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['lobby'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->discord->getChannel($channel_id = $settings['lobby'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} LOBBY__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/oocmessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['ooc'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->discord->getChannel($channel_id = $settings['ooc'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                //$time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                //$message = "**__{$time} OOC__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/icmessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['ic'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->discord->getChannel($channel_id = $settings['ic'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                //$time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                //$message = "**__{$time} OOC__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->gameChatWebhookRelay($ckey, $message, $channel_id, true, false);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/memessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['ic'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->discord->getChannel($channel_id = $settings['ic'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} EMOTE__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/garbage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['adminlog'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->discord->getChannel($channel_id = $settings['adminlog'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} GARBAGE__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/round_start', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['discussion'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->discord->getChannel($settings['discussion'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                $message = '';
                if (isset($this->role_ids['round_start'])) $message .= "<@&{$this->role_ids['round_start']}>, ";
                $message .= 'New round ';
                if (isset($data['round']) && $game_id = $data['round']) {
                    $this->logNewRound($settings['key'], $game_id, $time);
                    $message .= "`$game_id` ";
                }
                $message .= 'has started!';
                if ($playercount_channel = $this->discord->getChannel($settings['playercount']))
                if ($existingCount = explode('-', $playercount_channel->name)[1]) {
                    $existingCount = intval($existingCount);
                    switch ($existingCount) {
                        case 0:
                            $message .= " There are currently no players on the {$settings['name']} server.";
                            break;
                        case 1:
                            $message .= " There is currently 1 player on the {$settings['name']} server.";
                            break;
                        default:
                            if (isset($this->role_ids['30+']) && $this->role_ids['30+'] && ($existingCount >= 30)) $message .= " <@&{$this->role_ids['30+']}>,";
                            elseif (isset($this->role_ids['15+']) && $this->role_ids['15+'] && ($existingCount >= 15)) $message .= " <@&{$this->role_ids['15+']}>,";
                            elseif (isset($this->role_ids['2+']) && $this->role_ids['2+'] && ($existingCount >= 2)) $message .= " <@&{$this->role_ids['2+']}>,";
                            $message .= " There are currently $existingCount players on the {$settings['name']} server.";
                            break;
                    }
                }
                $this->sendMessage($channel, $message);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/respawn_notice', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            { // NYI
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);
            $this->httpHandler->offsetSet($server_endpoint.'/login', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['transit'], $this->channel_ids['parole_notif'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->discord->getChannel($settings['transit'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $parole_notif_channel = $this->discord->getChannel($this->channel_ids['parole_notif'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                $message = "$ckey connected to the server";
                if (isset($data['ip'])) $message .= " with IP of {$data['ip']}";
                if (isset($data['cid'])) $message .= " and CID of {$data['cid']}";
                $message .= '.';
                if (isset($this->current_rounds[$settings['key']]) && $this->current_rounds[$settings['key']]) $this->logPlayerLogin($settings['key'], $ckey, $time, $data['ip'] ?? '', $data['cid'] ?? '');

                if (isset($this->paroled[$ckey])) {
                    $message2 = '';
                    if (isset($this->role_ids['Parolemin'])) $message2 .= "<@&{$this->role_ids['Parolemin']}>, ";
                    $message2 .= "`$ckey` has logged into `{$settings['name']}`";
                    $this->sendMessage($parole_notif_channel, $message2);
                }

                $relay($message, $channel, $ckey);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/logout', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['transit'], $this->channel_ids['parole_notif'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->discord->getChannel($settings['transit'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $parole_notif_channel = $this->discord->getChannel($this->channel_ids['parole_notif'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                $message = "$ckey disconnected from the server.";
                if (isset($this->current_rounds[$settings['key']]) && $this->current_rounds[$settings['key']]) $this->logPlayerLogout($settings['key'], $ckey, $time);

                if (isset($this->paroled[$ckey])) {
                    $message2 = '';
                    if (isset($this->role_ids['Parolemin'])) $message2 .= "<@&{$this->role_ids['Parolemin']}>, ";
                    $message2 .= "`$ckey` has log out of `{$settings['name']}`";
                    $this->sendMessage($parole_notif_channel, $message2);
                }

                $relay($message, $channel, $ckey);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/runtimemessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['runtime'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->discord->getChannel($settings['runtime'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                //isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} RUNTIME__**: $message";

                $relay($message, $channel);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/alogmessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['adminlog'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->discord->getChannel($settings['adminlog'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} ADMIN LOG__**: " . $message;

                $relay($message, $channel);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/attacklogmessage', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if ($settings['key'] === 'tdm') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN); // Disabled on TDM, use manual checking of log files instead
                if (! isset($settings['attack'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->discord->getChannel($settings['attack'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->sanitizeInput($data['ckey']) : $ckey = null;
                isset($data['ckey2']) ? $ckey2 = $this->sanitizeInput($data['ckey2']) : $ckey2 = null;
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} ATTACK LOG__**: " . $message;
                if ($ckey && $ckey2) if ($ckey === $ckey2) $message .= " (Self-Attack)";
                
                $relay($message, $channel);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $generic_http_handler = new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                return new HttpResponse(HttpResponse::STATUS_OK);
            });
            $this->httpHandler->offsetSet('roundstatus', $generic_http_handler, true);
            $this->httpHandler->offsetSet('status_update', $generic_http_handler, true);
            /*
            $this->httpHandler->offsetSet($server_endpoint.'/', new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($key, $server, $relay): HttpResponse
            {
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);
            */
        }

        // httpHandler log endpoints
        $botlog_func = new httpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint = '/botlog'): HttpResponse
        {
            $webpage_content = function (string $return) use ($endpoint) {
                return '<meta name="color-scheme" content="light dark"> 
                        <div class="button-container">
                            <button style="width:8%" onclick="sendGetRequest(\'pull\')">Pull</button>
                            <button style="width:8%" onclick="sendGetRequest(\'reset\')">Reset</button>
                            <button style="width:8%" onclick="sendGetRequest(\'update\')">Update</button>
                            <button style="width:8%" onclick="sendGetRequest(\'restart\')">Restart</button>
                            <button style="background-color: black; color:white; display:flex; justify-content:center; align-items:center; height:100%; width:68%; flex-grow: 1;" onclick="window.open(\''. $this->github . '\')">' . $this->discord->user->displayname . '</button>
                        </div>
                        <div class="alert-container"></div>
                        <div class="checkpoint">' . 
                            str_replace('[' . date("Y"), '</div><div> [' . date("Y"), 
                                str_replace([PHP_EOL, '[] []', ' [] '], '</div><div>', $return)
                            ) . 
                        "</div>
                        <div class='reload-container'>
                            <button onclick='location.reload()'>Reload</button>
                        </div>
                        <div class='loading-container'>
                            <div class='loading-bar'></div>
                        </div>
                        <script>
                            var mainScrollArea=document.getElementsByClassName('checkpoint')[0];
                            var scrollTimeout;
                            window.onload=function(){
                                if (window.location.href==localStorage.getItem('lastUrl')){
                                    mainScrollArea.scrollTop=localStorage.getItem('scrollTop');
                                } else {
                                    localStorage.setItem('lastUrl',window.location.href);
                                    localStorage.setItem('scrollTop',0);
                                }
                            };
                            mainScrollArea.addEventListener('scroll',function(){
                                clearTimeout(scrollTimeout);
                                scrollTimeout=setTimeout(function(){
                                    localStorage.setItem('scrollTop',mainScrollArea.scrollTop);
                                },100);
                            });
                            function sendGetRequest(endpoint) {
                                var xhr = new XMLHttpRequest();
                                xhr.open('GET', window.location.protocol + '//' + window.location.hostname + ':{$this->http_port}/' + endpoint, true);
                                xhr.onload = function () {
                                    var response = xhr.responseText.replace(/(<([^>]+)>)/gi, '');
                                    var alertContainer = document.querySelector('.alert-container');
                                    var alert = document.createElement('div');
                                    alert.innerHTML = response;
                                    alertContainer.appendChild(alert);
                                    setTimeout(function() {
                                        alert.remove();
                                    }, 15000);
                                    if (endpoint === 'restart') {
                                        var loadingBar = document.querySelector('.loading-bar');
                                        var loadingContainer = document.querySelector('.loading-container');
                                        loadingContainer.style.display = 'block';
                                        var width = 0;
                                        var interval = setInterval(function() {
                                            if (width >= 100) {
                                                clearInterval(interval);
                                                location.reload();
                                            } else {
                                                width += 2;
                                                loadingBar.style.width = width + '%';
                                            }
                                        }, 300);
                                        loadingBar.style.backgroundColor = 'white';
                                        loadingBar.style.height = '20px';
                                        loadingBar.style.position = 'fixed';
                                        loadingBar.style.top = '50%';
                                        loadingBar.style.left = '50%';
                                        loadingBar.style.transform = 'translate(-50%, -50%)';
                                        loadingBar.style.zIndex = '9999';
                                        loadingBar.style.borderRadius = '5px';
                                        loadingBar.style.boxShadow = '0 0 10px rgba(0, 0, 0, 0.5)';
                                        var backdrop = document.createElement('div');
                                        backdrop.style.position = 'fixed';
                                        backdrop.style.top = '0';
                                        backdrop.style.left = '0';
                                        backdrop.style.width = '100%';
                                        backdrop.style.height = '100%';
                                        backdrop.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                                        backdrop.style.zIndex = '9998';
                                        document.body.appendChild(backdrop);
                                        setTimeout(function() {
                                            clearInterval(interval);
                                            if (!document.readyState || document.readyState === 'complete') {
                                                location.reload();
                                            } else {
                                                setTimeout(function() {
                                                    location.reload();
                                                }, 90000);
                                            }
                                        }, 90000);
                                    }
                                };
                                xhr.send();
                            }
                            </script>
                            <style>
                                .button-container {
                                    position: fixed;
                                    top: 0;
                                    left: 0;
                                    right: 0;
                                    background-color: #f1f1f1;
                                    overflow: hidden;
                                }
                                .button-container button {
                                    float: left;
                                    display: block;
                                    color: black;
                                    text-align: center;
                                    padding: 14px 16px;
                                    text-decoration: none;
                                    font-size: 17px;
                                    border: none;
                                    cursor: pointer;
                                    color: white;
                                    background-color: black;
                                }
                                .button-container button:hover {
                                    background-color: #ddd;
                                }
                                .checkpoint {
                                    margin-top: 100px;
                                }
                                .alert-container {
                                    position: fixed;
                                    top: 0;
                                    right: 0;
                                    width: 300px;
                                    height: 100%;
                                    overflow-y: scroll;
                                    padding: 20px;
                                    color: black;
                                    background-color: black;
                                }
                                .alert-container div {
                                    margin-bottom: 10px;
                                    padding: 10px;
                                    background-color: #fff;
                                    border: 1px solid #ddd;
                                }
                                .reload-container {
                                    position: fixed;
                                    bottom: 0;
                                    left: 50%;
                                    transform: translateX(-50%);
                                    margin-bottom: 20px;
                                }
                                .reload-container button {
                                    display: block;
                                    color: black;
                                    text-align: center;
                                    padding: 14px 16px;
                                    text-decoration: none;
                                    font-size: 17px;
                                    border: none;
                                    cursor: pointer;
                                }
                                .reload-container button:hover {
                                    background-color: #ddd;
                                }
                                .loading-container {
                                    position: fixed;
                                    top: 0;
                                    left: 0;
                                    right: 0;
                                    bottom: 0;
                                    background-color: rgba(0, 0, 0, 0.5);
                                    display: none;
                                }
                                .loading-bar {
                                    position: absolute;
                                    top: 50%;
                                    left: 50%;
                                    transform: translate(-50%, -50%);
                                    width: 0%;
                                    height: 20px;
                                    background-color: white;
                                }
                                .nav-container {
                                    position: fixed;
                                    bottom: 0;
                                    right: 0;
                                    margin-bottom: 20px;
                                }
                                .nav-container button {
                                    display: block;
                                    color: black;
                                    text-align: center;
                                    padding: 14px 16px;
                                    text-decoration: none;
                                    font-size: 17px;
                                    border: none;
                                    cursor: pointer;
                                    color: white;
                                    background-color: black;
                                    margin-right: 10px;
                                }
                                .nav-container button:hover {
                                    background-color: #ddd;
                                }
                                .checkbox-container {
                                    display: inline-block;
                                    margin-right: 10px;
                                }
                                .checkbox-container input[type=checkbox] {
                                    display: none;
                                }
                                .checkbox-container label {
                                    display: inline-block;
                                    background-color: #ddd;
                                    padding: 5px 10px;
                                    cursor: pointer;
                                }
                                .checkbox-container input[type=checkbox]:checked + label {
                                    background-color: #bbb;
                                }
                            </style>
                            <div class='nav-container'>"
                                . ($endpoint === '/botlog' ? "<button onclick=\"location.href='/botlog2'\">Botlog 2</button>" : "<button onclick=\"location.href='/botlog'\">Botlog 1</button>")
                            . "</div>
                            <div class='reload-container'>
                                <div class='checkbox-container'>
                                    <input type='checkbox' id='auto-reload-checkbox' " . (isset($_COOKIE['auto-reload']) && $_COOKIE['auto-reload'] === 'true' ? 'checked' : '') . ">
                                    <label for='auto-reload-checkbox'>Auto Reload</label>
                                </div>
                                <button id='reload-button'>Reload</button>
                            </div>
                            <script>
                                var reloadButton = document.getElementById('reload-button');
                                var autoReloadCheckbox = document.getElementById('auto-reload-checkbox');
                                var interval;
        
                                reloadButton.addEventListener('click', function () {
                                    clearInterval(interval);
                                    location.reload();
                                });
        
                                autoReloadCheckbox.addEventListener('change', function () {
                                    if (this.checked) {
                                        interval = setInterval(function() {
                                            location.reload();
                                        }, 15000);
                                        localStorage.setItem('auto-reload', 'true');
                                    } else {
                                        clearInterval(interval);
                                        localStorage.setItem('auto-reload', 'false');
                                    }
                                });
        
                                if (localStorage.getItem('auto-reload') == 'true') {
                                    autoReloadCheckbox.checked = true;
                                    interval = setInterval(function() {
                                        location.reload();
                                    }, 15000);
                                }
                            </script>";
            };
            if ($return = @file_get_contents('botlog.txt')) return HttpResponse::html($webpage_content($return));
            return $this->httpHandler->__throwError('Unable to access `botlog.txt`', HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
        });
        $this->httpHandler->offsetSet('/botlog', $botlog_func, true);
        $this->httpHandler->offsetSet('/botlog2', $botlog_func, true);
        
    }

    public function filterMessage(Message $message): array
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
        if (is_string($channel)) $channel = $this->discord->getChannel($channel);
        if (! $channel) {
            $this->logger->error("Channel not found: {$channel}");
            return null;
        }
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

    public function sendEmbed($channel, string $content, Embed $embed, $prevent_mentions = false, $announce_shard = true): ?PromiseInterface
    {
        return null;
        $builder = MessageBuilder::new();
        // $this->logger->debug("Sending message to {$channel->name} ({$channel->id}): {$message}");
        if (is_string($channel)) $channel = $this->discord->getChannel($channel);
        if (! $channel) {
            $this->logger->error("Channel not found: {$channel}");
            return null;
        }
        if ($announce_shard && $this->sharding && $this->enabled_servers) {
            if (! $enabled_servers_string = implode(', ', $this->enabled_servers)) $enabled_servers_string = 'None';
            if ($this->shard) $content .= '**SHARD FOR [' . $enabled_servers_string . ']**' . PHP_EOL;
            else $content = '**MAIN PROCESS FOR [' . $enabled_servers_string . ']**' . PHP_EOL . $content;
        }
        $builder->setContent($content);
        return $channel->sendEmbed($embed);
    }
    
    /**
     * Checks if a role has a higher position than the bot's role and has the permission to manage roles.
     *
     * @param string $role_id The ID of the role to check.
     * @param Guild $guild The guild object.
     * @return bool Returns true if the role has a higher position and has the permission to manage roles, false otherwise.
     */
    function checkRolePosition(string $role_id, Guild $guild): bool
    {
        if ($role_id == $guild->id) return false;
        if (! $bot = $guild->members->get('id', $this->discord->id)) return false;
        if (! $role = $guild->roles->get('id', $role_id)) return false;
        foreach ($bot->roles as $brole) if ($brole->position > $role->position && $brole->permissions->manage_roles) return true;
        return false;
    }
    
    /**
     * Returns the highest role from a collection of roles.
     *
     * @param Collection $roles The collection of roles.
     * @return Role|null The highest role, or null if the collection is empty.
     */
    function getHighestRole(Collection $roles): ?Role
    {
        return array_reduce($roles->toArray(), function ($prev, $role) {
            if ($prev === null) return $role;
            return ($this->comparePositionTo($role, $prev) > 0 ? $role : $prev);
        });
    }

    /**
     * Compares the position of two Role objects and returns the result.
     *
     * @param Role $role The first Role object to compare.
     * @param Role $role2 The second Role object to compare.
     * @return int Returns -1 if $role is positioned before $role2, 0 if they have the same position, and 1 if $role is positioned after $role2.
     */
    function comparePositionTo(Role $role, Role $role2): int
    {
        if ($role->position === $role2->position) return $role2->id <=> $role->id;
        return $role->position <=> $role2->position;
    }

    public function sendPlayerMessage($channel, bool $urgent, string $content, string $sender, string $recipient = '', string $file_name = 'message.txt', $prevent_mentions = false, $announce_shard = true): ?PromiseInterface
    {
        $then = function (Message $message) { $this->logger->debug("Urgent message sent to {$message->channel->name} ({$message->channel->id}): {$message->content} with message link {$message->url}"); };

        // Sender is the ckey or Discord displayname
        $ckey = null;
        $member = null;
        $verified = false;
        if ($item = $this->getVerifiedItem($sender)) {
            $ckey = $item['ss13'];
            $verified = true;
            $member = $this->getVerifiedMember($ckey);
        }
        $content = '**__['.date('H:i:s', time()).']__ ' . ($ckey ?? $sender) . ": **$content";

        // $this->logger->debug("Sending message to {$channel->name} ({$channel->id}): {$message}");
        if ($announce_shard && $this->sharding && $this->enabled_servers) {
            if (! $enabled_servers_string = implode(', ', $this->enabled_servers)) $enabled_servers_string = 'None';
            if ($this->shard) $content .= '**SHARD FOR [' . $enabled_servers_string . ']**' . PHP_EOL;
            else $content = '**MAIN PROCESS FOR [' . $enabled_servers_string . ']**' . PHP_EOL . $content;
        }
        $builder = MessageBuilder::new();
        if ($urgent) $builder->setContent("<@&{$this->role_ids['Admin']}>, an urgent message has been sent!");
        if (! $urgent && $prevent_mentions) $builder->setAllowedMentions(['parse'=>[]]);
        if (! $verified && strlen($content)<=2000) return $channel->sendMessage($builder->setContent($content))->then($then, null);
        if (strlen($content)<=4096) {
            $embed = new Embed($this->discord);
            if ($recipient) $embed->setTitle(($ckey ?? $sender) . " => $recipient");
            if ($member) $embed->setAuthor("{$member->user->displayname} ({$member->id})", $member->avatar);
            $embed->setDescription($content);
            $builder->addEmbed($embed);
            return $channel->sendMessage($builder)->then($then, null);
        }
        return $channel->sendMessage($builder->addFileFromContent($file_name, $content))->then($then, null);
    }

    public function reply(Message $message, string $content, string $file_name = 'message.txt', bool $prevent_mentions = false, bool $announce_shard = true): ?PromiseInterface
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
        $this->httpHandler = new HttpHandler($this, [], $options['http_whitelist'] ?? [], $options['http_key'] ?? '');
        $this->messageHandler = new MessageHandler($this);
        $this->generateServerFunctions();
        $this->generateGlobalFunctions();
        $this->logger->debug('[CHAT COMMAND LIST] ' . PHP_EOL . $this->messageHandler->generateHelp());
        $this->logger->debug('[HTTP COMMAND LIST] ' . PHP_EOL . $this->httpHandler->generateHelp());
        
        if (! $this->serverinfo_url) $this->serverinfo_url = "http://{$this->webserver_url}/servers/serverinfo.json"; // Default to VZG unless passed manually in config

        if (isset($this->discord)) {
            $this->discord->once('ready', function () use ($options) {
                $this->ready = true;
                $this->logger->info("logged in as {$this->discord->user->displayname} ({$this->discord->id})");
                $this->logger->info('------');
                if (isset($options['webapi'], $options['socket'], $options['web_address'], $options['http_port'])) {
                    $this->logger->info('setting up HttpServer API');
                    $this->webapi = $options['webapi'];
                    $this->socket = $options['socket'];
                    $this->web_address = $options['web_address'];
                    $this->http_port = $options['http_port'];
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
                if (! $ooc_badwords_warnings = $this->VarLoad('ooc_badwords_warnings.json')) {
                    $ooc_badwords_warnings = [];
                    $this->VarSave('ooc_badwords_warnings.json', $ooc_badwords_warnings);
                }
                $this->ooc_badwords_warnings = $ooc_badwords_warnings;
                if (! $ic_badwords_warnings = $this->VarLoad('ic_badwords_warnings.json')) {
                    $ic_badwords_warnings = [];
                    $this->VarSave('ic_badwords_warnings.json', $ic_badwords_warnings);
                }
                $this->ic_badwords_warnings = $ic_badwords_warnings;
                $this->embed_footer = $this->github 
                    ? $this->github . PHP_EOL
                    : '';
                $this->embed_footer .= "{$this->discord->username}#{$this->discord->discriminator} by valithor" . PHP_EOL;

                $this->getVerified(); // Populate verified property with data from DB
                if ($this->httpHandler && $this->civ13_guild_id && $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) { // Whitelist the IPs of all High Staff
                    $members = $guild->members->filter(function ($member) {
                        return $member->roles->has($this->role_ids['High Staff']);
                    });
                    foreach ($members as $member)
                        if ($item = $this->getVerifiedItem($member->user))
                            if (isset($item['ss13']) && $ckey = $item['ss13'])
                                if ($playerlogs = $this->getCkeyLogCollections($ckey)['playerlogs'])
                                    foreach ($playerlogs as $log)
                                        if (isset($log['ip']))
                                            $this->httpHandler->whitelist($log['ip']);
                }
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
                //$this->setIPs();
                $this->serverinfo_url = "http://{$this->webserver_url}/servers/serverinfo.json";
                $this->serverinfoTimer(); // Start the serverinfo timer and update the serverinfo channel
                foreach ($this->provisional as $ckey => $discord_id) $this->provisionalRegistration($ckey, $discord_id); // Attempt to register all provisional users
                $this->bancheckTimer(); // Start the unban timer and remove the role from anyone who has been unbanned
                $this->pending = new Collection([], 'discord');
                // Initialize configurations
                if (! $discord_config = $this->VarLoad('discord_config.json')) $discord_config = [];
                foreach ($this->discord->guilds as $guild) if (! isset($discord_config[$guild->id])) $this->SetConfigTemplate($guild, $discord_config);
                $this->discord_config = $discord_config; // Declared, but not currently used for anything
                
                if (! empty($this->functions['ready'])) foreach ($this->functions['ready'] as $func) $func($this);
                else $this->logger->debug('No ready functions found!');
                if (! $this->shard) $this->discord->application->commands->freshen()->then(function (GlobalCommandRepository $commands): void
                {
                    $this->slash->updateCommands($commands);
                    if (! empty($this->functions['ready_slash'])) foreach (array_values($this->functions['ready_slash']) as $func) $func($this, $commands);
                    else $this->logger->debug('No ready slash functions found!');
                });
                
                $this->discord->on('message', function (Message $message): void
                {
                    if ($message->author->bot || $message->webhook_id) return; // Ignore bots and webhooks (including slash commands) to prevent infinite loops and other issues
                    if (! $this->messageHandler->handle($message, $message_filtered = $this->filterMessage($message))) { // This section will be deprecated in the future
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
                    if (! isset($this->discord_config[$guild->id])) $this->SetConfigTemplate($guild, $this->discord_config);
                });

                if ($guild = $this->discord->guilds->get('id', $this->civ13_guild_id) && (! (isset($this->timers['relay_timer'])) || (! $this->timers['relay_timer'] instanceof TimerInterface))) {
                    $this->logger->info('chat relay timer started');
                    if (! isset($this->timers['relay_timer'])) $this->timers['relay_timer'] = $this->discord->getLoop()->addPeriodicTimer(10, function ()
                    {
                        if ($this->relay_method !== 'file') return null;
                        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return $this->logger->error("Could not find Guild with ID `{$this->civ13_guild_id}`");
                        foreach ($this->server_settings as $settings) {
                            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                            if (isset($settings['ooc']) && $channel = $guild->channels->get('id', $settings['ooc'])) $this->gameChatFileRelay($settings['basedir'] . self::ooc_path, $channel);  // #ooc-server
                            if (isset($settings['asay']) && $channel = $guild->channels->get('id', $settings['asay'])) $this->gameChatFileRelay($settings['basedir'] . self::admin_path, $channel);  // #asay-server
                        }
                    });
                    if (! isset($this->timers['verifier_status_timer'])) $this->timers['verifier_status_timer'] = $this->discord->getLoop()->addPeriodicTimer(1800, function () {
                        if (! $status = $this->verifier_online) {
                            $this->getVerified(false); // Check if the verifier is back online, but don't try to reload the verified list from the file cache
                            if ($status !== $this->verifier_online) foreach ($this->provisional as $ckey => $discord_id) $this->provisionalRegistration($ckey, $discord_id); // If the verifier was offline, but is now online, reattempt registration of all provisional users
                        }
                    });
                }

                if ($application_commands = $this->discord->__get('application_commands')) {
                    $names = [];
                    foreach ($application_commands as $command) $names[] = $command->getName();
                    $namesString = '`' . implode('`, `', $names) . '`';
                    $this->logger->debug('[APPLICATION COMMAND LIST] ' . PHP_EOL . $namesString);
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

        $this->onFulfilledDefault = function ($result): void
        {
            $output = 'Promise resolved with type of: `' . gettype($result) . '`';
            if (is_object($result)) {
                $output .= ' and class of: `' . get_class($result) . '`';
                $output .= ' with properties: `' . implode('`, `', array_keys(get_object_vars($result))) . '`';
            }
            $this->logger->debug($output);
        };
        $this->onRejectedDefault = function ($reason): void
        {
            $this->logger->error("Promise rejected with reason: `$reason'`");
        };

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

        $this->byond = new Byond();
        return $options;
    }

    /**
     * Chains a callback to be executed when the promise is fulfilled or rejected.
     *
     * @param PromiseInterface $promise The promise to chain with.
     * @param callable|null $onFulfilled The callback to execute when the promise is fulfilled. If null, the default callback will be used.
     * @param callable|null $onRejected The callback to execute when the promise is rejected. If null, the default callback will be used.
     * @return PromiseInterface The new promise that will be fulfilled or rejected based on the result of the callback.
     */
    public function then(PromiseInterface $promise, ?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        return $promise->then($onFulfilled ?? $this->onFulfilledDefault, $onRejected ?? $this->onRejectedDefault);
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
        if (! $assoc_array = @json_decode($string, TRUE)) return null;
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
    {
        $server_settings = array_filter($this->server_settings, function($settings) use ($message_content_lower) {
            return $settings['key'] === strtolower($message_content_lower);
        });
        if (empty($server_settings)) return $this->reply($message, 'Please use the format `listbans {server}`. Valid servers: `' . implode(', ', array_keys($this->server_settings)) . '`');

        $server_settings = reset($server_settings);
        if (! isset($server_settings['basedir']) || ! file_exists($filename = $server_settings['basedir'] . self::bans)) {
            $this->logger->warning("Either basedir or `" . self::bans . "` is not defined or does not exist");
            return $message->react("🔥");
        }

        $builder = MessageBuilder::new();
        $builder->addFile($filename);
        return $message->reply($builder);
    }
    
    /*
    * This function is used to get either sanitize a ckey or a Discord snowflake
    */
    public function sanitizeInput(string $input): string
    {
        return trim(str_replace(['<@!', '<@&', '<@', '>', '.', '_', '-', '+', ' '], '', strtolower($input)));
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
            if ($item = $this->verified->get('ss13', $input)) return $item;
        }
        if (($input instanceof Member || $input instanceof User) && ($item = $this->verified->get('discord', $input->id))) return $item;
        if (is_array($input)) {
            if (! isset($input['discord']) && ! isset($input['ss13'])) return null;
            if (isset($input['discord']) && is_numeric($input['discord']) && $item = $this->verified->get('discord', $this->sanitizeInput($input['discord']))) return $item;
            if (isset($input['ss13']) && is_string($input['ss13']) && $item = $this->verified->get('ss13', $this->sanitizeInput($input['ss13']))) return $item;
        }

        return null;
    }

    /*
    * This function is used to get a Member object from a ckey or Discord ID
    * It will return false if the user is not verified, if the user is not in the Civ13 Discord server, or if the bot is not in the Civ13 Discord server
    */
    public function getVerifiedMember(Member|User|array|string|null $input): ?Member
    {
        if (! $input) return null;

        // Get the guild (required to get the member)
        $guild = $this->discord->guilds->get('id', $this->civ13_guild_id);
        if (! $guild) return null;

        // Get Discord ID
        $id = null;
        if ($input instanceof Member || $input instanceof User) $id = $input->id;
        elseif (is_string($input)) {
            if (is_numeric($input = $this->sanitizeInput($input))) $id = $input;
            elseif ($item = $this->verified->get('ss13', $input)) $id = $item['discord'];
        } elseif (is_array($input)) {
            if (isset($input['discord'])) {
                if (is_numeric($discordId = $this->sanitizeInput($input['discord']))) $id = $discordId;
            } elseif (isset($input['ss13'])) {
                if ($item = $this->verified->get('ss13', $this->sanitizeInput($input['ss13']))) $id = $item['discord'];
            }
        }
        if (! $id || ! $this->isVerified($id)) return null;
        return $guild->members->get('id', $id);
    }

    public function getRole(string $input): ?Role
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return null;
        if (is_numeric($input = $this->sanitizeInput($input))) return $guild->roles->get('id', $input);
        return $guild->roles->get('name', $input);
    }
    
    /*
    * This function is used to refresh the bot's cache of verified users
    * It is called when the bot starts up, and when the bot receives a GUILD_MEMBER_ADD event
    * It is also called when the bot receives a GUILD_MEMBER_REMOVE event
    * It is also called when the bot receives a GUILD_MEMBER_UPDATE event, but only if the user's roles have changed
    */
    /**
     * Retrieves verified users from a JSON file or an API endpoint and returns them as a Collection.
     *
     * @param bool $reload Whether to force a reload of the data from the cached data (JSON file) if the API endpoint is unreachable.
     *
     * @return Collection The verified users as a Collection.
     */
    public function getVerified(bool $initialize = true): Collection
    {
        $http_response_header = null;
        if (! $json = @file_get_contents($this->verify_url, false, stream_context_create(['http' => ['connect_timeout' => 5]]))) {
            $this->verifierStatusChannelUpdate($this->verifier_online = false);
        } else {
            $header = implode(' ', $http_response_header); // This is populated invisibly by file_get_contents
            $this->verifierStatusChannelUpdate($this->verifier_online = strpos($header, '502') === false);
        }
        if ($verified_array = $json ? json_decode($json, true) ?? [] : []) { // If the API endpoint is reachable, use the data from the API endpoint
            $this->VarSave('verified.json', $verified_array);
            return $this->verified = new Collection($verified_array, 'discord');
        }
        if ($initialize) { // If the API endpoint is unreachable, use the data from the file cache
            if (! $verified_array = $this->VarLoad('verified.json') ?? []) $this->VarSave('verified.json', $verified_array);
            return $this->verified = new Collection($verified_array, 'discord');
        }
        return $this->verified ?? new Collection($verified_array, 'discord'); 
    }

    public function getRoundsCollections(): array // [string $server, collection $rounds]
    {
        $collections_array = [];
        foreach ($this->rounds as $server => $rounds) {
            $r = [];
            foreach ($rounds as $game_id => $round) {
                $r[] = [
                    'game_id' => $game_id,
                    'start' => $round['start'] ?? null,
                    'end' => $round['end'] ?? null,
                    'players' => $round['players'] ?? [],
                ];
            }
            $collections_array[] = [$server => new Collection($r, 'game_id')];
        }
        return $collections_array;
    }
    
    public function logNewRound(string $server, string $game_id, string $time): void
    {
        if (array_key_exists($server, $this->current_rounds) && array_key_exists($this->current_rounds[$server], $this->rounds[$server]) && $this->rounds[$server][$this->current_rounds[$server]] && $game_id !== $this->current_rounds[$server]) // If the round already exists and is not the current round
            $this->rounds[$server][$this->current_rounds[$server]]['end'] = $time; // Set end time of previous round
        $this->current_rounds[$server] = $game_id; // Update current round
        $this->VarSave('current_rounds.json', $this->current_rounds); // Update log of currently running game_ids
        $round = &$this->rounds[$server][$game_id];
        $round = []; // Initialize round array
        $round['start'] = $time; // Set start time
        $round['end'] = null;
        $round['players'] = [];
        $round['interrupted'] = false;
        $this->VarSave('rounds.json', $this->rounds); // Update log of rounds
    }
    public function logPlayerLogin(string $server, string $ckey, string $time, string $ip = '', string $cid = ''): void
    {
        if ($game_id = $this->current_rounds[$server] ?? null) {
            $this->rounds[$server][$game_id]['players'][$ckey] = $this->rounds[$server][$game_id]['players'][$ckey] ?? [];
            $this->rounds[$server][$game_id]['players'][$ckey]['login'] = $this->rounds[$server][$game_id]['players'][$ckey]['login'] ?? $time;
            if ($ip && ! in_array($ip, $this->rounds[$server][$game_id]['players'][$ckey]['ip'] ?? [])) $this->rounds[$server][$game_id]['players'][$ckey]['ip'][] = $ip; 
            if ($cid && ! in_array($cid, $this->rounds[$server][$game_id]['players'][$ckey]['cid'] ?? [])) $this->rounds[$server][$game_id]['players'][$ckey]['cid'][] = $cid;
            $this->VarSave('rounds.json', $this->rounds);
        }
    }
    public function logPlayerLogout(string $server, string $ckey, string $time): void
    {
        if (array_key_exists($server, $this->current_rounds)
            && array_key_exists($ckey, $this->rounds[$server][$this->current_rounds[$server]]['players'])
            && array_key_exists('login', $this->rounds[$server][$this->current_rounds[$server]]['players'][$ckey]))
        {
            $this->rounds[$server][$this->current_rounds[$server]]['players'][$ckey]['logout'] = $time;
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
        for ($i = 0; $i < $length; $i++) $token .= $charset[random_int(0, strlen($charset) - 1)];
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
        if (! $page = $this->byond->getProfilePage($item['ss13'])) return false; // Website could not be retrieved or the description wasn't found
        if ($item['token'] != $this->byond->__extractProfileDesc($page)) return false; // Token does not match the description
        return true; // Token matches
    }

    /*
    * This function is used to check if the user has verified their account
    * If the have not, it checks to see if they have ever played on the server before
    * If they have not, it sends a message stating that they need to join the server first
    * It will send a message to the user with instructions on how to verify
    * If they have, it will check if they have the verified role, and if not, it will add it
    */
    public function verifyProcess(string $ckey, string $discord_id, ?Member $m = null): string
    {
        $ckey = $this->sanitizeInput($ckey);
        if (! isset($this->permitted[$ckey]) && $this->permabancheck($ckey)) {
            if ($m && ! $m->roles->has($this->role_ids['permabanished'])) $m->addRole($this->role_ids['permabanished'], "permabancheck $ckey");
            return 'This account needs to appeal an existing ban first.';
        }
        if (isset($this->softbanned[$ckey]) || isset($this->softbanned[$discord_id])) {
            if ($m && ! $m->roles->has($this->role_ids['permabanished'])) $m->addRole($this->role_ids['permabanished'], "permabancheck $ckey");
            return 'This account is currently under investigation.';
        }
        if ($this->verified->has($discord_id)) { $member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id); if (! $member->roles->has($this->role_ids['infantry'])) $member->setRoles([$this->role_ids['infantry']], "approveme join $ckey"); return 'You are already verified!';}
        if ($this->verified->has($ckey)) return "`$ckey` is already verified! If this is your account, contact {<@{$this->technician_id}>} to delete this entry.";
        if (! $this->pending->get('discord', $discord_id)) {
            if (! $age = $this->getByondAge($ckey)) return "Byond account `$ckey` does not exist!";
            if (! isset($this->permitted[$ckey]) && ! $this->checkProfileAge($age)) {
                $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => $reason = "Byond account `$ckey` does not meet the requirements to be approved. ($age)"];
                $msg = $this->ban($arr, null, [], true);
                if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                return $reason;
            }
            $found = false;
            $file_contents = '';
            foreach ($this->server_settings as $settings) {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                if (file_exists($settings['basedir'] . self::playerlogs) && $fc = @file_get_contents($settings['basedir'] . self::playerlogs)) $file_contents .= $fc;
                else $this->logger->warning('unable to open `' . $settings['basedir'] . self::playerlogs . '`');
            }
            foreach (explode('|', $file_contents) as $line) if (explode(';', trim($line))[0] === $ckey) { $found = true; break; }
            if (! $found) return "Byond account `$ckey` has never been seen on the server before! You'll need to join one of our servers at least once before verifying."; 
            return 'Login to your profile at ' . $this->byond::PROFILE . ' and enter this token as your description: `' . $this->generateByondToken($ckey, $discord_id) . PHP_EOL . '`Use the command again once this process has been completed.';
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

    public function unverifyCkey(string $id): array // ['success' => bool, 'message' => string]
    {
        if ( ! $verified_array = $this->VarLoad('verified.json')) {
            $this->logger->warning('Unable to load the verified list.');
            return ['success' => false, 'message' => 'Unable to load the verified list.'];
        }

        $removed = array_filter($verified_array, function ($value) use ($id) {
            return $value['ss13'] === $id || $value['discord'] === $id;
        });

        if (! $removed) {
            $this->logger->info("Unable to find `$id` in the verified list.");
            return ['success' => false, 'message' => "Unable to find `$id` in the verified list."];
        }

        $verified_array = array_values(array_diff_key($verified_array, $removed));
        $this->verified = new Collection($verified_array, 'discord');   
        $this->VarSave('verified.json', $verified_array);

         // Send $_POST information to the website.
        $message = '';
        if (isset($this->verify_url) && $this->verify_url) { // Bypass webserver deregistration if not configured
            $http_status = 0; // Don't try to curl if the webserver is down
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->verify_url,
                CURLOPT_HTTPHEADER => ['Content-Type' => 'application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => 'Civ13',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['method' => 'DELETE', 'token' => $this->civ_token, 'ckey' => $id, 'discord' => $id]),
                CURLOPT_CONNECTTIMEOUT => 5, // Set a connection timeout of 2 seconds
            ]);
            $result = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Validate the website's HTTP response! 200 = success, 403 = ckey already registered, anything else is an error
            curl_close($ch);
            switch ($http_status) {
                case 200: // Verified
                    if (! $member = $this->getVerifiedMember($id)) $message = "`$id` was unverified but the member couldn't be found in the server.";
                    if ($member && ($member->roles->has($this->role_ids['infantry']) || $member->roles->has($this->role_ids['veteran']))) $member->setRoles([], "unverified ($id)");
                    if ($channel = isset($this->channel_ids['staff_bot']) ? $this->discord->getChannel($this->channel_ids['staff_bot']) : null) $this->sendMessage($channel, "Unverified `$id`.");
                    $this->getVerified(false);
                    break;
                case 403: // Already registered
                    $message = "ID `$id` was not already verified."; // This should have been caught above. Need to run getVerified() again?
                    $this->getVerified(false);
                    break;
                case 404:
                    $message = 'The website could not be found or is misconfigured. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                    break;
                case 405: // Method not allowed
                    $message = "The method used to access the website is not allowed. Please check the configuration of the website." . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>. Reason: $result";
                    break;
                case 502: // NGINX's PHP-CGI workers are unavailable
                    $message = "The website's PHP-CGI workers are currently unavailable. Please try again later." . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                    break;
                case 503: // Database unavailable
                    $message = 'The website timed out while attempting to process the request because the database is currently unreachable. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                    break;
                case 504: // Gateway timeout
                    $message = 'The website timed out while attempting to process the request. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                    break;
                case 0: // The website is down, so allow provisional registration, then try to verify when it comes back up
                    $this->verifierStatusChannelUpdate($this->verifier_online = false);
                    $message = 'The website could not be reached. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                    break;
                default:
                    $message = "There was an error attempting to process the request: [$http_status] $result" . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                    break;
            }
            if (isset($ch)) curl_close($ch);
        }
        
        $removed_items = implode(PHP_EOL, array_map(fn($item) => json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $removed));
        if ($removed_items) $message .= PHP_EOL . 'Removed from the verified list: ```json' . PHP_EOL . $removed_items . PHP_EOL . '```' . PHP_EOL . $message;
        if ($message) $this->logger->info($message);
        return ['success' => true, 'message' => $message];
    }

    /**
     * Retrieves the age associated with the given ckey.
     *
     * @param string $ckey The ckey to retrieve the age for.
     * @return string|false The age associated with the ckey, or false if not found.
     */
    public function getByondAge(string $ckey): string|false
    {
        if (isset($this->ages[$ckey])) return $this->ages[$ckey];
        if ($age = $this->byond->getByondAge($ckey)) {
            $this->ages[$ckey] = $age;
            $this->VarSave('ages.json', $this->ages);
            return $this->ages[$ckey];
        }
        return false;
    }

    /**
     * This function is used to determine if a BYOND account is old enough to play on the server.
     *
     * @param string $age The age of the BYOND account in the format "YYYY-MM-DD".
     * @return bool Returns true if the account is old enough, false otherwise.
     */
    public function checkProfileAge(string $age): bool
    {
        return strtotime($age) <= strtotime($this->minimum_age);
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
            if (isset($result['error']) && $result['error']) {
                if (str_starts_with($result['error'], 'The website') || (! isset($this->verify_url) || ! $this->verify_url)) { // The website URL is not configured or the website could not be reached
                    if ($member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id))
                    if ((isset($this->verify_url) && $this->verify_url)) {
                        if (! isset($this->timers['provisional_registration_'.$discord_id])) $this->timers['provisional_registration_'.$discord_id] = $this->discord->getLoop()->addTimer(1800, function () use ($provisionalRegistration, $ckey, $discord_id) { $provisionalRegistration($ckey, $discord_id); });
                        if (! $member->roles->has($this->role_ids['infantry']) && isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Failed to verify Byond account `$ckey` with Discord ID <@$discord_id>: {$result['error']}" . PHP_EOL . 'Providing provisional verification role and trying again in 30 minutes... ');
                    }
                    if (! $member->roles->has($this->role_ids['infantry'])) $member->setRoles([$this->role_ids['infantry']], "Provisional verification `$ckey`");
                    return true;
                }
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
       
        $http_status = 0; // Don't try to curl if the webserver is down
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->verify_url,
            CURLOPT_HTTPHEADER => ['Content-Type' => 'application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Civ13',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['token' => $this->civ_token, 'ckey' => $ckey, 'discord' => $discord_id]),
            CURLOPT_CONNECTTIMEOUT => 5, // Set a connection timeout of 2 seconds
        ]);
        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Validate the website's HTTP response! 200 = success, 403 = ckey already registered, anything else is an error
        curl_close($ch);
        switch ($http_status) {
            case 200: // Verified
                $success = true;
                $error = "`$ckey` - ({$this->ages[$ckey]}) has been verified and registered to <@$discord_id>";
                $this->pending->offsetUnset($discord_id);
                $this->getVerified(false);
                if (! $member = $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id)) return ['success' => false, 'error' => "($ckey - {$this->ages[$ckey]}) was verified but the member couldn't be found in the server."];
                $channel = isset($this->channel_ids['staff_bot']) ? $this->discord->getChannel($this->channel_ids['staff_bot']) : null;
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
                $this->getVerified(false);
                // Check if the user is already verified and add the role if it's missing
                if (! $guild = $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) break;
                if (! $members = $guild->members->filter(function (Member $member) {
                    return ! $member->roles->has($this->role_ids['veteran'])
                        && ! $member->roles->has($this->role_ids['infantry'])
                        && ! $member->roles->has($this->role_ids['banished'])
                        && ! $member->roles->has($this->role_ids['permabanished'])
                        && ! $member->roles->has($this->role_ids['dungeon']);
                })) break;
                if (! $member = $members->get('id', $discord_id)) break;
                if (! $m = $this->getVerifiedMember($member)) break;
                $m->addRole($this->role_ids['infantry'], "approveme verified ($ckey)");
                break;
            case 404:
                $error = 'The website could not be found or is misconfigured. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
            case 502: // NGINX's PHP-CGI workers are unavailable
                $error = "The website's PHP-CGI workers are currently unavailable. Please try again later." . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
            case 503: // Database unavailable
                $error = 'The website timed out while attempting to process the request because the database is currently unreachable. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
            case 504: // Gateway timeout
                $error = 'The website timed out while attempting to process the request. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
            case 0: // The website is down, so allow provisional registration, then try to verify when it comes back up
                $this->verifierStatusChannelUpdate($this->verifier_online = false);
                $error = 'The website could not be reached. Please try again later.' . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                if (! $provisional) {
                    if (! isset($this->provisional[$ckey])) {
                        $this->provisional[$ckey] = $discord_id;
                        $this->VarSave('provisional.json', $this->provisional);
                    }
                    if ($this->provisionalRegistration($ckey, $discord_id)) $error = "The website could not be reached. Provisionally registered `$ckey` with Discord ID <@$discord_id>.";
                    else $error .= ' Provisional registration is already pending and a new provisional role will not be provided at this time.' . PHP_EOL . $error;
                }
                break;
            default:
                $error = "There was an error attempting to process the request: [$http_status] $result" . PHP_EOL . "If this error persists, contact <@{$this->technician_id}>.";
                break;
        }
        if (isset($ch)) curl_close($ch);
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
        $banned = $this->legacy ? $this->legacyBancheck($ckey) : $this->sqlBancheck($ckey);
        if (! $bypass && $member = $this->getVerifiedMember($ckey)) {
            $hasBanishedRole = $member->roles->has($this->role_ids['banished']);
            if ($banned && ! $hasBanishedRole) $member->addRole($this->role_ids['banished'], "bancheck ($ckey)");
            elseif (! $banned && $hasBanishedRole) $member->removeRole($this->role_ids['banished'], "bancheck ($ckey)");
        }
        return $banned;
    }
    public function legacyBancheck(string $ckey): bool
    {
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled'] || ! isset($settings['basedir'])) continue;
            if (file_exists($settings['basedir'] . self::bans) && $file = @fopen($settings['basedir'] . self::bans, 'r')) {
                while (($fp = fgets($file, 4096)) !== false) {
                    // str_replace(PHP_EOL, '', $fp); // Is this necessary?
                    $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                    if ((count($linesplit)>=8) && ($linesplit[8] === $ckey)) {
                        fclose($file);
                        return true;
                    }
                }
                fclose($file);
            } else $this->logger->debug('unable to open `' . $settings['basedir'] . self::bans . '`');
        }
        return false;
    }
    public function legacyPermabancheck(string $ckey): bool
    {
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled'] || ! isset($settings['basedir'])) continue;
            if (file_exists($settings['basedir'] . self::bans) && $file = @fopen($settings['basedir'] . self::bans, 'r')) {
                while (($fp = fgets($file, 4096)) !== false) {
                    // str_replace(PHP_EOL, '', $fp); // Is this necessary?
                    $linesplit = explode(';', trim(str_replace('|||', '', $fp))); // $split_ckey[0] is the ckey
                    if ((count($linesplit)>=8) && ($linesplit[8] === $ckey) && ($linesplit[0] === 'Server') && (str_ends_with($linesplit[7], '999 years'))) {
                        fclose($file);
                        return true;
                    }
                }
                fclose($file);
            } else $this->logger->debug('unable to open `' . $settings['basedir'] . self::bans . '`');
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
        if (! $this->bancheck($ckey, true)) foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['panic']) || ! $settings['panic']) continue;
            $settings['legacy']
                ? $this->legacyBan(['ckey' => $ckey, 'duration' => '1 hour', 'reason' => "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->discord_formatted}"], null, $settings)
                : $this->sqlBan(['ckey' => $ckey, 'reason' => '1 hour', 'duration' => "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->discord_formatted}"], null, $settings);
            $this->panic_bans[$ckey] = true;
            $this->VarSave('panic_bans.json', $this->panic_bans);
        }
    }
    public function __panicUnban(string $ckey): void
    {
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['panic']) || ! $settings['panic']) continue;
            $settings['legacy']
                ? $this->legacyUnban($ckey, null, $settings)
                : $this->sqlUnban($ckey, null, $settings);
            unset($this->panic_bans[$ckey]);
            $this->VarSave('panic_bans.json', $this->panic_bans);
        }
    }

    /*
    * These Legacy and SQL functions should not be called directly
    * Define $legacy = true/false and use ban/unban methods instead
    */
    public function sqlUnban($array, $admin = null, ?array $settings = []): string
    {
        return "SQL methods are not yet implemented!" . PHP_EOL;
    }
    public function legacyUnban(string $ckey, ?string $admin = null, ?array $settings = []): void
    {
        $admin = $admin ?? $this->discord->user->username;
        $legacyUnban = function (string $ckey, string $admin, array $settings)
        {
            if (file_exists($settings['basedir'] . self::discord2unban) && $file = @fopen($settings['basedir'] . self::discord2unban, 'a')) {
                fwrite($file, $admin . ":::$ckey");
                fclose($file);
            } else $this->logger->warning('unable to open `' . $settings['basedir'] . self::discord2unban . '`');
        };
        if ($settings) $legacyUnban($ckey, $admin, $settings);
        else foreach ($this->server_settings as $s) {
            if (! isset($s['enabled']) || ! $s['enabled']) continue;
            $legacyUnban($ckey, $admin, $s);
        }
    }
    public function sqlpersunban(string $ckey, ?string $admin = null): void
    {
        // TODO
    }
    public function legacyBan(array $array, ?string $admin = null, ?array $settings = []): string
    {
        $admin = $admin ?? $this->discord->user->username;
        $legacyBan = function (array $array, string $admin, array $settings): string
        {
            if (str_starts_with(strtolower($array['duration']), 'perm')) $array['duration'] = '999 years';
            if (file_exists($settings['basedir'] . self::discord2ban) && $file = @fopen($settings['basedir'] . self::discord2ban, 'a')) {
                fwrite($file, "$admin:::{$array['ckey']}:::{$array['duration']}:::{$array['reason']}" . PHP_EOL);
                fclose($file);
                return "**$admin** banned **{$array['ckey']}** from **{$settings['name']}** for **{$array['duration']}** with the reason **{$array['reason']}**" . PHP_EOL;
            } else {
                $this->logger->warning('unable to open `' . $settings['basedir'] . self::discord2ban . '`');
                return 'unable to open `' . $settings['basedir'] . self::discord2ban . '`' . PHP_EOL;
            }
        };
        if ($settings) return $legacyBan($array, $admin, $settings);
        $result = '';
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $result .= $legacyBan($array, $admin, $settings);
        }
        return $result;
    }
    public function sqlBan(array $array, $admin = null, ?string $settings = ''): string
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
    public function ban(array &$array /* = ['ckey' => '', 'duration' => '', 'reason' => ''] */, ?string $admin = null, ?array $settings = [], bool $permanent = false): string
    {
        if (! isset($array['ckey'])) return "You must specify a ckey to ban.";
        if (! is_numeric($array['ckey']) && ! is_string($array['ckey'])) return "The ckey must be a Byond username or Discord ID.";
        if (! isset($array['duration'])) return "You must specify a duration to ban for.";
        if ($array['duration'] === '999 years') $permanent = true;
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
        if ($this->legacy) return $this->legacyBan($array, $admin, $settings);
        return $this->sqlBan($array, $admin, $settings);
    }
    public function unban(string $ckey, ?string $admin = null,?array $settings = []): void
    {
        $admin ??= $this->discord->user->displayname;
        if ($this->legacy) $this->legacyUnban($ckey, $admin, $settings);
        else $this->sqlUnban($ckey, $admin, $settings);
        if (! $this->shard && $member = $this->getVerifiedMember($ckey)) {
            if ($member->roles->has($this->role_ids['banished'])) $member->removeRole($this->role_ids['banished'], "Unbanned by $admin");
            if ($member->roles->has($this->role_ids['permabanished'])) {
                $member->removeRole($this->role_ids['permabanished'], "Unbanned by $admin");
                $member->addRole($this->role_ids['infantry'], "Unbanned by $admin");
            }
        }
    }

    public function OOCMessage(string $message, string $sender, ?array $settings = []): bool
    {
        $oocmessage = function (string $message, string $sender, array $settings): bool
        {
            if (file_exists($settings['basedir'] . self::discord2ooc) && $file = @fopen($settings['basedir'] . self::discord2ooc, 'a')) {
                fwrite($file, "$sender:::$message" . PHP_EOL);
                fclose($file);
                if (isset($settings['ooc']) && $channel = $this->discord->getChannel($settings['ooc'])) $this->sendPlayerMessage($channel, false, $message, $sender);
                return true; 
            }
            $this->logger->error('unable to open `' . $settings['basedir'] . self::discord2ooc . '` for writing');
            return false;
        };
        $sent = false;
        foreach ($this->server_settings as $s) {
            if ($settings) {
                if ($settings['key'] !== $settings['key']) continue;
                if (! $s['enabled'] ?? false) return false;
                return $oocmessage($message, $sender, $settings);
            } else {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $sent = $oocmessage($message, $sender, $settings);
            }
        }
        return $sent;
    }

    public function AdminMessage(string $message, string $sender, ?array $settings = []): bool
    {
        $adminmessage = function (string $message, string $sender, array $settings): bool
        {
            if (file_exists($settings['basedir'] . self::discord2admin) && $file = @fopen($settings['basedir'] . self::discord2admin, 'a')) {
                fwrite($file, "$sender:::$message" . PHP_EOL);
                fclose($file);
                $urgent = true; // Check if there are any admins on the server, if not then send the message as urgent
                if ($guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) {
                    $admin = false;
                    if ($item = $this->verified->get('ss13', $sender))
                        if ($member = $guild->members->get('id', $item['discord']))
                            if ($member->roles->has($this->role_ids['Admin']))
                                {$admin = true; $urgent = false;}
                    if (! $admin)
                        if ($playerlist = $this->localServerPlayerCount()['playerlist'])
                            if ($admins = $guild->members->filter(function (Member $member) { return $member->roles->has($this->role_ids['Admin']); }))
                                foreach ($admins as $member)
                                    if ($item = $this->verified->get('discord', $member->id))
                                        if (in_array($item['ss13'], $playerlist))
                                            { $urgent = false; break; }
                }
                if (isset($settings['asay']) && $channel = $this->discord->getChannel($settings['asay'])) $this->sendPlayerMessage($channel, $urgent, $message, $sender);
                return true;
            }
            $this->logger->error('unable to open `' . $settings['basedir'] . self::discord2admin . '` for writing');
            return false;
        };
        $sent = false;
        foreach ($this->server_settings as $s) {
            if ($settings['key']) {
                if ($settings['key'] !== $s['key']) continue;
                if (! $s['enabled'] ?? false) return false;
                return $adminmessage($message, $sender, $settings);
            } else {
                if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
                $sent = $adminmessage($message, $sender, $settings);
            }
        }
        return $sent;
    }

    public function DirectMessage(string $recipient, string $message, string $sender, ?array $settings = []): bool
    {
        $directmessage = function (string $recipient, string $message, string $sender, array $settings): bool
        {
            if (file_exists($settings['basedir'] . self::discord2dm) && $file = @fopen($settings['basedir'] . self::discord2dm, 'a')) {
                fwrite($file, "$sender:::$recipient:::$message" . PHP_EOL);
                fclose($file);
                if (isset($settings['asay']) && $channel = $this->discord->getChannel($settings['asay'])) $this->sendPlayerMessage($channel, false, $message, $sender, $recipient);
                return true;
            }
            $this->logger->debug('unable to open `' . $settings['basedir'] . self::discord2dm . '` for writing');
            return false;
        };
        $sent = false;
        foreach ($this->server_settings as  $s) {
            if ($settings['key']) {
                if ($settings['key'] !== $s['key']) continue;
                if (! $s['enabled'] ?? false) return false;
                return $directmessage($recipient, $message, $sender, $settings);
            } else {
                if (! isset($s['enabled']) || ! $s['enabled']) continue;
                $sent = $directmessage($recipient, $message, $sender, $settings);
            }
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
            $stationname = $server['stationname'] ?? '';
            foreach (array_keys($server) as $key) {
                $p = explode('player', $key); 
                if (isset($p[1]) && is_numeric($p[1])) $this->players[$stationname][] = $this->sanitizeInput(urldecode($server[$key]));
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
            //if ($status === 'offline') $msg .= PHP_EOL . "Webserver technician <@{$this->technician_id}> has been notified.";
            $channel->name = "{$webserver_name}-{$status}";
            $success = function ($result) use ($channel, $status) {
                $this->loop->addTimer(2, function () use ($channel, $status): void
                {
                    $channel_new = $this->discord->getChannel($channel->id);
                    $this->sendMessage($channel_new, "Webserver is now **{$status}**.");
                });
            };
            return $this->then($channel->guild->channels->save($channel), $success);
        }
        return null;
    }
    public function verifierStatusChannelUpdate(bool $status): ?PromiseInterface
    {
        if (! $channel = $this->discord->getChannel($this->channel_ids['verifier-status'])) return null;
        [$verifier_name, $reported_status] = explode('-', $channel->name);
        $status = $this->verifier_online
            ? 'online'
            : 'offline';
        if ($reported_status != $status) {
            //if ($status === 'offline') $msg .= PHP_EOL . "Verifier technician <@{$this->technician_id}> has been notified.";
            $channel->name = "{$verifier_name}-{$status}";
            $success = function ($result) use ($channel, $status) {
                $this->loop->addTimer(2, function () use ($channel, $status): void
                {
                    $channel_new = $this->discord->getChannel($channel->id);
                    $this->sendMessage($channel_new, "Verifier is now **{$status}**.");
                });
            };
            return $this->then($channel->guild->channels->save($channel), $success);
        }
        return null;
    }
    /*public function statusChannelUpdate(bool $status, string $channel_id): ?PromiseInterface
    {
        if (! $channel = $this->discord->getChannel($channel_id)) return null;
        [$server_name, $reported_status] = explode('-', $channel->name);
        $status_string = $status
            ? 'online'
            : 'offline';
        if ($reported_status != $status_string) {
            //if ($status === 'offline') $msg .= PHP_EOL . "Server technician <@{$this->technician_id}> has been notified.";
            $this->sendMessage($channel, "Server is now **{$status_string}**.");
            $channel->name = "{$server_name}-{$status_string}";
            return $channel->guild->channels->save($channel);
        }
        return null;
    }*/
    public function serverinfoFetch(): array
    {
        $context = stream_context_create(['http' => ['connect_timeout' => 5]]);
        if (! $data_json = @json_decode(@file_get_contents($this->serverinfo_url, false, $context),  true)) {
            $this->logger->debug("unable to retrieve serverinfo from {$this->serverinfo_url}");
            $this->webserverStatusChannelUpdate($this->webserver_online = false);
            return [];
        }
        $this->webserverStatusChannelUpdate($this->webserver_online = true);
        $this->logger->debug("successfully retrieved serverinfo from {$this->serverinfo_url}");
        return $this->serverinfo = $data_json;
    }
    public function bansToCollection(): Collection
    {
        // Get the contents of the file
        $file_contents = '';
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (file_exists($settings['basedir'] . self::bans) && $fc = @file_get_contents($settings['basedir'] . self::bans)) $file_contents .= $fc;
            else $this->logger->warning('unable to open `' . $settings['basedir'] . self::bans . '`');
        }
        
        // Create a new collection
        $ban_collection = new Collection([], 'increment');
        if (! $file_contents) return $ban_collection;
        $file_contents = str_replace(PHP_EOL, '', $file_contents);
        $increment = 0;
        foreach (explode('|||', $file_contents) as $item) if ($ban = $this->banArrayToAssoc(explode(';', $item))) {
            $ban['increment'] = ++$increment;
            $ban_collection->pushItem($ban);
        }
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
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (file_exists($settings['basedir'] . self::playerlogs) && $fc = @file_get_contents($settings['basedir'] . self::playerlogs)) $file_contents .= $fc;
            else $this->logger->warning('unable to open `' . $settings['basedir'] . self::playerlogs . '`');
        }
        $file_contents = str_replace(PHP_EOL, '', $file_contents);

        $arrays = [];
        $i = 0;
        foreach (explode('|', $file_contents) as $item) {
            if ($log = $this->playerlogArrayToAssoc(explode(';', $item))) {
                $log['increment'] = ++$i;
                $arrays[] = $log;
            }
        }
        return new Collection($arrays, 'increment');
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
                return ['playerlogs' => $playerlog, 'bans' => $bans];
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
        foreach ($collectionsArray['playerlogs'] as $log) { // Get the ckey's primary identifiers
            if (isset($log['ip']) && ! in_array($log['ip'], $ips)) $ips[] = $log['ip'];
            if (isset($log['cid']) && ! in_array($log['cid'], $cids)) $cids[] = $log['cid'];
        }
        foreach ($collectionsArray['bans'] as $log) { // Get the ckey's primary identifiers
            if (isset($log['ip']) && ! in_array($log['ip'], $ips)) $ips[] = $log['ip'];
            if (isset($log['cid']) && ! in_array($log['cid'], $ips)) $cids[] = $log['cid'];
        }
        // Iterate through the playerlogs ban logs to find all known ckeys, ips, and cids
        $playerlogs = $this->playerlogsToCollection();
        for ($i = 0; $i < 10; $i++) {
            $found = false;
            $found_ckeys = [];
            $found_ips = [];
            $found_cids = [];
            foreach ($playerlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                if (! in_array($log['ckey'], $ckeys)) { $found_ckeys[] = $log['ckey']; $found = true; }
                if (! in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                if (! in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
            }
            if (! $found) break;
            $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
            $ips = array_unique(array_merge($ips, $found_ips));
            $cids = array_unique(array_merge($cids, $found_cids));
        }

        $banlogs = $this->bansToCollection();
        for ($i = 0; $i < 10; $i++) {
            $found = false;
            $found_ckeys = [];
            $found_ips = [];
            $found_cids = [];
            foreach ($banlogs as $log) if (in_array($log['ckey'], $ckeys) || in_array($log['ip'], $ips) || in_array($log['cid'], $cids)) {
                if (! in_array($log['ckey'], $ips)) { $found_ckeys[] = $log['ckey']; $found = true; }
                if (! in_array($log['ip'], $ips)) { $found_ips[] = $log['ip']; $found = true; }
                if (! in_array($log['cid'], $cids)) { $found_cids[] = $log['cid']; $found = true; }
            }
            if (! $found) break;
            $ckeys = array_unique(array_merge($ckeys, $found_ckeys));
            $ips = array_unique(array_merge($ips, $found_ips));
            $cids = array_unique(array_merge($cids, $found_cids));
        }

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
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);
        curl_close($ch);
        $json = @json_decode($response, true);
        if (! $json) return ''; // If the request timed out or if the service 429'd us
        if ($json['status'] === 'success') return $json['countryCode'] . '->' . $json['region'] . '->' . $json['city'];
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
    public function checkCkey(string $ckey): void
    { // Suspicious user ban rules
        if (! isset($this->permitted[$ckey]) && ! in_array($ckey, $this->seen_players)) {
            $this->seen_players[] = $ckey;
            $ckeyinfo = $this->ckeyinfo($ckey);
            if ($ckeyinfo['altbanned']) { // Banned with a different ckey
                $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->discord_formatted}"];
                $msg = $this->ban($arr, null, [], true) . ' (Alt Banned)';
                if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
            } else foreach ($ckeyinfo['ips'] as $ip) {
                if (in_array($this->IP2Country($ip), $this->blacklisted_countries)) { // Country code
                    $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->discord_formatted}"];
                    $msg = $this->ban($arr, null, [], true) . ' (Blacklisted Country)';
                    if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                    break;
                } else foreach ($this->blacklisted_regions as $region) if (str_starts_with($ip, $region)) { //IP Segments
                    $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->discord_formatted}"];
                    $msg = $this->ban($arr, null, [], true) . ' (Blacklisted Region)';
                    if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                    break 2;
                }
            }
        }
        if ($this->verified->get('ss13', $ckey)) return;
        if ($this->panic_bunker || (isset($this->serverinfo[1]['admins']) && $this->serverinfo[1]['admins'] == 0 && isset($this->serverinfo[1]['vote']) && $this->serverinfo[1]['vote'] == 0)) {
            $this->__panicBan($ckey); // Require verification for Persistence rounds
            return;
        }
        if (! isset($this->permitted[$ckey]) && ! isset($this->ages[$ckey]) && ! $this->checkProfileAge($age = $this->getByondAge($ckey))) { //Ban new accounts
            $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Byond account `$ckey` does not meet the requirements to be approved. ($age)"];
            $msg = $this->ban($arr, null, [], true);
            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
        }
    }
    public function serverinfoTimer(): TimerInterface
    {
        $serverinfoTimerOnline = function () {
            $this->serverinfoFetch(); 
            $this->serverinfoParsePlayers();
            foreach ($this->serverinfoPlayers() as $server_array) foreach ($server_array as $ckey) $this->checkCkey($ckey);
        };
        //$serverinfoTimerOnline();

        $serverinfoTimerOffline = function () {
            $arr = $this->localServerPlayerCount();
            $servers = $arr['playercount'];
            $server_array = $arr['playerlist'];
            foreach ($servers as $server => $count) $this->playercountChannelUpdate($server, $count); // This needs to be updated to pass $settings instead of "{$server}-""
            foreach ($server_array as $ckey) {
                if (is_null($ckey)) continue;
                if (! isset($this->permitted[$ckey]) && ! in_array($ckey, $this->seen_players)) { // Suspicious user ban rules
                    $this->seen_players[] = $ckey;
                    $ckeyinfo = $this->ckeyinfo($ckey);
                    if (isset($ckeyinfo['altbanned']) && $ckeyinfo['altbanned']) { // Banned with a different ckey
                        $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->discord_formatted}"];
                        $msg = $this->ban($arr, null, [], true). ' (Alt Banned)';;
                        if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                    } else if (isset($ckeyinfo['ips'])) foreach ($ckeyinfo['ips'] as $ip) {
                        if (in_array($this->IP2Country($ip), $this->blacklisted_countries)) { // Country code
                            $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->discord_formatted}"];
                            $msg = $this->ban($arr, null, [], true) . ' (Blacklisted Country)';
                            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                            break;
                        } else foreach ($this->blacklisted_regions as $region) if (str_starts_with($ip, $region)) { //IP Segments
                            $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->discord_formatted}"];
                            $msg = $this->ban($arr, null, [], true) . ' (Blacklisted Region)';
                            if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                            break 2;
                        }
                    }
                }
                if ($this->verified->get('ss13', $ckey)) continue;
                //if ($this->panic_bunker || (isset($this->serverinfo[1]['admins']) && $this->serverinfo[1]['admins'] == 0 && isset($this->serverinfo[1]['vote']) && $this->serverinfo[1]['vote'] == 0)) return $this->__panicBan($ckey); // Require verification for Persistence rounds
                if (! isset($this->permitted[$ckey]) && ! isset($this->ages[$ckey]) && ! $this->checkProfileAge($age = $this->getByondAge($ckey))) { //Ban new accounts
                    $arr = ['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Byond account `$ckey` does not meet the requirements to be approved. ($age)"];
                    $msg = $this->ban($arr, null, [], true);
                    if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, $msg);
                }
            }
        };
        //$serverinfoTimerOffline();

        if (! isset($this->timers['serverinfo_timer'])) $this->timers['serverinfo_timer'] = $this->discord->getLoop()->addPeriodicTimer(180, function () use ($serverinfoTimerOnline, $serverinfoTimerOffline) {
            $timerFunction = $this->webserver_online ? $serverinfoTimerOnline : $serverinfoTimerOffline;
            $timerFunction();
        });
        return $this->timers['serverinfo_timer']; // Check players every minute
    }
    /*
    * This function parses the serverinfo data and updates the relevant Discord channel name with the current player counts
    * Prefix is used to differentiate between two different servers, however it cannot be used with more due to ratelimits on Discord
    * It is called on ready and every 5 minutes
    */
    private function playercountChannelUpdate(string|array $settings, int $count = 0): bool
    {
        if (is_string($settings)) {
            $filteredSettings = array_filter($this->server_settings, function ($item) use ($settings) {
                return $item['key'] === $settings;
            });
            if (! empty($filteredSettings)) $settings = reset($filteredSettings);
            else $settings = [];
        }

        if (! $channel = $this->discord->getChannel($settings['playercount'])) {
            $this->logger->warning("Channel {$settings['playercount']} doesn't exist!");
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
    public function serverinfoParse(array $return = []): array
    {
        if (empty($this->serverinfo) || ! $serverinfo = $this->serverinfo) {
            return $return; // No data to parse
            $this->logger->warning('No serverinfo data to parse!');
        }
        $index = 0; // We need to keep track of the index we're looking at, as the array may not be sequential
        foreach ($this->server_settings as $settings) {
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
                $this->playercountChannelUpdate($settings, $p1);
            }
            $index++;
        }
        $this->playercount_ticker++;
        return $return;
    }
    /**
     * Returns an array of the player count for each locally hosted server in the configuration file.
     *
     * @return array
     */
    public function localServerPlayerCount(array $servers = [], array $players = []): array
    {
        foreach ($this->server_settings as $settings) {            
            if (! isset($settings['ip'], $settings['port'])) {
                $this->logger->warning("Server {$settings['key']} is missing required settings in config!");
                continue;
            }
            if ($settings['ip'] !== $this->httpHandler->external_ip) continue;
            $socket = @fsockopen('localhost', intval($settings['port']), $errno, $errstr, 1);
            $server_status = is_resource($socket) ? 'Online' : 'Offline';
            $servers[$settings['key']] = 0;
            if ($server_status === 'Online') {
                fclose($socket);
                if (file_exists($settings['basedir'] . self::serverdata) && $data = @file_get_contents($settings['basedir'] . self::serverdata)) {
                    $data = explode(';', str_replace(['<b>Address</b>: ', '<b>Map</b>: ', '<b>Gamemode</b>: ', '<b>Players</b>: ', 'round_timer=', 'map=', 'epoch=', 'season=', 'ckey_list=', '</b>', '<b>'], '', $data));
                    /*
                    0 => <b>Server Status</b> {Online/Offline}
                    1 => <b>Address</b> byond://{ip_address}
                    2 => <b>Map</b>: {map}
                    3 => <b>Gamemode</b>: {gamemode}
                    4 => <b>Players</b>: {playercount}
                    5 => realtime={realtime}
                    6 => world.address={ip}
                    7 => round_timer={00:00}
                    8 => map={map}
                    9 => epoch={epoch}
                    10 => season={season}
                    11 => ckey_list={ckey&ckey}
                    */
                    if (isset($data[11])) { // Player list
                        $players = explode('&', $data[11]);
                        $players = array_map(fn($player) => $this->sanitizeInput($player), $players);
                    }
                    if (isset($data[4])) $servers[$settings['key']] = $data[4]; // Player count
                }
            }
        }
        return ['playercount' => $servers, 'playerlist' => $players];
    }

    public function generateServerstatusEmbed(): Embed
    {        
        $embed = new Embed($this->discord);
        foreach ($this->server_settings as $settings) {            
            if (! isset($settings['ip'], $settings['port'])) {
                $this->logger->warning("Server {$settings['key']} is missing required settings in config!");
                continue;
            }
            if ($settings['ip'] !== $this->httpHandler->external_ip) continue;
            $socket = @fsockopen('localhost', intval($settings['port']), $errno, $errstr, 1);
            $server_status = is_resource($socket) ? 'Online' : 'Offline';
            if ($server_status === 'Offline') $embed->addFieldValues($settings['name'], $server_status);
            if ($server_status === 'Online') {
                fclose($socket);
                if (file_exists($settings['basedir'] . self::serverdata) && $data = @file_get_contents($settings['basedir'] . self::serverdata)) {
                    $data = explode(';', str_replace(['<b>Address</b>: ', '<b>Map</b>: ', '<b>Gamemode</b>: ', '<b>Players</b>: ', 'round_timer=', 'map=', 'epoch=', 'season=', 'ckey_list=', '</b>', '<b>'], '', $data));
                    /*
                    0 => <b>Server Status</b> {Online/Offline}
                    1 => <b>Address</b> byond://{ip_address}
                    2 => <b>Map</b>: {map}
                    3 => <b>Gamemode</b>: {gamemode}
                    4 => <b>Players</b>: {playercount}
                    5 => realtime={realtime}
                    6 => world.address={ip}
                    7 => round_timer={00:00}
                    8 => map={map}
                    9 => epoch={epoch}
                    10 => season={season}
                    11 => ckey_list={ckey&ckey}
                    */
                    if (isset($data[1])) $embed->addFieldValues($settings['name'], '<'.$data[1].'>');
                    if (isset($settings['host'])) $embed->addFieldValues('Host', $settings['host'], true);
                    if (isset($data[7])) {
                        list($hours, $minutes) = explode(':', $data[7]);
                        $hours = intval($hours);
                        $minutes = intval($minutes);
                        $days = floor($hours / 24);
                        $hours = $hours % 24;
                        $time = ($days ? $days . 'd' : '') . ($hours ? $hours . 'h' : '') . $minutes . 'm';
                        $embed->addFieldValues('Round Time', $time, true);
                    }
                    if (isset($data[8])) $embed->addFieldValues('Map', $data[8], true); // Appears twice in the data
                    //if (isset($data[3])) $embed->addFieldValues('Gamemode', $data[3], true);
                    if (isset($data[9])) $embed->addFieldValues('Epoch', $data[9], true);
                    if (isset($data[11])) { // Player list
                        $players = explode('&', $data[11]);
                        $players = array_map(fn($player) => $this->sanitizeInput($player), $players);
                        if (! $players_list = implode(", ", $players)) $players_list = 'N/A';
                        $embed->addFieldValues('Players', $players_list, true);
                    }
                    if (isset($data[10])) $embed->addFieldValues('Season', $data[10], true);
                    //if (isset($data[5])) $embed->addFieldValues('Realtime', $data[5], true);
                    //if (isset($data[6])) $embed->addFieldValues('IP', $data[6], true);
                    
                }
            }
        }
        $embed->setFooter($this->embed_footer);
        $embed->setColor(0xe1452d);
        $embed->setTimestamp();
        $embed->setURL('');
        return $embed;
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
        foreach ($this->server_settings as $settings) {            
            if (! $server = array_shift($serverinfo)) continue; // No data for this server
            if (! isset($settings['supported']) || ! $settings['supported']) { $index++; continue; } // Server is not supported by the remote webserver and won't appear in data
            if (! isset($settings['name'])) { 
                $this->logger->warning("Server {$settings['name']} is missing a name in config!");
                $index++; continue;
            } // Server is missing required settings in config 
            if (array_key_exists('ERROR', $server)) { $index++; continue; } // Remote webserver reports server is not responding

            $p1 = (isset($server['players'])
                ? $server['players']
                : count(array_map(fn($player) => $this->sanitizeInput(urldecode($player)), array_filter($server, function (string $key) { return str_starts_with($key, 'player') && !str_starts_with($key, 'players'); }, ARRAY_FILTER_USE_KEY)))
            );
            $this->playercountChannelUpdate($settings, $p1);
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
        if ($member->guild_id === $this->civ13_guild_id && $item = $this->verified->get('discord', $member->id)) {
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
        if (isset($this->welcome_message, $this->channel_ids['get-approved']) && $this->welcome_message && $member->guild_id === $this->civ13_guild_id)
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
    public function bancheckTimer(): bool
    {
        // We don't want the persistence server to do this function
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['basedir']) || ! file_exists($settings['basedir'] . self::bans) || ! $file = @fopen($settings['basedir'] . self::bans , 'r')) return false;
            fclose($file);
        }

        $bancheckTimer = function () {
            if ($this->shard) return;
            if (isset($this->role_ids['banished']) && $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) foreach ($guild->members as $member) {
                if (! $item = $this->getVerifiedMemberItems()->get('discord', $member->id)) continue;
                $banned = $this->bancheck($item['ss13'], true);
                if ($banned && ! ($member->roles->has($this->role_ids['banished']) || $member->roles->has($this->role_ids['permabanished']))) {
                    $member->addRole($this->role_ids['banished'], 'bancheck timer');
                    if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Added the banished role to $member.");
                } elseif (! $banned && ($member->roles->has($this->role_ids['banished']) || $member->roles->has($this->role_ids['permabanished']))) {
                    $member->removeRole($this->role_ids['banished'], 'bancheck timer');
                    $member->removeRole($this->role_ids['permabanished'], 'bancheck timer');
                    if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "Removed the banished role from $member.");
                }
            }
        };
        $bancheckTimer();
        if (! isset($this->timers['bancheck_timer'])) $this->timers['bancheck_timer'] = $this->discord->getLoop()->addPeriodicTimer(43200, function () use ($bancheckTimer) { $bancheckTimer(); });
        return true;
    }

    /*
    * This function is used to change the bot's status on Discord
    */
    public function statusChanger(Activity $activity, string $state = 'online'): void
    {
        if (! $this->shard) $this->discord->updatePresence($activity, false, $state);
    }

    /*
    * These functions handle in-game chat moderation and relay those messages to Discord
    * Players will receive warnings and bans for using blacklisted words
    */
    public function gameChatFileRelay(string $file_path, string $channel_id, ?bool $moderate = false, bool $ooc = true): bool
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
            $fp = html_entity_decode(str_replace(PHP_EOL, '', $fp)); // Parsing HTML will remove any instances of < and >, so we need to decode them first. Players can use these characters in their messages too, and that behavior must be moderated by the game instead.
            $string = substr($fp, strpos($fp, '/')+1);
            if ($string && $ckey = $this->sanitizeInput(substr($string, 0, strpos($string, ':'))))
                $relay_array[] = ['ckey' => $ckey, 'message' => $fp, 'server' => explode('-', $channel->name)[0]];
        }
        ftruncate($file, 0);
        fclose($file);
        return $this->__gameChatRelay($relay_array, $channel, $moderate, $ooc); // Disabled moderation as it is now done quicker using the Webhook system
    }
    public function gameChatWebhookRelay(string $ckey, string $message, string $channel_id, ?bool $moderate = true, ?bool $ooc = true): bool
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
            $listener = function () use ($ckey, $message, $channel_id, $moderate, $ooc, &$listener) {
                $this->gameChatWebhookRelay($ckey, $message, $channel_id, $moderate, $ooc);
                $this->discord->removeListener('ready', $listener);
            };
            $this->discord->on('ready', $listener);
            return true; // Assume that the function will succeed when the bot is ready
        }
        
        return $this->__gameChatRelay(['ckey' => $ckey, 'message' => $message, 'server' => explode('-', $channel->name)[1]], $channel, $moderate, $ooc);
    }
    private function __gameChatRelay(array $array, $channel, bool $moderate = true, bool $ooc = true): bool
    {
        if (! $array || ! isset($array['ckey']) || ! isset($array['message']) || ! isset($array['server']) || ! $array['ckey'] || ! $array['message'] || ! $array['server']) {
            $this->logger->warning('__gameChatRelay() was called with an empty array or invalid content.');
            return false;
        }
        if ($moderate && $this->moderate) {
            if ($ooc) $this->__gameChatModerate($array['ckey'], $array['message'], $this->ooc_badwords, $this->ooc_badwords_warnings, $array['server']);
            else $this->__gameChatModerate($array['ckey'], $array['message'], $this->ic_badwords, $this->ic_badwords_warnings, $array['server']);
        }
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
    private function __gameChatModerate(string $ckey, string $string, array $badwords_array, array &$badword_warnings, string $server = 'nomads'): string
    {
        $lower = strtolower($string);
        foreach ($badwords_array as $badwords) switch ($badwords['method']) {
            case 'exact': // ban ckey if $string contains a blacklisted phrase exactly as it is defined
                if (preg_match('/\b' . $badwords['word'] . '\b/i', $lower)) {
                    $this->__relayViolation($server, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
            case 'cyrillic': // ban ckey if $string contains a cyrillic character
                if (preg_match('/\p{Cyrillic}/ui', $lower)) {
                    $this->__relayViolation($server, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
            case 'str_starts_with':
                if (str_starts_with($lower, $badwords['word'])) {
                    $this->__relayViolation($server, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
            case 'str_ends_with':
                if (str_ends_with($lower, $badwords['word'])) {
                    $this->__relayViolation($server, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
            case 'str_contains': // ban ckey if $string contains a blacklisted word
            default: // default to 'contains'
                if (str_contains($lower, $badwords['word'])) {
                    $this->__relayViolation($server, $ckey, $badwords, $badword_warnings);
                    break 2;
                }
                continue 2;
        }
        return $string;
    }
    // This function is called from the game's chat hook if a player says something that contains a blacklisted word
    private function __relayViolation(string $server, string $ckey, array $badwords_array, array &$badword_warnings): string|bool // TODO: return type needs to be decided
    {
        if ($this->sanitizeInput($ckey) === $this->sanitizeInput($this->discord->user->displayname)) return false; // Don't ban the bot
        $filtered = substr($badwords_array['word'], 0, 1) . str_repeat('%', strlen($badwords_array['word'])-2) . substr($badwords_array['word'], -1, 1);
        if (! $this->__relayWarningCounter($ckey, $badwords_array, $badword_warnings)) {
            $arr = ['ckey' => $ckey, 'duration' => $badwords_array['duration'], 'reason' => "Blacklisted phrase ($filtered). Review the rules at {$this->rules}. Appeal at {$this->discord_formatted}"];
            return $this->ban($arr);
        }
        $warning = "You are currently violating a server rule. Further violations will result in an automatic ban that will need to be appealed on our Discord. Review the rules at {$this->rules}. Reason: {$badwords_array['reason']} ({$badwords_array['category']} => $filtered)";
        if (isset($this->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->channel_ids['staff_bot'])) $this->sendMessage($channel, "`$ckey` is" . substr($warning, 7));
        foreach ($this->server_settings as $settings) if (strtolower($server) === $settings['key']) return $this->DirectMessage($ckey, $warning, $this->discord->user->displayname, $settings);
        return false;
    }
    /*
    * This function determines if a player has been warned too many times for a specific category of bad words
    * If they have, it will return false to indicate they should be banned
    * If they have not, it will return true to indicate they should be warned
    */
    private function __relayWarningCounter(string $ckey, array $badwords_array, array &$badword_warnings): bool
    {
        if (! isset($badword_warnings[$ckey][$badwords_array['category']])) $badword_warnings[$ckey][$badwords_array['category']] = 1;
        else ++$badword_warnings[$ckey][$badwords_array['category']];

        $filename = '';
        if ($badword_warnings === $this->ic_badwords_warnings) $filename = 'ic_badwords_warnings.json';
        elseif ($badword_warnings === $this->ooc_badwords_warnings) $filename = 'ooc_badwords_warnings.json';
        if ($filename !== '') $this->VarSave($filename, $badword_warnings);

        if ($badword_warnings[$ckey][$badwords_array['category']] > $badwords_array['warnings']) return false;
        return true;
   }

    /*
    * This function calculates the player's ranking based on their medals
    * Returns true if the required files are successfully read, false otherwise
    */
    public function recalculateRanking(): bool
    {
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['basedir'])) continue;
            $awards_path = $settings['basedir'] . self::awards_path;
            if ( ! file_exists($awards_path) || ! touch($awards_path)) return false;
            $ranking_path = $settings['basedir'] . self::ranking_path;
            if ( ! file_exists($ranking_path) || ! touch($ranking_path)) return false;
            if (! $lines = file($awards_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) return false;
            $result = array();
            foreach ($lines as $line) {
                $medal_s = 0;
                $duser = explode(';', trim($line));
                $medalScores = [
                    'long service medal' => 0.5,
                    'wounded badge' => 0.5,
                    'tank destroyer silver badge' => 0.75,
                    'wounded silver badge' => 0.75,
                    'wounded gold badge' => 1,
                    'assault badge' => 1.5,
                    'tank destroyer gold badge' => 1.5,
                    'combat medical badge' => 2,
                    'iron cross 1st class' => 3,
                    'iron cross 2nd class' => 5,
                ];
                if (! isset($result[$duser[0]])) $result[$duser[0]] = 0;
                if (isset($duser[2]) && isset($medalScores[$duser[2]])) $medal_s += $medalScores[$duser[2]];
                $result[$duser[0]] += $medal_s;
            }
            arsort($result);
            if (file_put_contents($ranking_path, implode(PHP_EOL, array_map(function ($ckey, $score) {
                return "$score;$ckey";
            }, array_keys($result), $result))) === false) return false;
        }
        return true;
    }

    /**
     * Retrieves the ranking from a file and returns it as a formatted string.
     *
     * @param string $path The path to the file containing the ranking data.
     * @return false|string Returns the top 10 ranks as a string if found, or false if the file does not exist or cannot be opened.
     */
    
    public function getRanking(string $path): false|string
    {
        $line_array = array();
        if (! file_exists($path) || ! $search = @fopen($path, 'r')) return false;
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
    }

    /**
     * Retrieves the rank for a given ckey from a file.
     *
     * @param string $path The path to the file containing the ranking data.
     * @param string $ckey The ckey to search for.
     * @return false|string Returns the rank for the ckey as a string if found, or false if the file does not exist or cannot be accessed.
     */
    public function getRank(string $path, string $ckey): false|string
    {
        $line_array = array();
        if (! file_exists($path) || ! touch($path) || ! $search = @fopen($path, 'r')) return false;
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
    }

    // Check that all required roles are properly declared in the bot's config and exist in the guild
    public function hasRequiredConfigRoles(array $required_roles = []): bool
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) { $this->logger->error('Guild ' . $this->civ13_guild_id . ' is missing from the bot'); return false; }
        if ($diff = array_diff($required_roles, array_keys($this->role_ids))) { $this->logger->error('Required roles are missing from the `role_ids` config', $diff); return false; }
        foreach ($required_roles as $role) if (! isset($this->role_ids[$role]) || ! $guild->roles->get('id', $this->role_ids[$role])) { $this->logger->error("$role role is missing from the guild"); return false; }
        return true;
    }

    /*
    * This function is used to update the contents of files based on the roles of verified members
    * The callback function is used to determine what to write to the file
    */
    public function updateFilesFromMemberRoles(callable $callback, array $file_paths, array $required_roles): void
    {
        /* This is currently not working as intended
        $callbackParams = new \ReflectionFunction($callback);
        if (
            !(
                $callbackParams->getNumberOfParameters() === 3 && // Function must expect 3 parameters
                //count($callbackParams) === 3 && // There must be 3 parameters availble to pass to the function
                $callbackParams[0] !== null && $callbackParams[0] instanceof Member &&
                $callbackParams[1] !== null && is_array($callbackParams[1]) &&
                $callbackParams[2] !== null && is_array($callbackParams[2])
            )
        ) {
            $this->logger->error('updateFilesFromMemberRoles() was called with an invalid callback function');
            return;
        }
        */
        
        $file_contents = '';
        foreach ($this->verified as $item)
            if ($member = $this->getVerifiedMember($item))
                $file_contents .= $callback($member, $item, $required_roles);
        if ($file_contents) foreach ($file_paths as $fp) if (file_exists($fp))
            if (file_put_contents($fp, $file_contents) === false) // Attempt to write to the file
                $this->logger->error("Failed to write to file `$fp`"); // Log an error if the write failed
    }

    // This function is used to update the whitelist files
    public function whitelistUpdate(?array $required_roles = ['veteran']): bool
    {
        if (! $this->hasRequiredConfigRoles($required_roles)) return false;
        $file_paths = [];
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['basedir']) || ! file_exists($settings['basedir'] . self::whitelist)) continue;
            $file_paths[] = $settings['basedir'] . self::whitelist;
        }

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
    public function factionlistUpdate(?array $required_roles = ['red', 'blue', 'organizer']): bool
    {
        if (! $this->hasRequiredConfigRoles($required_roles)) return false;
        $file_paths = [];
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['basedir']) || ! file_exists($settings['basedir'] . self::factionlist)) continue;
            $file_paths[] = $settings['basedir'] . self::factionlist;
        }

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
     * @param array $required_roles An array of required roles and their corresponding permissions.
     * @return bool Returns true if the update was successful, false otherwise.
     */
    public function adminlistUpdate(
        $required_roles = [
            'Owner' => ['Host', '65535'],
            'Chief Technical Officer' => ['Chief Technical Officer', '65535'],
            'Host' => ['Host', '65535'], // Default Host permission, only used if another role is not found first
            'Head Admin' => ['Head Admin', '16382'],
            'Manager' => ['Manager', '16382'],
            'Supervisor' => ['Supervisor', '16382'],
            'High Staff' => ['High Staff', '16382'], // Default High Staff permission, only used if another role is not found first
            'Admin' => ['Admin', '16254'],
            'Moderator' => ['Moderator', '25088'],
            //'Developer' => ['Developer', '7288'], // This Discord role doesn't exist
            'Mentor' => ['Mentor', '16384'],
        ]
    ): bool
    {
        if (! $this->hasRequiredConfigRoles(array_keys($required_roles))) return false;
        $file_paths = [];
        foreach ($this->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['basedir']) || ! file_exists($settings['basedir'] . self::admins)) continue;
            $file_paths[] = $settings['basedir'] . self::admins;
        }

        $callback = function (Member $member, array $item, array $required_roles): string
        {
            $string = '';
            $checked_ids = [];
            foreach (array_keys($required_roles) as $role)
                if ($member->roles->has($this->role_ids[$role]))
                    if (! in_array($member->id, $checked_ids)) {
                        $string .= "{$item['ss13']};{$required_roles[$role][0]};{$required_roles[$role][1]}|||" . PHP_EOL;
                        $checked_ids[] = $member->id;
                    }
            return $string;
        };
        $this->updateFilesFromMemberRoles($callback, $file_paths, $required_roles);
        return true;
    }
}