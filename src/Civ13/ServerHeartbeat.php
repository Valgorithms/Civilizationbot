<?php

declare(strict_types=1);

namespace Civ13;

/** Passive progress evidence, never a TCP, Topic or player-join health check. */
final class ServerHeartbeat
{
    public const STALE_SECONDS = 120.0;
    public const MISSING = 'Heartbeat file missing';
    public const MALFORMED = 'Heartbeat unreadable or malformed';
    private ?string $previous = null;
    private ?float $lastPoll = null;
    private ?float $lastProgress = null;
    private bool $confirmed = false;
    private ?float $missingSince = null;

    public function __construct(private string $path, private ?\Closure $clock = null, private ?\Closure $reader = null)
    {
    }

    /** @return array{state:string, reason:string, age:?float, data:?string} */
    public function poll(): array
    {
        $now = $this->clock ? ($this->clock)() : hrtime(true) / 1e9;
        $data = $this->reader ? ($this->reader)($this->path) : $this->read();
        // The game rewrites this complete record every 100 ticks, even with zero players.
        // Parse the named field: optional "Approved only" shifts positional fields.
        $valid = is_string($data) && strlen($data) <= 65536
            && str_starts_with($data, '<b>Server Status</b>: Online;')
            && str_ends_with(rtrim($data, "\r\n"), ';')
            && preg_match('/;<b>Players<\/b>: [0-9]{1,5};/', $data) === 1
            && preg_match_all('/(?:^|;)ckey_list=([^;]*)(?=;)/', $data) === 1
            && preg_match_all('/(?:^|;)realtime=([0-9]{1,20})(?=;)/', $data, $matches) === 1;
        if (! $valid) {
            $this->previous = $this->lastPoll = $this->lastProgress = null;
            $this->confirmed = false;

            // The game deletes and rewrites the file, so only an absence that persists for the stale
            // window means the writer is gone; a torn, unreadable or brief absence may be a rewrite.
            if ($data !== null || ($this->missingSince !== null && $now < $this->missingSince)) {
                $this->missingSince = null;
            }
            if ($data === null) {
                $this->missingSince ??= $now;
            }
            $missing = $data === null && $now - $this->missingSince >= self::STALE_SECONDS;

            return ['state' => 'unknown', 'reason' => $missing ? self::MISSING : self::MALFORMED, 'age' => null, 'data' => null];
        }
        $this->missingSince = null;
        $token = ltrim($matches[1][0], '0');
        $reset = $this->lastPoll === null || $now < $this->lastPoll
            || $now - $this->lastPoll > self::STALE_SECONDS;
        $order = $this->previous === null ? 0 : ((strlen($token) <=> strlen($this->previous)) ?: strcmp($token, $this->previous));
        if ($reset || $order < 0) {
            $this->confirmed = false;
            $this->lastProgress = $now;
        } elseif ($order > 0) {
            $this->confirmed = true;
            $this->lastProgress = $now;
        }
        $this->previous = $token;
        $this->lastPoll = $now;
        $age = max(0.0, $now - $this->lastProgress);
        $state = $age >= self::STALE_SECONDS ? 'stale' : ($this->confirmed ? 'advancing' : 'unknown');

        return [
            'state' => $state,
            'reason' => match ($state) {
                'advancing' => 'Heartbeat advanced between recent observations',
                'stale' => 'No heartbeat progress observed for at least 120 seconds',
                default => 'Waiting to observe heartbeat progress',
            },
            'age' => $age,
            'data' => $state === 'advancing' ? $data : null,
        ];
    }

    /** @return string|false|null Null when no file exists at the path. */
    private function read(): string|false|null
    {
        // No touch(), stat-based freshness or socket probe. Bound reads and reject special files.
        clearstatcache(true, $this->path);
        if (! file_exists($this->path)) {
            // An unreadable parent directory hides the file; that is not evidence it is absent.
            $parent = dirname($this->path);

            return is_dir($parent) && ! is_readable($parent) ? false : null;
        }
        if (! is_file($this->path) || ! is_readable($this->path)) {
            return false;
        }

        return @file_get_contents($this->path, false, null, 0, 65537);
    }
}
