<?php declare(strict_types=1);
/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2025-present Valithor Obsidion <valzargaming.com>
 */

namespace Civ13\MessageCommand\Commands;

use Civ13\Civ13;
use Civ13\MessageCommand\Civ13GameServerMessageCommand;
use Discord\Parts\Channel\Message;
use Discord\Parts\Guild\Guild;
use React\Promise\PromiseInterface;

enum SS13Medal: string
{
    case CombatMedicalBadge  = 'combat medical badge';
    case TankDestroyerSilverBadge = 'tank destroyer silver badge';
    case TankDestroyerGoldBadge = 'tank destroyer gold badge';
    case AssaultBadge = 'tank destroyer silver badge';
    case WoundedBadge = 'assault badge';
    case WoundedSilverBadge = 'wounded silver badge';
    case WoundedGoldBadge = 'wounded badge';
    case IronCross1stClass = 'iron cross 1st class';
    case IronCross2ndClass = 'iron cross 2nd class';
    case LongServiceMedal = 'long service medal';

    public function emoji(): string
    {
        return match($this) {
            self::CombatMedicalBadge->value       => 'combat_medical_badge',
            self::TankDestroyerSilverBadge->value => 'tank_silver',
            self::TankDestroyerGoldBadge->value   => 'tank_gold',
            self::AssaultBadge->value             => 'assault',
            self::WoundedBadge->value             => 'wounded',
            self::WoundedSilverBadge->value       => 'wounded_silver',
            self::WoundedGoldBadge->value         => 'wounded_gold',
            self::IronCross1stClass->value        => 'iron_cross1',
            self::IronCross2ndClass->value        => 'iron_cross2',
            self::LongServiceMedal->value         => 'long_service',
        };
    }

    public static function fromName(string $name): ?self
    {
        return match($name) {
            self::CombatMedicalBadge->value       => self::CombatMedicalBadge,
            self::TankDestroyerSilverBadge->value => self::TankDestroyerSilverBadge,
            self::TankDestroyerGoldBadge->value   => self::TankDestroyerGoldBadge,
            self::AssaultBadge->value             => self::AssaultBadge,
            self::WoundedBadge->value             => self::WoundedBadge,
            self::WoundedSilverBadge->value       => self::WoundedSilverBadge,
            self::WoundedGoldBadge->value         => self::WoundedGoldBadge,
            self::IronCross1stClass->value        => self::IronCross1stClass,
            self::IronCross2ndClass->value        => self::IronCross2ndClass,
            self::LongServiceMedal->value         => self::LongServiceMedal,
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
 * Handles the "Civ13Medals" command.
 */
class Civ13GameServerMedals extends Civ13GameServerMessageCommand
{
    public function __invoke(Message $message, string $command, array $message_filtered): PromiseInterface
    {
        if (! $ckey = self::messageWithoutCommand($command, $message_filtered, true, true)) {
             if (! $ckey = $this->civ13->verifier->getVerifiedItem($message->author)['ss13'] ?? null)
                return $this->civ13->reply($message, 'Please register your SS13 account using the `/approveme` command.');
            return $this->civ13->reply($message, 'Wrong format. Please try `medals [ckey]`.');
        }
        if (! $msg = self::medals($message->guild, $this->civ13->enabled_gameservers['tdm']->basedir . Civ13::awards, $ckey)) return $this->civ13->reply($message, 'There was an error trying to get your medals!');
        return $this->civ13->reply($message, $msg, 'medals.txt');
    }

    public static function medals(Guild $guild, string $fp, string $ckey): string|false
    {
        if (! $search = @fopen($fp, 'r')) return "Error opening `$fp`.";
        $result = '';
        while (! feof($search)) if (str_contains($line = trim(str_replace(PHP_EOL, '', fgets($search))), $ckey)) {
            if (($duser = explode(';', $line)) && $duser[0] === $ckey)
                $result .= "`{$duser[1]}:` "
                    . self::getMedalEmoji($guild, $duser[2])
                    . " **{$duser[2]}, *{$duser[4]}*, {$duser[5]}"
                    . PHP_EOL;
        }
        return $result ?: "No medals found for `$ckey`.";
    }

    /**
     * Retrieves the emoji associated with a specific medal.
     *
     * @param Guild $guild
     * @param string $medal
     * @return string
     */
    public static function getMedalEmoji(Guild $guild, string $medal): string
    {
        if ($enum = SS14MedalEmojis::fromName($medal)) if ($emoji = $guild->emojis->get('name', $enum->emoji())) return (string) $emoji;
        return '<:long_service:705786458874707978>'; // Fallback if emoji not found
    }
}