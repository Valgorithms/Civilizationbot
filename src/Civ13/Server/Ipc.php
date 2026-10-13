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

    /** Empty membership is meaningful: revoke the previous list. */
    public static function replace(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.bot-list-');
        if ($temporary === false) { throw new \RuntimeException('Cannot stage server membership list'); }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot replace server membership list');
            }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
}
