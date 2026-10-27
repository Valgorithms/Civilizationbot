<?php
declare(strict_types=1);
namespace Civ13;

/**
 * Pure proposal boundary, not a member-fetch implementation or write authorization.
 * An adapter must attest complete/current membership, pair status with its records,
 * and recheck its generation immediately before any later writer commits bytes.
 */
final class AdminSyncSnapshotGate
{
    /**
     * $verificationStatus uses HostedVerificationSnapshot::status() fields.
     * $membership: {guild_id, complete:bool, connected:bool, generation:int,
     *               roles:list<string>, members:list<array{id:string,roles:list<string>}>}.
     * Throws on unavailable/invalid input. Only a valid empty projection returns ''.
     * $now is explicit Unix time; no clocks, I/O, listeners or configuration effects here.
     */
    public static function render(
        array $verificationStatus,
        array $verified,
        array $membership,
        string $guildId,
        int $currentGeneration,
        int $now,
        array $roleIds,
        array $permissions
    ): string {
        $success = $verificationStatus['last_success_utc'] ?? null;
        $date = is_string($success)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $success, new \DateTimeZone('UTC'))
            : false;
        $age = $verificationStatus['age_seconds'] ?? null;
        if (($verificationStatus['available'] ?? null) !== true
            || ($verificationStatus['stale'] ?? null) !== false
            || ($verificationStatus['has_valid_list'] ?? null) !== true
            || ($verificationStatus['refresh_in_progress'] ?? null) !== false
            || ($verificationStatus['records'] ?? null) !== count($verified)
            || !is_int($age) || $age < 0 || $age >= HostedVerificationSnapshot::FRESH_SECONDS
            || $date === false || $date->format('Y-m-d\TH:i:s\Z') !== $success
            || $now < $date->getTimestamp() || $now - $date->getTimestamp() >= HostedVerificationSnapshot::FRESH_SECONDS) {
            throw new \RuntimeException('Admin sync requires a fresh successful verification snapshot');
        }
        if (preg_match('/^[0-9]{17,20}$/D', $guildId) !== 1
            || ($membership['guild_id'] ?? null) !== $guildId
            || ($membership['complete'] ?? null) !== true
            || ($membership['connected'] ?? null) !== true
            || $currentGeneration < 0 || ($membership['generation'] ?? null) !== $currentGeneration
            || !is_array($membership['roles'] ?? null) || !array_is_list($membership['roles'])
            || !is_array($membership['members'] ?? null)) {
            throw new \RuntimeException('Admin sync requires a complete current guild snapshot');
        }
        foreach ($membership['roles'] as $role) {
            if (!is_string($role) || preg_match('/^[0-9]{17,20}$/D', $role) !== 1) {
                throw new \RuntimeException('Invalid guild role snapshot');
            }
        }
        foreach ($permissions as $name => $_) {
            if (!in_array($roleIds[$name] ?? null, $membership['roles'], true)) {
                throw new \RuntimeException('Required admin role is absent from the guild');
            }
        }
        return AdminListRenderer::render($verified, $membership['members'], $roleIds, $permissions);
    }
}
