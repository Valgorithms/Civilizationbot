<?php
declare(strict_types=1);
namespace Civ13\Server;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Bounded append-log relay. Advance only after delivery; never erase producer logs. */
final class FileRelay
{
    private array $offsets = [];
    private array $busy = [];
    public function relay(string $path, callable $send): PromiseInterface
    {
        if (isset($this->busy[$path])) { return resolve(false); }
        $file = @fopen($path, 'rb');
        if (!$file) { return \React\Promise\reject(new \RuntimeException('Cannot read server chat log')); }
        $stat = fstat($file);
        $identity = [$stat['dev'], $stat['ino']];
        $previous = $this->offsets[$path] ?? [$identity, 0];
        $offset = $previous[0] === $identity && $previous[1] <= $stat['size'] ? $previous[1] : 0;
        fseek($file, $offset);
        $lines = []; $ends = []; $bytes = 0;
        while (count($lines) < 32 && $bytes < 65536 && ($line = fgets($file, 8194)) !== false) {
            if (!str_ends_with($line, "\n")) { break; }
            $bytes += strlen($line); $lines[] = rtrim($line, "\r\n"); $ends[] = ftell($file);
        }
        fclose($file);
        $this->busy[$path] = true;
        $promise = resolve(null);
        foreach ($lines as $index => $line) {
            $promise = $promise->then(function () use ($send, $line) { return $line === '' ? resolve(null) : $send($line); })
                ->then(function () use ($path, $identity, $ends, $index) { $this->offsets[$path] = [$identity, $ends[$index]]; });
        }
        return $promise->then(function () use ($path) { unset($this->busy[$path]); return true; }, function (\Throwable $error) use ($path) { unset($this->busy[$path]); throw $error; });
    }
}
