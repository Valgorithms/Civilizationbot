<?php
declare(strict_types=1);
namespace Civ13\Server;

/** Native BYOND and OpenDream share these newline-delimited IPC records. */
final class Ipc
{
    public static function append(string $path, array $fields): bool
    {
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '' || strlen($field) > 8192 || str_contains($field, ':::') || preg_match('/[\r\n\x00]/', $field)) { return false; }
        }
        $record = implode(':::', $fields)."\n";
        $file = @fopen($path, 'ab');
        if (!$file) { return false; }
        try {
            if (!flock($file, LOCK_EX)) { return false; }
            return fwrite($file, $record) === strlen($record) && fflush($file);
        } finally { flock($file, LOCK_UN); fclose($file); }
    }

    /** Preserve the existing file's access metadata; empty membership revokes it. */
    public static function replace(string $path, string $contents): void
    {
        // Replacing the inode with tempnam()+rename() loses its mode/owner/ACL.
        // Native readers do not take our lock: this is an in-place update, not
        // a transaction or a promise of crash/power-loss atomicity.
        $file = @fopen($path, 'c+b');
        if ($file === false) { throw new \RuntimeException('Cannot open server membership list'); }
        try {
            if (!flock($file, LOCK_EX) || !rewind($file)) { throw new \RuntimeException('Cannot lock server membership list'); }
            $length = strlen($contents);
            for ($offset = 0; $offset < $length; $offset += $written) {
                $written = fwrite($file, substr($contents, $offset));
                if ($written === false || $written === 0) { throw new \RuntimeException('Cannot write server membership list'); }
            }
            if (!ftruncate($file, $length) || !fflush($file)) { throw new \RuntimeException('Cannot finish server membership list'); }
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
}
