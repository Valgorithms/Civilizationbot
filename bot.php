<?php
$testing = false; // Set to true to disable certain features that may be disruptive to the server when testing locally

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

use Civ13\Civ13;
use Discord\Discord;
//use \Discord\Helpers\CacheConfig;
use React\EventLoop\Loop;
//use \WyriHaximus\React\Cache\Redis as RedisCache;
//use \Clue\React\Redis\Factory as Redis;
use React\Filesystem\Factory as FilesystemFactory;
use Monolog\Logger;
use Monolog\Level;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Discord\WebSockets\Intents;
use React\Http\Browser;

ini_set('display_errors', 1);
error_reporting(E_ALL);

set_time_limit(0);
ignore_user_abort(1);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1'); // Unlimited memory usage
define('MAIN_INCLUDED', 1); // Token and SQL credential files may be protected locally and require this to be defined to access
require getcwd() . '/token.php'; // $token
include getcwd() . '/vendor/autoload.php';
include 'vendor/autoload.php';

$web_address = 'www.civ13.com';
$http_port = 55555;

$loop = Loop::get();
$streamHandler = new StreamHandler('php://stdout', Level::Info);
$streamHandler->setFormatter(new LineFormatter(null, null, true, true));
$logger = new Logger('Civ13', [$streamHandler]);
$discord = new Discord([
    'loop' => $loop,
    'logger' => $logger,
    /* // Disabled for debugging
    'cache' => new CacheConfig(
        $interface = new RedisCache(
            (new Redis($loop))->createLazyClient('127.0.0.1:6379'),
            'dphp:cache:
        '),
        $compress = true, // Enable compression if desired
        $sweep = false // Disable automatic cache sweeping if desired
    ), 
    */
    'socket_options' => [
        'dns' => '8.8.8.8', // can change dns
    ],
    'token' => $token,
    'loadAllMembers' => true,
    'storeMessages' => true, // Because why not?
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS | Intents::MESSAGE_CONTENT,
]);
if (include __DIR__ . '/src/Stats.php') {
    $stats = new Stats();
    $stats->init($discord);
}
$browser = new Browser($loop);
$filesystem = FilesystemFactory::create($loop);
include 'functions.php'; // execInBackground(), portIsAvailable()
include 'variable_functions.php';
include 'verifier_functions.php';
include 'civ_token.php'; // $civ_token

// TODO: Add a timer and a callable function to update these IP addresses every 12 hours
$civ13_ip = gethostbyname('www.civ13.com');
$vzg_ip = gethostbyname('www.valzargaming.com');
$http_whitelist = [$civ13_ip, $vzg_ip];
$http_key = getenv('WEBAPI_TOKEN') ?? '';

$webapi = null;
$socket = null;
$options = array(
    'github' => 'https://github.com/VZGCoders/Civilizationbot',
    'command_symbol' => '@Civilizationbot',
    'owner_id' => '196253985072611328', // Taislin
    'technician_id' => '116927250145869826', // Valithor
    'civ13_guild_id' => '468979034571931648', // Civ13
    'discord_invite' => 'https://civ13.com/discord',
    'discord_formatted' => 'civ13.com slash discord',
    'rules' => 'civ13.com slash rules',
    'relay_method' => 'webhook',
    'sharding' => false, // Enable sharding of the bot, allowing it to be run on multiple servers without conflicts, and suppressing certain responses where a shard may be handling the request
    'shard' => false, // Whether this instance is a shard
    'legacy' => true, // Whether to use the filesystem or SQL database system
    'moderate' => true, // Whether to moderate in-game chat
    // The Verify URL is where verification requests are sent to and where the verification list is retrieved from
    // The website must return valid json when no parameters are passed to it and MUST allow POST requests including 'token', 'ckey', and 'discord'
    // Reach out to Valithor if you need help setting up your website
    'webserver_url' => 'www.valzargaming.com',
    'verify_url' => 'http://valzargaming.com:8080/verified/', // Leave this blank if you do not want to use the webserver, ckeys will be stored locally as provisional
    // 'serverinfo_url' => '', // URL of the serverinfo.json file, defaults to the webserver if left blank
    'ooc_badwords' => [
        /* Format:
            'word' => 'bad word' // Bad word to look for
            'duration' => duration ['1 minute', '1 hour', '1 day', '1 week', '1 month', '999 years'] // Duration of the ban
            'reason' => 'reason' // Reason for the ban
            'category' => rule category ['racism/discrimination', 'toxic', 'advertisement'] // Used to group bad words together by category
            'method' => detection method ['exact', 'str_contains', 'str_ends_with', 'str_starts_with'] // Exact ignores partial matches, str_contains matches partial matches, etc.
            'warnings' => 1 // Number of warnings before a ban
        */
        ['word' => 'badwordtestmessage', 'duration' => '1 minute', 'reason' => 'Violated server rule.', 'category' => 'test', 'method' => 'str_contains', 'warnings' => 1], // Used to test the system
        
        ['word' => 'beaner', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
        ['word' => 'chink', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
        ['word' => 'coon', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'exact', 'warnings' => 1],
        ['word' => 'fag', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
        ['word' => 'gook', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
        ['word' => 'kike', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
        ['word' => 'nigg', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
        ['word' => 'nlgg', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
        ['word' => 'niqq', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
        ['word' => 'tranny', 'duration' => '999 years', 'reason' => 'Racism and Discrimination.', 'category' => 'racism/discrimination', 'method' => 'str_contains', 'warnings' => 1],
        
        ['word' => 'cunt', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 5],
        ['word' => 'retard', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 5],
        ['word' => 'kys', 'duration' => '1 minute', 'reason' => 'You must not be toxic or too agitated in any OOC communication channels.', 'category' => 'toxic', 'method' => 'exact', 'warnings' => 1], // This is more severe than the others, so ban after only one warning
        
        ['word' => 'discord.gg', 'duration' => '999 years', 'reason' => 'You must not post unauthorized Discord invitation links in any OOC communication channels.', 'category' => 'advertisement', 'method' => 'str_contains', 'warnings' => 2],
        ['word' => 'discord.com', 'duration' => '999 years', 'reason' => 'You must not post unauthorized Discord invitation links in any OOC communication channels.', 'category' => 'advertisement', 'method' => 'str_contains', 'warnings' => 2],
        //['word' => 'RU', 'duration' => '999 years', 'reason' => 'только английский.', 'category' => 'language', 'method' => 'cyrillic', 'warnings' => 2],
    ],
    'ic_badwords' => [],
    'folders' => array(
        // 'typespess_path' => '/home/civ13/civ13-typespess',
    ),
    'files' => array( // Server-specific file paths MUST start with the server name as defined in server_settings unless otherwise specified
        'map_defines_path' => '/home/civ13/civ13-git/code/__defines/maps.dm',
        'tdm_sportsteams' => '/home/civ13/civ13-tdm/SQL/sports_teams.txt', // Football Teams (This is only used for the 'sportsteams' chat command)
        'tdm_awards_path' => '/home/civ13/civ13-tdm/SQL/awards.txt', // Medals
        'tdm_awards_br_path' => '/home/civ13/civ13-tdm/SQL/awards_br.txt', // Battle Royale Medals
        // 'typespess_launch_server_path' => '/home/civ13/civ13-typespess/scripts/launch_server.sh',
    ),
    'channel_ids' => array(
        'get-approved' => '690025163634376738', #get-approved
        'webserver-status' => '1106967195092783104', #webserver-{status}
        'verifier-status' => '1231988255470125117', #verifier-{status}
        'staff_bot' => '712685552155230278', // #staff-bot
        'parole_logs' => '985606778916048966', // #parole-logs (for tracking)
        'parole_notif' => '977715818731294790', // #parole-notif (for login/logout notifications)
    ),
    'role_ids' => array(
        // Discord ranks
        // Staff Roles
        'Owner' => '468980650914086913', // Civ13 Discord Server Owner
        'Chief Technical Officer' => '791450326455681034', // Civ13 Debug Host / Database admin
        'Host' => '677873806513274880', // Civ13 Server Host
        'Head Admin' => '487608503553490965',
        'Manager' => '496004389950193667',
        'High Staff' => '792826030796308503',
        'Supervisor' => '561770271300911105',
        'Event Admin' => '774435124611514368',
        'Admin' => '468982360659066912',
        'Moderator' => '823302316743589938',
        'Mentor' => '469297467918254085',
        'Parolemin' => '743971427929030748', // Parole Admin
        // Player Roles
        'veteran' => '468983261708681216', // Promoted
        'infantry' => '468982790772228127', // Verified
        'banished' => '710328377210306641', // Banned in-game
        'permabanished' => '1126137099209425017', // Permanently banned in-game
        'dungeon' => '547186843746304020', // Dungeon, for those who have had their Discord permissions revoked
        'paroled' => '745336314689355796', // On parole
        
        // Factions
        'red' => '1132678312301428886', // Redmenia
        'blue' => '1132678353070067802', // Blugoslavia
        'organizer' => '1089060051425165362', // Admin / Faction Organizer
        // Notification pings
        'mapswap' => '1200520534262284288', // Map Swap Ping
        'round_start' => '1110597830403424328', // Round Start Ping
        '2+' => '981963719804346418', // LowPopStart
        '15+' => '981963721817620511', // 15+ Popping
        '30+' => '981963696895062106', // 30+ Popping
        // Server channels
        'tdm' => '753768519203684445',
        'nomads' => '753768513671397427',
        'pers' => '753768492834095235',
    ),
);
$options['welcome_message'] = "Welcome to the Civ13 Discord Server! Please read the rules and verify your account using the `{$options['command_symbol']} approveme` chat command. Failure to verify in a timely manner will result in an automatic removal from the server.";
foreach (['а', 'б', 'в', 'г', 'д', 'е', 'ё', 'ж', 'з', 'и', 'й', 'к', 'л', 'м', 'н', 'о', 'п', 'р', 'с', 'т', 'у', 'ф', 'х', 'ц', 'ч', 'ш', 'щ', 'ъ', 'ы', 'ь', 'э', 'ю', 'я', 'і', 'ї', 'є'] as $char) { // // Ban use of Cyrillic characters
    $options['ooc_badwords'][] = ['word' => $char, 'duration' => '999 years', 'reason' => 'только английский.', 'category' => 'language', 'method' => 'str_contains', 'warnings' => 2];
    $options['ic_badwords'][] = ['word' => $char, 'duration' => '999 years', 'reason' => 'только английский.', 'category' => 'language', 'method' => 'str_contains', 'warnings' => 2];
}

// Write editable configurations to a single JSON file
/*
$json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents("config.json", $json);
*/

// Load configurations from the JSON file
/*
$loadedData = [];
$json = file_get_contents("config.json");
$loadedData = json_decode($json, true);
foreach ($loadedData as $key => $value) $options[$key] = $value;
*/

$server_settings = [ // Server specific settings, listed in the order in which they appear on the VZG server list.
    'tdm' => [
        'supported' => true,
        'enabled' => true,
        'name' => 'TDM',
        //'key' => 'tdm', // This must match the top-level key in the server_settings array
        'ip' => $civ13_ip,
        'port' => '1714',
        'host' => 'Taislin',
        'panic' => false,
        'legacy' => true,
        'moderate' => true,
        'relay_method' => 'webhook',
        'basedir' => '/home/civ13/civ13-tdm', // Base directory of the server
        // Primary channels
        'discussion' => '799952134426591273', // #tdm
        'playercount' => '1048777462898761789', // tdm-#
        // Chat relay channels
        'ooc' => '1107016184328622233', // #ooc-tdm
        'lobby' => '1107021760483831988', // #lobby-tdm
        'asay' => '1107016769169801216', // #asay-tdm
        'ic' => '1121531682198138920', // #ic-tdm
        // Log channels
        'transit' => '1107020747622326313', // #transit-tdm
        'adminlog' => '1107024305927225455', // #adminlog-tdm
        'debug' => '1106248157798600715', // #debug-tdm (debugging)
        'garbage' => '1107018726307528735', // #garbage-tdm
        'runtime' => '1107017103883632792', // #runtime-tdm
        'attack' => '1107017830160936980', // #attack-tdm
    ],
    'nomads' => [
        'supported' => true, // Whether the server is supported by the remote webserver
        'enabled' => true, // Whether the server should have commands handled by the bot
        'name' => 'Nomads', // Name of the server and the prefix of the playercount channel (e.g. nomads-999)
        //'key' => 'nomads', // This must match the top-level key in the server_settings array
        'ip' => $civ13_ip, // IP of the server
        'port' => '1715', // Port of the server
        'host' => 'Taislin', // Host of the server
        'panic' => true, // Panic mode will ban all users who are not verified
        'legacy' => true, // Legacy mode will use the file system instead of an SQL database
        'moderate' => true, // Whether chat moderation is enabled
        'relay_method' => 'webhook', // How messages are relayed to the server
        'basedir' => '/home/civ13/civ13-rp', // Base directory of the server
        // Primary channels
        'discussion' => '799952084505067581', // #nomads
        'playercount' => '1048777424894185484', // nomads-#
        // Chat relay channels
        'ooc' => '1110001963405418616', // #ooc-nomads
        'lobby' => '1110001986134347856', // #lobby-nomads
        'asay' => '1110002005977604186', // #asay-nomads
        'ic' => '1121531739114852432', // #ic-nomads
        // Log channels
        'transit' => '1110002027469221989', // #transit-nomads
        'adminlog' => '1110002047123738624', // #adminlog-nomads
        'debug' => '1106248132779593758', // #debug-nomads (debugging)
        'garbage' => '1110002493259251752', // #garbage-nomads
        'runtime' => '1110002671936602132', // #runtime-nomads
        'attack' => '1110002697383448648', // #attack-nomads
    ],
    'pers' => [
        'supported' => true,
        'enabled' => false,
        'name' => 'Persistence',
        //'key' => 'pers', // This must match the top-level key in the server_settings array
        'ip' => $vzg_ip,
        'port' => '1716',
        'host' => 'ValZarGaming',
        'panic' => true,
        'legacy' => true,
        'moderate' => true,
        'relay_method' => 'webhook',
        'basedir' => '/home/valithor/VPS/civ13-rp', // Base directory of the server
        // Primary channels
        'discussion' => '799951945346711552', // #pers
        'playercount' => '1090788345082298369', // pers-#
        // Chat relay channels
        'ooc' => '1139614228408455388', // #ooc-pers
        'lobby' => '1139614248222343282', // #lobby-pers
        'asay' => '1139614266299785278', // #asay-pers
        'ic' => '1139614281512529941', // #ic-pers
        // Log channels
        'transit' => '1139614542700216420', // #transit-pers
        'adminlog' => '1139614564577722448', // #adminlog-pers
        'debug' => '1139614582931984575', // #debug-pers (debugging)
        'garbage' => '1139614596789964820', // #garbage-pers
        'runtime' => '1139614629081915492', // #runtime-pers
        'attack' => '1139614643954921593', // #attack-pers
    ],
];
foreach ($server_settings as $key => $value) $server_settings[$key]['key'] = $key; // Key is intended to be a shortname for the full server, so defining both a full name and short key are required. Individual server settings will also get passed around and lose their primary key, so we need to reassign it.

$hidden_options = [
    'loop' => $loop,
    'discord' => $discord,
    'browser' => $browser,
    'filesystem' => $filesystem,
    'logger' => $logger,
    'stats' => $stats,

    'webapi' => &$webapi,
    'socket' => &$socket,
    'web_address' => $web_address,
    'http_port' => $http_port,
    'http_key' => $http_key,
    'http_whitelist' => $http_whitelist,
    'civ_token' => getenv('CIV_TOKEN') ?? $civ_token ?? 'CHANGEME',
    'server_settings' => $server_settings, // Server specific settings, listed in the order in which they appear on the VZG server list.
    'functions' => array(
        'ready' => [
            // 'on_ready' => $on_ready,
            'status_changer_timer' => $status_changer_timer,
            'status_changer_random' => $status_changer_random,
        ],
        'ready_slash' => [
            //
        ],
        'message' => [
            //
        ],
        'GUILD_MEMBER_ADD' => [
            // 
        ],
        'misc' => [ // Custom functions
            'promotable_check' => $promotable_check,
            'mass_promotion_loop' => $mass_promotion_loop,
            'mass_promotion_check' => $mass_promotion_check,
        ],
    ),
];
$options = array_merge($options, $hidden_options);




$civ13 = new Civ13($options);
$global_error_handler = function (int $errno, string $errstr, ?string $errfile, ?int $errline) use ($civ13, $testing) {
    if (
        ($channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot']))
        // fsockopen
        && ! str_ends_with($errstr, 'Connection timed out') 
        && ! str_ends_with($errstr, '(Connection timed out)')
        && ! str_ends_with($errstr, 'Connection refused') // Usually happens if the verifier server doesn't respond quickly enough
        && ! str_contains($errstr, '(Connection refused)') // Usually happens in localServerPlayerCount
        //&& ! str_ends_with($errstr, 'Network is unreachable')
        //&& ! str_ends_with($errstr, '(Network is unreachable)')

        // Connectivity issues
        && ! str_ends_with($errstr, 'No route to host') // Usually happens if the verifier server is down
        && ! str_ends_with($errstr, 'No address associated with hostname') // Either the DNS or the VPS is acting up
        && ! str_ends_with($errstr, 'Temporary failure in name resolution') // Either the DNS or the VPS is acting up
        && ! str_ends_with($errstr, 'Bad Gateway') // Usually happens if the verifier server's PHP-CGI is down
        //&& ! str_ends_with($errstr, 'HTTP request failed!')

        //&& ! str_contains($errstr, 'Undefined array key')
    )
    {
        $msg = "[$errno] Fatal error on `$errfile:$errline`: $errstr ";
        if (isset($civ13->technician_id) && $tech_id = $civ13->technician_id) $msg = "<@{$tech_id}>, $msg";
        if (! $testing) $channel->sendMessage($msg);
    }
};
set_error_handler($global_error_handler);

use React\Socket\SocketServer;
use React\Http\HttpServer;
use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
$socket = new SocketServer(sprintf('%s:%s', '0.0.0.0', $http_port), [], $civ13->loop);
$last_path = '';
/**
 * This code block creates a new HttpServer object and defines a callback function that handles incoming HTTP requests.
 * The function extracts information from the request URI such as scheme, host, port, path, query and fragment.
 * If the path is empty or does not start with a forward slash, it sets the path to '/index'.
 * The function then sets the last_path variable to the full URI including query and fragment.
 * Finally, the function returns the response generated by the $civ13->httpServiceManager->httpHandler->handle() method.
 *
 * @param ServerRequestInterface $request The HTTP request object.
 * @return Response The HTTP response object.
 */
$webapi = new HttpServer($loop, function (ServerRequestInterface $request) use ($civ13, &$last_path): Response//Interface
{
    $scheme = $request->getUri()->getScheme();
    $host = $request->getUri()->getHost();
    $port = $request->getUri()->getPort();
    $path = $request->getUri()->getPath();
    if ($path === '' || $path[0] !== '/' || $path === '/') $path = '/index';
    $query = $request->getUri()->getQuery();
    $fragment = $request->getUri()->getFragment(); // Only used on the client side, ignored by the server
    $last_path = "$scheme://$host:$port$path". ($query ? "?$query" : '') . ($fragment ? "#$fragment" : '');
    //$civ13->logger->info('[WEBAPI URI] ' . preg_replace('/(?<=key=)[^&]+/', '********', $last_path););
    return $civ13->httpServiceManager->httpHandler->handle($request);
});
/**
 * Handles errors thrown by the web API.
 *
 * @param Exception $e The exception that was thrown.
 * @param \Psr\Http\Message\RequestInterface|null $request The request that caused the exception.
 * @param object $civ13 The main object of the application.
 * @param object $socket The socket object.
 * @param string $last_path The last path that was accessed.
 * @return void
 */
$webapi->on('error', function (Exception $e, ?\Psr\Http\Message\RequestInterface $request = null) use ($civ13, $socket, &$last_path, $testing) {
    if (
        str_starts_with($e->getMessage(), 'Received request with invalid protocol version')
    ) return; // Ignore this error, it's not important
    $last_path = preg_replace('/(?<=key=)[^&]+/', '********', $last_path);
    $error = "[WEBAPI] {$e->getMessage()} [{$e->getFile()}:{$e->getLine()}] " . str_replace('\n', PHP_EOL, $e->getTraceAsString());
    $civ13->logger->error("[WEBAPI] $error");
    if ($request) $civ13->logger->error('[WEBAPI] Request: ' .  preg_replace('/(?<=key=)[^&]+/', '********', $request->getRequestTarget()));
    if (str_starts_with($e->getMessage(), 'The response callback')) {
        $civ13->logger->info('[WEBAPI] ERROR - RESTART');
        if (! $testing && isset($civ13->channel_ids['staff_bot']) && $channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot'])) {
            $builder = \Discord\Builders\MessageBuilder::new()
                ->setContent('Restarting due to error in HttpServer API...' . PHP_EOL . "Last path: `$last_path`")
                ->addFileFromContent('httpserver_error.txt', preg_replace('/(?<=key=)[^&]+/', '********', $error));
            $channel->sendMessage($builder);
        }
        $socket->close();
        if (! isset($civ13->timers['restart'])) $civ13->timers['restart'] = $civ13->discord->getLoop()->addTimer(5, function () use ($civ13) {
            \restart();
            $civ13->discord->close();
            die();
        });
    }
});

$civ13->run();