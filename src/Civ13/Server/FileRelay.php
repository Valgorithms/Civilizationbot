<?php
declare(strict_types=1);
namespace Civ13\Server;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use function React\Promise\reject;

/** Bounded append relay with optional private durable checkpoints and writer exclusion. */
final class FileRelay
{
    private array $offsets = [];
    private array $busy = [];
    public function __construct(private ?string $stateDirectory = null, private $oversized = null)
    {
        if ($stateDirectory !== null) {
            if (is_link($stateDirectory) || (!is_dir($stateDirectory) && !@mkdir($stateDirectory, 0700))) {
                throw new \RuntimeException('Private relay checkpoint directory unavailable');
            }
            $this->stateDirectory = realpath($stateDirectory) ?: throw new \RuntimeException('Checkpoint path unavailable');
        }
    }
    private function checkpoint($file, array $identity, int $offset, bool $skipping): array
    {
        fseek($file, max(0, $offset - 64));
        $tail = fread($file, min(64, $offset));
        return ['version'=>1, 'identity'=>$identity, 'offset'=>$offset, 'tail'=>hash('sha256', $tail), 'skipping'=>$skipping];
    }
    private function save(string $path, ?string $state, array $checkpoint): void
    {
        if ($state !== null) {
            if (is_link($state)) { throw new \RuntimeException('Checkpoint symlink rejected'); }
            $temporary = $state.'.'.bin2hex(random_bytes(8)).'.tmp';
            try {
                $bytes = json_encode($checkpoint, JSON_THROW_ON_ERROR)."\n";
                if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $state)) {
                    throw new \RuntimeException('Cannot publish relay checkpoint');
                }
            } finally { if (is_file($temporary)) { @unlink($temporary); } }
        }
        $this->offsets[$path] = $checkpoint;
    }
    public function relay(string $path, callable $send): PromiseInterface
    {
        if (isset($this->busy[$path])) { return resolve(false); }
        $file = null; $lock = null; $state = null;
        $release = function () use (&$file, &$lock, $path): void {
            if (is_resource($file)) { fclose($file); }
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            unset($this->busy[$path]);
        };
        try {
            if (is_link($path) || !($file = @fopen($path, 'rb'))) { throw new \RuntimeException('Cannot read server chat log'); }
            $stat = fstat($file); $identity = [$stat['dev'], $stat['ino']];
            $previous = $this->offsets[$path] ?? null;
            if ($this->stateDirectory !== null) {
                $name = hash('sha256', realpath($path));
                $state = $this->stateDirectory.'/'.$name.'.json';
                $lockPath = $this->stateDirectory.'/'.$name.'.lock';
                if (is_link($lockPath) || is_link($state)) { throw new \RuntimeException('Checkpoint symlink rejected'); }
                $lock = @fopen($lockPath, 'c+b');
                if (!$lock) { throw new \RuntimeException('Cannot open relay lock'); }
                if (!flock($lock, LOCK_EX | LOCK_NB)) { $release(); return resolve(false); }
                if (is_file($state)) {
                    $checkpointFile = @fopen($state, 'rb');
                    if (!$checkpointFile) { throw new \RuntimeException('Cannot read relay checkpoint'); }
                    try { $checkpointBytes = fread($checkpointFile, 2049); }
                    finally { fclose($checkpointFile); }
                    if ($checkpointBytes === false || strlen($checkpointBytes) > 2048) { throw new \RuntimeException('Checkpoint exceeds bound'); }
                    $previous = json_decode($checkpointBytes, true, 16, JSON_THROW_ON_ERROR);
                    if (!is_array($previous) || ($previous['version'] ?? null) !== 1
                        || !is_array($previous['identity'] ?? null) || count($previous['identity']) !== 2
                        || !array_is_list($previous['identity']) || !is_int($previous['identity'][0]) || !is_int($previous['identity'][1])
                        || !is_int($previous['offset'] ?? null) || $previous['offset'] < 0
                        || !is_bool($previous['skipping'] ?? null) || !is_string($previous['tail'] ?? null)
                        || !preg_match('/^[a-f0-9]{64}$/D', $previous['tail'])) { throw new \RuntimeException('Invalid relay checkpoint'); }
                }
            }
            $offset = 0; $skipping = false;
            if ($previous !== null && $previous['identity'] === $identity && $previous['offset'] <= $stat['size']) {
                $candidate = $this->checkpoint($file, $identity, $previous['offset'], $previous['skipping']);
                if (hash_equals($previous['tail'], $candidate['tail'])) { $offset = $previous['offset']; $skipping = $previous['skipping']; }
            }
            fseek($file, $offset);
            $records = []; $bytes = 0;
            while (count($records) < 32 && $bytes < 65536 && ($line = fgets($file, min(65538, 65537 - $bytes))) !== false) {
                $bytes += strlen($line); $end = ftell($file);
                $complete = str_ends_with($line, "\n");
                if ($skipping) {
                    $skipping = !$complete;
                    $records[] = ['', $this->checkpoint($file, $identity, $end, $skipping)];
                    fseek($file, $end);
                    if (!$complete) { break; }
                    continue;
                }
                if (!$complete) {
                    if (strlen($line) < 65536) { break; }
                    if ($this->oversized === null) { throw new \RuntimeException('Oversized relay record requires audit handler'); }
                    ($this->oversized)($path, $offset);
                    $records[] = ['', $this->checkpoint($file, $identity, $end, true)];
                    break;
                }
                $records[] = [rtrim($line, "\r\n"), $this->checkpoint($file, $identity, $end, false)];
                fseek($file, $end); $offset = $end;
            }
            $this->busy[$path] = true;
            $promise = resolve(null);
            foreach ($records as [$line, $checkpoint]) {
                $promise = $promise->then(fn () => $line === '' ? resolve(null) : $send($line))
                    ->then(function () use ($path, $state, $checkpoint): void { $this->save($path, $state, $checkpoint); });
            }
            return $promise->then(function () use ($release) { $release(); return true; }, function (\Throwable $error) use ($release) { $release(); throw $error; });
        } catch (\Throwable $error) { $release(); return reject($error); }
    }
}
