<?php
declare(strict_types=1);
namespace Civ13\Calendar;

use Discord\WebSockets\Intents;

/** Explicit opt-in composition for the already-running production Civ13 client. */
final class PublicCalendar
{
    private GatewaySync $sync;
    private Ledger $ledger;
    private FileState $file;
    private \Closure $clock;
    private \Closure $current;
    public static function attach(object $app, array $settings): ?self
    {
        if (($settings['enabled'] ?? false) === false) { return null; }
        if (($settings['enabled'] ?? null) !== true || !is_bool($settings['public_http'] ?? null) || !is_string($settings['state_file'] ?? null)
            || !is_array($settings['onboarding_role_keys'] ?? null) || !Visibility::id($app->civ13_guild_id ?? null)) { throw new \InvalidArgumentException('Explicit calendar configuration required'); }
        $ids = [];
        foreach ($settings['onboarding_role_keys'] as $key) {
            if (!in_array($key, ['Verified', 'SS14 Verified'], true) || !Visibility::id($app->role_ids[$key] ?? null)) { throw new \InvalidArgumentException('Established onboarding role mapping required'); }
            $ids[] = $app->role_ids[$key];
        }
        if (count(array_unique($ids)) !== count($ids) || in_array($app->civ13_guild_id, $ids, true)) { throw new \InvalidArgumentException('Distinct onboarding roles required'); }
        $intents = $app->discord->options['intents'] ?? 0;
        $required = Intents::GUILDS | Intents::GUILD_SCHEDULED_EVENTS;
        if (!is_int($intents) || ($intents & $required) !== $required) { throw new \InvalidArgumentException('Existing guild and scheduled-event intents required'); }
        if ($settings['public_http'] && (!is_string($settings['public_authority'] ?? null) || preg_match('/^[a-z0-9.-]+(?::[1-9][0-9]{0,4})?$/D', $settings['public_authority']) !== 1)) { throw new \InvalidArgumentException('Explicit public authority required'); }
        return new self($app, $settings, $ids);
    }
    private function __construct(object $app, private array $settings, array $ids)
    {
        $guildId = $app->civ13_guild_id;
        $this->clock = static fn (): int => time();
        $this->file = new FileState($settings['state_file']);
        $this->ledger = new Ledger($guildId, $this->file->read(), fn (array $state) => $this->file->write($state));
        $current = $this->current = static function () use ($app, $guildId, $settings, $ids): bool {
            if ($app->civ13_guild_id !== $guildId || ($app->options['calendar_sync'] ?? null) !== $settings) { return false; }
            return array_map(static fn (string $key) => $app->role_ids[$key] ?? null, $settings['onboarding_role_keys']) === $ids;
        };
        $this->sync = new GatewaySync($app->discord, $app->loop, $this->ledger, $guildId, $ids,
            static function () use ($app, $guildId) {
                $guild = $app->discord->guilds->get('id', $guildId);
                if ($guild === null) { throw new \RuntimeException('Configured guild unavailable'); }
                return GatewaySync::refresh($guild);
            }, $current, $this->clock);
        if (($app->ready ?? false) === true) { $this->sync->ready(); }
    }
    public function snapshot(): ?array
    {
        if (!(($this->current)())) { $this->sync->stop(); }
        return $this->ledger->snapshot(($this->clock)());
    }
    public function exposesHttp(): bool { return $this->settings['public_http']; }
    public function authority(): string { return $this->settings['public_authority']; }
    public function stop(): void { $this->sync->stop(); $this->file->close(); }
}
