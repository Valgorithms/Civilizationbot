<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand;

use Civ13\Civ13;
use Civ14\DynamicPropertyAccessorTrait;
use Discord\Discord;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;

/**
  * @property-read  Browser          $browser
  * @property-read  Discord          $discord
  * @property-read  LoggerInterface  $logger
  * @property-read  LoopInterface    $loop
  */
class Civ13MessageCommand extends MessageCommand
{
    use DynamicPropertyAccessorTrait;

    public function __construct(protected Civ13 &$civ13){}

    public function new(\Closure|callable|null $callback = null): static
    {
        $new = new static($this->civ13);
        $new->setCallback($callback);
        return $new;
    }

    protected function getBrowserProperty(): Browser
    { 
        return isset($this->civ13->browser)
            ? $this->civ13->browser
            : new Browser($this->loop ?? Loop::get()); // Workaround for PHPUnit tests
    }

    protected function getDiscordProperty(): Discord
    {
        return $this->civ13->discord;
    }

    protected function getLoggerProperty(): LoggerInterface
    {
        return $this->civ13->logger;
    }

    protected function getLoopProperty(): LoopInterface
    { 
        return isset($this->civ13->loop)
            ? $this->civ13->loop
            : Loop::get(); // Workaround for PHPUnit tests
    }

    public function __debugInfo(): array
    {
        return [
            'class' => get_class($this),
            'methods' => get_class_methods($this),
        ];
    }
}