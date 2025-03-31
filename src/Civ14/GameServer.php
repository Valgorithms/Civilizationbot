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
use React\Http\Browser;

/**
  * @property-read  Browser|null          $browser
  * @property-read  Discord|null          $discord
  * @property-read  LoggerInterface|null  $logger
  */
  
class GameServer
{
    protected string $PARENT_CLASS_PROPERTY = 'civ13';
    
    public function __construct(
        public Civ13 &$civ13,
        protected readonly string $ip,
        protected readonly string $port
    ) {}
    
    public function getBrowserProperty(): ?Browser
    {
        return isset($this->civ13->browser)
            ? $this->civ13->browser
            : null;
    }
    
    public function getDiscordProperty(): ?Discord
    {
        return isset($this->civ13->discord)
            ? $this->civ13->discord
            : null;
    }

    public function getLoggerProperty(): ?LoggerInterface
    {
        return isset($this->civ13->logger)
            ? $this->civ13->logger
            : null;
    }

    public function getProtocol(): string
    {
        return $this->protocol ?? 'http';
    }

    public function getIP(): string
    {
        return $this->ip ?? gethostbyname('www.civ13.com');
    }

    public function getPort(): string
    {
        return $this->port ?? 1212;
    }

    public function setProtocol($string = 'http'): void
    {
        if (!isset($this->protocol)) $this->protocol = $string;
    }
    
    public function setIP(string $ip): void
    {
        if (!isset($this->ip)) $this->ip = $ip;
    }

    public function setPort(string $port): void
    {
        if (!isset($this->port)) $this->port = $port;
    }

    use DynamicPropertyAccessorTrait;
}