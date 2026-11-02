<?php
declare(strict_types=1);
namespace Civ13;

/** Pure two-policy projection. Never reads members, credentials or files itself. */
final class AccessListProjection
{
    public static function render(array $status, array $verified, array $membership, string $guild, int $generation, int $now, array $roleIds, array $targets, array $cache): string
    {
        $success = $status['last_success_utc'] ?? null;
        $date = is_string($success) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $success, new \DateTimeZone('UTC')) : false;
        if (($status['available'] ?? null) !== true || ($status['stale'] ?? null) !== false
            || ($status['has_valid_list'] ?? null) !== true || ($status['refresh_in_progress'] ?? null) !== false
            || ($status['records'] ?? null) !== count($verified) || !is_int($status['age_seconds'] ?? null)
            || $status['age_seconds'] < 0 || $status['age_seconds'] >= HostedVerificationSnapshot::FRESH_SECONDS
            || $date === false || $date->format('Y-m-d\TH:i:s\Z') !== $success
            || $now < $date->getTimestamp() || $now - $date->getTimestamp() >= HostedVerificationSnapshot::FRESH_SECONDS) {
            throw new \RuntimeException('Access verification unavailable');
        }
        if (preg_match('/^[1-9][0-9]{16,19}$/D', $guild) !== 1 || ($membership['guild_id'] ?? null) !== $guild
            || ($cache['ready'] ?? null) !== true || !is_int($cache['epoch'] ?? null) || $cache['epoch'] < 0
            || ($membership['cache_epoch'] ?? null) !== $cache['epoch'] || $generation < 0
            || ($membership['generation'] ?? null) !== $generation
            || !is_array($membership['roles'] ?? null) || !array_is_list($membership['roles'])
            || !is_array($membership['members'] ?? null) || !array_is_list($membership['members'])
            || !is_int($membership['expected_members'] ?? null) || $membership['expected_members'] < 0
            || count($membership['members']) !== $membership['expected_members'] || count($membership['members']) > 50000) {
            throw new \RuntimeException('Access member cache unavailable or incomplete');
        }
        $required = [];
        foreach (['approved', 'whitelist'] as $kind) {
            // User selected Verified for both; keep each target's policy explicit.
            if (($targets[$kind]['required_roles'] ?? null) !== ['Verified']) { throw new \InvalidArgumentException('Explicit Verified access policy required'); }
            foreach ($targets[$kind]['required_roles'] as $name) {
                $role = $roleIds[$name] ?? null;
                if (!self::snowflake($role) || !in_array($role, $membership['roles'], true)) { throw new \RuntimeException('Required access role unavailable'); }
                $required[$kind][] = $role;
            }
        }
        $members = [];
        foreach ($membership['members'] as $member) {
            if (!is_array($member) || !self::snowflake($member['id'] ?? null) || isset($members[$member['id']])
                || !is_array($member['roles'] ?? null) || !array_is_list($member['roles'])) { throw new \RuntimeException('Invalid access member snapshot'); }
            foreach ($member['roles'] as $role) { if (!self::snowflake($role)) { throw new \RuntimeException('Invalid access role snapshot'); } }
            $members[$member['id']] = $member['roles'];
        }
        $records = HostedVerificationSource::validate(json_encode($verified, JSON_THROW_ON_ERROR));
        $mapped = []; $lines = ['approved' => [], 'whitelist' => []];
        foreach ($records as $record) {
            $mapped[$record['discord']] = true;
            $roles = $members[$record['discord']] ?? [];
            foreach ($required as $kind => $eligible) {
                if (array_intersect($roles, $eligible) === []) { continue; }
                if (preg_match('/^[a-z0-9]{1,256}$/D', $record['ss13']) !== 1) { throw new \RuntimeException('Selected access ckey is not canonical'); }
                $lines[$kind][$record['ss13']] = $record['ss13'].'='.$record['discord']."\n";
            }
        }
        $outputs = [];
        foreach ($lines as $kind => $rows) { ksort($rows, SORT_STRING); $outputs[$kind] = $rows === [] ? "\n" : implode('', $rows); }
        $unmapped = 0;
        foreach ($members as $id => $roles) {
            if (!isset($mapped[$id]) && array_intersect($roles, $required['approved']) !== []) { ++$unmapped; }
        }
        return json_encode(['outputs' => $outputs, 'unmapped_role_holders' => $unmapped], JSON_THROW_ON_ERROR);
    }

    /** Counts/hashes only; never include selected identities or private destinations. */
    public static function preview(string $wire): array
    {
        $data = self::decode($wire); $targets = [];
        foreach ($data['outputs'] as $kind => $bytes) {
            $targets[$kind] = ['records' => $bytes === "\n" ? 0 : substr_count($bytes, "\n"), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        return ['state' => 'preview', 'publishable_mapping' => $data['unmapped_role_holders'] === 0, 'targets' => $targets, 'unmapped_role_holders' => $data['unmapped_role_holders']];
    }

    public static function decode(string $wire): array
    {
        if (strlen($wire) > 12582912) { throw new \InvalidArgumentException('Access proposal too large'); }
        $data = json_decode($wire, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_keys($data) !== ['outputs', 'unmapped_role_holders']
            || !is_array($data['outputs']) || array_keys($data['outputs']) !== ['approved', 'whitelist']
            || !is_int($data['unmapped_role_holders']) || $data['unmapped_role_holders'] < 0 || $data['unmapped_role_holders'] > 50000) {
            throw new \InvalidArgumentException('Invalid access proposal');
        }
        foreach ($data['outputs'] as $bytes) {
            if (!is_string($bytes) || strlen($bytes) > 4194304) { throw new \InvalidArgumentException('Invalid access output'); }
            if ($bytes === "\n") { continue; }
            if ($bytes === '') { throw new \InvalidArgumentException('Zero-byte access set is unavailable'); }
            $rows = explode("\n", $bytes);
            if (array_pop($rows) !== '' || count($rows) > 50000) { throw new \InvalidArgumentException('Invalid access framing'); }
            $previous = null; $users = [];
            foreach ($rows as $row) {
                if (preg_match('/^([a-z0-9]{1,256})=([1-9][0-9]{16,19})$/D', $row, $match) !== 1
                    || ($previous !== null && strcmp($previous, $match[1]) >= 0) || isset($users[$match[2]])) {
                    throw new \InvalidArgumentException('Invalid or ambiguous canonical access row');
                }
                $previous = $match[1]; $users[$match[2]] = true;
            }
        }
        return $data;
    }

    private static function snowflake(mixed $value): bool { return is_string($value) && preg_match('/^[1-9][0-9]{16,19}$/D', $value) === 1; }
}
