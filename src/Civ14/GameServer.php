<?php declare(strict_types=1);

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ14;

use Civ13\Civ13;
use Discord\Discord;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/**
  * * Class GameServer
  * 
  * 
  * @property-read  Browser               $browser
  * @property-read  Discord|null          $discord
  * @property-read  LoggerInterface|null  $logger
  */
  
class GameServer
{
    protected string $PARENT_CLASS_PROPERTY = 'civ13';

    /** @var Civ13 $civ13 */
    protected Civ13 $civ13;
    //protected Browser $browser;
    
    public function __construct(
        &$civ13
    ) {
        $this->civ13 = &$civ13;
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
     * from `$civ13->loop` or a default loop from `Loop::get()`. The new `Browser` instance
     * is then assigned to both `$civ13->browser` and the local `browser` property by reference.
     *
     * @return Browser|null Returns the `Browser` instance or null if not set.
     */
    public function getBrowserProperty(): Browser
    { // The below is a workaround for the fact that the civ13->browser property is not set in PHPUnit tests.
        return isset($this->civ13->browser)
            ? $this->civ13->browser
            : new Browser($this->civ13->loop ?? Loop::get());
    }
    
    public function getDiscordProperty(): Discord
    {
        return $this->civ13->discord;
    }

    public function getLoggerProperty(): LoggerInterface
    {
        return $this->civ13->logger;
    }

    use ServerApiTrait;
    use DynamicPropertyAccessorTrait;
}