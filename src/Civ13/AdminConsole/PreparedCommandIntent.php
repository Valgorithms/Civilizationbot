<?php

declare(strict_types=1);

namespace Civ13\AdminConsole;

/** Value only. Not a capability, dispatch token, queue receipt or completion receipt. */
final readonly class PreparedCommandIntent
{
    public function __construct(
        public string $actorId,
        public string $targetId,
        public string $requestId,
    ) {
        if (!ReadOnlyStatusService::actor($actorId)
            || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $targetId) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestId) !== 1) {
            throw new \InvalidArgumentException('Invalid command intent identity');
        }
    }

    public function toArray(): array
    {
        // Fixed positional encoding prevents delimiter ambiguity. No credentials/URLs.
        $payload = ['civ13.admin-command-intent.v1', $this->actorId, $this->targetId, 'restart'];
        return [
            'schema' => 'civ13.admin-command-intent.v1',
            'state' => 'prepared_not_executable',
            'execution_enabled' => false,
            'target_id' => $this->targetId,
            'action' => 'restart',
            'request_id' => $this->requestId,
            'deduplication_key' => hash('sha256', json_encode(['civ13.admin-command-request.v1', $this->actorId, $this->requestId], JSON_THROW_ON_ERROR)),
            'payload_fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }
}
