<?php
declare(strict_types=1);
namespace Civ13\Safehouse;

/** Disabled by default. No save-file access, game rules or verifier-cache authority. */
final class Client
{
    public const PROTOCOL = 'civ13.safehouse.v1';
    public const ACTIONS = ['link.challenge', 'link.complete', 'stash.inspect', 'room.inspect', 'room.manage', 'trade.quote', 'trade.confirm', 'trade.status', 'market.list', 'market.buy', 'market.cancel'];

    public function __construct(private Transport $transport, private string $journal, private bool $enabled = false, private int $capacity = 1000) {}

    public function request(string $requestId, string $discordId, string $action, array $payload, ?int $expectedAccountRevision): array
    {
        if (!$this->enabled) {
            throw new \LogicException('Safehouse integration is disabled');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,96}$/D', $requestId) || !preg_match('/^[0-9]{1,22}$/D', $discordId) || !in_array($action, self::ACTIONS, true) || ($expectedAccountRevision !== null && $expectedAccountRevision < 0)) {
            throw new \InvalidArgumentException('Invalid request identity/action/revision');
        }
        $request = ['protocol' => self::PROTOCOL, 'request_id' => $requestId, 'discord_id' => $discordId, 'action' => $action, 'expected_account_revision' => $expectedAccountRevision, 'payload' => self::canonical($payload)];
        $digest = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
        $request['request_digest'] = $digest;
        // Serialize local delivery and persist before external effects. A timeout retains
        // the exact request for replay; only the game can deduplicate settlement.
        $lock = fopen($this->journal.'.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot acquire safehouse journal lock');
        }
        try {
            $records = is_file($this->journal) ? json_decode(file_get_contents($this->journal), true, 64, JSON_THROW_ON_ERROR) : [];
            if (!is_array($records)) {
                throw new \RuntimeException('Invalid safehouse journal');
            }
            if (isset($records[$requestId])) {
                if ($records[$requestId]['request'] !== $request) {
                    throw new \LogicException('Request ID already bound to another request');
                }
                if (isset($records[$requestId]['response']) && $records[$requestId]['response']['status'] !== 'pending') {
                    return $records[$requestId]['response'];
                }
            } else {
                if (count($records) >= $this->capacity) {
                    throw new \RuntimeException('Safehouse journal capacity reached; archival required');
                }
                $records[$requestId] = ['request' => $request];
                $this->persist($records);
            }
            try {
                $response = $this->transport->exchange($request);
            } catch (\Throwable $error) {
                return ['protocol' => self::PROTOCOL, 'request_id' => $requestId, 'status' => 'pending', 'reason' => 'transport_unresolved'];
            }
            foreach (['protocol', 'request_id', 'discord_id', 'action', 'request_digest'] as $key) {
                if (($response[$key] ?? null) !== $request[$key]) {
                    throw new \UnexpectedValueException('Game response binding mismatch: '.$key);
                }
            }
            if (!in_array($response['status'] ?? null, ['pending', 'committed', 'rejected'], true)) {
                throw new \UnexpectedValueException('Unknown game outcome');
            }
            $records[$requestId]['response'] = $response;
            $this->persist($records);
            return $response;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function persist(array $records): void
    {
        $temporary = $this->journal.'.'.bin2hex(random_bytes(8)).'.tmp';
        $stream = fopen($temporary, 'xb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot create journal snapshot');
        }
        try {
            $bytes = json_encode($records, JSON_THROW_ON_ERROR);
            if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
                throw new \RuntimeException('Cannot persist journal snapshot');
            }
        } finally {
            fclose($stream);
        }
        if (!rename($temporary, $this->journal)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot replace journal snapshot');
        }
    }

    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                $entry = self::canonical($entry);
            } elseif (!is_scalar($entry) && $entry !== null) {
                throw new \InvalidArgumentException('Payload must contain JSON values');
            }
        }
        return $value;
    }
}
