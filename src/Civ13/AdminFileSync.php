<?php
declare(strict_types=1);
namespace Civ13;

use Civ13\Server\AdminFileWriter;
use React\Promise\PromiseInterface;
use function React\Promise\all;
use function React\Promise\resolve;

/** Portable application composition only. No client, credential discovery or game activation. */
final class AdminFileSync
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

    /**
     * $app is the existing Civ13 instance (or an inert facade in tests), never a new client.
     * Optional writer/clock are explicit test seams; constructor does not open the admin file.
     * Uses the explicitly configured existing read-only verification feed; no transport authentication claim.
     * Disabled returns before reading app state or creating any listener/timer/writer.
     */
    public static function attach(object $app, array $settings, string $adminFile, ?callable $writer = null, ?callable $clock = null): ?self
    {
        if (($settings['enabled'] ?? false) === false) { return null; }
        self::validateConfiguration($settings,$adminFile,$app->civ13_guild_id,$app->role_ids);
        return new self($app,$settings,$adminFile,$writer,$clock);
    }

    /** Shared pure preflight: run after autoload but before credentials/client construction. */
    public static function validateConfiguration(array $settings, string $adminFile, string $guildId, array $roleIds): void
    {
        if (($settings['enabled'] ?? false) === false) { return; }
        if (($settings['enabled'] ?? null) !== true
            || !is_int($settings['max_bytes'] ?? null) || $settings['max_bytes'] < 1 || $settings['max_bytes'] > 16777216
            || !self::seconds($settings['debounce_seconds'] ?? null,5)
            || !self::seconds($settings['refresh_timeout_seconds'] ?? null,300)
            || !PortableProfile::absoluteAdminFile($adminFile)) {
            throw new \InvalidArgumentException('Explicit bounded admin file synchronization settings required');
        }
        if (preg_match('/^[1-9][0-9]{16,19}$/D',$guildId)!==1) { throw new \InvalidArgumentException('Explicit target guild required'); }
        foreach (GameServer::ADMIN_PERMISSIONS as $name => $_) {
            if (!is_string($roleIds[$name] ?? null) || preg_match('/^[1-9][0-9]{16,19}$/D',$roleIds[$name])!==1) {
                throw new \InvalidArgumentException('Explicit required admin role mapping missing');
            }
        }
        if (count(array_unique(array_intersect_key($roleIds,GameServer::ADMIN_PERMISSIONS)))!==count(GameServer::ADMIN_PERMISSIONS)) {
            throw new \InvalidArgumentException('Distinct required admin roles required');
        }
    }

    private function __construct(
        private readonly object $app,
        array $settings,
        string $adminFile,
        ?callable $writer,
        ?callable $clock
    ) {
        if (($app->verifier->readOnly ?? null) !== true) { throw new \LogicException('Admin file synchronization requires read-only verification'); }
        $this->verifier=$app->verifier;
        $this->discord=$app->discord;
        $this->loop=$app->loop;
        $this->guildId=$app->civ13_guild_id;
        $this->roleIds=$app->role_ids;
        $this->clock=\Closure::fromCallable($clock ?? time(...));
        $this->writer=\Closure::fromCallable($writer ?? new AdminFileWriter($adminFile,$settings['max_bytes']));
        $this->coordinator=new AdminSyncCoordinator(true,
            function (): PromiseInterface {
                if (!$this->configurationCurrent()) { throw new \RuntimeException('Admin file configuration changed before verification refresh'); }
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
                if (!$this->configurationCurrent()) { throw new \RuntimeException('Admin file configuration changed'); }
                $current=$this->verification();
                if ($current['records']!==$snapshot['records']
                    || ($current['status']['last_success_utc'] ?? null)!==($snapshot['status']['last_success_utc'] ?? null)) {
                    throw new \RuntimeException('Verification identity snapshot changed');
                }
                $now=($this->clock)();
                if (!$this->configurationCurrent()) { throw new \RuntimeException('Admin file configuration changed during validation'); }
                if (!is_int($now)) { throw new \RuntimeException('Expected explicit Unix time'); }
                return AdminSyncSnapshotGate::renderCached($current['status'],$snapshot['records'],$membership,
                    $this->guildId,$generation,$now,$this->roleIds,GameServer::ADMIN_PERMISSIONS,$this->trigger->state());
            },
            fn (string $bytes,int $generation,callable $guard): mixed => ($this->writer)($bytes,$generation,$guard));
        $this->trigger=new AdminCacheSyncTrigger(true,$this->guildId,fn ()=>$this->coordinator->request(),
            fn (): bool => $this->configurationCurrent() && $this->guild()!==null,
            function (): PromiseInterface {
                if (!$this->configurationCurrent()) { throw new \RuntimeException('Admin file configuration changed before cache refresh'); }
                $guild=$this->guild();
                if ($guild===null) { throw new \RuntimeException('Target guild cache unavailable'); }
                $pending=[];
                foreach (['members','roles'] as $repository) {
                    try {
                        if (!$this->configurationCurrent()) { throw new \RuntimeException('Admin file configuration changed during cache refresh'); }
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
            function (array $outcome): void { $this->app->logger->info('Admin file synchronization',$outcome); },
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
        return !$this->stopped && $this->app->verifier===$this->verifier && $this->app->discord===$this->discord
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
        $epoch=$this->trigger->epoch(); $roles=[]; $members=[];
        foreach (GameServer::ADMIN_PERMISSIONS as $name=>$_) {
            $id=$this->roleIds[$name];
            if ($guild->roles->get('id',$id)!==null) { $roles[]=$id; }
        }
        // Same verified-record -> cached-member lookup used by the existing GameServer flow.
        foreach ($this->capturedRecords as $record) {
            if (!is_array($record) || !is_string($record['discord'] ?? null)) { throw new \RuntimeException('Invalid verified identity'); }
            $member=$this->verifier->getVerifiedMember($record['discord']);
            if ($member===null) { continue; }
            $held=[];
            foreach ($roles as $role) { if ($member->roles->has($role)) { $held[]=$role; } }
            $members[]=['id'=>$member->id,'roles'=>$held];
        }
        return ['guild_id'=>$this->guildId,'generation'=>$generation,'cache_epoch'=>$epoch,'roles'=>$roles,'members'=>$members];
    }

    private static function seconds(mixed $value,int $maximum): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value>0 && $value<=$maximum;
    }
}
