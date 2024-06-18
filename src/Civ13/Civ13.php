<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Byond\Byond;
use Civ13\Slash;
use Civ13\Moderator;
use Discord\Discord;
use Discord\Builders\MessageBuilder;
use Discord\Helpers\BigInt;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Role;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Activity;
use Discord\Parts\User\Member;
use Discord\Stats;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use React\Http\Browser;
use React\Filesystem\AdapterInterface;
use React\Filesystem\Factory as FilesystemFactory;
use ReflectionFunction;

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

    public bool $ready = false;
    public array $options = [];
    
    public Byond $byond;
    public Moderator $moderator;
    public Verifier $verifier;

    public string $welcome_message = '';
    
    public \Closure $onFulfilledDefault;
    public \Closure $onRejectedDefault;

    public Slash $slash;
    public HttpServiceManager $httpServiceManager;
    public MessageServiceManager $messageServiceManager;
    public CommandServiceManager $commandServiceManager;
    
    public string $webserver_url = 'www.valzargaming.com'; // The URL of the webserver that the bot pulls server information from

    public StreamSelectLoop $loop;
    public Discord $discord;
    public Browser $browser;
    public AdapterInterface $filesystem;
    public Logger $logger;
    public Stats $stats;

    public string $filecache_path = '';
    
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

    /**
     * @var Timerinterface[]
     */
    public array $timers = [];
    public array $serverinfo = []; // Collected automatically by serverinfo_timer
    public array $players = []; // Collected automatically by serverinfo_timer
    public array $seen_players = []; // Collected automatically by serverinfo_timer
    public int $playercount_ticker = 0;

    public array $current_rounds = [];
    public array $rounds = [];

    /**
     * @var Gameserver[]
     */
    public array $gameservers = [];
    /**
     * @var Gameserver[]
     */
    public array $enabled_gameservers = [];
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
    
    public array $tests = []; // Staff application test templates
    public bool $panic_bunker = false; // If true, the bot will server ban anyone who is not verified when they join the server
    public array $panic_bans = []; // List of ckeys that have been banned by the panic bunker in the current runtime

    /**
     * Creates a Civ13 client instance.
     * 
     * @param array $options An array of options for configuring the client.
     * @param array $server_settings An array of configurations for the game servers.
     * @throws E_USER_ERROR If the code is not running in a CLI environment.
     * @throws E_USER_WARNING If the ext-gmp extension is not loaded.
     */
    public function __construct(array $options = [], array $server_settings = [])
    {
        if (php_sapi_name() !== 'cli') trigger_error('DiscordPHP will not run on a webserver. Please use PHP CLI to run a DiscordPHP bot.', E_USER_ERROR);

        // x86 need gmp extension for big integer operation
        if (PHP_INT_SIZE === 4 && ! BigInt::init()) trigger_error('ext-gmp is not loaded. Permissions will NOT work correctly!', E_USER_WARNING);

        $this->logger =  $options['logger'] ?? $this->discord->getLogger() ?? new Logger(self::class, [new StreamHandler('php://stdout', Level::Info)]);
        $options = $this->resolveOptions($options);
        $this->options =& $options;
        if (isset($options['discord']) && ($options['discord'] instanceof Discord)) $this->discord =& $options['discord'];
        elseif (isset($options['discord_options']) && is_array($options['discord_options'])) $this->discord = new Discord($options['discord_options']);
        else $this->logger->error('No Discord instance or options passed in options!');
        $this->loop = $options['loop'] ?? $this->discord->getLoop();

        $this->browser = $options['browser'];
        $this->filesystem = $options['filesystem'];
        $this->stats = $options['stats'];
        
        $this->filecache_path = getcwd() . '/json/';
        if (isset($options['filecache_path']) && is_string($options['filecache_path'])) {
            if (! str_ends_with($options['filecache_path'], '/')) $options['filecache_path'] .= '/';
            $this->filecache_path = $options['filecache_path'];
        }
        if (! is_dir($this->filecache_path)) mkdir($this->filecache_path, 0664, true);
        
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
        
        if (isset($options['functions'])) foreach (array_keys($options['functions']) as $key1) foreach ($options['functions'][$key1] as $key2 => $func) $this->functions[$key1][$key2] = $func;
        else $this->logger->warning('No functions passed in options!');
        
        if (isset($options['files'])) foreach ($options['files'] as $key => $path) $this->files[$key] = $path;
        else $this->logger->warning('No files passed in options!');
        if (isset($options['channel_ids'])) foreach ($options['channel_ids'] as $key => $id) $this->channel_ids[$key] = $id;
        else $this->logger->warning('No channel_ids passed in options!');
        if (isset($options['role_ids'])) foreach ($options['role_ids'] as $key => $id) $this->role_ids[$key] = $id;
        else $this->logger->warning('No role_ids passed in options!');

        $this->afterConstruct($options, $server_settings);
    }
    /**
     * This method is called after the object is constructed.
     * It initializes various properties, starts timers, and starts handling events.
     *
     * @param array $options An array of options.
     * @param array $server_options An array of server options.
     * @return void
     */
    private function afterConstruct(array $options = [], array $server_settings = []): void
    {
        $this->__loadOrInitializeVariables();
        new Moderator($this);
        new Verifier($this, $options);
        foreach ($server_settings as $gameserver_settings) new Gameserver($this, $gameserver_settings);
        $this->byond = new Byond();
        $this->httpServiceManager = new HttpServiceManager($this);
        $this->messageServiceManager = new MessageServiceManager($this);
        if (isset($this->discord)) $this->discord->once('ready', function () {
            $this->ready = true;
            $this->logger->info("Logged in as {$this->discord->username} {$this->discord->user}");
            /*$this->discord->users->fetch($this->discord->id)->then(function ($user) {
                $this->logger->info('User:' . json_encode($user));
            });*/
            $this->logger->info('------');
            //$this->commandServiceManager = new CommandServiceManager($this->discord, $this->httpServiceManager, $this->messageServiceManager, $this);
            $this->__UpdateDiscordVariables();
            //else $this->logger->debug('No ready functions found!');
            $this->loop->addTimer(5, function () {
                $this->slash = new Slash($this);
            });
            $this->declareListeners();
            $this->bancheckTimer(); // Start the unban timer and remove the role from anyone who has been unbanned
            if (! empty($this->functions['ready'])) foreach ($this->functions['ready'] as $func) $func($this);
        });
    }
    /**
     * Resolves the given options array by validating and setting default values for each option.
     *
     * @param array $options An array of options to be resolved.
     * @return array The resolved options array.
     */
    private function resolveOptions(array &$options = []): array
    {
        if (! isset($options['welcome_message']) || ! is_string($options['welcome_message'])) {
            $options['welcome_message'] = '';
        }
        $this->welcome_message = $options['welcome_message'];
        
        if (! isset($options['logger']) || ! ($options['logger'] instanceof Logger)) {
            $streamHandler = new StreamHandler('php://stdout', Level::Info);
            $streamHandler->setFormatter(new LineFormatter(null, null, true, true));
            $options['logger'] = new Logger(self::class, [$streamHandler]);
        }
        $onFulfilledDefaultValid = false;
        if (isset($options['onFulfilledDefault']) && is_callable($options['onFulfilledDefault'])) {
            if ($reflection = new ReflectionFunction($options['onFulfilledDefault']))
                if ($returnType = $reflection->getReturnType())
                    if ($returnType->getName() !== 'void')
                        { $this->onFulfilledDefault = $options['onFulfilledDefault']; $onFulfilledDefaultValid = true; }
        }
        if (! $onFulfilledDefaultValid) $this->onFulfilledDefault = function ($result) {
            $output = 'Promise resolved with type of: `' . gettype($result) . '`';
            if (is_object($result)) {
                $output .= ' and class of: `' . get_class($result) . '`';
                $output .= ' with properties: `' . implode('`, `', array_keys(get_object_vars($result))) . '`';
                if (isset($result->scriptData)) $output .= " and scriptData of: `{$result->scriptData}`";
                $output .= PHP_EOL;
                ob_start();
                var_dump($result);
                $output .= ob_get_clean();
            }
            $this->logger->debug($output);
            return $result;
        };
        $onRejectedDefaultValid = false;
        if (isset($options['onRejectedDefault']) && is_callable($options['onRejectedDefault'])) {
            if ($reflection = new ReflectionFunction($options['onRejectedDefault']))
                if ($returnType = $reflection->getReturnType())
                    if ($returnType->getName() === 'void')
                        { $this->onRejectedDefault = $options['onRejectedDefault']; $onRejectedDefaultValid = true; }
        }
        if (! $onRejectedDefaultValid) $this->onRejectedDefault = function ($reason): void
        {
            $this->logger->error("Promise rejected with reason: `$reason'`");
        };

        if (isset($options['folders'])) foreach ($options['folders'] as $key => $value) if (! is_string($value) || ! is_dir($value) || ! @mkdir($value, 0664, true)) {
            $this->logger->warning("`$value` is not a valid folder path!");
            unset($options['folders'][$key]);
        }
        if (isset($options['files'])) foreach ($options['files'] as $key => $value) if (! is_string($value) || ! @touch($value)) {
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
     * Loads or initializes the variables used by the Civ13 class.
     * This method loads the values from JSON files or initializes them if the files do not exist.
     * It also handles the interruption of rounds if the bot was restarted during a round.
     * This process can take a while, so it should be called before the Discord client is ready.
     */
    private function __loadOrInitializeVariables(): void
    {
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

        if (! $ages = $this->VarLoad('ages.json')) {
            $ages = [];
            $this->VarSave('ages.json', $ages);
        }
        $this->ages = $ages;
        if (! $this->serverinfo_url) $this->serverinfo_url = "http://{$this->webserver_url}/servers/serverinfo.json"; // Default to VZG unless passed manually in config
        $this->embed_footer = $this->github 
        ? $this->github . PHP_EOL
        : '';
    }
    /**
     * Loads or initializes the variables used by the Civ13 class.
     * These variables rely on the Discord client being ready.
     */
    private function __UpdateDiscordVariables(): void
    {
        $this->embed_footer .= "{$this->discord->username}#{$this->discord->discriminator} by valithor" . PHP_EOL;
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
        if (! $onRejected) $onRejectedDefault = function ($reason) use ($promise, $onFulfilled): void
        { // TODO: Add a check for Discord disconnects and refire the promise
            $this->logger->error("Promise rejected with reason: `$reason'`");
            if (str_starts_with($reason, 'Promise rejected with reason: `RuntimeException: Connection to tls://discord.com:443 timed out after 60 seconds (ETIMEDOUT)')) { // Promise attempted to resolve while Discord was disconnected
                ob_start();
                var_dump($promise);
                $debug_string = ob_get_clean();
                $this->logger->error("Original promise dump: $debug_string");
                ob_start();
                var_dump($onFulfilled);
                $debug_string = ob_get_clean();
                $this->logger->error("onFulfilled callback dump: $debug_string");
            }
        };
        return $promise->then($onFulfilled ?? $this->onFulfilledDefault, $onRejected ?? $onRejectedDefault ?? $this->onRejectedDefault);
    }

    /**
     * Filters the message and extracts relevant information.
     *
     * @param Message $message The message to filter.
     * @return array An array containing the filtered message content, the lowercased message content, and a flag indicating if the message was called.
     */
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
    /**
     * Sanitizes the input (either a ckey or a Discord snowflake) by removing specific characters and converting it to lowercase.
     *
     * @param string $input The input string to be sanitized.
     * @return string The sanitized input string.
     */
    public function sanitizeInput(string $input): string
    {
        return trim(str_replace(['<@!', '<@&', '<@', '>', '.', '_', '-', '+', ' '], '', strtolower($input)));
    }
    /**
     * Check that all required roles are properly declared in the bot's config and exist in the guild.
     *
     * @param array $required_roles An array of required role names.
     * @return bool Returns true if all required roles exist, false otherwise.
     */
    public function hasRequiredConfigRoles(array $required_roles = []): bool
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) { $this->logger->error('Guild ' . $this->civ13_guild_id . ' is missing from the bot'); return false; }
        if ($diff = array_diff($required_roles, array_keys($this->role_ids))) { $this->logger->error('Required roles are missing from the `role_ids` config', $diff); return false; }
        foreach ($required_roles as $role) if (! isset($this->role_ids[$role]) || ! $guild->roles->get('id', $this->role_ids[$role])) { $this->logger->error("$role role is missing from the guild"); return false; }
        return true;
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

    /*
     * This function is used to change the bot's status on Discord
     */
    public function statusChanger(Activity $activity, string $state = 'online'): void
    {
        $this->discord->updatePresence($activity, false, $state);
    }
    /**
     * Sends a message to the specified channel.
     *
     * @param Channel|Thread|string $channel The channel to send the message to. Can be a channel ID or a Channel object.
     * @param string $content The content of the message.
     * @param string $file_name The name of the file to attach to the message. Default is 'message.txt'.
     * @param bool $prevent_mentions Whether to prevent mentions in the message. Default is false.
     * @return PromiseInterface|null A PromiseInterface representing the asynchronous operation, or null if the channel is not found.
     */
    public function sendMessage(Channel|Thread|string $channel, string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): ?PromiseInterface
    {
        // $this->logger->debug("Sending message to {$channel->name} ({$channel->id}): {$message}");
        if (is_string($channel) && ! $channel = $this->discord->getChannel($channel)) {
            $this->logger->error("Channel not found for sendMessage");
            return null;
        }
        $builder = MessageBuilder::new();
        if ($prevent_mentions) $builder->setAllowedMentions(['parse'=>[]]);
        if (strlen($content)<=2000) return $channel->sendMessage($builder->setContent($content));
        if (strlen($content)>4096) return $channel->sendMessage($builder->addFileFromContent($file_name, $content));
        $embed = new Embed($this->discord);
        $embed->setDescription($content);
        return $channel->sendMessage($builder->addEmbed($embed));
    }
    /**
     * Sends a message as a reply to another message.
     *
     * @param Message $message The original message to reply to.
     * @param string $content The content of the reply message.
     * @param string $file_name The name of the file to attach to the reply message (default: 'message.txt').
     * @param bool $prevent_mentions Whether to prevent mentions in the reply message (default: false).
     * @return PromiseInterface|null A promise that resolves to the sent reply message, or null if the reply message could not be sent.
     */
    public function reply(Message $message, string $content, string $file_name = 'message.txt', bool $prevent_mentions = false): ?PromiseInterface
    {
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
     * Sends an embed message to a channel.
     *
     * @param Channel|Thread|string $channel The channel to send the message to.
     * @param string $content The content of the message.
     * @param Embed $embed The embed object to send.
     * @param bool $prevent_mentions (Optional) Whether to prevent mentions in the message. Default is false.
     * @return PromiseInterface|null A promise that resolves to the sent message, or null if the channel is not found.
     */
    public function sendEmbed(Channel|Thread|string $channel, Embed $embed, string $content, bool $prevent_mentions = false): ?PromiseInterface
    {
        if (is_string($channel) && ! $channel = $this->discord->getChannel($channel)) {
            $this->logger->error("Channel not found for sendEmbed");
            return null;
        }
        $builder = MessageBuilder::new();
        if ($prevent_mentions) $builder->setAllowedMentions(['parse'=>[]]);
        // $this->logger->debug("Sending message to {$channel->name} ({$channel->id}): {$message}");
        if (is_string($channel)) $channel = $this->discord->getChannel($channel);
        if (! $channel) {
            $this->logger->error("Channel not found: {$channel}");
            return null;
        }
        $builder->setContent($content);
        $builder->addEmbed($embed);
        return $channel->sendMessage($builder);
    }
    /**
     * Sends a player message to a channel.
     *
     * @param Channel|Thread|string $channel The channel to send the message to.
     * @param bool $urgent Whether the message is urgent or not.
     * @param string $content The content of the message.
     * @param string $sender The sender of the message (ckey or Discord username).
     * @param string $recipient The recipient of the message (optional).
     * @param string $file_name The name of the file to attach to the message (default: 'message.txt').
     * @param bool $prevent_mentions Whether to prevent mentions in the message (default: false).
     * @return PromiseInterface|null A promise that resolves to the sent message, or null if the message couldn't be sent.
     */
    public function relayPlayerMessage(Channel|Thread|string $channel, string $content, string $sender, ?string $recipient = '', ?bool $urgent = false, string $file_name = 'message.txt', bool $prevent_mentions = false): PromiseInterface|false
    {
        if (is_string($channel) && ! $channel = $this->discord->getChannel($channel)) {
            $this->logger->error("Channel not found for relayPlayerMessage");
            return false;
        }
        $then = function (Message $message) { $this->logger->debug("Urgent message sent to {$message->channel->name} ({$message->channel->id}): {$message->content} with message link {$message->url}"); };

        // Sender is the ckey or Discord username
        $ckey = null;
        $member = null;
        $verified = false;
        if (isset($this->verifier) && $item = $this->verifier->getVerifiedItem($sender)) {
            $ckey = $item['ss13'];
            $verified = true;
            $member = $this->verifier->getVerifiedMember($ckey);
        }
        $content = '**__['.date('H:i:s', time()).']__ ' . ($ckey ?? $sender) . ": **$content";

        $builder = MessageBuilder::new();
        if ($urgent) $builder->setContent("<@&{$this->role_ids['Admin']}>, an urgent message has been sent!");
        if (! $urgent && $prevent_mentions) $builder->setAllowedMentions(['parse'=>[]]);
        if (! $verified && strlen($content)<=2000) return $channel->sendMessage($builder->setContent($content))->then($then, null);
        if (strlen($content)>4096) return $channel->sendMessage($builder->addFileFromContent($file_name, $content))->then($then, null);
        $embed = new Embed($this->discord);
        if ($recipient) $embed->setTitle(($ckey ?? $sender) . " => $recipient");
        if ($member) $embed->setAuthor("{$member->user->username} ({$member->id})", $member->avatar);
        $embed->setDescription($content);
        $builder->addEmbed($embed);
        return $channel->sendMessage($builder)->then($then, null);
        
    }
    /**
     * Sends an out-of-character (OOC) message.
     *
     * @param string $message The message to send.
     * @param string $sender The sender of the message.
     * @param string|int|null $server_key Server for the message (optional).
     * @return bool Returns true if the message was sent successfully, false otherwise.
     */
    public function OOCMessage(string $message, string $sender, string|int|null $server_key = null): PromiseInterface|bool
    {
        if (is_null($server_key)) {
            $sent = false;
            foreach ($this->enabled_gameservers as $server) if ($server->OOCMessage($message, $sender)) $sent = true;
            return $sent;
        }
        if (! isset($this->enabled_gameservers[$server_key])) return false;
        return $this->enabled_gameservers[$server_key]->OOCMessage($message, $sender);
    }
    /**
     * Sends an admin message to the server.
     *
     * @param string $message The message to send.
     * @param string $sender The sender of the message.
     * @param string|int|null $server_key Server for the message (optional).
     * @return bool Returns true if the message was sent successfully, false otherwise.
     */
    public function AdminMessage(string $message, string $sender, string|int|null $server_key = null): bool
    {
        if (is_null($server_key)) {
            $sent = false;
            foreach ($this->enabled_gameservers as $server) if ($server->AdminMessage($message, $sender)) $sent = true;
            return $sent;
        }
        if (! isset($this->enabled_gameservers[$server_key])) return false;
        return $this->enabled_gameservers[$server_key]->AdminMessage($message, $sender);
    }
    /**
     * Sends a direct message to a recipient using the specified sender and message.
     *
     * @param string $recipient The recipient of the direct message.
     * @param string $message The content of the direct message.
     * @param string $sender The sender of the direct message.
     * @param string|int|null $server_key Server for sending the direct message (optional).
     * @return bool Returns true if the direct message was sent successfully, false otherwise.
     */
    public function DirectMessage(string $message, string $sender, string $recipient, string|int|null $server_key = null): bool
    {
        if (is_null($server_key)) {
            $sent = false;
            foreach ($this->enabled_gameservers as $server) if ($server->DirectMessage($message, $sender, $recipient)) $sent = true;
            return $sent;
        }
        if (! isset($this->enabled_gameservers[$server_key])) return false;
        return $this->enabled_gameservers[$server_key]->DirectMessage($message, $sender, $recipient);
    }

    /**
     * Relays in-game chat messages to Discord and handles chat moderation.
     *
     * This function reads chat messages from a file and relays them to a Discord channel.
     * It also performs chat moderation by checking for blacklisted words and applying warnings and bans to players.
     *
     * @param string $file_path The path to the file containing the chat messages.
     * @param string $channel_id The ID of the Discord channel to relay the messages to.
     * @param bool|null $moderate (Optional) Whether to enable chat moderation. Defaults to false.
     * @param bool $ooc (Optional) Whether to include out-of-character (OOC) messages. Defaults to true.
     * @return bool Returns true if the chat messages were successfully relayed, false otherwise.
     */
    public function gameChatFileRelay(string $file_path, string $channel_id, ?bool $moderate = false, ?bool $ooc = true): bool
    {
        if ($this->relay_method !== 'file') return false;
        if (! @touch($file_path) || ! $file = @fopen($file_path, 'r+')) {
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
        return $this->__gameChatRelay($channel, $relay_array, $moderate, $ooc); // Disabled moderation as it is now done quicker using the Webhook system
    }
    /**
     * Relays game chat messages to a Discord channel using a webhook.
     *
     * @param string $ckey The ckey of the player sending the message.
     * @param string $message The message to be relayed.
     * @param string $channel_id The ID of the Discord channel to relay the message to.
     * @param bool|null $moderate Whether to moderate the message or not. Defaults to true.
     * @param bool|null $ooc Whether the message is out-of-character or not. Defaults to true.
     * @return bool Returns true if the message was successfully relayed, false otherwise.
     */
    public function gameChatWebhookRelay(string $ckey, string $message, string $channel_id, string|int $gameserver_key, ?bool $ooc = true): bool
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
            $listener = function () use ($ckey, $message, $channel_id, $gameserver_key, $ooc, &$listener) {
                $this->gameChatWebhookRelay($ckey, $message, $channel_id, $gameserver_key, $ooc);
                $this->discord->removeListener('ready', $listener);
            };
            $this->discord->on('ready', $listener);
            return true; // Assume that the function will succeed when the bot is ready
        }
        
        return $this->__gameChatRelay($channel, ['ckey' => $ckey, 'message' => $message, 'server' => explode('-', $channel->name)[1]], $gameserver_key, $ooc);
    }
    /**
     * Relays game chat messages to a Discord channel.
     *
     * @param Channel|Thread|string $channel The Discord channel to send the message to.
     * * @param array $array The array containing the chat message information.
     * @param bool $moderate (optional) Whether to apply moderation to the message. Default is true.
     * @param bool $ooc (optional) Whether the message is out-of-character (OOC) or in-character (IC). Default is true.
     * @return bool Returns true if the message was successfully relayed, false otherwise.
     */
    private function __gameChatRelay(Channel|Thread|string $channel, array $array, string|int $gameserver_key, ?bool $ooc = true): bool
    {
        if (is_string($channel) && ! $channel = $this->discord->getChannel($channel)) {
            $this->logger->error("Channel not found for __gameChatRelay");
            return null;
        }
        if (! $array || ! isset($array['ckey']) || ! isset($array['message']) || ! isset($array['server']) || ! $array['ckey'] || ! $array['message'] || ! $array['server']) {
            $this->logger->warning('__gameChatRelay() was called with an empty array or invalid content.');
            return false;
        }
        if (isset($this->moderator) && ($gameserver = $this->enabled_gameservers[$gameserver_key]) && $gameserver->moderate) {
            if ($ooc) $this->moderator->moderate($array['ckey'], $array['message'], $this->ooc_badwords, $this->ooc_badwords_warnings, $gameserver->key);
            else $this->moderator->moderate($array['ckey'], $array['message'], $this->ic_badwords, $this->ic_badwords_warnings, $gameserver->key);
        }
        if (! $item = $this->verifier->get('ss13', $this->sanitizeInput($array['ckey']))) {
            $this->sendMessage($channel, $array['message'], 'relay.txt', false, false);
            return true;
        }
        $builder = MessageBuilder::new();
        $embed = new Embed($this->discord);
        if ($user = $this->discord->users->get('id', $item['discord'])) $embed->setAuthor("{$user->username} ({$user->id})", $user->avatar);
        // else $this->discord->users->fetch('id', $item['discord']); // disabled to prevent rate limiting
        $embed->setDescription($array['message']);
        $builder->addEmbed($embed);
        $channel->sendMessage($builder);
        return true;
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
     * Retrieves the Role object based on the given input.
     *
     * @param string $input The input to search for the Role.
     * @return Role|null The Role object if found, or null if not found.
     */
    public function getRole(string $input): ?Role
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return null;
        if (is_numeric($input = $this->sanitizeInput($input))) return $guild->roles->get('id', $input);
        return $guild->roles->get('name', $input);
    }

    private function declareListeners(): void
    {
        $this->discord->on('GUILD_MEMBER_ADD', function (Member $member): void
        {
            if (! empty($this->functions['GUILD_MEMBER_ADD'])) foreach ($this->functions['GUILD_MEMBER_ADD'] as $func) $func($this, $member);
            //else $this->logger->debug('No GUILD_MEMBER_ADD functions found!');
        });

        $this->discord->on('GUILD_MEMBER_REMOVE', function (Member $member): void
        {
            if (! empty($this->functions['GUILD_MEMBER_REMOVE'])) foreach ($this->functions['GUILD_MEMBER_REMOVE'] as $func) $func($this, $member);
            //else $this->logger->debug('No GUILD_MEMBER_REMOVE functions found!');
        });

        $this->discord->on('GUILD_MEMBER_UPDATE', function (Member $member, Discord $discord, ?Member $member_old): void
        {
            if (! empty($this->functions['GUILD_MEMBER_UPDATE'])) foreach ($this->functions['GUILD_MEMBER_UPDATE'] as $func) $func($this, $member);
            //else $this->logger->debug('No GUILD_MEMBER_UPDATE functions found!');
        });

        $this->discord->on('GUILD_CREATE', function (Guild $guild): void
        {
            if (! empty($this->functions['GUILD_CREATE'])) foreach ($this->functions['GUILD_CREATE'] as $func) $func($this, $guild);
            //else $this->logger->debug('No GUILD_CREATE functions found!');
        });
    }
    
    /**
     * These functions are used to save and load data to and from files.
     * Please maintain a consistent schema for directories and files
     *
     * The bot's $filecache_path should be a folder named json inside of either cwd() or __DIR__
     * getcwd() should be used if there are multiple instances of this bot operating from different source directories but share the same bot files (NYI)
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
     * @param string $filename The name of the file to save to.
     * @param array $assoc_array The associative array to be saved.
     * @return bool Returns true if the data was successfully saved, false otherwise.
     */
    public function VarSave(string $filename, array $assoc_array = []): bool
    {
        if ($filename === '') {
            $this->logger->warning('Unable to save data to file: Filename is empty');
            return false;
        }
        
        $filePath = $this->filecache_path . $filename;
        $jsonData = json_encode($assoc_array);
        
        if (file_put_contents($filePath, $jsonData) === false) {
            $this->logger->warning("Unable to save data to file: $filePath");
            return false;
        }
        return true;
    }
    /**
     * Loads an associative array from a file that was saved in JSON format.
     *
     * @param string $filename The name of the file to load from.
     * @return array|null Returns the associative array that was loaded, or null if the file does not exist or could not be loaded.
     */
    public function VarLoad(string $filename = ''): ?array
    {
        if ($filename === '') {
            $this->logger->warning('Unable to load data from file: Filename is empty');
            return null;
        }
        
        $filePath = $this->filecache_path . $filename;
        
        if (! file_exists($filePath)) {
            $this->logger->debug("File does not exist: $filePath");
            return null;
        }
        
        $jsonData = @file_get_contents($filePath);
        if ($jsonData === false) {
            $this->logger->warning("Unable to load data from file: $filePath");
            return null;
        }
        
        $assoc_array = @json_decode($jsonData, true);
        if ($assoc_array === null) {
            $this->logger->warning("Unable to decode JSON data from file: $filePath");
            return null;
        }
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
     * Retrieves an array of collections containing information about rounds.
     *
     * @return array An array of collections, where each collection represents a server and its rounds.
     */
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
    /**
     * Logs a new round in the game.
     *
     * @param string $server The server name.
     * @param string $game_id The game ID.
     * @param string $time The current time.
     * @return void
     */
    public function logNewRound(string $server, string $game_id, string $time): void
    {
        if (array_key_exists($server, $this->current_rounds) && array_key_exists($this->current_rounds[$server], $this->rounds[$server]) && $this->rounds[$server][$this->current_rounds[$server]] && $game_id !== $this->current_rounds[$server]) // If the round already exists and is not the current round
            $this->rounds[$server][$this->current_rounds[$server]]['end'] = $time; // Set end time of previous round
        $this->current_rounds[$server] = $game_id; // Update current round
        $this->VarSave('current_rounds.json', $this->current_rounds); // Update log of currently running game_ids
        $round =& $this->rounds[$server][$game_id];
        $round = []; // Initialize round array
        $round['start'] = $time; // Set start time
        $round['end'] = null;
        $round['players'] = [];
        $round['interrupted'] = false;
        $this->VarSave('rounds.json', $this->rounds); // Update log of rounds
    }
    /**
     * Logs the login of a player.
     *
     * @param string $server The server name.
     * @param string $ckey The player's ckey.
     * @param string $time The login time.
     * @param string $ip The player's IP address (optional).
     * @param string $cid The player's CID (optional).
     * @return void
     */
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
    /**
     * Logs the logout time of a player.
     *
     * @param string $server The server name.
     * @param string $ckey The player's ckey.
     * @param string $time The logout time.
     * @return void
     */
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

    /**
     * Retrieves the age associated with the given ckey.
     *
     * @param string $ckey The ckey to retrieve the age for.
     * @return string|false The age associated with the ckey, or false if not found.
     */
    public function getByondAge(string $ckey): string|false
    {
        if (isset($this->ages[$ckey])) return $this->ages[$ckey];
        if (! isset($this->byond)) {
            $this->logger->warning('BYOND object not set!');
            return false;
        }
        if ($age = Byond::getJoined($ckey)) {
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
    public function checkByondAge(string $age): bool
    {
        return strtotime($age) <= strtotime($this->minimum_age);
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
        $gameservers = array_filter($this->enabled_gameservers, function($gameserver) use ($message_content_lower) {
            return $gameserver->key === strtolower($message_content_lower);
        });
        if (empty($gameservers)) return $this->reply($message, 'Please use the format `listbans {server}`. Valid servers: `' . implode(', ', array_keys($this->enabled_gameservers)) . '`');

        foreach ($gameservers as &$gameserver) if (! @touch($filename = $gameserver->basedir . self::bans)) {
            $this->logger->warning("Failed to create file $filename");
            return $message->react("🔥");
        }

        $builder = MessageBuilder::new();
        $builder->addFile($filename);
        return $message->reply($builder);
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
        foreach ($this->enabled_gameservers as $server) if (! @file_exists($path = $server->basedir . self::bans) || ! @touch($path)) {
            $this->logger->warning("unable to open `$path`");
            return false;
        }

        $bancheckTimer = function () {
            $this->logger->debug('Running periodic bancheck...'); // This should take ~2.5 seconds to run
            if (isset($this->role_ids['banished']) && $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) foreach ($guild->members as $member) {
                if (isset($this->verifier) && ! $item = $this->verifier->getVerifiedMemberItems()->get('discord', $member->id)) continue;
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
            $this->logger->debug('Periodic bancheck complete.');
        };
        $bancheckTimer();
        if (! isset($this->timers['bancheck_timer'])) $this->timers['bancheck_timer'] = $this->discord->getLoop()->addPeriodicTimer(43200, function () use ($bancheckTimer) { $bancheckTimer(); });
        return true;
    }
    /**
     * Determines whether a ckey is currently banned from the server.
     *
     * This function is called when a user is verified to determine whether they should be given the banished role or have it taken away.
     * It checks the nomads_bans.txt and tdm_bans.txt files for the ckey.
     *
     * @param string $ckey The ckey to check for banishment.
     * @param bool $bypass (optional) If set to true, the function will not add or remove the banished role from the user.
     * @return bool Returns true if the ckey is found in either ban file, false otherwise.
     */
    public function bancheck(string $ckey, bool $bypass = false): bool
    {
        if (! $ckey = $this->sanitizeInput($ckey)) return false;
        $banned = false;
        foreach ($this->enabled_gameservers as &$gameserver) if ($gameserver->bancheck($ckey)) $banned = true;
        if (! $bypass && (isset($this->verifier) && $member = $this->verifier->getVerifiedMember($ckey))) {
            $hasBanishedRole = $member->roles->has($this->role_ids['banished']);
            if ($banned && ! $hasBanishedRole) $member->addRole($this->role_ids['banished'], "bancheck ($ckey)");
            elseif (! $banned && $hasBanishedRole) $member->removeRole($this->role_ids['banished'], "bancheck ($ckey)");
        }
        return $banned;
    }
    public function permabancheck(string $ckey, bool $bypass = false): bool
    {
        if (! $ckey = $this->sanitizeInput($ckey)) return false;
        $permabanned = false;
        foreach ($this->enabled_gameservers as &$gameserver) if ($gameserver->permabancheck($ckey)) $permabanned = true;
        if (! $bypass && (isset($this->verifier) && $member = $this->verifier->getVerifiedMember($ckey)))
            if ($permabanned && ! $member->roles->has($this->role_ids['permabanished'])) {
                if (! $member->roles->has($this->role_ids['Admin'])) $member->setRoles([$this->role_ids['banished'], $this->role_ids['permabanished']], "permabancheck ($ckey)");
            } elseif (! $permabanned && $member->roles->has($this->role_ids['permabanished'])) $member->removeRole($this->role_ids['permabanished'], "permabancheck ($ckey)");
        return $permabanned;
    }
    /**
     * Paroles or unparoles a player identified by their ckey.
     *
     * @param string $ckey The ckey of the player.
     * @param string $admin The admin who is performing the action.
     * @param bool $state The state of the player's parole. Default is true.
     * @return array The updated list of paroled players.
     */
    public function paroleCkey(string $ckey, string $admin, bool $state = true): array
    {
        if ($state) $this->paroled[$ckey] = $admin;
        else unset($this->paroled[$ckey]);
        $this->VarSave('paroled.json', $this->paroled);
        return $this->paroled;
    }
    public function __panicBan(string $ckey): void
    {
        if (! $this->bancheck($ckey, true)) {
            foreach ($this->enabled_gameservers as &$gameserver) {
                if (! $gameserver->panic_bunker) continue;
                $gameserver->ban(['ckey' => $ckey, 'duration' => '1 hour', 'reason' => "The server is currently restricted. You must come to Discord and link your byond account before you can play: {$this->discord_formatted}"]);
                $this->panic_bans[$ckey] = true;                
            }
            $this->VarSave('panic_bans.json', $this->panic_bans);
        }
    }
    public function __panicUnban(string $ckey): void
    {
        foreach ($this->enabled_gameservers as &$gameserver) {
            if (! $gameserver->panic_bunker) continue;
            $gameserver->unban($ckey);
            unset($this->panic_bans[$ckey]);
        }
        $this->VarSave('panic_bans.json', $this->panic_bans);
    }
    /*
     * These functions determine which of the above methods should be used to process a ban or unban
     * Ban functions will return a string containing the results of the ban
     * Unban functions will return nothing, but may contain error-handling messages that can be passed to $logger->warning()
     */
    public function ban(array $array /* = ['ckey' => '', 'duration' => '', 'reason' => ''] */, ?string $admin = null, string|int|null $server = null, bool $permanent = false): string
    {
        if (! isset($array['ckey'])) return "You must specify a ckey to ban.";
        if (! is_numeric($array['ckey']) && ! is_string($array['ckey'])) return "The ckey must be a Byond username or Discord ID.";
        if (! isset($array['duration'])) return "You must specify a duration to ban for.";
        if ($array['duration'] === '999 years') $permanent = true;
        if (! isset($array['reason'])) return "You must specify a reason for the ban.";
        $array['ckey'] = $this->sanitizeInput($array['ckey']);
        if (is_numeric($array['ckey'])) {
            if (isset($this->verifier) && ! $item = $this->verifier->get('discord', $array['ckey'])) return "Unable to find a ckey for <@{$array['ckey']}>. Please use the ckey instead of the Discord ID.";
            $array['ckey'] = $item['ss13'];
        }
        if (isset($this->verifier) && $member = $this->verifier->getVerifiedMember($array['ckey'])) if (! $member->roles->has($this->role_ids['banished'])) {
            if (! $permanent) $member->addRole($this->role_ids['banished'], "Banned for {$array['duration']} with the reason {$array['reason']}");
            else $member->setRoles([$this->role_ids['banished'], $this->role_ids['permabanished']], "Banned for {$array['duration']} with the reason {$array['reason']}");
        }
        $return = '';
        if (is_null($server)) foreach ($this->enabled_gameservers as &$gameserver) $return .= $gameserver->ban($array, $admin, $permanent);
        elseif (isset($this->enabled_gameservers[$server])) $return .= $this->enabled_gameservers[$server]->ban($array, $admin, $permanent);
        else $return .= "Invalid server specified for ban.";
        return $return;
    }
    public function unban(string $ckey, ?string $admin = null, string|array|null $gameserver = null): void
    {
        $admin ??= $this->discord->user->username;
        if (is_null($gameserver)) foreach ($this->enabled_gameservers as &$gameserver) $this->unban($ckey, $admin, $gameserver->key);
        elseif(isset($this->enabled_gameservers[$gameserver])) $this->enabled_gameservers[$gameserver]->unban($ckey, $admin);
        else {
            $this->logger->warning("Invalid server specified for unban.");
            return;
        }
        if (isset($this->verifier) && $member = $this->verifier->getVerifiedMember($ckey)) {
            if ($member->roles->has($this->role_ids['banished'])) $member->removeRole($this->role_ids['banished'], "Unbanned by $admin");
            if ($member->roles->has($this->role_ids['permabanished'])) {
                $member->removeRole($this->role_ids['permabanished'], "Unbanned by $admin");
                $member->addRole($this->role_ids['infantry'], "Unbanned by $admin");
            }
        }
    }

    /**
     * Retrieves information about a given ckey.
     *
     * @param string $ckey The ckey to retrieve information for.
     * @return array An array containing the ckeys, ips, cids, banned status, altbanned status, verification status, and associated discords. (array[array, array, array, bool, bool, bool])
     */
    public function ckeyinfo(string $ckey): array
    {
        if (! $ckey = $this->sanitizeInput($ckey)) return [null, null, null, false, false];
        if (! $collectionsArray = $this->getCkeyLogCollections($ckey)) return [null, null, null, false, false];
        if ($item = $this->verifier->getVerifiedItem($ckey)) $ckey = $item['ss13'];
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
            if (isset($this->verifier) && $item = $this->verifier->get('ss13', $key)) {
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
    public function ckeyinfoEmbed(string $ckey, ?array $ckeyinfo = null): Embed
    {
        if (! $ckeyinfo) $ckeyinfo = $this->ckeyinfo($ckey);
        $embed = new Embed($this->discord);
        $embed->setTitle($ckey);
        if (isset($this->verifier) && $member = $this->verifier->getVerifiedMember($ckey)) $embed->setAuthor("{$member->user->username} ({$member->id})", $member->avatar);
        if (! empty($ckeyinfo['ckeys'])) {
            foreach ($ckeyinfo['ckeys'] as &$ckey) if (isset($this->ages[$ckey])) $ckey = "$ckey ({$this->ages[$ckey]})";
            $embed->addFieldValues('Ckeys', implode(', ', $ckeyinfo['ckeys']));
        }
        if (! empty($ckeyinfo['ips'])) $embed->addFieldValues('IPs', implode(', ', $ckeyinfo['ips']));
        if (! empty($ckeyinfo['cids'])) $embed->addFieldValues('CIDs', implode(', ', $ckeyinfo['cids']));
        if (! empty($ckeyinfo['ips'])) {
            $regions = [];
            foreach ($ckeyinfo['ips'] as $ip) if (! in_array($region = $this->IP2Country($ip), $regions)) $regions[] = $region;
            $embed->addFieldValues('Regions', implode(', ', $regions));
        }
        $embed->addfieldValues('Verified', $ckeyinfo['verified'] ? 'Yes' : 'No');
        if (! empty($ckeyinfo['discords'])) {
            foreach ($ckeyinfo['discords'] as &$id) if ($id) $id = "<@{$id}>";
            $embed->addfieldValues('Discord', implode(', ', $ckeyinfo['discords']));
        }
        $embed->addfieldValues('Currently Banned', $ckeyinfo['banned'] ? 'Yes' : 'No');
        $embed->addfieldValues('Alt Banned', $ckeyinfo['altbanned'] ? 'Yes' : 'No');
        $embed->addfieldValues('Ignoring banned alts or new account age', isset($this->permitted[$ckey]) ? 'Yes' : 'No');
        return $embed;
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
    /**
     * Allows a ckey to bypass the panic bunker.
     *
     * @param string $ckey The ckey to permit or revoke access for.
     * @param bool $allow Whether to allow or revoke access for the ckey.
     * @return array The updated list of permitted ckeys.
     */
    public function permitCkey(string $ckey, bool $allow = true): array
    {
        if ($allow) $this->permitted[$ckey] = true;
        else unset($this->permitted[$ckey]);
        $this->VarSave('permitted.json', $this->permitted);
        return $this->permitted;
    }
    /**
     * Soft bans a user by adding their ckey to the softbanned array or removes them from it if $allow is false.
     * 
     * @param string $ckey The key of the user to be soft banned.
     * @param bool $allow Whether to add or remove the user from the softbanned array.
     * @return array The updated softbanned array.
     */
    public function softban(string $id, bool $allow = true): array
    {
        if ($allow) $this->softbanned[$id] = true;
        else unset($this->softbanned[$id]);
        $this->VarSave('softbanned.json', $this->softbanned);
        return $this->softbanned;
    }

    public function bansToCollection(): Collection
    {
        // Get the contents of the file
        $file_contents = '';
        foreach ($this->enabled_gameservers as &$gameserver) {
            if (! @file_exists($gameserver->basedir . self::bans) || ! $fc = @file_get_contents($gameserver->basedir . self::bans)) {
                $this->logger->warning('unable to open `' . $gameserver->basedir . self::bans . '`');
                continue;
            }
            $file_contents .= $fc;
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
        foreach ($this->enabled_gameservers as &$gameserver) {
            if (! @file_exists($gameserver->basedir . self::playerlogs) || ! $fc = @file_get_contents($gameserver->basedir . self::playerlogs)) {
                $this->logger->warning('unable to open `' . $gameserver->basedir . self::playerlogs . '`');
                continue;
            }
            $file_contents .= $fc;
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
    /**
     * Fetches server information from the specified URL.
     *
     * @return array The server information as an associative array.
     */
    public function serverinfoFetch(): array
    {
        $context = stream_context_create(['http' => ['connect_timeout' => 5]]);
        if (! $data_json = @json_decode(@file_get_contents($this->serverinfo_url, false, $context),  true)) {
            $this->logger->debug("Unable to retrieve serverinfo from `{$this->serverinfo_url}`");
            $this->webserverStatusChannelUpdate($this->webserver_online = false);
            return [];
        }
        $this->webserverStatusChannelUpdate($this->webserver_online = true);
        $this->logger->debug("Successfully retrieved serverinfo from `{$this->serverinfo_url}`");
        return $this->serverinfo = $data_json;
    }
    /*
     * This function parses the serverinfo data and updates the relevant Discord channel name with the current player counts
     * Prefix is used to differentiate between two different servers, however it cannot be used with more due to ratelimits on Discord
     * It is called on ready and every 5 minutes
     */
    // TODO: This function has been moved to Gameserver.php and should not be used anymore.
    /* private function playercountChannelUpdate(string|int|null $gameserver_key, int $count = 0): bool
    {
        if (! is_null($gameserver_key)) {
            if (! isset($this->enabled_gameservers[$gameserver_key])) {
                $this->logger->warning("Server {$gameserver_key} doesn't exist!");
                return false;
            }
            if ($this->enabled_gameservers[$gameserver_key]->playercountChannelUpdate($count)) return true;
        }

        $return = false;
        foreach ($this->enabled_gameservers as &$gameserver) if ($gameserver->playercountChannelUpdate($count)) $return = true;
        return $return;
    } */
    /**
     * Parses the server information and returns an array of parsed data.
     *
     * @param array $return The array to store the parsed data (optional).
     * @return array The array containing the parsed server information.
     */
    // TODO: This function has been deprecated and should not be used anymore.
    /*public function serverinfoParse(array $return = []): array
    {
        if (empty($this->serverinfo) || ! $serverinfo = $this->serverinfo) {
            return $return; // No data to parse
            $this->logger->warning('No serverinfo data to parse!');
        }
        $index = 0; // We need to keep track of the index we're looking at, as the array may not be sequential
        foreach ($this->enabled_gameservers as &$gameserver) {
            if (! $server = array_shift($serverinfo)) continue; // No data for this server
            if (! $gameserver->supported) { 
                $this->logger->debug("Server {$gameserver->name} is not supported by the remote webserver!");
                $index++; continue;
            } // Server is not supported by the remote webserver and won't appear in data
            if (array_key_exists('ERROR', $server)) {
                $this->logger->debug("Server {$gameserver->name} is not responding!");
                $return[$index] = []; $index++; continue;
            } // Remote webserver reports server is not responding
            $return[$index]['Server'] = [false => $gameserver->name . PHP_EOL . "<byond://{$gameserver->ip}:{$gameserver->port}>"];
            $return[$index]['Host'] = [true => $gameserver->host];
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
    
            $p1 = (isset($server['players'])
                ? $server['players']
                : count($players) ?? 0);
            $gameserver->playercountChannelUpdate($p1);
            $index++;
        }
        $this->playercount_ticker++;
        return $return;
    }*/

    /**
     * Generates a server status embed.
     *
     * @return Embed The generated server status embed.
     */
    public function generateServerstatusEmbed(): Embed
    {        
        $embed = new Embed($this->discord);
        $embed->setFooter($this->embed_footer);
        $embed->setColor(0xe1452d);
        $embed->setTimestamp();
        $embed->setURL('');
        foreach ($this->enabled_gameservers as &$gameserver) {            
            if (! isset($gameserver->ip, $gameserver->port)) {
                $this->logger->warning("Server {$gameserver->key} is missing required settings in config!");
                continue;
            }
            if ($gameserver->ip !== $this->httpServiceManager->httpHandler->external_ip) continue;
            if (! is_resource($socket = @fsockopen('localhost', intval($gameserver->port), $errno, $errstr, 1))) {
                $embed->addFieldValues($gameserver->name, 'Offline');
                continue;
            }
            fclose($socket);
            if (! @touch($gameserver->basedir . self::serverdata) || ! $data = @file_get_contents($gameserver->basedir . self::serverdata)) {
                $this->logger->warning("Unable to open `{$gameserver->basedir}" . self::serverdata . "`");
                continue;
            }
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
            if (isset($data[1])) $embed->addFieldValues($gameserver->name, '<'.$data[1].'>');
            $embed->addFieldValues('Host', $gameserver->host, true);
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
        return $embed;
    }
    // This is a simplified version of serverinfoParse() that only updates the player counter
    /* TODO: This function has been deprecated and should not be used anymore.
    public function serverinfoParsePlayers(): void
    {
        if (empty($this->serverinfo) || ! $serverinfo = $this->serverinfo) {
            $this->logger->warning('No serverinfo players data to parse!');
            return; // No data to parse
        }
        foreach ($this->enabled_gameservers as &$gameserver) {
            if (! $server = array_shift($serverinfo)) continue; // No data for this server
            if (! $gameserver->supported) continue; // Server is not supported by the remote webserver and won't appear in data
            if (array_key_exists('ERROR', $server)) continue; // Remote webserver reports server is not responding
            $p1 = (isset($server['players'])
                ? $server['players']
                : count(array_map(fn($player) => $this->sanitizeInput(urldecode($player)), array_filter($server, function (string $key) { return str_starts_with($key, 'player') && !str_starts_with($key, 'players'); }, ARRAY_FILTER_USE_KEY)))
            );
            $gameserver->playercountChannelUpdate($p1);
        }
        $this->playercount_ticker++;
    }
    */

    /*
     * This function calculates the player's ranking based on their medals
     * Returns true if the required files are successfully read, false otherwise
     */
    public function recalculateRanking(): bool
    {
        foreach ($this->enabled_gameservers as &$gameserver) {
            if ( ! @touch($awards_path = $gameserver->basedir . self::awards_path)) return false;
            if ( ! @touch($ranking_path = $gameserver->basedir . self::ranking_path)) return false;
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
        if (! @touch($path) || ! $search = @fopen($path, 'r')) return false;
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
        if (! @touch($path) || ! $search = @fopen($path, 'r')) return false;
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

    /**
     * This function is used to update the contents of files based on the roles of verified members.
     * The callback function is used to determine what to write to the file.
     *
     * @param callable $callback The callback function that determines what to write to the file.
     * @param array $file_paths An array of file paths to update.
     * @param array $required_roles An array of required roles for the members.
     * @return void
     */
    public function updateFilesFromMemberRoles(callable $callback, array $file_paths, array $required_roles): void
    {
        if (! isset($this->verifier)) {
            $this->logger->error('Unable to update files from member roles: Verifier is not set.');
            return;
        } 
        $file_contents = '';
        foreach ($this->verifier->verified as $item)
            if ($member = $this->verifier->getVerifiedMember($item))
                $file_contents .= $callback($member, $item, $required_roles);
        if ($file_contents) foreach ($file_paths as $fp) if (@touch($fp))
            if (file_put_contents($fp, $file_contents) === false) // Attempt to write to the file
                $this->logger->error("Failed to write to file `$fp`"); // Log an error if the write failed
    }
    /**
     * Updates the whitelist based on the member roles.
     *
     * @param array|null $required_roles The required roles for whitelisting. Default is ['veteran'].
     * @return bool Returns true if the whitelist update is successful, false otherwise.
     */
    public function whitelistUpdate(?array $required_roles = ['veteran', 'infantry']): bool
    {
        $return = false;
        foreach ($this->enabled_gameservers as &$gameserver) if ($gameserver->whitelistUpdate($required_roles)) $return = true;
        return $return;
    }
    /**
     * Updates the faction list based on the required roles.
     *
     * @param array|null $required_roles The required roles for updating the faction list. Default is ['red', 'blue', 'organizer'].
     * @return bool Returns true if the faction list is successfully updated, false otherwise.
     */
    public function factionlistUpdate(?array $required_roles = ['red', 'blue', 'organizer']): bool
    {
        if (! isset($this->verifier)) {
            $this->logger->error('Unable to update faction list: Verifier is not set.');
            return false;
        }
        $return = false;
        foreach ($this->enabled_gameservers as &$gameserver) if ($gameserver->factionlistUpdate($required_roles)) $return = true;
        return $return;
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
        $return = false;
        foreach ($this->enabled_gameservers as &$gameserver) if ($gameserver->adminlistUpdate($required_roles)) $return = true;
        return $return;
    }

    // Magic Methods
    public function __destruct()
    {
        foreach ($this->timers as $timer) $this->loop->cancelTimer($timer);
    }
    public function __toString(): string
    {
        return self::class;
    }
}