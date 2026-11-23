<?php

declare(strict_types=1);

namespace Civ13\Local;

use Civ13\PortableProfile;

/** Select the hosted TDM entries without executing any controller or bot bootstrap. */
final class TdmGroupConfiguration
{
    private const MAX_CONFIG_BYTES = 1048576;

    /**
     * Only a missing file in a readable directory permits legacy status.
     * A configured file is parsed once; neither world falls back on a bad
     * snapshot. Normal shard status can still refresh its roster afterward.
     *
     * @return array<string, array>
     */
    public static function profiles(string $file, array $legacy): array
    {
        $legacy['retire_legacy_lifecycle'] = true;
        clearstatcache(true, $file);
        $info = @lstat($file);
        if ($info === false) {
            $siblings = @scandir(dirname($file));
            if ($siblings === false || in_array(basename($file), $siblings, true)) {
                throw new \UnexpectedValueException('Cannot determine whether the TDM group config is present.');
            }
            return ['tdm' => $legacy];
        }
        // Match ShardRegionControl's rejection of symlinked config paths.
        if (($info['mode'] & 0170000) !== 0100000) {
            throw new \UnexpectedValueException('The TDM group config must be a regular, non-symlinked file.');
        }
        $raw = @file_get_contents($file, false, null, 0, self::MAX_CONFIG_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_CONFIG_BYTES) {
            throw new \UnexpectedValueException('The TDM group config is unreadable or too large.');
        }
        try {
            $group = json_decode(str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \UnexpectedValueException('The TDM group config is not valid JSON.', 0, $error);
        }
        if (!is_array($group) || ($group['group'] ?? null) !== 'tdm'
            || !is_array($group['regions'] ?? null) || !array_is_list($group['regions']) || $group['regions'] === []) {
            throw new \UnexpectedValueException('Expected a TDM group and its region list.');
        }
        $repo = self::directory($group['repository'] ?? null, 'repository');
        $root = self::directory($group['root'] ?? null, 'region root');
        // Do not install updates into a directory this bot merely assumed was a checkout.
        $script = $repo.'/scripts/shard-group/shard_group.py';
        if (!is_file($script) || !is_readable($script) || is_link($script)) {
            throw new \UnexpectedValueException('The configured TDM repository has no readable shard controller.');
        }

        $regions = $ports = $barracks = $battles = $gateways = [];
        foreach ($group['regions'] as $region) {
            $id = is_array($region) ? ($region['id'] ?? null) : null;
            $kind = is_array($region) ? ($region['kind'] ?? null) : null;
            $port = is_array($region) ? ($region['port'] ?? null) : null;
            if (!is_string($id) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $id) || $id === 'front-door'
                || isset($regions[$id]) || !is_string($kind) || !preg_match('/^[A-Z_]+$/D', $kind)
                || !self::validPort($port) || isset($ports[$port])) {
                throw new \UnexpectedValueException('The TDM group must contain unique valid region IDs and gameplay ports.');
            }
            $regions[$id] = $region;
            $ports[$port] = true;
            if ($kind === 'BARRACKS') { $barracks[] = $id; }
            if ($kind === 'BATTLE') { $battles[] = $id; }
            if ($kind === 'GATEWAY') { $gateways[] = $id; }
        }
        if (count($barracks) !== 1 || count($battles) !== 1 || count($gateways) > 1) {
            throw new \UnexpectedValueException('The TDM group needs one BARRACKS and one BATTLE and permits at most one GATEWAY.');
        }

        // Match shard_group.py: the relay uses gate, otherwise use mainland.
        $mainland = array_key_exists('mainland', $group) ? $group['mainland'] : $group['regions'][0]['id'];
        if (!is_string($mainland) || !isset($regions[$mainland])
            || in_array($regions[$mainland]['kind'], ['BATTLE', 'GATEWAY', 'RAID', 'EVENT', 'ORBIT', 'TUTORIAL', 'EXPEDITION'], true)) {
            throw new \UnexpectedValueException('The configured TDM mainland is not an eligible region.');
        }
        $relay = array_key_exists('relay_port', $group) ? $group['relay_port'] : 0;
        if ($relay !== 0 && (!self::validPort($relay) || isset($ports[$relay]))) {
            throw new \UnexpectedValueException('The TDM relay needs an assigned port distinct from gameplay ports.');
        }
        if ($relay === 0 && $gateways !== []) {
            throw new \UnexpectedValueException('A TDM gateway requires the routing relay.');
        }
        $frontId = $relay !== 0 && array_key_exists('gate', $group) ? $group['gate'] : $mainland;
        if (!is_string($frontId) || !isset($regions[$frontId])
            || in_array($regions[$frontId]['kind'], ['BATTLE', 'RAID', 'EVENT', 'TUTORIAL', 'EXPEDITION'], true)) {
            throw new \UnexpectedValueException('The configured TDM front door is not an eligible region.');
        }

        $battleId = $battles[0];
        foreach (array_unique([$frontId, $battleId, $barracks[0]]) as $id) {
            self::directory($root.'/'.$id, 'region directory');
        }
        $forRegion = static fn (string $id): array => [
            'enabled' => true,
            'python' => '/usr/bin/python3',
            'script' => $script,
            'config' => $file,
            'region' => $id,
            'kill' => 'stop',
        ];
        unset($legacy['systemd']);
        $front = array_replace($legacy, [
            'basedir' => $root.'/'.$frontId,
            'port' => (string) $regions[$frontId]['port'],
            'shard_group' => $forRegion($frontId),
            'region_group' => $file, // The front-door entry counts the whole group.
        ]);
        $sharedAdmins = $root.'/front-door/SQL/admins.txt';
        if (is_file($sharedAdmins)) {
            $front['admin_file'] = $sharedAdmins;
        }
        $battle = array_replace($front, [
            'supported' => false, // No public Battle entry; Play still points to TDM.
            'name' => 'TDM Battle',
            'basedir' => $root.'/'.$battleId,
            'port' => (string) $regions[$battleId]['port'],
            'shard_group' => $forRegion($battleId),
            'shard_of' => 'tdm',
        ]);
        unset($battle['region_group']);
        return ['tdm' => $front, 'tdmbattle' => $battle];
    }

    private static function validPort(mixed $port): bool
    {
        return is_int($port) && $port >= 1024 && $port <= 65535;
    }

    private static function directory(mixed $path, string $label): string
    {
        if (!PortableProfile::absoluteAdminFile($path) || !is_dir($path) || !is_readable($path)) {
            throw new \UnexpectedValueException("The configured TDM {$label} must be an existing readable absolute directory.");
        }
        return rtrim($path, '/\\');
    }
}
