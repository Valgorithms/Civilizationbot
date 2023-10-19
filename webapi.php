<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

use Discord\Parts\Embed\Embed;
use React\Socket\SocketServer;
use React\Http\HttpServer;
use React\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

@include getcwd() . '/webapi_token_env.php'; // putenv("WEBAPI_TOKEN='YOUR_TOKEN_HERE'");
$webhook_key = getenv('WEBAPI_TOKEN') ?? 'CHANGEME'; // The token is used to verify that the sender is legitimate and not a malicious actor

$webapiFail = function (string $part, string $id) {
    // logInfo('[webapi] Failed', ['part' => $part, 'id' => $id]);
    return new Response(($id ? 404 : 400), ['Content-Type' => 'text/plain'], ($id ? 'Invalid' : 'Missing').' '.$part);
};

$webapiSnow = function (string $string) {
    return preg_match('/^[0-9]{16,20}$/', $string);
};

// $external_ip = file_get_contents('http://ipecho.net/plain');
// $civ13_ip = gethostbyname('www.civ13.com');
// $vzg_ip = gethostbyname('www.valzargaming.com');
$port = '55555';

$portknock = false;
$max_attempts = 3;
$portknock_ips = []; // ['ip' => ['step' => 0, 'authed' = false]]
$portknock_servers = [];
@include getcwd() . '/webapi_portknocks.php'; // putenv("DOORS=['port1', 'port2', 'port1', 'port3', 'port2' 'port1']"); (not a real example)
if ($portknock_ports = getenv('DOORS') ? unserialize(getenv('DOORS')) : []) { // The port knocks are used to prevent malicious port scanners from spamming the webapi
    $validatePort = function (int|string $value) use ($port) {
        return (
            $value > 0 // Port numbers are positive
            && $value < 65536 // Port numbers are between 0 and 65535
            && $value != $port // If the webapi port is in the port knocks list it is misconfigured and should be disabled
        );
    };
    $valid_config = true;
    foreach ($portknock_ports as $p) {
        if (! $validatePort($p)) {
            $valid_config = false;
            break;
        }
    }
    if ($valid_config) {
        $portknock = true;
        $initialized_ports = [];
        foreach ($portknock_ports as $p) {
            if (! in_array($p, $initialized_ports)) { // Don't listen on the same port as the webapi or any other port
                $s = new SocketServer(sprintf('%s:%s', '0.0.0.0', $p), [], $civ13->loop);
                $w = new HttpServer($loop, function (ServerRequestInterface $request) use ($civ13, $p, $portknock_ips, $portknock_ports, $max_attempts) {
                    // Initialize variables
                    $ip = $request->getServerParams()['REMOTE_ADDR'];
                    $step = 0;
                    if (! isset($portknock_ips[$ip])) $portknock_ips[$ip] = ['step' => 0, 'authed' => false, 'failed' => 0, 'knocks' => 1]; // First time knocking
                    elseif (isset($portknock_ips[$ip]['step'])) { // Already knocked
                        $step = $portknock_ips[$ip]['step'];
                        $portknock_ips[$ip]['knocks']++; // Useful for detecting spam, but not functionally used (yet)
                    }

                    // Too many failed attempts
                    if ($portknock_ips[$ip]['failed'] > $max_attempts) {
                        $civ13->logger->warning('[webapi] Blocked Port Scanner', [
                            'ip' => $ip,
                            'step' => $portknock_ips[$ip]['step'],
                            'authed' => $portknock_ips[$ip]['authed'],
                            'failed' => $portknock_ips[$ip]['failed'],
                            'knocks' => $portknock_ips[$ip]['knocks'],
                        ]);
                        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
                    }

                    // Already authed, so deauth
                    if ($portknock_ips[$ip]['authed']) {
                        $portknock_ips[$ip]['authed'] = false;
                        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
                    }

                    // Check if knock is valid
                    // Authenticate if all steps are completed
                    // Reset knocks and log failed attempt if the step is invalid
                    $valid_steps = [];
                    foreach ($portknock_ports as $value) if ($value == $p) $valid_steps[] = $p;
                    if (in_array($step, $valid_steps)) {
                        $portknock_ips[$ip]['step']++;
                        if ($portknock_ips[$ip]['step'] > count($valid_steps)) $portknock_ips[$ip]['authed'] = true;
                    } else {
                        $portknock_ips[$ip]['step'] = 0;
                        $portknock_ips[$ip]['failed']++;
                    }
                    
                    // Log the knock
                    $civ13->logger->debug('[webapi] Knock', [
                        'ip' => $ip,
                        'step' => $portknock_ips[$ip]['step'],
                        'authed' => $portknock_ips[$ip]['authed'],
                        'failed' => $portknock_ips[$ip]['failed'],
                        'knocks' => $portknock_ips[$ip]['knocks'],
                    ]);

                    return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
                });
                $w->listen($s);
                $w->on('error', function (Exception $e) use ($civ13) {
                    $civ13->logger->error('KNOCK ' . $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . '] ' . str_replace('\n', PHP_EOL, $e->getTraceAsString()));
                });
                $portknock_servers[] = $w;
            }
            $initialized_ports[] = $p;
        }
    }
}

$socket = new SocketServer(sprintf('%s:%s', '0.0.0.0', $port), [], $civ13->loop);

/**
 * This code block creates a new HttpServer object and handles incoming HTTP requests.
 * It extracts the scheme, host, port, path, query, and fragment from the request URI.
 * If the path is empty or does not start with a forward slash, it sets the path to '/index'.
 * It logs the URI and passes the request to the HttpHandler object for processing.
 * If the response is an instance of ResponseInterface, it returns the response.
 * If the response is either not an instance of ResponseInterface or if teh endpoint does not result in a Response, it logs an error and returns a JSON response with an error message.
 * If the endpoint does not result in a Response, it logs an error and returns a JSON response with an error message.
 */
$last_path = '';
$webapi = new HttpServer($loop, function (ServerRequestInterface $request) use ($civ13, &$last_path, $port, $socket, $vzg_ip, $civ13_ip, $external_ip, $webhook_key, $portknock, $portknock_ips, $max_attempts, $webapiFail, $webapiSnow): Response
{
    $scheme = $request->getUri()->getScheme();
    $host = $request->getUri()->getHost();
    $port = $request->getUri()->getPort();
    $path = $request->getUri()->getPath();
    if ($path === '' || $path[0] !== '/' || $path === '/') $path = '/index';
    $query = $request->getUri()->getQuery();
    $fragment = $request->getUri()->getFragment(); // Only used on the client side, ignored by the server
    $last_path = "$scheme://$host:$port$path". ($query ? "?$query" : '') . ($fragment ? "#$fragment" : '');
    //$civ13->logger->info('[WEBAPI URI] ' . $last_path);
    return $civ13->httpHandler->handle($request);

    
    // Port knocking security check
    $authed_ips = [];
    if ($portknock && isset($portknock_ips[$request->getServerParams()['REMOTE_ADDR']])) {
        if ($portknock_ips[$request->getServerParams()['REMOTE_ADDR']]['failed'] > $max_attempts) {// Malicious port scanner
            $civ13->logger->warning('[webapi] Blocked Port Scanner', [
                'ip' => $request->getServerParams()['REMOTE_ADDR'],
                'step' => $portknock_ips[$request->getServerParams()['REMOTE_ADDR']]['step'],
                'authed' => $portknock_ips[$request->getServerParams()['REMOTE_ADDR']]['authed'],
                'failed' => $portknock_ips[$request->getServerParams()['REMOTE_ADDR']]['failed'],
                'knocks' => $portknock_ips[$request->getServerParams()['REMOTE_ADDR']]['knocks'],
            ]);
            return new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');
        }
        /* // Port knocking to obtain a valid session is not implemented for security reasons
        if ($portknock_ips[$request->getServerParams()['REMOTE_ADDR']]['authed']) 
            $authed_ips[] = $request->getServerParams()['REMOTE_ADDR'];
        */
    }
    /*
    $path = explode('/', $request->getUri()->getPath());
    $sub = (isset($path[1]) ? (string) $path[1] : false);
    $id = (isset($path[2]) ? (string) $path[2] : false);
    $id2 = (isset($path[3]) ? (string) $path[3] : false);
    $ip = (isset($path[4]) ? (string) $path[4] : false);
    $idarray = array(); // get from post data (NYI)
    */
    
    $echo = 'API ';
    $sub = 'index.';
    $path = explode('/', $request->getUri()->getPath());
    $civ13->logger->debug('[webapi] ' . $request->getServerParams()['REMOTE_ADDR'] . ' ' . $request->getMethod() . ' ' . $request->getUri()->getPath());
    $repository = $sub = (isset($path[1]) ? (string) strtolower($path[1]) : false); if ($repository) $echo .= "$repository";
    $method = $id = (isset($path[2]) ? (string) strtolower($path[2]) : false); if ($method) $echo .= "/$method";
    $id2 = (isset($path[3]) ? (string) strtolower($path[3]) : false); if ($id2) $echo .= "/$id2";
    $ip = $partial = (isset($path[4]) ? (string) strtolower($path[4]) : false); if ($partial) $echo .= "/$partial";
    $id3 = (isset($path[5]) ? (string) strtolower($path[5]) : false); if ($id3) $echo .= "/$id3";
    $id4 = (isset($path[6]) ? (string) strtolower($path[6]) : false); if ($id4) $echo .= "/$id4";
    $idarray = array(); // get from post data (NYI)
    // $civ13->logger->info($echo);
    
    if ($ip) $civ13->logger->info('API IP ' . $ip);

    $whitelisted = false;
    switch ($sub) {
        case (str_starts_with($sub, 'index.')):
            $return = '<meta http-equiv="refresh" content="0 url=\'https://www.valzargaming.com/?login\'" />'; // Redirect to the website to log in
            return new Response(200, ['Content-Type' => 'text/html'], $return);
            break;
        
        case 'channel':
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->getChannel($id)) return $webapiFail('channel_id', $id);
            break;

        case 'guild':
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)) return $webapiFail('guild_id', $id);
            break;

        case 'bans':
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->bans) return $webapiFail('guild_id', $id);
            break;

        case 'channels':
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->channels) return $webapiFail('guild_id', $id);
            break;

        case 'members':
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->members) return $webapiFail('guild_id', $id);
            break;

        case 'emojis':
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->emojis) return $webapiFail('guild_id', $id);
            break;

        case 'invites':
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->invites) return $webapiFail('guild_id', $id);
            break;

        case 'roles':
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->roles) return $webapiFail('guild_id', $id);
            break;

        case 'guildMember':
            if (! $id || ! $webapiSnow($id) || ! $guild = $civ13->discord->guilds->get('id', $id)) return $webapiFail('guild_id', $id);
            if (! $id2 || ! $webapiSnow($id2) || ! $return = $guild->members->get('id', $id2)) return $webapiFail('user_id', $id2);
            break;

        case 'user':
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->users->get('id', $id)) return $webapiFail('user_id', $id);
            break;

        case 'userName':
            if (! $id || ! $return = $civ13->discord->users->get('name', $id)) return $webapiFail('user_name', $id);
            break;

        case 'lookup':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if (! $id || ! $webapiSnow($id) || ! $return = $civ13->discord->users->get('id', $id)) return $webapiFail('user_id', $id);
            break;

        case 'owner':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if (! $id || ! $webapiSnow($id)) return $webapiFail('user_id', $id); $return = false;
            if ($user = $civ13->discord->users->get('id', $id)) { // Search all guilds the bot is in and check if the user id exists as a guild owner
                foreach ($civ13->discord->guilds as $guild) {
                    if ($id == $guild->owner_id) {
                        $return = true;
                        break 1;
                    }
                }
            }
            break;

        case 'avatar':
            if (! $id || ! $webapiSnow($id)) return $webapiFail('user_id', $id);
            if (! $user = $civ13->discord->users->get('id', $id)) $return = 'https://cdn.discordapp.com/embed/avatars/'.rand(0,4).'.png';
            else $return = $user->avatar;
            // if (! $return) return new Response(($id ? 404 : 400), ['Content-Type' => 'text/plain'], (''));
            break;

        case 'avatars': // This needs to be optimized to not use async code
            /*
            $idarray = $data ?? array(); // $data contains POST data
            $results = [];
            $promise = $civ13->discord->users->fetch($idarray[0])->then(function (User $user) use (&$results) {
              $results[$user->id] = $user->avatar;
            });
            
            for ($i = 1; $i < count($idarray); $i++) {
                $discord = $civ13->discord;
                $promise->then(function () use (&$results, $idarray, $i, $discord) {
                return $civ13->discord->users->fetch($idarray[$i])->then(function (User $user) use (&$results) {
                    $results[$user->id] = $user->avatar;
                });
              });
            }

            $promise->done(function () use ($results) {
              return new Response (200, ['Content-Type' => 'application/json'], json_encode($results));
            }, function () use ($results) {
              // return with error ?
              return new Response(200, ['Content-Type' => 'application/json'], json_encode($results));
            });
            */
            $return = '';
            break;

        case 'webhook':
            $server =& $method; // alias for readability+
            $upper = null;
            foreach (array_keys($civ13->server_settings) as $key) if (strtolower($key) === $server) $upper = $key;
            if (! isset($civ13->channel_ids[$server.'_debug_channel']) || ! $channel_id = $civ13->channel_ids[$server.'_debug_channel']) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
            $params = $request->getQueryParams();
            // var_dump($params);
            if (! $whitelisted && (! isset($params['key']) || $params['key'] != $webhook_key)) return new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');
            if (! isset($params['method']) || ! isset($params['data'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Missing Parameters');
            $data = json_decode($params['data'], true);
            $time = '['.date('H:i:s', time()).']';
            $message = '';
            $ckey = '';
            if (isset($data['ckey'])) $ckey = $civ13->sanitizeInput($data['ckey']);
            switch ($params['method']) {
                case 'ahelpmessage':
                    $message .= "**__{$time} AHELP__ $ckey**: " . html_entity_decode(urldecode($data['message']));
                    break;
                case 'asaymessage':
                    if (! isset($civ13->channel_ids[$server.'_asay_channel']) || ! $channel_id = $civ13->channel_ids[$server.'_asay_channel']) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    if (isset($data['message'])) {
                        $ckey = explode('/', $ckey)[0]; // separate ckey from the string
                        $message .= /*"**__{$time} ASAY__ $ckey**: " .*/ html_entity_decode(urldecode($data['message']));
                    }
                    if ($civ13->relay_method === 'webhook' && $ckey && $message && $civ13->gameChatWebhookRelay($ckey, $message, $channel_id)) 
                         return new Response(200, ['Content-Type' => 'text/html'], 'Done'); // Relay handled by civ13->gameChatWebhookRelay
                    break;
                case 'lobbymessage': // Might overlap with deadchat
                    if (! isset($civ13->channel_ids[$server.'_lobby_channel']) || ! $channel_id = $civ13->channel_ids[$server.'_lobby_channel']) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $message .= "**__{$time} LOBBY__ $ckey**: " . html_entity_decode(urldecode($data['message']));
                    break;
                case 'oocmessage':
                    if (! isset($civ13->channel_ids[$server.'_ooc_channel']) || ! $channel_id = $civ13->channel_ids[$server.'_ooc_channel']) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $message .= html_entity_decode(strip_tags(urldecode($data['message'])));
                    if ($civ13->relay_method === 'webhook' && $ckey && $message && $civ13->gameChatWebhookRelay($ckey, $message, $channel_id))
                        return new Response(200, ['Content-Type' => 'text/html'], 'Done'); // Relay handled by civ13->gameChatWebhookRelay
                    if ($civ13->relay_method === 'file' && ! $ckey && str_ends_with($message, 'starting!') && $strpos = strpos($message, 'New round ')) {
                        $new_message = '';
                        if (isset($civ13->role_ids['round_start'])) $new_message .= "<@&{$civ13->role_ids['round_start']}>, ";
                        $new_message .= substr($message, $strpos);
                        $message = $new_message;
                        if (isset($civ13->channel_ids[$server . '-playercount']) && $playercount_channel = $civ13->discord->getChannel($civ13->channel_ids[$server . '-playercount']))
                            if ($existingCount = explode('-', $playercount_channel->name)[1]) {
                                $existingCount = intval($existingCount);
                                switch ($existingCount) {
                                    case 0:
                                        $message .= ' There are currently no players on the ' . ($upper ?? $server) . ' server.';
                                        break;
                                    case 1:
                                        $message .= ' There is currently 1 player on the ' . ($upper ?? $server) . ' server.';
                                        break;
                                    default:
                                        if (isset($civ13->role_ids['30+']) && $civ13->role_ids['30+'] && ($existingCount >= 30)) $message .= " <@&{$civ13->role_ids['30+']}>,";
                                        elseif (isset($civ13->role_ids['15+']) && $civ13->role_ids['15+'] && ($existingCount >= 15)) $message .= " <@&{$civ13->role_ids['15+']}>,";
                                        elseif (isset($civ13->role_ids['2+']) && $civ13->role_ids['2+'] && ($existingCount >= 2)) $message .= " <@&{$civ13->role_ids['2+']}>,";
                                        $message .= ' There are currently ' . $existingCount . ' players on the ' . ($upper ?? $server) . ' server.';
                                        break;
                                }
                            }
                    }
                    break;
                case 'icmessage':
                    if (! isset($civ13->channel_ids[$server.'_ic_channel']) || ! $channel_id = $civ13->channel_ids[$server.'_ic_channel']) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $message .= html_entity_decode(strip_tags(urldecode($data['message'])));
                    if ($civ13->relay_method === 'webhook' && $ckey && $message && $civ13->gameChatWebhookRelay($ckey, $message, $channel_id, false))
                        return new Response(200, ['Content-Type' => 'text/html'], 'Done'); // Relay handled by civ13->gameChatWebhookRelay
                    break;
                case 'memessage':
                    if (isset($data['message'])) $message .= "**__{$time} EMOTE__ $ckey** " . html_entity_decode(urldecode($data['message']));
                    break;
                case 'garbage':
                    if (isset($data['message'])) $message .= "**__{$time} GARBAGE__ $ckey**: " . html_entity_decode(strip_tags($data['message']));
                    break;
                case 'round_start':
                    if (! isset($civ13->channel_ids[$server]) || ! $channel_id = $civ13->channel_ids[$server]) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    if ($civ13->relay_method !== 'webhook') return new Response(200, ['Content-Type' => 'text/html'], 'Done'); // Only relay if using webhook
                    if (isset($civ13->role_ids['round_start'])) $message .= "<@&{$civ13->role_ids['round_start']}>, ";
                    $message .= 'New round ';
                    if (isset($data['round']) && $game_id = $data['round']) {
                        $civ13->logNewRound($server, $game_id, $time);
                        $message .= "`$game_id` ";
                    }
                    $message .= 'has started!';
                    if ($playercount_channel = $civ13->discord->getChannel($civ13->channel_ids[$server . '-playercount']))
                    if ($existingCount = explode('-', $playercount_channel->name)[1]) {
                        $existingCount = intval($existingCount);
                        switch ($existingCount) {
                            case 0:
                                $message .= ' There are currently no players on the ' . ($upper ?? $server) . ' server.';
                                break;
                            case 1:
                                $message .= ' There is currently 1 player on the ' . ($upper ?? $server) . ' server.';
                                break;
                            default:
                                if (isset($civ13->role_ids['30+']) && $civ13->role_ids['30+'] && ($existingCount >= 30)) $message .= " <@&{$civ13->role_ids['30+']}>,";
                                elseif (isset($civ13->role_ids['15+']) && $civ13->role_ids['15+'] && ($existingCount >= 15)) $message .= " <@&{$civ13->role_ids['15+']}>,";
                                elseif (isset($civ13->role_ids['2+']) && $civ13->role_ids['2+'] && ($existingCount >= 2)) $message .= " <@&{$civ13->role_ids['2+']}>,";
                                $message .= ' There are currently ' . $existingCount . ' players on the ' . ($upper ?? $server) . ' server.';
                                break;
                        }
                    }
                    // A future update should include a way to call a $civ13 function using the server and round id
                    break;
                case 'respawn_notice':
                    // if (isset($civ13->role_ids['respawn_notice'])) $message .= "<@&{$civ13->role_ids['respawn_notice']}>, ";
                    if (isset($data['message'])) $message .= html_entity_decode(urldecode($data['message']));
                    break;
                case 'login':
                    $civ13->checkCkey($ckey);
                    // Move this to a function in civ13.php
                    if (isset($civ13->paroled[$ckey])
                        && isset($civ13->channel_ids['parole_notif'])
                        && $parole_log_channel = $civ13->discord->getChannel($civ13->channel_ids['parole_notif'])
                    ) {
                        $message2 = '';
                        if (isset($civ13->role_ids['parolemin'])) $message2 .= "<@&{$civ13->role_ids['parolemin']}>, ";
                        $message2 .= "`$ckey` has logged into `$server`";
                        $parole_log_channel->sendMessage($message2);
                    }

                    if (isset($civ13->current_rounds[$server]) && $civ13->current_rounds[$server]) 
                        $civ13->logPlayerLogin($server, $ckey, $time, $data['ip'] ?? '', $data['cid'] ?? '');

                    if (! isset($civ13->channel_ids[$server.'_transit_channel']) || ! $channel_id = $civ13->channel_ids[$server.'_transit_channel']) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $message .= "$ckey connected to the server";
                    if (isset($data['ip'])) {
                        $address = $data['ip'];
                        $message .=  " with IP of $address";
                    }
                    if (isset($data['cid'])) {
                        $computer_id = $data['cid'];
                        $message .= " and CID of $computer_id";
                    }
                    $message .= '.';
                    break;
                case 'logout':
                    $civ13->checkCkey($ckey);
                    // Move this to a function in civ13.php    
                    if (isset($civ13->paroled[$ckey])
                        && isset($civ13->channel_ids['parole_notif'])
                        && $parole_log_channel = $civ13->discord->getChannel($civ13->channel_ids['parole_notif'])
                    ) {
                        $message2 = '';
                        if (isset($civ13->role_ids['parolemin'])) $message2 .= "<@&{$civ13->role_ids['parolemin']}>, ";
                        $message2 .= "`$ckey` has log out of `$server`";
                        $parole_log_channel->sendMessage($message2);
                    }

                    if (isset($civ13->current_rounds[$server]) && $civ13->current_rounds[$server])
                        $civ13->logPlayerLogout($server, $ckey, $time);

                    if (! isset($civ13->channel_ids[$server.'_transit_channel']) || ! $channel_id = $civ13->channel_ids[$server.'_transit_channel']) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $message .= "$ckey disconnected from the server.";
                    break;
                case 'token':
                case 'roundstatus':
                case 'status_update':
                    echo "[DATA FOR {$params['method']}]: "; var_dump($params['data']); echo PHP_EOL;
                    break;
                case 'runtimemessage':
                    if (! isset($civ13->channel_ids[$server.'_runtime_channel'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $channel_id = $civ13->channel_ids[$server.'_runtime_channel'];
                    $message .= "**__{$time} RUNTIME__**: " . strip_tags($data['message']);
                    break;
                case 'alogmessage':
                    if (! isset($civ13->channel_ids[$server.'_adminlog_channel'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $channel_id = $civ13->channel_ids[$server.'_adminlog_channel'];
                    $message .= "**__{$time} ADMIN LOG__**: " . strip_tags($data['message']);
                    break;
                case 'attacklogmessage':
                    if ($server == 'tdm' && ! (! isset($data['ckey2']) || ! $data['ckey2'] || ($data['ckey'] !== $data['ckey2']))) return new Response(200, ['Content-Type' => 'text/html'], 'Done'); // Disabled on TDM, use manual checking of log files instead
                    if (! isset($civ13->channel_ids[$server.'_attack_channel'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $channel_id = $civ13->channel_ids[$server.'_attack_channel'];
                    $message .= "**__{$time} ATTACK LOG__**: " . strip_tags($data['message']);
                    if (isset($data['ckey']) && isset($data['ckey2'])) if ($data['ckey'] && $data['ckey2']) if ($data['ckey'] === $data['ckey2']) $message .= " (Self-Attack)";
                    break;
                default:
                    $civ13->logger->alert("API UNKNOWN METHOD `{$params['method']}` FROM " . $request->getServerParams()['REMOTE_ADDR']);
                    return new Response(400, ['Content-Type' => 'text/plain'], 'Invalid Parameter');
            }
            if ($message && $channel = $civ13->discord->getChannel($channel_id)) {
                if (! $ckey || ! $item = $civ13->verified->get('ss13', $civ13->sanitizeInput(explode('/', $ckey)[0]))) $channel->sendMessage($message);
                elseif ($user = $civ13->discord->users->get('id', $item['discord'])) {
                    $embed = new Embed($civ13->discord);
                    $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
                    $embed->setDescription($message);
                    $channel->sendEmbed($embed);
                } elseif ($item) {
                    $civ13->discord->users->fetch($item['discord']);
                    $channel->sendMessage($message);
                } else $channel->sendMessage($message);
            }
            return new Response(200, ['Content-Type' => 'text/html'], 'Done');

        case 'discord2ckey':
            if (! $id || ! $webapiSnow($id) || !is_numeric($id)) return $webapiFail('user_id', $id);
            if ($discord2ckey = array_shift($civ13->messageHandler->offsetGet('discord2ckey'))) return new Response(200, ['Content-Type' => 'text/plain'], $discord2ckey($civ13, $id));
            break;
            
        default:
            return new Response(501, ['Content-Type' => 'text/plain'], 'Not implemented');
    }
    return new Response(200, ['Content-Type' => 'text/json'], json_encode($return ?? ''));
});
//$webapi->listen($socket); // Moved to civ13.php
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
$webapi->on('error', function (Exception $e, ?\Psr\Http\Message\RequestInterface $request = null) use ($civ13, $socket, &$last_path) {
    if (str_starts_with($e->getMessage(), 'Received request with invalid protocol version')) return; // Ignore this error, it's not important
    $last_path = preg_replace('/(?<=key=)[^&]+/', '********', $last_path);
    $error = 'API ' . $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . '] ' . str_replace('\n', PHP_EOL, $e->getTraceAsString());
    $civ13->logger->error('[webapi] ' . $error);
    if ($request) $civ13->logger->error('[webapi] Request: ' . $request->getRequestTarget());
    if (str_starts_with($e->getMessage(), 'The response callback')) {
        $civ13->logger->info('[RESTART] WEBAPI ERROR');
        if (isset($civ13->channel_ids['staff_bot']) && $channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot'])) {
            $builder = \Discord\Builders\MessageBuilder::new()
                ->setContent('Restarting due to error in HttpServer API...' . PHP_EOL . "Last path: `$last_path`")
                ->addFileFromContent("httpserver_error.txt", $error);
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