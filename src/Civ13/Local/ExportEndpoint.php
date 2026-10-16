<?php
declare(strict_types=1);
namespace Civ13\Local;

/** Disabled official installation adapter. Source MUST expose a consistent
 * committed read-only projection and monotonically increasing export revision.
 * Never instantiate the mutable legacy verifier to obtain a snapshot. */
final class ExportEndpoint
{
    public function __construct(private $source, private string $authority, private string $secretKey, private string $readKey, private $clock, private bool $enabled = false, private int $ttl = 600)
    {
        if (!is_callable($source) || !is_callable($clock) || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || strlen($readKey) < 32 || $ttl < 1 || $ttl > 86400) { throw new \InvalidArgumentException('Invalid authenticated export configuration'); }
    }
    /** Return a signed exact-byte envelope only; no verify/register/unverify write method. */
    public function handle(string $method, string $authorization): array
    {
        if ($method !== 'GET') { return [405, ['error' => 'read_only']]; }
        if (!$this->enabled) { return [503, ['error' => 'export_disabled']]; }
        if (!hash_equals('Bearer '.$this->readKey, $authorization)) { return [401, ['error' => 'unauthorized']]; }
        try {
            $projection = ($this->source)();
            if (!is_array($projection) || count($projection) !== 4 || !is_int($projection['revision'] ?? null) || !is_int($projection['issued_at'] ?? null) || !is_int($projection['expires_at'] ?? null) || !is_array($projection['records'] ?? null)) { throw new \UnexpectedValueException('Invalid committed export projection'); }
            $now = ($this->clock)();
            if ($projection['expires_at'] <= $now || $projection['issued_at'] > $now + 5 || $projection['expires_at'] - $projection['issued_at'] > $this->ttl) { throw new \UnexpectedValueException('Stale export projection'); }
            return [200, json_decode(VerificationReplica::export($projection['records'], $this->authority, $projection['revision'], $projection['issued_at'], $projection['expires_at'], $this->secretKey), true, 32, JSON_THROW_ON_ERROR)];
        } catch (\Throwable) { return [503, ['error' => 'export_unavailable']]; }
    }
}
