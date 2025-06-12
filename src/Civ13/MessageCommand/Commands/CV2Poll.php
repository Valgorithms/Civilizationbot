<?php declare(strict_types=1);

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Carbon\Carbon;
use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Thread\Thread;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Handles the "CV2Poll" command.
 */
class CV2Poll extends Civ13MessageCommand
{
    protected const string    TITLE            = 'Poll';
    protected const string    DESCRIPTION_TEXT = 'Click a button to vote.';
    protected const int|float TIMER_INTERVAL   = 5.0; // Seconds
    protected const int|float TIMER_DURATION   = 60.0; // Seconds

    protected array|null          $yes_votes;
    protected array|null          $no_votes;
    protected Channel|Thread|null $channel;
    protected Message|null        $poll_message;
    protected Carbon|null         $start_time;
    protected TimerInterface|null $ticker;

    protected Button|null $yes_button;
    protected Button|null $no_button;
    protected Button|null $abstain_button;
    protected Button|null $resolve_button;
    protected Button|null $cancel_button;

    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        switch (self::messageWithoutCommand($command, $message_filtered, true)) {
            case 'start':
                return $this->start($message->channel);
            case 'stop':
            case 'cancel':
                $this->cancel();
                return $message->reply('Poll has been cancelled.');
            default:
                return $message->reply('Invalid format. Use `!cv2poll start` to start a poll or `!cv2poll stop` to cancel it.');
        }
    }

    /** @var PromiseInterface<?TimerInterface> */
    protected function start(Channel|Thread $channel): ?PromiseInterface
    {
        if (isset($this->ticker)) {
            $this->logger->warning('Poll already active in channel {channel_id}', ['channel_id' => $channel->id, 'ticket_class' => get_class($this->ticker)]);
            return null; // Only one poll can be active at a time
        }

        $this->channel = $channel;

        return $this->tick()->then(fn() => $this->createTicker());
    }

    protected function cancel(): void
    {
        $this->cancelTicker();
        if (isset($this->yes_button)) $this->yes_button->removeListener();
        if (isset($this->no_button)) $this->no_button->removeListener();
        if (isset($this->abstain_button)) $this->abstain_button->removeListener();
        if (isset($this->resolve_button)) $this->resolve_button->removeListener();
        if (isset($this->cancel_button)) $this->cancel_button->removeListener();
        $this->yes_votes = null;
        $this->no_votes = null;
        $this->channel = null;
        $this->poll_message = null;
        $this->start_time = null;
        $this->yes_button = null;
        $this->no_button = null;
        $this->abstain_button = null;
        $this->resolve_button = null;
        $this->cancel_button = null;
    }

    protected function resolve(): void
    {
        // @todo
        $this->cancel();
    }

    public function createBuilder(bool $final = false): MessageBuilder
    {
        return Civ13::createBuilder(true)->addComponent($this->createContainer($final));
    }

    public function createContainer(bool $final = false): Container
    {
        return Container::new()->setAccentColor(self::ACCENT_COLOR_DEFAULT)->addComponents([
            TextDisplay::new('# ' . self::TITLE),
            Separator::new(),
            TextDisplay::new('## Description'),
            TextDisplay::new(self::DESCRIPTION_TEXT),
            Separator::new(),
            TextDisplay::new('## Votes'),
            TextDisplay::new('Yes: ' . (isset($this->yes_votes) ? count($this->yes_votes) : 0)),
            TextDisplay::new('No: ' . (isset($this->no_votes) ? count($this->no_votes) : 0)),
            $final
                ? TextDisplay::new('Poll has ended.')
                : TextDisplay::new('Poll ends in ' . Carbon::now()->diffForHumans(($this->start_time ??= Carbon::now())->copy()->addSeconds(self::TIMER_DURATION), true)),
            Separator::new(),
            $final
                ? TextDisplay::new('### Winner: ' . $this->getWinner())
                : ActionRow::new()
                    ->addComponent($this->getYesButton())
                    ->addComponent($this->getNoButton())
                    ->addComponent($this->getAbstainButton())
                    ->addComponent($this->getResolveButton())
                    ->addComponent($this->getCancelButton()),
        ]);
    }

    public function getWinner(): string
    {
        $yes_count = count($this->yes_votes ?? []);
        $no_count = count($this->no_votes ?? []);

        if ($yes_count === 0 && $no_count === 0) return 'No votes cast';
        return match ($yes_count <=> $no_count) {
            1 => 'Yes',
            0 => 'Tie',
            -1 => 'No',
        };
    }

    protected function getYesButton(): Button
    {
        return $this->yes_button ??= Button::new(Button::STYLE_PRIMARY, 'vote_yes')->setLabel('Yes')->setListener(
            fn(Interaction $interaction) => $this->voteYes($interaction->member->id),
            $this->discord,
            false
        );
    }

    protected function getNoButton(): Button
    {
        return $this->no_button ??= Button::new(Button::STYLE_PRIMARY, 'vote_no')->setLabel('No')->setListener(
            fn(Interaction $interaction) => $this->voteNo($interaction->member->id),
            $this->discord,
            false
        );
    }

    protected function getAbstainButton(): Button
    {
        return $this->abstain_button ??= Button::new(Button::STYLE_SECONDARY, 'vote_abstain')->setLabel('Remove My Vote')->setListener(
            fn(Interaction $interaction) => $this->removeVote($interaction->member->id),
            $this->discord,
            false
        );
    }

    protected function getResolveButton(): Button
    {
        return $this->resolve_button ??= Button::new(Button::STYLE_SUCCESS, 'vote_resolve')->setLabel('Resolve Vote')->setListener(
            fn(Interaction $interaction) => $this->tick(true),
            $this->discord,
            true
        );
    }

    protected function getCancelButton(): Button
    {
        return $this->cancel_button ??= Button::new(Button::STYLE_DANGER, 'vote_cancel')->setLabel('Cancel Vote')->setListener(
            function (Interaction $interaction) {
                $message = clone $this->poll_message;
                $channel = clone $this->poll_message->channel;
                $this->loop->addTimer(self::TIMER_INTERVAL+1, // Workaround for Discord API rate limits
                    fn() => $channel->messages->delete($message, 'Poll cancelled')
                );
                $this->cancel();
            },
            $this->discord,
            true
        );
    }

    protected function createTicker(): TimerInterface
    {
        $this->cancelTicker(); // Avoid multiple timers
        return $this->ticker = $this->loop->addPeriodicTimer(self::TIMER_INTERVAL,
            fn() => $this->civ13->then($this->tick())
        );
    }

    protected function cancelTicker(): void
    {
        if (isset($this->ticker)) {
            $this->loop->cancelTimer($this->ticker);
            unset($this->ticker);
        }
    }

    /** @return PromiseInterface<?Message> */
    protected function createMessage(MessageBuilder $builder): PromiseInterface
    {
        return isset($this->channel)
            ?   $this->civ13->then(
                    $this->channel->sendMessage($builder),
                    fn(Message $message) => $this->poll_message = $message
                )
            :   resolve(null); // No channel to send the message to

    }

    /** @return PromiseInterface<?Message> */
    protected function editMessage(MessageBuilder $builder): PromiseInterface
    {
        return $this->civ13->then(
            $this->poll_message
                ? $this->poll_message->edit($builder)
                : resolve(null) // No message to edit
        );
    }

    /** @return PromiseInterface<?Message> */
    protected function tick(bool $final = false): PromiseInterface
    {
        $final = $final ?: (Carbon::now()->isAfter(($this->start_time ??= Carbon::now())->copy()->addSeconds(self::TIMER_DURATION)));

        $promise = isset($this->poll_message)
            ? $this->editMessage($this->createBuilder($final))
            : $this->createMessage($this->createBuilder($final));

        if ($final) $this->resolve();

        return $promise;
    }

    protected function voteYes(string $user_id): void
    {
        if (!in_array($user_id, $this->yes_votes ?? [])) {
            $this->yes_votes[] = $user_id;
        }

        if (in_array($user_id, $this->no_votes ?? [])) {
            $this->no_votes = array_diff($this->no_votes ?? [], [$user_id]);
        }
    }

    protected function voteNo(string $user_id): void
    {
        if (!in_array($user_id, $this->no_votes ?? [])) {
            $this->no_votes[] = $user_id;
        }

        if (in_array($user_id, $this->yes_votes ?? [])) {
            $this->yes_votes = array_diff($this->yes_votes ?? [], [$user_id]);
        }
    }

    protected function removeVote(string $user_id): void
    {
        if (in_array($user_id, $this->yes_votes ?? [])) {
            $this->yes_votes = array_diff($this->yes_votes ?? [], [$user_id]);
        }

        if (in_array($user_id, $this->no_votes ?? [])) {
            $this->no_votes = array_diff($this->no_votes ?? [], [$user_id]);
        }
    }
}