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
use Discord\Parts\Guild\Guild;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Http\Browser;
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
    
    protected $webapi;
    
    public collection $verified; //This probably needs a default value for Collection, maybe make it a Repository instead?
    public collection $pending;
    public $ages = []; //$ckey => $age, temporary cache to avoid spamming the Byond REST API, but we don't want to save it to a file because we also use it to check if the account still exists
    public $minimum_age = '-21 days'; //Minimum age of a ckey
    public $permitted = []; //List of ckeys that are permitted to use the verification command even if they don't meet the minimum age requirement

    public $timers = [];
    public $serverinfo = []; //Collected automatically by serverinfo_timer
    public $players = []; //Collected automatically by serverinfo_timer
    
    public $functions = array(
        'ready' => [],
        'ready_slash' => [],
        'messages' => [],
        'misc' => [],
    );
    
    public string $command_symbol = '!s';
    public string $owner_id = '196253985072611328';
    public string $civ13_guild_id = '468979034571931648';
    public string $verifier_feed_channel_id = '1032411190695055440';
    public string $civ_token = '';

    public string $github = 'https://github.com/VZGCoders/Civilizationbot';
    public string $banappeal = 'https://civ13.com/discord/';
    
    public array $files = [];
    public array $ips = [];
    public array $ports = [];
    public array $channel_ids = [];
    public array $role_ids = [];
    
    public array $discord_config = [];
    public array $tests = [];
    public bool $panic_bunker = false;
    public array $panic_bans = [];
    
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
        
        if (isset($options['filecache_path'])) {
            if (is_string($options['filecache_path'])) {
                if (! str_ends_with($options['filecache_path'], '/')) $options['filecache_path'] .= '/';
                $this->filecache_path = $options['filecache_path'];
            } else $this->filecache_path = getcwd() . '/json/';
        } else $this->filecache_path = getcwd() . '/json/';
        if (!file_exists($this->filecache_path)) mkdir($this->filecache_path, 0664, true);
        
        if(isset($options['command_symbol'])) $this->command_symbol = $options['command_symbol'];
        if(isset($options['owner_id'])) $this->owner_id = $options['owner_id'];
        if(isset($options['banappeal'])) $this->banappeal = $options['banappeal'];
        if(isset($options['github'])) $this->github = $options['github'];
        if(isset($options['civ13_guild_id'])) $this->civ13_guild_id = $options['civ13_guild_id'];
        if(isset($options['verifier_feed_channel_id'])) $this->verifier_feed_channel_id = $options['verifier_feed_channel_id'];
        if(isset($options['civ_token'])) $this->civ_token = $options['civ_token'];
                
        if(isset($options['discord'])) $this->discord = $options['discord'];
        elseif(isset($options['discord_options'])) $this->discord = new Discord($options['discord_options']);
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
                $this->getVerified(); //Populate verified property with data from DB
                $this->setIPs();
                $this->serverinfoTimer();
                $this->pending = new Collection([], 'discord');
                //Initialize configurations
                if (! $discord_config = $this->VarLoad('discord_config.json')) $discord_config = [];
                foreach ($this->discord->guilds as $guild) if (!isset($discord_config[$guild->id])) $this->SetConfigTemplate($guild, $discord_config);
                $this->discord_config = $discord_config; //Declared, but not currently used for anything
                
                if (! $tests = $this->VarLoad('tests.json')) $tests = [];
                $this->tests = $tests;

                if (! $permitted = $this->VarLoad('permitted.json')) $permitted = [];
                $this->permitted = $permitted;

                if (! $panic_bans = $this->VarLoad('panic_bans.json')) $panic_bans = [];
                $this->panic_bans = $panic_bans;
                
                if(! empty($this->functions['ready'])) foreach ($this->functions['ready'] as $func) $func($this);
                else $this->logger->debug('No ready functions found!');
                $this->discord->application->commands->freshen()->done( function ($commands) {
                    $this->slash->updateCommands($commands);
                    if (!empty($this->functions['ready_slash'])) foreach (array_values($this->functions['ready_slash']) as $func) $func($this, $commands);
                    else $this->logger->debug('No ready slash functions found!');
                });
                
                $this->discord->on('message', function ($message) {
                    if(! empty($this->functions['message'])) foreach ($this->functions['message'] as $func) $func($this, $message);
                    else $this->logger->debug('No message functions found!');
                });
                $this->discord->on('GUILD_MEMBER_ADD', function ($guildmember) {
                    $this->joinRoles($guildmember);
                    if(! empty($this->functions['GUILD_MEMBER_ADD'])) foreach ($this->functions['GUILD_MEMBER_ADD'] as $func) $func($this, $guildmember);
                    else $this->logger->debug('No message functions found!');
                });
                $this->discord->on('GUILD_CREATE', function (Guild $guild)
                {
                    if (!isset($this->discord_config[$guild->id])) $this->SetConfigTemplate($guild, $this->discord_config);
                });
            });
        }
    }
    
    /**
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

    public function getVerifiedUsers(): Collection
    {
        if ($guild = $this->discord->guilds->get('id', $this->DF13_guild_id)) return $this->verified->filter(function($v) use ($guild) { return $guild->members->has($v['discord']); });
        return $this->verified;
    }
    
    /*
    * This function is used to refresh the bot's cache of verified users
    * It is called when the bot starts up, and when the bot receives a GUILD_MEMBER_ADD event
    * It is also called when the bot receives a GUILD_MEMBER_REMOVE event
    * It is also called when the bot receives a GUILD_MEMBER_UPDATE event, but only if the user's roles have changed
    */
    public function getVerified(): Collection
    {
        if ($verified_array = json_decode(file_get_contents('http://valzargaming.com/verified/'), true)) {
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
        $ch = curl_init(); //create curl resource
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
    
    /**
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
    /**
     * This function is used determine if a byond account is old enough to play on the server
     * false is returned if the account is too young, true is returned if the account is old enough
     * */
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
        if ($this->verified->has($ckey)) return "`$ckey` is already verified!";
        if (! $this->pending->get('discord', $discord_id)) {
            if (! $age = $this->getByondAge($ckey)) return "Ckey `$ckey` does not exist!";
            if (! $this->checkByondAge($age) && ! isset($this->permitted[$ckey])) {
                $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage($this->ban([$ckey, '999 years', "Byond account $ckey does not meet the requirements to be approved. ($age)"]));
                return "Ckey `$ckey` is too new! ($age)";
            }
            $found = false;
            foreach (explode('|', file_get_contents($this->files['tdm_playerlogs']) . file_get_contents($this->files['nomads_playerlogs'])) as $line)
                if (explode(';', trim($line))[0] == $ckey) {
                    $found = true;
                    break;
                }
            if (! $found) return "Ckey `$ckey` has never been seen on the server before! You'll need to join either Nomads or TDM at least once before verifying."; 
            return 'Login to your profile at https://secure.byond.com/members/-/account and enter this token as your description: `' . $this->generateByondToken($ckey, $discord_id) . PHP_EOL . '`Use the command again once this process has been completed.';
        }
        return $this->verifyNew($discord_id)[1]; //TODO: There's supposed to be separate processing for $result[0] being false/true but I don't remember why...
    }

    /*
    * This function is called when a user still needs to set their token in their BYOND description and call the approveme prompt
    * It will check if the token is valid, then add the user to the verified list
    */
    public function verifyNew(string $discord_id): array //[bool, string]
    { //Attempt to verify a user
        if(! $item = $this->pending->get('discord', $discord_id)) return [false, 'This error should never happen'];
        if(! $this->checkToken($discord_id)) return [false, "You have not set your token yet! It needs to be set to {$item['token']}"];
        return $this->verifyCkey($item['ss13'], $discord_id);
    }
    
    /*
    * This function is called when a user has already set their token in their BYOND description and called the approveme prompt
    * If the discord id or ckey is already in the SQL database, it will return an error message stating that the ckey is already verified
    * otherwise it will add the user to the SQL database and the verified list, remove them from the pending list, and give them the verified role
    */
    public function verifyCkey(string $ckey, string $discord_id): array //[bool, string]
    { //Send $_POST information to the website. Only call this function after the getByondDesc() verification process has been completed!
        $success = false;
        $message = '';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://valzargaming.com/verified/');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type' => 'application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //return the transfer as a string    
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['token' => $this->civ_token, 'ckey' => $ckey, 'discord' => $discord_id]));
        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); //Validate the website's HTTP response! 200 = success, 403 = ckey already registered, anything else is an error
        switch ($http_status) {
            case 200: //Verified
                $success = true;
                $message = "`$ckey` has been verified and registered to $discord_id";
                $this->pending->offsetUnset($discord_id);
                $this->discord->guilds->get('id', $this->civ13_guild_id)->members->get('id', $discord_id)->addRole($this->role_ids['infantry']);
                $this->getVerified();
                if (isset($this->panic_bans[$ckey])) {
                    $this->panicUnban($ckey);
                    $message .= ' and the panic bunker ban removed.';
                }
                break;
            case 403: //Already registered
                $message = "Either `$ckey` or <@$discord_id> has already been verified and registered to a discord id"; //This should have been caught above. Need to run getVerified() again?
                $this->getVerified();
                break;
            default: 
                $message = "There was an error attempting to process the request: [$http_status] $result";
                break;
        }
        curl_close($ch);
        return [$success, $message];
    }
    
    public function bancheck(string $ckey): bool
    {
        if ($filecheck1 = fopen($this->files['nomads_bans'], 'r')) {
            while (($fp = fgets($filecheck1, 4096)) !== false) {
                //str_replace(PHP_EOL, '', $fp); // Is this necessary?
                $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
                    fclose($filecheck1);
                    return true;
                }
            }
            fclose($filecheck1);
        } else $this->logger->warning("unable to open `{$this->files['nomads_bans']}`");
        if ($filecheck2 = fopen($this->files['tdm_bans'], 'r')) {
            while (($fp = fgets($filecheck2, 4096)) !== false) {
                //str_replace(PHP_EOL, '', $fp); // Is this necessary?
                $linesplit = explode(';', trim(str_replace('|||', '', $fp))); //$split_ckey[0] is the ckey
                if ((count($linesplit)>=8) && ($linesplit[8] == $ckey)) {
                    fclose($filecheck2);
                    return true;
                }
            }
            fclose($filecheck2);
        } else $this->logger->warning("unable to open `{$this->files['tdm_bans']}`");
        return false;
    }

    public function permitCkey(string $ckey, bool $allow = true): array
    {
        if ($allow) $this->permitted[$ckey] = true;
        else unset($this->permitted[$ckey]);
        $this->VarSave('permitted.json', $this->permitted);
        return $this->permitted;
    }
    public function panicBan(string $ckey): void
    {
        if (! $this->bancheck($ckey)) {
            $this->ban([$ckey, '999 years', "Panic Bunker mode is currently turned on. You must come to Discord and register before you can play: {$this->banappeal}"]);
            $this->panic_bans[$ckey] = true;
            $this->VarSave('panic_bans.json', $this->panic_bans);
        }
    }
    public function panicUnban(string $ckey): void
    {
        $this->unban($ckey);
        unset($this->panic_bans[$ckey]);
        $this->VarSave('panic_bans.json', $this->panic_bans);
    }

    public function banNomads($array, $message = null): string
    {
        $admin = ($message ? $message->author->displayname : $this->discord->user->username);
        $result = '';
        if ($file = fopen($this->files['nomads_discord2ban'], 'a')) {
            fwrite($file, "$admin:::{$array[0]}:::{$array[1]}:::{$array[2]}" . PHP_EOL);
            fclose($file);
        } else {
            $this->logger->warning("unable to open {$this->files['nomads_discord2ban']}");
            $result .= "unable to open {$this->files['nomads_discord2ban']}" . PHP_EOL;
        }
        $result .= "**$admin** banned **{$array[0]}** from **Nomads** for **{$array[1]}** with the reason **{$array[2]}**" . PHP_EOL;
        return $result;
    }
    public function banTDM($array, $message = null): string
    {
        $admin = ($message ? $message->author->displayname : $this->discord->user->username);
        if (! $file = fopen($this->files['tdm_discord2ban'], 'a')) return "unable to open {$this->files['tdm_discord2ban']}" . PHP_EOL;
        fwrite($file, "$admin:::{$array[0]}:::{$array[1]}:::{$array[2]}" . PHP_EOL);
        fclose($file);
        return "**$admin** banned **{$array[0]}** from **TDM** for **{$array[1]}** with the reason **{$array[2]}**" . PHP_EOL;
    }
    public function ban($array, $message = null):string
    {
        return $this->banNomads($array, $message) . $this->banTDM($array, $message);
    }

    public function unbanNomads(string $ckey, ?string $admin = null): void
    {
        if (! $admin) $admin = $this->discord->user->displayname;
        if ($file = fopen($this->files['nomads_discord2unban'], 'a')) {
            fwrite($file, "$admin:::$ckey");
            fclose($file);
        }
    }
    public function unbanTDM(string $ckey, ?string $admin = null): void
    {
        if (! $admin) $admin = $this->discord->user->displayname;
        if ($file = fopen($this->files['tdm_discord2unban'], 'a')) {
            fwrite($file, "$admin:::$ckey");
            fclose($file);
        }
    }
    public function unban(string $ckey, ?string $admin = null): void
    {
        if (! $admin) $admin = $this->discord->user->displayname;
        $this->unbanNomads($ckey, $admin);
        $this->unbanTDM($ckey, $admin);
    }
    
    public function setIPs(): void
    { //on ready, move definitions into config/constructor?
        $vzg_ip = gethostbyname('www.valzargaming.com');
        $external_ip = file_get_contents('http://ipecho.net/plain');
        $this->ips = [
            'nomads' => $external_ip,
            'tdm' => $external_ip,
            'vzg' => $vzg_ip,
        ];
        $this->ports = [
            'nomads' => '1715',
            'tdm' => '1714',
            'bc' => '1717', 
            'ps13' => '7778',
        ];
    }
    public function serverinfoPlayers(): array
    { 
        if (empty($data_json = $this->serverinfo)) return [];
        $this->players = [];
        foreach ($data_json as $server) {
            if (array_key_exists('ERROR', $server)) continue;
            //Players
            foreach (array_keys($server) as $key) {
                $p = explode('player', $key); 
                if (isset($p[1]) && is_numeric($p[1])) $this->players[] = str_replace(['.', '_', ' '], '', strtolower(urldecode($server[$key])));
            }
        }
        return $this->players;
    }
    public function serverinfoFetch(): array
    {
        if (! $data_json = json_decode(file_get_contents("http://{$this->ips['vzg']}/servers/serverinfo.json"),  true)) return [];
        return $this->serverinfo = $data_json;
    }
    public function serverinfoTimer(): void
    {
        $func = function() {
            $this->serverinfoFetch(); 
            foreach ($this->serverinfoPlayers() as $ckey) {
                if ($this->verified->get('ss13', $ckey)) continue;
                if ($this->panic_bunker) return $this->panicBan($ckey);
                if (isset($this->ages[$ckey])) continue;
                if (! $this->checkByondAge($age = $this->getByondAge($ckey)) && ! isset($this->permitted[$ckey]))
                    $this->discord->getChannel($this->channel_ids['staff_bot'])->sendMessage($this->ban([$ckey, '999 years', "Byond account $ckey does not meet the requirements to be approved. ($age)"]));
            }
        };
        $func();
        $this->timers['serverinfo_timer'] = $this->discord->getLoop()->addPeriodicTimer(60, function() use ($func) { $func(); });
    }
    public function serverinfoParse(): array
    {
        if (empty($data_json = $this->serverinfo)) return [];
        $return = [];

        $server_info[0] = ['name' => 'TDM', 'host' => 'Taislin', 'link' => "<byond://{$this->ips['tdm']}:{$this->ports['tdm']}>"];
        $server_info[1] = ['name' => 'Nomads', 'host' => 'Taislin', 'link' => "<byond://{$this->ips['nomads']}:{$this->ports['nomads']}>"];
        $server_info[2] = ['name' => 'Blue Colony', 'host' => 'ValZarGaming', 'link' => "<byond://{$this->ips['vzg']}:{$this->ports['bc']}>"];
        $server_info[3] = ['name' => 'Pocket Stronghold 13', 'host' => 'ValZarGaming', 'link' => "<byond://{$this->ips['vzg']}:{$this->ports['ps13']}>"];
        
        $index = 0;
        foreach ($data_json as $server) {
            $server_info_hard = array_shift($server_info);
            if (array_key_exists('ERROR', $server)) continue;
            if (isset($server_info_hard['name'])) $return[$index]['Server'] = [false => $server_info_hard['name'] . PHP_EOL . $server_info_hard['link']];
            if (isset($server_info_hard['host'])) $return[$index]['Host'] = [true => $server_info_hard['host']];
            //Round time
            if (isset($server['roundduration']) /*|| isset($server['round_duration'])*/) { //TODO
                $rd = explode(":", urldecode($server['roundduration']));
                $remainder = ($rd[0] % 24);
                $rd[0] = floor($rd[0] / 24);
                if ($rd[0] != 0 || $remainder != 0 || $rd[1] != 0) $rt = "{$rd[0]}d {$remainder}h {$rd[1]}m";
                else $rt = 'STARTING';
                $return[$index]['Round Timer'] = [true => $rt];
            }
            if (isset($server['round_duration'])) {
                //TODO
            }
            if (isset($server['map'])) $return[$index]['Map'] = [true => urldecode($server['map'])];
            if (isset($server['age'])) $return[$index]['Epoch'] = [true => urldecode($server['age'])];
            //Players
            $players = [];
            foreach (array_keys($server) as $key) {
                $p = explode('player', $key); 
                if (isset($p[1])) {
                    if(is_numeric($p[1])) $players[] = str_replace(['.', '_', ' '], '', strtolower(urldecode($server[$key])));
                }
            }
            if ($server['players'] || ! empty($players)) $return[$index]['Players (' . (isset($server['players']) ? $server['players'] : count($players) ?? '?') . ')'] = [true => (empty($players) ? 'N/A' : implode(', ', $players))];
            if (isset($server['season'])) $return[$index]['Season'] = [true => urldecode($server['season'])];
            $index++;
        }
        return $return;
    }

    public function joinRoles($member)
    { //Move into class
        if ($member->guild_id != $this->civ13_guild_id) return;
        if ($item = $this->verified->get('discord', $member->id)) {
            if ($this->bancheck($item['ss13'])) return $member->setroles([$this->role_ids['infantry'], $this->role_ids['banished']], "bancheck join {$item['ss13']}");
            return $member->setroles([$this->role_ids['infantry']], "verified join {$item['ss13']}");
        }
    }
}