<?php
declare(strict_types=1);
namespace Civ13\Local;
use React\Promise\PromiseInterface;

/** Opt-in worker adapter. No Discord routes, native fallback, shell or automatic retry. */
final class OpenDreamControl
{
    private array $maps;
    private string $profileDigest;
    public function requiresOperationId(): bool { return $this->lifecycleSchema !== null; }
    public function __construct(private array $server, private string $python, private string $controller, private string $profile, private $execute, private bool $enabled = false, private ?string $lifecycleSchema = null)
    {
        if ($lifecycleSchema !== null && $lifecycleSchema !== 'civ13.lifecycle.v1') { throw new \InvalidArgumentException('Unsupported lifecycle schema'); }
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
    public function request(string $action, ?string $map = null, ?string $operationId = null): PromiseInterface
    {
        if (!$this->enabled) { throw new \LogicException('Local lifecycle controls disabled'); }
        if (is_link($this->profile) || hash_file('sha256', $this->profile) !== $this->profileDigest) { throw new \LogicException('Controller profile changed; explicit review required'); }
        if (!in_array($action, ['start', 'stop', 'restart', 'status', 'topic-status', 'mapswap'], true) || ($action === 'mapswap' ? !isset($this->maps[$map ?? '']) : $map !== null)) { throw new \InvalidArgumentException('Unsupported lifecycle request'); }
        $argv = [$this->python, $this->controller, '--profile', $this->profile, $action];
        if ($map !== null) { $argv[] = $map; }
        $mutation = in_array($action, ['start', 'stop', 'restart', 'mapswap'], true);
        if ($operationId !== null && (!$mutation || $this->lifecycleSchema === null)) { throw new \InvalidArgumentException('Operation identity requires versioned mutation'); }
        if ($mutation && $this->lifecycleSchema !== null) {
            $operationId ??= bin2hex(random_bytes(16));
            self::identity($operationId); $argv[] = '--operation-id'; $argv[] = $operationId;
        }
        $promise = ($this->execute)($argv, $action === 'start' ? 25 : 210);
        if (!$promise instanceof PromiseInterface) { throw new \LogicException('Async executor required'); }
        return $promise->then(function (array $result) use ($action, $map, $operationId): array {
            if (!is_int($result['exit_code'] ?? null) || !is_string($result['stdout'] ?? null) || strlen($result['stdout']) > 4096) { throw new \UnexpectedValueException('Invalid control transport result'); }
            $data = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_bool($data['ok'] ?? null)) { throw new \UnexpectedValueException('Invalid control response'); }
            if ($operationId !== null) {
                if (($data['id'] ?? null) !== $operationId || !is_array($data['receipt'] ?? null)) { throw new \UnexpectedValueException('Missing bound lifecycle receipt'); }
                $this->receipt($data['receipt'], $operationId, $action, $map);
                if ($data['ok'] ? $data['receipt']['outcome'] !== 'committed' : $data['receipt']['outcome'] !== 'pending' || ($data['error'] ?? null) !== 'operation_pending') { throw new \UnexpectedValueException('Lifecycle outcome mismatch'); }
                if ($data['ok']) {
                    $original = $data; unset($original['id'], $original['receipt']);
                    if ($original !== $data['receipt']['result']) { throw new \UnexpectedValueException('Terminal projection differs from receipt'); }
                }
            }
            if (!$data['ok']) { if ($result['exit_code'] !== 1 || !is_string($data['error'] ?? null)) { throw new \UnexpectedValueException('Invalid failure response'); } throw new \RuntimeException($operationId === null ? 'Local controller rejected request' : 'Lifecycle outcome pending; inspect operation '.$operationId.' without replay'); }
            if ($action === 'topic-status') {
                $keys = array_keys($data); sort($keys);
                if ($keys !== ['ok', 'status', 'topic_port'] || $result['exit_code'] !== 0 || $data['topic_port'] !== $this->server['topic_port'] || !is_array($data['status']) || array_is_list($data['status'])) { throw new \UnexpectedValueException('Invalid Topic response'); }
                return $data;
            }
            $keys = array_keys($data); sort($keys); $allowed = ['engine', 'game_port', 'id', 'ok', 'running', 'runtime_dir', 'server_pid', 'topic_port'];
            if ($operationId !== null) { $allowed[] = 'receipt'; }
            if (array_diff($keys, $allowed) || $result['exit_code'] !== 0 || ($data['engine'] ?? null) !== 'opendream' || !is_bool($data['running'] ?? null) || ($data['game_port'] ?? null) !== $this->server['gameplay_port'] || ($data['topic_port'] ?? null) !== $this->server['topic_port'] || realpath($data['runtime_dir'] ?? '') !== realpath($this->server['runtime_dir']) || !array_key_exists('server_pid', $data) || ($data['server_pid'] !== null && (!is_int($data['server_pid']) || $data['server_pid'] <= 0))) { throw new \UnexpectedValueException('Unbound control response'); }
            if ($data['running'] && $data['server_pid'] === null) { throw new \UnexpectedValueException('Running process requires owned PID'); }
            return $data; // Process state only, never HTTP/Topic readiness.
        }, static function (\Throwable $error) use ($operationId): never { throw new \RuntimeException($operationId === null ? 'Control transport failed; outcome unknown' : 'Lifecycle outcome unknown; inspect operation '.$operationId.' without replay', 0, $error); });
    }

    /** Fresh read-only receipt query. not_found and pending never authorize mutation replay. */
    public function recover(string $operationId, string $action, ?string $map = null): PromiseInterface
    {
        if (!$this->enabled || $this->lifecycleSchema === null) { throw new \LogicException('Versioned recovery disabled'); }
        self::identity($operationId);
        if (!in_array($action, ['start', 'stop', 'restart', 'mapswap'], true) || ($action === 'mapswap' ? !isset($this->maps[$map ?? '']) : $map !== null)) { throw new \InvalidArgumentException('Invalid recovery binding'); }
        if (is_link($this->profile) || hash_file('sha256', $this->profile) !== $this->profileDigest) { throw new \LogicException('Controller profile changed'); }
        $promise = ($this->execute)([$this->python, $this->controller, '--profile', $this->profile, 'operation-status', '--operation-id', $operationId], 10);
        if (!$promise instanceof PromiseInterface) { throw new \LogicException('Async executor required'); }
        return $promise->then(function (array $result) use ($operationId, $action, $map): array {
            if (($result['exit_code'] ?? null) !== 0 || !is_string($result['stdout'] ?? null) || strlen($result['stdout']) > 4096) { throw new \UnexpectedValueException('Invalid recovery transport'); }
            $data = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new \UnexpectedValueException('Invalid recovery object'); }
            $keys = array_keys($data); sort($keys);
            if ($keys !== ['ok', 'operation_status', 'receipt', 'schema'] || $data['ok'] !== true || $data['schema'] !== $this->lifecycleSchema || !in_array($data['operation_status'], ['pending', 'committed', 'not_found'], true)) { throw new \UnexpectedValueException('Invalid recovery DTO'); }
            if ($data['operation_status'] === 'not_found') { if ($data['receipt'] !== null) { throw new \UnexpectedValueException('Unexpected receipt'); } }
            else { if (!is_array($data['receipt'])) { throw new \UnexpectedValueException('Missing receipt'); } $this->receipt($data['receipt'], $operationId, $action, $map); if ($data['receipt']['outcome'] !== $data['operation_status']) { throw new \UnexpectedValueException('Recovery outcome mismatch'); } }
            return $data; // Query completed, not game readiness or permission to retry.
        });
    }

    private static function identity(string $id): void { if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id)) { throw new \InvalidArgumentException('Invalid operation identity'); } }

    private function receipt(array $receipt, string $id, string $action, ?string $map): void
    {
        $expected = ['schema', 'operation_id', 'binding_digest', 'request', 'outcome', 'result'];
        if (($receipt['outcome'] ?? null) === 'committed') { $expected[] = 'launch_identity'; }
        $keys = array_keys($receipt); sort($keys); sort($expected);
        $body = ['action' => $action, 'map_id' => $map, 'origin' => 'cli', 'profile_digest' => $this->profileDigest];
        $request = $receipt['request'] ?? null; if (is_array($request)) { ksort($request); }
        if ($keys !== $expected || ($receipt['schema'] ?? null) !== $this->lifecycleSchema || ($receipt['operation_id'] ?? null) !== $id || $request !== $body || !hash_equals(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), is_string($receipt['binding_digest'] ?? null) ? $receipt['binding_digest'] : '')) { throw new \UnexpectedValueException('Unbound lifecycle receipt'); }
        if ($receipt['outcome'] === 'pending') { if ($receipt['result'] !== null) { throw new \UnexpectedValueException('Pending result cannot claim completion'); } return; }
        if ($receipt['outcome'] !== 'committed' || !is_array($receipt['result']) || ($receipt['result']['ok'] ?? null) !== true) { throw new \UnexpectedValueException('Invalid terminal receipt'); }
        $result = $receipt['result']; $allowed = ['ok', 'engine', 'running', 'game_port', 'topic_port', 'runtime_dir', 'server_pid'];
        if (array_diff(array_keys($result), $allowed) || ($result['engine'] ?? null) !== 'opendream' || !is_bool($result['running'] ?? null) || ($result['game_port'] ?? null) !== $this->server['gameplay_port'] || ($result['topic_port'] ?? null) !== $this->server['topic_port'] || !is_string($result['runtime_dir'] ?? null) || realpath($result['runtime_dir']) !== realpath($this->server['runtime_dir']) || !array_key_exists('server_pid', $result) || ($result['server_pid'] !== null && (!is_int($result['server_pid']) || $result['server_pid'] <= 0)) || ($result['running'] && $result['server_pid'] === null)) { throw new \UnexpectedValueException('Unbound terminal process result'); }
        $launch = $receipt['launch_identity'];
        if ($launch !== null) {
            if (!is_array($launch)) { throw new \UnexpectedValueException('Invalid launch identity'); }
            $keys = array_keys($launch); sort($keys);
            if ($keys !== ['build_id', 'instance_id', 'map_id', 'profile_digest', 'ready', 'schema', 'server_pid'] || $launch['schema'] !== 'civ13.launch.v1' || $launch['profile_digest'] !== $this->profileDigest || $launch['ready'] !== false || !is_string($launch['instance_id']) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $launch['instance_id']) || $launch['server_pid'] !== $result['server_pid']) { throw new \UnexpectedValueException('Invalid launch ownership projection'); }
            foreach (['map_id', 'build_id'] as $field) { if ($launch[$field] !== null && (!is_string($launch[$field]) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $launch[$field]))) { throw new \UnexpectedValueException('Invalid launch revision'); } }
        }
    }
    private static function absolute(string $path): bool { return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path); }
}
