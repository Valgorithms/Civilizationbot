<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13MessageCommand;
use Civ14\GameServer;
use Discord\Parts\Channel\Message;
use Discord\Parts\Guild\Guild;
use React\Promise\PromiseInterface;
    
enum SS14MedalEmojis: string
{
    case BronzeNomadsVeteran = 'Bronze Nomads Veteran Medal';
    case SilverNomadsVeteran = 'Silver Nomads Veteran Medal';
    case GoldNomadsVeteran   = 'Gold Nomads Veteran Medal';

    public function emoji(): string
    {
        return match($this) {
            self::BronzeNomadsVeteran => 'nomads_bronze',
            self::SilverNomadsVeteran => 'nomads_silver',
            self::GoldNomadsVeteran   => 'nomads_gold',
        };
    }

    public static function fromName(string $name): ?self
    {
        return match($name) {
            self::BronzeNomadsVeteran->value => self::BronzeNomadsVeteran,
            self::SilverNomadsVeteran->value => self::SilverNomadsVeteran,
            self::GoldNomadsVeteran->value   => self::GoldNomadsVeteran,
            default => null,
        };
    }

    public static function withEmoji(string $name): string
    {
        return ($enum = self::fromName($name))
            ? $enum->emoji() . ' ' . $name
            : $name;
    }
}


/**
 * Handles the "14medals" command.
 */
class SS14Medals extends Civ13MessageCommand
{
    public function __construct(protected Civ13 &$civ13, protected GameServer &$gameserver){}

    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $id = self::messageWithoutCommand($command, $message_filtered)) {
            if (! $item = $this->civ13->ss14verifier->get('discord', $message->author->id)) return $this->civ13->reply($message, 'Please register your SS14 account using the `/verifyme` command.');
            $id = $item['ss14'] ?? null;
        }
        if (is_numeric($id)) {
            if (! $item = $this->civ13->ss14verifier->get('discord', $id)) return $this->civ13->reply($message, "Unable to locate verified Discord account with id `$id`.");
            $id = $item['ss14'] ?? null;
        }
        if (! $medals = self::getMedals($this->gameserver, $id)) return $this->civ13->reply( $message, "No SS14 medals found for `$id`.");
        return $this->civ13->reply($message,
            "Medals for $id:" . PHP_EOL
            . (implode(PHP_EOL, ($guild = $this->discord->guilds->get('id', $this->civ13->civ13_guild_id))
                ? self::getMedalsWithEmojis($guild, $medals)
                : $medals))
        );
    }

    /**
     * Appends emojis to the beginning of each medal.
     *
     * @param String[] $medals
     * @return array
     */
    public static function getMedalsWithEmojis(Guild $guild, array $medals): array
    {
        return array_map(static fn($medal) =>
            ($emoji = self::getMedalEmoji($guild, $medal))
                ? "$emoji $medal"
                : $medal,
            $medals);
    }

    /**
     * Retrieves the emojis for the given medals.
     *
     * @param Guild $guild
     * @param String[] $medals
     * @return array
     */
    public static function getMedalEmojis(Guild $guild, array $medals): array
    {
        return array_filter(array_map(fn($medal) => self::getMedalEmoji($guild, $medal), $medals));
    }

    /**
     * Retrieves the emoji for a specific medal.
     *
     * @param Guild $guild
     * @param string $medal
     * @return string|null
     */
    public static function getMedalEmoji(Guild $guild, string $medal): ?string
    {
        if ($enum = SS14MedalEmojis::fromName($medal)) {
            if ($emoji = $guild->emojis->get('name', $enum->emoji())) {
                return (string) $emoji;
            }
        }
        return null;
    }
    
    /**
     * Retrieves the medals associated with a specific SS14 user.
     *
     * @param GameServer $gameserver
     * @param string $ss14
     * @return array|null
     */
    public static function getMedals(GameServer $gameserver, string $ss14): ?array
    {
        return ($item = $gameserver->medals->get('user', $ss14))
            ? $item['medals'] ?? null
            : null;
    }
}