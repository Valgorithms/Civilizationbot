<?php
declare(strict_types=1);
namespace Civ13\Local;

final class ReadOnlyEndpoint
{
    public function __construct(private VerificationReplica $replica, private string $readKey) { if (strlen($readKey) < 32) { throw new \InvalidArgumentException('Separate local read key required'); } }
    /** No verifier registration, roles, posts, server controls or writes are reachable. */
    public function handle(string $method, string $path, string $authorization): array
    {
        if ($method !== 'GET') { return [405, ['error' => 'read_only']]; }
        if ($path !== '/v1/verification-replica') { return [404, ['error' => 'not_found']]; }
        if (!hash_equals('Bearer '.$this->readKey, $authorization)) { return [401, ['error' => 'unauthorized']]; }
        try { $snapshot = $this->replica->snapshot(); }
        catch (\Throwable) { return [503, ['error' => 'replica_unavailable']]; }
        return [200, ['authoritative' => false, 'source' => 'official', 'snapshot' => $snapshot]];
    }
}
