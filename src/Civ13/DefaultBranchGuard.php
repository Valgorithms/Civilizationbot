<?php
declare(strict_types=1);
namespace Civ13;

/** Fail-closed portable bot source policy. No reset, checkout, stash, force or credential loading. */
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
        $require($command(['status','--porcelain=v1','--untracked-files=all']) === '', 'local source edits retained');
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
            $require($command(['symbolic-ref','--quiet','--short','HEAD']) === 'main' && $command(['status','--porcelain=v1','--untracked-files=all']) === '', 'source changed during verification');
            $command(['merge','--ff-only',$target]);
        }
        $require($command(['symbolic-ref','--quiet','--short','HEAD']) === 'main'
            && $command(['rev-parse','HEAD']) === $target && $command(['status','--porcelain=v1','--untracked-files=all']) === '', 'final source identity changed');
        return ['branch'=>'main','upstream'=>'origin/main','commit'=>$target,'before'=>$before,'fresh_remote_verified'=>true];
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
