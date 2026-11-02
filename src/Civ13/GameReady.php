<?php
declare(strict_types=1);
namespace Civ13;

/** Bounded in-process observation ledger. An authenticated game claim is not process readiness. */
final class GameReady
{
    public const SCHEMA = 'game_ready.v1';
    public const MAX_BYTES = 1024;
    private array $entries = [];
    private ?int $lastNow = null;
    private \Closure $clock;

    public function __construct(?callable $clock = null, private int $capacity = 256, private int $retention = 86400)
    {
        if ($capacity < 1 || $capacity > 256 || $retention < 120 || $retention > 86400) { throw new \InvalidArgumentException('Bounded ready ledger required'); }
        $this->clock = \Closure::fromCallable($clock ?? time(...));
    }

    /** Closed flat object, literal unique keys; values normalize before deduplication. */
    public static function decode(string $raw, string $server): array
    {
        if (strlen($raw) > self::MAX_BYTES) { throw new \InvalidArgumentException('Ready payload too large'); }
        $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        $keys = ['boot', 'event', 'generation', 'generation_kind', 'round', 'schema', 'server'];
        if (!is_array($data)) { throw new \InvalidArgumentException('Ready object required'); }
        $actual = array_keys($data); sort($actual);
        preg_match_all('~"((?:[^"\\\\]|\\\\.)*)"\\s*:~', $raw, $fields); $literal = $fields[1]; sort($literal);
        if ($actual !== $keys || $literal !== $keys || ($data['schema'] ?? null) !== self::SCHEMA
            || ($data['event'] ?? null) !== 'game_ready'
            || !is_string($data['round'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $data['round']) !== 1
            || preg_match('/^[a-z0-9_]{1,32}$/D', $server) !== 1 || ($data['server'] ?? null) !== $server
            || !is_string($data['boot'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $data['boot']) !== 1
            || !is_string($data['generation'] ?? null)) { throw new \InvalidArgumentException('Invalid ready identity'); }
        if ($data['generation_kind'] === 'fresh') {
            if ($data['generation'] !== 'fresh:'.$data['boot']) { throw new \InvalidArgumentException('Fresh identity must bind its boot'); }
        } elseif ($data['generation_kind'] !== 'restored' || preg_match('/^round-v2:[a-f0-9]{32}$/D', $data['generation']) !== 1) {
            throw new \InvalidArgumentException('Validated restored generation required');
        }
        return ['schema'=>self::SCHEMA, 'event'=>'game_ready', 'server'=>$server, 'boot'=>$data['boot'], 'round'=>$data['round'],
            'generation_kind'=>$data['generation_kind'], 'generation'=>$data['generation']];
    }

    /** 202/200 acknowledge an observation in this process, never durable/external delivery. */
    public function receive(string $raw, string $server): array
    {
        $event = self::decode($raw, $server);
        $now = ($this->clock)();
        if (!is_int($now) || $now < 0 || ($this->lastNow !== null && $now < $this->lastNow)) { throw new \RuntimeException('Ready clock unavailable'); }
        $this->lastNow = $now;
        foreach ($this->entries as $key=>$entry) { if ($entry['expires'] <= $now) { unset($this->entries[$key]); } }
        $canonical = implode("\n", array_values($event));
        $id = hash('sha256', $canonical);
        $key = $server.':'.$event['boot'];
        if (isset($this->entries[$key])) {
            $entry = $this->entries[$key];
            if (!hash_equals($entry['id'], $id)) { return ['status'=>409]; }
            return $this->receipt($entry, 'duplicate', 200);
        }
        if (count($this->entries) >= $this->capacity) { return ['status'=>503]; }
        $entry = ['event'=>$event, 'id'=>$id, 'ack'=>md5($canonical), 'observed'=>gmdate('Y-m-d\TH:i:s\Z', $now), 'expires'=>$now+$this->retention];
        $this->entries[$key] = $entry;
        return $this->receipt($entry, 'queued', 202);
    }

    private function receipt(array $entry, string $state, int $status): array
    {
        return ['status'=>$status, 'ack'=>$entry['ack'], 'body'=>['schema'=>'civ13.game_ready.receipt.v1',
            'state'=>$state, 'event_id'=>$entry['id'], 'observed_at'=>$entry['observed'], 'delivery'=>'unconfigured']];
    }

    public function count(): int { return count($this->entries); }
}
