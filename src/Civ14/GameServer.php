<?php declare(strict_types=1);

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ14;

use Civ13\Civ13;
use Discord\Discord;
use Discord\Parts\Embed\Embed;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

use function React\Async\await;

/**
  * @property-read  Browser          $browser
  * @property-read  Discord          $discord
  * @property-read  LoggerInterface  $logger
  */
class GameServer
{
    use ServerApiTrait;
    use DynamicPropertyAccessorTrait;

    protected Civ13 $civ13;

    public bool   $enabled = true;
    public string $key     = 'civ14';
    public string $name    = 'Civilization 14';
    public string $host    = 'Taislin';
    public array  $players = []; // Cannot be retrieved via the hub or server API

    // Normally would just promote the property, but currently causes an issue in PHPUnit tests
    public function __construct(
        &$civ13,
        array &$options = []
    ) {
        $this->civ13         = &$civ13;
        $this->enabled       = (bool)$options['enabled'] ?? true;
        $this->name          = $options['name']          ?? 'Civilization 14';
        $this->protocol      = $options['protocol']      ?? 'http';
        $this->ip            = $options['ip']            ?? '127.0.0.1';
        $this->port          = (int)$options['port']     ?? 1212;
        $this->host          = $options['host']          ?? 'Taislin';
        $this->watchdogToken = $options['watchdogToken'] ?? null;
        $this->afterConstruct();
    }
    protected function afterConstruct(): void
    {
        $this->setup();
    }
    protected function setup(): void
    {
        $this->civ13->civ14_gameservers[$this->key] =& $this;
        if ($this->enabled) $this->civ13->civ14_enabled_gameservers[$this->key] =& $this;
        $this->logger->info('Added ' . ($this->enabled ? 'enabled' : 'disabled') . " SS14 game server: {$this->name} ({$this->key})");
    }

    public function toEmbed(): Embed
    {
        $embed = $this->civ13->createEmbed();
        if (! is_resource($socket = @fsockopen('localhost', $this->port, $errno, $errstr, 1))) return $embed->addFieldValues($this->name, 'Offline');
        fclose($socket);
        /** @var array $info */
        $status = await($this->getStatus());
        return $embed
            ->setTitle($this->name)
            ->addFieldValues("Server URL", "ss14://{$this->ip}:{$this->port}", false)
            ->addFieldValues('Host', $this->host, true)
            ->addFieldValues(
                isset($status['players'])
                    ? 'Players (' . (int)$status['players'] . ')'
                    : 'Players',
                'N/A',
                true
            );
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
     * Retrieves or initializes the browser property.
     *
     * This method checks if the `browser` property is already set in the `$civ13` object.
     * If it exists, it assigns it to the local `browser` property by reference and returns it.
     * If it does not exist, it initializes a new `Browser` instance using the event loop
     * from `$civ13->loop` or a default loop from `Loop::get()`.
     *
     * @return Browser
     */
    protected function getBrowserProperty(): Browser
    { 
        return isset($this->civ13->browser)
            ? $this->civ13->browser
            : new Browser($this->civ13->loop ?? Loop::get()); // Workaround for civ13->browser property not set in PHPUnit tests
    }
    
    /**
     * Retrieves the Discord property from the Civ13 instance.
     *
     * @return Discord
     */
    protected function getDiscordProperty(): Discord
    {
        return $this->civ13->discord;
    }

    /**
     * Retrieves the logger instance associated with the Civ13 object.
     *
     * @return LoggerInterface
     */
    protected function getLoggerProperty(): LoggerInterface
    {
        return $this->civ13->logger;
    }
}