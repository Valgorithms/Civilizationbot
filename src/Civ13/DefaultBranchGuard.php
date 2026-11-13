<?php
declare(strict_types=1);
namespace Civ13;

/**
 * Portable bot source policy. No reset, checkout, force or credential loading. The only stash is a
 * local composer.lock set aside when an update itself changes composer.lock (kept, never dropped).
 * control.php start treats a refusal as a warning: these checks must never keep the bot from booting.
 */
final class DefaultBranchGuard
{
    public const ORIGIN = 'https://github.com/Valgorithms/Civilizationbot.git';
    public const BRANCH = 'main';

    public static function verify(string $repository, bool $update = false, string $git = 'git', string $origin = self::ORIGIN): array
    {
        $root = realpath($repository);
        if ($root === false) { throw new \RuntimeException('Default branch guard refused: repository unavailable'); }
        $command = static fn (array $args): string => self::command($git, $root, $args);
        $require = static function (bool $ok, string $reason): void { if (!$ok) { throw new \RuntimeException('Default branch guard refused: '.$reason); } };
        $require($command(['symbolic-ref','--quiet','--short','HEAD']) === self::BRANCH, 'named branch main required');
        $require($command(['rev-parse','--abbrev-ref','--symbolic-full-name','@{upstream}']) === 'origin/main', 'upstream origin/main required');
        $require($command(['symbolic-ref','--quiet','refs/remotes/origin/HEAD']) === 'refs/remotes/origin/main', 'local origin default main required');
        $sourceStatus = $command(['status','--porcelain=v1','--untracked-files=all']);
        // composer.lock is not loaded at runtime (vendor/ is), so a local dependency lock
        // never blocks start or update. If the update changes composer.lock too, the local
        // lock is stashed first and the upstream one kept (composer install is due anyway).
        $lockOnly = self::lockOnlyStatus($sourceStatus);
        $localLockEdit = $lockOnly;
        $require($sourceStatus === '' || $localLockEdit, 'local source edits retained');
        $lockState = $localLockEdit ? self::lockState($git, $root) : null;
        $require($command(['remote','get-url','--all','origin']) === $origin, 'unexpected origin retained');
        foreach (explode("\n", $command(['ls-files','-v'])) as $line) {
            $require(!preg_match('/^[a-z]|^S /', $line), 'hidden index state retained');
        }
        $require(self::command($git,$root,['config','--bool','--get','core.sparseCheckout'],[0,1]) !== 'true', 'sparse checkout unsupported');
        $before = $command(['rev-parse','--verify','HEAD^{commit}']);
        $command(['fetch','--no-tags','origin','refs/heads/main:refs/remotes/origin/main']);
        $target = $command(['rev-parse','--verify','refs/remotes/origin/main^{commit}']);
        $advertised = preg_split('/\r?\n/', $command(['ls-remote','--symref','--exit-code','origin','HEAD','refs/heads/main']));
        $symbolic = false; $identities = [];
        foreach ($advertised as $line) {
            $parts = preg_split('/\s+/', $line);
            if ($parts === ['ref:','refs/heads/main','HEAD']) { $symbolic = true; }
            elseif (count($parts) === 2) { $identities[$parts[1]] = $parts[0]; }
        }
        $require($symbolic, 'remote default branch is not main');
        $require(preg_match('/^[a-f0-9]{40}$/D',$target) === 1 && count($identities) === 2
            && ($identities['HEAD'] ?? null) === $target && ($identities['refs/heads/main'] ?? null) === $target, 'fetched and advertised main disagree');
        if ($before !== $target) {
            self::command($git,$root,['merge-base','--is-ancestor',$before,$target],[0,1],$code);
            $require($code === 0, 'local main ahead or diverged; unmerged commits retained');
            $require($update, 'main behind; explicit safe update required');
            $require($command(['symbolic-ref','--quiet','--short','HEAD']) === 'main' && $command(['status','--porcelain=v1','--untracked-files=all']) === $sourceStatus
                && (!$localLockEdit || self::lockState($git, $root) === $lockState), 'source changed during verification');
            if ($localLockEdit) {
                self::command($git,$root,['diff','--quiet',$before,$target,'--','composer.lock'],[0,1],$lockChanged);
                if ($lockChanged === 1) {
                    $command(['stash','push','-m','civilizationbot update: local composer.lock (upstream changed it)','--','composer.lock']);
                    $stashedLock = true;
                    $sourceStatus = $command(['status','--porcelain=v1','--untracked-files=all']);
                    $require($sourceStatus === '', 'local composer.lock could not be set aside');
                    $localLockEdit = false;
                }
            }
            $command(['merge','--ff-only',$target]);
        }
        $require($command(['symbolic-ref','--quiet','--short','HEAD']) === 'main'
            && $command(['rev-parse','HEAD']) === $target
            && $command(['status','--porcelain=v1','--untracked-files=all']) === $sourceStatus
            && (!$localLockEdit || self::lockState($git, $root) === $lockState),
            'final source identity changed');
        return ['branch'=>'main','upstream'=>'origin/main','commit'=>$target,'before'=>$before,
                'fresh_remote_verified'=>true,'local_dependency_lock_edit'=>$localLockEdit,
                'local_dependency_lock_stashed'=>$stashedLock ?? false];
    }

    /**
     * Read-only update check for the launcher: fetches origin/main like verify(), never merges.
     * can_update says whether `control.php update` would fast-forward; reason explains a no.
     */
    public static function check(string $repository, string $git = 'git', string $origin = self::ORIGIN): array
    {
        $root = realpath($repository);
        if ($root === false) { throw new \RuntimeException('Default branch guard refused: repository unavailable'); }
        $current = self::command($git, $root, ['rev-parse','--verify','HEAD^{commit}']);
        $reason = '';
        try { self::verify($root, false, $git, $origin); }
        catch (\RuntimeException $error) { $reason = preg_replace('/^Default branch guard refused: /', '', $error->getMessage()); }
        $behindOnly = $reason === 'main behind; explicit safe update required';
        $fresh = $reason === '' || $behindOnly;
        $available = self::command($git, $root, ['rev-parse','--verify','refs/remotes/origin/main^{commit}'], [0,128]);
        if (preg_match('/^[a-f0-9]{40}$/D', $available) !== 1) { $available = ''; }
        $behind = $available === '' ? 0 : (int) self::command($git, $root, ['rev-list','--count',$current.'..'.$available]);
        $canUpdate = false;
        if ($reason === '') { $reason = 'already up to date'; }
        elseif ($behindOnly) {
            $dirty = self::command($git, $root, ['status','--porcelain=v1','--untracked-files=all']);
            $canUpdate = $dirty === '' || self::lockOnlyStatus($dirty);
            $reason = $canUpdate ? '' : 'local edits retained (update needs a clean checkout): '.str_replace("\n", ', ', $dirty);
        }
        return ['component'=>'bot','current'=>$current,'available'=>$available,'behind'=>$behind,
                'can_update'=>$canUpdate,'reason'=>$reason,'fresh'=>$fresh];
    }

    /**
     * Launcher "Repair and update": brings any checkout back to a clean main that tracks and equals
     * origin/main, whatever the guard refused for, and loses nothing. Local edits and untracked files
     * go to a labelled stash, local commits not on origin/main stay on a backup branch, and a local
     * composer.lock is put back when upstream did not change it. Only call with the bot stopped.
     *
     * @return array<string,mixed> what was set aside, then verify()'s answer after the repair
     */
    public static function repair(string $repository, string $git = 'git', string $origin = self::ORIGIN): array
    {
        $root = realpath($repository);
        if ($root === false || !is_dir($root.'/.git') && !is_file($root.'/.git')) { throw new \RuntimeException('Repair refused: repository unavailable'); }
        $command = static fn (array $args, array $allowed = [0], ?int &$code = null): string => self::command($git, $root, $args, $allowed, $code);
        $stamp = gmdate('Ymd-His');
        $done = ['stash' => null, 'backup_branch' => null, 'origin_was' => null, 'restored_composer_lock' => false];
        // A merge, rebase or cherry-pick left half way stops every other step.
        foreach (['merge', 'rebase', 'cherry-pick', 'am'] as $operation) {
            $command([$operation, '--abort'], [0, 1, 128]);
        }
        $currentOrigin = $command(['remote', 'get-url', 'origin'], [0, 2, 128]);
        if ($currentOrigin !== $origin) {
            $done['origin_was'] = $currentOrigin === '' ? null : $currentOrigin;
            $command($currentOrigin === '' ? ['remote', 'add', 'origin', $origin] : ['remote', 'set-url', 'origin', $origin]);
        }
        $command(['config', 'core.sparseCheckout', 'false']);
        foreach (explode("\n", $command(['ls-files', '-v'])) as $line) { // assume-unchanged (lower case) or skip-worktree (S)
            if (preg_match('/^([a-z]|S) (.+)$/D', $line, $match)) {
                if ($match[1] !== 'S') { $command(['update-index', '--no-assume-unchanged', '--', $match[2]]); }
                if (strtoupper($match[1]) === 'S') { $command(['update-index', '--no-skip-worktree', '--', $match[2]]); }
            }
        }
        $command(['fetch', '--no-tags', 'origin', '+refs/heads/main:refs/remotes/origin/main']);
        $target = $command(['rev-parse', '--verify', 'refs/remotes/origin/main^{commit}']);
        $oldHead = $command(['rev-parse', '--verify', 'HEAD^{commit}'], [0, 128]);
        $lockBytes = is_file($root.'/composer.lock') ? file_get_contents($root.'/composer.lock') : false;
        $oldLock = $oldHead !== '' ? $command(['rev-parse', '--verify', $oldHead.':composer.lock'], [0, 128]) : '';
        if ($command(['status', '--porcelain=v1', '--untracked-files=all']) !== '') {
            $command(['stash', 'push', '--include-untracked', '-m', 'civilizationbot repair '.$stamp]);
            $done['stash'] = 'civilizationbot repair '.$stamp;
        }
        // Commits that exist only locally (on main or a detached/other HEAD) stay reachable.
        foreach (array_unique(array_filter([$oldHead, $command(['rev-parse', '--verify', 'refs/heads/main^{commit}'], [0, 128])])) as $tip) {
            $command(['merge-base', '--is-ancestor', $tip, $target], [0, 1], $code);
            if ($code === 1) {
                $name = 'civilizationbot-backup-'.$stamp.($done['backup_branch'] ? '-2' : '');
                $command(['branch', $name, $tip]);
                $done['backup_branch'] = $done['backup_branch'] ? $done['backup_branch'].', '.$name : $name;
            }
        }
        $command(['checkout', '-f', '-B', 'main', $target]);
        $command(['branch', '--set-upstream-to=origin/main', 'main']);
        $command(['symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main']);
        $newLock = $command(['rev-parse', '--verify', $target.':composer.lock'], [0, 128]);
        if (is_string($lockBytes) && $oldLock !== '' && $oldLock === $newLock && hash('sha1', 'blob '.strlen($lockBytes)."\0".$lockBytes) !== $newLock) {
            file_put_contents($root.'/composer.lock', $lockBytes); // a local dependency lock (e.g. a path override) survives
            $done['restored_composer_lock'] = true;
        }

        return $done + self::verify($root, true, $git, $origin);
    }

    /** Git porcelain (trimmed) showing composer.lock as the only change, staged or not. */
    public static function lockOnlyStatus(string $status): bool
    {
        return in_array($status, ['M composer.lock','M  composer.lock','MM composer.lock'], true);
    }

    /**
     * True when the unstaged composer.lock differs from HEAD only in packages the
     * working lock installs from a path repository (dist type "path") and in
     * plugin-api-version. Every other package, the package names and every other
     * key must match HEAD exactly. A staged lock edit is never accepted here.
     */
    public static function pathOnlyLockEdit(string $git, string $root): bool
    {
        try {
            $head = self::command($git, $root, ['rev-parse','--verify','HEAD:composer.lock']);
            $staged = self::command($git, $root, ['ls-files','--stage','--','composer.lock']);
            if (!preg_match('/^100644 ([0-9a-f]{40}) 0\tcomposer\.lock$/D', $staged, $match) || $match[1] !== $head) { return false; }
            $committed = json_decode(self::command($git, $root, ['cat-file','blob',$head]), true, 64);
        } catch (\RuntimeException) { return false; }
        $bytes = @file_get_contents($root.'/composer.lock');
        $working = is_string($bytes) ? json_decode($bytes, true, 64) : null;
        return is_array($committed) && is_array($working) && self::pathOnlyDifference($committed, $working);
    }

    /** Pure comparison used by pathOnlyLockEdit(). */
    public static function pathOnlyDifference(array $committed, array $working): bool
    {
        $sections = ['packages', 'packages-dev'];
        $rest = static fn (array $lock): array => array_diff_key($lock, array_flip([...$sections, 'plugin-api-version']));
        if ($rest($committed) !== $rest($working)) { return false; }
        $path = false;
        foreach ($sections as $section) {
            $index = static function ($packages): ?array {
                if (!is_array($packages) || !array_is_list($packages)) { return null; }
                $byName = [];
                foreach ($packages as $package) {
                    if (!is_array($package) || !is_string($package['name'] ?? null) || isset($byName[$package['name']])) { return null; }
                    $byName[$package['name']] = $package;
                }
                return $byName;
            };
            $old = $index($committed[$section] ?? []); $new = $index($working[$section] ?? []);
            if ($old === null || $new === null || array_diff_key($old, $new) !== [] || array_diff_key($new, $old) !== []) { return false; }
            foreach ($new as $name => $package) {
                if ($package === $old[$name]) { continue; }
                if (($package['dist']['type'] ?? null) !== 'path') { return false; }
                $path = true;
            }
        }
        return $path;
    }

    /** Bind working bytes and the staged index mode/blob; porcelain alone is insufficient. */
    private static function lockState(string $git, string $root): array
    {
        $working = hash_file('sha256', $root.'/composer.lock');
        if ($working === false) { throw new \RuntimeException('Default branch guard refused: local dependency lock unavailable'); }
        $staged = self::command($git, $root, ['ls-files','--stage','--','composer.lock']);
        if (!preg_match('/^100644 [0-9a-f]{40} 0\tcomposer\.lock$/D', $staged)) {
            throw new \RuntimeException('Default branch guard refused: local dependency lock index unavailable');
        }
        return [$working, $staged];
    }

    private static function command(string $git, string $root, array $arguments, array $allowed = [0], ?int &$code = null): string
    {
        $environment = getenv();
        $environment['GIT_TERMINAL_PROMPT'] = '0';
        $environment['GCM_INTERACTIVE'] = 'Never';
        $pipes = []; $process = proc_open([$git,'-C',$root,...$arguments], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root, $environment, ['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \RuntimeException('Default branch guard refused: Git unavailable'); }
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $started = hrtime(true); $output = ''; $stderrBytes = 0; $failed = false;
        try {
            do {
                $output .= stream_get_contents($pipes[1]); $stderrBytes += strlen(stream_get_contents($pipes[2]));
                $status = proc_get_status($process);
                if (strlen($output)+$stderrBytes > 1048576 || (hrtime(true)-$started)/1e9 > 60) { $failed = true; proc_terminate($process); break; }
                if ($status['running']) { usleep(10000); }
            } while ($status['running']);
            $output .= stream_get_contents($pipes[1]);
            $code = $status['exitcode'];
        } finally { fclose($pipes[1]);fclose($pipes[2]);proc_close($process); }
        if ($failed || !in_array($code,$allowed,true)) { throw new \RuntimeException('Default branch guard refused: Git '.$arguments[0].' failed; source retained'); }
        return trim($output);
    }
}
