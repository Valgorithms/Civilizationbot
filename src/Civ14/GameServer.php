<?php declare(strict_types=1);

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ14;

use Civ13\Civ13;
use Civ13\Exceptions\PartException;
use Civ13\OSFunctions;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Helpers\ExCollectionInterface;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

use function React\Async\async;
use function React\Promise\resolve;
use function React\Promise\reject;

use function React\Async\await;

/**
  * @property-read  ExCollectionInterface $medals
  * @property-read  Browser               $browser
  * @property-read  Discord               $discord
  * @property-read  LoggerInterface       $logger
  * @property-read  LoopInterface         $loop
  */
class GameServer
{
    use ServerApiTrait;
    use DynamicPropertyAccessorTrait;

    public const MEDALS = '/medals.json';

    /** @var Civ13 $civ13 */
    protected $civ13;

    public bool    $enabled;
    public string  $basedir;
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
        $this->basedir       = $options['basedir']        ?? null;
        $this->key           = $options['key']            ?? 'civ14';
        $this->name          = $options['name']           ?? 'Civilization 14';
        $this->protocol      = $options['protocol']       ?? 'http';
        $this->ip            = $options['ip']             ?? '127.0.0.1';
        $this->port          = (int) $options['port']     ?? 1212;
        $this->host          = $options['host']           ?? 'Taislin';
        $this->playercount   = $options['playercount']    ?? '';
        $this->discussion    = $options['discussion']     ?? '';
        $this->watchdogToken = $options['watchdogToken']  ?? null;
        if ($status = $this->civ13->VarLoad("{$this->key}_status.json") ?? []) $this->updateServerPropertiesFromStatusArray($status, false);
        $this->afterConstruct();
    }
    protected function afterConstruct(): void
    {
        $this->setup();
        if (! $this->enabled) return; // Don't start timers for disabled servers
        $this->civ13->deferUntilReady(
            function (): void
            {
                $this->getStatus(); // Ignore errors, just return offline status
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

    /**
     * Announces the start of a new round in the Discord discussion channel.
     *
     * This method checks if the game server is enabled and if the discussion channel exists and has been created.
     * If all checks pass, it sends a message to the channel announcing the new round, optionally mentioning a specific role.
     * If any check fails, it logs the error and returns a rejected promise.
     *
     * @return PromiseInterface Resolves when the announcement message is sent, or rejects with a PartException on failure.
     */
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

    /**
     * Announces the online or offline status of the server in the designated Discord channel.
     *
     * This method checks if the announcement feature is enabled and if the specified discussion channel exists and is created.
     * If all checks pass, it sends an online or offline message to the channel.
     *
     * @param bool $status Indicates whether to announce as online (true) or offline (false). Defaults to true (online).
     * @return PromiseInterface Resolves when the message is sent, or rejects with a PartException if the channel is invalid.
     */
    public function announceOnline(bool $status = true)
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
            ($status ? '**Online**' : '**Offline**')
        );
    }

    /**
     * Starts or retrieves a periodic timer that updates the player count channel.
     *
     * This method attempts to open a socket connection to the game server on the specified port.
     * If the connection is successful, it asynchronously retrieves the server status.
     * If the connection fails, it sets the playing status to 0.
     * The method then returns an existing periodic timer for updating the player count channel,
     * or creates a new one if it does not exist. The timer triggers every 600 seconds.
     *
     * @return TimerInterface The periodic timer responsible for updating the player count channel.
     */
    public function playercountTimer(): PromiseInterface
    {
        await($this->civ13->then($this->getStatus(), null, fn(\Throwable $e) => null));
        return $this->civ13->then(
            $this->getStatus(),
            fn() => $this->setPlayercountTimer(),
            fn(\Throwable $e) => null
        );
    }

    public function setPlayercountTimer()
    {
        return (isset($this->playercount_timer))
            ? $this->playercount_timer
            : $this->playercount_timer = $this->loop->addPeriodicTimer(600, fn () => $this->playercountChannelUpdate());
    }

    /**
     * Returns the timer responsible for periodically updating the current round embed message.
     *
     * If the timer does not already exist, it initializes the timer to call
     * updateCurrentRoundEmbedMessageBuilder() every 60 seconds. The timer is stored
     * in $this->current_round_embed_timer to ensure only one instance is active.
     *
     * @return TimerInterface The periodic timer for updating the current round embed message.
     */
    public function currentRoundEmbedTimer(): TimerInterface
    {
        if (! isset($this->current_round_embed_timer)) {
            $this->civ13->then($this->updateCurrentRoundEmbedMessageBuilder()); // Call the function on the first access attempt
            $this->current_round_embed_timer = $this->loop->addPeriodicTimer(60, async(fn() => $this->civ13->then($this->updateCurrentRoundEmbedMessageBuilder())));
        }
        return $this->current_round_embed_timer;
    }

    /**
     * Updates the name of the player count channel to reflect the current number of players.
     *
     * This method checks if the specified player count channel exists and has been created.
     * If the channel exists and its name does not match the current player count, it updates
     * the channel's name accordingly. Returns a resolved promise if no update is needed,
     * or a rejected promise if the channel does not exist or has not been created.
     *
     * @return PromiseInterface Resolves when the channel name is updated or no update is needed,
     *                          rejects if the channel does not exist or is not created.
     */
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
        [$channel_prefix, $existing_count] = explode('-', $channel->name);
        $playing_count = empty($this->__status)
            ? 0
            : $this->playing;
        if ((int) $existing_count !== $playing_count) {
            $channel->name = "{$channel_prefix}-{$playing_count}";
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
        return $this->getStatus()->finally(fn(): PromiseInterface => $this->__updateCurrentRoundEmbedMessageBuilder($channel, Civ13::createBuilder()->addEmbed($this->toEmbed())));
    }

    protected function __updateCurrentRoundEmbedMessageBuilder($channel, $builder): PromiseInterface
    {
        $resend = function (?Message $message, callable $new) {
            if ($message) $message->delete();
            return $new(new PartException("Failed to edit current round message in {$this->key} ({$this->name})"));
        };
        $send = fn(Message $message): bool                      => $this->civ13->VarSave($this->getRoundMessageIdFileName(), [$this->round_message_id = $message->id]);
        $new  = fn(\Throwable $error): PromiseInterface         => $this->civ13->then($channel->sendMessage($builder), $send);
        $edit = fn(?Message $message = null): ?PromiseInterface => $message ? $this->civ13->then($message->edit($builder), null, fn (\Throwable $error) => $resend($message, $new)) : null;
        
        return ($round_message_id = $this->getRoundMessageId())
            ? $this->civ13->then($channel->messages->fetch($round_message_id), $edit, $new)
            : $this->civ13->then($channel->sendMessage($builder), $send, null);
    }

    /**
     * Generates a Discord embed representing the current state of the game server.
     *
     * If the server is offline or an error occurs during status fetch, the embed will indicate the server is offline.
     * Otherwise, the embed will include details such as server URL, host, player count, map, round ID, and elapsed time.
     *
     * @return Embed The generated embed containing server information.
     */
    public function toEmbed(): Embed
    {
        $embed = $this->civ13->createEmbed();
        if (empty($this->__status)) return $embed->addFieldValues($this->name, 'Offline');
        if (! empty($this->players)) $embed->addFieldValues(
            'Playing',
            implode(', ', $this->playersCollection(true)->toArray())
        );
        return $embed
            ->setTitle($this->name)
            ->addFieldValues('Server URL', "ss14://{$this->ip}:{$this->port}", false)
            ->addFieldValues('Host', $this->host, true)
            ->addFieldValues('Players', "{$this->playing}/{$this->soft_max_players}", true)
            ->addFieldValues('Map', $this->map, true)
            ->addFieldValues('Round ID', (string)$this->round_id, true)
            ->addFieldValues('Elapsed Time', ($this->round_start_time && $elapsed = $this->parseElapsedTime()) ? $elapsed : 'N/A', true);
    }

    public function playersCollection(bool $desc_safe = false): ExCollectionInterface
    {
        if (! $collection = $this->civ13->ss14verifier->toCollection($discrim = 'ss14')) return new Collection($this->players);

        $players = array_map(
            fn($player) => ($item = $collection->get($discrim, $player)) ? "<@{$item['discord']}>" : $player,
            $this->players
        );

        if (! $desc_safe) return new Collection($players);

        // Ensure the combined length of the imploded $players does not exceed 1024 characters
        $max_length = 1024;
        $separator = ', ';
        $current_length = 0;
        $result = [];

        foreach ($players as $player) {
            $add_length = strlen($player) + ($result ? strlen($separator) : 0);
            if ($current_length + $add_length > $max_length) break;
            $result[] = $player;
            $current_length += $add_length;
        }

        return new Collection($result);
    }

    /**
     * Calculates and returns the elapsed time since the round started as a human-readable string.
     *
     * The elapsed time is computed as the difference between the current UTC time and the round start time.
     * The result is formatted to include days, hours, minutes, and seconds, omitting any zero-value units.
     *
     * @return string Human-readable elapsed time (e.g., "1 days, 2 hours, 3 minutes, 4 seconds").
     */
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
     * Retrieves the round message ID.
     *
     * This method attempts to return the round message ID for the current instance.
     * - If the property `$round_message_id` is already set, it returns its value.
     * - Otherwise, it tries to load the value from a serialized array stored in a JSON file
     *   using the `VarLoad` method of the `$civ13` object, with a filename based on the instance's key.
     *   If successful, it sets and returns the first element of the loaded array as `$round_message_id`.
     * - If neither is available, it returns null.
     *
     * @return string|null
     */
    public function getRoundMessageId(): ?string
    {
        if (isset($this->round_message_id)) return $this->round_message_id;
        if ($serialized_array = $this->civ13->VarLoad($this->getRoundMessageIdFileName())) return $this->round_message_id = array_shift($serialized_array);
        return null;
    }

    /**
     * Generates the filename for storing the round message ID associated with this game server instance.
     *
     * @return string
     */
    public function getRoundMessageIdFileName(): string
    {
        return "{$this->key}_round_message_id.json";
    }

    /**
     * Retrieves the medals data as an array, or an empty array if the file does not exist.
     *
     * @return ExCollectionInterface
     */
    protected function getMedalsProperty(): ExCollectionInterface
    {
        $data = OSFunctions::VarLoad($this->basedir, self::MEDALS, $this->logger);
        return new Collection(
            isset($data) ? array_shift($data) : [],
            'user'
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