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

namespace Discord;

use Civ13\Civ13;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class DiscordWebAuth
{
    public Civ13 $civ13;
    protected array $sessions;
    protected array $params;
    protected string $CLIENT_ID = '';
    protected string $CLIENT_SECRET = '';
    protected string $requesting_ip;

    protected string $baseURL = 'https://discord.com/api/v10';
    protected string $default_redirect;

    protected string|false $access_token = false;
    protected string $state = '';
    public ?object $user = null;
    public ?array $connections = null;
    
    protected string $external_ip = '';
    protected string $web_address = 'www.civ13.com:55555/';
    protected string $redirect_home = '';
    protected ?string $originating_url = null;
    protected array $allowed_uri = []; //Exact URL as added in https://discord.com/developers/applications/###/oauth2

    /**
     * @param Civ13                  $civ13         The bot instance (held by reference).
     * @param array<string, mixed>   $sessions      The shared per-IP session store (held by reference).
     * @param string                 $client_id     Discord application client id.
     * @param string                 $client_secret Discord application client secret.
     * @param string                 $web_address   Public host for building redirect URIs.
     * @param int                    $http_port     Public HTTP port.
     * @param string                 $resolved_ip   Resolved server IP, added to the allowed redirect list.
     * @param ServerRequestInterface $request       The incoming OAuth2 callback request.
     */
    public function __construct(Civ13 &$civ13, array &$sessions, string $client_id, string $client_secret, string $web_address, int $http_port, string $resolved_ip, ServerRequestInterface $request)
    {
        $this->civ13 = &$civ13;
        $this->sessions = &$sessions;
        $this->CLIENT_ID = $client_id;
        $this->CLIENT_SECRET = $client_secret;
        $this->params = $request->getQueryParams();
        $this->requesting_ip = $request->getServerParams()['REMOTE_ADDR'];

        $this->web_address = "$web_address:$http_port";
        $this->redirect_home = "http://{$this->web_address}/";
        $this->allowed_uri [] = "{$this->redirect_home}dwa";
        $this->allowed_uri [] = "http://{$resolved_ip}:$http_port/dwa";

        $this->default_redirect = $request->getUri()->getScheme().'://'.$request->getUri()->getHost().':'.$http_port.explode('?', $request->getUri()->getPath())[0];
        $this->originating_url = $request->getHeaderLine('referer') ?? $request->getUri()->getScheme().'://'.$request->getUri()->getHost();
        
        if (isset($this->sessions[$this->requesting_ip]['discord_state'])) {
            $this->state = $this->sessions[$this->requesting_ip]['discord_state'];
        } else {
            // Cryptographically random, not uniqid() (which is predictable
            // microtime) — this is the OAuth2 CSRF token.
            $this->state = bin2hex(random_bytes(16));
            $this->sessions[$this->requesting_ip]['discord_state'] = $this->state;
        }

        if (isset($this->sessions[$this->requesting_ip]['discord_access_token'])) {
            $this->access_token = $this->sessions[$this->requesting_ip]['discord_access_token'];
            $this->user = $this->getUser();
            $this->connections = $this->getConnections();
        }
    }

    /** Performs a cURL request to the Discord API, attaching the bearer token when present and JSON-decoding the response. */
    private function apiRequest(string $url = '', object|array|null $post = null, ?bool $associative = null)
    {
        $ch = curl_init($url);

        $headers = ['Accept: application/json'];
        if ($this->access_token) {
            $headers[] = 'Authorization: Bearer '.$this->access_token;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);

        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $response = curl_exec($ch);
        curl_close($ch);

        return is_string($response) ? @json_decode($response, $associative) : null;
    }

    /** Redirects the browser to the Discord OAuth2 authorize page, or to the first allowed URI when the redirect target is not whitelisted. */
    public function login(?string $redirect_uri = null, ?string $scope = 'identify guilds connections'): Response
    {
        // Always validate the effective redirect target against the allow-list —
        // a caller-supplied $redirect_uri must not skip the check (open redirect
        // / auth-code interception).
        $target = $redirect_uri ?: $this->default_redirect;
        if (! in_array($target, $this->allowed_uri, true)) {
            $this->civ13->logger->info('[DWA] Redirect URI not allowed: '.$target.' => '.$this->allowed_uri[0]);

            return new Response(
                Response::STATUS_FOUND,
                ['Location' => $this->allowed_uri[0].'?login']
            );
        }

        $params = [
            'client_id' => $this->CLIENT_ID,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $this->state,
            'redirect_uri' => $target,
        ];

        return new Response(
            Response::STATUS_FOUND,
            ['Location' => ($this->baseURL.'/oauth2/authorize?'.http_build_query($params))]
        );
    }

    /** Clears this IP's session and redirects home. */
    public function logout(): Response
    {
        unset($this->sessions[$this->requesting_ip]);

        return new Response(
            Response::STATUS_FOUND,
            ['Location' => ($this->redirect_home ?: $this->default_redirect)]
        );
    }

    /** Exchanges the OAuth2 `code` for an access token (when `$state` matches) and stores it on the session, then redirects home. */
    public function getToken(string $state = '', string $redirect_uri = ''): Response
    {
        $code = (string) ($this->params['code'] ?? '');

        // Constant-time CSRF check; reject an empty/absent state or code outright.
        if ($state === '' || $code === '' || $this->state === '' || ! hash_equals($this->state, $state)) {
            return new Response(Response::STATUS_BAD_REQUEST);
        }

        // Single-use: burn the state so a replayed callback cannot reuse it.
        unset($this->sessions[$this->requesting_ip]['discord_state']);
        $this->state = '';

        $params = [
            'client_id' => $this->CLIENT_ID,
            'client_secret' => $this->CLIENT_SECRET,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => ($redirect_uri ?: $this->default_redirect),
        ];

        $token = $this->apiRequest($this->baseURL.'/oauth2/token', $params);
        if (is_object($token) && ! isset($token->error) && isset($token->access_token)) {
            $this->sessions[$this->requesting_ip]['discord_access_token'] = $token->access_token;
        }

        return new Response(
            Response::STATUS_FOUND,
            ['Location' => ($this->redirect_home ?: $this->default_redirect)]
        );
    }

    /** Revokes the current access token with Discord and logs the user out. */
    public function removeToken(): Response
    {
        if ($this->access_token) {
            $params = [
                'client_id' => $this->CLIENT_ID,
                'client_secret' => $this->CLIENT_SECRET,
                'access_token' => $this->access_token,
            ];

            $res = $this->apiRequest($this->baseURL.'/oauth2/token/remove', $params);
        }

        return $this->logout();
    }

    /** Fetches `/users/@me` plus the user's guilds, decorating them with avatar/icon CDN URLs. */
    public function getUser()
    {
        $user = $this->apiRequest($this->baseURL.'/users/@me');
        if (! is_object($user) || ! isset($user->id)) {
            return null;
        }

        $user->avatar_url = 'https://cdn.discordapp.com/avatars/'.$user->id.'/'.($user->avatar ?? '').'.png';
        $user->guilds = $this->apiRequest($this->baseURL.'/users/@me/guilds');
        foreach ((is_iterable($user->guilds) ? $user->guilds : []) as $key => $guild) {
            if (isset($guild->icon) && $guild->icon) {
                $user->guilds[$key]->avatar_url = 'https://cdn.discordapp.com/icons/'.$guild->id.'/'.$guild->icon.'.png';
            }
        }

        return $user;
    }
    
    /** Fetches `/users/@me/connections` and mirrors each connection's id/name (and Steam URL) into the session. */
    public function getConnections()
    {
        $connections = $this->apiRequest($this->baseURL.'/users/@me/connections');
        foreach ((is_iterable($connections) ? $connections : []) as $key => $connection) {
            /*
            id    string    id of the connection account
            name    string    the username of the connection account
            type    string    the service of the connection (twitch, youtube)
            revoked?    boolean    whether the connection is revoked
            integrations?    array    an array of partial server integrations
            verified    boolean    whether the connection is verified
            friend_sync    boolean    whether friend sync is enabled for this connection
            show_activity    boolean    whether activities related to this connection will be shown in presence updates
            visibility    integer    visibility of this connection
            */
            if (isset($connection->type)) {
                $this->sessions[$this->requesting_ip]['oauth_'.$connection->type.'_id'] = $connection->id;
                $this->sessions[$this->requesting_ip]['oauth_'.$connection->type.'_name'] = $connection->name;
                if ($connection->type == 'steam') {
                    $this->sessions[$this->requesting_ip]['oauth_steam_url'] = "https://steamcommunity.com/profiles/{$connection->id}/";
                }
            }
        }

        return $connections;
    }

    /** Whether a Discord user has been resolved for this session. */
    public function isAuthed(): bool
    {
        return ! is_null($this->user);
    }

    /** The authed user's guild matching `$id`, or false when unauthenticated or not a member. */
    public function getGuild(string|int $id)
    {
        if (is_null($this->user)) {
            return false;
        }
        
        foreach ($this->user->guilds as $guild) {
            if ($guild->id == $id) {
                return $guild;
            }
        }

        return false;
    }
}
/*
elseif (is_null($this->sessions[$this->requesting_ip]['discord_access_token']))
    $dw->login();
*/
/*
if ($existingAccessToken->hasExpired()) {
$newAccessToken = $provider->getAccessToken('refresh_token', [
    'refresh_token' => $existingAccessToken->getRefreshToken()
]);
*/
