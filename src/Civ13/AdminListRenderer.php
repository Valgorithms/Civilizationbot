<?php
declare(strict_types=1);
namespace Civ13;

/** Pure projection only: no file, Discord, verifier mutation or game effects. */
final class AdminListRenderer
{
    /**
     * $permissions is ordered highest rank first (pass GameServer::ADMIN_PERMISSIONS).
     * $members is a complete guild snapshot: list<array{id:string,roles:list<string>}>.
     * Completeness/freshness must be established by the caller; use AdminSyncSnapshotGate.
     */
    public static function render(array $verified, array $members, array $roleIds, array $permissions): string
    {
        if ($permissions === []) { throw new \InvalidArgumentException('Admin permissions must be explicit'); }
        $seenRoles = [];
        foreach ($permissions as $name => $permission) {
            $role = $roleIds[$name] ?? null;
            if (!is_string($name) || !self::snowflake($role) || isset($seenRoles[$role])
                || !is_array($permission) || !array_is_list($permission) || count($permission) !== 2
                || !self::field($permission[0]) || !is_string($permission[1])
                || preg_match('/^(0|[1-9][0-9]{0,4})$/D', $permission[1]) !== 1 || (int)$permission[1] > 65535) {
                throw new \InvalidArgumentException('Invalid admin role configuration');
            }
            $seenRoles[$role] = true;
        }
        if (!array_is_list($members)) { throw new \InvalidArgumentException('Expected member list'); }
        $byId = [];
        foreach ($members as $member) {
            if (!is_array($member) || !self::snowflake($member['id'] ?? null)
                || isset($byId[$member['id']]) || !is_array($member['roles'] ?? null)
                || !array_is_list($member['roles'])) {
                throw new \InvalidArgumentException('Invalid or ambiguous member snapshot');
            }
            foreach ($member['roles'] as $role) {
                if (!self::snowflake($role)) { throw new \InvalidArgumentException('Invalid member role'); }
            }
            $byId[$member['id']] = $member['roles'];
        }
        // Reuse existing identity normalization and ambiguity rejection, without fetching.
        $verified = HostedVerificationSource::validate(json_encode($verified, JSON_THROW_ON_ERROR));
        $lines = [];
        foreach ($verified as $record) {
            $roles = $byId[$record['discord']] ?? null;
            if ($roles === null) { continue; }
            foreach ($permissions as $name => [$rank, $flags]) {
                if (in_array($roleIds[$name], $roles, true)) {
                    // Legacy verification spelling may be valid but not a safe admin ckey.
                    // Reject selected unsafe authority; do not silently omit/revoke it.
                    if (preg_match('/^[a-z0-9]{1,256}$/D', $record['ss13']) !== 1) {
                        throw new \InvalidArgumentException('Selected admin ckey is not canonical');
                    }
                    $lines[$record['ss13']] = $record['ss13'].';'.$rank.';'.$flags."|||\n";
                    break;
                }
            }
        }
        ksort($lines, SORT_STRING);
        return implode('', $lines);
    }

    private static function snowflake(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9]{17,20}$/D', $value) === 1;
    }

    private static function field(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 256
            && preg_match('/[;|\x00-\x1F\x7F]/', $value) === 0;
    }
}
