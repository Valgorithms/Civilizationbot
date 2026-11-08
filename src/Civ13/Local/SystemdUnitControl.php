<?php
declare(strict_types=1);
namespace Civ13\Local;
use React\Promise\PromiseInterface;

/**
 * Starts, stops and restarts a Linux game server that a systemd unit owns
 * (see Civ13 scripts/README.md, "Supervised hosting"). Only a fixed verb and
 * the configured unit name reach systemctl; start, stop and restart are queued
 * with --no-block, so success means "accepted" and status reports what systemd
 * sees.
 *
 * scope "system" (default) controls a system unit. A bot that is not root runs
 * the three verbs through `sudo -n`, which needs a sudoers rule naming exactly
 * those commands; status never uses sudo. scope "user" controls a unit of the
 * bot user's own manager, found through runtime_dir.
 */
final class SystemdUnitControl
{
    public const ACTIONS = ['status', 'start', 'stop', 'restart'];

    public function __construct(
        private string $unit,
        private $execute,
        private string $scope = 'system',
        private ?string $runtimeDir = null,
        private ?bool $sudo = null,
        private string $systemctl = '/usr/bin/systemctl',
        private string $sudoPath = '/usr/bin/sudo',
    ) {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.@-]{0,80}\.service$/D', $unit)) { throw new \InvalidArgumentException('systemd unit must be a plain *.service name'); }
        if (!in_array($scope, ['system', 'user'], true)) { throw new \InvalidArgumentException('systemd scope must be system or user'); }
        if (!str_starts_with($systemctl, '/') || !str_starts_with($sudoPath, '/')) { throw new \InvalidArgumentException('systemctl and sudo paths must be absolute'); }
        if ($scope === 'user') {
            $this->runtimeDir ??= '/run/user/'.self::uid();
            if (!str_starts_with($this->runtimeDir, '/') || !is_dir($this->runtimeDir)) { throw new \InvalidArgumentException('systemd runtime_dir must be an existing absolute directory'); }
        }
        $this->sudo ??= $scope === 'system' && self::uid() !== 0;
        if (!is_callable($execute)) { throw new \InvalidArgumentException('Async executor required'); }
    }

    private static function uid(): int { return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(); }

    /** @return string[] */
    public function argv(string $action): array
    {
        if (!in_array($action, self::ACTIONS, true)) { throw new \InvalidArgumentException('Unsupported systemd action'); }
        if ($this->scope === 'user') {
            // AsyncProcess passes only PATH; the user bus is found through XDG_RUNTIME_DIR.
            $prefix = ['/usr/bin/env', 'XDG_RUNTIME_DIR='.$this->runtimeDir, $this->systemctl, '--user'];
        } else {
            $prefix = ($this->sudo && $action !== 'status') ? [$this->sudoPath, '-n', $this->systemctl] : [$this->systemctl];
        }
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
