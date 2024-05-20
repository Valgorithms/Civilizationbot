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
use React\Promise\PromiseInterface;
use React\EventLoop\TimerInterface;
use React\Filesystem\Factory as FilesystemFactory;

require_once 'BYOND.php';

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

    public array $options = [];
    
    public Byond $byond;

    public bool $sharding = false;
    public bool $shard = false;
    public string $welcome_message = '';
    
    public \Closure $onFulfilledDefault;
    public \Closure $onRejectedDefault;

    public Slash $slash;
    public HttpServiceManager $httpServiceManager;
    public MessageServiceManager $messageServiceManager;
    
    public string $webserver_url = 'www.valzargaming.com'; // The URL of the webserver that the bot pulls server information from

    public StreamSelectLoop $loop;
    public Discord $discord;
    public bool $ready = false;
    public Browser $browser;
    public $filesystem;
    public Logger $logger;
    public $stats;

    public string $filecache_path = '';
    
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
     * @param array $options An array of options for configuring the client.
     * @param array $server_options An array of options for configuring the server.
     * @throws E_USER_ERROR If the code is not running in a CLI environment.
     * @throws E_USER_WARNING If the ext-gmp extension is not loaded.
     */
    public function __construct(array $options = [], array $server_options = [])
    {
        if (php_sapi_name() !== 'cli') trigger_error('DiscordPHP will not run on a webserver. Please use PHP CLI to run a DiscordPHP bot.', E_USER_ERROR);

        // x86 need gmp extension for big integer operation
        if (PHP_INT_SIZE === 4 && ! BigInt::init()) trigger_error('ext-gmp is not loaded. Permissions will NOT work correctly!', E_USER_WARNING);
        
        $options = $this->resolveOptions($options);
        $this->options = &$options;
        
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
     * Sends a message to the specified channel.
     *
     * @param mixed $channel The channel to send the message to. Can be a channel ID or a Channel object.
     * @param string $content The content of the message.
     * @param string $file_name The name of the file to attach to the message. Default is 'message.txt'.
     * @param bool $prevent_mentions Whether to prevent mentions in the message. Default is false.
     * @param bool $announce_shard Whether to announce the shard in the message. Default is true.
     * @return PromiseInterface|null A PromiseInterface representing the asynchronous operation, or null if the channel is not found.
     */
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

    /**
     * Sends an embed message to a channel.
     *
     * @param mixed $channel The channel to send the message to.
     * @param string $content The content of the message.
     * @param Embed $embed The embed object to send.
     * @param bool $prevent_mentions (Optional) Whether to prevent mentions in the message. Default is false.
     * @param bool $announce_shard (Optional) Whether to announce the shard. Default is true.
     * @return PromiseInterface|null A promise that resolves to the sent message, or null if the channel is not found.
     */
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

    /**
     * Sends a player message to a channel.
     *
     * @param ChannelInterface $channel The channel to send the message to.
     * @param bool $urgent Whether the message is urgent or not.
     * @param string $content The content of the message.
     * @param string $sender The sender of the message (ckey or Discord displayname).
     * @param string $recipient The recipient of the message (optional).
     * @param string $file_name The name of the file to attach to the message (default: 'message.txt').
     * @param bool $prevent_mentions Whether to prevent mentions in the message (default: false).
     * @param bool $announce_shard Whether to announce the shard in the message (default: true).
     * @return PromiseInterface|null A promise that resolves to the sent message, or null if the message couldn't be sent.
     */
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

    /**
     * Sends a reply message.
     *
     * @param Message $message The original message to reply to.
     * @param string $content The content of the reply message.
     * @param string $file_name The name of the file to attach to the reply message (default: 'message.txt').
     * @param bool $prevent_mentions Whether to prevent mentions in the reply message (default: false).
     * @param bool $announce_shard Whether to announce the shard in the reply message (default: true).
     * @return PromiseInterface|null A promise that resolves to the sent reply message, or null if the reply message could not be sent.
     */
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
        $this->httpServiceManager = new HttpServiceManager($this);
        $this->messageServiceManager = new MessageServiceManager($this);
        
        if (! $this->serverinfo_url) $this->serverinfo_url = "http://{$this->webserver_url}/servers/serverinfo.json"; // Default to VZG unless passed manually in config

        if (isset($this->discord)) {
            $this->discord->once('ready', function () use ($options) {
                $this->ready = true;
                $this->logger->info("logged in as {$this->discord->user->displayname} ({$this->discord->id})");
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

                $this->discord->on('GUILD_MEMBER_ADD', function (Member $member): void
                {
                    if ($this->shard) return;                    
                    $this->joinRoles($member);
                    if (! empty($this->functions['GUILD_MEMBER_ADD'])) foreach ($this->functions['GUILD_MEMBER_ADD'] as $func) $func($this, $member);
                    else $this->logger->debug('No message functions found!');

                    $this->getVerified();
                    if (isset($this->timers["add_{$member->id}"])) {
                        $this->discord->getLoop()->cancelTimer($this->timers["add_{$member->id}"]);
                        unset($this->timers["add_{$member->id}"]);
                    }
                    $this->timers["add_{$member->id}"] = $this->discord->getLoop()->addTimer(8640, function () use ($member): ?PromiseInterface
                    { // Kick member if they have not verified
                        $this->getVerified();
                        if (! $guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return null; // Guild not found (bot not in guild)
                        if (! $member_future = $guild->members->get('id', $member->id)) return null; // Member left before timer was up
                        if ($this->getVerifiedItem($member)) return null; // Don't kick if they have been verified
                        if (
                            $member_future->roles->has($this->role_ids['infantry']) ||
                            $member_future->roles->has($this->role_ids['veteran']) ||
                            $member_future->roles->has($this->role_ids['banished']) ||
                            $member_future->roles->has($this->role_ids['permabanished'])
                        ) return null; // Don't kick if they have an verified or banned role
                        return $guild->members->kick($member_future, 'Not verified');
                    });
                });

                $this->discord->on('GUILD_MEMBER_REMOVE', function (Member $member): void
                {
                    $this->getVerified();
                    if ($member->roles->has($this->role_ids['veteran'])) $this->whitelistUpdate();
                    $faction_roles = [
                        'red',
                        'blue',
                    ];
                    foreach ($faction_roles as $role_id) if ($member->roles->has($this->role_ids[$role_id])) { $this->factionlistUpdate(); break;}
                    $admin_roles = [
                        'Owner',
                        'Chief Technical Officer',
                        'Head Admin',
                        'Manager',
                        'High Staff',
                        'Supervisor',
                        'Event Admin',
                        'Admin',
                        'Moderator',
                        'Mentor',
                        'veteran',
                        'infantry',
                        'banished',
                        'paroled',
                    ];
                    foreach ($admin_roles as $role) if ($member->roles->has($this->role_ids[$role])) { $this->adminlistUpdate(); break; }
                });

                $this->discord->on('GUILD_MEMBER_UPDATE', function (Member $member, Discord $discord, ?Member $member_old): void
                {
                    if (! $member_old) { // Not enough information is known about the change, so we will update everything
                        $this->whitelistUpdate();
                        $this->getVerified();
                        $this->factionlistUpdate();
                        $this->adminlistUpdate();
                        return;
                    }
                    if ($member->roles->has($this->role_ids['veteran']) !== $member_old->roles->has($this->role_ids['veteran'])) $this->whitelistUpdate();
                    elseif ($member->roles->has($this->role_ids['infantry']) !== $member_old->roles->has($this->role_ids['infantry'])) $this->getVerified();
                    $faction_roles = [
                        'red',
                        'blue',
                    ];
                    foreach ($faction_roles as $role) 
                        if ($member->roles->has($this->role_ids[$role]) !== $member_old->roles->has($this->role_ids[$role])) { $this->factionlistUpdate(); break;}
                    $admin_roles = [
                        'Owner',
                        'Chief Technical Officer',
                        'Head Admin',
                        'Manager',
                        'High Staff',
                        'Supervisor',
                        'Event Admin',
                        'Admin',
                        'Moderator',
                        'Mentor',
                        'veteran',
                        'infantry',
                        'banished',
                        'paroled',
                    ];
                    foreach ($admin_roles as $role) 
                        if ($member->roles->has($this->role_ids[$role]) !== $member_old->roles->has($this->role_ids[$role])) { $this->adminlistUpdate(); break;}
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
     * Checks if the input is verified.
     *
     * @param string $input The input to be checked.
     * @return bool Returns true if the input is verified, false otherwise.
     */
    public function isVerified(string $input): bool
    {
        return $this->verified->get('ss13', $input) ?? (is_numeric($input) && ($this->verified->get('discord', $input)));
    }
    
    /**
     * Fetches the bot's cache of verified members that are currently found in the Civ13 Discord server.
     * If the bot is not in the Civ13 Discord server, it will return the bot's cache of verified members.
     *
     * @return Collection The collection of verified member items.
     */
    public function getVerifiedMemberItems(): Collection
    {
        if ($guild = $this->discord->guilds->get('id', $this->civ13_guild_id)) return $this->verified->filter(function($v) use ($guild) { return $guild->members->has($v['discord']); });
        return $this->verified;
    }

    /**
     * This function is used to get a verified item from a ckey or Discord ID.
     * If the user is verified, it will return an array containing the verified item.
     * It will return false if the user is not verified.
     *
     * @param Member|User|array|string $input The input value to search for the verified item.
     * @return array|null The verified item as an array, or null if not found.
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

    /**
     * This function is used to get a Member object from a ckey or Discord ID.
     * It will return false if the user is not verified, if the user is not in the Civ13 Discord server, or if the bot is not in the Civ13 Discord server.
     *
     * @param Member|User|array|string|null $input The input parameter can be a Member object, User object, an array, a string, or null.
     * @return Member|null The Member object if found, or null if not found or not verified.
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
        $round = &$this->rounds[$server][$game_id];
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

    /**
     * This function is used to verify a BYOND account.
     * 
     * The function first checks if the discord_id is in the pending collection.
     * If the discord_id is not in the pending collection, the function returns false.
     * 
     * The function then attempts to retrieve the 50 character token from the BYOND website.
     * If the token found on the BYOND website does not match the token in the pending collection, the function returns false.
     * 
     * If the token matches, the function returns true.
     * 
     * @param string $discord_id The Discord ID of the user to verify.
     * @return bool Returns true if the token matches, false otherwise.
     */
    public function checkToken(string $discord_id): bool
    { // Check if the user set their token
        if (! $item = $this->pending->get('discord', $discord_id)) return false; // User is not in pending collection (This should never happen and is probably a programming error)
        if (! $page = $this->byond->getProfilePage($item['ss13'])) return false; // Website could not be retrieved or the description wasn't found
        if ($item['token'] != $this->byond->__extractProfileDesc($page)) return false; // Token does not match the description
        return true; // Token matches
    }

    /**
     * This function is used to check if the user has verified their account.
     * If they have not, it checks to see if they have ever played on the server before.
     * If they have not, it sends a message stating that they need to join the server first.
     * It will send a message to the user with instructions on how to verify.
     * If they have, it will check if they have the verified role, and if not, it will add it.
     *
     * @param string $ckey The ckey of the user.
     * @param string $discord_id The Discord ID of the user.
     * @param Member|null $m The Discord member object (optional).
     * @return string The verification status message.
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

    /**
     * This function is called when a user still needs to set their token in their BYOND description and call the approveme prompt.
     * It will check if the token is valid, then add the user to the verified list.
     *
     * @param string $discord_id The Discord ID of the user to verify.
     * @return array An array with the verification result. The array contains the following keys:
     *   - 'success' (bool): Indicates whether the verification was successful.
     *   - 'error' (string): If 'success' is false, this contains the error message.
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

    /**
     * Removes a ckey from the verified list and sends a DELETE request to a website.
     *
     * @param string $id The ckey to be removed.
     * @return array An array with the success status and a message.
     *               ['success' => bool, 'message' => string]
     */
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
    
    /**
     * This function is called when a user has set their token in their BYOND description and attempts to verify.
     * It is also used to handle errors coming from the webserver.
     * If the website is down, it will add the user to the provisional list and set a timer to try to verify them again in 30 minutes.
     * If the user is allowed to be granted a provisional role, it will return true.
     *
     * @param string $ckey The BYOND ckey of the user.
     * @param string $discord_id The Discord ID of the user.
     * @return bool Returns true if the user is allowed to be granted a provisional role, false otherwise.
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
    /**
     * This function is called when a user has already set their token in their BYOND description and called the approveme prompt.
     * If the Discord ID or ckey is already in the SQL database, it will return an error message stating that the ckey is already verified.
     * Otherwise, it will add the user to the SQL database and the verified list, remove them from the pending list, and give them the verified role.
     *
     * @param string $ckey The ckey of the user.
     * @param string $discord_id The Discord ID of the user.
     * @param bool $provisional (Optional) Whether the registration is provisional or not. Default is false.
     * @return array An array with 'success' (bool) and 'error' (string) keys indicating the success status and error message, if any.
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
        $banned = $this->legacy ? $this->legacyBancheck($ckey) : $this->sqlBancheck($ckey);
        if (! $bypass && $member = $this->getVerifiedMember($ckey)) {
            $hasBanishedRole = $member->roles->has($this->role_ids['banished']);
            if ($banned && ! $hasBanishedRole) $member->addRole($this->role_ids['banished'], "bancheck ($ckey)");
            elseif (! $banned && $hasBanishedRole) $member->removeRole($this->role_ids['banished'], "bancheck ($ckey)");
        }
        return $banned;
    }
    /**
     * Checks if a given ckey is banned based on legacy ban data.
     *
     * @param string $ckey The ckey to check for ban.
     * @return bool Returns true if the ckey is banned, false otherwise.
     */
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
    /**
     * Checks if a player with the given ckey is permabanned based on legacy settings.
     *
     * @param string $ckey The ckey of the player to check.
     * @return bool Returns true if the player is permabanned, false otherwise.
     */
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
    /**
     * Checks if a player with the given ckey is banned.
     *
     * @param string $ckey The ckey of the player to check.
     * @return bool Returns true if the player is banned, false otherwise.
     */
    public function sqlBancheck(string $ckey): bool
    {
        // TODO
        return false;
    }
    /**
     * Checks if a player with the given ckey is permabanned.
     *
     * @param string $ckey The ckey of the player to check.
     * @return bool Returns true if the player is permabanned, false otherwise.
     */
    public function sqlPermabancheck(string $ckey): bool
    {
        // TODO
        return false;
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

    /**
     * This function allows a ckey to bypass the verification process entirely.
     * NOTE: This function is only authorized to be used by the database administrator.
     *
     * @param string $ckey The ckey to register.
     * @param string $discord_id The Discord ID associated with the ckey.
     * @return array An array containing the success status and error message (if any).
     */
    public function registerCkey(string $ckey, string $discord_id): array // ['success' => bool, 'error' => string]
    {
        $this->permitCkey($ckey, true);
        return $this->verifyCkey($ckey, $discord_id);
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
    public function softban(string $id, bool $allow = true): array
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
            $this->logger->debug("Unable to retrieve serverinfo from `{$this->serverinfo_url}`");
            $this->webserverStatusChannelUpdate($this->webserver_online = false);
            return [];
        }
        $this->webserverStatusChannelUpdate($this->webserver_online = true);
        $this->logger->debug("Successfully retrieved serverinfo from `{$this->serverinfo_url}`");
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
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;    
            if (! isset($settings['ip'], $settings['port'])) {
                $this->logger->warning("Server {$settings['key']} is missing required settings in config!");
                continue;
            }
            if ($settings['ip'] !== $this->httpServiceManager->httpHandler->external_ip) continue;
            $servers[$settings['key']] = 0;
            $socket = @fsockopen('localhost', intval($settings['port']), $errno, $errstr, 1);
            if (! is_resource($socket)) continue;
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
        return ['playercount' => $servers, 'playerlist' => $players];
    }

    public function generateServerstatusEmbed(): Embed
    {        
        $embed = new Embed($this->discord);
        $embed->setFooter($this->embed_footer);
        $embed->setColor(0xe1452d);
        $embed->setTimestamp();
        $embed->setURL('');
        foreach ($this->server_settings as $settings) {            
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['ip'], $settings['port'])) {
                $this->logger->warning("Server {$settings['key']} is missing required settings in config!");
                continue;
            }
            if ($settings['ip'] !== $this->httpServiceManager->httpHandler->external_ip) continue;
            if (! is_resource($socket = @fsockopen('localhost', intval($settings['port']), $errno, $errstr, 1))) {
                $embed->addFieldValues($settings['name'], 'Offline');
                continue;
            }
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
        return $embed;
    }
    // This is a simplified version of serverinfoParse() that only updates the player counter
    public function serverinfoParsePlayers(): void
    {
        if (empty($this->serverinfo) || ! $serverinfo = $this->serverinfo) {
            $this->logger->warning('No serverinfo players data to parse!');
            return; // No data to parse
        }
        foreach ($this->server_settings as $settings) {
            if (! $server = array_shift($serverinfo)) continue; // No data for this server
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            if (! isset($settings['supported']) || ! $settings['supported']) continue; // Server is not supported by the remote webserver and won't appear in data
            if (! isset($settings['name'])) { // Server is missing required settings in config 
                $this->logger->warning("Server {$settings['name']} is missing a name in config!");
                continue;
            }
            if (array_key_exists('ERROR', $server)) continue; // Remote webserver reports server is not responding
            $p1 = (isset($server['players'])
                ? $server['players']
                : count(array_map(fn($player) => $this->sanitizeInput(urldecode($player)), array_filter($server, function (string $key) { return str_starts_with($key, 'player') && !str_starts_with($key, 'players'); }, ARRAY_FILTER_USE_KEY)))
            );
            $this->playercountChannelUpdate($settings, $p1);
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