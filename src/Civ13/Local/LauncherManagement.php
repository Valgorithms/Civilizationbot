<?php
declare(strict_types=1);
namespace Civ13\Local;

use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Opt-in, per-region Discord facade. Never accepts a shell, path or group selector. */
final class LauncherManagement
{
    public const ACTIONS = ['status', 'start', 'stop', 'restart', 'update', 'kill', 'stage', 'handover', 'freeze', 'thaw', 'force-restart'];
    private array $pending = [];
    private bool $busy = false;
    private bool $uncertain = false;

    public function __construct(private array $policy, private string $python, private string $script,
        private string $config, private string $region, private $execute, private $clock = null)
    {
        if (($policy['enabled'] ?? null) !== true || !is_callable($execute)) { throw new \InvalidArgumentException('Management requires explicit enablement and executor'); }
        foreach (['guild_id'] as $key) { self::identity($policy[$key] ?? null); }
        foreach (['channel_ids', 'operator_ids', 'actions'] as $key) {
            if (!is_array($policy[$key] ?? null) || !array_is_list($policy[$key]) || count($policy[$key]) < 1 || count($policy[$key]) > 64) { throw new \InvalidArgumentException('Explicit bounded management allowlists required'); }
        }
        foreach (array_merge($policy['channel_ids'], $policy['operator_ids']) as $id) { self::identity($id); }
        foreach ($policy['actions'] as $action) { if (!in_array($action, self::ACTIONS, true)) { throw new \InvalidArgumentException('Unsupported management capability'); } }
        foreach ([$python, $script, $config] as $path) {
            if (!(str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) || !is_file($path)) { throw new \InvalidArgumentException('Existing absolute controller paths required'); }
        }
        if (is_link($script) || is_link($config) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $region)) { throw new \InvalidArgumentException('Invalid management controller or region'); }
        $this->clock ??= static fn(): int => time();
    }

    private static function identity(mixed $id): void
    {
        if (!is_string($id) || !preg_match('/^[0-9]{1,22}$/D', $id)) { throw new \InvalidArgumentException('Explicit Discord snowflake required'); }
    }

    /** Called for every message, including confirmation. Raw controller output never goes to Discord. */
    public function handle(string $actor, string $guild, string $channel, string $input, bool $automated = false): PromiseInterface
    {
        if ($automated || $guild !== $this->policy['guild_id'] || !in_array($channel, $this->policy['channel_ids'], true) || !in_array($actor, $this->policy['operator_ids'], true)) {
            return resolve('Host management is not authorized here for this user.');
        }
        $now = ($this->clock)();
        foreach ($this->pending as $id => $request) { if ($request['expires'] <= $now) { unset($this->pending[$id]); } }
        $key = $actor.':'.$guild.':'.$channel;
        $parts = preg_split('/\s+/', trim($input));
        if ($input === '' || $input === 'help') { return resolve('Enabled region actions: '.implode(', ', $this->policy['actions']).'. Mutations require a one-use confirmation within 60 seconds.'); }
        if ($input === 'cancel') { unset($this->pending[$key]); return resolve('Pending confirmation canceled. This does not cancel a submitted host operation.'); }
        $confirm = ($parts[0] ?? '') === 'confirm';
        $action = $confirm ? ($parts[1] ?? '') : ($parts[0] ?? '');
        if (!in_array($action, $this->policy['actions'], true) || count($parts) !== ($confirm ? 3 : 1)) { return resolve('Unsupported or disabled action. Use manage help; arbitrary arguments are not supported.'); }
        if ($this->busy || ($this->uncertain && $action !== 'status')) { return resolve('Management is busy or an earlier outcome is unknown. Inspect the host receipt locally before retrying; no automatic retry will occur.'); }
        if ($action !== 'status') {
            if (!$confirm) {
                $token = bin2hex(random_bytes(12));
                $this->pending[$key] = ['action' => $action, 'token' => $token, 'expires' => $now + 60];
                $warning = match ($action) {
                    'kill', 'force-restart' => 'Unsaved progress can be lost. Recovery uses the last completed save.',
                    'stage' => 'Builds a revision which may automatically install while this world is idle or asleep.',
                    'start', 'restart', 'update', 'handover' => 'May fetch, compile, install or start a new revision and disrupt players.',
                    'stop' => 'Saves and stops this world; connected players will be disconnected.',
                    'freeze' => 'Suspends the world if the host safety checks permit it.',
                    'thaw' => 'Resumes the world; a cold world starts from its save and may refresh its build.',
                    default => 'Changes this world.',
                };
                return resolve("Region {$this->region}: {$action}. {$warning} Confirm with: manage confirm {$action} {$token} (use this server's command prefix). Expires in 60 seconds.");
            }
            $pending = $this->pending[$key] ?? null;
            if (!$pending || $pending['action'] !== $action || !hash_equals($pending['token'], $parts[2])) { return resolve('Confirmation is invalid, expired or already used.'); }
            unset($this->pending[$key]); // consume before any process can start, including failure
        } elseif ($confirm) { return resolve('Status does not require confirmation.'); }
        $this->busy = true;
        try {
            $promise = ($this->execute)([$this->python, '-I', '-B', $this->script, '--config', $this->config, $action, '--region='.$this->region], $action === 'force-restart' ? 210 : 60);
            if (!$promise instanceof PromiseInterface) { throw new \RuntimeException('Executor did not return a promise'); }
            return $promise->then(fn(array $result): string => $this->answer($action, $result))->then(
                function (string $line): string { $this->busy = false; return $line; },
                function (\Throwable $error) use ($action): string { return $this->unknown($action); }
            );
        } catch (\Throwable $error) { return resolve($this->unknown($action)); }
    }

    private function unknown(string $action): string
    {
        $this->busy = false;
        if ($action !== 'status') { $this->uncertain = true; }
        return 'No verified host result. Outcome may be unknown; inspect the local host receipt before retrying. New mutations are blocked for this bot instance after an uncertain mutation. No raw logs are disclosed.';
    }

    private function answer(string $action, array $result): string
    {
        if (!is_int($result['exit_code'] ?? null) || !is_string($result['stdout'] ?? null)) { throw new \UnexpectedValueException('Invalid transport'); }
        $data = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_bool($data['ok'] ?? null)) { throw new \UnexpectedValueException('Invalid host envelope'); }
        // A refusal may follow partial effects (e.g. force-restart killed a process before start failed).
        if (!$data['ok']) {
            if ($action !== 'status') { $this->uncertain = true; }
            return 'Host refused the request. Inspect the local receipt for its reason and any partial effects; do not assume nothing changed. Further mutations are blocked after a refused mutation.';
        }
        if ($result['exit_code'] !== 0) { throw new \UnexpectedValueException('Inconsistent exit'); }
        if ($action === 'status') {
            $own = $data['regions'][$this->region] ?? null;
            if (!is_array($own)) { throw new \UnexpectedValueException('Missing region'); }
            $state = in_array($own['state'] ?? '', ['awake', 'asleep', 'cold', 'stopped', 'frozen', 'running'], true) ? $own['state'] : 'unknown';
            $clients = $own['world']['clients'] ?? null;
            $players = is_int($clients) && $clients >= 0 ? (string)$clients : 'unknown';
            $phase = $own['operation']['phase'] ?? null;
            $phase = in_array($phase, ['accepted', 'done', 'failed', 'starting', 'started', 'stopping', 'stopped', 'building', 'fetching', 'saving', 'compiling', 'compiled', 'pulling', 'checking save', 'backing up', 'switching', 'rolling back', 'checking out', 'handed over'], true) ? $phase : 'unknown';
            $stage = $own['stage']['phase'] ?? null;
            $stage = in_array($stage, ['accepted', 'checking for updates', 'current', 'ready', 'preparing', 'compiling', 'failed'], true) ? $stage : 'unknown';
            $pending = is_bool($own['stage']['pending'] ?? null) ? ($own['stage']['pending'] ? 'yes' : 'no') : 'unknown';
            $busy = is_bool($own['busy'] ?? null) ? ($own['busy'] ? 'yes' : 'no') : 'unknown';
            return "Region {$this->region}: state {$state}; players {$players}; busy {$busy}; lifecycle phase {$phase}; stage phase {$stage}, pending {$pending}. Status is not proof of deployment acceptance.";
        }
        $synchronous = in_array($action, ['kill', 'freeze', 'thaw'], true);
        $own = $data[$synchronous ? 'done' : 'accepted'][$this->region] ?? null;
        if (!is_array($own) || isset($data['refused'][$this->region])) { throw new \UnexpectedValueException('Missing/contradictory region receipt'); }
        if ($synchronous && ($own[match ($action) { 'kill' => 'killed', 'freeze' => 'frozen', default => 'thawed' }] ?? null) === true) {
            return "Region {$this->region}: host reports {$action} completed. Gameplay has not been verified.";
        }
        $expected = $action === 'force-restart' || $action === 'thaw' ? 'start' : $action;
        if (($own['accepted'] ?? null) !== true || ($own['action'] ?? null) !== $expected || ($action === 'thaw' && ($own['cold_start'] ?? null) !== true)) { throw new \UnexpectedValueException('Mismatched receipt'); }
        return "Region {$this->region}: {$action} accepted, not completed. Check status and the host receipt before assuming the world is ready.";
    }
}
