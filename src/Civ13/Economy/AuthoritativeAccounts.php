<?php

declare(strict_types=1);

namespace Civ13\Economy;

/** Inject only an authoritative verifier snapshot, never cached/provisional collections. */
final class AuthoritativeAccounts
{
    /** $fetch must return the same status/json shape as Verifier::fetchVerifiedData(). */
    public function __construct(private \Closure $fetch) {}

    public function forDiscord(string $discordId): string
    {
        return $this->find('discord', $discordId)['ss13'];
    }

    public function requireAccount(string $account): void
    {
        $this->find('ss13', $account);
    }

    private function find(string $field, string $value): array
    {
        $snapshot = ($this->fetch)();
        if (!is_array($snapshot) || ($snapshot['http_status'] ?? null) !== 200 || !is_string($snapshot['json'] ?? null)) {
            throw new \RuntimeException('Authoritative verification is unavailable.');
        }
        $records = json_decode($snapshot['json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($records) || !array_is_list($records)) {
            throw new \RuntimeException('Invalid authoritative verification snapshot.');
        }
        $accounts = $discordIds = [];
        $match = null;
        foreach ($records as $record) {
            if (!is_array($record) || !is_string($record['ss13'] ?? null) || !preg_match('/\A[a-z0-9]{1,64}\z/', $record['ss13'])
                || !is_string($record['discord'] ?? null) || !preg_match('/\A[0-9]{17,20}\z/', $record['discord'])
                || isset($accounts[$record['ss13']]) || isset($discordIds[$record['discord']])) {
                throw new \RuntimeException('Invalid or ambiguous authoritative verification snapshot.');
            }
            $accounts[$record['ss13']] = $discordIds[$record['discord']] = true;
            if ($record[$field] === $value) {
                $match = $record;
            }
        }
        return $match ?? throw new \RuntimeException('An authoritative verified game account is required.');
    }
}
