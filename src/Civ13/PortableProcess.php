<?php
declare(strict_types=1);
namespace Civ13;

/** Shell-free PHP child plan; the supervisor owns only the process handle it creates. */
final class PortableProcess
{
    public static function argv(string $php, string $root): array
    {
        if (!PortableProfile::absolute($php) || !is_file($php) || !is_file($root.'/bot.php')) { throw new \InvalidArgumentException('Explicit PHP executable and bot entry required'); }
        return [$php, $root.'/bot.php'];
    }

    public static function start(array $argv, string $state, callable $spawn): mixed
    {
        // argv array + bypass_shell works on Windows and Unix; never sudo/nohup/cmd.
        return $spawn($argv, [0=>['pipe','r'],1=>['file',$state.'/stdout.log','a'],2=>['file',$state.'/stderr.log','a']], $state, ['bypass_shell'=>true]);
    }

    /** Hold the returned handle for the lifetime of the child. */
    public static function lock(string $state): mixed
    {
        $handle = fopen($state.'/bot.lock', 'c+');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) { fclose($handle); }
            throw new \RuntimeException('Portable instance already running');
        }
        return $handle;
    }

    public static function requestStop(string $state): void
    {
        $stream = @fopen($state.'/stop.request', 'x');
        if ($stream === false) { throw new \RuntimeException('Stop already requested or state unavailable'); }
        fclose($stream);
    }
}
