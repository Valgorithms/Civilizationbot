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

namespace Civ13\MessageCommand;

use Civ13\Civ13;
use Civ14\DynamicPropertyAccessorTrait;
use Discord\Discord;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;

/**
 * @property-read Browser         $browser
 * @property-read Discord         $discord
 * @property-read LoggerInterface $logger
 * @property-read LoopInterface   $loop
 */
class Civ13MessageCommand extends MessageCommand
{
    use DynamicPropertyAccessorTrait;

    protected const string TITLE = 'Command Title';
    protected const string DESCRIPTION_TEXT = 'This command is not implemented yet.';
    protected const string ACCENT_COLOR_DEFAULT = 'f1c40f';
    protected const string ACCENT_COLOR_ERROR = 'e91e63';

    /** @param Civ13 $civ13 The bot instance this command reads its services from (held by reference). */
    public function __construct(protected Civ13 &$civ13)
    {
    }

    /**
     * Returns a fresh instance bound to the same bot, with `$callback` as its handler.
     *
     * @return static
     */
    public function new(\Closure|callable|null $callback = null): static
    {
        $new = new static($this->civ13);
        $new->setCallback($callback);

        return $new;
    }

    /** Backs the `browser` magic property; falls back to a fresh Browser during PHPUnit runs. */
    protected function getBrowserProperty(): Browser
    {
        return isset($this->civ13->browser)
            ? $this->civ13->browser
            : new Browser($this->loop ?? Loop::get()); // Workaround for PHPUnit tests
    }

    /** Backs the `discord` magic property. */
    protected function getDiscordProperty(): Discord
    {
        return $this->civ13->discord;
    }

    /** Backs the `logger` magic property. */
    protected function getLoggerProperty(): LoggerInterface
    {
        return $this->civ13->logger;
    }

    /** Backs the `loop` magic property; falls back to the global loop during PHPUnit runs. */
    protected function getLoopProperty(): LoopInterface
    {
        return isset($this->civ13->loop)
            ? $this->civ13->loop
            : Loop::get(); // Workaround for PHPUnit tests
    }

    /** @inheritDoc */
    public function __debugInfo(): array
    {
        return [
            'class' => get_class($this),
            'methods' => get_class_methods($this),
        ];
    }
}
