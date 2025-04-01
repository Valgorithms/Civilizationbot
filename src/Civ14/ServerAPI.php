<?php declare(strict_types=1);

namespace Civ14;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;
use function React\Promise\reject;
use function React\Async\await;

/**
 * property GameServer $gameServer
 */
class ServerAPI
{
    /**
     * @var GameServer $gameServer
     */
    //protected GameServer $gameServer;
    private Browser $httpClient;

    private string $protocol = 'http';
    private string $ip;
    private int|string $port = 1212;
    private string $watchdogToken;

    public function __construct(
        protected $gameServer
    ) {

        if (! $this->httpClient = $this->gameServer->browser ?? new Browser()) {
            throw new \RuntimeException('Browser instance is not available.');
        }
    }

    /**
     * Fetch basic server status.
     *
     * @return PromiseInterface Resolves to an array of server status.
     */
    public function getStatus(): PromiseInterface
    {
        return $this->sendGetRequest('/status')
            ->then(fn(ResponseInterface $response) => self::parseResponse($response));
    }

    /**
     * Fetch detailed server information.
     *
     * @return PromiseInterface Resolves to an array of server information.
     */
    public function getInfo(): PromiseInterface
    {
        return $this->sendGetRequest('/info')
            ->then(fn(ResponseInterface $response) => self::parseResponse($response));
    }

    /**
     * Notify the server of an available update (requires authorization).
     *
     * @return PromiseInterface Resolves to a boolean indicating success.
     */
    public function update(): PromiseInterface
    {
        return $this->sendPostRequest('/update', [
            'headers' => $this->authHeaders(),
        ])->then(fn(ResponseInterface $response) => self::isResponseSuccessful($response));
    }

    /**
     * Shut down the server (requires authorization).
     *
     * @return PromiseInterface Resolves to a boolean indicating success.
     */
    public function shutdown(): PromiseInterface
    {
        return $this->sendPostRequest('/shutdown', [
            'headers' => $this->authHeaders(),
        ])->then(fn(ResponseInterface $response) => self::isResponseSuccessful($response));
    }

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
            : $this->httpClient->get($this->baseURL() . $endpoint, $headers);
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
            : $this->httpClient->post($this->baseURL() . $endpoint, $headers, $body);
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
        return json_decode($response->getBody()->getContents(), true);
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
    public static function isResponseSuccessful(ResponseInterface $response)
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
        $watchdogToken = $this->watchdogToken ?? null;
        return $watchdogToken ? ['WatchdogToken' => $watchdogToken] : [];
    }

    public function setProtocol(string $protocol = 'http'): void
    {
        $this->protocol = $protocol;
    }
    
    public function setIP(string $ip = '127.0.0.1'): void
    {
        $this->ip = $ip;
    }

    public function setPort(int|string $port = 1212): void
    {
        $this->port = $port;
    }

    public function isLocal()
    {
        return !filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    public function isPortFree(): bool
    {
            if ($connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 1)) {
                fclose($connection);
                return false;
            }
            return true;
    }

    public function setWatchdogToken(string $token): void
    {
        $this->watchdogToken = $token;
    }
}