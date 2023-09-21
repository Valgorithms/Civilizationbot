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

$webapi = new HttpServer($loop, function (ServerRequestInterface $request) use ($civ13, $port, $socket, $vzg_ip, $civ13_ip, $external_ip, $webhook_key, $portknock, $portknock_ips, $max_attempts, $webapiFail, $webapiSnow)
{
    if ($response = $civ13->httpHandler->handle($request)) return $response;
    return new Response(500, ['Content-Type' => 'application/json'], json_encode(['error' => 'error']));

    
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
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->getChannel($id)) return $webapiFail('channel_id', $id);
            break;

        case 'guild':
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)) return $webapiFail('guild_id', $id);
            break;

        case 'bans':
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->bans) return $webapiFail('guild_id', $id);
            break;

        case 'channels':
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->channels) return $webapiFail('guild_id', $id);
            break;

        case 'members':
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->members) return $webapiFail('guild_id', $id);
            break;

        case 'emojis':
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->emojis) return $webapiFail('guild_id', $id);
            break;

        case 'invites':
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->invites) return $webapiFail('guild_id', $id);
            break;

        case 'roles':
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->guilds->get('id', $id)->roles) return $webapiFail('guild_id', $id);
            break;

        case 'guildMember':
            if (! $id || !$webapiSnow($id) || ! $guild = $civ13->discord->guilds->get('id', $id)) return $webapiFail('guild_id', $id);
            if (! $id2 || !$webapiSnow($id2) || ! $return = $guild->members->get('id', $id2)) return $webapiFail('user_id', $id2);
            break;

        case 'user':
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->users->get('id', $id)) return $webapiFail('user_id', $id);
            break;

        case 'userName':
            if (! $id || ! $return = $civ13->discord->users->get('name', $id)) return $webapiFail('user_name', $id);
            break;

        case 'lookup':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if (! $id || !$webapiSnow($id) || ! $return = $civ13->discord->users->get('id', $id)) return $webapiFail('user_id', $id);
            break;

        case 'owner':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if (! $id || !$webapiSnow($id)) return $webapiFail('user_id', $id); $return = false;
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
            if (! $id || !$webapiSnow($id)) return $webapiFail('user_id', $id);
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

        case 'discord2ckey':
            if (! $id || !$webapiSnow($id) || !is_numeric($id)) return $webapiFail('user_id', $id);
            if ($discord2ckey = array_shift($civ13->messageHandler->offsetGet('discord2ckey'))) return new Response(200, ['Content-Type' => 'text/plain'], $discord2ckey($civ13, $id));
            break;
            
        default:
            return new Response(501, ['Content-Type' => 'text/plain'], 'Not implemented');
    }
    return new Response(200, ['Content-Type' => 'text/json'], json_encode($return ?? ''));
});
//$webapi->listen($socket); // Moved to civ13.php
$webapi->on('error', function (Exception $e, ?\Psr\Http\Message\RequestInterface $request = null) use ($civ13, $socket) {
    $error = 'API ' . $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . '] ' . str_replace('\n', PHP_EOL, $e->getTraceAsString());
    $civ13->logger->error('[webapi] ' . $error);
    if ($request) $civ13->logger->error('[webapi] Request: ' . $request->getRequestTarget());
    if (str_starts_with($e->getMessage(), 'The response callback')) {
        $civ13->logger->info('[RESTART] WEBAPI ERROR');
        if (isset($civ13->channel_ids['staff_bot']) && $channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot'])) {
            $builder = \Discord\Builders\MessageBuilder::new()
                ->setContent('Restarting due to error in HttpServer API...')
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