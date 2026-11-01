<?php
declare(strict_types=1);
namespace Civ13\Calendar;

/** One durable transaction contains revisions and sanitized output. Commit before making it visible. */
final class Ledger
{
    private array $state;
    private bool $healthy = true;
    private \Closure $commit;
    public function __construct(private string $guild, array $state, callable $commit)
    {
        if (!Visibility::id($guild)) { throw new \InvalidArgumentException('Explicit guild required'); }
        $this->commit = \Closure::fromCallable($commit);
        if ($state === []) { $state = ['version' => 1, 'guild_id' => $guild, 'revision' => 0, 'entries' => [], 'snapshot' => null]; }
        if (($state['version'] ?? null) !== 1 || ($state['guild_id'] ?? null) !== $guild || !is_int($state['revision'] ?? null) || $state['revision'] < 0 || !is_array($state['entries'] ?? null)) { throw new \RuntimeException('Ledger recovery required'); }
        foreach ($state['entries'] as $id => $entry) {
            if (!Visibility::id((string)$id) || !is_array($entry) || !is_int($entry['sequence'] ?? null) || $entry['sequence'] < 0 || $entry['sequence'] > 2147483647 || !is_bool($entry['public'] ?? null) || !is_int($entry['changed'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $entry['fingerprint'] ?? '') || !is_string($entry['start_at'] ?? null)) { throw new \RuntimeException('Ledger recovery required'); }
            Projection::instant($entry['start_at']);
        }
        $this->state = $state;
    }

    private function save(array $candidate): void
    {
        try { ($this->commit)($candidate); $this->state = $candidate; $this->healthy = true; }
        catch (\Throwable $error) { $this->healthy = false; throw $error; }
    }

    public function invalidate(int $now): void { $this->publish([], ['refresh_pending'], 'stale', $now, $this->state['entries']); }

    public function replace(array $projection, int $now): void
    {
        $this->healthy = false;
        $entries = $this->state['entries']; $events = []; $seen = [];
        foreach ($projection['events'] as $event) {
            $id = $event['event_id']; $seen[$id] = true;
            $fingerprint = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $prior = $entries[$id] ?? null;
            $sequence = $prior === null ? 0 : $prior['sequence'] + (int)(!$prior['public'] || $prior['fingerprint'] !== $fingerprint);
            if ($sequence > 2147483647) { throw new \RuntimeException('Event revision exhausted'); }
            $changed = $prior === null || $sequence !== $prior['sequence'] ? $now : $prior['changed'];
            $entries[$id] = ['sequence' => $sequence, 'fingerprint' => $fingerprint, 'start_at' => $event['start_at'], 'public' => true, 'changed' => $changed];
            $events[] = $event + ['uid' => 'discord-'.$this->guild.'-'.$id.'@civ13.github.io', 'sequence' => $sequence, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $changed)];
        }
        foreach ($entries as $id => &$entry) {
            if (isset($seen[$id])) { continue; }
            if ($entry['public']) { $entry['public'] = false; ++$entry['sequence']; $entry['changed'] = $now; }
            if ($entry['sequence'] > 2147483647) { throw new \RuntimeException('Event revision exhausted'); }
            if ($now - $entry['changed'] <= 30 * 86400) {
                $events[] = ['event_id' => (string)$id, 'uid' => 'discord-'.$this->guild.'-'.$id.'@civ13.github.io', 'sequence' => $entry['sequence'],
                    'updated_at' => gmdate('Y-m-d\TH:i:s\Z', $entry['changed']), 'start_at' => $entry['start_at'], 'end_at' => null,
                    'title' => 'Event no longer listed', 'description' => 'Details withdrawn; check Discord for current information.',
                    'location' => '', 'url' => '', 'status' => 'withdrawn', 'recurrence' => 'none'];
            }
        }
        unset($entry);
        if (count($entries) > 10000 || count($events) > 1000) { throw new \RuntimeException('Calendar retention bound requires review'); }
        $this->publish($events, $projection['notes'], $projection['notes'] === [] ? 'complete' : 'degraded', $now, $entries);
    }

    private function publish(array $events, array $notes, string $status, int $now, array $entries): void
    {
        $revision = $this->state['revision'] + 1;
        if ($revision > 9007199254740991 || $now < 1704067200 || $now > 4102444680) { throw new \RuntimeException('Calendar clock or revision unsupported'); }
        if (is_array($this->state['snapshot']) && $now < strtotime($this->state['snapshot']['generated_at'])) { $this->healthy = false; throw new \RuntimeException('Calendar clock moved backward'); }
        $snapshot = ['schema' => 'civ13.discord-calendar.v1', 'guild_id' => $this->guild, 'revision' => $revision,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + 120),
            'state' => $status, 'notes' => $notes, 'events' => $events];
        $this->save(['version' => 1, 'guild_id' => $this->guild, 'revision' => $revision, 'entries' => $entries, 'snapshot' => $snapshot]);
    }

    /** Heartbeat ACK renews a known good projection; it makes no Discord HTTP request. */
    public function renew(int $now): void
    {
        $snapshot = $this->snapshot($now);
        if ($snapshot !== null && $snapshot['state'] !== 'stale') { $this->publish($snapshot['events'], $snapshot['notes'], $snapshot['state'], $now, $this->state['entries']); }
    }

    public function snapshot(int $now): ?array
    {
        $snapshot = $this->state['snapshot'];
        if (!is_array($snapshot)) { return null; }
        if (!$this->healthy || $snapshot['state'] === 'stale' || $now < strtotime($snapshot['generated_at']) || $now >= strtotime($snapshot['expires_at'])) {
            $snapshot['state'] = 'stale'; $snapshot['events'] = []; $snapshot['notes'] = ['source_unavailable'];
        }
        return $snapshot;
    }
}
