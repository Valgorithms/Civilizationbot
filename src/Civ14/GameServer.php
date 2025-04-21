<?php declare(strict_types=1);

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ14;

use Civ13\Civ13;
use Civ13\Exceptions\PartException;
use Discord\Discord;
use Discord\Parts\Embed\Embed;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;


use function React\Promise\resolve;
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

    /** @var Civ13 $civ13 */
    protected $civ13;

    public bool   $enabled;
    public string $key;
    public string $host;
    public string $playercount; // Channel ID for player count
    
    public array  $players = []; // Cannot be retrieved via the hub or server API
    /** @var Timerinterface[] */
    public array  $timers  = [];

    // Normally would just promote the property, but currently causes an issue in PHPUnit tests
    public function __construct(
        &$civ13,
        array &$options = []
    ) {
        $this->civ13         = &$civ13;
        $this->enabled       = (bool) $options['enabled'] ?? true;
        $this->key           = $options['key']            ?? 'civ14';
        $this->name          = $options['name']           ?? 'Civilization 14';
        $this->protocol      = $options['protocol']       ?? 'http';
        $this->ip            = $options['ip']             ?? '127.0.0.1';
        $this->port          = (int) $options['port']     ?? 1212;
        $this->host          = $options['host']           ?? 'Taislin';
        $this->playercount   = $options['playercount']    ?? '';
        $this->watchdogToken = $options['watchdogToken']  ?? null;
        $this->afterConstruct();
    }
    protected function afterConstruct(): void
    {
        $this->setup();
        $this->civ13->deferUntilReady(
            function (): void
            {
                $this->civ13->then($this->getStatus(), null, fn($e) => null); // Ignore errors, just return offline status
                $this->logger->info("Getting player count for SS14 GameServer {$this->name}");
                $this->playercountTimer(); // Update playercount channel every 10 minutes
            },
            $this->key
        );

    }
    protected function setup(): void
    {
        $this->civ13->civ14_gameservers[$this->key] =& $this;
        if ($this->enabled) $this->civ13->civ14_enabled_gameservers[$this->key] =& $this;
        $this->logger->info('Added ' . ($this->enabled ? 'enabled' : 'disabled') . " SS14 game server: {$this->name} ({$this->key})");
    }

    public function playercountTimer(): TimerInterface
    {
        (is_resource($socket = @fsockopen('localhost', $this->port, $errno, $errstr, 1)) && fclose($socket) && $this->getStatus())
            ?: $this->playing = 0;
        if (! isset($this->timers['playercount_timer]'])) $this->timers['playercount_timer'] = $this->loop->addPeriodicTimer(600, fn () => $this->playercountChannelUpdate($this->playing));
        return $this->timers['playercount_timer'];
    }
    
    public function playercountChannelUpdate(int $count = 0): PromiseInterface
    {
        if (! $channel = $this->discord->getChannel($this->playercount)) {
            $this->logger->debug($err = "Channel {$this->playercount} doesn't exist!");
            return reject(new PartException($err));
        }
        if (! $channel->created) {
            $this->logger->warning($err = "Channel {$channel->name} hasn't been created!");
            return reject(new PartException($err));
        }
        [$channelPrefix, $existingCount] = explode('-', $channel->name);
        if ((int) $existingCount !== $count) {
            $channel->name = "{$channelPrefix}-{$count}";
            return $channel->guild->channels->save($channel);
        }
        return resolve(null);
    }

    public function toEmbed(): Embed
    {
        $embed = $this->civ13->createEmbed();
        try {
            /** @var array $info */
            await($this->civ13->then($this->getStatus()));
        } catch (\Throwable $e) { // Ignore errors, just return offline status
            return $embed->addFieldValues($this->name, 'Offline');
        }
        if (empty($this->__status)) return $embed->addFieldValues($this->name, 'Offline');

        return $embed
            ->setTitle($this->name)
            ->addFieldValues('Server URL', "ss14://{$this->ip}:{$this->port}", false)
            ->addFieldValues('Host', $this->host, true)
            ->addFieldValues('Players', "{$this->playing}/{$this->soft_max_players}", true)
            ->addFieldValues('Map', $this->map, true)
            ->addFieldValues('Round ID', (string)$this->round_id, true)
            ->addFieldValues('Elapsed Time', ($this->round_start_time && $elapsed = $this->parseElapsedTime()) ? $elapsed : 'N/A', true);
    }

    protected function parseElapsedTime(): string
    {
        $interval = (new \DateTime($this->round_start_time))->diff(new \DateTime("now", new \DateTimeZone("UTC")));
        return implode(', ', array_filter([
            $interval->d > 0 ? $interval->d . ' days' : null,
            $interval->h > 0 ? $interval->h . ' hours' : null,
            $interval->i > 0 ? $interval->i . ' minutes' : null,
            $interval->s > 0 ? $interval->s . ' seconds' : null,
        ]));
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
            : new Browser($this->loop ?? Loop::get()); // Workaround for civ13->browser property not set in PHPUnit tests
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

    /**
     * Retrieves the event loop instance associated with the Civ13 object.
     *
     * This method checks if the `civ13->loop` property is set and returns it.
     * If the property is not set (e.g., during PHPUnit tests), it falls back
     * to using the default loop instance provided by `Loop::get()`.
     *
     * @return LoopInterface
     */
    protected function getLoopProperty(): LoopInterface
    { 
        return isset($this->civ13->loop)
            ? $this->civ13->loop
            : Loop::get(); // Workaround for civ13->loop property not set in PHPUnit tests
    }
}