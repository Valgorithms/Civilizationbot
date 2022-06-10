<?php
function webapiFail($part, $id) {
	//logInfo('[webapi] Failed', ['part' => $part, 'id' => $id]);
	return new \React\Http\Message\Response(($id ? 404 : 400), ['Content-Type' => 'text/plain'], ($id ? 'Invalid' : 'Missing').' '.$part.PHP_EOL);
}

function webapiSnow($string) {
	return preg_match('/^[0-9]{16,18}$/', $string);
}

$socket = new \React\Socket\Server(sprintf('%s:%s', '0.0.0.0', '55555'), $loop);
$webapi = new \React\Http\Server($loop, function (\Psr\Http\Message\ServerRequestInterface $request) use ($civ13, $socket)
{
    $discord = $civ13->discord;
    $loop = $civ13->loop;
    $nomads_bans = $civ13->files['nomads_bans'];
    $tdm_bans = $civ13->files['tdm_bans'];
    
	$path = explode('/', $request->getUri()->getPath());
	$sub = (isset($path[1]) ? (string) $path[1] : false);
	$id = (isset($path[2]) ? (string) $path[2] : false);
	$id2 = (isset($path[3]) ? (string) $path[3] : false);
	$ip = (isset($path[4]) ? (string) $path[4] : false);
	$idarray = array(); //get from post data
	
	if ($ip) echo '[REQUESTING IP] ' . $ip . PHP_EOL ;
    $whitelist = [
        '127.0.0.1', //local host
        gethostbyname('www.civ13.com'),
        gethostbyname('www.valzargaming.com'),
        '51.254.161.128', //civ13.com
        '76.111.78.31', //valzargaming.com
    ];
	if (substr($request->getServerParams()['REMOTE_ADDR'], 0, 6) != '10.0.0' && ! in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist) ) {
        echo '[WEBAPI] REMOTE_ADDR: ' . $request->getServerParams()['REMOTE_ADDR'] . PHP_EOL;
    }

	switch ($sub) {
        case 'messagetest':
            if ($channel = $discord->getChannel('712685552155230278')) {
                echo "I'm alive!" . PHP_EOL;
                $return = "I'm alive!";
                $channel->sendMessage("I'm alive!");
            }
            break;
        
        case 'botlog':
            if (substr($request->getServerParams()['REMOTE_ADDR'], 0, 6) != '10.0.0' && ! in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist) ) { //Restricted for obvious reasons
				echo '[REJECT] ' . $request->getServerParams()['REMOTE_ADDR'] . PHP_EOL;
				return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], 'Reject'.PHP_EOL);
			}
            if ($return = file_get_contents('botlog.txt')) return new \React\Http\Message\Response(200, ['Content-Type' => 'text/plain'], $return.PHP_EOL);
            else return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], "Unable to access `botlog.txt`".PHP_EOL);
            break;
        
        case 'nomads_bans':
            if (substr($request->getServerParams()['REMOTE_ADDR'], 0, 6) != '10.0.0' && ! in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist) ) { //Restricted for obvious reasons
				echo '[REJECT] ' . $request->getServerParams()['REMOTE_ADDR'] . PHP_EOL;
				return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], 'Reject'.PHP_EOL);
			}
            if ($return = file_get_contents($nomads_bans)) return new \React\Http\Message\Response(200, ['Content-Type' => 'text/plain'], $return.PHP_EOL);
            else return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], "Unable to access `$nomads_bans`".PHP_EOL);
            break;
        
        case 'tdm_bans':
            if (substr($request->getServerParams()['REMOTE_ADDR'], 0, 6) != '10.0.0' && ! in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist) ) { //Restricted for obvious reasons
				echo '[REJECT] ' . $request->getServerParams()['REMOTE_ADDR'] . PHP_EOL;
				return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], 'Reject'.PHP_EOL);
			}
            if ($return = file_get_contents($tdm_bans)) return new \React\Http\Message\Response(200, ['Content-Type' => 'text/plain'], $return.PHP_EOL);
            else return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], "Unable to access `$tdm_bans`".PHP_EOL);
            break;
        
		case 'channel':
			if (!$id || !webapiSnow($id) || !$return = $discord->getChannel($id))
				return webapiFail('channel_id', $id);
			break;

		case 'guild':
			if (!$id || !webapiSnow($id) || !$return = $discord->guilds->offsetGet($id))
				return webapiFail('guild_id', $id);
			break;

		case 'bans':
			if (!$id || !webapiSnow($id) || !$guild = $discord->guilds->offsetGet($id))
				return webapiFail('guild_id', $id);
			$return = $guild->bans;
			break;

		case 'channels':
			if (!$id || !webapiSnow($id) || !$guild = $discord->guilds->offsetGet($id))
				return webapiFail('guild_id', $id);
			$return = $guild->channels;
			break;

		case 'members':
			if (!$id || !webapiSnow($id) || !$guild = $discord->guilds->offsetGet($id))
				return webapiFail('guild_id', $id);
			$return = $guild->members;
			break;

		case 'emojis':
			if (!$id || !webapiSnow($id) || !$guild = $discord->guilds->offsetGet($id))
				return webapiFail('guild_id', $id);
			$return = $guild->emojis;
			break;

		case 'invites':
			if (!$id || !webapiSnow($id) || !$guild = $discord->guilds->offsetGet($id))
				return webapiFail('guild_id', $id);
			$return = $guild->invites;
			break;

		case 'roles':
			if (!$id || !webapiSnow($id) || !$guild = $discord->guilds->offsetGet($id))
				return webapiFail('guild_id', $id);
			$return = $guild->roles;
			break;

		case 'guildMember':
			if (!$id || !webapiSnow($id) || !$guild = $discord->guilds->offsetGet($id))
				return webapiFail('guild_id', $id);
			if (!$id2 || !webapiSnow($id2) || !$return = $guild->members->offsetGet($id2))
				return webapiFail('user_id', $id2);
			break;

		case 'user':
			if (!$id || !webapiSnow($id) || !$return = $discord->users->offsetGet($id)) {
				return webapiFail('user_id', $id);
			}
			break;

		case 'userName':
			if (!$id || !$return = $discord->users->get('name', $id))
				return webapiFail('user_name', $id);
			break;
        
        
        case 'pull':
            if (substr($request->getServerParams()['REMOTE_ADDR'], 0, 6) != '10.0.0' && ! in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist) ) { //Restricted for obvious reasons
				echo '[REJECT] ' . $request->getServerParams()['REMOTE_ADDR'] . PHP_EOL;
				return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], 'Reject'.PHP_EOL);
			}
            execInBackground('sudo git pull');
            $return = 'updating civilizationbot';
            break;
        
        case 'update':
            if (substr($request->getServerParams()['REMOTE_ADDR'], 0, 6) != '10.0.0' && ! in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist) ) { //Restricted for obvious reasons
				echo '[REJECT] ' . $request->getServerParams()['REMOTE_ADDR'] . PHP_EOL;
				return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], 'Reject'.PHP_EOL);
			}
            execInBackground('sudo composer update');
            $return = 'updating dependencies';
            break;
        
		case 'restart':
			if (substr($request->getServerParams()['REMOTE_ADDR'], 0, 6) != '10.0.0' && ! in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist) ) { //Restricted for obvious reasons
				echo '[REJECT] ' . $request->getServerParams()['REMOTE_ADDR'] . PHP_EOL;
				return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], 'Reject'.PHP_EOL);
			}
            if ($channel = $discord->getChannel('712685552155230278')) {
                echo '[RESTART]' . PHP_EOL;
                $channel->sendMessage("Restarting...");
            }
            $return = 'restarting';
			execInBackground('sudo nohup php8.1 bot.php > botlog.txt &');
            $socket->close();
            $discord->close();
			break;

		case 'lookup':
			if (substr($request->getServerParams()['REMOTE_ADDR'], 0, 6) != '10.0.0' && ! in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist) ) {
				echo '[REJECT] ' . $request->getServerParams()['REMOTE_ADDR'] . PHP_EOL;
				return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], 'Reject'.PHP_EOL);
			}
			if (!$id || !webapiSnow($id) || !$return = $discord->users->offsetGet($id))
				return webapiFail('user_id', $id);
			break;

		case 'owner':
			if (substr($request->getServerParams()['REMOTE_ADDR'], 0, 6) != '10.0.0' && ! in_array($request->getServerParams()['REMOTE_ADDR'], $whitelist) ) {
				echo '[REJECT] ' . $request->getServerParams()['REMOTE_ADDR'] . PHP_EOL;
				return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], 'Reject'.PHP_EOL);
			}
			if (!$id || !webapiSnow($id))
				return webapiFail('user_id', $id);
			$return = false;
			if ($user = $discord->users->offsetGet($id)) { //Search all guilds the bot is in and check if the user id exists as a guild owner
				foreach ($discord->guilds as $guild) {
					if ($id == $guild->owner_id) {
						$return = true;
						break 1;
					}
				}
			}
			break;

		case 'avatar':
			if (!$id || !webapiSnow($id)) {
				return webapiFail('user_id', $id);
			}
			if (!$user = $discord->users->offsetGet($id)) {
				$discord->users->fetch($id)->done(
					function ($user) {
						$return = $user->avatar;
						return new \React\Http\Message\Response(200, ['Content-Type' => 'text/plain'], $return);
					}, function ($error) {
						return webapiFail('user_id', $id);
					}
				);
				$return = 'https://cdn.discordapp.com/embed/avatars/'.rand(0,4).'.png';
			}else{
				$return = $user->avatar;
			}
			//if (!$return) return new \React\Http\Message\Response(($id ? 404 : 400), ['Content-Type' => 'text/plain'], ('').PHP_EOL);
			break;

		case 'avatars':
			$idarray = $data ?? array(); // $data contains POST data
			$results = [];
			$promise = $discord->users->fetch($idarray[0])->then(function ($user) use (&$results) {
			  $results[$user->id] = $user->avatar;
			});
			
			for ($i = 1; $i < count($idarray); $i++) {
			  $promise->then(function () use (&$results, $idarray, $i, $discord) {
				return $discord->users->fetch($idarray[$i])->then(function ($user) use (&$results) {
				  $results[$user->id] = $user->avatar;
				});
			  });
			}

			$promise->done(function () use ($results) {
			  return new \React\Http\Message\Response (200, ['Content-Type' => 'application/json'], json_encode($results));
			}, function () use ($results) {
			  // return with error ?
			  return new \React\Http\Message\Response(200, ['Content-Type' => 'application/json'], json_encode($results));
			});
			break;
		default:
			return new \React\Http\Message\Response(501, ['Content-Type' => 'text/plain'], 'Not implemented'.PHP_EOL);
	}
	return new \React\Http\Message\Response(200, ['Content-Type' => 'text/json'], json_encode($return));
});
$webapi->listen($socket);
$webapi->on('error', function ($e) {
	echo('[webapi] ' . $e->getMessage());
});