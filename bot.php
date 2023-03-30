<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

use \Civ13\Civ13;
use \Discord\Discord;
use \Discord\Helpers\CacheConfig;
use \React\EventLoop\Loop;
use \WyriHaximus\React\Cache\Redis as RedisCache;
use \Clue\React\Redis\Factory as Redis;
use \React\Filesystem\Factory as FilesystemFactory;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;
use \Discord\WebSockets\Intents;
use \React\Http\Browser;

ini_set('display_errors', 1);
error_reporting(E_ALL);

set_time_limit(0);
ignore_user_abort(1);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1'); //Unlimited memory usage
define('MAIN_INCLUDED', 1); //Token and SQL credential files may be protected locally and require this to be defined to access
require getcwd() . '/token.php'; //$token
include getcwd() . '/vendor/autoload.php';

$loop = Loop::get();
$logger = new Logger('New logger');
$logger->pushHandler(new StreamHandler('php://stdout'));
$discord = new Discord([
    'loop' => $loop,
    'logger' => $logger,
    'cache' => new CacheConfig($interface = new RedisCache((new Redis($loop))->createLazyClient('127.0.0.1:6379'), 'dphp:cache:'), $compress = true, $sweep = false), //Disabled for debugging
    /*'socket_options' => [
        'dns' => '8.8.8.8', // can change dns
    ],*/
    'token' => $token,
    'loadAllMembers' => true,
    'storeMessages' => true, //Because why not?
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS | Intents::MESSAGE_CONTENT,
]);
include 'stats_object.php'; 
$stats = new Stats();
$stats->init($discord);
$browser = new Browser($loop);
$filesystem = FilesystemFactory::create($loop);
include 'functions.php'; //execInBackground(), portIsAvailable()
include 'variable_functions.php';
include 'verifier_functions.php';
include 'civ13.php';

$options = array(
    'loop' => $loop,
    'discord' => $discord,
    'browser' => $browser,
    'filesystem' => $filesystem,
    'logger' => $logger,
    'stats' => $stats,
    
    //Configurations
    'banappeal' => 'civ13.com slash discord',
    'github' => 'https://github.com/VZGCoders/Civilizationbot',
    'command_symbol' => '!s',
    'owner_id' => '196253985072611328', //Taislin
    'civ13_guild_id' => '468979034571931648', //Civ13
    'verifier_feed_channel_id' => '1032411190695055440', //Channel VZG Verifier webhooks verification messages to
    'files' => array(
        //Fun
        'insults_path' => 'insults.txt',
        'ranking_path' => 'ranking.txt',
        'status_path' => 'status.txt',
        
        //Defines
        'map_defines_path' => '/home/1713/civ13-git/code/__defines/maps.dm',
        
        //Nomads
        'nomads_log_basedir' => '/home/1713/civ13-rp/data/logs',
        'nomads_ooc_path' => '/home/1713/civ13-rp/ooc.log',
        'nomads_admin_path' => '/home/1713/civ13-rp/admin.log',
        'nomads_discord2ooc' => '/home/1713/civ13-rp/SQL/discord2ooc.txt',
        'nomads_discord2admin' => '/home/1713/civ13-rp/SQL/discord2admin.txt',
        'nomads_discord2dm' => '/home/1713/civ13-rp/SQL/discord2dm.txt',
        'nomads_discord2ban' => '/home/1713/civ13-rp/SQL/discord2ban.txt',
        'nomads_discord2unban' => '/home/1713/civ13-rp/SQL/discord2unban.txt',
        'nomads_admins' => '/home/1713/civ13-rp/SQL/admins.txt',
        'nomads_whitelist' => '/home/1713/civ13-rp/SQL/whitelist.txt',
        'nomads_bans' => '/home/1713/civ13-rp/SQL/bans.txt',
        
        //TDM
        'tdm_log_basedir' => '/home/1713/civ13-tdm/data/logs',
        'tdm_ooc_path' => '/home/1713/civ13-tdm/ooc.log',
        'tdm_admin_path' => '/home/1713/civ13-tdm/admin.log',
        'tdm_discord2ooc' => '/home/1713/civ13-tdm/SQL/discord2ooc.txt',
        'tdm_discord2admin' => '/home/1713/civ13-tdm/SQL/discord2admin.txt',
        'tdm_discord2dm' => '/home/1713/civ13-tdm/SQL/discord2dm.txt',
        'tdm_discord2ban' => '/home/1713/civ13-tdm/SQL/discord2ban.txt',
        'tdm_discord2unban' => '/home/1713/civ13-tdm/SQL/discord2unban.txt',
        'tdm_discord2ban' => '/home/1713/civ13-tdm/SQL/discord2ban.txt',
        'tdm_admins' => '/home/1713/civ13-tdm/SQL/admins.txt',
        'tdm_whitelist' => '/home/1713/civ13-tdm/SQL/whitelist.txt',
        'tdm_bans' => '/home/1713/civ13-tdm/SQL/bans.txt',
        'tdm_awards_path' => '/home/1713/civ13-tdm/SQL/awards.txt',
        'tdm_awards_br_path' => '/home/1713/civ13-tdm/SQL/awards_br.txt',
        //Campaign
        'factionlist' => '/home/1713/civ13-tdm/SQL/factionlist.txt',

        //Persistence
        'pers_log_basedir' => '/home/1713/civ13-pers/data/logs',
        'pers_ooc_path' => '/home/1713/civ13-pers/ooc.log',
        'pers_admin_path' => '/home/1713/civ13-pers/admin.log',
        'pers_discord2ooc' => '/home/1713/civ13-pers/SQL/discord2ooc.txt',
        'pers_discord2admin' => '/home/1713/civ13-pers/SQL/discord2admin.txt',
        'pers_discord2dm' => '/home/1713/civ13-pers/SQL/discord2dm.txt',
        'pers_discord2ban' => '/home/1713/civ13-pers/SQL/discord2ban.txt',
        'pers_discord2unban' => '/home/1713/civ13-pers/SQL/discord2unban.txt',
        'pers_discord2ban' => '/home/1713/civ13-pers/SQL/discord2ban.txt',
        'pers_admins' => '/home/1713/civ13-pers/SQL/admins.txt',
        'pers_whitelist' => '/home/1713/civ13-pers/SQL/whitelist.txt',
        'pers_bans' => '/home/1713/civ13-pers/SQL/bans.txt',
        'pers_awards_path' => '/home/1713/civ13-pers/SQL/awards.txt',
        'pers_awards_br_path' => '/home/1713/civ13-pers/SQL/awards_br.txt',
        //Campaign
        'factionlist' => '/home/1713/civ13-pers/SQL/factionlist.txt',

        //Script paths
        'nomads_updateserverabspaths' => '/home/1713/civ13-rp/scripts/updateserverabspaths.py',
        'nomads_serverdata' => '/home/1713/civ13-rp/serverdata.txt',
        'nomads_dmb' => '/home/1713/civ13-rp/civ13.dmb',
        'nomads_killsudos' => '/home/1713/civ13-rp/scripts/killsudos.py',
        'nomads_killciv13' => '/home/1713/civ13-rp/scripts/killciv13.py',
        'mapswap_nomads' => '/home/1713/civ13-rp/scripts/mapswap.py',

        'tdm_updateserverabspaths' => '/home/1713/civ13-tdm/scripts/updateserverabspaths.py',
        'tdm_serverdata' => '/home/1713/civ13-tdm/serverdata.txt',
        'tdm_dmb' => '/home/1713/civ13-tdm/civ13.dmb',
        'tdm_killsudos' => '/home/1713/civ13-tdm/scripts/killsudos.py',
        'tdm_killciv13' => '/home/1713/civ13-tdm/scripts/killciv13.py',
        'mapswap_tdm' => '/home/1713/civ13-tdm/scripts/mapswap.py',

        'pers_updateserverabspaths' => '/home/1713/civ13-pers/scripts/updateserverabspaths.py',
        'pers_serverdata' => '/home/1713/civ13-pers/serverdata.txt',
        'pers_dmb' => '/home/1713/civ13-pers/civ13.dmb',
        'pers_killsudos' => '/home/1713/civ13-pers/scripts/killsudos.py',
        'pers_killciv13' => '/home/1713/civ13-pers/scripts/killciv13.py',
        'mapswap_pers' => '/home/1713/civ13-pers/scripts/mapswap.py',

        'typespess_path' => '/home/1713/civ13-typespess',
        'typespess_launch_server_path' => 'scripts/launch_server.sh',
        
         //Unused
        'nomads_playerlogs' => '/home/1713/civ13-rp/SQL/playerlogs.txt',
        'tdm_playerlogs' => '/home/1713/civ13-tdm/SQL/playerlogs.txt',
        'pers_playerlogs' => '/home/1713/civ13-pers/SQL/playerlogs.txt',
    ),
    'channel_ids' => array(
        'nomads_ooc_channel' => '636644156923445269', //#ooc-nomads
        'nomads_admin_channel' => '637046890030170126', //#ahelp-nomads
        'nomads-playercount' => '1048777424894185484', //nomads-#
        'tdm_ooc_channel' => '636644391095631872', //#ooc-tdm
        'tdm_admin_channel' => '637046904575885322', //#ahelp-tdm
        'tdm-playercount' => '1048777462898761789', //tdm-#
        'pers_ooc_channel' => '1090863947579658320', //#ooc-pers
        'pers_admin_channel' => '1090863837730848798', //#ahelp-pers
        'pers-playercount' => '1090788345082298369', //pers-#
        'staff_bot' => '712685552155230278', //#staff-bot
        //'ban_appeals' => '1019718839749062687', //#ban-appeals (forum thread, unused by bot)        
    ),
    'role_ids' => array(
        'admiral' => '468980650914086913', //Civ13 Discord Server Owner
        'bishop' => '791450326455681034', //Civ13 Debug Host
        'host' => '677873806513274880', //Civ13 Server Host
        'grandmaster' => '487608503553490965', //Grand Master
        'marshall' => '496004389950193667', //Marshall
        'captain' => '792826030796308503', //Head admin
        'knightcommander' => '561770271300911105', //Master admin
        'storyteller' => '774435124611514368', //Event admin
        'knight' => '468982360659066912', //Admin
        'squire' => '823302316743589938', //Squire
        'mentor' => '469297467918254085', //Mentor
        'veteran' => '468983261708681216', //Promoted
        'infantry' => '468982790772228127', //Verified
        'banished' => '710328377210306641', //Banned in-game (unused)
        'paroled' => '745336314689355796', //On parole (unused)
        'red' => '955869414622904320',
        'blue' => '955869567035527208',
    ),
    'functions' => array(
        'ready' => [
            //'on_ready' => $on_ready,
            'status_changer_timer' => $status_changer_timer,
            'status_changer_random' => $status_changer_random,
            'civ_listeners' => $civ_listeners, //TODO: Move into civ13.php
        ],
        'ready_slash' => [
            'slash_init' => $slash_init,
        ],
        'message' => [
            'on_message' => $on_message,
        ],
        'GUILD_MEMBER_ADD' => [
            //
        ],
        'misc' => [ //Custom functions
            'promotable_check' => $promotable_check,
            'mass_promotion_loop' => $mass_promotion_loop,
            'mass_promotion_check' => $mass_promotion_check,
        ],
    ),
);
if (include 'civ_token.php') $options['civ_token'] = $civ_token;
$civ13 = new Civ13($options);
include 'webapi.php'; //$socket, $webapi, webapiFail(), webapiSnow();
$civ13->run();