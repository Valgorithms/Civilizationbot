<?php

declare(strict_types=1);

// No Composer, Discord, game or bot bootstrap: hosted verified list -> each region's SQL/approved.txt.
require __DIR__.'/../../src/Civ13/RegionApprovedList.php';

use Civ13\RegionApprovedList;

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

// Canonical rows, sorted; invalid rows and every duplicated ckey or ID are left out.
$text = RegionApprovedList::render([
    ['ss13' => 'zed', 'discord' => '990000000000000003'],
    ['ss13' => 'alpha', 'discord' => '990000000000000001'],
    ['ss13' => 'twice', 'discord' => '990000000000000004'],
    ['ss13' => 'twice', 'discord' => '990000000000000005'],
    ['ss13' => 'shareda', 'discord' => '990000000000000006'],
    ['ss13' => 'sharedb', 'discord' => '990000000000000006'],
    ['ss13' => 'Bad Key', 'discord' => '990000000000000007'],
    ['ss13' => 'noid', 'discord' => '12'],
    ['ss13' => 'intid', 'discord' => 990000000000000008],
    'junk',
]);
check($text === "alpha=990000000000000001\nintid=990000000000000008\nzed=990000000000000003\n", 'Rendered list changed: '.json_encode($text));
check(RegionApprovedList::render([]) === '', 'An empty list must render as nothing');

$root = sys_get_temp_dir().'/region-approved-'.bin2hex(random_bytes(4));
mkdir($root);
try {
    foreach (['gaia', 'europe'] as $region) {
        mkdir("$root/$region/SQL", 0777, true);
    }
    mkdir("$root/moon"); // no SQL folder: never created
    file_put_contents("$root/earth.json", "\xEF\xBB\xBF".json_encode(['root' => $root, 'regions' => [['id' => 'gaia'], ['id' => 'europe'], ['id' => 'moon'], ['id' => '../bad'], 'orbit']]));
    $directories = RegionApprovedList::directories("$root/earth.json");
    check($directories === ["$root/gaia", "$root/europe", "$root/moon", "$root/orbit"], 'Region directories changed: '.json_encode($directories));
    check(RegionApprovedList::directories("$root/missing.json") === [], 'A missing group file must give no regions');

    $list = new RegionApprovedList();
    $first = $list->publish($directories, $text);
    check(count($first['written']) === 2 && $first['skipped'] === 2 && $first['failed'] === [], 'First publish: '.json_encode($first));
    check(file_get_contents("$root/gaia/SQL/approved.txt") === $text && ! is_dir("$root/moon/SQL"), 'Region file not written, or a folder was created');
    check(glob("$root/gaia/SQL/.approved*") === [], 'A temporary file was left behind');

    // Unchanged list: nothing is written again.
    $mtime = filemtime("$root/gaia/SQL/approved.txt");
    $again = $list->publish($directories, $text);
    check($again['written'] === [] && $again['unchanged'] === 2, 'Unchanged list was rewritten: '.json_encode($again));

    // A fresh owner (bot restart) finds identical files and leaves them alone.
    $restart = (new RegionApprovedList())->publish($directories, $text);
    check($restart['written'] === [] && $restart['unchanged'] === 2 && filemtime("$root/gaia/SQL/approved.txt") === $mtime, 'Identical files were rewritten after a restart');

    // A changed list replaces every region's file.
    $newer = $text."zz=990000000000000009\n";
    $changed = $list->publish($directories, $newer);
    check(count($changed['written']) === 2 && file_get_contents("$root/europe/SQL/approved.txt") === $newer, 'Changed list not written: '.json_encode($changed));

    // An empty hosted list never wipes the regions.
    $empty = $list->publish($directories, '');
    check($empty['written'] === [] && file_get_contents("$root/gaia/SQL/approved.txt") === $newer, 'An empty list overwrote a region');
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

echo "region approved list: ok\n";
