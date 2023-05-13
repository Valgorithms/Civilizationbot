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
use \Psr\Http\Message\ServerRequestInterface;

if (! include 'webhook_key.php') $webhook_key = 'CHANGEME'; //The token is used to verify that the sender is legitimate and not a malicious actor

function webapiFail($part, $id) {
    //logInfo('[webapi] Failed', ['part' => $part, 'id' => $id]);
    return new Response(($id ? 404 : 400), ['Content-Type' => 'text/plain'], ($id ? 'Invalid' : 'Missing').' '.$part);
}

function webapiSnow($string) {
    return preg_match('/^[0-9]{16,20}$/', $string);
}

$external_ip = file_get_contents('http://ipecho.net/plain');
$valzargaming_ip = gethostbyname('www.valzargaming.com');

$socket = new SocketServer(sprintf('%s:%s', '0.0.0.0', '55555'), [], $civ13->loop);
$webapi = new HttpServer($loop, function (ServerRequestInterface $request) use ($civ13, $socket, $external_ip, $valzargaming_ip, $webhook_key)
{
    /*
    $path = explode('/', $request->getUri()->getPath());
    $sub = (isset($path[1]) ? (string) $path[1] : false);
    $id = (isset($path[2]) ? (string) $path[2] : false);
    $id2 = (isset($path[3]) ? (string) $path[3] : false);
    $ip = (isset($path[4]) ? (string) $path[4] : false);
    $idarray = array(); //get from post data (NYI)
    */
    
    $echo = 'API ';
    $sub = 'index.';
    $path = explode('/', $request->getUri()->getPath());
    $repository = $sub = (isset($path[1]) ? (string) strtolower($path[1]) : false); if ($repository) $echo .= "$repository";
    $method = $id = (isset($path[2]) ? (string) strtolower($path[2]) : false); if ($method) $echo .= "/$method";
    $id2 = $repository2 = (isset($path[3]) ? (string) strtolower($path[3]) : false); if ($id2) $echo .= "/$id2";
    $ip = $partial = $method2 = (isset($path[4]) ? (string) strtolower($path[4]) : false); if ($partial) $echo .= "/$partial";
    $id3 = (isset($path[5]) ? (string) strtolower($path[5]) : false); if ($id3) $echo .= "/$id3";
    $id4 = (isset($path[6]) ? (string) strtolower($path[6]) : false); if ($id4) $echo .= "/$id4";
    $idarray = array(); //get from post data (NYI)
    //$civ13->logger->info($echo);
    
    if ($ip) $civ13->logger->info('API IP ' . $ip);
    $whitelist = [
        '127.0.0.1',
        $external_ip,
        $valzargaming_ip,
        '51.254.161.128',
        '69.244.83.231',
    ];
    $substr_whitelist = ['10.0.0.', '192.168.']; 
    $whitelisted = false;
    foreach ($substr_whitelist as $substr) if (substr($request->getServerParams()['REMOTE_ADDR'], 0, strlen($substr)) == $substr) $whitelisted = true;
    if (in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist)) $whitelisted = true;
    
    if (! $whitelisted) $civ13->logger->info('API REMOTE_ADDR ' . $request->getServerParams()['REMOTE_ADDR']);

    switch ($sub) {
        case (str_starts_with($sub, 'index.')):
            $return = '<meta http-equiv = \"refresh\" content = \"0; url = https://www.valzargaming.com/?login\" />'; //Redirect to the website to log in
            return new Response(200, ['Content-Type' => 'text/html'], $return);
            break;
        case 'github':
            $return = '<meta http-equiv = \"refresh\" content = \"0; url = https://github.com/VZGCoders/Civilizationbot\" />'; //Redirect to the website to log in
            return new Response(200, ['Content-Type' => 'text/html'], $return);
            break;
        case 'favicon.ico':
            if (! $whitelisted) {
                $civ13->logger->info('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            $favicon = file_get_contents('favicon.ico');
            return new Response(200, ['Content-Type' => 'image/x-icon'], $favicon);
        
        case 'nohup.out':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if ($return = file_get_contents('nohup.out')) return new Response(200, ['Content-Type' => 'text/plain'], $return);
            else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `nohup.out`");
            break;
        
        case 'botlog':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if ($return = file_get_contents('botlog.txt')) return new Response(200, ['Content-Type' => 'text/html'], '<meta name="color-scheme" content="light dark"> <div class="checkpoint">' . str_replace('[' . date("Y"), '</div><div> [' . date("Y"), str_replace([PHP_EOL, '[] []', ' [] '], '</div><div>', $return)) . "</div><script>var mainScrollArea=document.getElementsByClassName('checkpoint')[0];var scrollTimeout;window.onload=function(){if(window.location.href==localStorage.getItem('lastUrl')){mainScrollArea.scrollTop=localStorage.getItem('scrollTop');}else{localStorage.setItem('lastUrl',window.location.href);localStorage.setItem('scrollTop',0);}};mainScrollArea.addEventListener('scroll',function(){clearTimeout(scrollTimeout);scrollTimeout=setTimeout(function(){localStorage.setItem('scrollTop',mainScrollArea.scrollTop);},100);});setTimeout(locationreload,10000);function locationreload(){location.reload();}</script>");
            else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `botlog.txt`");
            break;
            
        case 'botlog2':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if ($return = file_get_contents('botlog2.txt')) return new Response(200, ['Content-Type' => 'text/html'], '<meta name="color-scheme" content="light dark"> <div class="checkpoint">' . str_replace('[' . date("Y"), '</div><div> [' . date("Y"), str_replace([PHP_EOL, '[] []', ' [] '], '</div><div>', $return)) . "</div><script>var mainScrollArea=document.getElementsByClassName('checkpoint')[0];var scrollTimeout;window.onload=function(){if(window.location.href==localStorage.getItem('lastUrl')){mainScrollArea.scrollTop=localStorage.getItem('scrollTop');}else{localStorage.setItem('lastUrl',window.location.href);localStorage.setItem('scrollTop',0);}};mainScrollArea.addEventListener('scroll',function(){clearTimeout(scrollTimeout);scrollTimeout=setTimeout(function(){localStorage.setItem('scrollTop',mainScrollArea.scrollTop);},100);});setTimeout(locationreload,10000);function locationreload(){location.reload();}</script>");
            else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `botlog2.txt`");
            break;
        
        case 'channel':
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->getChannel($id)) return webapiFail('channel_id', $id);
            break;

        case 'guild':
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->guilds->get('id', $id)) return webapiFail('guild_id', $id);
            break;

        case 'bans':
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->guilds->get('id', $id)->bans) return webapiFail('guild_id', $id);
            break;

        case 'channels':
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->guilds->get('id', $id)->channels) return webapiFail('guild_id', $id);
            break;

        case 'members':
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->guilds->get('id', $id)->members) return webapiFail('guild_id', $id);
            break;

        case 'emojis':
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->guilds->get('id', $id)->emojis) return webapiFail('guild_id', $id);
            break;

        case 'invites':
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->guilds->get('id', $id)->invites) return webapiFail('guild_id', $id);
            break;

        case 'roles':
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->guilds->get('id', $id)->roles) return webapiFail('guild_id', $id);
            break;

        case 'guildMember':
            if (! $id || !webapiSnow($id) || !$guild = $civ13->discord->guilds->get('id', $id)) return webapiFail('guild_id', $id);
            if (! $id2 || !webapiSnow($id2) || !$return = $guild->members->get('id', $id2)) return webapiFail('user_id', $id2);
            break;

        case 'user':
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->users->get('id', $id)) return webapiFail('user_id', $id);
            break;

        case 'userName':
            if (! $id || !$return = $civ13->discord->users->get('name', $id)) return webapiFail('user_name', $id);
            break;
        
        case 'reset':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            execInBackground('git reset --hard origin/main');
            if (isset($civ13->channel_ids['staff_bot']) && $channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot'])) $channel->sendMessage('Forcefully moving the HEAD back to origin/main...');
            $return = 'fixing git';
            break;
        
        case 'pull':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            execInBackground('git pull');
            $civ13->logger->info('[GIT PULL]');
            if (isset($civ13->channel_ids['staff_bot']) && $channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot'])) $channel->sendMessage('Updating code from GitHub...');
            $return = 'updating code';
            break;
        
        case 'update':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            execInBackground('composer update');
            $civ13->logger->info('[COMPOSER UPDATE]');
            if (isset($civ13->channel_ids['staff_bot']) && $channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot'])) $channel->sendMessage('Updating dependencies...');
            $return = 'updating dependencies';
            break;
        
        case 'restart':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            $civ13->logger->info('[RESTART]');
            if (isset($civ13->channel_ids['staff_bot']) && $channel = $civ13->discord->getChannel($civ13->channel_ids['staff_bot'])) $channel->sendMessage('Restarting...');
            $return = 'restarting';
            $socket->close();
            $civ13->discord->getLoop()->addTimer(5, function () use ($civ13) {
                \restart();
                $civ13->discord->close();
                die();
            });
            break;

        case 'lookup':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if (! $id || !webapiSnow($id) || !$return = $civ13->discord->users->get('id', $id)) return webapiFail('user_id', $id);
            break;

        case 'owner':
            if (! $whitelisted) {
                $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
            }
            if (! $id || !webapiSnow($id)) return webapiFail('user_id', $id); $return = false;
            if ($user = $civ13->discord->users->get('id', $id)) { //Search all guilds the bot is in and check if the user id exists as a guild owner
                foreach ($civ13->discord->guilds as $guild) {
                    if ($id == $guild->owner_id) {
                        $return = true;
                        break 1;
                    }
                }
            }
            break;

        case 'avatar':
            if (! $id || !webapiSnow($id)) return webapiFail('user_id', $id);
            if (! $user = $civ13->discord->users->get('id', $id)) $return = 'https://cdn.discordapp.com/embed/avatars/'.rand(0,4).'.png';
            else $return = $user->avatar;
            //if (! $return) return new Response(($id ? 404 : 400), ['Content-Type' => 'text/plain'], (''));
            break;

        case 'avatars': //This needs to be optimized to not use async code
            /*
            $idarray = $data ?? array(); // $data contains POST data
            $results = [];
            $promise = $civ13->discord->users->fetch($idarray[0])->then(function ($user) use (&$results) {
              $results[$user->id] = $user->avatar;
            });
            
            for ($i = 1; $i < count($idarray); $i++) {
                $discord = $civ13->discord;
                $promise->then(function () use (&$results, $idarray, $i, $discord) {
                return $civ13->discord->users->fetch($idarray[$i])->then(function ($user) use (&$results) {
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
            $server =& $method; //alias for readability
            if (!isset($civ13->channel_ids[$server.'_debug_webhook_channel'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
            $channel_id = $civ13->channel_ids[$server.'_debug_webhook_channel'];
            $params = $request->getQueryParams();
            var_dump($params);
            if (! $whitelisted && (!isset($params['key']) || $params['key'] != $webhook_key)) return new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized');
            if (!isset($params['method']) || !isset($params['data'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Missing Parameters');
            $data = json_decode($params['data'], true);
            $time = '['.date('H:i:s', time()).']';
            $message = '';
            $ckey = '';
            switch ($params['method']) {
                case 'ahelpmessage':
                    $message .= "**__{$time} AHELP__ {$data['ckey']}**: " . html_entity_decode(urldecode($data['message']));
                    $ckey = str_replace(['.', '_', ' '], '', strtolower($data['ckey']));
                    break;
                case 'asaymessage':
                    if (!isset($civ13->channel_ids[$server.'_asay_webhook_channel'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $channel_id = $civ13->channel_ids[$server.'_asay_webhook_channel'];
                    $message .= "**__{$time} ASAY__ {$data['ckey']}**: " . html_entity_decode(urldecode($data['message']));
                    $ckey = str_replace(['.', '_', ' '], '', strtolower($data['ckey']));
                    break;
                case 'lobbymessage':
                    $message .= "**__{$time} LOBBY__ {$data['ckey']}**: " . html_entity_decode(urldecode($data['message']));
                    $ckey = str_replace(['.', '_', ' '], '', strtolower($data['ckey']));
                    break;
                case 'oocmessage':
                    if (!isset($civ13->channel_ids[$server.'_ooc_webhook_channel'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $channel_id = $civ13->channel_ids[$server.'_ooc_webhook_channel'];
                    $message .= "**__{$time} OOC__ {$data['ckey']}**: " . html_entity_decode(urldecode($data['message']));
                    $ckey = str_replace(['.', '_', ' '], '', strtolower($data['ckey']));
                    break;
                case 'memessage':
                    if (isset($data['message'])) $message .= "**__{$time} EMOTE__ {$data['ckey']}** " . html_entity_decode(urldecode($data['message']));
                    $ckey = str_replace(['.', '_', ' '], '', strtolower($data['ckey']));
                    break;
                case 'garbage':
                    $message .= "**__{$time} GARBAGE__ {$data['ckey']}**: " . html_entity_decode(strip_tags($data['message']));
                    //$ckey = str_replace(['.', '_', ' '], '', strtolower($data['ckey']));
                    $arr = explode(' ', strip_tags($data['message']));
                    $trigger = $arr[3];
                    if ($trigger == 'logout:') $ckey = explode('/', $arr[4])[0];
                    elseif ($trigger == 'login:') $ckey = explode('/', $arr[4])[0];
                    else $ckey = explode('/', substr(strip_tags($data['message']), 4))[0];
                    break;
                case 'respawn_notice':
                    //if (isset($civ13->role_ids['respawn_notice'])) $message .= "<@&{$civ13->role_ids['respawn_notice']}>, ";
                    $message .= html_entity_decode(urldecode($data['message']));
                    break;
                case 'login':
                    $message .= "{$data['ckey']} logged in.";
                    $ckey = str_replace(['.', '_', ' '], '', strtolower($data['ckey']));
                    break;
                case 'logout':
                    if (!isset($civ13->channel_ids[$server.'_transit_webhook_channel'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $channel_id = $civ13->channel_ids[$server.'_transit_webhook_channel'];
                    $message .= "{$data['ckey']} logged out.";
                    $ckey = strtolower(str_replace(['.', '_', ' '], '', explode('[DC]', $data['ckey'])[0]));
                    break;
                case 'token':
                case 'roundstatus':
                case 'status_update':
                    echo "[DATA FOR {$params['method']}]: "; var_dump($params['data']); echo PHP_EOL;
                    break;
                case 'runtimemessage':
                    $message .= "**__{$time} RUNTIME__**: " . strip_tags($data['message']);
                    $trigger = explode(' ', $data['message'])[1];
                    if ($trigger == 'ListVarEdit') $ckey = str_replace(['.', '_', ' '], '', explode(':', strtolower(substr($data['message'], 8+strlen('ListVarEdit'))))[0]);
                    elseif ($trigger == 'VarEdit') $ckey = str_replace(['.', '_', ' '], '', explode('/', strtolower(substr($data['message'], 8+strlen('VarEdit'))))[0]);
                    break;
                case 'alogmessage':
                    $message .= "**__{$time} ADMIN LOG__**: " . strip_tags($data['message']);
                    break;
                case 'attacklogmessage':
                    if (!isset($civ13->channel_ids[$server.'_attack_webhook_channel'])) return new Response(400, ['Content-Type' => 'text/plain'], 'Webhook Channel Not Defined');
                    $channel_id = $civ13->channel_ids[$server.'_attack_webhook_channel'];
                    $message .= "**__{$time} ATTACK LOG__**: " . strip_tags($data['message']);
                    break;
                default:
                    return new Response(400, ['Content-Type' => 'text/plain'], 'Invalid Parameter');
            }
            if ($message && $channel = $civ13->discord->getChannel($channel_id)) {
                if (! $ckey || ! $item = $civ13->verified->get('ss13', strtolower(str_replace(['.', '_', ' '], '', explode('/', $ckey)[0])))) $channel->sendMessage($message);
                elseif ($user = $civ13->discord->users->get('id', $item['discord'])) {
                    $embed = new Embed($civ13->discord);
                    $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
                    $embed->setDescription($message);
                    $channel->sendEmbed($embed);
                } elseif($item) {
                    $civ13->discord->users->fetch('id', $item['discord']);
                    $channel->sendMessage($message);
                } else {
                    $channel->sendMessage($message);
                }
            }
            return new Response(200, ['Content-Type' => 'text/html'], 'Done');

        case 'nomads':
            switch ($id) {
                case 'bans':
                    if (! $whitelisted) {
                        $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                        return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
                    }
                    $nomads_bans = $civ13->files['nomads_bans'];
                    if ($return = file_get_contents($nomads_bans)) return new Response(200, ['Content-Type' => 'text/plain'], $return);
                    else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `$nomads_bans`");
                    break;
                case 'playerlogs':
                    if (! $whitelisted) {
                        $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                        return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
                    }
                    $nomads_playerlogs = $civ13->files['nomads_playerlogs'];
                    if ($return = file_get_contents($nomads_playerlogs)) return new Response(200, ['Content-Type' => 'text/plain'], $return);
                    else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `$nomads_playerlogs`");
            }
            break;
        case 'tdm':
            switch ($id) {
                case 'bans':
                    if (! $whitelisted) {
                        $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                        return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
                    }
                    $tdm_bans = $civ13->files['tdm_bans'];
                    if ($return = file_get_contents($tdm_bans)) return new Response(200, ['Content-Type' => 'text/plain'], $return);
                    else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `$tdm_bans`");
                    break;
                case 'playerlogs':
                    if (! $whitelisted) {
                        $civ13->logger->alert('API REJECT ' . $request->getServerParams()['REMOTE_ADDR']);
                        return new Response(501, ['Content-Type' => 'text/plain'], 'Reject');
                    }
                    $tdm_playerlogs = $civ13->files['tdm_playerlogs'];
                    if ($return = file_get_contents($tdm_playerlogs)) return new Response(200, ['Content-Type' => 'text/plain'], $return);
                    else return new Response(501, ['Content-Type' => 'text/plain'], "Unable to access `$tdm_playerlogs`");
                default:
                    return new Response(501, ['Content-Type' => 'text/plain'], 'Not implemented');
            }
            break;
        
        case 'discord2ckey':
            if (! $id || !webapiSnow($id) || !is_numeric($id)) return webapiFail('user_id', $id);
            $discord2ckey = $civ13->functions['misc']['discord2ckey'];
            $return = $discord2ckey($civ13, $id);
            return new Response(200, ['Content-Type' => 'text/plain'], $return);
            break;
            
        case 'verified':
            return new Response(200, ['Content-Type' => 'text/plain'], json_encode($civ13->verified->toArray()));
            break;
            
        default:
            return new Response(501, ['Content-Type' => 'text/plain'], 'Not implemented');
    }
    return new Response(200, ['Content-Type' => 'text/json'], json_encode($return));
});
$webapi->listen($socket);
$webapi->on('error', function ($e) use ($civ13) {
    $civ13->logger->error('API ' . $e->getMessage());
});