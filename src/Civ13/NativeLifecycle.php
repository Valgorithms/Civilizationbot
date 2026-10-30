<?php

declare(strict_types=1);

namespace Civ13;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/** Fixed requests to an independently owned supervisor. Never executes or kills a process. */
final class NativeLifecycle
{
    public const PROTOCOL = 'civilizationbot.native-supervisor.v2';
    public const REQUEST_LIFETIME_SECONDS = 300;
    private array $binding;
    private array $pins;
    private string $directory;
    private string $bindingHash;

    public function __construct(private array $settings, array $server, string $repository, private LoopInterface $loop, private $clock = null, private $wallClock = null)
    {
        if (($settings['enabled'] ?? false) !== true) {
            throw new \LogicException('Native lifecycle adapter is disabled');
        }
        $this->clock ??= static fn () => hrtime(true);
        $this->wallClock ??= static fn () => time();
        if (($server['enabled'] ?? null) !== true || ($server['ip'] ?? null) !== '127.0.0.1' || (string) ($server['port'] ?? '') !== '1714') {
            throw new \InvalidArgumentException('Native lifecycle requires loopback TEST1714');
        }
        $root = self::directory($settings['supervisor_root'] ?? '');
        $runtime = self::directory($server['basedir'] ?? '');
        $repository = self::directory($repository);
        if (!str_starts_with($runtime, $root.DIRECTORY_SEPARATOR.'runs'.DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Explicit supervisor-owned run directory required');
        }
        $this->directory = self::directory($root.'/bot-control');
        $expected = $settings['expected'] ?? [];
        if (!is_array($expected) || array_keys($expected) !== ['revision', 'map', 'generation', 'dmb_sha256', 'rsc_sha256']) {
            throw new \InvalidArgumentException('Exact build, map and save binding required');
        }
        if (!is_string($expected['revision']) || !preg_match('/^[a-f0-9]{40}$/D', $expected['revision'])
            || !in_array($expected['map'], ['NOMADS_GAIA', 'NOMADS_EUROPE'], true)
            || !is_string($expected['generation']) || !preg_match('/^round-v2:[a-f0-9]{32}$/D', $expected['generation'])) {
            throw new \InvalidArgumentException('Invalid native build, map or save identity');
        }
        foreach (['dmb_sha256', 'rsc_sha256'] as $field) { self::digest($expected[$field]); }
        $this->pins = [];
        foreach (['software/supervisor.py' => 'supervisor_sha256', 'software/master_guard.py' => 'guard_sha256', 'supervisor.json' => 'config_sha256'] as $relative => $field) {
            self::digest($settings[$field] ?? null);
            $this->pins[$root.'/'.$relative] = $settings[$field];
        }
        $daemon = self::file($settings['daemon'] ?? '');
        self::digest($settings['daemon_sha256'] ?? null);
        $this->pins[$daemon] = $settings['daemon_sha256'];
        $this->pins[$runtime.'/civ13.dmb'] = $expected['dmb_sha256'];
        $this->pins[$runtime.'/civ13.rsc'] = $expected['rsc_sha256'];
        $this->binding = [
            'supervisor_root' => $root, 'runtime_directory' => $runtime,
            'repository' => $repository, 'branch' => 'master',
            'origin' => 'https://github.com/Civ13/Civ13.git', 'bind' => '127.0.0.1', 'port' => 1714,
            'daemon' => $daemon, 'expected' => $expected,
            'supervisor_sha256' => $settings['supervisor_sha256'],
            'guard_sha256' => $settings['guard_sha256'], 'config_sha256' => $settings['config_sha256'],
        ];
        $this->bindingHash = hash('sha256', json_encode($this->binding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->verifyPins();
        $config = self::read($root.'/supervisor.json');
        if (self::directory($config['root'] ?? '') !== $root || self::directory($config['repository'] ?? '') !== $repository
            || ($config['branch'] ?? null) !== 'master' || ($config['bind'] ?? null) !== '127.0.0.1'
            || ($config['port'] ?? null) !== 1714 || ($config['map'] ?? null) !== $expected['map']
            || self::file($config['daemon'] ?? '') !== $daemon || ($config['bot_control_protocol'] ?? null) !== self::PROTOCOL) {
            throw new \InvalidArgumentException('Supervisor does not implement the reviewed loopback native contract');
        }
    }

    /** Public protocol description for the separately reviewed supervisor; contains no credentials. */
    public function description(): array
    {
        return ['protocol' => self::PROTOCOL, 'binding' => $this->binding, 'binding_sha256' => $this->bindingHash];
    }

    /** No action, token load, world query, or request write; usable by operator preflight. */
    public function inspect(): array
    {
        $this->verifyPins();
        $root = $this->binding['supervisor_root'];
        if (file_exists($root.'/HALTED.json')) {
            throw new \RuntimeException('Supervisor is halted; operator reconciliation required');
        }
        if (file_exists($this->directory.'/reconcile-'.$this->bindingHash.'.json')) {
            throw new \RuntimeException('Saved generation advanced; reconcile the explicit profile before another operation');
        }
        $capability = self::read($this->directory.'/capability.json');
        $now = $this->wallTime();
        if (($capability['protocol'] ?? null) !== self::PROTOCOL || ($capability['binding_sha256'] ?? null) !== $this->bindingHash
            || !is_int($capability['heartbeat_unix'] ?? null) || $capability['heartbeat_unix'] < $now - 10
            || $capability['heartbeat_unix'] > $now + 2
            || !is_int($capability['sequence'] ?? null) || $capability['sequence'] < 0
            || !is_string($capability['epoch'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $capability['epoch'])
            || ($capability['master_verified'] ?? null) !== true || ($capability['busy'] ?? null) !== false) {
            throw new \RuntimeException('Missing, stale or incompatible supervisor capability');
        }
        if (!array_key_exists('process', $capability)) { throw new \RuntimeException('Supervisor process state is unknown'); }
        $this->process($capability['process']);
        return $capability;
    }

    /** The pending latch survives timeout, malformed results, failed operations and bot restart. */
    public function request(string $action): PromiseInterface
    {
        if (!in_array($action, ['start', 'save-stop', 'restart'], true)) {
            throw new \InvalidArgumentException('Unsupported native lifecycle action');
        }
        $capability = $this->inspect();
        if (($action === 'start') !== ($capability['process'] === null)) {
            throw new \RuntimeException('Native action does not match the owned process state');
        }
        $lock = $this->lock();
        if ($lock === null) { throw new \RuntimeException('Native lifecycle transition already in progress'); }
        $pending = $this->directory.'/pending.json';
        $request = $this->directory.'/request.json';
        $operation = bin2hex(random_bytes(16));
        $resultPath = $this->directory.'/result-'.$operation.'.json';
        $temporary = $this->directory.'/request-'.$operation.'.tmp';
        $used = $this->directory.'/used-'.$capability['epoch'].'-'.$capability['sequence'].'.json';
        try {
            clearstatcache();
            if (file_exists($pending) || is_link($pending) || file_exists($request) || is_link($request) || file_exists($used) || is_link($used)) {
                throw new \RuntimeException('Unresolved native operation; inspect supervisor evidence before retry');
            }
            // Recheck after serialization, before creating any request.
            $current = $this->inspect();
            if ($current['epoch'] !== $capability['epoch'] || $current['sequence'] !== $capability['sequence'] || $current['process'] !== $capability['process']) { throw new \RuntimeException('Supervisor epoch or owned process changed'); }
            $issued = $this->wallTime();
            if ($current['heartbeat_unix'] < $issued - 10 || $current['heartbeat_unix'] > $issued + 2) {
                throw new \RuntimeException('Supervisor capability expired before publication');
            }
            $packet = ['protocol' => self::PROTOCOL, 'operation' => $operation, 'action' => $action,
                'epoch' => $capability['epoch'], 'sequence' => $capability['sequence'], 'binding_sha256' => $this->bindingHash,
                'binding' => $this->binding, 'before' => $capability['process'],
                'issued_unix' => $issued, 'expires_unix' => $issued + self::REQUEST_LIFETIME_SECONDS];
            $bytes = json_encode($packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            self::create($pending, $bytes);
            self::create($temporary, $bytes);
            // Hard-link publication is exclusive: it cannot replace a concurrent request.
            if (!link($temporary, $request)) { throw new \RuntimeException('Native request publication uncertain; inspect pending operation'); }
            unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock); $lock = null;
            $deferred = new Deferred();
            $deadline = ($this->clock)() + 600 * 1000000000;
            $timer = $this->loop->addPeriodicTimer(0.25, function ($timer) use ($deferred, $deadline, $pending, $request, $resultPath, $packet, $bytes, $used): void {
                $completionLock = null;
                try {
                    clearstatcache();
                    if (!file_exists($resultPath)) {
                        if (($this->clock)() < $deadline) { return; }
                        throw new \RuntimeException('Native outcome unknown; pending operation retained; do not retry');
                    }
                    $completionLock = $this->lock();
                    if ($completionLock === null) {
                        if (($this->clock)() < $deadline) { return; }
                        throw new \RuntimeException('Native completion transition busy; pending operation retained');
                    }
                    $this->verifyPins();
                    $result = $this->result(self::read($resultPath), $packet);
                    if (file_exists($request) || self::bytes($pending) !== $bytes) {
                        throw new \RuntimeException('Supervisor request consumption or pending identity is unverified');
                    }
                    // Keep the supervisor's immutable result; clear only this exact successful pending record.
                    self::create($used, json_encode(['operation' => $packet['operation'], 'result_sha256' => hash_file('sha256', $resultPath)], JSON_THROW_ON_ERROR));
                    if ($packet['action'] !== 'start' && $result['saved_generation'] !== $this->binding['expected']['generation']) {
                        self::create($this->directory.'/reconcile-'.$this->bindingHash.'.json', json_encode([
                            'operation' => $packet['operation'], 'binding_sha256' => $this->bindingHash,
                            'saved_generation' => $result['saved_generation'],
                        ], JSON_THROW_ON_ERROR));
                    }
                    if (!unlink($pending)) { throw new \RuntimeException('Native result complete but pending acknowledgement failed'); }
                    $this->loop->cancelTimer($timer);
                    flock($completionLock, LOCK_UN); fclose($completionLock); $completionLock = null;
                    $deferred->resolve($result);
                } catch (\Throwable $error) {
                    $this->loop->cancelTimer($timer);
                    if (is_resource($completionLock)) { flock($completionLock, LOCK_UN); fclose($completionLock); }
                    $deferred->reject($error);
                }
            });
            return $deferred->promise();
        } catch (\Throwable $error) {
            // A request may already have been consumed. Never remove its persistent latch or retry.
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            throw $error;
        }
    }

    /** Held only for durable publication/completion transitions, never while waiting for the game. */
    private function lock(): mixed
    {
        $path = $this->directory.'/adapter.lock';
        if (is_link($path)) { throw new \RuntimeException('Unsafe native adapter lock'); }
        $lock = fopen($path, 'c+b');
        if ($lock === false) { throw new \RuntimeException('Native adapter lock unavailable'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }
        return $lock;
    }

    private function result(array $result, array $packet): array
    {
        foreach (['protocol', 'operation', 'action', 'epoch', 'sequence', 'binding_sha256', 'before', 'issued_unix', 'expires_unix'] as $key) {
            if (!array_key_exists($key, $result) || $result[$key] !== $packet[$key]) { throw new \UnexpectedValueException('Unbound native supervisor result'); }
        }
        if (($result['outcome'] ?? null) !== 'complete' || ($result['master_verified'] ?? null) !== true
            || ($result['ready'] ?? null) !== ($packet['action'] !== 'save-stop') || !array_key_exists('after', $result)) {
            throw new \RuntimeException('Native operation rejected, incomplete or unknown; inspect retained evidence');
        }
        $this->process($result['after']);
        if ($packet['action'] === 'save-stop' ? $result['after'] !== null : $result['after'] === null) {
            throw new \UnexpectedValueException('Native result process state mismatch');
        }
        if ($packet['action'] !== 'start') {
            if (($result['exit_acknowledged'] ?? null) !== true || ($result['save_complete'] ?? null) !== true
                || !is_string($result['saved_generation'] ?? null) || !preg_match('/^round-v2:[a-f0-9]{32}$/D', $result['saved_generation'])) {
                throw new \UnexpectedValueException('Save-safe shutdown evidence is incomplete');
            }
            self::digest($result['save_receipt_sha256'] ?? null);
        }
        if ($packet['action'] === 'restart' && $result['after']['creation_filetime'] === $packet['before']['creation_filetime']) {
            throw new \UnexpectedValueException('Restart did not establish a fresh owned process');
        }
        if ($packet['action'] !== 'save-stop' && (($result['restored_generation'] ?? null) !== ($packet['action'] === 'restart' ? $result['saved_generation'] : $this->binding['expected']['generation']))) {
            throw new \UnexpectedValueException('Replacement did not restore the intended save');
        }
        return $result;
    }

    private function wallTime(): int
    {
        $now = ($this->wallClock)();
        if (!is_int($now) || $now < 0 || $now > PHP_INT_MAX - self::REQUEST_LIFETIME_SECONDS) {
            throw new \RuntimeException('Invalid native request clock');
        }
        return $now;
    }

    private function process(mixed $process): void
    {
        if ($process === null) { return; }
        if (!is_array($process) || !is_int($process['pid'] ?? null) || $process['pid'] < 1
            || !is_string($process['creation_filetime'] ?? null) || !preg_match('/^[1-9][0-9]{0,19}$/D', $process['creation_filetime'])
            || ($process['executable'] ?? null) !== $this->binding['daemon']
            || ($process['working_directory'] ?? null) !== $this->binding['runtime_directory']
            || ($process['dmb_sha256'] ?? null) !== $this->binding['expected']['dmb_sha256']
            || ($process['rsc_sha256'] ?? null) !== $this->binding['expected']['rsc_sha256']) {
            throw new \UnexpectedValueException('Unverified native process identity');
        }
    }

    private function verifyPins(): void
    {
        clearstatcache();
        self::directory($this->directory);
        foreach ($this->pins as $path => $hash) {
            if (self::file($path) !== realpath($path) || !hash_equals($hash, hash_file('sha256', $path))) {
                throw new \RuntimeException('Native supervisor, guard, configuration or build changed; review required');
            }
        }
    }

    private static function digest(mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D', $value)) { throw new \InvalidArgumentException('Explicit SHA-256 required'); }
    }

    private static function path(string $path): string
    {
        if (!PortableProfile::absolute($path) || str_contains($path, "\0")) { throw new \InvalidArgumentException('Absolute local native path required'); }
        $resolved = realpath($path);
        if ($resolved === false || str_starts_with($resolved, '\\\\')) { throw new \InvalidArgumentException('Existing local native path required'); }
        for ($part = $path; dirname($part) !== $part; $part = dirname($part)) {
            if (is_link($part)) { throw new \InvalidArgumentException('Linked native paths are unsupported'); }
        }
        return $resolved;
    }

    private static function directory(string $path): string
    {
        $resolved = self::path($path);
        if (!is_dir($resolved)) { throw new \InvalidArgumentException('Native directory required'); }
        return $resolved;
    }

    private static function file(string $path): string
    {
        $resolved = self::path($path);
        if (!is_file($resolved)) { throw new \InvalidArgumentException('Native file required'); }
        return $resolved;
    }

    private static function bytes(string $path): string
    {
        self::file($path);
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new \RuntimeException('Native record unavailable'); }
        try { $bytes = stream_get_contents($stream, 16385); } finally { fclose($stream); }
        if ($bytes === false || strlen($bytes) > 16384) { throw new \RuntimeException('Native record unavailable or oversized'); }
        return $bytes;
    }

    private static function read(string $path): array
    {
        $value = json_decode(self::bytes($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) { throw new \RuntimeException('Native object record required'); }
        return $value;
    }

    private static function create(string $path, string $bytes): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false) { throw new \RuntimeException('Native record already exists or is unavailable'); }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
                throw new \RuntimeException('Native request persistence failed; inspect pending operation');
            }
        } finally { fclose($stream); }
    }
}
