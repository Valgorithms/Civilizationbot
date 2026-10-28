<?php

declare(strict_types=1);

namespace Civ13\AdminConsole;

/** Inert admission contract. Does not dispatch, query, release or complete operations. */
final class OperationAdmission
{
    private array $targets = [];

    public function __construct(
        array $targetIds,
        private ?OperationAdmissionStore $store = null,
        private ?\Closure $authorize = null,
    ) {
        if (!array_is_list($targetIds) || count($targetIds) > 32) {
            throw new \InvalidArgumentException('Bounded target inventory required');
        }
        foreach ($targetIds as $id) {
            if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id) !== 1 || isset($this->targets['server:'.$id])) {
                throw new \InvalidArgumentException('Invalid trusted target identity');
            }
            $this->targets['server:'.$id] = true;
        }
    }

    /** Supplied actor is from a future real session, not from the intent or request. */
    public function admit(string $actor, PreparedCommandIntent $intent): array
    {
        if (!ReadOnlyStatusService::actor($actor)) { return self::error(401, 'authentication_required'); }
        if ($actor !== $intent->actorId || !isset($this->targets['server:'.$intent->targetId])) { return self::error(403, 'access_denied'); }
        if ($this->authorize === null) { return self::error(503, 'authorization_unavailable'); }
        if ($this->store === null) { return self::error(503, 'admission_unavailable'); }
        try {
            $allowed = ($this->authorize)($actor, $intent->targetId, 'restart');
            if (!is_bool($allowed)) { return self::error(503, 'authorization_unavailable'); }
            if (!$allowed) { return self::error(403, 'access_denied'); }
        } catch (\Throwable) { return self::error(503, 'authorization_unavailable'); }

        // Recompute from typed identity, never caller hashes. Stable after lost response.
        $canonical = $intent->toArray();
        $key = $canonical['deduplication_key'];
        $operationId = 'adm_'.$key; // Existing Local lifecycle identity alphabet, <=80 bytes.
        try { $outcome = $this->store->reserve($key, $canonical['payload_fingerprint'], $operationId); }
        catch (\Throwable) { $outcome = 'uncertain'; }
        // Revocation during storage cannot leak a receipt or authorize subsequent dispatch.
        // Keep any reservation: deleting it would destroy retry identity.
        try {
            $allowed = ($this->authorize)($actor, $intent->targetId, 'restart');
            if (!is_bool($allowed)) { return self::error(503, 'authorization_unavailable'); }
            if (!$allowed) { return self::error(403, 'access_denied'); }
        } catch (\Throwable) { return self::error(503, 'authorization_unavailable'); }
        if ($outcome === 'conflict') { return self::error(409, 'request_conflict'); }
        if (!in_array($outcome, ['reserved', 'existing'], true)) {
            return ['status' => 503, 'body' => ['error' => 'admission_outcome_unknown', 'recovery_required' => true, 'retry_dispatch' => false]];
        }
        return ['status' => 200, 'body' => [
            'schema' => 'civ13.admin-admission.v1',
            'state' => $outcome === 'reserved' ? 'reserved_not_dispatched' : 'existing_requires_reconciliation',
            'operation_id' => $operationId,
            'target_id' => $intent->targetId,
            'action' => 'restart',
            'execution_enabled' => false,
            'retry_dispatch' => false,
            'recovery_required' => $outcome === 'existing',
        ]];
    }

    private static function error(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['error' => $error]];
    }
}
