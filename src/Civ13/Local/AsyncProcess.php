<?php
declare(strict_types=1);
namespace Civ13\Local;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/** Bounded shell-free worker execution. Never kills discovered or descendant PIDs. */
final class AsyncProcess
{
    private bool $busy = false;
    public function __construct(private LoopInterface $loop, private string $temporaryRoot, private $launch = null)
    {
        if (!is_dir($temporaryRoot) || is_link($temporaryRoot)) { throw new \InvalidArgumentException('Private temporary root required'); }
        $this->launch ??= static fn ($argv, $descriptors, $env) => proc_open($argv, $descriptors, $pipes, null, $env, ['bypass_shell' => true, 'create_new_console' => false]);
    }
    public function __invoke(array $argv, int $seconds): PromiseInterface
    {
        $deferred = new Deferred();
        if ($this->busy || $seconds < 1 || $seconds > 210 || !$argv || count($argv) > 8) { $deferred->reject(new \RuntimeException('Worker busy or invalid bounds')); return $deferred->promise(); }
        foreach ($argv as $arg) { if (!is_string($arg) || strlen($arg) > 4096 || str_contains($arg, "\0")) { $deferred->reject(new \InvalidArgumentException('Invalid argv')); return $deferred->promise(); } }
        $directory = $this->temporaryRoot.'/worker-'.bin2hex(random_bytes(12)); mkdir($directory, 0700);
        $out = $directory.'/stdout'; $err = $directory.'/stderr';
        $env = []; foreach (['PATH', 'SYSTEMROOT', 'TEMP', 'TMP'] as $key) { $value = getenv($key); if ($value !== false) { $env[$key] = $value; } }
        try { $process = ($this->launch)($argv, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $env); }
        catch (\Throwable $error) { foreach ([$out, $err] as $file) { if (is_file($file)) { unlink($file); } } rmdir($directory); $deferred->reject($error); return $deferred->promise(); }
        if (!is_resource($process)) { foreach ([$out, $err] as $file) { if (is_file($file)) { unlink($file); } } rmdir($directory); $deferred->reject(new \RuntimeException('Worker unavailable')); return $deferred->promise(); }
        $this->busy = true; $deadline = hrtime(true) + $seconds * 1000000000;
        $timer = $this->loop->addPeriodicTimer(0.05, function ($timer) use ($process, $out, $err, $directory, $deadline, $deferred): void {
            clearstatcache(); $status = proc_get_status($process);
            $oversized = (is_file($out) && filesize($out) > 4096) || (is_file($err) && filesize($err) > 4096);
            if ($status['running'] && !$oversized && hrtime(true) < $deadline) { return; }
            $this->loop->cancelTimer($timer);
            $unknown = $status['running'] || $oversized;
            if ($status['running']) { proc_terminate($process, 9); }
            $code = $status['exitcode']; $closed = proc_close($process); if ($code < 0) { $code = $closed; }
            $stdout = !$unknown && is_file($out) ? file_get_contents($out) : '';
            foreach ([$out, $err] as $file) { if (is_file($file)) { unlink($file); } } rmdir($directory); $this->busy = false;
            if ($unknown) { $deferred->reject(new \RuntimeException('Worker deadline/output exceeded; outcome unknown; inspect receipt before retry')); }
            else { $deferred->resolve(['exit_code' => $code, 'stdout' => $stdout]); }
        });
        return $deferred->promise();
    }
}
