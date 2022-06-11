<?php
set_time_limit(0);
ignore_user_abort(1);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1'); //Unlimited memory usage
define('MAIN_INCLUDED', 1); //Token and SQL credential files may be protected locally and require this to be defined to access
require getcwd(). '/token.php'; //$token
include getcwd() . '/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();
$logger = new Monolog\Logger('New logger');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout'));
$discord = new \Discord\Discord([
    'loop' => $loop,
    'logger' => $logger,
    /*'socket_options' => [
        'dns' => '8.8.8.8', // can change dns
    ],*/
    'token' => "$token",
    'loadAllMembers' => true,
    'storeMessages' => false, //Not needed yet
    'intents' => Discord\WebSockets\Intents::getDefaultIntents() | Discord\WebSockets\Intents::GUILD_MEMBERS, // default intents as well as guild members
]);
include 'stats_object.php'; 
$stats = new Stats();
$stats->init($discord);
$browser = new \React\Http\Browser($loop);
$filesystem = \React\Filesystem\Factory::create($loop);

include 'functions.php'; //execInBackground(), portIsAvailable()
include 'variable_functions.php'; //$recalculate_ranking, $ooc_relay, $timer_function, $on_ready, $on_message, $on_message2
include 'civ13.php';
    
    
$options = array(
    'loop' => $loop,
    'discord' => $discord,
    'browser' => $browser,
    'filesystem' => $filesystem,
    'logger' => $logger,
    
    //Configurations
    'command_symbol' => '!s',
    'owner_id' => '196253985072611328', //Taislin
    'civ13_guild_id' => '468979034571931648', //Civ13
    'ips' => array(
        'nomads_ip' => gethostbyname('www.civ13.com'),
        'tdm_ip' => gethostbyname('www.civ13.com'),
    ),
    'ports' => array(
        'nomads_port' => '1715',
        'tdm_port' => '1714',
    ),
    'files' => array(
        //Fun
        'insults_path' => 'insults.txt',
        'ranking_path' => 'ranking.txt',
        
        //Nomads
        'nomads_ooc_path' => '/home/1713/civ13-rp/ooc.log',
        'nomads_admin_path' => '/home/1713/civ13-rp/admin.log',
        'nomads_discord2ooc' => '/home/1713/civ13-rp/SQL/discord2ooc.txt',
        'nomads_discord2admin' => '/home/1713/civ13-rp/SQL/discord2admin.txt',
        'nomads_discord2dm' => '/home/1713/civ13-rp/SQL/discord2dm.txt',
        'nomads_discord2ban' => '/home/1713/civ13-rp/SQL/discord2ban.txt',
        'nomads_discord2unban' => '/home/1713/civ13-rp/SQL/discord2unban.txt',
        'nomads_whitelist' => '/home/1713/civ13-rp/SQL/whitelist.txt',
        'nomads_bans' => '/home/1713/civ13-rp/SQL/bans.txt',
        
        //Unused
        'nomads_playerlogs' => '/home/1713/civ13-rp/SQL/playerlogs.txt',
        
        //TDM
        'tdm_ooc_path' => '/home/1713/civ13-tdm/ooc.log',
        'tdm_admin_path' => '/home/1713/civ13-tdm/admin.log',
        'tdm_discord2ooc' => '/home/1713/civ13-tdm/SQL/discord2ooc.txt',
        'tdm_discord2admin' => '/home/1713/civ13-tdm/SQL/discord2admin.txt',
        'tdm_discord2dm' => '/home/1713/civ13-tdm/SQL/discord2dm.txt',
        'tdm_discord2ban' => '/home/1713/civ13-tdm/SQL/discord2ban.txt',
        'tdm_discord2unban' => '/home/1713/civ13-tdm/SQL/discord2unban.txt',
        'tdm_discord2ban' => '/home/1713/civ13-tdm/SQL/discord2ban.txt',
        'tdm_whitelist' => '/home/1713/civ13-tdm/SQL/whitelist.txt',
        'tdm_bans' => '/home/1713/civ13-tdm/SQL/bans.txt',
        'tdm_awards_path' => '/home/1713/civ13-tdm/SQL/awards.txt',
        'tdm_awards_br_path' => '/home/1713/civ13-tdm/SQL/awards_br.txt',

        //Script paths
        'nomads_updateserverabspaths' => '/home/1713/civ13-rp/scripts/updateserverabspaths.py',
        'nomads_serverdata' => '/home/1713/civ13-rp/serverdata.txt',
        'nomads_dmb' => '/home/1713/civ13-rp/civ13.dmb',
        'nomads_killsudos' => '/home/1713/civ13-rp/scripts/killsudos.py',
        'nomads_killciv13' => '/home/1713/civ13-rp/scripts/killciv13.py',
        'nomads_mapswap' => '/home/1713/civ13-rp/scripts/mapswap.py',

        'tdm_updateserverabspaths' => '/home/1713/civ13-tdm/scripts/updateserverabspaths.py',
        'tdm_serverdata' => '/home/1713/civ13-tdm/serverdata.txt',
        'tdm_dmb' => '/home/1713/civ13-tdm/civ13.dmb',
        'tdm_killsudos' => '/home/1713/civ13-tdm/scripts/killsudos.py',
        'tdm_killciv13' => '/home/1713/civ13-tdm/scripts/killciv13.py',
        'tdm_mapswap' => '/home/1713/civ13-tdm/scripts/mapswap.py',

        'typespess_path' => '/home/1713/civ13-typespess',
        'typespess_launch_server_path' => 'scripts/launch_server.sh',
    ),
    'channel_ids' => array(
        'nomads_ooc_channel' => '636644156923445269', //#ooc-nomads
        'nomads_admin_channel' => '637046890030170126', //#ahelp-nomads
        'tdm_ooc_channel' => '636644391095631872', //#ooc-tdm
        'tdm_admin_channel' => '637046904575885322', //#ahelp-tdm
    ),
    'role_ids' => array(
        'admiral' => '468980650914086913', //Host
        'captain' => '792826030796308503', //Head admin
        'knight' => '468982360659066912', //Admin
        'veteran' => '468983261708681216', //Promoted
        'infantry' => '468982790772228127', //Verified
    ),
    'functions' => array(
        'ready' => [
            'on_ready' => $on_ready,
        ],
        'message' => [
            'on_message' => $on_message,
            'on_message2' => $on_message2,
        ],
        'misc' => [ //Custom functions
            'recalculate_ranking' => $recalculate_ranking,
            'ooc_relay' => $ooc_relay,
            'timer_function' => $timer_function,
        ],
    ),
);
$civ13 = new Civ13\Civ13($options);
include 'webapi.php'; //$socket, $webapi, webapiFail(), webapiSnow();
$civ13->run();