<?php

declare(strict_types=1);

// Dependency-free: no bot.php bootstrap, Discord, systemd or controller invocation.
require __DIR__.'/../../src/Civ13/PortableProfile.php';
require __DIR__.'/../../src/Civ13/Local/TdmGroupConfiguration.php';

use Civ13\Local\TdmGroupConfiguration;

$count = 0;
function expect(bool $condition, string $message): void
{
    global $count;
    $count++;
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects(callable $resolve, string $reason): void
{
    try { $resolve(); }
    catch (UnexpectedValueException) { expect(true, $reason); return; }
    expect(false, $reason);
}
function cleanFixture(string $path): void
{
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (scandir($path) as $child) {
        if ($child !== '.' && $child !== '..') { cleanFixture($path.'/'.$child); }
    }
    rmdir($path);
}

$tmp = sys_get_temp_dir().'/bot-tdm-config-'.bin2hex(random_bytes(8));
mkdir($tmp, 0700);
try {
    $file = $tmp.'/tdm.json';
    $repo = $tmp.'/checkout';
    $root = $tmp.'/custom-group-root';
    $dirs = ['old-status', 'checkout/scripts/shard-group', 'custom-group-root/join_here',
        'custom-group-root/briefing', 'custom-group-root/rounds', 'custom-group-root/front-door/SQL'];
    foreach ($dirs as $dir) { mkdir($tmp.'/'.$dir, 0700, true); }
    $script = $repo.'/scripts/shard-group/shard_group.py';
    $admins = $root.'/front-door/SQL/admins.txt';
    file_put_contents($script, "# synthetic; never executed\n");
    file_put_contents($admins, "synthetic-test\n");
    $legacy = ['basedir' => $tmp.'/old-status', 'port' => '1714', 'ip' => '192.0.2.10',
        'enabled' => true, 'supported' => true, 'name' => 'TDM', 'systemd' => ['unit' => 'civ13-tdm.service'],
        'play' => ['url' => 'https://civ13.com/tdm/']];
    $group = ['group' => 'tdm', 'repository' => $repo, 'root' => $root, 'persistent' => false,
        'mainland' => 'briefing', 'relay_port' => 18200, 'gate' => 'join_here', 'regions' => [
        ['id' => 'briefing', 'kind' => 'BARRACKS', 'port' => 18201],
        ['id' => 'rounds', 'kind' => 'BATTLE', 'port' => 18202],
        ['id' => 'join_here', 'kind' => 'GATEWAY', 'port' => 18203],
    ]];
    $absent = TdmGroupConfiguration::profiles($file, $legacy);
    expect(array_keys($absent) === ['tdm'] && $absent['tdm']['systemd'] === $legacy['systemd'], 'Genuinely absent config retains legacy status only');
    expect($absent['tdm']['retire_legacy_lifecycle'] === true, 'Absent-file status profile is also read-only');
    $resolve = static function (array $config) use ($file, $legacy): array {
        file_put_contents($file, json_encode($config, JSON_THROW_ON_ERROR));
        return TdmGroupConfiguration::profiles($file, $legacy);
    };

    $profiles = $resolve($group);
    expect(array_keys($profiles) === ['tdm', 'tdmbattle'], 'Two bot entries from one group snapshot');
    [$front, $battle] = array_values($profiles);
    expect($front['basedir'] === $root.'/join_here' && $front['port'] === '18203', 'Routed public front follows exact configured directory and port');
    expect($battle['basedir'] === $root.'/rounds' && $battle['port'] === '18202', 'Battle uses BATTLE kind, not a hardcoded port or directory');
    expect($front['shard_group']['region'] === 'join_here' && $battle['shard_group']['region'] === 'rounds', 'Targets cannot diverge from the selected profiles');
    expect($front['shard_group']['script'] === $script && $battle['shard_group']['script'] === $script
        && $front['shard_group']['config'] === $file && $battle['shard_group']['config'] === $file, 'Both controllers derive from one configured root');
    expect($front['admin_file'] === $admins && $battle['admin_file'] === $admins, 'Shared admin path stays attached to TDM');
    expect(!isset($front['systemd']) && !isset($battle['systemd']), 'Configured group cannot reach the legacy unit');
    expect($front['retire_legacy_lifecycle'] === true && $battle['retire_legacy_lifecycle'] === true, 'Both controlled TDM keys retire legacy lifecycle operations');
    expect($front['play'] === $legacy['play'] && $battle['play'] === $legacy['play'], 'Battle Play link still enters through the public TDM door');
    expect($front['region_group'] === $file && !isset($battle['region_group'])
        && $battle['shard_of'] === 'tdm' && $battle['supported'] === false, 'Battle is not a second aggregate Play entry');

    $direct = $group;
    unset($direct['mainland'], $direct['gate'], $direct['relay_port'], $direct['regions'][2]);
    expect($resolve($direct)['tdm']['shard_group']['region'] === 'briefing', 'Without relay, the coordinator default mainland is the first region');
    $routedMainland = $group;
    unset($routedMainland['gate']);
    expect($resolve($routedMainland)['tdm']['shard_group']['region'] === 'briefing', 'A relay without explicit gate uses mainland');
    file_put_contents($file, "\xEF\xBB\xBF".json_encode($group, JSON_THROW_ON_ERROR));
    expect(TdmGroupConfiguration::profiles($file, $legacy) === $profiles, 'BOM composes with one valid snapshot');

    $invalid = [
        array_replace($group, ['group' => 'other']),
        array_replace($group, ['repository' => 'relative']),
        array_replace($group, ['root' => $tmp.'/missing-root']),
        array_replace($group, ['gate' => 'missing']),
        array_replace($group, ['gate' => 'rounds']),
        array_replace($group, ['mainland' => 'missing']),
        array_replace($group, ['relay_port' => 'auto']),
        array_replace($group, ['relay_port' => 18201]),
        array_replace($group, ['relay_port' => 0]),
        array_replace($group, ['regions' => [$group['regions'][0], $group['regions'][2]]]),
        array_replace($group, ['regions' => [...$group['regions'], $group['regions'][1]]]),
        array_replace($group, ['regions' => [...$group['regions'], ['id' => 'more-battle', 'kind' => 'BATTLE', 'port' => 18204]]]),
        array_replace($group, ['regions' => [$group['regions'][0], [...$group['regions'][1], 'port' => '18202'], $group['regions'][2]]]),
        array_replace($group, ['regions' => [$group['regions'][0], [...$group['regions'][1], 'port' => 18201], $group['regions'][2]]]),
        array_replace($group, ['regions' => [$group['regions'][0], [...$group['regions'][1], 'id' => 'unset-battle-dir'], $group['regions'][2]]]),
        array_replace($group, ['regions' => [$group['regions'][0], [...$group['regions'][1], 'id' => '../old-status'], $group['regions'][2]]]),
    ];
    foreach ($invalid as $index => $candidate) { rejects(fn () => $resolve($candidate), "Invalid configured group #$index fails closed"); }
    file_put_contents($file, '{not-json');
    rejects(fn () => TdmGroupConfiguration::profiles($file, $legacy), 'Malformed JSON cannot reenable legacy');
    file_put_contents($file, str_repeat(' ', 1048577));
    rejects(fn () => TdmGroupConfiguration::profiles($file, $legacy), 'Oversized config fails closed');
    rejects(fn () => TdmGroupConfiguration::profiles($tmp.'/missing-parent/tdm.json', $legacy), 'Uninspectable location cannot be called absent');
    unlink($file);
    mkdir($file);
    rejects(fn () => TdmGroupConfiguration::profiles($file, $legacy), 'A directory in place of the config is not absence');
    rmdir($file);
    if (PHP_OS_FAMILY !== 'Windows') {
        symlink($tmp.'/missing-target', $file);
        rejects(fn () => TdmGroupConfiguration::profiles($file, $legacy), 'A dangling config symlink is not absence');
        unlink($file);
    }
    unlink($script);
    rejects(fn () => $resolve($group), 'Missing coordinator in selected repository fails closed');

    echo "TDM group configuration contracts passed: $count assertions\n";
} finally {
    cleanFixture($tmp);
}
