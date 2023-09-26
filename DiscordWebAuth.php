<?php
use Civ13\Civ13;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

Class DWA
{
    public Civ13 $civ13;
    protected array $sessions;
    protected array $params;
    protected $CLIENT_ID = '';
    protected $CLIENT_SECRET = '';
    protected string $requesting_ip;

    protected $baseURL = 'https://discord.com/api/v10';
    protected $default_redirect;

    protected $access_token = false;
    protected $state = false;
    public $user;
    public $connections;
    
    protected $external_ip = '';
    protected $web_address = 'www.civ13.com:55555/';
    protected $redirect_home = '';
    protected $originating_url = null;
    protected $allowed_uri = []; //Exact URL as added in https://discord.com/developers/applications/###/oauth2

    function __construct(Civ13 &$civ13, array &$sessions, string $client_id, string $client_secret, ServerRequestInterface $request, string $requesting_ip) {
        $this->civ13 = &$civ13;
        $this->sessions = &$sessions;
        $this->CLIENT_ID = $client_id;
        $this->CLIENT_SECRET = $client_secret;
        $this->params = $request->getQueryParams();
        $this->requesting_ip = $requesting_ip;

        $this->redirect_home = 'http://' . ($this->web_address ?? $this->civ13->httpHandler->external_ip)/* . ':55555/'*/;
        $this->allowed_uri []= $this->redirect_home . 'dwa';
        
        $path = $request->getUri()->getPath();
        $this->default_redirect = $request->getUri()->getScheme().'://'.$request->getUri()->getHost().':55555'.explode('?', $path)[0];
        $this->originating_url = $request->getHeaderLine('referer') ?? $request->getUri()->getScheme().'://'.$request->getUri()->getHost();
        
        if (isset($this->sessions[$this->requesting_ip]['discord_state']))
            $this->state = $this->sessions[$this->requesting_ip]['discord_state'];
        else
        {
            $this->state = uniqid();
            $this->sessions[$this->requesting_ip]['discord_state'] = $this->state;
        }

        if (isset($this->sessions[$this->requesting_ip]['discord_access_token']))
        {
            $this->access_token = $this->sessions[$this->requesting_ip]['discord_access_token'];
            $this->user = $this->getUser();
            $this->connections = $this->getConnections();
        }
    }

    private function apiRequest(string $url = '', $post = null)
    {
        $ch = curl_init($url);

        $headers[] = 'Accept: application/json';
        if ($this->access_token)
            $headers[] = 'Authorization: Bearer '.$this->access_token;

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($post)
        {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response);
    }

    public function login(string $redirect_uri = null, string $scope = 'identify guilds connections'): Response
    {
        if (! isset($redirect_uri)){
            if (!in_array(($redirect_uri ? $redirect_uri : $this->default_redirect), $this->allowed_uri)) {
                $this->civ13->logger->info('[DWA] Redirect URI not allowed: ' . ($redirect_uri ? $redirect_uri : $this->default_redirect) . ' => ' . $this->allowed_uri[0]);
                return new Response(
                    Response::STATUS_FOUND,
                    ['Location' => $this->allowed_uri[0] . '?login']
                );
            }
        }
        
        $params = [
            'client_id' => $this->CLIENT_ID,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $this->state,
            'redirect_uri' =>  ($redirect_uri ? $redirect_uri : $this->default_redirect)
        ];
        return new Response(
            Response::STATUS_FOUND,
            ['Location' => ($this->baseURL.'/oauth2/authorize?'.http_build_query($params))]
        );
    }

    public function logout(): Response
    {
        unset($this->sessions[$this->requesting_ip]);
        return new Response(
            Response::STATUS_FOUND,
            ['Location' => ($redirect_home ?? $this->default_redirect)]
        );
    }

    public function getToken(string $state = '', string $redirect_uri = ''): Response
    {
        if ($state == $this->state)
        {
            $params = [
                'client_id'        => $this->CLIENT_ID,
                'client_secret'    => $this->CLIENT_SECRET,
                'grant_type'    => 'authorization_code',
                'code'            => $this->params['code'],
                'redirect_uri'    => ($redirect_uri ? $redirect_uri : $this->default_redirect)
            ];

            $token = $this->apiRequest($this->baseURL.'/oauth2/token' , $params);
            if (! isset($token->error))
                $this->sessions[$this->requesting_ip]['discord_access_token'] = $token->access_token;
            return new Response(
                Response::STATUS_FOUND,
                ['Location' => ($redirect_home ?? $this->default_redirect)]
            );
        }
    }

    public function removeToken(): Response
    {
        if ($this->access_token)
        {
            $params = [
                'client_id'        => $this->CLIENT_ID,
                'client_secret'    => $this->CLIENT_SECRET,
                'access_token' => $this->access_token
            ];

            $res = $this->apiRequest($this->baseURL.'/oauth2/token/remove' , $params);
            return $this->logout();
        }
    }

    public function getUser()
    {
        $user = $this->apiRequest($this->baseURL.'/users/@me');
        $user->avatar_url = 'https://cdn.discordapp.com/avatars/'.$user->id.'/'.$user->avatar.'.png';
        $user->guilds = $this->apiRequest($this->baseURL.'/users/@me/guilds');
        foreach($user->guilds as $key => $guild)
        {
            if (isset($guild->icon) && $guild->icon)
                $user->guilds[$key]->avatar_url = 'https://cdn.discordapp.com/icons/'.$guild->id.'/'.$guild->icon.'.png';
        }
        return $user;
    }
    
    
    public function getConnections()
    {
        $connections = $this->apiRequest($this->baseURL.'/users/@me/connections');
        foreach($connections as $key => $connection)
        {
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
            if (isset($connection->type)){
                $this->sessions[$this->requesting_ip]['oauth_' . $connection->type . '_id'] = $connection->id;
                $this->sessions[$this->requesting_ip]['oauth_' . $connection->type . '_name'] = $connection->name;
                if ($connection->type == 'steam'){
                    $this->sessions[$this->requesting_ip]['oauth_steam_url'] = 'https://steamcommunity.com/profiles/'.$connection->id.'/';
                }
            }
        }
        return $connections;
    }

    public function isAuthed(): bool
    {
        return !is_null($this->user);
    }

    public function getGuild($id)
    {
        if (is_null($this->user))
            return false;
        
        foreach($this->user->guilds as $guild)
        {
            if ($guild->id == $id)
                return $guild;
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