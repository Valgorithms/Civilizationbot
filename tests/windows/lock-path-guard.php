<?php
declare(strict_types=1);
// DefaultBranchGuard keeps a local composer.lock through an update, sets it aside in a stash
// when the update changes composer.lock itself, and still refuses other source edits.
// Temporary Git repositories only; no bot, Composer or network.
require __DIR__.'/../../src/Civ13/DefaultBranchGuard.php';
use Civ13\DefaultBranchGuard;
function lockCheck(bool $ok, string $what): void { if (!$ok) { throw new RuntimeException('Lock path guard fixture failed: '.$what); } }
function lockRun(array $args): string {
    $pipes = []; $p = proc_open($args, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, null, null, ['bypass_shell' => true]);
    lockCheck(is_resource($p), 'process'); fclose($pipes[0]); $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    lockCheck(proc_close($p) === 0, 'command '.implode(' ', $args)); return trim($out);
}
function lockGit(string $root, ...$args): string { return lockRun(['git','-c','user.name=Fixture','-c','user.email=fixture@example.invalid','-C',$root,...$args]); }
function lockRefused(callable $call): void { try { $call(); } catch (RuntimeException $e) { lockCheck(str_contains($e->getMessage(), 'Default branch guard refused'), 'refusal text'); return; } throw new RuntimeException('Expected guard refusal'); }
function lockJson(array $lock): string { return json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"; }

$zip = static fn (string $name, string $version): array => ['name' => $name, 'version' => $version, 'dist' => ['type' => 'zip', 'url' => "https://example.invalid/{$name}.zip", 'reference' => 'a']];
$path = static fn (string $name): array => ['name' => $name, 'version' => 'dev-master', 'dist' => ['type' => 'path', 'url' => "D:/GitHub/{$name}", 'reference' => 'b']];
$committed = ['content-hash' => 'h', 'packages' => [$zip('discord-php/http', 'v10.9.8'), $zip('react/http', 'v1.11.1')], 'packages-dev' => [$zip('symfony/console', 'v7.4.20')], 'plugin-api-version' => '2.6.0'];
$pathOnly = $committed; $pathOnly['packages'][0] = $path('discord-php/http'); $pathOnly['plugin-api-version'] = '2.9.0';
$bumped = $pathOnly; $bumped['packages'][1] = $zip('react/http', 'v1.12.0');
$added = $pathOnly; $added['packages'][] = $zip('symfony/polyfill-time', 'v1.0.0');
$hash = $pathOnly; $hash['content-hash'] = 'other';
$devPath = $committed; $devPath['packages-dev'][0] = $path('symfony/console');

// Pure comparison.
lockCheck(DefaultBranchGuard::pathOnlyDifference($committed, $pathOnly), 'path-only edit accepted');
lockCheck(DefaultBranchGuard::pathOnlyDifference($committed, $devPath), 'dev path package accepted');
lockCheck(!DefaultBranchGuard::pathOnlyDifference($committed, $committed), 'identical lock is not a path edit');
lockCheck(!DefaultBranchGuard::pathOnlyDifference($committed, $bumped), 'zip version bump refused');
lockCheck(!DefaultBranchGuard::pathOnlyDifference($committed, $added), 'added package refused');
lockCheck(!DefaultBranchGuard::pathOnlyDifference($committed, $hash), 'content-hash change refused');
$swapped = $committed; $swapped['packages'][0] = $path('other/package');
lockCheck(!DefaultBranchGuard::pathOnlyDifference($committed, $swapped), 'renamed package refused');

$root = sys_get_temp_dir().'/lock-path-guard-'.bin2hex(random_bytes(8)); mkdir($root);
try {
    $origin = $root.'/origin.git'; $seed = $root.'/seed'; $repo = $root.'/runtime';
    lockRun(['git','init','--bare','--initial-branch=main',$origin]); lockRun(['git','clone',$origin,$seed]);
    file_put_contents($seed.'/source.txt', 'first'); file_put_contents($seed.'/composer.lock', lockJson($committed));
    lockGit($seed, 'add', 'source.txt', 'composer.lock'); lockGit($seed, 'commit', '-m', 'first'); lockGit($seed, 'push', 'origin', 'main');
    lockRun(['git','clone',$origin,$repo]);
    $first = lockGit($repo, 'rev-parse', 'HEAD');
    file_put_contents($seed.'/source.txt', 'second'); lockGit($seed, 'add', 'source.txt'); lockGit($seed, 'commit', '-m', 'second'); lockGit($seed, 'push', 'origin', 'main');
    $second = lockGit($seed, 'rev-parse', 'HEAD');

    // Any local lock edit (even a version bump) no longer blocks the update: the lock is not runtime source.
    $bumpedBytes = lockJson($bumped); file_put_contents($repo.'/composer.lock', $bumpedBytes);
    $state = DefaultBranchGuard::check($repo, 'git', $origin);
    lockCheck($state['behind'] === 1 && $state['can_update'] === true && $state['reason'] === '', 'bumped lock updatable: '.$state['reason']);
    $result = DefaultBranchGuard::verify($repo, true, 'git', $origin);
    lockCheck($result['commit'] === $second && $result['local_dependency_lock_edit'] === true && $result['local_dependency_lock_stashed'] === false, 'update applied with lock kept');
    lockCheck(lockGit($repo, 'rev-parse', 'HEAD') === $second && file_get_contents($repo.'/composer.lock') === $bumpedBytes, 'HEAD advanced and lock bytes kept');

    // A staged lock edit updates too.
    file_put_contents($seed.'/source.txt', 'third'); lockGit($seed, 'add', 'source.txt'); lockGit($seed, 'commit', '-m', 'third'); lockGit($seed, 'push', 'origin', 'main');
    $third = lockGit($seed, 'rev-parse', 'HEAD');
    $bytes = lockJson($pathOnly); file_put_contents($repo.'/composer.lock', $bytes); lockGit($repo, 'add', 'composer.lock');
    lockCheck(DefaultBranchGuard::check($repo, 'git', $origin)['can_update'] === true, 'staged lock edit updatable');
    lockCheck(DefaultBranchGuard::verify($repo, true, 'git', $origin)['commit'] === $third && file_get_contents($repo.'/composer.lock') === $bytes, 'staged lock kept through update');
    lockGit($repo, 'reset', '-q', '--', 'composer.lock');

    // Another source edit beside the lock is still refused by update (repair handles it).
    file_put_contents($seed.'/source.txt', 'fourth'); lockGit($seed, 'add', 'source.txt'); lockGit($seed, 'commit', '-m', 'fourth'); lockGit($seed, 'push', 'origin', 'main');
    file_put_contents($repo.'/notes.txt', 'unrelated');
    lockRefused(fn () => DefaultBranchGuard::verify($repo, true, 'git', $origin));
    lockCheck(lockGit($repo, 'rev-parse', 'HEAD') === $third, 'mixed edits kept HEAD');
    unlink($repo.'/notes.txt');

    // Main changing composer.lock itself: the local lock is stashed (kept) and upstream's lock is used.
    $upstream = $committed; $upstream['packages'][1] = $zip('react/http', 'v1.13.0');
    file_put_contents($seed.'/composer.lock', lockJson($upstream)); lockGit($seed, 'add', 'composer.lock'); lockGit($seed, 'commit', '-m', 'lock'); lockGit($seed, 'push', 'origin', 'main');
    $result = DefaultBranchGuard::verify($repo, true, 'git', $origin);
    lockCheck($result['commit'] === lockGit($seed, 'rev-parse', 'HEAD') && $result['local_dependency_lock_stashed'] === true, 'upstream lock change updates');
    lockCheck(file_get_contents($repo.'/composer.lock') === lockJson($upstream) && lockGit($repo, 'status', '--porcelain') === '', 'upstream lock checked out cleanly');
    lockGit($repo, 'checkout', 'stash@{0}', '--', 'composer.lock');
    lockCheck(file_get_contents($repo.'/composer.lock') === $bytes, 'stashed local lock recoverable');
    echo "PASS lock guard: any local lock edit updates (unstaged, staged), mixed edits refused, upstream lock change stashes the local lock\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { @chmod($file->getPathname(), 0700); $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname()); }
    @rmdir($root);
}
