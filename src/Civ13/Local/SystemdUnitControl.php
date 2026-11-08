<?php
declare(strict_types=1);
namespace Civ13\Local;
use React\Promise\PromiseInterface;

/**
 * Starts, stops and restarts a Linux game server that a systemd user unit owns
 * (see Civ13 scripts/README.md, "Supervised hosting"). The bot must run as the
 * unit's user. Only a fixed verb and the configured unit name reach systemctl;
 * start, stop and restart are queued with --no-block, so success means
 * "accepted" and status reports what systemd sees.
 */
final class SystemdUnitControl
{
    public const ACTIONS = ['status', 'start', 'stop', 'restart'];

    public function __construct(private string $unit, private string $runtimeDir, private $execute, private string $systemctl = '/usr/bin/systemctl')
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.@-]{0,80}\.service$/D', $unit)) { throw new \InvalidArgumentException('systemd unit must be a plain *.service name'); }
        if (!str_starts_with($runtimeDir, '/') || !is_dir($runtimeDir)) { throw new \InvalidArgumentException('systemd runtime_dir must be an existing absolute directory'); }
        if (!str_starts_with($systemctl, '/')) { throw new \InvalidArgumentException('systemctl path must be absolute'); }
        if (!is_callable($execute)) { throw new \InvalidArgumentException('Async executor required'); }
    }

    /** The user manager's runtime directory for the current process user. */
    public static function defaultRuntimeDir(): string
    {
        return '/run/user/'.(function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
    }

    /** @return string[] */
    public function argv(string $action): array
    {
        if (!in_array($action, self::ACTIONS, true)) { throw new \InvalidArgumentException('Unsupported systemd action'); }
        // AsyncProcess passes only PATH; the user bus is found through XDG_RUNTIME_DIR.
        $prefix = ['/usr/bin/env', 'XDG_RUNTIME_DIR='.$this->runtimeDir, $this->systemctl, '--user'];
        return $action === 'status'
            ? array_merge($prefix, ['show', '--property=ActiveState,SubState,MainPID', $this->unit])
            : array_merge($prefix, ['--no-block', $action, $this->unit]);
    }

    public function request(string $action): PromiseInterface
    {
        $promise = ($this->execute)($this->argv($action), 30);
        if (!$promise instanceof PromiseInterface) { throw new \LogicException('Async executor required'); }
        return $promise->then(function (array $result) use ($action): array {
            if (!is_int($result['exit_code'] ?? null) || !is_string($result['stdout'] ?? null)) { throw new \UnexpectedValueException('Invalid systemctl transport result'); }
            if ($result['exit_code'] !== 0) { throw new \RuntimeException("systemctl {$action} {$this->unit} failed (exit {$result['exit_code']})"); }
            if ($action !== 'status') { return ['accepted' => true, 'engine' => 'byond', 'action' => $action, 'state' => 'queued by systemd']; }
            $status = ['unit' => $this->unit];
            foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $line) {
                if (preg_match('/^(ActiveState|SubState|MainPID)=([A-Za-z0-9-]{0,32})$/D', $line, $match)) { $status[$match[1]] = $match[2]; }
            }
            if (!isset($status['ActiveState'])) { throw new \UnexpectedValueException('systemctl show gave no ActiveState'); }
            return $status;
        });
    }

    /** One line for Discord from a status answer. */
    public static function describe(array $status): string
    {
        $unit = (string) ($status['unit'] ?? 'unit');
        $state = (string) ($status['ActiveState'] ?? 'unknown');
        $sub = (string) ($status['SubState'] ?? '');
        $line = "Service {$unit} is {$state}".($sub !== '' && $sub !== $state ? " ({$sub})" : '');
        if (ctype_digit((string) ($status['MainPID'] ?? '')) && (int) $status['MainPID'] > 0) { $line .= ", DreamDaemon PID {$status['MainPID']}"; }
        return $line.'.';
    }
}
