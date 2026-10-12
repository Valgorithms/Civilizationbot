<?php
declare(strict_types=1);
namespace Civ13\Local;
use React\Promise\PromiseInterface;

/** Opt-in worker adapter. No Discord routes, native fallback, shell or automatic retry. */
final class OpenDreamControl
{
    private array $maps;
    private string $profileDigest;
    public function __construct(private array $server, private string $python, private string $controller, private string $profile, private $execute, private bool $enabled = false)
    {
        foreach ([$python, $controller, $profile] as $path) { if (!self::absolute($path) || !is_file($path) || is_link($path)) { throw new \InvalidArgumentException('Explicit existing trusted adapter paths required'); } }
        if (!is_callable($execute) || ($server['engine'] ?? null) !== 'opendream' || ($server['host'] ?? null) !== '127.0.0.1') { throw new \InvalidArgumentException('Unsupported control engine'); }
        if (filesize($profile) > 65536) { throw new \InvalidArgumentException('Bounded controller profile required'); }
        $bytes = file_get_contents($profile) ?: ''; $this->profileDigest = hash('sha256', $bytes);
        $native = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (($native['engine'] ?? null) !== 'opendream' || realpath($native['runtime_dir'] ?? '') !== realpath($server['runtime_dir'] ?? '') || ($native['game_port'] ?? null) !== ($server['gameplay_port'] ?? null) || ($native['topic_port'] ?? null) !== ($server['topic_port'] ?? null) || !is_dir($server['runtime_dir'] ?? '')) { throw new \InvalidArgumentException('Controller profile binding mismatch'); }
        foreach (['gameplay_port', 'topic_port'] as $key) { if (!is_int($server[$key]) || $server[$key] < 1024 || $server[$key] > 65535) { throw new \InvalidArgumentException('Invalid local port'); } }
        if ($server['gameplay_port'] === $server['topic_port']) { throw new \InvalidArgumentException('Port collision'); }
        $this->maps = $native['maps'] ?? [];
        if (!is_array($this->maps) || count($this->maps) > 128) { throw new \InvalidArgumentException('Invalid map allowlist'); }
        foreach ($this->maps as $id => $relative) {
            $path = is_string($relative) ? realpath($server['runtime_dir'].'/'.$relative) : false;
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) || !$path || !str_starts_with($path, rtrim(realpath($server['runtime_dir']), '/\\').DIRECTORY_SEPARATOR) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'dmm') { throw new \InvalidArgumentException('Invalid map destination'); }
        }
    }
    /** Executor takes argv plus bounded timeout seconds, resolves {exit_code,stdout}; rejection means unknown outcome. */
    public function request(string $action, ?string $map = null): PromiseInterface
    {
        if (!$this->enabled) { throw new \LogicException('Local lifecycle controls disabled'); }
        if (is_link($this->profile) || hash_file('sha256', $this->profile) !== $this->profileDigest) { throw new \LogicException('Controller profile changed; explicit review required'); }
        if (!in_array($action, ['start', 'stop', 'restart', 'status', 'mapswap'], true) || ($action === 'mapswap' ? !isset($this->maps[$map ?? '']) : $map !== null)) { throw new \InvalidArgumentException('Unsupported lifecycle request'); }
        $argv = [$this->python, $this->controller, '--profile', $this->profile, $action];
        if ($map !== null) { $argv[] = $map; }
        $promise = ($this->execute)($argv, $action === 'start' ? 25 : 210);
        if (!$promise instanceof PromiseInterface) { throw new \LogicException('Async executor required'); }
        return $promise->then(function (array $result): array {
            if (!is_int($result['exit_code'] ?? null) || !is_string($result['stdout'] ?? null) || strlen($result['stdout']) > 4096) { throw new \UnexpectedValueException('Invalid control transport result'); }
            $data = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_bool($data['ok'] ?? null)) { throw new \UnexpectedValueException('Invalid control response'); }
            if (!$data['ok']) { if ($result['exit_code'] !== 1 || !is_string($data['error'] ?? null)) { throw new \UnexpectedValueException('Invalid failure response'); } throw new \RuntimeException('Local controller rejected request'); }
            $keys = array_keys($data); sort($keys); $allowed = ['engine', 'game_port', 'id', 'ok', 'running', 'runtime_dir', 'server_pid', 'topic_port'];
            if (array_diff($keys, $allowed) || $result['exit_code'] !== 0 || ($data['engine'] ?? null) !== 'opendream' || !is_bool($data['running'] ?? null) || ($data['game_port'] ?? null) !== $this->server['gameplay_port'] || ($data['topic_port'] ?? null) !== $this->server['topic_port'] || realpath($data['runtime_dir'] ?? '') !== realpath($this->server['runtime_dir']) || !array_key_exists('server_pid', $data) || ($data['server_pid'] !== null && (!is_int($data['server_pid']) || $data['server_pid'] <= 0))) { throw new \UnexpectedValueException('Unbound control response'); }
            if ($data['running'] && $data['server_pid'] === null) { throw new \UnexpectedValueException('Running process requires owned PID'); }
            return $data; // Process state only, never HTTP/Topic readiness.
        });
    }
    private static function absolute(string $path): bool { return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path); }
}
