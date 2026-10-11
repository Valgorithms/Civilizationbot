<?php

declare(strict_types=1);

namespace Civ13\Economy;

/** Resolve the requesting Discord ID through a current verifier; never accept a target account. */
final class AccountView
{
    public function __construct(private Ledger $ledger, private \Closure $resolveAccount) {}

    public function read(string $realm, string $discordId): array
    {
        $account = ($this->resolveAccount)($realm, $discordId);
        if (!is_string($account) || $account === '') {
            throw new \RuntimeException('A verified game account is required.');
        }
        return ['account' => $account, 'balance' => $this->ledger->balance($realm, $account), 'history' => $this->ledger->history($realm, $account)];
    }
}
