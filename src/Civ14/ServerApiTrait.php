<?php declare(strict_types=1);

namespace Civ14;

use Civ13\Civ13;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
/**
 * @see Civ14\GameServer
 * @property Civ13 $civ13
 * @property Browser $browser
 * @property string $discussion
 * 
 * @see Civ14\GameServer::announceNewRound()
 * @method PromiseInterface announceNewRound()
 */
trait ServerApiTrait
{
    // Server
    protected string      $protocol      = 'http';
    public    string      $ip            = '127.0.0.1';
    public    int         $port          = 1212;
    protected string|null $watchdogToken = null;
    // Status endpoint response
    public    array       $__status         = [];
    public    string      $name             = '[EN] Civilization 14';
    public    int         $playing          = 0;
    public    array       $tags             = [];
    public    string      $map              = 'Unknown';
    public    int         $round_id         = -1;
    public    int         $soft_max_players = 0;
    public    bool        $panic_bunker     = false;
    public    int         $run_level        = 0;
    public    ?string     $preset           = null;
    public    ?string     $round_start_time = null;

    /**
     * Sends a GET request to the specified URL with optional headers.
     *
     * @param string $endpoint The endpoint to send the GET request to.
     * @param array $headers An optional array of headers to include in the request.
     * @return PromiseInterface A promise representing the asynchronous HTTP response.
     */
    public function sendGetRequest(string $endpoint, array $headers = array()): PromiseInterface
    {
        return ($this->isLocal() && $this->isPortFree())
            ? reject(new \RuntimeException('Port is not listening'))
            : $this->browser->get($this->baseURL() . $endpoint, $headers);
    }

    /**
     * Sends a POST request to the specified URL with the given headers and body.
     *
     * @param string $endpoint The endpoint to send the POST request to.
     * @param array $headers An associative array of headers to include in the request.
     * @param string $body The body content to include in the POST request. Defaults to an empty string.
     * @return PromiseInterface A promise representing the asynchronous HTTP response.
     */
    public function sendPostRequest(string $endpoint, array $headers = array(), $body = ''): PromiseInterface
    {
        return ($this->isLocal() && $this->isPortFree())
            ? reject(new \RuntimeException('Port is not listening'))
            : $this->browser->post(
                $this->baseURL() . $endpoint,
                array_merge($headers, $this->authHeaders()),
                $body
            );
    }

    /**
     * Fetch basic server status.
     *
     * The returned array contains the following keys:
     * - `name` (string): The name of the server.
     * - `players` (int): The current number of players on the server.
     * - `tags` (array<string>): A list of tags associated with the server.
     *   - Example: "region:eu_w", "rp:med".
     * - `map` (string): The name of the map currently in use.
     * - `round_id` (int): The unique identifier for the current round.
     * - `soft_max_players` (int): The soft maximum number of players allowed.
     * - `panic_bunker` (bool): Indicates whether the panic bunker mode is active.
     * - `run_level` (int): The current run level of the server.
     * - `preset` (string): The preset configuration in use.
     * - `round_start_time` (string): The ISO 8601 timestamp of when the round started.
     *
     * @return PromiseInterface<array> Resolves to an array of server status.
     */
    public function getStatus(bool $use_default_handlers = false): PromiseInterface
    {
        $promise = $this->sendGetRequest('/status')->then(function(ResponseInterface $response): ResponseInterface
        {
            if ($json = json_decode($response->getBody()->getContents(), true)) {
                if (
                    isset($this->discussion) &&
                    isset($json['round_id']) &&
                    is_numeric($json['round_id']) &&
                    $this->round_id !== -1 && // Only announce if we have a previous round_id
                    $json['round_id'] != $this->round_id
                ) $this->announceNewRound();
                $this->__status = $json;
                $this->name = $json['name'];
                $this->playing = (int)$json['players'];
                $this->tags = $json['tags'] ?? [];
                $this->map = $json['map'] ?? 'Unknown';
                $this->round_id = $json['round_id'];
                $this->soft_max_players = $json['soft_max_players'];
                $this->panic_bunker = $json['panic_bunker'];
                $this->run_level = (int)$json['run_level'];
                $this->preset = $json['preset'] ?? null;
                $this->round_start_time = $json['round_start_time'] ?? null;
            } else {
                $this->__status = [];
                $this->playing = 0;
                $this->tags = [];
                $this->map = 'Unknown';
                $this->round_id = 0;
                $this->soft_max_players = 0;
                $this->panic_bunker = false;
                $this->run_level = 0;
                $this->preset = null;
                $this->round_start_time = null;
            }
            return $response;
        });
        return $use_default_handlers
            ? $this->civ13->then($promise, fn(ResponseInterface $response) => self::parseResponse($response), null, fn(\Throwable $e) => null) // Catch but ignore errors
            : $promise->then(fn(ResponseInterface $response) => self::parseResponse($response));
    }

    /**
     * Fetch detailed server information.
     *
     * @return PromiseInterface<array> Resolves to an array of server information.
     */
    public function getInfo(bool $use_default_handlers = false): PromiseInterface
    {
        $promise = $this->sendGetRequest('/info')
            ->then(fn(ResponseInterface $response) => self::parseResponse($response));
        return $use_default_handlers
            ? $this->civ13->then($promise, null, fn(\Throwable $e) => null) // Catch but ignore errors
            : $promise;
    }

    /**
     * Notify the server of an available update (requires authorization).
     *
     * @return PromiseInterface Resolves to a boolean indicating success.
     */
    public function update(bool $use_default_handlers = false): PromiseInterface
    {
        $promise = $this->sendPostRequest('/update')
            ->then(fn(ResponseInterface $response) => self::isResponseSuccessful($response));
        return $use_default_handlers
            ? $this->civ13->then($promise, null, fn(\Throwable $e) => null) // Catch but ignore errors
            : $promise;
    }

    /**
     * Shut down the server (requires authorization).
     *
     * @return PromiseInterface Resolves to a boolean indicating success.
     */
    public function shutdown(bool $use_default_handlers = false): PromiseInterface
    {
        $promise = $this->sendPostRequest('/shutdown')
            ->then(fn(ResponseInterface $response) => self::isResponseSuccessful($response));
        return $use_default_handlers
            ? $this->civ13->then($promise, null, fn(\Throwable $e) => null) // Catch but ignore errors
            : $promise;
    }

    /**
     * Parses the given HTTP response and decodes its JSON content into an associative array.
     *
     * @param ResponseInterface $response The HTTP response to parse.
     * 
     * @return array The decoded JSON content as an associative array.
     */
    public static function parseResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true) ?: [];
    }

    /**
     * Checks if the server is online.
     *
     * This method determines the online status of the server by checking
     * if it is local and whether the port is free. If the port is not
     * listening, it rejects with a RuntimeException. Otherwise, it retrieves
     * the server's status.
     *
     * @return PromiseInterface Resolves with the server status if online,
     *                          or rejects with an exception if the port is not listening.
     */
    public function isOnline(): PromiseInterface
    {
        return ($this->isLocal() && $this->isPortFree())
            ? reject(new \RuntimeException('Port is not listening'))
            : $this->getStatus(true);
    }

    /**
     * Determines if the IP address is a local (private or reserved) address.
     *
     * This method checks whether the IP address stored in the `$this->ip` property
     * is within a private or reserved range. It uses PHP's `filter_var` function
     * with the `FILTER_VALIDATE_IP` filter and the flags `FILTER_FLAG_NO_PRIV_RANGE`
     * and `FILTER_FLAG_NO_RES_RANGE` to exclude private and reserved IP ranges.
     *
     * @return bool Returns true if the IP address is local (private or reserved),
     *              otherwise false.
     */
    public function isLocal(): bool
    {
        return !filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Checks if the specified port is free to use.
     *
     * This method attempts to open a socket connection to the specified port
     * on the local machine (127.0.0.1). If the connection cannot be established,
     * it assumes the port is free. Otherwise, it determines the port is in use.
     *
     * @return bool Returns true if the port is free, false if it is in use.
     */
    public function isPortFree(): bool
    {
        if (! $connection = @fsockopen('localhost', $this->port, $errno, $errstr, 1)) return true;
        fclose($connection);
        return false;
    }

    /**
     * Determines if the given HTTP response is successful.
     *
     * This method checks if the status code of the provided response
     * is equal to 200, which indicates a successful HTTP request.
     *
     * @param ResponseInterface $response The HTTP response to evaluate.
     * @return bool True if the response status code is 200, otherwise false.
     */
    public static function isResponseSuccessful(ResponseInterface $response): bool
    {
        return $response->getStatusCode() === 200;
    }

    /**
     * Constructs the base URL for the server using the protocol, IP address, and port.
     *
     * @return string The complete base URL in the format: protocol://ip:port
     */
    public function baseUrl(): string
    {
        return $this->protocol . '://' . $this->ip . ':' . $this->port;
    }

    /**
     * Get authorization headers if a watchdog token is provided.
     *
     * @return array
     */
    public function authHeaders(): array
    {
        return isset($this->watchdogToken)
            ? ['WatchdogToken' => $this->watchdogToken]
            : [];
    }

    /**
     * Retrieves the protocol used by the server.
     *
     * @return string The protocol string.
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * Retrieves the IP address associated with the server.
     *
     * @return string The IP address as a string.
     */
    public function getIP(): String
    {
        return $this->ip;
    }

    /**
     * Retrieves the port number used by the server.
     *
     * @return int The port number.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Retrieves the watchdog token.
     *
     * @return string|null The watchdog token if set, or null if not set.
     */
    public function getWatchdogToken(): ?string
    {
        return $this->watchdogToken;
    }

    /**
     * Sets the protocol to be used by the server.
     *
     * @param string $protocol The protocol to set (default is 'http').
     *
     * @return void
     */
    public function setProtocol(string $protocol = 'http'): void
    {
        $this->protocol = $protocol;
    }
    
    /**
     * Sets the IP address for the server.
     *
     * @param string $ip The IP address to set. Defaults to '127.0.0.1'.
     *                    Must be a valid IPv4 or IPv6 address.
     * 
     * @throws \InvalidArgumentException If the provided IP address is invalid.
     * 
     * @return void
     */
    public function setIP(string $ip = '127.0.0.1'): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Invalid IP address provided.');
        }
        $this->ip = $ip;
    }

    /**
     * Sets the port for the server.
     *
     * @param int|string $port The port number to set. Defaults to 1212. 
     *                         Must be numeric, otherwise an exception is thrown.
     * 
     * @throws \InvalidArgumentException If the provided port is not numeric.
     * 
     * @return void
     */
    public function setPort(int|string $port = 1212): void
    {
        if (!is_numeric($port)) {
            throw new \InvalidArgumentException('Port must be a number.');
        }
        $this->port = (int) $port;
    }

    /**
     * Sets the watchdog token.
     *
     * @param string|null $token The token to set, or null to clear the token.
     * @return void
     */
    public function setWatchdogToken(?string $token = null): void
    {
        $this->watchdogToken = $token;
    }
}