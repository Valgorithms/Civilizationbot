<?php

declare(strict_types=1);

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13;

use Byond\Byond;
use Civ13\ServerDirectory\ByondStatusProbe;
use Civ13\ServerDirectory\Directory;
use Civ13\ServerDirectory\Endpoint as DirectoryEndpoint;
use Discord\Discord;
use Discord\DiscordWebAuth;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Member;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response as HttpResponse;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

class HttpServiceManager
{
    public const HTMLDIR = '/html';
    public readonly string $basedir;

    public Discord $discord;
    public Logger $logger;
    public HttpHandler $httpHandler;
    public HttpServer $webapi;
    public SocketServer $socket;
    public string $web_address;
    public int $http_port;

    protected array $dwa_sessions = [];
    protected array $dwa_timers = [];
    protected array $dwa_discord_ids = [];
    private ?Directory $launcherDirectory = null;
    private ?GameReady $gameReady = null;
    private ?TimerInterface $launcherDirectoryTimer = null;

    /** @param Civ13 $civ13 The bot instance; builds the {@see HttpHandler} and binds `discord`/`logger` by reference. */
    public function __construct(public Civ13 &$civ13)
    {
        $this->discord = &$civ13->discord;
        $this->logger = &$civ13->logger;
        $this->httpHandler = new HttpHandler($this->civ13, [], $this->civ13->options['http_whitelist'] ?? [], $this->civ13->options['http_key'] ?? '');
        $this->basedir = getcwd();
        $this->__afterConstruct();
    }

    /** Closes the listening socket, if one is open. */
    public function __destruct()
    {
        if ($this->launcherDirectoryTimer !== null) {
            $this->civ13->loop->cancelTimer($this->launcherDirectoryTimer);
        }
        if (isset($this->socket)) {
            $this->socket->close();
        }
    }

    /** Boots the web API: validates the required `webapi`/`socket`/`web_address`/`http_port` options, then binds endpoints and the listening socket. */
    protected function __afterConstruct()
    {
        if (! isset($this->civ13->options['webapi'], $this->civ13->options['socket'], $this->civ13->options['web_address'], $this->civ13->options['http_port'])) {
            $this->logger->warning('HttpServer API not set up! Missing variables in options.');
            $this->logger->warning('Missing webapi variable: '.(isset($this->civ13->options['webapi']) ? 'false' : 'true'));
            $this->logger->warning('Missing socket variable: '.(isset($this->civ13->options['socket']) ? 'false' : 'true'));
            $this->logger->warning('Missing web_address variable: '.(isset($this->civ13->options['web_address']) ? 'false' : 'true'));
            $this->logger->warning('Missing http_port variable: '.(isset($this->civ13->options['http_port']) ? 'false' : 'true'));

            return;
        }
        $this->webapi = $this->civ13->options['webapi'];
        $this->socket = $this->civ13->options['socket'];
        $this->web_address = $this->civ13->options['web_address'];
        $this->http_port = $this->civ13->options['http_port'];

        $this->__generateEndpoints();
        $fn = function () {
            //$this->logger->info('Populating HttpServer API whitelist...');
            //$this->__populateWhitelist(); // This is disabled for now because it takes >20 seconds.
            $this->webapi->listen($this->socket);
            if ($this->launcherDirectory !== null) {
                $refresh = fn () => $this->launcherDirectory->refresh()->then(null, fn (\Throwable $error) => $this->logger->warning('Launcher directory refresh failed'));
                $refresh();
                $this->launcherDirectoryTimer = $this->civ13->loop->addPeriodicTimer(Directory::REFRESH_SECONDS, $refresh);
            }
            $this->logger->info("HttpServer API is now listening on port {$this->http_port}");
            $this->logger->debug('[HTTP COMMAND LIST] '.PHP_EOL.$this->httpHandler->generateHelp());
        };
        $this->civ13->ready
            ? $fn()
            : $this->discord->once('init', fn () => $fn());
    }

    /**
     * Handles an incoming HTTP request and returns an HTTP response.
     *
     * If the Civ13 service is ready, the request is passed to the HTTP handler.
     * If the handler returns a response with a status code of 500 (Internal Server Error),
     * a warning is logged with the URI path of the request.
     *
     * If the Civ13 service is not ready, a 503 (Service Unavailable) response is returned.
     *
     * @param  ServerRequestInterface $request The incoming HTTP request.
     * @return HttpResponse           The HTTP response generated by the handler or a service unavailable response.
     */
    public function handle(ServerRequestInterface $request): HttpResponse
    {
        if ($this->civ13->ready) {
            $response = $this->httpHandler->handle($request);
            if ($response->getStatusCode() === HttpResponse::STATUS_INTERNAL_SERVER_ERROR) {
                $this->logger->warning('Internal Server Error on '.$request->getUri()->getPath());
            }

            return $response;
        }
        $this->logger->warning('Civ13 service is not ready.');

        return new HttpResponse(HttpResponse::STATUS_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain'], 'Service Unavailable');
    }

    /**
     * Decodes the 'data' parameter from the query parameters of the given server request.
     *
     * This method retrieves the query parameters from the provided ServerRequestInterface
     * instance, checks if the 'data' parameter exists, and attempts to decode it from a
     * URL-encoded JSON string into an associative array.
     *
     * @param ServerRequestInterface $request The server request containing query parameters.
     *
     * @return array The decoded 'data' parameter as an associative array, or an empty array
     *               if the 'data' parameter is not present or cannot be decoded.
     */
    public static function decodeParamData(ServerRequestInterface $request): array
    {
        $value = $request->getQueryParams()['data'] ?? null;
        if (!is_string($value) || strlen($value) > 65536) { return []; }
        // PSR query parsing normally decoded the URL once already. Preserve message %/plus bytes.
        $decoded = json_decode($value, true, 16);
        if (!is_array($decoded)) { $decoded = json_decode(urldecode($value), true, 16); }
        return is_array($decoded) ? $decoded : [];
    }

    /** Authenticated structured OOC identity, independent of network whitelist. */
    public function handleOocWebhook(ServerRequestInterface $request, GameServer $gameserver): HttpResponse
    {
        return $this->handleStructuredChatWebhook($request, $gameserver, 'ooc');
    }

    /** Bounded native actor/message records for OOC, IC and emote chat. */
    public function handleStructuredChatWebhook(ServerRequestInterface $request, GameServer $gameserver, string $kind): HttpResponse
    {
        if (!in_array($kind, ['ooc', 'ic', 'me'], true)) { return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST); }
        $data = self::decodeParamData($request);
        $key = $this->civ13->options['http_key'] ?? '';
        $requestKey = $request->getQueryParams()['key'] ?? null;
        if (!is_string($key) || $key === '' || !is_string($requestKey) || !hash_equals($key, $requestKey)) {
            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
        }
        if (!is_string($data['ckey'] ?? null) || !preg_match('/^[A-Za-z0-9_ ]{1,64}$/D', $data['ckey'])
            || !is_string($data['message'] ?? null) || $data['message'] === '' || strlen($data['message']) > 16384
            || str_contains($data['message'], "\0")) {
            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
        }
        $ckey = Civ13::sanitizeInput($data['ckey']);
        if ($ckey === '') { return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST); }
        if ($gameserver->legacy_relay) { return new HttpResponse(HttpResponse::STATUS_FORBIDDEN); }
        $channel = $kind === 'ooc' ? ($gameserver->ooc ?? null) : ($gameserver->ic ?? null);
        if (!$channel || !$this->discord->getChannel($channel)) {
            return new HttpResponse(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
        }
        $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message'])));
        if ($message === '') { return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST); }
        if ($kind === 'me') { $message = '**__['.date('H:i:s').'] EMOTE__ '.$ckey.':** '.$message; }
        $gameserver->gameChatWebhookRelay($message, $channel, $ckey, $kind === 'ooc')
            ->then(null, fn (\Throwable $error) => $this->logger->error('Structured chat relay failed: '.$error->getMessage()));
        return new HttpResponse(HttpResponse::STATUS_ACCEPTED);
    }

    /** Existing keyed bridge only. Observations never upgrade lifecycle/Topic readiness. */
    public function handleGameReadyWebhook(ServerRequestInterface $request, GameServer $gameserver): HttpResponse
    {
        $params = $request->getQueryParams();
        $secret = $this->civ13->options['http_key'] ?? '';
        if (!is_string($secret) || $secret === '' || !is_string($params['key'] ?? null) || !hash_equals($secret, $params['key'])) {
            return new HttpResponse(403);
        }
        if ($request->getMethod() !== 'GET') { return new HttpResponse(405, ['Allow'=>'GET']); }
        $root = '/webhook/'.$gameserver->key;
        $path = $request->getUri()->getPath();
        if (!in_array($path, [$root, $root.'/game_ready'], true)
            || ($path === $root && ($params['method'] ?? null) !== 'game_ready')
            || ($path !== $root && isset($params['method']) && $params['method'] !== 'game_ready')) { return new HttpResponse(400); }
        $settings = $this->civ13->options['game_ready_events'] ?? ['enabled'=>false];
        if (!is_array($settings) || array_keys($settings) !== ['enabled'] || ($settings['enabled'] ?? null) !== true
            || ($gameserver->enabled ?? false) !== true
            || ($this->civ13->enabled_gameservers[$gameserver->key] ?? null) !== $gameserver) { return new HttpResponse(503); }
        if (!is_string($params['data'] ?? null) || strlen($params['data']) > GameReady::MAX_BYTES) { return new HttpResponse(400); }
        try {
            // No legacy second URL decode; native query parsing already decoded data once.
            GameReady::decode($params['data'], $gameserver->key);
            $this->gameReady ??= new GameReady();
            $result = $this->gameReady->receive($params['data'], $gameserver->key);
        } catch (\JsonException|\InvalidArgumentException) { return new HttpResponse(400); }
        catch (\Throwable) { return new HttpResponse(503); }
        if (!isset($result['body'])) { return new HttpResponse($result['status'], ['Cache-Control'=>'no-store']); }
        try { $this->logger->info('Game ready observation', ['server'=>$gameserver->key, 'event_id'=>$result['body']['event_id'], 'state'=>$result['body']['state']]); }
        catch (\Throwable) {} // Optional logging cannot undo a reserved observation.
        return new HttpResponse($result['status'], ['Content-Type'=>'application/json', 'Cache-Control'=>'no-store',
            'X-Civ13-Game-Ready'=>$result['ack']], json_encode($result['body'], JSON_THROW_ON_ERROR));
    }

    /** Round identity is authenticated; a channel name is not a player-count source. */
    public function handleRoundStartWebhook(ServerRequestInterface $request, GameServer $gameserver): HttpResponse
    {
        $secret = $this->civ13->options['http_key'] ?? '';
        $key = $request->getQueryParams()['key'] ?? null;
        if (!is_string($secret) || $secret === '' || !is_string($key) || !hash_equals($secret, $key) || $gameserver->legacy_relay) {
            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
        }
        $data = self::decodeParamData($request);
        if (!is_string($data['round'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $data['round'])) {
            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
        }
        if (!isset($gameserver->discussion) || !$channel = $this->discord->getChannel($gameserver->discussion)) {
            return new HttpResponse(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
        }
        try { $gameserver->logNewRound($data['round'], '['.date('H:i:s').']'); }
        catch (\Throwable $error) {
            $this->logger->error('Round persistence failed', ['exception' => $error]);
            return new HttpResponse(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
        }
        // A round reloaded from a save keeps its identity; announce it without the ping.
        if (in_array($data['resumed'] ?? false, [true, 1, '1'], true)) {
            $message = 'Round `'.$data['round'].'` has resumed from a save.';
        } else {
            $role = $this->civ13->role_ids['round_start'] ?? null;
            $message = ($role ? "<@&$role>, " : '').'New round `'.$data['round'].'` has started!';
        }
        $this->civ13->sendMessage($channel, $message)->then(null, function (\Throwable $error): void {
            $this->logger->error('Queued round notification failed', ['exception' => $error]);
        });
        return new HttpResponse(HttpResponse::STATUS_ACCEPTED);
    }

    /**
     * World save notices from a persistent world: "started" when a save begins,
     * then "completed" (with its wall time) or "failed". Posted to the server's
     * discussion channel without pings; a save is routine, not an alert.
     */
    public function handleWorldSaveWebhook(ServerRequestInterface $request, GameServer $gameserver): HttpResponse
    {
        $secret = $this->civ13->options['http_key'] ?? '';
        $key = $request->getQueryParams()['key'] ?? null;
        if (!is_string($secret) || $secret === '' || !is_string($key) || !hash_equals($secret, $key) || $gameserver->legacy_relay) {
            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
        }
        $data = self::decodeParamData($request);
        $event = $data['event'] ?? null;
        if (!in_array($event, ['started', 'completed', 'failed'], true)) {
            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
        }
        $seconds = $data['seconds'] ?? null;
        if ($seconds !== null && (!is_int($seconds) && !is_float($seconds) || $seconds < 0 || $seconds > 86400)) {
            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
        }
        if (!isset($gameserver->discussion) || !$channel = $this->discord->getChannel($gameserver->discussion)) {
            return new HttpResponse(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
        }
        $shutdown = in_array($data['shutdown'] ?? false, [true, 1, '1'], true);
        $name = is_string($gameserver->name ?? null) && $gameserver->name !== '' ? $gameserver->name : $gameserver->key;
        $time = '['.date('H:i:s').']';
        $message = match ($event) {
            'started' => "{$time} **{$name}**: world save started".($shutdown ? ' before a restart' : '').'. The server may pause briefly.',
            'completed' => "{$time} **{$name}**: world save completed".($seconds !== null ? ' in '.round($seconds, 1).' s' : '').'.',
            'failed' => "{$time} **{$name}**: world save did not complete. Staff have been notified in game.",
        };
        $this->civ13->sendMessage($channel, $message)->then(null, function (\Throwable $error): void {
            $this->logger->error('Queued world save notification failed', ['exception' => $error]);
        });
        return new HttpResponse(HttpResponse::STATUS_ACCEPTED);
    }

    /**
     * Planned shutdown notices (Civ13 shutdown_notice.dm): "scheduled" when a restart or shutdown
     * starts counting down (seconds is the players' warning, 0 = at once), "cancelled" when an
     * admin calls it off. Posted to the server's discussion channel, like world saves; no pings.
     */
    public function handleShutdownNoticeWebhook(ServerRequestInterface $request, GameServer $gameserver): HttpResponse
    {
        $secret = $this->civ13->options['http_key'] ?? '';
        $key = $request->getQueryParams()['key'] ?? null;
        if (!is_string($secret) || $secret === '' || !is_string($key) || !hash_equals($secret, $key) || $gameserver->legacy_relay) {
            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
        }
        $data = self::decodeParamData($request);
        $event = $data['event'] ?? null;
        $kind = $data['kind'] ?? null;
        $seconds = $data['seconds'] ?? 0;
        $reason = $data['reason'] ?? null;
        if (!in_array($event, ['scheduled', 'cancelled'], true)
            || !in_array($kind, ['restart', 'shutdown', 'maintenance', 'update', 'fresh', 'reboot'], true)
            || !is_int($seconds) || $seconds < 0 || $seconds > 86400
            || ($reason !== null && (!is_string($reason) || strlen($reason) > 400 || str_contains($reason, "\0")))) {
            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
        }
        if (!isset($gameserver->discussion) || !$channel = $this->discord->getChannel($gameserver->discussion)) {
            return new HttpResponse(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
        }
        $name = is_string($gameserver->name ?? null) && $gameserver->name !== '' ? $gameserver->name : $gameserver->key;
        $time = '['.date('H:i:s').']';
        $what = match ($kind) {
            'shutdown' => 'save and shut down',
            'maintenance' => 'save and go down for maintenance',
            'update' => 'move to an updated build',
            'reboot' => 'restart',
            default => 'save and restart',
        };
        $noun = match ($kind) {
            'shutdown', 'maintenance' => 'shutdown',
            'update' => 'update',
            default => 'restart',
        };
        if ($event === 'cancelled') {
            $message = "{$time} **{$name}**: the planned {$noun} was called off.";
        } else {
            $when = $seconds === 0 ? 'now' : 'in '.self::shutdownNoticeDuration($seconds);
            $message = "{$time} **{$name}** will {$what} {$when}.";
        }
        if (is_string($reason) && ($reason = trim(preg_replace('/[\x00-\x1F\x7F@<>`*_~|]+/u', ' ', strip_tags(html_entity_decode($reason))) ?? '')) !== '') {
            $message .= ' ('.mb_substr($reason, 0, 200).')';
        }
        $this->civ13->sendMessage($channel, $message, prevent_mentions: true)->then(null, function (\Throwable $error): void {
            $this->logger->error('Queued shutdown notice failed', ['exception' => $error]);
        });
        return new HttpResponse(HttpResponse::STATUS_ACCEPTED);
    }

    /**
     * In-game verification (Civ13 code/modules/client/discord_verify.dm): the game POSTs the
     * player's ckey and the code they typed as JSON, with the webhook key in the X-Webhook-Key
     * header, so neither secret is ever part of a URL. The primary bot redeems it; a desktop bot
     * with a read-only verifier relays it, encrypted, to the primary bot. Once authenticated the
     * answer is always 200 JSON {ok, message}, which the game shows to the player.
     */
    public function handleDiscordVerifyWebhook(ServerRequestInterface $request, GameServer $gameserver): HttpResponse
    {
        $secret = $this->civ13->options['http_key'] ?? '';
        $key = $request->getHeaderLine('X-Webhook-Key');
        if (!is_string($secret) || $secret === '' || $key === '' || !hash_equals($secret, $key) || $gameserver->legacy_relay) {
            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
        }
        if ($request->getMethod() !== 'POST') {
            return new HttpResponse(HttpResponse::STATUS_METHOD_NOT_ALLOWED);
        }
        $body = (string) $request->getBody();
        $data = strlen($body) <= 1024 ? json_decode($body, true, 4) : null;
        if (!is_array($data) || !$data = self::inGameVerifyRequest($data)) {
            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
        }
        if (!$this->civ13->verifier->readOnly) {
            return HttpResponse::json($this->civ13->verifier->redeemInGame($data['ckey'], $data['code']));
        }

        // The web API runs each request in a fiber (bot.php), so waiting here does not block the loop.
        return HttpResponse::json(\React\Async\await($this->relayInGameVerification($data['ckey'], $data['code'])));
    }

    /**
     * Primary bot: in-game registrations relayed by a desktop bot that shares INGAME_VERIFY_TOKEN.
     * Request and answer are sealed with it ({@see InGameVerification::seal()}), so the code is
     * never readable on the wire even over plain HTTP, and the answer is bound to its request.
     */
    public function handleInGameVerifyRelay(ServerRequestInterface $request): HttpResponse
    {
        $secret = $this->civ13->options['ingame_verify_key'] ?? '';
        if (!is_string($secret) || $secret === '' || $this->civ13->verifier->readOnly) {
            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
        }
        if ($request->getMethod() !== 'POST') {
            return new HttpResponse(HttpResponse::STATUS_METHOD_NOT_ALLOWED);
        }
        $opened = InGameVerification::open($secret, 'request', (string) $request->getBody());
        if ($opened === null) {
            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
        }
        if (!$data = self::inGameVerifyRequest($opened['payload'])) {
            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
        }
        $answer = $this->civ13->verifier->redeemInGame($data['ckey'], $data['code']);

        return HttpResponse::plaintext(InGameVerification::seal($secret, 'response', $answer, $opened['nonce']))
            ->withHeader('Content-Type', 'application/json');
    }

    /** Desktop bot: forwards to the primary bot, sealed both ways. Resolves {ok, message}; never rejects. */
    public function relayInGameVerification(string $ckey, string $code): PromiseInterface
    {
        $url = InGameVerification::relayUrl($this->civ13->verifier->verify_url);
        $key = $this->civ13->options['ingame_verify_key'] ?? '';
        if ($url === null || !is_string($key) || $key === '') {
            return \React\Promise\resolve(['ok' => false, 'message' => 'Verifying from this server is not set up yet. Use /approveme in Discord instead.']);
        }
        $body = InGameVerification::seal($key, 'request', ['ckey' => $ckey, 'code' => $code]);
        $nonce = InGameVerification::nonceOf($body);

        return $this->civ13->browser->withTimeout(10.0)->withRejectErrorResponse(false)->post(
            $url,
            ['Content-Type' => 'application/json'],
            $body
        )->then(function (ResponseInterface $response) use ($key, $nonce): array {
            $opened = $response->getStatusCode() === 200 ? InGameVerification::open($key, 'response', (string) $response->getBody(), $nonce) : null;
            $answer = $opened['payload'] ?? null;
            if (is_array($answer) && is_bool($answer['ok'] ?? null) && is_string($answer['message'] ?? null)) {
                return ['ok' => $answer['ok'], 'message' => mb_substr($answer['message'], 0, 400)];
            }
            $this->logger->warning('In-game verification relay refused', ['status' => $response->getStatusCode()]);

            return ['ok' => false, 'message' => 'The main Civ13 bot refused the request. Try again later, or use /approveme in Discord.'];
        }, function (\Throwable $error): array {
            $this->logger->warning('In-game verification relay unreachable', ['error' => $error->getMessage()]);

            return ['ok' => false, 'message' => 'The main Civ13 bot could not be reached. Try again later, or use /approveme in Discord.'];
        });
    }

    /** {ckey, code} from the game or a relay, or null. The game sends a BYOND ckey: lower-case letters and digits. */
    public static function inGameVerifyRequest(array $data): ?array
    {
        if (!is_string($data['ckey'] ?? null) || preg_match('/^[a-z0-9]{1,32}$/D', $data['ckey']) !== 1
            || !is_string($data['code'] ?? null) || $data['code'] === '' || strlen($data['code']) > 32) {
            return null;
        }

        return ['ckey' => $data['ckey'], 'code' => $data['code']];
    }

    /** "2 minutes", "1 minute 30 seconds", "45 seconds". */
    public static function shutdownNoticeDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;
        if ($minutes === 0) {
            return $rest.' second'.($rest === 1 ? '' : 's');
        }
        $text = $minutes.' minute'.($minutes === 1 ? '' : 's');
        return $rest ? $text.' '.$rest.' second'.($rest === 1 ? '' : 's') : $text;
    }

    /**
     * Whether a staff-channel line from the game should ping @Admin.
     * false: never (staff wrote it, or the game reports staff online);
     * true: ping (the game reports no staff online);
     * null: the game sent no presence fields, so guess from Discord presence.
     */
    public static function staffWebhookNeedsPing(array $data): ?bool
    {
        $truthy = static fn ($value): bool => $value === true || $value === 1 || $value === '1';
        if ($truthy($data['from_staff'] ?? null)) { return false; }
        if (($data['kind'] ?? null) === 'asay') { return false; } // Only staff can asay.
        // Games before the presence fields still mark asay lines in the text.
        if (! isset($data['kind']) && is_string($data['message'] ?? null) && preg_match('/^__\*\*\[[^\]]*\] \([^)]*\) ASAY:\*\*__/', $data['message'])) { return false; }
        if (isset($data['staff_online']) && is_numeric($data['staff_online'])) { return (int) $data['staff_online'] <= 0; }

        return null;
    }

    /** Staff messages preserve display text without inferring account authority. */
    public function handleStaffWebhook(ServerRequestInterface $request, GameServer $gameserver, string $kind = 'asay'): HttpResponse
    {
        if (!in_array($kind, ['asay', 'ahelp', 'urgent'], true)) { return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST); }
        $secret = $this->civ13->options['http_key'] ?? '';
        $supplied = $request->getQueryParams()['key'] ?? null;
        if (!is_string($secret) || $secret === '' || !is_string($supplied) || !hash_equals($secret, $supplied)) {
            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
        }
        if ($gameserver->legacy_relay) {
            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
        }
        if (! isset($gameserver->asay)) {
            return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
        }
        if (! $channel = $this->discord->getChannel($channel_id = $gameserver->asay)) {
            return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
        }

        $data = self::decodeParamData($request);
        $time = '['.date('H:i:s', time()).']';
        // Native asay's ckey currently contains key_name display text, not proven account identity.
        if (!is_string($data['message'] ?? null) || $data['message'] === '' || strlen($data['message']) > 16384 || str_contains($data['message'], "\0")) {
            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
        }
        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
        //$message = "**__{$time} ASAY__ $ckey:** $message";
        $message = ($kind === 'ahelp' ? "**__{$time} AHELP__:** " : "**__{$time}__** ").$message;
        if ($kind === 'urgent' && isset($this->civ13->role_ids['Admin'])) { $message = "<@&{$this->civ13->role_ids['Admin']}>, ".$message; }

        $gameserver->gameChatWebhookRelay($message, $channel_id, null, true, false)
            ->then(null, fn (\Throwable $error) => $this->logger->error('Staff display relay failed: '.$error->getMessage()));

        if ($kind === 'urgent') { return new HttpResponse(HttpResponse::STATUS_ACCEPTED); }

        // Staff wrote it, or the game says staff are online: never ping.
        $presence = self::staffWebhookNeedsPing($data);
        if ($presence === false) { return new HttpResponse(HttpResponse::STATUS_ACCEPTED); }

        // Notify staff based on verified online Discord members only.
        if (isset($this->civ13->role_ids['Admin']) && $guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)) {
            $urgent = true;
            $admin = $presence === true; // The game reported no online staff; skip the Discord guess.
            if (isset($this->civ13->verifier)) {
                if (! $admin) {
                    if ($playerlist = array_map('strtolower', $gameserver->players)) {
                        if ($admins = $guild->members->filter(function (Member $member) {
                            return $member->roles->has($this->civ13->role_ids['Admin']);
                        })) {
                            try {
                                foreach ($admins as $member) {
                                    if ($item = $this->civ13->verifier->get('discord', $member->id)) {
                                        if (in_array(strtolower((string) $item['ss13']), $playerlist, true)) {
                                            $urgent = false;
                                            break;
                                        }
                                    }
                                }
                            } catch (\Civ13\Exceptions\VerifierException $e) {
                                // Online staff cannot be proven, so notify rather than fail the
                                // already-relayed message with a 500.
                                $this->logger->warning("Staff presence lookup unavailable for {$kind}: {$e->getMessage()}");
                                $urgent = true;
                            }
                        }
                    }
                }
            }
            if ($urgent && isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                // Keep the ping in plain content: sendMessage() moves content over 2000 bytes into an embed/file, which never pings.
                $prefix = "<@&{$this->civ13->role_ids['Admin']}>, an urgent asay message has been received in the {$gameserver->name} server. Please see the relevant message in <#{$gameserver->asay}>: `";
                $excerpt = $message;
                $budget = max(0, 2000 - strlen($prefix) - 4); // closing backtick and '...'
                if (strlen($excerpt) > $budget) { // Byte cut without ext-mbstring; drop any split trailing UTF-8 sequence.
                    $excerpt = preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', substr($excerpt, 0, $budget)).'...';
                }
                $this->civ13->sendMessage($channel, $prefix.$excerpt.'`');
            }
        }

        return new HttpResponse(HttpResponse::STATUS_ACCEPTED);
    }

    /** Authenticated bounded display logs; account sanctions never derive from log text. */
    public function handleLogWebhook(ServerRequestInterface $request, GameServer $gameserver, string $kind): HttpResponse
    {
        $routes = ['runtime' => ['runtime', 'RUNTIME'], 'admin' => ['adminlog', 'ADMIN LOG'], 'attack' => ['attack', 'ATTACK LOG']];
        if (!isset($routes[$kind])) { return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST); }
        $secret = $this->civ13->options['http_key'] ?? '';
        $supplied = $request->getQueryParams()['key'] ?? null;
        if (!is_string($secret) || $secret === '' || !is_string($supplied) || !hash_equals($secret, $supplied)) { return new HttpResponse(HttpResponse::STATUS_FORBIDDEN); }
        if ($gameserver->legacy_relay || ($kind === 'attack' && !$gameserver->log_attacks)) { return new HttpResponse(HttpResponse::STATUS_FORBIDDEN); }
        [$property, $label] = $routes[$kind];
        $channel = $gameserver->$property ?? null;
        if (!$channel || !$this->discord->getChannel($channel)) { return new HttpResponse(HttpResponse::STATUS_SERVICE_UNAVAILABLE); }
        $data = self::decodeParamData($request);
        if (!is_string($data['message'] ?? null) || $data['message'] === '' || strlen($data['message']) > 16384 || str_contains($data['message'], "\0")) { return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST); }
        foreach (['ckey', 'ckey2'] as $field) {
            if (array_key_exists($field, $data) && (!is_string($data[$field]) || strlen($data[$field]) > 256 || str_contains($data[$field], "\0"))) { return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST); }
        }
        $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message'])));
        if ($message === '') { return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST); }
        $message = '**__['.date('H:i:s').'] '.$label.'__:** '.$message;
        if ($kind === 'attack' && ($first = Civ13::sanitizeInput($data['ckey'] ?? '')) !== '' && $first === Civ13::sanitizeInput($data['ckey2'] ?? '')) { $message .= ' (Self-Attack)'; }
        $gameserver->gameChatWebhookRelay($message, $channel, null, true, false)
            ->then(null, fn (\Throwable $error) => $this->logger->error('Game display-log relay failed: '.$error->getMessage()));
        return new HttpResponse(HttpResponse::STATUS_ACCEPTED);
    }

    /**
     * Starts a session for the given IP address.
     *
     * This method initializes a session for the specified IP address if it does not already exist.
     * It also ensures that any existing timer associated with the IP address is canceled before
     * creating a new timer. The session will automatically timeout after 30 minutes if no further
     * activity occurs.
     *
     * @param string $ip
     */
    protected function startSession(string $ip): void
    {
        if (isset($this->dwa_sessions[$ip])) {
            return;
        } // Session already exists
        $this->logger->info("Starting session for $ip");
        $this->dwa_sessions[$ip] = [];
        if (isset($this->dwa_timers[$ip]) && $this->dwa_timers[$ip] instanceof TimerInterface) {
            $this->discord->getLoop()->cancelTimer($this->dwa_timers[$ip]);
            unset($this->dwa_timers[$ip]);
        }
        $this->dwa_timers[$ip] = $this->discord->getLoop()->addTimer(30 * 60, fn () => $this->timeoutSession($ip)); // Timeout after 30 minutes
    }

    /**
     * Terminates a session for the given IP address.
     *
     * This method ensures that any active timers linked to the session are properly canceled
     * using the event loop, and both the timer and session data are cleaned up.
     *
     * @param string $ip
     */
    protected function endSession(string $ip): void
    {
        $this->logger->info("Ending session for $ip");
        if (isset($this->dwa_timers[$ip]) && $this->dwa_timers[$ip] instanceof TimerInterface) {
            $this->discord->getLoop()->cancelTimer($this->dwa_timers[$ip]);
            unset($this->dwa_timers[$ip]);
        }
        unset($this->dwa_sessions[$ip]);
    }

    /**
     * Terminates a session for the specified IP address after a timeout.
     *
     * Logs the timeout event and ends the session associated with the given IP address.
     *
     * @param string $ip
     */
    protected function timeoutSession(string $ip): void
    {
        $this->logger->info("Timeout session for $ip");
        $this->endSession($ip);
    }

    /**
     * Populates the whitelist with IP addresses of verified Ambassadors.
     *
     * This method retrieves the guild associated with the Civ13 guild ID and filters its members
     * to find those with the "Ambassador" role. For each Ambassador, it checks if they have a
     * verified item associated with them. If the verified item contains an SS13 ckey, it retrieves
     * the player's log collections and extracts their IP addresses. These IP addresses are then
     * added to the whitelist using the HTTP handler.
     */
    protected function __populateWhitelist(): void
    {
        if ($this->httpHandler && $this->civ13->civ13_guild_id && $guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)) { // Whitelist the IPs of all Ambassador            $members = $guild->members->filter(function ($member) {
            foreach ($guild->members->filter(fn ($member) => $member->roles->has($this->civ13->role_ids['Ambassador'])) as $member) {
                if ($item = $this->civ13->verifier->getVerifiedItem($member)) {
                    if (isset($item['ss13']) && $ckey = $item['ss13']) {
                        if ($playerlogs = $this->civ13->getCkeyLogCollections($ckey)['playerlogs']) {
                            foreach ($playerlogs as $log) {
                                if (isset($log['ip'])) {
                                    $this->httpHandler->whitelist($log['ip']);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /** Registers the core web-API routes (channel browser, message/embed senders, admin actions), then delegates to the per-server and website route generators. */
    protected function __generateEndpoints()
    {
        $this->__generateLauncherDirectoryEndpoint();
        if ($this->civ13->calendarSync !== null && $this->civ13->calendarSync->exposesHttp()) {
            $calendar = $this->civ13->calendarSync;
            $endpoint = new \Civ13\Calendar\Endpoint($calendar->authority(), fn () => $calendar->snapshot());
            $this->httpHandler->offsetSet(\Civ13\Calendar\Endpoint::PATH,
                static fn (ServerRequestInterface $request, string $path, bool $whitelisted): HttpResponse => $endpoint->handle($request),
                false, 'exact', 'Read-only sanitized community event snapshot')
                ->setRateLimit(\Civ13\Calendar\Endpoint::PATH, 120, 60);
        }
        $this->httpHandler
            ->offsetSet(
                '/get-channels',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
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

                    /** @var \Discord\Parts\Guild\Guild $guild An instance of a Discord guild. */
                    foreach ($this->discord->guilds as $guild) {
                        $guildDiv = $doc->createElement('div');
                        $guildDiv->setAttribute('class', 'guild');
                        $guildName = $doc->createElement('h3');
                        $a = $doc->createElement('a', $guild->name);
                        $a->setAttribute('href', 'https://discord.com/channels/'.$guild->id);
                        $a->setAttribute('target', '_blank');
                        $guildName->appendChild($a);
                        $guildDiv->appendChild($guildName);

                        // CSS for .channel class
                        $channelStyle = $doc->createElement('style', '.channel { margin-left: 20px; }');
                        $guildDiv->appendChild($channelStyle);
                        
                        $channels = $guild->channels
                            ->filter(static fn ($channel) => $channel->isTextBased())
                            ->sort(static fn ($a, $b) => $a->position <=> $b->position);

                        foreach ($channels as $channel) {
                            $channelDiv = $doc->createElement('div');
                            $channelDiv->setAttribute('class', 'channel');

                            $channelName = $doc->createElement('div');
                            $channelSpan = $doc->createElement('span');
                            $a = $doc->createElement('a', $channel->name);
                            $a->setAttribute('href', 'https://discord.com/channels/'.$guild->id.'/'.$channel->id);
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
                },
                true
            )
            ->offsetSet(
                '/send-message',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    $params = $request->getQueryParams();

                    isset($params['channel']) ? $channelId = $params['channel'] : $channelId = null;
                    if (! $channelId || ! $channel = $this->discord->getChannel($channelId)) {
                        return HttpResponse::json(['error' => "Channel `$channelId` not found"]);
                    }
                    if (! $channel->isTextBased()) {
                        return HttpResponse::json(['error' => "Cannot send messages to channel `$channelId`"]);
                    }

                    isset($params['message']) ? $message = $params['message'] : $message = null;
                    if (! $message) {
                        return HttpResponse::json(['error' => 'Message not found']);
                    }

                    $channel->sendMessage($message);

                    return HttpResponse::json(['success' => true]);
                },
                true
            )
            ->offsetSet(
                '/send-embed',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    $params = $request->getQueryParams();

                    isset($params['channel']) ? $channelId = $params['channel'] : $channelId = null;
                    if (! $channel = $this->discord->getChannel($channelId)) {
                        return HttpResponse::json(['error' => "Channel `$channelId` not found"]);
                    }
                    if (! $channel->isTextBased()) {
                        return HttpResponse::json(['error' => "Cannot send messages to channel `$channelId`"]);
                    }

                    isset($params['message']) ? $content = $params['message'] : $content = '';
                    if (! $content) {
                        return HttpResponse::json(['error' => 'Message not found']);
                    }

                    $builder = Civ13::createBuilder();
                    if (isset($this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']]) && $user = $this->discord->users->get('id', $this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']])) { // This will not work if the user didn't login with oauth2 during this runtime session (i.e. the bot was restarted)
                        $builder->addEmbed(
                            $this->civ13->createEmbed()
                            ->setAuthor("{$user->username} ({$user->id})", $user->avatar)
                            ->addField('Message', $content)
                            ->setFooter($this->civ13->embed_footer)
                        );
                    } else {
                        $builder->setContent($content);
                        $this->logger->info('Either the IP was not associated with a user or no user could be found.');
                        $this->logger->info("IP: {$request->getServerParams()['REMOTE_ADDR']}");
                        if (isset($this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']])) {
                            $this->logger->info("Discord ID: {$this->dwa_discord_ids[$request->getServerParams()['REMOTE_ADDR']]}");
                        }
                    }
                    
                    $channel->sendMessage($builder);

                    return HttpResponse::json(['success' => true]);
                },
                true
            )
            // HttpHandler website endpoints
            ->offsetSets(
                ['/', '/index.html', '/index.php'],
                fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => ($whitelisted && $method = $this->httpHandler->offsetGet('/botlog', 'handlers'))
                        ? ($method($request, $endpoint, $whitelisted))
                        : (($method = $this->httpHandler->offsetGet('/home.html', 'handlers'))
                            ? ($method($request, $endpoint, $whitelisted))
                            : (new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => 'https://www.valzargaming.com/?login'])))
            )
            ->offsetSet(
                '/robots.txt',
                static fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => HttpResponse::plaintext('User-agent: *'.PHP_EOL.'Disallow: /')
            )
            ->offsetSet(
                '/github',
                fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => new HttpResponse(HttpResponse::STATUS_MOVED_PERMANENTLY, ['Location' => $this->civ13->github])
            )
            ->offsetSet(
                $endpoint = '/.well-known/security.txt',
                fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => HttpResponse::plaintext(//'Contact: mailto:valithor@valzargaming.com' . PHP_EOL .
                    "Contact: {$this->civ13->github}".PHP_EOL.
                    'Acknowledgments: https://github.com/orgs/discord-php/teams/contributors'.PHP_EOL.
                    'Preferred-Languages: en'.PHP_EOL.
                    "Canonical: http://{$this->httpHandler->external_ip}:{$this->http_port}/.well-known/security.txt".PHP_EOL.
                    'Policy: http://valzargaming.com/legal'
                )
            )
                ->setRateLimit($endpoint, 1, 10) // 1 request per 10 seconds
            ->offsetSet(
                '/ping',
                static fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => HttpResponse::plaintext('Hello wörld!')
            )
            ->offsetSet(
                '/favicon.ico',
                static fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => ($favicon = @file_get_contents('favicon.ico'))
                        ? new HttpResponse(HttpResponse::STATUS_OK, ['Content-Type' => 'image/x-icon', 'Cache-Control' => 'public, max-age=2592000'], $favicon)
                        : new HttpResponse(HttpResponse::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain'], 'Unable to access `favicon.ico`')
            )
            // HttpHandler management endpoints
            ->offsetSet(
                '/reset',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    if (!OSFunctions::legacyShellAvailable()) {
                        return HttpResponse::plaintext('Legacy maintenance is unavailable in Windows/portable mode.')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
                    }
                    OSFunctions::execInBackground('git reset --hard origin/main');
                    $message = 'Forcefully moving the HEAD back to origin/main...';
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                        $this->civ13->sendMessage($channel, $message);
                    }

                    return HttpResponse::plaintext($message);
                },
                true
            )
            ->offsetSet(
                '/githubupdated',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    if (!OSFunctions::legacyShellAvailable()) {
                        return HttpResponse::plaintext('Legacy maintenance is unavailable in Windows/portable mode.')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
                    }
                    $secret = (string) getenv('github_secret');
                    $body = strval($request->getBody());
                    // Prefer the SHA-256 signature GitHub sends; fall back to SHA-1.
                    $sig256 = $request->getHeaderLine('X-Hub-Signature-256');
                    $sig1 = $request->getHeaderLine('X-Hub-Signature');

                    $valid = $secret !== '' && (
                        ($sig256 !== '' && hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $sig256))
                        || ($sig1 !== '' && hash_equals('sha1='.hash_hmac('sha1', $body, $secret), $sig1))
                    );

                    if (! $valid) {
                        // Log only non-sensitive request metadata, never the raw headers.
                        $meta = json_encode([
                            'delivery' => $request->getHeaderLine('X-GitHub-Delivery'),
                            'event' => $request->getHeaderLine('X-GitHub-Event'),
                            'ua' => $request->getHeaderLine('User-Agent'),
                            'has_sig' => $sig256 !== '' || $sig1 !== '',
                            'secret_configured' => $secret !== '',
                        ]);
                        $this->logger->warning("Rejected unsigned/invalid webhook on `$endpoint`: $meta");
                        $tech_ping = isset($this->civ13->technician_id) ? "<@{$this->civ13->technician_id}>, " : '';
                        if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                            $this->civ13->sendMessage($channel, $tech_ping."Rejected unsigned/invalid webhook on `$endpoint` endpoint.");
                        }

                        return new HttpResponse(HttpResponse::STATUS_UNAUTHORIZED);
                    }
                    if (! $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                        return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                    }
                    $promise = isset($this->civ13->restart_message) && $this->civ13->restart_message instanceof Message
                        ? $this->civ13->restart_message->edit(Civ13::createBuilder()->setContent('Updating code from GitHub... (1/2)'))
                        : $this->civ13->sendMessage($channel, 'Updating code from GitHub... (1/2)');
                    $promise->then(fn (Message $message) => OSFunctions::execInBackground('git pull'));
                    $this->discord->getLoop()->addTimer(
                        5,
                        fn (): PromiseInterface => $promise
                        ->then(static fn (Message $message): PromiseInterface => $message->edit(Civ13::createBuilder()->setContent('Forcefully moving the HEAD back to origin/main... (2/2)')))
                        ->then(fn (Message $message) => $this->civ13->restart_message = $message)
                        ->then(static fn () => OSFunctions::execInBackground('git reset --hard origin/main'))
                        /*if (isset($this->civ13->timers['restart_pending']) && $this->civ13->timers['restart_pending'] instanceof TimerInterface) $this->discord->getLoop()->cancelTimer($this->civ13->timers['restart_pending']);
                        $this->civ13->timers['restart_pending'] = $this->discord->getLoop()->addTimer(300, fn() =>
                            (isset($this->civ13->restart_message) && $this->civ13->restart_message instanceof Message)
                                ? $this->civ13->restart_message->edit(Civ13::createBuilder()->setContent('Restarting...'))->then(fn() => $this->civ13->restart())
                                : $this->civ13->sendMessage($channel, 'Restarting...')->then(fn() => $this->civ13->restart())
                        );*/
                    );

                    return new HttpResponse(HttpResponse::STATUS_OK);
                }
            )
            ->offsetSet(
                '/cancelupdaterestart',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    if (!OSFunctions::legacyShellAvailable()) {
                        return HttpResponse::plaintext('Legacy maintenance is unavailable in Windows/portable mode.')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
                    }
                    if (isset($this->civ13->timers['restart_pending']) && $this->civ13->timers['restart_pending'] instanceof TimerInterface) {
                        $this->discord->getLoop()->cancelTimer($this->civ13->timers['restart_pending']);
                        unset($this->civ13->timers['restart_pending']);
                        if (isset($this->civ13->restart_message) && $this->civ13->restart_message instanceof Message) {
                            $this->civ13->restart_message->edit(Civ13::createBuilder()->setContent('Restart cancelled.'));
                        }

                        return HttpResponse::plaintext('Restart cancelled.');
                    }

                    return HttpResponse::plaintext('No restart pending.');
                }
            )
            ->offsetSet(
                '/pull',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    if (!OSFunctions::legacyShellAvailable()) {
                        return HttpResponse::plaintext('Legacy maintenance is unavailable in Windows/portable mode.')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
                    }
                    OSFunctions::execInBackground('git pull');
                    $message = 'Updating code from GitHub...';
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                        $this->civ13->sendMessage($channel, $message);
                    }

                    return HttpResponse::plaintext($message);
                },
                true
            )
            ->offsetSet(
                '/update',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    if (!OSFunctions::legacyShellAvailable()) {
                        return HttpResponse::plaintext('Legacy maintenance is unavailable in Windows/portable mode.')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
                    }
                    OSFunctions::execInBackground('composer update');
                    $message = 'Updating dependencies...';
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                        $this->civ13->sendMessage($channel, $message);
                    }

                    return HttpResponse::plaintext($message);
                },
                true
            )
            ->offsetSet(
                '/restart',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    if (!OSFunctions::legacyShellAvailable()) {
                        return HttpResponse::plaintext('Legacy maintenance is unavailable in Windows/portable mode.')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
                    }
                    $message = 'Manually Restarting...';
                    if (isset($this->civ13->restart_message) && $this->civ13->restart_message instanceof Message) {
                        $this->civ13->restart_message->edit(Civ13::createBuilder()->setContent('Manually Restarting...'))->then(fn () => $this->civ13->restart());

                        return HttpResponse::plaintext($message);
                    }
                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                        $this->civ13->sendMessage($channel, $message)->then(fn () => $this->civ13->restart());
                    }

                    return HttpResponse::plaintext($message);
                },
                true
            )
            ->offsetSet(
                '/kill',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    if (!OSFunctions::legacyShellAvailable()) {
                        return HttpResponse::plaintext('Legacy maintenance is unavailable in Windows/portable mode.')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
                    }
                    $message = 'Shutting down...';
                    $this->discord->getLoop()->addTimer(3, fn () => $this->civ13->restart());

                    return HttpResponse::plaintext($message);
                },
                true
            )
            ->offsetSet(
                '/clearenv',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    if (!OSFunctions::legacyShellAvailable()) {
                        return HttpResponse::plaintext('Legacy maintenance is unavailable in Windows/portable mode.')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
                    }
                    $message = 'Clearing .env...';
                    if (file_exists($env = getcwd().'/.env')) {
                        file_put_contents($env, '');
                    }

                    return HttpResponse::plaintext($message);
                },
                true
            )
            ->offsetSet(
                '/updateadmins',
                fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->queueAdminListUpdate(),
                true
            )
            ->offsetSet(
                '/bancheck_centcom',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse {
                    $params = $request->getQueryParams();
                    if (! isset($params['ckey'])) {
                        return HttpResponse::plaintext('`ckey` must be included as a query parameter')->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                    }
                    if (is_numeric($ckey = $params['ckey'])) {
                        if (! $item = $this->civ13->verifier->get('discord', $ckey)) {
                            return HttpResponse::plaintext("Unable to locate Byond username for Discord ID `$ckey`")->withStatus(HttpResponse::STATUS_BAD_REQUEST);
                        }
                        $ckey = $item['ss13'];
                    }
                    if (! $json = Byond::bansearch_centcom($ckey, false)) {
                        return HttpResponse::plaintext("Unable to locate bans for `$ckey` on CentCom")->withStatus(HttpResponse::STATUS_OK);
                    }

                    return new HttpResponse(HttpResponse::STATUS_OK, ['Content-Type' => 'application/json'], $json);
                }
            )
            // HttpHandler log endpoints
            ->offsetSets(
                ['/botlog', '/botlog2'],
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    $webpage_content = function (string $return) use ($endpoint) {
                        return '<meta name="color-scheme" content="light dark"> 
                                <div class="button-container">
                                    <button style="width:8%" onclick="sendGetRequest(\'pull\')">Pull</button>
                                    <button style="width:8%" onclick="sendGetRequest(\'reset\')">Reset</button>
                                    <button style="width:8%" onclick="sendGetRequest(\'update\')">Update</button>
                                    <button style="width:8%" onclick="sendGetRequest(\'restart\')">Restart</button>
                                    <button style="background-color: black; color:white; display:flex; justify-content:center; align-items:center; height:100%; width:68%; flex-grow: 1;" onclick="window.open(\''.$this->civ13->github.'\')">'.$this->discord->username.'</button>
                                </div>
                                <div class="alert-container"></div>
                                <div class="checkpoint">'.
                                    str_replace(
                                        '['.date('Y'),
                                        '</div><div> ['.date('Y'),
                                        str_replace([PHP_EOL, '[] []', ' [] '], '</div><div>', $return)
                                    ).
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
                                            }, 80000);
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
                                                        width += 1.25;
                                                        loadingBar.style.width = width + '%';
                                                    }
                                                }, 800);
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
                                                        }, 80000);
                                                    }
                                                }, 80000);
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
                                        .($endpoint === '/botlog' ? "<button onclick=\"location.href='/botlog2'\">Botlog 2</button>" : "<button onclick=\"location.href='/botlog'\">Botlog 1</button>")
                                    ."</div>
                                    <div class='reload-container'>
                                        <div class='checkbox-container'>
                                            <input type='checkbox' id='auto-reload-checkbox' ".(isset($_COOKIE['auto-reload']) && $_COOKIE['auto-reload'] === 'true' ? 'checked' : '').">
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
                    if (! $return = @file_get_contents($fp = 'output.log')) {
                        return $this->httpHandler->__throwError("Unable to access `$fp`", HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                    }

                    return HttpResponse::html($webpage_content($return));
                },
                true
            )
            ->offsetSet(
                '/verified',
                fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse =>
                    (isset($this->civ13->verifier->readOnly) && $this->civ13->verifier->readOnly && !$this->civ13->verifier->hostedStatus()['available'])
                    ? HttpResponse::json(['error' => 'Hosted verification unavailable', 'source' => $this->civ13->verifier->hostedStatus()])->withStatus(503)
                    : HttpResponse::json($this->civ13->verifier->verified
                        ->filter(fn ($item) => $item['ss13'] && $item['discord'])
                        ->toArray()
                ),
                false
            )
            ->offsetSet(
                '/verified/ingame', // Desktop bots relay in-game verification here (INGAME_VERIFY_TOKEN)
                fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleInGameVerifyRelay($request),
                false
            )
            ->offsetSet(
                '/ss14verified',
                fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => ($this->civ13->ss14verifier === null ? HttpResponse::json(['error' => 'SS14 verification unavailable'])->withStatus(503) : HttpResponse::json($this->civ13->ss14verifier->toArray())),
                false
            )
            ->offsetSet(
                $endpoint = '/contact',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                    $parsedBody = $request->getParsedBody();
                    $ip = $request->getServerParams()['REMOTE_ADDR'];
                    $ckey = htmlspecialchars($parsedBody['ckey'] ?? 'Anonymous');
                    $email = htmlspecialchars($parsedBody['email'] ?? 'No email provided');
                    $messageContent = htmlspecialchars($parsedBody['message'] ?? 'No message provided');
                    
                    $embed = $this->civ13->createEmbed()
                        ->addFieldValues('IP', $ip)
                        ->addFieldValues('Byond Username', $ckey)
                        ->addFieldValues('Email', $email)
                        ->addFieldValues('Message', $messageContent)
                        ->setAuthor('Anonymous', $this->discord->avatar);
                    if ($item = $this->civ13->verifier->getVerifiedItem(Civ13::sanitizeInput($ckey))) {
                        if (isset($item['discord']) && $user = $this->discord->users->get('id', $item['discord'])) {
                            $embed->setAuthor("{$user->username} ({$user->id})", $user->avatar ?? $this->discord->avatar);
                        }
                    }
                    // Do not log the submitter's email or message body — they go
                    // to the private #email channel embed above, not the logs.
                    $this->logger->info(sprintf('[CONTACT FORM] IP: %s, Byond Username: %s, message %d chars', $ip, $ckey, strlen((string) $messageContent)));
                    if (isset($this->civ13->channel_ids['email']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['email'])) {
                        $channel->sendMessage(Civ13::createBuilder()->addEmbed($embed));
                    }

                    return HttpResponse::plaintext('Form submitted successfully');
                }
            )
                ->setRateLimit($endpoint, 1, 43200) // 1 form every 12 hours

            // HttpHandler data endpoints
            /*
            ->offsetSet('/endpoint',
                function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse
                {
                    return HttpResponse::plaintext("Hello wörld!\n");
                    return HttpResponse::html("<!doctype html><html><body>Hello wörld!</body></html>");
                    return new HttpResponse(
                        HttpResponse::STATUS_OK,
                        ['Content-Type' => 'application/json'],
                        json_encode($json ?? '')
                    );
                })
            */;
        // HttpHandler whitelisting with DiscordWebAuth
        if ($dwa_client_id = getenv('dwa_client_id')) {
            if ($dwa_client_secret = getenv('dwa_client_secret')) {
                $this->httpHandler
                    ->offsetSet(
                        '/dwa',
                        function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use ($dwa_client_id, $dwa_client_secret): HttpResponse {
                            if (! isset($this->civ13->technician_id)) {
                                return new HttpResponse(HttpResponse::STATUS_INTERNAL_SERVER_ERROR, [], 'Technician ID not set in configuration.');
                            }

                            //return new HttpResponse(HttpResponse::STATUS_FORBIDDEN); // Moved to verifier server
                            $ip = $request->getServerParams()['REMOTE_ADDR'];
                            $this->startSession($ip);
                            if (isset($this->dwa_sessions[$ip]['oauth_steam_url'])) {
                                $this->logger->info("[DWA] Steam URL for `$ip`: {$this->dwa_sessions[$ip]['oauth_steam_url']}");
                            }

                            $DiscordWebAuth = new DiscordWebAuth($this->civ13, $this->dwa_sessions, $dwa_client_id, $dwa_client_secret, $this->web_address, $this->http_port, gethostbyname('www.civ13.com'), $request);

                            $params = $request->getQueryParams();
                            if (isset($params['code'], $params['state'])) {
                                // Never log the authorization code (it is an
                                // exchangeable credential).
                                $this->logger->info('[DWA] OAuth callback received; exchanging code.');

                                return $DiscordWebAuth->getToken((string) $params['state']);
                            }
                            if (isset($params['login'])) {
                                $this->logger->info('[DWA] Login requested');

                                return $DiscordWebAuth->login();
                            }
                            if (isset($params['logout'])) {
                                $this->logger->info('[DWA] Logout requested');

                                return $DiscordWebAuth->logout();
                            }
                            if (isset($params['remove']) && $DiscordWebAuth->isAuthed()) {
                                $this->logger->info('[DWA] Remove token requested');

                                return $DiscordWebAuth->removeToken();
                            }
                    
                            $tech_ping = "<@{$this->civ13->technician_id}>, ";
                            if (isset($DiscordWebAuth->user, $DiscordWebAuth->user->id) && $DiscordWebAuth->user->id == $this->civ13->technician_id) {
                                $this->dwa_discord_ids[$ip] = $DiscordWebAuth->user->id;
                                if (! $this->civ13->verifier->get('discord', $DiscordWebAuth->user->id)) {
                                    $this->logger->info("[DWA] Unauthorized Discord ID: {$DiscordWebAuth->user->id}");
                                    if (isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                                        $this->civ13->sendMessage($channel, $tech_ping."<@&$DiscordWebAuth->user->id> tried to log in with Discord but does not have permission to! Please check the logs.");
                                    }

                                    return new HttpResponse(HttpResponse::STATUS_UNAUTHORIZED);
                                }
                                if ($this->httpHandler->whitelist($ip) && isset($this->civ13->channel_ids['staff_bot']) && $channel = $this->discord->getChannel($this->civ13->channel_ids['staff_bot'])) {
                                    $this->logger->info("[DWA] Authorized Discord ID: {$DiscordWebAuth->user->id}");
                                    $this->civ13->sendMessage($channel, $tech_ping."<@{$DiscordWebAuth->user->id}> has logged in with Discord.");
                                }
                            }

                            return new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => "http://{$this->httpHandler->external_ip}:{$this->http_port}/botlog"]);
                        }
                    );
            }
        }
        // HttpHandler redirect endpoints
        if ($this->civ13->github) {
            $this->httpHandler
                ->offsetSet(
                    '/github',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => $this->civ13->github])
                );
        }

        if ($this->civ13->discord_invite) {
            $this->httpHandler
                ->offsetSet(
                    '/discord',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => new HttpResponse(HttpResponse::STATUS_FOUND, ['Location' => $this->civ13->discord_invite])
                );
        }

        $this->__generateServerEndpoints();
        $this->__generateWebsiteEndpoints();
    }

    /** Registers per-game-server web-API routes (`/{key}/bans`, etc.) for every enabled game server. */
    protected function __generateServerEndpoints()
    {
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            // General usage server endpoints
            $server_endpoint = '/'.$gameserver->key;
            $this->httpHandler
                ->offsetSet(
                    $server_endpoint.'/bans',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse {
                        if (! file_exists($bans = $gameserver->basedir.Civ13::bans)) {
                            return HttpResponse::plaintext("Unable to access `$bans`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        if (! $content = @file_get_contents($bans)) {
                            return HttpResponse::plaintext("Unable to read `$bans`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        // Current format: type;job;uid;reason;admin;date;timestamp;expires;ckey;cid;ip|||
                        $rows = explode('|||', trim($content));
                        $json = [];
                        $id = 0;
                        foreach ($rows as $data) {
                            if (! $ban = explode(';', $data)) {
                                continue;
                            }
                            if (! isset($ban[10])) {
                                continue;
                            } // Must be missing some data
                            /*$old_json[] = [
                                'type' => $ban[0],
                                'job' => $ban[1],
                                'uid' => $ban[2],
                                'reason' => $ban[3],
                                'admin' => $ban[4],
                                'date' => $ban[5],
                                'timestamp' => $ban[6],
                                'expires' => $ban[7],
                                'ckey' => $ban[8],
                                'cid' => $ban[9],
                                'ip' => $ban[10]
                            ];
                            */
                            
                            $ban_type = str_replace(PHP_EOL, '', $ban[0]);

                            $date = str_replace('.', ':', $ban[5]);
                            $dateTime = new \DateTime($date);
                            $banned_on_timestamp = $dateTime->getTimestamp();
                            $banned_on = date('c', $banned_on_timestamp);

                            $d = explode(' ', $ban[7]);
                            $dur['int'] = intval($d[2]);
                            $dur['unit'] = $d[3];
                            $expires_in = strtotime('+'.$dur['int'].' '.$dur['unit'], $banned_on_timestamp);
                            $expires_date = date('c', $expires_in);

                            $json[] = [
                                'id' => $id,
                                'banType' => $ban_type,
                                'ckey' => $ban[8],
                                'bannedOn' => $banned_on,
                                'bannedBy' => $ban[4],
                                'reason' => $ban[3],
                                'expires' => $expires_date,
                                'unbannedBy' => null,
                                'jobBans' => null,
                            ];
                            $id++;
                        }
                        $response = new HttpResponse(HttpResponse::STATUS_OK, ['Content-Type' => 'application/json'], json_encode($json ?? ''));
                        $response = $response->withHeader('Cache-Control', 'public, max-age=3600');

                        return $response;
                    }
                )

                ->offsetSet(
                    $server_endpoint.'/playerlogs',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse {
                        if (! file_exists($playerlogs = $gameserver->basedir.Civ13::playerlogs)) {
                            return HttpResponse::plaintext("Unable to access `$playerlogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        if (! $return = @file_get_contents($playerlogs)) {
                            return HttpResponse::plaintext("Unable to read `$playerlogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }

                        return HttpResponse::plaintext($return);
                    },
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/ooclogs',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse {
                        if (! file_exists($ooclogs = $gameserver->basedir.Civ13::ooc_path)) {
                            return HttpResponse::plaintext("Unable to access `$ooclogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        if (! $return = @file_get_contents($ooclogs)) {
                            return HttpResponse::plaintext("Unable to read `$ooclogs`")->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }

                        return HttpResponse::plaintext($return);
                    },
                    true
                );
            // Webhooks received from the game server
            $server_endpoint = '/webhook/'.$gameserver->key; // If no parameters are passed to a server_endpoint, try to find it using the query parameters
            $this->httpHandler
                ->offsetSet(
                    $server_endpoint,
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
                        $params = $request->getQueryParams();
                        //if ($params['method']) $this->logger->info("[METHOD] `{$params['method']}`");
                        if ($method = $this->httpHandler->offsetGet($endpoint.'/'.($params['method'] ?? ''), 'handlers')) {
                            return $method($request, $endpoint, $whitelisted);
                        }

                        return HttpResponse::plaintext("Method not found for `{$endpoint}/".($params['method'] ?? '').'`')->withStatus(HttpResponse::STATUS_NOT_FOUND);
                    },
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/ahelpmessage',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleStaffWebhook($request, $gameserver, 'ahelp'),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/asaymessage',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleStaffWebhook($request, $gameserver, 'asay'),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/urgentasaymessage',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleStaffWebhook($request, $gameserver, 'urgent'),
                    true
                )

                ->offsetSet(
                    $server_endpoint.'/lobbymessage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse {
                        if ($gameserver->legacy_relay) {
                            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        }
                        if (! isset($gameserver->lobby)) {
                            return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        if (! $this->discord->getChannel($channel_id = $gameserver->lobby)) {
                            return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }

                        $data = self::decodeParamData($request);
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        $message = "**__{$time} LOBBY__ $ckey:** $message";

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true);

                        return new HttpResponse(HttpResponse::STATUS_OK);
                    },
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/oocmessage',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleOocWebhook($request, $gameserver),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/icmessage',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleStructuredChatWebhook($request, $gameserver, 'ic'),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/memessage',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleStructuredChatWebhook($request, $gameserver, 'me'),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/garbage',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse {
                        if ($gameserver->legacy_relay) {
                            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        }
                        if (! isset($gameserver->adminlog)) {
                            return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        if (! $this->discord->getChannel($channel_id = $gameserver->adminlog)) {
                            return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }

                        $data = self::decodeParamData($request);
                        $time = '['.date('H:i:s', time()).']';
                        isset($data['ckey']) ? $ckey = Civ13::sanitizeInput($data['ckey']) : $ckey = '(NULL)';
                        isset($data['message']) ? $message = strip_tags(htmlspecialchars_decode(html_entity_decode($data['message']))) : $message = '(NULL)';
                        $message = "**__{$time} GARBAGE__ $ckey:** $message";

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey);

                        return new HttpResponse(HttpResponse::STATUS_OK);
                    },
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/game_ready',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleGameReadyWebhook($request, $gameserver),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/round_start',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleRoundStartWebhook($request, $gameserver),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/world_save',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleWorldSaveWebhook($request, $gameserver),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/shutdown_notice',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleShutdownNoticeWebhook($request, $gameserver),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/discord_verify',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleDiscordVerifyWebhook($request, $gameserver),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/respawn_notice',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse { // NYI
                        return new HttpResponse(HttpResponse::STATUS_OK);
                    },
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/login',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse {
                        if ($gameserver->legacy_relay) {
                            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        }
                        if (! isset($gameserver->transit)) {
                            return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        if (! $this->discord->getChannel($channel_id = $gameserver->transit)) {
                            return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        $parole_notif_channel = isset($this->civ13->channel_ids['parole_notif'])
                            ? $this->discord->getChannel($this->civ13->channel_ids['parole_notif']) : null;

                        $data = self::decodeParamData($request);
                        if (!is_string($data['ckey'] ?? null) || !preg_match('/^[A-Za-z0-9_ ]{1,64}$/D', $data['ckey'])
                            || ($ckey = Civ13::sanitizeInput($data['ckey'])) === '') {
                            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
                        }
                        foreach (['ip', 'cid'] as $field) {
                            if (array_key_exists($field, $data) && (!is_string($data[$field]) || strlen($data[$field]) > 128 || str_contains($data[$field], "\0"))) {
                                return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
                            }
                        }
                        if ($gameserver = $this->civ13->enabled_gameservers[$gameserver->key]) {
                            if (!in_array($ckey, $gameserver->players, true)) {
                                $gameserver->players[] = $ckey;
                            }
                        }

                        $time = '['.date('H:i:s', time()).']';
                        $message = "$ckey connected to the server";
                        if (isset($data, $data['ip'])) {
                            $message .= " with IP of {$data['ip']}";
                        }
                        if (isset($data, $data['cid'])) {
                            $message .= " and CID of {$data['cid']}";
                        }
                        $message .= '.';
                        $gameserver->logPlayerLogin($ckey, $time, $data['ip'] ?? '', $data['cid'] ?? '')->then(null, fn (\Throwable $error) => $this->logger->error('Player login persistence failed', ['exception' => $error]));

                        if ($parole_notif_channel && isset($this->civ13->paroled[$ckey])) {
                            $message2 = '';
                            if (isset($this->civ13->role_ids['Parolemin'])) {
                                $message2 .= "<@&{$this->civ13->role_ids['Parolemin']}>, ";
                            }
                            $message2 .= "`$ckey` has logged into `{$gameserver->name}`";
                            $this->civ13->sendMessage($parole_notif_channel, $message2);
                        }

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true, false);
                        if ($ckey && $ckey !== '(NULL)') {
                            $this->civ13->moderator->scrutinizeCkey($ckey);
                            $gameserver->panicCheck($ckey);
                        }

                        return new HttpResponse(HttpResponse::STATUS_OK);
                    },
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/logout',
                    function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use (&$gameserver): HttpResponse {
                        if ($gameserver->legacy_relay) {
                            return new HttpResponse(HttpResponse::STATUS_FORBIDDEN);
                        }
                        if (! isset($gameserver->transit)) {
                            return HttpResponse::plaintext('Webhook Channel Not Defined')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        if (! $this->discord->getChannel($channel_id = $gameserver->transit)) {
                            return HttpResponse::plaintext('Discord Channel Not Found')->withStatus(HttpResponse::STATUS_INTERNAL_SERVER_ERROR);
                        }
                        $parole_notif_channel = isset($this->civ13->channel_ids['parole_notif'])
                            ? $this->discord->getChannel($this->civ13->channel_ids['parole_notif']) : null;

                        $data = self::decodeParamData($request);
                        if (!is_string($data['ckey'] ?? null) || !preg_match('/^[A-Za-z0-9_ ]{1,64}$/D', $data['ckey'])
                            || ($ckey = Civ13::sanitizeInput($data['ckey'])) === '') {
                            return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
                        }
                        foreach (['ip', 'cid'] as $field) {
                            if (array_key_exists($field, $data) && (!is_string($data[$field]) || strlen($data[$field]) > 128 || str_contains($data[$field], "\0"))) {
                                return new HttpResponse(HttpResponse::STATUS_BAD_REQUEST);
                            }
                        }
                        $time = '['.date('H:i:s', time()).']';
                        $message = "$ckey disconnected from the server.";
                        $gameserver->logPlayerLogout($ckey, $time)->then(null, fn (\Throwable $error) => $this->logger->error('Player logout persistence failed', ['exception' => $error]));

                        $gameserver->gameChatWebhookRelay($message, $channel_id, $ckey, true, false);
                        if ($parole_notif_channel && isset($this->civ13->paroled[$ckey])) {
                            $message2 = '';
                            if (isset($this->civ13->role_ids['Parolemin'])) {
                                $message2 .= "<@&{$this->civ13->role_ids['Parolemin']}>, ";
                            }
                            $message2 .= "`$ckey` has log out of `{$gameserver->name}`";
                            $this->civ13->sendMessage($parole_notif_channel, $message2);
                        }

                        return new HttpResponse(HttpResponse::STATUS_OK);
                    },
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/runtimemessage',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleLogWebhook($request, $gameserver, 'runtime'),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/alogmessage',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleLogWebhook($request, $gameserver, 'admin'),
                    true
                )
                ->offsetSet(
                    $server_endpoint.'/attacklogmessage',
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => $this->handleLogWebhook($request, $gameserver, 'attack'),
                    true
                )
                ->offsetSets(
                    [$server_endpoint.'/roundstatus', $server_endpoint.'/status_update'],
                    fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse => new HttpResponse(HttpResponse::STATUS_OK),
                    true
                )
                /*
                ->offsetSet($server_endpoint.'/', (function (ServerRequestInterface $request, string $endpoint, bool $whitelisted) use ($key, $server): HttpResponse
                {
                    return new HttpResponse(HttpResponse::STATUS_OK);
                }), true);
                */;
        }
    }

    /** The synchronous HTTP router acknowledges queueing; failures remain observable. */
    private function queueAdminListUpdate(): HttpResponse
    {
        $completed = false;
        $failed = false;
        try {
            $this->civ13->adminlistUpdate()->then(
                static function () use (&$completed): void { $completed = true; },
                function (\Throwable $error) use (&$failed): void {
                    $failed = true;
                    $this->logger->error('Admin list refresh failed; some destinations may already have changed: '.$error->getMessage());
                }
            );
        } catch (\Throwable $error) {
            $this->logger->error('Admin list refresh could not be queued: '.$error->getMessage());
            return HttpResponse::plaintext('Unable to queue admin list refresh')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
        }
        if ($failed) {
            return HttpResponse::plaintext('Admin list refresh failed; some destinations may already have changed.')->withStatus(HttpResponse::STATUS_SERVICE_UNAVAILABLE);
        }
        if ($completed) {
            return HttpResponse::plaintext('Admin lists updated.')->withStatus(HttpResponse::STATUS_OK);
        }
        return HttpResponse::plaintext('Admin list refresh queued; completion has not been observed.')->withStatus(HttpResponse::STATUS_ACCEPTED);
    }

    /** Opt-in public status only. No endpoint input can select a probe address or port. */
    private function __generateLauncherDirectoryEndpoint(): void
    {
        $providers = $this->civ13->options['launcher_directory'] ?? [];
        $statusTargets = $this->civ13->options['launcher_directory_status_targets'] ?? [];
        if (!$providers && !$statusTargets) {
            return;
        }
        try {
            $this->launcherDirectory = new Directory(
                $providers,
                $this->civ13->enabled_gameservers,
                ByondStatusProbe::create($this->civ13->loop),
                statusTargets: $statusTargets
            );
        } catch (\InvalidArgumentException $error) {
            $this->logger->warning('Launcher directory disabled: '.$error->getMessage());
            return;
        }
        $endpoint = new DirectoryEndpoint($this->launcherDirectory);
        $this->httpHandler->offsetSet(
            Directory::PATH,
            static fn (ServerRequestInterface $request, string $path, bool $whitelisted): HttpResponse => $endpoint->handle($request),
            false,
            'exact',
            'Read-only, online-only launcher server directory'
        )->setRateLimit(Directory::PATH, 600, 60);
    }

    /** Serves every `*.html` file under the `/html` directory as its own web-API route, creating the directory if missing. */
    protected function __generateWebsiteEndpoints()
    {
        if (! is_dir($dirPath = $this->basedir.self::HTMLDIR) && ! mkdir($dirPath, 0664, true)) {
            return $this->logger->error('Failed to create `/html` directory');
        }
        $files = [];
        foreach (new \DirectoryIterator($dirPath) as $file) {
            if ($file->isDot() || ! $file->isFile() || $file->getExtension() !== 'html') {
                continue;
            }
            $files[] = substr($file->getPathname(), strlen($dirPath));
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'. /*<?xml-stylesheet type="text/xsl" href="sitemap.xsl"?> .*/ '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($files as &$file) {
            if (! $fileContent = @file_get_contents(substr(self::HTMLDIR, 1).$file)) {
                $this->logger->error("Failed to read file: `$file`");
                unset($file);
                continue;
            }
            $this->httpHandler->offsetSet($file, fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted) => HttpResponse::html($fileContent));
            $xml .= "<url><loc>$file</loc></url>";
            $this->logger->info("[HTTP FILE ENDPOINT] `$file`");
        }
        $xml .= '</urlset>';
        $this->httpHandler->offsetSet($endpoint = '/sitemap.xml', fn (ServerRequestInterface $request, string $endpoint, bool $whitelisted) => HttpResponse::xml($xml))->setRateLimit($endpoint, 1, 10);

        $sitemalxsl = (function (ServerRequestInterface $request, string $endpoint, bool $whitelisted): HttpResponse {
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
        //->offsetSet('/sitemap.xsl', $sitemalxsl);
        //$this->httpHandler->setRateLimit('/sitemap.xsl', 1, 10); // 1 request per 10 seconds
    }

    /**
     * Magic method to dynamically call methods on the HttpHandler object.
     *
     * @param  string                  $name      The name of the method being called.
     * @param  array                   $arguments The arguments passed to the method.
     * @return mixed                   The result of the method call.
     * @throws \BadMethodCallException If the method does not exist.
     */
    public function __call(string $name, array $arguments)
    { // Forward calls to the HttpHandler object (offsetSet, setRateLimit, etc.)
        if (method_exists($this->httpHandler, $name)) {
            return call_user_func_array([$this->httpHandler, $name], $arguments);
        }
        throw new \BadMethodCallException("Method {$name} does not exist.");
    }
}
