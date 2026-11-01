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

namespace Civ13;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Discord\Helpers\Timestamp;

/** Formats recorded round starts, without replacing them with the current time. */
final class RoundStartTimestamp
{
    public static function format(mixed $start, ?DateTimeZone $timezone = null): string
    {
        try {
            $seconds = self::seconds($start, $timezone);

            return $seconds === null
                ? 'Unknown'
                : (string) Timestamp::new($seconds, Timestamp::STYLE_LONG_DATE_TIME);
        } catch (\Throwable) {
            return 'Unknown';
        }
    }

    private static function seconds(mixed $start, ?DateTimeZone $timezone): ?int
    {
        if ($start instanceof DateTimeInterface) {
            return ($seconds = $start->getTimestamp()) >= 0 ? $seconds : null;
        }
        if (! is_int($start) && ! is_string($start)) {
            return null;
        }
        $value = trim((string) $start);
        if ($value === '' || strlen($value) > 64) {
            return null;
        }
        // Canonical Unix seconds or modern 13-digit Unix milliseconds. Do not
        // guess units for ambiguous 11/12-digit numbers or cast floating values.
        if (preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $value)) {
            $seconds = filter_var($value, FILTER_VALIDATE_INT);

            return $seconds !== false ? $seconds : null;
        }
        if (preg_match('/\A[1-9][0-9]{12}\z/', $value)) {
            $seconds = filter_var(substr($value, 0, -3), FILTER_VALIDATE_INT);

            return $seconds !== false ? $seconds : null;
        }

        // logNewRound() records H:i:s m/d/Y using date() in the bot's PHP
        // timezone. Historic values have no offset; retain that interpretation.
        $timezone ??= new DateTimeZone(date_default_timezone_get());
        $legacy = (bool) preg_match('/\A[0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{2}\/[0-9]{2}\/[0-9]{4}\z/', $value);
        if ($legacy) {
            $format = 'H:i:s m/d/Y';
        } elseif (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/', $value)) {
            // RFC3339 offsets define the instant independently of PHP's zone.
            if (str_ends_with($value, '-00:00')) {
                return null; // RFC3339's unknown local offset is not UTC.
            }
            if (str_ends_with($value, 'Z')) {
                $value = substr($value, 0, -1).'+00:00';
            }
            $fractional = str_contains($value, '.');
            if ($fractional) {
                $value = preg_replace_callback('/\.([0-9]{1,6})(?=[+-])/', static fn (array $match): string => '.'.str_pad($match[1], 6, '0'), $value);
            }
            $format = $fractional ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP';
        } else {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format($format) !== $value) {
            return null; // Reject rollover dates and nonexistent local times.
        }
        if ($legacy && self::ambiguousLocalTime($date, $timezone, $format, $value)) {
            return null;
        }

        return ($seconds = $date->getTimestamp()) >= 0 ? $seconds : null;
    }

    /** A timezone-less repeated DST hour cannot identify which instant was saved. */
    private static function ambiguousLocalTime(DateTimeImmutable $date, DateTimeZone $timezone, string $format, string $value): bool
    {
        $seconds = $date->getTimestamp();
        $transitions = $timezone->getTransitions($seconds - 86400, $seconds + 86400);
        if ($transitions === false) {
            return false;
        }
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $alternative = $seconds + $date->getOffset() - $offset;
            if ($alternative !== $seconds
                && (new DateTimeImmutable('@'.$alternative))->setTimezone($timezone)->format($format) === $value) {
                return true;
            }
        }

        return false;
    }
}
