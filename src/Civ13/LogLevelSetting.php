<?php
declare(strict_types=1);
namespace Civ13;

/**
 * The bot's log level: the portable profile's `log_level`, else the LOG_LEVEL environment value, else info.
 * An unknown value falls back to info with a warning; it never stops the bot from starting.
 */
final class LogLevelSetting
{
    public const DEFAULT = 'info';
    /** The levels the launcher offers, quietest last. */
    public const NAMES = ['debug', 'info', 'notice', 'warning', 'error'];

    /** @return array{0: string, 1: ?string} the level name and a warning for an ignored value, or null. */
    public static function resolve(mixed $profileValue, mixed $environmentValue): array
    {
        foreach (['profile log_level' => $profileValue, 'LOG_LEVEL' => $environmentValue] as $source => $value) {
            if ($value === null || $value === false || $value === '') { continue; }
            $name = is_string($value) ? strtolower(trim($value)) : '';
            if (in_array($name, self::NAMES, true)) { return [$name, null]; }
            return [self::DEFAULT, 'Unknown '.$source.' '.json_encode($value).'; using '.self::DEFAULT.' (expected one of '.implode(', ', self::NAMES).')'];
        }
        return [self::DEFAULT, null];
    }
}
