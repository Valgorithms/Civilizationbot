<?php declare(strict_types=1);

namespace Civ14;

use React\Http\Browser;
use React\Promise\PromiseInterface;

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
        return $this->httpClient->get($this->baseURL() . '/status')
            ->then(fn($response) => json_decode((string) $response->getBody(), true));
    }

    /**
     * Fetch detailed server information.
     *
     * @return PromiseInterface Resolves to an array of server information.
     */
    public function getInfo(): PromiseInterface
    {
        return $this->httpClient->get($this->baseURL() . '/info')
            ->then(fn($response) => json_decode((string) $response->getBody(), true));
    }

    /**
     * Shut down the server (requires authorization).
     *
     * @return PromiseInterface Resolves to a boolean indicating success.
     */
    public function shutdown(): PromiseInterface
    {
        return $this->httpClient->post($this->baseURL() . '/shutdown', [
            'headers' => $this->getAuthHeaders(),
        ])->then(fn($response) => $response->getStatusCode() === 200);
    }

    /**
     * Notify the server of an available update (requires authorization).
     *
     * @return PromiseInterface Resolves to a boolean indicating success.
     */
    public function update(): PromiseInterface
    {
        return $this->httpClient->post($this->baseURL() . '/update', [
            'headers' => $this->getAuthHeaders(),
        ])->then(fn($response) => $response->getStatusCode() === 200);
    }

    /**
     * Get authorization headers if a watchdog token is provided.
     *
     * @return array
     */
    private function getAuthHeaders(): array
    {
        $watchdogToken = $this->gameServer->civ13->watchdogToken ?? null;
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

    protected function baseUrl(): string
    {
        return $this->protocol . '://' . $this->ip . ':' . $this->port;
    }
}