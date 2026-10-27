<?php
declare(strict_types=1);
namespace Civ13\Server;

use Civ13\PortableProfile;

/** Existing operator-controlled local file only. No creation, replacement or retries. */
class AdminFileWriter
{
    public function __construct(private readonly string $path, private readonly int $maxBytes)
    {
        if (!PortableProfile::absoluteAdminFile($path) || $maxBytes < 1 || $maxBytes > 16777216) {
            throw new \InvalidArgumentException('Explicit local admin file and bounded byte budget required');
        }
        $normalized = self::normalize($path);
        $tail = PHP_OS_FAMILY === 'Windows' ? substr($normalized,3) : substr($normalized,1);
        if ($tail === '' || str_starts_with($normalized,'//')
            || (PHP_OS_FAMILY === 'Windows' && str_contains($tail,':'))
            || array_intersect(explode('/',$tail),['','.','..']) !== []) {
            throw new \InvalidArgumentException('Canonical local file path required');
        }
    }

    /**
     * Returns updated, checked unchanged, superseded (guard), or blocked (no mutation).
     * Throws after possible mutation; callers must treat file integrity as uncertain.
     * Guard must be pure and synchronous. No asynchronous yield between guard and write.
     */
    final public function __invoke(string $bytes, int $generation, callable $canCommit): array
    {
        if ($generation < 0 || strlen($bytes) > $this->maxBytes) { return $this->blocked('invalid-budget-or-generation'); }
        if (!$this->allowed($canCommit)) { return ['state'=>'superseded']; }
        $before = $this->pathStat();
        if ($before === null) { return $this->blocked('path-unavailable-or-unsafe'); }
        // r+b never creates a missing path and never truncates merely by opening it.
        $file = @fopen($this->path,'r+b');
        if ($file === false) { return $this->blocked('open-failed'); }
        $locked = false;
        try {
            $wouldBlock = 0;
            if (!@flock($file,LOCK_EX | LOCK_NB,$wouldBlock)) {
                return $this->blocked($wouldBlock === 1 ? 'lock-busy' : 'lock-failed');
            }
            $locked = true;
            $opened = @fstat($file); $current = $this->pathStat();
            if (!$this->same($before,$opened) || !$this->same($opened,$current)
                || !is_int($opened['size'] ?? null) || $opened['size'] < 0 || $opened['size'] > $this->maxBytes) {
                return $this->blocked('identity-or-size-unavailable');
            }
            if (!$this->allowed($canCommit)) { return ['state'=>'superseded']; }
            if (!@rewind($file)) { return $this->blocked('read-position-failed'); }
            $existing = @stream_get_contents($file,$this->maxBytes + 1);
            if ($existing === false || strlen($existing) > $this->maxBytes || strlen($existing) !== $opened['size']) {
                return $this->blocked('bounded-read-failed');
            }
            if (!$this->same($opened,$this->pathStat())) { return $this->blocked('path-changed'); }
            if (!@rewind($file)) { return $this->blocked('write-position-failed'); }
            // Includes the unchanged acknowledgement: stale data must not be called current.
            if (!$this->allowed($canCommit)) { return ['state'=>'superseded']; }
            if ($existing === $bytes) { return ['state'=>'unchanged']; }
            $length = strlen($bytes);
            for ($offset = 0; $offset < $length; $offset += $written) {
                $written = $this->writeChunk($file,substr($bytes,$offset,65536));
                if ($written === false || $written <= 0 || $written > min(65536,$length - $offset)) {
                    throw new \RuntimeException('Admin file write failed; integrity uncertain');
                }
            }
            if (!$this->truncate($file,$length) || !$this->flush($file)) {
                throw new \RuntimeException('Admin file completion failed; integrity uncertain');
            }
            if (!$this->same($opened,$this->pathStat())) {
                throw new \RuntimeException('Admin file path changed after mutation; integrity uncertain');
            }
            return ['state'=>'updated'];
        } finally {
            if ($locked) { @flock($file,LOCK_UN); }
            @fclose($file);
        }
    }

    private function blocked(string $reason): array { return ['state'=>'blocked','reason'=>$reason]; }

    private function allowed(callable $guard): bool
    {
        try { return $guard() === true; } catch (\Throwable) { return false; }
    }

    /** Conservative portable checks, not an atomic no-follow/open-by-handle boundary. */
    private function pathStat(): ?array
    {
        clearstatcache(true);
        $stat = @lstat($this->path); $resolved = @realpath($this->path);
        if (!is_array($stat) || !self::regular($stat) || $resolved === false
            || self::normalize($resolved) !== self::normalize($this->path)) { return null; }
        return $stat;
    }

    private function same(mixed $left, mixed $right): bool
    {
        // Modern PHP supplies Windows file IDs too. Missing/zero IDs fail closed;
        // never replace this with size/mtime equality to make a platform test pass.
        return PHP_INT_SIZE >= 8 && is_array($left) && is_array($right)
            && self::regular($left) && self::regular($right)
            && is_int($left['dev'] ?? null) && is_int($right['dev'] ?? null)
            && is_int($left['ino'] ?? null) && is_int($right['ino'] ?? null)
            && $left['ino'] !== 0 && $right['ino'] !== 0
            && $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }

    private static function regular(array $stat): bool
    {
        return is_int($stat['mode'] ?? null) && ($stat['mode'] & 0170000) === 0100000;
    }

    private static function normalize(string $path): string
    {
        return PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\','/',$path)) : $path;
    }

    // Narrow failure-injection seam for temporary-file tests; production calls PHP directly.
    protected function writeChunk($file, string $bytes): int|false { return @fwrite($file,$bytes); }
    protected function truncate($file, int $length): bool { return @ftruncate($file,$length); }
    protected function flush($file): bool { return @fflush($file); }
}
