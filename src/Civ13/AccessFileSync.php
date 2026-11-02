<?php
declare(strict_types=1);
namespace Civ13;

use Civ13\Server\AccessListWriter;
use React\Promise\PromiseInterface;
use function React\Promise\all;
use function React\Promise\resolve;

/** Portable application composition only. No client, credential discovery or game activation. */
final class AccessFileSync
{
    private AdminCacheSyncTrigger $trigger;
    private AdminSyncCoordinator $coordinator;
    private array $listeners = [];
    private bool $stopped = false;
    private bool $acquiringVerification = false;
    private bool $verificationProjectionPending = false;
    private array $capturedRecords = [];
    private \Closure $clock;
    private \Closure $writer;
    private ?AccessListWriter $publisher = null;
    private array $preview = ['state' => 'unavailable'];
    private readonly array $settings;
    private readonly object $server;
    private ?int $previewEpoch = null;
    private ?string $previewSuccess = null;
    private ?int $previewMembers = null;

    /**
     * $app is the existing Civ13 instance (or an inert facade in tests), never a new client.
     * Optional writer/clock are explicit test seams; constructor never opens a target.
     * Uses the explicitly configured existing read-only verification feed; no transport authentication claim.
     * Disabled returns before reading app state or creating any listener/timer/writer.
     */
    public static function attach(object $app, array $settings, ?callable $writer = null, ?callable $clock = null): ?self
    {
        if (!self::enabled($settings)) { return null; }
        self::validateConfiguration($settings, $app->civ13_guild_id, $app->role_ids, ($app->options['admin_file_sync']['enabled'] ?? false));
        return new self($app, $settings, $writer, $clock);
    }

    /** Omitted means on; explicit boolean false is an inert opt-out. */
    public static function enabled(array $settings): bool
    {
        if (!array_key_exists('enabled',$settings)) { return true; }
        if (!is_bool($settings['enabled'])) { throw new \InvalidArgumentException('Boolean access sync flag required'); }
        return $settings['enabled'];
    }

    /** Pure preflight; no destination or cache access. Both policies were explicitly selected. */
    public static function validateConfiguration(array $settings, string $guildId, array $roleIds, bool $adminOwner = false): void
    {
        if (!self::enabled($settings)) { return; }
        if ($adminOwner
            || !in_array($settings['mode'] ?? null, ['preview', 'publish'], true)
            || !is_int($settings['max_bytes'] ?? null) || $settings['max_bytes'] < 1 || $settings['max_bytes'] > 4194304
            || !self::seconds($settings['debounce_seconds'] ?? null, 5) || !self::seconds($settings['refresh_timeout_seconds'] ?? null, 300)
            || !is_string($settings['server_key'] ?? null) || preg_match('/^[a-z0-9_]{1,32}$/D', $settings['server_key']) !== 1
            || !PortableProfile::absoluteAdminFile($settings['server_basedir'] ?? null)
            || preg_match('/^[1-9][0-9]{16,19}$/D', $guildId) !== 1
            || !is_string($roleIds['Verified'] ?? null) || preg_match('/^[1-9][0-9]{16,19}$/D', $roleIds['Verified']) !== 1
            || !is_array($settings['targets'] ?? null) || count($settings['targets']) !== 2 || array_diff(array_keys($settings['targets']), ['approved', 'whitelist']) !== []) {
            throw new \InvalidArgumentException('Explicit access policies, single cache owner and bounded local targets required');
        }
        foreach ($settings['targets'] as $kind => $target) {
            $keys=is_array($target) ? array_keys($target) : []; sort($keys);
            if (!is_array($target) || $keys !== ['allow_creation', 'allow_empty', 'metadata_policy', 'path', 'required_roles']
                || $target['required_roles'] !== ['Verified']
                || str_replace('\\', '/', $target['path'] ?? '') !== rtrim(str_replace('\\', '/', $settings['server_basedir']), '/').'/SQL/'.$kind.'.txt') {
                throw new \InvalidArgumentException('Explicit separate Verified policy and server SQL destination required');
            }
        }
        new AccessListWriter($settings['targets'], $settings['max_bytes']); // Constructor is pure.
    }

    public function lastPreview(): array
    {
        try {
            $status=$this->verifier->hostedStatus(); $now=($this->clock)(); $guild=$this->guild();
            $success=$status['last_success_utc'] ?? null;
            $date=is_string($success) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$success,new \DateTimeZone('UTC')) : false;
            if (!$this->configurationCurrent() || !$this->trigger->isReady() || $this->previewEpoch!==$this->trigger->epoch()
                || ($status['available'] ?? null)!==true || ($status['stale'] ?? null)!==false || ($status['refresh_in_progress'] ?? null)!==false
                || $success!==$this->previewSuccess || !is_int($now) || $date===false || $now<$date->getTimestamp()
                || $now-$date->getTimestamp()>=HostedVerificationSnapshot::FRESH_SECONDS || $guild===null || $guild->member_count!==$this->previewMembers) {
                return ['state'=>'unavailable'];
            }
            return $this->preview;
        } catch (\Throwable) { return ['state'=>'unavailable']; }
    }

    private function __construct(
        private readonly object $app,
        array $settings,
        ?callable $writer,
        ?callable $clock
    ) {
        if (($app->verifier->readOnly ?? null) !== true) { throw new \LogicException('Access file synchronization requires read-only verification'); }
        $this->settings=$settings;
        $server=$app->enabled_gameservers[$settings['server_key']] ?? null;
        if (!is_object($server) || ($server->enabled ?? null)!==true || ($server->basedir ?? null)!==$settings['server_basedir']) { throw new \LogicException('Enabled access server binding unavailable'); }
        $this->server=$server;
        $this->verifier=$app->verifier;
        $this->discord=$app->discord;
        $this->loop=$app->loop;
        $this->guildId=$app->civ13_guild_id;
        $this->roleIds=$app->role_ids;
        $this->clock=\Closure::fromCallable($clock ?? time(...));
        if ($settings['mode']==='publish' && $writer===null) { $this->publisher=new AccessListWriter($settings['targets'],$settings['max_bytes']); }
        $this->writer=\Closure::fromCallable($writer ?? $this->publisher ?? static fn (): array => ['state'=>'unchanged']);
        $this->coordinator=new AdminSyncCoordinator(true,
            function (): PromiseInterface {
                if (!$this->configurationCurrent()) { throw new \RuntimeException('Access file configuration changed before verification refresh'); }
                if ($this->verificationProjectionPending) {
                    $this->verificationProjectionPending=false;
                    return resolve($this->captureVerification());
                }
                $this->acquiringVerification=true;
                try {
                    return $this->verifier->refreshHosted()->then(fn (mixed $unused): array => $this->captureVerification())
                        ->finally(function (): void { $this->acquiringVerification=false; });
                } catch (\Throwable $error) {
                    $this->acquiringVerification=false;
                    throw $error;
                }
            },
            fn (int $generation): array => $this->members($generation),
            function (array $snapshot,array $membership,int $generation): string {
                if (!$this->configurationCurrent()) { throw new \RuntimeException('Access file configuration changed'); }
                $current=$this->verification();
                if ($current['records']!==$snapshot['records']
                    || ($current['status']['last_success_utc'] ?? null)!==($snapshot['status']['last_success_utc'] ?? null)) {
                    throw new \RuntimeException('Verification identity snapshot changed');
                }
                $now=($this->clock)();
                if (!$this->configurationCurrent()) { throw new \RuntimeException('Access file configuration changed during validation'); }
                if (!is_int($now)) { throw new \RuntimeException('Expected explicit Unix time'); }
                $guild=$this->guild();
                if ($guild===null || $guild->member_count!==$membership['expected_members']) { throw new \RuntimeException('Member cache changed'); }
                $wire=AccessListProjection::render($current['status'],$snapshot['records'],$membership,
                    $this->guildId,$generation,$now,$this->roleIds,$this->settings['targets'],$this->trigger->state());
                $preview=AccessListProjection::preview($wire);
                foreach ($preview['targets'] as $target) { if ($target['bytes']>$this->settings['max_bytes']) { throw new \RuntimeException('Access output exceeds budget'); } }
                $this->preview=$preview;
                $this->previewEpoch=$this->trigger->epoch();
                $this->previewSuccess=$current['status']['last_success_utc'];
                $this->previewMembers=$membership['expected_members'];
                return $wire;
            },
            fn (string $bytes,int $generation,callable $guard): mixed => $this->settings['mode']==='preview' ? ($guard() ? ['state'=>'blocked','reason'=>'preview-only'] : ['state'=>'superseded']) : ($this->writer)($bytes,$generation,$guard));
        $this->trigger=new AdminCacheSyncTrigger(true,$this->guildId,function (): PromiseInterface { $this->preview=['state'=>'unavailable']; return $this->coordinator->request(); },
            fn (): bool => $this->configurationCurrent() && $this->guild()!==null,
            function (): PromiseInterface {
                if (!$this->configurationCurrent()) { throw new \RuntimeException('Access file configuration changed before cache refresh'); }
                $guild=$this->guild();
                if ($guild===null) { throw new \RuntimeException('Target guild cache unavailable'); }
                $pending=[];
                foreach (['members','roles'] as $repository) {
                    try {
                        if (!$this->configurationCurrent()) { throw new \RuntimeException('Access file configuration changed during cache refresh'); }
                        $promise=$guild->{$repository}->freshen();
                        if (!$promise instanceof PromiseInterface) { throw new \RuntimeException('Cache refresh promise required'); }
                        $pending[]=$promise->then(static fn (mixed $result): bool => true,static fn (\Throwable $error): bool => false);
                    } catch (\Throwable) { $pending[]=resolve(false); }
                }
                // Do not release the trigger's refresh ownership while one repository still drains.
                return all($pending)->then(static function (array $results): void {
                    if (in_array(false,$results,true)) { throw new \RuntimeException('Cache refresh failed'); }
                });
            },
            function (float $delay,callable $callback): callable {
                $timer=$this->loop->addTimer($delay,$callback);
                return fn ()=>$this->loop->cancelTimer($timer);
            },
            function (array $outcome): void { $this->app->logger->info('Access file synchronization', $outcome + ['mode'=>$this->settings['mode'], 'preview'=>$this->lastPreview(), 'publication'=>$this->publisher?->lastReceipt()]); },
            static function (): void { throw new \LogicException('Stop and recreate composition for configuration changes'); },
            (float)$settings['debounce_seconds'],(float)$settings['refresh_timeout_seconds']);
        try {
            foreach ($this->trigger->listeners() as $event=>$listener) {
                $this->listeners[$event]=$listener;
                $this->discord->on($event,$listener);
            }
            // Existing Civ13 ready flag, not an inferred private Discord connection field.
            if (($this->app->ready ?? false)===true) { $this->trigger->bootstrapReady(); }
        } catch (\Throwable $error) {
            try { $this->stop(); } catch (\Throwable) {}
            throw $error;
        }
    }

    private readonly object $verifier;
    private readonly object $discord;
    private readonly object $loop;
    private readonly string $guildId;
    private readonly array $roleIds;

    /** The existing verifier already replaced its cache; do not fetch it again. */
    public function verificationChanged(): void
    {
        if (!$this->configurationCurrent() || $this->acquiringVerification) { return; }
        $this->preview=['state'=>'unavailable'];
        $this->verificationProjectionPending=true;
        $this->trigger->verificationChanged();
    }

    private function captureVerification(): array
    {
        $snapshot=$this->verification();
        $this->capturedRecords=$snapshot['records'];
        return $snapshot;
    }

    /** Call before releasing the owner or changing destination/role/source configuration. */
    public function stop(): void
    {
        if ($this->stopped) { return; }
        $this->stopped=true;
        $this->trigger->stop();
        $failure=null;
        foreach ($this->listeners as $event=>$listener) {
            try { $this->discord->removeListener($event,$listener); } catch (\Throwable $error) { $failure ??= $error; }
        }
        $this->listeners=[];
        if ($failure!==null) { throw $failure; }
    }

    private function configurationCurrent(): bool
    {
        return !$this->stopped && ($this->app->options['access_file_sync'] ?? null)===$this->settings
            && ($this->app->options['admin_file_sync']['enabled'] ?? false)===false
            && ($this->app->enabled_gameservers[$this->settings['server_key']] ?? null)===$this->server
            && ($this->server->enabled ?? null)===true && $this->server->basedir===$this->settings['server_basedir']
            && $this->app->verifier===$this->verifier && $this->app->discord===$this->discord
            && $this->app->loop===$this->loop && ($this->app->verifier->readOnly ?? null)===true
            && $this->app->civ13_guild_id===$this->guildId && $this->app->role_ids===$this->roleIds;
    }

    private function guild(): mixed { return $this->discord->guilds->get('id',$this->guildId); }

    /** Capture status and records together; count alone does not establish identity. */
    private function verification(): array
    {
        $before=$this->verifier->hostedStatus();
        $records=[];
        foreach ($this->verifier->verified as $record) { $records[]=$record; }
        $after=$this->verifier->hostedStatus();
        if ($before!==$after || ($after['records'] ?? null)!==count($records)) {
            throw new \RuntimeException('Verification snapshot changed during capture');
        }
        $snapshot=['status'=>$after,'records'=>$records];
        return $snapshot;
    }

    private function members(int $generation): array
    {
        if (!$this->configurationCurrent() || !$this->trigger->isReady() || ($guild=$this->guild())===null) {
            throw new \RuntimeException('Target guild cache is not ready');
        }
        $epoch=$this->trigger->epoch(); $role=$this->roleIds['Verified'];
        if ($guild->roles->get('id',$role)===null || !is_int($guild->member_count) || $guild->member_count<0 || $guild->member_count>50000) {
            throw new \RuntimeException('Access guild role or membership count unavailable');
        }
        $members=[];
        foreach ($guild->members as $member) {
            if (count($members)>=50000) { throw new \RuntimeException('Access member budget exceeded'); }
            $members[]=['id'=>$member->id, 'roles'=>$member->roles->has($role) ? [$role] : []];
        }
        return ['guild_id'=>$this->guildId,'generation'=>$generation,'cache_epoch'=>$epoch,'roles'=>[$role],
            'expected_members'=>$guild->member_count,'members'=>$members];
    }

    private static function seconds(mixed $value,int $maximum): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value>0 && $value<=$maximum;
    }
}
