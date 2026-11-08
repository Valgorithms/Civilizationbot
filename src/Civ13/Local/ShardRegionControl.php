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
                $refused = $data['refused'] ?? $data['error'] ?? 'unknown reason';
                if (is_array($refused)) { $refused = $refused[$region] ?? reset($refused) ?: 'unknown reason'; }
                throw new \RuntimeException('Refused: '.substr(is_string($refused) ? $refused : 'unknown reason', 0, 300));
            }
            if ($result['exit_code'] !== 0) { throw new \UnexpectedValueException('shard_group exit '.$result['exit_code'].' with ok=true'); }
            if ($action === 'status') { return self::regionStatus($data, $region); }
            // kill answers under "done"; the others under "accepted". Each maps region id => that region's answer.
            $answers = $action === 'kill' ? ($data['done'] ?? null) : ($data['accepted'] ?? null);
            $answer = is_array($answers) ? ($answers[$region] ?? null) : null;
            if (!is_array($answer)) {
                $refused = is_array($data['refused'] ?? null) ? ($data['refused'][$region] ?? null) : null;
                if (is_string($refused)) { throw new \RuntimeException('Refused: '.substr($refused, 0, 300)); }
                throw new \UnexpectedValueException('shard_group did not accept '.$action.' for '.$region);
            }
            if ($action === 'kill') {
                if (($answer['killed'] ?? null) !== true) { throw new \UnexpectedValueException('shard_group did not kill '.$region); }
                return ['accepted' => true, 'action' => 'kill', 'region' => $region] + $answer;
            }
            if (($answer['accepted'] ?? null) !== true || ($answer['action'] ?? null) !== $action) { throw new \UnexpectedValueException('shard_group did not accept '.$action); }
            return $answer + ['region' => $region];
        });
    }

    /** This region's host status in gaia_host's shape. */
    private static function regionStatus(array $data, string $region): array
    {
        $own = is_array($data['regions'] ?? null) ? ($data['regions'][$region] ?? null) : null;
        if (!is_array($own)) { throw new \UnexpectedValueException('shard_group status has no region '.$region); }
        unset($own['regions']);
        return $own;
    }

    /** One line for Discord from a status answer, led by the region's name. */
    public static function describe(array $status): string
    {
        // Letters, marks and digits in any script, cut to 64 characters, never mid-character.
        $name = is_string($status['name'] ?? null)
            ? preg_replace('/^(.{0,64}).*$/us', '$1', preg_replace('/[^\p{L}\p{M}\p{N} \-\'().]/u', '', $status['name']) ?? '') ?? ''
            : '';
        $line = GaiaHostControl::describe($status);
        $map = is_string($status['region_view']['map'] ?? null) ? preg_replace('/[^\p{L}\p{M}\p{N} \-\'().]/u', '', substr($status['region_view']['map'], 0, 64)) : '';
        if ($map !== '' && $map !== null) { $line .= " Map: {$map}."; }
        return $name === '' ? $line : "{$name}: {$line}";
    }

    private static function absolute(string $path): bool { return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path); }
}
