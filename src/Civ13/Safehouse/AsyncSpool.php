<?php
declare(strict_types=1);
namespace Civ13\Safehouse;

/** Nonblocking enqueue/poll primitives. The host schedules poll on React timers.
 * No sleeps, process launch, save access or authority decisions occur here. */
final class AsyncSpool
{
    public const IPC_PROTOCOL = 'civ13.safehouse.ipc.v1';
    private \Closure $clock;
    public function __construct(private string $directory, private string $key, private bool $enabled = false, private int $capacity = 1000, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
        if (strlen($key) < 32) { throw new \InvalidArgumentException('Service key must have at least 32 bytes'); }
        if (!is_dir($directory) || is_link($directory)) { throw new \InvalidArgumentException('Private existing spool directory required'); }
        $parent = $directory;
        while (dirname($parent) !== $parent) {
            if (is_link($parent)) { throw new \InvalidArgumentException('Symlink spool ancestor rejected'); }
            $parent = dirname($parent);
        }
        $this->directory = realpath($directory);
    }

    /** Returns the opaque file key. Repeated ID must contain identical bytes. */
    public function enqueue(array $request): string
    {
        if (!$this->enabled) { throw new \LogicException('Safehouse spool disabled'); }
        foreach (['protocol', 'request_id', 'discord_id', 'action', 'request_digest'] as $field) {
            if (!is_string($request[$field] ?? null)) { throw new \InvalidArgumentException('Invalid request envelope'); }
        }
        if ($request['protocol'] !== Client::PROTOCOL || !in_array($request['action'], Client::ACTIONS, true)) { throw new \InvalidArgumentException('Unsupported request'); }
        $bytes = json_encode($request, JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 8192) { throw new \InvalidArgumentException('Spool request too large'); }
        $id = hash('sha256', $request['request_id']);
        $path = $this->path($id, 'request');
        $lockPath = $this->directory.'/queue.lock';
        if (is_link($lockPath)) { throw new \RuntimeException('Symlink queue lock rejected'); }
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new \RuntimeException('Queue busy; retry unchanged request');
        }
        try {
            if (is_file($path)) {
                $existing = $this->read($path, 'request');
                if (base64_decode($existing['body'], true) !== $bytes) { throw new \LogicException('Request ID collision'); }
                return $id;
            }
            if (count(glob($this->directory.'/*.request')) >= $this->capacity) { throw new \RuntimeException('Queue capacity reached'); }
            $now = ($this->clock)();
            $envelope = self::seal($bytes, 'request', $now, $now + 120, $this->key);
            $temporary = $this->directory.'/'.bin2hex(random_bytes(16)).'.tmp';
            $stream = fopen($temporary, 'xb');
            if ($stream === false) { throw new \RuntimeException('Cannot create spool temporary'); }
            try {
                $encoded = json_encode($envelope, JSON_THROW_ON_ERROR);
                if (fwrite($stream, $encoded) !== strlen($encoded) || !fflush($stream) || !fsync($stream)) { throw new \RuntimeException('Cannot persist spool request'); }
            } finally { fclose($stream); }
            if (!rename($temporary, $path)) { @unlink($temporary); throw new \RuntimeException('Cannot publish spool request'); }
            return $id;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Missing/expired replies remain pending. Authentication failures throw. */
    public function poll(string $id): array
    {
        if (!$this->enabled) { throw new \LogicException('Safehouse spool disabled'); }
        $requestEnvelope = $this->read($this->path($id, 'request'), 'request');
        $request = json_decode(base64_decode($requestEnvelope['body'], true), true, 32, JSON_THROW_ON_ERROR);
        if ($this->replyPending($id)) { return ['status' => 'pending', 'request_id' => $request['request_id'], 'reason' => 'reply_write_pending']; }
        $replyPath = $this->path($id, 'reply');
        if (!is_file($replyPath)) { return ['status' => 'pending', 'request_id' => $request['request_id']]; }
        $replyEnvelope = $this->read($replyPath, 'reply');
        if ($replyEnvelope['expires_at'] < ($this->clock)()) { return ['status' => 'pending', 'request_id' => $request['request_id'], 'reason' => 'expired_reply']; }
        $reply = json_decode(base64_decode($replyEnvelope['body'], true), true, 32, JSON_THROW_ON_ERROR);
        foreach (['protocol', 'request_id', 'discord_id', 'action', 'request_digest'] as $field) {
            if (($reply[$field] ?? null) !== $request[$field]) { throw new \UnexpectedValueException('Reply identity mismatch'); }
        }
        if (!in_array($reply['status'] ?? null, ['pending', 'committed', 'rejected'], true)) { throw new \UnexpectedValueException('Unknown reply status'); }
        if ($this->replyPending($id)) { return ['status' => 'pending', 'request_id' => $request['request_id'], 'reason' => 'reply_write_pending']; }
        return $reply;
    }

    /** Presence only: malformed/empty markers also block success. Only the game
     * writer may clear its barrier after validating the durable signed receipt. */
    private function replyPending(string $id): bool
    {
        clearstatcache();
        return @lstat($this->path($id, 'reply.pending')) !== false;
    }

    /** Fresh authenticated read-only status request for an expired/uncertain
     * operation. Never re-signs or reissues that economic operation. The game
     * checks the actor against the original durable receipt before disclosure. */
    public function recover(string $id, string $statusRequestId): string
    {
        if (!$this->enabled) { throw new \LogicException('Safehouse spool disabled'); }
        $originalEnvelope = $this->read($this->path($id, 'request'), 'request');
        $original = json_decode(base64_decode($originalEnvelope['body'], true), true, 32, JSON_THROW_ON_ERROR);
        if ($statusRequestId === $original['request_id']) { throw new \InvalidArgumentException('Recovery needs a new status request ID'); }
        $request = ['protocol' => Client::PROTOCOL, 'request_id' => $statusRequestId, 'discord_id' => $original['discord_id'], 'action' => 'trade.status', 'expected_account_revision' => null, 'payload' => ['operation_request_id' => $original['request_id'], 'operation_request_digest' => $original['request_digest']]];
        $request['request_digest'] = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
        return $this->enqueue($request);
    }

    /** Public for independent fixture adapters; signing never authorizes a trade. */
    public static function seal(string $bytes, string $direction, int $issued, int $expires, string $key): array
    {
        $body = base64_encode($bytes);
        $signed = self::IPC_PROTOCOL."\n".$direction."\n".$issued."\n".$expires."\n".$body;
        return ['protocol' => self::IPC_PROTOCOL, 'issued_at' => $issued, 'expires_at' => $expires, 'body' => $body, 'mac' => hash_hmac('sha256', $signed, $key)];
    }

    private function path(string $id, string $suffix): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) { throw new \InvalidArgumentException('Invalid spool file key'); }
        $path = $this->directory.'/'.$id.'.'.$suffix;
        if (is_link($path)) { throw new \RuntimeException('Symlink spool entry rejected'); }
        return $path;
    }

    private function read(string $path, string $direction): array
    {
        if (!is_file($path) || filesize($path) > 100000) { throw new \RuntimeException('Missing or oversized spool entry'); }
        $envelope = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (($envelope['protocol'] ?? null) !== self::IPC_PROTOCOL || !is_int($envelope['issued_at'] ?? null) || !is_int($envelope['expires_at'] ?? null) || !is_string($envelope['body'] ?? null) || !is_string($envelope['mac'] ?? null) || base64_decode($envelope['body'], true) === false) { throw new \UnexpectedValueException('Invalid authenticated envelope'); }
        if ($envelope['expires_at'] < $envelope['issued_at'] || $envelope['expires_at'] - $envelope['issued_at'] > 120 || $envelope['issued_at'] > ($this->clock)() + 5) { throw new \UnexpectedValueException('Invalid envelope time bound'); }
        $expected = self::seal(base64_decode($envelope['body'], true), $direction, $envelope['issued_at'], $envelope['expires_at'], $this->key);
        if (!hash_equals($expected['mac'], $envelope['mac'])) { throw new \UnexpectedValueException('Invalid service authentication'); }
        return $envelope;
    }
}
