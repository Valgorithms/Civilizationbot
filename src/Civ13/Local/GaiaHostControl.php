<?php
declare(strict_types=1);
namespace Civ13\Local;
use React\Promise\PromiseInterface;

/**
 * Calls Civ13's scripts/gaia-host/gaia_host.py, the one control path for a
 * Windows server started from a scheduled task. Only the action name is passed;
 * the script's config file names every path and command. start, stop, restart
 * and update are accepted within seconds and finish in the script's own worker,
 * so success here means "accepted", and status reports progress.
 */
final class GaiaHostControl
{
    public const ACTIONS = ['status', 'start', 'stop', 'restart', 'update'];

    public function __construct(private string $python, private string $script, private string $config, private $execute)
    {
        foreach ([$python, $script, $config] as $path) {
            if (!self::absolute($path) || !is_file($path)) { throw new \InvalidArgumentException('gaia_host needs existing absolute python, script and config paths'); }
        }
        // Interpreters are often links (venvs, distro alternatives); the script and its config are not.
        if (is_link($script) || is_link($config)) { throw new \InvalidArgumentException('gaia_host script and config must not be links'); }
        if (!is_callable($execute)) { throw new \InvalidArgumentException('Async executor required'); }
    }

    /** Resolves with the script's JSON answer; rejects with its refusal text or a transport error. */
    public function request(string $action): PromiseInterface
    {
        if (!in_array($action, self::ACTIONS, true)) { throw new \InvalidArgumentException('Unsupported gaia_host action'); }
        $promise = ($this->execute)([$this->python, '-I', '-B', $this->script, '--config', $this->config, $action], 60);
        if (!$promise instanceof PromiseInterface) { throw new \LogicException('Async executor required'); }
        return $promise->then(static function (array $result) use ($action): array {
            if (!is_int($result['exit_code'] ?? null) || !is_string($result['stdout'] ?? null)) { throw new \UnexpectedValueException('Invalid gaia_host transport result'); }
            $data = json_decode(trim($result['stdout']), true, 16);
            if (!is_array($data) || !is_bool($data['ok'] ?? null)) { throw new \UnexpectedValueException('gaia_host gave no JSON answer (exit '.$result['exit_code'].')'); }
            if (!$data['ok']) { throw new \RuntimeException('Refused: '.substr((string) ($data['refused'] ?? $data['error'] ?? 'unknown reason'), 0, 300)); }
            if ($result['exit_code'] !== 0) { throw new \UnexpectedValueException('gaia_host exit '.$result['exit_code'].' with ok=true'); }
            if ($action !== 'status' && (($data['accepted'] ?? null) !== true || ($data['action'] ?? null) !== $action)) { throw new \UnexpectedValueException('gaia_host did not accept '.$action); }
            return $data;
        });
    }

    /** One line for Discord from a status answer. */
    public static function describe(array $status): string
    {
        $parts = [match ($status['state'] ?? null) {
            'asleep' => 'Server is asleep (frozen in memory; a join or travel wakes it).',
            'cold' => 'Server is asleep (stopped after its last save; a join or travel starts it).',
            default => ($status['listening'] ?? false) ? 'Server port is open.' : 'Server is not running.',
        }];
        $world = $status['world'] ?? null;
        if (is_array($world)) {
            $parts[] = ($world['ready'] ?? false) ? 'World ready' : 'World not ready';
            if (is_int($world['clients'] ?? null)) { $parts[] = "{$world['clients']} players"; }
            if (is_string($world['error'] ?? null) && $world['error'] !== '') { $parts[] = 'error: '.substr($world['error'], 0, 120); }
        }
        $operation = $status['operation'] ?? null;
        if (is_array($operation) && isset($operation['action'], $operation['phase'])) {
            $line = "Last action: {$operation['action']} ({$operation['phase']})";
            if (is_string($operation['error'] ?? null) && $operation['error'] !== '') { $line .= ': '.substr($operation['error'], 0, 200); }
            $parts[] = $line;
        }
        $line = implode(' ', array_map(static fn ($part) => str_ends_with($part, '.') ? $part : $part.'.', $parts));
        // A region host (Civ13 scripts/shard-group) also lists every region of its group.
        $regions = [];
        foreach (is_array($status['regions'] ?? null) ? array_slice($status['regions'], 0, 16) : [] as $region) {
            if (!is_array($region) || !is_string($region['name'] ?? null) || !is_string($region['state'] ?? null)) { continue; }
            $name = substr(preg_replace('/[^\w \-\'().]/u', '', $region['name']) ?? '', 0, 64);
            $state = preg_replace('/[^a-z]/', '', $region['state']) ?? '';
            $regions[] = "{$name}: {$state}".(is_int($region['players'] ?? null) ? ", {$region['players']} players" : '');
        }
        return $regions === [] ? $line : $line."\nRegions: ".implode('; ', $regions).'.';
    }

    private static function absolute(string $path): bool { return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path); }
}
