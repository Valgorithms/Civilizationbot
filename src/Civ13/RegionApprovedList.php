<?php

declare(strict_types=1);

namespace Civ13;

/**
 * Desktop bot: copies the hosted verified list into every persistence region's
 * SQL/approved.txt, so the game (Civ13 code/_helpers/access_lists.dm) knows who is
 * verified. Val chose this on 2026-11-22: the list comes from civ13.com over the
 * configured read-only source, and a region file is written only when its text
 * would change.
 *
 * Wire format (the game's parser): sorted `ckey=DiscordID` rows, each ending LF.
 * The game rejects the whole file on a duplicate ckey or ID, so any ckey or ID
 * that appears twice is left out. A region without an SQL folder is skipped; this
 * never creates folders. An empty hosted list is never written, so a bad refresh
 * cannot unverify everyone.
 */
final class RegionApprovedList
{
    /** Directory => sha1 of the text last confirmed there. */
    private array $written = [];

    /** @param list<array<string,mixed>> $records hosted verifier rows with `ss13` and `discord` */
    public static function render(array $records): string
    {
        $rows = [];
        foreach ($records as $record) {
            $ckey = is_array($record) ? ($record['ss13'] ?? null) : null;
            $id = is_array($record) ? ($record['discord'] ?? null) : null;
            $id = is_int($id) ? (string) $id : $id;
            if (! is_string($ckey) || preg_match('/^[a-z0-9]{1,256}$/D', $ckey) !== 1
                || ! is_string($id) || preg_match('/^[1-9][0-9]{16,19}$/D', $id) !== 1) {
                continue;
            }
            $rows[] = [$ckey, $id];
        }
        $ckeys = array_count_values(array_column($rows, 0));
        $ids = array_count_values(array_column($rows, 1));
        $lines = [];
        foreach ($rows as [$ckey, $id]) {
            if ($ckeys[$ckey] === 1 && $ids[$id] === 1) {
                $lines[] = "$ckey=$id\n";
            }
        }
        sort($lines, SORT_STRING);

        return implode('', $lines);
    }

    /**
     * Every region directory named by a shard group file (`root` plus `regions[].id`),
     * in file order. An unreadable or malformed file gives none.
     *
     * @return list<string>
     */
    public static function directories(string $groupFile): array
    {
        $bytes = $groupFile !== '' && @is_file($groupFile) ? @file_get_contents($groupFile, false, null, 0, 1048577) : false;
        if (! is_string($bytes) || strlen($bytes) > 1048576) {
            return [];
        }
        $group = json_decode(str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes, true, 32);
        if (! is_array($group) || ! is_string($group['root'] ?? null) || $group['root'] === '' || ! is_array($group['regions'] ?? null)) {
            return [];
        }
        $root = rtrim($group['root'], '/\\');
        $directories = [];
        foreach ($group['regions'] as $region) {
            $id = is_array($region) ? ($region['id'] ?? null) : (is_string($region) ? $region : null);
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) === 1) {
                $directories[] = $root.'/'.$id;
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * Writes `$text` to `<dir>/SQL/approved.txt` where it differs. Each write goes to a
     * sibling temporary file first and is then renamed over the target, so the game never
     * reads a half-written list. A failed write is retried on the next call.
     *
     * @param  list<string>                                          $directories
     * @return array{written: list<string>, unchanged: int, skipped: int, failed: list<string>}
     */
    public function publish(array $directories, string $text): array
    {
        $result = ['written' => [], 'unchanged' => 0, 'skipped' => 0, 'failed' => []];
        if ($text === '') {
            $result['skipped'] = count($directories);

            return $result;
        }
        $hash = sha1($text);
        foreach ($directories as $directory) {
            if (($this->written[$directory] ?? null) === $hash) {
                $result['unchanged']++;

                continue;
            }
            $sql = rtrim($directory, '/\\').'/SQL';
            if (! @is_dir($sql)) {
                $result['skipped']++;

                continue;
            }
            $target = $sql.'/approved.txt';
            $current = @is_file($target) ? @file_get_contents($target) : false;
            if ($current === $text) {
                $this->written[$directory] = $hash;
                $result['unchanged']++;

                continue;
            }
            $stage = $sql.'/.approved.txt.'.bin2hex(random_bytes(6)).'.tmp';
            if (@file_put_contents($stage, $text) === strlen($text) && @rename($stage, $target)) {
                $this->written[$directory] = $hash;
                $result['written'][] = $directory;

                continue;
            }
            @unlink($stage);
            $result['failed'][] = $directory;
        }

        return $result;
    }
}
