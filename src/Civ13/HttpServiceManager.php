<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2022-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13;

use Discord\Builders\MessageBuilder;
use Discord\DiscordWebAuth;
use Discord\Parts\Embed\Embed;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response as HttpResponse;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

class HttpServiceManager
{
    const HTMLDIR = '/html';
    readonly string $basedir;

    public Civ13 $civ13;
    public HttpHandler $httpHandler;
    public HttpServer $webapi;
    public SocketServer $socket;
    public string $web_address;
    public int $http_port;

    protected array $dwa_sessions = [];
    protected array $dwa_timers = [];
    protected array $dwa_discord_ids = [];

    public function __construct(Civ13 &$civ13) {
        $this->civ13 = $civ13;
        $this->httpHandler = new HttpHandler($this->civ13, [], $this->civ13->options['http_whitelist'] ?? [], $this->civ13->options['http_key'] ?? '');
        $this->basedir = getcwd();
        $this->__afterConstruct();
    }

    public function __destruct() {
        if (isset($this->socket)) $this->socket->close();
    }

    /*
    * This function is called after the constructor is finished.
    * It is used to load the files, start the timers, and start handling events.
    */
    protected function __afterConstruct()
    {
        $this->__populateWhitelist();
        $this->__generateEndpoints();
        $this->civ13->logger->debug('[HTTP COMMAND LIST] ' . PHP_EOL . $this->httpHandler->generateHelp());
    }

    public function handle(ServerRequestInterface $request): HttpResponse
    {
        return $this->httpHandler->handle($request);
    }

    public function offsetSet(int|string $offset, callable $callback, ?bool $whitelisted = false,  ?string $method = 'exact', ?string $description = ''): HttpHandler
    {
        return $this->httpHandler->offsetSet($offset, $callback, $whitelisted, $method, $description);
    }
    public function setRateLimit(string $endpoint, int $limit, int $window): HttpHandler
    {
        return $this->httpHandler->setRateLimit($endpoint, $limit, $window);
    }

    private function __populateWhitelist()
    {
        if ($this->httpHandler && $this->civ13->civ13_guild_id && $guild = $this->civ13->discord->guilds->get('id', $this->civ13->civ13_guild_id)) { // Whitelist the IPs of all High Staff
            $members = $guild->members->filter(function ($member) {
                return $member->roles->has($this->civ13->role_ids['High Staff']);
            });
            foreach ($members as $member)
                if ($item = $this->civ13->verifier->getVerifiedItem($member))
                    if (isset($item['ss13']) && $ckey = $item['ss13'])
                        if ($playerlogs = $this->civ13->getCkeyLogCollections($ckey)['playerlogs'])
                            foreach ($playerlogs as $log)
                                if (isset($log['ip']))
                                    $this->httpHandler->whitelist($log['ip']);
        }
    }

    private function __generateEndpoints()
    {
        $this->civ13->discord->once('ready', function () {
            if (! isset($this->civ13->options['webapi'], $this->civ13->options['socket'], $this->civ13->options['web_address'], $this->civ13->options['http_port'])) {
                $this->civ13->logger->warning('HttpServer API not set up! Missing variables in options.');
                $this->civ13->logger->warning('Missing webapi variable: ' . (isset($this->civ13->options['webapi']) ? 'false' : 'true'));
                $this->civ13->logger->warning('Missing socket variable: ' . (isset($this->civ13->options['socket']) ? 'false' : 'true'));
                $this->civ13->logger->warning('Missing web_address variable: ' . (isset($this->civ13->options['web_address']) ? 'false' : 'true'));
                $this->civ13->logger->warning('Missing http_port variable: ' . (isset($this->civ13->options['http_port']) ? 'false' : 'true'));
                return;
            }
            $this->civ13->logger->info('------');
            $this->civ13->logger->info('setting up HttpServer API');
            $this->civ13->logger->info('------');
            $this->webapi = $this->civ13->options['webapi'];
            $this->socket = $this->civ13->options['socket'];
            $this->web_address = $this->civ13->options['web_address'];
            $this->http_port = $this->civ13->options['http_port'];
            $this->webapi->listen($this->socket);

            $this->httpHandler->offsetSet('/get-channels', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                $doc = new \DOMDocument();
                $html = $doc->createElement('html');
                $body = $doc->createElement('body');

                // Create input box
                $input = $doc->createElement('input');
                $input->setAttribute('type', 'text');
                $input->setAttribute('placeholder', 'Enter message');
                $input->setAttribute('style', 'margin-left: 10px;');
                $input->setAttribute('id', 'message-input');
                $body->appendChild($input);
                
                $h2 = $doc->createElement('h2', 'Guilds');
                $body->appendChild($h2);
                // CSS for .guild class
                $guildStyle = $doc->createElement('style', '.guild { margin-bottom: 20px; }');
                $html->appendChild($guildStyle);

                foreach ($this->civ13->discord->guilds as $guild) {
                    $guildDiv = $doc->createElement('div');
                    $guildDiv->setAttribute('class', 'guild');
                    $guildName = $doc->createElement('h3');
                    $a = $doc->createElement('a', $guild->name);
                    $a->setAttribute('href', 'https://discord.com/channels/' . $guild->id);
                    $a->setAttribute('target', '_blank');
                    $guildName->appendChild($a);
                    $guildDiv->appendChild($guildName);

                    // CSS for .channel class
                    $channelStyle = $doc->createElement('style', '.channel { margin-left: 20px; }');
                    $guildDiv->appendChild($channelStyle);
                    
                    $channels = [];
                    foreach ($guild->channels as $channel) if ($channel->isTextBased()) $channels[] = $channel;

                    usort($channels, function ($a, $b) {
                        return $a->position - $b->position;
                    });                

                    foreach ($channels as $channel) {
                        $channelDiv = $doc->createElement('div');
                        $channelDiv->setAttribute('class', 'channel');

                        $channelName = $doc->createElement('div');
                        $channelSpan = $doc->createElement('span');
                        $a = $doc->createElement('a', $channel->name);
                        $a->setAttribute('href', 'https://discord.com/channels/' . $guild->id . '/' . $channel->id);
                        $a->setAttribute('target', '_blank');
                        $channelSpan->appendChild($a);
                        $channelName->appendChild($channelSpan);

                        // Create button and input box
                        $button = $doc->createElement('button', 'Send Message');
                        $button->setAttribute('onclick', "sendMessage('{$channel->id}')");
                        $button2 = $doc->createElement('button', 'Send Embed');
                        $button2->setAttribute('onclick', "sendEmbed('{$channel->id}')");
                        $channelName->appendChild($doc->createTextNode(' ')); // Add space here
                        $channelName->appendChild($button);
                        $channelName->appendChild($button2);

                        $channelDiv->appendChild($channelName);
                        $guildDiv->appendChild($channelDiv);
                    }

                    $body->appendChild($guildDiv);
                }

                // Create javascript function for /send-message
                $script = $doc->createElement('script', '
                    function sendMessage(channelId) {
                        var input = document.querySelector(`#message-input`);
                        var message = input.value;
                        input.value = \'\';
                        fetch("/send-message?channel=" + encodeURIComponent(channelId) + "&message=" + encodeURIComponent(message))
                            .then(response => response.json())
                            .then(data => console.log(data))
                            .catch(error => console.error(error));
                    }
                ');
                $body->appendChild($script);
                // Create javascript function for /send-embed
                $script = $doc->createElement('script', '
                    function sendEmbed(channelId) {
                        var input = document.querySelector(`#message-input`);
                        var message = input.value;
                        input.value = \'\';
                        fetch("/send-embed?channel=" + encodeURIComponent(channelId) + "&message=" + encodeURIComponent(message))
                            .then(response => response.json())
                            .then(data => console.log(data))
                            .catch(error => console.error(error));
                    }
                ');
                $body->appendChild($script);
                
                $html->appendChild($body);
                $doc->appendChild($html);
                return HttpResponse::html($doc->saveHTML());
            }), true);

            $this->httpHandler->offsetSet('/send-message', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                $params = $request->getQueryParams();

                isset($params['channel']) ? $channelId = $params['channel'] : $channelId = null;
                if (! $channelId || ! $channel = $this->civ13->discord->getChannel($channelId)) return HttpResponse::json(['error' => "Channel `$channelId` not found"]);
                if (! $channel->isTextBased()) return HttpResponse::json(['error' => "Cannot send messages to channel `$channelId`"]);

                isset($params['message']) ? $message = $params['message'] : $message = null;
                if (! $message) return HttpResponse::json(['error' => "Message not found"]);

                $channel->sendMessage($message);
                return HttpResponse::json(['success' => true]);
            }), true);

            $this->httpHandler->offsetSet('/send-embed', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                $params = $request->getQueryParams();

                isset($params['channel']) ? $channelId = $params['channel'] : $channelId = null;
                if (! $channel = $this->civ13->discord->getChannel($channelId)) return HttpResponse::json(['error' => "Channel `$channelId` not found"]);
                if (! $channel->isTextBased()) return HttpResponse::json(['error' => "Cannot send messages to channel `$channelId`"]);

                isset($params['message']) ? $content = $params['message'] : $content = '';
                if (! $content) return HttpResponse::json(['error' => "Message not found"]);

                $builder = MessageBuilder::new();
                if (isset($this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']]) && $user = $this->civ13->discord->users->get('id', $this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']])) { // This will not work if the user didn't login with oauth2 during this runtime session (i.e. the bot was restarted)
                    $embed = new Embed($this->civ13->discord);
                    $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
                    $embed->addField('Message', $content);
                    $builder->addEmbed($embed);
                } else {
                    $builder->setContent($content);
                    $this->civ13->logger->info("Either the IP was not associated with a user or no user could be found.");
                    $this->civ13->logger->info("IP: {$request->getServerParams()['REMOTE_ADDR']}");
                    if (isset($this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']])) $this->civ13->logger->info("Discord ID: {$this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']]}");
                }
                
                $channel->sendMessage($builder); // TODO: Add a built-in function for using MessageBuilder with included embeds
                return HttpResponse::json(['success' => true]);
            }), true);
            
            // HttpHandler website endpoints
            $index = new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                if ($whitelisted) {
                    $method = $this->httpHandler->offsetGet('/botlog') ?? [];
                    if ($method = array_shift($method)) return $method($request, $data, $whitelisted, $endpoint);
                }
                $method = $this->httpHandler->offsetGet('/home.html') ?? [];
                if ($method = array_shift($method)) return $method($request, $data, $whitelisted, $endpoint);
                return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => 'https://www.valzargaming.com/?login']);
            });
            $this->httpHandler->offsetSet('/', $index);
            $this->httpHandler->offsetSet('/index.html', $index);
            $this->httpHandler->offsetSet('/index.php', $index);
            $robots = new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                return HttpResponse::plaintext('User-agent: *' . PHP_EOL . 'Disallow: /');
            });
            $this->httpHandler->offsetSet('/robots.txt', $robots);
            $security = new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                return HttpResponse::plaintext('Contact: mailto:valithor@valzargaming.com' . PHP_EOL . 
                "Contact: {$this->civ13->github}}" . PHP_EOL .
                'Preferred-Languages: en' . PHP_EOL . 
                "Canonical: http://{$this->httpHandler->external_ip}:{$this->http_port}/.well-known/security.txt" . PHP_EOL . 
                'Policy: http://valzargaming.com/legal' . PHP_EOL . 
                'Acknowledgments: http://valzargaming.com/partners');
            });
            $this->httpHandler->offsetSet('/.well-known/security.txt', $security);
            $this->httpHandler->setRateLimit('/.well-known/security.txt', 1, 10); // 1 request per 10 seconds
            $this->httpHandler->offsetSet('/ping', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                return HttpResponse::plaintext("Hello wörld!");
            }));
            $this->httpHandler->offsetSet('/favicon.ico', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                if ($favicon = @file_get_contents('favicon.ico')) return new HttpResponse(HttpResponse::STATUS_OK, ['Content-Type' => 'image/x-icon', 'Cache-Control' => 'public, max-age=2592000'], $favicon);
                return new HttpResponse(HttpResponse::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain'], "Unable to access `favicon.ico`");
            }));

            // HttpHandler whitelisting with DiscordWebAuth
            if (include('dwa_secrets.php'))
            if ($dwa_client_id = getenv('dwa_client_id'))
            if ($dwa_client_secret = getenv('dwa_client_secret'))
            $this->httpHandler->offsetSet('/dwa', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($dwa_client_id, $dwa_client_secret): HttpResponse
            {
                $ip = $request->getServerParams()['REMOTE_ADDR'];
                if (! isset($this->dwa_sessions[$ip])) {
                    $this->dwa_sessions[$ip] = [];
                    $this->dwa_timers[$ip] = $this->civ13->discord->getLoop()->addTimer(30 * 60, function () use ($ip) { // Set a timer to unset the session after 30 minutes
                        unset($this->dwa_sessions[$ip]);
                    });
                }

                $DiscordWebAuth = new DiscordWebAuth($this->civ13, $this->dwa_sessions, $dwa_client_id, $dwa_client_secret, $this->web_address, $this->http_port, $request);
                if (isset($params['code']) && isset($params['state']))
                    return $DiscordWebAuth->getToken($params['state']);
                elseif (isset($params['login']))
                    return $DiscordWebAuth->login();
                elseif (isset($params['logout']))
                    return $DiscordWebAuth->logout();
                elseif ($DiscordWebAuth->isAuthed() && isset($params['remove']))
                    return $DiscordWebAuth->removeToken();
                
                $tech_ping = '';
                if (isset($this->civ13->technician_id)) $tech_ping = "<@{$this->civ13->technician_id}>, ";
                if (isset($DiscordWebAuth->user) && isset($DiscordWebAuth->user->id)) {
                    $this->dwa_discord_ids[$ip] = $DiscordWebAuth->user->id;
                    if (! $this->civ13->verifier->verified->get('discord', $DiscordWebAuth->user->id)) {
                        if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $tech_ping . "<@&$DiscordWebAuth->user->id> tried to log in with Discord but does not have permission to! Please check the logs.");
                        return new HttpResponse(HttpResponse::STATUS_UNAUTHORIZED);
                    }
                    if ($this->httpHandler->whitelist($ip))
                        if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot']))
                            $this->civ13->sendMessage($channel, $tech_ping . "<@{$DiscordWebAuth->user->id}> has logged in with Discord.");
                    $method = $this->httpHandler->offsetGet('/botlog') ?? [];
                    if ($method = array_shift($method))
                        return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => "http://{$this->httpHandler->external_ip}:{$this->http_port}/botlog"]);
                }

                return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => "http://{$this->httpHandler->external_ip}:{$this->http_port}/botlog"]);
            }));

            // HttpHandler management endpoints
            $this->httpHandler->offsetSet('/reset', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                execInBackground('git reset --hard origin/main');
                $message = 'Forcefully moving the HEAD back to origin/main...';
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $message);
                return HttpResponse::plaintext($message);
            }), true);
            $this->httpHandler->offsetSet('/githubupdated', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                if ($signature = $request->getHeaderLine('X-Hub-Signature')) {
                    // Secret isn't working right now, so we're not using it
                    //$hash = "sha1=".hash_hmac('sha1', file_get_contents("php://input"), getenv('github_secret')); // GitHub Webhook Secret is the same as the 'Secret' field on the Webhooks / Manage webhook page of the respostory
                    //if (strcmp($signature, $hash) == 0) {
                        //if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, 'GitHub push event webhook received');
                        if ($channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, 'Updating code from GitHub... (1/3)');
                        execInBackground('git pull');
                        $this->civ13->loop->addTimer(5, function () {
                            if ($channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, 'Forcefully moving the HEAD back to origin/main... (2/3)');
                            execInBackground('git reset --hard origin/main');
                        });
                        $this->civ13->loop->addTimer(10, function () {
                            if ($channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, 'Updating code from GitHub... (3/3)');
                            execInBackground('git pull');
                        });
                        if (isset($this->civ13->timers['update_pending']) && $this->civ13->timers['update_pending'] instanceof TimerInterface) $this->civ13->loop->cancelTimer($this->civ13->timers['update_pending']);
                        $this->civ13->timers['update_pending'] = $this->civ13->loop->addTimer(300, function () {
                            $this->socket->close();
                            if (! isset($this->civ13->timers['restart'])) $this->civ13->timers['restart'] = $this->civ13->discord->getLoop()->addTimer(5, function () {
                                \restart();
                                $this->civ13->discord->close();
                                die();
                            });
                        });
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    //}
                }
                $headers = $request->getHeaders();
                //$this->civ13->logger->warning("Unauthorized Request Headers on `$endpoint` endpoint: ", $headers);
                //$this->civ13->logger->warning("Signature: $signature, Hash: $hash");
                $tech_ping = '';
                if (isset($this->civ13->technician_id)) $tech_ping = "<@{$this->civ13->technician_id}>, ";
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $tech_ping . "Unauthorized Request Headers on `$endpoint` endpoint: " . json_encode($headers));
                return new HttpResponse(HttpResponse::STATUS_UNAUTHORIZED);
            }));

            $this->httpHandler->offsetSet('/cancelupdaterestart', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                if (isset($this->civ13->timers['update_pending']) && $this->civ13->timers['update_pending'] instanceof TimerInterface) {
                    $this->civ13->loop->cancelTimer($this->civ13->timers['update_pending']);
                    unset($this->civ13->timers['update_pending']);
                    return HttpResponse::plaintext('Restart cancelled.');
                }
                return HttpResponse::plaintext('No restart pending.');
            }));
            $this->httpHandler->offsetSet('/pull', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                execInBackground('git pull');
                $message = 'Updating code from GitHub...';
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $message);
                return HttpResponse::plaintext($message);
            }), true);
            $this->httpHandler->offsetSet('/update', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                execInBackground('composer update');
                $message = 'Updating dependencies...';
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $message);
                return HttpResponse::plaintext($message);
            }), true);
            $this->httpHandler->offsetSet('/restart', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                $message = 'Restarting...';
                if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot'])) $this->civ13->sendMessage($channel, $message);
                $this->socket->close();
                if (! isset($this->civ13->timers['restart'])) $this->civ13->timers['restart'] = $this->civ13->discord->getLoop()->addTimer(5, function () {
                    \restart();
                    $this->civ13->discord->close();
                    die();
                });
                return HttpResponse::plaintext($message);
            }), true);

            // HttpHandler redirect endpoints
            if ($this->civ13->github)
            $this->httpHandler->offsetSet('/github', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                return new HttpResponse(HttpResponse::STATUS_FOUND,['Location' => $this->civ13->github]);
            }));

            if ($this->civ13->discord_invite)
            $this->httpHandler->offsetSet('/discord', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint ): HttpResponse
            {
                return new HttpResponse(HttpResponse::STATUS_FOUND,['Location' => $this->civ13->discord_invite]);
            }));

            // HttpHandler data endpoints
            $this->httpHandler->offsetSet('/verified', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                return HttpResponse::json($this->civ13->verifier->verified->toArray());
            }), true);


            /*
            $this->httpHandler->offsetSet('/endpoint', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                
                return HttpResponse::plaintext("Hello wörld!\n");
                return HttpResponse::html("<!doctype html><html><body>Hello wörld!</body></html>");
                return new HttpResponse(
                    HttpResponse::STATUS_OK,
                    ['Content-Type' => 'text/json'],
                    json_encode($json ?? '')
                );
            }));
            */            

            // HttpHandler log endpoints
            $botlog_func = new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint = '/botlog'): HttpResponse
            {
                $webpage_content = function (string $return) use ($endpoint) {
                    return '<meta name="color-scheme" content="light dark"> 
                            <div class="button-container">
                                <button style="width:8%" onclick="sendGetRequest(\'pull\')">Pull</button>
                                <button style="width:8%" onclick="sendGetRequest(\'reset\')">Reset</button>
                                <button style="width:8%" onclick="sendGetRequest(\'update\')">Update</button>
                                <button style="width:8%" onclick="sendGetRequest(\'restart\')">Restart</button>
                                <button style="background-color: black; color:white; display:flex; justify-content:center; align-items:center; height:100%; width:68%; flex-grow: 1;" onclick="window.open(\''. $this->civ13->github . '\')">' . $this->civ13->discord->user->displayname . '</button>
                            </div>
                            <div class="alert-container"></div>
                            <div class="checkpoint">' . 
                                str_replace('[' . date("Y"), '</div><div> [' . date("Y"), 
                                    str_replace([PHP_EOL, '[] []', ' [] '], '</div><div>', $return)
                                ) . 
                            "</div>
                            <div class='reload-container'>
                                <button onclick='location.reload()'>Reload</button>
                            </div>
                            <div class='loading-container'>
                                <div class='loading-bar'></div>
                            </div>
                            <script>
                                var mainScrollArea=document.getElementsByClassName('checkpoint')[0];
                                var scrollTimeout;
                                window.onload=function(){
                                    if (window.location.href==localStorage.getItem('lastUrl')){
                                        mainScrollArea.scrollTop=localStorage.getItem('scrollTop');
                                    } else {
                                        localStorage.setItem('lastUrl',window.location.href);
                                        localStorage.setItem('scrollTop',0);
                                    }
                                };
                                mainScrollArea.addEventListener('scroll',function(){
                                    clearTimeout(scrollTimeout);
                                    scrollTimeout=setTimeout(function(){
                                        localStorage.setItem('scrollTop',mainScrollArea.scrollTop);
                                    },100);
                                });
                                function sendGetRequest(endpoint) {
                                    var xhr = new XMLHttpRequest();
                                    xhr.open('GET', window.location.protocol + '//' + window.location.hostname + ':{$this->http_port}/' + endpoint, true);
                                    xhr.onload = function () {
                                        var response = xhr.responseText.replace(/(<([^>]+)>)/gi, '');
                                        var alertContainer = document.querySelector('.alert-container');
                                        var alert = document.createElement('div');
                                        alert.innerHTML = response;
                                        alertContainer.appendChild(alert);
                                        setTimeout(function() {
                                            alert.remove();
                                        }, 15000);
                                        if (endpoint === 'restart') {
                                            var loadingBar = document.querySelector('.loading-bar');
                                            var loadingContainer = document.querySelector('.loading-container');
                                            loadingContainer.style.display = 'block';
                                            var width = 0;
                                            var interval = setInterval(function() {
                                                if (width >= 100) {
                                                    clearInterval(interval);
                                                    location.reload();
                                                } else {
                                                    width += 2;
                                                    loadingBar.style.width = width + '%';
                                                }
                                            }, 300);
                                            loadingBar.style.backgroundColor = 'white';
                                            loadingBar.style.height = '20px';
                                            loadingBar.style.position = 'fixed';
                                            loadingBar.style.top = '50%';
                                            loadingBar.style.left = '50%';
                                            loadingBar.style.transform = 'translate(-50%, -50%)';
                                            loadingBar.style.zIndex = '9999';
                                            loadingBar.style.borderRadius = '5px';
                                            loadingBar.style.boxShadow = '0 0 10px rgba(0, 0, 0, 0.5)';
                                            var backdrop = document.createElement('div');
                                            backdrop.style.position = 'fixed';
                                            backdrop.style.top = '0';
                                            backdrop.style.left = '0';
                                            backdrop.style.width = '100%';
                                            backdrop.style.height = '100%';
                                            backdrop.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                                            backdrop.style.zIndex = '9998';
                                            document.body.appendChild(backdrop);
                                            setTimeout(function() {
                                                clearInterval(interval);
                                                if (!document.readyState || document.readyState === 'complete') {
                                                    location.reload();
                                                } else {
                                                    setTimeout(function() {
                                                        location.reload();
                                                    }, 90000);
                                                }
                                            }, 90000);
                                        }
                                    };
                                    xhr.send();
                                }
                                </script>
                                <style>
                                    .button-container {
                                        position: fixed;
                                        top: 0;
                                        left: 0;
                                        right: 0;
                                        background-color: #f1f1f1;
                                        overflow: hidden;
                                    }
                                    .button-container button {
                                        float: left;
                                        display: block;
                                        color: black;
                                        text-align: center;
                                        padding: 14px 16px;
                                        text-decoration: none;
                                        font-size: 17px;
                                        border: none;
                                        cursor: pointer;
                                        color: white;
                                        background-color: black;
                                    }
                                    .button-container button:hover {
                                        background-color: #ddd;
                                    }
                                    .checkpoint {
                                        margin-top: 100px;
                                    }
                                    .alert-container {
                                        position: fixed;
                                        top: 0;
                                        right: 0;
                                        width: 300px;
                                        height: 100%;
                                        overflow-y: scroll;
                                        padding: 20px;
                                        color: black;
                                        background-color: black;
                                    }
                                    .alert-container div {
                                        margin-bottom: 10px;
                                        padding: 10px;
                                        background-color: #fff;
                                        border: 1px solid #ddd;
                                    }
                                    .reload-container {
                                        position: fixed;
                                        bottom: 0;
                                        left: 50%;
                                        transform: translateX(-50%);
                                        margin-bottom: 20px;
                                    }
                                    .reload-container button {
                                        display: block;
                                        color: black;
                                        text-align: center;
                                        padding: 14px 16px;
                                        text-decoration: none;
                                        font-size: 17px;
                                        border: none;
                                        cursor: pointer;
                                    }
                                    .reload-container button:hover {
                                        background-color: #ddd;
                                    }
                                    .loading-container {
                                        position: fixed;
                                        top: 0;
                                        left: 0;
                                        right: 0;
                                        bottom: 0;
                                        background-color: rgba(0, 0, 0, 0.5);
                                        display: none;
                                    }
                                    .loading-bar {
                                        position: absolute;
                                        top: 50%;
                                        left: 50%;
                                        transform: translate(-50%, -50%);
                                        width: 0%;
                                        height: 20px;
                                        background-color: white;
                                    }
                                    .nav-container {
                                        position: fixed;
                                        bottom: 0;
                                        right: 0;
                                        margin-bottom: 20px;
                                    }
                                    .nav-container button {
                                        display: block;
                                        color: black;
                                        text-align: center;
                                        padding: 14px 16px;
                                        text-decoration: none;
                                        font-size: 17px;
                                        border: none;
                                        cursor: pointer;
                                        color: white;
                                        background-color: black;
                                        margin-right: 10px;
                                    }
                                    .nav-container button:hover {
                                        background-color: #ddd;
                                    }
                                    .checkbox-container {
                                        display: inline-block;
                                        margin-right: 10px;
                                    }
                                    .checkbox-container input[type=checkbox] {
                                        display: none;
                                    }
                                    .checkbox-container label {
                                        display: inline-block;
                                        background-color: #ddd;
                                        padding: 5px 10px;
                                        cursor: pointer;
                                    }
                                    .checkbox-container input[type=checkbox]:checked + label {
                                        background-color: #bbb;
                                    }
                                </style>
                                <div class='nav-container'>"
                                    . ($endpoint === '/botlog' ? "<button onclick=\"location.href='/botlog2'\">Botlog 2</button>" : "<button onclick=\"location.href='/botlog'\">Botlog 1</button>")
                                . "</div>
                                <div class='reload-container'>
                                    <div class='checkbox-container'>
                                        <input type='checkbox' id='auto-reload-checkbox' " . (isset($_COOKIE['auto-reload']) && $_COOKIE['auto-reload'] === 'true' ? 'checked' : '') . ">
                                        <label for='auto-reload-checkbox'>Auto Reload</label>
                                    </div>
                                    <button id='reload-button'>Reload</button>
                                </div>
                                <script>
                                    var reloadButton = document.getElementById('reload-button');
                                    var autoReloadCheckbox = document.getElementById('auto-reload-checkbox');
                                    var interval;
            
                                    reloadButton.addEventListener('click', function () {
                                        clearInterval(interval);
                                        location.reload();
                                    });
            
                                    autoReloadCheckbox.addEventListener('change', function () {
                                        if (this.checked) {
                                            interval = setInterval(function() {
                                                location.reload();
                                            }, 15000);
                                            localStorage.setItem('auto-reload', 'true');
                                        } else {
                                            clearInterval(interval);
                                            localStorage.setItem('auto-reload', 'false');
                                        }
                                    });
            
                                    if (localStorage.getItem('auto-reload') == 'true') {
                                        autoReloadCheckbox.checked = true;
                                        interval = setInterval(function() {
                                            location.reload();
                                        }, 15000);
                                    }
                                </script>";
                };
                if ($return = @file_get_contents('botlog.txt')) return HttpResponse::html($webpage_content($return));
                return $this->httpHandler->__throwError('Unable to access `botlog.txt`', HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
            });
            $this->httpHandler->offsetSet('/botlog', $botlog_func, true);
            $this->httpHandler->offsetSet('/botlog2', $botlog_func, true);
        });

        $this->__generateServerEndpoints();
        $this->__generateWebsiteEndpoints();
    }

    private function __generateServerEndpoints()
    {
        $relay = function($message, $channel, $ckey = null): ?PromiseInterface
        {
            if (! $ckey || ! $item = $this->civ13->verifier->verified->get('ss13', $this->civ13->sanitizeInput(explode('/', $ckey)[0]))) return $this->civ13->sendMessage($channel, $message);
            if (! $user = $this->civ13->discord->users->get('id', $item['discord'])) {
                $this->civ13->logger->warning("{$item['ss13']}'s Discord ID was not found not in the primary Discord server!");
                $this->civ13->discord->users->fetch($item['discord']);
                return $this->civ13->sendMessage($channel, $message);
            } 
            $embed = new Embed($this->civ13->discord);
            $embed->setAuthor("{$user->displayname} ({$user->id})", $user->avatar);
            $embed->setDescription($message);
            return $channel->sendEmbed($embed);
        };
        
        foreach ($this->civ13->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $server_endpoint = '/' . $settings['key'];

            $this->httpHandler->offsetSet('/bancheck_centcom', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                $params = $request->getQueryParams();
                if (! isset($params['ckey'])) return HttpResponse::plaintext("`ckey` must be included as a query parameter")->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                if (is_numeric($ckey = $params['ckey'])) {
                    if (! $item = $this->civ13->verifier->verified->get('discord', $ckey)) return HttpResponse::plaintext("Unable to locate Byond username for Discord ID `$ckey`")->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                    $ckey = $item['ss13'];
                }
                if (! $json = $this->civ13->bansearch_centcom($ckey, false)) return HttpResponse::plaintext("Unable to locate bans for `$ckey` on CentCom")->withStatus(HttpResponse::STATUS_OK);                
                return new HttpResponse(HttpResponse::STATUS_OK, ['Content-Type' => 'application/json'], $json);
            }));
            $this->httpHandler->offsetSet($server_endpoint.'/bans', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if (! file_exists($bans = $settings['basedir'] . Civ13::bans)) return HttpResponse::plaintext("Unable to access `$bans`")->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                if (! $return = @file_get_contents($bans)) return HttpResponse::plaintext("Unable to read `$bans`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                return HttpResponse::plaintext($return);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/playerlogs', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if (! file_exists($playerlogs = $settings['basedir'] . Civ13::playerlogs)) return HttpResponse::plaintext("Unable to access `$playerlogs`")->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                if (! $return = @file_get_contents($playerlogs)) return HttpResponse::plaintext("Unable to read `$playerlogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                return HttpResponse::plaintext($return);
            }), true);
        }

        $endpoint = '/webhook';
        foreach ($this->civ13->server_settings as $settings) {
            if (! isset($settings['enabled']) || ! $settings['enabled']) continue;
            $server_endpoint = $endpoint . '/' . $settings['key'];

            // If no parameters are passed to a server_endpoint, try to find it using the query parameters
            $this->httpHandler->offsetSet($server_endpoint, new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                $params = $request->getQueryParams();
                //if ($params['method']) $this->civ13->logger->info("[METHOD] `{$params['method']}`");
                $method = $this->httpHandler->offsetGet($endpoint.'/'.($params['method'] ?? '')) ?? [];
                if ($method = array_shift($method)) return $method($request, $data, $whitelisted, $endpoint);
                else {
                    if ($params['method'] ?? '') $this->civ13->logger->warning("[NO FUNCTION FOUND FOR METHOD] `{$params['method']}`");
                    return HttpResponse::plaintext('Method not found')->withStatus(HttpResponse::STATUS_NOT_FOUND);
                }
                $this->civ13->logger->warning("[UNROUTED ENDPOINT] `$endpoint`");
                return HttpResponse::plaintext('Method not found')->withStatus(HttpResponse::STATUS_NOT_FOUND);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/ahelpmessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['asay'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->civ13->discord->getChannel($channel_id = $settings['asay'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} AHELP__ $ckey**: " . $message;

                //$relay($message, $channel, $ckey); //Bypass moderator
                $this->civ13->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/asaymessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['asay'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->civ13->discord->getChannel($channel_id = $settings['asay'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                //$message = "**__{$time} ASAY__ $ckey**: $message";
                $message = "**__{$time}__** $message";

                if (str_contains($data['message'], $this->civ13->discord->user->displayname)) $this->civ13->gameChatWebhookRelay($ckey, $message, $channel_id); // Message was probably meant for the bot
                else $relay($message, $channel, $ckey); //Bypass moderator
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/urgentasaymessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['asay'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->civ13->discord->getChannel($settings['asay'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                $message = "<@{$this->civ13->role_ids['Admin']}>, ";
                isset($data['message']) ? $message .= strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message .= '(NULL)';
                //$message = "**__{$time} ASAY__ $ckey**: $message";
                $message = "**__{$time}__** $message";

                $relay($message, $channel, $ckey);
                //$this->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/lobbymessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['lobby'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->civ13->discord->getChannel($channel_id = $settings['lobby'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} LOBBY__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->civ13->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/oocmessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['ooc'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->civ13->discord->getChannel($channel_id = $settings['ooc'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                //$time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                //$message = "**__{$time} OOC__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->civ13->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/icmessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['ic'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->civ13->discord->getChannel($channel_id = $settings['ic'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                //$time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                //$message = "**__{$time} OOC__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->civ13->gameChatWebhookRelay($ckey, $message, $channel_id, true, false);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/memessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['ic'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->civ13->discord->getChannel($channel_id = $settings['ic'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} EMOTE__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->civ13->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/garbage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['adminlog'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $this->civ13->discord->getChannel($channel_id = $settings['adminlog'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} GARBAGE__ $ckey**: $message";

                //$relay($message, $channel, $ckey);
                $this->civ13->gameChatWebhookRelay($ckey, $message, $channel_id);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/round_start', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['discussion'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->civ13->discord->getChannel($settings['discussion'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                $message = '';
                if (isset($this->civ13->role_ids['round_start'])) $message .= "<@&{$this->civ13->role_ids['round_start']}>, ";
                $message .= 'New round ';
                if (isset($data['round']) && $game_id = $data['round']) {
                    $this->civ13->logNewRound($settings['key'], $game_id, $time);
                    $message .= "`$game_id` ";
                }
                $message .= 'has started!';
                if ($playercount_channel = $this->civ13->discord->getChannel($settings['playercount']))
                if ($existingCount = explode('-', $playercount_channel->name)[1]) {
                    $existingCount = intval($existingCount);
                    switch ($existingCount) {
                        case 0:
                            $message .= " There are currently no players on the {$settings['name']} server.";
                            break;
                        case 1:
                            $message .= " There is currently 1 player on the {$settings['name']} server.";
                            break;
                        default:
                            if (isset($this->civ13->role_ids['30+']) && $this->civ13->role_ids['30+'] && ($existingCount >= 30)) $message .= " <@&{$this->civ13->role_ids['30+']}>,";
                            elseif (isset($this->civ13->role_ids['15+']) && $this->civ13->role_ids['15+'] && ($existingCount >= 15)) $message .= " <@&{$this->civ13->role_ids['15+']}>,";
                            elseif (isset($this->civ13->role_ids['2+']) && $this->civ13->role_ids['2+'] && ($existingCount >= 2)) $message .= " <@&{$this->civ13->role_ids['2+']}>,";
                            $message .= " There are currently $existingCount players on the {$settings['name']} server.";
                            break;
                    }
                }
                $this->civ13->sendMessage($channel, $message);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/respawn_notice', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            { // NYI
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);
            $this->httpHandler->offsetSet($server_endpoint.'/login', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['transit'], $this->civ13->channel_ids['parole_notif'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->civ13->discord->getChannel($settings['transit'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $parole_notif_channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['parole_notif'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                $message = "$ckey connected to the server";
                if (isset($data['ip'])) $message .= " with IP of {$data['ip']}";
                if (isset($data['cid'])) $message .= " and CID of {$data['cid']}";
                $message .= '.';
                if (isset($this->civ13->current_rounds[$settings['key']]) && $this->civ13->current_rounds[$settings['key']]) $this->civ13->logPlayerLogin($settings['key'], $ckey, $time, $data['ip'] ?? '', $data['cid'] ?? '');

                if (isset($this->civ13->paroled[$ckey])) {
                    $message2 = '';
                    if (isset($this->civ13->role_ids['Parolemin'])) $message2 .= "<@&{$this->civ13->role_ids['Parolemin']}>, ";
                    $message2 .= "`$ckey` has logged into `{$settings['name']}`";
                    $this->civ13->sendMessage($parole_notif_channel, $message2);
                }

                $ckeyinfo = $this->civ13->ckeyinfo($ckey);
                if ($ckeyinfo['altbanned'] && ! isset($this->civ13->permitted[$ckey]))
                    if (isset($this->civ13->channel_ids['staff_bot']) && $staffbot = $this->civ13->discord->getChannel($this->civ13->channel_ids['staff_bot']))
                        $this->civ13->sendMessage($staffbot, $this->civ13->ban(['ckey' => $ckey, 'duration' => '999 years', 'reason' => "Account under investigation. Appeal at {$this->civ13->discord_formatted}"], null, [], true) . ' (Alt Banned)');

                $relay($message, $channel, $ckey);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/logout', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['transit'], $this->civ13->channel_ids['parole_notif'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->civ13->discord->getChannel($settings['transit'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $parole_notif_channel = $this->civ13->discord->getChannel($this->civ13->channel_ids['parole_notif'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                $message = "$ckey disconnected from the server.";
                if (isset($this->civ13->current_rounds[$settings['key']]) && $this->civ13->current_rounds[$settings['key']]) $this->civ13->logPlayerLogout($settings['key'], $ckey, $time);

                if (isset($this->civ13->paroled[$ckey])) {
                    $message2 = '';
                    if (isset($this->civ13->role_ids['Parolemin'])) $message2 .= "<@&{$this->civ13->role_ids['Parolemin']}>, ";
                    $message2 .= "`$ckey` has log out of `{$settings['name']}`";
                    $this->civ13->sendMessage($parole_notif_channel, $message2);
                }

                $relay($message, $channel, $ckey);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/runtimemessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['runtime'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->civ13->discord->getChannel($settings['runtime'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                //isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} RUNTIME__**: $message";

                $relay($message, $channel);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/alogmessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if (! isset($settings['adminlog'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->civ13->discord->getChannel($settings['adminlog'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} ADMIN LOG__**: " . $message;

                $relay($message, $channel);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $this->httpHandler->offsetSet($server_endpoint.'/attacklogmessage', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($settings, $relay): HttpResponse
            {
                if ($this->civ13->relay_method !== 'webhook') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                if ($settings['key'] === 'tdm') return new HttpResponse(HttpResponse::STATUS_FORBIDDEN); // Disabled on TDM, use manual checking of log files instead
                if (! isset($settings['attack'])) return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                if (! $channel = $this->civ13->discord->getChannel($settings['attack'])) return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);

                $time = '['.date('H:i:s', time()).']';
                isset($data['ckey']) ? $ckey = $this->civ13->sanitizeInput($data['ckey']) : $ckey = null;
                isset($data['ckey2']) ? $ckey2 = $this->civ13->sanitizeInput($data['ckey2']) : $ckey2 = null;
                isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                $message = "**__{$time} ATTACK LOG__**: " . $message;
                if ($ckey && $ckey2) if ($ckey === $ckey2) $message .= " (Self-Attack)";
                
                $relay($message, $channel);
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);

            $generic_http_handler = new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
            {
                return new HttpResponse(HttpResponse::STATUS_OK);
            });
            $this->httpHandler->offsetSet('roundstatus', $generic_http_handler, true);
            $this->httpHandler->offsetSet('status_update', $generic_http_handler, true);
            /*
            $this->httpHandler->offsetSet($server_endpoint.'/', new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($key, $server, $relay): HttpResponse
            {
                return new HttpResponse(HttpResponse::STATUS_OK);
            }), true);
            */
        }
    }

    private function __generateWebsiteEndpoints()
    {
        if (! is_dir($dirPath = $this->basedir . self::HTMLDIR))
            if (! mkdir($dirPath, 0664, true))
                return $this->civ13->logger->error('Failed to create `/html` directory');
        $files = [];
        foreach (new \DirectoryIterator($dirPath) as $file) {
            if ($file->isDot() || !$file->isFile() || $file->getExtension() !== 'html') continue;
            $files[] = substr($file->getPathname(), strlen($dirPath));
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . /*<?xml-stylesheet type="text/xsl" href="sitemap.xsl"?> .*/ '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($files as &$file) {
            if (! $fileContent = file_get_contents(substr(self::HTMLDIR, 1) . $file)) {
                $this->civ13->logger->error("Failed to read file: `$file`");
                unset($file);
                continue;
            }
            $this->httpHandler->offsetSet($file, new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($fileContent): HttpResponse {
                return HttpResponse::html($fileContent);
            }));
            $xml .= "<url><loc>$file</loc></url>";
            //$this->civ13->logger->debug("Registered HTML endpoint: `$endpoint`");
        }
        $xml .= '</urlset>';
        $sitemapxml = new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint) use ($xml): HttpResponse
        {
            return HttpResponse::xml($xml);
        });
        $this->httpHandler->offsetSet('/sitemap.xml', $sitemapxml);
        $this->httpHandler->setRateLimit('/sitemap.xml', 1, 10); // 1 request per 10 seconds

        $sitemalxsl = new HttpHandlerCallback(function (ServerRequestInterface $request, array $data, bool $whitelisted, string $endpoint): HttpResponse
        {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
                <xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
                <xsl:output method="html" indent="yes"/>

                <xsl:template match="/">
                    <html>
                    <head>
                    <title>Sitemap</title>
                    <meta http-equiv="Cache-Control" content="max-age=31536000, public"/>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; }
                    </style>
                    </head>
                    <body>
                    <h1>Sitemap</h1>
                    <table>
                        <tr>
                        <th>URL</th>
                        </tr>
                        <xsl:for-each select="urlset/url">
                        <tr>
                            <td><a href="{loc}"><xsl:value-of select="loc"/></a></td>
                        </tr>
                        </xsl:for-each>
                    </table>
                    </body>
                    </html>
                </xsl:template>
                </xsl:stylesheet>';
            return HttpResponse::xml($xml);
        });
        //$this->httpHandler->offsetSet('/sitemap.xsl', $sitemalxsl);
        //$this->httpHandler->setRateLimit('/sitemap.xsl', 1, 10); // 1 request per 10 seconds
    }
}