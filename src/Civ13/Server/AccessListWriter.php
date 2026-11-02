<?php
declare(strict_types=1);
namespace Civ13\Server;

use Civ13\AccessListProjection;
use Civ13\PortableProfile;

/** Same-directory per-file replacement in an operator-owned local SQL directory. */
class AccessListWriter
{
    private bool $latched = false;
    private readonly array $targets;
    private array $receipt = ['state' => 'not_attempted', 'targets' => []];

    public function __construct(array $targets, private readonly int $maxBytes)
    {
        if ($maxBytes < 1 || $maxBytes > 4194304 || count($targets) !== 2 || array_diff(array_keys($targets), ['approved', 'whitelist']) !== []) { throw new \InvalidArgumentException('Explicit bounded access targets required'); }
        $this->targets=['approved'=>$targets['approved'],'whitelist'=>$targets['whitelist']];
        $parents = [];
        foreach ($targets as $kind => $target) {
            $path = $target['path'] ?? null;
            if (!PortableProfile::absoluteAdminFile($path) || str_contains($path, "\0") || str_contains($path, '://')
                || str_starts_with(str_replace('\\', '/', $path), '//') || basename($path) !== $kind.'.txt'
                || !is_bool($target['allow_creation'] ?? null) || !is_bool($target['allow_empty'] ?? null)
                || ($target['metadata_policy'] ?? null) !== 'inherit-parent') { throw new \InvalidArgumentException('Explicit local access target and replacement metadata policy required'); }
            $normalized = str_replace('\\', '/', $path);
            $tail = PHP_OS_FAMILY === 'Windows' ? substr($normalized, 3) : substr($normalized, 1);
            if (array_intersect(explode('/', $tail), ['', '.', '..']) !== [] || (PHP_OS_FAMILY === 'Windows' && str_contains($tail, ':'))) { throw new \InvalidArgumentException('Canonical access path required'); }
            $parents[] = dirname($path);
        }
        if ($parents[0] !== $parents[1]) { throw new \InvalidArgumentException('Access targets must share one explicit local directory'); }
    }

    public function lastReceipt(): array { return $this->receipt; }

    public function __invoke(string $wire, int $generation, callable $canCommit): array
    {
        $result = $this->publish($wire, $generation, $canCommit);
        if (($this->receipt['cleanup_unconfirmed'] ?? false) === true) { throw new \RuntimeException('Access staging cleanup needs reconciliation'); }
        return $result;
    }

    private function publish(string $wire, int $generation, callable $canCommit): array
    {
        if ($this->latched) { $this->receipt['latched']=true; return ['state'=>'blocked', 'reason'=>'reconciliation-required']; }
        $this->receipt = ['state' => 'blocked', 'targets' => []];
        $data = AccessListProjection::decode($wire);
        if ($data['unmapped_role_holders'] !== 0) { return $this->receipt + ['reason'=>'unmapped-role-holders']; }
        if ($generation < 0 || !$this->allowed($canCommit)) { $this->receipt['state']='superseded'; return $this->receipt; }
        $parent = dirname($this->targets['approved']['path']);
        $resolved = realpath($parent);
        if ($resolved === false || !$this->samePath($parent, $resolved) || is_link($parent)) { return $this->receipt + ['reason' => 'directory-unavailable']; }
        $lockPath = $parent.'/.civilizationbot-access.lock';
        $statePath = $parent.'/.civilizationbot-access.state';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) { return $this->receipt + ['reason' => 'lock-unsafe']; }
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) { return $this->receipt + ['reason' => 'lock-unavailable']; }
        $stages = []; $before = []; $committed = false; $mutationPossible = false; $locked = false; $phase='preparation'; $activeKind=null; $reserved=false;
        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) { return $this->receipt + ['reason' => 'lock-busy']; }
            $locked = true;
            clearstatcache(true,$lockPath);
            $lockIdentity=fstat($lock); $parentIdentity=@stat($parent);
            if (!$this->sameIdentity($lockIdentity,@lstat($lockPath)) || is_link($lockPath) || !is_array($parentIdentity)) { throw new \RuntimeException('lock-identity'); }
            if (!rewind($lock) || stream_get_contents($lock,129) !== '') {
                $this->receipt['reason']='pending-publication-reconciliation';
                return $this->receipt;
            }
            $state=$this->snapshot($statePath,true,64);
            if ($state['bytes']!==null && $state['bytes']!=='') {
                $this->receipt['reason']='pending-publication-reconciliation'; return $this->receipt;
            }
            // The lock inode stays stable; reservation state has its own atomically replaced inode.
            if (!$this->replaceReservation($statePath,true)) {
                $this->latched=true; $this->receipt['cleanup_unconfirmed']=true;
                throw new \RuntimeException('reservation-unconfirmed');
            }
            $reserved=true;
            // Prepare both complete files before changing either target.
            foreach ($this->targets as $kind => $target) {
                $bytes = $data['outputs'][$kind];
                if (strlen($bytes) > $this->maxBytes || ($bytes === "\n" && !$target['allow_empty'])) { throw new \RuntimeException('output-policy'); }
                $before[$kind] = $this->snapshot($target['path'], $target['allow_creation']);
                if ($before[$kind]['bytes'] === $bytes) { $this->receipt['targets'][$kind] = ['state' => 'unchanged']; continue; }
                $path = $parent.'/.civ13-access-'.bin2hex(random_bytes(16)).'.tmp';
                $file = @fopen($path, 'x+b');
                if ($file === false) { throw new \RuntimeException('stage-open'); }
                $stages[$kind] = ['path' => $path, 'identity' => fstat($file)];
                try {
                    for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
                        $written = $this->writeChunk($file, substr($bytes, $offset, 65536));
                        if (!is_int($written) || $written <= 0 || $written > min(65536, $length - $offset)) { throw new \RuntimeException('stage-write'); }
                    }
                    if (!$this->sync($file) || !rewind($file) || stream_get_contents($file, $this->maxBytes + 1) !== $bytes) { throw new \RuntimeException('stage-verify'); }
                } finally { fclose($file); }
            }
            $phase='publication';
            foreach ($this->targets as $kind => $target) {
                $activeKind=$kind;
                clearstatcache(true,$lockPath); clearstatcache(true,$parent);
                if (!$this->sameIdentity($lockIdentity,@lstat($lockPath)) || !$this->sameIdentity($parentIdentity,@stat($parent)) || !$this->samePath($parent,realpath($parent))) { throw new \RuntimeException('directory-or-lock-changed'); }
                if ($this->snapshot($target['path'], $target['allow_creation']) !== $before[$kind]) { throw new \RuntimeException('destination-changed'); }
                if (isset($stages[$kind])) {
                    clearstatcache(true, $stages[$kind]['path']);
                    if (!$this->sameIdentity($stages[$kind]['identity'], @lstat($stages[$kind]['path']))) { throw new \RuntimeException('stage-identity'); }
                }
                // Recheck after potentially blocking reads, immediately before publication.
                if (!$this->allowed($canCommit)) {
                    if ($committed) { throw new \RuntimeException('partial-superseded'); }
                    $this->receipt['state']='superseded'; return $this->receipt;
                }
                if (!isset($stages[$kind])) { continue; }
                $mutationPossible = true;
                if (!$this->replace($stages[$kind]['path'], $target['path'])) {
                    // A false/throwing adapter is not automatically a no-mutation receipt.
                    if ($this->snapshot($target['path'], $target['allow_creation']) === $before[$kind]) { $mutationPossible = false; }
                    throw new \RuntimeException('replace-refused');
                }
                $committed = true;
                unset($stages[$kind]);
                $this->receipt['targets'][$kind] = ['state' => 'committed-unconfirmed'];
                $after = $this->snapshot($target['path'], false);
                if ($after['bytes'] !== $data['outputs'][$kind]) { throw new \RuntimeException('published-verification'); }
                $this->receipt['targets'][$kind] = ['state' => 'updated', 'sha256' => hash('sha256', $after['bytes']), 'bytes' => strlen($after['bytes'])];
            }
            $this->receipt['state'] = $committed ? 'updated' : 'unchanged';
            return $this->receipt;
        } catch (\Throwable) {
            $this->receipt['state'] = ($committed || $mutationPossible) ? 'failed' : 'blocked';
            $this->receipt['integrity_uncertain'] = $committed || $mutationPossible;
            $this->receipt['reason'] = $phase.'-refused';
            foreach ($before as $kind=>$baseline) {
                if (isset($this->receipt['targets'][$kind])) { continue; }
                $this->receipt['targets'][$kind] = ($mutationPossible && $kind===$activeKind) ? ['state'=>'unconfirmed']
                    : ['state'=>$baseline['bytes']===null ? 'absent' : 'preserved', 'sha256'=>$baseline['bytes']===null ? null : hash('sha256',$baseline['bytes'])];
            }
            if ($committed || $mutationPossible) { $this->latched = true; throw new \RuntimeException('Access publication needs reconciliation'); }
            return $this->receipt;
        } finally {
            foreach ($stages as $stage) {
                clearstatcache(true, $stage['path']);
                $current = @lstat($stage['path']);
                if ($current===false && !file_exists($stage['path']) && !is_link($stage['path'])) { continue; }
                if (!$this->sameIdentity($stage['identity'], $current) || !$this->removeStage($stage['path'])) {
                    $this->latched = true;
                    $this->receipt['cleanup_unconfirmed'] = true;
                    $this->receipt['state'] = 'failed';
                }
            }
            if ($reserved && !$this->latched) {
                // Sync/verify the EMPTY STAGE first. A failed sync cannot erase the live pending record.
                if (!$this->replaceReservation($statePath,false)) {
                    $this->latched=true; $this->receipt['cleanup_unconfirmed']=true; $this->receipt['state']='failed';
                }
            }
            if ($locked) { @flock($lock, LOCK_UN); }
            fclose($lock);
        }
    }

    private function snapshot(string $path, bool $allowMissing, ?int $limit = null): array
    {
        $budget=$limit ?? $this->maxBytes;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            if ($allowMissing && !file_exists($path) && !is_link($path)) { return ['identity' => null, 'bytes' => null]; }
            throw new \RuntimeException('destination-unavailable');
        }
        if (($stat['mode'] & 0170000) !== 0100000 || is_link($path) || !is_int($stat['size']) || $stat['size'] > $budget
            || !$this->samePath($path, realpath($path))) { throw new \RuntimeException('destination-unsafe'); }
        $file = @fopen($path, 'rb');
        if ($file === false) { throw new \RuntimeException('destination-open'); }
        try {
            if (!$this->sameIdentity($stat, fstat($file))) { throw new \RuntimeException('destination-identity'); }
            $bytes = stream_get_contents($file, $budget + 1);
            clearstatcache(true, $path);
            if (!is_string($bytes) || strlen($bytes) !== $stat['size'] || !$this->sameIdentity($stat, @lstat($path))) { throw new \RuntimeException('destination-read'); }
            return ['identity' => ['dev' => $stat['dev'], 'ino' => $stat['ino']], 'bytes' => $bytes];
        } finally { fclose($file); }
    }

    /** No fallible flush/read follows the successful state rename; no power-loss claim. */
    protected function replaceReservation(string $path, bool $pending): bool
    {
        $stage=null; $file=null; $identity=false;
        try {
            $before=$this->snapshot($path,true,64);
            if (!$pending && $before['bytes']!=="civ13.access.pending.v1\n") { return false; }
            if ($pending && $before['bytes']!==null && $before['bytes']!=='') { return false; }
            $stage=dirname($path).'/.civ13-access-journal-'.bin2hex(random_bytes(16)).'.tmp';
            $file=@fopen($stage,'x+b');
            if ($file===false) { $stage=null; return false; }
            $identity=fstat($file); $bytes=$pending ? "civ13.access.pending.v1\n" : '';
            if (($bytes!=='' && fwrite($file,$bytes)!==strlen($bytes)) || !$this->syncJournal($file,$pending)
                || !rewind($file) || stream_get_contents($file,65)!==$bytes) { return false; }
            if (!fclose($file)) { $file=null; return false; } $file=null;
            if ($this->snapshot($path,true,64)!==$before || !@rename($stage,$path)) { return false; }
            $stage=null; // Successful atomic namespace completion; do not mutate/flush the live state.
            return true;
        } catch (\Throwable) { return false; }
        finally {
            if (is_resource($file)) { fclose($file); }
            if ($stage!==null) {
                clearstatcache(true,$stage);
                if (!$this->sameIdentity($identity,@lstat($stage)) || !$this->removeStage($stage)) {
                    $this->latched=true; $this->receipt['cleanup_unconfirmed']=true;
                }
            }
        }
    }

    protected function syncJournal($file, bool $pending): bool { return @fflush($file) && @fsync($file); }

    private function allowed(callable $guard): bool { try { return $guard() === true; } catch (\Throwable) { return false; } }
    private function samePath(string $left, string|false $right): bool
    {
        if ($right === false) { return false; }
        $left = str_replace('\\', '/', $left); $right = str_replace('\\', '/', $right);
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }
    private function sameIdentity(array|false $left, array|false $right): bool
    {
        return is_array($left) && is_array($right) && ($left['ino'] ?? 0) !== 0 && ($right['ino'] ?? 0) !== 0
            && $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
    }
    protected function writeChunk($file, string $bytes): int|false { return fwrite($file, $bytes); }
    protected function sync($file): bool { return @fflush($file) && @fsync($file); }
    protected function replace(string $from, string $to): bool { return @rename($from, $to); }
    protected function removeStage(string $path): bool { return @unlink($path); }
}
