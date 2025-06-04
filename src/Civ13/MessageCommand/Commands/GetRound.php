<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Member;
use React\Promise\PromiseInterface;

/**
 * Handles the "getround" command.
 * 
 * Allows users to fetch information about a specific game round from enabled gameservers.
 * Includes player lists, round start/end times, and, for staff, additional logging details.
 */
class GetRound extends Civ13MessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $input = self::messageWithoutCommand($command, $message_filtered)) return $this->civ13->reply($message, 'Invalid format! Please use the format: getround `game_id`');
        $input = explode(' ', $input);
        if (! $rounds = $this->getRounds($game_id = $input[0])) return $this->civ13->reply($message, "No data found for round `$game_id`.");
        return $message->reply($this->createBuilder(
            $message->member,
            $game_id,
            $rounds,
            isset($input[1]) ? Civ13::sanitizeInput($input[1]) : null
        ));
    }

    protected function getRounds(string $game_id): array
    {
        $rounds = [];
        foreach ($this->civ13->enabled_gameservers as $gameserver) if ($round = $gameserver->getRound($game_id)) {
            $round['server_key'] = $gameserver->key;
            $rounds[$gameserver->name] = $round;
        }
        return $rounds;
    }

    protected function createBuilder(Member $member, string $game_id, array $rounds, ?string $ckey = null): MessageBuilder
    {
        $builder = Civ13::createBuilder(true)->setContent("Round data for game_id `$game_id`" . ($ckey ? " (ckey: `$ckey`)" : ''));
        foreach ($rounds as $server => $round) {
            $embed = $this->createEmbed(
                $server,
                $round,
                $this->civ13->hasRank($member, ['Owner', 'Chief Technical Officer', 'Ambassador']),
                $ckey
            );
            if ($this->civ13->hasRank($member, ['Admin'])) $this->addStaffDetails(
                $embed,
                $builder,
                $round
            );
            $builder->addEmbed($embed);
        }
        return $builder;
    }

    protected function createEmbed($server, array $round, bool $high_staff = false, ?string $ckey = null): Embed
    {
        $embed = $this->civ13->createEmbed()
            ->setTitle($server)
            //->addFieldValues('Game ID', $game_id);
            ->addFieldValues('Start', $round['start'] ?? 'Unknown', true)
            ->addFieldValues('End', $round['end'] ?? 'Ongoing/Unknown', true);
        if (($players = implode(', ', array_keys($round['players']))) && strlen($players) <= 1024) $embed->addFieldValues('Players (' . count($round['players']) . ')', $players);
        else $embed->addFieldValues('Players (' . count($round['players']) . ')', 'Either none or too many to list!');
        if ($discord_ids = array_filter(array_map(fn($c) => ($item = $this->civ13->verifier->get('ss13', $c)) ? "<@{$item['discord']}>" : null, array_keys($round['players'])))) {
            if (strlen($verified_players = implode(', ', $discord_ids)) <= 1024) $embed->addFieldValues('Verified Players (' . count($discord_ids) . ')', $verified_players);
            else $embed->addFieldValues('Verified Players (' . count($discord_ids) . ')', 'Too many to list!');
        }
        if ($ckey && $player = $round['players'][$ckey]) {
            $player['ip'] ??= [];
            $player['cid'] ??= [];
            $ip = $high_staff ? implode(', ', $player['ip']) : 'Redacted';
            $cid = $high_staff ? implode(', ', $player['cid']): 'Redacted';
            $login = $player['login'] ?? 'Unknown';
            $logout = $player['logout'] ?? 'Unknown';
            $embed->addFieldValues("Player Data ($ckey)", "IP: $ip" . PHP_EOL . "CID: $cid" . PHP_EOL . "Login: $login" . PHP_EOL . "Logout: $logout");
        }
        return $embed;
    }

    protected function addStaffDetails(Embed &$embed, MessageBuilder &$builder, array $round)
    {
        $log = (isset($round['log']) && !empty($round['log']))
            ? str_replace('/', ';', "logs {$round['server_key']}{$round['log']}")
            : '';
        $interrupted = $round['interrupted'] ? 'Yes' : 'No';
        $embed->addFieldValues('Bot Logging Interrupted', $interrupted, true)->addFieldValues('Log Command', $log ?? 'Unknown', true);
        $builder->addComponent(
            ActionRow::new()->addComponent(
                Button::new(Button::STYLE_PRIMARY, $log)
                    ->setLabel('Log')
                    ->setEmoji('📝')
                    ->setListener(fn($interaction) => $interaction->acknowledge()->then(fn() => $this->interaction_log_handler($interaction)), $this->discord, $oneOff = true)
            )
        );
    }

    protected function interaction_log_handler(Interaction $interaction): PromiseInterface
    {
        if (! $interaction->member->roles->has($this->civ13->role_ids['Admin'])) return $interaction->sendFollowUpMessage(Civ13::createBuilder()->setContent('You do not have permission to use this command.'), true);
        $tokens = explode(';', substr($interaction->data['custom_id'], strlen('logs ')));
        $keys = [];
        foreach ($this->civ13->enabled_gameservers as &$gameserver) {
            $keys[] = $gameserver->key;
            if (trim($tokens[0]) !== $gameserver->key) continue; // Check if server is valid
            if (! isset($gameserver->basedir) || ! file_exists($gameserver->basedir . Civ13::log_basedir)) {
                $this->logger->warning($error = "Either basedir or `" . Civ13::log_basedir . "` is not defined or does not exist");
                return $interaction->sendFollowUpMessage(Civ13::createBuilder()->setContent($error));
            }

            unset($tokens[0]);
            $results = $this->civ13->FileNav($gameserver->basedir . Civ13::log_basedir, $tokens);
            if ($results[0]) return $interaction->sendFollowUpMessage(Civ13::createBuilder()->addFile($results[1], 'log.txt'), true);
            if (count($results[1]) > 7) $results[1] = [array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1]), array_pop($results[1])];
            if (! isset($results[2]) || ! $results[2]) return $interaction->sendFollowUpMessage(Civ13::createBuilder()->setContent('Available options: ' . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`'));
            return $interaction->sendFollowUpMessage(Civ13::createBuilder()->setContent("{$results[2]} is not an available option! Available options: " . PHP_EOL . '`' . implode('`' . PHP_EOL . '`', $results[1]) . '`'));
        }
        return $interaction->sendFollowUpMessage(Civ13::createBuilder()->setContent('Please use the format `logs {server}`. Valid servers: `' . implode(', ', $keys) . '`'));
    }
}