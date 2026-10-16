<?php

declare(strict_types=1);

namespace Civ13\Economy;

/** Explicit configuration only; never reads environment variables or enables itself. */
final class Config
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $realm = '',
        public readonly array $servers = [],
        public readonly string $policyVersion = '',
    ) {
        if (!$enabled) {
            return;
        }
        foreach ([$realm, $policyVersion, ...$servers] as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 128) {
                throw new \InvalidArgumentException('Enabled economy requires nonempty realm, policy version and server identifiers of at most 128 bytes.');
            }
        }
        if ($servers === []) {
            throw new \InvalidArgumentException('Enabled economy requires an explicit server allowlist.');
        }
    }
}
