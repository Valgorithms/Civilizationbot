<?php

declare(strict_types=1);

namespace Civ13;

/** Filters BYOND guest identities at the embed boundary, not from player records. */
final class PlayerEmbedKeys
{
    /**
     * BYOND supplies Guest-123 or Guest-W123; canonical ckeys omit the hyphen.
     * Keep names that merely start with "guest", such as GuestBuilder.
     *
     * @param  array<array-key, string|int> $keys
     * @return list<string|int>
     */
    public static function withoutGuests(array $keys): array
    {
        return array_values(array_filter($keys, static fn (string|int $key): bool =>
            preg_match('/\Aguest-?w?[0-9]+\z/i', trim((string) $key)) !== 1
        ));
    }
}
