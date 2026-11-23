<?php
declare(strict_types=1);
namespace Civ13\Local;
use React\Promise\PromiseInterface;

/**
 * Calls Civ13's scripts/shard-group/shard_group.py for one region of a shard
 * group, the control path for a world that runs as a region (for example the
 * TDM Barracks and battle worlds on civ13.com). Only a fixed action, the group
 * config and the region id reach the script; the group config names every
 * path and command. start, stop, restart and update are accepted within
 * seconds and finish in the script's detached worker, so success means
 * "accepted", and status reports progress.
 *
 * Answers are reshaped like gaia_host.py's, so callers treat both the same:
 * an accepted action resolves with accepted/action/operation, a refusal rejects
 * with "Refused: ...", and status resolves with this region's host status.
 * Every call names this region only, which also keeps the answer within the
 * worker's output bound.
 */
final class ShardRegionControl
{
    public const ACTIONS = ['status', 'start', 'stop', 'restart', 'update', 'kill'];

    public function __construct(private string $python, private string $script, private string $config, private string $region, private $execute)
    {
        foreach ([$python, $script, $config] as $path) {
            if (!self::absolute($path) || !is_file($path)) { throw new \InvalidArgumentException('shard_group needs existing absolute python, script and config paths'); }
        }
        // Interpreters are often links (venvs, distro alternatives); the script and its config are not.
        if (is_link($script) || is_link($config)) { throw new \InvalidArgumentException('shard_group script and config must not be links'); }
        if (!preg_match('/^[a-z0-9_-]{1,32}$/D', $region)) { throw new \InvalidArgumentException('shard_group region must be a plain region id'); }
        if (!is_callable($execute)) { throw new \InvalidArgumentException('Async executor required'); }
    }

    public function region(): string { return $this->region; }

    /** Resolves with the reshaped answer; rejects with the script's refusal text or a transport error. */
    public function request(string $action): PromiseInterface
    {
        if (!in_array($action, self::ACTIONS, true)) { throw new \InvalidArgumentException('Unsupported shard_group action'); }
        // One argv entry for the region keeps within the worker's eight-argument bound.
        $promise = ($this->execute)([$this->python, '-I', '-B', $this->script, '--config', $this->config, $action, '--region='.$this->region], 60);
        if (!$promise instanceof PromiseInterface) { throw new \LogicException('Async executor required'); }
        $region = $this->region;
        return $promise->then(static function (array $result) use ($action, $region): array {
            if (!is_int($result['exit_code'] ?? null) || !is_string($result['stdout'] ?? null)) { throw new \UnexpectedValueException('Invalid shard_group transport result'); }
            $data = json_decode(trim($result['stdout']), true, 32);
            if (!is_array($data) || !is_bool($data['ok'] ?? null)) { throw new \UnexpectedValueException('shard_group gave no JSON answer (exit '.$result['exit_code'].')'); }
            if (!$data['ok']) {
                $refused = self::regionRefusal($data, $region);
                throw new \RuntimeException('Refused: '.substr(is_string($refused) ? $refused : 'unknown reason', 0, 300));
            }
            if ($result['exit_code'] !== 0) { throw new \UnexpectedValueException('shard_group exit '.$result['exit_code'].' with ok=true'); }
            if ($action === 'status') { return self::regionStatus($data, $region); }
            // kill answers under "done"; the others under "accepted". Each maps region id => that region's answer.
            $answer = self::mutationReceipt($data, $action === 'kill' ? 'done' : 'accepted', $region);
            if ($action === 'kill') {
                if (($answer['killed'] ?? null) !== true) { throw new \UnexpectedValueException('shard_group did not kill '.$region); }
                return ['accepted' => true, 'action' => 'kill', 'region' => $region] + $answer;
            }
            if (($answer['accepted'] ?? null) !== true || (($answer['action'] ?? null) !== $action && !self::isFrozenUpdateStage($action, $answer))) {
                throw new \UnexpectedValueException('shard_group did not accept '.$action);
            }
            return ['region' => $region] + $answer;
        });
    }

    /** A definitive refusal must be scoped to this command, with no effects in the envelope. */
    public static function regionRefusal(array $data, string $region): string
    {
        foreach (['accepted', 'done'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== []) {
                throw new \UnexpectedValueException('Contradictory region refusal');
            }
        }
        $refused = $data['refused'] ?? $data['error'] ?? null;
        if (is_array($refused)) {
            $refused = count($refused) === 1 ? ($refused[$region] ?? null) : null;
        }
        if (!is_string($refused) || $refused === '') { throw new \UnexpectedValueException('Missing or mismatched region refusal'); }
        return $refused;
    }

    /** A region-scoped command must not claim effects or refusals for other regions. */
    public static function mutationReceipt(array $data, string $bucket, string $region): array
    {
        $other = $bucket === 'done' ? 'accepted' : 'done';
        foreach (['refused', $other] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== []) {
                throw new \UnexpectedValueException('Contradictory region receipt');
            }
        }
        return self::singleRegionRecord($data[$bucket] ?? null, $region);
    }

    public static function singleRegionRecord(mixed $records, string $region): array
    {
        $own = is_array($records) && count($records) === 1 ? ($records[$region] ?? null) : null;
        if (!is_array($own) || (array_key_exists('region', $own) && $own['region'] !== $region)) {
            throw new \UnexpectedValueException('Missing or mismatched region '.$region);
        }
        return $own;
    }

    /** The coordinator may queue or reuse a staged build instead of updating a frozen region immediately. */
    public static function isFrozenUpdateStage(string $requested, array $receipt): bool
    {
        if ($requested !== 'update' || ($receipt['accepted'] ?? null) !== true
            || ($receipt['action'] ?? null) !== 'stage' || ($receipt['state'] ?? null) !== 'asleep') {
            return false;
        }
        if (array_key_exists('already_staging', $receipt)) {
            return $receipt['already_staging'] === true && !array_key_exists('operation', $receipt);
        }
        return is_string($receipt['operation'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $receipt['operation']) === 1;
    }

    /** This region's host status in gaia_host's shape. */
    private static function regionStatus(array $data, string $region): array
    {
        $own = self::singleRegionRecord($data['regions'] ?? null, $region);
        unset($own['regions']);
        return $own;
    }

    /** Status text may reach Discord: keep labels allowlisted, never echo arbitrary host output. */
    public static function phaseLabel(mixed $phase, bool $stage = false): string
    {
        $labels = $stage ? [
            'accepted', 'checking for updates', 'current', 'ready', 'preparing', 'compiling', 'failed',
            'waiting for a requested build of another region', "waiting for another region's compile",
        ] : [
            'accepted', 'done', 'failed', 'killed', 'starting', 'started', 'stopping', 'stopped', 'building',
            'fetching', 'saving', 'compiling', 'compiled', 'pulling', 'checking save', 'backing up', 'switching',
            'rolling back', 'checking out', 'handed over', 'checking for updates', 'already on the latest build',
            'build is current', 'building the update beside the running world', 'waiting for the server to exit',
            'restarted onto the staged build', 'copying the staged build', 'booting the standby', 'loading the standby',
            'finishing the backup', 'installing the staged build', 'stopping without saving',
            'backing up the last completed save', 'stopped without saving', "waiting for another region's handover",
            'the running build predates handovers; saving, stopping and starting instead',
            'compiling (the compiler stopped without a result; trying again)',
        ];
        if (in_array($phase, $labels, true)) { return $phase; }
        if (!$stage && is_string($phase)) {
            if (preg_match('/^warning players \([0-9]{1,9} s\), then saving$/D', $phase) === 1) { return $phase; }
            if (preg_match('/^[a-z-]{1,32} got no reply in time \(/D', $phase) === 1) { return 'waiting for the world to answer'; }
        }
        return 'unknown';
    }

    /** One line for Discord from a status answer, led by the region's name. */
    public static function describe(array $status): string
    {
        // Letters, marks and digits in any script, cut to 64 characters, never mid-character.
        $name = is_string($status['name'] ?? null)
            ? preg_replace('/^(.{0,64}).*$/us', '$1', preg_replace('/[^\p{L}\p{M}\p{N} \-\'().]/u', '', $status['name']) ?? '') ?? ''
            : '';
        $state = $status['state'] ?? null;
        $listening = $status['listening'] ?? null;
        $parts = [match (true) {
            $state === 'asleep' || $state === 'cold' => GaiaHostControl::describe(['state' => $state]),
            $state === 'unknown' || !is_bool($listening) => 'Server status is unknown.',
            $listening => 'Server port is open.',
            default => 'Server port is closed.',
        }];
        $world = $status['world'] ?? null;
        if (is_array($world) && $state !== 'unknown' && $listening === true && $state !== 'asleep') {
            $parts[] = match ($world['ready'] ?? null) {
                true => 'World ready.',
                false => 'World not ready.',
                default => 'World readiness unknown.',
            };
            if (empty($world['error']) && is_int($world['clients'] ?? null) && $world['clients'] >= 0) { $parts[] = "{$world['clients']} players."; }
        }
        $operation = $status['operation'] ?? null;
        $action = $operation['action'] ?? null;
        if (is_string($action) && preg_match('/^[a-z][a-z-]{0,31}$/D', $action) === 1) {
            $parts[] = 'Last action: '.$action.' ('.self::phaseLabel($operation['phase'] ?? null).').';
        }
        $line = implode(' ', $parts);
        $map = is_string($status['region_view']['map'] ?? null) ? preg_replace('/[^\p{L}\p{M}\p{N} \-\'().]/u', '', substr($status['region_view']['map'], 0, 64)) : '';
        if ($map !== '' && $map !== null) { $line .= " Map: {$map}."; }
        return $name === '' ? $line : "{$name}: {$line}";
    }

    private static function absolute(string $path): bool { return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path); }
}
