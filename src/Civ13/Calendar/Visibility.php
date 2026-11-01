<?php
declare(strict_types=1);
namespace Civ13\Calendar;

/** Synthetic ordinary-member permissions. Never consults the bot or an admin member. */
final class Visibility
{
    public static function id(mixed $id): bool { return is_string($id) && preg_match('/^[1-9][0-9]{16,19}$/D', $id) === 1; }

    /** Read a bit without coercing Discord's decimal permission strings to machine integers. */
    public static function bit(mixed $decimal, int $bit): bool
    {
        if (!is_string($decimal) || preg_match('/^(0|[1-9][0-9]{0,79})$/D', $decimal) !== 1) { throw new \InvalidArgumentException('Unknown permission bits'); }
        for ($i = 0; $i <= $bit; ++$i) {
            $quotient = ''; $carry = 0;
            foreach (str_split($decimal) as $digit) { $n = $carry * 10 + (int)$digit; $quotient .= (string)intdiv($n, 2); $carry = $n % 2; }
            $decimal = ltrim($quotient, '0') ?: '0';
        }
        return $carry === 1;
    }

    public static function baseline(string $guild, array $roles, array $onboarding): array
    {
        if (!self::id($guild) || count(array_unique($onboarding)) !== count($onboarding)) { throw new \InvalidArgumentException('Unknown baseline'); }
        $index = [];
        foreach ($roles as $role) {
            if (!is_array($role) || !self::id($role['id'] ?? null) || isset($index[$role['id']])) { throw new \InvalidArgumentException('Unknown roles'); }
            $index[$role['id']] = $role;
        }
        $selected = [$guild, ...$onboarding]; $view = false;
        foreach ($selected as $id) {
            if (!self::id($id) || !isset($index[$id]) || ($id !== $guild && ($index[$id]['managed'] ?? null) !== false)) { throw new \InvalidArgumentException('Unknown onboarding role'); }
            // An onboarding profile with staff powers must not become public visibility authority.
            foreach ([3, 4, 5, 28] as $staffBit) { if (self::bit($index[$id]['permissions'] ?? null, $staffBit)) { throw new \InvalidArgumentException('Privileged baseline'); } }
            $view = $view || self::bit($index[$id]['permissions'], 10);
        }
        return ['roles' => $selected, 'view' => $view];
    }

    /** true=visible, false=restricted, null=unknown (fail closed). Category overwrites are not inherited. */
    public static function channel(array $channel, string $guild, int $type, array $baseline): ?bool
    {
        try {
            if (($channel['guild_id'] ?? null) !== $guild || ($channel['type'] ?? null) !== $type || !is_array($channel['permission_overwrites'] ?? null)) { return null; }
            if (($channel['nsfw'] ?? false) !== false) { return null; } // Baseline carries no age assertion.
            $everyone = null; $allow = $deny = false; $seen = [];
            foreach ($channel['permission_overwrites'] as $overwrite) {
                $o = (array)$overwrite;
                if (!self::id($o['id'] ?? null) || !in_array($o['type'] ?? null, [0, 1], true) || isset($seen[$o['id']])) { return null; }
                $seen[$o['id']] = true;
                $a = self::bit($o['allow'] ?? null, 10); $d = self::bit($o['deny'] ?? null, 10);
                if ($o['type'] !== 0) { continue; } // No user identity in the synthetic baseline.
                if ($o['id'] === $guild) { $everyone = [$a, $d]; }
                elseif (in_array($o['id'], $baseline['roles'], true)) { $allow = $allow || $a; $deny = $deny || $d; }
            }
            $view = $baseline['view'];
            if ($everyone !== null) { $view = ($view && !$everyone[1]) || $everyone[0]; }
            return ($view && !$deny) || $allow; // Combined role denies, then combined role allows.
        } catch (\Throwable) { return null; }
    }
}
