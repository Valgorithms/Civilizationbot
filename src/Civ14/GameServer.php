<?php declare(strict_types=1);

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ14;

use Civ13\Civ13;
use Civ13\Exceptions\PartException;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
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
  * @property-read  LoopInterface    $loop
  */
class GameServer
{
    use ServerApiTrait;
    use DynamicPropertyAccessorTrait;

    /** @var Civ13 $civ13 */
    protected $civ13;

    public bool    $enabled;
    public string  $key;
    public string  $host;
    public string  $playercount; // Channel ID for player count
    public string  $discussion; // Channel ID for discussions

    protected ?string $round_message_id; // Message ID for the round embed message
    protected TimerInterface $playercount_timer;
    protected TimerInterface $current_round_embed_timer;

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
        $this->discussion    = $options['discussion']     ?? '';
        $this->watchdogToken = $options['watchdogToken']  ?? null;
        $this->afterConstruct();
    }
    protected function afterConstruct(): void
    {
        $this->setup();
        if (! $this->enabled) return; // Don't start timers for disabled servers
        $this->civ13->deferUntilReady(
            function (): void
            {
                $this->getStatus(true); // Ignore errors, just return offline status
                $this->logger->info("Getting player count for SS14 GameServer {$this->name}");
                $this->playercountTimer(); // Update playercount channel every 10 minutes
                $this->currentRoundEmbedTimer(); // The bot has to set a round id first
            },
            __METHOD__ . " ({$this->key})"
        );

    }
    protected function setup(): void
    {
        $this->civ13->civ14_gameservers[$this->key] =& $this;
        if ($this->enabled) $this->civ13->civ14_enabled_gameservers[$this->key] =& $this;
        $this->logger->info('Added ' . ($this->enabled ? 'enabled' : 'disabled') . " SS14 game server: {$this->name} ({$this->key})");
    }

    public function announceNewRound(): PromiseInterface
    {
        if (! $this->enabled) return resolve(null);
        if (! $channel = $this->discord->getChannel($this->discussion)) {
            $this->logger->debug($err = "Channel {$this->discussion} doesn't exist!");
            return reject(new PartException($err));
        }
        if (! $channel->created) {
            $this->logger->warning($err = "Channel {$channel->name} hasn't been created!");
            return reject(new PartException($err));
        }
        return $this->civ13->sendMessage(
            $channel,
            (isset($this->civ13->role_ids['round_start']) ? "<@&{$this->civ13->role_ids['round_start']}>, " : "")
                . "New round `{$this->round_id}` has started!"
        );
    }

    public function playercountTimer(): TimerInterface
    {
        (is_resource($socket = @fsockopen('localhost', $this->port, $errno, $errstr, 1)) && fclose($socket) && await($this->getStatus(true)))
            ?: $this->playing = 0;
        return (isset($this->playercount_timer))
            ? $this->playercount_timer
            : $this->playercount_timer = $this->loop->addPeriodicTimer(600, fn () => $this->playercountChannelUpdate());
    }

    public function currentRoundEmbedTimer(): TimerInterface
    {
        if (! isset($this->current_round_embed_timer)) {
            $this->updateCurrentRoundEmbedMessageBuilder();
            $this->current_round_embed_timer = $this->loop->addPeriodicTimer(60, fn() => $this->updateCurrentRoundEmbedMessageBuilder());
        }
        return $this->current_round_embed_timer;
    }

    public function playercountChannelUpdate(): PromiseInterface
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
        if ((int) $existingCount !== $this->playing) {
            $channel->name = "{$channelPrefix}-{$this->playing}";
            return $channel->guild->channels->save($channel);
        }
        return resolve(null);
    }

    /**
     * Updates the current round embed message builder.
     *
     * @param MessageBuilder|null $builder The message builder to used to perform the update the message. Defaults to null.
     * @return PromiseInterface<Message> A promise that resolves when the update is complete.
     */
    public function updateCurrentRoundEmbedMessageBuilder(): PromiseInterface
    {
        if (! $guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id)) {
            $this->logger->error($err = "Could not find Guild with ID `{$this->civ13->civ13_guild_id}`");
            return reject(new PartException($err));
        }
        if (! $channel = $guild->channels->get('id', $this->playercount)) {
            $this->logger->error($err = "Could not find Channel with ID `{$this->playercount}`");
            return reject(new PartException($err));
        }
        $builder = Civ13::createBuilder()->addEmbed($this->toEmbed(true));

        $send_onFulfilled   = fn(Message $message): bool                      => $this->civ13->VarSave("{$this->key}_round_message_id.json", [$this->round_message_id = $message->id]);
        $edit_onFulfilled   = fn(?Message $message = null): ?PromiseInterface  => $message ? $this->civ13->then($message->edit($builder), $this->civ13->onFulfilledDefault) : null;
        $edit_onRejected    = fn(\Throwable $error): PromiseInterface         => $this->civ13->then($channel->sendMessage($builder), $send_onFulfilled);
        
        return ($round_message_id = $this->getRoundMessageId())
            ? $this->civ13->then($channel->messages->fetch($round_message_id), $edit_onFulfilled, $edit_onRejected)
            : $this->civ13->then($channel->sendMessage($builder), $send_onFulfilled, null);
    }

    public function toEmbed(bool $fetch = false): Embed
    {
        $embed = $this->civ13->createEmbed();
        if ($fetch) try {
            /** @var array */
            await($this->civ13->then($this->getStatus(true)));
        } catch (\Throwable $e) { // Ignore errors, just return offline status
            return $embed->addFieldValues($this->name, 'Offline');
        }
        if (empty($this->__status)) return $embed->addFieldValues($this->name, 'Offline');
        if (! empty($this->players)) $embed->addFieldValues('Playing', implode(', ', $this->players));
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

    public function getRoundMessageId(): ?string
    {
        if (isset($this->round_message_id)) return $this->round_message_id;
        if ($serialized_array = $this->civ13->VarLoad("{$this->key}_round_message_id.json")) return $this->round_message_id = array_shift($serialized_array);
        return null;
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