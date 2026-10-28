<?php

declare(strict_types=1);

namespace Civ13\AdminConsole;

/** Inert command preparation only; no transport, storage, clock or ambient identity. */
final class CommandIntentPreparer
{
    private array $targets = [];

    /**
     * targetIds is a trusted operator-configured list, never request-provided URLs.
     * authorize(actor,target,action): bool|null reads maintained role cache only.
     * This is a separate action policy, NOT the read-status permission.
     */
    public function __construct(array $targetIds, private ?\Closure $authorize = null)
    {
        if (!array_is_list($targetIds) || count($targetIds) > 32) {
            throw new \InvalidArgumentException('Bounded explicit target inventory required');
        }
        foreach ($targetIds as $id) {
            if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $id) !== 1 || isset($this->targets['server:'.$id])) {
                throw new \InvalidArgumentException('Invalid trusted target identity');
            }
            $this->targets['server:'.$id] = true;
        }
    }

    /** Actor must come from an independently authenticated server-side session. */
    public function prepare(string $actor, array $request): array
    {
        if (!ReadOnlyStatusService::actor($actor)) { return self::error(401, 'authentication_required'); }
        $keys = array_keys($request); sort($keys);
        if ($keys !== ['action', 'request_id', 'target_id']
            || !is_string($request['target_id']) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $request['target_id']) !== 1
            || !is_string($request['action']) || !is_string($request['request_id'])
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $request['request_id']) !== 1) {
            return self::error(400, 'invalid_intent');
        }
        // No arbitrary verbs, shell commands, maps or implicit save-and-shutdown.
        if ($request['action'] !== 'restart') { return self::error(422, 'unsupported_action'); }
        if ($this->authorize === null) { return self::error(503, 'authorization_unavailable'); }
        try {
            $allowed = ($this->authorize)($actor, $request['target_id'], 'restart');
            if (!is_bool($allowed)) { return self::error(503, 'authorization_unavailable'); }
            // Same denial for unknown and forbidden targets; no target enumeration.
            if (!$allowed || !isset($this->targets['server:'.$request['target_id']])) { return self::error(403, 'access_denied'); }
            $intent = new PreparedCommandIntent($actor, $request['target_id'], $request['request_id']);
            // Future dispatch MUST independently reauthorize and resolve target generation.
            // This synchronous response does not assert readiness or execute anything.
            return ['status' => 200, 'body' => $intent->toArray()];
        } catch (\Throwable) {
            return self::error(503, 'preparation_unavailable');
        }
    }

    private static function error(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['error' => $code]];
    }
}
